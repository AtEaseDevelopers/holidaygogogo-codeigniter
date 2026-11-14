<?php
/** Run with: php tests/helpers/ManualLeadEditSaveTest.php */
define('BASEPATH', __DIR__);
define('FCPATH', dirname(__DIR__, 2) . '/');
class CI_Model { public $db; }
class MY_Controller {
    public $db, $input, $session, $load, $Guests_Model, $Ghl_Contacts_Model, $Booking_Model;
}
function lc_block_edit($module) { return false; }
function base_url($path) { return $path; }
function redirect($path) { $GLOBALS['edit_save_redirect'] = $path; }
require FCPATH . 'application/helpers/ghl_manual_lead_helper.php';
require FCPATH . 'application/helpers/manual_lead_status_changes_helper.php';
require FCPATH . 'application/models/Ghl_Contacts_Model.php';
require FCPATH . 'application/models/Guests_Model.php';
require FCPATH . 'application/controllers/Manual_Leads.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
class EditSaveDb {
    public $rows, $failInsert = false;
    private $where = array(), $snapshot, $ok = true, $lastId = 0;
    function __construct() {
        $this->rows = array(
            'ghl_contacts' => array(array('id' => 42, 'lead_source' => 'manual', 'first_name' => 'Old', 'notes' => 'Old notes', 'lead_intro' => 'Old lead description', 'high_chance' => 0, 'sales_person_id' => null, 'op_id' => null)),
            'lead_status_log' => array(array('LogID' => 10, 'dedup_key' => 'ghl:42', 'Status' => 'Y', 'CreatedBy' => 7, 'Note' => 'Old status note', 'StatusDate' => '2026-11-04', 'LeadStatus' => 'NEW')),
        );
    }
    function where($field, $value) { $this->where[$field] = $value; return $this; }
    function update($table, $values) {
        foreach ($this->rows[$table] as &$row) {
            $matches = true;
            foreach ($this->where as $key => $value) { if ($row[$key] != $value) { $matches = false; } }
            if ($matches) { $row = array_merge($row, $values); }
        }
        $this->where = array(); return true;
    }
    function insert($table, $values) {
        if ($this->failInsert) { $this->ok = false; $this->lastId = 0; return false; }
        $this->lastId = 20; $values['LogID'] = 20; $this->rows[$table][] = $values; return true;
    }
    function insert_id() { return $this->lastId; }
    function trans_begin() { $this->snapshot = $this->rows; $this->ok = true; }
    function trans_status() { return $this->ok; }
    function trans_commit() { $this->snapshot = null; }
    function trans_rollback() { $this->rows = $this->snapshot; }
}
class EditSaveGuests extends Guests_Model {
    function Read_Manual_Lead_Detail($key) { return $key === 'ghl:42' ? (object) $this->db->rows['ghl_contacts'][0] : null; }
    function Read_Lead_Status_Log($key, $admin_id = null) { return array_map(function($r) { return (object) $r; }, $this->db->rows['lead_status_log']); }
}
class EditSaveSession {
    public $flash = array();
    function userdata($key) { return $key === 'admin_id' ? 7 : null; }
    function set_flashdata($key, $value) { $this->flash[$key] = $value; }
}
function make_controller($failInsert = false) {
    $controller = (new ReflectionClass('Manual_Leads'))->newInstanceWithoutConstructor();
    $controller->db = new EditSaveDb(); $controller->db->failInsert = $failInsert;
    $controller->Guests_Model = new EditSaveGuests(); $controller->Guests_Model->db = $controller->db;
    $controller->Ghl_Contacts_Model = new Ghl_Contacts_Model(); $controller->Ghl_Contacts_Model->db = $controller->db;
    $controller->Booking_Model = new class { function Read_Manual_Lead_Assignees() { return array((object) array('AdminID' => 7, 'Status' => 'Y')); } };
    $controller->load = new class { function helper($name) {} function model($name) {} };
    $controller->session = new EditSaveSession();
    $controller->input = new class {
        public $data, $query = array();
        function get() { return $this->query; }
        function post($key = null) { return $key === null ? $this->data : ($this->data[$key] ?? null); }
    };
    $controller->input->data = array('dedup_key' => 'ghl:42', 'first_name' => 'Updated', 'phone' => '+60 123456789', 'company_name' => 'Company', 'client_type' => 'Company trip',
        'travel_date_range' => '10/12/2026 - 15/12/2026', 'sales_person_id' => '7', 'op_id' => '7', 'destination' => 'Redang', 'potential_sales_amount' => 'RM 10,000', 'notes' => 'Updated lead notes', 'lead_intro' => "Updated lead description\nSecond line",
        'status_changes' => json_encode(array('notes' => array(array('id' => 10, 'note' => 'Updated status note')),
            'add' => array(array('status_date' => '2026-11-05', 'lead_status' => 'FOLLOW UP', 'note' => 'New status note')))));
    return $controller;
}
$filters = array('q' => 'Ali & Co', 'manual_sales_person' => array('7', '9'), 'page' => '2');
$success = make_controller(); $success->input->query = $filters;
$success->input->data['high_chance'] = '1';
$success->Update();
check(isset($success->session->flash['ghl_lead_success']), 'Successful edit not acknowledged');
check(strpos($edit_save_redirect, 'Manual_Leads?') === 0, 'Save did not return to Manual Leads');
parse_str(parse_url($edit_save_redirect, PHP_URL_QUERY), $saved_filters);
check($saved_filters === $filters, 'Save lost filters or page number');
check($success->db->rows['ghl_contacts'][0]['notes'] === 'Updated lead notes', 'Lead notes lost');
check($success->Guests_Model->Read_Manual_Lead_Detail('ghl:42')->lead_intro === "Updated lead description\nSecond line", 'Lead detail returned the old introduction');
check($success->db->rows['lead_status_log'][0]['Note'] === 'Updated status note', 'Existing status note lost');
check($success->db->rows['lead_status_log'][1]['Note'] === 'New status note', 'New status note lost');
check($success->db->rows['ghl_contacts'][0]['high_chance'] === 1, 'High Chance Yes did not save');

