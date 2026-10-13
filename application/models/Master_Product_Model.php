<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Master_Product_Model — a read-only, CROSS-FEATURE view of the shared
 * competitor_analyses table. Unlike Competitor_Analysis_Model / Our_Product_Model
 * (each scoped to its own `feature` slice), this reads every analysed record from
 * BOTH tools so the "Master Product" page can list them together. Each row keeps
 * its `feature` so the listing can route View/PDF to the owning tool.
 */
class Master_Product_Model extends CI_Model
{
	/** Every analysed record, newest first (headline columns + feature). */
	function Read_All()
	{
		$this->db->select('id, feature, url, source, page_title, product_name, tour_code, price, currency, destination, duration, cost_usd, product_count, status, created_at');
		$this->db->order_by('id', 'DESC');
		return $this->db->get('competitor_analyses')->result();
	}

	/** Cumulative USD OpenAI spend across every analysed record (both tools). */
	function Read_Total_Cost()
	{
		$row = $this->db->select('SUM(cost_usd) AS total', false)->get('competitor_analyses')->row();
		return $row && $row->total !== null ? (float) $row->total : 0.0;
	}

	/** Full selected analyses from both tools, decoded by their owning models. */
	function Read_For_Export($ids)
	{
		if (empty($ids)) { return array(); }
		$this->load->model('Competitor_Analysis_Model');
		$this->load->model('Our_Product_Model');
		$rows = array_merge(
			$this->Competitor_Analysis_Model->Read_By_Ids($ids),
			$this->Our_Product_Model->Read_By_Ids($ids)
		);
		usort($rows, function ($a, $b) { return (int) $b->id <=> (int) $a->id; });
		return $rows;
	}

	/** Delete one record by id, regardless of feature. */
	function Delete($id)
	{
		$this->db->delete('competitor_analyses', array('id' => (int) $id));
		return $this->db->affected_rows() > 0;
	}
}
