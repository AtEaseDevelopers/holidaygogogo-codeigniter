<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * TOTP (RFC 6238) helpers for admin two-factor auth with Google Authenticator.
 *
 * TOTP = HOTP(secret, T) where T = floor(unixtime / period). HOTP (RFC 4226)
 * is a dynamic truncation of HMAC-SHA1(secret, counter) reduced to N digits.
 * Google Authenticator / Authy / 1Password are all plain TOTP clients, so an
 * RFC-correct implementation here is enough — nothing to register with Google.
 *
 * The code-generation / verification functions are PURE (time is passed in, no
 * DB, no global clock) so they unit-test against the RFC 6238 Appendix B test
 * vectors. Secret generation and at-rest encryption are kept separate because
 * they touch the CSPRNG / OpenSSL.
 *
 * SECURITY NOTES (see tests + the deep-research findings):
 *  - Replay: totp_verify() returns the matched *time step*. The caller MUST
 *    persist it and pass it back as $after_step so a code can't be reused
 *    inside its ~30s window (CVE-class bug if skipped).
 *  - Drift: a small window (default 1 step = prev/current/next, ~±30s) absorbs
 *    clock skew. Keep it small — a wider window widens the brute-force surface.
 *  - At rest: the shared secret is ENCRYPTED (reversible), never hashed —
 *    verification needs the original key back. Key lives in .env, not the DB.
 */

