<?php
/**
 * Run with: php tests/helpers/CostingSortOrderTest.php
 *
 * Locks costing_stamp_sort_order() — the pure helper that stamps a sequential
 * display order (0,1,2…) onto an ordered list of cost rows. The input order IS
 * the intended display order (it mirrors the on-screen / posted row order after a
 * drag-and-drop rearrange on the Costing template), so each row gets sort_order
 * matching its position. The model reads rows back ORDER BY sort_order, so this is
 * the contract that makes a drag rearrange persist on reopen.
 *
 * Bug this guards: before an explicit order column, rows displayed in incidental
 * auto-increment id order and could not be rearranged.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

require_once __DIR__ . '/../../application/helpers/costing_calc_helper.php';

$assertions = [];

/* 1) Sequential stamping from input order */
$rows = [
    ['name' => 'Tipping'],
    ['name' => 'Add on tour'],
    ['name' => 'Extra Bed'],
];
$stamped = costing_stamp_sort_order($rows);
$assertions['stamp: count unchanged']        = (count($stamped) === 3);
$assertions['stamp: first  -> sort_order 0'] = ($stamped[0]['sort_order'] === 0);
$assertions['stamp: second -> sort_order 1'] = ($stamped[1]['sort_order'] === 1);
$assertions['stamp: third  -> sort_order 2'] = ($stamped[2]['sort_order'] === 2);
$assertions['stamp: keeps existing keys']    = ($stamped[0]['name'] === 'Tipping' && $stamped[2]['name'] === 'Extra Bed');

/* 2) Re-ordered input yields the new order (the drag-persist contract) */
$reordered = [
    ['name' => 'Extra Bed'],
    ['name' => 'Tipping'],
    ['name' => 'Add on tour'],
];
$stamped2 = costing_stamp_sort_order($reordered);
$assertions['reorder: Extra Bed now 0']   = ($stamped2[0]['name'] === 'Extra Bed' && $stamped2[0]['sort_order'] === 0);
$assertions['reorder: Tipping now 1']     = ($stamped2[1]['name'] === 'Tipping' && $stamped2[1]['sort_order'] === 1);
$assertions['reorder: Add on tour now 2'] = ($stamped2[2]['name'] === 'Add on tour' && $stamped2[2]['sort_order'] === 2);

/* 3) Empty / non-array input is safe */
$assertions['empty: [] -> []']       = (costing_stamp_sort_order([]) === []);
$assertions['empty: non-array -> []'] = (costing_stamp_sort_order(null) === []);

/* 4) Overwrites any stale sort_order carried in the input */
$stale = [
    ['name' => 'A', 'sort_order' => 99],
    ['name' => 'B', 'sort_order' => 7],
];
$fresh = costing_stamp_sort_order($stale);
$assertions['stale: re-stamps to position'] = ($fresh[0]['sort_order'] === 0 && $fresh[1]['sort_order'] === 1);

/* 5) SOURCE CONTRACT — helper defines it; model stamps + reads by it */
$helper = @file_get_contents(__DIR__ . '/../../application/helpers/costing_calc_helper.php');
$assertions['helper: defines costing_stamp_sort_order()'] =
    (bool) preg_match('/function\s+costing_stamp_sort_order\s*\(/', (string) $helper);

$model = @file_get_contents(__DIR__ . '/../../application/models/Costing_Model.php');
$assertions['model: stamps order in Normalize_Booking_Rows'] =
    (strpos((string) $model, 'costing_stamp_sort_order(') !== false);
$assertions['model: reads rows ORDER BY sort_order'] =
    (strpos((string) $model, "costing_booking_items.sort_order") !== false);

/* ------------------------------------------------------------------ */

$fail = 0;
foreach ($assertions as $label => $ok) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
}
echo "\n" . (count($assertions) - $fail) . '/' . count($assertions) . " passed\n";
exit($fail === 0 ? 0 : 1);
