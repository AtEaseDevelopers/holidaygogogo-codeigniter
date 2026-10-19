<?php
/**
 * Run with: php tests/helpers/CostingTourCodeHelperTest.php
 *
 * Locks the pure costing tour-code generator (no DB / no HTTP / no date funcs).
 * Format: QU-YYMM-XXXX, sequence restarts each YYMM period.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

require_once __DIR__ . '/../../application/helpers/costing_tour_code_helper.php';

$assertions = [];

/* 1) FORMAT ------------------------------------------------------- */
$assertions['format: pads to 4 digits'] = (costing_tour_code_format('2608', 3) === 'QU-2608-0003');
$assertions['format: keeps 4-digit seq'] = (costing_tour_code_format('2608', 1234) === 'QU-2608-1234');
$assertions['format: 5-digit seq overflows pad'] = (costing_tour_code_format('2608', 12345) === 'QU-2608-12345');
$assertions['format: negative -> zero'] = (costing_tour_code_format('2608', -5) === 'QU-2608-0000');
$assertions['prefix: QU'] = (costing_tour_code_prefix() === 'QU');

/* 2) SEQUENCE PARSE ---------------------------------------------- */
$assertions['seq: reads its own format'] = (costing_tour_code_sequence('QU-2608-0007', '2608') === 7);
$assertions['seq: other period -> 0'] = (costing_tour_code_sequence('QU-2607-0007', '2608') === 0);
$assertions['seq: blank -> 0'] = (costing_tour_code_sequence('', '2608') === 0);
$assertions['seq: hand-typed non-numeric tail -> 0'] = (costing_tour_code_sequence('QU-2608-ABC', '2608') === 0);
$assertions['seq: unrelated code -> 0'] = (costing_tour_code_sequence('TOUR-123', '2608') === 0);
$assertions['seq: case-insensitive prefix'] = (costing_tour_code_sequence('qu-2608-0009', '2608') === 9);
$assertions['seq: trims whitespace'] = (costing_tour_code_sequence('  QU-2608-0004  ', '2608') === 4);

/* 3) NEXT --------------------------------------------------------- */
$assertions['next: empty -> 0001'] = (costing_tour_code_next([], '2608') === 'QU-2608-0001');
$assertions['next: max+1'] = (costing_tour_code_next(['QU-2608-0001', 'QU-2608-0005', 'QU-2608-0003'], '2608') === 'QU-2608-0006');
$assertions['next: ignores other periods'] = (costing_tour_code_next(['QU-2607-0009', 'QU-2608-0002'], '2608') === 'QU-2608-0003');
$assertions['next: ignores hand-typed'] = (costing_tour_code_next(['CUSTOM', 'QU-2608-0002'], '2608') === 'QU-2608-0003');
$assertions['next: all foreign -> 0001'] = (costing_tour_code_next(['QU-2607-0009', 'CUSTOM'], '2608') === 'QU-2608-0001');

/* 4) REVISION SUFFIX --------------------------------------------- */
$assertions['rev: formats REV01'] = (costing_tour_code_revision('QU-2610-0008', 1) === 'QU-2610-0008-REV01');
$assertions['rev: zero-pads single digit'] = (costing_tour_code_revision('QU-2610-0008', 9) === 'QU-2610-0008-REV09');
$assertions['rev: keeps two digits'] = (costing_tour_code_revision('QU-2610-0008', 10) === 'QU-2610-0008-REV10');
$assertions['rev: three digits overflow pad'] = (costing_tour_code_revision('QU-2610-0008', 123) === 'QU-2610-0008-REV123');
$assertions['rev: revision 0 -> base unchanged'] = (costing_tour_code_revision('QU-2610-0008', 0) === 'QU-2610-0008');
$assertions['rev: negative -> base unchanged'] = (costing_tour_code_revision('QU-2610-0008', -3) === 'QU-2610-0008');

$assertions['base: strips suffix'] = (costing_tour_code_base('QU-2610-0008-REV02') === 'QU-2610-0008');
$assertions['base: idempotent without suffix'] = (costing_tour_code_base('QU-2610-0008') === 'QU-2610-0008');
$assertions['base: case-insensitive suffix'] = (costing_tour_code_base('QU-2610-0008-rev07') === 'QU-2610-0008');
$assertions['base: only strips trailing'] = (costing_tour_code_base('QU-2610-0008-REV02 ') === 'QU-2610-0008');

$assertions['revnum: parses REV02'] = (costing_tour_code_revision_number('QU-2610-0008-REV02') === 2);
$assertions['revnum: absent -> 0'] = (costing_tour_code_revision_number('QU-2610-0008') === 0);
$assertions['revnum: case-insensitive'] = (costing_tour_code_revision_number('QU-2610-0008-rev11') === 11);
$assertions['revnum: non-numeric tail -> 0'] = (costing_tour_code_revision_number('QU-2610-0008-REVX') === 0);

$assertions['next-rev: no family -> 1'] = (costing_tour_code_next_revision(['QU-2610-0008'], 'QU-2610-0008') === 1);
$assertions['next-rev: max+1'] = (costing_tour_code_next_revision(['QU-2610-0008', 'QU-2610-0008-REV01', 'QU-2610-0008-REV02'], 'QU-2610-0008') === 3);
$assertions['next-rev: ignores other base'] = (costing_tour_code_next_revision(['QU-2610-0009-REV05', 'QU-2610-0008-REV01'], 'QU-2610-0008') === 2);
$assertions['next-rev: empty -> 1'] = (costing_tour_code_next_revision([], 'QU-2610-0008') === 1);

/* a -REVnn code must NOT disturb the monthly sequence parser */
$assertions['seq: ignores REV suffix'] = (costing_tour_code_sequence('QU-2608-0007-REV01', '2608') === 0);
$assertions['next: ignores REV codes'] = (costing_tour_code_next(['QU-2608-0002', 'QU-2608-0002-REV01'], '2608') === 'QU-2608-0003');

/* 5) SOURCE CONTRACT --------------------------------------------- */
$helper = @file_get_contents(__DIR__ . '/../../application/helpers/costing_tour_code_helper.php');
foreach (['costing_tour_code_prefix', 'costing_tour_code_format', 'costing_tour_code_sequence', 'costing_tour_code_next', 'costing_tour_code_revision', 'costing_tour_code_base', 'costing_tour_code_revision_number', 'costing_tour_code_next_revision'] as $fn) {
    $assertions["helper: defines {$fn}()"] = (bool) preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', (string) $helper);
}

$model = @file_get_contents(__DIR__ . '/../../application/models/Costing_Model.php');
$assertions['model: loads the tour-code helper'] = (strpos((string) $model, 'costing_tour_code') !== false);

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
