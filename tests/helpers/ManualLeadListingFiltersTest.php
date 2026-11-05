<?php
/** Run with: php tests/helpers/ManualLeadListingFiltersTest.php */
define('BASEPATH', __DIR__);
require __DIR__ . '/../../application/helpers/manual_lead_filter_helper.php';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('CONCAT_WS', function($separator, ...$values) {
    return implode($separator, array_filter($values, function($v) { return $v !== null; }));
});
$pdo->exec('CREATE TABLE ghl_contacts (id INTEGER, first_name TEXT, last_name TEXT, company_name TEXT, destination TEXT, potential_sales_amount TEXT, sales_person_id INTEGER, op_id INTEGER, travel_date TEXT, travel_end_date TEXT)');
$insert = $pdo->prepare('INSERT INTO ghl_contacts VALUES (?,?,?,?,?,?,?,?,?,?)');
$insert->execute(array(1, 'Polymer   Applications (M) Sdn Bhd', null, 'Polymer Applications (M) Sdn Bhd', 'Phuket', 'RM 10,000 approx.', 7, 9, '2026-12-10', '2026-12-20'));
$insert->execute(array(2, 'Ivin', 'Tan', 'Globaltronic Precision Pte Ltd', 'Desaru', 'RM1.5k per person', 9, 7, '2026-12-15', null));
$insert->execute(array(3, 'Aiman', null, '100%_Company', 'Redang', 'RM 8,000', 7, null, null, null));
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function expect($get, $ids, $label) {
    global $pdo;
    list($sql, $params) = manual_lead_filter_sql($get);
    $statement = $pdo->prepare('SELECT gc.id FROM ghl_contacts gc WHERE 1=1 ' . $sql . ' ORDER BY gc.id');
    $statement->execute($params);
    check(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)) === $ids, $label);
    check(guest_list_branches_to_run('manual', $get)['ghl'], 'New filter suppressed manual leads');
}
expect(array('q' => 'polymer applications (m) sdn bhd'), array(1), 'Full displayed name / repeated spaces');
expect(array('q' => 'bhd POLYMER'), array(1), 'Partial words in any order');
expect(array('q' => 'precision'), array(2), 'Company name in broad search');
expect(array('q' => 'vin'), array(2), 'Substring of guest name');
expect(array('company_name' => '100%_'), array(3), 'Literal wildcard characters');
expect(array('manual_sales_person' => array('7')), array(1,3), 'Sales Person');
expect(array('manual_op' => array('7')), array(2), 'OP');
expect(array('manual_sales_person' => array('7','9'), 'manual_destination' => 'DES'), array(2), 'Combined assignee and destination');
expect(array('potential_sales_amount' => '1.5k'), array(2), 'Free-text amount');
expect(array('manual_travel_date' => '15/12/2026 - 15/12/2026'), array(1,2), 'Overlap and legacy single date');
expect(array('manual_travel_date' => '20/12/2026 - 23/12/2026'), array(1), 'Inclusive end boundary');
expect(array('manual_travel_date' => '21/12/2026 - 23/12/2026'), array(), 'Outside travel ranges');
expect(array('q' => "' OR 1=1 --"), array(), 'Search is bound, not SQL');

// Exercise the actual listing/count/export model paths with identical GET inputs.
class CI_Model { public $load, $input, $session, $db; }
require __DIR__ . '/../../application/models/Guests_Model.php';
$model = new Guests_Model(); $model->Set_Mode('manual');
$model->input = new class {
    function get($key = null) {
        $get = array('q' => 'Ivin', 'manual_op' => array('7'), 'manual_travel_date' => '15/12/2026 - 15/12/2026');
        return $key === null ? $get : ($get[$key] ?? null);
    }
};
$model->load = new class { function helper($name) {} };
$model->session = new class { function userdata($key) { return $key === 'level' ? 50 : 7; } };
$model->db = new class {
    public $calls = array();
    function query($sql, $params) {
        $this->calls[] = array($sql, $params);
        return new class { function result() { return array(); } function row() { return (object) array('cnt' => 0); } };
    }
};
$model->Read_Guests(30, 0); $model->Count_Guests(); $model->Read_Manual_Leads_For_Export();
check(count($model->db->calls) === 3, 'Listing/count/export did not execute');
foreach ($model->db->calls as $call) {
    check(strpos($call[0], 'gc.op_id IN (?)') !== false, 'OP missing from model path');
    check(strpos($call[0], 'gc.travel_date <= ?') !== false, 'Travel range missing from model path');
    check(strpos($call[0], 'gc.created_by = ?') !== false, 'Creator visibility removed');
    check($call[1] === $model->db->calls[0][1], 'Listing/count/export parameter mismatch');
}
echo "OK: broad name search, all new filters, travel overlap, scope and listing/count/export parity.\n";
