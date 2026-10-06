<?php
class Category_Model extends CI_Model
{
	function Read_Category()
	{
		$this->db->select('CategoryID, CategoryCodeID, Name, City, State, Country, IsDestination');
		$this->db->where('CategoryID', $this->input->get('category_id'));
		return $this->db->get('category')->row_array();
	}

	function Read_Categories()
	{
		$this->db->select('CategoryID, Name, City, State, Status');
		if(!empty($this->input->get('category_code'))) {
			$this->db->where('CategoryCodeID', $this->input->get('category_code'));
		}
		if(!empty($this->input->get('name'))) {
			$this->db->where('Name', $this->input->get('name'));
		}
		if(!empty($this->input->get('city'))) {
			$this->db->where('City', $this->input->get('city'));
		}
		if(!empty($this->input->get('state'))) {
			$this->db->where('State', $this->input->get('state'));
		}
		if(!empty($this->input->get('country'))) {
			$this->db->where('Country', $this->input->get('country'));
		}
		if(!empty($this->input->get('is_destination'))) {
			$this->db->where('IsDestination', $this->input->get('is_destination'));
		}
		$this->db->where('Status', 'Y');
		$this->db->order_by('Name', 'ASC');
		return $this->db->get('category')->result();
	}

	function Read_Category_Codes()
	{
		$this->db->select('CategoryCodeID, Name');
		$this->db->where('Status', 'Y');
		$this->db->order_by('Name', 'ASC');
		return $this->db->get('category_code')->result();
	}

	function Read_Country_Codes()
	{
		$this->db->select('CountryCodeID, Country');
		$this->db->where('Status', 'Y');
		$this->db->order_by('Country', 'ASC');
		return $this->db->get('country_code')->result();
	}

	function Read_Products()
	{
		$this->db->select('ProductID, ProductCode, Name');
		$this->db->where('Status', 'Y');
		$this->db->order_by('ProductCode', 'ASC');
		$this->db->order_by('Name', 'ASC');
		return $this->db->get('product')->result();
	}

	function Read_Category_Product_Ids($category_id)
	{
		$this->db->select('ProductID');
		$this->db->where('CategoryID', $category_id);
		$this->db->where('Status', 'Y');
		$rows = $this->db->get('category_product')->result();
		$ids = array();
		foreach($rows as $row) {
			$ids[] = $row->ProductID;
		}
		return $ids;
	}

	function Create()
	{
		$this->db->insert_batch('category', json_decode(json_encode($this->input->post('category'))));
		// insert_id() returns the first auto-increment id from the batch (MySQL).
		$category_id = $this->db->insert_id();
		$this->Sync_Products($category_id);
		return $category_id;
	}

	function Update()
	{
		$category = json_decode(json_encode($this->input->post('category')));
		// Drop any non-column keys (e.g. numeric keys from a malformed post)
		// so they can never become invalid SQL columns.
		foreach($category as $row) {
			foreach((array) $row as $key => $value) {
				if(!is_string($key) || $key === '' || ctype_digit($key)) {
					unset($row->$key);
				}
			}
		}
		// Only run the category column update when there are real field changes
		// (more than the always-present CategoryID/UpdateBy/UpdateDate trio);
		// product-link changes alone are handled by Sync_Products() below.
		if(count((array) $category[0]) > 3) {
			$this->db->update_batch('category', $category, 'CategoryID');
		}
		$this->Sync_Products($category[0]->CategoryID);
	}

	// Replace the category's product links with the posted set. Posted as a flat
	// array of ProductIDs under 'products'; absent key means "no change" (skip),
	// empty array means "clear all links".
	function Sync_Products($category_id)
	{
		if(empty($category_id)) {
			return;
		}
		$products = $this->input->post('products');
		if($products === null) {
			return;
		}
		// The form posts products as a JSON-encoded array of ProductIDs.
		if(is_string($products)) {
			$products = json_decode($products, true);
		}
		if(!is_array($products)) {
			return;
		}
		$this->db->where('CategoryID', $category_id);
		$this->db->delete('category_product');
		$products = array_values(array_unique(array_filter($products, 'strlen')));
		if(empty($products)) {
			return;
		}
		$admin_id = $this->session->userdata('admin_id');
		$now = date('Y-m-d H:i:s');
		$rows = array();
		foreach($products as $product_id) {
			$rows[] = array(
				'CategoryID' => $category_id,
				'ProductID'  => $product_id,
				'Status'     => 'Y',
				'InsertBy'   => $admin_id,
				'InsertDate' => $now
			);
		}
		$this->db->insert_batch('category_product', $rows);
	}
	
	function Detect()
	{
		// Only active rows count as redundant; soft-deleted (Status='N')
		// categories are hidden from the listing, so they must not block
		// re-creating a name the user can no longer see.
		$this->db->where('Name', $this->input->post('name'));
		$this->db->where('Status', 'Y');
		if($this->db->get('category')->row()) {
			return true;
		} else {
			return false;
		}
	}
	function find($category_id)
    {
        return $this->db->get_where('category', ['CategoryID' => $category_id])->row();
    }
}