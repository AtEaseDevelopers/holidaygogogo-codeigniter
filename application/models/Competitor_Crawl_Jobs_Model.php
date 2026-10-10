<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Competitor_Crawl_Jobs_Model — durable record of each crawl RUN (one row per job),
 * so the Analysis Results listing can survive the loss of the transient status files.
 * The file remains the live/working copy (per-page progress, resume, crash detection);
 * this table only captures the lasting facts (host, final state + product count, cost,
 * keyword) written at the queued/done/error milestones — NOT on every progress tick.
 * Shared by both tools via the explicit $feature argument.
 */
class Competitor_Crawl_Jobs_Model extends CI_Model
{
	/**
	 * Insert/refresh one crawl job's durable record (upsert on feature+job_id).
	 * created_at is only set on first insert; later milestone writes keep it.
	 */
	function Upsert_Job($feature, $row)
	{
		$row = is_array($row) ? $row : array();
		$job_id = (string) (isset($row['job_id']) ? $row['job_id'] : '');
		if ($job_id === '') {
			return 0;
		}
		$now = date('Y-m-d H:i:s');
		$sql = 'INSERT INTO `competitor_crawl_jobs` '
			. '(`feature`, `job_id`, `host`, `src_url`, `mode`, `state`, `product_count`, '
			. '`keyword`, `ai_crawl`, `competitor_name`, `cost_total`, `created_at`, `updated_at`) VALUES ('
			. $this->db->escape((string) $feature) . ','
			. $this->db->escape($job_id) . ','
			. $this->db->escape((string) (isset($row['host']) ? $row['host'] : '')) . ','
			. $this->db->escape((string) (isset($row['src_url']) ? $row['src_url'] : '')) . ','
			. $this->db->escape((string) (isset($row['mode']) ? $row['mode'] : 'crawl')) . ','
			. $this->db->escape((string) (isset($row['state']) ? $row['state'] : 'queued')) . ','
			. (int) (isset($row['product_count']) ? $row['product_count'] : 0) . ','
			. $this->db->escape((string) (isset($row['keyword']) ? $row['keyword'] : '')) . ','
			. (int) (! empty($row['ai_crawl']) ? 1 : 0) . ','
			. ((isset($row['competitor_name']) && $row['competitor_name'] !== '') ? $this->db->escape((string) $row['competitor_name']) : 'NULL') . ','
			. (float) (isset($row['cost_total']) ? $row['cost_total'] : 0) . ','
			. $this->db->escape((string) (isset($row['created_at']) && $row['created_at'] !== '' ? $row['created_at'] : $now)) . ','
			. $this->db->escape($now)
			. ') ON DUPLICATE KEY UPDATE '
			. '`host` = VALUES(`host`), `src_url` = VALUES(`src_url`), `mode` = VALUES(`mode`), '
			. '`state` = VALUES(`state`), `product_count` = VALUES(`product_count`), '
			. '`keyword` = VALUES(`keyword`), `ai_crawl` = VALUES(`ai_crawl`), '
			. '`competitor_name` = VALUES(`competitor_name`), `cost_total` = VALUES(`cost_total`), '
			. '`updated_at` = VALUES(`updated_at`)';
		$this->db->query($sql);
		return $this->db->affected_rows();
	}

	/** Every durable crawl-job record for a feature, newest first. */
	function Read_All($feature)
	{
		return $this->db->select('job_id, host, src_url, mode, state, product_count, keyword, ai_crawl, competitor_name, cost_total, created_at')
			->where('feature', (string) $feature)
			->where('mode', 'crawl')
			->order_by('created_at', 'DESC')
			->get('competitor_crawl_jobs')->result();
	}

	/** Drop one job's record (e.g. when the user deletes the crawl). */
	function Delete_Job($feature, $job_id)
	{
		$this->db->delete('competitor_crawl_jobs', array(
			'feature' => (string) $feature,
			'job_id'  => (string) $job_id,
		));
		return $this->db->affected_rows();
	}
}
