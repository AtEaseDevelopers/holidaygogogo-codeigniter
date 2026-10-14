<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Access rules for the PUBLIC customer Quotation PDF (Costing_Quotation).
 *
 * The quotation URL is addressed by the unguessable costing_bookings.quotation_token
 * (a 32-char md5), never by the sequential package id, so links can't be enumerated.
 * These are pure decisions so they can be unit-tested without a DB/framework.
 */

if (!function_exists('costing_quotation_token_is_valid')) {
    /**
     * True only for a well-formed quotation token: exactly 32 lowercase hex chars
     * (the md5(uniqid()) shape produced by Costing_Model::Ensure_Quotation_Token()).
     * Rejects empty strings, numeric ids, uppercase, and anything the wrong length —
     * so a guessed/enumerated id can never masquerade as a token.
     */
    function costing_quotation_token_is_valid($token)
    {
        $token = trim((string) $token);
        return (bool) preg_match('/^[a-f0-9]{32}$/', $token);
    }
}

if (!function_exists('costing_quotation_status_accessible')) {
    /**
     * Whether a package status allows its quotation to be served publicly.
     * Mirrors Costing_Model::Normalize_Status() (lowercase + trim). Only an explicit
     * 'inactive' blocks access; unknown/blank falls back to accessible ('active').
     */
    function costing_quotation_status_accessible($status)
    {
        $status = strtolower(trim((string) $status));
        return $status !== 'inactive';
    }
}
