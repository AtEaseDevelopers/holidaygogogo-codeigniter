<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Competitor Product — an Owner-only (level 10) tool. The Owner pastes a
 * competitor product URL; we scrape the page, ask OpenAI to extract the product
 * and compare it against our own costing packages, then store and list the
 * result. GET renders the form + history; Analyze (AJAX POST) runs the pipeline;
 * View shows one saved analysis; Delete removes one.
 *
 * The scraping + OpenAI call live in libraries/CompetitorAnalysisService; the
 * pure transforms live in helpers/competitor_analysis_helper.
 */
class Competitor_Product extends MY_Controller
{
	function __construct()
	{
		parent::__construct();
		// Owner-only feature.
		if ((int) $this->session->level !== 10) {
			redirect(base_url('Booking'));
			return;
		}
		$this->load->model('Competitor_Analysis_Model');
		$this->load->helper('competitor_analysis');
	}

	function index()
	{
		$titles = array(
			'tab_title'        => 'HolidayGoGoGo | Competitor Product',
			'breadcrumb_title' => 'Competitor Product',
		);
		$this->load->view('layout/header', $titles);
		$this->load->view('competitor_product/index');
		$this->load->view('layout/footer');
	}

	/**
	 * Review a finished crawl's products and pick which to analyse. Reads the job's
	 * items.json and lists each product (title + url) with a checkbox.
	 */
	function Review()
	{
		$this->load->helper('competitor_analysis');
		$job_id = preg_replace('/[^A-Za-z0-9_]/', '', (string) $this->input->get('job'));
		$dir    = APPPATH . 'logs/competitor_crawl/jobs/';
		$status = json_decode((string) @file_get_contents($dir . $job_id . '.json'), true);
		$items  = json_decode((string) @file_get_contents($dir . $job_id . '.items.json'), true);
		if ($job_id === '' || ! is_array($status) || ! is_array($items)) {
			redirect(base_url('Competitor_Product'));
			return;
		}
		$analysed = isset($status['analysed']) && is_array($status['analysed']) ? $status['analysed'] : array();
		$products = array();
		foreach ($items as $i => $it) {
			$text = isset($it['text']) ? (string) $it['text'] : '';
			$url  = isset($it['url']) ? (string) $it['url'] : '';
			$a    = isset($analysed[(string) $i]) && is_array($analysed[(string) $i]) ? $analysed[(string) $i] : array();
			$title = (isset($it['title']) && trim((string) $it['title']) !== '')
				? trim((string) $it['title'])                 // real <h1> name from the crawl
				: competitor_item_title($text, $url);         // fallback (older crawls)
			$meta = competitor_item_meta($text, $title);
			$products[] = array(
				'i'           => $i,
				'url'         => $url,
				'title'       => $title,
				'duration'    => $meta['duration'],
				'snippet'     => $meta['snippet'],
				'kind'        => competitor_item_kind($text),   // 'package' (has price) | 'itinerary'
				'chars'       => mb_strlen($text, 'UTF-8'),
				'analysis_id' => isset($a['id']) ? (int) $a['id'] : 0,
				'cost'        => isset($a['cost']) ? (float) $a['cost'] : 0.0,
				'analysed_at' => isset($a['at']) ? (string) $a['at'] : '',
			);
		}
		$titles = array(
			'tab_title'        => 'HolidayGoGoGo | Competitor Product',
			'breadcrumb_title' => 'Competitor Product >> Review',
		);
		$this->load->view('layout/header', $titles);
		$this->load->view('competitor_product/review', array(
			'job'      => $job_id,
			'src_url'  => isset($status['url']) ? $status['url'] : '',
			'products' => $products,
			'has_ai'   => (bool) get_env('OPENAI_API_KEY'),
		));
		$this->load->view('layout/footer');
	}

