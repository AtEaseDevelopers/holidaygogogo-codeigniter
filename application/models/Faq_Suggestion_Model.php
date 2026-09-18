<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Faq_Suggestion_Model — persistence + generation for the "FAQ AI Suggestion"
 * feature (see helpers/faq_suggestion_helper.php for the pure transforms).
 *
 * A row in `faq_suggestions` is a candidate FAQ the AI mined from recent
 * WhatsApp / GHL conversations. It mirrors a FAQ's fields (Title, the same
 * Description JSON of sub-Q&A pairs, and destinations — stored inline as a CSV
 * of CategoryIDs). A reviewer edits it, then promotes it to a real `faq` row
 * (State -> accepted) or dismisses it. Status is the app-wide soft-delete flag.
 */
class Faq_Suggestion_Model extends CI_Model
{
	/** WhatsApp chat exports live here (same dir the Guests chat upload writes to). */
	const CHAT_DIR = 'assets/upload/chat_history/';

	/**
	 * Suggestions for the listing, newest first. $state filters by lifecycle
	 * ('pending' | 'accepted' | 'dismissed'); '' or 'all' returns every active
	 * (non-deleted) row. Each row is decorated with QuestionCount and the
	 * "||"-joined Destinations names for the view.
	 */
	function Read_Suggestions($state = 'pending')
	{
		$this->load->model('Faq_Model'); // Decode_Items() lives there
		$this->db->select('SuggestionID, Title, Description, Reason, DestinationIds, State, AcceptedFAQID, RunKey, Model, CostUsd, InsertBy, InsertDate');
		$this->db->from('faq_suggestions');
		$this->db->where('Status', 'Y');
		if (in_array($state, array('pending', 'accepted', 'dismissed'), true)) {
			$this->db->where('State', $state);
		}
		// Newest run on top, but WITHIN a run keep the AI's priority order
		// (most-helpful-first = ascending SuggestionID, the insert order).
		$this->db->order_by('RunKey', 'DESC');
		$this->db->order_by('SuggestionID', 'ASC');
		$rows = $this->db->get()->result();

		$dest_names = $this->Destination_Name_Map();
		foreach ($rows as $row) {
			$items = Faq_Model::Decode_Items($row->Description);
			$row->QuestionCount = count($items);
			$names = array();
			foreach (self::Parse_Id_Csv($row->DestinationIds) as $id) {
				if (isset($dest_names[$id])) {
					$names[] = $dest_names[$id];
				}
			}
			sort($names);
			$row->Destinations = implode('||', $names);
		}
		return $rows;
	}

	/** Count of pending suggestions (for the menu / listing badge). */
	function Count_Pending()
	{
		$this->db->where('Status', 'Y');
		$this->db->where('State', 'pending');
		return (int) $this->db->count_all_results('faq_suggestions');
	}

	/** One active suggestion row, or null. */
	function Read($id)
	{
		$this->db->where('SuggestionID', (int) $id);
		$this->db->where('Status', 'Y');
		return $this->db->get('faq_suggestions')->row();
	}

	function Create($data)
	{
		$admin_id = $this->session->userdata('admin_id');
		$now      = date('Y-m-d H:i:s');
		$row = array(
			'Title'          => (string) $data['Title'],
			'Description'    => (string) $data['Description'],
			'Reason'         => isset($data['Reason']) ? (string) $data['Reason'] : null,
			'DestinationIds' => isset($data['DestinationIds']) ? (string) $data['DestinationIds'] : null,
			'State'          => isset($data['State']) ? $data['State'] : 'pending',
			'RunKey'         => isset($data['RunKey']) ? (string) $data['RunKey'] : null,
			'Model'          => isset($data['Model']) ? (string) $data['Model'] : null,
			'CostUsd'        => isset($data['CostUsd']) ? $data['CostUsd'] : null,
			'Status'         => 'Y',
			'InsertBy'       => $admin_id,
			'InsertDate'     => $now,
			'UpdateBy'       => $admin_id,
			'UpdateDate'     => $now,
		);
		$this->db->insert('faq_suggestions', $row);
		return (int) $this->db->insert_id();
	}

	/** Update a suggestion's editable fields (Title / Description / DestinationIds). */
	function Update($id, $data)
	{
		$row = array(
			'Title'          => (string) $data['Title'],
			'Description'    => (string) $data['Description'],
			'DestinationIds' => isset($data['DestinationIds']) ? (string) $data['DestinationIds'] : null,
			'UpdateBy'       => $this->session->userdata('admin_id'),
			'UpdateDate'     => date('Y-m-d H:i:s'),
		);
		$this->db->where('SuggestionID', (int) $id);
		return $this->db->update('faq_suggestions', $row);
	}

