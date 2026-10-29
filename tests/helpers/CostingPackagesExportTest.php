<?php
/**
 * Run with: php tests/helpers/CostingPackagesExportTest.php
 *
 * Locks the contract for the Costing Package listing Excel export
 * (application/helpers/costing_packages_export_helper.php) — the pure mappers that
 * turn the raw package + detail rows from Costing_Model::Read_Packages_For_Export
 * into the IMPORT-template-shaped worksheets (Packages / Cost Items / Combinations
 * / Hotels / Flights / Itinerary) with a TOUR CODE column after Package Name, so an
 * export round-trips back through the Import flow. Covers:
 *   - formatters: Y-m-d dates, no-thousands numbers w/ trailing-zero trim,
 *     nullable numbers, optional + plain Y/N, JSON hotel-columns -> pipe cell;
 *   - TOUR CODE inserted after Package Name on every sheet; export sheet meta
 *     mirrors the import sheet meta (same keys/titles);
 *   - every record mapper emits EXACTLY its column keys, blanks stay '' (never
 *     "-"), a no-booking package blanks its booking/financial cells;
 *   - the export cells re-parse through the header-aware import parser with the
 *     TOUR CODE column ignored (round-trip at the parser boundary).
 *
 * Runs without a DB or PhpSpreadsheet.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}
if (!defined('FCPATH')) {
    define('FCPATH', __DIR__ . '/../../');
}
// The export reuses the import column maps + parser, and resolves the four
// itinerary sections via the costing_itinerary helper, so load all three.
require_once __DIR__ . '/../../application/helpers/costing_packages_import_helper.php';
require_once __DIR__ . '/../../application/helpers/costing_itinerary_helper.php';
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
function check_true($label, $actual) { check($label, true, (bool) $actual); }

/* ------------------------------------------------------------ Formatters */

check('num: trims trailing zeros', '5', costing_packages_export_num('5.00'));
check('num: keeps one decimal', '1200.5', costing_packages_export_num('1200.50'));
check('num: keeps two decimals', '12.34', costing_packages_export_num('12.34'));
check('num: strips thousands (float)', '1234.5', costing_packages_export_num(1234.5));
check('num: zero', '0', costing_packages_export_num('0'));
check('num: blank -> 0', '0', costing_packages_export_num(''));
check('num_or_blank: null -> blank', '', costing_packages_export_num_or_blank(null));
check('num_or_blank: empty -> blank', '', costing_packages_export_num_or_blank(''));
check('num_or_blank: value formatted', '4500', costing_packages_export_num_or_blank('4500.00'));
check('num_or_blank: zero stays 0', '0', costing_packages_export_num_or_blank('0'));

check('ymd: date formatted', '2026-12-01', costing_packages_export_ymd('2026-12-01'));
check('ymd: datetime trimmed to date', '2026-12-01', costing_packages_export_ymd('2026-12-01 10:30:00'));
check('ymd: blank -> blank', '', costing_packages_export_ymd(''));
check('ymd: zero date -> blank', '', costing_packages_export_ymd('0000-00-00'));
check('ymd: unparseable kept', 'Next spring', costing_packages_export_ymd('Next spring'));

check('yn: 1 -> Y', 'Y', costing_packages_export_yn('1'));
check('yn: 0 -> N', 'N', costing_packages_export_yn('0'));
check('yn: blank -> N', 'N', costing_packages_export_yn(''));
check('optional_yn: null -> blank', '', costing_packages_export_optional_yn(null));
check('optional_yn: empty -> blank', '', costing_packages_export_optional_yn(''));
check('optional_yn: 1 -> Y', 'Y', costing_packages_export_optional_yn('1'));
check('optional_yn: 0 -> N', 'N', costing_packages_export_optional_yn('0'));

check('hotel cols: JSON array -> pipe', 'Twin|Extra Bed', costing_packages_export_hotel_columns_cell('["Twin","Extra Bed"]'));
check('hotel cols: JSON with slash kept', 'Twin / Triple', costing_packages_export_hotel_columns_cell('["Twin \/ Triple"]'));
check('hotel cols: blank -> blank', '', costing_packages_export_hotel_columns_cell(''));
check('hotel cols: plain kept', 'Twin|Triple', costing_packages_export_hotel_columns_cell('Twin|Triple'));

/* -------------------------------------------- Column sets (import + tour code) */

