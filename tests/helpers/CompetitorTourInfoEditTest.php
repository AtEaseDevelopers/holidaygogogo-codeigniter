<?php
/**
 * Run with: php tests/helpers/CompetitorTourInfoEditTest.php
 *
 * Locks competitor_tour_info_from_post() — the pure parser behind the
 * Competitor Product "Tour Information" inline edit (Competitor_Product/Save_Tour_Info).
 *
 * What it guards:
 *  - the right keys land in 'columns' (top-level table cols) vs 'details' (details_json);
 *  - 'inclusions' is emitted as a JSON-encoded column string (it's a JSON column);
 *  - list textareas split on newlines with blanks trimmed/reindexed;
 *  - scalars are trimmed;
 *  - meals -> {breakfast,lunch,dinner} map;
 *  - scenic rows skip both-blank but keep name-only;
 *  - shopping rows re-join to "point — justification" (round-trips the reader);
 *  - itinerary rows skip fully-empty and keep day/title/description.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

require_once __DIR__ . '/../../application/helpers/competitor_analysis_helper.php';

$failures = 0;
function check($label, $expected, $actual)
{
    global $failures;
    if ($expected === $actual) {
        echo "PASS: $label\n";
    } else {
        $failures++;
        echo "FAIL: $label\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
    }
}

// ---- A full, representative POST ------------------------------------------
$post = array(
    'id'             => '7',
    // scalars -> columns
    'tour_code'      => '  GE-01 ',
    'destination'    => 'Egypt',
    'duration'       => '8D7N',
    'currency'       => 'MYR',
    'price'          => 'From RM 5,999',
    // scalars -> details
    'departure_city' => 'Kuala Lumpur',
    'price_from'     => '5999',
    'price_to'       => '7999',
    'difficulty'     => 'Easy',
    'suitable_age'   => 'All ages',
    'flight_departure' => 'MH 1234',
    'flight_return'    => 'MH 4321',
    // list -> column (inclusions is a JSON column)
    'inclusions'     => "Flights\n  Hotels  \n\nMeals\n",
    // lists -> details
    'countries'      => "Egypt\nJordan",
    'cities'         => "Cairo\n\nLuxor\n ",
    'themes'         => "History\nCulture",
    'exclusions'     => "Tips",
    // structured: meals
    'meal_breakfast' => '7',
    'meal_lunch'     => ' 5 ',
    'meal_dinner'    => '6',
    // structured: scenic highlights (parallel arrays)
    'scenic_name'    => array('Pyramids of Giza', 'Nile Cruise', '', '  '),
    'scenic_desc'    => array('See the Great Pyramid', '', 'Orphan desc', ''),
    // structured: shopping stops
    'shopping_point' => array('Papyrus Institute', 'Perfume Shop', ''),
    'shopping_just'  => array('Authentic papyrus art', '', ''),
    // structured: itinerary
    'itin_day'       => array('Day 1', 'Day 2', ''),
    'itin_title'     => array('Arrival', 'Cairo tour', ''),
    'itin_desc'      => array('Land in Cairo', 'Visit museum', ''),
);

$out = competitor_tour_info_from_post($post);
$cols = $out['columns'];
$det  = $out['details'];

// ---- Columns vs details routing + scalar trim ------------------------------
check('tour_code trimmed into columns', 'GE-01', $cols['tour_code']);
check('destination in columns', 'Egypt', $cols['destination']);
check('currency in columns', 'MYR', $cols['currency']);
check('price (headline) in columns', 'From RM 5,999', $cols['price']);
check('departure_city routed to details', 'Kuala Lumpur', $det['departure_city']);
check('price_from in details', '5999', $det['price_from']);
check('flight_return in details', 'MH 4321', $det['flight_return']);
check('scalar key absent from the wrong bucket (no departure_city col)', false, array_key_exists('departure_city', $cols));
check('scalar key absent from the wrong bucket (no tour_code detail)', false, array_key_exists('tour_code', $det));

// ---- inclusions: JSON-encoded column string --------------------------------
check('inclusions is a JSON column string', array('Flights', 'Hotels', 'Meals'), json_decode($cols['inclusions'], true));

// ---- list splitting (newlines, blank trim, reindex) ------------------------
check('countries list', array('Egypt', 'Jordan'), $det['countries']);
check('cities list drops blanks + reindexes', array('Cairo', 'Luxor'), $det['cities']);
check('exclusions single item', array('Tips'), $det['exclusions']);

// ---- meals map -------------------------------------------------------------
check('meals map trimmed', array('breakfast' => '7', 'lunch' => '5', 'dinner' => '6'), $det['meals']);

// ---- scenic highlights -----------------------------------------------------
check('scenic highlights: skip both-blank, keep name-only', array(
    array('name' => 'Pyramids of Giza', 'description' => 'See the Great Pyramid'),
    array('name' => 'Nile Cruise', 'description' => ''),
    array('name' => '', 'description' => 'Orphan desc'),
), $det['scenic_highlights']);

// ---- shopping stops: re-join + round-trip ----------------------------------
check('shopping stops joined to "point — justification"', array(
    'Papyrus Institute — Authentic papyrus art',
    'Perfume Shop',
), $det['shopping_stops']);
// Round-trip: the stored string must parse back to the same point/justification.
$pj = competitor_split_point_justification($det['shopping_stops'][0]);
check('shopping round-trip point', 'Papyrus Institute', $pj['point']);
check('shopping round-trip justification', 'Authentic papyrus art', $pj['justification']);

// ---- itinerary -------------------------------------------------------------
check('itinerary rows skip fully-empty', array(
    array('day' => 'Day 1', 'title' => 'Arrival', 'description' => 'Land in Cairo'),
    array('day' => 'Day 2', 'title' => 'Cairo tour', 'description' => 'Visit museum'),
), $det['itinerary']);

// ---- Empty / minimal POST: structured fields normalise to empty arrays -----
$empty = competitor_tour_info_from_post(array('id' => '9'));
check('empty POST -> empty countries list', array(), $empty['details']['countries']);
check('empty POST -> empty scenic list', array(), $empty['details']['scenic_highlights']);
check('empty POST -> empty itinerary list', array(), $empty['details']['itinerary']);
check('empty POST -> blank meals slots', array('breakfast' => '', 'lunch' => '', 'dinner' => ''), $empty['details']['meals']);
check('empty POST -> inclusions is empty JSON array', array(), json_decode($empty['columns']['inclusions'], true));

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
