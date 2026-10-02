<?php
/**
 * Run with: php tests/helpers/ResolveBookingChecklistTeamLeadIdsTest.php
 *
 * Locks the contract for resolve_booking_checklist_team_lead_ids(): given a
 * booking, it returns the set of admin IDs allowed to tick its checklist by
 * virtue of LEADING a team one of the booking's assignees belongs to.
 *
 * A lead qualifies iff they are an ACTIVE TEAM LEAD (Level 25) or OP TEAM LEAD
 * (Level 45) whose admin.TeamID matches the team of the booking's TC
 * (SalesAgent), TC2 (SalesAgent2) or OP (BookingOP). Assignees themselves are
 * NOT included (can_user_modify_booking_checklist handles them). Inactive or
 * wrong-level admins in the same team are excluded.
 *
 * Exercised via a minimal get_instance() shim + sqlite-backed db facade — no
 * full CodeIgniter bootstrap.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

// Thin facade over PDO exposing the chained query-builder surface the function
// under test uses: select(), where_in(), where() (incl. raw + operator forms),
// get('table')->result().
class TestDbFacade
{
    public $pdo;
    private $select = '*';
    private $conds = array();  // each: [sqlFragment, params[]]
    private $from = null;
    private $joins = array();

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function select($cols) { $this->select = $cols; return $this; }

    public function from($table) { $this->from = $table; return $this; }

    public function join($table, $cond, $type = '')
    {
        $this->joins[] = trim(strtoupper($type) . " JOIN $table ON $cond");
        return $this;
    }

    public function table_exists($table)
    {
        $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute(array($table));
        return (bool) $stmt->fetch();
    }

    public function where_in($field, array $values)
    {
        if (empty($values)) { $values = array(-1); }
        $place = implode(',', array_fill(0, count($values), '?'));
        $this->conds[] = array("$field IN ($place)", array_values($values));
        return $this;
    }

    public function where($key, $value = null, $escape = true)
    {
        if ($value === null && $escape === false) {
            // Raw SQL fragment, e.g. "TeamID IS NOT NULL".
            $this->conds[] = array($key, array());
            return $this;
        }
        // Support a trailing operator in the key, e.g. "TeamID >".
        $key = trim($key);
        if (preg_match('/^(.+?)\s+(<=|>=|<>|!=|<|>|=)$/', $key, $m)) {
            $this->conds[] = array("{$m[1]} {$m[2]} ?", array($value));
        } else {
            $this->conds[] = array("$key = ?", array($value));
        }
        return $this;
    }

    public function get($table = null)
    {
        $tbl = ($table !== null) ? $table : $this->from;
        $sql = "SELECT {$this->select} FROM $tbl";
        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }
        $params = array();
        if (!empty($this->conds)) {
            $frags = array();
            foreach ($this->conds as $c) {
                $frags[] = $c[0];
                foreach ($c[1] as $p) { $params[] = $p; }
            }
            $sql .= ' WHERE ' . implode(' AND ', $frags);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_OBJ);
        // Reset builder state (mirrors CI flushing after get()).
        $this->select = '*';
        $this->conds = array();
        $this->from = null;
        $this->joins = array();
        return new class($rows) {
            private $rows;
            public function __construct($rows) { $this->rows = $rows; }
            public function result() { return $this->rows; }
        };
    }
}

// get_instance() shim so the helper's `$CI =& get_instance()` resolves.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE admin (
    AdminID INTEGER PRIMARY KEY,
    Name TEXT,
    Level TEXT,
    Status TEXT,
    TeamID INTEGER
)");

// Team 1: lead L1a (25), lead L1b (45), member M1 (20). Team 2: lead L2 (25).
// Team 3: lead L3 (25). Plus an inactive lead and a wrong-level admin in team 1.
// Team 5: member M5 (20) with NO primary-team leader; L5 (primary team 4) leads
// it via the admin_team link table (multi-team leader).
$pdo->exec("INSERT INTO admin VALUES
    (10, 'L1a sales lead', '25', 'Y', 1),
    (11, 'L1b op lead',    '45', 'Y', 1),
    (12, 'M1 member',      '20', 'Y', 1),
    (13, 'L1c inactive',   '25', 'N', 1),
    (14, 'Agent lvl20',    '20', 'Y', 1),
    (20, 'L2 sales lead',  '25', 'Y', 2),
    (21, 'M2 member',      '20', 'Y', 2),
    (30, 'L3 sales lead',  '25', 'Y', 3),
    (40, 'No-team lead',   '25', 'Y', NULL),
    (50, 'L5 multi lead',  '25', 'Y', 4),
    (51, 'M5 member',      '20', 'Y', 5)
");

// Extra team memberships for multi-team leaders. L5 (50) oversees team 5.
$pdo->exec("CREATE TABLE admin_team (
    AdminID INTEGER,
    TeamID INTEGER
)");
$pdo->exec("INSERT INTO admin_team VALUES (50, 5)");

$CI = (object) array('db' => new TestDbFacade($pdo));
if (!function_exists('get_instance')) {
    function get_instance() { global $CI; return $CI; }
}

require_once __DIR__ . '/../../application/helpers/booking_flow_helper.php';

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

// TC in team 1, OP in team 2 -> both teams' active leads, no assignees.
// Team 1 active leads: 10, 11. Team 2 active leads: 20. (12/14 lvl20, 13 inactive excluded.)
assert_eq('TC team1 + OP team2 -> {10,11,20}',
    array(10, 11, 20),
    resolve_booking_checklist_team_lead_ids((object) array('SalesAgent' => 12, 'SalesAgent2' => 0, 'BookingOP' => 21)));

// All three assignees in team 1 -> just team 1's active leads.
assert_eq('all in team1 -> {10,11}',
    array(10, 11),
    resolve_booking_checklist_team_lead_ids(array('SalesAgent' => 12, 'SalesAgent2' => 14, 'BookingOP' => 10)));

// Cross-team via TC2: TC team1, TC2 team3, OP team2 -> leads of all three.
assert_eq('cross-team TC/TC2/OP -> {10,11,20,30}',
    array(10, 11, 20, 30),
    resolve_booking_checklist_team_lead_ids(array('SalesAgent' => 12, 'SalesAgent2' => 30, 'BookingOP' => 21)));

// Assignee whose team has no OTHER active lead than themselves still returns
// that team's active leads (leads may themselves be assignees; dedup handles it).
assert_eq('TC is a team1 lead -> team1 leads {10,11}',
    array(10, 11),
    resolve_booking_checklist_team_lead_ids(array('SalesAgent' => 10, 'SalesAgent2' => 0, 'BookingOP' => 0)));

// Multi-team leader: M5 is in team 5, which has no primary-team leader; L5 (50)
// oversees team 5 only via admin_team, so they qualify to tick M5's checklist.
assert_eq('team5 assignee -> multi-team lead {50}',
    array(50),
    resolve_booking_checklist_team_lead_ids(array('SalesAgent' => 51, 'SalesAgent2' => 0, 'BookingOP' => 0)));

// Assignee with no team -> no team leads.
assert_eq('no-team assignee -> {}',
    array(),
    resolve_booking_checklist_team_lead_ids(array('SalesAgent' => 40, 'SalesAgent2' => 0, 'BookingOP' => 0)));

// Unassigned booking -> {}.
assert_eq('unassigned booking -> {}',
    array(),
    resolve_booking_checklist_team_lead_ids(array('SalesAgent' => 0, 'SalesAgent2' => 0, 'BookingOP' => 0)));

// Empty / null booking -> {}.
assert_eq('null booking -> {}', array(), resolve_booking_checklist_team_lead_ids(null));
assert_eq('empty array booking -> {}', array(), resolve_booking_checklist_team_lead_ids(array()));

echo "\n" . ($failures === 0 ? "All assertions passed.\n" : "$failures assertion(s) failed.\n");
exit($failures === 0 ? 0 : 1);
