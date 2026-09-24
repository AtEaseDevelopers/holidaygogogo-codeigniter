<?php
/**
 * Run with: php tests/helpers/LeadsCustomerAccessTest.php
 *
 * Covers the pure access resolver behind the new "Leads/Customer" tab
 * (application/helpers/leads_customer_access_helper.php):
 *
 *   lc_resolve_access($module, $level, $rows) => ['view'=>bool, 'edit'=>bool]
 *
 * Owner (level 10) always has full view+edit on every module (never stored).
 * Everyone else is driven purely by the granted rows; edit implies view.
 * The DB-backed wrappers (lc_can_view / lc_can_edit) are covered by manual
 * verification since they read session + Lc_Module_Access_Model.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

require_once __DIR__ . '/../../application/helpers/leads_customer_access_helper.php';

$modules = array_keys(lc_modules());

$assertions = array();

// 1) Owner (10) => full access on every module regardless of rows ------------
foreach ($modules as $m) {
    $r = lc_resolve_access($m, 10, array());
    $assertions["owner: $m view"] = $r['view'] === true;
    $assertions["owner: $m edit"] = $r['edit'] === true;
}
$assertions['owner: level as string "10"'] = lc_resolve_access('customer', '10', array())['edit'] === true;

// 2) Granted view-only => view true / edit false -----------------------------
$viewOnly = array('manual_leads' => array('CanView' => 1, 'CanEdit' => 0));
$assertions['view-only: view true']  = lc_resolve_access('manual_leads', 20, $viewOnly)['view'] === true;
$assertions['view-only: edit false'] = lc_resolve_access('manual_leads', 20, $viewOnly)['edit'] === false;

// 3) Granted view+edit => both true ------------------------------------------
$viewEdit = array('customer' => array('CanView' => 1, 'CanEdit' => 1));
$assertions['view+edit: view true'] = lc_resolve_access('customer', 20, $viewEdit)['view'] === true;
$assertions['view+edit: edit true'] = lc_resolve_access('customer', 20, $viewEdit)['edit'] === true;

// 4) edit=1 implies view even if view flag missing ---------------------------
$editOnly = array('guests' => array('CanView' => 0, 'CanEdit' => 1));
$assertions['edit implies view'] = lc_resolve_access('guests', 20, $editOnly)['view'] === true;

// 5) No row for the module => both false -------------------------------------
$assertions['no row: view false'] = lc_resolve_access('ghl_leads', 20, $viewEdit)['view'] === false;
$assertions['no row: edit false'] = lc_resolve_access('ghl_leads', 20, $viewEdit)['edit'] === false;

// 5b) Campaign + Lead Status are grid-controlled like the other modules -------
$assertions['campaign is a module']    = in_array('campaign', $modules, true);
$assertions['lead_status is a module'] = in_array('lead_status', $modules, true);
// Non-owner has no default access (pure grid, owner-only default) — a Team Lead
// (25) sees Lead Status only when explicitly granted, matching Campaign.
$assertions['campaign no grant: view false']    = lc_resolve_access('campaign', 25, array())['view'] === false;
$assertions['lead_status no grant: view false'] = lc_resolve_access('lead_status', 25, array())['view'] === false;
$grantNew = array(
    'campaign'    => array('CanView' => 1, 'CanEdit' => 0),
    'lead_status' => array('CanView' => 1, 'CanEdit' => 1),
);
$assertions['campaign granted view']       = lc_resolve_access('campaign', 25, $grantNew)['view'] === true;
$assertions['campaign granted no edit']    = lc_resolve_access('campaign', 25, $grantNew)['edit'] === false;
$assertions['lead_status granted view+edit'] = lc_resolve_access('lead_status', 25, $grantNew)['edit'] === true;

// 5c) Customer Profile is grid-controlled like the other modules -------------
// (was owner-only; now a per-admin toggle). Owner keeps implicit full access;
// a non-owner sees the Action ▸ Customer Profile button + page only on View,
// and may run/delete the AI analysis only on Edit.
$assertions['customer_profile is a module'] = in_array('customer_profile', $modules, true);
$assertions['customer_profile owner view'] = lc_resolve_access('customer_profile', 10, array())['view'] === true;
$assertions['customer_profile owner edit'] = lc_resolve_access('customer_profile', 10, array())['edit'] === true;
$assertions['customer_profile no grant: view false'] = lc_resolve_access('customer_profile', 20, array())['view'] === false;
$assertions['customer_profile no grant: edit false'] = lc_resolve_access('customer_profile', 20, array())['edit'] === false;
$cpGrant = array('customer_profile' => array('CanView' => 1, 'CanEdit' => 0));
$assertions['customer_profile view-only: view true']  = lc_resolve_access('customer_profile', 20, $cpGrant)['view'] === true;
$assertions['customer_profile view-only: edit false'] = lc_resolve_access('customer_profile', 20, $cpGrant)['edit'] === false;
$cpEdit = array('customer_profile' => array('CanView' => 0, 'CanEdit' => 1));
$assertions['customer_profile edit implies view'] = lc_resolve_access('customer_profile', 20, $cpEdit)['view'] === true;

// 6) Unknown module => both false (even for a granted non-owner) --------------
$assertions['unknown: view false'] = lc_resolve_access('nope', 20, $viewEdit)['view'] === false;
$assertions['unknown: edit false'] = lc_resolve_access('nope', 20, $viewEdit)['edit'] === false;

// 7) Unknown module for owner still false (guards typos) ---------------------
$assertions['unknown owner: false'] = lc_resolve_access('nope', 10, array())['view'] === false;

// Report ---------------------------------------------------------------------
$failed = 0;
foreach ($assertions as $label => $ok) {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

echo PHP_EOL . ($failed === 0 ? "All assertions passed.\n" : "$failed assertion(s) failed.\n");
exit($failed === 0 ? 0 : 1);
