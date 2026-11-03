<?php
/**
 * Run with: php tests/helpers/CostingImportRevisionTest.php
 *
 * Locks the pure layer behind the Costing Package Excel import's "import as a new
 * REVISION of an existing package" feature
 * (application/helpers/costing_packages_import_helper.php):
 *
 *   - costing_import_package_columns_with_tour_code(): the PARSE-time Packages map
 *     that recognises an export's TOUR CODE column (inserted right after Package
 *     Name) while the plain template map still omits it;
 *   - header-aware parsing + assembly carry tour_code off an export's Packages
 *     sheet, and leave it blank for a plain-template upload (no TOUR CODE column);
 *   - costing_import_revision_decisions(): per-package new-vs-revision decision —
 *       * a TOUR CODE (base, REV suffix stripped) matching a known family defaults
 *         to 'revision' with the family's next code/root;
 *       * no match (or blank code) => 'new';
 *       * a matched row the user flips to 'new' => 'new' (fork a fresh package);
 *       * a 'revision' choice with no matching family => 'new' (can't revise what
 *         does not exist);
 *       * a -REVnn code resolves to its base and still matches the family.
 *   - costing_import_revision_name(): an import-as-revision SAVES the imported
 *     PACKAGE NAME (user may have renamed it), falling back to the family root's
 *     name only when the imported cell is blank/whitespace. Guards the bug where a
 *     renamed revision silently inherited the old (corrupted) family name.
 *
 * Runs without a DB or PhpSpreadsheet.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}
if (!defined('FCPATH')) {
    define('FCPATH', __DIR__ . '/../../');
}
require_once __DIR__ . '/../../application/helpers/costing_tour_code_helper.php';
require_once __DIR__ . '/../../application/helpers/costing_itinerary_helper.php';
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

/* --------------------------------------- Tour-Code-aware Packages column map */

$plain = costing_import_package_columns();
$aug   = costing_import_package_columns_with_tour_code();

check_true('plain Packages map has NO tour_code', !in_array('tour_code', array_values($plain), true));
check_true('augmented Packages map HAS tour_code', in_array('tour_code', array_values($aug), true));

// TOUR CODE must sit immediately after PACKAGE NAME so an export round-trips cleanly.
$aug_labels = array_keys($aug);
check('augmented: PACKAGE NAME first', 'PACKAGE NAME', $aug_labels[0]);
check('augmented: TOUR CODE second', 'TOUR CODE', $aug_labels[1]);
check('augmented adds exactly one column', count($plain) + 1, count($aug));

/* ------------------------------- Parse: export sheet carries tour_code ... */

// An EXPORT Packages sheet: header with TOUR CODE, one data row. Parsed with the
// augmented map, tour_code lands on its key and nothing else shifts.
$export_header = $aug_labels;
$export_row = array(
    'Japan Spring', 'QU-2612-0001', 'Tan Family', '+60 12', 't@e.com', 'Alice Tan', '+60 19 888',
    '6', '5', 'active', '2027-03-15', 'desc', '2027-04-01', '2027-04-06', '4', '0', '15', '0', '0',
    'FIT', 'Twin|Triple', 'Hotel', 'Y', '', '', '', '', '',
);
$recs = costing_import_sheet_to_records(array($export_header, $export_row), $aug);
check('export parse: one record', 1, count($recs));
check('export parse: tour_code captured', 'QU-2612-0001', $recs[0]['tour_code']);
check('export parse: name not shifted', 'Japan Spring', $recs[0]['name']);
check('export parse: customer not shifted', 'Tan Family', $recs[0]['customer_name']);
check('export parse: sales_person not shifted', 'Alice Tan', $recs[0]['sales_person']);

/* ------------------------------- ... and a plain template leaves it blank */

// A plain TEMPLATE Packages sheet has NO TOUR CODE column. Header-aware parsing with
// the augmented map simply yields a blank tour_code (and everything else still maps).
$tmpl_header = array_keys($plain);
$tmpl_row = array(
    'Bali Escape', 'Lim Family', '+60 34', 'l@e.com', 'Bob Lee', '+60 18 777',
    '4', '3', 'active', '2027-06-01', 'desc', '2027-07-01', '2027-07-04', '2', '0', '12', '0', '0',
    'none', 'Twin', 'Hotel', 'Y', '', '', '', '', '',
);
$recs2 = costing_import_sheet_to_records(array($tmpl_header, $tmpl_row), $aug);
check('template parse: one record', 1, count($recs2));
check('template parse: tour_code blank', '', $recs2[0]['tour_code']);
check('template parse: name still maps', 'Bali Escape', $recs2[0]['name']);

/* ------------------------------- Assemble carries tour_code onto the package */

