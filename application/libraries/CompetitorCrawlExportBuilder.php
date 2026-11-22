<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Shared by the legacy download and background worker; never starts AI work. */
class CompetitorCrawlExportBuilder
{
    private $CI;
    public function __construct() { $this->CI =& get_instance(); }
    public function build($job, $indices = null, $progress = null)
    {
        $selected_only = $indices !== null;
        $selected_indices = $selected_only ? array_fill_keys($indices, true) : array();
        if ($progress) { call_user_func($progress, 'Loading saved products', 0, 0); }
		$dir = APPPATH . 'logs/competitor_crawl/jobs/';
		$status = json_decode((string) @file_get_contents($dir . $job . '.json'), true);
		$source = isset($status['url']) ? (string) $status['url'] : '';
		$this->CI->load->model('Competitor_Crawl_Items_Model');
		$this->CI->load->model('Competitor_Analysis_Model');
		$this->CI->db->save_queries = false;
		$stored = $this->CI->Competitor_Crawl_Items_Model->Read_By_Job('competitor', $job);
		if ( ! empty($stored)) {
			$shaped = competitor_items_from_db_rows($stored);
			$items = $shaped['items'];
			$analysed = $shaped['analysed'];
			if ($source === '') { $source = (string) $stored[0]->src_url; }
			unset($stored, $shaped);
		} else {
			$items = json_decode((string) @file_get_contents($dir . $job . '.items.json'), true);
			$analysed = isset($status['analysed']) && is_array($status['analysed']) ? $status['analysed'] : array();
		}
		if ( ! is_array($items) || empty($items)) { throw new RuntimeException('No saved products are available for this crawl.'); }
		if ($progress) { call_user_func($progress, 'Preparing products', 0, 0); }
		$prepared = competitor_prepare_crawl_items($items, $source, $analysed);
		$items = $prepared['items'];
		$crawl_summary = $prepared['summary'];
		if ($selected_only) {
			$mapped = array();
			foreach ($selected_indices as $idx => $_) {
				if (isset($prepared['index_map'][$idx])) { $mapped[$prepared['index_map'][$idx]] = true; }
			}
			$selected_indices = $mapped;
			// Preserve the stable crawl indices, including index 0 and gaps after deletion.
			$items = array_intersect_key($items, $selected_indices);
			$analysed = array_intersect_key($analysed, $items);
			if (empty($items)) {
				throw new RuntimeException('The selected crawled products are no longer available.');
			}
		}
		unset($prepared, $status);
		$ids = array();
		foreach ($items as $idx => $item) {
			if (isset($analysed[$idx]['id'])) { $ids[] = (int) $analysed[$idx]['id']; }
		}
		$analyses = $this->CI->Competitor_Analysis_Model->Read_By_Ids($ids);
		foreach ($analyses as $analysis) {
			foreach ($items as $idx => $item) {
				if (isset($analysed[$idx]['id']) && (int) $analysed[$idx]['id'] === (int) $analysis->id) {
					$analysis->crawl_metadata = $item['crawl_metadata'];
					break;
				}
			}
		}
		$this->CI->load->library('CompetitorExcelExport');
		$this->CI->competitorexcelexport->set_progress($progress);
		$book = $this->CI->competitorexcelexport->crawl($items, $analysed, $analyses, $job, $source, $selected_only, $crawl_summary);
        return $book;
    }
}
