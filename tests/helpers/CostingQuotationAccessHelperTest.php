<?php
/**
 * Run with: php tests/helpers/CostingQuotationAccessHelperTest.php
 *
 * Pure access rules behind the PUBLIC customer Quotation PDF (Costing_Quotation):
 *
 *   - costing_quotation_token_is_valid(): the URL is addressed by the unguessable
 *     32-char md5 quotation_token, NOT the sequential package id, so a guessed/
 *     enumerated id (e.g. "15") must never be accepted as a token.
 *   - costing_quotation_status_accessible(): an 'inactive' package's quotation is
 *     no longer served (404); everything else stays accessible.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

require_once __DIR__ . '/../../application/helpers/costing_quotation_access_helper.php';

$assertions = array();

// --- token shape: exactly 32 lowercase hex -------------------------------------
$valid = str_repeat('a1b2', 8); // 32 hex chars
$assertions['token valid 32hex']   = costing_quotation_token_is_valid($valid) === true;
$assertions['token real md5']      = costing_quotation_token_is_valid(md5('booking-15')) === true;
$assertions['token trims']         = costing_quotation_token_is_valid('  ' . $valid . '  ') === true;
$assertions['token reject empty']  = costing_quotation_token_is_valid('') === false;
$assertions['token reject null']   = costing_quotation_token_is_valid(null) === false;
$assertions['token reject id']     = costing_quotation_token_is_valid('15') === false;
$assertions['token reject short']  = costing_quotation_token_is_valid(str_repeat('a', 31)) === false;
$assertions['token reject long']   = costing_quotation_token_is_valid(str_repeat('a', 33)) === false;
$assertions['token reject upper']  = costing_quotation_token_is_valid(strtoupper($valid)) === false;
$assertions['token reject nonhex'] = costing_quotation_token_is_valid(str_repeat('g', 32)) === false;

// --- status gate: only explicit 'inactive' blocks ------------------------------
$assertions['status active']        = costing_quotation_status_accessible('active') === true;
$assertions['status active pad']    = costing_quotation_status_accessible('  ACTIVE  ') === true;
$assertions['status unknown']       = costing_quotation_status_accessible('draft') === true;
$assertions['status blank']         = costing_quotation_status_accessible('') === true;
$assertions['status null']          = costing_quotation_status_accessible(null) === true;
$assertions['status inactive']      = costing_quotation_status_accessible('inactive') === false;
$assertions['status inactive case'] = costing_quotation_status_accessible('Inactive ') === false;

$failed = 0;
foreach ($assertions as $label => $ok) {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

echo PHP_EOL . ($failed === 0 ? "All assertions passed.\n" : "$failed assertion(s) failed.\n");
exit($failed === 0 ? 0 : 1);
