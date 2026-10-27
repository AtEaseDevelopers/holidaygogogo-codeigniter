<?php
/**
 * Run with: php tests/helpers/CostingPackagesExportTest.php
 *
 * Locks the contract for the Costing Package listing Excel export
 * (application/helpers/costing_packages_export_helper.php) - the pure mappers
 * that turn the flat package + detail rows from
 * Costing_Model::Read_Packages_For_Export into the per-package worksheets the
 * export writes (ONE sheet per package: a summary block + Cost Items / Hotels /
 * Flights / Combinations sections). Covers:
 *   - every mapped detail row has exactly the keys its column set declares;
 *   - summary formatting: duration string, money number_format, status ucfirst,
 *     a package with no booking blanks the booking/financial columns to '-';
 *   - empty input -> empty rows;
 *   - grouping detail rows under their package via the stamped package_id;
 *   - the per-package summary pair block + unique worksheet titles.
 *
 * Runs without a DB or PhpSpreadsheet.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}
if (!defined('FCPATH')) {
    define('FCPATH', __DIR__ . '/../../');
}
require_once __DIR__ . '/../../application/helpers/costing_packages_export_helper.php';

$failures = 0;
function check($label, $expected, $actual)
{
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

// Each row's keys must match its column-set keys, in the declared order, so the
// stream function writes a complete, correctly-ordered row.
function assert_row_keys_match_columns($label, $columns, $rows)
{
    $expected_keys = array_map(function ($c) { return $c[1]; }, $columns);
    foreach ($rows as $i => $row) {
        check($label . " row {$i} keys", $expected_keys, array_keys($row));
    }
}

/* -------------------------------------------------------------- Packages */

$packages = array(
    // Fully-costed package with a latest booking.
    array(
        'id' => 7,
        'name' => 'Bali 5D4N',
        'tour_code' => 'BALI-001',
        'customer_name' => 'John Tan',
        'customer_contact' => '+60 123456789',
        'customer_email' => 'john@example.com',
        'sales_admin_name' => 'Alice',
        'duration_days' => 5,
        'duration_nights' => 4,
        'status' => 'active',
        'updated_at' => '2026-10-20 14:30:00',
        'latest_booking_id' => 55,
        'latest_booking_label' => 'QU-BALI-001',
        'latest_travel_date' => '2026-12-01',
        'latest_total_pax' => 12,
        'margin_percentage' => '18.5',
        'total_cost' => '12000.5',
        'selling_price_per_pax' => '1225.75',
        'total_revenue' => '14709',
        'total_profit' => '2708.5',
    ),
    // Package with NO booking yet - booking/financial cells must blank to '-'.
    array(
        'id' => 8,
        'name' => 'Draft Package',
        'tour_code' => '',
        'customer_name' => '',
        'customer_contact' => '',
        'customer_email' => '',
        'sales_admin_name' => '',
        'duration_days' => 0,
        'duration_nights' => 0,
        'status' => 'inactive',
        'updated_at' => '2026-10-21 09:00:00',
        'latest_booking_id' => null,
        'latest_booking_label' => 'No booking yet',
    ),
);

$summary_cols = costing_packages_export_summary_columns();
$summary_rows = costing_packages_export_summary_rows($packages);

check('summary: one row per package', 2, count($summary_rows));
assert_row_keys_match_columns('summary', $summary_cols, $summary_rows);

// Booked package: formatting.
check('summary: numbering from 1', '1', $summary_rows[0]['no']);
check('summary: duration string', '5 Days / 4 Nights', $summary_rows[0]['duration']);
check('summary: status ucfirst', 'Active', $summary_rows[0]['status']);
check('summary: margin fixed 2dp', '18.50', $summary_rows[0]['margin_percentage']);
check('summary: total cost grouped money', '12,000.50', $summary_rows[0]['total_cost']);
check('summary: selling money', '1,225.75', $summary_rows[0]['selling_price_per_pax']);
check('summary: pax as int string', '12', $summary_rows[0]['latest_total_pax']);
check('summary: travel date formatted', '01 Dec 2026', $summary_rows[0]['latest_travel_date']);
check('summary: updated_at with time', '20 Oct 2026 14:30', $summary_rows[0]['updated_at']);