	/**
	 * AJAX: analyse the SELECTED products of a crawl. Queues an `analyse` job that
	 * reuses the crawl's items.json for the chosen indices. Returns {job}.
	 */
	function Analyze_Selected()
	{
		if ( ! $this->input->is_ajax_request()) {
			redirect(base_url('Competitor_Product'));
			return;
		}
		$this->output->set_content_type('application/json');
		if (empty(get_env('OPENAI_API_KEY'))) {
			echo json_encode(array('success' => false, 'message' => 'OpenAI is not configured (OPENAI_API_KEY).'));
			return;
		}
		$src_id  = preg_replace('/[^A-Za-z0-9_]/', '', (string) $this->input->post('job'));
		$indices = $this->input->post('indices');
		$indices = is_array($indices) ? array_values(array_unique(array_map('intval', $indices))) : array();
		$dir     = APPPATH . 'logs/competitor_crawl/jobs/';
		$status  = json_decode((string) @file_get_contents($dir . $src_id . '.json'), true);
		$items_file = $dir . $src_id . '.items.json';
		if ($src_id === '' || ! is_array($status) || ! is_file($items_file)) {
			echo json_encode(array('success' => false, 'message' => 'Crawl not found.'));
			return;
		}
		if (empty($indices)) {
			echo json_encode(array('success' => false, 'message' => 'Select at least one product.'));
			return;
		}
		$job_id = $this->queue_job(array(
			'url'        => isset($status['url']) ? $status['url'] : '',
			'mode'       => 'analyse',
			'items_file' => $items_file,
			'indices'    => $indices,
			'src_job'    => $src_id,   // so the worker can mark these indices analysed
			'created_by' => $this->session->admin_id,
		));
		echo json_encode($job_id !== ''
			? array('success' => true, 'job' => $job_id)
			: array('success' => false, 'message' => 'Could not start the analysis.'));
	}