$priorityOnly = make_controller();
$priorityOnly->db->rows = $success->db->rows;
$priorityOnly->input->data['status_changes'] = '{}';
$priorityOnly->Update();
check($priorityOnly->db->rows['ghl_contacts'][0]['high_chance'] === 1, 'Omitted High Chance cleared the flag');
$priorityOnly->input->data['high_chance'] = '0';
$priorityOnly->Update();
check($priorityOnly->db->rows['ghl_contacts'][0]['high_chance'] === 0, 'High Chance No did not clear the flag');
check($priorityOnly->db->rows['lead_status_log'] === $success->db->rows['lead_status_log'], 'Priority-only edit changed status history');
$before = $priorityOnly->db->rows;
$priorityOnly->input->data['high_chance'] = array('1');
$priorityOnly->Update();
check(isset($priorityOnly->session->flash['ghl_lead_error']) && $priorityOnly->db->rows === $before, 'Invalid High Chance saved');

$failed = make_controller(true); $before = $failed->db->rows;
$failed->input->data['high_chance'] = '1';
$failed->Update();
check(isset($failed->session->flash['ghl_lead_error']), 'Failed edit reported success');
check($edit_save_redirect === 'Manual_Leads', 'Unfiltered edit did not return to the listing');
check($failed->db->rows === $before, 'Status failure did not roll back lead and notes');
$foreign = make_controller(); $before = $foreign->db->rows;
$foreign->input->query = $filters;
$foreign->input->data['status_changes'] = json_encode(array('notes' => array(array('id' => 999, 'note' => 'Foreign'))));
$foreign->Update();
parse_str(parse_url($edit_save_redirect, PHP_URL_QUERY), $failed_filters);
check($failed_filters === $filters, 'Validation error lost filters or page number');
check($foreign->db->rows === $before && isset($foreign->session->flash['ghl_lead_error']), 'Invalid status wrote partial changes');
foreach (array('first_name', 'phone', 'company_name', 'client_type', 'travel_date_range', 'sales_person_id', 'op_id', 'destination', 'potential_sales_amount') as $field) {
    $missing = make_controller(); $before = $missing->db->rows;
    $missing->input->data[$field] = '';
    $missing->Update();
    check($missing->db->rows === $before && isset($missing->session->flash['ghl_lead_error']), 'Missing required field saved: ' . $field);
}
$lastStatus = make_controller(); $before = $lastStatus->db->rows;
$lastStatus->input->data['status_changes'] = json_encode(array('delete' => array(10)));
$lastStatus->Update();
check($lastStatus->db->rows === $before && isset($lastStatus->session->flash['ghl_lead_error']), 'Final status deletion saved');
echo "OK: actual Update saves lead and status notes together, rolls back on failure, and rejects foreign entries.\n";
