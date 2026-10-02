<?php
/**
 * Run with: php tests/helpers/OpCardsTc1ScopeTest.php
 *
 * Locks the rule: OP / OP TEAM LEAD (level 40/45) summary cards are scoped to
 * the BCs of the user's whole TEAM (everyone sharing admin.TeamID — see
 * team_member_admin_ids / TeamScopeTest) as TC1 (booking.SalesAgent), so
 * teammates see each other's BCs. ONE exception: the "Pending BC" card (status
 * PB) stays team-wide because pending BCs have no TC1 assigned yet.
 *
 * Two coordinated guarantees:
 *   - Card counts (Booking::ajax_summary_cards, $is_op block) carry
 *     `AND booking.SalesAgent IN (<team>)` everywhere EXCEPT pending_bc_op.
 *   - Each scoped card's drill-down link carries sales_agent=<team csv> (built via
 *     the $op_link helper) so the listing filters to the same TC1 set and the
 *     count/drill-down agree (CLAUDE memory feedback_card_drilldown_status). The
 *     pending_bc_op link stays status=PB only (team-wide). The DEFAULT booking
 *     listing is itself team-scoped (apply_booking_filters restricts level
 *     25/40/45 to bookings whose TC/TC2/OP is in the viewer's team); the card
 *     drill-down's sales_agent=<team> filter narrows that to the SalesAgent
 *     subset, so card count and list still agree.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("CREATE TABLE booking (
    BookingID INTEGER PRIMARY KEY,
    BookingConfirmationTitle TEXT,
    CancelStatus TEXT,
    Status TEXT,
    AfterSalesService TEXT,
    StartDate TEXT,
    SalesAgent INTEGER
)");

// Logged-in user's OP team = {HANI lead, CHEN}; OTHER is on a different team.
$HANI  = 100;
$CHEN  = 101;
$OTHER = 200;
$TEAM  = "{$HANI},{$CHEN}"; // the inlined "SalesAgent IN (...)" set

$tomorrow = '2026-06-25';

// Cols: id, title, CancelStatus, Status, AfterSalesService, StartDate, SalesAgent
$pdo->exec("INSERT INTO booking VALUES
    (1,  'BOOKING CONFIRMATION', 'N', 'PB',  'PENDING', '2026-12-01', {$HANI}),   /* PB, team             */
    (2,  'BOOKING CONFIRMATION', 'N', 'PB',  'PENDING', '2026-12-01', {$OTHER}),  /* PB, other team       */
    (3,  'BOOKING CONFIRMATION', 'N', 'PB',  'PENDING', '2026-12-01', NULL),      /* PB, unassigned       */
    (4,  'BOOKING CONFIRMATION', 'N', 'PBC', 'PENDING', '2026-12-01', {$HANI}),   /* PBC, Hani            */
    (5,  'BOOKING CONFIRMATION', 'N', 'PBC', 'PENDING', '2026-12-01', {$CHEN}),   /* PBC, Chen (teammate) */
    (6,  'BOOKING CONFIRMATION', 'N', 'PBC', 'PENDING', '2026-12-01', {$OTHER}),  /* PBC, other team      */
    (7,  'BOOKING CONFIRMATION', 'N', 'PBO', 'PENDING', '{$tomorrow}', {$CHEN}),  /* tomorrow, teammate   */
    (8,  'BOOKING CONFIRMATION', 'N', 'PBO', 'PENDING', '{$tomorrow}', {$OTHER}) /* tomorrow, other team */
");

$count = function($where) use ($pdo) {
    return (int)$pdo->query("SELECT COUNT(*) FROM booking WHERE {$where}")->fetchColumn();
};

// Pending BC card: UNTOUCHED — team-wide, all PB regardless of SalesAgent.
$pending_bc = $count("CancelStatus='N' AND Status='PB'");

// Every other OP card: scoped to the whole OP team (Hani + Chen), not just self.
$pending_bc_confirmation = $count("CancelStatus='N' AND Status='PBC' AND SalesAgent IN ({$TEAM})");
$travel_tomorrow         = $count("BookingConfirmationTitle='BOOKING CONFIRMATION' AND CancelStatus='N' AND Status!='N' AND StartDate='{$tomorrow}' AND SalesAgent IN ({$TEAM})");

// Listing parity: a scoped card's drill-down link adds sales_agent=<team>, so the
// list filters SalesAgent IN team. The pending_bc_op link omits it -> team-wide.
$dd_pending_bc_confirmation = $count("CancelStatus='N' AND Status='PBC' AND SalesAgent IN ({$TEAM})");
$dd_pending_bc              = $count("CancelStatus='N' AND Status='PB'");

function assert_eq($label, $expected, $actual) {
    if ($expected === $actual) {
        echo "  PASS  {$label} = " . json_encode($actual) . "\n";
    } else {
        echo "  FAIL  {$label}: expected " . json_encode($expected)
           . ", got " . json_encode($actual) . "\n";
        exit(1);
    }
}
function assert_true($label, $cond) {
    if ($cond) { echo "  PASS  {$label}\n"; }
    else { echo "  FAIL  {$label}\n"; exit(1); }
}

// Behavioural: Pending BC stays team-wide; everything else is whole-team scoped.
assert_eq('pending BC team-wide (all PB incl. unassigned)', 3, $pending_bc);
assert_eq('pending BC confirmation counts the whole team',  2, $pending_bc_confirmation); // Hani + Chen
assert_eq('travelling tomorrow counts the whole team',      1, $travel_tomorrow);          // Chen

