<?php
/**
 * Run with: php tests/helpers/CostingPackagesImportTest.php
 *
 * Locks the contract for the Costing Package Excel IMPORT pure layer
 * (application/helpers/costing_packages_import_helper.php): the raw-sheet parser
 * (costing_import_sheet_to_records) and the assembler
 * (costing_import_assemble_packages) that turns parsed records + DB lookup maps
 * into the exact payloads Costing_Model::Import_Package feeds to Save_Package /
 * Generate_Booking_Snapshot / Save_Quote_Details.
 *
 * Covers:
 *   - header-row + fully-blank-row skipping, positional cell mapping;
 *   - grouping Cost Items / Hotels / Flights back to their package by name;
 *   - currency-code -> id and category-code resolution (hit AND miss -> error);
 *   - duplicate Package Name -> global error, first wins;
 *   - detail rows referencing an unknown package -> global error;
 *   - pax == 0 and no-cost-items -> per-package errors;
 *   - combination grouping incl. is_general + manual selling price, and an item
 *     naming an undeclared combination still forming one;
 *   - flight rows folding into options (incl. a blank-title default option);
 *   - itinerary days ordered by Day Number (unnumbered rows last), meal-plan
 *     labels tokenised, and package-level itinerary notes carried as 'notes';
 *   - that bank_charges_myr / myr_per_unit / total_amount are NOT pre-filled.
 *
 * Runs without a DB or PhpSpreadsheet.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}
if (!defined('FCPATH')) {
    define('FCPATH', __DIR__ . '/../../');
}
require_once __DIR__ . '/../../application/helpers/costing_packages_import_helper.php';

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
function check_true($label, $actual) { check($label, true, (bool) $actual); }

/* ----------------------------------------------------- Lookups (fake DB) */

$lookups = array(
    'currency_by_code' => array('MYR' => 1, 'USD' => 2, 'JPY' => 3),
    'category_codes'   => array('accommodation' => 'accommodation', 'tour_leader' => 'tour_leader', 'flight' => 'flight'),
    'sales_by_name'    => array('alice tan' => 10, 'bob lee' => 20),
);

/* ----------------------------------------------------- Parser tests */

$pkg_cols = costing_import_package_columns();
$pkg_labels = array_keys($pkg_cols);

// A sheet with a header row, a blank row, and one data row.
$raw = array(
    $pkg_labels,                                   // header row -> skipped
    array('', '', '', '', '', '', '', '', '', ''), // blank row -> skipped
    array('Japan Spring', 'Tan Family', '+60 12', 't@e.com', 'Alice Tan', '6', '5', 'active', 'desc',
          '2027-04-01', '2027-04-06', '4', '0', '15', '0', '0', 'FIT', 'Twin|Triple', 'Hotel', 'Y'),
);
$records = costing_import_sheet_to_records($raw, $pkg_cols);
check('parser keeps only the data row', 1, count($records));
check('parser maps name', 'Japan Spring', $records[0]['name']);
check('parser maps sales_person', 'Alice Tan', $records[0]['sales_person']);
check('parser maps adult_count cell', '4', $records[0]['adult_count']);
check('parser record key set', array_values($pkg_cols), array_keys($records[0]));

/* ----------------------------------------------------- Assemble: happy path */

