<?php
/**
 * Run with: php tests/helpers/AiUsageHelperTest.php
 *
 * Locks the pure ai_usage_helper functions behind the owner-only "AI Cost &
 * Usage" page (see controllers/Ai_Usage.php):
 *   - ai_usage_summary   : total calls / tokens / cost across log rows
 *   - ai_usage_group     : roll up by feature / model / user, and the special
 *                          'month' timeline dimension; ordering rules
 *   - ai_usage_group_key : month = YYYY-MM from created_at; blanks -> placeholder
 *   - ai_usage_fmt_*     : display formatting
 */

if (!defined('BASEPATH')) {
	define('BASEPATH', __DIR__);
}
require __DIR__ . '/../../application/helpers/ai_usage_helper.php';

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

// --- fixtures: a small mixed set of AI calls -------------------------------
$rows = array(
	array('feature' => 'Competitor Analysis', 'model' => 'gpt-4o',      'input_tokens' => 1000, 'output_tokens' => 500,  'cost_usd' => 0.0075, 'created_at' => '2026-09-10 09:00:00', 'created_by_name' => 'Alice'),
	array('feature' => 'Customer Analysis',   'model' => 'gpt-4o-mini', 'input_tokens' => 2000, 'output_tokens' => 1000, 'cost_usd' => 0.0009, 'created_at' => '2026-09-12 10:00:00', 'created_by_name' => 'Alice'),
	array('feature' => 'FAQ Search',          'model' => 'gpt-4o-mini', 'input_tokens' => 500,  'output_tokens' => 100,  'cost_usd' => 0.000135, 'created_at' => '2026-08-30 11:00:00', 'created_by_name' => ''),
	array('feature' => 'Competitor Analysis', 'model' => 'gpt-4o',      'input_tokens' => 3000, 'output_tokens' => 1500, 'cost_usd' => 0.0225, 'created_at' => '2026-09-15 12:00:00', 'created_by_name' => 'Bob'),
);

echo "ai_usage_summary\n";
$sum = ai_usage_summary($rows);
assert_eq('calls', 4, $sum['calls']);
assert_eq('input_tokens', 6500, $sum['input_tokens']);
assert_eq('output_tokens', 3100, $sum['output_tokens']);
assert_eq('total_tokens', 9600, $sum['total_tokens']);
assert_eq('cost_usd', round(0.0075 + 0.0009 + 0.000135 + 0.0225, 6), $sum['cost_usd']);

echo "ai_usage_summary empty\n";
$empty = ai_usage_summary(array());
assert_eq('empty calls', 0, $empty['calls']);
assert_eq('empty cost', 0.0, $empty['cost_usd']);
assert_eq('non-array safe', 0, ai_usage_summary('nope')['calls']);

echo "ai_usage_group by feature\n";
$byf = ai_usage_group($rows, 'feature');
assert_eq('group count', 3, count($byf));
// Competitor Analysis has the highest cost (0.03) -> first
assert_eq('top feature key', 'Competitor Analysis', $byf[0]['key']);
assert_eq('top feature calls', 2, $byf[0]['calls']);
assert_eq('top feature tokens', 6000, $byf[0]['total_tokens']);
assert_eq('top feature cost', 0.03, $byf[0]['cost_usd']);

echo "ai_usage_group by model\n";
$bym = ai_usage_group($rows, 'model');
assert_eq('model count', 2, count($bym));
assert_eq('top model key', 'gpt-4o', $bym[0]['key']);

echo "ai_usage_group by user (blank -> placeholder)\n";
$byu = ai_usage_group($rows, 'created_by_name');
$keys = array();
foreach ($byu as $g) { $keys[] = $g['key']; }
assert_true('blank user shown as (none)', in_array('(none)', $keys, true));

echo "ai_usage_group month is chronological\n";
$bymonth = ai_usage_group($rows, 'month');
assert_eq('month count', 2, count($bymonth));
assert_eq('first month', '2026-08', $bymonth[0]['key']);
assert_eq('second month', '2026-09', $bymonth[1]['key']);
assert_eq('sept calls', 3, $bymonth[1]['calls']);

echo "ai_usage_group_key\n";
assert_eq('month from created_at', '2026-09', ai_usage_group_key(array('created_at' => '2026-09-15 12:00:00'), 'month'));
assert_eq('month blank', 'Unknown', ai_usage_group_key(array('created_at' => ''), 'month'));
assert_eq('missing field placeholder', '(none)', ai_usage_group_key(array(), 'model'));

echo "ai_usage_fmt\n";
assert_eq('usd fmt', '$0.0123', ai_usage_fmt_usd(0.012345));
assert_eq('usd fmt 2dp', '$1.50', ai_usage_fmt_usd(1.5, 2));
assert_eq('int fmt', '12,345', ai_usage_fmt_int(12345));

echo "\nAll AiUsageHelper tests passed.\n";
