<?php
/**
 * Run with: php tests/helpers/CostingCalcHelperTest.php
 *
 * Locks the pure costing/quotation math (no DB / no HTTP / no date funcs, so it
 * runs on plain PHP + SQLite). These functions turn cost rows priced in any
 * currency + a frozen per-costing rate snapshot into MYR cost, then apply a
 * gross margin to show selling + profit. No manual selling price.
 *
 *   selling = cost / (1 - margin%/100)
 *   profit  = selling - cost
 *
 * Three parts:
 *   1. Pure math    : convert, sum-by-category, markup, snapshot build, normalize.
 *   2. End-to-end   : rows + rate_map + margin -> selling/profit hand-checked.
 *   3. Source contract: helper defines the functions; Costing wires it in.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

require_once __DIR__ . '/../../application/helpers/costing_calc_helper.php';

$assertions = [];
$approx = function ($a, $b) { return abs((float) $a - (float) $b) < 1e-6; };

/* ------------------------------------------------------------------ *
 * 0) CATEGORIES                                                       *
 * ------------------------------------------------------------------ */

$cats = costing_categories();
$assertions['cats: 5 fixed categories'] = (count($cats) === 5);
$assertions['cats: keys are the enum values'] = (
    isset($cats['flight'], $cats['accommodation'], $cats['other'], $cats['tour_leader'], $cats['miscellaneous'])
);
$assertions['cats: label is human readable'] = ($cats['tour_leader'] === 'Tour Leader');

/* ------------------------------------------------------------------ *
 * 1) LINE -> MYR                                                      *
 * ------------------------------------------------------------------ */

$rate_map = ['MYR' => 1.0, 'USD' => 4.5, 'SGD' => 3.5];

$assertions['line: USD 100 @4.5 -> 450'] =
    $approx(costing_line_to_myr(['currency_code' => 'USD', 'unit_cost' => 100, 'quantity' => 1], $rate_map), 450.00);
$assertions['line: MYR uses rate 1'] =
    $approx(costing_line_to_myr(['currency_code' => 'MYR', 'unit_cost' => 250, 'quantity' => 1], $rate_map), 250.00);
$assertions['line: quantity multiplies'] =
    $approx(costing_line_to_myr(['currency_code' => 'USD', 'unit_cost' => 100, 'quantity' => 2], $rate_map), 900.00);
$assertions['line: missing rate -> 0 (no crash)'] =
    $approx(costing_line_to_myr(['currency_code' => 'THB', 'unit_cost' => 100, 'quantity' => 1], $rate_map), 0.00);
$assertions['line: rounds to 2dp'] =
    $approx(costing_line_to_myr(['currency_code' => 'USD', 'unit_cost' => 10.005, 'quantity' => 1], ['USD' => 1.0]), 10.01);

/* ------------------------------------------------------------------ *
 * 2) SUM BY CATEGORY                                                  *
 * ------------------------------------------------------------------ */

$lines = [
    ['category' => 'flight',        'currency_code' => 'USD', 'unit_cost' => 100, 'quantity' => 1], // 450
    ['category' => 'flight',        'currency_code' => 'MYR', 'unit_cost' => 50,  'quantity' => 1], // 50
    ['category' => 'accommodation', 'currency_code' => 'SGD', 'unit_cost' => 100, 'quantity' => 1], // 350
    ['category' => 'tour_leader',   'currency_code' => 'MYR', 'unit_cost' => 200, 'quantity' => 1], // 200
];
$sum = costing_sum_by_category($lines, $rate_map);
$assertions['sum: flight = 500']          = $approx($sum['flight'], 500.00);
$assertions['sum: accommodation = 350']   = $approx($sum['accommodation'], 350.00);
$assertions['sum: tour_leader = 200']     = $approx($sum['tour_leader'], 200.00);
$assertions['sum: other = 0']             = $approx($sum['other'], 0.00);
$assertions['sum: miscellaneous = 0']     = $approx($sum['miscellaneous'], 0.00);
$assertions['sum: total = 1050']          = $approx($sum['total'], 1050.00);
$assertions['sum: empty -> total 0']      = $approx(costing_sum_by_category([], $rate_map)['total'], 0.00);
$assertions['sum: always has 5 cats + total'] = (count(costing_sum_by_category([], $rate_map)) === 6);

/* ------------------------------------------------------------------ *
 * 3) GROSS MARGIN (selling = cost / (1 - margin%))                     *
 * ------------------------------------------------------------------ */

