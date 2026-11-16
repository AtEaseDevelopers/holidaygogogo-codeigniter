<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Booking Team Group Helper
 *
 * Collapses the raw `team` master names (INB TEAM / OUB TEAM / CORPORATE TEAM /
 * TRANSPORT TEAM / MANAGEMENT TEAM) into the four reporting buckets the owner's
 * "Sales by team" view presents:
 *
 *   Inbound               <- INB TEAM
 *   Corporate & Outbound  <- CORPORATE TEAM + OUB TEAM   (merged bucket)
 *   Transport             <- TRANSPORT TEAM
 *   Management            <- MANAGEMENT TEAM
 *
 * Used to stamp a single grouped-team label onto each row of the Booking Excel
 * export. The booking's team itself is the FROZEN booking.TeamID snapshot (see
 * booking_team_snapshot_helper.php), so this is purely a display re-grouping.
 *
 * Matching is keyword-based (not exact string / not TeamID) so a rename like
 * "INB TEAM" -> "INBOUND TEAM" still groups correctly. An empty/NULL team gives
 * an empty label (blank cell); a team that matches none of the buckets falls
 * back to its own raw name rather than being silently hidden.
 */

if (!function_exists('booking_team_group')) {
    /**
     * @param string|null $team_name Raw team name from the `team` master (booking.TeamID join)
     * @return string Grouped reporting label, '' for no team, or the raw name if unknown
     */
    function booking_team_group($team_name)
    {
        if ($team_name === null) {
            return '';
        }
        $t = strtoupper(trim($team_name));
        if ($t === '') {
            return '';
        }
        if (strpos($t, 'INB') !== false || strpos($t, 'INBOUND') !== false) {
            return 'Inbound';
        }
        if (strpos($t, 'OUB') !== false || strpos($t, 'OUTBOUND') !== false || strpos($t, 'CORPORATE') !== false) {
            return 'Corporate & Outbound';
        }
        if (strpos($t, 'TRANSPORT') !== false) {
            return 'Transport';
        }
        if (strpos($t, 'MANAGEMENT') !== false) {
            return 'Management';
        }
        // Unknown team: surface the raw name rather than hide the sale.
        return trim($team_name);
    }
}