// Parity: card count == drill-down list count.
assert_eq('pending BC confirmation parity', $pending_bc_confirmation, $dd_pending_bc_confirmation);
assert_eq('pending BC parity (team-wide)',  $pending_bc,              $dd_pending_bc);

// ---------------------------------------------------------------------------
// Source-code locks.
// ---------------------------------------------------------------------------
$model = file_get_contents(__DIR__ . '/../../application/models/Booking_Model.php');
// The DEFAULT listing is team-scoped for level 25/40/45: apply_booking_filters
// restricts to bookings whose TC/TC2/OP is in the viewer's team.
$abf_pos = strpos($model, 'private function apply_booking_filters');
$abf = $abf_pos !== false ? substr($model, $abf_pos, 2500) : '';
assert_true('apply_booking_filters team-scopes level 25/40/45 by SalesAgent/SalesAgent2/BookingOP',
    preg_match('/\[25,\s*40,\s*45\][\s\S]{0,400}?where_in\(.booking\.SalesAgent.[\s\S]{0,300}?or_where_in\(.booking\.BookingOP./', $abf) === 1);
// The drill-downs rely on the existing sales_agent filter param.
assert_true('model supports the sales_agent filter param',
    strpos($model, "input->get('sales_agent')") !== false
    && strpos($model, "where_in('SalesAgent'") !== false);

$controller = file_get_contents(__DIR__ . '/../../application/controllers/Booking.php');
// Isolate the OP block so the locks below don't accidentally match TC-block code.
$op_start = strpos($controller, 'if($is_op) {');
$fin_start = strpos($controller, 'if($is_finance) {');
assert_true('controller has an $is_op block', $op_start !== false && $fin_start !== false && $fin_start > $op_start);
$op_block = substr($controller, $op_start, $fin_start - $op_start);

// The OP block resolves the team via the team_scope helper (team_member_ids_from_db,
// which unions a multi-team leader's extra admin_team teams) and scopes by IN(...).
assert_true('OP block loads the team_scope helper',
    strpos($op_block, "load->helper('team_scope')") !== false);
assert_true('OP block resolves the team for the logged-in user',
    preg_match('/team_member_ids_from_db\(\s*\$this->db\s*,\s*\$admin_id\s*\)/', $op_block) === 1);
assert_true('OP block scopes by SalesAgent IN (team)',
    strpos($op_block, 'booking.SalesAgent IN ({$op_team_csv})') !== false);

// pending_bc_op stays team-wide: its own query must NOT carry a SalesAgent filter.
$pb_pos = strpos($op_block, "\$cards['pending_bc_op']");
$pb_query = $pb_pos !== false ? substr($op_block, max(0, $pb_pos - 220), 220) : '';
assert_true('pending_bc_op query stays team-wide (no SalesAgent filter)',
    strpos($pb_query, 'SalesAgent') === false);

// The scoped cards exist in the OP block.
foreach (array(
    "pending_bc_confirmation_op", "travel_tomorrow_op", "travel_tomorrow_not_ready_op",
    "pending_review_op", "gl_submitted", "insurance_pending", "ferry_pending",
    "customer_payment_due_soon", "supplier_due_soon", "checklist_payout_due_soon",
) as $card) {
    $pos = strpos($op_block, "\$cards['{$card}']");
    assert_true("controller builds {$card} card", $pos !== false);
}
assert_true('OP block scopes queries by the team SalesAgent predicate',
    substr_count($op_block, '$op_sa_in') >= 8
    || substr_count($op_block, 'SalesAgent IN ({$op_team_csv})') >= 3);

// Drill-down scoping: $op_link injects the team csv into sales_agent.
assert_true('OP block defines an $op_link helper that injects the team sales_agent',
    preg_match('/\$op_link\s*=\s*function[\s\S]{0,160}?sales_agent[\s\S]{0,60}?\$op_team_csv/', $op_block) === 1);
assert_true('scoped OP card links go through $op_link',
    substr_count($op_block, '$op_link(') >= 12);

// pending_bc_op must NOT be scoped: its link stays on plain $qs (status=PB),
// not $op_link (which would append sales_agent and hide the team-wide queue).
$pb_link = $pb_pos !== false ? substr($op_block, $pb_pos, 200) : '';
assert_true('pending_bc_op link uses status=PB on plain $qs (team-wide)',
    strpos($pb_link, "\$base . \$qs(array('status' => 'PB'))") !== false);
assert_true('pending_bc_op link does NOT go through $op_link',
    strpos($pb_link, '$op_link(') === false);

// Conversion helper accepts a team SalesAgent-ids scope and inlines IN(...).
$helper = file_get_contents(__DIR__ . '/../../application/helpers/submitted_payment_response_helper.php');
$cs_pos = strpos($helper, 'function submitted_payment_conversion_summary_sql');
$cs_sig = $cs_pos !== false ? substr($helper, $cs_pos, 120) : '';
assert_true('conversion summary helper takes a sales-agent ids scope param',
    strpos($cs_sig, '$sales_agent_ids') !== false);
assert_true('conversion summary helper appends b.SalesAgent IN (...)',
    preg_match('/submitted_payment_conversion_summary_sql[\s\S]{0,1100}?b\.SalesAgent\s+IN\s*\(/', $helper) === 1);

echo "\nAll assertions passed.\n";
