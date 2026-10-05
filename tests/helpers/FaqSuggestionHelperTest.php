<?php
/**
 * Run with: php tests/helpers/FaqSuggestionHelperTest.php
 *
 * Locks the pure faq_suggestion_helper functions that power the "FAQ AI
 * Suggestion" feature:
 *   - faq_suggestion_cutoff        : "last N days" lower-bound datetime (>=1 day)
 *   - faq_suggestion_transcript    : GHL + WA messages -> labelled transcript,
 *                                    dropping system/blank lines, tail-capped
 *   - faq_suggestion_build_prompt  : instructions + input; must contain "json"
 *                                    (Responses json_object mode) + destinations
 *   - faq_suggestion_parse_response: AI JSON -> clean suggestions, resolving
 *                                    destination names to ids, dropping bad rows
 *   - faq_suggestion_filter_new    : drop titles already seen / duplicated
 */

if (!defined('BASEPATH')) {
	define('BASEPATH', __DIR__);
}
require __DIR__ . '/../../application/helpers/faq_suggestion_helper.php';

function assert_eq($label, $expected, $actual) {
	if ($expected === $actual) {
		echo "  PASS  {$label}\n";
	} else {
		echo "  FAIL  {$label}: expected " . var_export($expected, true)
		   . ", got " . var_export($actual, true) . "\n";
		exit(1);
	}
}
function assert_true($label, $actual) { assert_eq($label, true, (bool) $actual); }
function assert_false($label, $actual) { assert_eq($label, false, (bool) $actual); }

// ---- cutoff ----------------------------------------------------------------
$now = mktime(12, 0, 0, 9, 17, 2026); // 2026-09-17 12:00:00
assert_eq('cutoff 3 days',        '2026-09-14 12:00:00', faq_suggestion_cutoff($now, 3));
assert_eq('cutoff 1 day',         '2026-09-16 12:00:00', faq_suggestion_cutoff($now, 1));
assert_eq('cutoff floors to 1',   '2026-09-16 12:00:00', faq_suggestion_cutoff($now, 0));
assert_eq('cutoff neg floors 1',  '2026-09-16 12:00:00', faq_suggestion_cutoff($now, -5));

// ---- transcript ------------------------------------------------------------
$ghl = array(
	array('direction' => 'inbound',  'body' => 'What time is check-in?'),
	array('direction' => 'outbound', 'body' => 'Check-in is 3pm.'),
	array('direction' => 'inbound',  'body' => '   '),          // blank -> dropped
);
$wa = array(
	array('outbound' => false, 'body' => 'Is breakfast included?', 'system' => false),
	array('outbound' => true,  'body' => 'Yes, daily breakfast.',  'system' => false),
	array('outbound' => false, 'body' => 'Messages are encrypted', 'system' => true), // system -> dropped
);
$t = faq_suggestion_transcript($ghl, $wa, 60000);
assert_eq('transcript lines',
	"Customer: What time is check-in?\nAgent: Check-in is 3pm.\nCustomer: Is breakfast included?\nAgent: Yes, daily breakfast.",
	$t);
assert_eq('empty inputs -> empty', '', faq_suggestion_transcript(array(), array(), 60000));
$with_sources = faq_suggestion_transcript_with_sources(
	array(array('id' => 91, 'direction' => 'inbound', 'body' => 'Can I pay by card?')),
	array(array('outbound' => true, 'body' => 'Yes, card is accepted.', 'system' => false, 'chat_file_id' => 7, 'message_index' => 3)),
	60000
);
assert_true('source transcript labels message', strpos($with_sources['text'], '[S1] Customer: Can I pay by card?') !== false);
assert_eq('source map preserves GHL id', 91, $with_sources['sources']['S1']['ghl_message_id']);
assert_eq('source map preserves WA file id', 7, $with_sources['sources']['S2']['chat_file_id']);

// ---- is_noise --------------------------------------------------------------
assert_true('blank is noise',            faq_suggestion_is_noise('   '));
assert_true('emoji only is noise',       faq_suggestion_is_noise('👍👍'));
assert_true('single char is noise',      faq_suggestion_is_noise('k'));
assert_true('ack ok is noise',           faq_suggestion_is_noise('Ok'));
assert_true('ack thanks is noise',       faq_suggestion_is_noise('Thank you!'));
assert_true('greeting hi is noise',      faq_suggestion_is_noise('Hi'));
assert_true('media omitted is noise',    faq_suggestion_is_noise('<Media omitted>'));
assert_true('deleted is noise',          faq_suggestion_is_noise('This message was deleted'));
assert_false('real question not noise',  faq_suggestion_is_noise('How much is the deposit?'));
assert_false('greeting+question kept',   faq_suggestion_is_noise('Hi, how much is the Japan tour?'));

