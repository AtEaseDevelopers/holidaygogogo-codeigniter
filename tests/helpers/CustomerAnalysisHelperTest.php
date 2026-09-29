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
check_true('request instructions ask for the recommendation object', strpos($req['instructions'], '"recommendation"') !== false);
check_true('recommendation shape asks for comparison options', strpos($req['instructions'], '"options"') !== false && strpos($req['instructions'], '"dimensions"') !== false);
check_true('recommendation shape asks for a decision guide', strpos($req['instructions'], '"decision_guide"') !== false);
check_true('recommendation shape asks for a follow-up question', strpos($req['instructions'], '"follow_up"') !== false);
check_true('recommendation shape keeps agent-only tc_notes', strpos($req['instructions'], '"tc_notes"') !== false);
check_true('recommendation must be written in English', stripos($req['instructions'], 'in English') !== false);
check_true('request instructions ask for recommended_tours', strpos($req['instructions'], '"recommended_tours"') !== false);
check_true('recommend guidance says copy names exactly', stripos($req['instructions'], 'EXACTLY') !== false);
// The recommendation must be produced for EVERY customer, not only hot/cold ones.
check_true('recommendation guidance says ALWAYS fill it', stripos($req['instructions'], 'ALWAYS fill the recommendation') !== false);
check_true('recommendation guidance lets the model choose dimensions per product type', stripos($req['instructions'], 'CHOOSE to fit the product type') !== false);
check_true('recommendation guidance hedges date/season claims into tc_notes', stripos($req['instructions'], 'tc_notes') !== false && stripos($req['instructions'], 'season') !== false);
check_true('recommendation guidance covers cold/unclear re-engagement', stripos($req['instructions'], 're-engage') !== false || stripos($req['instructions'], 'reengage') !== false);
check_true('request instructions no longer ask for the flat approach_suggestion field', strpos($req['instructions'], '"approach_suggestion"') === false);

// OUR PRODUCTS block: appended to the input only when we pass products, so the
// model recommends a real tour instead of inventing one.
$reqNoProd = customer_analysis_build_request('Ali', 'Customer: hi');
check_true('no OUR PRODUCTS block when none given', strpos($reqNoProd['input'], 'OUR PRODUCTS') === false);
$reqProd = customer_analysis_build_request('Ali', 'Customer: hi', array(
    array('name' => 'Japan 6D5N Sakura', 'tour_code' => 'JP6D', 'price_myr' => 4999),
));
check_true('OUR PRODUCTS block present when products given', strpos($reqProd['input'], 'OUR PRODUCTS') !== false);
check_true('OUR PRODUCTS block carries the tour name', strpos($reqProd['input'], 'Japan 6D5N Sakura') !== false);
check_true('update request carries OUR PRODUCTS block', strpos(
    customer_analysis_build_update_request('Ali', array('summary' => 'x'), 'Customer: keen', array(array('name' => 'Redang 3D2N')))['input'],
    'Redang 3D2N') !== false);

// The incremental-update request refreshes the character profile (no next steps),
// and carries the prior profile fields so the model can keep what still holds.
$reqUpd = customer_analysis_build_update_request('Ali', array('summary' => 'old', 'approach_suggestion' => 'ping about dates', 'profile' => array('character' => 'cautious buyer')), "Customer: still keen");
check_true('update request carries prior summary', strpos($reqUpd['input'], 'old') !== false);
check_true('update request carries prior approach message', strpos($reqUpd['input'], 'ping about dates') !== false);
check_true('update request asks for the recommendation object', strpos($reqUpd['instructions'], '"recommendation"') !== false);
check_true('update request also insists the recommendation is always filled', stripos($reqUpd['instructions'], 'ALWAYS fill the recommendation') !== false);
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
$json = str_replace('"summary":', '"temperature": "Hot", "temperature_reason": "Asked to confirm dates and pax", "approach_suggestion": "Hi! 帮你比较 Redang 几间酒店 😊", "summary":', $json);
$rec = customer_analysis_parse_ai_response($json);
check_true('parse recovers object from json fence', is_array($rec));
check('parse temperature (Hot -> hot)', 'hot', $rec['temperature']);
check('parse temperature_reason', 'Asked to confirm dates and pax', $rec['temperature_reason']);
check('parse approach_suggestion', 'Hi! 帮你比较 Redang 几间酒店 😊', $rec['approach_suggestion']);
check('parse summary', 'Cautious family planner keen on a Japan trip.', $rec['summary']);
check('parse profile.character', 'Detail-oriented and price-sensitive', $rec['profile']['character']);
check('parse profile.mood', 'Warm but hesitant; anxious about kids', $rec['profile']['mood']);
check('parse profile.journey', 'Asked about Redang then switched to Japan', $rec['profile']['journey']);
check('parse profile.family_needs', '2 adults 2 young kids — needs an extra room', $rec['profile']['family_needs']);
check('parse profile.preferences list', array('Sea view room', 'Direct flights'), $rec['profile']['preferences']);
check('parse profile.complaints list', array('Felt earlier reply was slow'), $rec['profile']['complaints']);

