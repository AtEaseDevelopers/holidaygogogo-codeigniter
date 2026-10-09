<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Competitor_Analysis_Model — thin persistence for the competitor_analyses
 * table. The scraping + OpenAI work is done in the controller/service; this
 * layer only stores the finished record and reads it back for the listing and
 * the detail view. List fields arrive as PHP arrays and are stored as JSON.
 */
class Competitor_Analysis_Model extends CI_Model
{
	/**
	 * Which slice of the shared competitor_analyses table this model owns. The
	 * base model is the competitor tool ('competitor'); the "Our Product" tool
	 * subclasses this and sets 'our_product' so the two features store and list
	 * their runs independently while sharing one table (feature column). Every
	 * write tags the row and every read/aggregate scopes to it.
	 */
	protected $feature = 'competitor';

	/**
	 * The rich extraction fields that live inside the single details_json column
	 * (everything beyond the headline columns). Kept here so Create() stores and
	 * Read_One() decodes exactly the same set.
	 */
	private $detail_keys = array(
		'price_from', 'price_to', 'departure_city', 'flight_departure', 'flight_return',
		'difficulty', 'target_traveller', 'suitable_age', 'child_friendly', 'senior_friendly',
		'matched_product',
		'countries', 'cities', 'travel_months', 'themes', 'tour_styles', 'local_transport',
		'exclusions', 'hotels', 'shopping_stops', 'optional_tours', 'special_remarks',
		'scenic_highlights', 'usp', 'traveller_segments', 'meals', 'itinerary',
	);

	/**
	 * Insert one analysis. $data is the flat record from
	 * competitor_parse_ai_response() plus url/page_title/model/status. Returns
	 * the new row id.
	 */
	function Create($data)
	{
		$details = array();
		foreach ($this->detail_keys as $k) {
			if (array_key_exists($k, $data)) {
				$details[$k] = $data[$k];
			}
		}

		// A site crawl passes a 'products' array (one combined report row); a
		// single URL/upload passes the flat scalar/list fields (the classic row).
		$is_crawl = isset($data['products']) && is_array($data['products']);

		$row = array(
			'url'           => (string) (isset($data['url']) ? $data['url'] : ''),
			'page_title'    => isset($data['page_title']) ? $data['page_title'] : null,
			'product_name'  => isset($data['product_name']) ? $data['product_name'] : null,
			// User-supplied competitor name (label only — never sent to the AI); NULL when blank.
			'competitor_name' => (isset($data['competitor_name']) && $data['competitor_name'] !== '') ? $data['competitor_name'] : null,
			'tour_code'     => isset($data['tour_code']) ? $data['tour_code'] : null,
			'price'         => isset($data['price']) ? $data['price'] : null,
			'currency'      => isset($data['currency']) ? $data['currency'] : null,
			'destination'   => isset($data['destination']) ? $data['destination'] : null,
			'duration'      => isset($data['duration']) ? $data['duration'] : null,
			'inclusions'    => json_encode(isset($data['inclusions']) ? $data['inclusions'] : array(), JSON_UNESCAPED_UNICODE),
			'pros'          => json_encode(isset($data['pros']) ? $data['pros'] : array(), JSON_UNESCAPED_UNICODE),
			'cons'          => json_encode(isset($data['cons']) ? $data['cons'] : array(), JSON_UNESCAPED_UNICODE),
			'summary'       => isset($data['summary']) ? $data['summary'] : null,
			'comparison'    => isset($data['comparison']) ? $data['comparison'] : null,
			'details_json'  => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
			'products_json' => $is_crawl ? json_encode($data['products'], JSON_UNESCAPED_UNICODE) : null,
			'product_count' => $is_crawl ? count($data['products']) : (isset($data['product_count']) ? (int) $data['product_count'] : 0),
			'raw_json'      => isset($data['raw_json']) ? $data['raw_json'] : null,
			'model'         => isset($data['model']) ? $data['model'] : null,
			'input_tokens'  => isset($data['input_tokens']) ? (int) $data['input_tokens'] : 0,
			'output_tokens' => isset($data['output_tokens']) ? (int) $data['output_tokens'] : 0,
			'cost_usd'      => isset($data['cost_usd']) ? $data['cost_usd'] : 0,
			'status'        => isset($data['status']) ? $data['status'] : 'done',
			'error_message' => isset($data['error_message']) ? $data['error_message'] : null,
			'source'        => isset($data['source']) ? $data['source'] : null,
			'feature'       => $this->feature,
			'created_by'    => isset($data['created_by']) ? $data['created_by'] : null,
		);
		$this->db->insert('competitor_analyses', $row);
		return $this->db->insert_id();
	}

	/** Listing rows, newest first. */
	function Read_All()
	{
		$this->db->select('id, url, page_title, product_name, competitor_name, tour_code, price, currency, destination, duration, cost_usd, product_count, status, created_at');
		$this->db->where('feature', $this->feature);
		$this->db->order_by('id', 'DESC');
		return $this->db->get('competitor_analyses')->result();
	}