$parsed = array(
    'packages' => array(
        array('name' => 'Japan Spring', 'customer_name' => 'Tan Family', 'customer_contact' => '+60 12',
              'customer_email' => 't@e.com', 'sales_person' => 'Alice Tan', 'duration_days' => '6',
              'duration_nights' => '5', 'status' => 'active', 'description' => 'desc',
              'travel_date' => '2027-04-01', 'travel_date_end' => '2027-04-06', 'adult_count' => '4',
              'child_count' => '0', 'margin_percentage' => '15', 'commissionable_per_pax' => '0',
              'ad_hoc_per_pax' => '0', 'quote_flight_mode' => 'FIT', 'quote_hotel_columns' => 'Twin|Triple',
              'quote_hotel_title_label' => 'Hotel', 'quote_show_single_supp' => 'Y',
              'itinerary_notes' => 'Includes daily breakfast.'),
    ),
    'cost_items' => array(
        // combination item
        array('package_name' => 'Japan Spring', 'combination' => 'Option A', 'category' => 'accommodation',
              'name' => '5N Hotel', 'supplier' => 'JTB', 'pax_type' => 'adult', 'multiplier_type' => 'per_pax',
              'quantity' => '1', 'unit_count' => '5', 'unit_price' => '1,200', 'currency' => 'myr'),
        // top-level (blank combination) item, foreign currency
        array('package_name' => 'Japan Spring', 'combination' => '', 'category' => 'tour_leader',
              'name' => 'Tour Leader', 'supplier' => '', 'pax_type' => '', 'multiplier_type' => 'fixed',
              'quantity' => '1', 'unit_count' => '1', 'unit_price' => '500', 'currency' => 'USD'),
        // item naming an UNDECLARED combination -> combination auto-created
        array('package_name' => 'Japan Spring', 'combination' => 'Option B', 'category' => 'flight',
              'name' => 'Air ticket', 'supplier' => '', 'pax_type' => 'adult', 'multiplier_type' => 'per_pax',
              'quantity' => '1', 'unit_count' => '1', 'unit_price' => '2000', 'currency' => 'JPY'),
    ),
    'combinations' => array(
        array('package_name' => 'Japan Spring', 'name' => 'Option A', 'is_general' => 'N', 'selling_price_per_pax' => '4500'),
    ),
    'hotels' => array(
        array('package_name' => 'Japan Spring', 'hotel_name' => "Hotel Nikko\nPrince", 'twin_triple_price' => '1200', 'single_supp_price' => '600'),
    ),
    'flights' => array(
        array('package_name' => 'Japan Spring', 'option_title' => 'Opt 1', 'airline' => 'MAS', 'option_price' => '2500',
              'travel_date' => '01 Apr', 'sector' => 'KUL-NRT', 'flight_no' => 'MH088', 'timing' => '2355-0805', 'duration' => '7h'),
        array('package_name' => 'Japan Spring', 'option_title' => 'Opt 1', 'airline' => '', 'option_price' => '',
              'travel_date' => '06 Apr', 'sector' => 'NRT-KUL', 'flight_no' => 'MH089', 'timing' => '1100-1700', 'duration' => '7h'),
        // blank-title row -> its own default option
        array('package_name' => 'Japan Spring', 'option_title' => '', 'airline' => 'AK', 'option_price' => '900',
              'travel_date' => '01 Apr', 'sector' => 'KUL-KIX', 'flight_no' => 'AK12', 'timing' => '0900-1700', 'duration' => '6h'),
    ),
    'itinerary' => array(
        // deliberately out of order + one row with no day number
        array('package_name' => 'Japan Spring', 'day_number' => '2', 'title' => 'City Tour', 'description' => 'Asakusa', 'meal_plan' => 'Breakfast, Lunch'),
        array('package_name' => 'Japan Spring', 'day_number' => '1', 'title' => 'Arrival', 'description' => 'Transfer', 'meal_plan' => 'Dinner'),
        array('package_name' => 'Japan Spring', 'day_number' => '', 'title' => 'Free & Easy', 'description' => '', 'meal_plan' => ''),
    ),
);

$out = costing_import_assemble_packages($parsed, $lookups);
check('happy: no global errors', array(), $out['global_errors']);
check('happy: one package assembled', 1, count($out['packages']));

$p = $out['packages'][0];
check('happy: no per-package errors', array(), $p['errors']);
check('happy: no per-package warnings', array(), $p['warnings']);
check_true('happy: creatable', $p['creatable']);
check('happy: flight mode FIT -> fit', 'fit', $p['quote_level']['quote_flight_mode']);
check('happy: save_package name', 'Japan Spring', $p['save_package']['name']);
check('happy: sales resolved to AdminID', 10, $p['save_package']['sales_admin_id']);
check('happy: duration coerced to int', 6, $p['save_package']['duration_days']);

// Snapshot payload
$sp = $p['snapshot_payload'];
check('happy: adult_count int', 4, $sp['adult_count']);
check('happy: margin number', 15.0, $sp['margin_percentage']);
check('happy: one top-level row', 1, count($sp['rows']));
check('happy: top-level row is tour_leader', 'tour_leader', $sp['rows'][0]['category']);
check('happy: top-level currency resolved (USD=2)', 2, $sp['rows'][0]['currency_id']);
check_true('happy: bank_charges_myr NOT prefilled', !array_key_exists('bank_charges_myr', $sp['rows'][0]));
check_true('happy: myr_per_unit NOT prefilled', !array_key_exists('myr_per_unit', $sp['rows'][0]));
check_true('happy: total_amount NOT prefilled', !array_key_exists('total_amount', $sp['rows'][0]));
check('happy: top-level row include flag', 1, $sp['rows'][0]['include']);

