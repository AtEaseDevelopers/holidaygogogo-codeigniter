<?php
/** Run with: php tests/helpers/ManualLeadTravelFieldsTest.php */
define('BASEPATH', __DIR__);
class CI_Model { public $db; }
require __DIR__ . '/../../application/helpers/ghl_manual_lead_helper.php';
require __DIR__ . '/../../application/models/Ghl_Contacts_Model.php';
require __DIR__ . '/../../application/helpers/guest_list_export_helper.php';

class ManualLeadTravelTestDb {
    public $inserted;
    public $updated;
    public $where = array();
    function insert($table, $row) { $this->inserted = $row; return true; }
    function insert_id() { return 42; }
    function where($key, $value) { $this->where[$key] = $value; return $this; }
    function update($table, $row) { $this->updated = $row; return true; }
}
function check($ok, $message) {
    if (!$ok) { throw new RuntimeException($message); }
}
$prepared = ghl_manual_lead_prepare(array(
    'first_name' => 'Guest', 'travel_date' => '2026-12-15',
    'sales_person_id' => '12', 'op_id' => '34', 'destination' => 'Redang',
    'potential_sales_amount' => 'RM 10,000 approx.', 'client_type' => 'Company trip',
), 'travel', '2026-11-05 09:00:00', 7);
$model = new Ghl_Contacts_Model();
$model->db = new ManualLeadTravelTestDb();
check($model->create_manual_lead($prepared['row']) === 42, 'Create failed');
check($model->update_manual_lead(42, $prepared['row']), 'Update failed');
foreach (array('travel_date', 'sales_person_id', 'op_id', 'destination', 'potential_sales_amount') as $field) {
    check($model->db->inserted[$field] === $prepared['row'][$field], 'Insert lost ' . $field);
    check($model->db->updated[$field] === $prepared['row'][$field], 'Update lost ' . $field);
}
check(!array_key_exists('customer_type', $model->db->updated), 'Editing clears historical customer type');
check(!array_key_exists('created_by', $model->db->updated), 'Editing changes creator');
check($model->db->where === array('id' => 42, 'lead_source' => 'manual'), 'Update scope changed');
$columns = array_column(guest_list_export_columns('manual'), 1);
foreach (array('TravelDate', 'SalesPerson', 'Op', 'LeadDestination', 'PotentialSalesAmount') as $field) {
    check(in_array($field, $columns, true), 'Export missing ' . $field);
}
check(!in_array('CustomerType', $columns, true), 'Removed form field still in manual export');
check(guest_list_export_cell((object) array('TravelDate' => '2026-12-15'), 'TravelDate') === '15 Dec 2026', 'Travel date format');
echo "OK: manual lead travel fields survive create/update and are included in export.\n";
