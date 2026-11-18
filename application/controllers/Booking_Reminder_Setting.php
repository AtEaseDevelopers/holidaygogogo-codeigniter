<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Booking_Reminder_Setting extends MY_Controller
{
	function __construct()
	{
		parent::__construct();
		$this->load->model('Booking_Reminder_Setting_Model');
	}

	function index()
	{
		if (nav_block_view('booking_reminder')) {
			return;
		}

		$hours = $this->Booking_Reminder_Setting_Model->Get_Hours();
		if ($hours === false) {
			show_error('Unable to load booking reminder settings.');
			return;
		}

		$can_edit = nav_can_edit('booking_reminder');
		$token = $this->session->userdata('booking_reminder_token');
		if ($can_edit && empty($token)) {
			$token = bin2hex(random_bytes(32));
			$this->session->set_userdata('booking_reminder_token', $token);
		}

		$titles = array(
			'tab_title' => 'HolidayGoGoGo | Booking Reminders',
			'breadcrumb_title' => 'Booking Reminders',
		);
		$data = array(
			'hours' => $hours,
			'max_hours' => Booking_Reminder_Setting_Model::MAX_HOURS,
			'can_edit' => $can_edit,
			'token' => $token,
		);
		$this->load->view('layout/header', $titles);
		$this->load->view('booking_reminder_setting/index', $data);
		$this->load->view('layout/footer');
	}

	function Save()
	{
		if (nav_block_edit('booking_reminder')) {
			return;
		}

		if ($this->input->method(true) !== 'POST') {
			show_error('Method not allowed.', 405);
			return;
		}

		$expected = $this->session->userdata('booking_reminder_token');
		$token = $this->input->post('token');
		if (empty($expected) || !is_string($token) || !hash_equals($expected, $token)) {
			show_error('Reload Booking Reminders and try again.', 403);
			return;
		}

		$range = array('options' => array('min_range' => 0, 'max_range' => Booking_Reminder_Setting_Model::MAX_HOURS));
		$draft_hours = filter_var($this->input->post('draft_bc_reminder_hours'), FILTER_VALIDATE_INT, $range);
		$pending_hours = filter_var($this->input->post('pending_bc_reminder_hours'), FILTER_VALIDATE_INT, $range);

		if ($draft_hours === false || $pending_hours === false) {
			$this->session->set_flashdata('booking_reminder_error', 'Enter a whole number within the allowed range for each reminder.');
		} elseif (!$this->Booking_Reminder_Setting_Model->Save_Hours($draft_hours, $pending_hours)) {
			$this->session->set_flashdata('booking_reminder_error', 'Booking reminder settings could not be saved.');
		} else {
			$this->session->set_flashdata('booking_reminder_success', 'Booking reminder settings saved.');
		}

		redirect('Booking_Reminder_Setting', 'location', 303);
	}
}
