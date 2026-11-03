<?php
/**
 * Run with: php tests/helpers/CostingPackageSearchTest.php
 *
 * Locks the contract for the Costing Package listing's free-text Search box
 * (application/helpers/costing_package_search_helper.php ->
 * costing_package_search_clause), which Costing_Model::Build_Package_Filter_Where
 * uses to filter both the dashboard listing and the Excel export.
 *
 * Guards the two bugs reported 2026-11-03:
 *   1. "Mr. Anthony is within the list but can't be filtered" — Anthony is a
 *      customer_name, but the old search only matched name/tour_code/description,
 *      so the single Search box could never find him. The clause must now span
 *      the customer + sales-person columns too.
 *   2. "tune search by broad match, not exact match" — a multi-word search was
 *      one contiguous LIKE '%beijing anthony%'. It must now split into tokens
 *      (AND across tokens, OR across columns) so word order / adjacency no
 *      longer matter.
 *
 * Runs without a DB or framework bootstrap. The escaper is stubbed with a
 * plain quote-doubling so the emitted SQL is deterministic.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

require_once __DIR__ . '/../../application/helpers/costing_package_search_helper.php';

$failures = 0;
function check($label, $expected, $actual)
{
    global $failures;
    if ($expected === $actual) {
        echo "PASS  {$label}\n";
    } else {
        $failures++;
        echo "FAIL  {$label}\n";
        echo "        expected: " . var_export($expected, true) . "\n";
        echo "        actual:   " . var_export($actual, true) . "\n";
    }
}

// Mirror CI's escape_like_str well enough for assertions: escape % and _ and
// double single quotes. For plain alphanumerics it is an identity function.
$escaper = function ($value) {
    return str_replace(array("'", '%', '_'), array("''", '\\%', '\\_'), (string) $value);
};

$columns = array('cp.name', 'cp.tour_code', 'cp.customer_name', 'sales_admin.Name');

// --- empty / whitespace inputs return no clause ------------------------------
check('empty string -> no clause', '', costing_package_search_clause('', $columns, $escaper));
check('whitespace-only -> no clause', '', costing_package_search_clause("  \t ", $columns, $escaper));
check('no columns -> no clause', '', costing_package_search_clause('anthony', array(), $escaper));

// --- single token ORs across every column (the Anthony bug) ------------------
$single = costing_package_search_clause('anthony', $columns, $escaper);
check(
    'single token ORs across all columns incl. customer_name',
    "(cp.name LIKE '%anthony%' OR cp.tour_code LIKE '%anthony%' OR cp.customer_name LIKE '%anthony%' OR sales_admin.Name LIKE '%anthony%')",
    $single
);
// The whole point of bug #1: the customer column is in there.
check('Anthony clause reaches customer_name', true, strpos($single, "cp.customer_name LIKE '%anthony%'") !== false);

// --- multi-word is BROAD (token AND), not one contiguous LIKE (bug #2) -------
$multi = costing_package_search_clause('beijing anthony', $columns, $escaper);
check(
    'two tokens -> each parenthesised group ANDed together',
    "(cp.name LIKE '%beijing%' OR cp.tour_code LIKE '%beijing%' OR cp.customer_name LIKE '%beijing%' OR sales_admin.Name LIKE '%beijing%')"
        . " AND "
        . "(cp.name LIKE '%anthony%' OR cp.tour_code LIKE '%anthony%' OR cp.customer_name LIKE '%anthony%' OR sales_admin.Name LIKE '%anthony%')",
    $multi
);
check('broad match: no contiguous "beijing anthony" literal', false, strpos($multi, "'%beijing anthony%'") !== false);

// --- word order does not matter (same token set -> same groups) --------------
$ab = costing_package_search_clause('alpha beta', array('cp.name'), $escaper);
$ba = costing_package_search_clause('beta alpha', array('cp.name'), $escaper);
check(
    'order a b',
    "(cp.name LIKE '%alpha%') AND (cp.name LIKE '%beta%')",
    $ab
);
check(
    'order b a (mirror of a b)',
    "(cp.name LIKE '%beta%') AND (cp.name LIKE '%alpha%')",
    $ba
);

// --- collapses runs of internal whitespace into token boundaries -------------
check(
    'tabs / multiple spaces collapse to token split',
    "(cp.name LIKE '%a%') AND (cp.name LIKE '%b%')",
    costing_package_search_clause("  a \t  b  ", array('cp.name'), $escaper)
);

// --- the escaper is applied to each token ------------------------------------
check(
    'LIKE wildcards in the token are escaped via the callback',
    "(cp.name LIKE '%100\\%%')",
    costing_package_search_clause('100%', array('cp.name'), $escaper)
);
check(
    "single quote in token is escaped (SQL-literal safe)",
    "(cp.name LIKE '%O''Brien%')",
    costing_package_search_clause("O'Brien", array('cp.name'), $escaper)
);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "{$failures} FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
