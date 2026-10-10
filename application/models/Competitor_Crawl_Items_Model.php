<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Competitor_Crawl_Items_Model — durable store for the products a crawl discovers.
 *
 * A site crawl discovers many product pages; historically they lived ONLY in the
 * transient jobs/<id>.items.json file, so a lost/overwritten file meant the crawled
 * products vanished (nothing was in the DB until the user explicitly analysed them).
 * This model persists every crawled item at crawl time so the listing/Review can
 * read from the DB, with the file as a cache. Shared by both tools via the explicit
 * $feature argument ('competitor' | 'our_product'); the row's `idx` mirrors the
 * item's position in items.json (the key the Review page + status `analysed` map use).
 */
class Competitor_Crawl_Items_Model extends CI_Model
{
	/**
	 * Insert/refresh a crawl's items (rows from competitor_crawl_items_for_db()).
	 * Upsert on the (feature, job_id, idx) unique key so a chunked crawl writing the
	 * same job repeatedly (or a resumed run) refreshes content WITHOUT clobbering an
	 * item's analysis_id link. Returns affected row count.
	 */
	function Upsert_Many($feature, $rows)
	{
		$rows = is_array($rows) ? $rows : array();
		if (empty($rows)) {
			return 0;
		}
		$now      = date('Y-m-d H:i:s');
		$affected = 0;
		// Batch in chunks so one INSERT never approaches max_allowed_packet (item_text
		// is LONGTEXT; a big crawl is tens of MB). Each chunk is its own upsert.
		foreach (array_chunk($rows, 200) as $chunk) {
			$values = array();
			foreach ($chunk as $r) {
				$title = (isset($r['title']) && $r['title'] !== null && $r['title'] !== '')
					? $this->db->escape((string) $r['title']) : 'NULL';
				$text  = (isset($r['item_text']) && $r['item_text'] !== null)
					? $this->db->escape((string) $r['item_text']) : 'NULL';
				$values[] = '('
					. $this->db->escape((string) $feature) . ','
					. $this->db->escape((string) (isset($r['job_id']) ? $r['job_id'] : '')) . ','
					. $this->db->escape((string) (isset($r['host']) ? $r['host'] : '')) . ','
					. $this->db->escape((string) (isset($r['src_url']) ? $r['src_url'] : '')) . ','
					. (int) (isset($r['idx']) ? $r['idx'] : 0) . ','
					. $this->db->escape((string) (isset($r['url']) ? $r['url'] : '')) . ','
					. $title . ','
					. $text . ','
					. $this->db->escape($now)
					. ')';
			}
			$sql = 'INSERT INTO `competitor_crawl_items` '
				. '(`feature`, `job_id`, `host`, `src_url`, `idx`, `url`, `title`, `item_text`, `created_at`) VALUES '
				. implode(',', $values)
				. ' ON DUPLICATE KEY UPDATE '
				. '`host` = VALUES(`host`), `src_url` = VALUES(`src_url`), `url` = VALUES(`url`), '
				. '`title` = VALUES(`title`), `item_text` = VALUES(`item_text`)';
			$this->db->query($sql);
			$affected += $this->db->affected_rows();
		}
		return $affected;
	}

	/** One crawl's items, ordered by idx (Review reads this when the file is gone). */
	function Read_By_Job($feature, $job_id)
	{
		return $this->db->select('id, idx, url, title, item_text, analysis_id, src_url, host')
			->where('feature', (string) $feature)
			->where('job_id', (string) $job_id)
			->order_by('idx', 'ASC')
			->get('competitor_crawl_items')->result();
	}

	/** Link a crawled item to the analysis row it produced (called after Analyse). */
	function Mark_Analysed($feature, $job_id, $idx, $analysis_id)
	{
		$this->db->where('feature', (string) $feature)
			->where('job_id', (string) $job_id)
			->where('idx', (int) $idx)
			->update('competitor_crawl_items', array('analysis_id' => (int) $analysis_id));
		return $this->db->affected_rows();
	}

	/**
	 * Per-host crawled-product tally (latest crawl date per host) — lets the listing
	 * show a site's products even after every job file for that host is gone.
	 */
	function Count_By_Host($feature)
	{
		return $this->db->select('host, COUNT(*) AS cnt, MAX(created_at) AS last_at', false)
			->where('feature', (string) $feature)
			->where("host <> ''", null, false)
			->group_by('host')
			->get('competitor_crawl_items')->result();
	}

	/**
	 * One entry point per crawled host for the listing: DISTINCT product count (so
	 * re-crawls of the same site don't double-count), latest crawl time, a site URL,
	 * and best_job — the job that captured the most items, which Review opens. Feeds
	 * competitor_archived_host_rows() so a site stays reachable after its files vanish.
	 */
	function Read_Host_Entry_Points($feature)
	{
		$agg = $this->db->query(
			"SELECT host, COUNT(DISTINCT url) AS cnt, MAX(created_at) AS last_at, MAX(src_url) AS src_url "
			. "FROM competitor_crawl_items WHERE feature = ? AND host <> '' GROUP BY host",
			array((string) $feature))->result();
		$jobs = $this->db->query(
			"SELECT host, job_id, COUNT(*) AS c FROM competitor_crawl_items "
			. "WHERE feature = ? AND host <> '' GROUP BY host, job_id",
			array((string) $feature))->result();
		$best = array();
		foreach ($jobs as $j) {
			if ( ! isset($best[$j->host]) || (int) $j->c > $best[$j->host]['c']) {
				$best[$j->host] = array('job' => $j->job_id, 'c' => (int) $j->c);
			}
		}
		$out = array();
		foreach ($agg as $a) {
			$out[] = (object) array(
				'host'     => $a->host,
				'count'    => (int) $a->cnt,
				'last_at'  => $a->last_at,
				'src_url'  => $a->src_url,
				'best_job' => isset($best[$a->host]) ? $best[$a->host]['job'] : '',
			);
		}
		return $out;
	}

	/**
	 * Drop specific items of a crawl by their idx — keeps the DB in step when the
	 * user deletes crawled products on the Review page (otherwise a deleted item
	 * would resurrect from the DB fallback once the items file is gone, and its
	 * analysis_id would dangle after the analysis row is deleted).
	 */
	function Delete_Items_By_Idx($feature, $job_id, $indices)
	{
		$indices = is_array($indices) ? $indices : array();
		$clean   = array();
		foreach ($indices as $i) {
			if (is_numeric($i) && (int) $i >= 0) { $clean[] = (int) $i; }
		}
		if (empty($clean)) {
			return 0;
		}
		$this->db->where('feature', (string) $feature)
			->where('job_id', (string) $job_id)
			->where_in('idx', array_values(array_unique($clean)))
			->delete('competitor_crawl_items');
		return $this->db->affected_rows();
	}

	/** Drop a crawl's items (e.g. when its analyses are all deleted). */
	function Delete_By_Job($feature, $job_id)
	{
		$this->db->delete('competitor_crawl_items', array(
			'feature' => (string) $feature,
			'job_id'  => (string) $job_id,
		));
		return $this->db->affected_rows();
	}
}