$lookups = array(
    'currency_by_code' => array('MYR' => 1),
    'category_codes'   => array('accommodation' => 'accommodation'),
    'sales_by_name'    => array('alice tan' => 10),
);
$parsed = array(
    'packages' => array(array(
        'name' => 'Japan Spring', 'tour_code' => 'QU-2612-0001', 'customer_name' => '', 'customer_contact' => '',
        'customer_email' => '', 'sales_person' => '', 'sales_person_mobile' => '', 'duration_days' => '6',
        'duration_nights' => '5', 'status' => 'active', 'validity_date' => '', 'description' => '',
        'travel_date' => '2027-04-01', 'travel_date_end' => '', 'adult_count' => '4', 'child_count' => '0',
        'margin_percentage' => '15', 'commissionable_per_pax' => '0', 'ad_hoc_per_pax' => '0',
        'quote_flight_mode' => 'FIT', 'quote_hotel_columns' => '', 'quote_hotel_title_label' => '',
        'quote_show_single_supp' => 'Y', 'itinerary_include' => '', 'itinerary_exclude' => '',
        'itinerary_important_notes' => '', 'itinerary_terms' => '', 'itinerary_tour_intro' => '',
    )),
    'cost_items' => array(), 'combinations' => array(), 'hotels' => array(), 'flights' => array(), 'itinerary' => array(),
);
$assembled = costing_import_assemble_packages($parsed, $lookups);
check('assemble: one package', 1, count($assembled['packages']));
check('assemble: tour_code carried', 'QU-2612-0001', $assembled['packages'][0]['tour_code']);

/* ----------------------------------------------- Decision: new vs revision */

// Known families keyed by LOWER base code (what the controller builds from the DB).
$families = array(
    'qu-2612-0001' => array('root_id' => 5, 'root_name' => 'Japan Spring', 'next_code' => 'QU-2612-0001-REV01'),
);

// 1) base code matches a family -> default revision.
$d = costing_import_revision_decisions(array(array('tour_code' => 'QU-2612-0001')), $families);
check('match: mode revision', 'revision', $d[0]['mode']);
check('match: has_match true', true, $d[0]['has_match']);
check('match: root id', 5, $d[0]['root_id']);
check('match: target code', 'QU-2612-0001-REV01', $d[0]['target_code']);

// 2) a -REVnn code resolves to the same base and still matches.
$d = costing_import_revision_decisions(array(array('tour_code' => 'QU-2612-0001-REV03')), $families);
check('rev suffix: still revision', 'revision', $d[0]['mode']);
check('rev suffix: base recovered', 'QU-2612-0001', $d[0]['base_code']);

// 3) unknown code -> new.
$d = costing_import_revision_decisions(array(array('tour_code' => 'QU-9999-9999')), $families);
check('no match: mode new', 'new', $d[0]['mode']);
check('no match: has_match false', false, $d[0]['has_match']);
check('no match: no target', '', $d[0]['target_code']);

// 4) blank code -> new.
$d = costing_import_revision_decisions(array(array('tour_code' => '')), $families);
check('blank code: mode new', 'new', $d[0]['mode']);

// 5) matched but user flips to 'new' -> new (fork a fresh package).
$d = costing_import_revision_decisions(array(array('tour_code' => 'QU-2612-0001')), $families, array(0 => 'new'));
check('override new: mode new', 'new', $d[0]['mode']);
check('override new: still flags the match', true, $d[0]['has_match']);
check('override new: target cleared', '', $d[0]['target_code']);

// 6) 'revision' requested but no family -> stays new (can't revise what does not exist).
$d = costing_import_revision_decisions(array(array('tour_code' => 'QU-9999-9999')), $families, array(0 => 'revision'));
check('revision w/o family: mode new', 'new', $d[0]['mode']);

// 7) explicit 'revision' on a match stays revision, index alignment across many.
$pkgs = array(
    array('tour_code' => 'QU-2612-0001'),  // match
    array('tour_code' => ''),              // new
    array('tour_code' => 'QU-2612-0001'),  // match, flipped to new
);
$d = costing_import_revision_decisions($pkgs, $families, array(0 => 'revision', 2 => 'new'));
check('multi[0]: revision', 'revision', $d[0]['mode']);
check('multi[1]: new', 'new', $d[1]['mode']);
check('multi[2]: flipped new', 'new', $d[2]['mode']);

/* --------------------------------- Revision name: imported PACKAGE NAME wins */
// Regression guard: an import-as-revision must SAVE the name from the uploaded
// Packages sheet (the user may have renamed it), NOT silently inherit the old
// family name. This was the bug behind pkg 82 keeping pkg 80's corrupted name.

check(
    'revision name: imported name wins over root',
    'Guangzhou Warisan Xiguan & Pelayaran Malam 4H3M Makanan Halal - Mr Lee',
    costing_import_revision_name(
        'Guangzhou Warisan Xiguan & Pelayaran Malam 4H3M Makanan Halal - Mr Lee',
        '??????·???? ????4?3? - Mr Lee'            // old corrupted family name
    )
);
check(
    'revision name: blank import falls back to root',
    'Japan Spring',
    costing_import_revision_name('', 'Japan Spring')
);
check(
    'revision name: whitespace-only import falls back to root',
    'Japan Spring',
    costing_import_revision_name('   ', 'Japan Spring')
);
check(
    'revision name: imported name is trimmed',
    'Bali Escape',
    costing_import_revision_name('  Bali Escape  ', 'Old Name')
);
check(
    'revision name: multibyte imported name preserved',
    '广州西关·游船4天3晚 - Mr Lee',
    costing_import_revision_name('广州西关·游船4天3晚 - Mr Lee', 'Old Name')
);

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
