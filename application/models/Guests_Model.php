<?php
class Guests_Model extends CI_Model
{
	// Which source(s) this request reads: 'guest' (Guest List, booking guests),
	// 'ghl' (GHL Leads, synced only), 'manual' (Manual Leads, hand-entered only)
	// or 'all' (campaign picker, both). Set once per request by the controller.
	private $mode = 'guest';

	// > 0 when the campaign audience picker is reading for a specific campaign
	// being edited: every listing branch then drops people opted out of THAT
	// campaign's picker (campaign_hidden_guests row for this id). 0 = off (normal
	// listings, and Create mode where no campaign exists yet). Keyed by dedup_key.
	private $campaign_hidden_id = 0;

	/**
	 * Lock the listing to one source. Controllers call this before Read/Count so
	 * the Guest List and GHL Leads pages each read only their own branch; the
	 * campaign picker passes 'all' to read booking guests and leads together.
	 */
	function Set_Mode($mode)
	{
		$this->mode = in_array($mode, array('ghl', 'manual', 'all'), true) ? $mode : 'guest';
		return $this;
	}

	/**
	 * Scope the next Read/Count to a campaign's audience picker: people opted out
	 * of THIS campaign's picker disappear from every branch (booking guest / GHL /
	 * leader / customer). Pass 0 (Create mode / normal listings) to leave it off.
	 */
	function Exclude_Campaign_Hidden($campaign_id)
	{
		$this->campaign_hidden_id = (int) $campaign_id;
		return $this;
	}

	/**
	 * The per-campaign " AND NOT EXISTS (...) " exclusion for a branch, as
	 * [sql, params]. Empty when off (id <= 0). $key_expr is the branch's trusted
	 * dedup-key SQL expression (gl.dedup_key, the GHL/customer key, …); the bound
	 * campaign id must be pushed onto the branch's param list at the call site so
	 * it stays aligned with the '?' in the appended SQL.
	 */
	private function Campaign_Hidden_Exclusion($key_expr)
	{
		if ($this->campaign_hidden_id <= 0) {
			return array('', array());
		}
		$sql = " AND NOT EXISTS (SELECT 1 FROM campaign_hidden_guests chg
			WHERE chg.CampaignID = ? AND chg.DedupKey = {$key_expr}) ";
		return array($sql, array($this->campaign_hidden_id));
	}

	private function Dedup_Key_Expr()
	{
		return "gl.dedup_key";
	}

	/**
	 * The key the booking branch GROUPS on (NOT the one it displays). A stored
	 * dedup_key only means "same person" when it is a real phone (a run of 7+
	 * digits) or an email (has '@'). Junk typed into Mobile/Email — "-", "na",
	 * "tba", "xxx", "0", … — normalises to that same junk string and would
	 * collapse hundreds of unrelated guests into ONE row, whose Destinations then
	 * concat into a giant list (the reported bug). For those, fall back to a
	 * per-guest-row unique key so each junk-keyed guest stays its own row.
	 *
	 * The DISPLAYED dedup_key column stays the raw {$dedup} (see the outer
	 * SELECT), so inline-edit / remarks / campaign keying is unchanged.
	 */
	private function Dedup_Group_Key_Expr($dedup)
	{
		return "CASE
			WHEN {$dedup} REGEXP '[0-9]{7,}' OR {$dedup} LIKE '%@%'
				THEN {$dedup}
			ELSE CONVERT(CONCAT('gl:', gl.GuestListID) USING utf8mb4) COLLATE utf8mb4_unicode_ci
		END";
	}

	/**
	 * Append a multi-select filter as an " AND {$column} IN (?, ?, …) " fragment
	 * to $where and push its values onto $params. No-op when nothing is selected.
	 * $column is a trusted, code-supplied SQL expression (never user input);
	 * every value is bound as a placeholder.
	 */
	private function Append_In_Clause(&$where, &$params, $column, $raw)
	{
		$values = guest_list_multi_values($raw);
		if(empty($values)) {
			return;
		}
		$placeholders = implode(',', array_fill(0, count($values), '?'));
		$where .= " AND {$column} IN ({$placeholders}) ";
		foreach($values as $v) {
			$params[] = $v;
		}
	}

	private function Ghl_Dedup_Key_Expr()
	{
		return "(COALESCE(gc.dedup_key, CONCAT('ghl:', gc.id)) COLLATE utf8mb4_unicode_ci)";
	}

	private function Build_Branches()
	{
		$dedup     = $this->Dedup_Key_Expr();
		$gc_dedup  = $this->Ghl_Dedup_Key_Expr();

		$q_raw     = trim((string)$this->input->get('q'));
		$has_q     = $q_raw !== '';
		$like      = $has_q ? '%' . $q_raw . '%' : null;

		$this->load->helper('guest_contact');

		// Each page reads ONE source (see Set_Mode); a filter that can never match
		// that source still drops it to empty via the shared suppression predicates.
		$get       = $this->input->get();
		$run       = guest_list_branches_to_run($this->mode, $get);
		$run_bookings = $run['bookings'];
		$run_ghl      = $run['ghl'];

		$booking = null;
		if($run_bookings) {
			// This branch always hides cancelled bookings: the "Has cancelled BC"
			// segment is customer-only now (it suppresses this branch entirely), so
			// the predicate only ever resolves to the default hide.
			$where = " WHERE b.Status != 'N' AND " . guest_list_cancel_predicate($get, 'b.CancelStatus')
				. guest_list_lock_predicate($this->mode, 'b.LockStatus')
				. guest_list_member_only_predicate($this->mode, $this->Leader_Match_Expr()) . "
				AND gl.dedup_key IS NOT NULL
				AND (
					NULLIF(TRIM(gl.Name), '')     IS NOT NULL
					OR NULLIF(TRIM(gl.LastName), '') IS NOT NULL
					OR NULLIF(TRIM(gl.Mobile), '')   IS NOT NULL
					OR NULLIF(TRIM(gl.Email), '')    IS NOT NULL
				) ";
			$b_params = array();

			if(in_array($this->session->userdata('level'), array(20, 50))) {
				$where     .= " AND b.SalesAgent = ? ";
				$b_params[] = $this->session->userdata('admin_id');
			}

			$booking_range = guest_list_parse_date_range($this->input->get('booking_date'));
			if($booking_range !== null) {
				$where     .= " AND b.InsertDate >= ? AND b.InsertDate < DATE_ADD(?, INTERVAL 1 DAY) ";
				$b_params[] = $booking_range[0];
				$b_params[] = $booking_range[1];
			}

			$travel_range = guest_list_parse_date_range($this->input->get('travel_date'));
			if($travel_range !== null) {
				$where     .= " AND b.StartDate <= ? AND b.EndDate >= ? ";
				$b_params[] = $travel_range[1];
				$b_params[] = $travel_range[0];
			}

			// DOB is a booking-only, born-between calendar range on the guest's
			// date of birth. DateOfBirth is a DATE column, so an inclusive
			// >= start AND <= end matches exactly (NULL / 0000-00-00 never match).
			$dob_range = guest_list_parse_date_range($this->input->get('dob'));
			if($dob_range !== null) {
				$where     .= " AND gl.DateOfBirth >= ? AND gl.DateOfBirth <= ? ";
				$b_params[] = $dob_range[0];
				$b_params[] = $dob_range[1];
			}

			// Campaign / Follow date ranges match the guest's remark log: a guest
			// appears when ANY of their active remarks (keyed by dedup_key, so it
			// spans all their bookings) falls in the picked range. EXISTS keeps it
			// a row-level predicate the GROUP BY never has to see.
			$campaign_range = guest_list_parse_date_range($this->input->get('campaign_date'));
			if($campaign_range !== null) {
				$where     .= " AND EXISTS (SELECT 1 FROM guest_remarks gr
					WHERE gr.Status = 'Y' AND gr.dedup_key = gl.dedup_key
					AND gr.CampaignDate >= ? AND gr.CampaignDate <= ?) ";
				$b_params[] = $campaign_range[0];
				$b_params[] = $campaign_range[1];
			}

			$follow_range = guest_list_parse_date_range($this->input->get('follow_date'));
			if($follow_range !== null) {
				$where     .= " AND EXISTS (SELECT 1 FROM guest_remarks gr
					WHERE gr.Status = 'Y' AND gr.dedup_key = gl.dedup_key
					AND gr.FollowDate >= ? AND gr.FollowDate <= ?) ";
				$b_params[] = $follow_range[0];
				$b_params[] = $follow_range[1];
			}

			// Birthday is a recurring month/day match on gl.DateOfBirth that
			// ignores the birth year (today / this month / a chosen month), so
			// it surfaces guests to greet regardless of how old they turn.
			$birthday = guest_list_birthday_clause($this->input->get('birthday'));
			if($birthday !== null) {
				$where    .= $birthday['sql'];
				foreach($birthday['params'] as $bp) {
					$b_params[] = $bp;
				}
			}

			// (The "Customer type" value segments — booking-lead, purchase count,
			// lifetime value, consecutive years, cancelled BC — now target the
			// customer master and are applied in Build_Customer_Branch; they suppress
			// this whole branch, so they are not built here.)

			// EXCEPTION — "Family with kids": a child/infant lives on the booking, so
			// a booking-guest row CAN satisfy it. So when the picker's Type is left at
			// "--ALL TYPES--" (or Booking Guest) this branch returns guests whose own
			// trip carried a kid, instead of forcing the customer master. GHL leads
			// (no booking) stay suppressed.
			if(guest_list_flag_on($get, 'family_kids')) {
				$where .= " AND (COALESCE(b.Children, 0) > 0 OR COALESCE(b.Infant, 0) > 0) ";
			}

			$booking_number = trim((string)$this->input->get('booking_number'));
			if($booking_number !== '') {
				$where     .= " AND b.BookingNumber LIKE ? ";
				$b_params[] = '%' . $booking_number . '%';
			}
			$contact_number = trim((string)$this->input->get('contact_number'));
			if($contact_number !== '') {
				// Match ONLY the guest's own gl.Mobile — the exact number shown in
				// the Contact Num column — so every returned row visibly contains
				// the searched digits. (A booking's leader/contact number that lives
				// only on b.Mobile / c.phone_number is intentionally NOT matched
				// here; the leader-fallback branch still surfaces such contacts when
				// the booking's guest list is empty.)
				$where     .= " AND gl.Mobile LIKE ? ";
				$b_params[] = '%' . $contact_number . '%';
			}
			$tl_clause = guest_list_team_leader_clause($this->input->get());
			if($tl_clause !== null) {
				$where     .= $tl_clause['sql'];
				$b_params[] = $tl_clause['param'];
			}
			// Clicking a Team Leader name drills into that leader's exact
			// booking(s) by BookingID, so the list shows only that booking's
			// team members — not every booking the leader has ever run.
			$this->Append_In_Clause($where, $b_params, 'b.BookingID', $this->input->get('booking_id'));
			$email = trim((string)$this->input->get('email'));
			if($email !== '') {
				$where     .= " AND gl.Email LIKE ? ";
				$b_params[] = '%' . $email . '%';
			}
			// "Has email address" campaign filter: keep only guests carrying an
			// email so an email blast has someone to reach.
			if(guest_list_flag_on($get, 'has_email')) {
				$where .= " AND NULLIF(TRIM(gl.Email), '') IS NOT NULL ";
			}
			// Customer Code / Date Creation live on the linked customer master
			// (c.CustomerCode / c.created_at), so they filter the customer row.
			$customer_code = trim((string)$this->input->get('customer_code'));
			if($customer_code !== '') {
				$where     .= " AND c.CustomerCode LIKE ? ";
				$b_params[] = '%' . $customer_code . '%';
			}
			$create_range = guest_list_parse_date_range($this->input->get('create_date'));
			if($create_range !== null) {
				$where     .= " AND c.created_at >= ? AND c.created_at < DATE_ADD(?, INTERVAL 1 DAY) ";
				$b_params[] = $create_range[0];
				$b_params[] = $create_range[1];
			}
			// Dropdown filters are multi-select (see views/guests/index.php): each
			// may arrive as several values, so they filter via an IN (...) list.
			$this->Append_In_Clause($where, $b_params, 'b.Destination',                    $this->input->get('destination'));
			$this->Append_In_Clause($where, $b_params, 'b.SalesAgent',                     $this->input->get('sales_agent'));
			$this->Append_In_Clause($where, $b_params, 'b.Source',                         $this->input->get('source'));
			$this->Append_In_Clause($where, $b_params, 'c.customer_type',                  $this->input->get('customer_type'));
			$this->Append_In_Clause($where, $b_params, 'cn.Country',                       $this->input->get('nationality'));
			$this->Append_In_Clause($where, $b_params, 'gl.Gender',                        $this->input->get('gender'));
			$this->Append_In_Clause($where, $b_params, 'gl.Type',                          $this->input->get('guest_type'));
			$this->Append_In_Clause($where, $b_params, 'COALESCE(gl.ChatLanguage, c.ChatLanguage, b.ChatLanguage)', $this->input->get('language'));
			// Booking Type — BC / PI / QU (booking.BookingConfirmationTitle:
			// 'BOOKING CONFIRMATION' / 'PROFORMA INVOICE' / 'QUOTATION'). The
			// campaign picker defaults this to BOOKING CONFIRMATION so a roster is
			// drawn from confirmed bookings only; PI / QU are opt-in. The Guest List
			// page never sends bc_type, so this stays a no-op there.
			$this->Append_In_Clause($where, $b_params, 'b.BookingConfirmationTitle',   $this->input->get('bc_type'));
			// "Campaign" filter keys off the campaign_guests roster snapshot.
			// Multi-select over ANY of the picked campaigns; mode = include keeps
			// guests who joined them, exclude drops them. Keyed on dedup_key so it
			// matches the same person across every one of their bookings.
			$joined_campaigns = guest_list_multi_values($this->input->get('joined_campaign'));
			if(!empty($joined_campaigns)) {
				$ph  = implode(',', array_fill(0, count($joined_campaigns), '?'));
				$neg = $this->input->get('campaign_mode') === 'exclude' ? 'NOT ' : '';
				$where .= " AND {$neg}EXISTS (SELECT 1 FROM campaign_guests cg
					WHERE cg.CampaignID IN ({$ph}) AND cg.DedupKey = gl.dedup_key) ";
				foreach($joined_campaigns as $cid) { $b_params[] = (int)$cid; }
			}
			list($chg_sql, $chg_par) = $this->Campaign_Hidden_Exclusion('gl.dedup_key');
			$where .= $chg_sql;
			foreach($chg_par as $p) { $b_params[] = $p; }
			if($has_q) {
				// Search Name matches the guest's OWN name only (first, last, or the
				// two joined). To search by the booking's team leader (b.Customer, the
				// name on the BC form) use the dedicated "Team Leader" column filter.
				$where     .= " AND ( gl.Name LIKE ? OR gl.LastName LIKE ? OR CONCAT_WS(' ', gl.Name, gl.LastName) LIKE ? ) ";
				$b_params[] = $like;
				$b_params[] = $like;
				$b_params[] = $like;
			}

			$from_joins_where = "
	FROM booking b
	STRAIGHT_JOIN guest_list gl ON gl.BookingID = b.BookingID AND gl.Status = 'Y'
	LEFT JOIN customer     c   ON c.CustomerID    = b.CustomerID
	LEFT JOIN admin        a   ON a.AdminID       = b.SalesAgent
	LEFT JOIN source       s   ON s.SourceID      = b.Source
	LEFT JOIN country_code cn  ON cn.CountryCodeID = gl.Nationality
	LEFT JOIN country_code ccp ON ccp.CountryCodeID = gl.CountryCodeID
	LEFT JOIN category     cat ON cat.CategoryID  = b.Destination
	{$where}
			";

			// Guest Role and Num of Pax are per-guest aggregates, so they filter
			// the GROUP BY result via HAVING (not the row-level WHERE). The
			// expressions below reuse columns produced by the windowed inner
			// subquery (IsLeader, BookingPax, booking_rn) so Read and Count agree.
			$having        = array();
			$having_params = array();

			// Guest Role is multi-select. "Lead" only affects the GHL branch, so
			// here we look at the two booking roles: picking exactly one narrows
			// the result; picking both (or neither) leaves booking guests unfiltered.
			$roles       = guest_list_multi_values($this->input->get('role'));
			$want_leader = in_array('Team Leader', $roles, true);
			$want_member = in_array('Team Member', $roles, true);
			if($want_leader && !$want_member)     { $having[] = "MAX(IsLeader) = 1"; }
			elseif($want_member && !$want_leader) { $having[] = "MAX(IsLeader) = 0"; }

			$pax_expr = "COALESCE(SUM(CASE WHEN booking_rn = 1 THEN BookingPax END), 0)";
			$pax_min  = trim((string)$this->input->get('pax_min'));
			$pax_max  = trim((string)$this->input->get('pax_max'));
			if($pax_min !== '' && is_numeric($pax_min)) {
				$having[]        = "{$pax_expr} >= ?";
				$having_params[] = (int)$pax_min;
			}
			if($pax_max !== '' && is_numeric($pax_max)) {
				$having[]        = "{$pax_expr} <= ?";
				$having_params[] = (int)$pax_max;
			}

			// (The "Customer type" value segments — purchase count, lifetime value,
			// consecutive years — are per-CUSTOMER aggregates now, applied in
			// Build_Customer_Branch; they suppress this branch, so no HAVING here.)

			$booking = array(
				'dedup'         => $dedup,
				'from'          => $from_joins_where,
				'params'        => $b_params,
				'having'        => empty($having) ? '' : ' HAVING ' . implode(' AND ', $having),
				'having_params' => $having_params,
			);
		}

		$ghl = null;
		if($run_ghl) {
			$g_params = array();
			$ghl_where = "";
			if($has_q) {
				$ghl_where .= " AND ( gc.first_name LIKE ? OR gc.last_name LIKE ? OR CONCAT_WS(' ', gc.first_name, gc.last_name) LIKE ? ) ";
				$g_params[] = $like;
				$g_params[] = $like;
				$g_params[] = $like;
			}

			// Booking-date filter matches a lead's captured date — the same
			// DATE(COALESCE(date_added, created_at)) shown in the listing.
			$ghl_range = guest_list_parse_date_range($this->input->get('booking_date'));
			if($ghl_range !== null) {
				$ghl_where .= " AND DATE(COALESCE(gc.date_added, gc.created_at)) >= ?
					AND DATE(COALESCE(gc.date_added, gc.created_at)) < DATE_ADD(?, INTERVAL 1 DAY) ";
				$g_params[] = $ghl_range[0];
				$g_params[] = $ghl_range[1];
			}

			$dob_range = guest_list_parse_date_range($this->input->get('dob'));
			if($dob_range !== null) {
				$ghl_where .= " AND gc.date_of_birth >= ? AND gc.date_of_birth <= ? ";
				$g_params[] = $dob_range[0];
				$g_params[] = $dob_range[1];
			}

			// Contact Number / Email are not booking-only — a lead carries both,
			// so they filter the GHL branch too (gc.phone / gc.email).
			$contact_number = trim((string)$this->input->get('contact_number'));
			if($contact_number !== '') {
				$ghl_where .= " AND gc.phone LIKE ? ";
				$g_params[] = '%' . $contact_number . '%';
			}
			$email = trim((string)$this->input->get('email'));
			if($email !== '') {
				$ghl_where .= " AND gc.email LIKE ? ";
				$g_params[] = '%' . $email . '%';
			}
			// "Has email address" campaign filter — keep only leads with an email.
			if(guest_list_flag_on($get, 'has_email')) {
				$ghl_where .= " AND NULLIF(TRIM(gc.email), '') IS NOT NULL ";
			}

			// These GHL lead attributes may be stored either on ghl_contacts
			// (manual/native fields) or in synced GHL custom fields.
			$this->Append_In_Clause($ghl_where, $g_params, "COALESCE(NULLIF(TRIM(gcv.gender), ''), gc.gender)", $this->input->get('gender'));
			$this->Append_In_Clause($ghl_where, $g_params, "COALESCE(NULLIF(TRIM(gcv.language), ''), gc.chat_language)", $this->input->get('language'));
			$this->Append_In_Clause($ghl_where, $g_params, "COALESCE(NULLIF(TRIM(gcv.race), ''), gc.race)", $this->input->get('race'));
			$this->Append_In_Clause($ghl_where, $g_params, "COALESCE(NULLIF(TRIM(gcv.nationality), ''), gc.nationality)", $this->input->get('nationality'));

			// Tag is lead-only too. A tag can live on the contact (gc.tags_json)
			// or per-conversation (gt.tags_concat, several JSON arrays newline-
			// joined). Match ANY of the picked tags across BOTH: JSON_SEARCH on
			// the contact array, plus a quoted LIKE on the concatenated text.
			$tags = guest_list_multi_values($this->input->get('tags'));
			if(!empty($tags)) {
				$ors = array();
				foreach($tags as $t) {
					$ors[]      = "(JSON_SEARCH(gc.tags_json, 'one', ?, '!') IS NOT NULL OR gt.tags_concat LIKE ? ESCAPE '!')";
					$g_params[] = $this->db->escape_like_str($t);
					$g_params[] = '%"' . $this->db->escape_like_str($t) . '"%';
				}
				$ghl_where .= " AND (" . implode(' OR ', $ors) . ") ";
			}

			// Manual-lead business fields (Manual Leads page). These live only on
			// hand-entered rows: Nature of Business is a free-text LIKE; Number of
			// Pax / Client Type / State are multi-select IN over the stored label.
			$nature = trim((string)$this->input->get('nature_of_business'));
			if($nature !== '') {
				$ghl_where .= " AND gc.nature_of_business LIKE ? ";
				$g_params[] = '%' . $nature . '%';
			}
			$this->Append_In_Clause($ghl_where, $g_params, 'gc.number_of_pax', $this->input->get('number_of_pax'));
			$this->Append_In_Clause($ghl_where, $g_params, 'gc.client_type',   $this->input->get('client_type'));
			$this->Append_In_Clause($ghl_where, $g_params, 'gc.state',         $this->input->get('state'));
			// Source (Manual Leads): free-text source label (gc.source, e.g. "Facebook").
			// Uses its own param name (manual_source) — NOT the booking-branch 'source'
			// (numeric SourceID), which is in the GHL-branch suppression list and would
			// otherwise empty this page when set.
			$this->Append_In_Clause($ghl_where, $g_params, 'gc.source',        $this->input->get('manual_source'));
			// Created By (Manual Leads): filter on the creator admin (gc.created_by = admin.AdminID).
			$this->Append_In_Clause($ghl_where, $g_params, 'gc.created_by',    $this->input->get('created_by'));

			// Lead Status (Manual Leads): a lead's status lives ONLY in the dated Lead
			// Status Updates log (lead_status_log). Two related filters read it:
			//
			//  - Lead Status (no date): matches only on a lead's LATEST status — the
			//    newest active log entry by StatusDate then LogID (tie-break), keyed by
			//    the same dedup_key the listing shows — never a status it merely held
			//    once. A lead with no log entries has no status and cannot match.
			//  - Lead Status Date: matches a lead that had ANY active log entry whose
			//    StatusDate falls in the range (inclusive) — even if the status later
			//    changed. When a Lead Status is ALSO picked alongside a date, the two
			//    combine into a SINGLE any-entry test (that dated entry must carry one
			//    of the picked statuses) — deliberately NOT the latest-status rule, so
			//    "moved to X during this window" still matches if X is no longer current.
			// Both are multi-select on the status.
			$lead_statuses  = guest_list_multi_values($this->input->get('lead_status'));
			$lead_status_dt = guest_list_parse_date_range($this->input->get('lead_status_date'));
			if($lead_status_dt !== null) {
				$ghl_where .= " AND EXISTS (SELECT 1 FROM lead_status_log lsl
						WHERE lsl.Status = 'Y' AND lsl.dedup_key = {$gc_dedup}
						AND lsl.StatusDate >= ? AND lsl.StatusDate <= ? ";
				$g_params[] = $lead_status_dt[0];
				$g_params[] = $lead_status_dt[1];
				if(!empty($lead_statuses)) {
					$ph = implode(',', array_fill(0, count($lead_statuses), '?'));
					$ghl_where .= " AND lsl.LeadStatus IN ({$ph}) ";
					foreach($lead_statuses as $st) { $g_params[] = $st; } // any dated entry IN
				}
				$ghl_where .= ") ";
			} elseif(!empty($lead_statuses)) {
				$ph = implode(',', array_fill(0, count($lead_statuses), '?'));
				$ghl_where .= " AND EXISTS (SELECT 1 FROM lead_status_log lsl
						WHERE lsl.Status = 'Y' AND lsl.dedup_key = {$gc_dedup}
						AND lsl.LeadStatus IN ({$ph})
						AND NOT EXISTS (SELECT 1 FROM lead_status_log lsl2
							WHERE lsl2.Status = 'Y' AND lsl2.dedup_key = {$gc_dedup}
							AND (lsl2.StatusDate > lsl.StatusDate
								OR (lsl2.StatusDate = lsl.StatusDate AND lsl2.LogID > lsl.LogID)))) ";
				foreach($lead_statuses as $st) { $g_params[] = $st; } // latest-log IN
			}

			// "Campaign" filter over leads in a campaign roster; multi-select over
			// any of the picked campaigns, include/exclude via campaign_mode.
			$joined_campaigns = guest_list_multi_values($this->input->get('joined_campaign'));
			if(!empty($joined_campaigns)) {
				$ph  = implode(',', array_fill(0, count($joined_campaigns), '?'));
				$neg = $this->input->get('campaign_mode') === 'exclude' ? 'NOT ' : '';
				$ghl_where .= " AND {$neg}EXISTS (SELECT 1 FROM campaign_guests cg
					WHERE cg.CampaignID IN ({$ph}) AND cg.DedupKey = {$gc_dedup}) ";
				foreach($joined_campaigns as $cid) { $g_params[] = (int)$cid; }
			}
			list($chg_sql, $chg_par) = $this->Campaign_Hidden_Exclusion($gc_dedup);
			$ghl_where .= $chg_sql;
			foreach($chg_par as $p) { $g_params[] = $p; }

			// Split the shared GHL branch between its two pages, and enforce the
			// Manual Leads page's per-creator privacy: mode 'ghl' hides manual rows,
			// mode 'manual' keeps only manual rows and (for non view-all roles)
			// restricts to the viewer's own created leads. See the pure helper for
			// the exact predicate + bound params.
			list($ls_sql, $ls_params) = guest_list_ghl_lead_source_scope(
				$this->mode,
				$this->session->userdata('level'),
				$this->session->userdata('admin_id')
			);
			if($ls_sql !== '') {
				$ghl_where .= $ls_sql;
				foreach($ls_params as $p) { $g_params[] = $p; }
			}

			// The campaign picker (mode 'all') runs this branch ALONGSIDE the booking
			// branch, so a person who is BOTH a booking guest and a GHL lead on the
			// same phone would appear twice — and the name+IC display-merge can't
			// collapse them (the booking row carries an IC → merge_key "ic:name|IC",
			// the lead has none → merge_key = bare dedup_key). Suppress the lead when
			// its phone already belongs to a visible (non-cancelled, in-scope) booking
			// guest; the richer booking row represents them. This restores the anti-join
			// the old merged listing had (dropped when GHL Leads became its own page);
			// the standalone 'ghl'/'manual' pages run this branch alone and skip it.
			if($this->mode === 'all') {
				$ghl_where .= " AND NOT EXISTS (SELECT 1 FROM guest_list glx
					JOIN booking bx ON bx.BookingID = glx.BookingID
					WHERE glx.Status = 'Y' AND bx.Status != 'N'
					AND " . guest_list_cancel_predicate($get, 'bx.CancelStatus') . "
					AND glx.dedup_key = {$gc_dedup} ";
				if(in_array($this->session->userdata('level'), array(20, 50))) {
					$ghl_where .= " AND bx.SalesAgent = ? ";
					$g_params[] = $this->session->userdata('admin_id');
				}
				$ghl_where .= ") ";
			}

			// GHL Leads is its own page now, so the query reads ghl_contacts only —
			// no booking/guest_list scan. (The old merged listing anti-joined the
			// two to avoid showing one person twice; separate pages don't need it.)
			// Tags and a name fallback both live per-conversation in
			// ghl_conversations and a contact can hold several conversations, so
			// pre-aggregate per contact to keep this one-row-per-contact:
			//  - tags_concat: every tag array newline-joined (flattened + de-duped
			//    in PHP by ghl_lead_tags_parse).
			//  - conv_full_name / conv_contact_name: a name for the ~contacts whose
			//    ghl_contacts.first_name is blank (often a business, e.g.
			//    "BERDAYA MARKETING SDN BHD"). The REGEXP '[A-Za-z]' guard drops
			//    GHL's phone-formatted contact_name ("012-710 3413") which would
			//    just duplicate the phone column.
			$from_joins_where = "