$m = costing_apply_markup(1000, 20); // 1000 / 0.80 = 1250
$assertions['margin: 20% on 1000 -> selling 1250'] = $approx($m['selling'], 1250.00);
$assertions['margin: 20% on 1000 -> profit 250']   = $approx($m['profit'], 250.00);
$assertions['margin: keeps cost']                  = $approx($m['cost'], 1000.00);

$m0 = costing_apply_markup(1000, 0);
$assertions['margin: 0% -> selling == cost'] = $approx($m0['selling'], 1000.00);
$assertions['margin: 0% -> profit 0']        = $approx($m0['profit'], 0.00);

$m50 = costing_apply_markup(1000, 50); // 1000 / 0.50 = 2000
$assertions['margin: 50% -> selling 2x'] = $approx($m50['selling'], 2000.00);
$assertions['margin: 50% -> profit == cost'] = $approx($m50['profit'], 1000.00);

// 100%+ gross margin is undefined (infinite price) -> falls back to cost.
$m100 = costing_apply_markup(1000, 100);
$assertions['margin: 100% falls back to cost'] = $approx($m100['selling'], 1000.00);

$mf = costing_apply_markup(1000, 12.5); // 1000 / 0.875 = 1142.86
$assertions['margin: 12.5% -> selling 1142.86'] = $approx($mf['selling'], 1142.86);
$assertions['margin: 12.5% -> profit 142.86']   = $approx($mf['profit'], 142.86);

$mr = costing_apply_markup(333.33, 15); // 333.33 / 0.85 = 392.15
$assertions['margin: rounds selling to 2dp'] = $approx($mr['selling'], 392.15);
$assertions['margin: negative margin clamped to 0'] = $approx(costing_apply_markup(1000, -5)['selling'], 1000.00);

/* ------------------------------------------------------------------ *
 * 4) SNAPSHOT ROWS                                                    *
 * ------------------------------------------------------------------ */

// currency_lookup maps code -> currency_id (from the master); prefill maps code -> rate.
$currency_lookup = ['MYR' => 1, 'USD' => 2, 'SGD' => 3, 'THB' => 4];
$prefill = ['USD' => 4.7, 'MYR' => 999]; // MYR must be forced to 1 regardless
$snap_lines = [
    ['currency_code' => 'USD'],
    ['currency_code' => 'USD'],
    ['currency_code' => 'THB'],
    ['currency_code' => 'MYR'],
];
$rows = costing_build_snapshot_rows($snap_lines, $currency_lookup, $prefill);
$by_code = [];
foreach ($rows as $r) { $by_code[$r['currency_code']] = $r; }

$assertions['snapshot: one row per distinct currency'] = (count($rows) === 3);
$assertions['snapshot: USD prefilled from feed']       = $approx($by_code['USD']['rate_to_myr'], 4.7);
$assertions['snapshot: MYR forced to 1.0']             = $approx($by_code['MYR']['rate_to_myr'], 1.0);
$assertions['snapshot: unknown -> 0 (manual entry)']   = $approx($by_code['THB']['rate_to_myr'], 0.0);
$assertions['snapshot: carries currency_id']           = ((int) $by_code['USD']['currency_id'] === 2);
$assertions['snapshot: carries empty remark']          = (array_key_exists('remark', $by_code['USD']) && $by_code['USD']['remark'] === '');

/* ------------------------------------------------------------------ *
 * 5) NORMALIZE RATE                                                   *
 * ------------------------------------------------------------------ */

$assertions['normalize: MYR always 1.0']       = $approx(costing_normalize_rate('MYR', 999), 1.0);
$assertions['normalize: negative -> 0']        = $approx(costing_normalize_rate('USD', -3), 0.0);
$assertions['normalize: passes positive rate'] = $approx(costing_normalize_rate('usd', 4.5), 4.5);
$assertions['normalize: clamps to 8dp']        = $approx(costing_normalize_rate('USD', 4.123456789), 4.12345679);

/* ------------------------------------------------------------------ *
 * 6) END-TO-END                                                      *
 * ------------------------------------------------------------------ */

