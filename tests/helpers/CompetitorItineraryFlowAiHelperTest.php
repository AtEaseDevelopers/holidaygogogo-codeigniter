<?php
/**
 * Run with: php tests/helpers/CompetitorItineraryFlowAiHelperTest.php
 *
 * Locks the pure helpers behind the AI-re-extracted "Daily Itinerary Flow" sheet
 * and the fixed Products sheet, added after the 28 Oct 2026 "Competitor Tour
 * Template Review – Indulge Guangdong" (Simon Lee) review:
 *   - competitor_arrival_airport()          fill blank arrival from Destination
 *   - competitor_build_itinerary_flow_agent() days -> {instructions,input} spec
 *   - competitor_itinerary_flow_rows()      AI {stops:[...]} -> export rows
 *
 * The sample flow is the review's own CORRECT reference list for CNIGD06, so the
 * normaliser is checked against the intended output, not the old heuristic junk.
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

// ---- competitor_arrival_airport ---------------------------------------------
check('arrival: parsed value wins', 'Guangzhou',
    competitor_arrival_airport('Guangzhou', 'Guangdong, China'));
check('arrival: blank falls back to destination first segment', 'Guangdong',
    competitor_arrival_airport('', 'Guangdong, China'));
check('arrival: blank + plain destination', 'Guangzhou',
    competitor_arrival_airport('', 'Guangzhou'));
check('arrival: splits on en/em dash too', 'Guangzhou',
    competitor_arrival_airport('', 'Guangzhou – Panyu'));
check('arrival: both blank -> empty', '',
    competitor_arrival_airport('', ''));
check('arrival: whitespace-only arrival falls back', 'Osaka',
    competitor_arrival_airport('   ', 'Osaka'));

// ---- competitor_build_itinerary_flow_agent ----------------------------------
$itin = array(
    array('day' => '1', 'title' => 'Zhaoqing', 'description' => 'Enjoy the Music Fountain Show (closed Monday).'),
    array('day' => '2', 'title' => 'Seven Star Cave', 'description' => 'Boat ride at Seven Star Cave, then Lingnan Tiandi.'),
);
$spec = competitor_build_itinerary_flow_agent($itin, array('product_name' => 'Indulge Guangdong', 'destination' => 'Guangdong'), 'en');
check_true('agent: returns instructions + input', isset($spec['instructions'], $spec['input']));
check_true('agent: carries the "one row per place" rule', strpos($spec['instructions'], 'One row per place') !== false);
check_true('agent: excludes airports/hotels/meals', strpos($spec['instructions'], 'airports, hotels, meals') !== false);
check_true('agent: keeps district rule (Guangzhou - Panyu)', strpos($spec['instructions'], 'Guangzhou - Panyu') !== false);
check_true('agent: asks for a stops JSON object', strpos($spec['instructions'], '"stops"') !== false);
check_true('agent: input says json (enables json_object mode)', stripos($spec['input'], 'json') !== false);
check_true('agent: input embeds the day descriptions', strpos($spec['input'], 'Music Fountain Show') !== false);
check_true('agent: input carries product/destination context', strpos($spec['input'], 'Indulge Guangdong') !== false);
check_true('agent: EN adds no translate directive', strpos($spec['instructions'], 'translate place') === false);

$spec_cn = competitor_build_itinerary_flow_agent($itin, array(), 'cn');
check_true('agent: CN adds a translate directive', strpos($spec_cn['instructions'], 'Simplified Chinese') !== false);

// ---- competitor_itinerary_flow_rows -----------------------------------------
// The review's correct reference list for CNIGD06 (as the model would return it).
$ai = array('stops' => array(
    array('day' => 1, 'stop' => 'Music Fountain Show', 'city' => 'Zhaoqing', 'notes' => 'Closed Monday - may be replaced by Qixingyan Archway Square'),
    array('day' => 2, 'stop' => 'Seven Star Cave', 'city' => 'Zhaoqing', 'notes' => 'Boat ride'),
    array('day' => 2, 'stop' => 'Lingnan Tiandi', 'city' => 'Foshan (Chancheng)', 'notes' => 'Includes Zumiao, Donghuali Old Town'),
    array('day' => 3, 'stop' => 'Fengjian Village', 'city' => 'Shunde', 'notes' => ''),
    array('day' => 3, 'stop' => 'Shunfeng Mountain Park', 'city' => 'Shunde', 'notes' => ''),
    array('day' => 3, 'stop' => 'Jinbang Upper Street', 'city' => 'Shunde', 'notes' => ''),
    array('day' => 3, 'stop' => 'Qinghui Garden', 'city' => 'Shunde', 'notes' => ''),
));
$rows = competitor_itinerary_flow_rows($ai);
check('rows: total count', 7, count($rows));
check('rows: first row shape', array('day' => '1', 'stop_order' => 1,
    'stop_name' => 'Music Fountain Show', 'city' => 'Zhaoqing',
    'notes' => 'Closed Monday - may be replaced by Qixingyan Archway Square'), $rows[0]);
// stop_order restarts per day.
check('rows: day 2 first = order 1', 1, $rows[1]['stop_order']);
check('rows: day 2 second = order 2', 2, $rows[2]['stop_order']);
check('rows: day 3 first = order 1', 1, $rows[3]['stop_order']);
check('rows: day 3 fourth = order 4', 4, $rows[6]['stop_order']);
check('rows: city/district preserved', 'Foshan (Chancheng)', $rows[2]['city']);

// Drops empty-named rows, accepts a cached row re-read (stop_name key), idempotent.
$mixed = array('stops' => array(
    array('day' => 1, 'stop' => '', 'city' => 'X', 'notes' => ''),          // dropped
    array('day' => 1, 'stop_name' => 'Re-read Stop', 'city' => 'Y', 'notes' => 'n'),
));
$rows2 = competitor_itinerary_flow_rows($mixed);
check('rows: empty name dropped', 1, count($rows2));
check('rows: accepts stop_name key (idempotent re-read)', 'Re-read Stop', $rows2[0]['stop_name']);
check('rows: order recomputed after a drop', 1, $rows2[0]['stop_order']);

// Defensive: junk input -> empty, never a warning.
check('rows: missing stops key -> empty', array(), competitor_itinerary_flow_rows(array()));
check('rows: non-array -> empty', array(), competitor_itinerary_flow_rows('nope'));

// ---- competitor_flow_badge --------------------------------------------------
check('badge: null json -> none', 'none', competitor_flow_badge(null, 1));
check('badge: empty json -> none', 'none', competitor_flow_badge('', 2));
check('badge: no en entry -> none', 'none', competitor_flow_badge('{"cn":{"0":{"stops":[]}}}', 1, 'en'));
check('badge: single product cached -> ready', 'ready', competitor_flow_badge('{"en":{"0":{"stops":[]}}}', 1));
check('badge: all products cached -> ready', 'ready', competitor_flow_badge('{"en":{"0":{"stops":[]},"1":{"stops":[]}}}', 2));
check('badge: some products cached -> partial', 'partial', competitor_flow_badge('{"en":{"0":{"stops":[]}}}', 3));
check('badge: cn lang entry -> ready', 'ready', competitor_flow_badge('{"cn":{"0":{"stops":[]}}}', 1, 'cn'));
check('badge: product_count 0 treated as 1 -> ready', 'ready', competitor_flow_badge('{"en":{"0":{"stops":[]}}}', 0));

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