if (!function_exists('totp_base32_encode')) {
    /**
     * RFC 4648 Base32 encode (no padding), the alphabet Google Authenticator
     * expects in the otpauth:// secret parameter.
     *
     * @param string $binary raw bytes
     * @return string Base32 (A-Z, 2-7), uppercase, unpadded
     */
    function totp_base32_encode($binary)
    {
        if ($binary === '') {
            return '';
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }
}

if (!function_exists('totp_base32_decode')) {
    /**
     * RFC 4648 Base32 decode. Tolerates lowercase, spaces and '=' padding so a
     * user can type the secret by hand. Returns '' for empty input.
     *
     * @param string $b32
     * @return string raw bytes
     */
    function totp_base32_decode($b32)
    {
        $map = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', (string) $b32));
        if ($b32 === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($b32) as $char) {
            $bits .= str_pad(decbin($map[$char]), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }
        return $out;
    }
}

if (!function_exists('totp_hotp')) {
    /**
     * HOTP(secret, counter) — RFC 4226 dynamic truncation. Pure.
     *
     * @param string $secret_bin raw-byte shared secret
     * @param int    $counter    moving factor
     * @param int    $digits     code length (default 6)
     * @return string zero-padded numeric code
     */
    function totp_hotp($secret_bin, $counter, $digits = 6)
    {
        // 8-byte big-endian counter (pack 'J' needs 64-bit PHP; build manually
        // to stay correct for large steps on all builds).
        $bin = '';
        for ($i = 7; $i >= 0; $i--) {
            $bin .= chr(($counter >> ($i * 8)) & 0xff);
        }
        $hash = hash_hmac('sha1', $bin, $secret_bin, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $part = (
            ((ord($hash[$offset])     & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8)  |
             (ord($hash[$offset + 3]) & 0xff)
        );
        $code = $part % pow(10, $digits);
        return str_pad((string) $code, $digits, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('totp_time_step')) {
    /**
     * The time step T = floor(unixtime / period). Pure.
     *
     * @param int $timestamp unix seconds
     * @param int $period    seconds per step (default 30)
     * @return int
     */
    function totp_time_step($timestamp, $period = 30)
    {
        return (int) floor((int) $timestamp / $period);
    }
}

if (!function_exists('totp_code_at')) {
    /**
     * The TOTP code valid at a given unix time. Pure.
     *
     * @param string $secret_bin
     * @param int    $timestamp
     * @param int    $period
     * @param int    $digits
     * @return string
     */
    function totp_code_at($secret_bin, $timestamp, $period = 30, $digits = 6)
    {
        return totp_hotp($secret_bin, totp_time_step($timestamp, $period), $digits);
    }
}

if (!function_exists('totp_verify')) {
    /**
     * Verify a user-entered code against the secret, tolerating clock drift and
     * enforcing single-use. Pure (the clock is passed in).
     *
     * @param string   $secret_bin
     * @param string   $code        what the user typed
     * @param int      $timestamp   server unix time
     * @param int      $window      steps of drift each side (default 1 = ±30s)
     * @param int      $period
     * @param int      $digits
     * @param int|null $after_step  last accepted step; steps <= this are
     *                              rejected (replay prevention). null = no guard.
     * @return int|false the matched time step on success (store it!), else false
     */
    function totp_verify($secret_bin, $code, $timestamp, $window = 1, $period = 30, $digits = 6, $after_step = null)
    {
        $code = preg_replace('/\D/', '', (string) $code);
        if ($code === '' || strlen($code) !== (int) $digits) {
            return false;
        }
        $current = totp_time_step($timestamp, $period);
        for ($i = -$window; $i <= $window; $i++) {
            $step = $current + $i;
            if ($step < 0) {
                continue;
            }
            if ($after_step !== null && $step <= $after_step) {
                continue; // already used — block replay
            }
            if (hash_equals(totp_hotp($secret_bin, $step, $digits), $code)) {
                return $step;
            }
        }
        return false;
    }
}

if (!function_exists('totp_provisioning_uri')) {
    /**
     * Build the otpauth://totp/... URI that becomes the enrollment QR code.
     * Google Authenticator hardcodes 30s/6-digit, but we emit period/digits for
     * other compliant apps.
     *
     * @param string $issuer       e.g. "HolidayGoGoGo"
     * @param string $account_name e.g. the admin username/email
     * @param string $secret_bin   raw-byte secret
     * @param int    $period
     * @param int    $digits
     * @return string
     */
    function totp_provisioning_uri($issuer, $account_name, $secret_bin, $period = 30, $digits = 6)
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account_name);
        $params = http_build_query(array(
            'secret'    => totp_base32_encode($secret_bin),
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => $digits,
            'period'    => $period,
        ), '', '&', PHP_QUERY_RFC3986);
        return 'otpauth://totp/' . $label . '?' . $params;
    }
}

if (!function_exists('totp_generate_secret')) {
    /**
     * A fresh random shared secret. 20 bytes = 160 bits, the RFC 4226
     * recommended length. NOT pure (uses the CSPRNG).
     *
     * @param int $bytes
     * @return string raw bytes
     */
    function totp_generate_secret($bytes = 20)
    {
        return random_bytes($bytes);
    }
}

if (!function_exists('totp_encrypt')) {
    /**
     * Encrypt the raw secret for at-rest storage with AES-256-GCM (authenticated
     * encryption). The key string from .env is hashed to a 32-byte key. Output
     * is base64("v1" | iv | tag | ciphertext). NOT pure (OpenSSL + IV).
     *
     * @param string $plaintext raw secret bytes
     * @param string $key_str   TOTP_ENCRYPTION_KEY from .env
     * @return string base64 token, '' on failure
     */
    function totp_encrypt($plaintext, $key_str)
    {
        if ((string) $key_str === '') {
            return '';
        }
        $key = hash('sha256', $key_str, true);
        $iv  = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            return '';
        }
        return base64_encode('v1' . $iv . $tag . $cipher);
    }
}

if (!function_exists('totp_decrypt')) {
    /**
     * Reverse totp_encrypt(). Returns '' on any tamper/failure.
     *
     * @param string $token   base64 token produced by totp_encrypt()
     * @param string $key_str TOTP_ENCRYPTION_KEY from .env
     * @return string raw secret bytes, '' on failure
     */
    function totp_decrypt($token, $key_str)
    {
        if ((string) $token === '' || (string) $key_str === '') {
            return '';
        }
        $raw = base64_decode($token, true);
        if ($raw === false || strlen($raw) < 2 + 12 + 16 || substr($raw, 0, 2) !== 'v1') {
            return '';
        }
        $key    = hash('sha256', $key_str, true);
        $iv     = substr($raw, 2, 12);
        $tag    = substr($raw, 14, 16);
        $cipher = substr($raw, 30);
        $plain  = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }
}