// Rows: USD 200 (flight) + SGD 100 (accommodation) + MYR 50 (misc), 10% margin.
// Cost = 200*4.5 + 100*3.5 + 50 = 900 + 350 + 50 = 1300; selling = 1300/0.9 = 1444.44.
$e_lines = [
    ['category' => 'flight',        'currency_code' => 'USD', 'unit_cost' => 200, 'quantity' => 1],
    ['category' => 'accommodation', 'currency_code' => 'SGD', 'unit_cost' => 100, 'quantity' => 1],
    ['category' => 'miscellaneous', 'currency_code' => 'MYR', 'unit_cost' => 50,  'quantity' => 1],
];
$e_sum = costing_sum_by_category($e_lines, $rate_map);
$e_fin = costing_apply_markup($e_sum['total'], 10); // 1300 / 0.90 = 1444.44
$assertions['e2e: cost 1300']       = $approx($e_fin['cost'], 1300.00);
$assertions['e2e: selling 1444.44'] = $approx($e_fin['selling'], 1444.44);
$assertions['e2e: profit 144.44']   = $approx($e_fin['profit'], 144.44);

/* ------------------------------------------------------------------ *
 * 6b) MULTIPLIER TYPES + ROW MYR (cost template)                      *
 * ------------------------------------------------------------------ */

$mult = costing_multiplier_types();
$assertions['mult: 4 types']              = (count($mult) === 4);
$assertions['mult: keys per_day/pax/fix'] = (isset($mult['per_day'], $mult['per_pax'], $mult['fixed']));
$assertions['mult: key per_day_pax']      = (isset($mult['per_day_pax']));
$assertions['mult: label per day']        = ($mult['per_day'] === 'Per Day');
$assertions['mult: label per day & pax']  = ($mult['per_day_pax'] === 'Per Day & Pax');

$assertions['mult: normalize unknown -> fixed'] = (costing_normalize_multiplier_type('bogus') === 'fixed');
$assertions['mult: normalize trims/cases']      = (costing_normalize_multiplier_type(' Per_Pax ') === 'per_pax');
$assertions['mult: normalize per_day_pax']      = (costing_normalize_multiplier_type(' Per_Day_Pax ') === 'per_day_pax');

$assertions['mult: per_day uses duration'] = (costing_multiplier_count('per_day', 5, 20) === 5);
$assertions['mult: per_pax uses pax']      = (costing_multiplier_count('per_pax', 5, 20) === 20);
$assertions['mult: per_day_pax = day*pax'] = (costing_multiplier_count('per_day_pax', 5, 20) === 100);
$assertions['mult: fixed is 1']            = (costing_multiplier_count('fixed', 5, 20) === 1);
$assertions['mult: negative clamped to 0'] = (costing_multiplier_count('per_day', -3, 20) === 0);
$assertions['mult: day*pax neg day -> 0']  = (costing_multiplier_count('per_day_pax', -3, 20) === 0);

// Image case: USD 30 @ rate 4 (no bank charge) -> 120/unit, No of Day 5 -> 600.
$img = costing_row_myr(30, 4.0, 0, 5);
$assertions['row: USD30@4 -> 120/unit'] = $approx($img['myr_per_unit'], 120.00);
$assertions['row: 120 x 5 days -> 600'] = $approx($img['total_myr'], 600.00);

// Bank charge is a per-line fee: applied ONCE to the line total (on the total
// unit/amount), NOT baked into every per-unit MYR and multiplied by the count.
// The per-unit "MYR (convert)" is the pure foreign->MYR conversion.
//   myr_per_unit = round(cost * rate, 2)
//   total_myr    = round(myr_per_unit * count, 2) + bank   (bank once)
$bc = costing_row_myr(100, 4.5, 10, 3);
$assertions['row: 100@4.5 -> 450/unit (pure, no bank)'] = $approx($bc['myr_per_unit'], 450.00);
$assertions['row: 450 x 3 + 10 bank once -> 1360']      = $approx($bc['total_myr'], 1360.00);

$assertions['row: fixed count 1 + bank once'] = $approx(costing_row_myr(100, 4.5, 10, 1)['total_myr'], 460.00);
$assertions['row: zero count -> 0 total (no bank)'] = $approx(costing_row_myr(100, 4.5, 10, 0)['total_myr'], 0.00);
$assertions['row: negative rate -> 0 rate'] = $approx(costing_row_myr(100, -4.5, 0, 1)['total_myr'], 0.00);
$assertions['row: bank not in per-unit'] = $approx(costing_row_myr(100, 4.5, -10, 1)['myr_per_unit'], 450.00);
$assertions['row: rounds per-unit 2dp']   = $approx(costing_row_myr(10.005, 1.0, 0, 1)['myr_per_unit'], 10.01);

