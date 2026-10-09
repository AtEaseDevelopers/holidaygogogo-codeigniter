<?php
/**
 * Run with: php tests/helpers/TotpHelperTest.php
 *
 * Locks the TOTP (RFC 6238) admin-2FA helpers:
 *  - Base32 encode/decode round-trips and matches the known SHA1 test secret.
 *  - Code generation reproduces the RFC 6238 Appendix B published vectors
 *    (both the full 8-digit codes and the 6-digit truncation we use).
 *  - verify() tolerates ±1 step drift, rejects wrong/short codes, and BLOCKS
 *    replay via the after_step guard (the CVE-class case).
 *  - The otpauth:// provisioning URI carries a Base32 secret + issuer.
 *  - AES-256-GCM encrypt/decrypt round-trips and rejects tamper/wrong key.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}
require __DIR__ . '/../../application/helpers/totp_helper.php';

function assert_eq($label, $expected, $actual) {
    if ($expected === $actual) {
        echo "  PASS  {$label} = " . var_export($actual, true) . "\n";
    } else {
        echo "  FAIL  {$label}: expected " . var_export($expected, true)
           . ", got " . var_export($actual, true) . "\n";
        exit(1);
    }
}

function assert_true($label, $actual) {
    assert_eq($label, true, (bool) $actual);
}

// RFC 6238 Appendix B uses the ASCII seed "12345678901234567890" (20 bytes) for SHA1.
$seed = '12345678901234567890';

// ---- Base32 ----------------------------------------------------------------
assert_eq('base32 of RFC seed', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', totp_base32_encode($seed));
assert_eq('base32 round-trip', $seed, totp_base32_decode(totp_base32_encode($seed)));
assert_eq('base32 decode tolerates lowercase+spaces', $seed, totp_base32_decode(strtolower(totp_base32_encode($seed))));
assert_eq('base32 empty', '', totp_base32_encode(''));

// ---- RFC 6238 Appendix B vectors (SHA1), 8-digit then our 6-digit ----------
$vectors = array(
    59          => '94287082',
    1111111109  => '07081804',
    1111111111  => '14050471',
    1234567890  => '89005924',
    2000000000  => '69279037',
);
foreach ($vectors as $ts => $code8) {
    assert_eq("code8 @ {$ts}", $code8, totp_code_at($seed, $ts, 30, 8));
    assert_eq("code6 @ {$ts}", substr($code8, -6), totp_code_at($seed, $ts, 30, 6));
}

// ---- verify: drift window --------------------------------------------------
$ts = 1111111109; // step 37037036
$good = totp_code_at($seed, $ts, 30, 6);
assert_eq('verify current step returns step', totp_time_step($ts, 30), totp_verify($seed, $good, $ts));
// code from the previous step still accepted ~29s later (window 1)
$prev = totp_code_at($seed, $ts - 30, 30, 6);
assert_eq('verify prev-step code within window', totp_time_step($ts - 30, 30), totp_verify($seed, $prev, $ts));
// two steps away is outside the default window
$far = totp_code_at($seed, $ts - 90, 30, 6);
assert_eq('verify rejects 3-steps-away code', false, totp_verify($seed, $far, $ts));

// ---- verify: bad input -----------------------------------------------------
assert_eq('verify rejects wrong code', false, totp_verify($seed, '000000', $ts));
assert_eq('verify rejects short code', false, totp_verify($seed, '1234', $ts));
assert_eq('verify rejects empty', false, totp_verify($seed, '', $ts));

// ---- verify: replay prevention (the important one) -------------------------
$step = totp_verify($seed, $good, $ts);
assert_true('first use accepted', $step !== false);
assert_eq('same code rejected once step consumed', false, totp_verify($seed, $good, $ts, 1, 30, 6, $step));
// a later step is still fine after consuming an earlier one
$later_ts = $ts + 30;
$later = totp_code_at($seed, $later_ts, 30, 6);
assert_eq('next step accepted after replay guard', totp_time_step($later_ts, 30), totp_verify($seed, $later, $later_ts, 1, 30, 6, $step));

// ---- provisioning URI ------------------------------------------------------
$uri = totp_provisioning_uri('HolidayGoGoGo', 'alice@example.com', $seed);
assert_true('uri scheme', strpos($uri, 'otpauth://totp/HolidayGoGoGo:alice%40example.com?') === 0);
assert_true('uri carries base32 secret', strpos($uri, 'secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ') !== false);
assert_true('uri carries issuer', strpos($uri, 'issuer=HolidayGoGoGo') !== false);

// ---- at-rest encryption ----------------------------------------------------
$key = 'test-key-from-env-0123456789';
$secret = totp_generate_secret();
assert_eq('secret is 20 bytes', 20, strlen($secret));
$token = totp_encrypt($secret, $key);
assert_true('encrypt produced a token', $token !== '');
assert_eq('decrypt round-trips', $secret, totp_decrypt($token, $key));
assert_eq('decrypt wrong key fails', '', totp_decrypt($token, 'wrong-key'));
assert_eq('decrypt tampered token fails', '', totp_decrypt($token . 'x', $key));
assert_eq('encrypt without key fails', '', totp_encrypt($secret, ''));

echo "\nAll TOTP helper tests passed.\n";
