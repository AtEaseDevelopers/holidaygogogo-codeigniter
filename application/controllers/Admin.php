<?php
class Admin extends MY_Controller
{
	function __construct()
	{
		parent::__construct();
		$this->load->model('Admin_Model');
	}

	function index()
	{
		$titles = array('tab_title' => 'HolidayGoGoGo | Admin', 'breadcrumb_title' => 'Admin');
		$this->load->helper('totp');
		$array['twofa_enabled'] = totp_two_factor_enabled();
		$array['admins'] = $this->Admin_Model->Read();
		if(!empty($array['admins'])) {
			foreach($array['admins'] as $admin) {
				switch($admin->Level) {
					case 10:
						$admin->Level = 'OWNER';
						break;
					case 20:
						$admin->Level = 'SALES AGENT';
						break;
					case 25:
						$admin->Level = 'TEAM LEAD';
						break;
					case 30:
						$admin->Level = 'FINANCE';
						break;
					case 40:
						$admin->Level = 'OP';
						break;
					case 45:
						$admin->Level = 'OP TEAM LEAD';
						break;
					case 50:
						$admin->Level = 'TC';
						break;
					case 60:
						$admin->Level = 'MARKETING';
						break;
					default:
				}

				switch($admin->Status) {
					case 'Y':
						$admin->StatusIcon = '<i class="la la-check-circle text-success"></i>';
						break;
					case 'D':
						$admin->StatusIcon = '<i class="la la-times-circle text-danger"></i>';
						break;
					default:
				}
			}
		}
		$this->load->view('layout/header', $titles);
		$this->load->view('admin/index', $array);
		$this->load->view('layout/footer');
	}

	function Create()
	{
		if($this->input->is_ajax_request()) {
			$status = $this->Admin_Model->Create();
			echo json_encode($status);
		} else {
			$titles = array('tab_title' => 'HolidayGoGoGo | Admin', 'breadcrumb_title' => 'Admin >> Create');
			$array = array('Action' => 'C', 'AdminID' => 0, 'Name' => 'NA', 'AccessControl' => array(), 'TeamID' => '');
			$array['country_codes'] = $this->Admin_Model->Read_Country_Codes();
			$array['teams'] = $this->Admin_Model->Read_Teams();
			$array['ghl_users'] = $this->Admin_Model->Read_GHL_Users();
			$array['lead_dashboard_agents'] = array();
			$array['additional_teams'] = array();
			$array['sales_targets'] = array();
			$array['year_sales_targets'] = array();
			$this->load->view('layout/header', $titles);
			$this->load->view('admin/admin', $array);
			$this->load->view('layout/footer');
		}
	}

	function Update()
	{
		if($this->input->is_ajax_request()) {
			$status = $this->Admin_Model->Update();
			echo json_encode($status);
		} else {
			$array = $this->Admin_Model->Read();
			if(!empty($array)) {
				$titles = array('tab_title' => 'HolidayGoGoGo | Admin', 'breadcrumb_title' => 'Admin >> Update');
				$array['AccessControl'] = explode(',', $array['AccessControl']);
				$array['Action'] = 'U';
				$array['country_codes'] = $this->Admin_Model->Read_Country_Codes();
				$array['teams'] = $this->Admin_Model->Read_Teams();
				$array['ghl_users'] = $this->Admin_Model->Read_GHL_Users();
				$array['lead_dashboard_agents'] = $this->Admin_Model->Read_Lead_Dashboard_Agents_For_Admin($this->input->get('admin_id'));
				$array['additional_teams'] = $this->Admin_Model->Read_Teams_For_Admin($this->input->get('admin_id'));
				$array['sales_targets'] = $this->Admin_Model->Read_Sales_Targets_For_Admin($this->input->get('admin_id'));
				$array['year_sales_targets'] = $this->Admin_Model->Read_Year_Sales_Targets_For_Admin($this->input->get('admin_id'));
				$this->load->view('layout/header', $titles);
				$this->load->view('admin/admin', $array);
				$this->load->view('layout/footer');
			} else {
				redirect('Admin');
			}
		}
	}

	function Update_Status_To_D_Or_Y()
	{
		if($this->input->is_ajax_request()) {
			$status = $this->Admin_Model->Update();
			echo json_encode($status);
		} else {
			$this->load->view('errors/access_denied');
		}
	}

	function Update_Status_To_N()
	{
		if($this->input->is_ajax_request()) {
			$status = $this->Admin_Model->Update();
			echo json_encode($status);
		} else {
			$this->load->view('errors/access_denied');
		}
	}

	// Owner-only 2FA recovery: clear an admin's Google Authenticator enrolment so
	// they set it up again on their next login (for a lost/replaced phone).
	function Reset_Two_Factor()
	{
		if(!$this->input->is_ajax_request() || (int) $this->session->level !== 10) {
			$this->load->view('errors/access_denied');
			return;
		}
		$admin_id = (int) $this->input->post('admin_id');
		if($admin_id > 0) {
			$this->load->model('Login_Model');
			$this->Login_Model->Reset_Two_Factor($admin_id);
			echo json_encode(true);
		} else {
			echo json_encode(false);
		}
	}
}