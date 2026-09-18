<?php
/**
 * Run with: php tests/helpers/CustomerAnalysisHelperTest.php
 *
 * Locks the pure AI Customer Analysis helpers (no DB, no network). The service
 * merges a customer's GHL chat + uploaded WhatsApp exports, sends the transcript
 * to OpenAI, and stores the reply. These cover the pure transforms only:
 *   - customer_analysis_merge_timeline()   interleaves the two chat sources
 *   - customer_analysis_render_transcript() flattens + recency-truncates
 *   - customer_analysis_build_request()    shapes instructions + input
 *   - customer_analysis_parse_ai_response() normalises the model's JSON reply
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}
require_once __DIR__ . '/../../application/helpers/customer_analysis_helper.php';

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
function check_true($label, $cond) { check($label, true, (bool) $cond); }

// ---- customer_analysis_merge_timeline ---------------------------------------
$ghl = array(
    array('side' => 'in',  'author' => 'Ali', 'body' => 'Hi, any Japan tour?', 'time' => '1 Jul 2026, 9:00 AM', 'type' => 'SMS'),
    array('side' => 'out', 'author' => 'Agent', 'body' => 'Yes! 6D5N from RM4999', 'time' => '1 Jul 2026, 9:05 AM', 'type' => 'SMS'),
    array('side' => 'in',  'author' => 'Ali', 'body' => '', 'time' => '1 Jul 2026, 9:06 AM', 'type' => 'image'), // blank -> dropped
);
$uploads = array(
    array('ts' => '7/3/26, 10:00 AM', 'sender' => 'Ali', 'body' => 'Can send itinerary?', 'system' => false, 'outbound' => false),
    array('ts' => '7/3/26, 10:01 AM', 'sender' => '', 'body' => 'Messages are end-to-end encrypted', 'system' => true, 'outbound' => false), // system -> dropped
    array('ts' => '6/30/26, 8:00 AM', 'sender' => 'Holidaygogogo Tours May', 'body' => 'Welcome!', 'system' => false, 'outbound' => true),
);

$timeline = customer_analysis_merge_timeline($ghl, $uploads);
check('merge drops blank + system lines', 4, count($timeline));
check('merge sorts oldest first (upload before ghl)', 'Welcome!', $timeline[0]['body']);
check('merge maps ghl outbound to agent', 'agent', $timeline[2]['speaker']);
check('merge maps ghl inbound to customer', 'customer', $timeline[1]['speaker']);
check('merge maps upload outbound to agent', 'agent', $timeline[0]['speaker']);
check('merge tags origin ghl', 'ghl', $timeline[1]['origin']);
check('merge tags origin upload', 'upload', $timeline[0]['origin']);
check('merge interleaves upload after ghl chronologically', 'Can send itinerary?', $timeline[3]['body']);

// Empty inputs are safe.
check('merge empty sources', array(), customer_analysis_merge_timeline(array(), array()));
check('merge tolerates non-arrays', array(), customer_analysis_merge_timeline('x', null));

// ---- customer_analysis_render_transcript ------------------------------------
$txt = customer_analysis_render_transcript($timeline);
check_true('transcript labels agent turns', strpos($txt, 'Agent: Welcome!') !== false);
check_true('transcript labels customer turns', strpos($txt, 'Customer: Hi, any Japan tour?') !== false);
check_true('transcript carries the timestamp', strpos($txt, '[1 Jul 2026, 9:00 AM]') !== false);

// Recency truncation keeps the newest content, drops the oldest.
$big = array();
for ($i = 0; $i < 50; $i++) {
    $big[] = array('when' => '', 'speaker' => 'customer', 'body' => 'OLD-' . $i, 'sort' => $i, 'seq' => $i, 'origin' => 'ghl');
}
$big[] = array('when' => '', 'speaker' => 'agent', 'body' => 'NEWEST-LINE', 'sort' => 999, 'seq' => 999, 'origin' => 'ghl');
$trunc = customer_analysis_render_transcript($big, 120);
check_true('truncate keeps newest line', strpos($trunc, 'NEWEST-LINE') !== false);
check_true('truncate drops oldest line', strpos($trunc, 'OLD-0:') === false);
check_true('truncate marks the cut', strpos($trunc, 'truncated') !== false);
check_true('truncate respects char budget (approx)', strlen($trunc) <= 120 + 40);

// ---- customer_analysis_build_request ----------------------------------------
$req = customer_analysis_build_request('Ali Bin Abu', "Customer: hi\nAgent: hello");
check_true('request carries customer name', strpos($req['input'], 'Ali Bin Abu') !== false);
check_true('request carries transcript', strpos($req['input'], 'Customer: hi') !== false);
check_true('request instructions ask for strict JSON', stripos($req['instructions'], 'STRICT JSON') !== false);
check_true('request instructions do NOT ask for sales_intel (disabled)', strpos($req['instructions'], 'sales_intel') === false);
check_true('request instructions do NOT ask for next_actions (dropped)', strpos($req['instructions'], 'next_actions') === false);
check_true('request instructions do NOT ask for key_facts (dropped)', strpos($req['instructions'], 'key_facts') === false);
check_true('request instructions ask for customer characteristics/mood profile', stripos($req['instructions'], 'characteristic') !== false && stripos($req['instructions'], 'mood') !== false);
// The structured character-profile fields are all present in the shape.
foreach (array_keys(customer_analysis_profile_text_fields()) as $__f) {
    check_true("request instructions ask for profile field '$__f'", strpos($req['instructions'], '"' . $__f . '"') !== false);
}
foreach (array_keys(customer_analysis_profile_list_fields()) as $__f) {
    check_true("request instructions ask for list field '$__f'", strpos($req['instructions'], '"' . $__f . '"') !== false);
}
check_true('request instructions ask for hot/cold temperature', strpos($req['instructions'], 'temperature') !== false && stripos($req['instructions'], 'hot') !== false && stripos($req['instructions'], 'cold') !== false);

// The incremental-update request refreshes the character profile (no next steps),
// and carries the prior profile fields so the model can keep what still holds.
$reqUpd = customer_analysis_build_update_request('Ali', array('summary' => 'old', 'profile' => array('character' => 'cautious buyer')), "Customer: still keen");
check_true('update request carries prior summary', strpos($reqUpd['input'], 'old') !== false);
check_true('update request carries prior profile field', strpos($reqUpd['input'], 'cautious buyer') !== false);
check_true('update request carries new transcript', strpos($reqUpd['input'], 'still keen') !== false);
check_true('update request does NOT ask for next_actions (dropped)', strpos($reqUpd['instructions'], 'next_actions') === false);
check_true('update request does NOT ask for key_facts (dropped)', strpos($reqUpd['instructions'], 'key_facts') === false);

// ---- customer_analysis_normalize_temperature --------------------------------
check('temperature normalises HOT -> hot', 'hot', customer_analysis_normalize_temperature('HOT'));
check('temperature normalises  Cold  -> cold', 'cold', customer_analysis_normalize_temperature('  Cold '));
check('temperature rejects unknown -> empty', '', customer_analysis_normalize_temperature('warm'));
check('temperature rejects empty -> empty', '', customer_analysis_normalize_temperature(''));
$reqNoName = customer_analysis_build_request('', 'x');
check_true('request falls back to (unknown) name', strpos($reqNoName['input'], '(unknown)') !== false);

// ---- customer_analysis_parse_ai_response ------------------------------------
$json = '```json
{
  "summary": "Cautious family planner keen on a Japan trip.",
  "character": "Detail-oriented and price-sensitive",
  "mood": "Warm but hesitant; anxious about kids",
  "behavior": "Asks many questions before deciding",
  "language": "English",
  "reply_pattern": "Replies late at night, sometimes goes quiet",
  "response_expectation": "Expects prompt answers on pricing",
  "journey": "Asked about Redang then switched to Japan",
  "family_needs": "2 adults 2 young kids — needs an extra room",
  "source": "Facebook ad",
  "justification": "Repeatedly asked about per-pax price and kid facilities",
  "preferences": ["Sea view room", "Direct flights"],
  "expectations": ["Clear itinerary", "Fast quote"],
  "complaints": ["Felt earlier reply was slow"]
}
```';
$json = str_replace('"summary":', '"temperature": "Hot", "temperature_reason": "Asked to confirm dates and pax", "summary":', $json);
$rec = customer_analysis_parse_ai_response($json);
check_true('parse recovers object from json fence', is_array($rec));
check('parse temperature (Hot -> hot)', 'hot', $rec['temperature']);
check('parse temperature_reason', 'Asked to confirm dates and pax', $rec['temperature_reason']);
check('parse summary', 'Cautious family planner keen on a Japan trip.', $rec['summary']);
check('parse profile.character', 'Detail-oriented and price-sensitive', $rec['profile']['character']);
check('parse profile.mood', 'Warm but hesitant; anxious about kids', $rec['profile']['mood']);
check('parse profile.journey', 'Asked about Redang then switched to Japan', $rec['profile']['journey']);
check('parse profile.family_needs', '2 adults 2 young kids — needs an extra room', $rec['profile']['family_needs']);
check('parse profile.preferences list', array('Sea view room', 'Direct flights'), $rec['profile']['preferences']);
check('parse profile.complaints list', array('Felt earlier reply was slow'), $rec['profile']['complaints']);

// Missing fields default cleanly; a string list is split into an array.
$partial = customer_analysis_parse_ai_response('{"summary":"Just a lead","preferences":"sea view; halal food"}');
check('parse defaults missing temperature to empty', '', $partial['temperature']);
check('parse defaults missing profile.character to empty', '', $partial['profile']['character']);
check('parse defaults missing profile.complaints to empty list', array(), $partial['profile']['complaints']);
check('parse splits a string list on ;', array('sea view', 'halal food'), $partial['profile']['preferences']);

// Stray prose around the object is tolerated.
$withProse = customer_analysis_parse_ai_response('Here is the analysis: {"summary":"ok"} hope it helps');
check('parse pulls object out of prose', 'ok', $withProse['summary']);

// Garbage returns null so the caller can flag an error.
check('parse returns null on non-json', null, customer_analysis_parse_ai_response('sorry, I cannot help'));
check('parse returns null on empty', null, customer_analysis_parse_ai_response('   '));

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "{$failures} FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
