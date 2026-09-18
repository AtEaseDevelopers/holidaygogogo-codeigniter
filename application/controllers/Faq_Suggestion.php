<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * FAQ AI Suggestion — the review UI for the AI-mined candidate FAQs.
 *
 * Staff trigger a run from here (the "Generate" button): they pick a date range
 * and an optional mobile number, and the model reads that window of WhatsApp /
 * GHL conversations and stores candidate FAQs in `faq_suggestions`. They then
 * review each one, edit the same fields a FAQ carries, and Accept (create a real
 * FAQ, flag the suggestion 'accepted' for audit) or Dismiss. The heavy lifting
 * lives in Faq_Suggestion_Model + helpers/faq_suggestion_helper.php.
 *
 * Access mirrors the FAQ page: OWNER (level 10) always passes; every other role
 * needs 'FV' (FAQ VIEW) to see suggestions and 'FE' (FAQ EDIT) to edit / accept /
 * dismiss / delete and to trigger a manual generation.
 */
class Faq_Suggestion extends MY_Controller
{
	function __construct()
	{
		parent::__construct();
		$this->load->model('Faq_Suggestion_Model');
		$this->load->model('Faq_Model');
		$this->load->model('Faq_Tag_Model');
		$this->load->model('Universal_Model');
	}

	private function Can_View()
	{
		return (int) $this->session->level === 10 || in_array('FV', (array) $this->session->access_control);
	}

	private function Can_Edit()
	{
		return (int) $this->session->level === 10 || in_array('FE', (array) $this->session->access_control);
	}

	// Listing of generation runs (each run opens to the suggestions inside it,
	// mirroring the Competitor Analysis history → detail flow).
	function index()
	{
		if (!$this->Can_View()) {
			redirect(base_url('Dashboard'));
			return;
		}

		$titles = array('tab_title' => 'HolidayGoGoGo | FAQ AI Suggestion', 'breadcrumb_title' => 'FAQ AI Suggestion');
		$data['runs']          = $this->Faq_Suggestion_Model->Read_Runs();
		$data['pending_count'] = $this->Faq_Suggestion_Model->Count_Pending();
		$data['can_edit']      = $this->Can_Edit();
		$this->load->view('layout/header', $titles);
		$this->load->view('faq_suggestion/index', $data);
		$this->load->view('layout/footer');
	}

	// Detail of one run — its metadata plus the candidate FAQs it produced.
	function View()
	{
		if (!$this->Can_View()) {
			redirect(base_url('Dashboard'));
			return;
		}
		$run_id = (int) $this->input->get('id');
		$run    = $this->Faq_Suggestion_Model->Read_Run($run_id);
		if ($run === null) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}