// Combinations: Option A (declared, priced) + Option B (auto-created from item)
check('happy: two combinations', 2, count($sp['combinations']));
$byName = array();
foreach ($sp['combinations'] as $c) { $byName[$c['name']] = $c; }
check_true('happy: Option A present', isset($byName['Option A']));
check_true('happy: Option B present (auto-created)', isset($byName['Option B']));
check('happy: Option A selling price', 4500.0, $byName['Option A']['selling_price_per_pax']);
check('happy: Option A is_general 0', 0, $byName['Option A']['is_general']);
check('happy: Option A accommodation price (comma parsed)', 1200.0, $byName['Option A']['rows'][0]['unit_price']);
check('happy: Option A currency MYR=1', 1, $byName['Option A']['rows'][0]['currency_id']);
check('happy: Option B selling price null', null, $byName['Option B']['selling_price_per_pax']);
check('happy: Option B currency JPY=3', 3, $byName['Option B']['rows'][0]['currency_id']);

// Hotels
check('happy: one hotel row', 1, count($p['hotels']));
check('happy: hotel name preserved', "Hotel Nikko\nPrince", $p['hotels'][0]['hotel_name']);

// Flights -> grouped options: Opt 1 (2 rows) + default blank (1 row)
check('happy: two flight options', 2, count($p['flight_options']));
check('happy: Opt 1 title', 'Opt 1', $p['flight_options'][0]['title']);
check('happy: Opt 1 airline', 'MAS', $p['flight_options'][0]['airline']);
check('happy: Opt 1 two schedule rows', 2, count($p['flight_options'][0]['rows']));
check('happy: default option blank title', '', $p['flight_options'][1]['title']);
check('happy: default option one row', 1, count($p['flight_options'][1]['rows']));

// Quote level
check('happy: hotel columns split', array('Twin', 'Triple'), $p['quote_level']['quote_hotel_columns']);
check('happy: show single supp Y -> 1', 1, $p['quote_level']['quote_show_single_supp']);

// Itinerary: ordered by day number, unnumbered last; meal plan tokenised; notes carried
check('happy: three itinerary rows', 3, count($p['itinerary']));
check('happy: day 1 first', 'Arrival', $p['itinerary'][0]['title']);
check('happy: day 2 second', 'City Tour', $p['itinerary'][1]['title']);
check('happy: unnumbered row last', 'Free & Easy', $p['itinerary'][2]['title']);
check('happy: day 1 day_number int', 1, $p['itinerary'][0]['day_number']);
check('happy: unnumbered day_number 0', 0, $p['itinerary'][2]['day_number']);
check('happy: meal plan tokens (label->slug)', array('breakfast', 'lunch'), $p['itinerary'][1]['meal_plan']);
check('happy: multi-word meal label tokenised', array('dinner'), $p['itinerary'][0]['meal_plan']);
check('happy: itinerary notes carried as notes', 'Includes daily breakfast.', $p['itinerary_level']['notes']);

/* ----------------------------------------------------- Errors: duplicate name */

$dup = costing_import_assemble_packages(array(
    'packages' => array(
        array('name' => 'Dup', 'adult_count' => '2', 'child_count' => '0', 'status' => 'active',
              'duration_days' => '1', 'duration_nights' => '0'),
        array('name' => 'Dup', 'adult_count' => '2', 'child_count' => '0', 'status' => 'active',
              'duration_days' => '1', 'duration_nights' => '0'),
    ),
    'cost_items' => array(
        array('package_name' => 'Dup', 'combination' => '', 'category' => 'flight', 'name' => 'x',
              'supplier' => '', 'pax_type' => '', 'multiplier_type' => 'fixed', 'quantity' => '1',
              'unit_count' => '1', 'unit_price' => '10', 'currency' => 'MYR'),
    ),
), $lookups);
check('dup: only one package kept', 1, count($dup['packages']));
check_true('dup: a duplicate global error raised', count($dup['global_errors']) === 1
    && strpos($dup['global_errors'][0], 'Duplicate Package Name') !== false);

/* ----------------------------------------------------- Errors: unknown lookups + pax + no items */

