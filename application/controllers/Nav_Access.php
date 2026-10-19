<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Nav_Access extends MY_Controller
{
	function __construct()
	{
		parent::__construct();
		if ((int) $this->session->userdata('level') !== 10) {
			redirect(nav_home_url());
		}
		$this->load->model('Nav_Module_Access_Model');
		$this->load->helper('nav_access');
	}

	function index()
	{
		$titles = array(
			'tab_title'        => 'HolidayGoGoGo | Navigation Access',
			'breadcrumb_title' => 'Navigation Access',
		);
		$data['admins']  = $this->Nav_Module_Access_Model->Read_Grid();
		$data['modules'] = nav_modules();
		$data['groups']  = nav_module_groups();
		$this->load->view('layout/header', $titles);
		$this->load->view('nav_access/index', $data);
		$this->load->view('layout/footer');
	}

	function Save()
	{
		if ( ! $this->input->is_ajax_request()) {
			redirect('Nav_Access');
			return;
		}
		$ok = $this->Nav_Module_Access_Model->Save();
		echo json_encode((bool) $ok);
	}
}