		$titles = array('tab_title' => 'HolidayGoGoGo | FAQ AI Suggestion', 'breadcrumb_title' => 'FAQ AI Suggestion >> Run');
		$data['run']         = $run;
		$data['suggestions'] = $this->Faq_Suggestion_Model->Read_Suggestions_For_Run($run_id);
		$data['can_edit']    = $this->Can_Edit();
		$this->load->view('layout/header', $titles);
		$this->load->view('faq_suggestion/view', $data);
		$this->load->view('layout/footer');
	}

	function Update()
	{
		if (!$this->Can_Edit()) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}
		$id = (int) $this->input->get('id');

		if ($this->input->post()) {
			$post_id = (int) $this->input->post('suggestion_id');
			if (!$this->Universal_Model->Validate_Id('SuggestionID', $post_id, 'faq_suggestions')) {
				redirect(base_url('Faq_Suggestion'));
				return;
			}
			$error = $this->Save_From_Post($post_id);
			if ($error !== true) {
				$this->session->set_flashdata('faq_error', $error);
				redirect(base_url('Faq_Suggestion/Update?id=') . $post_id);
				return;
			}
			$this->session->set_flashdata('faq_success', 'Suggestion updated.');
			redirect($this->Run_Url($post_id));
			return;
		}

		$suggestion = $this->Faq_Suggestion_Model->Read($id);
		if ($suggestion === null) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}

		$titles = array('tab_title' => 'HolidayGoGoGo | FAQ AI Suggestion', 'breadcrumb_title' => 'FAQ AI Suggestion >> Edit');
		$data['suggestion'] = $suggestion;
		$data['run_url']    = $this->Run_Url($id);
		$data['items']      = Faq_Model::Decode_Items($suggestion->Description);
		$data['tags']       = $this->Faq_Tag_Model->Read_Active();
		$data['destinations'] = $this->Faq_Model->Read_Destinations();
		$data['selected_destination_ids'] = Faq_Suggestion_Model::Parse_Id_Csv($suggestion->DestinationIds);
		$this->load->view('layout/header', $titles);
		$this->load->view('faq_suggestion/form', $data);
		$this->load->view('layout/footer');
	}

	// Promote a suggestion to a real internal FAQ. Creates the FAQ from the
	// (possibly edited) suggestion fields, syncs its destinations, then flags the
	// suggestion 'accepted' with a link to the FAQ (kept for audit).
	function Accept()
	{
		if (!$this->Can_Edit()) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}
		$id = (int) $this->input->get('id');
		$suggestion = $this->Faq_Suggestion_Model->Read($id);
		if ($suggestion === null) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}
		$back = $this->Run_Url($suggestion);
		if ($suggestion->State === 'accepted') {
			$this->session->set_flashdata('faq_error', 'This suggestion has already been accepted.');
			redirect($back);
			return;
		}

		$title = trim((string) $suggestion->Title);
		if ($title === '' || trim((string) $suggestion->Description) === '') {
			$this->session->set_flashdata('faq_error', 'This suggestion has no title or questions to accept. Edit it first.');
			redirect(base_url('Faq_Suggestion/Update?id=') . $id);
			return;
		}

		// The suggestion's Description is already stored in the FAQ JSON format.
		// AI-suggested FAQs are published as 'external' type.
		$faq_id = $this->Faq_Model->Create(array(
			'Title'       => $title,
			'Slug'        => $this->Faq_Model->Generate_Slug($title, 0),
			'Description' => (string) $suggestion->Description,
			'Type'        => 'external',
		));
		$this->Faq_Model->Sync_Destinations($faq_id, Faq_Suggestion_Model::Parse_Id_Csv($suggestion->DestinationIds));
		$this->Faq_Suggestion_Model->Set_State($id, 'accepted', $faq_id);

		$this->session->set_flashdata('faq_success', 'FAQ created from suggestion.');
		redirect($back);
	}

	function Dismiss()
	{
		if (!$this->Can_Edit()) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}
		$id = (int) $this->input->get('id');
		$suggestion = $this->Faq_Suggestion_Model->Read($id);
		$back = ($suggestion !== null) ? $this->Run_Url($suggestion) : base_url('Faq_Suggestion');
		if ($suggestion !== null) {
			$this->Faq_Suggestion_Model->Set_State($id, 'dismissed');
			$this->session->set_flashdata('faq_success', 'Suggestion dismissed.');
		}
		redirect($back);
	}

	/**
	 * The run-detail URL a per-suggestion action returns to. Accepts a suggestion
	 * id (int, looked up) or a suggestion row; falls back to the runs listing
	 * when the suggestion has no run (legacy rows).
	 */
	private function Run_Url($suggestion)
	{
		if (!is_object($suggestion)) {
			$suggestion = $this->Faq_Suggestion_Model->Read((int) $suggestion);
		}
		$run_id = ($suggestion !== null && isset($suggestion->RunID)) ? (int) $suggestion->RunID : 0;
		return $run_id > 0
			? base_url('Faq_Suggestion/View?id=') . $run_id
			: base_url('Faq_Suggestion');
	}

	// Soft-delete, wired to the shared Delete_Record() ajax helper (GET ?id=).
	function Delete()
	{
		if (!$this->Can_Edit()) {
			return;
		}
		$id = (int) $this->input->get('id');
		if ($this->Faq_Suggestion_Model->Read($id) !== null) {
			$this->Faq_Suggestion_Model->Delete($id);
		}
	}

	// Manual "Generate" — runs the AI mining synchronously over the reviewer's
	// chosen date range, optionally scoped to a single mobile number.
	function Generate()
	{
		if (!$this->Can_Edit()) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}
		if (!$this->input->post()) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}

		$this->load->helper('faq_suggestion');
		$range = faq_suggestion_date_range($this->input->post('start_date'), $this->input->post('end_date'));
		if ($range['error'] !== '') {
			$this->session->set_flashdata('faq_error', $range['error']);
			redirect(base_url('Faq_Suggestion'));
			return;
		}
		$mobile = trim((string) $this->input->post('mobile'));

		$run_id = $this->Faq_Suggestion_Model->Queue_Run(array(
			'Source'    => 'chats',
			'StartDate' => substr($range['start'], 0, 10),
			'EndDate'   => substr($range['end'], 0, 10),
			'Mobile'    => $mobile,
		));

		$scope = date('j M Y', strtotime($range['start'])) . ' – ' . date('j M Y', strtotime($range['end']));
		if ($mobile !== '') {
			$scope .= ' for ' . $mobile;
		}
		$this->Dispatch_Run($run_id, 'chats', $scope);
		redirect(base_url('Faq_Suggestion'));
	}

	// Manual "Generate from PDF" — upload a document and mine FAQs from it.
	function Generate_Pdf()
	{
		if (!$this->Can_Edit()) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}
		if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
			$this->session->set_flashdata('faq_error', 'Please choose a PDF file to upload.');
			redirect(base_url('Faq_Suggestion'));
			return;
		}

		try {
			$info = $this->Receive_Pdf();
		} catch (Exception $e) {
			$this->session->set_flashdata('faq_error', $e->getMessage());
			redirect(base_url('Faq_Suggestion'));
			return;
		}

		$run_id = $this->Faq_Suggestion_Model->Queue_Run(array(
			'Source'     => 'pdf',
			'FileName'   => $info['orig_name'],
			'StoredName' => $info['file_name'],
		));
		$this->Dispatch_Run($run_id, 'pdf', $info['orig_name']);
		redirect(base_url('Faq_Suggestion'));
	}

	// Soft-delete a whole run (and its suggestions), wired to Delete_Record().
	function Delete_Run()
	{
		if (!$this->Can_Edit()) {
			return;
		}
		$id = (int) $this->input->get('id');
		if ($this->Faq_Suggestion_Model->Read_Run($id) !== null) {
			$this->Faq_Suggestion_Model->Delete_Run($id);
		}
	}

	/** Flash a success / failure message for a completed generation run. */
	private function Flash_Summary($summary, $source, $scope)
	{
		if ((int) $summary['created'] > 0) {
			$this->session->set_flashdata('faq_success', 'Generated ' . (int) $summary['created'] . ' new FAQ suggestion(s) from ' . ($source === 'pdf' ? 'PDF' : 'chats') . ' (' . $scope . ').');
		} elseif ($summary['reason'] === 'no_messages') {
			$this->session->set_flashdata('faq_error', 'No WhatsApp / GHL messages found (' . $scope . ').');
		} else {
			$this->session->set_flashdata('faq_error', 'No new suggestions this time (the AI found nothing beyond what already exists).');
		}
	}

	/**
	 * Run a queued run in the background (detached CLI worker) so the slow OpenAI
	 * call doesn't block the request. When exec() is unavailable the run is
	 * processed inline instead (blocks, but still completes). Flashes an
	 * appropriate message either way.
	 */
	private function Dispatch_Run($run_id, $source, $scope)
	{
		$this->Prune_Logs();
		if ($this->Spawn_Worker($run_id)) {
			$this->session->set_flashdata('faq_success', 'Generation started in the background from ' . ($source === 'pdf' ? 'PDF' : 'chats') . ' (' . $scope . '). This page updates as it finishes.');
			return;
		}
		// No process spawn available — process inline as a fallback.
		@set_time_limit(600);
		$summary = $this->Faq_Suggestion_Model->Process_Run($run_id);
		$this->Flash_Summary($summary, $source, $scope);
	}

	/**
	 * Spawn the detached CLI worker for a run. Returns true if launched, false
	 * when exec() is unavailable (caller then falls back to inline processing).
	 */
	private function Spawn_Worker($run_id)
	{
		if (!function_exists('exec')) {
			return false;
		}
		$log_dir = APPPATH . 'logs/faq_suggestion/';
		if (!is_dir($log_dir)) {
			@mkdir($log_dir, 0755, true);
		}
		$php   = $this->Php_Cli_Bin();
		$index = FCPATH . 'index.php';
		$out   = $log_dir . 'run_' . (int) $run_id . '.out';
		// pcre.jit=0 silences a PCRE-JIT warning in the sandboxed child; the
		// controller name MUST match the file case (Linux is case-sensitive).
		// `& echo $!` backgrounds the worker and prints its PID.
		$cmd = escapeshellarg($php) . ' -d pcre.jit=0 -d memory_limit=512M '
			. escapeshellarg($index) . ' Faq_Suggestion_Job run ' . escapeshellarg((string) (int) $run_id)
			. ' > ' . escapeshellarg($out) . ' 2>&1 & echo $!';
		$pid = (int) @exec($cmd);
		return $pid > 0;
	}

	/**
	 * Delete worker log (.out) files older than 3 days, at most once per day.
	 * A tiny stamp file throttles it so this runs (opportunistically, on the next
	 * generation of the day) without needing a server crontab.
	 */
	private function Prune_Logs()
	{
		$dir = APPPATH . 'logs/faq_suggestion/';
		if (!is_dir($dir)) {
			return;
		}
		// Throttle to once per calendar day.
		$stamp = $dir . '.last_prune';
		$today = date('Y-m-d');
		if (is_file($stamp) && trim((string) @file_get_contents($stamp)) === $today) {
			return;
		}
		@file_put_contents($stamp, $today);

		$this->load->helper('faq_suggestion');
		$files = array();
		foreach ((array) glob($dir . 'run_*.out') as $path) {
			$files[] = array('path' => $path, 'mtime' => (int) @filemtime($path));
		}
		foreach (faq_suggestion_logs_to_prune($files, time(), 3) as $path) {
			@unlink($path);
		}
	}

	/** Resolve the PHP CLI binary (env override, then common paths, then 'php'). */
	private function Php_Cli_Bin()
	{
		$env = get_env('PHP_CLI_BIN');
		if ($env) {
			return $env;
		}
		$cands = array();
		if (defined('PHP_BINDIR') && PHP_BINDIR) {
			$cands[] = rtrim(PHP_BINDIR, '/') . '/php';
		}
		$cands = array_merge($cands, array('/opt/homebrew/bin/php', '/usr/local/bin/php', '/usr/bin/php'));
		foreach ($cands as $c) {
			if (@is_executable($c)) {
				return $c;
			}
		}
		return 'php';
	}

	// Ajax poll for the runs listing: returns each run's live status so the page
	// can update in place while a background run is queued / running.
	function Runs_Status()
	{
		if (!$this->input->is_ajax_request()) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}
		// Read-only endpoint — release the session lock so polling doesn't
		// serialize behind other requests from the same session.
		if (function_exists('session_write_close')) {
			@session_write_close();
		}
		$this->output->set_content_type('application/json');
		if (!$this->Can_View()) {
			echo json_encode(array('running' => false, 'runs' => array()));
			return;
		}

		$running = false;
		$out = array();
		foreach ($this->Faq_Suggestion_Model->Read_Runs() as $r) {
			$state = strtolower((string) $r->RunState);
			if ($state === 'queued' || $state === 'running') {
				$running = true;
			}
			$out[] = array(
				'RunID'        => (int) $r->RunID,
				'RunState'     => $state,
				'Source'       => strtolower((string) $r->Source),
				'Created'      => (int) $r->Created,
				'Proposed'     => (int) $r->Proposed,
				'PendingCount' => (int) $r->PendingCount,
				'CostUsd'      => ($r->CostUsd === null || $r->CostUsd === '') ? null : (float) $r->CostUsd,
				'ErrorMessage' => (string) $r->ErrorMessage,
			);
		}
		echo json_encode(array('running' => $running, 'runs' => $out));
	}

	/**
	 * Store the uploaded document and return CI's upload data (full_path,
	 * orig_name, file_ext). Throws Exception with a human message on any failure.
	 */
	private function Receive_Pdf()
	{
		$dir = FCPATH . 'assets/upload/faq_suggestion/';
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$config = array(
			'upload_path'   => $dir,
			'allowed_types' => 'pdf',
			'max_size'      => 20480, // 20 MB
			'encrypt_name'  => true,
		);
		$this->load->library('upload', $config);
		$this->upload->initialize($config);
		if (!$this->upload->do_upload('file')) {
			throw new Exception(trim(strip_tags($this->upload->display_errors('', ' '))));
		}
		return $this->upload->data();
	}

	// Returns true on success, or an error message string on failure. Reuses the
	// FAQ item builder (tags + reference links, no per-item audit trail).
	private function Save_From_Post($id)
	{
		$title = trim((string) $this->input->post('Title'));
		if ($title === '') {
			return 'Failed to save. Title is required.';
		}

		$sub_tags = $this->input->post('sub_tags');
		$sub_tags = is_array($sub_tags) ? array_values($sub_tags) : array();
		$link_labels = $this->input->post('sub_link_labels');
		$link_urls   = $this->input->post('sub_link_urls');
		$link_labels = is_array($link_labels) ? array_values($link_labels) : array();
		$link_urls   = is_array($link_urls)   ? array_values($link_urls)   : array();

		$built = Faq_Model::Build_Items(
			$this->input->post('sub_questions'),
			$this->input->post('sub_answers'),
			null, '', '',
			$sub_tags, $link_labels, $link_urls
		);
		if ($built['error'] !== null) {
			return $built['error'];
		}
		if (empty($built['items'])) {
			return 'Add at least one sub-question and answer.';
		}

		$destination_ids = Faq_Model::Normalize_Ids($this->input->post('Destinations'));
		$this->Faq_Suggestion_Model->Update($id, array(
			'Title'          => $title,
			'Description'    => Faq_Model::Encode_Items($built['items']),
			'DestinationIds' => implode(',', $destination_ids),
		));
		return true;
	}
}
