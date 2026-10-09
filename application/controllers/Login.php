<?php
class Login extends CI_Controller
{
	// Two-factor tuning.
	const TOTP_ISSUER       = 'HolidayGoGoGo';
	const TWOFA_MAX_ATTEMPTS = 5;      // failed codes before a temporary lock
	const TWOFA_LOCK_SECONDS = 600;    // 10-minute lockout

	function __construct()
	{
		parent::__construct();
        $this->load->model('Login_Model');
        $this->load->helper('totp');
	}

	function index()
	{
		$array = [];
		if($this->input->post('login')) {
			$login = $this->Login_Model->Validate_Login();
			if(!empty($login)) {
				// Password is correct, but DON'T grant the real session yet.
				// Hold an "awaiting 2FA" state keyed by admin id only, then send
				// the user to the second factor. The MY_Controller gate looks for
				// 'admin_id' (set in complete_login), so protected pages stay
				// locked until TOTP passes.
				$this->activity_log('Login Password OK - Username:'.$_POST["username"], 'Y', $login->AdminID);
				if( ! $this->_twofa_enabled()) {
					// 2FA globally disabled via .env (e.g. local dev) — log in now.
					$this->_complete_login($login);
					return;
				}
				$this->session->set_userdata(array('pending_admin_id' => $login->AdminID));
				redirect('Login/Two_Factor');
			} else {
				$this->activity_log('Login Fail - Username:'.$_POST["username"].', Password:'.$_POST["password"], 'N', null);
				$array = array('error_message' => 'Invalid Login');
			}
		}
		$this->load->view('login', $array);
	}

	// Second factor: ask for the Google Authenticator code. Unenrolled admins are
	// sent to Setup_Two_Factor first.
	function Two_Factor()
	{
		$admin = $this->_pending_admin();
		if($admin === false) {
			redirect('Login');
		}
		if($admin->TotpEnabled !== 'Y' || empty($admin->TotpSecret)) {
			redirect('Login/Setup_Two_Factor');
		}

		$array = array();
		if($lock = $this->_lock_remaining($admin)) {
			$array['error_message'] = 'Too many attempts. Try again in '.ceil($lock / 60).' minute(s).';
			$array['locked_seconds'] = $lock;
			$this->load->view('two_factor', $array);
			return;
		}

		if($this->input->post('code')) {
			$secret = totp_decrypt($admin->TotpSecret, $this->_totp_key());
			$step = $secret === '' ? false
				: totp_verify($secret, $this->input->post('code'), time(), 1, 30, 6, (int) $admin->TotpLastStep);
			if($step !== false) {
				$this->Login_Model->Update_Last_Step($admin->AdminID, $step); // consume → no replay
				$this->Login_Model->Set_Totp_Throttle($admin->AdminID, 0, null); // clear throttle
				$this->_complete_login($admin);
				return;
			}
			// Post/Redirect/Get: record the failure then redirect so a browser
			// refresh can't re-POST (and re-burn an attempt).
			$this->_register_failure($admin);
			redirect('Login/Two_Factor');
		}

		// Remaining-attempts message is derived from the persisted fail count, so
		// it keeps showing after a refresh (unlike one-shot flashdata).
		if((int) $admin->TotpFailCount > 0) {
			$array['error_message'] = 'Invalid code. '.(self::TWOFA_MAX_ATTEMPTS - (int) $admin->TotpFailCount).' attempt(s) left.';
		}
		$this->load->view('two_factor', $array);
	}

	// First-time enrolment: show the QR, confirm one code, then enable + log in.
	function Setup_Two_Factor()
	{
		$admin = $this->_pending_admin();
		if($admin === false) {
			redirect('Login');
		}
		if($admin->TotpEnabled === 'Y' && !empty($admin->TotpSecret)) {
			redirect('Login/Two_Factor');
		}
		if($this->_totp_key() === '') {
			$this->load->view('two_factor_setup', array(
				'error_message' => 'Two-factor is not configured on the server (missing TOTP_ENCRYPTION_KEY). Contact the administrator.',
			));
			return;
		}

		// Keep ONE secret across refresh/retries so a half-scanned QR stays valid.
		$secret_b32 = $this->session->userdata('pending_totp_secret');
		if(empty($secret_b32)) {
			$secret_b32 = totp_base32_encode(totp_generate_secret());
			$this->session->set_userdata(array('pending_totp_secret' => $secret_b32));
		}
		$secret = totp_base32_decode($secret_b32);

		$array = array(
			'secret_b32' => $secret_b32,
			'otpauth'    => totp_provisioning_uri(self::TOTP_ISSUER, $admin->Username, $secret),
		);

		if($lock = $this->_lock_remaining($admin)) {
			$array['error_message'] = 'Too many attempts. Try again in '.ceil($lock / 60).' minute(s).';
			$array['locked_seconds'] = $lock;
			$this->load->view('two_factor_setup', $array);
			return;
		}

		if($this->input->post('code')) {
			$step = totp_verify($secret, $this->input->post('code'), time(), 1, 30, 6);
			if($step !== false) {
				$this->Login_Model->Enable_Two_Factor($admin->AdminID, totp_encrypt($secret, $this->_totp_key()), $step);
				$this->Login_Model->Set_Totp_Throttle($admin->AdminID, 0, null); // clear throttle
				$this->session->unset_userdata('pending_totp_secret');
				$this->activity_log('2FA Enrolled - AdminID:'.$admin->AdminID, 'Y', $admin->AdminID);
				$admin->TotpLastStep = $step;
				$this->_complete_login($admin);
				return;
			}
			// Post/Redirect/Get: record the failure then redirect so refresh can't re-POST.
			$this->_register_failure($admin);
			redirect('Login/Setup_Two_Factor');
		}

		if((int) $admin->TotpFailCount > 0) {
			$array['error_message'] = 'Invalid code. '.(self::TWOFA_MAX_ATTEMPTS - (int) $admin->TotpFailCount).' attempt(s) left.';
		}
		$this->load->view('two_factor_setup', $array);
	}

