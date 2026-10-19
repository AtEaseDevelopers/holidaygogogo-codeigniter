<?php
/**
 * Run with: php tests/helpers/CostingPackageRevisionTest.php
 *
 * Locks the pure dashboard grouping of costing packages into revision families
 * (no DB / no HTTP). One visible row per family = its latest revision, with the full
 * member history attached under ['revisions'] (highest revision first).
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

require_once __DIR__ . '/../../application/helpers/costing_package_revision_helper.php';

$assertions = array();

function row($id, $root, $rev, $code, $extra = array())
{
    return array_merge(array(
        'id' => $id,
        'revision_root_id' => $root,
        'revision_number' => $rev,
        'tour_code' => $code,
        'status' => 'active',
        'latest_quotation_token' => null,
        'updated_at' => '2026-10-19 10:00:00',
    ), $extra);
}

/* 1) A lone base package is its own single-member family. */
$g = costing_group_package_revisions(array(row(5, null, 0, 'QU-2610-0005')));
$assertions['lone: one family row'] = (count($g) === 1);
$assertions['lone: history size 1'] = (count($g[0]['revisions']) === 1);
$assertions['lone: visible is the base'] = ((int) $g[0]['id'] === 5 && (int) $g[0]['revision_number'] === 0);

/* 2) A base + two revisions collapse to one row showing the latest (REV02). Input is
      deliberately out of order to prove latest-wins, not last-seen. */
$input = array(
    row(5, null, 0, 'QU-2610-0005'),
    row(9, 5, 2, 'QU-2610-0005-REV02'),
    row(7, 5, 1, 'QU-2610-0005-REV01'),
);
$g = costing_group_package_revisions($input);
$assertions['family: collapses to one row'] = (count($g) === 1);
$assertions['family: visible row is latest (REV02)'] = ((int) $g[0]['id'] === 9 && (int) $g[0]['revision_number'] === 2);
$assertions['family: history has all 3 members'] = (count($g[0]['revisions']) === 3);
$assertions['family: history sorted latest-first'] = (
    (int) $g[0]['revisions'][0]['revision_number'] === 2
    && (int) $g[0]['revisions'][1]['revision_number'] === 1
    && (int) $g[0]['revisions'][2]['revision_number'] === 0
);

/* 3) Revision clicked before its base still groups correctly (family keyed by root). */
$g = costing_group_package_revisions(array(
    row(9, 5, 2, 'QU-2610-0005-REV02'),
    row(5, null, 0, 'QU-2610-0005'),
));
$assertions['out-of-order: one family'] = (count($g) === 1);
$assertions['out-of-order: latest still wins'] = ((int) $g[0]['id'] === 9);

/* 4) Two independent families stay two rows, in first-seen order. */
$g = costing_group_package_revisions(array(
    row(9, 5, 2, 'QU-2610-0005-REV02'),
    row(5, null, 0, 'QU-2610-0005'),
    row(12, null, 0, 'QU-2610-0012'),
));
$assertions['multi: two family rows'] = (count($g) === 2);
$assertions['multi: order preserved (family 5 first)'] = ((int) $g[0]['revisions'][0]['revision_number'] === 2 && (int) $g[1]['id'] === 12);

/* 5) Revision entry carries token + status through to the history list. */
$g = costing_group_package_revisions(array(
    row(5, null, 0, 'QU-2610-0005', array('latest_quotation_token' => str_repeat('a', 32), 'status' => 'inactive')),
));
$assertions['entry: token passed through'] = ($g[0]['revisions'][0]['quotation_token'] === str_repeat('a', 32));
$assertions['entry: status passed through'] = ($g[0]['revisions'][0]['status'] === 'inactive');

/* 6) Empty input -> empty result. */
$assertions['empty: no rows'] = (costing_group_package_revisions(array()) === array());

/* ----------------------------------------------------------------- */
$fail = 0;
foreach ($assertions as $label => $ok) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
}
echo "\n" . (count($assertions) - $fail) . '/' . count($assertions) . " passed\n";
exit($fail === 0 ? 0 : 1);