// costing_row_totals: a frozen per-unit MYR (the pure conversion saved on the row)
// WINS and is never recomputed from the rate — only the line total is derived from
// it, with the bank charge added ONCE. This is what freezing into a stored column
// buys: the "MYR (convert)" value can't drift when the master rate moves, yet the
// bank charge stays a single per-line fee.
$frozen = costing_row_totals(450.00, 100, 4.5, 10, 3);
$assertions['totals: frozen per-unit wins']    = $approx($frozen['myr_per_unit'], 450.00);
$assertions['totals: frozen x count + bank once -> total'] = $approx($frozen['total_myr'], 1360.00);
$moved = costing_row_totals(450.00, 100, 9.9, 10, 2); // master rate changed after save
$assertions['totals: frozen ignores moved rate']       = $approx($moved['myr_per_unit'], 450.00);
$assertions['totals: frozen ignores moved rate total'] = $approx($moved['total_myr'], 910.00);
// Legacy rows (no frozen value) fall back to converting from rate + bank charge.
$legacy = costing_row_totals(null, 100, 4.5, 10, 3);
$assertions['totals: null -> computes like row_myr'] = $approx($legacy['myr_per_unit'], 450.00);
$assertions['totals: null legacy total']             = $approx($legacy['total_myr'], 1360.00);
$assertions['totals: empty string -> legacy']        = $approx(costing_row_totals('', 30, 4.0, 0, 5)['total_myr'], 600.00);
$assertions['totals: frozen 0 stays 0']              = $approx(costing_row_totals(0.0, 100, 4.5, 10, 3)['myr_per_unit'], 0.00);
$assertions['totals: frozen 0 -> 0 total (no bank)'] = $approx(costing_row_totals(0.0, 100, 4.5, 10, 3)['total_myr'], 0.00);
$assertions['totals: negative frozen clamped']       = $approx(costing_row_totals(-5, 100, 4.5, 10, 3)['myr_per_unit'], 0.00);

/* ------------------------------------------------------------------ *
 * 6b) CURRENCY BREAKDOWN (rate + total per currency)                  *
 * ------------------------------------------------------------------ */

// Rate map is keyed by currency_id like the cost step's $currency_rate_map.
$bd_map = [
    1 => ['code' => 'MYR', 'rate_to_myr' => 1.0, 'bank_charges_myr' => 0.0],
    2 => ['code' => 'USD', 'rate_to_myr' => 4.5, 'bank_charges_myr' => 10.0],
    3 => ['code' => 'THB', 'rate_to_myr' => 0.13, 'bank_charges_myr' => 0.0],
];
$bd_rows = [
    ['currency_id' => 2, 'unit_price' => 100, 'count' => 2, 'include' => 1], // USD 200 -> 450*2 + 10 bank once = 910
    ['currency_id' => 2, 'unit_price' => 50,  'count' => 1, 'include' => 1], // USD 50  -> 225*1 + 10 bank once = 235
    ['currency_id' => 3, 'unit_price' => 1000, 'count' => 1, 'include' => 1], // THB 1000 -> 130
    ['currency_id' => 1, 'unit_price' => 300, 'count' => 1, 'include' => 1], // MYR 300 -> 300
    ['currency_id' => 2, 'unit_price' => 999, 'count' => 9, 'include' => 0], // excluded
];
$bd = costing_currency_breakdown($bd_rows, $bd_map);
$by = [];
foreach ($bd as $e) { $by[$e['code']] = $e; }

$assertions['breakdown: one entry per used currency'] = (count($bd) === 3);
$assertions['breakdown: ordered by code (MYR,THB,USD)'] =
    ($bd[0]['code'] === 'MYR' && $bd[1]['code'] === 'THB' && $bd[2]['code'] === 'USD');
$assertions['breakdown: USD total_foreign summed'] = $approx($by['USD']['total_foreign'], 250.00);
$assertions['breakdown: USD total_myr summed (bank once per line)'] = $approx($by['USD']['total_myr'], 1145.00);
$assertions['breakdown: USD rate carried'] = $approx($by['USD']['rate_to_myr'], 4.5);
$assertions['breakdown: USD bank charge carried'] = $approx($by['USD']['bank_charges_myr'], 10.0);
$assertions['breakdown: THB total_foreign'] = $approx($by['THB']['total_foreign'], 1000.00);
$assertions['breakdown: THB total_myr'] = $approx($by['THB']['total_myr'], 130.00);
$assertions['breakdown: MYR rate 1, foreign == myr'] =
    ($approx($by['MYR']['rate_to_myr'], 1.0) && $approx($by['MYR']['total_foreign'], 300.00) && $approx($by['MYR']['total_myr'], 300.00));
