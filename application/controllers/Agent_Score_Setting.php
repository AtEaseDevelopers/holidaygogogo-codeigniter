<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Agent Score Settings — OWNER-only (admin.Level = '10') page to include/exclude
 * specific sales agents from the Agent Score. Excluded agents drop out of the TC
 * "Agent Score" Top-5 leaderboard + its 100-benchmark and out of the Agent Score
 * column on the owner per-agent matrix (applied in Booking.php via the same
 * agent_score_excluded_agents table this page writes).
 */
class Agent_Score_Setting extends MY_Controller
{
	function __construct()
	{
		parent::__construct();
		$this->load->model('Agent_Score_Setting_Model');
	}

	// OWNER only — every other role is bounced to the dashboard.
	private function Is_Owner()
	{
		return function_exists('nav_can_view') ? nav_can_view('agent_score') : ((int) $this->session->level === 10);
	}

	function index()
	{
		if (!$this->Is_Owner()) {
			redirect(base_url('Dashboard'));
			return;
		}

		$excluded = array_flip($this->Agent_Score_Setting_Model->Excluded_Admin_Ids());

		$titles = array(
			'tab_title'        => 'HolidayGoGoGo | Agent Score Settings',
			'breadcrumb_title' => 'Agent Score Settings',
		);
		$data = array(
			'agents'   => $this->Agent_Score_Setting_Model->Sales_Agents(),
			'excluded' => $excluded,
		);
		$this->load->view('layout/header', $titles);
		$this->load->view('agent_score_setting/index', $data);
		$this->load->view('layout/footer');
	}

	function Save()
	{
		if (!$this->Is_Owner()) {
			redirect(base_url('Dashboard'));
			return;
		}

		// Switch semantics: ON (blue, checked) = INCLUDE, OFF (grey) = exclude.
		// The form posts included[] (the ON agents); everyone on the settings roster
		// NOT switched on is excluded. We still store the EXCLUDED set (the table's
		// meaning is unchanged) — we just invert the checkboxes to derive it.
		$included = array();
		foreach ((array) $this->input->post('included') as $id) {
			$included[(int) $id] = true;
		}
		$excluded = array();
		foreach ($this->Agent_Score_Setting_Model->Sales_Agents() as $agent) {
			$aid = (int) $agent->AdminID;
			if (!isset($included[$aid])) { $excluded[] = $aid; }
		}
		$this->Agent_Score_Setting_Model->Set_Excluded($excluded, (int) $this->session->admin_id);

		$this->session->set_flashdata('agent_score_setting_success', 'Agent Score settings saved.');
		redirect(base_url('Agent_Score_Setting'));
	}
}