$bad = costing_import_assemble_packages(array(
    'packages' => array(
        // pax 0, unknown sales person
        array('name' => 'Broken', 'adult_count' => '0', 'child_count' => '0', 'status' => 'active',
              'sales_person' => 'Nobody', 'duration_days' => '1', 'duration_nights' => '0'),
        // valid header but zero valid items (bad currency + bad category)
        array('name' => 'NoItems', 'adult_count' => '2', 'child_count' => '0', 'status' => 'active',
              'duration_days' => '1', 'duration_nights' => '0'),
    ),
    'cost_items' => array(
        array('package_name' => 'NoItems', 'combination' => '', 'category' => 'accommodation', 'name' => 'Bad cur',
              'supplier' => '', 'pax_type' => '', 'multiplier_type' => 'fixed', 'quantity' => '1',
              'unit_count' => '1', 'unit_price' => '10', 'currency' => 'GBP'),
        array('package_name' => 'NoItems', 'combination' => '', 'category' => 'nope', 'name' => 'Bad cat',
              'supplier' => '', 'pax_type' => '', 'multiplier_type' => 'fixed', 'quantity' => '1',
              'unit_count' => '1', 'unit_price' => '10', 'currency' => 'MYR'),
    ),
    // detail row for a package that does not exist
    'hotels' => array(
        array('package_name' => 'Ghost', 'hotel_name' => 'X', 'twin_triple_price' => '1', 'single_supp_price' => ''),
    ),
), $lookups);

$pkgByName = array();
foreach ($bad['packages'] as $bp) { $pkgByName[$bp['name']] = $bp; }

check_true('broken: pax-0 error present', (function () use ($pkgByName) {
    foreach ($pkgByName['Broken']['errors'] as $e) { if (strpos($e, 'Total pax is zero') !== false) return true; }
    return false;
})());
check_true('broken: not creatable', !$pkgByName['Broken']['creatable']);
check_true('broken: unknown sales person is a WARNING (not blocking)', (function () use ($pkgByName) {
    foreach ($pkgByName['Broken']['warnings'] as $w) { if (strpos($w, 'Sales Person') !== false) return true; }
    return false;
})());
check_true('broken: sales person NOT in errors', (function () use ($pkgByName) {
    foreach ($pkgByName['Broken']['errors'] as $e) { if (strpos($e, 'Sales Person') !== false) return false; }
    return true;
})());
check_true('noitems: unknown currency error', (function () use ($pkgByName) {
    foreach ($pkgByName['NoItems']['errors'] as $e) { if (strpos($e, 'unknown Currency') !== false) return true; }
    return false;
})());
check_true('noitems: unknown category error', (function () use ($pkgByName) {
    foreach ($pkgByName['NoItems']['errors'] as $e) { if (strpos($e, 'unknown Category') !== false) return true; }
    return false;
})());
check_true('noitems: "no valid cost items" error', (function () use ($pkgByName) {
    foreach ($pkgByName['NoItems']['errors'] as $e) { if (strpos($e, 'No valid cost items') !== false) return true; }
    return false;
})());
check_true('ghost: unknown-package global error', (function () use ($bad) {
    foreach ($bad['global_errors'] as $e) { if (strpos($e, 'unknown Package Name') !== false) return true; }
    return false;
})());

/* ----------------------------------------------------- Field warnings (non-blocking) */

$warn = costing_import_assemble_packages(array(
    'packages' => array(
        array('name' => 'Warny', 'adult_count' => '2', 'child_count' => '0', 'status' => 'archived',
              'duration_days' => '1', 'duration_nights' => '0', 'travel_date' => '2027-05-01', 'quote_flight_mode' => 'maybe'),
    ),
    'cost_items' => array(
        array('package_name' => 'Warny', 'combination' => '', 'category' => 'flight', 'name' => 'Air',
              'supplier' => '', 'pax_type' => 'senior', 'multiplier_type' => 'weekly', 'quantity' => '1',
              'unit_count' => '1', 'unit_price' => '10', 'currency' => 'MYR'),
    ),
), $lookups)['packages'][0];

check_true('warn: still creatable despite warnings', $warn['creatable']);
check('warn: no blocking errors', array(), $warn['errors']);
check_true('warn: unknown status warned', (function () use ($warn) {
    foreach ($warn['warnings'] as $w) { if (strpos($w, 'Status') !== false) return true; } return false;
})());
check_true('warn: unknown flight mode warned', (function () use ($warn) {
    foreach ($warn['warnings'] as $w) { if (strpos($w, 'Flight Mode') !== false) return true; } return false;
})());
check_true('warn: unknown multiplier warned', (function () use ($warn) {
    foreach ($warn['warnings'] as $w) { if (strpos($w, 'Multiplier Type') !== false) return true; } return false;
})());
check_true('warn: unknown pax type warned', (function () use ($warn) {
    foreach ($warn['warnings'] as $w) { if (strpos($w, 'Pax Type') !== false) return true; } return false;
})());
check('warn: pax type blanked', '', $warn['snapshot_payload']['rows'][0]['pax_type']);

// Flight mode label mapping: "No" -> 'none'
check('flight mode No -> none', 'none', costing_import_flight_mode('No'));
check('flight mode blank -> blank', '', costing_import_flight_mode(''));
check('flight mode junk -> null', null, costing_import_flight_mode('zzz'));

