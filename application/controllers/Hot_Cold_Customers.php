<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Hot_Cold_Customers — an Owner-only (level 10) listing that buckets customers
 * into Hot and Cold based on their latest AI chat analysis (booking intent). The
 * classification itself is produced on-demand when the "AI Analysis" button is
 * run on a customer's Customer Profile page (see Customer_Analysis); this page
 * only reads the stored labels, so it shows customers already analysed.
 */
class Hot_Cold_Customers extends MY_Controller
{
	function __construct()
	{
		parent::__construct();
		if ((int) $this->session->level !== 10) {
			redirect(base_url('Booking'));
			return;
		}
		$this->load->model('Customer_Analysis_Model');
	}

	function index()
	{
		$q    = trim((string) $this->input->get('q'));
		$type = trim((string) $this->input->get('type'));
		$allowed = array('Customer', 'Guest List', 'GHL Lead', 'Manual Lead');
		if ( ! in_array($type, $allowed, true)) { $type = ''; }

		$data = array(
			'f_q'    => $q,
			'f_type' => $type,
			'hot'    => $this->Customer_Analysis_Model->Read_Classified('hot', $q, $type),
			'cold'   => $this->Customer_Analysis_Model->Read_Classified('cold', $q, $type),
		);
		$titles = array(
			'tab_title'        => 'HolidayGoGoGo | Hot / Cold Customers',
			'breadcrumb_title' => 'Hot / Cold Customers',
		);
		$this->load->view('layout/header', $titles);
		$this->load->view('hot_cold_customers/index', $data);
		$this->load->view('layout/footer');
	}
}
