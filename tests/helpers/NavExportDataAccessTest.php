<?php
/** Run with: php tests/helpers/NavExportDataAccessTest.php */
define('BASEPATH', __DIR__);
define('LEVEL', serialize(array(10 => 'OWNER', 50 => 'TC')));
require __DIR__ . '/../../application/helpers/leads_customer_access_helper.php';
require __DIR__ . '/../../application/helpers/nav_access_helper.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function base_url($path = '') { return $path; }
$export_modules = array();
foreach (nav_modules() as $key => $meta) {
    if (!empty($meta['capability'])) { $export_modules[] = $key; }
}
check(count($export_modules) === 8, 'Expected eight export permission groups');
foreach ($export_modules as $module) {
    foreach (array(20,25,30,40,45,50,60) as $level) {
        check(!nav_resolve_access($module, $level, array('VB','GB'), 7, array())['view'], 'Staff must default to denied');
        check(nav_resolve_access($module, $level, array(), 7, array($module => array('CanView' => 1, 'CanEdit' => 0)))['view'], 'Explicit grant ignored');
        check(!nav_resolve_access($module, $level, array(), 7, array($module => array('CanView' => 0, 'CanEdit' => 0)))['view'], 'Revocation ignored');
    }
    check(nav_resolve_access($module, 10, array(), 1, array())['view'], 'Owner must retain export');
    foreach ($export_modules as $other) {
        if ($module === $other) { continue; }
        check(!nav_resolve_access($module, 50, array(), 7, array($other => array('CanView' => 1, 'CanEdit' => 0)))['view'], 'Another group incorrectly grants export');
    }
}
check(nav_export_module_for_controller('Guest_List') === 'booking_export_data', 'Booking guest list mapped incorrectly');
check(nav_export_module_for_controller('Unknown') === null, 'Unknown controller must fail closed');
foreach (nav_data_export_routes() as $controller => $methods) {
    check(in_array(nav_export_module_for_controller($controller), $export_modules, true), 'Export group missing: ' . $controller);
    foreach ($methods as $method) {
        check(nav_is_data_export_request(strtoupper($controller), strtoupper($method)), 'Case-insensitive route missed');
        $path = __DIR__ . '/../../application/controllers/' . ucfirst($controller) . '.php';
        // Actual filenames capitalize both words (e.g. Manual_Leads).
        $found = false;
        foreach (glob(__DIR__ . '/../../application/controllers/*.php') as $file) {
            if (strtolower(basename($file, '.php')) !== $controller) { continue; }
            $found = preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/i', file_get_contents($file)) === 1;
        }
        check($found, 'Export route missing: ' . $controller . '/' . $method);
    }
}
foreach (array(array('Manual_Leads','Import_Template'),array('Product','Import_Template'),array('Customer','Import_Template'),array('Faq','Download_Pdf'),array('Guests','Download_Chat_History')) as $route) {
    check(!nav_is_data_export_request($route[0], $route[1]), 'Unrelated download blocked');
}

// Render the actual grid: Export Data has one Allow checkbox, with no Edit box.
$modules = nav_modules(); $groups = nav_module_groups();
$staff = (object) array('AdminID' => 7, 'Name' => 'Staff', 'Level' => 50, 'access' => array());
foreach (array_keys($modules) as $key) { $staff->access[$key] = array('view' => false, 'edit' => false); }
$admins = array($staff);
ob_start(); include __DIR__ . '/../../application/views/nav_access/index.php'; $html = ob_get_clean();
$dom = new DOMDocument(); @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
foreach ($export_modules as $module) {
    check($xpath->query('//input[@data-mod="' . $module . '" and @class="nav-view"]')->length === 1, 'Export checkbox missing');
    check($xpath->query('//input[@data-mod="' . $module . '" and @class="nav-edit"]')->length === 0, 'Extra Edit checkbox rendered');
}

// Exercise the real MY_Controller guard: direct URL is denied before the action.
class CI_Controller {
    public $session, $router;
    function __construct() { $this->session = $GLOBALS['testCI']->session; $this->router = $GLOBALS['testCI']->router; }
}
function &get_instance() { return $GLOBALS['testCI']; }
function show_error($message, $code) { throw new RuntimeException($message, $code); }
$GLOBALS['testCI'] = (object) array(
    'session' => new class {
        public $level = 50;
        function has_userdata($key) { return true; }
        function userdata($key) { return $key === 'admin_id' ? 7 : ($key === 'level' ? $this->level : array()); }
    },
    'router' => new class { public $class = 'Manual_Leads', $exportMethod = 'Download'; function fetch_method() { return $this->exportMethod; } },
    'db' => new class { function table_exists($table) { return true; } },
    'load' => new class { function model($name) {} },
    'Nav_Module_Access_Model' => new class { function Read_For_Admin($id) { return array(); } },
    'Lc_Module_Access_Model' => new class { function Read_For_Admin($id) { return array(); } },
);
require __DIR__ . '/../../application/core/MY_Controller.php';
foreach (nav_data_export_routes() as $controller => $methods) {
    foreach ($methods as $method) {
        $GLOBALS['testCI']->router->class = $controller;
        $GLOBALS['testCI']->router->exportMethod = $method;
        $denied = false;
        try { new MY_Controller(); } catch (RuntimeException $e) { $denied = $e->getCode() === 403; }
        check($denied, 'Direct URL bypassed permission: ' . $controller . '/' . $method);
    }
}
$GLOBALS['testCI']->session->level = 10;
check(nav_block_data_export() === false, 'Owner export denied');
echo "OK: staff default deny, grant/revoke, owner, route coverage, grid checkbox and direct-URL denial.\n";
