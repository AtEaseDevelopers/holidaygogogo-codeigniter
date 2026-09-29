<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Customer_Analysis_Model — thin persistence for the customer_analyses table.
 * The chat gathering + OpenAI work happens in the controller/service; this layer
 * only stores one finished analysis and reads it back for the per-customer page.
 * Analyses are keyed by the listing row's dedup_key so the whole history for a
 * customer lives together regardless of which booking/lead row it was run from.
 *
 * The flat record from customer_analysis_parse_ai_response() is stored with its
 * list/assoc fields as JSON; Read_* decode them back onto the row object.
 */
class Customer_Analysis_Model extends CI_Model
{
	/**
	 * Insert one analysis. $data carries the parsed AI record (summary,
	 * sales_intel, next_actions, key_facts) plus context (dedup_key, guest_name,
	 * source_counts, message_count, model, tokens, cost, status, created_by).
	 * Returns the new row id.
	 */
	function Create($data)
	{
		$sales_intel  = isset($data['sales_intel']) && is_array($data['sales_intel']) ? $data['sales_intel'] : array();
		$next_actions = isset($data['next_actions']) && is_array($data['next_actions']) ? $data['next_actions'] : array();
		$key_facts    = isset($data['key_facts']) && is_array($data['key_facts']) ? $data['key_facts'] : array();
		// The structured character profile lives in details_json.
		$profile      = isset($data['profile']) && is_array($data['profile']) ? $data['profile'] : array();
		$rec_tours    = isset($data['recommended_tours']) && is_array($data['recommended_tours']) ? $data['recommended_tours'] : array();
		// The structured customer-facing comparison (intro/options/decision_guide/…).
		$recommendation = isset($data['recommendation']) && is_array($data['recommendation']) ? $data['recommendation'] : array();

		$row = array(
			'dedup_key'     => (string) (isset($data['dedup_key']) ? $data['dedup_key'] : ''),
			'guest_name'    => isset($data['guest_name']) ? $data['guest_name'] : null,
			'source_type'   => ! empty($data['source_type']) ? $data['source_type'] : null,
			'source_counts' => isset($data['source_counts']) ? $data['source_counts'] : null,
			'message_count' => isset($data['message_count']) ? (int) $data['message_count'] : 0,
			'last_message_at' => ! empty($data['last_message_at']) ? $data['last_message_at'] : null,
			'covered_ghl'    => isset($data['covered_ghl']) ? (int) $data['covered_ghl'] : 0,
			'covered_upload' => isset($data['covered_upload']) ? (int) $data['covered_upload'] : 0,
			'temperature'   => ! empty($data['temperature']) ? $data['temperature'] : null,
			'temperature_reason' => isset($data['temperature_reason']) ? $data['temperature_reason'] : null,
			'approach_suggestion' => isset($data['approach_suggestion']) ? $data['approach_suggestion'] : null,
			'recommended_tours' => $rec_tours ? json_encode($rec_tours, JSON_UNESCAPED_UNICODE) : null,
			'recommendation_json' => ! empty($recommendation['options']) ? json_encode($recommendation, JSON_UNESCAPED_UNICODE) : null,
			'summary'       => isset($data['summary']) ? $data['summary'] : null,
			'sales_intel'   => json_encode($sales_intel, JSON_UNESCAPED_UNICODE),
			'next_actions'  => json_encode($next_actions, JSON_UNESCAPED_UNICODE),
			'key_facts'     => json_encode($key_facts, JSON_UNESCAPED_UNICODE),
			'details_json'  => $profile ? json_encode($profile, JSON_UNESCAPED_UNICODE) : (isset($data['details_json']) ? $data['details_json'] : null),
			'raw_json'      => isset($data['raw_json']) ? $data['raw_json'] : null,
			'model'         => isset($data['model']) ? $data['model'] : null,
			'input_tokens'  => isset($data['input_tokens']) ? (int) $data['input_tokens'] : 0,
			'output_tokens' => isset($data['output_tokens']) ? (int) $data['output_tokens'] : 0,
			'cost_usd'      => isset($data['cost_usd']) ? $data['cost_usd'] : 0,
			'status'        => isset($data['status']) ? $data['status'] : 'done',
			'error_message' => isset($data['error_message']) ? $data['error_message'] : null,
			'created_by'    => isset($data['created_by']) ? $data['created_by'] : null,
			'created_at'    => date('Y-m-d H:i:s'),
		);
		$this->db->insert('customer_analyses', $row);
		return $this->db->insert_id();
	}