	/**
	 * AJAX: delete one OR MANY crawled products from a crawl's Review list (the
	 * per-row trash button posts a single index; "Delete Selected" posts several).
	 * Drops each item from the crawl's items.json (other indices stay put so the
	 * analysed-map keys remain valid) and, for any product already analysed,
	 * deletes its saved analysis row and refunds its cost from the running total.
	 * Returns {success}.
	 */
	function Delete_Crawl_Items()
	{
		if ( ! $this->input->is_ajax_request()) {
			redirect(base_url('Competitor_Product'));
			return;
		}
		$this->output->set_content_type('application/json');
		$job_id  = preg_replace('/[^A-Za-z0-9_]/', '', (string) $this->input->post('job'));
		$indices = $this->input->post('indices');
		$indices = is_array($indices) ? array_values(array_unique(array_map('intval', $indices))) : array();
		$dir     = APPPATH . 'logs/competitor_crawl/jobs/';
		$status_file = $dir . $job_id . '.json';
		$items_file  = $dir . $job_id . '.items.json';
		if ($job_id === '' || ! is_file($status_file) || ! is_file($items_file)) {
			echo json_encode(array('success' => false, 'message' => 'Crawl not found.'));
			return;
		}
		if (empty($indices)) {
			echo json_encode(array('success' => false, 'message' => 'Select at least one product.'));
			return;
		}
		$status = json_decode((string) @file_get_contents($status_file), true);
		$items  = json_decode((string) @file_get_contents($items_file), true);
		if ( ! is_array($status) || ! is_array($items)) {
			echo json_encode(array('success' => false, 'message' => 'Crawl not found.'));
			return;
		}
		$result = competitor_remove_crawl_items($items, $status, $indices);
		foreach ($result['deleted_analysis_ids'] as $aid) {
			$this->Competitor_Analysis_Model->Delete((int) $aid);
		}
		@file_put_contents($items_file, json_encode($result['items'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		@file_put_contents($status_file, json_encode($result['status'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		echo json_encode(array('success' => true));
	}

	/** AJAX: minimal state of a job (for the Review page to poll an analyse run). */
	function Job_State()
	{
		if ( ! $this->input->is_ajax_request()) {
			redirect(base_url('Competitor_Product'));
			return;
		}
		$this->output->set_content_type('application/json');
		$job_id = preg_replace('/[^A-Za-z0-9_]/', '', (string) $this->input->get('job'));
		$s = json_decode((string) @file_get_contents(APPPATH . 'logs/competitor_crawl/jobs/' . $job_id . '.json'), true);
		$state = is_array($s) && isset($s['state']) ? $s['state'] : 'unknown';
		// done/total drive the live "X / N" counter; results ({index:{id,cost,at}}) lets
		// the Review page update the analysed rows in place when the background job finishes.
		echo json_encode(array(
			'state'   => $state,
			'message' => is_array($s) ? competitor_job_progress_message($s) : '',
			'done'    => is_array($s) && isset($s['done'])  ? (int) $s['done']  : 0,
			'total'   => is_array($s) && isset($s['total']) ? (int) $s['total'] : 0,
			'results' => (is_array($s) && isset($s['results']) && is_array($s['results'])) ? $s['results'] : array(),
		));
	}

	/**
	 * AJAX: a pasted site/product URL runs as a BACKGROUND crawl job (discovers
	 * products, then auto-analyses with OpenAI when configured) — returns {job}. An
	 * uploaded PDF/image is analysed SYNCHRONOUSLY (you can't crawl a file) and
	 * returns {id} for redirect. Failures come back as {success:false, message:…}.
	 */
	function Analyze()
	{
		if ( ! $this->input->is_ajax_request()) {
			redirect(base_url('Competitor_Product'));
			return;
		}
		$this->output->set_content_type('application/json');

		// A POST bigger than post_max_size arrives with EMPTY $_POST and $_FILES — so a
		// too-large upload looks like "no file". Detect it and say so clearly.
		$clen = (int) $this->input->server('CONTENT_LENGTH');
		if (empty($_POST) && empty($_FILES) && $clen > 0) {
			$max = ini_get('upload_max_filesize');
			echo json_encode(array('success' => false, 'message' => 'The file is too large to upload (limit ' . $max . '). Please use a smaller file.'));
			return;
		}

		$has_file = isset($_FILES['file']) && ! empty($_FILES['file']['name'])
			&& isset($_FILES['file']['error']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE;

		// A file that hit the ini size cap comes through with an error code, not clean.
		if (isset($_FILES['file']['error']) && in_array((int) $_FILES['file']['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) {
			echo json_encode(array('success' => false, 'message' => 'The file is too large to upload (limit ' . ini_get('upload_max_filesize') . '). Please use a smaller file.'));
			return;
		}

		$url   = trim((string) $this->input->post('url'));
		$paste = trim((string) $this->input->post('paste'));

		// Pasted text (notes + links) → BACKGROUND analyse job (non-blocking). We
		// scrape each pasted link and browse JS pages with web_search, which can run
		// for minutes — doing it inline hit the web server's read timeout and showed
		// a false "Analysis Failed" even though the row saved. Queuing frees the
		// browser immediately; the row appears in Analysis Results when done.
		if ( ! $has_file && $paste !== '') {
			$job_id = $this->queue_job(array(
				'url'        => competitor_paste_source_label($paste),
				'mode'       => 'paste',
				'paste_text' => $paste,
				'created_by' => $this->session->admin_id,
			));
			if ($job_id !== '') {
				echo json_encode(array('success' => true, 'job' => $job_id));
				return;
			}
			// No process spawn available (exec disabled) → run inline as a last resort.
			@set_time_limit(600);
			$this->load->model('Product_Model');
			$this->load->helper('product_tour_fields');
			$our_products = competitor_format_our_products($this->Product_Model->Read_For_Comparison());
			$this->load->library('CompetitorAnalysisService');
			try {
				$record = $this->competitoranalysisservice->analyze_paste($paste, $our_products);
			} catch (Exception $e) {
				$this->Competitor_Analysis_Model->Create(array(
					'url'           => competitor_paste_source_label($paste),
					'source'        => 'paste',
					'status'        => 'error',
					'error_message' => $e->getMessage(),
					'created_by'    => $this->session->admin_id,
				));
				echo json_encode(array('success' => false, 'message' => $e->getMessage()));
				return;
			}
			$record['url']        = competitor_paste_source_label($paste);
			$record['source']     = 'paste';
			$record['status']     = 'done';
			$record['created_by'] = $this->session->admin_id;
			$id = $this->Competitor_Analysis_Model->Create($record);
			echo json_encode(array('success' => true, 'id' => $id));
			return;
		}

		if ( ! $has_file && ($url === '' || ! preg_match('#^https?://#i', $url))) {
			echo json_encode(array('success' => false, 'message' => 'Enter a valid http(s) URL, paste text/links, or upload a PDF/image.'));
			return;
		}

		// URL → background crawl + auto-analyse job (non-blocking; polled in the
		// history table). AI runs only when OPENAI_API_KEY is set.
		if ( ! $has_file) {
			$keyword  = trim((string) $this->input->post('keyword'));
			$ai_crawl = (string) $this->input->post('ai_crawl') === '1';
			$job_id = $this->start_crawl_job($url, $keyword, $ai_crawl);
			if ($job_id !== '') {
				echo json_encode(array('success' => true, 'job' => $job_id));
			} else {
				echo json_encode(array('success' => false, 'message' => 'Could not start the background crawl (process spawn unavailable).'));
			}
			return;
		}

		// File upload → BACKGROUND analyse job (non-blocking). The OpenAI vision call
		// can run for a minute; doing it inline hit the web server's read timeout and
		// showed a false "Analysis Failed" even though the row saved. We stage the
		// uploaded file, queue the job, and free the browser immediately; the row
		// appears in Analysis Results when done (the worker deletes the staged file).
		try {
			$upload = $this->receive_upload();      // throws on invalid file
		} catch (Exception $e) {
			echo json_encode(array('success' => false, 'message' => $e->getMessage()));
			return;
		}
		$source_label = $upload['orig_name'] !== '' ? $upload['orig_name'] : 'uploaded file';
		$job_id = $this->queue_job(array(
			'url'        => $source_label,
			'mode'       => 'upload',
			'file_path'  => $upload['full_path'],
			'file_ext'   => $upload['file_ext'],
			'created_by' => $this->session->admin_id,
		));
		if ($job_id !== '') {
			echo json_encode(array('success' => true, 'job' => $job_id));
			return;
		}

		// No process spawn available (exec disabled) → run inline as a last resort.
		@set_time_limit(600);
		$this->load->model('Product_Model');
		$this->load->helper('product_tour_fields');
		$our_products = competitor_format_our_products($this->Product_Model->Read_For_Comparison());
		$this->load->library('CompetitorAnalysisService');
		try {
			$record = $this->competitoranalysisservice->analyze_file($upload['full_path'], $upload['file_ext'], $our_products);
		} catch (Exception $e) {
			@unlink($upload['full_path']);
			$this->Competitor_Analysis_Model->Create(array(
				'url'           => $source_label,
				'source'        => 'upload',
				'status'        => 'error',
				'error_message' => $e->getMessage(),
				'created_by'    => $this->session->admin_id,
			));
			echo json_encode(array('success' => false, 'message' => $e->getMessage()));
			return;
		}
		@unlink($upload['full_path']);

		$record['url']        = $source_label;
		$record['source']     = 'upload';
		$record['status']     = 'done';
		$record['created_by'] = $this->session->admin_id;
		$id = $this->Competitor_Analysis_Model->Create($record);

		echo json_encode(array('success' => true, 'id' => $id));
	}

	/**
	 * Read every crawl-job status file into public views, pruning files older than
	 * 7 days along the way. Returns ['crawls' => [...crawl-mode views...], 'singles'
	 * => [...in-progress paste views...], 'running' => bool]. A FINISHED paste job
	 * is omitted (its saved DB row is folded in by the listing); transient analyse
	 * jobs are skipped. Each view carries a '_sort' (file mtime) for recency order.
	 */
	private function read_job_views()
	{
		$dir = APPPATH . 'logs/competitor_crawl/jobs/';
		$crawls  = array();
		$singles = array();
		$running = false;
		foreach (glob($dir . '*.json') ?: array() as $path) {
			if (substr($path, -11) === '.items.json') {
				continue;   // crawled-text sidecar, not a status file
			}
			if (filemtime($path) < time() - 7 * 86400) {
				@unlink($path);
				@unlink(preg_replace('/\.json$/', '.out', $path));
				@unlink(preg_replace('/\.json$/', '.items.json', $path));
				@unlink(preg_replace('/\.json$/', '.pid', $path));
				continue;
			}
			$s = json_decode((string) file_get_contents($path), true);
			if ( ! is_array($s)) {
				continue;
			}
			$mode = isset($s['mode']) ? $s['mode'] : 'crawl';
			// Analyse jobs are transient (driven from the Review page), and translate
			// jobs just cache a language overlay — neither is listed as its own
			// "Crawled Results" row.
			if ($mode === 'analyse' || $mode === 'translate') {
				continue;
			}
			// A FINISHED paste/upload job is shown via its saved DB row (folded in by
			// the listing), so skip the done status file to avoid a duplicate row.
			// Queued/running/error jobs still show (progress + error visibility).
			if (in_array($mode, array('paste', 'upload'), true) && (isset($s['state']) ? $s['state'] : '') === 'done') {
				continue;
			}
			$view = competitor_job_public_view($s);
			$view['_sort'] = filemtime($path);
			if (in_array($view['state'], array('queued', 'running'), true)) {
				$running = true;
			}
			if ($view['mode'] === 'crawl') {
				$crawls[] = $view;
			} else {
				$singles[] = $view;   // in-progress paste
			}
		}
		return array('crawls' => $crawls, 'singles' => $singles, 'running' => $running);
	}

	/**
	 * AJAX: the Analysis Results table (newest first). Crawl runs of the SAME
	 * website are MERGED into one row per host (competitor_group_crawl_jobs) — the
	 * per-run history lives behind the Timeline page — while pasted text/links and
	 * uploaded PDFs/images stay as their own rows. Prunes stale job files. Returns
	 * {jobs: [...], running: <bool>}.
	 */
	function Jobs_List()
	{
		if ( ! $this->input->is_ajax_request()) {
			redirect(base_url('Competitor_Product'));
			return;
		}
		$this->output->set_content_type('application/json');
		$collected = $this->read_job_views();
		$running   = $collected['running'];

		// One merged row per crawled website; recency = its latest run's timestamp.
		$jobs = competitor_group_crawl_jobs($collected['crawls']);
		foreach ($jobs as &$g) {
			$g['_sort'] = strtotime($g['ts']) ?: 0;
		}
		unset($g);
		// In-progress paste jobs stay as individual rows.
		$jobs = array_merge($jobs, $collected['singles']);

		// Re-hydrate crawl-analysed DB rows whose transient job file has been
		// pruned (files die at 7 days; the DB row is permanent) so they keep
		// showing — deduped by host against the still-live crawl group rows, whose
		// products remain reachable via Review/Timeline.
		$covered_hosts = array();
		foreach ($jobs as $g) {
			if ( ! empty($g['is_group']) && isset($g['host'])) {
				$covered_hosts[] = $g['host'];
			}
		}
		$jobs = array_merge($jobs, competitor_orphan_crawl_rows(
			$this->Competitor_Analysis_Model->Read_Crawl_Analyses(30), $covered_hosts, 'host'
		));

		// Fold in single (non-crawl) analyses — uploaded PDFs/images and pasted
		// text/links — so they share the one results table.
		foreach ($this->Competitor_Analysis_Model->Read_Uploads(20) as $u) {
			$ts       = (string) $u->created_at;
			$is_paste = ($u->source === 'paste');
			$jobs[] = array(
				'job'          => 'upload_' . (int) $u->id,
				'is_upload'    => true,                             // shares the DB-row (delete/view) path
				'is_paste'     => $is_paste,                        // paste vs file, for the tag/icon
				'analysis_id'  => (int) $u->id,
				'url'          => (string) $u->url,                 // file name, or the pasted source label
				'title'        => (string) $u->product_name,
				'state'        => ($u->status === 'error') ? 'error' : 'done',
				'message'      => $is_paste ? 'Analysed' : 'Uploaded',
				'count'        => 1,
				'analysed'     => 1,
				'cost_total'   => (float) $u->cost_usd,
				'ts'           => $ts,
				'keyword'      => '',
				'reviewable'   => false,
				'done'         => 1,
				'total'        => 1,
				'read_start'   => '',
				'_sort'        => strtotime($ts) ?: 0,
			);
		}

		usort($jobs, function ($a, $b) { return $b['_sort'] - $a['_sort']; });
		$jobs = array_slice($jobs, 0, 30);
		foreach ($jobs as &$j) { unset($j['_sort']); }

		echo json_encode(array('jobs' => $jobs, 'running' => $running));
	}

	/** Sanitise a ?host= param to a bare hostname (a-z 0-9 . -), lowercased. */
	private function clean_host($raw)
	{
		return strtolower(preg_replace('/[^a-z0-9.\-]/i', '', (string) $raw));
	}

	/**
	 * The Crawl Timeline for one website — every crawl RUN of that host, newest
	 * first, each with its own Review & Select. Reached from the merged Analysis
	 * Results row. The rows themselves load via Timeline_List so a running crawl
	 * keeps updating.
	 */
	function Timeline()
	{
		$host = $this->clean_host($this->input->get('host'));
		if ($host === '') {
			redirect(base_url('Competitor_Product'));
			return;
		}
		$titles = array(
			'tab_title'        => 'HolidayGoGoGo | Competitor Product',
			'breadcrumb_title' => 'Competitor Product >> Timeline',
		);
		$this->load->view('layout/header', $titles);
		$this->load->view('competitor_product/timeline', array('host' => $host));
		$this->load->view('layout/footer');
	}

	/**
	 * AJAX: the crawl RUNS for one website host (newest first) — the un-merged
	 * per-run views the Timeline page renders. Same shape as a single crawl row in
	 * Jobs_List, so each run keeps Review & Select / Terminate / Delete. Returns
	 * {jobs: [...runs...], running: <bool>}.
	 */
	function Timeline_List()
	{
		if ( ! $this->input->is_ajax_request()) {
			redirect(base_url('Competitor_Product'));
			return;
		}
		$this->output->set_content_type('application/json');
		$host = $this->clean_host($this->input->get('host'));
		$collected = $this->read_job_views();
		$runs = array();
		$running = false;
		foreach ($collected['crawls'] as $v) {
			if (competitor_job_host($v['url']) !== $host) {
				continue;
			}
			if (in_array($v['state'], array('queued', 'running'), true)) {
				$running = true;
			}
			$runs[] = $v;
		}
		usort($runs, function ($a, $b) { return $b['_sort'] - $a['_sort']; });
		foreach ($runs as &$r) { unset($r['_sort']); }
		unset($r);
		echo json_encode(array('jobs' => $runs, 'running' => $running));
	}

	/**
	 * AJAX: terminate a running crawl job and DROP it — kill the detached worker
	 * process (by the PID captured at spawn, plus its direct children e.g. headless
	 * Chrome) and delete all its job files so the row disappears.
	 */
	function Terminate_Job()
	{
		if ( ! $this->input->is_ajax_request()) {
			redirect(base_url('Competitor_Product'));
			return;
		}
		$this->output->set_content_type('application/json');
		$job_id = preg_replace('/[^A-Za-z0-9_]/', '', (string) $this->input->post('job'));
		if ($job_id === '') {
			echo json_encode(array('success' => false, 'message' => 'Job not found.'));
			return;
		}
		$dir = APPPATH . 'logs/competitor_crawl/jobs/';
		$pid = (int) @file_get_contents($dir . $job_id . '.pid');
		if ($pid > 0) {
			if (function_exists('exec')) {
				@exec('pkill -9 -P ' . escapeshellarg((string) $pid));   // children (e.g. Chrome)
			}
			if (function_exists('posix_kill')) {
				@posix_kill($pid, 9);
			} elseif (function_exists('exec')) {
				@exec('kill -9 ' . escapeshellarg((string) $pid));
			}
		}
		// An upload job stages the file for its (now-killed) worker to read; remove it
		// so terminating mid-analysis doesn't orphan it in assets/upload/.
		$s = json_decode((string) @file_get_contents($dir . $job_id . '.json'), true);
		if (is_array($s) && ! empty($s['file_path']) && is_file($s['file_path'])) {
			@unlink($s['file_path']);
		}
		foreach (array('.json', '.out', '.items.json', '.pid') as $ext) {
			@unlink($dir . $job_id . $ext);
		}
		echo json_encode(array('success' => true));
	}

	/**
	 * Queue a crawl and spawn the detached CLI worker. Returns the job id or ''
	 * when it can't be spawned.
	 */
	private function start_crawl_job($url, $keyword = '', $ai_crawl = false)
	{
		return $this->queue_job(array(
			'url'          => $url,
			'keyword'      => trim((string) $keyword),
			'ai_crawl'     => $ai_crawl ? 1 : 0,
			'created_by'   => $this->session->admin_id,
		));
	}

	/**
	 * Write a queued job status file (merging $status) and spawn the detached CLI
	 * worker (Competitor_Product_Job::run). Returns the job id, or '' when exec is
	 * unavailable / the process can't be launched.
	 */
	private function queue_job($status)
	{
		if ( ! function_exists('exec')) {
			return '';
		}
		$dir = APPPATH . 'logs/competitor_crawl/jobs/';
		if ( ! is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		try {
			$rand = bin2hex(random_bytes(4));
		} catch (Exception $e) {
			$rand = substr(md5(json_encode($status) . microtime(true)), 0, 8);
		}
		$job_id = date('Ymd_His') . '_' . $rand;
		$now = date('Y-m-d H:i:s');
		$row = array_merge(array('job' => $job_id, 'state' => 'queued', 'ts' => $now, 'created' => $now), (array) $status);
		@file_put_contents($dir . $job_id . '.json', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

		$php   = $this->php_cli_bin();
		$index = FCPATH . 'index.php';
		$out   = $dir . $job_id . '.out';
		// pcre.jit=0: the spawned (sandboxed) process can't allocate JIT executable
		// memory, which otherwise spams a PCRE-JIT warning; the interpreter is fine.
		// Controller name MUST match the file case exactly (Competitor_Product_Job) — Linux
		// filesystems are case-sensitive, so lowercase 'competitor_job' 404s there.
		// `& echo $!` prints the detached worker's PID so we can Terminate it later.
		$cmd = escapeshellarg($php) . ' -d pcre.jit=0 -d memory_limit=768M ' . escapeshellarg($index) . ' Competitor_Product_Job run ' . escapeshellarg($job_id)
			. ' > ' . escapeshellarg($out) . ' 2>&1 & echo $!';
		$pid = (int) @exec($cmd);
		if ($pid > 0) {
			@file_put_contents($dir . $job_id . '.pid', $pid);
		}
		return $job_id;
	}

	/**
	 * Resolve the CLI php binary. Under php-fpm PHP_BINARY is php-fpm (not usable as
	 * CLI), so prefer an explicit PHP_CLI_BIN, then PHP_BINDIR/php, then common
	 * paths. Falls back to bare 'php'.
	 */
	private function php_cli_bin()
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

	/**
	 * Move the posted `file` into a temp upload dir and return its CI upload
	 * data (full_path, orig_name, file_ext). Throws Exception on any failure so
	 * Analyze() can report it. Caller must unlink full_path when done.
	 */
	private function receive_upload()
	{
		$dir = FCPATH . 'assets/upload/competitor/';
		if ( ! is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$config = array(
			'upload_path'   => $dir,
			'allowed_types' => 'pdf|jpg|jpeg|png|gif|webp',
			'max_size'      => 20480,        // 20 MB
			'encrypt_name'  => true,          // avoid collisions; keeps extension
		);
		$this->load->library('upload', $config);
		$this->upload->initialize($config);
		if ( ! $this->upload->do_upload('file')) {
			throw new Exception(trim(strip_tags($this->upload->display_errors('', ''))));
		}
		return $this->upload->data();
	}

	function View()
	{
		$id = (int) $this->input->get('id');
		$analysis = $this->Competitor_Analysis_Model->Read_One($id);
		if ( ! $analysis) {
			redirect(base_url('Competitor_Product'));
			return;
		}
		$lang   = competitor_normalize_lang($this->input->get('lang'));
		$has_cn = $this->Competitor_Analysis_Model->Read_Translation($id, 'cn') !== null;
		if ($lang !== 'en') {
			$analysis = $this->apply_language($analysis, $id, $lang);
		}
		$titles = array(
			'tab_title'        => 'HolidayGoGoGo | Competitor Product',
			'breadcrumb_title' => 'Competitor Product >> View',
		);
		$this->load->view('layout/header', $titles);
		$this->load->view('competitor_product/view', array(
			'a'      => $analysis,
			'lang'   => $lang,
			'labels' => competitor_ui_labels($lang),
			'has_cn' => $has_cn,
		));
		$this->load->view('layout/footer');
	}

	/**
	 * AJAX: ensure a cached translation exists for {id, lang}. Generates it in the
	 * BACKGROUND (a detached CLI worker) so the language toggle never blocks on the
	 * slow OpenAI call — the caller polls Job_State and only lets the user switch to
	 * the translated language once it reports done. Returns {done:true} when the
	 * translation is already cached (instant switch), {job:<id>} when a background
	 * job was started, or {success:false, message} on error. Falls back to an inline
	 * (blocking) translate only when process spawn is unavailable.
	 */
	function Translate()
	{
		if ( ! $this->input->is_ajax_request()) {
			redirect(base_url('Competitor_Product'));
			return;
		}
		$this->output->set_content_type('application/json');

		$id   = (int) $this->input->post('id');
		$lang = competitor_normalize_lang($this->input->post('lang'));
		if ($lang === 'en') {
			echo json_encode(array('success' => true, 'done' => true));   // English is the stored original
			return;
		}
		if (empty(get_env('OPENAI_API_KEY'))) {
			echo json_encode(array('success' => false, 'message' => 'OpenAI is not configured (OPENAI_API_KEY).'));
			return;
		}
		$analysis = $this->Competitor_Analysis_Model->Read_One($id);
		if ( ! $analysis) {
			echo json_encode(array('success' => false, 'message' => 'Analysis not found.'));
			return;
		}
		// Already translated → nothing to do (cheap path; keeps the toggle instant).
		if ($this->Competitor_Analysis_Model->Read_Translation($id, $lang) !== null) {
			echo json_encode(array('success' => true, 'done' => true));
			return;
		}

		// Translate in the BACKGROUND so the toggle returns immediately; the view
		// polls Job_State and enables the 中文 button once the worker caches it.
		$job_id = $this->queue_job(array(
			'url'         => $analysis->product_name ?: $analysis->page_title,
			'mode'        => 'translate',
			'analysis_id' => $id,
			'lang'        => $lang,
			'created_by'  => $this->session->admin_id,
		));
		if ($job_id !== '') {
			echo json_encode(array('success' => true, 'job' => $job_id));
			return;
		}

		// No process spawn available (exec disabled) → translate inline as a last resort.
		@set_time_limit(600);
		$this->load->library('CompetitorAnalysisService');
		try {
			$overlay = $this->competitoranalysisservice->translate_analysis(
				competitor_display_products($analysis), $lang
			);
		} catch (Exception $e) {
			echo json_encode(array('success' => false, 'message' => $e->getMessage()));
			return;
		}
		$this->Competitor_Analysis_Model->Save_Translation($id, $lang, $overlay);
		echo json_encode(array('success' => true, 'done' => true));
	}

	/**
	 * Overlay the cached translation for $lang onto the analysis row so the view /
	 * PDF render in that language. Silently falls back to the English original when
	 * no translation is cached yet.
	 */
	private function apply_language($analysis, $id, $lang)
	{
		$tr = $this->Competitor_Analysis_Model->Read_Translation($id, $lang);
		if (is_array($tr)) {
			$analysis = competitor_apply_translation_to_row($analysis, $tr);
		}
		return $analysis;
	}

	/**
	 * Absolute path to a CJK-capable font for the Chinese PDF, from
	 * COMPETITOR_PDF_CJK_FONT in .env. MUST be a TrueType .ttf: the bundled DomPDF
	 * font lib mis-renders .otf/.cff (glyphs shift) and can't read .ttc
	 * collections, so those are rejected here (the PDF then renders with a Latin
	 * fallback rather than garbage). Returns '' when unset, missing or unsupported.
	 */
	private function pdf_cjk_font_path()
	{
		$path = trim((string) get_env('COMPETITOR_PDF_CJK_FONT'));
		if ($path === '' || ! is_file($path)) {
			return '';
		}
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return in_array($ext, array('ttf'), true) ? $path : '';
	}

	/**
	 * Stream a saved analysis as a downloadable PDF (same content as View(), laid
	 * out for DomPDF via the competitor_product/pdf template). Owner-only via the
	 * constructor gate.
	 */
	function Download_Pdf()
	{
		$id = (int) $this->input->get('id');
		$analysis = $this->Competitor_Analysis_Model->Read_One($id);
		if ( ! $analysis) {
			redirect(base_url('Competitor_Product'));
			return;
		}

		// Follow the language the user is viewing — overlay the cached translation
		// so the PDF matches the on-screen (translated) content.
		$lang = competitor_normalize_lang($this->input->get('lang'));
		if ($lang !== 'en') {
			$analysis = $this->apply_language($analysis, $id, $lang);
		}

		// DejaVu Sans (DomPDF's default) has no CJK glyphs, so a Chinese PDF would
		// render as empty boxes. When a CJK TrueType font is configured
		// (COMPETITOR_PDF_CJK_FONT in .env), register it and use it as the body font;
		// subsetting keeps the embedded output small. Latin falls back to DejaVu.
		$cjk_font = $this->pdf_cjk_font_path();
		$use_cjk  = ($lang !== 'en' && $cjk_font !== '');

		$html = $this->load->view('competitor_product/pdf', array(
			'a'        => $analysis,
			'lang'     => $lang,
			'labels'   => competitor_ui_labels($lang),
			'pdf_font' => $use_cjk ? 'cjk, "DejaVu Sans", sans-serif' : '"DejaVu Sans", sans-serif',
		), true);

		require_once APPPATH . 'libraries/dompdf/autoload.inc.php';
		$options = new \Dompdf\Options();
		$options->setIsRemoteEnabled(true);
		$options->setIsFontSubsettingEnabled(true);
		if ($use_cjk) {
			// Namespace the (writable) font cache per font file so switching the
			// configured font never mixes stale glyph metrics from a previous one.
			$font_dir = APPPATH . 'cache/dompdf_fonts/' . md5($cjk_font) . '/';
			if ( ! is_dir($font_dir)) { @mkdir($font_dir, 0755, true); }
			$options->setFontDir($font_dir);
			$options->setFontCache($font_dir);
			// registerFont resolves symlinks (e.g. /Library/Fonts → /System/...), so
			// the REAL directory must be in the chroot or the font silently won't load.
			$real = realpath($cjk_font);
			$options->setChroot(array_values(array_filter(array(
				FCPATH, APPPATH, dirname($cjk_font), $real ? dirname($real) : null
			))));
		}
		$dompdf = new \Dompdf\Dompdf($options);
		if ($use_cjk) {
			$fm = $dompdf->getFontMetrics();
			$fm->registerFont(array('family' => 'cjk', 'style' => 'normal', 'weight' => 'normal'), $cjk_font);
			$fm->registerFont(array('family' => 'cjk', 'style' => 'normal', 'weight' => 'bold'), $cjk_font);
		}
		$dompdf->loadHtml($html, 'UTF-8');
		$dompdf->setPaper('A4', 'portrait');
		$dompdf->render();

		// Emit like the other PDF endpoints (Costing_Quotation / Booking_Confirmation):
		// explicit headers + output() + exit, instead of DomPDF's stream() which
		// die()s on "headers already sent".
		$name = competitor_pdf_filename($analysis->product_name ?: $analysis->page_title, $analysis->id);
		header('Content-Type: application/pdf');
		header('Content-Disposition: attachment; filename="' . $name . '"');
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header('Pragma: no-cache');
		header('Expires: 0');
		echo $dompdf->output();
		exit;
	}

	function Delete()
	{
		if ( ! $this->input->is_ajax_request()) {
			redirect(base_url('Competitor_Product'));
			return;
		}
		$this->output->set_content_type('application/json');
		$ok = $this->Competitor_Analysis_Model->Delete((int) $this->input->post('id'));
		echo json_encode(array('success' => $ok));
	}
}