$assertions['breakdown: excluded rows skipped'] = ($approx($by['USD']['total_foreign'], 250.00));
$assertions['breakdown: empty rows -> empty list'] = (costing_currency_breakdown([], $bd_map) === []);
// A row missing its rate (unknown currency) still lists, rate 0, myr 0.
$bd_missing = costing_currency_breakdown([['currency_id' => 99, 'unit_price' => 100, 'count' => 1]], $bd_map);
$assertions['breakdown: unknown currency -> rate 0'] = ($approx($bd_missing[0]['rate_to_myr'], 0.0) && $approx($bd_missing[0]['total_myr'], 0.0) && $approx($bd_missing[0]['total_foreign'], 100.00));

/* ------------------------------------------------------------------ *
 * 6b) COMBINATION SUMMARY (customer bundles, additive)                *
 * ------------------------------------------------------------------ */

// Gross margin: cost after markup = cost / (1 - margin/100).
$assertions['after markup: 20% on 1000 -> 1250'] = $approx(costing_cost_after_markup(1000, 20), 1250.00);
$assertions['after markup: 0% keeps cost'] = $approx(costing_cost_after_markup(1000, 0), 1000.00);
$assertions['after markup: negative keeps cost'] = $approx(costing_cost_after_markup(1000, -5), 1000.00);
$assertions['after markup: 50% doubles'] = $approx(costing_cost_after_markup(1000, 50), 2000.00);
$assertions['after markup: 100% falls back to cost'] = $approx(costing_cost_after_markup(1000, 100), 1000.00);
$assertions['after markup: 12.5% -> 1142.86'] = $approx(costing_cost_after_markup(1000, 12.5), 1142.86);

$combos_in = [
    ['name' => 'Premium',  'item_names' => ['Business Flight', '5-Star Hotel'], 'cost_myr' => 4000.00],
    ['name' => 'Standard', 'item_names' => ['Economy Flight', '4-Star Hotel'],  'cost_myr' => 2500.00],
];
$cs = costing_combination_summary($combos_in, 20); // 20% gross margin, no pax

$assertions['combo: one entry per combination'] = (count($cs['combinations']) === 2);
$assertions['combo: name carried'] = ($cs['combinations'][0]['name'] === 'Premium');
$assertions['combo: item names carried'] = ($cs['combinations'][0]['item_names'] === ['Business Flight', '5-Star Hotel']);
$assertions['combo: selling = cost / (1 - margin)'] = $approx($cs['combinations'][0]['selling'], 5000.00); // 4000/0.8
$assertions['combo: second selling'] = $approx($cs['combinations'][1]['selling'], 3125.00); // 2500/0.8
$assertions['combo: total_cost = sum of costs'] = $approx($cs['total_cost'], 6500.00);
$assertions['combo: total_selling additive'] = $approx($cs['total_selling'], 8125.00);

// Per-pax pricing: cost/pax grossed up, then × pax. 4000 cost, 4 pax, 20% ->
// cost/pax 1000 -> after markup 1250 -> profit/pax 250 -> selling 5000.
$csp = costing_combination_summary([['name' => 'P', 'item_names' => [], 'cost_myr' => 4000.00]], 20, 4);
$assertions['combo: cost per pax'] = $approx($csp['combinations'][0]['cost_per_pax'], 1000.00);
$assertions['combo: cost after markup per pax'] = $approx($csp['combinations'][0]['cost_after_markup_per_pax'], 1250.00);
$assertions['combo: selling price per pax defaults to markup'] = $approx($csp['combinations'][0]['selling_price_per_pax'], 1250.00);
$assertions['combo: profit per pax'] = $approx($csp['combinations'][0]['profit_per_pax'], 250.00);
$assertions['combo: selling = price/pax x pax'] = $approx($csp['combinations'][0]['selling'], 5000.00);
$assertions['combo: total profit'] = $approx($csp['combinations'][0]['total_profit'], 1000.00);

// Manual selling price per pax overrides the suggested markup (authoritative).
$csm = costing_combination_summary([['name' => 'M', 'item_names' => [], 'cost_myr' => 4000.00, 'selling_price_per_pax' => 1500.00]], 20, 4);
$assertions['combo: manual selling wins'] = $approx($csm['combinations'][0]['selling_price_per_pax'], 1500.00);
$assertions['combo: manual selling drives revenue'] = $approx($csm['combinations'][0]['selling'], 6000.00);
$assertions['combo: manual selling drives profit/pax'] = $approx($csm['combinations'][0]['profit_per_pax'], 500.00);

