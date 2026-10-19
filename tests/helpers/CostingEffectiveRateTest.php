<?php
/**
 * Run with: php tests/helpers/CostingEffectiveRateTest.php
 *
 * Locks costing_effective_rate() - the pure unit behind the Costing Currency
 * "buffer" feature. A per-currency-rate buffer is ADDED on top of the stored
 * market rate so quoted foreign costs sit slightly above the raw exchange rate:
 *
 *     effective rate = round(rate + buffer, 8)
 *
 * Guards:
 *   - A zero/omitted buffer leaves the rate untouched (back-compat with every
 *     pre-buffer rate row, which defaults buffer to 0).
 *   - A buffer alone must NEVER fabricate a rate: rate <= 0 => 0, so a currency
 *     pair with no configured rate still converts to nothing.
 *   - The buffer is additive-only: a negative buffer is clamped to 0.
 *   - Result is rounded to 8dp (same precision as the stored DECIMAL(18,8) rate).
 *
 * Runs without a DB or framework bootstrap.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}
require_once __DIR__ . '/../../application/helpers/costing_calc_helper.php';

$failures = 0;
function near($label, $expected, $actual, $eps = 1e-9) {
    global $failures;
    if (abs((float) $expected - (float) $actual) < $eps) {
        echo "PASS  {$label}\n";
    } else {
        $failures++;
        echo "FAIL  {$label}\n";
        echo "      expected: {$expected}\n";
        echo "      actual:   {$actual}\n";
    }
}

// Core: effective = rate + buffer.
near('rate + buffer', 4.82, costing_effective_rate(4.72, 0.10));
near('larger buffer', 5.00, costing_effective_rate(4.72, 0.28));

// Zero / omitted buffer leaves the rate unchanged (every legacy row defaults to 0).
near('zero buffer -> rate unchanged', 4.72, costing_effective_rate(4.72, 0));
near('buffer "0" string -> rate', 4.72, costing_effective_rate('4.72', '0'));

// A buffer alone must never fabricate a rate for a pair that has none.
near('rate 0 -> 0 regardless of buffer', 0.0, costing_effective_rate(0, 0.10));
near('negative rate -> 0', 0.0, costing_effective_rate(-1, 0.10));

// Buffer is additive-only: negatives are clamped to 0 (never discounts the rate).
near('negative buffer clamped to 0', 4.72, costing_effective_rate(4.72, -0.50));

// String inputs (POST / DB come through as strings) coerce cleanly.
near('string inputs coerced', 4.82, costing_effective_rate('4.72000000', '0.10000000'));

// 8dp rounding, matching the stored DECIMAL(18,8).
near('rounds to 8dp', 1.23456790, costing_effective_rate(1.123456789, 0.111111111));

if ($failures === 0) {
    echo "\nAll CostingEffectiveRate assertions passed.\n";
    exit(0);
}
echo "\n{$failures} assertion(s) failed.\n";
exit(1);