	/**
	 * Single (non-crawl) analyses — pasted PDF/image uploads and pasted text/links —
	 * shown as rows in the main results table alongside crawls. Tagged rows come from
	 * the `source` column; the url-prefix clause keeps legacy uploads (source NULL,
	 * filename url) while still excluding site-crawl / crawl-analysed http rows.
	 * Newest first, capped.
	 */
	function Read_Uploads($limit = 20)
	{
		$this->db->select('id, url, source, product_name, competitor_name, cost_usd, status, created_at');
		$this->db->where('feature', $this->feature);
		$this->db->where("(source IN ('upload','paste') OR (source IS NULL AND url NOT LIKE 'http%'))", null, false);
		$this->db->order_by('id', 'DESC');
		$this->db->limit((int) $limit);
		return $this->db->get('competitor_analyses')->result();
	}

	/**
	 * Crawl-analysed rows (a pasted/URL crawl, not an upload/paste) — the
	 * complement of Read_Uploads. These are re-hydrated into the results listing
	 * so a saved crawl analysis keeps showing when its crawl job files are
	 * missing. Newest first, capped.
	 */
	function Read_Crawl_Analyses($limit = 30)
	{
		$this->db->select('id, url, product_name, competitor_name, cost_usd, status, created_at');
		$this->db->where('feature', $this->feature);
		$this->db->where("url LIKE 'http%' AND (source IS NULL OR source NOT IN ('upload','paste'))", null, false);
		$this->db->order_by('id', 'DESC');
		$this->db->limit((int) $limit);
		return $this->db->get('competitor_analyses')->result();
	}

	/** Cumulative USD OpenAI spend across every stored analysis. */
	function Read_Total_Cost()
	{
		$row = $this->db->select('SUM(cost_usd) AS total', false)->where('feature', $this->feature)->get('competitor_analyses')->row();
		return $row && $row->total !== null ? (float) $row->total : 0.0;
	}

	/**
	 * One full row with list fields decoded back into arrays; null if missing.
	 * The rich details_json is unpacked onto the row so the view can read
	 * $row->countries, $row->itinerary, $row->meals, etc. directly. Every detail
	 * key is defaulted (list -> array, meals -> blank slots, scalar -> '') so
	 * older rows without details_json render cleanly.
	 */
	function Read_One($id)
	{
		$row = $this->db->get_where('competitor_analyses', array('id' => (int) $id, 'feature' => $this->feature))->row();
		if ( ! $row) {
			return null;
		}
		$row->inclusions = $this->decode_list($row->inclusions);
		$row->pros       = $this->decode_list($row->pros);
		$row->cons       = $this->decode_list($row->cons);

		// Combined site-crawl row: an array of full product records. Empty array
		// for classic single/upload rows so the view can branch on it.
		$products = json_decode((string) $row->products_json, true);
		$row->products = is_array($products) ? $products : array();

		$details = json_decode((string) $row->details_json, true);
		if ( ! is_array($details)) {
			$details = array();
		}
		$scalar_keys = array('price_from', 'price_to', 'departure_city', 'flight_departure',
			'flight_return', 'difficulty', 'target_traveller', 'suitable_age',
			'child_friendly', 'senior_friendly', 'matched_product');
		foreach ($this->detail_keys as $k) {
			if ($k === 'meals') {
				$m = isset($details['meals']) && is_array($details['meals']) ? $details['meals'] : array();
				$row->meals = array(
					'breakfast' => isset($m['breakfast']) ? $m['breakfast'] : '',
					'lunch'     => isset($m['lunch']) ? $m['lunch'] : '',
					'dinner'    => isset($m['dinner']) ? $m['dinner'] : '',
				);
			} elseif (in_array($k, $scalar_keys, true)) {
				$row->$k = isset($details[$k]) ? (string) $details[$k] : '';
			} else {
				$row->$k = isset($details[$k]) && is_array($details[$k]) ? $details[$k] : array();
			}
		}
		return $row;
	}

	/**
	 * Cached translation overlay for one language (from
	 * CompetitorAnalysisService::translate_analysis), or null if not translated
	 * yet. Stored as {"cn": {...}, ...} in the translations_json column.
	 */
	function Read_Translation($id, $lang)
	{
		$row = $this->db->select('translations_json')
			->get_where('competitor_analyses', array('id' => (int) $id, 'feature' => $this->feature))->row();
		if ( ! $row) {
			return null;
		}
		$all = json_decode((string) $row->translations_json, true);
		$lang = (string) $lang;
		return (is_array($all) && isset($all[$lang]) && is_array($all[$lang])) ? $all[$lang] : null;
	}

	/** Store (upsert) the translation overlay for one language, keeping the others. */
	function Save_Translation($id, $lang, $data)
	{
		$row = $this->db->select('translations_json')
			->get_where('competitor_analyses', array('id' => (int) $id, 'feature' => $this->feature))->row();
		if ( ! $row) {
			return false;
		}
		$all = json_decode((string) $row->translations_json, true);
		if ( ! is_array($all)) {
			$all = array();
		}
		$all[(string) $lang] = $data;
		$this->db->where('id', (int) $id)->where('feature', $this->feature)
			->update('competitor_analyses', array('translations_json' => json_encode($all, JSON_UNESCAPED_UNICODE)));
		return true;
	}

	function Delete($id)
	{
		$this->db->delete('competitor_analyses', array('id' => (int) $id, 'feature' => $this->feature));
		return $this->db->affected_rows() > 0;
	}

	private function decode_list($value)
	{
		$arr = json_decode((string) $value, true);
		return is_array($arr) ? $arr : array();
	}
}
