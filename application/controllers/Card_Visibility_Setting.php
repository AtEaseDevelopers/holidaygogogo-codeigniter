<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Card Visibility Settings — OWNER-only (admin.Level = '10') page to control, per
 * (user x card), which Booking summary cards each non-owner user may see. Cards
 * are VISIBLE BY DEFAULT to their normal role; switching one OFF hides that card
 * from that user (a row in card_visibility_hidden_cards). Applied at runtime by
 * card_visibility_helper (CSS hide in booking/_summary_cards + data stripping in
 * Booking::ajax_summary_cards).
 */
class Card_Visibility_Setting extends MY_Controller
{
	function __construct()
	{
		parent::__construct();
		$this->load->model('Card_Visibility_Setting_Model');
		$this->load->helper('card_visibility');
	}

	// OWNER only — every other role is bounced to the dashboard.
	private function Is_Owner()
	{
		return function_exists('nav_can_view') ? nav_can_view('card_visibility') : ((int) $this->session->level === 10);
	}

	function index()
	{
		if (!$this->Is_Owner()) {
			redirect(base_url('Dashboard'));
			return;
		}

		$titles = array(
			'tab_title'        => 'HolidayGoGoGo | Card Visibility Settings',
			'breadcrumb_title' => 'Card Visibility Settings',
		);
		$data = array(
			'registry' => card_visibility_registry(),
			'users'    => $this->Card_Visibility_Setting_Model->Configurable_Users(),
			'hidden'   => $this->Card_Visibility_Setting_Model->Hidden_Pair_Keys(),
		);
		$this->load->view('layout/header', $titles);
		$this->load->view('card_visibility_setting/index', $data);
		$this->load->view('layout/footer');
	}

	function Save()
	{
		if (!$this->Is_Owner()) {
			redirect(base_url('Dashboard'));
			return;
		}

		// Switches post their pair key ("slug|adminid") only when ON (= visible).
		// The authoritative eligible set is rebuilt server-side from the registry
		// and the live user list, so the hidden set is exactly the eligible pairs
		// the owner did not leave on — visible-by-default, tamper-resistant.
		$posted  = (array) $this->input->post('visible');
		$visible = array();
		foreach ($posted as $p) { $visible[(string) $p] = true; }

		$eligible = card_visibility_eligible_pairs(
			card_visibility_registry(),
			$this->Card_Visibility_Setting_Model->Configurable_Users()
		);
		$hidden = card_visibility_hidden_from_visible($eligible, $visible);

		$this->Card_Visibility_Setting_Model->Set_Hidden($hidden, (int) $this->session->admin_id);

		$this->session->set_flashdata('card_visibility_setting_success', 'Card visibility saved.');
		redirect(base_url('Card_Visibility_Setting'));
	}
}
