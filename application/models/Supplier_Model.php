<?php
class Supplier_Model extends CI_Model
{
	function Read_Supplier()
	{
		$this->db->select('SupplierID, Name, Phone, PrimaryEmail, SecondaryEmail, Address, CurrencyCode, Bank, BankAccount, BankHolder, SwiftCode, SupplierCode,AutocountSyncAction, AutocountSyncStatus, AutocountSyncMessage');
		$this->db->where('SupplierID', $this->input->get('supplier_id'));
		return $this->db->get('supplier')->row_array();
	}

	function Read_Suppliers1()
	{
		$this->db->select('SupplierID, Name, Phone, Status, SupplierCode,AutocountSyncAction, AutocountSyncStatus, AutocountSyncMessage');;
		if(!empty($this->input->get('name'))) {
			$this->db->where('Name', $this->input->get('name'));
		}
		if(!empty($this->input->get('phone'))) {
			$this->db->where('Phone', $this->input->get('phone'));
		}
		if(!empty($this->input->get('primary_email'))) {
			$this->db->where('PrimaryEmail', $this->input->get('primary_email'));
		}
		if(!empty($this->input->get('secondary_email'))) {
			$this->db->where('SecondaryEmail', $this->input->get('secondary_email'));
		}
		if(!empty($this->input->get('address'))) {
			$this->db->where('Address', $this->input->get('address'));
		}
		if(!empty($this->input->get('currency_code'))) {
			$this->db->where('CurrencyCode', $this->input->get('currency_code'));
		}
		if(!empty($this->input->get('bank'))) {
			$this->db->where('Bank', $this->input->get('bank'));
		}
		if(!empty($this->input->get('bank_account'))) {
			$this->db->where('BankAccount', $this->input->get('bank_account'));
		}
		if(!empty($this->input->get('bank_holder'))) {
			$this->db->where('BankHolder', $this->input->get('bank_holder'));
		}
		if(!empty($this->input->get('swift_code'))) {
			$this->db->where('SwiftCode', $this->input->get('swift_code'));
		}
		$this->db->where('Status', 'Y');
		$this->db->order_by('Name', 'ASC');
		return $this->db->get('supplier')->result();
	}

	function Read_Suppliers2()
	{
		$this->db->select('Name, Phone, PrimaryEmail, SecondaryEmail, Address, CurrencyCode, Bank, BankAccount, BankHolder, SwiftCode, SupplierCode, AutocountSyncAction, AutocountSyncStatus, AutocountSyncMessage');
		if(!empty($this->input->get('name'))) {
			$this->db->where('Name', $this->input->get('name'));
		}
		if(!empty($this->input->get('phone'))) {
			$this->db->where('Phone', $this->input->get('phone'));
		}
		if(!empty($this->input->get('primary_email'))) {
			$this->db->where('PrimaryEmail', $this->input->get('primary_email'));
		}
		if(!empty($this->input->get('secondary_email'))) {
			$this->db->where('SecondaryEmail', $this->input->get('secondary_email'));
		}
		if(!empty($this->input->get('address'))) {
			$this->db->where('Address', $this->input->get('address'));
		}
		if(!empty($this->input->get('currency_code'))) {
			$this->db->where('CurrencyCode', $this->input->get('currency_code'));
		}
		if(!empty($this->input->get('bank'))) {
			$this->db->where('Bank', $this->input->get('bank'));
		}
		if(!empty($this->input->get('bank_account'))) {
			$this->db->where('BankAccount', $this->input->get('bank_account'));
		}
		if(!empty($this->input->get('bank_holder'))) {
			$this->db->where('BankHolder', $this->input->get('bank_holder'));
		}
		if(!empty($this->input->get('swift_code'))) {
			$this->db->where('SwiftCode', $this->input->get('swift_code'));
		}
		$this->db->where('Status', 'Y');
		$this->db->order_by('Name', 'ASC');
		return $this->db->get('supplier')->result();
	}
	
	function Create()
	{
		$this->db->insert_batch('supplier', json_decode(json_encode($this->input->post('supplier'))));
	}

