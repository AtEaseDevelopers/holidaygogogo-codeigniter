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
$separate=faq_suggestion_transcript_with_sources(array(
    array('id'=>1,'conversation_id'=>'tour-a','direction'=>'inbound','body'=>'Are meals included?'),
    array('id'=>2,'conversation_id'=>'tour-b','direction'=>'inbound','body'=>'Are meals included?')
),array(),0);
assert_eq('same question in different tours retains both sources',2,count($separate['sources']));
assert_true('transcript identifies conversation boundaries',strpos($separate['text'],'conversation C1')!==false && strpos($separate['text'],'conversation C2')!==false);

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

// ---- JSON mode input (single/batch re-evaluation and file requests) ----------
$candidate = array('id'=>64, 'title'=>'Club Med Cherating child under 2 years old eligibility',
	'items'=>array(array('q'=>'Can I bring a child below 2 years old to Club Med Cherating?', 'a'=>'')));
$evidence = array(array('reference'=>'S1094', 'text'=>'Customer: Club med Cherating boleh bawa budak 2tahun ke bawah'));
$reevaluation = faq_workspace_reevaluate_prompt($candidate, $evidence, '', array());
assert_false('regression fixture has no json word in the candidate input', stripos($reevaluation['input'], 'json') !== false);
assert_true('instructions alone already mention JSON', stripos($reevaluation['instructions'], 'json') !== false);
$messages = faq_suggestion_json_input($reevaluation['input']);
assert_true('single re-evaluation input explicitly requests JSON', stripos($messages[0]['content'], 'json object') !== false);
assert_eq('candidate input remains a user message', 'user', $messages[1]['role']);
assert_eq('single re-evaluation keeps its exact input', $reevaluation['input'], $messages[1]['content']);
assert_eq('single re-evaluation preserves candidate and evidence', array(
	'existing_candidate'=>$candidate, 'conversation_evidence'=>$evidence,
	'staff_additional_information'=>'', 'approved_knowledge'=>array(), 'staff_information_reference'=>null,
), json_decode($messages[1]['content'], true));

$batch_input = json_encode(array('candidates'=>array(array('candidate_id'=>64, 'input'=>json_decode($reevaluation['input'], true)))));
$messages = faq_suggestion_json_input($batch_input);
assert_true('batch re-evaluation input explicitly requests JSON', stripos($messages[0]['content'], 'json object') !== false);
assert_eq('batch re-evaluation keeps its candidate IDs and data', $batch_input, $messages[1]['content']);

foreach (array(
	array('type'=>'input_file', 'file_id'=>'file-test'),
	array('type'=>'input_file', 'filename'=>'policy.pdf', 'file_data'=>'data:application/pdf;base64,cGRm'),
	array('type'=>'input_image', 'image_url'=>'data:image/png;base64,aW1hZ2U='),
) as $attachment) {
	$file_input = array(array('role'=>'user', 'content'=>array(
		array('type'=>'input_text', 'text'=>'Extract policy excerpts for review.'), $attachment,
	)));
	$messages = faq_suggestion_json_input($file_input);
	assert_true($attachment['type'].' request explicitly asks for JSON', stripos($messages[0]['content'], 'json object') !== false);
	assert_eq($attachment['type'].' request keeps its complete attachment and text', $file_input, array_slice($messages, 1));
	assert_eq('file input is not mutated', 'Extract policy excerpts for review.', $file_input[0]['content'][0]['text']);
}
$chat_input = "Return json with this exact shape:\n{\"suggestions\":[]}\nCustomer: hi";
$messages = faq_suggestion_json_input($chat_input);
assert_eq('existing JSON chat prompts remain intact', $chat_input, $messages[1]['content']);