$pkg_cols = costing_packages_export_insert_tour_code(costing_import_package_columns());
$pkg_labels = array_keys($pkg_cols);
check('insert: tour code is 2nd column', 'TOUR CODE', $pkg_labels[1]);
check('insert: tour code key', 'tour_code', $pkg_cols['TOUR CODE']);
check('insert: package name still first', 'PACKAGE NAME', $pkg_labels[0]);
check('insert: adds exactly one column', count(costing_import_package_columns()) + 1, count($pkg_cols));

$sheets = costing_packages_export_sheets_meta();
check('meta: six sheets', 6, count($sheets));
check('meta: keys mirror import',
    array('packages', 'cost_items', 'combinations', 'hotels', 'flights', 'itinerary'),
    array_map(function ($s) { return $s[0]; }, $sheets));
check('meta: titles mirror import',
    array('Packages', 'Cost Items', 'Combinations', 'Hotels', 'Flights', 'Itinerary'),
    array_map(function ($s) { return $s[1]; }, $sheets));
// Every sheet carries TOUR CODE as its 2nd column.
foreach ($sheets as $s) {
    $labels = array_keys($s[2]);
    check('meta: ' . $s[1] . ' tour code 2nd', 'TOUR CODE', $labels[1]);
}

/* ------------------------------------------------- Packages record mapper */

$booked = array(
    'id' => 7, 'name' => 'Bali 5D4N', 'tour_code' => 'QU-2612-0007',
    'customer_name' => 'John Tan', 'customer_contact' => '+60 123456789',
    'customer_email' => 'john@example.com', 'sales_admin_name' => 'Alice',
    'duration_days' => 5, 'duration_nights' => 4, 'status' => 'active',
    'description' => 'Island hopping',
    // Storage is the original plural columns (section_meta $meta[1]); the export
    // reads these and emits them under the short record keys (itinerary_include...).
    'itinerary_includes' => 'Hotel & breakfast', 'itinerary_excludes' => 'Tips',
    'itinerary_important_notes' => 'Bring passport', 'itinerary_terms_and_conditions' => 'No refunds',
    'quote_flight_mode' => 'fit', 'quote_hotel_columns' => '["Twin \/ Triple","Extra Bed"]',
    'quote_hotel_title_label' => 'Hotel', 'quote_show_single_supp' => '1',
    'latest_booking_id' => 55, 'latest_travel_date' => '2026-12-01',
    'latest_travel_date_end' => '2026-12-05', 'latest_adult_count' => 10,
    'latest_child_count' => 2, 'latest_total_pax' => 12,
    'margin_percentage' => '18.50', 'commissionable_per_pax' => '0.00',
    'ad_hoc_per_pax' => '50.00',
);
$draft = array(
    'id' => 8, 'name' => 'Draft Package', 'tour_code' => '',
    'customer_name' => '', 'customer_contact' => '', 'customer_email' => '',
    'sales_admin_name' => '', 'duration_days' => 0, 'duration_nights' => 0,
    'status' => 'inactive', 'description' => '',
    'itinerary_includes' => '', 'itinerary_excludes' => '',
    'itinerary_important_notes' => '', 'itinerary_terms_and_conditions' => '', 'itinerary_notes' => '',
    'quote_flight_mode' => '', 'quote_hotel_columns' => '',
    'quote_hotel_title_label' => '', 'quote_show_single_supp' => null,
    'latest_booking_id' => null,
);

$rec = costing_packages_export_package_record($booked);
check('pkg: key set matches columns', array_values($pkg_cols), array_keys($rec));
check('pkg: tour code', 'QU-2612-0007', $rec['tour_code']);
check('pkg: sales_person from admin name', 'Alice', $rec['sales_person']);
check('pkg: travel date ymd', '2026-12-01', $rec['travel_date']);
check('pkg: travel date end ymd', '2026-12-05', $rec['travel_date_end']);
check('pkg: adult count', '10', $rec['adult_count']);
check('pkg: child count', '2', $rec['child_count']);
check('pkg: margin trimmed', '18.5', $rec['margin_percentage']);
check('pkg: commissionable 0', '0', $rec['commissionable_per_pax']);
check('pkg: ad hoc', '50', $rec['ad_hoc_per_pax']);
check('pkg: flight mode code', 'fit', $rec['quote_flight_mode']);
check('pkg: hotel columns pipe', 'Twin / Triple|Extra Bed', $rec['quote_hotel_columns']);
check('pkg: show single supp Y', 'Y', $rec['quote_show_single_supp']);
check('pkg: status stored value', 'active', $rec['status']);
// The four itinerary-wide sections export to their own columns (from the new
// per-section columns; falls back to splitting legacy itinerary_notes).
check('pkg: itinerary include', 'Hotel & breakfast', $rec['itinerary_include']);
check('pkg: itinerary exclude', 'Tips', $rec['itinerary_exclude']);
check('pkg: itinerary important notes', 'Bring passport', $rec['itinerary_important_notes']);
check('pkg: itinerary terms', 'No refunds', $rec['itinerary_terms']);