	function Update()
	{
		$this->db->update_batch('supplier', json_decode(json_encode($this->input->post('supplier'))), 'SupplierID');

		$this->db->set('Phone', null);
		$this->db->where('SupplierID', $this->input->post('supplier_id'));
		$this->db->where('Phone', '');
		$this->db->update('supplier');

		$this->db->set('PrimaryEmail', null);
		$this->db->where('SupplierID', $this->input->post('supplier_id'));
		$this->db->where('PrimaryEmail', '');
		$this->db->update('supplier');

		$this->db->set('SecondaryEmail', null);
		$this->db->where('SupplierID', $this->input->post('supplier_id'));
		$this->db->where('SecondaryEmail', '');
		$this->db->update('supplier');

		$this->db->set('Address', null);
		$this->db->where('SupplierID', $this->input->post('supplier_id'));
		$this->db->where('Address', '');
		$this->db->update('supplier');

		$this->db->set('Bank', null);
		$this->db->where('SupplierID', $this->input->post('supplier_id'));
		$this->db->where('Bank', '');
		$this->db->update('supplier');

		$this->db->set('BankAccount', null);
		$this->db->where('SupplierID', $this->input->post('supplier_id'));
		$this->db->where('BankAccount', '');
		$this->db->update('supplier');

		$this->db->set('BankHolder', null);
		$this->db->where('SupplierID', $this->input->post('supplier_id'));
		$this->db->where('BankHolder', '');
		$this->db->update('supplier');
		
		$this->db->set('SwiftCode', null);
		$this->db->where('SupplierID', $this->input->post('supplier_id'));
		$this->db->where('SwiftCode', '');
		$this->db->update('supplier');
	}

	function Detect()
	{
		$this->db->where('Name', $this->input->post('name'));
		if($this->db->get('supplier')->row()) {
			return true;
		} else {
			return false;
		}
	}

	public function find($supplier_id)
    {
        return $this->db->get_where('supplier', ['SupplierID' => $supplier_id])->row();
    }
	
    public function update_by_id($supplier_id, $data = [])
    {
        if (empty($data)) return false;

        return $this->db
            ->where('SupplierID', $supplier_id)
            ->update('supplier', $data);
    }
	public function get_pending_sycn_suppliers()
	{
		$this->load->helper('autocount');
		$config = get_autocount_config();

		$supplier_qty_cront = !empty($config['supplier_qty_cront'])
			? (int)$config['supplier_qty_cront']
			: 10;
		
		$statuses = !empty($config['supplier_sync_autocount_status']) 
			? (array)$config['supplier_sync_autocount_status'] 
			: ['P'];

		$this->db->from('supplier');
		$this->db->where_in('AutocountSyncStatus', $statuses);
		$this->db->where('AutocountSyncAction IS NOT NULL', null, false);

		if (!empty($config['supplier_cutoff_date'])) {
			$date = date('Y-m-d', strtotime($config['supplier_cutoff_date']));
			$this->db->where('supplier.InsertDate >', $date);
		}

		$this->db->limit($supplier_qty_cront);

		return $this->db->get()->result_array();
	}

	/**
	 * Flat list of active supplier names, for a "pick existing OR type new"
	 * datalist (e.g. the costing combination item supplier field). Names only —
	 * the caller stores whatever is chosen/typed as free text and never writes
	 * back to this master.
	 */
	public function Read_Supplier_Names()
	{
		$this->db->select('Name');
		$this->db->where('Status', 'Y');
		$this->db->where('Name !=', '');
		$this->db->order_by('Name', 'ASC');
		$rows = $this->db->get('supplier')->result_array();

		$names = array();
		foreach ($rows as $row) {
			$name = trim((string) $row['Name']);
			if ($name !== '' && !in_array($name, $names, true)) {
				$names[] = $name;
			}
		}

		return $names;
	}

	public function get_supplier_by_name($name)
	{
		$this->db->select('SupplierID, Name, SupplierCode');
		$this->db->where('Name', $name);
		$this->db->where('Status', 'Y');
		return $this->db->get('supplier')->row();
	}

	public function get_all_suppliers_for_mapping()
	{
		$this->db->select('Name, SupplierCode');
		$this->db->where('Status', 'Y');
		$this->db->order_by('Name', 'ASC');
		$result = $this->db->get('supplier')->result();
		
		$mapping = array();
		foreach ($result as $supplier) {
			$mapping[trim(strtolower($supplier->Name))] = $supplier->SupplierCode;
		}
		
		return $mapping;
	}

}