	/**
	 * Move a suggestion through its lifecycle. When accepting, $faq_id links the
	 * real FAQ that was created from it (kept for audit).
	 */
	function Set_State($id, $state, $faq_id = null)
	{
		if (!in_array($state, array('pending', 'accepted', 'dismissed'), true)) {
			return false;
		}
		$row = array(
			'State'    => $state,
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s'),
		);
		if ($faq_id !== null) {
			$row['AcceptedFAQID'] = (int) $faq_id;
		}
		$this->db->where('SuggestionID', (int) $id);
		return $this->db->update('faq_suggestions', $row);
	}

	/** Soft-delete (Status -> 'N'), the app-wide delete convention. */
	function Delete($id)
	{
		$this->db->where('SuggestionID', (int) $id);
		return $this->db->update('faq_suggestions', array(
			'Status'   => 'N',
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s'),
		));
	}

	// ---------------------------------------------------------------------
	// Generation — the routine the cron (and the manual "Generate now" button)
	// run: gather the last $days of conversations, ask OpenAI, store new
	// candidates. Returns a summary array.
	// ---------------------------------------------------------------------

	/**
	 * Generate suggestions from the last $days of WhatsApp + GHL conversations.
	 * Returns ['created'=>int, 'proposed'=>int, 'reason'=>string, 'model'=>string].
	 * 'reason' is set when nothing was created ('no_messages' | 'no_suggestions').
	 * Never throws for an empty window; re-throws AI/config errors to the caller.
	 */
	function Generate($days = 3)
	{
		$this->load->helper('faq_suggestion');
		$this->load->helper('chat_history');
		$this->load->model('Faq_Model');

		$cutoff = faq_suggestion_cutoff(time(), $days);

		$ghl_rows = $this->Recent_Ghl_Messages($cutoff);
		$wa_rows  = $this->Recent_Wa_Messages($cutoff);

		$dest_map   = $this->Destination_Name_Map();          // id => name
		$dest_names = array_values($dest_map);

		$transcript = faq_suggestion_transcript($ghl_rows, $wa_rows, $this->max_transcript_chars());
		if (trim($transcript) === '') {
			return array('created' => 0, 'proposed' => 0, 'reason' => 'no_messages', 'model' => '');
		}

		$this->load->library('FaqSuggestionService');
		$result = $this->faqsuggestionservice->suggest($transcript, $dest_names);

		// name => id for resolving the AI's destination names back to CategoryIDs.
		$name_to_id = array();
		foreach ($dest_map as $id => $name) {
			$name_to_id[$name] = $id;
		}
		$parsed = faq_suggestion_parse_response($result['raw'], $name_to_id, 20);
		$parsed = faq_suggestion_filter_new($parsed, $this->Existing_Titles());

		// Build every valid suggestion first, so the run's AI cost can be split
		// evenly across the rows actually stored (one AI call produces them all).
		$rows = array();
		foreach ($parsed as $s) {
			$built = Faq_Model::Build_Items(
				array_map(function ($it) { return $it['q']; }, $s['items']),
				array_map(function ($it) { return $it['a']; }, $s['items'])
			);
			if ($built['error'] !== null || empty($built['items'])) {
				continue;
			}
			$rows[] = array(
				'Title'          => $s['title'],
				'Description'    => Faq_Model::Encode_Items($built['items']),
				'Reason'         => isset($s['reason']) ? $s['reason'] : '',
				'DestinationIds' => implode(',', $s['destination_ids']),
			);
		}

		$run_key   = date('Y-m-d H:i');
		$cost_each = empty($rows) ? 0 : round(((float) $result['cost_usd']) / count($rows), 6);
		$created   = 0;
		foreach ($rows as $row) {
			$row['State']    = 'pending';
			$row['RunKey']   = $run_key;
			$row['Model']    = $result['model'];
			$row['CostUsd']  = $cost_each;
			$this->Create($row);
			$created++;
		}

		return array(
			'created'  => $created,
			'proposed' => count($parsed),
			'reason'   => $created === 0 ? 'no_suggestions' : '',
			'model'    => $result['model'],
		);
	}