// ---- transcript: noise drop + dedupe with counts ---------------------------
$g = array(
	array('direction' => 'inbound',  'body' => 'How much deposit?'),
	array('direction' => 'outbound', 'body' => 'RM500 per person.'),
	array('direction' => 'inbound',  'body' => 'ok thanks'),          // ack -> dropped
	array('direction' => 'inbound',  'body' => 'How much deposit??'),  // dup question
	array('direction' => 'inbound',  'body' => '<Media omitted>'),     // media -> dropped
	array('direction' => 'inbound',  'body' => 'how much   DEPOSIT'),  // dup question (3rd)
);
$out = faq_suggestion_transcript($g, array(), 0);
assert_eq('dedupe + count + noise drop',
	"Customer: How much deposit? (asked 3 times)\nAgent: RM500 per person.",
	$out);

// Tail-cap keeps the most recent whole lines only.
$many = array();
for ($i = 1; $i <= 50; $i++) {
	$many[] = array('direction' => 'inbound', 'body' => 'line' . $i);
}
$capped = faq_suggestion_transcript($many, array(), 40);
assert_true('cap under limit', strlen($capped) <= 40);
assert_false('cap drops early line', strpos($capped, 'line1:') !== false);
assert_true('cap keeps last line', strpos($capped, 'line50') !== false);
assert_false('cap never starts mid-line', substr($capped, 0, 1) === 'e' || substr($capped, 0, 1) === 'l' && strpos($capped, 'Customer') !== 0);

// ---- build_prompt ----------------------------------------------------------
$p = faq_suggestion_build_prompt("Customer: hi\nAgent: hello", array('Japan', ' Korea ', ''));
assert_true('prompt has instructions', trim($p['instructions']) !== '');
assert_true('input mentions json (json_object mode)', stripos($p['input'], 'json') !== false);
assert_true('input carries transcript', strpos($p['input'], 'Customer: hi') !== false);
assert_true('input lists destination Japan', strpos($p['input'], 'Japan') !== false);
assert_true('input lists destination Korea trimmed', strpos($p['input'], 'Korea') !== false);
$p2 = faq_suggestion_build_prompt('x', array());
assert_true('no destinations -> (none configured)', strpos($p2['input'], '(none configured)') !== false);
// Answers must be customer-facing, ready to copy & paste straight to a customer.
assert_true('prompt asks for ready-to-send answers', stripos($p['instructions'], 'ready-to-send') !== false);
assert_true('prompt mentions copy and paste to customer', stripos($p['instructions'], 'copy') !== false);
assert_true('json shape hint says ready-to-send reply', stripos($p['input'], 'ready-to-send reply') !== false);
assert_true('prompt requests evidence refs', strpos($p['input'], 'source_refs') !== false);
assert_true('prompt asks for step-by-step detail', stripos($p['instructions'], 'step-by-step') !== false);
assert_true('prompt preserves tour-specific facts', stripos($p['instructions'], 'Preserve tour-specific facts') !== false);
assert_true('prompt rejects genericising tour questions', stripos($p['instructions'], 'Do NOT turn a question about a named tour') !== false);
assert_true('prompt distinguishes private and published details', stripos($p['instructions'], 'published or generally applicable package price') !== false);

// build_prompt with existing FAQs injected so the model can skip duplicates.
$existing_faqs = array(
	array('title' => 'Deposit amount', 'questions' => array('How much is the deposit?')),
	array('title' => 'Check-in time',  'questions' => array('What time is check-in?')),
);
$pe = faq_suggestion_build_prompt("Customer: hi", array('Japan'), $existing_faqs);
assert_true('prompt lists an existing FAQ title',    strpos($pe['input'], 'Deposit amount') !== false);
assert_true('prompt lists an existing FAQ question', strpos($pe['input'], 'How much is the deposit?') !== false);
assert_true('prompt instructs to skip existing',     stripos($pe['input'] . $pe['instructions'], 'already') !== false);
assert_false('no existing block when none given',    strpos($p2['input'], 'already exist') !== false);

