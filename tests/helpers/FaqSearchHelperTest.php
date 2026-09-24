<?php
/**
 * Run with: php tests/helpers/FaqSearchHelperTest.php
 *
 * Locks the pure faq_search_helper functions behind the "AI answer" box on the
 * internal FAQ Library page (/Faq/Internal):
 *   - faq_search_valid_question : trim/collapse; reject too-short questions
 *   - faq_search_build_corpus   : FAQ list -> numbered Q/A corpus, skips empties,
 *                                 tail-capped to a char budget
 *   - faq_search_build_prompt   : instructions + input; must contain "json"
 *                                 (Responses json_object mode) + the question
 *   - faq_search_parse_response : AI JSON -> {found, answer, sources}, deduped,
 *                                 empty answer forces found=false
 */

if (!defined('BASEPATH')) {
	define('BASEPATH', __DIR__);
}
require __DIR__ . '/../../application/helpers/faq_search_helper.php';

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
function assert_contains($label, $needle, $haystack) {
	if (strpos((string) $haystack, (string) $needle) !== false) {
		echo "  PASS  {$label}\n";
	} else {
		echo "  FAIL  {$label}: " . var_export($needle, true) . " not found in "
		   . var_export($haystack, true) . "\n";
		exit(1);
	}
}

// ---- valid_question --------------------------------------------------------
assert_eq('trims + collapses',   'is breakfast included?', faq_search_valid_question("  is   breakfast\nincluded?  "));
assert_eq('too short -> empty',  '', faq_search_valid_question('hi'));
assert_eq('blank -> empty',      '', faq_search_valid_question('   '));
assert_eq('keeps 3 chars',       'why', faq_search_valid_question('why'));

// ---- build_corpus ----------------------------------------------------------
$faqs = array(
	array(
		'title' => 'Redang Bay Resort',
		'destinations' => array('REDANG', ''),
		'items' => array(
			array('q' => 'What is the check-in time?', 'a' => 'Check-in is 3pm.'),
			array('q' => '', 'a' => ''),                 // empty -> skipped
		),
	),
	array(
		'title' => 'Empty FAQ',
		'destinations' => array(),
		'items' => array(),                              // no answerable content -> whole FAQ skipped
	),
	array(
		'title' => 'Payment',
		'items' => array(
			array('q' => 'How to pay deposit?', 'a' => 'Bank transfer 30%.'),
		),
	),
);
$corpus = faq_search_build_corpus($faqs);
assert_contains('corpus numbers first FAQ',  '[1] Redang Bay Resort (REDANG)', $corpus);
assert_contains('corpus keeps Q',            'Q: What is the check-in time?', $corpus);
assert_contains('corpus keeps A',            'A: Check-in is 3pm.', $corpus);
assert_false('corpus drops empty FAQ',       strpos($corpus, 'Empty FAQ') !== false);
assert_contains('corpus renumbers past skip','[2] Payment', $corpus);
assert_eq('empty list -> empty corpus',      '', faq_search_build_corpus(array()));

// char cap keeps the head
$long = array(array('title' => 'A', 'items' => array(array('q' => str_repeat('x', 500), 'a' => 'y'))));
$capped = faq_search_build_corpus($long, 50);
assert_true('corpus respects char cap', strlen($capped) <= 50);

// ---- build_prompt ----------------------------------------------------------
$spec = faq_search_build_prompt('Is breakfast included?', $corpus);
assert_true('prompt has instructions', isset($spec['instructions']) && $spec['instructions'] !== '');
assert_contains('input mentions json (json_object mode)', 'json', $spec['input']);
assert_contains('input carries the question', 'Is breakfast included?', $spec['input']);
assert_contains('input carries the corpus', 'Check-in is 3pm.', $spec['input']);
$empty_spec = faq_search_build_prompt('anything', '');
assert_contains('empty corpus noted', 'FAQ library is empty', $empty_spec['input']);

// ---- parse_response --------------------------------------------------------
$ok = faq_search_parse_response(array(
	'found' => true,
	'answer' => 'Yes, breakfast is included daily.',
	'sources' => array('Redang Bay Resort', 'redang bay resort', 'Payment'),
));
assert_true('found true',            $ok['found']);
assert_eq('answer kept',             'Yes, breakfast is included daily.', $ok['answer']);
assert_eq('sources deduped (ci)',    array('Redang Bay Resort', 'Payment'), $ok['sources']);

$missing = faq_search_parse_response(array('found' => true, 'answer' => '   '));
assert_false('empty answer forces not-found', $missing['found']);
assert_eq('empty answer normalised', '', $missing['answer']);

$implicit = faq_search_parse_response(array('answer' => 'Some answer.'));
assert_true('no flag + answer -> found', $implicit['found']);

$garbage = faq_search_parse_response('not an object');
assert_false('garbage -> not found', $garbage['found']);
assert_eq('garbage -> empty answer', '', $garbage['answer']);
assert_eq('garbage -> no sources', array(), $garbage['sources']);

echo "\nAll FaqSearchHelper tests passed.\n";