// No-booking package: booking/financial columns blank to '-', text blanks to '-'.
check('summary: no-booking label', 'No booking yet', $summary_rows[1]['latest_booking_label']);
check('summary: no-booking travel date', '-', $summary_rows[1]['latest_travel_date']);
check('summary: no-booking pax', '-', $summary_rows[1]['latest_total_pax']);
check('summary: no-booking margin', '-', $summary_rows[1]['margin_percentage']);
check('summary: no-booking total cost', '-', $summary_rows[1]['total_cost']);
check('summary: no-booking profit', '-', $summary_rows[1]['total_profit']);
check('summary: empty tour_code -> -', '-', $summary_rows[1]['tour_code']);
check('summary: empty customer -> -', '-', $summary_rows[1]['customer_name']);
check('summary: empty sales person -> -', '-', $summary_rows[1]['sales_admin_name']);
check('summary: zero duration string', '0 Days / 0 Nights', $summary_rows[1]['duration']);

check('summary: empty input -> no rows', array(), costing_packages_export_summary_rows(array()));

/* ------------------------------------------------------------ Cost Items */

$items = array(
    array(
        'package_id' => 7,
        'category' => 'Hotel', 'name' => 'Grand Hyatt', 'supplier' => 'Agoda',
        'pax_type' => 'adult', 'quantity' => '12', 'unit_price' => '350.5',
        'currency' => 'USD', 'total_amount' => '4206', 'myr_per_unit' => '1652.36',
    ),
    // Null myr_per_unit (legacy row) + blank supplier -> '-'.
    array(
        'package_id' => 7,
        'category' => '', 'name' => 'Misc', 'supplier' => '',
        'pax_type' => '', 'quantity' => '1', 'unit_price' => '0',
        'currency' => 'MYR', 'total_amount' => '0', 'myr_per_unit' => null,
    ),
);

$item_cols = costing_packages_export_item_columns();
$item_rows = costing_packages_export_item_rows($items);

check('items: row count', 2, count($item_rows));
assert_row_keys_match_columns('items', $item_cols, $item_rows);
check('items: unit price money', '350.50', $item_rows[0]['unit_price']);
check('items: total money', '4,206.00', $item_rows[0]['total_amount']);
check('items: myr/unit money', '1,652.36', $item_rows[0]['myr_per_unit']);
check('items: null myr/unit -> -', '-', $item_rows[1]['myr_per_unit']);
check('items: blank supplier -> -', '-', $item_rows[1]['supplier']);
check('items: blank category -> -', '-', $item_rows[1]['category']);
check('items: empty input -> no rows', array(), costing_packages_export_item_rows(array()));

/* ---------------------------------------------------------------- Hotels */

$hotels = array(
    array(
        'package_id' => 7,
        'hotel_name' => "Grand Hyatt\nAyana Resort", 'twin_triple_price' => '450',
        'single_supp_price' => '200',
    ),
);
$hotel_cols = costing_packages_export_hotel_columns();
$hotel_rows = costing_packages_export_hotel_rows($hotels);
assert_row_keys_match_columns('hotels', $hotel_cols, $hotel_rows);
check('hotels: multi-line name preserved', "Grand Hyatt\nAyana Resort", $hotel_rows[0]['hotel_name']);
check('hotels: twin price money', '450.00', $hotel_rows[0]['twin_triple_price']);
check('hotels: single supp money', '200.00', $hotel_rows[0]['single_supp_price']);
check('hotels: empty input -> no rows', array(), costing_packages_export_hotel_rows(array()));

/* --------------------------------------------------------------- Flights */

$flights = array(
    array(
        'package_id' => 7,
        'travel_date' => '01 Dec', 'sector' => 'KUL-DPS', 'flight_no' => 'AK123',
        'timing' => '0800-1100', 'duration' => '3h',
    ),
    array(
        'package_id' => 7,
        'travel_date' => '', 'sector' => '', 'flight_no' => '',
        'timing' => '', 'duration' => '',
    ),
);
$flight_cols = costing_packages_export_flight_columns();
$flight_rows = costing_packages_export_flight_rows($flights);
assert_row_keys_match_columns('flights', $flight_cols, $flight_rows);
check('flights: sector kept', 'KUL-DPS', $flight_rows[0]['sector']);
check('flights: blank sector -> -', '-', $flight_rows[1]['sector']);
check('flights: blank date -> -', '-', $flight_rows[1]['travel_date']);
check('flights: empty input -> no rows', array(), costing_packages_export_flight_rows(array()));