// build_prompt with a max cap -> the AI itself is told the ceiling.
$pc = faq_suggestion_build_prompt("Customer: hi", array('Japan'), array(), 25);
assert_true('capped prompt states the max in instructions', strpos($pc['instructions'], '25') !== false);
assert_true('capped prompt says "at most"',                 stripos($pc['instructions'] . $pc['input'], 'at most') !== false);
assert_false('uncapped prompt has no "at most"',            stripos($p['instructions'] . $p['input'], 'at most') !== false);
$pc0 = faq_suggestion_build_prompt("Customer: hi", array('Japan'), array(), 0);
assert_false('max 0 -> no "at most" (uncapped)',            stripos($pc0['instructions'] . $pc0['input'], 'at most') !== false);

// ---- existing_block --------------------------------------------------------
$blk = faq_suggestion_existing_block($existing_faqs);
assert_true('block has title',    strpos($blk, 'Deposit amount') !== false);
assert_true('block has question', strpos($blk, 'How much is the deposit?') !== false);
assert_eq('block empty for none', '', faq_suggestion_existing_block(array()));

// ---- parse_response --------------------------------------------------------
$dest_map = array('Japan' => 5, 'Korea' => 8);
$json = array('suggestions' => array(
	array(
		'title' => 'Check-in time',
		'reason' => 'Asked by many customers before arrival.',
		'source_refs' => array('s3', 'NOPE', 'S3', 'S9'),
		'destinations' => array('Japan', 'Atlantis'), // Atlantis unknown -> dropped
		'items' => array(
			array('q' => 'What time is check-in?', 'a' => '3pm.'),
			array('q' => 'half', 'a' => ''),           // incomplete -> dropped
		),
	),
	array('title' => '', 'items' => array(array('q' => 'x', 'a' => 'y'))), // no title -> dropped
	array('title' => 'No items here', 'items' => array()),                 // no items -> dropped
	array(
		'title' => 'Breakfast',
		'destinations' => array('korea'),             // case-insensitive resolve
		'items' => array(array('q' => 'Breakfast?', 'a' => 'Included.')),
	),
));
$parsed = faq_suggestion_parse_response($json, $dest_map, 30);
assert_eq('parsed count', 2, count($parsed));
assert_eq('parsed[0] title', 'Check-in time', $parsed[0]['title']);
assert_eq('parsed[0] reason kept', 'Asked by many customers before arrival.', $parsed[0]['reason']);
assert_eq('parsed[1] reason default empty', '', $parsed[1]['reason']);
assert_eq('parsed[0] dest ids', array(5), $parsed[0]['destination_ids']);
assert_eq('parsed[0] items kept', 1, count($parsed[0]['items']));
assert_eq('parsed[1] title', 'Breakfast', $parsed[1]['title']);
assert_eq('parsed[1] dest ids ci', array(8), $parsed[1]['destination_ids']);
assert_eq('valid source refs normalised', array('S3', 'S9'), $parsed[0]['source_refs']);

// String input is decoded; cap is honoured.
$raw = '{"suggestions":[{"title":"A","items":[{"q":"a","a":"b"}]},{"title":"B","items":[{"q":"a","a":"b"}]}]}';
assert_eq('string input decoded + capped', 1, count(faq_suggestion_parse_response($raw, array(), 1)));
// max = 0 disables the cap entirely (store every suggestion the AI returns).
assert_eq('max 0 = uncapped', 2, count(faq_suggestion_parse_response($raw, array(), 0)));
assert_eq('garbage -> empty', array(), faq_suggestion_parse_response('not json', array(), 30));

// ---- norm_title / filter_new ----------------------------------------------
assert_eq('norm strips punctuation', 'check in time', faq_suggestion_norm_title('  Check-In  Time!! '));
$sugg = array(
	array('title' => 'Check-in time'),
	array('title' => 'CHECK IN TIME'),   // dup of #1 -> dropped
	array('title' => 'Baggage allowance'),
	array('title' => 'Visa rules'),      // already an existing FAQ -> dropped
);
$new = faq_suggestion_filter_new($sugg, array('Visa Rules'));
assert_eq('filter_new count', 2, count($new));
assert_eq('filter_new[0]', 'Check-in time', $new[0]['title']);
assert_eq('filter_new[1]', 'Baggage allowance', $new[1]['title']);

