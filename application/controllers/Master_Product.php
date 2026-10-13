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
		if ($this->input->method() !== 'post') {
			redirect(base_url('Master_Product'));
			return;
		}
		$ids = $this->input->post('ids');
		if ( ! is_string($ids) || ! preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $ids)) {
			show_error('Select at least one product record to download.', 400);
			return;
		}
		@set_time_limit(180);
		$analyses = $this->Master_Product_Model->Read_For_Export(explode(',', $ids));
		if (empty($analyses)) {
			show_error('The selected product records are no longer available.', 404);
			return;
		}
		$this->load->helper('competitor_analysis');
		$this->load->library('CompetitorExcelExport');
		$book = $this->competitorexcelexport->analysis($analyses, 'en', true);
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment; filename="master-products-' . date('Ymd-His') . '.xlsx"');
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header('Pragma: no-cache');
		header('Expires: 0');
		$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);
		$writer->setPreCalculateFormulas(false);
		$writer->save('php://output');
		$book->disconnectWorksheets();
		exit;
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
