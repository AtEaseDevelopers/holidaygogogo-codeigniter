<?php
/**
 * Run with: php tests/helpers/CostingCurrencyExportRowsTest.php
 *
 * Locks the contract for costing_currency_export_rows() - the pure mapper that
 * turns raw costing_exchange_rates history records (as read by
 * Costing_Model::Read_Exchange_Rate_History_For_Export) into the ordered string
 * cells the "Export History" .xlsx download writes. One export row per historical
 * rate change, numbered from 1, amounts fixed-precision, blank names shown as "-".
 *
 * Runs without a DB or PhpSpreadsheet.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}
require_once __DIR__ . '/../../application/helpers/costing_currency_export_helper.php';

$failures = 0;
function check($label, $expected, $actual) {
    global $failures;
    if ($expected === $actual) {
        echo "PASS  {$label}\n";
    } else {
        $failures++;
        echo "FAIL  {$label}\n";
        echo "      expected: " . json_encode($expected) . "\n";
        echo "      actual:   " . json_encode($actual) . "\n";
    }
}

// A raw history record shaped like Read_Exchange_Rate_History returns.
$histories = array(
    array(
        'from_currency_code' => 'USD', 'to_currency_code' => 'MYR',
        'unit_amount' => '1.00000000', 'rate' => '4.35000000', 'buffer' => '0.10000000',
        'converted_amount' => '4.35000000', 'bank_charges_myr' => '5.00',
        'valid_from' => '2026-08-14 10:30:00', 'updated_by_name' => 'Alice',
    ),
    array(
        'from_currency_code' => 'USD', 'to_currency_code' => 'MYR',
        'unit_amount' => '1', 'rate' => '4.3',
        'converted_amount' => '4.3', 'bank_charges_myr' => '0',
        'valid_from' => '2026-07-01 09:00:00', 'updated_by_name' => '',
    ),
);

$rows = costing_currency_export_rows($histories);

// Columns/formatting mirror the on-screen Rate History table exactly.
check('row count', 2, count($rows));
check('numbered from 1', '1', $rows[0]['no']);
check('second numbered 2', '2', $rows[1]['no']);
check('rate string 6dp + code', '1 USD = 4.350000 MYR', $rows[0]['rate']);
check('buffer string 6dp', '0.100000 MYR', $rows[0]['buffer']);
check('missing buffer -> 0.000000', '0.000000 MYR', $rows[1]['buffer']);
check('bank charges MYR 2dp', 'MYR 5.00', $rows[0]['bank_charges']);
check('valid_from table format', '14 AUG 2026 10:30', $rows[0]['valid_from']);
check('updated by name', 'Alice', $rows[0]['updated_by_name']);

// ---- Blank / missing values -----------------------------------------------
check('blank name -> dash', '-', $rows[1]['updated_by_name']);
check('short rate padded to 6dp', '1 USD = 4.300000 MYR', $rows[1]['rate']);
check('zero charges -> MYR 0.00', 'MYR 0.00', $rows[1]['bank_charges']);

// ---- Thousands separator like the table -----------------------------------
$big = costing_currency_export_rows(array(array('from_currency_code' => 'JPY', 'rate' => '1234.5', 'bank_charges_myr' => '2500')));
check('rate thousands sep', '1 JPY = 1,234.500000 MYR', $big[0]['rate']);
check('charges thousands sep', 'MYR 2,500.00', $big[0]['bank_charges']);

// ---- Missing keys default safely ------------------------------------------
$sparse = costing_currency_export_rows(array(array('from_currency_code' => 'SGD')));
check('missing rate -> 0.000000', '1 SGD = 0.000000 MYR', $sparse[0]['rate']);
check('missing name -> dash', '-', $sparse[0]['updated_by_name']);
check('missing code -> Currency label', '1 Currency = 0.000000 MYR', costing_currency_export_rows(array(array()))[0]['rate']);

// ---- Unparseable / empty date ---------------------------------------------
$baddate = costing_currency_export_rows(array(array('valid_from' => 'not-a-date')));
check('bad date kept verbatim', 'not-a-date', $baddate[0]['valid_from']);
$nodate = costing_currency_export_rows(array(array('valid_from' => '')));
check('empty date -> No date', 'No date', $nodate[0]['valid_from']);

// ---- MYR-only filter matches the Rate History table subset ----------------
$pairs = array(
    array('from_currency_code' => 'USD', 'to_currency_code' => 'MYR', 'rate' => '4.35'),
    array('from_currency_code' => 'USD', 'to_currency_code' => 'SGD', 'rate' => '1.36'),
    array('from_currency_code' => 'EUR', 'to_currency_code' => 'myr', 'rate' => '4.70'),
    array('from_currency_code' => 'EUR', 'rate' => '4.70'),
);
$myrOnly = costing_currency_export_myr_only($pairs);
check('myr filter keeps 2', 2, count($myrOnly));
check('myr filter case-insensitive', 'EUR', $myrOnly[1]['from_currency_code']);
check('myr filter non-array -> []', array(), costing_currency_export_myr_only(null));

// ---- Per-currency grouping (one sheet per currency) -----------------------
$mixed = array(
    array('from_currency_code' => 'USD', 'rate' => '4.35'),
    array('from_currency_code' => 'SGD', 'rate' => '3.20'),
    array('from_currency_code' => 'USD', 'rate' => '4.30'),
    array('from_currency_code' => '',    'rate' => '1.00'),
);
$groups = costing_currency_export_group_by_currency($mixed);
check('group keys in first-seen order', array('USD', 'SGD', '-'), array_keys($groups));
check('USD bucket keeps 2 rows', 2, count($groups['USD']));
check('USD bucket keeps order', array('4.35', '4.30'), array_map(function ($r) { return $r['rate']; }, $groups['USD']));
check('SGD bucket 1 row', 1, count($groups['SGD']));
check('blank code -> dash bucket', 1, count($groups['-']));

// numbering restarts per currency once mapped to export rows
$usdRows = costing_currency_export_rows($groups['USD']);
check('per-currency numbering restarts', array('1', '2'), array_map(function ($r) { return $r['no']; }, $usdRows));

check('group non-array -> []', array(), costing_currency_export_group_by_currency(null));

// ---- Sheet titles: valid, capped at 31, unique ----------------------------
$used = array();
check('plain code title', 'USD', costing_currency_export_sheet_title('USD', $used));
check('illegal chars stripped', 'A B', costing_currency_export_sheet_title('A/B', $used));
check('duplicate deduped', 'USD (2)', costing_currency_export_sheet_title('USD', $used));
$used2 = array();
$long = costing_currency_export_sheet_title(str_repeat('X', 40), $used2);
check('title capped at 31', 31, strlen($long));
check('empty code -> Currency', 'Currency', costing_currency_export_sheet_title('', $used2));

// ---- Defensive: non-array input -> empty list -----------------------------
check('non-array -> []', array(), costing_currency_export_rows(null));

if ($failures === 0) {
    echo "\nAll CostingCurrencyExportRows assertions passed.\n";
    exit(0);
}
echo "\n{$failures} assertion(s) failed.\n";
exit(1);
