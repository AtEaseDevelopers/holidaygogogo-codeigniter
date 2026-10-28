<?php
/**
 * Run with: php tests/helpers/CompetitorFlightItineraryHelperTest.php
 *
 * Locks the best-effort pure helpers behind the "Tour Info" Excel export's new
 * Products flight columns and the "Daily Itinerary Flow" sheet:
 *   - competitor_flight_parts()          free-text flight -> {departure,arrival,airline}
 *   - competitor_itinerary_day_city()    "in/to <Place>" city cue
 *   - competitor_itinerary_place_phrases() prose -> ordered stop names
 *   - competitor_itinerary_stops()       days -> stop-flow rows
 *
 * Sample inputs are real stored shapes (competitor_analyses.details_json) incl.
 * the "?" separator the scraper leaves where a non-Latin arrow/char was lost.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}
require_once __DIR__ . '/../../application/helpers/competitor_analysis_helper.php';

$failures = 0;
function check($label, $expected, $actual) {
    global $failures;
    if ($expected === $actual) {
        echo "PASS  {$label}\n";
    } else {
        $failures++;
        echo "FAIL  {$label}\n";
        echo "      expected: " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n";
        echo "      actual:   " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
    }
}
function check_true($label, $cond) { check($label, true, (bool) $cond); }

// ---- competitor_flight_parts ------------------------------------------------
// "City ? City FLIGHTNO TIME-TIME" — the common shape (id 24 in dev).
$f = competitor_flight_parts('Kuala Lumpur ? Zhanjiang MH8002 11:00-14:55', 'Kuala Lumpur');
check('flight: departure from city field', 'Kuala Lumpur', $f['departure_airport']);
check('flight: arrival parsed after separator', 'Zhanjiang', $f['arrival_airport']);
check('flight: airline/flight code', 'MH8002', $f['airline']);

// Airport codes with "/" route + parenthetical note + no city field (id 13 shape).
$f2 = competitor_flight_parts('MU858 KUL/LHW 0405-1130 (Stopover in Kunming)', '');
check('flight: airline code when leading', 'MU858', $f2['airline']);
check('flight: departure from route when no city', 'KUL', $f2['departure_airport']);
check('flight: arrival last route token', 'LHW', $f2['arrival_airport']);

// Multiple legs -> both flight numbers; "/3" continuation kept.
$f3 = competitor_flight_parts('Penang ? Taipei CI722 09:00-13:40', 'Penang');
check('flight: single leg airline', 'CI722', $f3['airline']);

// Garbled non-Latin source collapses to "?" — airline still recovered, places blank.
$f4 = competitor_flight_parts('?1? ???? HX690 ? 692 ??08:30?09:10????', '');
check('flight: airline from garbled text', 'HX690', $f4['airline']);
check('flight: no place from garble', '', $f4['arrival_airport']);

// Empty input -> fallback only.
$f5 = competitor_flight_parts('', 'Singapore');
check('flight: empty uses city fallback', 'Singapore', $f5['departure_airport']);
check('flight: empty has no airline', '', $f5['airline']);

// ---- competitor_itinerary_day_city ------------------------------------------
check('day city from title cue', 'Zhanjiang', competitor_itinerary_day_city('Arrival in Zhanjiang'));
check('day city from transfer cue', 'Foshan', competitor_itinerary_day_city('Transfer to Foshan today'));
check('day city none', '', competitor_itinerary_day_city('Free day at leisure'));

// ---- competitor_itinerary_place_phrases -------------------------------------
$desc = 'Arrive in Zhanjiang and visit Chikan Old Street, a French colonial-style '
      . 'street block. Visit the Guangzhou Bay Folk Museum and the old wharf. '
      . 'Dinner is free. Stay at a 4-star hotel.';
$stops = competitor_itinerary_place_phrases($desc, 'Zhanjiang');
check_true('phrases: keeps named street', in_array('Chikan Old Street', $stops, true));
check_true('phrases: keeps named museum', in_array('Guangzhou Bay Folk Museum', $stops, true));
check_true('phrases: drops the day city itself', !in_array('Zhanjiang', $stops, true));
check_true('phrases: drops nationality adjective', !in_array('French', $stops, true));
check_true('phrases: drops sentence-opener verbs', !in_array('Arrive', $stops, true) && !in_array('Dinner', $stops, true) && !in_array('Stay', $stops, true));
// Order preserved: street appears before museum.
$ci = array_search('Chikan Old Street', $stops, true);
$mi = array_search('Guangzhou Bay Folk Museum', $stops, true);
check_true('phrases: order preserved', $ci !== false && $mi !== false && $ci < $mi);
// De-dup: a place repeated across sentences appears once.
$dup = competitor_itinerary_place_phrases('Visit West Lake. Later return to West Lake for the show.', '');
check('phrases: de-duplicates', 1, count(array_filter($dup, function ($x) { return $x === 'West Lake'; })));

// ---- competitor_itinerary_stops ---------------------------------------------
$itin = array(
    array('day' => 'Day 1', 'title' => 'Arrival in Zhaoqing',
          'description' => 'Visit Seven Star Cave and enjoy the Music Fountain Show in Zhaoqing.'),
    array('day' => 'Day 2', 'title' => 'Free & easy', 'description' => ''),
);
$rows = competitor_itinerary_stops($itin);
check_true('stops: produces rows', count($rows) >= 2);
check('stops: first row day', 'Day 1', $rows[0]['day']);
check('stops: stop order starts at 1', 1, $rows[0]['stop_order']);
check('stops: city detected', 'Zhaoqing', $rows[0]['city']);
check_true('stops: stop name is a place', in_array($rows[0]['stop_name'], array('Seven Star Cave', 'Music Fountain Show'), true));
check('stops: notes blank best-effort', '', $rows[0]['notes']);
// A day with only a title (no parseable stop) still appears via the title fallback.
$last = $rows[count($rows) - 1];
check('stops: title-only day not dropped', 'Day 2', $last['day']);
check('stops: fallback uses title', 'Free & easy', $last['stop_name']);
// Order increments within a day.
$day1 = array_values(array_filter($rows, function ($r) { return $r['day'] === 'Day 1'; }));
if (count($day1) > 1) {
    check('stops: order increments within day', 2, $day1[1]['stop_order']);
}

// Non-array input is safe.
check('stops: non-array -> empty', array(), competitor_itinerary_stops('nope'));

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
