<?php
/**
 * Run with: php tests/helpers/TeamScopeTest.php
 *
 * Locks team-membership resolution behind the booking / payment listing scope
 * (application/helpers/team_scope_helper.php). A TEAM LEAD (25) / OP TEAM LEAD
 * (45) sees every active member sharing their admin.TeamID; a viewer with no
 * Team is a team of one. Self is always included so the result is never empty.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}
require __DIR__ . '/../../application/helpers/team_scope_helper.php';

$failures = 0;
function assert_eq($label, $expected, $actual) {
    global $failures;
    if ($expected === $actual) {
        echo "  PASS  $label\n";
    } else {
        $failures++;
        echo "  FAIL  $label — expected " . var_export($expected, true)
           . ", got " . var_export($actual, true) . "\n";
    }
}

// Build a fake admin row (CI ->result() object shape).
function admin_row($id, $team, $status = 'Y') {
    return (object) array('AdminID' => $id, 'TeamID' => $team, 'Status' => $status);
}

// Team A = {1 (lead), 2, 3}; Team B = {4 (lead), 5}; 6 has no team.
$admins = array(
    admin_row(1, 7),   // Team 7 lead
    admin_row(2, 7),   // Team 7 member
    admin_row(3, 7),   // Team 7 member
    admin_row(4, 9),   // Team 9 lead
    admin_row(5, 9),   // Team 9 member
    admin_row(6, null) // no team
);

echo "Team membership resolution:\n";
assert_eq('Team 7 lead (1) sees whole team',        array(1, 2, 3), team_member_admin_ids(1, $admins));
assert_eq('Team 7 member (2) sees whole team',      array(1, 2, 3), team_member_admin_ids(2, $admins));
assert_eq('Team 9 lead (4) sees only Team 9',       array(4, 5),    team_member_admin_ids(4, $admins));
assert_eq('No-team viewer (6) is a team of one',    array(6),       team_member_admin_ids(6, $admins));
assert_eq('Unknown admin (99) falls back to self',  array(99),      team_member_admin_ids(99, $admins));

echo "Inactive members excluded:\n";
$with_inactive = array(
    admin_row(1, 7),
    admin_row(2, 7, 'N'),  // resigned — excluded
    admin_row(3, 7, 'D'),  // deleted  — excluded
    admin_row(8, 7)        // active   — included
);
assert_eq('Only active teammates counted', array(1, 8), team_member_admin_ids(1, $with_inactive));

echo "Empty / zero TeamID treated as no team:\n";
$edge = array(
    admin_row(1, ''),    // empty string
    admin_row(2, 0),     // zero
    admin_row(3, '7'),   // string team id
    admin_row(4, 7)
);
assert_eq('Empty-string TeamID -> team of one',  array(1), team_member_admin_ids(1, $edge));
assert_eq('Zero TeamID -> team of one',          array(2), team_member_admin_ids(2, $edge));
assert_eq('String "7" matches int 7',            array(3, 4), team_member_admin_ids(3, $edge));

echo "String admin_id coercion:\n";
assert_eq("Viewer id as string '2' behaves like int", array(1, 2, 3), team_member_admin_ids('2', $admins));

// --- Multi-team leaders (admin_team link table) -----------------------------
// Only TEAM LEAD (25) / OP TEAM LEAD (45) may belong to >1 team; membership
// beyond the primary admin.TeamID comes in as link rows (AdminID, TeamID). A
// leader's visibility = union of their primary team and every extra team; a
// member is in scope when ANY of their teams intersects the viewer's team set.
function at_row($id, $team) {
    return (object) array('AdminID' => $id, 'TeamID' => $team);
}
echo "Multi-team leader union:\n";
// Admin 1 leads Team 7 (primary) and ALSO oversees Team 9 via a link row.
$extra = array(at_row(1, 9));
assert_eq('Leader (1) with extra team 9 sees both teams',
    array(1, 2, 3, 4, 5), team_member_admin_ids(1, $admins, $extra));
assert_eq('Plain member (2) unaffected by leader extra team',
    array(1, 2, 3), team_member_admin_ids(2, $admins, $extra));
assert_eq('Team 9 member (5) still sees only Team 9',
    array(4, 5), team_member_admin_ids(5, $admins, $extra));

echo "Extra-team rows honour active/inactive + normalisation:\n";
$inactive2 = array(
    admin_row(1, 7),
    admin_row(2, 7, 'N'),  // resigned — excluded even though in leader's team
    admin_row(4, 9),
    admin_row(5, 9)
);
assert_eq('Inactive teammate still excluded under multi-team',
    array(1, 4, 5), team_member_admin_ids(1, $inactive2, array(at_row(1, 9))));
assert_eq('Empty/zero extra TeamID ignored',
    array(1, 2, 3), team_member_admin_ids(1, $admins, array(at_row(1, 0), at_row(1, ''))));

echo "No extra rows => identical to legacy single-team behaviour:\n";
assert_eq('Empty extra array matches 2-arg call',
    team_member_admin_ids(1, $admins), team_member_admin_ids(1, $admins, array()));

echo "\n" . ($failures === 0 ? "All assertions passed.\n" : "$failures assertion(s) FAILED.\n");
exit($failures === 0 ? 0 : 1);
