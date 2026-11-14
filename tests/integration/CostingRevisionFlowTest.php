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
 *   - Revising the base or an older revision copies the selected version and uses
 *     the next family revision number.
 *   - Duplicating an older revision copies its content into an independent family.
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

    // Start a fresh family so revisions already present in the seed data do not
    // affect the expected revision numbers.
    $seed = $ci->db->query("SELECT id FROM costing_packages WHERE status <> 'deleted' AND id IN (SELECT package_id FROM costing_bookings) ORDER BY id DESC LIMIT 1")->row_array();
    expect(!empty($seed), 'seed: found a package with a snapshot');
    $base_id = $ci->Costing_Model->Duplicate_Package((int) $seed['id']);
    expect($base_id > 0, 'seed: created an independent base package');
    $base = $ci->db->where('id', $base_id)->get('costing_packages')->row_array();
    $base_code = (string) $base['tour_code'];
    $base_bookings = (int) $ci->db->query("SELECT COUNT(*) c FROM costing_bookings WHERE package_id = $base_id")->row()->c;

    /* ---- 1) First revision -------------------------------------------------- */
    $rev1_id = $ci->Costing_Model->Create_Revision($base_id);
    expect($rev1_id > 0, 'rev1: created (id=' . $rev1_id . ')');
    $rev1 = $ci->db->query("SELECT * FROM costing_packages WHERE id = " . (int) $rev1_id)->row_array();
    expect($rev1['tour_code'] === $base_code . '-REV01', 'rev1: tour_code = base-REV01 (' . $rev1['tour_code'] . ')');
    expect((int) $rev1['revision_root_id'] === $base_id, 'rev1: revision_root_id points at base');
    expect((int) $rev1['revision_number'] === 1, 'rev1: revision_number = 1');
    expect($rev1['name'] === $base['name'], 'rev1: keeps the source package name');
    $rev1_bookings = (int) $ci->db->query("SELECT COUNT(*) c FROM costing_bookings WHERE package_id = " . (int) $rev1_id)->row()->c;
    expect($rev1_bookings === $base_bookings, 'rev1: snapshot deep-copied (' . $rev1_bookings . ' booking(s))');
    $shared_token = (int) $ci->db->query("SELECT COUNT(*) c FROM costing_bookings WHERE package_id = " . (int) $rev1_id . " AND quotation_token IS NOT NULL")->row()->c;
    expect($shared_token === 0, 'rev1: no shared quotation token (minted on demand)');

    /* ---- 2) Copy the selected version --------------------------------------- */
    $ci->db->where('id', $rev1_id)->update('costing_packages', array(
        'name' => 'Updated REV01 package',
        'duration_days' => (int) $base['duration_days'] + 1,
    ));
    $rev1_travel_date = '2031-01-15';
    $ci->db->where('package_id', $rev1_id)->update('costing_bookings', array('travel_date' => $rev1_travel_date));

    $rev2_id = $ci->Costing_Model->Create_Revision($base_id);
    expect($rev2_id > 0, 'rev2: created (id=' . $rev2_id . ')');
    $rev2 = $ci->db->query("SELECT * FROM costing_packages WHERE id = " . (int) $rev2_id)->row_array();
    expect($rev2['tour_code'] === $base_code . '-REV02', 'rev2: tour_code = base-REV02 (' . $rev2['tour_code'] . ')');
    expect((int) $rev2['revision_root_id'] === $base_id, 'rev2: still rooted at base');
    expect((int) $rev2['revision_number'] === 2, 'rev2: revision_number = 2');
    expect($rev2['duration_days'] === $base['duration_days'], 'rev2: copies the selected base duration');

    $rev3_id = $ci->Costing_Model->Create_Revision($rev1_id);
    expect($rev3_id > 0, 'rev3: created from REV01');
    $rev3 = $ci->db->query("SELECT * FROM costing_packages WHERE id = " . (int) $rev3_id)->row_array();
    expect($rev3['tour_code'] === $base_code . '-REV03', 'rev3: revising any member -> next family REV (' . $rev3['tour_code'] . ')');
    expect((int) $rev3['revision_root_id'] === $base_id, 'rev3: still rooted at base');
    expect($rev3['name'] === 'Updated REV01 package', 'rev3: copies the selected REV01 name');
    expect((int) $rev3['duration_days'] === (int) $base['duration_days'] + 1, 'rev3: copies the selected REV01 duration');
    $rev3_booking = $ci->db->where('package_id', $rev3_id)->get('costing_bookings')->row_array();
    expect($rev3_booking['travel_date'] === $rev1_travel_date, 'rev3: copies the selected REV01 snapshot');

    /* ---- 3) Duplicate stays an independent family --------------------------- */
    $dup_id = $ci->Costing_Model->Duplicate_Package($rev1_id);
    expect($dup_id > 0, 'dup: created (id=' . $dup_id . ')');
    $dup = $ci->db->query("SELECT * FROM costing_packages WHERE id = " . (int) $dup_id)->row_array();
    expect($dup['revision_root_id'] === null, 'dup: revision_root_id NULL (independent family)');
    expect((int) $dup['revision_number'] === 0, 'dup: revision_number 0');
    expect(strpos((string) $dup['tour_code'], '-REV') === false, 'dup: fresh monthly code, no REV suffix (' . $dup['tour_code'] . ')');
    expect($dup['name'] === 'Updated REV01 package (Copy)', 'dup: copies the selected REV01 name and appends "(Copy)"');
    $dup_booking = $ci->db->where('package_id', $dup_id)->get('costing_bookings')->row_array();
    expect($dup_booking['travel_date'] === $rev1_travel_date, 'dup: copies the selected REV01 snapshot');

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
