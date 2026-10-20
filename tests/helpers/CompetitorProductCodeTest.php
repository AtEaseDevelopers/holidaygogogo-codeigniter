<?php
/**
 * Run with: php tests/helpers/CompetitorProductCodeTest.php
 *
 * Locks competitor_product_code() — the pure per-product code derived from a
 * saved competitor_analyses row id. Guards against the old "product number" bug
 * where the exported/on-screen number was a throwaway row position (not unique,
 * not tied to the product). The code must be:
 *   - feature-prefixed (competitor -> CP, our_product -> OP, unknown -> CP)
 *   - the id zero-padded to 6 digits, but never truncated when wider
 *   - unsuffixed for a single-product analysis, 1-based suffixed when the
 *     analysis expands to several products
 *   - '' for a non-positive id (unanalysed row has no code)
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
        echo "      expected: " . json_encode($expected) . "\n";
        echo "      actual:   " . json_encode($actual) . "\n";
    }
}

// ---- prefix + zero-padding --------------------------------------------------
check('competitor prefix + 6-digit pad', 'CP-000123', competitor_product_code(123, 'competitor'));
check('our_product prefix',              'OP-000123', competitor_product_code(123, 'our_product'));
check('unknown feature defaults to CP',  'CP-000007', competitor_product_code(7, ''));
check('default feature is competitor',   'CP-000007', competitor_product_code(7));
check('id wider than 6 digits not truncated', 'CP-1234567', competitor_product_code(1234567, 'competitor'));

// ---- single vs multi-product suffix ----------------------------------------
check('single product -> no suffix',     'CP-000123', competitor_product_code(123, 'competitor', 1, 1));
check('multi product suffix 1-based #1', 'CP-000123-1', competitor_product_code(123, 'competitor', 1, 3));
check('multi product suffix 1-based #2', 'CP-000123-2', competitor_product_code(123, 'competitor', 2, 3));
check('our_product multi suffix',        'OP-000055-4', competitor_product_code(55, 'our_product', 4, 4));
check('total<=1 ignores a passed index', 'CP-000123', competitor_product_code(123, 'competitor', 1, 1));
check('total 0 treated as single',       'CP-000123', competitor_product_code(123, 'competitor', 2, 0));

// ---- guard: unanalysed / invalid id ----------------------------------------
check('zero id -> empty',                '', competitor_product_code(0, 'competitor'));
check('negative id -> empty',            '', competitor_product_code(-5, 'our_product', 1, 2));
check('numeric-string id accepted',      'CP-000042', competitor_product_code('42', 'competitor'));

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