// recommended_tours: the real tours the model matched, each with a justification.
$recJson = customer_analysis_parse_ai_response('{"summary":"ok","recommended_tours":[' .
    '{"name":"Japan 6D5N Sakura","tour_code":"JP6D","price_myr":4999,"justification":"Wants a Japan trip for 2 adults + 2 kids"},' .
    '{"name":"","justification":"dropped — no name"},' .
    '{"name":"Redang 3D2N","price_myr":0,"justification":"Backup beach option"}' .
    ']}');
check('parse recommended_tours drops nameless rows', 2, count($recJson['recommended_tours']));
check('parse recommended_tours name', 'Japan 6D5N Sakura', $recJson['recommended_tours'][0]['name']);
check('parse recommended_tours tour_code', 'JP6D', $recJson['recommended_tours'][0]['tour_code']);
check('parse recommended_tours price_myr', 4999.0, $recJson['recommended_tours'][0]['price_myr']);
check('parse recommended_tours justification', 'Wants a Japan trip for 2 adults + 2 kids', $recJson['recommended_tours'][0]['justification']);
check('parse recommended_tours zero price -> null', null, $recJson['recommended_tours'][1]['price_myr']);
check('parse recommended_tours missing code -> empty', '', $recJson['recommended_tours'][1]['tour_code']);

// ---- customer_analysis_normalize_recommendation ----------------------------
$recRaw = array(
    'intro'   => '  If you are a group of young friends going to Redang…  ',
    'options' => array(
        array(
            'name' => 'Laguna Redang Island Resort', 'tour_code' => 'LAG3D', 'price_myr' => '1288',
            'feel_emoji' => '🏝️', 'overall_feel' => 'Resort feel + comfy stay',
            'dimensions' => array(
                array('label' => 'Beach Vibe', 'emoji' => '🌊', 'points' => array('Long Beach scenery', 'White sand + blue sea')),
                array('label' => '', 'emoji' => '', 'points' => array()), // empty dim -> dropped
            ),
        ),
        array('name' => '', 'overall_feel' => 'dropped — no name'),      // nameless -> dropped
        array('name' => 'Redang Bay Resort', 'price_myr' => 0, 'dimensions' => array()), // zero price -> null
    ),
    'decision_guide' => array(
        array('emoji' => '🏝️', 'persona' => 'Wants comfort', 'pick' => 'Laguna'),
        array('emoji' => '', 'persona' => '', 'pick' => ''),             // empty -> dropped
    ),
    'follow_up' => 'How many of you, which month, and budget per person?',
    'tc_notes'  => array('Live shows vary by date/season — do not promise them', ''),
);
$rn = customer_analysis_normalize_recommendation($recRaw);
check('recommendation trims intro', 'If you are a group of young friends going to Redang…', $rn['intro']);
check('recommendation drops nameless options', 2, count($rn['options']));
check('recommendation keeps option name', 'Laguna Redang Island Resort', $rn['options'][0]['name']);
check('recommendation coerces positive price to float', 1288.0, $rn['options'][0]['price_myr']);
check('recommendation zero price -> null', null, $rn['options'][1]['price_myr']);
check('recommendation drops empty dimensions', 1, count($rn['options'][0]['dimensions']));
check('recommendation keeps dimension points', array('Long Beach scenery', 'White sand + blue sea'), $rn['options'][0]['dimensions'][0]['points']);
check('recommendation drops empty decision-guide rows', 1, count($rn['decision_guide']));
check('recommendation keeps decision pick', 'Laguna', $rn['decision_guide'][0]['pick']);
check('recommendation keeps follow_up', 'How many of you, which month, and budget per person?', $rn['follow_up']);
check('recommendation drops blank tc_notes', array('Live shows vary by date/season — do not promise them'), $rn['tc_notes']);
check('recommendation defaults empty input cleanly', array('intro' => '', 'options' => array(), 'decision_guide' => array(), 'follow_up' => '', 'tc_notes' => array()), customer_analysis_normalize_recommendation('nonsense'));

