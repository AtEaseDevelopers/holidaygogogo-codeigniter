<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pure tour-code generation for costing packages. No DB, no HTTP, so it stays
 * unit-testable (see tests/helpers/CostingTourCodeHelperTest.php).
 *
 * Format: QU-YYMM-XXXX
 *   QU   - fixed prefix (quotation)
 *   YYMM - 2-digit year + 2-digit month of creation
 *   XXXX - zero-padded running sequence, restarting each YYMM period
 *
 * e.g. the 3rd package created in Aug 2026 -> QU-2608-0003
 *
 * The model passes in the current YYMM period plus every existing tour_code for
 * that period; these functions only format/parse, so the date lookup and the DB
 * query stay outside the tested surface.
 */

if (!function_exists('costing_tour_code_prefix')) {
    /**
     * @return string the fixed tour-code prefix.
     */
    function costing_tour_code_prefix()
    {
        return 'QU';
    }
}

if (!function_exists('costing_tour_code_format')) {
    /**
     * Build a tour code from a YYMM period and a sequence number.
     *
     * @param string $period   4-char YYMM string (e.g. "2608").
     * @param int    $sequence 1-based running number within the period.
     * @return string QU-YYMM-XXXX
     */
    function costing_tour_code_format($period, $sequence)
    {
        return costing_tour_code_prefix()
            . '-' . $period
            . '-' . str_pad((string) max(0, (int) $sequence), 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('costing_tour_code_sequence')) {
    /**
     * Extract the sequence number from a code belonging to the given period.
     * Returns 0 when the code does not match qu-<period>-<digits> (any other
     * period, blank, or a hand-typed code with a non-numeric tail).
     *
     * @param string $code
     * @param string $period 4-char YYMM string.
     * @return int
     */
    function costing_tour_code_sequence($code, $period)
    {
        $prefix = costing_tour_code_prefix() . '-' . $period . '-';
        $code = trim((string) $code);
        if (stripos($code, $prefix) !== 0) {
            return 0;
        }

        $tail = substr($code, strlen($prefix));
        if ($tail === '' || !ctype_digit($tail)) {
            return 0;
        }

        return (int) $tail;
    }
}

if (!function_exists('costing_tour_code_next')) {
    /**
     * Next tour code for a period = highest existing sequence in that period + 1,
     * formatted. Ignores codes from other periods / hand-typed codes.
     *
     * @param array<int,string> $existing_codes all tour_codes to scan.
     * @param string            $period         4-char YYMM string.
     * @return string QU-YYMM-XXXX
     */
    function costing_tour_code_next($existing_codes, $period)
    {
        $max = 0;
        foreach ((array) $existing_codes as $code) {
            $seq = costing_tour_code_sequence($code, $period);
            if ($seq > $max) {
                $max = $seq;
            }
        }

        return costing_tour_code_format($period, $max + 1);
    }
}

/**
 * Revision suffix helpers. A package revision keeps the base tour code and appends
 * "-REVnn" (2-digit, 1-based), e.g. base QU-2610-0008 -> QU-2610-0008-REV01. The base
 * (revision 0) carries no suffix. Because the "-REVnn" tail is non-numeric, the
 * sequence parser above ignores it, so revisions never disturb the monthly sequence.
 */
if (!function_exists('costing_tour_code_revision')) {
    /**
     * Append a revision suffix to a base code. Revision 0 (or less) returns the base
     * code unchanged.
     *
     * @param string $base_code        e.g. QU-2610-0008
     * @param int    $revision_number  1-based revision number.
     * @return string e.g. QU-2610-0008-REV01
     */
    function costing_tour_code_revision($base_code, $revision_number)
    {
        $base_code = trim((string) $base_code);
        $revision_number = (int) $revision_number;
        if ($revision_number <= 0) {
            return $base_code;
        }

        return $base_code . '-REV' . str_pad((string) $revision_number, 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('costing_tour_code_base')) {
    /**
     * Strip a trailing "-REVnn" (case-insensitive) suffix to recover the base code.
     * Idempotent for codes that carry no suffix.
     *
     * @param string $code
     * @return string the base tour code.
     */
    function costing_tour_code_base($code)
    {
        $code = trim((string) $code);
        return (string) preg_replace('/-REV\d+$/i', '', $code);
    }
}

if (!function_exists('costing_tour_code_revision_number')) {
    /**
     * Parse the "-REVnn" suffix of a code into its revision number. Returns 0 when the
     * code carries no (numeric) revision suffix.
     *
     * @param string $code
     * @return int
     */
    function costing_tour_code_revision_number($code)
    {
        $code = trim((string) $code);
        if (preg_match('/-REV(\d+)$/i', $code, $m)) {
            return (int) $m[1];
        }
        return 0;
    }
}

if (!function_exists('costing_tour_code_next_revision')) {
    /**
     * Next revision number for a family = highest existing revision among codes that
     * share the given base + 1. Codes with a different base are ignored. The base code
     * itself (no suffix) counts as revision 0, so the first revision is always 1.
     *
     * @param array<int,string> $existing_codes all tour_codes in the family (or more).
     * @param string            $base_code      e.g. QU-2610-0008
     * @return int next 1-based revision number.
     */
    function costing_tour_code_next_revision($existing_codes, $base_code)
    {
        $base_code = trim((string) $base_code);
        $max = 0;
        foreach ((array) $existing_codes as $code) {
            if (costing_tour_code_base($code) !== $base_code) {
                continue;
            }
            $rev = costing_tour_code_revision_number($code);
            if ($rev > $max) {
                $max = $rev;
            }
        }

        return $max + 1;
    }
}
