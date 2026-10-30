<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Master_Product — an Owner-only (level 10) overview that lists EVERY analysed
 * record from both product tools (Competitor Product + Our Product) in one table.
 * Each row links View / Download PDF to its owning tool (by the row's
 * `feature`), and Delete removes it. The analyses themselves are produced by those
 * two tools; this page only aggregates and manages them.
 */
class Master_Product extends MY_Controller
{
	function __construct()
	{
		parent::__construct();
		// Owner-only feature.
		if ((int) $this->session->level !== 10) {
			redirect(base_url('Booking'));
			return;
		}
		$this->load->model('Master_Product_Model');
	}

	function index()
	{
		$titles = array(
			'tab_title'        => 'HolidayGoGoGo | Master Product',
			'breadcrumb_title' => 'Master Product',
		);
		$this->load->helper('competitor_analysis');   // competitor_flow_badge for the row badges
		$this->load->view('layout/header', $titles);
		$this->load->view('master_product/index', array(
			'records'    => $this->Master_Product_Model->Read_All(),
			'total_cost' => $this->Master_Product_Model->Read_Total_Cost(),
		));
		$this->load->view('layout/footer');
	}

	/** One workbook for the selected or filtered rows submitted by the listing. */
	function Download_Excel()
	{
		$this->export_selected('wide');
	}

	/**
	 * Same selected/filtered rows, but in the compact split-table "Tour Info" layout
	 * (summary sheet + a sheet each for itinerary / scenic / selling points / inclusion
	 * / exclusion / accommodation) — mirrors the per-analysis Tour Info export.
	 */
	function Download_Excel_Tables()
	{
		$this->export_selected('tables');
	}

