<?php
/**
 * php tests/integration/CostingRevisionFlowTest.php
 *
 * Exercises the costing package REVISION flow end-to-end against the configured
 * MySQL connection, but every costing table it touches is a TEMPORARY copy seeded
 * from the real rows — nothing in the live DB is changed.
 *
 * Covers:
 *   - Create_Revision builds QU-...-REVnn, links revision_root_id/revision_number,
 *     deep-copies the snapshot and mints NO shared quotation token.
 *   - A second revision branches from the latest (REV02), still rooted at the base.
 *   - Duplicate_Package stays a fresh, independent family (revision_number 0, no root).
 *   - Read_Packages_Dashboard groups the family into a single latest-row with the full
 *     revision history attached.
 */

chdir(dirname(__DIR__, 2));
// Bootstrap CI via a safe no-op CLI controller (run('') early-returns on the id guard),
// then reuse the live instance's DB connection below.
$_SERVER['argv'] = array('index.php', 'Faq_Knowledge_Import_Job', 'run');
require 'index.php';

$ci =& get_instance();
$ci->load->model('Costing_Model');
$ci->load->helper('costing_tour_code');

function expect($ok, $label)
{
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    echo 'PASS: ' . $label . PHP_EOL;
}

// Every table the revision/duplicate/dashboard paths read or write. Mirror each into a
// TEMPORARY table seeded from the real data via an intermediate _seed_ copy, so the
// shadow table carries real rows without the live table ever being written.
$TABLES = array(
    'costing_packages',
    'costing_package_items',
    'costing_itinerary_days',
    'costing_quote_hotels',
    'costing_quote_flight_options',
    'costing_quote_flights',
    'costing_bookings',
    'costing_combinations',
    'costing_booking_items',
    'costing_booking_financials',
    'costing_snapshot_rates',
);

