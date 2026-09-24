<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Ai_Usage_Model — persistence for the ai_usage_log table (one row per OpenAI
 * call). Log() is called by every AI service right after a call; the Read_*
 * methods feed the owner-only "AI Cost & Usage" page (controllers/Ai_Usage.php).
 * All roll-ups happen in the pure ai_usage_helper so they stay unit-tested; this
 * layer only stores and fetches rows.
 */
class Ai_Usage_Model extends CI_Model
{
	/**
	 * Insert one usage record. $data carries feature/model/input_tokens/
	 * output_tokens/cost_usd plus optional status, reference_id and created_by.
	 * Returns the new row id. Callers wrap this so a logging failure never breaks
	 * the paid AI flow.
	 */
	function Log($data)
	{
		$in  = isset($data['input_tokens'])  ? (int) $data['input_tokens']  : 0;
		$out = isset($data['output_tokens']) ? (int) $data['output_tokens'] : 0;
		$row = array(
			'feature'       => isset($data['feature']) ? (string) $data['feature'] : 'Unknown',
			'provider'      => isset($data['provider']) ? (string) $data['provider'] : 'openai',
			'model'         => isset($data['model']) ? $data['model'] : null,
			'input_tokens'  => $in,
			'output_tokens' => $out,
			'total_tokens'  => $in + $out,
			'cost_usd'      => isset($data['cost_usd']) ? $data['cost_usd'] : 0,
			'status'        => isset($data['status']) ? (string) $data['status'] : 'ok',
			'reference_id'  => isset($data['reference_id']) ? $data['reference_id'] : null,
			'created_by'    => isset($data['created_by']) && $data['created_by'] !== '' ? (int) $data['created_by'] : null,
			'created_at'    => date('Y-m-d H:i:s'),
		);
		$this->db->insert('ai_usage_log', $row);
		return $this->db->insert_id();
	}

	/**
	 * All log rows matching the page filters, newest first, with the triggering
	 * admin's name joined on for the "by user" breakdown. Returned as assoc arrays
	 * so ai_usage_helper (pure) can roll them up.
	 *
	 * $filters: date_from (Y-m-d), date_to (Y-m-d), feature, model — each optional.
	 */
	function Read_Rows($filters = array())
	{
		$this->db->select('l.*, a.Name AS created_by_name', false);
		$this->db->from('ai_usage_log l');
		$this->db->join('admin a', 'a.AdminID = l.created_by', 'left');

		if ( ! empty($filters['date_from'])) {
			$this->db->where('l.created_at >=', $filters['date_from'] . ' 00:00:00');
		}
		if ( ! empty($filters['date_to'])) {
			$this->db->where('l.created_at <=', $filters['date_to'] . ' 23:59:59');
		}
		if ( ! empty($filters['feature'])) {
			$this->db->where('l.feature', $filters['feature']);
		}
		if ( ! empty($filters['model'])) {
			$this->db->where('l.model', $filters['model']);
		}
		$this->db->order_by('l.created_at', 'DESC');
		$this->db->order_by('l.id', 'DESC');
		return $this->db->get()->result_array();
	}

	/** Distinct non-empty values of one column (feature|model), for filter menus. */
	function Read_Distinct($column)
	{
		$allowed = array('feature', 'model');
		if ( ! in_array($column, $allowed, true)) {
			return array();
		}
		$rows = $this->db->distinct()
			->select($column)
			->where($column . ' IS NOT NULL', null, false)
			->where($column . " != ''", null, false)
			->order_by($column, 'ASC')
			->get('ai_usage_log')
			->result_array();
		$out = array();
		foreach ($rows as $r) {
			$out[] = $r[$column];
		}
		return $out;
	}
}