	/**
	 * Char budget for the (already noise-filtered + deduped) transcript sent to
	 * OpenAI. Default 200,000 chars ≈ ~50k tokens — a safe backstop that leaves
	 * ample context room for the instructions + reply on gpt-5.x/4o-class models,
	 * and comfortably fits a typical few-days window after reduction. Override
	 * with FAQ_SUGGESTION_MAX_CHARS (set 0 to disable the cap entirely).
	 */
	protected function max_transcript_chars()
	{
		$n = get_env('FAQ_SUGGESTION_MAX_CHARS');
		if ($n === null || $n === '' || !is_numeric($n)) {
			return 200000;
		}
		return (int) $n; // an explicit 0 disables the cap
	}

	/**
	 * GHL messages added on/after $cutoff, oldest first, shaped
	 * [['direction'=>, 'body'=>], ...]. Bounded so a busy window can't blow up
	 * memory before the transcript cap trims it.
	 */
	protected function Recent_Ghl_Messages($cutoff)
	{
		$this->db->select('direction, body');
		$this->db->from('ghl_messages');
		$this->db->where('date_added >=', $cutoff);
		$this->db->where("TRIM(COALESCE(body, '')) !=", '');
		$this->db->order_by('date_added', 'ASC');
		$this->db->order_by('id', 'ASC');
		// Uncapped by default; set FAQ_SUGGESTION_MAX_GHL > 0 to bound the rows read.
		$limit = (int) get_env('FAQ_SUGGESTION_MAX_GHL');
		if ($limit > 0) {
			$this->db->limit($limit);
		}
		$rows = $this->db->get()->result_array();
		return $rows;
	}

	/**
	 * WhatsApp chat exports uploaded on/after $cutoff, parsed into the message
	 * shape chat_history_parse() returns. "Recent WA chats" = files the team
	 * added within the window (an export's own line timestamps use the device
	 * locale and are ambiguous to parse, so upload time is the reliable signal).
	 * Reads at most FAQ_SUGGESTION_MAX_WA_FILES files (default 50).
	 */
	protected function Recent_Wa_Messages($cutoff)
	{
		$this->db->select('StoredName');
		$this->db->from('chat_history_files');
		$this->db->where('Status', 'Y');
		$this->db->where('CreatedAt >=', $cutoff);
		$this->db->order_by('FileID', 'DESC');
		// Uncapped by default; set FAQ_SUGGESTION_MAX_WA_FILES > 0 to bound files read.
		$cap = (int) get_env('FAQ_SUGGESTION_MAX_WA_FILES');
		if ($cap > 0) {
			$this->db->limit($cap);
		}
		$files = $this->db->get()->result();

		$messages = array();
		foreach ($files as $f) {
			$path = FCPATH . self::CHAT_DIR . basename((string) $f->StoredName);
			if (!is_file($path) || !is_readable($path)) {
				continue;
			}
			$text = (string) @file_get_contents($path);
			if ($text === '') {
				continue;
			}
			foreach (chat_history_parse($text) as $m) {
				$messages[] = $m;
			}
		}
		return $messages;
	}

	/**
	 * Titles already in use — active FAQs plus pending/accepted suggestions — so
	 * a run doesn't re-propose an FAQ that already exists or is awaiting review.
	 */
	function Existing_Titles()
	{
		$titles = array();
		foreach ($this->db->select('Title')->where('Status', 'Y')->get('faq')->result() as $r) {
			$titles[] = (string) $r->Title;
		}
		$this->db->select('Title');
		$this->db->where('Status', 'Y');
		$this->db->where_in('State', array('pending', 'accepted'));
		foreach ($this->db->get('faq_suggestions')->result() as $r) {
			$titles[] = (string) $r->Title;
		}
		return $titles;
	}

	/** Active destination categories as CategoryID => Name (IsDestination='YES'). */
	function Destination_Name_Map()
	{
		$this->db->select('CategoryID, Name');
		$this->db->where('IsDestination', 'YES');
		$this->db->where('Status', 'Y');
		$this->db->order_by('Name', 'ASC');
		$rows = $this->db->get('category')->result();
		$map = array();
		foreach ($rows as $row) {
			$map[(int) $row->CategoryID] = $row->Name;
		}
		return $map;
	}

	/** Turn a "3,7,9" CSV of ids into a clean list of positive ints. Pure. */
	public static function Parse_Id_Csv($raw)
	{
		$out = array();
		if (!is_string($raw) && !is_numeric($raw)) {
			return $out;
		}
		foreach (explode(',', (string) $raw) as $piece) {
			$id = (int) trim($piece);
			if ($id > 0 && !in_array($id, $out, true)) {
				$out[] = $id;
			}
		}
		return $out;
	}
}