$rec2 = costing_packages_export_package_record($draft);
check('pkg: no-booking travel date blank', '', $rec2['travel_date']);
check('pkg: no-booking adult blank', '', $rec2['adult_count']);
check('pkg: no-booking margin blank', '', $rec2['margin_percentage']);
check('pkg: no-booking commissionable blank', '', $rec2['commissionable_per_pax']);
check('pkg: blank tour code stays blank (not -)', '', $rec2['tour_code']);
check('pkg: blank customer stays blank (not -)', '', $rec2['customer_name']);
check('pkg: blank hotel columns', '', $rec2['quote_hotel_columns']);
check('pkg: null single supp blank', '', $rec2['quote_show_single_supp']);

/* -------------------------------------------------- Cost Items record mapper */

$item_cols = costing_packages_export_insert_tour_code(costing_import_item_columns());
$item = array(
    'package_name' => 'Bali 5D4N', 'tour_code' => 'QU-2612-0007',
    'combination' => '4 Star Hotel', 'category' => 'ground_package',
    'name' => '5N Hotel Twin', 'supplier' => 'Agoda', 'pax_type' => 'adult',
    'multiplier_type' => 'per_pax', 'quantity' => '1.00', 'unit_count' => '5.00',
    'unit_price' => '1200.50', 'currency' => 'USD',
);
$item_top = array(
    'package_name' => 'Bali 5D4N', 'tour_code' => 'QU-2612-0007',
    'combination' => null, 'category' => 'tour_leader', 'name' => 'Tour Leader',
    'supplier' => '', 'pax_type' => '', 'multiplier_type' => 'fixed',
    'quantity' => '1', 'unit_count' => '1', 'unit_price' => '3000', 'currency' => 'MYR',
);
$irec = costing_packages_export_item_record($item);
check('item: key set matches columns', array_values($item_cols), array_keys($irec));
check('item: combination name', '4 Star Hotel', $irec['combination']);
check('item: category code kept', 'ground_package', $irec['category']);
check('item: quantity trimmed', '1', $irec['quantity']);
check('item: unit count trimmed', '5', $irec['unit_count']);
check('item: unit price', '1200.5', $irec['unit_price']);
check('item: currency', 'USD', $irec['currency']);
$irec2 = costing_packages_export_item_record($item_top);
check('item: null combination -> blank', '', $irec2['combination']);
check('item: blank supplier stays blank', '', $irec2['supplier']);

/* ------------------------------------------------- Combinations record mapper */

$combo_cols = costing_packages_export_insert_tour_code(costing_import_combination_columns());
$crec = costing_packages_export_combination_record(array(
    'package_name' => 'Bali 5D4N', 'tour_code' => 'QU-2612-0007',
    'name' => '4 Star Hotel', 'is_general' => '0', 'selling_price_per_pax' => '1500.00',
));
check('combo: key set matches columns', array_values($combo_cols), array_keys($crec));
check('combo: is_general N', 'N', $crec['is_general']);
check('combo: selling trimmed', '1500', $crec['selling_price_per_pax']);
$crec2 = costing_packages_export_combination_record(array(
    'package_name' => 'X', 'tour_code' => 'T', 'name' => 'Gen',
    'is_general' => '1', 'selling_price_per_pax' => null,
));
check('combo: is_general Y', 'Y', $crec2['is_general']);
check('combo: null selling -> blank', '', $crec2['selling_price_per_pax']);

/* -------------------------------------------------------- Hotels record mapper */

