<?php
/**
 * Run with: php tests/helpers/GhlManualLeadPresetsTest.php
 *
 * Locks the fixed dropdown option lists shared by the Manual Lead create/edit
 * form, the filter and the bulk import: Client Type, Number of Pax buckets and
 * the Malaysian State list. One source of truth so form + filter + import never
 * drift.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}
require __DIR__ . '/../../application/helpers/ghl_manual_lead_helper.php';

function assert_eq($label, $expected, $actual) {
    if ($expected === $actual) {
        echo "  PASS  {$label} = " . var_export($actual, true) . "\n";
    } else {
        echo "  FAIL  {$label}: expected " . var_export($expected, true)
           . ", got " . var_export($actual, true) . "\n";
        exit(1);
    }
}

assert_eq('client types', array('HRDC', 'Meeting', 'Incentive', 'Conference', 'Expo', 'Leisure', 'Company trip'), ghl_manual_lead_client_types());

$pax = ghl_manual_lead_pax_options();
assert_eq('pax count', 11, count($pax));
assert_eq('pax first', '1-10', $pax[0]);
assert_eq('pax last', '100+', $pax[10]);

$states = ghl_manual_lead_states();
assert_eq('state count', 16, count($states));
assert_eq('has selangor', true, in_array('Selangor', $states, true));
assert_eq('has kl', true, in_array('Kuala Lumpur', $states, true));
assert_eq('has sabah', true, in_array('Sabah', $states, true));

echo "\nAll GhlManualLeadPresets assertions passed.\n";
