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
    'first_name' => 'Guest', 'travel_date_range' => '15/12/2026 - 20/12/2026',
    'sales_person_id' => '12', 'op_id' => '34', 'destination' => 'Redang',
    'potential_sales_amount' => 'RM 10,000 approx.', 'client_type' => 'Company trip', 'high_chance' => '1',
), 'travel', '2026-11-05 09:00:00', 7);
check($prepared['ok'], 'Valid range rejected');
check($prepared['row']['travel_date'] === '2026-12-15', 'Range start lost');
check($prepared['row']['travel_end_date'] === '2026-12-20', 'Range end lost');
foreach (array('20/12/2026 - 15/12/2026', '30/02/2026 - 05/03/2026', '15/12/2026 - ', 'garbage') as $invalid) {
    $bad = ghl_manual_lead_prepare(array('first_name' => 'Guest', 'travel_date_range' => $invalid), 'bad', '2026-11-05 09:00:00');
    check(!$bad['ok'], 'Invalid range accepted: ' . $invalid);
}
$blank = ghl_manual_lead_prepare(array('first_name' => 'Guest', 'travel_date_range' => ''), 'blank', '2026-11-05 09:00:00');
check($blank['ok'] && $blank['row']['travel_date'] === null && $blank['row']['travel_end_date'] === null, 'Blank range must remain optional');
$same = ghl_manual_lead_prepare(array('first_name' => 'Guest', 'travel_date_range' => '15/12/2026 - 15/12/2026'), 'same', '2026-11-05 09:00:00');
check($same['ok'] && $same['row']['travel_date'] === $same['row']['travel_end_date'], 'Same-day travel rejected');
$model = new Ghl_Contacts_Model();
$model->db = new ManualLeadTravelTestDb();
check($model->create_manual_lead($prepared['row']) === 42, 'Create failed');
check($model->db->inserted['high_chance'] === 1, 'Create lost High Chance');
check($model->update_manual_lead(42, $prepared['row']), 'Update failed');
foreach (array('travel_date', 'travel_end_date', 'sales_person_id', 'op_id', 'destination', 'potential_sales_amount') as $field) {
    check($model->db->inserted[$field] === $prepared['row'][$field], 'Insert lost ' . $field);
    check($model->db->updated[$field] === $prepared['row'][$field], 'Update lost ' . $field);
}
check(!array_key_exists('customer_type', $model->db->updated), 'Editing clears historical customer type');
check(!array_key_exists('created_by', $model->db->updated), 'Editing changes creator');
check($model->db->where === array('id' => 42, 'lead_source' => 'manual'), 'Update scope changed');
$columns = array_column(guest_list_export_columns('manual'), 1);
check(array_slice($columns, 9, 5) === array('TravelDate', 'SalesPerson', 'Op', 'LeadDestination', 'PotentialSalesAmount'), 'New report fields must follow column 9 in the requested order');
foreach (array('TravelDate', 'SalesPerson', 'Op', 'LeadDestination', 'PotentialSalesAmount') as $field) {
    check(in_array($field, $columns, true), 'Export missing ' . $field);
}
check(!in_array('CustomerType', $columns, true), 'Removed form field still in manual export');
check(guest_list_export_cell((object) array('TravelDate' => '2026-12-15'), 'TravelDate') === '15 Dec 2026', 'Travel date format');
check(guest_list_export_cell((object) array('TravelDate' => '2026-12-15', 'TravelEndDate' => '2026-12-20'), 'TravelDate') === '15 Dec 2026 - 20 Dec 2026', 'Export range format');
check(guest_list_export_cell((object) array('TravelDate' => null, 'TravelEndDate' => null), 'TravelDate') === '', 'Empty range export');
$model->update_manual_lead(42, $blank['row']);
check($model->db->updated['travel_date'] === null && $model->db->updated['travel_end_date'] === null, 'Clear must remove both dates');
echo "OK: manual lead travel fields survive create/update and are included in export.\n";