$hotel_cols = costing_packages_export_insert_tour_code(costing_import_hotel_columns());
$hrec = costing_packages_export_hotel_record(array(
    'package_name' => 'Bali 5D4N', 'tour_code' => 'QU-2612-0007',
    'hotel_name' => "Grand Hyatt\nAyana Resort", 'twin_triple_price' => '450.00',
    'single_supp_price' => '200.00',
));
check('hotel: key set matches columns', array_values($hotel_cols), array_keys($hrec));
check('hotel: multi-line name preserved', "Grand Hyatt\nAyana Resort", $hrec['hotel_name']);
check('hotel: twin price trimmed', '450', $hrec['twin_triple_price']);
check('hotel: single supp trimmed', '200', $hrec['single_supp_price']);

/* ------------------------------------------------------- Flights record mapper */

$flight_cols = costing_packages_export_insert_tour_code(costing_import_flight_columns());
$frec = costing_packages_export_flight_record(array(
    'package_name' => 'Bali 5D4N', 'tour_code' => 'QU-2612-0007',
    'option_title' => 'Option 1 - MAS', 'airline' => 'Malaysia Airlines',
    'option_price' => '2500.00', 'travel_date' => '01 Dec 2026', 'sector' => 'KUL-DPS',
    'flight_no' => 'MH088', 'timing' => '2355-0805', 'duration' => '7h 10m',
));
check('flight: key set matches columns', array_values($flight_cols), array_keys($frec));
check('flight: option title', 'Option 1 - MAS', $frec['option_title']);
check('flight: airline', 'Malaysia Airlines', $frec['airline']);
check('flight: option price trimmed', '2500', $frec['option_price']);
$frec2 = costing_packages_export_flight_record(array(
    'package_name' => 'X', 'tour_code' => 'T', 'option_title' => 'Opt', 'airline' => '',
    'option_price' => null, 'travel_date' => '', 'sector' => '', 'flight_no' => '',
    'timing' => '', 'duration' => '',
));
check('flight: null option price blank', '', $frec2['option_price']);
check('flight: blank leg fields stay blank', '', $frec2['sector']);

/* ----------------------------------------------------- Itinerary record mapper */

$itin_cols = costing_packages_export_insert_tour_code(costing_import_itinerary_columns());
$itrec = costing_packages_export_itinerary_record(array(
    'package_name' => 'Bali 5D4N', 'tour_code' => 'QU-2612-0007',
    'day_number' => '2', 'title' => 'City Tour', 'description' => 'Asakusa & Shibuya',
    'meal_plan' => 'breakfast,lunch,dinner',
));
check('itin: key set matches columns', array_values($itin_cols), array_keys($itrec));
check('itin: day number', '2', $itrec['day_number']);
check('itin: meal plan slug string kept', 'breakfast,lunch,dinner', $itrec['meal_plan']);

/* ------------------------------------------------------- Bucket dispatcher */

check('rows: unknown bucket -> empty', array(), costing_packages_export_sheet_rows('nope', array(array('x' => 1))));
$mapped = costing_packages_export_sheet_rows('packages', array($booked, $draft));
check('rows: dispatch maps each row', 2, count($mapped));
check('rows: dispatch uses package mapper', 'Bali 5D4N', $mapped[0]['name']);

/* ----------------------------------- Round-trip: export cells -> import parser */
// Build a raw Cost Items sheet exactly as the export writes it (header incl. TOUR
// CODE, then the exported record), then parse it with the IMPORT column map. The
// header-aware parser must ignore TOUR CODE and land every value on the right key.
$export_item_labels = array_keys($item_cols);                 // incl. TOUR CODE
$export_item_values = array();
foreach (array_values($item_cols) as $ck) {
    $export_item_values[] = isset($irec[$ck]) ? $irec[$ck] : '';
}
$raw_sheet = array($export_item_labels, $export_item_values);
$parsed = costing_import_sheet_to_records($raw_sheet, costing_import_item_columns());
check('round-trip: one record parsed', 1, count($parsed));
check('round-trip: TOUR CODE column ignored (no key)', false, array_key_exists('tour_code', $parsed[0]));
check('round-trip: package name landed', 'Bali 5D4N', $parsed[0]['package_name']);
check('round-trip: combination landed (not shifted by tour code)', '4 Star Hotel', $parsed[0]['combination']);
check('round-trip: category landed', 'ground_package', $parsed[0]['category']);
check('round-trip: unit price landed', '1200.5', $parsed[0]['unit_price']);
check('round-trip: currency landed', 'USD', $parsed[0]['currency']);

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
