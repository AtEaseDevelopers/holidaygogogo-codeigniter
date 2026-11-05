<?php
/** Run with: php tests/helpers/NavExportDataSaveTest.php */
define('BASEPATH', __DIR__);
require __DIR__ . '/../../application/helpers/leads_customer_access_helper.php';
require __DIR__ . '/../../application/helpers/nav_access_helper.php';
class CI_Model { public $db, $input, $session; }
require __DIR__ . '/../../application/models/Nav_Module_Access_Model.php';
class ExportAccessSaveDb {
    public $batch = array();
    function table_exists($table) { return true; }
    function select($fields) { return $this; }
    function where($field, $value) { return $this; }
    function get($table) { return $this; }
    function result() { return array((object) array('AdminID' => 7)); }
    function trans_start() {}
    function truncate($table) { $this->batch = array(); }
    function insert_batch($table, $rows) { $this->batch = array_merge($this->batch, $rows); }
    function trans_complete() {}
    function trans_status() { return true; }
}
$model = new Nav_Module_Access_Model(); $model->db = new ExportAccessSaveDb();
$model->input = new class {
    public $value;
    function post($key) { return $key === 'access_json' ? $this->value : null; }
};
$model->session = new class { function userdata($key) { return 1; } };
foreach (array(0, 1) as $grant) {
    $model->input->value = json_encode(array(7 => array('export_data' => array('view' => $grant, 'edit' => 1), 'manual_leads' => array('view' => 1, 'edit' => 0))));
    if (!$model->Save()) { throw new RuntimeException('Save failed'); }
    $rows = array_column($model->db->batch, null, 'Module');
    if ($rows['export_data']['CanView'] !== $grant || $rows['export_data']['CanEdit'] !== 0) { throw new RuntimeException('Export grant/revoke not persisted'); }
    if ($rows['manual_leads']['CanView'] !== 1 || $rows['manual_leads']['CanEdit'] !== 0) { throw new RuntimeException('Page permission altered'); }
}
echo "OK: export grant/revoke saved independently, without an Edit checkbox.\n";
