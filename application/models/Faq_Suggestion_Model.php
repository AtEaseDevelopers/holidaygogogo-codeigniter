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
	 * The suggestions belonging to one generation run (the run detail page), in
	 * the AI's priority order (most-helpful-first = ascending SuggestionID). Each
	 * row is decorated with QuestionCount and the "||"-joined Destinations names.
	 */
	function Read_Suggestions_For_Run($run_id)
	{
		$this->load->model('Faq_Model'); // Decode_Items() lives there
		$this->db->select('SuggestionID, Title, Description, Reason, DestinationIds, State, AcceptedFAQID, RunID, RunKey, Model, CostUsd, InsertBy, InsertDate, DraftSourcesJson');
		$this->db->from('faq_suggestions');
		$this->db->where('Status', 'Y');
		$this->db->where('RunID', (int) $run_id);
		$this->db->order_by('SuggestionID', 'ASC');
		$rows = $this->db->get()->result();
		return $this->Decorate_Suggestions($rows);
	}

	/** Share destination and source details between the run page and the paginated list. */
	function Decorate_Suggestions($rows)
	{
		if (!$rows) { return $rows; }
		$dest_names = $this->Destination_Name_Map();
		$ids = array();
		foreach ($rows as $row) { $ids[] = (int) $row->SuggestionID; }
		$evidence_by_suggestion = $this->Read_Evidence_For_Suggestions($ids);
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
			$row->Evidence = isset($evidence_by_suggestion[(int) $row->SuggestionID])
				? $evidence_by_suggestion[(int) $row->SuggestionID] : array();
			$citations = json_decode((string)($row->DraftSourcesJson ?? ''), true);
			foreach (is_array($citations) ? $citations : array() as $citation) {
				if (($citation['kind'] ?? '') !== 'knowledge') { continue; }
				$row->Evidence[] = (object)array('SourceType'=>'knowledge', 'SourceRef'=>$citation['reference'] ?? '',
					'SourceExcerpt'=>$citation['excerpt'] ?? '', 'SourceTitle'=>$citation['title'] ?? 'Knowledge Source');
			}
		}
		return $rows;
	}

	/**
	 * Source messages cited by one suggestion, for the edit screen. The run
	 * detail uses the batched variant below; keeping this public lets both pages
	 * show identical "Detected from" evidence.
	 */
	function Read_Evidence($suggestion_id)
	{
		$all = $this->Read_Evidence_For_Suggestions(array((int) $suggestion_id));
		return isset($all[(int) $suggestion_id]) ? $all[(int) $suggestion_id] : array();
	}

	/** Load source evidence for many suggestions in one query. */
	private function Read_Evidence_For_Suggestions($suggestion_ids)
	{
		$ids = array_values(array_filter(array_map('intval', (array) $suggestion_ids)));
		if (empty($ids)) {
			return array();
		}
		$this->db->select('fs.SuggestionID, fs.SourceType, fs.SourceRef, fs.GhlMessageID, fs.ChatFileID, fs.MessageIndex, fs.SourceExcerpt');
		$this->db->select('gm.conversation_id AS ConversationID, gm.contact_id AS ContactID, gm.direction AS MessageDirection, gm.from_number AS FromNumber, gm.to_number AS ToNumber, gm.date_added AS MessageDate');
		$this->db->select('chf.OriginalName AS ChatFileName, chf.dedup_key AS ChatContactKey');
		$this->db->select('sr.FileName AS RunFileName');
		$this->db->from('faq_suggestion_sources fs');
		$this->db->join('ghl_messages gm', 'gm.id = fs.GhlMessageID', 'left');
		$this->db->join('chat_history_files chf', 'chf.FileID = fs.ChatFileID', 'left');
		$this->db->join('faq_suggestion_runs sr', 'sr.RunID = fs.RunID', 'left');
		$this->db->where_in('fs.SuggestionID', $ids);
		$this->db->order_by('fs.SuggestionID', 'ASC');
		$this->db->order_by('fs.SourceID', 'ASC');
		$out = array();
		foreach ($this->db->get()->result() as $source) {
			$out[(int) $source->SuggestionID][] = $source;
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Runs — a run is one generation (from a date range of chats, or one
	// uploaded PDF). The listing shows runs; each opens to the suggestions
	// inside it (mirrors the Competitor Analysis history → detail flow).
	// ---------------------------------------------------------------------

	/** Recent generations for the suggestion filter; re-evaluations keep the original candidate's RunID. */
	function Read_Recent_Generation_Runs($limit = 20)
	{
		$this->load->helper('faq_suggestion');
		$runs = $this->db->select('RunID, Source, StartDate, EndDate, Mobile, FileName, InsertDate')
			->where('Status', 'Y')->where('Source !=', 'reevaluate')
			->order_by('RunID', 'DESC')->limit(max(1, (int) $limit))->get('faq_suggestion_runs')->result();
		foreach ($runs as $run) { $run->Scope = faq_suggestion_run_scope($run); }
		return $runs;
	}

	/**
	 * All active runs for the listing, newest first. Each row is decorated with
	 * a Scope label and a PendingCount (still-pending suggestions in that run).
	 */
	function Read_Runs()
	{
		$this->load->helper('faq_suggestion');
		$this->db->select('RunID, Source, StartDate, EndDate, Mobile, FileName, Model, CostUsd, Proposed, Created, RunState, ErrorMessage, InsertBy, InsertDate');
		// Cheap presence flag so the listing can show a "View input" button without
		// pulling the (potentially large) transcript into the grid query.
		$this->db->select("(CASE WHEN InputText IS NOT NULL AND InputText <> '' THEN 1 ELSE 0 END) AS HasInput", false);
		$this->db->from('faq_suggestion_runs');
		$this->db->where('Status', 'Y');
		$this->db->order_by('RunID', 'DESC');
		$runs = $this->db->get()->result();
		if (empty($runs)) {
			return $runs;
		}

		// One grouped query for the per-run pending counts (avoid N+1).
		$pending = array();
		$this->db->select('RunID, COUNT(*) AS c');
		$this->db->from('faq_suggestions');
		$this->db->where('Status', 'Y');
		$this->db->where_not_in('State', array('accepted','dismissed'));
		$this->db->where('RunID IS NOT NULL', null, false);
		$this->db->group_by('RunID');
		foreach ($this->db->get()->result() as $r) {
			$pending[(int) $r->RunID] = (int) $r->c;
		}

		foreach ($runs as $run) {
			$run->Scope        = faq_suggestion_run_scope($run);
			$run->PendingCount = isset($pending[(int) $run->RunID]) ? $pending[(int) $run->RunID] : 0;
		}
		return $runs;
	}

	/** One active run row (decorated with its Scope label), or null. */
	function Read_Run($run_id)
	{
		$this->load->helper('faq_suggestion');
		$this->db->where('RunID', (int) $run_id);
		$this->db->where('Status', 'Y');
		$run = $this->db->get('faq_suggestion_runs')->row();
		if ($run !== null) {
			$run->Scope = faq_suggestion_run_scope($run);
		}
		return $run;
	}

	/**
	 * Queue a run: create the row in the 'queued' state (the background worker,
	 * or the inline fallback, later moves it running → done/error). Returns RunID.
	 * $data: Source, and (chats) StartDate/EndDate/Mobile or (pdf) FileName/StoredName.
	 */
	function Queue_Run($data)
	{
		return $this->Create_Run($data + array('RunState' => 'queued'));
	}

	/** Create a run row (records the attempt before the AI call); returns RunID. */
	function Create_Run($data)
	{
		$admin_id = $this->session->userdata('admin_id');
		$now      = date('Y-m-d H:i:s');
		$row = array(
			'Source'     => isset($data['Source']) ? (string) $data['Source'] : 'chats',
			'StartDate'  => !empty($data['StartDate']) ? (string) $data['StartDate'] : null,
			'EndDate'    => !empty($data['EndDate'])   ? (string) $data['EndDate']   : null,
			'Mobile'     => isset($data['Mobile'])     && $data['Mobile']     !== '' ? (string) $data['Mobile']     : null,
			'FileName'   => isset($data['FileName'])   && $data['FileName']   !== '' ? (string) $data['FileName']   : null,
			'StoredName' => isset($data['StoredName']) && $data['StoredName'] !== '' ? (string) $data['StoredName'] : null,
			'RunState'   => isset($data['RunState']) ? (string) $data['RunState'] : 'queued',
			'Status'     => 'Y',
			'InsertBy'   => $admin_id,
			'InsertDate' => $now,
			'UpdateBy'   => $admin_id,
			'UpdateDate' => $now,
		);
		$this->db->insert('faq_suggestion_runs', $row);
		return (int) $this->db->insert_id();
	}

	/** Update a run's outcome fields (counts / cost / model / state / error). */
	function Update_Run($run_id, $data)
	{
		$allowed = array('Model', 'CostUsd', 'Proposed', 'Created', 'RunState', 'ErrorMessage', 'InputText');
		$row = array();
		foreach ($allowed as $k) {
			if (array_key_exists($k, $data)) {
				$row[$k] = $data[$k];
			}
		}
		if (empty($row)) {
			return false;
		}
		$row['UpdateBy']   = $this->session->userdata('admin_id');
		$row['UpdateDate'] = date('Y-m-d H:i:s');
		$this->db->where('RunID', (int) $run_id);
		return $this->db->update('faq_suggestion_runs', $row);
	}

	/** Soft-delete a run and every suggestion inside it. */
	function Delete_Run($run_id)
	{
		$this->load->model('Faq_Workspace_Model');
		$before=$this->Read_Run($run_id);
		$ids=array();
		foreach ($this->db->select('SuggestionID')->where('RunID',(int)$run_id)->get('faq_suggestions')->result() as $s) { $ids[]=(int)$s->SuggestionID; }
		$this->db->trans_start();
		if ($ids) {
			foreach ($ids as $id) { $this->Faq_Workspace_Model->Audit($id,null,'run_deleted',$before,array('RunID'=>(int)$run_id)); }
		}
		$run_id  = (int) $run_id;
		$admin_id = $this->session->userdata('admin_id');
		$now      = date('Y-m-d H:i:s');
		$this->db->where('RunID', $run_id);
		$this->db->update('faq_suggestions', array('Status' => 'N', 'UpdateBy' => $admin_id, 'UpdateDate' => $now));
		$this->db->where('RunID', $run_id);
		$this->db->update('faq_suggestion_runs', array('Status' => 'N', 'UpdateBy' => $admin_id, 'UpdateDate' => $now));
		$this->db->trans_complete(); return $this->db->trans_status();
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
			'State'          => isset($data['State']) ? $data['State'] : 'pending_context',
			'ReviewReason'   => isset($data['ReviewReason']) ? $data['ReviewReason'] : 'Add supporting information or re-evaluate the answer.',
			'RunKey'         => isset($data['RunKey']) ? (string) $data['RunKey'] : null,
			'RunID'          => isset($data['RunID']) ? (int) $data['RunID'] : null,
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

	/** Soft-delete (Status -> 'N'), the app-wide delete convention. */
	function Delete($id)
	{
		$this->load->model('Faq_Workspace_Model');
		$before=$this->Read($id);
		$this->db->trans_start();
		$this->Faq_Workspace_Model->Audit((int)$id,null,'candidate_deleted',$before,array('Status'=>'N'));
		$this->db->where('SuggestionID', (int) $id);
		$this->db->update('faq_suggestions', array(
			'Status'   => 'N',
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s'),
		));
		$this->db->trans_complete(); return $this->db->trans_status();
	}

	// ---------------------------------------------------------------------
	// Generation — the routine the manual "Generate" button runs: gather the
	// chosen date range of conversations (optionally for one mobile number),
	// ask OpenAI, store new candidates. Returns a summary array.
	// ---------------------------------------------------------------------

	/** Directory the uploaded PDFs are stored in (relative to FCPATH). */
	const UPLOAD_DIR = 'assets/upload/faq_suggestion/';

	/**
	 * Process one queued run (the background worker's body; also the inline
	 * fallback when exec() is unavailable). Reads the run's stored inputs, moves
	 * it running, calls the AI, stores the new candidates, and marks the run
	 * done / error. Never throws — the outcome is recorded on the run row.
	 * Returns ['created'=>int, 'proposed'=>int, 'reason'=>string, 'model'=>string,
	 *          'run_id'=>int].
	 */
	function Process_Run($run_id)
	{
		$this->load->helper('faq_suggestion');
		$this->load->helper('chat_history');
		$this->load->model('Faq_Model');

		$run_id = (int) $run_id;
		$run    = $this->Read_Run($run_id);
		if ($run === null) {
			return array('created' => 0, 'proposed' => 0, 'reason' => 'gone', 'model' => '', 'run_id' => $run_id);
		}
		$state = strtolower((string) $run->RunState);
		if ($state !== 'queued' && $state !== 'running') {
			// Already finished (e.g. a duplicate worker) — nothing to do.
			return array('created' => (int) $run->Created, 'proposed' => (int) $run->Proposed, 'reason' => '', 'model' => (string) $run->Model, 'run_id' => $run_id);
		}
		$this->Update_Run($run_id, array('RunState' => 'running'));

		$dest_map      = $this->Destination_Name_Map();
		$dest_names    = array_values($dest_map);
		// Existing answers let the AI compare actual coverage within package scope.
			$existing_faqs = $this->Existing_Faqs();
			$this->load->model('Faq_Workspace_Model');
		$knowledge=array('map'=>array(),'selection'=>null);

		try {
			if (strtolower((string) $run->Source) === 'pdf') {
				// Stream the PDF straight to OpenAI's Files API from disk (no base64,
				// nothing embedded in the JSON body) and reference it by id — this
				// keeps memory flat regardless of file size. The service falls back to
				// the base64 method on any Files-API failure; the memory_limit bump
				// below covers that fallback path defensively.
				@ini_set('memory_limit', faq_suggestion_memory_limit(get_env('FAQ_SUGGESTION_MEMORY_LIMIT')));
				$path = FCPATH . self::UPLOAD_DIR . basename((string) $run->StoredName);
				$ext  = strtolower(pathinfo((string) $run->StoredName, PATHINFO_EXTENSION));
					$this->load->library('FaqSuggestionService');
					$knowledge=$this->Faq_Workspace_Model->Knowledge_Input((string)$run->FileName);
					$source_map=array('D1'=>array('source_type'=>'uploaded_document','excerpt'=>'Uploaded document: '.$run->FileName,'ghl_message_id'=>null,'chat_file_id'=>null,'message_index'=>null));
					$prompt=faq_suggestion_build_file_prompt($dest_names,$existing_faqs,$this->max_suggestions(),$knowledge['input'],$knowledge['packages']);
					$this->Update_Run($run_id,array('InputText'=>$prompt['input']."\nAttached document: ".$run->FileName));
					$result = $this->faqsuggestionservice->suggest_file_path($path, $ext, (string) $run->FileName, $dest_names, $existing_faqs, $this->max_suggestions(),$knowledge['input'],$knowledge['packages']);
			} elseif (strtolower((string) $run->Source) === 'chatfile') {
				// Mine an uploaded WhatsApp export (.txt, or .zip of several) — parse
				// it into messages and reuse the same text path as the chats generator.
				@ini_set('memory_limit', faq_suggestion_memory_limit(get_env('FAQ_SUGGESTION_MEMORY_LIMIT')));
				$path       = FCPATH . self::UPLOAD_DIR . basename((string) $run->StoredName);
				$ext        = strtolower(pathinfo((string) $run->StoredName, PATHINFO_EXTENSION));
				$wa_rows    = $this->Chat_File_Messages($path, $ext);
				$built      = faq_suggestion_transcript_with_sources(array(), $wa_rows, $this->max_transcript_chars());
				$transcript = $built['text'];
				$source_map = $built['sources'];
				if (trim($transcript) === '') {
					$this->Update_Run($run_id, array('RunState' => 'done', 'Proposed' => 0, 'Created' => 0));
					return array('created' => 0, 'proposed' => 0, 'reason' => 'no_messages', 'model' => '', 'run_id' => $run_id);
				}
				// Keep the exact text handed to the AI so operators can review the
				// input a run's suggestions came from (stored before the call so it
				// survives an AI failure).
					$prompt=faq_suggestion_build_prompt($transcript,$dest_names,$existing_faqs,$this->max_suggestions());
					$this->Update_Run($run_id, array('InputText' => $prompt['input']));
					$this->load->library('FaqSuggestionService');
					$result = $this->faqsuggestionservice->suggest($transcript, $dest_names, $existing_faqs, $this->max_suggestions());
			} else {
				$start     = substr((string) $run->StartDate, 0, 10) . ' 00:00:00';
				$end       = substr((string) $run->EndDate, 0, 10) . ' 23:59:59';
				$phone_key = faq_suggestion_phone_key((string) $run->Mobile);
				$ghl_rows  = $this->Recent_Ghl_Messages($start, $end, $phone_key);
				$wa_rows   = $this->Recent_Wa_Messages($start, $end, $phone_key);
				$built      = faq_suggestion_transcript_with_sources($ghl_rows, $wa_rows, $this->max_transcript_chars());
				$transcript = $built['text'];
				$source_map = $built['sources'];
				if (trim($transcript) === '') {
					$this->Update_Run($run_id, array('RunState' => 'done', 'Proposed' => 0, 'Created' => 0));
					return array('created' => 0, 'proposed' => 0, 'reason' => 'no_messages', 'model' => '', 'run_id' => $run_id);
				}
				// Keep the exact text handed to the AI (see chat-file branch above).
					$prompt=faq_suggestion_build_prompt($transcript,$dest_names,$existing_faqs,$this->max_suggestions());
					$this->Update_Run($run_id, array('InputText' => $prompt['input']));
					$this->load->library('FaqSuggestionService');
					$result = $this->faqsuggestionservice->suggest($transcript, $dest_names, $existing_faqs, $this->max_suggestions());
			}
		} catch (Exception $e) {
			$this->Update_Run($run_id, array('RunState' => 'error', 'ErrorMessage' => $e->getMessage()));
			return array('created' => 0, 'proposed' => 0, 'reason' => 'error', 'model' => '', 'run_id' => $run_id, 'error' => $e->getMessage());
		}

		try { return $this->store_suggestions($run_id, $result, $dest_map, isset($source_map) ? $source_map : array(),$knowledge['map'],$knowledge['selection'],$existing_faqs) + array('run_id' => $run_id); }
		catch (Exception $e) {
			$this->Update_Run($run_id,array('RunState'=>'error','ErrorMessage'=>$e->getMessage(),'Model'=>$result['model'],'CostUsd'=>$result['cost_usd']));
			return array('created'=>0,'proposed'=>0,'reason'=>'error','model'=>$result['model'],'run_id'=>$run_id,'error'=>$e->getMessage());
		}
	}

	/**
	 * Parse the AI reply, store new facts and proposed updates for review
	 * inside $run_id, and update the run with its counts / cost /
	 * model. Shared by the chats and PDF generators. Returns
	 * ['created'=>int, 'proposed'=>int, 'reason'=>string, 'model'=>string].
	 */
	protected function store_suggestions($run_id, $result, $dest_map, $source_map = array(), $knowledge_map=array(), $knowledge_selection=null, $existing_faqs=array())
	{
		$this->load->model('Faq_Model');

		// name => id for resolving the AI's destination names back to CategoryIDs.
		$name_to_id = array();
		foreach ($dest_map as $id => $name) {
			$name_to_id[$name] = $id;
		}
		$parsed = faq_suggestion_parse_response($result['raw'], $name_to_id, $this->max_suggestions(), true);
		$existing_refs = faq_suggestion_existing_reference_map($existing_faqs);

		// Build every valid suggestion first, so the run's AI cost can be split
		// evenly across the rows actually stored (one AI call produces them all).
		$rows = array();
		foreach ($parsed as $s) {
			$built = isset($s['assessment'])?array('items'=>$s['items'],'error'=>null):Faq_Model::Build_Items(
				array_map(function ($it) { return $it['q']; }, $s['items']),
				array_map(function ($it) { return $it['a']; }, $s['items'])
			);
			if ($built['error'] !== null || empty($built['items'])) {
				continue;
			}
			$rows[] = array(
				'ReviewReason'   => 'Review the draft answer and supporting evidence.',
				'Title'          => $s['title'],
				'Description'    => Faq_Model::Encode_Items($built['items']),
				'Reason'         => isset($s['reason']) ? $s['reason'] : '',
				'DestinationIds' => implode(',', $s['destination_ids']),
				'SourceRefs'     => isset($s['source_refs']) && is_array($s['source_refs']) ? $s['source_refs'] : array(),
				'AssessmentCandidate'=>isset($s['assessment'])?$s:null,
				'ExistingFAQComparison'=>faq_suggestion_existing_match($s, $existing_refs),
			);
		}

		$run_key   = date('Y-m-d H:i');
		$cost_each = empty($rows) ? 0 : round(((float) $result['cost_usd']) / count($rows), 6);
		$created   = 0;
		foreach ($rows as $row) {
			$assessed=$row['AssessmentCandidate']; unset($row['AssessmentCandidate']);
			$comparison=$row['ExistingFAQComparison']; unset($row['ExistingFAQComparison']);
			$source_refs = $row['SourceRefs'];
			unset($row['SourceRefs']); // this is evidence metadata, not a faq_suggestions column
			$row['State']   = 'pending_context';
			$row['RunKey']  = $run_key;
			$row['RunID']   = (int) $run_id;
			$row['Model']   = $result['model'];
			$row['CostUsd'] = $cost_each;
			$suggestion_id = $this->Create($row);
			$this->load->model('Faq_Workspace_Model');
			$this->Faq_Workspace_Model->Audit($suggestion_id, null, 'extracted', null, $comparison ? $row + array('faq_comparison'=>$comparison) : $row);
			if ($assessed) { $this->Faq_Workspace_Model->Apply_Assessment($suggestion_id,$assessed,$source_map,$knowledge_map,null,$run_id,$knowledge_selection); }
			else { foreach ($source_refs as $ref) { if (isset($source_map[$ref])) { $this->Create_Source($suggestion_id, $run_id, $ref, $source_map[$ref]); } } }
			$created++;
		}

		$this->Update_Run($run_id, array(
			'Model'    => $result['model'],
			'CostUsd'  => (float) $result['cost_usd'],
			'Proposed' => count($parsed),
			'Created'  => $created,
			'RunState' => 'done',
		));

		return array(
			'created'  => $created,
			'proposed' => count($parsed),
			'reason'   => $created === 0 ? 'no_suggestions' : '',
			'model'    => $result['model'],
		);
	}

	/** Store a validated evidence link returned by the model for one suggestion. */
	function Create_Source($suggestion_id, $run_id, $ref, $source)
	{
		$this->db->insert('faq_suggestion_sources', array(
			'SuggestionID' => (int) $suggestion_id, 'RunID' => (int) $run_id, 'SourceRef' => (string) $ref,
			'SourceType' => (string) $source['source_type'],
			'GhlMessageID' => !empty($source['ghl_message_id']) ? (int) $source['ghl_message_id'] : null,
			'ChatFileID' => !empty($source['chat_file_id']) ? (int) $source['chat_file_id'] : null,
			'MessageIndex' => !empty($source['message_index']) ? (int) $source['message_index'] : null,
			'SourceExcerpt' => (string) $source['excerpt'],
		));
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
	 * How many FAQ suggestions to allow from a single AI reply. This ceiling is
	 * now handed to the model in the prompt (so it stops generating once it hits
	 * the cap instead of us paying for tokens we'd discard) AND enforced again at
	 * parse time as a safety backstop against a runaway reply — default 200 (well
	 * above the old hard 50 so genuine long-tail suggestions are no longer
	 * silently dropped). Override with FAQ_SUGGESTION_MAX_SUGGESTIONS; set 0 to
	 * leave it uncapped (the model is told to be EXHAUSTIVE and nothing is trimmed).
	 */
	protected function max_suggestions()
	{
		$n = get_env('FAQ_SUGGESTION_MAX_SUGGESTIONS');
		if ($n === null || $n === '' || !is_numeric($n)) {
			return 200;
		}
		return (int) $n; // an explicit 0 disables the cap
	}

	/**
	 * GHL messages inside [$start, $end], oldest first, shaped
	 * [['direction'=>, 'body'=>], ...]. Bounded so a busy window can't blow up
	 * memory before the transcript cap trims it. When $phone_key is a non-empty
	 * last-9-digits key, only messages to/from that number are returned.
	 */
	protected function Recent_Ghl_Messages($start, $end, $phone_key = '')
	{
		$this->db->select('id, direction, body, conversation_id');
		$this->db->from('ghl_messages');
		$this->db->where('date_added >=', $start);
		$this->db->where('date_added <=', $end);
		$this->db->where("TRIM(COALESCE(body, '')) !=", '');
		if ($phone_key !== '') {
			$like = $this->db->escape_like_str($phone_key);
			// from_number / to_number hold E.164 ("+60…"); match on the trailing
			// last-9 so any country-code formatting still resolves.
			$this->db->where("(from_number LIKE '%{$like}' ESCAPE '!' OR to_number LIKE '%{$like}' ESCAPE '!')", null, false);
		}
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
	 * WhatsApp chat exports uploaded inside [$start, $end], parsed into the
	 * message shape chat_history_parse() returns. "WA chats in range" = files the
	 * team added within the window (an export's own line timestamps use the device
	 * locale and are ambiguous to parse, so upload time is the reliable signal).
	 * When $phone_key is a non-empty last-9-digits key, only files whose dedup_key
	 * is that number are read. Reads at most FAQ_SUGGESTION_MAX_WA_FILES files
	 * (default 50).
	 */
	protected function Recent_Wa_Messages($start, $end, $phone_key = '')
	{
		$this->db->select('FileID, StoredName');
		$this->db->from('chat_history_files');
		$this->db->where('Status', 'Y');
		$this->db->where('CreatedAt >=', $start);
		$this->db->where('CreatedAt <=', $end);
		if ($phone_key !== '') {
			// chat_history_files.dedup_key IS the last-9-digits phone key.
			$this->db->where('dedup_key', $phone_key);
		}
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
			foreach (chat_history_parse($text) as $i => $m) {
				$m['source_type'] = 'whatsapp_history'; $m['chat_file_id'] = (int) $f->FileID; $m['message_index'] = $i + 1;
				$messages[] = $m;
			}
		}
		return $messages;
	}

	/**
	 * Parse a single uploaded chat export at $path into the message shape
	 * chat_history_parse() returns (the same rows Recent_Wa_Messages produces, so
	 * faq_suggestion_transcript() consumes them). A .txt is parsed directly; a .zip
	 * has every .txt entry parsed and concatenated (skipping macOS junk, oversized
	 * entries and empties, mirroring the guest chat-history importer). Throws if the
	 * file is unreadable or a zip can't be opened.
	 */
	protected function Chat_File_Messages($path, $ext)
	{
		$this->load->helper('chat_history');
		if (!is_file($path) || !is_readable($path)) {
			throw new Exception('Could not read the uploaded chat file.');
		}

		$messages = array();
		if ($ext === 'zip') {
			if (!class_exists('ZipArchive')) {
				throw new Exception('ZIP uploads are not supported on this server.');
			}
			$zip = new ZipArchive();
			if ($zip->open($path) !== true) {
				throw new Exception('Could not open the ZIP file.');
			}
			$per_file_max = 5 * 1024 * 1024; // same cap as a single .txt, guards zip bombs
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$entry = $zip->getNameIndex($i);
				if (!chat_history_zip_entry_is_txt($entry)) { continue; }
				$stat = $zip->statIndex($i);
				if ($stat && (int) $stat['size'] > $per_file_max) { continue; }
				$content = $zip->getFromIndex($i);
				if ($content === false || trim($content) === '') { continue; }
				foreach (chat_history_parse($content) as $message_index => $m) {
					$m['source_type'] = 'uploaded_chat_file'; $m['message_index'] = $message_index + 1; $m['conversation_key']='upload:'.hash('sha256',$entry);
					$messages[] = $m;
				}
			}
			$zip->close();
		} else {
			$text = (string) @file_get_contents($path);
			if (trim($text) !== '') {
				foreach (chat_history_parse($text) as $message_index => $m) {
					$m['source_type'] = 'uploaded_chat_file'; $m['message_index'] = $message_index + 1; $m['conversation_key']='upload:single';
					$messages[] = $m;
				}
			}
		}
		return $messages;
	}

	/** Reuse the saved upload for contextual expansion; staff do not upload again. */
	function Uploaded_Chat_Evidence($run_id)
	{
		$run=$this->Read_Run($run_id);
		if (!$run || $run->Source!=='chatfile') { return array(); }
		$path=FCPATH.self::UPLOAD_DIR.basename((string)$run->StoredName);
		$messages=$this->Chat_File_Messages($path,strtolower(pathinfo($path,PATHINFO_EXTENSION)));
		return faq_suggestion_transcript_with_sources(array(),$messages,0)['sources'];
	}

	/** Existing published and draft answers supplied for coverage comparison. */
	function Existing_Faqs()
	{
		$this->load->model('Faq_Model');

		$out = array();
		foreach ($this->db->select('FAQID, Title, Description')->where('Status', 'Y')->order_by('FAQID', 'ASC')->get('faq')->result() as $r) {
			$out[] = $this->existing_faq_entry($r->Title, $r->Description) + array('kind'=>'faq', 'reference'=>'F'.(int)$r->FAQID);
		}

		$this->db->select('Title, Description, State');
		$this->db->where('Status', 'Y');
		$this->db->where('State !=', 'dismissed');
		foreach ($this->db->get('faq_suggestions')->result() as $r) {
			$out[] = $this->existing_faq_entry($r->Title, $r->Description) + array('kind'=>'suggestion', 'state'=>(string)$r->State);
		}
		return $out;
	}

	/** Retain answer facts and scope while excluding display and staff audit metadata. */
	protected function existing_faq_entry($title, $description)
	{
		$questions = array();
		$items = array();
		foreach (Faq_Model::Decode_Items((string) $description) as $it) {
			$q = trim((string) (isset($it['q']) ? $it['q'] : ''));
			$a = trim((string) (isset($it['a']) ? $it['a'] : ''));
			if ($q !== '' || $a !== '') { $items[] = array('q'=>$q, 'a'=>$a); }
			if ($q !== '') {
				$questions[] = $q;
			}
		}
		return array('title' => (string) $title, 'questions' => $questions, 'items'=>$items);
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