// ---- customer_analysis_render_recommendation_text ---------------------------
$msg = customer_analysis_render_recommendation_text($recRaw);
check_true('render carries the intro', strpos($msg, 'young friends going to Redang') !== false);
check_true('render numbers each option', strpos($msg, '1. Laguna Redang Island Resort') !== false);
check_true('render carries the price', strpos($msg, 'RM 1,288') !== false);
check_true('render carries a dimension label with emoji', strpos($msg, '🌊 Beach Vibe') !== false);
check_true('render bullets the points', strpos($msg, '• Long Beach scenery') !== false);
check_true('render carries the overall feel', strpos($msg, 'Resort feel + comfy stay') !== false);
check_true('render carries the decision guide', strpos($msg, 'Which to pick') !== false && strpos($msg, 'Wants comfort → Laguna') !== false);
check_true('render carries the follow-up', strpos($msg, 'budget per person?') !== false);
check_true('render NEVER leaks agent-only tc_notes into the message', strpos($msg, 'do not promise') === false);
check('render empty when no options', '', customer_analysis_render_recommendation_text(array('intro' => 'hi', 'options' => array())));

// A parsed AI reply with a real recommendation object derives approach_suggestion
// from it (structured), and keeps the structure under 'recommendation'.
$recResp = customer_analysis_parse_ai_response(json_encode(array(
    'summary'        => 'Young group weighing Redang resorts.',
    'recommendation' => $recRaw,
)));
check_true('parse keeps the structured recommendation', is_array($recResp['recommendation']) && count($recResp['recommendation']['options']) === 2);
check_true('parse derives approach_suggestion from the recommendation', strpos($recResp['approach_suggestion'], '1. Laguna Redang Island Resort') !== false);
check_true('derived approach_suggestion excludes tc_notes', strpos($recResp['approach_suggestion'], 'do not promise') === false);
// Back-compat: an old-shape reply with only a flat approach_suggestion still works.
$legacy = customer_analysis_parse_ai_response('{"summary":"ok","approach_suggestion":"Hi! 帮你比较 Redang 😊"}');
check('legacy flat approach_suggestion preserved', 'Hi! 帮你比较 Redang 😊', $legacy['approach_suggestion']);
check('legacy reply has empty recommendation options', array(), $legacy['recommendation']['options']);

// Missing fields default cleanly; a string list is split into an array.
$partial = customer_analysis_parse_ai_response('{"summary":"Just a lead","preferences":"sea view; halal food"}');
check('parse defaults missing temperature to empty', '', $partial['temperature']);
check('parse defaults missing recommended_tours to empty list', array(), $partial['recommended_tours']);
check('parse defaults missing approach_suggestion to empty', '', $partial['approach_suggestion']);
check('parse defaults missing profile.character to empty', '', $partial['profile']['character']);
check('parse defaults missing profile.complaints to empty list', array(), $partial['profile']['complaints']);
check('parse splits a string list on ;', array('sea view', 'halal food'), $partial['profile']['preferences']);

// Stray prose around the object is tolerated.
$withProse = customer_analysis_parse_ai_response('Here is the analysis: {"summary":"ok"} hope it helps');
check('parse pulls object out of prose', 'ok', $withProse['summary']);

// Garbage returns null so the caller can flag an error.
check('parse returns null on non-json', null, customer_analysis_parse_ai_response('sorry, I cannot help'));
check('parse returns null on empty', null, customer_analysis_parse_ai_response('   '));

// ---- customer_analysis_format_competitor_products ---------------------------
$capRows = array(
    (object) array('product_name' => 'Rival Japan 6D', 'tour_code' => 'RJ6', 'destination' => 'Japan', 'duration' => '6D5N', 'price' => 5599, 'currency' => 'MYR'),
    (object) array('product_name' => '', 'destination' => 'Korea'),                       // no name -> dropped
    (object) array('product_name' => 'Rival Bali', 'destination' => 'Bali', 'price' => 0), // zero price -> omitted
);
$cap = customer_analysis_format_competitor_products($capRows);
check('competitor format drops nameless rows', 2, count($cap));
check('competitor format keeps name', 'Rival Japan 6D', $cap[0]['name']);
check('competitor format keeps tour_code', 'RJ6', $cap[0]['tour_code']);
check('competitor format keeps positive price', 5599.0, $cap[0]['price']);
check('competitor format keeps currency with price', 'MYR', $cap[0]['currency']);
check_true('competitor format omits zero price', ! isset($cap[1]['price']));
check_true('competitor format omits empty currency (no price)', ! isset($cap[1]['currency']));

