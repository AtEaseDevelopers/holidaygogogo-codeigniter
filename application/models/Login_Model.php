<?php
class Login_Model extends CI_Model
{
	function Validate_Login()
	{
		$this->db->select('AdminID, Name, Gender, Level, AccessControl, Username, TotpSecret, TotpEnabled, TotpLastStep, TotpFailCount, TotpLockUntil');
		$this->db->where('Username', $this->input->post('username'));
        $this->db->where('Password', $this->input->post('password'));
		$this->db->where('Status', 'Y');
		$this->db->limit(1);
		$login = $this->db->get('admin');
		if($login->num_rows() == 1) {
			return $login->row();
		} else {
			return false;
		}
	}

	// Re-read a single admin for the 2FA step (the password step only kept the id
	// in the session, never the secret).
	function Read_For_Two_Factor($admin_id)
	{
		$this->db->select('AdminID, Name, Gender, Level, AccessControl, Username, TotpSecret, TotpEnabled, TotpLastStep, TotpFailCount, TotpLockUntil');
		$this->db->where('AdminID', $admin_id);
		$this->db->where('Status', 'Y');
		$this->db->limit(1);
		$row = $this->db->get('admin');
		return $row->num_rows() === 1 ? $row->row() : false;
	}

	// Persist the encrypted secret + enable 2FA once the admin confirmed a code.
	function Enable_Two_Factor($admin_id, $encrypted_secret, $last_step)
	{
		$this->db->where('AdminID', $admin_id);
		$this->db->update('admin', array(
			'TotpSecret'   => $encrypted_secret,
			'TotpEnabled'  => 'Y',
			'TotpLastStep' => $last_step,
		));
	}

	// Record the time step just consumed so the same code can't be replayed.
	function Update_Last_Step($admin_id, $last_step)
	{
		$this->db->where('AdminID', $admin_id);
		$this->db->update('admin', array('TotpLastStep' => $last_step));
	}

	// Persist the brute-force throttle on the admin row (NOT the session, which a
	// logout would wipe). $lock_until is a 'Y-m-d H:i:s' string or null.
	function Set_Totp_Throttle($admin_id, $fail_count, $lock_until)
	{
		$this->db->where('AdminID', $admin_id);
		$this->db->update('admin', array(
			'TotpFailCount' => (int) $fail_count,
			'TotpLockUntil' => $lock_until,
		));
	}

	// Owner-triggered reset: clear enrolment so the admin re-enrols on next login.
	// Also clears any throttle so a reset isn't defeated by a lingering lock.
	function Reset_Two_Factor($admin_id)
	{
		$this->db->where('AdminID', $admin_id);
		$this->db->update('admin', array(
			'TotpSecret'    => null,
			'TotpEnabled'   => 'N',
			'TotpLastStep'  => null,
			'TotpFailCount' => 0,
			'TotpLockUntil' => null,
		));
	}
}
