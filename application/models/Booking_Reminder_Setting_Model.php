<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Booking_Reminder_Setting_Model extends CI_Model
{
	// Maximum value of the SMALLINT UNSIGNED hours columns.
	const MAX_HOURS = 65535;

	function Get_Hours()
	{
		$query = $this->db->select('draft_bc_reminder_hours, pending_bc_reminder_hours')
			->get_where('booking_reminder_settings', array('id' => 1));
		if ($query === false) {
			return false;
		}

		$settings = $query->row_array();
		return array(
			'draft_bc_reminder_hours' => (int) ($settings['draft_bc_reminder_hours'] ?? $this->config->item('draft_bc_reminder_hours')),
			'pending_bc_reminder_hours' => (int) ($settings['pending_bc_reminder_hours'] ?? $this->config->item('pending_bc_reminder_hours')),
		);
	}

	function Save_Hours($draft_hours, $pending_hours)
	{
		return $this->db->query("
			INSERT INTO booking_reminder_settings (id, draft_bc_reminder_hours, pending_bc_reminder_hours)
			VALUES (1, ?, ?)
			ON DUPLICATE KEY UPDATE draft_bc_reminder_hours = VALUES(draft_bc_reminder_hours),
				pending_bc_reminder_hours = VALUES(pending_bc_reminder_hours)
		", array($draft_hours, $pending_hours));
	}
}