// filter_new also drops a suggestion whose QUESTION already exists as a FAQ
// question, even when its title is worded differently (reworded-title case).
$sugg2 = array(
	array('title' => 'Deposit info', 'items' => array(array('q' => 'How much is the deposit?', 'a' => 'RM500.'))),
	array('title' => 'Refund policy', 'items' => array(array('q' => 'Can I get a refund?', 'a' => 'Yes.'))),
);
$new2 = faq_suggestion_filter_new($sugg2, array(), array('How much is the deposit?'));
assert_eq('filter_new by question count', 1, count($new2));
assert_eq('filter_new by question kept',  'Refund policy', $new2[0]['title']);

// A suggestion whose title matches an existing question is also dropped.
$sugg3 = array(array('title' => 'How much is the deposit', 'items' => array(array('q' => 'x?', 'a' => 'y'))));
assert_eq('filter_new title vs existing question', 0,
	count(faq_suggestion_filter_new($sugg3, array(), array('How much is the deposit?'))));

// Two batch suggestions sharing one identical question -> second dropped.
$sugg4 = array(
	array('title' => 'A', 'items' => array(array('q' => 'What is the deposit?', 'a' => '1'))),
	array('title' => 'B', 'items' => array(array('q' => 'What is the deposit?', 'a' => '2'))),
);
assert_eq('filter_new in-batch question dedupe', 1, count(faq_suggestion_filter_new($sugg4)));

// ---- date_range / valid_date ----------------------------------------------
assert_eq('valid_date passes',      '2026-09-17', faq_suggestion_valid_date('2026-09-17'));
assert_eq('valid_date trims',       '2026-09-17', faq_suggestion_valid_date('  2026-09-17 '));
assert_eq('valid_date rejects bad', '', faq_suggestion_valid_date('2026-02-30'));
assert_eq('valid_date rejects fmt', '', faq_suggestion_valid_date('17/09/2026'));
assert_eq('valid_date rejects blank', '', faq_suggestion_valid_date(''));

$r = faq_suggestion_date_range('2026-09-10', '2026-09-17');
assert_eq('range start floored', '2026-09-10 00:00:00', $r['start']);
assert_eq('range end raised',    '2026-09-17 23:59:59', $r['end']);
assert_eq('range no error',      '', $r['error']);

$r1 = faq_suggestion_date_range('2026-09-17', '2026-09-17'); // single day
assert_eq('single-day start', '2026-09-17 00:00:00', $r1['start']);
assert_eq('single-day end',   '2026-09-17 23:59:59', $r1['end']);

$r2 = faq_suggestion_date_range('2026-09-20', '2026-09-10'); // start after end
assert_true('reversed range errors',  $r2['error'] !== '');
assert_eq('reversed range no start',  '', $r2['start']);

$r3 = faq_suggestion_date_range('', '2026-09-10'); // blank start
assert_true('blank start errors', $r3['error'] !== '');

// ---- phone_key -------------------------------------------------------------
assert_eq('phone_key last9 of e164', '123456789', faq_suggestion_phone_key('+60123456789'));
assert_eq('phone_key strips format',  '123456789', faq_suggestion_phone_key('012-345 6789'));
assert_eq('phone_key short kept',     '12345',     faq_suggestion_phone_key('12345'));
assert_eq('phone_key blank',          '',          faq_suggestion_phone_key('  '));
assert_eq('phone_key no digits',      '',          faq_suggestion_phone_key('abc'));

// ---- fmt_date --------------------------------------------------------------
assert_eq('fmt_date ymd',        '17 Sep 2026', faq_suggestion_fmt_date('2026-09-17'));
assert_eq('fmt_date datetime',   '17 Sep 2026', faq_suggestion_fmt_date('2026-09-17 08:30:00'));
assert_eq('fmt_date blank',      '', faq_suggestion_fmt_date(''));
assert_eq('fmt_date zero date',  '', faq_suggestion_fmt_date('0000-00-00'));

// ---- run_scope -------------------------------------------------------------
assert_eq('scope pdf uses filename', 'japan-2026.pdf',
	faq_suggestion_run_scope(array('Source' => 'pdf', 'FileName' => 'japan-2026.pdf')));