FROM ghl_contacts gc
LEFT JOIN (
	SELECT v.contact_id,
		MAX(CASE WHEN cf.field_key = 'contact.gender' THEN v.value_text END) AS gender,
		MAX(CASE WHEN cf.field_key = 'contact.language' THEN v.value_text END) AS language,
		MAX(CASE WHEN cf.field_key = 'contact.race' THEN v.value_text END) AS race,
		MAX(CASE WHEN cf.field_key = 'contact.nationality' THEN v.value_text END) AS nationality
	FROM ghl_contact_custom_field_values v
	INNER JOIN ghl_custom_fields cf ON cf.field_id = v.field_id
	WHERE cf.field_key IN ('contact.gender', 'contact.language', 'contact.race', 'contact.nationality')
	GROUP BY v.contact_id
) gcv ON gcv.contact_id = gc.contact_id
LEFT JOIN (
	SELECT contact_id,
		GROUP_CONCAT(CASE WHEN tags_json IS NOT NULL AND JSON_LENGTH(tags_json) > 0 THEN tags_json END SEPARATOR '\n') AS tags_concat,
		MAX(CASE WHEN full_name    REGEXP '[A-Za-z]' THEN NULLIF(TRIM(full_name), '')    END) AS conv_full_name,
		MAX(CASE WHEN contact_name REGEXP '[A-Za-z]' THEN NULLIF(TRIM(contact_name), '') END) AS conv_contact_name
	FROM ghl_conversations
	GROUP BY contact_id
) gt ON gt.contact_id = gc.contact_id
LEFT JOIN admin a ON a.AdminID = gc.created_by
WHERE 1 = 1
{$ghl_where}
			";

			$ghl = array(
				'dedup'  => $gc_dedup,
				'from'   => $from_joins_where,
				'params' => $g_params,
			);
		}

		return array($booking, $ghl);
	}

	/**
	 * The per-(guest-row) windowed subquery shared by the listing (Read_Guests)
	 * and the count (Count_Guests). It exposes IsLeader / BookingPax /
	 * booking_rn / dedup_key so an outer GROUP BY dedup_key can aggregate them
	 * into Role and Num of Pax — and the HAVING built in Build_Branches filters
	 * on exactly those, identically in both paths.
	 */
	/**
	 * The boolean SQL that flags a guest_list row as its booking's Team Leader:
	 * the guest's phone (dedup_key = last 9 digits) is the booking's own contact
	 * number (b.Mobile, else the customer's phone_number). One source of truth so
	 * the IsLeader column and the "members only" WHERE filter can never drift.
	 */
	private function Leader_Match_Expr()
	{
		return "gl.dedup_key = COALESCE(
			NULLIF(RIGHT(REGEXP_REPLACE(IFNULL(b.Mobile, ''),       '[^0-9]', ''), 9), ''),
			NULLIF(RIGHT(REGEXP_REPLACE(IFNULL(c.phone_number, ''), '[^0-9]', ''), 9), '')
		)";
	}

	private function Booking_Windowed_Select($dedup, $from)
	{
		$group = $this->Dedup_Group_Key_Expr($dedup);
		// rn=1 is the row that represents this person — its Name/Contact/Email/…
		// win the MAX(CASE WHEN rn=1 …) picks in the outer aggregate. Newest
		// booking first, but when two guests in the SAME booking share a phone (a
		// child put under the parent's number → same dedup_key) InsertDate+BookingID
		// tie, so break it: prefer the ADULT (the phone's real owner/leader), then
		// the earliest-entered row. Without these tiebreakers MySQL broke the tie
		// arbitrarily and could surface the child/team-member instead of the leader.
		return "
	SELECT
		{$dedup} AS dedup_key,
		{$group} AS group_key,
		TRIM(gl.Name) AS display_name,
		gl.Mobile        AS ContactNum,
		ccp.CountryCode  AS CallingCode,
		gl.Email,
		COALESCE(gl.ChatLanguage, c.ChatLanguage, b.ChatLanguage) AS ChatLanguage,
		a.Name           AS SalesAgentName,
		b.Customer       AS BookingCustomer,
		b.BookingID      AS BookingID,
		s.Name           AS SourceName,
		c.customer_type  AS customer_type,
		cat.Name         AS Destination,
		cn.Country       AS Nationality,
		gl.Gender,
		gl.IdentificationNumber AS IdentificationNumber,
		gl.Type          AS GuestType,
		gl.DateOfBirth,
		b.Token          AS Token,
		b.InsertDate     AS BookingDate,
		c.CustomerCode   AS CustomerCode,
		c.AltName        AS AltName,
		c.CustomerID     AS CustomerID,
		c.created_at     AS CustomerCreatedAt,
		b.StartDate      AS TravelStart,
		b.EndDate        AS TravelEnd,
		CASE WHEN " . $this->Leader_Match_Expr() . " THEN 1 ELSE 0 END AS IsLeader,
		(COALESCE(b.Adult, 0) + COALESCE(b.Children, 0) + COALESCE(b.Infant, 0)) AS BookingPax,
		COALESCE(b.NetTotal, 0) AS BookingNetTotal,
		ROW_NUMBER() OVER (
			PARTITION BY {$group}
			ORDER BY b.InsertDate DESC, b.BookingID DESC,
				CASE WHEN gl.Type = 'ADULT' THEN 0 ELSE 1 END ASC,
				gl.GuestListID ASC
		) AS rn,
		ROW_NUMBER() OVER (
			PARTITION BY {$group}, b.BookingID
			ORDER BY gl.GuestListID
		) AS booking_rn
	{$from}
		";
	}

	/**
	 * The "leader fallback" branch: a Team Leader row built straight from the
	 * booking's own contact for every active booking whose leader phone is NOT
	 * already a filled guest_list row. This surfaces the leaders of bookings whose
	 * guest list was never filled in (blank rows → NULL dedup_key → invisible to
	 * the normal branch) so they stay pickable for a campaign. The NOT EXISTS
	 * anti-join against the whole filled set guarantees a leader is never shown
	 * twice — if their phone already belongs to a real guest anywhere, that guest
	 * carries them instead. Returns null when the branch is off (wrong mode /
	 * bookings suppressed / a guest-only filter that a leader can't satisfy).
	 */
	private function Build_Leader_Fallback_Branch()
	{
		$this->load->helper('guest_contact');
		$get = $this->input->get();
		$run = guest_list_branches_to_run($this->mode, $get);
		if(!$run['bookings']) {
			return null;
		}
		if(guest_list_leader_fallback_suppressed_by_filters($get)) {
			return null;
		}
		// This branch exists only to surface booking Team Leaders. The Guest List
		// dashboard (mode 'guest') lists members only — leaders live on the Customer
		// page — so drop the whole fallback there. The campaign picker ('all') still
		// needs leaders as an audience, so it keeps it.
		if($this->mode === 'guest') {
			return null;
		}

		$key = $this->Booking_Leader_Key_Expr();

		$where = " WHERE b.Status != 'N' AND " . guest_list_cancel_predicate($get, 'b.CancelStatus') . "
			AND {$key} IS NOT NULL
			AND NOT EXISTS (
				SELECT 1 FROM guest_list glx
				WHERE glx.Status = 'Y' AND glx.dedup_key = {$key}
			) ";
		$params = array();

		if(in_array($this->session->userdata('level'), array(20, 50))) {
			$where     .= " AND b.SalesAgent = ? ";
			$params[]   = $this->session->userdata('admin_id');
		}

		$booking_range = guest_list_parse_date_range($this->input->get('booking_date'));
		if($booking_range !== null) {
			$where   .= " AND b.InsertDate >= ? AND b.InsertDate < DATE_ADD(?, INTERVAL 1 DAY) ";
			$params[] = $booking_range[0];
			$params[] = $booking_range[1];
		}

		$travel_range = guest_list_parse_date_range($this->input->get('travel_date'));
		if($travel_range !== null) {
			$where   .= " AND b.StartDate <= ? AND b.EndDate >= ? ";
			$params[] = $travel_range[1];
			$params[] = $travel_range[0];
		}

		// (The per-customer value segments suppress this fallback branch, so none of
		// them are built here — except "Family with kids", which reads the leader's
		// own booking child/infant count, so a leader whose trip carried a kid stays
		// pickable under an "All types" campaign audience.)
		if(guest_list_flag_on($get, 'family_kids')) {
			$where .= " AND (COALESCE(b.Children, 0) > 0 OR COALESCE(b.Infant, 0) > 0) ";
		}

		$booking_number = trim((string)$this->input->get('booking_number'));
		if($booking_number !== '') {
			$where   .= " AND b.BookingNumber LIKE ? ";
			$params[] = '%' . $booking_number . '%';
		}

		// Contact Number matches the leader's own booking phone (there is no
		// guest_list row yet), so it filters the booking/customer contact.
		$contact_number = trim((string)$this->input->get('contact_number'));
		if($contact_number !== '') {
			$where   .= " AND COALESCE(b.Mobile, c.phone_number) LIKE ? ";
			$params[] = '%' . $contact_number . '%';
		}

		$tl_clause = guest_list_team_leader_clause($this->input->get());
		if($tl_clause !== null) {
			$where   .= $tl_clause['sql'];
			$params[] = $tl_clause['param'];
		}

		// Customer Code / Date Creation on the leader's linked customer master.
		$customer_code = trim((string)$this->input->get('customer_code'));
		if($customer_code !== '') {
			$where   .= " AND c.CustomerCode LIKE ? ";
			$params[] = '%' . $customer_code . '%';
		}
		$create_range = guest_list_parse_date_range($this->input->get('create_date'));
		if($create_range !== null) {
			$where   .= " AND c.created_at >= ? AND c.created_at < DATE_ADD(?, INTERVAL 1 DAY) ";
			$params[] = $create_range[0];
			$params[] = $create_range[1];
		}

		$this->Append_In_Clause($where, $params, 'b.BookingID',   $this->input->get('booking_id'));
		$this->Append_In_Clause($where, $params, 'b.Destination', $this->input->get('destination'));
		$this->Append_In_Clause($where, $params, 'b.SalesAgent',  $this->input->get('sales_agent'));
		$this->Append_In_Clause($where, $params, 'b.Source',      $this->input->get('source'));
		$this->Append_In_Clause($where, $params, 'c.customer_type', $this->input->get('customer_type'));
		$this->Append_In_Clause($where, $params, "COALESCE(c.ChatLanguage, b.ChatLanguage)", $this->input->get('language'));
		// Booking Type (BC / PI / QU) — filter the leader's own booking too so the
		// synthesized leader row honours the campaign picker's default of BC-only.
		$this->Append_In_Clause($where, $params, 'b.BookingConfirmationTitle', $this->input->get('bc_type'));

		// "Campaign" filter over leaders in a campaign roster; multi-select over
		// any of the picked campaigns, include/exclude via campaign_mode. The
		// synthesized leader row is keyed on the booking's leader phone key.
		$joined_campaigns = guest_list_multi_values($this->input->get('joined_campaign'));
		if(!empty($joined_campaigns)) {
			$ph  = implode(',', array_fill(0, count($joined_campaigns), '?'));
			$neg = $this->input->get('campaign_mode') === 'exclude' ? 'NOT ' : '';
			$where .= " AND {$neg}EXISTS (SELECT 1 FROM campaign_guests cg
				WHERE cg.CampaignID IN ({$ph}) AND cg.DedupKey = {$key}) ";
			foreach($joined_campaigns as $cid) { $params[] = (int)$cid; }
		}
		list($chg_sql, $chg_par) = $this->Campaign_Hidden_Exclusion($key);
		$where .= $chg_sql;
		foreach($chg_par as $p) { $params[] = $p; }

		$q_raw = trim((string)$this->input->get('q'));
		if($q_raw !== '') {
			$where   .= " AND b.Customer LIKE ? ";
			$params[] = '%' . $q_raw . '%';
		}

		// (The per-customer value segments are applied in Build_Customer_Branch and
		// suppress this fallback branch, so it needs no HAVING of its own now.)
		$having        = array();
		$having_params = array();

		$from = "
	FROM booking b
	LEFT JOIN customer     c   ON c.CustomerID     = b.CustomerID
	LEFT JOIN admin        a   ON a.AdminID        = b.SalesAgent
	LEFT JOIN source       s   ON s.SourceID       = b.Source
	LEFT JOIN category     cat ON cat.CategoryID   = b.Destination
	LEFT JOIN country_code cc  ON cc.CountryCodeID = b.CountryCodeID
	{$where}
		";

		return array(
			'key'           => $key,
			'from'          => $from,
			'params'        => $params,
			'having'        => empty($having) ? '' : ' HAVING ' . implode(' AND ', $having),
			'having_params' => $having_params,
		);
	}

	/**
	 * The merged-listing SELECT for one leader-fallback group (one row per leader
	 * phone key). Column order MUST match the booking and GHL branch SELECTs so
	 * the UNION ALL lines up. Every non-key column is aggregated because a leader
	 * may run several unfilled bookings.
	 */
	private function Leader_Fallback_Select($key, $from, $having = '')
	{
		return "
	SELECT
		{$key} AS dedup_key,
		CONVERT(MAX(b.Customer) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Name,
		CONVERT(GROUP_CONCAT(DISTINCT NULLIF(TRIM(b.Customer), '') SEPARATOR '||') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS TeamLeader,
		CONVERT(GROUP_CONCAT(DISTINCT CASE WHEN NULLIF(TRIM(b.Customer), '') IS NOT NULL THEN CONCAT(b.BookingID, ':', TRIM(b.Customer)) END SEPARATOR '||') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS TeamLeaderBookings,
		CONVERT(MAX(COALESCE(b.Mobile, c.phone_number)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ContactNum,
		CONVERT(MAX(cc.CountryCode) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS CallingCode,
		CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS Email,
		CONVERT(MAX(COALESCE(c.ChatLanguage, b.ChatLanguage)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Language,
		CONVERT(MAX(a.Name) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS AgentName,
		CONVERT(MAX(s.Name) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Source,
		CONVERT(MAX(c.customer_type) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS CustomerType,
		CONVERT(GROUP_CONCAT(COALESCE(cat.Name, '-') ORDER BY DATE(b.InsertDate) SEPARATOR '||') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Destination,
		CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS Nationality,
		CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS Gender,
		CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS Race,
		CONVERT('ADULT' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS GuestType,
		CAST(NULL AS DATE) AS DOB,
		COALESCE(SUM(COALESCE(b.Adult, 0) + COALESCE(b.Children, 0) + COALESCE(b.Infant, 0)), 0) AS TotalPax,
		COALESCE(SUM(COALESCE(b.NetTotal, 0)), 0) AS TotalSales,
		CAST('Customer' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS Type,
		CONVERT(MAX(b.Token) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Token,
		CONVERT('Team Leader' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Role,
		CONVERT(GROUP_CONCAT(DISTINCT DATE(b.InsertDate) ORDER BY DATE(b.InsertDate) SEPARATOR ',') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS BookingDates,
		CONVERT(GROUP_CONCAT(DISTINCT CONCAT(DATE(b.StartDate), '|', IFNULL(DATE(b.EndDate), '')) ORDER BY CONCAT(DATE(b.StartDate), '|', IFNULL(DATE(b.EndDate), '')) SEPARATOR ',') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS TravelDates,
		CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS IC,
		CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS Tags,
		CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS ClientType,
		CONVERT(MAX(c.CustomerCode) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS CustomerCode,
		CONVERT(MAX(c.AltName) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS AltName,
		MAX(c.CustomerID) AS CustomerID,
		MAX(c.created_at) AS CustomerCreatedAt,
		CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS NatureOfBusiness,
		CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS LeadStatus,
		CAST(NULL AS DATE) AS LeadStatusDate,
		CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS State,
		CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS CreatedByName,
		MAX(b.InsertDate) AS RecencyAt
	{$from}
	GROUP BY {$key}
	{$having}
		";
	}

	private function Build_Merged_Sql_And_Params()
	{
		list($booking, $ghl) = $this->Build_Branches();
		$fallback = $this->Build_Leader_Fallback_Branch();

		$parts  = array();
		$params = array();

		if($booking !== null) {
			$dedup = $booking['dedup'];
			$parts[] = "
SELECT
	MAX(dedup_key) AS dedup_key,
	CONVERT(MAX(CASE WHEN rn = 1 THEN display_name   END) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Name,
	CONVERT(GROUP_CONCAT(DISTINCT CASE WHEN booking_rn = 1 THEN NULLIF(TRIM(BookingCustomer), '') END SEPARATOR '||') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS TeamLeader,
	CONVERT(GROUP_CONCAT(DISTINCT CASE WHEN booking_rn = 1 AND NULLIF(TRIM(BookingCustomer), '') IS NOT NULL THEN CONCAT(BookingID, ':', TRIM(BookingCustomer)) END SEPARATOR '||') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS TeamLeaderBookings,
	CONVERT(MAX(CASE WHEN rn = 1 THEN ContactNum     END) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ContactNum,
	CONVERT(MAX(CASE WHEN rn = 1 THEN CallingCode    END) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS CallingCode,
	CONVERT(MAX(CASE WHEN rn = 1 THEN Email          END) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Email,
	CONVERT(MAX(CASE WHEN rn = 1 THEN ChatLanguage   END) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Language,
	CONVERT(MAX(CASE WHEN rn = 1 THEN SalesAgentName END) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS AgentName,
	CONVERT(MAX(CASE WHEN rn = 1 THEN SourceName     END) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Source,
	CONVERT(MAX(CASE WHEN rn = 1 THEN customer_type  END) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS CustomerType,
	CONVERT(GROUP_CONCAT(CASE WHEN booking_rn = 1 THEN COALESCE(Destination, '-') END ORDER BY DATE(BookingDate) SEPARATOR '||') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Destination,
	CONVERT(MAX(CASE WHEN rn = 1 THEN Nationality    END) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Nationality,
	CONVERT(MAX(CASE WHEN rn = 1 THEN Gender         END) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Gender,
	CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS Race,
	CONVERT(MAX(CASE WHEN rn = 1 THEN GuestType      END) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS GuestType,
	MAX(CASE WHEN rn = 1 THEN DateOfBirth END) AS DOB,
	COALESCE(SUM(CASE WHEN booking_rn = 1 THEN BookingPax      END), 0) AS TotalPax,
	COALESCE(SUM(CASE WHEN booking_rn = 1 THEN BookingNetTotal END), 0) AS TotalSales,
	CONVERT(CASE WHEN MAX(IsLeader) = 1 THEN 'Customer' ELSE 'Booking Guest' END USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Type,
	CONVERT(MAX(CASE WHEN rn = 1 THEN Token END) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Token,
	CONVERT(CASE WHEN MAX(IsLeader) = 1 THEN 'Team Leader' ELSE 'Team Member' END USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Role,
	CONVERT(GROUP_CONCAT(DISTINCT DATE(BookingDate) ORDER BY DATE(BookingDate) SEPARATOR ',') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS BookingDates,
	CONVERT(GROUP_CONCAT(DISTINCT CONCAT(DATE(TravelStart), '|', IFNULL(DATE(TravelEnd), '')) ORDER BY CONCAT(DATE(TravelStart), '|', IFNULL(DATE(TravelEnd), '')) SEPARATOR ',') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS TravelDates,
	CONVERT(MAX(CASE WHEN rn = 1 THEN IdentificationNumber END) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS IC,
	CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS Tags,
	CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS ClientType,
	CONVERT(MAX(CASE WHEN rn = 1 THEN CustomerCode END) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS CustomerCode,
	CONVERT(MAX(CASE WHEN rn = 1 THEN AltName END) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS AltName,
	MAX(CASE WHEN rn = 1 THEN CustomerID END) AS CustomerID,
	MAX(CASE WHEN rn = 1 THEN CustomerCreatedAt END) AS CustomerCreatedAt,
	CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS NatureOfBusiness,
	CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS LeadStatus,
	CAST(NULL AS DATE) AS LeadStatusDate,
	CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS State,
	CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS CreatedByName,
	MAX(BookingDate) AS RecencyAt
FROM (
	{$this->Booking_Windowed_Select($dedup, $booking['from'])}
) t
GROUP BY group_key
{$booking['having']}
			";
			$params = array_merge($params, $booking['params'], $booking['having_params']);
		}

		if($ghl !== null) {
			$gc_dedup = $ghl['dedup'];
			$parts[] = "
SELECT
	{$gc_dedup} AS dedup_key,
	CONVERT(COALESCE(
		NULLIF(TRIM(CONCAT_WS(' ', gc.first_name, gc.last_name)), ''),
		gt.conv_full_name,
		gt.conv_contact_name
	) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Name,
	CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS TeamLeader,
	CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS TeamLeaderBookings,
	CONVERT(gc.phone USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ContactNum,
	CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS CallingCode,
	CONVERT(gc.email USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Email,
	CONVERT(COALESCE(NULLIF(TRIM(gcv.language), ''), gc.chat_language) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Language,
	NULL AS AgentName,
	NULL AS Source,
	CONVERT(gc.customer_type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS CustomerType,
	NULL AS Destination,
	CONVERT(COALESCE(NULLIF(TRIM(gcv.nationality), ''), gc.nationality) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Nationality,
	CONVERT(COALESCE(NULLIF(TRIM(gcv.gender), ''), gc.gender) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Gender,
	CONVERT(COALESCE(NULLIF(TRIM(gcv.race), ''), gc.race) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Race,
	NULL AS GuestType,
	gc.date_of_birth AS DOB,
	0    AS TotalPax,
	0    AS TotalSales,
	CONVERT(CASE WHEN gc.lead_source = 'manual' THEN 'Manual' ELSE 'GHL' END USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Type,
	CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS Token,
	CAST('Lead' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS Role,
	CONVERT(DATE(COALESCE(gc.date_added, gc.created_at)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS BookingDates,
	CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS TravelDates,
	CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS IC,
	CONVERT(COALESCE(gt.tags_concat, gc.tags_json) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Tags,
	CONVERT(gc.client_type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ClientType,
	CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS CustomerCode,
	CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS AltName,
	CAST(NULL AS UNSIGNED) AS CustomerID,
	CAST(NULL AS DATETIME) AS CustomerCreatedAt,
	CONVERT(gc.nature_of_business USING utf8mb4) COLLATE utf8mb4_unicode_ci AS NatureOfBusiness,
	CONVERT((SELECT lsl.LeadStatus FROM lead_status_log lsl
		WHERE lsl.Status = 'Y' AND lsl.dedup_key = {$gc_dedup}
		ORDER BY lsl.StatusDate DESC, lsl.LogID DESC LIMIT 1
	) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS LeadStatus,
	(SELECT lsl.StatusDate FROM lead_status_log lsl
		WHERE lsl.Status = 'Y' AND lsl.dedup_key = {$gc_dedup}
		ORDER BY lsl.StatusDate DESC, lsl.LogID DESC LIMIT 1
	) AS LeadStatusDate,
	CONVERT(gc.state USING utf8mb4) COLLATE utf8mb4_unicode_ci AS State,
	CONVERT(a.Name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS CreatedByName,
	COALESCE(gc.date_added, gc.created_at) AS RecencyAt
{$ghl['from']}
			";
			$params = array_merge($params, $ghl['params']);
		}

		if($fallback !== null) {
			$parts[] = $this->Leader_Fallback_Select($fallback['key'], $fallback['from'], $fallback['having']);
			$params  = array_merge($params, $fallback['params'], $fallback['having_params']);
		}

		if(empty($parts)) {
			return array(null, array());
		}

		$inner = "SELECT * FROM (" . implode(" UNION ALL ", $parts) . ") merged";
		return array($inner, $params);
	}

	/**
	 * The display-merge key over the merged listing rows (alias $a). Mirrors the
	 * pure guest_list_merge_key() (see GuestListMergeKeyTest): rows that share the
	 * same Name + IdentificationNumber collapse into ONE listing row even when
	 * their phone numbers (and dedup_key) differ; rows with no IC keep their own
	 * dedup_key so nothing merges unless it is provably the same person. Both
	 * branches are coerced to utf8mb4_unicode_ci so GROUP BY on the key can't hit
	 * an illegal-mix-of-collations error.
	 */
	private function Merge_Key_Expr($a)
	{
		$ic = "REGEXP_REPLACE(IFNULL({$a}.IC, ''), '[^0-9A-Za-z]', '')";
		return "CASE
			WHEN NULLIF(UPPER({$ic}), '') IS NOT NULL
				THEN CONVERT(CONCAT('ic:', LOWER(TRIM(IFNULL({$a}.Name, ''))), '|', UPPER({$ic})) USING utf8mb4) COLLATE utf8mb4_unicode_ci
			ELSE CONVERT({$a}.dedup_key USING utf8mb4) COLLATE utf8mb4_unicode_ci
		END";
	}

	/**
	 * Wrap the per-dedup merged rows in the name+IC display merge. Most rows have
	 * no IC, so their merge_key is their own dedup_key and they pass through
	 * unchanged (one group, one row). Rows that DO share Name + IC collapse: the
	 * representative (rep_rn = 1, newest dedup_key) supplies the scalar columns
	 * and the edit/remarks dedup_key, pax/sales SUM across the group, and every
	 * distinct phone of the group is packed into ContactNumbers ("code\x1fmobile"
	 * units, \x1e-separated) so the listing can show all of them on the one row.
	 */
	private function Merged_Wrapped_Sql($inner)
	{
		$mk = $this->Merge_Key_Expr('m');
		return "
SELECT
	mm.merge_key,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.dedup_key   END) AS dedup_key,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.Name        END) AS Name,
	CONVERT(GROUP_CONCAT(mm.TeamLeader         SEPARATOR '||') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS TeamLeader,
	CONVERT(GROUP_CONCAT(mm.TeamLeaderBookings SEPARATOR '||') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS TeamLeaderBookings,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.ContactNum  END) AS ContactNum,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.CallingCode END) AS CallingCode,
	CONVERT(GROUP_CONCAT(DISTINCT CONCAT(COALESCE(mm.CallingCode, ''), 0x1f, COALESCE(mm.ContactNum, '')) SEPARATOR 0x1e) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ContactNumbers,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.Email        END) AS Email,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.Language     END) AS Language,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.AgentName    END) AS AgentName,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.Source       END) AS Source,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.CustomerType END) AS CustomerType,
	CONVERT(GROUP_CONCAT(mm.Destination SEPARATOR '||') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Destination,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.Nationality  END) AS Nationality,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.Gender       END) AS Gender,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.Race         END) AS Race,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.GuestType    END) AS GuestType,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.DOB          END) AS DOB,
	COALESCE(SUM(mm.TotalPax), 0)   AS TotalPax,
	COALESCE(SUM(mm.TotalSales), 0) AS TotalSales,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.Type  END) AS Type,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.Token END) AS Token,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.Role  END) AS Role,
	CONVERT(GROUP_CONCAT(mm.BookingDates SEPARATOR ',') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS BookingDates,
	CONVERT(GROUP_CONCAT(mm.TravelDates  SEPARATOR ',') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS TravelDates,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.IC   END) AS IC,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.Tags END) AS Tags,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.ClientType       END) AS ClientType,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.CustomerCode      END) AS CustomerCode,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.AltName           END) AS AltName,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.CustomerID        END) AS CustomerID,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.CustomerCreatedAt END) AS CustomerCreatedAt,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.NatureOfBusiness END) AS NatureOfBusiness,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.LeadStatus       END) AS LeadStatus,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.LeadStatusDate   END) AS LeadStatusDate,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.State            END) AS State,
	MAX(CASE WHEN mm.rep_rn = 1 THEN mm.CreatedByName    END) AS CreatedByName,
	MAX(mm.RecencyAt) AS RecencyAt
FROM (
	SELECT m.*,
		{$mk} AS merge_key,
		ROW_NUMBER() OVER (PARTITION BY {$mk} ORDER BY m.dedup_key DESC) AS rep_rn
	FROM ({$inner}) m
) mm
GROUP BY mm.merge_key";
	}

	function Read_Guests($limit, $offset)
	{
		list($inner, $params) = $this->Build_Merged_Sql_And_Params();
		if($inner === null) {
			return array();
		}

		// Latest first: newest booking/lead (RecencyAt = MAX booking InsertDate or
		// lead date_added across the merged person) at the top. Undated rows sort
		// last (NULL is lowest in DESC); a blank-name tiebreak keeps nameless rows
		// below same-dated named ones, and Name is the final alphabetical tiebreak.
		//
		// Wrap the grouped merge in an outer SELECT so ORDER BY resolves to the
		// aggregated output columns (RecencyAt/Name) — referencing them directly on
		// the grouped query makes MySQL bind to the non-aggregated columns and trip
		// only_full_group_by.
		$sql = "SELECT * FROM (" . $this->Merged_Wrapped_Sql($inner) . ") final"
			. " ORDER BY RecencyAt IS NULL ASC, RecencyAt DESC, (Name IS NULL OR Name = '') ASC, Name ASC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
		return $this->db->query($sql, $params)->result();
	}

	/**
	 * The un-paginated result set for the Excel export: same UNION/merge/ordering
	 * as Read_Guests but without LIMIT/OFFSET, so the download mirrors the whole
	 * filtered listing at once.
	 *
	 * Returns the raw CI result OBJECT (not ->result()) on purpose: the caller
	 * walks it with unbuffered_row() so we never build one big PHP array of every
	 * guest — the export can span the entire history and that array alone could
	 * exhaust memory. Returns null when the mode/filters can match nothing.
	 */
	function Read_Guests_For_Export()
	{
		list($inner, $params) = $this->Build_Merged_Sql_And_Params();
		if($inner === null) {
			return null;
		}

		$sql = "SELECT * FROM (" . $this->Merged_Wrapped_Sql($inner) . ") final"
			. " ORDER BY RecencyAt IS NULL ASC, RecencyAt DESC, (Name IS NULL OR Name = '') ASC, Name ASC";
		return $this->db->query($sql, $params);
	}

	/**
	 * FULL Manual Leads export: every stored lead field PLUS the dated Lead Status
	 * Updates (lead_status_log entries joined into one cell). The shared listing
	 * only carries a slim column set, so instead of bloating that UNION this reads
	 * ghl_contacts directly — but reuses the EXACT same filter WHERE + params the
	 * listing built (the GHL branch from Build_Branches, mode 'manual'), so an
	 * export always mirrors the on-screen filtered rows. Returns the CI result
	 * OBJECT for unbuffered streaming, or null when nothing can match.
	 */
	function Read_Manual_Leads_For_Export()
	{
		$this->mode = 'manual';
		list($booking, $ghl) = $this->Build_Branches();
		if($ghl === null) {
			return null;
		}
		$gc_dedup = $ghl['dedup'];

		// The dated Lead Status Updates, oldest→newest, as "YYYY-MM-DD Status (note)"
		// one entry PER LINE inside one cell — the SEPARATOR is a real newline (the
		// PHP double-quoted "\n" below becomes a literal LF in the SQL string, as
		// GROUP_CONCAT's SEPARATOR only accepts a string literal, not CHAR(10)). The
		// export column enables wrap-text so each update sits on its own row in Excel.
		// Keyed by the SAME dedup_key the listing shows (COALESCE(gc.dedup_key,
		// 'ghl:'||id)), matching Add/Read_Lead_Status_Log.
		$status_updates = "(SELECT GROUP_CONCAT(
				CONCAT(DATE(lsl.StatusDate), ' ', lsl.LeadStatus,
					CASE WHEN NULLIF(TRIM(lsl.Note), '') IS NOT NULL THEN CONCAT(' (', lsl.Note, ')') ELSE '' END)
				ORDER BY lsl.StatusDate ASC, lsl.LogID ASC SEPARATOR '\n')
			FROM lead_status_log lsl
			WHERE lsl.Status = 'Y' AND lsl.dedup_key = {$gc_dedup})";

		$sql = "SELECT
			gc.first_name        AS Name,
			gc.company_name      AS Company,
			gc.phone             AS ContactNum,
			gc.email             AS Email,
			gc.address           AS Address,
			gc.country           AS Country,
			gc.gender            AS Gender,
			gc.race              AS Race,
			gc.nationality       AS Nationality,
			gc.chat_language     AS Language,
			gc.date_of_birth     AS DOB,
			gc.source            AS Source,
			gc.customer_type     AS CustomerType,
			gc.client_type       AS ClientType,
			gc.nature_of_business AS NatureOfBusiness,
			gc.number_of_pax     AS NumberOfPax,
			gc.state             AS State,
			{$status_updates}    AS StatusUpdates,
			gc.lead_intro        AS LeadIntro,
			gc.notes             AS Notes,
			(SELECT a2.Name FROM admin a2 WHERE a2.AdminID = gc.created_by) AS CreatedBy,
			COALESCE(gc.date_added, gc.created_at) AS CreatedAt
		{$ghl['from']}
		ORDER BY COALESCE(gc.date_added, gc.created_at) DESC, gc.id DESC";

		return $this->db->query($sql, $ghl['params']);
	}

	function Count_Guests()
	{
		// Count the SAME rows the listing renders — one per merge_key — so
		// pagination matches after the name+IC display merge collapses rows.
		// (For a GHL / no-IC page merge_key is just the dedup_key, so this stays
		// the old distinct-contact count.) Reuses the merged UNION so Read and
		// Count can never disagree.
		list($inner, $params) = $this->Build_Merged_Sql_And_Params();
		if($inner === null) {
			return 0;
		}

		$mk  = $this->Merge_Key_Expr('m');
		$sql = "SELECT COUNT(*) AS cnt FROM (
			SELECT {$mk} AS merge_key FROM ({$inner}) m GROUP BY merge_key
		) z";
		$row = $this->db->query($sql, $params)->row();
		return $row ? (int)$row->cnt : 0;
	}

	// ===================================================================
	// Customer List — the Guest List's twin, but anchored on the customer
	// master table (one row per customer) instead of guest_list rows. It
	// reuses the same shared view/filters/columns; the booking-derived
	// columns (Team Leader / Agent / Guest Type / …) are pulled in per
	// customer via LEFT JOINs, and the 21 filters attach in three tiers
	// (customer / booking / guest) as EXISTS so the anchor never fans out.
	// Kept separate from Read_Guests/Count_Guests (which UNION booking +
	// GHL + leader-fallback) so those paths are untouched.
	// ===================================================================

	/**
	 * The customer's own phone key — the last 9 digits of c.phone_number, the
	 * same normalization dedup_key uses. Correlates to the outer `customer c`.
	 */
	private function Customer_Dedup_Key_Expr()
	{
		return "NULLIF(RIGHT(REGEXP_REPLACE(IFNULL(c.phone_number, ''), '[^0-9]', ''), 9), '')";
	}

	/**
	 * Build the shared WHERE + params for the Customer List. Anchored on
	 * `customer c`; every booking/guest/remark predicate is an EXISTS so the
	 * result stays exactly one row per customer (no GROUP BY, so Count and Read
	 * agree). Returns ['where','params','needs_pax_join'] — the pax filter is the
	 * only predicate that needs the `bagg` aggregate join, flagged for the count.
	 */
	private function Build_Customer_Branch()
	{
		$this->load->helper('guest_contact');
		$get = $this->input->get();
		$key = $this->Customer_Dedup_Key_Expr();

		// Anchor only requires a name; phone is optional so name-only customers
		// (e.g. bulk-imported with no contact) still surface in the Customer List.
		$where  = " WHERE c.Status = 'Y'
			AND NULLIF(TRIM(c.name), '') IS NOT NULL ";
		$params = array();
		$needs_pax_join = false;

		// ---- Tier 1: customer-level (filter the anchor row directly) ----
		$q_raw = trim((string) $this->input->get('q'));
		if ($q_raw !== '') {
			// Match the customer's own name (or alternate name) OR any of their
			// bookings' team-leader name (b.Customer) so searching a leader still
			// surfaces the customer.
			$where .= " AND ( c.name LIKE ? OR c.AltName LIKE ? OR EXISTS (
				SELECT 1 FROM booking b
				WHERE b.CustomerID = c.CustomerID AND b.Status != 'N' AND b.CancelStatus = 'N'
					AND b.Customer LIKE ?
			) ) ";
			$params[] = '%' . $q_raw . '%';
			$params[] = '%' . $q_raw . '%';
			$params[] = '%' . $q_raw . '%';
		}

		$contact_number = trim((string) $this->input->get('contact_number'));
		if ($contact_number !== '') {
			$where   .= " AND c.phone_number LIKE ? ";
			$params[] = '%' . $contact_number . '%';
		}

		$email = trim((string) $this->input->get('email'));
		if ($email !== '') {
			$where   .= " AND c.PrimaryEmail LIKE ? ";
			$params[] = '%' . $email . '%';
		}

		// "Has email address" campaign filter — keep only customers with an email.
		if (guest_list_flag_on($get, 'has_email')) {
			$where .= " AND NULLIF(TRIM(c.PrimaryEmail), '') IS NOT NULL ";
		}

		$customer_code = trim((string) $this->input->get('customer_code'));
		if ($customer_code !== '') {
			$where   .= " AND c.CustomerCode LIKE ? ";
			$params[] = '%' . $customer_code . '%';
		}

		$create_range = guest_list_parse_date_range($this->input->get('create_date'));
		if ($create_range !== null) {
			$where   .= " AND c.created_at >= ? AND c.created_at < DATE_ADD(?, INTERVAL 1 DAY) ";
			$params[] = $create_range[0];
			$params[] = $create_range[1];
		}

		$this->Append_In_Clause($where, $params, 'c.ChatLanguage',  $this->input->get('language'));
		$this->Append_In_Clause($where, $params, 'c.customer_type', $this->input->get('customer_type'));

		// Non-booker (created from an e-invoice request) filter: '1' = only flagged
		// non-bookers, '0' = only bookers; blank = no filter.
		$non_booker = trim((string) $this->input->get('non_booker'));
		if ($non_booker === '1') {
			$where .= " AND c.CreatedFromEInvoice = 1 ";
		} elseif ($non_booker === '0') {
			$where .= " AND (c.CreatedFromEInvoice = 0 OR c.CreatedFromEInvoice IS NULL) ";
		}

		// ---- Tier 2: booking-level (EXISTS on the customer's bookings) ----
		$bstr  = '';
		$bpar  = array();

		// Levels 20/50 see only customers they've sold to — mirrors the read-side
		// scoping in Read_Guests (b.SalesAgent = own admin_id).
		if (in_array($this->session->userdata('level'), array(20, 50))) {
			$bstr  .= " AND b.SalesAgent = ? ";
			$bpar[] = $this->session->userdata('admin_id');
		}

		$booking_range = guest_list_parse_date_range($this->input->get('booking_date'));
		if ($booking_range !== null) {
			$bstr  .= " AND b.InsertDate >= ? AND b.InsertDate < DATE_ADD(?, INTERVAL 1 DAY) ";
			$bpar[] = $booking_range[0];
			$bpar[] = $booking_range[1];
		}

		$travel_range = guest_list_parse_date_range($this->input->get('travel_date'));
		if ($travel_range !== null) {
			$bstr  .= " AND b.StartDate <= ? AND b.EndDate >= ? ";
			$bpar[] = $travel_range[1];
			$bpar[] = $travel_range[0];
		}

		$booking_number = trim((string) $this->input->get('booking_number'));
		if ($booking_number !== '') {
			$bstr  .= " AND b.BookingNumber LIKE ? ";
			$bpar[] = '%' . $booking_number . '%';
		}

		$tl_clause = guest_list_team_leader_clause($get);
		if ($tl_clause !== null) {
			$bstr  .= $tl_clause['sql'];
			$bpar[] = $tl_clause['param'];
		}

		$this->Append_In_Clause($bstr, $bpar, 'b.SalesAgent',  $this->input->get('sales_agent'));
		$this->Append_In_Clause($bstr, $bpar, 'b.Source',      $this->input->get('source'));
		$this->Append_In_Clause($bstr, $bpar, 'b.BookingID',   $this->input->get('booking_id'));

		if ($bstr !== '') {
			$where   .= " AND EXISTS (
				SELECT 1 FROM booking b
				WHERE b.CustomerID = c.CustomerID AND b.Status != 'N' AND b.CancelStatus = 'N'
				{$bstr} ) ";
			$params = array_merge($params, $bpar);
		}

		// Destination filter matches EITHER a booking destination OR a manually
		// attached destination (customer_destination), so it agrees with the
		// merged Destination column shown on the Customer list.
		$dest_values = guest_list_multi_values($this->input->get('destination'));
		if (!empty($dest_values)) {
			$ph = implode(',', array_fill(0, count($dest_values), '?'));
			$where .= " AND ( EXISTS (
					SELECT 1 FROM booking b
					WHERE b.CustomerID = c.CustomerID AND b.Status != 'N' AND b.CancelStatus = 'N'
						AND b.Destination IN ({$ph})
				) OR EXISTS (
					SELECT 1 FROM customer_destination cd
					WHERE cd.CustomerID = c.CustomerID AND cd.Status = 'Y'
						AND cd.CategoryID IN ({$ph})
				) ) ";
			foreach ($dest_values as $v) { $params[] = $v; } // booking EXISTS
			foreach ($dest_values as $v) { $params[] = $v; } // attached EXISTS
		}

		// Guest Role: a customer "leads" a booking when that booking's own contact
		// phone key equals the customer's key (mirrors the IsLeader expression).
		// "Team Leader" keeps customers who lead ≥1 booking; "Team Member" keeps
		// customers who never lead. Selecting both (or neither) applies no filter.
		$roles       = guest_list_multi_values($this->input->get('role'));
		$want_leader = in_array('Team Leader', $roles, true);
		$want_member = in_array('Team Member', $roles, true);
		if ($want_leader !== $want_member) {
			$leads_expr = " EXISTS (
				SELECT 1 FROM booking b
				WHERE b.CustomerID = c.CustomerID AND b.Status != 'N' AND b.CancelStatus = 'N'
					AND COALESCE(
						NULLIF(RIGHT(REGEXP_REPLACE(IFNULL(b.Mobile, ''), '[^0-9]', ''), 9), ''),
						{$key}
					) = {$key} ) ";
			$where .= $want_leader ? " AND {$leads_expr} " : " AND NOT {$leads_expr} ";
		}

		// Num of Pax is the SUM across the customer's bookings, so it filters the
		// `bagg` aggregate (not an EXISTS). A customer with no bookings has NULL
		// TotalPax and is correctly dropped by a >= filter.
		$pax_min = trim((string) $this->input->get('pax_min'));
		$pax_max = trim((string) $this->input->get('pax_max'));
		if ($pax_min !== '' && is_numeric($pax_min)) {
			$needs_pax_join = true;
			$where   .= " AND bagg.TotalPax >= ? ";
			$params[] = (int) $pax_min;
		}
		if ($pax_max !== '' && is_numeric($pax_max)) {
			$needs_pax_join = true;
			$where   .= " AND bagg.TotalPax <= ? ";
			$params[] = (int) $pax_max;
		}

		// ---- Tier 3: guest attributes, now DENORMALISED onto the customer row as
		// c.Gender / c.DateOfBirth / c.Nationality / c.GuestType (kept in sync from
		// guest_list by Customer_Model::Refresh_Snapshot_*). Filtering these columns
		// directly replaces the old per-customer EXISTS scan of the multi-million-row
		// guest_list table — the source of the Customer List timeouts.
		$dob_range = guest_list_parse_date_range($this->input->get('dob'));
		if ($dob_range !== null) {
			$where   .= " AND c.DateOfBirth >= ? AND c.DateOfBirth <= ? ";
			$params[] = $dob_range[0];
			$params[] = $dob_range[1];
		}

		$birthday = guest_list_birthday_clause($this->input->get('birthday'), 'c.DateOfBirth');
		if ($birthday !== null) {
			$where .= $birthday['sql'];
			foreach ($birthday['params'] as $bp) { $params[] = $bp; }
		}

		$this->Append_In_Clause($where, $params, 'c.Gender',    $this->input->get('gender'));
		$this->Append_In_Clause($where, $params, 'c.GuestType', $this->input->get('guest_type'));

		// Nationality arrives as country NAMES; the snapshot stores the CountryCodeID,
		// so map names -> ids via country_code (matches the old cn.Country filter).
		$nat_values = guest_list_multi_values($this->input->get('nationality'));
		if (!empty($nat_values)) {
			$nat_ph  = implode(',', array_fill(0, count($nat_values), '?'));
			$where  .= " AND c.Nationality IN (SELECT CountryCodeID FROM country_code WHERE Country IN ({$nat_ph})) ";
			$params  = array_merge($params, $nat_values);
		}

		// "Campaign" filter — same campaign_guests roster snapshot the booking/GHL
		// branches use, keyed on the customer's own phone dedup key so it matches
		// the person across every one of their bookings. Include keeps customers on
		// the picked campaign(s); exclude drops them. Name-only customers (NULL key)
		// never match a roster, so include correctly hides them.
		$joined_campaigns = guest_list_multi_values($this->input->get('joined_campaign'));
		if (!empty($joined_campaigns)) {
			$ph   = implode(',', array_fill(0, count($joined_campaigns), '?'));
			$neg  = $this->input->get('campaign_mode') === 'exclude' ? 'NOT ' : '';
			$where .= " AND {$neg}EXISTS (SELECT 1 FROM campaign_guests cg
				WHERE cg.CampaignID IN ({$ph}) AND cg.DedupKey = {$key}) ";
			foreach ($joined_campaigns as $cid) { $params[] = (int)$cid; }
		}
		list($chg_sql, $chg_par) = $this->Campaign_Hidden_Exclusion($key);
		$where .= $chg_sql;
		foreach ($chg_par as $p) { $params[] = $p; }

		// ---- Remark tier: EXISTS on the customer's remark log (by dedup_key) ----
		$campaign_range = guest_list_parse_date_range($this->input->get('campaign_date'));
		if ($campaign_range !== null) {
			$where   .= " AND EXISTS (SELECT 1 FROM guest_remarks gr
				WHERE gr.Status = 'Y' AND gr.dedup_key = {$key}
				AND gr.CampaignDate >= ? AND gr.CampaignDate <= ?) ";
			$params[] = $campaign_range[0];
			$params[] = $campaign_range[1];
		}

		$follow_range = guest_list_parse_date_range($this->input->get('follow_date'));
		if ($follow_range !== null) {
			$where   .= " AND EXISTS (SELECT 1 FROM guest_remarks gr
				WHERE gr.Status = 'Y' AND gr.dedup_key = {$key}
				AND gr.FollowDate >= ? AND gr.FollowDate <= ?) ";
			$params[] = $follow_range[0];
			$params[] = $follow_range[1];
		}

		// ---- "Customer type" value segments (purchase count / lifetime value /
		// booking-lead / family-with-kids / consecutive years / cancelled-BC) ----
		// Measured over each customer's own bookings. These target the customer
		// master, so the campaign picker applies them ONLY on this path (the
		// booking+GHL UNION path suppresses them — see
		// guest_list_customer_only_segment_keys).
		$seg = guest_list_customer_segment_sql($get);
		if ($seg['sql'] !== '') {
			$where  .= $seg['sql'];
			$params  = array_merge($params, $seg['params']);
		}

		// ---- Default "Booking Status" gate: hide customers whose bookings are all
		// cancelled or all quotation/proforma (like the booking listing), unless the
		// customer_booking_status[] filter (or the legacy cancelled=1 toggle) reveals
		// them. No-booking customers stay visible. Always applied, so the count,
		// listing and export share the same default set. ----
		$status = guest_list_customer_status_predicate($get);
		$where  .= $status['sql'];
		$params  = array_merge($params, $status['params']);

		return array('where' => $where, 'params' => $params, 'needs_pax_join' => $needs_pax_join);
	}

	/**
	 * The `bagg` derived table: per-customer booking aggregate — team-leader
	 * names (for the Team Leader column), total pax (for the Num of Pax filter
	 * and column), and AnyLeader (Team Leader vs Member for the Role column).
	 */
	private function Customer_Booking_Aggregate_Subquery()
	{
		return "(
			SELECT b.CustomerID,
				CONVERT(GROUP_CONCAT(DISTINCT NULLIF(TRIM(b.Customer), '') SEPARATOR '||') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS TeamLeaders,
				CONVERT(GROUP_CONCAT(DISTINCT CASE WHEN NULLIF(TRIM(b.Customer), '') IS NOT NULL THEN CONCAT(b.BookingID, ':', TRIM(b.Customer)) END SEPARATOR '||') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS TeamLeaderBookings,
				COALESCE(SUM(COALESCE(b.Adult, 0) + COALESCE(b.Children, 0) + COALESCE(b.Infant, 0)), 0) AS TotalPax,
				MAX(CASE WHEN COALESCE(
						NULLIF(RIGHT(REGEXP_REPLACE(IFNULL(b.Mobile, ''), '[^0-9]', ''), 9), ''),
						NULLIF(RIGHT(REGEXP_REPLACE(IFNULL(cust.phone_number, ''), '[^0-9]', ''), 9), '')
					) = NULLIF(RIGHT(REGEXP_REPLACE(IFNULL(cust.phone_number, ''), '[^0-9]', ''), 9), '')
					THEN 1 ELSE 0 END) AS AnyLeader
			FROM booking b
			LEFT JOIN customer cust ON cust.CustomerID = b.CustomerID
			WHERE b.Status != 'N' AND b.CancelStatus = 'N'
			GROUP BY b.CustomerID
		)";
	}

	/**
	 * The `blatest` derived table: the customer's most recent active booking
	 * (same ordering as Booking_Windowed_Select's rn = 1), for the single-value
	 * Agent / Source / Destination / Token columns.
	 */
	private function Customer_Latest_Booking_Subquery()
	{
		return "(
			SELECT CustomerID, SalesAgent, Source, Destination, Token FROM (
				SELECT b.CustomerID, b.SalesAgent, b.Source, b.Destination, b.Token,
					ROW_NUMBER() OVER (PARTITION BY b.CustomerID ORDER BY b.InsertDate DESC, b.BookingID DESC) AS rn
				FROM booking b
				WHERE b.Status != 'N' AND b.CancelStatus = 'N'
			) w WHERE w.rn = 1
		)";
	}

	/**
	 * The `bcc` derived table: the calling code for the customer's phone. The
	 * customer row stores only a raw local number, so the code is borrowed from
	 * their bookings. Unlike the latest-booking table above this INCLUDES
	 * cancelled bookings (active preferred, then most recent) so a customer whose
	 * only booking was cancelled still shows a code — matching the Customer Portal.
	 */
	private function Customer_Calling_Code_Subquery()
	{
		return "(
			SELECT CustomerID, CountryCodeID FROM (
				SELECT b.CustomerID, b.CountryCodeID,
					ROW_NUMBER() OVER (PARTITION BY b.CustomerID
						ORDER BY (CASE WHEN b.CancelStatus = 'N' THEN 0 ELSE 1 END), b.InsertDate DESC, b.BookingID DESC) AS rn
				FROM booking b
				WHERE b.Status != 'N' AND b.CountryCodeID IS NOT NULL
			) w WHERE w.rn = 1
		)";
	}

	/**
	 * The Customer List page: one row per active customer, enriched with the same
	 * columns the Guest List shows, ordered by customer Date Creation. $sort_dir
	 * ('DESC' newest-first default, or 'ASC' oldest-first) drives the header sort
	 * toggle; customers with no creation date always sort last either way. See
	 * Build_Customer_Branch for the filter tiers.
	 */
	function Read_Customers_Rich($limit, $offset, $sort_dir = 'DESC')
	{
		// Whitelist the direction — this is interpolated straight into the SQL.
		$dir = (strtoupper((string) $sort_dir) === 'ASC') ? 'ASC' : 'DESC';
		$branch = $this->Build_Customer_Branch();
		$key    = $this->Customer_Dedup_Key_Expr();
		$bagg   = $this->Customer_Booking_Aggregate_Subquery();
		$blat   = $this->Customer_Latest_Booking_Subquery();
		$bcc    = $this->Customer_Calling_Code_Subquery();
		// Gender/DOB/Nationality/GuestType now come from the customer snapshot
		// columns (c.*), so the old multi-million-row guest_list self-join is gone.

		$sql = "
	SELECT
		{$key} AS dedup_key,
		c.CustomerID AS CustomerID,
		CONVERT(c.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Name,
		bagg.TeamLeaders        AS TeamLeader,
		bagg.TeamLeaderBookings AS TeamLeaderBookings,
		CONVERT(c.phone_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ContactNum,
		CONVERT(ccp.CountryCode USING utf8mb4) COLLATE utf8mb4_unicode_ci AS CallingCode,
		CONVERT(c.PrimaryEmail USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Email,
		CONVERT(c.ChatLanguage USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Language,
		CONVERT(a.Name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS AgentName,
		CONVERT(s.Name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Source,
		CONVERT(c.customer_type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS CustomerType,
		CONVERT(cat.Name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Destination,
		CONVERT(cn.Country USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Nationality,
		CONVERT(c.Gender USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Gender,
		CONVERT(COALESCE(c.GuestType, 'ADULT') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS GuestType,
		c.DateOfBirth AS DOB,
		COALESCE(bagg.TotalPax, 0) AS TotalPax,
		CAST('Customer' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS Type,
		CONVERT(blatest.Token USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Token,
		CONVERT(CASE WHEN bagg.AnyLeader = 1 THEN 'Team Leader' ELSE 'Team Member' END USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Role,
		CONVERT(c.CustomerCode USING utf8mb4) COLLATE utf8mb4_unicode_ci AS CustomerCode,
		CONVERT(c.AltName USING utf8mb4) COLLATE utf8mb4_unicode_ci AS AltName,
		c.AutocountSyncStatus  AS AutocountSyncStatus,
		CONVERT(c.AutocountSyncMessage USING utf8mb4) COLLATE utf8mb4_unicode_ci AS AutocountSyncMessage,
		c.CreatedFromEInvoice AS CreatedFromEInvoice,
		c.created_at AS CustomerCreatedAt
	FROM customer c
	LEFT JOIN {$blat} blatest ON blatest.CustomerID = c.CustomerID
	LEFT JOIN {$bcc} bcc ON bcc.CustomerID = c.CustomerID
	LEFT JOIN country_code ccp ON ccp.CountryCodeID = bcc.CountryCodeID
	LEFT JOIN admin    a   ON a.AdminID   = blatest.SalesAgent
	LEFT JOIN source   s   ON s.SourceID  = blatest.Source
	LEFT JOIN category cat ON cat.CategoryID = blatest.Destination
	LEFT JOIN {$bagg} bagg ON bagg.CustomerID = c.CustomerID
	LEFT JOIN country_code cn ON cn.CountryCodeID = c.Nationality
	{$branch['where']}
	ORDER BY (c.created_at IS NULL) ASC, c.created_at {$dir}, c.name ASC
	LIMIT " . (int) $limit . " OFFSET " . (int) $offset;

		return $this->db->query($sql, $branch['params'])->result();
	}

	/**
	 * FULL Customer List export: the SAME customer-anchored rows the dashboard
	 * shows, filtered by the SAME Build_Customer_Branch() WHERE + params — but
	 * without LIMIT/OFFSET, so an export mirrors the on-screen filtered set across
	 * every page. Returns the CI result OBJECT (not ->result()) so the export
	 * helper can stream it one unbuffered row at a time. Column aliases match the
	 * 'customer' mode in guest_list_export_helper.
	 */
	function Read_Customers_Rich_For_Export()
	{
		$branch = $this->Build_Customer_Branch();
		$key    = $this->Customer_Dedup_Key_Expr();
		$bagg   = $this->Customer_Booking_Aggregate_Subquery();
		$blat   = $this->Customer_Latest_Booking_Subquery();
		$bcc    = $this->Customer_Calling_Code_Subquery();

		$sql = "
	SELECT
		{$key} AS dedup_key,
		c.CustomerID AS CustomerID,
		CONVERT(c.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Name,
		CONVERT(c.phone_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ContactNum,
		CONVERT(ccp.CountryCode USING utf8mb4) COLLATE utf8mb4_unicode_ci AS CallingCode,
		CONVERT(c.PrimaryEmail USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Email,
		CONVERT(c.ChatLanguage USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Language,
		CONVERT(a.Name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS AgentName,
		CONVERT(s.Name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Source,
		CONVERT(c.customer_type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS CustomerType,
		CONVERT(cat.Name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Destination,
		CONVERT(cn.Country USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Nationality,
		CONVERT(c.Gender USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Gender,
		CONVERT(COALESCE(c.GuestType, 'ADULT') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS GuestType,
		c.DateOfBirth AS DOB,
		COALESCE(bagg.TotalPax, 0) AS TotalPax,
		CONVERT(c.CustomerCode USING utf8mb4) COLLATE utf8mb4_unicode_ci AS CustomerCode,
		CONVERT(c.AltName USING utf8mb4) COLLATE utf8mb4_unicode_ci AS AltName,
		c.AutocountSyncStatus  AS AutocountSyncStatus,
		CONVERT(c.AutocountSyncMessage USING utf8mb4) COLLATE utf8mb4_unicode_ci AS AutocountSyncMessage,
		c.CreatedFromEInvoice AS CreatedFromEInvoice,
		c.created_at AS CustomerCreatedAt
	FROM customer c
	LEFT JOIN {$blat} blatest ON blatest.CustomerID = c.CustomerID
	LEFT JOIN {$bcc} bcc ON bcc.CustomerID = c.CustomerID
	LEFT JOIN country_code ccp ON ccp.CountryCodeID = bcc.CountryCodeID
	LEFT JOIN admin    a   ON a.AdminID   = blatest.SalesAgent
	LEFT JOIN source   s   ON s.SourceID  = blatest.Source
	LEFT JOIN category cat ON cat.CategoryID = blatest.Destination
	LEFT JOIN {$bagg} bagg ON bagg.CustomerID = c.CustomerID
	LEFT JOIN country_code cn ON cn.CountryCodeID = c.Nationality
	{$branch['where']}
	ORDER BY (c.created_at IS NULL) ASC, c.created_at DESC, c.name ASC";

		return $this->db->query($sql, $branch['params']);
	}

	/**
	 * Total customers matching the current filters — shares Build_Customer_Branch
	 * with Read_Customers_Rich, so the count and the listing return the same set.
	 * Only the `bagg` aggregate is joined, and only when a pax filter needs it.
	 */
	function Count_Customers_Rich()
	{
		$branch   = $this->Build_Customer_Branch();
		$pax_join = $branch['needs_pax_join']
			? " LEFT JOIN " . $this->Customer_Booking_Aggregate_Subquery() . " bagg ON bagg.CustomerID = c.CustomerID "
			: "";

		$sql = "SELECT COUNT(*) AS cnt FROM customer c {$pax_join} {$branch['where']}";
		$row = $this->db->query($sql, $branch['params'])->row();
		return $row ? (int) $row->cnt : 0;
	}

	/**
	 * CustomerID => newline-joined attached-destination names, for the Customer
	 * listing's "Attached Destinations" column. One batched query for the whole
	 * page (mirrors the remark_counts / chat_counts per-page map pattern). Only
	 * active links to active destination categories are shown.
	 */
	function Read_Customer_Attached_Destinations($customer_ids)
	{
		$ids = array();
		foreach ((array) $customer_ids as $cid) {
			$cid = (int) $cid;
			if ($cid > 0) { $ids[$cid] = $cid; }
		}
		if (empty($ids)) {
			return array();
		}

		$this->db->select('cd.CustomerID AS CustomerID, GROUP_CONCAT(cat.Name ORDER BY cat.Name SEPARATOR "\n") AS Names', false);
		$this->db->from('customer_destination cd');
		$this->db->join('category cat', 'cat.CategoryID = cd.CategoryID', 'inner');
		$this->db->where_in('cd.CustomerID', array_values($ids));
		$this->db->where('cd.Status', 'Y');
		$this->db->where('cat.IsDestination', 'YES');
		$this->db->where('cat.Status', 'Y');
		$this->db->group_by('cd.CustomerID');

		$map = array();
		foreach ($this->db->get()->result() as $row) {
			$map[(int) $row->CustomerID] = (string) $row->Names;
		}
		return $map;
	}

	/**
	 * True when $new_key already belongs to a DIFFERENT person than
	 * $current_dedup_key — either an active booking guest or a GHL lead.
	 * Shared predicate lives in guest_contact_duplicate_key_sql() so the unit
	 * test exercises the exact same SQL. Global (not sales-agent scoped) so two
	 * distinct guests can never silently merge onto one number.
	 */
	function Contact_Key_Belongs_To_Other($new_key, $current_dedup_key)
	{
		$new_key = (string) $new_key;
		if ($new_key === '') {
			return false;
		}
		$this->load->helper('guest_contact');
		$sql = guest_contact_duplicate_key_sql();
		$row = $this->db->query($sql, array($new_key, $current_dedup_key, $new_key, $current_dedup_key))->row();
		return $row && (int) $row->dup === 1;
	}

	/**
	 * The booking "leader phone key" — the last-9 digits of the booking's own
	 * Mobile, falling back to the linked customer's phone. A guest whose
	 * dedup_key equals this is the booking's team leader (mirrors the IsLeader
	 * expression in Booking_Windowed_Select). Used to decide which bookings a
	 * dashboard edit should reflect back onto (booking.Mobile / ChatLanguage).
	 */
	private function Booking_Leader_Key_Expr()
	{
		return "COALESCE(
			NULLIF(RIGHT(REGEXP_REPLACE(IFNULL(b.Mobile, ''),       '[^0-9]', ''), 9), ''),
			NULLIF(RIGHT(REGEXP_REPLACE(IFNULL(c.phone_number, ''), '[^0-9]', ''), 9), '')
		)";
	}

	/**
	 * Overwrite a plain guest_list column (Name / Mobile / Email) on every active
	 * row that shares $current_dedup_key — this person's records across bookings —
	 * keeping them grouped under the same key. When $scope_admin_id is set (levels
	 * 20/50) the update is confined to that agent's own bookings. Returns affected
	 * rows. $column is whitelisted by the callers below, never taken from input.
	 */
	private function Update_Guest_List_Column($column, $current_dedup_key, $value, $admin_id, $scope_admin_id = null)
	{
		$sql = "UPDATE guest_list gl
			JOIN booking b ON b.BookingID = gl.BookingID
			SET gl.{$column} = ?, gl.UpdateBy = ?, gl.UpdateDate = ?
			WHERE gl.Status = 'Y' AND b.Status != 'N' AND b.CancelStatus = 'N'
				AND gl.dedup_key = ?";
		$params = array($value, $admin_id, date('Y-m-d H:i:s'), $current_dedup_key);

		if ($scope_admin_id !== null) {
			$sql      .= " AND b.SalesAgent = ?";
			$params[]  = $scope_admin_id;
		}

		$this->db->query($sql, $params);
		return $this->db->affected_rows();
	}

	/**
	 * Contact number edit. Writes Mobile onto every active guest_list row sharing
	 * $current_dedup_key, then reflects the new number back onto booking.Mobile
	 * for the bookings this person LEADS (so the BC / booking contact stays in
	 * sync). The leader match uses the OLD key, since the new Mobile would change
	 * the generated dedup_key. Returns guest_list rows affected PLUS booking rows
	 * affected — so a "leader fallback" row (a booking with no guest_list record)
	 * still reports success off the booking write alone.
	 */
	function Update_Guest_Contact($current_dedup_key, $new_mobile, $admin_id, $scope_admin_id = null)
	{
		$affected = $this->Update_Guest_List_Column('Mobile', $current_dedup_key, $new_mobile, $admin_id, $scope_admin_id);

		$key_expr = $this->Booking_Leader_Key_Expr();
		$sql = "UPDATE booking b
			LEFT JOIN customer c ON c.CustomerID = b.CustomerID
			SET b.Mobile = ?
			WHERE b.Status != 'N' AND b.CancelStatus = 'N'
				AND {$key_expr} = ?";
		$params = array($new_mobile, $current_dedup_key);
		if ($scope_admin_id !== null) {
			$sql      .= " AND b.SalesAgent = ?";
			$params[]  = $scope_admin_id;
		}
		$this->db->query($sql, $params);

		// The new Mobile changes the guest's generated dedup_key, so refresh the
		// customer snapshot for BOTH the old key (member left) and the new key.
		$this->load->helper('guest_contact');
		$this->load->model('Customer_Model');
		$this->Customer_Model->Refresh_Snapshot_By_Keys(
			array($current_dedup_key, guest_contact_normalize_key($new_mobile))
		);

		return $affected + $this->db->affected_rows();
	}

	/**
	 * First Name edit → guest_list.Name on all of the person's active rows. The
	 * booking's own leader name (booking.Customer) is intentionally NOT touched:
	 * it holds the full BC name, so rewriting it with just the first name would
	 * drop the surname. Returns rows affected.
	 */
	function Update_Guest_Name($current_dedup_key, $name, $admin_id, $scope_admin_id = null)
	{
		return $this->Update_Guest_List_Column('Name', $current_dedup_key, $name, $admin_id, $scope_admin_id);
	}

	/**
	 * Email edit → guest_list.Email on all of the person's active rows. Booking
	 * has no dedicated guest-email column (it reads MAX(guest_list.Email)), so the
	 * guest_list write already reflects onto the booking. Returns rows affected.
	 */
	function Update_Guest_Email($current_dedup_key, $email, $admin_id, $scope_admin_id = null)
	{
		return $this->Update_Guest_List_Column('Email', $current_dedup_key, $email, $admin_id, $scope_admin_id);
	}

	/**
	 * Language edit. ChatLanguage is a PER-GUEST field on guest_list, so the value
	 * is written to every active guest_list row sharing $current_dedup_key — this
	 * lets ANY guest (leader or plain team member) carry their own language, and
	 * the dashboard shows COALESCE(gl, customer, booking). On top of that, when the
	 * person LEADS a booking the value also flows down to booking + customer, so
	 * the leader's master record stays in sync (only ChatLanguage is touched — the
	 * AutoCount sync flags are left alone, so no debtor re-sync is queued). Returns
	 * guest_list rows affected PLUS booking/customer rows affected, so a "leader
	 * fallback" row (a booking with no guest_list record) still reports success.
	 */
	function Update_Guest_Language($current_dedup_key, $language, $admin_id, $scope_admin_id = null)
	{
		$affected = $this->Update_Guest_List_Column('ChatLanguage', $current_dedup_key, $language, $admin_id, $scope_admin_id);

		$key_expr = $this->Booking_Leader_Key_Expr();
		$sql = "UPDATE booking b
			LEFT JOIN customer c ON c.CustomerID = b.CustomerID
			SET b.ChatLanguage = ?, c.ChatLanguage = ?
			WHERE b.Status != 'N' AND b.CancelStatus = 'N'
				AND {$key_expr} = ?";
		$params = array($language, $language, $current_dedup_key);
		if ($scope_admin_id !== null) {
			$sql      .= " AND b.SalesAgent = ?";
			$params[]  = $scope_admin_id;
		}
		$this->db->query($sql, $params);

		return $affected + $this->db->affected_rows();
	}

	/**
	 * Alt Name edit → customer.AltName for one customer. Alt Name is a customer-
	 * level attribute (the same field shown on the BC form and Customer form), so
	 * it is keyed by CustomerID, not the guest dedup_key. An empty value clears it
	 * (stored NULL). Returns 1 when the customer exists (even if the value is
	 * unchanged, so re-saving the same Alt Name is not treated as "not found"),
	 * else 0.
	 */
	function Update_Customer_AltName($customer_id, $alt_name)
	{
		$customer_id = (int) $customer_id;
		if ($customer_id < 1) {
			return 0;
		}
		$exists = $this->db->select('CustomerID')
			->get_where('customer', array('CustomerID' => $customer_id))->row();
		if (!$exists) {
			return 0;
		}
		$this->db->where('CustomerID', $customer_id);
		$this->db->update('customer', array(
			'AltName'    => ($alt_name === '' ? null : $alt_name),
			'updated_at' => date('Y-m-d H:i:s'),
		));
		return 1;
	}

	/**
	 * Contact-number edit for a Customer List row → customer.phone_number, keyed
	 * by CustomerID. The customer table is the authoritative record for that
	 * screen, and a name-only / bulk-imported customer has no guest_list rows and
	 * leads no booking, so the dedup_key-based Update_Guest_Contact touches
	 * nothing — this write is what actually persists their number. Like
	 * Update_Customer_AltName it returns 1 whenever the customer exists (even if
	 * the number is unchanged, so re-saving is not treated as "not found"), else 0.
	 */
	function Update_Customer_Contact($customer_id, $new_mobile)
	{
		$customer_id = (int) $customer_id;
		if ($customer_id < 1) {
			return 0;
		}
		$exists = $this->db->select('CustomerID')
			->get_where('customer', array('CustomerID' => $customer_id))->row();
		if (!$exists) {
			return 0;
		}
		$this->db->where('CustomerID', $customer_id);
		$this->db->update('customer', array(
			'phone_number' => $new_mobile,
			'updated_at'   => date('Y-m-d H:i:s'),
		));
		// New phone -> different self guest record; recompute this customer's snapshot.
		$this->load->model('Customer_Model');
		$this->Customer_Model->Refresh_Snapshot_For_Customer($customer_id);
		return 1;
	}

	function Read_Distinct($col)
	{
		$allowed = array('Nationality', 'ChatLanguage');
		if(!in_array($col, $allowed, true)) {
			return array();
		}

		if($col === 'ChatLanguage') {
			$sql = "
				SELECT DISTINCT value
				FROM (
					SELECT TRIM(c.ChatLanguage) AS value
					FROM customer c
					WHERE c.ChatLanguage IS NOT NULL AND TRIM(c.ChatLanguage) != ''
					UNION
					SELECT TRIM(b.ChatLanguage) AS value
					FROM booking b
					WHERE b.ChatLanguage IS NOT NULL AND TRIM(b.ChatLanguage) != ''
						AND b.Status != 'N'
				) u
				ORDER BY value ASC
			";
		} else if($col === 'Nationality') {
			$sql = "
				SELECT Country AS value
				FROM country_code
				WHERE Status = 'Y'
					AND Country IS NOT NULL AND TRIM(Country) != ''
				ORDER BY Country ASC
			";
		} else {
			$sql = "
				SELECT DISTINCT TRIM(gl.{$col}) AS value
				FROM guest_list gl
				WHERE gl.{$col} IS NOT NULL AND TRIM(gl.{$col}) != ''
					AND gl.Status = 'Y'
				ORDER BY value ASC
			";
		}

		return $this->db->query($sql)->result();
	}

	/**
	 * Distinct Race values for the Campaign picker's leads-only Race filter.
	 * Race lives only on ghl_contacts (synced + manual leads), so this is the
	 * sole source. Returns rows with a `value` column (matches Read_Distinct).
	 */
	function Read_Distinct_Ghl_Races()
	{
		$sql = "
			SELECT DISTINCT TRIM(gc.race) AS value
			FROM ghl_contacts gc
			WHERE gc.race IS NOT NULL AND TRIM(gc.race) != ''
			ORDER BY value ASC
		";
		return $this->db->query($sql)->result();
	}

	/**
	 * Distinct Source values for the Manual Leads page's Source filter. Source is
	 * free-text on hand-entered leads (ghl_contacts.source, e.g. "Facebook"), so
	 * the dropdown is built from the values actually stored on manual leads rather
	 * than a master table. Returns rows with a `value` column (matches Read_Distinct).
	 */
	function Read_Distinct_Ghl_Sources()
	{
		$sql = "
			SELECT DISTINCT TRIM(gc.source) AS value
			FROM ghl_contacts gc
			WHERE gc.lead_source = 'manual'
				AND gc.source IS NOT NULL AND TRIM(gc.source) != ''
			ORDER BY value ASC
		";
		return $this->db->query($sql)->result();
	}

	/**
	 * The distinct, cleaned tag list for the Campaign picker's leads-only Tag
	 * filter. Tags live per-conversation (ghl_conversations.tags_json) and on
	 * the contact (ghl_contacts.tags_json); both are JSON arrays. We pull every
	 * non-empty array and flatten them through ghl_lead_tags_parse() (drops the
	 * bracketed [whatsapp]/[device] system tags, de-dupes case-insensitively).
	 *
	 * @return string[] Sorted unique tag strings.
	 */
	function Read_Ghl_Tags()
	{
		$this->load->helper('ghl_lead_tags');

		$sql = "
			SELECT tags_json FROM ghl_conversations
			WHERE tags_json IS NOT NULL AND JSON_LENGTH(tags_json) > 0
			UNION ALL
			SELECT tags_json FROM ghl_contacts
			WHERE tags_json IS NOT NULL AND JSON_LENGTH(tags_json) > 0
		";
		$rows = $this->db->query($sql)->result();

		$tags = array();
		foreach($rows as $r) {
			// ghl_lead_tags_parse takes a newline-joined blob; one array per call
			// is fine — it just parses the single JSON line.
			foreach(ghl_lead_tags_parse(isset($r->tags_json) ? $r->tags_json : null) as $t) {
				$tags[mb_strtolower($t)] = $t;
			}
		}
		$tags = array_values($tags);
		usort($tags, 'strcasecmp');
		return $tags;
	}

	/**
	 * Add a campaign remark for a guest (keyed by dedup_key, so it follows the
	 * person across all their bookings — same key the inline edits use). The
	 * campaign date, destination id, follow date and text are already validated
	 * /normalized by the caller ($destination_id / $follow_date may be empty →
	 * stored NULL). Returns the new RemarkID.
	 */
	function Add_Guest_Remark($dedup_key, $campaign_date, $destination_id, $remark, $follow_date, $admin_id)
	{
		$this->db->insert('guest_remarks', array(
			'dedup_key'     => (string) $dedup_key,
			'CampaignDate'  => $campaign_date,
			'DestinationID' => ((int) $destination_id > 0) ? (int) $destination_id : null,
			'Remark'        => $remark,
			'FollowDate'    => ($follow_date !== '') ? $follow_date : null,
			'Status'        => 'Y',
			'CreatedBy'     => $admin_id,
			'CreatedAt'     => date('Y-m-d H:i:s'),
		));
		return (int) $this->db->insert_id();
	}

	/**
	 * All active remarks for a guest, newest Campaign Date first, with the
	 * author's name and the chosen destination's name for display. CanDelete
	 * flags the rows the current user may remove (their own — pass
	 * $viewer_admin_id).
	 */
	function Read_Guest_Remarks($dedup_key, $viewer_admin_id = null)
	{
		$sql = "SELECT gr.RemarkID, gr.CampaignDate, gr.DestinationID, gr.FollowDate,
				gr.Remark, gr.CreatedBy, gr.CreatedAt,
				cat.Name AS DestinationName, a.Name AS CreatedByName
			FROM guest_remarks gr
			LEFT JOIN admin    a   ON a.AdminID     = gr.CreatedBy
			LEFT JOIN category cat ON cat.CategoryID = gr.DestinationID
			WHERE gr.Status = 'Y' AND gr.dedup_key = ?
			ORDER BY gr.CampaignDate DESC, gr.RemarkID DESC";
		$rows = $this->db->query($sql, array((string) $dedup_key))->result();

		foreach ($rows as $r) {
			$r->CanDelete = ($viewer_admin_id !== null && (int) $r->CreatedBy === (int) $viewer_admin_id);
		}
		return $rows;
	}

	/**
	 * Active-remark counts for a set of dedup_keys, keyed by dedup_key — used to
	 * badge the listing's Action menu without a per-row query. Returns [] when
	 * no keys are given.
	 */
	function Read_Remark_Counts($dedup_keys)
	{
		$keys = array();
		foreach ((array) $dedup_keys as $k) {
			$k = (string) $k;
			if ($k !== '' && !in_array($k, $keys, true)) { $keys[] = $k; }
		}
		if (empty($keys)) {
			return array();
		}
		$placeholders = implode(',', array_fill(0, count($keys), '?'));
		$sql = "SELECT dedup_key, COUNT(*) AS cnt
			FROM guest_remarks
			WHERE Status = 'Y' AND dedup_key IN ({$placeholders})
			GROUP BY dedup_key";
		$out = array();
		foreach ($this->db->query($sql, $keys)->result() as $row) {
			$out[$row->dedup_key] = (int) $row->cnt;
		}
		return $out;
	}

	/**
	 * Soft-delete a remark. Confined to the author (CreatedBy) so one user can't
	 * remove another's note. Returns rows affected (0 when not theirs / missing).
	 */
	function Delete_Guest_Remark($remark_id, $admin_id)
	{
		$this->db->query(
			"UPDATE guest_remarks SET Status = 'N' WHERE RemarkID = ? AND CreatedBy = ? AND Status = 'Y'",
			array((int) $remark_id, $admin_id)
		);
		return $this->db->affected_rows();
	}

	/**
	 * ----- Campaigns this person joined (Action ▸ Campaigns) ------------------
	 * Only the active campaigns whose roster (campaign_guests) already includes
	 * this person (dedup_key) — campaigns they never joined are excluded. Same
	 * dedup keying as remarks/chat history so it spans all of their
	 * bookings/leads. Newest first.
	 */
	function Read_Member_Campaigns($dedup_key)
	{
		$dedup_key = (string) $dedup_key;
		$sql = "SELECT c.CampaignID, c.Name, c.CampaignDate
			FROM campaign c
			JOIN campaign_guests cg
				ON cg.CampaignID = c.CampaignID AND cg.DedupKey = ?
			WHERE c.Status = 'Y'
			ORDER BY c.CampaignDate DESC, c.CampaignID DESC";
		return $this->db->query($sql, array($dedup_key))->result();
	}

	/**
	 * dedup_key => count of active campaigns the person is on, over a set of keys
	 * — badges the listing's Action menu ("Campaigns (n)") without a per-row query.
	 */
	function Read_Campaign_Counts($dedup_keys)
	{
		$keys = array();
		foreach ((array) $dedup_keys as $k) {
			$k = (string) $k;
			if ($k !== '' && !in_array($k, $keys, true)) { $keys[] = $k; }
		}
		if (empty($keys)) {
			return array();
		}
		$placeholders = implode(',', array_fill(0, count($keys), '?'));
		$sql = "SELECT cg.DedupKey, COUNT(*) AS cnt
			FROM campaign_guests cg
			JOIN campaign c ON c.CampaignID = cg.CampaignID AND c.Status = 'Y'
			WHERE cg.DedupKey IN ({$placeholders})
			GROUP BY cg.DedupKey";
		$out = array();
		foreach ($this->db->query($sql, $keys)->result() as $row) {
			$out[$row->DedupKey] = (int) $row->cnt;
		}
		return $out;
	}

	/**
	 * Opt a person in/out of a SINGLE campaign's audience picker. Hidden = insert
	 * the (CampaignID, dedup_key) row (idempotent via REPLACE), shown = delete it.
	 * Returns the new hidden state so the caller can echo it back to the toggle.
	 */
	function Set_Campaign_Hidden($campaign_id, $dedup_key, $hidden, $admin_id)
	{
		$campaign_id = (int) $campaign_id;
		$dedup_key   = (string) $dedup_key;
		if ($campaign_id <= 0 || $dedup_key === '') {
			return false;
		}
		if ($hidden) {
			$this->db->query(
				"REPLACE INTO campaign_hidden_guests (CampaignID, DedupKey, HiddenBy, HiddenAt) VALUES (?, ?, ?, ?)",
				array($campaign_id, $dedup_key, $admin_id, date('Y-m-d H:i:s'))
			);
			return true;
		}
		$this->db->query(
			"DELETE FROM campaign_hidden_guests WHERE CampaignID = ? AND DedupKey = ?",
			array($campaign_id, $dedup_key)
		);
		return false;
	}

	/**
	 * ----- Lead Status log (Manual Leads) --------------------------------------
	 * A dated (StatusDate + LeadStatus + optional Note) history per lead, keyed by
	 * dedup_key exactly like guest_remarks so it follows the person. Author in
	 * CreatedBy, soft-deleted via Status, author-only delete. This log is now the
	 * ONLY place a manual lead's status lives (the single ghl_contacts.lead_status
	 * field was dropped); the latest active entry is the lead's current status. The
	 * lead_status settings picklist only supplies the status NAMES.
	 */
	function Add_Lead_Status_Log($dedup_key, $status_date, $lead_status, $note, $admin_id)
	{
		$this->db->insert('lead_status_log', array(
			'dedup_key'  => (string) $dedup_key,
			'StatusDate' => $status_date,
			'LeadStatus' => (string) $lead_status,
			'Note'       => ($note !== '' && $note !== null) ? (string) $note : null,
			'Status'     => 'Y',
			'CreatedBy'  => $admin_id,
			'CreatedAt'  => date('Y-m-d H:i:s'),
		));
		return (int) $this->db->insert_id();
	}

	function Read_Lead_Status_Log($dedup_key, $viewer_admin_id = null)
	{
		$sql = "SELECT lsl.LogID, lsl.StatusDate, lsl.LeadStatus, lsl.Note,
				lsl.CreatedBy, lsl.CreatedAt, a.Name AS CreatedByName
			FROM lead_status_log lsl
			LEFT JOIN admin a ON a.AdminID = lsl.CreatedBy
			WHERE lsl.Status = 'Y' AND lsl.dedup_key = ?
			ORDER BY lsl.StatusDate DESC, lsl.LogID DESC";
		$rows = $this->db->query($sql, array((string) $dedup_key))->result();
		foreach ($rows as $r) {
			$r->CanDelete = ($viewer_admin_id !== null && (int) $r->CreatedBy === (int) $viewer_admin_id);
		}
		return $rows;
	}

	function Delete_Lead_Status_Log($log_id, $admin_id)
	{
		$this->db->query(
			"UPDATE lead_status_log SET Status = 'N' WHERE LogID = ? AND CreatedBy = ? AND Status = 'Y'",
			array((int) $log_id, $admin_id)
		);
		return $this->db->affected_rows();
	}

	/**
	 * Read the full detail of one hand-entered ("Manual") lead for the Action ▸ View
	 * modal, resolving the listing's dedup_key back to its ghl_contacts row. A manual
	 * lead is inserted without a stored dedup_key, so the listing shows the fallback
	 * COALESCE(gc.dedup_key, CONCAT('ghl:', gc.id)) — match on either form. Applies the
	 * SAME per-creator privacy the listing uses (mode 'manual'), so a viewer can't open
	 * a lead they aren't allowed to see by guessing the key. Returns the row or null.
	 */
	function Read_Manual_Lead_Detail($dedup_key)
	{
		$dedup_key = (string) $dedup_key;
		if ($dedup_key === '') {
			return null;
		}
		list($scope_sql, $scope_params) = guest_list_ghl_lead_source_scope(
			'manual',
			$this->session->userdata('level'),
			$this->session->userdata('admin_id')
		);
		// A manual lead has no stored dedup_key, so the listing shows the fallback
		// 'ghl:<id>'. Resolve that to the numeric id in PHP (portable across MySQL +
		// SQLite — no CONCAT) and also allow a real dedup_key for completeness.
		$fallback_id = (strpos($dedup_key, 'ghl:') === 0) ? (int) substr($dedup_key, 4) : -1;
		$sql = "SELECT gc.id, gc.first_name, gc.last_name, gc.company_name, gc.phone,
				gc.email, gc.address, gc.gender, gc.chat_language, gc.race, gc.nationality,
				gc.country, gc.source, gc.notes, gc.customer_type, gc.lead_intro,
				gc.nature_of_business, gc.number_of_pax, gc.client_type, gc.state,
				gc.date_of_birth, gc.tags_json,
				COALESCE(gc.date_added, gc.created_at) AS created_at, a.Name AS CreatedByName
			FROM ghl_contacts gc
			LEFT JOIN admin a ON a.AdminID = gc.created_by
			WHERE (gc.dedup_key = ? OR gc.id = ?) {$scope_sql}
			LIMIT 1";
		$params = array_merge(array($dedup_key, $fallback_id), $scope_params);
		return $this->db->query($sql, $params)->row();
	}

	/**
	 * ----- Chat history files (uploaded WhatsApp .txt exports) -----------------
	 * Keyed by dedup_key like the remarks above, so an uploaded chat follows the
	 * person across all their bookings/leads. StoredName is the random on-disk
	 * name; OriginalName is what we show and download as.
	 */
	function Add_Chat_History($dedup_key, $original_name, $stored_name, $title, $admin_id)
	{
		$this->db->insert('chat_history_files', array(
			'dedup_key'    => (string) $dedup_key,
			'OriginalName' => (string) $original_name,
			'StoredName'   => (string) $stored_name,
			'Title'        => ($title !== '' && $title !== null) ? (string) $title : null,
			'Status'       => 'Y',
			'CreatedBy'    => $admin_id,
			'CreatedAt'    => date('Y-m-d H:i:s'),
		));
		return (int) $this->db->insert_id();
	}

	/**
	 * All active chat files for a guest, newest first, with the uploader's name.
	 * CanDelete flags rows the viewer may remove (their own — pass their admin id).
	 */
	function Read_Chat_History($dedup_key, $viewer_admin_id = null)
	{
		$sql = "SELECT chf.FileID, chf.OriginalName, chf.StoredName, chf.Title,
				chf.CreatedBy, chf.CreatedAt, a.Name AS CreatedByName
			FROM chat_history_files chf
			LEFT JOIN admin a ON a.AdminID = chf.CreatedBy
			WHERE chf.Status = 'Y' AND chf.dedup_key = ?
			ORDER BY chf.CreatedAt DESC, chf.FileID DESC";
		$rows = $this->db->query($sql, array((string) $dedup_key))->result();

		foreach ($rows as $r) {
			$r->CanDelete = ($viewer_admin_id !== null && (int) $r->CreatedBy === (int) $viewer_admin_id);
		}
		return $rows;
	}

	/**
	 * Active chat-file counts for a set of dedup_keys, keyed by dedup_key — used
	 * to badge the listing's Action menu without a per-row query.
	 */
	function Read_Chat_History_Counts($dedup_keys)
	{
		$keys = array();
		foreach ((array) $dedup_keys as $k) {
			$k = (string) $k;
			if ($k !== '' && !in_array($k, $keys, true)) { $keys[] = $k; }
		}
		if (empty($keys)) {
			return array();
		}
		$placeholders = implode(',', array_fill(0, count($keys), '?'));
		$sql = "SELECT dedup_key, COUNT(*) AS cnt
			FROM chat_history_files
			WHERE Status = 'Y' AND dedup_key IN ({$placeholders})
			GROUP BY dedup_key";
		$out = array();
		foreach ($this->db->query($sql, $keys)->result() as $row) {
			$out[$row->dedup_key] = (int) $row->cnt;
		}
		return $out;
	}

	/**
	 * One active chat file (for view/download). Returns the row or null.
	 */
	function Get_Chat_History_File($file_id)
	{
		$row = $this->db->query(
			"SELECT FileID, dedup_key, OriginalName, StoredName, Title
			 FROM chat_history_files WHERE FileID = ? AND Status = 'Y'",
			array((int) $file_id)
		)->row();
		return $row ? $row : null;
	}

	/**
	 * Soft-delete a chat file, confined to its uploader (CreatedBy). Returns rows
	 * affected (0 when not theirs / missing). The disk file is left in place.
	 */
	function Delete_Chat_History($file_id, $admin_id)
	{
		$this->db->query(
			"UPDATE chat_history_files SET Status = 'N' WHERE FileID = ? AND CreatedBy = ? AND Status = 'Y'",
			array((int) $file_id, $admin_id)
		);
		return $this->db->affected_rows();
	}

	function Read_Guest_Detail($dedup_key)
	{
		$dedup    = $this->Dedup_Key_Expr();
		$scope    = "";
		$params_b = array($dedup_key);

		if(in_array($this->session->userdata('level'), array(20, 50))) {
			$scope     .= " AND b.SalesAgent = ? ";
			$params_b[] = $this->session->userdata('admin_id');
		}

		$booking_sql = "
SELECT
	b.BookingID, b.BookingNumber, b.Customer, b.Mobile AS BookingMobile,
	b.CountryCodeID, b.CustomerID, b.StartDate, b.EndDate, b.InsertDate,
	b.ChatLanguage AS BookingLanguage,
	b.Adult, b.Children, b.Infant, b.NetTotal,
	cat.Name AS DestinationName,
	s.Name AS SourceName,
	c.customer_type AS CustomerType,
	cc.CountryCode AS LeaderCountryCode,
	c.name AS LeaderMasterName,
	c.phone_number AS LeaderMasterPhone,
	c.ChatLanguage AS CustomerLanguage,
	COALESCE(
		NULLIF(RIGHT(REGEXP_REPLACE(IFNULL(b.Mobile, ''),       '[^0-9]', ''), 9), ''),
		NULLIF(RIGHT(REGEXP_REPLACE(IFNULL(c.phone_number, ''), '[^0-9]', ''), 9), '')
	) AS primary_phone_key
FROM booking b
LEFT JOIN category     cat ON cat.CategoryID    = b.Destination
LEFT JOIN customer     c   ON c.CustomerID      = b.CustomerID
LEFT JOIN source       s   ON s.SourceID        = b.Source
LEFT JOIN country_code cc  ON cc.CountryCodeID  = b.CountryCodeID
WHERE b.Status != 'N' AND b.CancelStatus = 'N'
	AND EXISTS (
		SELECT 1 FROM guest_list gl
		WHERE gl.BookingID = b.BookingID AND gl.Status = 'Y'
			AND {$dedup} = ?
	)
	{$scope}
ORDER BY b.InsertDate DESC, b.BookingID DESC
		";

		$bookings = $this->db->query($booking_sql, $params_b)->result();
		if(empty($bookings)) {
			return array('profile' => null, 'bookings' => array());
		}

		$booking_ids = array();
		foreach($bookings as $b) { $booking_ids[] = (int)$b->BookingID; }

		$placeholders = implode(',', array_fill(0, count($booking_ids), '?'));
		$gl_sql = "
SELECT gl.GuestListID, gl.BookingID, gl.Name, gl.LastName, gl.Mobile, gl.Email,
	cn.Country AS Nationality, gl.Gender, gl.DateOfBirth,
	{$dedup} AS dedup_key
FROM guest_list gl
LEFT JOIN country_code cn ON cn.CountryCodeID = gl.Nationality
WHERE gl.Status = 'Y' AND gl.BookingID IN ({$placeholders})
ORDER BY gl.BookingID ASC, gl.GuestListID ASC
		";

		$gl_rows = $this->db->query($gl_sql, $booking_ids)->result();

		$by_booking = array();
		foreach($gl_rows as $r) {
			$by_booking[(int)$r->BookingID][] = $r;
		}

		$profile_candidate = null;
		foreach($bookings as $b) {
			$rows = isset($by_booking[(int)$b->BookingID]) ? $by_booking[(int)$b->BookingID] : array();

			$leader_row = null;
			if(!empty($b->primary_phone_key)) {
				foreach($rows as $r) {
					if($r->dedup_key === $b->primary_phone_key) { $leader_row = $r; break; }
				}
			}
			if($leader_row === null && !empty($b->Customer)) {
				$booking_name_norm = strtolower(trim($b->Customer));
				foreach($rows as $r) {
					$full = strtolower(trim(trim($r->Name) . ' ' . trim($r->LastName)));
					$first_only = strtolower(trim($r->Name));
					if($full === $booking_name_norm || $first_only === $booking_name_norm) {
						$leader_row = $r;
						break;
					}
				}
			}
			if($leader_row === null && !empty($rows)) {
				$leader_row = $rows[0];
			}

			$this_role = 'team member';
			if($leader_row !== null && $leader_row->dedup_key === $dedup_key) {
				$this_role = 'team leader';
			}

			$team_members = array();
			$current_guest_row = null;
			foreach($rows as $r) {
				if($leader_row !== null && (int)$r->GuestListID === (int)$leader_row->GuestListID) { continue; }
				$team_members[] = $r;
			}

			foreach($rows as $r) {
				if($r->dedup_key === $dedup_key) {
					$current_guest_row = $r;
					if($profile_candidate === null) { $profile_candidate = array('row' => $r, 'booking' => $b); }
					break;
				}
			}

			$b->LeaderName        = $leader_row !== null ? trim(trim($leader_row->Name) . ' ' . trim($leader_row->LastName)) : (string)$b->Customer;
			$b->LeaderMobile      = $leader_row !== null && !empty($leader_row->Mobile) ? $leader_row->Mobile : (!empty($b->BookingMobile) ? $b->BookingMobile : $b->LeaderMasterPhone);
			$b->this_role         = $this_role;
			$b->team_members      = $team_members;
			$b->current_guest_row = $current_guest_row;
		}

		$profile = null;
		if($profile_candidate !== null) {
			$r = $profile_candidate['row'];
			$b = $profile_candidate['booking'];
			// Source / Customer Type / Num of Pax / Total Sales are relocated from
			// the listing to this page — aggregated across all of the guest's
			// bookings (which arrive newest-first) by the pure profile helper.
			$this->load->helper('guest_profile');
			$summary = guest_profile_summary($bookings);
			$profile = (object)array(
				'Name'         => trim(trim($r->Name) . ' ' . trim($r->LastName)),
				'ContactNum'   => $r->Mobile,
				'Email'        => $r->Email,
				'Language'     => !empty($b->CustomerLanguage) ? $b->CustomerLanguage : $b->BookingLanguage,
				'Nationality'  => $r->Nationality,
				'Gender'       => $r->Gender,
				'DOB'          => $r->DateOfBirth,
				'Source'       => $summary['source'],
				'CustomerType' => $summary['customer_type'],
				'TotalPax'     => $summary['total_pax'],
				'TotalSales'   => $summary['total_sales'],
				'dedup_key'    => $r->dedup_key,
			);
		}

		return array('profile' => $profile, 'bookings' => $bookings);
	}
}