// Zero margin -> selling == cost.
$cs0 = costing_combination_summary([['name' => 'A', 'item_names' => ['x'], 'cost_myr' => 1000]], 0);
$assertions['combo: zero margin keeps cost'] = $approx($cs0['combinations'][0]['selling'], 1000.00);
// Negative margin clamped to 0.
$csn = costing_combination_summary([['name' => 'A', 'item_names' => [], 'cost_myr' => 1000]], -5);
$assertions['combo: negative margin clamped'] = $approx($csn['total_selling'], 1000.00);
// Blank item names filtered; missing keys tolerated.
$csb = costing_combination_summary([['name' => 'A', 'item_names' => ['x', '  ', ''], 'cost_myr' => 100]], 10);
$assertions['combo: blank item names dropped'] = ($csb['combinations'][0]['item_names'] === ['x']);
$assertions['combo: empty input -> zero totals'] = (
    costing_combination_summary([], 20) === ['combinations' => [], 'total_cost' => 0.0, 'total_selling' => 0.0]
);
// Rounds to 2dp.
$csr = costing_combination_summary([['name' => 'A', 'item_names' => [], 'cost_myr' => 33.335]], 0);
$assertions['combo: cost rounds to 2dp'] = $approx($csr['combinations'][0]['cost_myr'], 33.34);

/* ------------------------------------------------------------------ *
 * 6c) GENERAL COMBINATION (common items folded into every combo)      *
 * ------------------------------------------------------------------ */

$gc_in = [
    ['name' => 'General',  'cost_myr' => 500.0, 'is_general' => 1],
    ['name' => 'Premium',  'cost_myr' => 4000.0, 'selling_price_per_pax' => 1234.0],
    ['name' => 'Standard', 'cost_myr' => 2500.0],
];
$gc = costing_fold_general_combination($gc_in);
$assertions['general: drops the general combo']   = (count($gc) === 2);
$assertions['general: first is Premium']          = ($gc[0]['name'] === 'Premium');
$assertions['general: folds into first']          = $approx($gc[0]['cost_myr'], 4500.0);
$assertions['general: folds into second']         = $approx($gc[1]['cost_myr'], 3000.0);
$assertions['general: preserves other keys']      = $approx($gc[0]['selling_price_per_pax'], 1234.0);
// No general combo -> unchanged costs, same count.
$gc_none = costing_fold_general_combination([['name' => 'A', 'cost_myr' => 100.0], ['name' => 'B', 'cost_myr' => 200.0]]);
$assertions['general: none -> unchanged count']   = (count($gc_none) === 2);
$assertions['general: none -> unchanged cost']    = $approx($gc_none[0]['cost_myr'], 100.0);
// Only a general combo -> nothing left to sell.
$assertions['general: only general -> empty']     = (costing_fold_general_combination([['name' => 'G', 'cost_myr' => 900.0, 'is_general' => 1]]) === []);
// Multiple general combos sum.
$gc_multi = costing_fold_general_combination([
    ['cost_myr' => 100.0, 'is_general' => 1],
    ['cost_myr' => 50.0, 'is_general' => 1],
    ['name' => 'X', 'cost_myr' => 1000.0],
]);
$assertions['general: multiple general sum']      = $approx($gc_multi[0]['cost_myr'], 1150.0);

/* ------------------------------------------------------------------ *
 * 7) SOURCE CONTRACT                                                  *
 * ------------------------------------------------------------------ */

$helper = @file_get_contents(__DIR__ . '/../../application/helpers/costing_calc_helper.php');
foreach (['costing_categories', 'costing_line_to_myr', 'costing_sum_by_category', 'costing_apply_markup', 'costing_cost_after_markup', 'costing_build_snapshot_rows', 'costing_normalize_rate', 'costing_multiplier_types', 'costing_multiplier_count', 'costing_row_myr', 'costing_row_totals', 'costing_currency_breakdown', 'costing_combination_summary', 'costing_fold_general_combination'] as $fn) {
    $assertions["helper: defines {$fn}()"] = (bool) preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', (string) $helper);
}

$model = @file_get_contents(__DIR__ . '/../../application/models/Costing_Model.php');
$assertions['model: loads the calc helper'] = (strpos((string) $model, 'costing_calc') !== false);

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