	/** Shared export plumbing for both the wide and split-table workbook layouts. */
	private function export_selected($layout)
	{
		if ($this->input->method() !== 'post') {
			redirect(base_url('Master_Product'));
			return;
		}
		$ids = $this->input->post('ids');
		if ( ! is_string($ids) || ! preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $ids)) {
			show_error('Select at least one product record to download.', 400);
			return;
		}
		// The Tour Info flow is normally pre-extracted in the background (Extract_Flow);
		// this path still runs any remaining miss synchronously, so lift the time limit
		// entirely (no AI-call ceiling) + give it the worker's memory.
		@set_time_limit(0);
		@ini_set('memory_limit', '768M');
		$analyses = $this->Master_Product_Model->Read_For_Export(explode(',', $ids));
		if (empty($analyses)) {
			show_error('The selected product records are no longer available.', 404);
			return;
		}
		$this->load->helper('competitor_analysis');
		$this->load->library('CompetitorExcelExport');
		if ($layout === 'tables') {
			$this->load->library('CompetitorAnalysisService');
			$this->competitoranalysisservice->set_usage_feature('Competitor Tour Info Flow');
			$flow_map = $this->competitoranalysisservice->build_itinerary_flow_map($analyses, 'en');
			$book = $this->competitorexcelexport->analysis_tables($analyses, 'en', $flow_map);
		} else {
			$book = $this->competitorexcelexport->analysis($analyses, 'en', true);
		}
		$suffix = $layout === 'tables' ? '-tour-info' : '';
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment; filename="master-products' . $suffix . '-' . date('Ymd-His') . '.xlsx"');
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header('Pragma: no-cache');
		header('Expires: 0');
		$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);
		$writer->setPreCalculateFormulas(false);
		$writer->save('php://output');
		$book->disconnectWorksheets();
		exit;
	}

	/**
	 * AJAX: how many of the posted product ids still need an AI itinerary-flow
	 * extraction for the Tour Info export (uncached). Lets the dashboard warn only
	 * when an AI call will actually run. Read-only (no OpenAI).
	 */
	function Flow_Cache_Status()
	{
		$this->output->set_content_type('application/json');
		$ids = $this->input->post('ids');
		if ( ! is_string($ids) || ! preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $ids)) {
			echo json_encode(array('uncached' => 0, 'total' => 0));
			return;
		}
		$analyses = $this->Master_Product_Model->Read_For_Export(explode(',', $ids));
		$this->load->helper('competitor_analysis');
		$this->load->library('CompetitorAnalysisService');
		echo json_encode($this->competitoranalysisservice->itinerary_flow_cache_status($analyses, 'en'));
	}

	/**
	 * AJAX: kick off the Tour Info AI itinerary-flow extraction for the posted ids in
	 * the BACKGROUND so a multi-select export doesn't block the browser.
	 *   {success:true, done:true}      — all cached, download now
	 *   {success:true, job:"<id>"}     — queued; poll Job_State then download
	 *   {success:true, fallback:true}  — no spawn / no AI key, do the normal (blocking) download
	 */
	function Extract_Flow()
	{
		if ( ! $this->input->is_ajax_request()) { redirect(base_url('Master_Product')); return; }
		$this->output->set_content_type('application/json');
		$ids = $this->input->post('ids');
		if ( ! is_string($ids) || ! preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $ids)) {
			echo json_encode(array('success' => false, 'message' => 'Select at least one product.'));
			return;
		}
		$analyses = $this->Master_Product_Model->Read_For_Export(explode(',', $ids));
		$this->load->helper('competitor_analysis');
		$this->load->library('CompetitorAnalysisService');
		$status = $this->competitoranalysisservice->itinerary_flow_cache_status($analyses, 'en');
		if ((int) $status['uncached'] === 0) { echo json_encode(array('success' => true, 'done' => true)); return; }
		if (empty(get_env('OPENAI_API_KEY'))) { echo json_encode(array('success' => true, 'fallback' => true)); return; }
		$job_id = $this->queue_job(array(
			'url'        => 'Master Tour Info export',
			'mode'       => 'extract_flow',
			'ids'        => $ids,
			'lang'       => 'en',
			'created_by' => $this->session->admin_id,
		));
		echo json_encode($job_id !== ''
			? array('success' => true, 'job' => $job_id)
			: array('success' => true, 'fallback' => true));
	}

	/** AJAX: minimal state of a background job (mirrors Competitor_Product::Job_State). */
	function Job_State()
	{
		if ( ! $this->input->is_ajax_request()) { redirect(base_url('Master_Product')); return; }
		$this->output->set_content_type('application/json');
		$this->load->helper('competitor_analysis');
		$job_id = preg_replace('/[^A-Za-z0-9_]/', '', (string) $this->input->get('job'));
		$s = json_decode((string) @file_get_contents(APPPATH . 'logs/competitor_crawl/jobs/' . $job_id . '.json'), true);
		$state = is_array($s) && isset($s['state']) ? $s['state'] : 'unknown';
		echo json_encode(array(
			'state'   => $state,
			'message' => is_array($s) ? competitor_job_progress_message($s) : '',
			'done'    => is_array($s) && isset($s['done'])  ? (int) $s['done']  : 0,
			'total'   => is_array($s) && isset($s['total']) ? (int) $s['total'] : 0,
			'results' => (is_array($s) && isset($s['results']) && is_array($s['results'])) ? $s['results'] : array(),
		));
	}

	/**
	 * Spawn a detached background worker (same contract as
	 * Competitor_Product::queue_job — shared Competitor_Product_Job runner + job dir).
	 * Returns the job id, or '' when exec() is unavailable.
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
		$cmd = escapeshellarg($php) . ' -d pcre.jit=0 -d memory_limit=768M ' . escapeshellarg($index) . ' Competitor_Product_Job run ' . escapeshellarg($job_id)
			. ' > ' . escapeshellarg($out) . ' 2>&1 & echo $!';
		$pid = (int) @exec($cmd);
		if ($pid > 0) {
			@file_put_contents($dir . $job_id . '.pid', $pid);
		}
		return $job_id;
	}

	/** Resolve the CLI php binary (same fallback chain as Competitor_Product::php_cli_bin). */
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

	function Delete()
	{
		if ( ! $this->input->is_ajax_request()) {
			redirect(base_url('Master_Product'));
			return;
		}
		$this->output->set_content_type('application/json');
		$ok = $this->Master_Product_Model->Delete((int) $this->input->post('id'));
		echo json_encode(array('success' => $ok));
	}
}