assert_eq('scope pdf fallback', 'Uploaded PDF',
	faq_suggestion_run_scope(array('Source' => 'pdf', 'FileName' => '')));
assert_eq('scope chats range', '10 Sep 2026 – 17 Sep 2026',
	faq_suggestion_run_scope(array('Source' => 'chats', 'StartDate' => '2026-09-10', 'EndDate' => '2026-09-17')));
assert_eq('scope chats range + mobile', '10 Sep 2026 – 17 Sep 2026 · 0123456789',
	faq_suggestion_run_scope(array('Source' => 'chats', 'StartDate' => '2026-09-10', 'EndDate' => '2026-09-17', 'Mobile' => '0123456789')));
assert_eq('scope chats fallback', 'Recent chats',
	faq_suggestion_run_scope(array('Source' => 'chats')));
$obj = (object) array('Source' => 'chats', 'StartDate' => '2026-09-17', 'EndDate' => '2026-09-17');
assert_eq('scope accepts object', '17 Sep 2026 – 17 Sep 2026', faq_suggestion_run_scope($obj));

// ---- build_file_prompt -----------------------------------------------------
$fp = faq_suggestion_build_file_prompt(array('Japan', 'Korea'));
assert_true('file prompt has instructions', strlen($fp['instructions']) > 0);
assert_true('file prompt input mentions json', stripos($fp['input'], 'json') !== false);
assert_true('file prompt lists destinations', strpos($fp['input'], 'Japan') !== false);
assert_true('file prompt asks for ready-to-send answers', stripos($fp['instructions'], 'ready-to-send') !== false);
assert_true('file prompt json hint says ready-to-send reply', stripos($fp['input'], 'ready-to-send reply') !== false);
assert_true('file prompt asks for step-by-step detail', stripos($fp['instructions'], 'step-by-step') !== false);
$fpe = faq_suggestion_build_file_prompt(array('Japan'), $existing_faqs);
assert_true('file prompt lists existing FAQ', strpos($fpe['input'], 'Deposit amount') !== false);
assert_true('file prompt instructs skip existing', stripos($fpe['input'] . $fpe['instructions'], 'already') !== false);
$fpc = faq_suggestion_build_file_prompt(array('Japan'), array(), 40);
assert_true('capped file prompt states the max', strpos($fpc['instructions'], '40') !== false);
assert_true('capped file prompt says "at most"', stripos($fpc['instructions'] . $fpc['input'], 'at most') !== false);
assert_false('uncapped file prompt has no "at most"', stripos($fp['instructions'] . $fp['input'], 'at most') !== false);

// ---- logs_to_prune ---------------------------------------------------------
$now = mktime(12, 0, 0, 9, 18, 2026); // 2026-09-18 12:00:00
$files = array(
	array('path' => 'run_1.out', 'mtime' => $now),                 // today -> keep
	array('path' => 'run_2.out', 'mtime' => $now - 2 * 86400),     // 2 days -> keep
	array('path' => 'run_3.out', 'mtime' => $now - 3 * 86400 - 1), // just over 3 days -> prune
	array('path' => 'run_4.out', 'mtime' => $now - 10 * 86400),    // 10 days -> prune
);
assert_eq('prune keeps last 3 days', array('run_3.out', 'run_4.out'),
	faq_suggestion_logs_to_prune($files, $now, 3));
assert_eq('prune empty list', array(), faq_suggestion_logs_to_prune(array(), $now, 3));
assert_eq('prune floors keep_days to 1', array('run_2.out', 'run_3.out', 'run_4.out'),
	faq_suggestion_logs_to_prune($files, $now, 0));

// ---- embed_text (semantic dedupe canonical text) ---------------------------
assert_eq('embed_text folds title + q + a',
	'Deposit How much deposit? RM500 per person',
	faq_suggestion_embed_text('Deposit', array(array('q' => 'How much deposit?', 'a' => 'RM500 per person'))));
assert_eq('embed_text collapses whitespace',
	'A B C',
	faq_suggestion_embed_text("  A  ", array(array('q' => "B\n\n", 'a' => "  C "))));
assert_eq('embed_text skips empty pairs',
	'Only title',
	faq_suggestion_embed_text('Only title', array(array('q' => '', 'a' => ''))));