/* ----------------------------------------------------------- Combinations */

$combos = array(
    array('package_id' => 7, 'name' => 'Standard', 'selling_price_per_pax' => '1225.75'),
    array('package_id' => 7, 'name' => 'Premium', 'selling_price_per_pax' => null),
);
$combo_cols = costing_packages_export_combination_columns();
$combo_rows = costing_packages_export_combination_rows($combos);
assert_row_keys_match_columns('combinations', $combo_cols, $combo_rows);
check('combinations: name kept', 'Standard', $combo_rows[0]['name']);
check('combinations: selling money', '1,225.75', $combo_rows[0]['selling_price_per_pax']);
check('combinations: null selling -> -', '-', $combo_rows[1]['selling_price_per_pax']);
check('combinations: empty input -> no rows', array(), costing_packages_export_combination_rows(array()));

/* -------------------------------------------------- Summary pairs (vertical) */

$pairs = costing_packages_export_summary_pairs($packages[0]);
$pair_labels = array_map(function ($p) { return $p[0]; }, $pairs);
check('pairs: No. column dropped', false, in_array('No.', $pair_labels, true));
check('pairs: first label is Package Name', 'Package Name', $pairs[0][0]);
check('pairs: Package Name value', 'Bali 5D4N', $pairs[0][1]);
// Value for Total Cost (MYR) pair equals the mapped money string.
$cost_pair = null;
foreach ($pairs as $p) { if ($p[0] === 'Total Cost (MYR)') { $cost_pair = $p; } }
check('pairs: total cost value', '12,000.50', $cost_pair[1]);

/* --------------------------------------------- Group one sheet per package */

$grouped = costing_packages_export_group_by_package(array(
    'packages' => $packages,
    'items' => $items,
    'hotels' => $hotels,
    'flights' => $flights,
    'combinations' => $combos,
));
check('group: one bundle per package', 2, count($grouped));
check('group: bundle order follows packages', array(7, 8),
    array_map(function ($b) { return (int) $b['package']['id']; }, $grouped));
// Package 7 owns all the detail; package 8 (no booking) owns none.
check('group: pkg 7 gets its items', 2, count($grouped[0]['items']));
check('group: pkg 7 gets its hotels', 1, count($grouped[0]['hotels']));
check('group: pkg 7 gets its combinations', 2, count($grouped[0]['combinations']));
check('group: pkg 8 has no items', 0, count($grouped[1]['items']));
check('group: pkg 8 has no hotels', 0, count($grouped[1]['hotels']));
check('group: empty data -> no bundles', array(), costing_packages_export_group_by_package(array()));

// Each bundle expands to the four detail sections in a fixed order.
$sections = costing_packages_export_sections($grouped[0]);
check('sections: four per package', 4, count($sections));
check('sections: titles in order',
    array('Cost Items', 'Hotels', 'Flights', 'Combinations'),
    array_map(function ($s) { return $s[0]; }, $sections));
check('sections: cost items rows mapped', 2, count($sections[0][2]));

/* ----------------------------------------------------- Worksheet titling */

$used = array();
check('title: from package name', 'Bali 5D4N', costing_packages_export_sheet_title(array('name' => 'Bali 5D4N', 'tour_code' => 'BALI-001'), $used));
// Same name again -> suffixed, never collides (revisions share a name).
check('title: duplicate name suffixed', 'Bali 5D4N (2)', costing_packages_export_sheet_title(array('name' => 'Bali 5D4N', 'tour_code' => 'BALI-002'), $used));
// Illegal Excel chars scrubbed.
$used2 = array();
check('title: illegal chars scrubbed', 'Bali Lombok', costing_packages_export_sheet_title(array('name' => 'Bali/Lombok', 'tour_code' => ''), $used2));
// Blank name falls back to tour code, then "Package".
$used3 = array();
check('title: blank name -> tour code', 'T-001', costing_packages_export_sheet_title(array('name' => '', 'tour_code' => 'T-001'), $used3));
$used4 = array();
check('title: all blank -> Package', 'Package', costing_packages_export_sheet_title(array('name' => '', 'tour_code' => ''), $used4));
// 31-char Excel cap.
$used5 = array();
$long = costing_packages_export_sheet_title(array('name' => str_repeat('A', 40), 'tour_code' => ''), $used5);
check('title: capped at 31 chars', 31, strlen($long));

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