	/**
	 * Every analysis for one customer (dedup_key), newest first, fully decoded for
	 * rendering. The latest one is shown expanded; the rest form the history list.
	 */
	function Read_By_Dedup($dedup_key)
	{
		$rows = $this->db
			->where('dedup_key', (string) $dedup_key)
			->order_by('created_at', 'DESC')
			->order_by('id', 'DESC')
			->get('customer_analyses')
			->result();
		foreach ($rows as $row) {
			$this->decode_row($row);
		}
		return $rows;
	}

	/**
	 * The latest successful analysis for one customer, decoded — the "memory" a
	 * follow-up run updates (carries summary/key_facts + watermark columns).
	 */
	function Read_Latest_Done($dedup_key)
	{
		$row = $this->db
			->where('dedup_key', (string) $dedup_key)
			->where('status', 'done')
			->order_by('created_at', 'DESC')
			->order_by('id', 'DESC')
			->limit(1)
			->get('customer_analyses')
			->row();
		if ($row) {
			$this->decode_row($row);
		}
		return $row;
	}

	/** One analysis by id, decoded. Null when missing. */
	function Read_One($id)
	{
		$row = $this->db->get_where('customer_analyses', array('id' => (int) $id))->row();
		if ( ! $row) {
			return null;
		}
		$this->decode_row($row);
		return $row;
	}

	function Delete($id)
	{
		$this->db->where('id', (int) $id)->delete('customer_analyses');
	}

	/**
	 * The LATEST analysis per customer (dedup_key) whose current classification is
	 * hot or cold — powers the Hot/Cold Customers listing. Takes each customer's
	 * most recent done analysis and includes it only when that row is classified,
	 * so a later un-classified re-run doesn't resurrect a stale hot/cold label.
	 * Optionally filter to one bucket ('hot' | 'cold').
	 */
	function Read_Classified($temperature = null, $q = '', $type = '')
	{
		$sql = "SELECT ca.*
			FROM customer_analyses ca
			JOIN (
				SELECT dedup_key, MAX(id) AS max_id
				FROM customer_analyses
				WHERE status = 'done'
				GROUP BY dedup_key
			) latest ON latest.max_id = ca.id
			WHERE ca.temperature IN ('hot','cold')";
		$bind = array();
		if ($temperature === 'hot' || $temperature === 'cold') {
			$sql .= " AND ca.temperature = ?";
			$bind[] = $temperature;
		}
		$q = trim((string) $q);
		if ($q !== '') {
			$sql .= " AND ca.guest_name LIKE ?";
			$bind[] = '%' . $q . '%';
		}
		$type = trim((string) $type);
		if ($type !== '') {
			$sql .= " AND ca.source_type = ?";
			$bind[] = $type;
		}
		$sql .= " ORDER BY ca.created_at DESC, ca.id DESC";
		return $this->db->query($sql, $bind)->result();
	}

	/** Decode the stored JSON columns back onto the row object in place. */
	private function decode_row($row)
	{
		// Structured character profile (details_json) — normalised to a stable shape.
		$this->load->helper('customer_analysis');
		$prof = json_decode((string) $row->details_json, true);
		$row->profile = customer_analysis_normalize_profile(is_array($prof) ? $prof : array());

		// Recommended tours (real products the AI matched to this customer).
		$rt = isset($row->recommended_tours) ? json_decode((string) $row->recommended_tours, true) : array();
		$row->recommended_tours = customer_analysis_normalize_recommended_tours(is_array($rt) ? $rt : array());

		// Structured customer-facing comparison (may be absent on legacy rows).
		$rc = (isset($row->recommendation_json) && $row->recommendation_json !== null)
			? json_decode((string) $row->recommendation_json, true) : array();
		$row->recommendation = customer_analysis_normalize_recommendation(is_array($rc) ? $rc : array());

		$si = json_decode((string) $row->sales_intel, true);
		if ( ! is_array($si)) {
			$si = array();
		}
		$row->sales_intel = array(
			'stage'                   => isset($si['stage']) ? (string) $si['stage'] : '',
			'sentiment'               => isset($si['sentiment']) ? (string) $si['sentiment'] : '',
			'language'                => isset($si['language']) ? (string) $si['language'] : '',
			'budget_signals'          => isset($si['budget_signals']) && is_array($si['budget_signals']) ? $si['budget_signals'] : array(),
			'interested_destinations' => isset($si['interested_destinations']) && is_array($si['interested_destinations']) ? $si['interested_destinations'] : array(),
			'interested_dates'        => isset($si['interested_dates']) && is_array($si['interested_dates']) ? $si['interested_dates'] : array(),
			'objections'              => isset($si['objections']) && is_array($si['objections']) ? $si['objections'] : array(),
		);
		$na = json_decode((string) $row->next_actions, true);
		$row->next_actions = is_array($na) ? $na : array();
		$kf = json_decode((string) $row->key_facts, true);
		$row->key_facts = is_array($kf) ? $kf : array();
	}
}