// ---- customer_analysis_filter_products_by_destination -----------------------
$pool = array(
    array('name' => 'A', 'destination' => 'Japan'),
    array('name' => 'B', 'destination' => 'Bali'),
    array('name' => 'C', 'countries' => array('South Korea')),
    array('name' => 'D', 'destination' => 'Vietnam'),
);
$hay = "Customer: looking for a japan trip\nAgent: sure\nCustomer: maybe korea too";
$match = customer_analysis_filter_products_by_destination($pool, $hay);
$matchNames = array_map(function ($p) { return $p['name']; }, $match);
check_true('dest filter keeps mentioned destination (Japan)', in_array('A', $matchNames, true));
check_true('dest filter keeps country match (Korea)', in_array('C', $matchNames, true));
check_true('dest filter drops unmentioned (Bali)', ! in_array('B', $matchNames, true));
check_true('dest filter drops unmentioned (Vietnam)', ! in_array('D', $matchNames, true));
check('dest filter empty haystack -> none', array(), customer_analysis_filter_products_by_destination($pool, '   '));
check('dest filter honours limit', 1, count(customer_analysis_filter_products_by_destination($pool, $hay, 1)));

// ---- customer_analysis_competitor_block / faq_block -------------------------
check('competitor block empty when no products', '', customer_analysis_competitor_block(array()));
$cblk = customer_analysis_competitor_block(array(array('name' => 'Rival Japan 6D', 'destination' => 'Japan')));
check_true('competitor block is labelled', strpos($cblk, 'COMPETITOR PRODUCTS') !== false);
check_true('competitor block carries the rival name', strpos($cblk, 'Rival Japan 6D') !== false);
check_true('competitor block warns never to recommend them', stripos($cblk, 'NEVER these') !== false);
check('faq block empty when no corpus', '', customer_analysis_faq_block('   '));
$fblk = customer_analysis_faq_block("[1] Deposit\nQ: How much deposit?\nA: 30% on booking.");
check_true('faq block is labelled KNOWLEDGE BASE', strpos($fblk, 'KNOWLEDGE BASE') !== false);
check_true('faq block carries the FAQ text', strpos($fblk, '30% on booking') !== false);

// ---- references threaded into build_request ---------------------------------
$refs = array(
    'competitor_products' => array(array('name' => 'Rival Japan 6D', 'destination' => 'Japan', 'price' => 5599)),
    'faq_corpus'          => "[1] Deposit\nQ: Deposit?\nA: 30% on booking.",
);
$reqRef = customer_analysis_build_request('Ali', 'Customer: japan please', array(array('name' => 'Our Japan 6D')), $refs);
check_true('request includes OUR PRODUCTS block', strpos($reqRef['input'], 'OUR PRODUCTS') !== false);
check_true('request includes COMPETITOR PRODUCTS block', strpos($reqRef['input'], 'COMPETITOR PRODUCTS') !== false);
check_true('request includes KNOWLEDGE BASE block', strpos($reqRef['input'], 'KNOWLEDGE BASE') !== false);
check_true('request carries rival name', strpos($reqRef['input'], 'Rival Japan 6D') !== false);
check_true('request carries faq answer', strpos($reqRef['input'], '30% on booking') !== false);
// No references given -> neither optional block appears.
$reqBare = customer_analysis_build_request('Ali', 'Customer: hi', array(array('name' => 'Our Japan 6D')));
check_true('no COMPETITOR block when none given', strpos($reqBare['input'], 'COMPETITOR PRODUCTS') === false);
check_true('no KNOWLEDGE BASE block when none given', strpos($reqBare['input'], 'KNOWLEDGE BASE') === false);
// Update request threads references too.
$reqUpdRef = customer_analysis_build_update_request('Ali', array('summary' => 'x'), 'Customer: still keen', array(array('name' => 'Our Japan 6D')), $refs);
check_true('update request includes COMPETITOR PRODUCTS block', strpos($reqUpdRef['input'], 'COMPETITOR PRODUCTS') !== false);
check_true('update request includes KNOWLEDGE BASE block', strpos($reqUpdRef['input'], 'KNOWLEDGE BASE') !== false);

// ---- field guidance grounds the approach in the references ------------------
check_true('guidance tells model to use the KNOWLEDGE BASE', stripos($reqRef['instructions'], 'KNOWLEDGE BASE') !== false);
check_true('guidance tells model to position vs COMPETITOR PRODUCTS', stripos($reqRef['instructions'], 'COMPETITOR PRODUCTS') !== false);
check_true('guidance forbids recommending a competitor', stripos($reqRef['instructions'], 'NEVER recommend a competitor') !== false);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "{$failures} FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
