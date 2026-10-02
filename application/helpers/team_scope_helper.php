<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('team_member_admin_ids')) {
    /**
     * Resolve the set of admin IDs that make up the Team the given user belongs
     * to, used to scope the booking / payment listings so a TEAM LEAD (25) or
     * OP TEAM LEAD (45) sees every team member's work while line staff (20 / 40)
     * see only their own.
     *
     * Membership is the shared admin.TeamID column (see the "team" settings
     * entity / 2026XXXX_Add_TeamID_To_Admin.sql) PLUS, for a TEAM LEAD (25) /
     * OP TEAM LEAD (45) only, any extra teams they oversee via the admin_team
     * link table (20261002_Create_Admin_Team_Table.sql). A member is assigned a
     * single Team; a leader may span several. So a viewer's visibility = the
     * union of their primary team and every extra team, and an admin is in scope
     * when ANY of their teams intersects the viewer's team set. This is
     * intentionally separate from the checklist pointer columns admin.TeamLeadID
     * (→ level 25) and admin.OpTeamLeadID (→ level 45).
     *
     * A viewer with no Team (TeamID NULL/empty and no extra rows) — or one not
     * present in $admins — is a team of one: just themselves. The viewer's own id
     * is always included, so the result is never empty (no `IN ()` SQL hazard).
     * Returns a sorted, de-duplicated list of ints.
     *
     * @param int   $admin_id        logged-in admin id
     * @param array $admins          admin rows; each needs ->AdminID, ->TeamID
     *                               and (optionally) ->Status (CI ->result()
     *                               objects). Rows with a Status other than 'Y'
     *                               are treated as inactive and excluded.
     * @param array $extra_team_rows admin_team link rows for multi-team leaders;
     *                               each needs ->AdminID and ->TeamID. Empty =>
     *                               legacy single-team behaviour. Accepts objects
     *                               or assoc arrays.
     * @return int[]
     */
    function team_member_admin_ids($admin_id, $admins, $extra_team_rows = array())
    {
        $admin_id = (int) $admin_id;

        $norm_team = function ($team) {
            return ($team === null || $team === '' || (int) $team <= 0) ? null : (int) $team;
        };

        // Build admin_id => set of extra team ids (team id used as a key so the
        // sets intersect with array_intersect_key).
        $extra = array();
        foreach ($extra_team_rows as $r) {
            $aid = (int) (is_object($r) ? $r->AdminID : $r['AdminID']);
            $tid = $norm_team(is_object($r) ? $r->TeamID : $r['TeamID']);
            if ($aid > 0 && $tid !== null) {
                $extra[$aid][$tid] = true;
            }
        }

        // Resolve the VIEWER's team set = their primary team ∪ every extra team
        // they oversee. Extra teams widen only the viewer's own view — they do
        // NOT pull the viewer into other teams' member sets (membership below is
        // matched on each admin's single primary team), so a plain member's scope
        // is unchanged by a leader also overseeing their team.
        $viewer_teams = isset($extra[$admin_id]) ? $extra[$admin_id] : array();
        foreach ($admins as $a) {
            if ((int) $a->AdminID === $admin_id) {
                $primary = $norm_team($a->TeamID);
                if ($primary !== null) {
                    $viewer_teams[$primary] = true;
                }
                break;
            }
        }

        $ids = array($admin_id); // self is always in scope
        if (!empty($viewer_teams)) {
            foreach ($admins as $a) {
                $active  = !isset($a->Status) || $a->Status === 'Y';
                $primary = $norm_team($a->TeamID);
                if ($active && $primary !== null && isset($viewer_teams[$primary])) {
                    $ids[] = (int) $a->AdminID;
                }
            }
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return $ids;
    }
}

if (!function_exists('team_member_ids_from_db')) {
    /**
     * DB-backed convenience over team_member_admin_ids(): fetches the admin roster
     * and the admin_team link rows via raw query() (so it never flushes a
     * query-builder state the caller is mid-way through assembling) and resolves
     * the viewer's team-scoped member ids. Degrades to single-team behaviour when
     * the admin_team table has not been migrated yet.
     *
     * @param object $db       a CodeIgniter DB handle ($this->db)
     * @param int    $admin_id logged-in admin id
     * @return int[]
     */
    function team_member_ids_from_db($db, $admin_id)
    {
        $admins = $db->query('SELECT AdminID, TeamID, Status FROM admin')->result();
        $extra  = $db->table_exists('admin_team')
            ? $db->query('SELECT AdminID, TeamID FROM admin_team')->result()
            : array();
        return team_member_admin_ids($admin_id, $admins, $extra);
    }
}