try {
    foreach ($TABLES as $t) {
        // 1) Capture real rows into a distinct-named temp (LIKE with a different name is allowed).
        $ci->db->query("CREATE TEMPORARY TABLE `_seed_$t` LIKE `$t`");
        $ci->db->query("INSERT INTO `_seed_$t` SELECT * FROM `$t`");
        // 2) Shadow the real table with a same-named temp built from its DDL (LIKE `$t` to
        //    itself is rejected as "not unique"), then seed it from the captured copy.
        $def = $ci->db->query("SHOW CREATE TABLE `$t`")->row_array();
        $create = isset($def['Create Table']) ? $def['Create Table'] : $def['Create View'];
        $create = preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $create);
        // TEMPORARY tables can't carry FKs (InnoDB rejects them), so drop constraint lines,
        // then clear the now-dangling comma before the closing paren.
        $lines = preg_split('/\r?\n/', $create);
        $lines = array_values(array_filter($lines, function ($l) {
            return !preg_match('/^\s*CONSTRAINT .*FOREIGN KEY/i', $l);
        }));
        $create = implode("\n", $lines);
        $create = preg_replace('/,(\s*\n\s*\))/', '$1', $create);
        $ci->db->query($create);
        $ci->db->query("INSERT INTO `$t` SELECT * FROM `_seed_$t`");
    }

    // Pick a base package that has a tour code and a snapshot.
    $base = $ci->db->query("SELECT * FROM costing_packages WHERE tour_code IS NOT NULL AND tour_code <> '' AND id IN (SELECT package_id FROM costing_bookings) ORDER BY id DESC LIMIT 1")->row_array();
    expect(!empty($base), 'seed: found a base package with tour code + snapshot (id=' . ($base['id'] ?? '?') . ', code=' . ($base['tour_code'] ?? '?') . ')');
    $base_id = (int) $base['id'];
    $base_code = (string) $base['tour_code'];
    $base_bookings = (int) $ci->db->query("SELECT COUNT(*) c FROM costing_bookings WHERE package_id = $base_id")->row()->c;

    /* ---- 1) First revision -------------------------------------------------- */
    $rev1_id = $ci->Costing_Model->Create_Revision($base_id);
    expect($rev1_id > 0, 'rev1: created (id=' . $rev1_id . ')');
    $rev1 = $ci->db->query("SELECT * FROM costing_packages WHERE id = " . (int) $rev1_id)->row_array();
    expect($rev1['tour_code'] === $base_code . '-REV01', 'rev1: tour_code = base-REV01 (' . $rev1['tour_code'] . ')');
    expect((int) $rev1['revision_root_id'] === $base_id, 'rev1: revision_root_id points at base');
    expect((int) $rev1['revision_number'] === 1, 'rev1: revision_number = 1');
    expect(trim((string) $rev1['name']) === trim((string) $base['name']), 'rev1: keeps base package name (no "(Copy)")');
    $rev1_bookings = (int) $ci->db->query("SELECT COUNT(*) c FROM costing_bookings WHERE package_id = " . (int) $rev1_id)->row()->c;
    expect($rev1_bookings === $base_bookings, 'rev1: snapshot deep-copied (' . $rev1_bookings . ' booking(s))');
    $shared_token = (int) $ci->db->query("SELECT COUNT(*) c FROM costing_bookings WHERE package_id = " . (int) $rev1_id . " AND quotation_token IS NOT NULL")->row()->c;
    expect($shared_token === 0, 'rev1: no shared quotation token (minted on demand)');

    /* ---- 2) Second revision branches from the latest ------------------------ */
    // Revise from the base id again — it must still resolve to the latest (REV01) and
    // produce REV02 rooted at the base.
    $rev2_id = $ci->Costing_Model->Create_Revision($base_id);
    expect($rev2_id > 0, 'rev2: created (id=' . $rev2_id . ')');
    $rev2 = $ci->db->query("SELECT * FROM costing_packages WHERE id = " . (int) $rev2_id)->row_array();
    expect($rev2['tour_code'] === $base_code . '-REV02', 'rev2: tour_code = base-REV02 (' . $rev2['tour_code'] . ')');
    expect((int) $rev2['revision_root_id'] === $base_id, 'rev2: still rooted at base');
    expect((int) $rev2['revision_number'] === 2, 'rev2: revision_number = 2');

    // Revising the REV01 row (not the base) must also yield REV03 (family-wide max+1).
    $rev3_id = $ci->Costing_Model->Create_Revision($rev1_id);
    $rev3 = $ci->db->query("SELECT * FROM costing_packages WHERE id = " . (int) $rev3_id)->row_array();
    expect($rev3['tour_code'] === $base_code . '-REV03', 'rev3: revising any member -> next family REV (' . $rev3['tour_code'] . ')');
    expect((int) $rev3['revision_root_id'] === $base_id, 'rev3: still rooted at base');

    /* ---- 3) Duplicate stays an independent family --------------------------- */
    $dup_id = $ci->Costing_Model->Duplicate_Package($base_id);
    expect($dup_id > 0, 'dup: created (id=' . $dup_id . ')');
    $dup = $ci->db->query("SELECT * FROM costing_packages WHERE id = " . (int) $dup_id)->row_array();
    expect($dup['revision_root_id'] === null, 'dup: revision_root_id NULL (independent family)');
    expect((int) $dup['revision_number'] === 0, 'dup: revision_number 0');
    expect(strpos((string) $dup['tour_code'], '-REV') === false, 'dup: fresh monthly code, no REV suffix (' . $dup['tour_code'] . ')');
    expect(substr((string) $dup['name'], -7) === ' (Copy)', 'dup: name gets "(Copy)"');

    /* ---- 4) Dashboard groups the family ------------------------------------- */
    // Read_Packages_Dashboard's SQL references the same table several times, which
    // MySQL forbids on TEMPORARY tables (err 1137) — so feed the real family rows (read
    // straight from the shadowed costing_packages) through the same pure grouping helper
    // the dashboard uses. The grouping itself is covered standalone in
    // tests/helpers/CostingPackageRevisionTest.php.
    $ci->load->helper('costing_package_revision');
    $rows = $ci->db->query("
        SELECT id, revision_root_id, revision_number, tour_code, status, updated_at,
               (SELECT quotation_token FROM costing_bookings b WHERE b.package_id = cp.id ORDER BY b.id DESC LIMIT 1) AS latest_quotation_token
        FROM costing_packages cp
        WHERE id = $base_id OR revision_root_id = $base_id OR id = " . (int) $dup_id . "
        ORDER BY updated_at DESC, id DESC
    ")->result_array();
    $grouped = costing_group_package_revisions($rows);

    $family_row = null;
    $dup_rows = 0;
    foreach ($grouped as $row) {
        $root = !empty($row['revision_root_id']) ? (int) $row['revision_root_id'] : (int) $row['id'];
        if ($root === $base_id) { $family_row = $row; }
        if ((int) $row['id'] === (int) $dup_id) { $dup_rows++; }
    }
    expect($family_row !== null, 'dash: family present as a single grouped row');
    expect((int) $family_row['revision_number'] === 3, 'dash: visible row is the latest revision (REV03)');
    expect(isset($family_row['revisions']) && count($family_row['revisions']) === 4, 'dash: history has base + 3 revisions (' . count($family_row['revisions']) . ')');
    expect((int) $family_row['revisions'][0]['revision_number'] === 3, 'dash: history sorted latest-first');
    expect($dup_rows === 1, 'dash: duplicate appears as its own family row');

    echo PHP_EOL . 'ALL PASS' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    echo $e->getMessage() . PHP_EOL;
    exit(1);
}