// ---- build_prompt ----------------------------------------------------------
$p = faq_suggestion_build_prompt("Customer: hi\nAgent: hello", array('Japan', ' Korea ', ''));
assert_true('prompt has instructions', trim($p['instructions']) !== '');
assert_true('input mentions json (json_object mode)', stripos($p['input'], 'json') !== false);
assert_true('input carries transcript', strpos($p['input'], 'Customer: hi') !== false);
assert_true('input lists destination Japan', strpos($p['input'], 'Japan') !== false);
assert_true('input lists destination Korea trimmed', strpos($p['input'], 'Korea') !== false);
$p2 = faq_suggestion_build_prompt('x', array());
assert_true('no destinations -> (none configured)', strpos($p2['input'], '(none configured)') !== false);
// One response identifies scope, assesses readiness and drafts supported answers.
assert_true('prompt drafts in the initial response', stripos($p['instructions'], 'draft answers and assess readiness in this single response') !== false);
assert_true('prompt forbids inferred policy', stripos($p['instructions'], 'Never infer a supplier/resort policy') !== false);
assert_true('json shape has label and missing information', strpos($p['input'], '"label":"Needs Information"') !== false && strpos($p['input'], 'missing_information') !== false);
assert_true('prompt requests evidence refs', strpos($p['input'], 'source_refs') !== false);
assert_true('original prompt still requests ready-to-send replies', stripos($p['instructions'], 'READY-TO-SEND') !== false && stripos($p['instructions'], 'Be EXHAUSTIVE') !== false && stripos($p['instructions'], 'STEP-BY-STEP') !== false);
assert_false('prompt no longer requires empty answers for missing information', strpos($p['instructions'].$p['input'], 'empty answers') !== false);
assert_true('prompt preserves tour-specific facts', stripos($p['instructions'], 'Preserve tour-specific facts') !== false);
assert_true('prompt rejects genericising tour questions', stripos($p['instructions'], 'Do NOT turn a question about a named tour') !== false);
assert_true('prompt distinguishes private and published details', stripos($p['instructions'], 'published or generally applicable package price') !== false);
$chat_prompt=faq_suggestion_build_prompt('[S1] Customer: What meals?\n[S2] Agent: Daily breakfast for Package A.',array(),array(),10);
assert_false('initial chat input has no knowledge block',strpos($chat_prompt['input'],'APPROVED KNOWLEDGE')!==false);
assert_false('initial chat input has no package catalogue',strpos($chat_prompt['input'],'ACTIVE PACKAGE NAMES')!==false);
assert_true('initial chat input preserves supporting reply',strpos($chat_prompt['input'],'Daily breakfast for Package A.')!==false);
assert_true('initial chat instructions keep unanswered questions',strpos($chat_prompt['instructions'],'Keep reusable questions even when no answer is available')!==false);
assert_true('initial chat instructions restrict answer references to chats',strpos($chat_prompt['instructions'],'must be a supplied S# chat message')!==false);
assert_true('initial chat instructions defer destination knowledge',strpos($chat_prompt['instructions'],'destination knowledge is added only during re-evaluation')!==false);
$sample=faq_suggestion_candidate_example(); $sample['title']='Package A meals';
assert_false('generation contract has no structured context',array_key_exists('context',$sample));
$sample['label']='Pending Approval'; $sample['missing_information']=array(); $sample['answer_refs']=array('S2','K12'); $sample['items']=array(array('q'=>'What meals?','a'=>'Daily breakfast.'));
$result=faq_suggestion_parse_response(array('suggestions'=>array($sample)),array(),1,true);
assert_eq('strict assessment preserves AI answer','Daily breakfast.',$result[0]['items'][0]['a']);
assert_eq('label maps to the saved readiness status','pending_approval',$result[0]['assessment']['status']);
assert_eq('strict assessment keeps answer message evidence',array('S2'),$result[0]['source_refs']);
$sample['label']='Approved';
assert_eq('AI cannot approve its own candidate',array(),faq_suggestion_parse_response(array('suggestions'=>array($sample)),array(),1,true));
$sample['label']='General';
assert_eq('general is rejected as a readiness label',array(),faq_suggestion_parse_response(array('suggestions'=>array($sample)),array(),1,true));
$sample['label']='Ready to Approve';
assert_eq('removed ready-to-approve label rejected',array(),faq_suggestion_parse_response(array('suggestions'=>array($sample)),array(),1,true));
$sample['label']='Needs Information'; $sample['missing_information']=array('Confirm whether dinner is included.');
$partial=faq_suggestion_parse_response(array('suggestions'=>array($sample)),array(),1,true);
assert_eq('needs information preserves a supported partial draft','Daily breakfast.',$partial[0]['items'][0]['a']);
assert_eq('partial draft still needs information','needs_information',$partial[0]['assessment']['status']);
$sample['status']='pending_approval';
assert_eq('conflicting label and status rejected',array(),faq_suggestion_parse_response(array('suggestions'=>array($sample)),array(),1,true));
$sample['status']='needs_information'; unset($sample['label']);
assert_eq('previous status contract remains readable','needs_information',faq_suggestion_parse_response(array('suggestions'=>array($sample)),array(),1,true)[0]['assessment']['status']);
$sample['items'][0]['a']='';
assert_eq('missing information candidate survives an empty answer',1,count(faq_suggestion_parse_response(array('suggestions'=>array($sample)),array(),1,true)));

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
assert_true('file prompt drafts supported answers immediately', stripos($fp['instructions'], 'draft answers and assess readiness in this single response') !== false);
assert_true('file prompt identifies document evidence', strpos($fp['input'], 'D1') !== false);
assert_true('original file prompt requests detailed replies grounded in the document', stripos($fp['instructions'], 'READY-TO-SEND') !== false && strpos($fp['instructions'], "grounded in the document's contents") !== false);
$fpe = faq_suggestion_build_file_prompt(array('Japan'), $existing_faqs);
assert_true('file prompt lists existing FAQ', strpos($fpe['input'], 'Deposit amount') !== false);
assert_true('file prompt instructs skip existing', stripos($fpe['input'] . $fpe['instructions'], 'already') !== false);
$fpc = faq_suggestion_build_file_prompt(array('Japan'), array(), 40);
assert_true('capped file prompt states the max', strpos($fpc['instructions'], '40') !== false);
assert_true('capped file prompt says "at most"', stripos($fpc['instructions'] . $fpc['input'], 'at most') !== false);
assert_false('uncapped file prompt has no "at most"', stripos($fp['instructions'] . $fp['input'], 'at most') !== false);
$fpk=faq_suggestion_build_file_prompt(array('Japan'),array(),10,array(array('reference'=>'K12','excerpt'=>'Daily breakfast for Package A.')));
assert_true('document prompt still includes supplied knowledge',strpos($fpk['input'],'Daily breakfast for Package A.')!==false);

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