	function Logout()
	{
		$this->session->sess_destroy();
		redirect('Login');
	}

	// ---- internals ---------------------------------------------------------

	// The admin behind the current "awaiting 2FA" state, or false.
	private function _pending_admin()
	{
		$id = $this->session->userdata('pending_admin_id');
		if(empty($id)) {
			return false;
		}
		return $this->Login_Model->Read_For_Two_Factor($id);
	}

	private function _totp_key()
	{
		return (string) get_env('TOTP_ENCRYPTION_KEY');
	}

	// Whether the second factor is enforced. Set TWOFA_ENABLED=false in .env to
	// skip 2FA entirely (e.g. local dev); any other value (or unset) keeps it on.
	private function _twofa_enabled()
	{
		$v = strtolower(trim((string) get_env('TWOFA_ENABLED')));
		return ! in_array($v, array('false', '0', 'no', 'off'), true);
	}

	// Seconds left on a temporary lock, or 0. The lock lives on the admin ROW so
	// logging out ("Cancel & sign in again") cannot reset it.
	private function _lock_remaining($admin)
	{
		if(empty($admin->TotpLockUntil)) {
			return 0;
		}
		$until = strtotime($admin->TotpLockUntil);
		return $until > time() ? $until - time() : 0;
	}

	// Count a wrong code against the admin row; lock after TWOFA_MAX_ATTEMPTS.
	// The user-facing message (attempts-left / lock countdown) is derived from
	// the persisted state by the GET render, so this just records the failure.
	private function _register_failure($admin)
	{
		$count = (int) $admin->TotpFailCount + 1;
		if($count >= self::TWOFA_MAX_ATTEMPTS) {
			// Lock, and reset the counter so a fresh set of attempts follows expiry.
			$this->Login_Model->Set_Totp_Throttle($admin->AdminID, 0, date('Y-m-d H:i:s', time() + self::TWOFA_LOCK_SECONDS));
			return;
		}
		$this->Login_Model->Set_Totp_Throttle($admin->AdminID, $count, null);
	}

	// Promote the "awaiting 2FA" state into a real session (mirrors the original
	// index() block) and send the user on.
	private function _complete_login($login)
	{
		switch($login->Level) {
			case 10: $priviledge = 'OWNER'; break;
			case 20: $priviledge = 'SALES AGENT'; break;
			case 25: $priviledge = 'TEAM LEAD'; break;
			case 30: $priviledge = 'FINANCE'; break;
			case 45: $priviledge = 'OP TEAM LEAD'; break;
			case 60: $priviledge = 'MARKETING'; break;
			default: $priviledge = '';
		}
		$session = array(
			'admin_id' => $login->AdminID,
			'profile_picture' => $login->Gender == 'F' ? base_url('assets/image/female.svg') : base_url('assets/image/male.svg'),
			'name' => $login->Name,
			'level' => $login->Level,
			'access_control' => explode(',', $login->AccessControl),
			'priviledge' => $priviledge
		);
		$this->session->unset_userdata(array('pending_admin_id', 'pending_totp_secret'));
		$this->activity_log('Login Success - Username:'.$login->Username, 'Y', $login->AdminID);
		$this->session->set_userdata($session);
		redirect(in_array($login->Level, [20, 40, 45, 60]) ? 'Booking' : 'Dashboard');
	}

	public function activity_log($action, $status, $user_id){
    	if($_SERVER['QUERY_STRING'] != ''){
            $get = '?'.$_SERVER['QUERY_STRING'];
        }else{
            $get = $_SERVER['QUERY_STRING'];
        }

        $data = array(
            'UserID'    => $user_id,
            'Action'    => $action,
            'IP'        => $_SERVER['REMOTE_ADDR'],
            'Url'       => current_url().$get,
            'Status'    => $status,
            'InsertBy'  => 'SYSTEM',
            'InsertDate'=> date('Y-m-d H:i:s')
        );

        return $this->db->insert('activity_log', $data);
    }
}