/* ----------------------------------------------------- Required / nullable fields */

$req = costing_import_assemble_packages(array(
    'packages' => array(
        // missing TRAVEL DATE and DURATION DAYS (both required)
        array('name' => 'NoHeader', 'adult_count' => '2', 'child_count' => '0', 'status' => 'active'),
        // header OK, but item rows miss required fields
        array('name' => 'BadItems', 'adult_count' => '2', 'child_count' => '0', 'status' => 'active',
              'travel_date' => '2027-05-01', 'duration_days' => '3'),
        // header OK + one fully-valid item => creatable
        array('name' => 'GoodOne', 'adult_count' => '2', 'child_count' => '0', 'status' => 'active',
              'travel_date' => '2027-05-01', 'duration_days' => '3'),
    ),
    'cost_items' => array(
        // missing Item name (but has category/price) -> required name error
        array('package_name' => 'BadItems', 'combination' => '', 'category' => 'flight', 'name' => '',
              'supplier' => '', 'pax_type' => '', 'multiplier_type' => 'fixed', 'quantity' => '1',
              'unit_count' => '1', 'unit_price' => '100', 'currency' => 'MYR'),
        // missing Unit Price -> required price error
        array('package_name' => 'BadItems', 'combination' => '', 'category' => 'flight', 'name' => 'No Price',
              'supplier' => '', 'pax_type' => '', 'multiplier_type' => 'fixed', 'quantity' => '1',
              'unit_count' => '1', 'unit_price' => '', 'currency' => 'MYR'),
        // empty filler row (only package name) -> silently skipped, no error
        array('package_name' => 'BadItems', 'combination' => '', 'category' => '', 'name' => '',
              'supplier' => '', 'pax_type' => '', 'multiplier_type' => '', 'quantity' => '',
              'unit_count' => '', 'unit_price' => '', 'currency' => ''),
        array('package_name' => 'GoodOne', 'combination' => '', 'category' => 'flight', 'name' => 'Air',
              'supplier' => '', 'pax_type' => '', 'multiplier_type' => 'fixed', 'quantity' => '1',
              'unit_count' => '1', 'unit_price' => '100', 'currency' => 'MYR'),
    ),
), $lookups);
$reqByName = array();
foreach ($req['packages'] as $rp) { $reqByName[$rp['name']] = $rp; }

check_true('req: missing travel date errors', (function () use ($reqByName) {
    foreach ($reqByName['NoHeader']['errors'] as $e) { if (strpos($e, 'Travel Date is required') !== false) return true; } return false;
})());
check_true('req: missing duration days errors', (function () use ($reqByName) {
    foreach ($reqByName['NoHeader']['errors'] as $e) { if (strpos($e, 'Duration Days is required') !== false) return true; } return false;
})());
check_true('req: NoHeader not creatable', !$reqByName['NoHeader']['creatable']);
check_true('req: missing item name errors', (function () use ($reqByName) {
    foreach ($reqByName['BadItems']['errors'] as $e) { if (strpos($e, 'missing its required Item name') !== false) return true; } return false;
})());
check_true('req: missing unit price errors', (function () use ($reqByName) {
    foreach ($reqByName['BadItems']['errors'] as $e) { if (strpos($e, 'Unit Price is required') !== false) return true; } return false;
})());
check_true('req: BadItems not creatable', !$reqByName['BadItems']['creatable']);
check_true('req: GoodOne creatable', $reqByName['GoodOne']['creatable']);
check('req: GoodOne has exactly one item', 1, count($reqByName['GoodOne']['snapshot_payload']['rows']));

/* ----------------------------------------------------- Preview summary */

$prev = costing_import_preview_summary($bad);
check('preview: total packages', 2, $prev['total']);
check('preview: none creatable (both broken)', 0, $prev['create_count']);
check('preview: both skipped', 2, $prev['skip_count']);
check_true('preview: carries global errors', count($prev['global_errors']) >= 1);

$prev2 = costing_import_preview_summary($out); // the happy package
check('preview: happy create_count', 1, $prev2['create_count']);
check('preview: happy cost_items count (1 top + 2 combo rows)', 3, $prev2['rows'][0]['cost_items']);
check('preview: happy itinerary days', 3, $prev2['rows'][0]['itinerary_days']);
check('preview: happy pax', 4, $prev2['rows'][0]['pax']);

/* ----------------------------------------------------- Summary */

echo "\n";
if ($failures === 0) {
    echo "ALL PASS\n";
    exit(0);
}
echo "{$failures} FAILURE(S)\n";
exit(1);
