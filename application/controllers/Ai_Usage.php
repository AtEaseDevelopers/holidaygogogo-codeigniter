<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Ai_Usage — an Owner-only (level 10) dashboard that reports the cost and token
 * usage of every AI feature in one place. Each AI service writes a row to the
 * ai_usage_log table per OpenAI call (see Ai_Usage_Model::Log); this page reads
 * those rows, applies the date/feature/model filters, and rolls them up via the
 * pure ai_usage_helper into totals and per-feature / per-model / per-user /
 * monthly breakdowns. Read-only — no AI is invoked here.
 */
class Ai_Usage extends MY_Controller
{
	function __construct()
	{
		parent::__construct();
		// Owner-only: AI spend is sensitive cost data.
		if ((int) $this->session->level !== 10) {
			redirect(base_url('Booking'));
			return;
		}
		$this->load->model('Ai_Usage_Model');
		$this->load->helper('ai_usage');
	}

	function index()
	{
		$filters = array(
			'date_from' => $this->clean_date($this->input->get('date_from')),
			'date_to'   => $this->clean_date($this->input->get('date_to')),
			'feature'   => trim((string) $this->input->get('feature')),
		);

		$rows = $this->Ai_Usage_Model->Read_Rows($filters);

		// The summary + breakdowns cover the whole filtered set; only the call log
		// is paginated (a slice of the same rows — no extra query needed).
		$per_page    = 25;
		$total       = count($rows);
		$total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;
		$page        = (int) $this->input->get('page');
		if ($page < 1) { $page = 1; }
		if ($page > $total_pages) { $page = $total_pages; }
		$offset   = ($page - 1) * $per_page;
		$log_rows = array_slice($rows, $offset, $per_page);

		$data = array(
			'log_rows'    => $log_rows,
			'summary'     => ai_usage_summary($rows),
			'by_feature'  => ai_usage_group($rows, 'feature'),
			'by_month'    => ai_usage_group($rows, 'month'),
			'all_features'=> $this->Ai_Usage_Model->Read_Distinct('feature'),
			'filters'     => $filters,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => $total_pages,
			'offset'      => $offset,
		);
		$titles = array(
			'tab_title'        => 'HolidayGoGoGo | AI Cost & Usage',
			'breadcrumb_title' => 'AI Cost & Usage',
		);
		$this->load->view('layout/header', $titles);
		$this->load->view('ai_usage/index', $data);
		$this->load->view('layout/footer');
	}

	/** Accept only a YYYY-MM-DD date; anything else becomes '' (no filter). */
	private function clean_date($v)
	{
		$v = trim((string) $v);
		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
	}
}
