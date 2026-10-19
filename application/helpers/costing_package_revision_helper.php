<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pure grouping of costing package dashboard rows into revision families. No DB, no
 * HTTP, so it stays unit-testable (see tests/helpers/CostingPackageRevisionTest.php).
 *
 * A "family" is every row sharing the same root: a revision points at its base via
 * revision_root_id, and a base (revision_number 0) is its own root. The dashboard
 * shows one row per family — the latest revision — with the full member list attached
 * under ['revisions'] (highest revision first) for the history expander.
 */

if (!function_exists('costing_group_package_revisions')) {
    /**
     * @param array<int,array> $packages enriched dashboard rows (need id,
     *        revision_root_id, revision_number, tour_code, status,
     *        latest_quotation_token, updated_at).
     * @return array<int,array> latest-per-family rows, each with a ['revisions'] list.
     *         Family order follows first appearance in $packages (preserves the query's
     *         ordering).
     */
    function costing_group_package_revisions($packages)
    {
        $families = array();
        $order = array();

        foreach ((array) $packages as $package) {
            $family_key = !empty($package['revision_root_id'])
                ? (int) $package['revision_root_id']
                : (int) $package['id'];

            if (!isset($families[$family_key])) {
                $families[$family_key] = array('latest' => $package, 'revisions' => array());
                $order[] = $family_key;
            }

            $families[$family_key]['revisions'][] = array(
                'id' => (int) $package['id'],
                'tour_code' => isset($package['tour_code']) ? $package['tour_code'] : '',
                'revision_number' => (int) $package['revision_number'],
                'status' => isset($package['status']) ? $package['status'] : '',
                'quotation_token' => isset($package['latest_quotation_token']) ? $package['latest_quotation_token'] : null,
                'updated_at' => isset($package['updated_at']) ? $package['updated_at'] : null,
            );

            if ((int) $package['revision_number'] > (int) $families[$family_key]['latest']['revision_number']) {
                $families[$family_key]['latest'] = $package;
            }
        }

        $grouped = array();
        foreach ($order as $family_key) {
            $row = $families[$family_key]['latest'];
            $revisions = $families[$family_key]['revisions'];
            usort($revisions, function ($a, $b) {
                return $b['revision_number'] - $a['revision_number'];
            });
            $row['revisions'] = $revisions;
            $grouped[] = $row;
        }

        return $grouped;
    }
}