// ---- cosine ----------------------------------------------------------------
assert_eq('cosine identical = 1',      1.0, faq_suggestion_cosine(array(1, 2, 3), array(1, 2, 3)));
assert_eq('cosine scaled = 1',         1.0, faq_suggestion_cosine(array(1, 0), array(5, 0)));
assert_eq('cosine orthogonal = 0',     0.0, faq_suggestion_cosine(array(1, 0), array(0, 1)));
assert_eq('cosine length mismatch = 0', 0.0, faq_suggestion_cosine(array(1, 2), array(1, 2, 3)));
assert_eq('cosine empty = 0',          0.0, faq_suggestion_cosine(array(), array()));
assert_eq('cosine zero vector = 0',    0.0, faq_suggestion_cosine(array(0, 0), array(1, 1)));

// ---- filter_semantic -------------------------------------------------------
// A candidate near an existing FAQ vector is dropped; a distinct one survives.
$sugs = array(array('title' => 'near'), array('title' => 'far'));
$sug_vecs = array(array(1.0, 0.0), array(0.0, 1.0));
$existing_vecs = array(array(0.99, 0.01)); // ~cos 0.9999 to "near", ~0.01 to "far"
assert_eq('semantic drops near-existing, keeps distinct',
	array(array('title' => 'far')),
	faq_suggestion_filter_semantic($sugs, $sug_vecs, $existing_vecs, 0.9));

// In-batch dedupe: two candidates that embed nearly the same -> keep the first.
$dupSugs = array(array('title' => 'first'), array('title' => 'reworded dup'));
$dupVecs = array(array(1.0, 0.0), array(0.999, 0.001));
assert_eq('semantic in-batch keeps first only',
	array(array('title' => 'first')),
	faq_suggestion_filter_semantic($dupSugs, $dupVecs, array(), 0.9));

// Threshold outside (0,1) disables the pass -> everything survives.
assert_eq('semantic disabled by threshold >= 1',
	$sugs, faq_suggestion_filter_semantic($sugs, $sug_vecs, $existing_vecs, 1));
assert_eq('semantic disabled by threshold <= 0',
	$sugs, faq_suggestion_filter_semantic($sugs, $sug_vecs, $existing_vecs, 0));

// A candidate with no usable vector is KEPT (fail-open, never silently dropped).
assert_eq('semantic keeps candidate lacking a vector',
	array(array('title' => 'novec')),
	faq_suggestion_filter_semantic(array(array('title' => 'novec')), array(null), $existing_vecs, 0.9));

// ---- memory_limit (PDF processing headroom) --------------------------------
assert_eq('mem default when blank',   '1024M', faq_suggestion_memory_limit(''));
assert_eq('mem default when null',    '1024M', faq_suggestion_memory_limit(null));
assert_eq('mem passes valid M',       '2048M', faq_suggestion_memory_limit('2048M'));
assert_eq('mem uppercases suffix',    '512M',  faq_suggestion_memory_limit('512m'));
assert_eq('mem allows G suffix',      '2G',    faq_suggestion_memory_limit('2g'));
assert_eq('mem allows raw bytes',     '268435456', faq_suggestion_memory_limit('268435456'));
assert_eq('mem allows -1 unlimited',  '-1',    faq_suggestion_memory_limit('-1'));
assert_eq('mem rejects garbage',      '1024M', faq_suggestion_memory_limit('lots'));
assert_eq('mem rejects bad suffix',   '1024M', faq_suggestion_memory_limit('512MB'));
assert_eq('mem custom default',       '2048M', faq_suggestion_memory_limit('', '2048M'));

// ---- input_preview (AI-input column hint) ----------------------------------
assert_eq('preview blank -> empty',      '', faq_suggestion_input_preview(''));
assert_eq('preview null -> empty',       '', faq_suggestion_input_preview(null));
assert_eq('preview collapses whitespace',
	'Customer: hi Agent: yo',
	faq_suggestion_input_preview("Customer: hi\nAgent:   yo"));
assert_eq('preview passes short through',
	'Short line',
	faq_suggestion_input_preview('  Short line  '));
assert_eq('preview truncates with ellipsis',
	str_repeat('a', 10) . '…',
	faq_suggestion_input_preview(str_repeat('a', 300), 10));
assert_eq('preview keeps exactly max',
	str_repeat('b', 10),
	faq_suggestion_input_preview(str_repeat('b', 10), 10));

echo "\nAll FaqSuggestionHelper tests passed.\n";
