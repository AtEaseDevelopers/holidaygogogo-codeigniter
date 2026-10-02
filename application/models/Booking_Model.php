<?php
class Booking_Model extends CI_Model
{
	private function apply_checklist_filter()
	{
		$raw = $this->input->get('checklist_filter');
		if(empty($raw)) {
			return false;
		}
		$ids = array_filter(array_map('intval', explode(',', $raw)), function($v){ return $v > 0; });
		if(empty($ids)) {
			return false;
		}
		$ids_list = implode(',', $ids);
		$json_contains_or = implode(' OR ', array_map(function($id) {
			return "JSON_CONTAINS(ppc.package_checklist_json, '{$id}')";
		}, $ids));

		// Find bookings that have a non-child/infant product with at least one of the selected
		// checklists assigned, but no completion record for any of those checklists on such a product
		$subquery = "booking.BookingID IN (
			SELECT DISTINCT bp.BookingID
			FROM booking_product bp
			JOIN product p ON p.ProductID = bp.ProductID AND p.is_child_or_infant = 0
			JOIN product_package_checklist ppc ON ppc.product_id = bp.ProductID
				AND ({$json_contains_or})
			WHERE bp.Status = 'Y'
			AND bp.disable_checklist_payment_out = 0
			AND NOT EXISTS (
				SELECT 1 FROM booking_checklist_completion bcc
				WHERE bcc.booking_id = bp.BookingID
				AND bcc.product_id = bp.ProductID
				AND bcc.package_checklist_id IN ({$ids_list})
			)
		)";
		$this->db->where($subquery, null, false);
		return true;
	}

	/**
	 * Filter to BCs with a pending supplier payout whose deadline falls in the
	 * requested bucket — backs the clickable Overdue / Today / Tomorrow segments
	 * of the OP "Supplier Pay-out Due Soon" card (?supplier_payout=...).
	 *
	 * Mirrors the card's row filters (Booking::ajax_summary_cards): pending,
	 * money-out, SUPPLIER PAYMENT%, supplier-linked. Buckets:
	 *   overdue  : floor (1 March, current year) <= Deadline < today
	 *   today    : Deadline = today
	 *   tomorrow : Deadline = today + 1
	 *
	 * The bucket is whitelisted before use; dates are server-derived, so the
	 * interpolated EXISTS subquery carries no user input. Returns true when a
	 * recognised bucket was applied.
	 */
	private function apply_supplier_payout_filter()
	{
		$bucket = $this->input->get('supplier_payout');
		if(empty($bucket)) {
			return false;
		}

		$today    = date('Y-m-d');
		$tomorrow = date('Y-m-d', strtotime('+1 day'));
		$floor    = date('Y') . '-03-01';

		if($bucket === 'overdue') {
			$deadline = "p.Deadline >= '{$floor}' AND p.Deadline < '{$today}'";
		} else if($bucket === 'today') {
			$deadline = "p.Deadline = '{$today}'";
		} else if($bucket === 'tomorrow') {
			$deadline = "p.Deadline = '{$tomorrow}'";
		} else {
			return false;
		}

		$this->db->where(
			"EXISTS (SELECT 1 FROM payment p"
			. " WHERE p.BookingID = booking.BookingID"
			. " AND p.Status = 'P' AND p.Debit > 0"
			. " AND p.Type LIKE 'SUPPLIER PAYMENT%'"
			. " AND p.SupplierID IS NOT NULL"
			. " AND {$deadline})",
			null, false
		);
		return true;
	}

	/**
	 * Filter to BCs that still owe a scheduled customer payment whose operative
	 * deadline falls in the requested bucket — backs the clickable Overdue /
	 * Today / Tomorrow segments of the OP "Payment From Customer Due Soon" card
	 * (?customer_payment=...). The money-IN counterpart of
	 * apply_supplier_payout_filter().
	 *
	 * Customer deadlines live on the booking, not the payment row, so the
	 * operative "next due" deadline depends on the payment stage (mirrors the
	 * booking-status PO / P / PP filters and the card in
	 * Booking::ajax_summary_cards):
	 *   - Status 'PBC' (pending BC confirmation) / 'P' (nothing received):
	 *       deposit first -> COALESCE(DepositDeadline, FullPaymentDeadline)
	 *   - Status 'PP' (deposit in): the balance -> FullPaymentDeadline
	 * Buckets (floor = 1 March of the current year):
	 *   overdue  : floor <= deadline < today
	 *   today    : deadline = today
	 *   tomorrow : deadline = today + 1
	 * Only BCs with an outstanding balance (NetTotal minus approved customer
	 * credits, excluding supplier-sourced agent commission) are kept, so a
	 * fully-paid BC past its deadline is not surfaced.
	 *
	 * The bucket is whitelisted before use and dates are server-derived, so the
	 * interpolated predicate carries no user input. Booking-level scoping (live
	 * BC, not cancelled) is supplied by the card link's status=A. Returns true
	 * when a recognised bucket was applied.
	 */
	private function apply_customer_payment_filter()
	{
		$bucket = $this->input->get('customer_payment');
		if(empty($bucket)) {
			return false;
		}

		$today    = date('Y-m-d');
		$tomorrow = date('Y-m-d', strtotime('+1 day'));
		$floor    = date('Y') . '-03-01';

		// 3pm cutoff (mirrors the card in Booking::ajax_summary_cards): a today
		// deadline is "today" only before 15:00; at/after 15:00 it lapses into
		// "overdue" (overdue widens to <= today, today matches nothing).
		$after3pm = ((int) date('H') >= 15);
		$range = function($col) use ($bucket, $today, $tomorrow, $floor, $after3pm) {
			if($bucket === 'overdue')  return $after3pm
				? "{$col} >= '{$floor}' AND {$col} <= '{$today}'"
				: "{$col} >= '{$floor}' AND {$col} < '{$today}'";
			if($bucket === 'today')    return $after3pm ? "1 = 0" : "{$col} = '{$today}'";
			if($bucket === 'tomorrow') return "{$col} = '{$tomorrow}'";
			return null;
		};
		if($range('x') === null) {
			return false;
		}

		$p_dl  = $range("COALESCE(booking.DepositDeadline, booking.FullPaymentDeadline)");
		$pp_dl = $range("booking.FullPaymentDeadline");

		$outstanding = "(booking.NetTotal - " . booking_settled_credit_sql() . ") > 0";

		$this->db->where(
			"((booking.Status IN ('PBC','P') AND {$p_dl})"
			. " OR (booking.Status = 'PP' AND {$pp_dl}))"
			. " AND {$outstanding}",
			null, false
		);
		return true;
	}

	/**
	 * Filter to BCs whose "Payment Out To Supplier" CHECKLIST is not ticked yet
	 * for a line whose payout deadline falls in the requested bucket — backs the
	 * clickable Overdue / Today / Tomorrow segments of the OP "Supplier Pay-out
	 * Checklist Due Soon" card (?checklist_payout=...).
	 *
	 * This is the checklist counterpart to apply_supplier_payout_filter(): that
	 * one reads the payment table (payouts already created); this one reads the
	 * checklist + booking_product deadline, the same signal the cron reminders
	 * use (Cronjob_Model::get_bookings_with_supplier_date). A line qualifies when
	 * it is active (Status='Y', disable_checklist_payment_out=0), its product is
	 * non-child/infant, the matching deadline (PaymentOutSupplierFull /
	 * PaymentOutSupplierDeposit) is in the bucket, and no completion row exists
	 * for that checklist on that line. Assignment mirrors the booking checklist
	 * modal: the full checklist must be assigned to the product, but the deposit
	 * checklist is auto-added by the deposit date, so a deposit line qualifies by
	 * its date alone (see checklist_payout_assignment_join_sql).
	 *
	 * Buckets (deadline; floor = 1 March of the current year):
	 *   overdue  : floor <= deadline < today
	 *   today    : deadline = today
	 *   tomorrow : deadline = today + 1
	 *
	 * The bucket is whitelisted before use; checklist IDs are resolved by name
	 * via a raw query (so the active-record state being built for the list query
	 * is left untouched) and dates are server-derived, so the interpolated
	 * subqueries carry no user input. Returns true when a recognised bucket was
	 * applied. Booking-level scoping (confirmation, not cancelled, not draft) is
	 * supplied by the card link's status=A + booking_confirmation_title params.
	 */
	private function apply_checklist_payout_filter()
	{
		$bucket = $this->input->get('checklist_payout');
		if(empty($bucket)) {
			return false;
		}

		$today    = date('Y-m-d');
		$tomorrow = date('Y-m-d', strtotime('+1 day'));
		$floor    = date('Y') . '-03-01';

		$range = function($col) use ($bucket, $today, $tomorrow, $floor) {
			if($bucket === 'overdue')  return "{$col} >= '{$floor}' AND {$col} < '{$today}'";
			if($bucket === 'today')    return "{$col} = '{$today}'";
			if($bucket === 'tomorrow') return "{$col} = '{$tomorrow}'";
			return null;
		};
		if($range('x') === null) {
			return false;
		}

		// Resolve the full/deposit checklist IDs by name. Raw query so the
		// list's active-record query under construction is not clobbered.
		$full = $this->db->query("SELECT ID FROM package_checklist WHERE name LIKE '%Payment Out To Supplier (full)%' LIMIT 1")->row();
		$dep  = $this->db->query("SELECT ID FROM package_checklist WHERE name LIKE '%Payment Out To Supplier (deposit)%' LIMIT 1")->row();
		$full_id = $full ? (int)$full->ID : 0;
		$dep_id  = $dep  ? (int)$dep->ID  : 0;

		$this->load->helper('checklist_payout');
		$exists = function($checklist_id, $date_col) use ($range) {
			return "EXISTS (SELECT 1 FROM booking_product bp"
				. " JOIN product p ON p.ProductID = bp.ProductID AND p.is_child_or_infant = 0"
				. checklist_payout_assignment_join_sql($checklist_id, $date_col)
				. " WHERE bp.BookingID = booking.BookingID"
				. " AND bp.Status = 'Y' AND bp.disable_checklist_payment_out = 0"
				. " AND bp.{$date_col} IS NOT NULL"
				. " AND " . $range("bp.{$date_col}")
				. " AND NOT EXISTS (SELECT 1 FROM booking_checklist_completion bcc"
				. " WHERE bcc.booking_id = bp.BookingID AND bcc.product_id = bp.ProductID"
				. " AND bcc.package_checklist_id = {$checklist_id}))";
		};

		$branches = array();
		if($full_id) { $branches[] = $exists($full_id, 'PaymentOutSupplierFull'); }
		if($dep_id)  { $branches[] = $exists($dep_id,  'PaymentOutSupplierDeposit'); }
		if(empty($branches)) {
			return false;
		}

		$this->db->where('(' . implode(' OR ', $branches) . ')', null, false);
		return true;
	}

	/**
	 * Filter to the bookings behind the OP / OP Team Lead "Slow Conversions
	 * (> 24h)" card (?slow_conversion=1). A booking qualifies when it took more
	 * than 24h to go from being saved as draft (SAD) to reaching PENDING PAYMENT
	 * (P) — the same SAD -> first-P gap as the card. Mirrors
	 * submitted_payment_conversion_summary_sql so the card count and this listing
	 * agree:
	 *   - company-wide (every agent's bookings — OP/OP Team Lead aren't scoped to
	 *     a sales slot, and levels 40/45 see all bookings in the listing);
	 *   - windowed by the SAD anchor (the draft-save date), i.e. drafts *saved*
	 *     within the month, cut off at today (never a future month-end). The
	 *     month comes from ?lead_month=YYYY-MM (the card passes its own window),
	 *     defaulting to the current month.
	 * The card link also carries status=A, which supplies the live scope
	 * (CancelStatus='N', Status!='N') via the shared status filter.
	 *
	 * lead_month is validated to YYYY-MM and the threshold is a constant int, so
	 * the interpolated subquery carries no user input. Returns true when applied.
	 */
	private function apply_slow_conversion_filter()
	{
		if(empty($this->input->get('slow_conversion'))) {
			return false;
		}

		$threshold = 86400; // 24h, in seconds

		// Draft-save window: the card's selected month, or the current month.
		$lead_month = (string) $this->input->get('lead_month');
		if(!preg_match('/^\d{4}-\d{2}$/', $lead_month)) {
			$lead_month = date('Y-m');
		}
		$win_start = $lead_month . '-01 00:00:00';
		// Cut off at today: for the live current month the end bound is today,
		// not the future month-end (matches the card, which passes $today).
		$month_end = date('Y-m-t', strtotime($lead_month . '-01'));
		$today     = date('Y-m-d');
		$win_end   = min($month_end, $today) . ' 23:59:59';

		$this->load->helper('submitted_payment_response');
		$this->db->where(
			'booking.BookingID IN (' .
				submitted_payment_slow_ids_sql_fragment($win_start, $win_end, $threshold) .
			')', null, false
		);
		return true;
	}

	private function apply_guest_list_status_filter()
	{
		$raw = $this->input->get('guest_list_status');
		if(empty($raw)) {
			return false;
		}
		$valid = ['locked','in_progress','submitted','not_submitted'];
		$statuses = array_values(array_intersect($valid, array_map('trim', explode(',', $raw))));
		if(empty($statuses)) {
			return false;
		}

		$this->config->load('guest_list');
		$timeout = (int) $this->config->item('guest_list_lock_timeout');
		if($timeout <= 0) {
			$timeout = 90;
		}

		$active_soft_lock_sql = "(booking.Token IS NOT NULL AND EXISTS (SELECT 1 FROM guest_list_locks gll WHERE gll.guest_list_hash = booking.Token AND gll.lock_expires_at > NOW() AND gll.last_heartbeat_at IS NOT NULL AND gll.last_heartbeat_at >= DATE_SUB(NOW(), INTERVAL " . $timeout . " SECOND)))";

		$this->db->group_start();
		foreach($statuses as $i => $status) {
			if($i == 0) {
				$this->db->group_start();
			} else {
				$this->db->or_group_start();
			}

			if($status == 'locked') {
				$this->db->where('booking.LockStatus', 'Y');
			} else if($status == 'in_progress') {
				$this->db->where('booking.LockStatus', 'N');
				$this->db->where($active_soft_lock_sql, null, false);
			} else if($status == 'submitted') {
				// Same predicate as the "Guest List Submitted" summary card so the
				// card count and this drill-down list resolve to the same rows.
				$this->load->helper('guest_list_status_filter');
				$this->db->where(guest_list_submitted_where(), null, false);
			} else if($status == 'not_submitted') {
				$this->db->where('booking.LockStatus', 'N');
				$this->db->group_start();
				$this->db->where('booking.is_submitted', 0);
				$this->db->or_where('booking.is_submitted IS NULL', null, false);
				$this->db->group_end();
			}

			$this->db->group_end();
		}
		$this->db->group_end();
		return true;
	}

	private function apply_status_filter()
	{
		$this->load->helper('booking_status_filter');

		// Guest-list status drill-downs (e.g. the "Guest List Submitted" card,
		// ?guest_list_status=submitted) are orthogonal to after-sales state: a
		// guest list stays submitted/locked even after the booking's after-sales
		// is COMPLETE. When such a filter is active without an explicit booking
		// status, the default landing scope's AfterSalesService='PENDING' gate
		// must NOT apply, or it silently hides COMPLETE bookings and the listing
		// undercounts versus the card. Cancelled bookings stay excluded so the
		// list still matches the cards' CancelStatus='N' rule.
		$status = $this->input->get('status');
		if(($status === null || $status === '') && !empty($this->input->get('guest_list_status'))) {
			$this->db->where('booking.CancelStatus', 'N');
			return;
		}

		// Global search box: a search should look across EVERY booking, not just
		// the default landing scope (AfterSalesService='PENDING'), which otherwise
		// hides confirmed/completed bookings and makes a search for e.g. a
		// destination look broken. When the user types a search term and hasn't
		// picked an explicit status, drop the PENDING gate but still exclude
		// cancelled bookings (same rule as the guest_list_status case above).
		$search_value = $this->input->get('search[value]');
		if(($status === null || $status === '') && !empty($search_value)) {
			$this->db->where('booking.CancelStatus', 'N');
			return;
		}

		// PO uses the 3pm-aware overdue cutoff so the list agrees with the
		// dashboard Payment Overdue card (today's deadlines count from 3pm).
		$where = booking_status_filter_full_where(
			$status, date('Y-m-d'), payment_overdue_cutoff_date()
		);
		$this->db->where($where, null, false);
	}

	function Read_Booking()
	{
		$this->db->select('booking.BookingID, booking.AllowReview, booking.CustomerReview, booking.CustomerReviewTimestamp, BookingConfirmationFooterID, TravelVoucherFooterID, booking.CountryCodeID AS CustomerCountryCode, booking.CountryCodeID2 AS CustomerCountryCode2, BookingNumber, ReservationNumber, DepositDeadline, FullPaymentDeadline, AdditionalPaymentDeadline, Customer, booking.Customer2, booking.Mobile AS CustomerMobile, booking.Mobile2 AS CustomerMobile2, StartDate, EndDate, Adult, Children, Infant, Destination, SalesAgent, Tag, BookingRemark, Subtotal, Discount, NetTotal, DepositPercentage, DepositMode, DepositFixedAmount, booking.ChatLanguage, Source, Token, booking.BookingConfirmationTitle, BookingConfirmationFooter, TravelVoucherFooter, booking.KeyContacts, booking.SpecialRemarks, ProductSequence, booking.Status, booking.CancelStatus, booking.PartialRefund, booking.LockStatus, booking.AfterSalesService, booking.bc_approved, booking.bc_approval_admin_id, booking.bc_approval_date, admin.Name AS SalesAgentName, booking.AutocountSyncStatus, booking.AutocountSyncMessage, booking.AutocountSyncAction, booking.CustomerAutocountSyncStatus, booking.CustomerAutocountSyncMessage, booking.CustomerAutocountSyncAction, customer.CustomerCode AS CustomerCode, customer.AltName AS CustomerAltName, customer.PrimaryEmail AS CustomerPrimaryEmail, customer.ic_passport_no AS ic_passport_no, customer.tin_no AS tin_no, customer.customer_type AS customer_type, booking.CustomerID, booking.CustomerID2, booking.BookingOP, booking.SalesAgent2, booking.SalesAgentIsPIC, booking.SalesAgent2IsPIC, booking.BookingFormText, booking.ChatSummary, booking.DraftApproved, booking.DraftApprovedDate, booking.InsertDate');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('customer', 'customer.CustomerID = booking.CustomerID', 'left');
		$this->db->where('booking.BookingID', $this->input->get('booking_id'));

		$row = $this->db->get('booking')->row_array();
		if ($row && !empty($row['BookingID'])) {
			$this->load->model('Booking_Customer_Type_Model');
			$row['customer_types_selected'] = $this->Booking_Customer_Type_Model->Read_Names_By_Booking($row['BookingID']);
		}
		return $row;
	}


	function Read_All_Bookings()
	{

		$this->db->select('booking.BookingID, BookingNumber, DepositDeadline, FullPaymentDeadline, Customer, booking.Mobile As CustomerMobile, StartDate, EndDate, NetTotal, booking.ChatLanguage, Token, booking.BookingConfirmationTitle, CancelStatus, booking.PartialRefund, LockStatus, booking.is_submitted, AfterSalesService, booking.Status, booking.bc_approved, booking.bc_approval_admin_id, booking.bc_approval_date, booking.InsertDate, admin.Name As SalesAgentName, category.Name As DestinationName, CountryCode, booking.AutocountSyncStatus, booking.AutocountSyncMessage, booking.AutocountSyncAction, booking.CustomerAutocountSyncStatus, booking.CustomerAutocountSyncMessage, booking.CustomerAutocountSyncAction, customer.CustomerCode, booking.CustomerID');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('category', 'category.CategoryID = booking.Destination', 'left');
		$this->db->join('country_code', 'country_code.CountryCodeID = booking.CountryCodeID', 'left');
		$this->db->join('customer', 'customer.CustomerID = booking.CustomerID', 'left');
		if(in_array($this->session->userdata('level'), [20, 50])) {
			$this->db->where('SalesAgent', $this->session->userdata('admin_id'));
		}

		$this->db->where('booking.Status !=', 'N');
		$this->db->order_by('booking.BookingID', 'DESC');

		return $this->db->get('booking')->result();
	}

	function Read_Actionable_Bookings()
	{
		$this->db->select('booking.BookingID, BookingNumber, DepositDeadline, FullPaymentDeadline, Customer, booking.Mobile As CustomerMobile, StartDate, EndDate, NetTotal, booking.ChatLanguage, Token, booking.BookingConfirmationTitle, CancelStatus, booking.PartialRefund, LockStatus, booking.is_submitted, AfterSalesService, booking.Status, booking.bc_approved, booking.bc_approval_admin_id, booking.bc_approval_date, booking.InsertDate, admin.Name As SalesAgentName, category.Name As DestinationName, CountryCode, booking.AutocountSyncStatus, booking.AutocountSyncMessage, booking.AutocountSyncAction, booking.CustomerAutocountSyncStatus, booking.CustomerAutocountSyncMessage, booking.CustomerAutocountSyncAction, customer.CustomerCode, booking.CustomerID');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('category', 'category.CategoryID = booking.Destination', 'left');
		$this->db->join('country_code', 'country_code.CountryCodeID = booking.CountryCodeID', 'left');
		$this->db->join('customer', 'customer.CustomerID = booking.CustomerID', 'left');
		if(in_array($this->session->userdata('level'), [20, 50])) {
			$this->db->where('SalesAgent', $this->session->userdata('admin_id'));
		}

		$this->db->where_in('booking.Status', array('P', 'PP', 'PBO', 'PTV', 'PT', 'OG', 'PBC'));
		$this->db->order_by('booking.BookingID', 'DESC');

		return $this->db->get('booking')->result();
	}

	function Read_Bookings()
	{
		$this->db->select('booking.BookingID, BookingNumber, DepositDeadline, FullPaymentDeadline, Customer, booking.Mobile As CustomerMobile, StartDate, EndDate, NetTotal, booking.ChatLanguage, Token, booking.BookingConfirmationTitle, CancelStatus, booking.PartialRefund, LockStatus, booking.is_submitted, AfterSalesService, booking.Status, booking.bc_approved, booking.bc_approval_admin_id, booking.bc_approval_date, booking.InsertDate, admin.Name As SalesAgentName, category.Name As DestinationName, CountryCode, booking.AutocountSyncStatus, booking.AutocountSyncMessage, booking.AutocountSyncAction, booking.CustomerAutocountSyncStatus, booking.CustomerAutocountSyncMessage, booking.CustomerAutocountSyncAction, customer.CustomerCode, booking.CustomerID');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('category', 'category.CategoryID = booking.Destination', 'left');
		$this->db->join('country_code', 'country_code.CountryCodeID = booking.CountryCodeID', 'left');
		$this->db->join('customer', 'customer.CustomerID = booking.CustomerID', 'left');
		if(in_array($this->session->userdata('level'), [20, 50])) {
			$this->db->where('SalesAgent', $this->session->userdata('admin_id'));
		}

		// These filters can ignore others
		$ignore = 0;

		if(!empty($this->input->get('customer'))) {
			//$this->db->where('Customer', $this->input->get('customer'));
			$this->db->like('Customer', $this->input->get('customer'));
			$ignore = 1;
		}

		if(!empty($this->input->get('booking_number'))) {
			$this->db->where('BookingNumber', $this->input->get('booking_number'));
			$ignore = 1;
		}

		if(!empty($this->input->get('reservation_number'))) {
			$this->db->like('ReservationNumber', $this->input->get('reservation_number'));
			$ignore = 1;
		}

		if($ignore == 0) {

			// Second level ignore
			$level2Ignore = 0;

			if(!empty($this->input->get('deadline'))) {
				$deadline = explode(' - ', $this->input->get('deadline'));
				$start_date = date('Y-m-d', strtotime(str_replace('/', '-', $deadline[0])));
				$end_date = date('Y-m-d', strtotime(str_replace('/', '-', $deadline[1])));
				$this->db->where("((`DepositDeadline` >= '".$start_date."' AND `DepositDeadline` <= '".$end_date."') OR (`FullPaymentDeadline` >= '".$start_date."' AND `FullPaymentDeadline` <= '".$end_date."') OR (`AdditionalPaymentDeadline` >= '".$start_date."' AND `AdditionalPaymentDeadline` <= '".$end_date."')) AND `booking`.`Status` IN ('P','PP')");
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('mobile'))) {
				$this->db->where('booking.Mobile', $this->input->get('mobile'));
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('travel_date'))) {
				$travel_date = explode(' - ', $this->input->get('travel_date'));
				$start_date = date('Y-m-d', strtotime(str_replace('/', '-', $travel_date[0])));
				$end_date = date('Y-m-d', strtotime(str_replace('/', '-', $travel_date[1])));
				$this->db->where("((`StartDate` <= '".$start_date."' AND `EndDate` >= '".$end_date."') OR (`StartDate` >= '".$start_date."' AND `StartDate` <= '".$end_date."') OR (`EndDate` >= '".$start_date."' AND `EndDate` <= '".$end_date."'))");
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('destination'))) {
				$this->db->where_in('Destination', explode(',', $this->input->get('destination')));
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('sales_agent'))) {
				$this->db->where_in('SalesAgent', explode(',', $this->input->get('sales_agent')));
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('booking_op'))) {
				$this->db->where_in('BookingOP', explode(',', $this->input->get('booking_op')));
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('sales_agent_2'))) {
				$this->db->where_in('SalesAgent2', explode(',', $this->input->get('sales_agent_2')));
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('tag'))) {
				$tags = explode(',', $this->input->get('tag'));
				$this->db->group_start();
				foreach($tags as $i => $t) {
					$t = (int)$t;
					if($i == 0) {
						$this->db->where("FIND_IN_SET($t, Tag)", null, false);
					} else {
						$this->db->or_where("FIND_IN_SET($t, Tag)", null, false);
					}
				}
				$this->db->group_end();
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('chat_language'))) {
				$this->db->where_in('booking.ChatLanguage', explode(',', $this->input->get('chat_language')));
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('source'))) {
				$this->db->where_in('Source', explode(',', $this->input->get('source')));
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('customer_type'))) {
				$customer_type_ids = array_filter(array_map('intval', explode(',', $this->input->get('customer_type'))));
				if(!empty($customer_type_ids)) {
					$in = implode(',', $customer_type_ids);
					$this->db->where("EXISTS (SELECT 1 FROM booking_customer_type bct WHERE bct.BookingID = booking.BookingID AND bct.CustomerTypeID IN ($in))", null, false);
				}
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('booking_confirmation_title'))) {
				$this->db->where_in('booking.BookingConfirmationTitle', explode(',', $this->input->get('booking_confirmation_title')));
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('autocount_status'))) {
				$this->db->where_in('booking.AutocountSyncStatus', explode(',', $this->input->get('autocount_status')));
				$level2Ignore = 1;
			}
			if($this->apply_guest_list_status_filter()) {
				$level2Ignore = 1;
			}
			if($this->apply_checklist_filter()) {
				$level2Ignore = 1;
			}
			if($this->apply_supplier_payout_filter()) {
				$level2Ignore = 1;
			}
			if($this->apply_checklist_payout_filter()) {
				$level2Ignore = 1;
			}
			if($this->apply_customer_payment_filter()) {
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('exclude_finished'))) {
				// "Exclude completed & pending-review" toggle on the OP
				// "Pending Insurance Checklist" card. Status='Y' is a finished
				// trip in either after-sales state — COMPLETE (review done) or
				// PENDING (AfterSalesService='PENDING', i.e. pending review) —
				// so dropping Status='Y' removes both at once and the drill-down
				// matches the card's count when the switch is on.
				$this->db->where('booking.Status !=', 'Y');
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('cancellation_reason'))) {
				$this->db->where_in('booking.CancellationReasonID', explode(',', $this->input->get('cancellation_reason')));
				$this->db->where('CancelStatus', 'Y');
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('einvoice_status'))) {
				$einvoice_values = array_map('trim', explode(',', $this->input->get('einvoice_status')));
				$has_yes = in_array('yes', $einvoice_values);
				$has_no = in_array('no', $einvoice_values);
				if($has_yes && !$has_no) {
					$this->db->where("(SELECT COUNT(*) FROM invoice_split_pax WHERE invoice_split_pax.BookingID = booking.BookingID AND invoice_split_pax.Status = 'Y') > 0");
				} else if($has_no && !$has_yes) {
					$this->db->where("(SELECT COUNT(*) FROM invoice_split_pax WHERE invoice_split_pax.BookingID = booking.BookingID AND invoice_split_pax.Status = 'Y') = 0");
				}
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('upcoming_not_ready'))) {
				$this->db->where('CancelStatus', 'N');
				$this->db->where_in('booking.Status', array('P','PBO','PGL','PTV'));
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('status'))) {
				if($this->input->get('status') == 'A') {
					$this->db->where('CancelStatus', 'N');
					$this->db->where('booking.Status !=', 'N');
				}
				if($this->input->get('status') == 'C') {
					$this->db->where('CancelStatus', 'Y');
					$this->db->where('booking.Status !=', 'N');
				}
				if($this->input->get('status') == 'Y') {
					$this->db->where('CancelStatus', 'N');
					$this->db->where('AfterSalesService', 'COMPLETE');
					$this->db->where('booking.Status', 'Y');
				}
				if($this->input->get('status') == 'OG') {
					$this->db->where('CancelStatus', 'N');
					$this->db->where('booking.Status', 'OG');
				}
				if($this->input->get('status') == 'PP') {
					$this->db->where('CancelStatus', 'N');
					$this->db->where('FullPaymentDeadline >=', date('Y-m-d'));
					$this->db->where('booking.Status', 'PP');
				}
				if($this->input->get('status') == 'PO') {
					$today = date('Y-m-d');
					// Match display_booking_status(): a P/PP row only renders as PO when
					// there is still an outstanding balance (NetTotal > approved credits).
					$approved_credit_sql = booking_settled_credit_sql();
					$this->db->where('CancelStatus', 'N');
					$this->db->where(
						"(((`booking`.`FullPaymentDeadline` < '".$today."' AND `booking`.`Status` IN ('P','PP'))"
						. " OR (`booking`.`DepositDeadline` < '".$today."' AND `booking`.`Status` = 'P'))"
						. " AND (`booking`.`NetTotal` - ".$approved_credit_sql.") > 0)"
					);
				}
				if($this->input->get('status') == 'PGL') {
					$this->db->where('CancelStatus', 'N');
					$this->db->where('LockStatus', 'N');
					$this->db->where('booking.Status', 'PTV');
				}
				if($this->input->get('status') == 'PBC') {
					$this->db->where('CancelStatus', 'N');
					$this->db->where('booking.Status', 'PBC');
				}
				if($this->input->get('status') == 'P') {
					$this->db->where('CancelStatus', 'N');
					$this->db->where("(`DepositDeadline` >= '".date('Y-m-d')."' OR (`DepositDeadline` IS NULL AND `FullPaymentDeadline` >= '".date('Y-m-d')."'))");
					$this->db->where('booking.Status', 'P');
				}
				if($this->input->get('status') == 'PR') {
					$this->db->where('CancelStatus', 'N');
					$this->db->where('AfterSalesService', 'PENDING');
					$this->db->where('booking.Status', 'Y');
				}
				if($this->input->get('status') == 'PT') {
					$this->db->where('CancelStatus', 'N');
					$this->db->where('booking.Status', 'PT');
				}
				if($this->input->get('status') == 'PTV') {
					$this->db->where('CancelStatus', 'N');
					$this->db->where('LockStatus', 'Y');
					$this->db->where('booking.Status', 'PTV');
				}

				$level2Ignore = 1;
			} else {
				$this->db->where('CancelStatus', 'N');
				$this->db->where('AfterSalesService', 'PENDING');
			}
		}

		if(!empty($this->input->get('booking_date'))) {
			$booking_date = explode(' - ', $this->input->get('booking_date'));
			$start_date = date('Y-m-d', strtotime(str_replace('/', '-', $booking_date[0])));
			$end_date = date('Y-m-d', strtotime(str_replace('/', '-', $booking_date[1])));
			$this->db->where('CAST(booking.InsertDate AS DATE) >=', $start_date);
			$this->db->where('CAST(booking.InsertDate AS DATE) <=', $end_date);
		} else {

			if($ignore == 0 && isset($level2Ignore) && $level2Ignore == 0) {
				$this->db->where('CAST(booking.InsertDate AS DATE) >=', date('Y-m-d', strtotime('-14 days')));
				$this->db->where('CAST(booking.InsertDate AS DATE) <=', date('Y-m-d'));
			}
		}

		$this->db->where('booking.Status !=', 'N');
		$this->db->order_by('booking.BookingID', 'DESC');

		return $this->db->get('booking')->result();
	}

	function Read_Bookings_With_Guest_Lists($group_by_booking_id)
	{
		if($group_by_booking_id == 'Y') {
			$this->db->select('booking.BookingID, BookingNumber, ReservationNumber, DepositDeadline, FullPaymentDeadline, Customer, booking.Mobile As CustomerMobile, StartDate, EndDate, Adult, Children, Infant, BookingRemark, Subtotal, Discount, NetTotal, booking.ChatLanguage As ChatLanguage, CancelStatus, booking.PartialRefund, LockStatus, booking.is_submitted, AfterSalesService, booking.Status, booking.InsertDate, MAX(guest_list.CountryCodeID) As GuestCountryCode, MAX(Type) As Type, MAX(guest_list.Name) As GuestName, MAX(guest_list.Gender) As Gender, MAX(guest_list.DateOfBirth) As DateOfBirth, MAX(guest_list.Nationality) As Nationality, MAX(guest_list.IdentificationNumber) As IdentificationNumber, MAX(guest_list.PassportNumber) As PassportNumber, MAX(guest_list.Mobile) As GuestMobile, MAX(guest_list.Email) As Email, MAX(MaritalStatus) As MaritalStatus, MAX(Employment) As Employment, MAX(guest_list.Address) As Address, MAX(Postcode) As Postcode, MAX(guest_list.City) As City, MAX(guest_list.State) As State, MAX(guest_list.Country) As Country, MAX(Nominee) As Nominee, MAX(NomineeIdentificationNumber) As NomineeIdentificationNumber, MAX(Relationship) As Relationship, admin.Name As SalesAgentName, sa2_admin.Name As SalesAgent2Name, category.Name As DestinationName, CountryCode, source.Name As SourceName, cancellation_reason.Name As CancellationReasonName, booking.CancellationRemark', FALSE);
		} else {
			$this->db->select('booking.BookingID, BookingNumber, ReservationNumber, DepositDeadline, FullPaymentDeadline, Customer, booking.Mobile As CustomerMobile, StartDate, EndDate, Adult, Children, Infant, BookingRemark, Subtotal, Discount, NetTotal, booking.ChatLanguage As ChatLanguage, CancelStatus, booking.PartialRefund, LockStatus, booking.is_submitted, AfterSalesService, booking.Status, booking.InsertDate, guest_list.CountryCodeID As GuestCountryCode, Type, guest_list.Name As GuestName, guest_list.Gender, guest_list.DateOfBirth, guest_list.Nationality, guest_list.IdentificationNumber, guest_list.PassportNumber, guest_list.Mobile As GuestMobile, guest_list.Email, MaritalStatus, Employment, guest_list.Address, Postcode, guest_list.City, guest_list.State, guest_list.Country, Nominee, NomineeIdentificationNumber, Relationship, admin.Name As SalesAgentName, sa2_admin.Name As SalesAgent2Name, category.Name As DestinationName, CountryCode, source.Name As SourceName, cancellation_reason.Name As CancellationReasonName, booking.CancellationRemark');
		}
		$this->db->join('guest_list', 'guest_list.BookingID = booking.BookingID', 'left');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('admin AS sa2_admin', 'sa2_admin.AdminID = booking.SalesAgent2', 'left');
		$this->db->join('category', 'category.CategoryID = booking.Destination', 'left');
		$this->db->join('country_code', 'country_code.CountryCodeID = booking.CountryCodeID', 'left');
		$this->db->join('source', 'source.SourceID = booking.Source', 'left');
		$this->db->join('cancellation_reason', 'cancellation_reason.CancellationReasonID = booking.CancellationReasonID', 'left');
		$this->db->join('customer', 'customer.CustomerID = booking.CustomerID', 'left');

		// Route the spreadsheet through the same WHERE-builder the on-screen
		// list uses, so any filter added to /Booking automatically applies to
		// the Download / Mass_Generate_Guest_Lists exports.
		$this->apply_booking_filters();

		if($group_by_booking_id == 'Y') {
			$this->db->group_by('booking.BookingID');
		} else {
			$this->db->where('guest_list.Status', 'Y');
		}
		$this->db->order_by('booking.BookingID', 'DESC');
		$this->db->order_by('Type', 'ASC');
		return $this->db->get('booking')->result();
	}

	function Read_Payments($booking_id)
	{
		$this->db->select('payment.PaymentID, Type, Credit, Debit, payment.Status');
		$this->db->where('payment.BookingID', $booking_id);
		$this->db->where('payment.Status !=', 'N');
		return $this->db->get('payment')->result();
	}

	/**
	 * Batch version of Read_Payments for a page of bookings.
	 *
	 * Returns payments for all given booking IDs in a single query, grouped as
	 * [BookingID => [payment rows]]. Used by the listing (Booking::ajax_list) to
	 * avoid one payment query per row (N+1). Each row's shape matches
	 * Read_Payments so callers can reuse the same profit calculation.
	 *
	 * @param int[] $booking_ids
	 * @return array<int, object[]>
	 */
	function Read_Payments_For_Bookings($booking_ids)
	{
		$grouped = array();
		if(empty($booking_ids)) {
			return $grouped;
		}
		$this->db->select('payment.BookingID, payment.PaymentID, Type, Credit, Debit, payment.Status');
		$this->db->where_in('payment.BookingID', $booking_ids);
		$this->db->where('payment.Status !=', 'N');
		$rows = $this->db->get('payment')->result();
		foreach($rows as $row) {
			$grouped[$row->BookingID][] = $row;
		}
		return $grouped;
	}
	
	function Read_Admins()
	{
		$this->db->select('AdminID, Name, Level, Status');
		$this->db->where('AdminID !=', 8);
		$this->db->where('Level !=', '30');
		$this->db->where_in('Status', array('Y', 'D'));
		$this->db->order_by('Status', 'ASC');
		$this->db->order_by('Name', 'ASC');
		return $this->db->get('admin')->result();
	}

	function Read_Notify_Admins()
	{
		$this->db->select('AdminID, Name, Level, Status');
		$this->db->where('AdminID !=', 8);
		$this->db->where_in('Status', array('Y', 'D'));
		$this->db->order_by('Status', 'ASC');
		$this->db->order_by('Name', 'ASC');
		return $this->db->get('admin')->result();
	}

	function Read_Booking_OP_Admins()
	{
		// Level 40 = OP, Level 45 = OP TEAM LEAD (a senior OP). Both can be
		// assigned as a booking's OP, so the dropdown lists both roles.
		$this->db->select('AdminID, Name');
		$this->db->where('AdminID !=', 8);
		$this->db->where_in('Level', array('40', '45'));
		$this->db->where('Status', 'Y');
		$this->db->order_by('Name', 'ASC');
		return $this->db->get('admin')->result();
	}

	function Read_Categories()
	{
		$this->db->select('CategoryID, Name');
		$this->db->where('IsDestination', 'YES');
		$this->db->where('Status', 'Y');
		$this->db->order_by('Name', 'ASC');
		return $this->db->get('category')->result();
	}

	function Read_Products()
	{
		$this->db->select('ProductID, ProductCode, product.Name As Product, category.Name As Category');
		$this->db->join('category', 'category.CategoryID = product.CategoryID', 'left');
		$this->db->where('product.Status', 'Y');
		$this->db->order_by('Category', 'ASC');
		$this->db->order_by('Product', 'ASC');
		return $this->db->get('product')->result();
	}

	// Products each category expects a booking to cover when chosen as Destination.
	// Only active links to active products are returned. Used to warn (not block)
	// when a booking's inserted products miss any of these at save time.
	function Read_Category_Products()
	{
		$this->db->select('category_product.CategoryID, category_product.ProductID, product.Name As Product, product.ProductCode');
		$this->db->join('product', 'product.ProductID = category_product.ProductID', 'inner');
		$this->db->where('category_product.Status', 'Y');
		$this->db->where('product.Status', 'Y');
		$this->db->order_by('product.ProductCode', 'ASC');
		return $this->db->get('category_product')->result();
	}

	function Read_Footers()
	{
		$this->db->select('FooterID, BookingConfirmationTitle, TravelVoucherTitle');
		$this->db->where('Status', 'Y');
		$this->db->order_by('BookingConfirmationTitle', 'ASC');
		$this->db->order_by('TravelVoucherTitle', 'ASC');
		return $this->db->get('footer')->result();
	}

	function Read_Country_Codes()
	{
		$this->db->select('CountryCodeID, Country, CountryCode');
		$this->db->where('Status', 'Y');
		$this->db->order_by('Country', 'ASC');
		return $this->db->get('country_code')->result();
	}

	function Read_Tags()
	{
		$this->db->select('TagID, Name');
		$this->db->where('Status', 'Y');
		$this->db->order_by('Name', 'ASC');
		return $this->db->get('tag')->result();
	}

	function Read_Sources()
	{
		$this->db->select('SourceID, Name');
		$this->db->where('Status', 'Y');
		$this->db->order_by('Name', 'ASC');
		return $this->db->get('source')->result();
	}

	function Read_Sources_With_Inactive($source_id)
	{
		$this->db->select('SourceID, Name');
		if(!empty($source_id)) {
			$this->db->where("(Status = 'Y' OR SourceID = " . intval($source_id) . ")", NULL, FALSE);
		} else {
			$this->db->where('Status', 'Y');
		}
		$this->db->order_by('Name', 'ASC');
		return $this->db->get('source')->result();
	}

	function Read_Booking_ID() {
		$this->db->select('BookingID');
		$this->db->where('BookingNumber', $this->input->get('booking_number'));
		return $this->db->get('booking')->row_array();
	}

	function Read_Customer() {
		$this->db->select('Customer');
		$this->db->where('BookingNumber', $this->input->get('booking_number'));
		return $this->db->get('booking')->row_array();
	}

	function Read_Net_Total() {
		$this->db->select('NetTotal');
		$this->db->where('BookingNumber', $this->input->get('booking_number'));
		return $this->db->get('booking')->row_array();
	}

	function Read_Token() {
		$this->db->select('Token');
		$this->db->where('BookingNumber', $this->input->get('booking_number'));
		return $this->db->get('booking')->row_array();
	}

	function Read_Product_Sequence() {
		$this->db->select('ProductSequence');
		$this->db->where('BookingID', $this->input->post('booking_id'));
		return $this->db->get('booking')->row_array();
	}

	// Freeze each row's point-in-time TeamID: the team the CREDITED agent is in
	// RIGHT NOW is snapshotted onto booking.TeamID when that agent is first set or
	// actually changes, and left untouched otherwise. The credited agent follows
	// the TC1/TC2 Jun-1 cutoff — TC1 (SalesAgent) pre-cutoff, TC2 (SalesAgent2)
	// on/after — so a post-cutoff sale is frozen to the TC2's team, and editing
	// TC1 on it does not re-team the sale. See booking_team_snapshot_helper.php
	// and lead_conversion_credit_helper.php.
	private function _apply_team_snapshot(&$booking_data)
	{
		if (empty($booking_data) || !is_array($booking_data)) {
			return;
		}
		$this->load->helper('booking_team_snapshot');
		$this->load->helper('lead_conversion_credit');

		foreach ($booking_data as $key => $row) {
			if (!is_array($row)) {
				continue;
			}

			// The stored row (updates only): supplies fields the write omits and
			// the previously-credited agent used to detect a change.
			$stored = null;
			if (!empty($row['BookingID'])) {
				$stored = $this->db->select('SalesAgent, SalesAgent2, InsertDate')
					->where('BookingID', $row['BookingID'])
					->get('booking')->row();
			}

			// A write that touches neither credited slot cannot change attribution
			// (InsertDate is fixed after creation) -> leave the frozen TeamID as-is.
			$touches_slot = array_key_exists('SalesAgent', $row)
				|| array_key_exists('SalesAgent2', $row);
			if ($stored !== null && !$touches_slot) {
				continue;
			}

			// Effective new values: the write wins, else fall back to the stored row.
			$new_sa  = array_key_exists('SalesAgent', $row)  ? $row['SalesAgent']  : ($stored ? $stored->SalesAgent  : 0);
			$new_sa2 = array_key_exists('SalesAgent2', $row) ? $row['SalesAgent2'] : ($stored ? $stored->SalesAgent2 : 0);
			// InsertDate is never edited via this form; a fresh insert lands "now".
			$insert_date = array_key_exists('InsertDate', $row) ? $row['InsertDate']
				: ($stored ? $stored->InsertDate : date('Y-m-d H:i:s'));

			// The agent credited under the cutoff, and their CURRENT team.
			$new_credit = lead_conversion_credited_admin_id($insert_date, $new_sa, $new_sa2);
			$team_id = null;
			if ((int) $new_credit > 0) {
				$a = $this->db->select('TeamID')
					->where('AdminID', (int) $new_credit)
					->get('admin')->row();
				$team_id = $a ? $a->TeamID : null;
			}

			$prev = $stored ? array(
				'InsertDate'  => $stored->InsertDate,
				'SalesAgent'  => $stored->SalesAgent,
				'SalesAgent2' => $stored->SalesAgent2,
			) : null;

			$decision = booking_team_snapshot_by_credit($insert_date, $new_sa, $new_sa2, $prev, $team_id);
			if ($decision['write']) {
				$booking_data[$key]['TeamID'] = $decision['team_id'];
			}
		}
	}

	function Create()
	{
		// Load booking flow helper
		$this->load->helper('booking_flow');
		
		// Get booking data and ensure AllowReview is properly formatted as integer (0 or 1)
		$booking_data = $this->input->post('booking');
		if (!empty($booking_data) && is_array($booking_data)) {
			foreach ($booking_data as $key => $booking_item) {
				if (isset($booking_item['AllowReview'])) {
					// Convert AllowReview to integer (0 or 1) for BOOLEAN field
					$booking_data[$key]['AllowReview'] = (int)$booking_item['AllowReview'];
					// Ensure it's either 0 or 1 (validate)
					if ($booking_data[$key]['AllowReview'] != 0 && $booking_data[$key]['AllowReview'] != 1) {
						$booking_data[$key]['AllowReview'] = 1; // Default to 1 if invalid
					}
				} else {
					// If AllowReview is not set, default to 1 (TRUE)
					$booking_data[$key]['AllowReview'] = 1;
				}
				
				// Set initial status to PBC if not provided
				if (!isset($booking_item['Status']) || empty($booking_item['Status'])) {
					$booking_data[$key]['Status'] = get_initial_booking_status(); // 'PBC'
				}
				
				// Set bc_approved to 0 for new bookings
				if (!isset($booking_item['bc_approved'])) {
					$booking_data[$key]['bc_approved'] = 0;
					$booking_data[$key]['bc_approval_date'] = null;
				}
			}
		}
		
		// Freeze the point-in-time team of the credited agent onto each row.
		$this->_apply_team_snapshot($booking_data);

		$this->db->insert_batch('booking', json_decode(json_encode($booking_data)));
		$booking_id = $this->db->insert_id();

		if ($booking_id) {
			$posted_customer_types = $this->input->post('customer_type');
			$this->load->model('Booking_Customer_Type_Model');
			$this->Booking_Customer_Type_Model->Sync($booking_id, is_array($posted_customer_types) ? $posted_customer_types : []);
		}

		// Log booking creation with initial status
		if ($booking_id) {
			$this->load->helper('booking_status_log');
			$initial_status = !empty($booking_data[0]['Status']) ? $booking_data[0]['Status'] : 'PBC';
			$creator_id = $this->session->userdata('admin_id');
			log_booking_creation($booking_id, $initial_status, "Booking Created", $creator_id);
		}

		$data = [
			'name'          => $this->input->post('booking')[0]['Customer'] ? $this->input->post('booking')[0]['Customer'] : null,
			'phone_number'  => $this->input->post('booking')[0]['Mobile'] ? $this->input->post('booking')[0]['Mobile'] : null,
			'ChatLanguage'  => $this->input->post('booking')[0]['ChatLanguage'] ? $this->input->post('booking')[0]['ChatLanguage'] : null,
			'updated_at'    => date('Y-m-d H:i:s'),
		];
		if (!empty($this->input->post('ic_passport_no'))) {
			$data['ic_passport_no'] = strtoupper($this->input->post('ic_passport_no'));
		}
		if ($this->input->post('tin_no') !== null) {
			$data['tin_no'] = strtoupper(trim($this->input->post('tin_no')));
		}
		// Alternate/secondary customer name from the BC form (blank clears it).
		if ($this->input->post('AltName') !== null) {
			$data['AltName'] = trim($this->input->post('AltName')) === '' ? null : strtoupper(trim($this->input->post('AltName')));
		}
		// Email address from the BC form (kept as typed; blank clears it).
		if ($this->input->post('PrimaryEmail') !== null) {
			$data['PrimaryEmail'] = trim($this->input->post('PrimaryEmail')) === '' ? null : trim($this->input->post('PrimaryEmail'));
		}
		$customerId = $this->input->post('CustomerID');
		if (!empty($customerId) && $customerId != 'undefined' && $customerId != 'null' && is_numeric($customerId)) {
			$customer_id = $this->input->post('CustomerID');
			$this->load->model('Customer_Model');
			$this->Customer_Model->update_by_id($this->input->post('CustomerID'), $data);
			$customerInfo = get_object_vars($this->Customer_Model->find($this->input->post('CustomerID')));
			if (!empty($customerInfo)) {
				if ($customerInfo['AutocountSyncAction'] == 'C' && $customerInfo['AutocountSyncStatus'] == 'S') {
					$this->Customer_Model->update_by_id($customerInfo['CustomerID'], [
						'AutocountSyncAction' => 'U',
						'AutocountSyncStatus' => 'P'
					]);
				} else if ($customerInfo['AutocountSyncAction'] == 'U' && $customerInfo['AutocountSyncStatus'] == 'S') {
					$this->Customer_Model->update_by_id($customerInfo['CustomerID'], [
						'AutocountSyncAction' => 'U',
						'AutocountSyncStatus' => 'P'
					]);
				} else {
					$this->Customer_Model->update_by_id($customerInfo['CustomerID'], [
						'AutocountSyncStatus'  => 'P',
					]);
				}
			}
		} else {
			if (!empty($data['name']) || !empty($data['phone_number'])) {
				$this->load->model('Customer_Model');

				$data['created_at'] = date('Y-m-d H:i:s');
				$data['AutocountSyncAction'] = 'C';
				$data['AutocountSyncStatus'] = 'P';

				// Hard duplicate-phone block: reuse an existing customer with the
				// same phone (any format) instead of creating a duplicate; only a
				// genuinely new phone creates a new customer + generated code.
				// The agent can override this from the "Existing Customer Found"
				// dialog ("Create new customer anyway") — force_new_customer forces
				// a brand-new customer even when the phone already exists.
				if ($this->input->post('force_new_customer')) {
					$customer_id = $this->Customer_Model->create_with_generated_code($data);
				} else {
					$customer_id = $this->Customer_Model->create_or_reuse_by_phone($data);
				}

				// remove this no need sync directly, cron will sync customer at first
				// // Immediately sync to Autocount
				// $this->load->library('CustomerSync');
				// $this->load->helper('autocount');
				// $config = get_autocount_config();
				// $data['CustomerID'] = $customer_id;
				// $result = $this->customersync->autocount_create($data, $config);

				// if (isset($result['status']) && ($result['status'] == 201 || $result['status'] == 204) && $result['error'] === null) {
				// 	$this->Customer_Model->update_by_id($customer_id, [
				// 		'AutocountSyncStatus'  => 'S',
				// 		'AutocountSyncMessage' => json_encode($result)
				// 	]);
				// } else {
				// 	$this->Customer_Model->update_by_id($customer_id, [
				// 		'AutocountSyncStatus'  => 'F',
				// 		'AutocountSyncMessage' => json_encode($result)
				// 	]);
				// }
			} else {
				$customer_id = null;
			}
		}

		if($this->input->post('booking_number') != '' || $this->input->post('booking_number') != null) {
			$booking_number = $this->input->post('booking_number');
		} else {
			$this->db->like('BookingNumber', date('ym'));
			if($this->db->get('booking')->row()) {
				$this->db->like('BookingNumber', date('ym'));
				$this->db->order_by('BookingNumber', 'DESC');
				$booking = $this->db->get('booking')->row_array();
				$digits = (explode('-', $booking['BookingNumber'])[2]) + 1;
				$booking_number = 'BC-' . date('ym') . '-' . sprintf('%04d', $digits);
			} else {
				$booking_number = 'BC-' . date('ym') . '-0001';
			}
		}

		$this->db->set('BookingNumber', $booking_number);
		$this->db->where('BookingID', $booking_id);
		$this->db->where('BookingNumber', null);
		$this->db->update('booking');

		$this->db->set('Token', sha1($booking_number));
		$this->db->where('BookingID', $booking_id);
		$this->db->where('Token', null);
		$this->db->update('booking');

		$this->db->set('CustomerID', $customer_id);
		$this->db->where('BookingID', $booking_id);
		$this->db->where('CustomerID', null);
		$this->db->update('booking');

		// A customer attached to a booking (primary or secondary) is a booker, so
		// clear any "non-booker / e-invoice origin" flag they may carry.
		$this->load->model('Customer_Model');
		$this->Customer_Model->Clear_Einvoice_Flag([
			$customer_id,
			isset($this->input->post('booking')[0]['CustomerID2']) ? $this->input->post('booking')[0]['CustomerID2'] : null,
		]);

		$this->db->select('BookingNumber, ReservationNumber, DepositDeadline, FullPaymentDeadline, Customer, booking.Mobile As CustomerMobile, StartDate, EndDate, Adult, Children, Infant, BookingRemark, NetTotal, ChatLanguage, Source, admin.CountryCodeID, admin.Name As SalesAgent, admin.Mobile As SalesAgentMobile, category.Name As Destination, CountryCode, source.Name As SourceName');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('category', 'category.CategoryID = booking.Destination', 'left');
		$this->db->join('country_code', 'country_code.CountryCodeID = booking.CountryCodeID', 'left');
		$this->db->join('source', 'source.SourceID = booking.Source', 'left');
		$this->db->where('BookingID', $booking_id);
		$booking = $this->db->get('booking')->row_array();
		$booking['DepositDeadline'] = empty($booking['DepositDeadline']) ? '-' : strtoupper(date('j M Y', strtotime($booking['DepositDeadline'])));
		$booking['FullPaymentDeadline'] = strtoupper(date('j M Y', strtotime($booking['FullPaymentDeadline'])));
		$booking['CustomerMobile'] = $booking['CountryCode'] . $booking['CustomerMobile'];
		if(!empty($booking['StartDate']) && !empty($booking['EndDate'])) {
			$booking['TravelDate'] = strtoupper(date('j M', strtotime($booking['StartDate'])) . ' - ' . date('j M Y', strtotime($booking['EndDate'])));
		} else {
			$booking['TravelDate'] = '-';
		}
		if(!empty($booking['Adult'])) {
			$booking['Adult'] = $booking['Adult'] == 1 ? $booking['Adult'] . ' ADULT ' : $booking['Adult'] . ' ADULTS ';
		}
		if(!empty($booking['Children'])) {
			$booking['Children'] = $booking['Children'] == 1 ? $booking['Children'] . ' CHILD ' : $booking['Children'] . ' CHILDREN ';
		}
		if(!empty($booking['Infant'])) {
			$booking['Infant'] = $booking['Infant'] == 1 ? $booking['Infant'] . ' INFANT ' : $booking['Infant'] . ' INFANTS ';
		}
		if(!empty($booking['Adult']) && !empty($booking['Children']) && !empty($booking['Infant'])) {
			$booking['PaxNumber'] = $booking['Adult'] . '& ' . $booking['Children'] . '& ' . $booking['Infant'];
		} else {
			if(!empty($booking['Adult']) && empty($booking['Children']) && !empty($booking['Infant'])) {
				$booking['PaxNumber'] = $booking['Adult'] . '& ' . $booking['Infant'];
			} else {
				if(!empty($booking['Adult']) && !empty($booking['Children']) && empty($booking['Infant'])) {
					$booking['PaxNumber'] = $booking['Adult'] . '& ' . $booking['Children'];
				} else {
					if(!empty($booking['Adult']) && empty($booking['Children']) && empty($booking['Infant'])) {
						$booking['PaxNumber'] = $booking['Adult'];
					} else {
						if(empty($booking['Adult']) && !empty($booking['Children']) && !empty($booking['Infant'])) {
							$booking['PaxNumber'] = $booking['Children'] . '& ' . $booking['Infant'];
						} else {
							if(empty($booking['Adult']) && empty($booking['Children']) && !empty($booking['Infant'])) {
								$booking['PaxNumber'] = $booking['Infant'];
							} else {
								$booking['PaxNumber'] = $booking['Children'];
							}
						}
					}
				}
			}
		}
		$booking['BookingRemark'] = empty($booking['BookingRemark']) ? '-' : $booking['BookingRemark'];
		$booking['NetTotal'] = 'RM ' . number_format($booking['NetTotal'], 2, '.', ',');
		$booking['Source'] = empty($booking['Source']) ? '-' : $booking['SourceName'];
		$this->load->model('Universal_Model');
		$country_code = $this->Universal_Model->Read_Country_Code($booking['CountryCodeID']);
		$booking['SalesAgentMobile'] = $country_code . $booking['SalesAgentMobile'];
		$text = urlencode('New Booking Record Successfully Created' . "\n\n" . 'Booking Number : ' . "\n" . $booking['BookingNumber'] . "\n\n" . 'Reservation Number : ' . "\n" . $booking['ReservationNumber'] . "\n\n" . 'Deposit Deadline : ' . "\n" . $booking['DepositDeadline'] . "\n\n" . 'Full Payment Deadline : ' . "\n" . $booking['FullPaymentDeadline'] . "\n\n" . 'Customer : ' . "\n" . $booking['Customer'] . ' (' . $booking['CustomerMobile'] . ')' . "\n\n" . 'Travel Date : ' . "\n" . $booking['TravelDate'] . "\n\n" . 'Pax Number : ' . "\n" . $booking['PaxNumber'] . "\n\n" . 'Destination : ' . "\n" . $booking['Destination'] . "\n\n" . 'Sales Agent : ' . "\n" . $booking['SalesAgent'] . ' (' . $booking['SalesAgentMobile'] . ')' . "\n\n" . 'Remark : ' . "\n" . $booking['BookingRemark'] . "\n\n" . 'Subtotal : ' . "\n" . $booking['NetTotal'] . "\n\n" . 'Chat Language : ' . "\n" . $booking['ChatLanguage'] . "\n\n" . 'Source : ' . "\n" . $booking['Source']);
		
		// Send Telegram notification only if APP_ENV is 'prod'
		$this->load->helper('utils');
		$app_env = get_app_env();
		if ($app_env === 'prod') {
			$telegram_ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
			@file_get_contents('https://api.telegram.org/bot7521016286:AAEMDyjd789UEHBH5LK4xfBzIzY9TZ80tCg/sendMessage?chat_id=-1002546036574&text=' . $text, false, $telegram_ctx);
		}

		return $booking_id;
	}

	function Create_Booking_Log()
	{
		if(current_url() == base_url('Booking/Update')) {
			$log_data = json_decode(json_encode($this->input->post('booking_log')));
			// Filter out Subtotal entries - Subtotal is a calculated field and should not be in audit log
			if (!empty($log_data)) {
				$log_data = array_values(array_filter($log_data, function($entry) {
					return !isset($entry->Column) || $entry->Column !== 'Subtotal';
				}));
			}
			if (!empty($this->_snapshot_pdf_path) && !empty($log_data)) {
				$snapshot_columns = array('NetTotal', 'StartDate', 'EndDate');
				foreach ($log_data as &$entry) {
					if (isset($entry->Column) && in_array($entry->Column, $snapshot_columns)) {
						$entry->SnapshotPDF = $this->_snapshot_pdf_path;
					} else {
						$entry->SnapshotPDF = null;
					}
				}
				unset($entry);
			}
			$this->db->insert_batch('booking_log', $log_data);
		} else {
			if(current_url() == base_url('Booking/Update_Cancel_Status')) {
				$array = array(
					'BookingID' => $this->input->get('booking_id'),
					'Column' => 'CancelStatus',
					'CurrentData' => $this->input->get('current_cancel_status'),
					'NewData' => $this->input->get('new_cancel_status'),
					'InsertBy' => $this->session->admin_id,
					'InsertDate' => date('Y-m-d H:i:s')
				);
				$this->db->insert('booking_log', $array);
			} else {
				if(current_url() == base_url('Booking/Update_Lock_Status')) {
					$array = array(
						'BookingID' => $this->input->get('booking_id'),
						'Column' => 'LockStatus',
						'CurrentData' => $this->input->get('current_lock_status'),
						'NewData' => $this->input->get('new_lock_status'),
						'InsertBy' => $this->session->admin_id,
						'InsertDate' => date('Y-m-d H:i:s')
					);
					$this->db->insert('booking_log', $array);
				} else {
					if(current_url() == base_url('Booking/Update_Travel_Insurance_Status')) {
						$array = array(
							'BookingID' => $this->input->get('booking_id'),
							'Column' => 'TravelInsuranceStatus',
							'CurrentData' => $this->input->get('current_travel_insurance_status'),
							'NewData' => $this->input->get('new_travel_insurance_status'),
							'InsertBy' => $this->session->admin_id,
							'InsertDate' => date('Y-m-d H:i:s')
						);
						$this->db->insert('booking_log', $array);
					} else {
						if(current_url() == base_url('Booking/Update_After_Sales_Service')) {
							$array = array(
								'BookingID' => $this->input->get('booking_id'),
								'Column' => 'AfterSalesService',
								'CurrentData' => $this->input->get('current_after_sales_service'),
								'NewData' => $this->input->get('new_after_sales_service'),
								'InsertBy' => $this->session->admin_id,
								'InsertDate' => date('Y-m-d H:i:s')
							);
							$this->db->insert('booking_log', $array);
						} else {
							$array = array(
								'BookingID' => $this->input->get('booking_id'),
								'Column' => 'Status',
								'CurrentData' => $this->input->get('current_status'),
								'NewData' => $this->input->get('new_status'),
								'InsertBy' => $this->session->admin_id,
								'InsertDate' => date('Y-m-d H:i:s')
							);
							$this->db->insert('booking_log', $array);
						}
					}
				}
			}
		}
	}
	
	function Create_Booking_Log2($current_status, $new_status, $booking_id)
	{
		// Log to original booking_log table
		$array = array(
			'BookingID' => $booking_id,
			'Column' => 'Status',
			'CurrentData' => $current_status,
			'NewData' => $new_status,
			'InsertBy' => $this->session->admin_id,
			'InsertDate' => date('Y-m-d H:i:s')
		);
		$this->db->insert('booking_log', $array);
		
		// Also log to booking_status_log table
		$this->load->helper('booking_status_log');
		$created_by = !empty($this->session->admin_id) ? $this->session->admin_id : 0;
		log_booking_status_update($booking_id, $current_status, $new_status, $created_by, null, true);
	}

	protected $_snapshot_pdf_path = null;

	function generate_booking_snapshot($token, $booking_id)
	{
		$CI =& get_instance();
		$CI->load->library('Booking_PDF_Generator');

		$snapshot_dir = FCPATH . 'assets/upload/booking_snapshots/';
		if (!is_dir($snapshot_dir)) {
			mkdir($snapshot_dir, 0755, true);
		}

		$filename = $booking_id . '_' . date('Ymd_His') . '.pdf';
		$filepath = $snapshot_dir . $filename;

		$success = $CI->booking_pdf_generator->generate_to_file($token, $filepath);

		if ($success) {
			$this->_snapshot_pdf_path = 'booking_snapshots/' . $filename;
			return $this->_snapshot_pdf_path;
		}
		return null;
	}

	function Update()
	{
		// Get current booking data before update to compare changes
		$booking_id = $this->input->post('booking_id');
		$current_booking = $this->getBookingById($booking_id);
		
		// Get booking data and ensure AllowReview is properly formatted as integer (0 or 1)
		$booking_data = $this->input->post('booking');
		if (!empty($booking_data) && is_array($booking_data)) {
			foreach ($booking_data as $key => $booking_item) {
				if (isset($booking_item['AllowReview'])) {
					// Convert AllowReview to integer (0 or 1) for BOOLEAN field
					$booking_data[$key]['AllowReview'] = (int)$booking_item['AllowReview'];
					// Ensure it's either 0 or 1 (validate)
					if ($booking_data[$key]['AllowReview'] != 0 && $booking_data[$key]['AllowReview'] != 1) {
						$booking_data[$key]['AllowReview'] = 1; // Default to 1 if invalid
					}
				}
			}
		}

		// Nullable int FK columns (BookingOP, SalesAgent2) can be cleared from the
		// form. The browser serialises a cleared value as '' which
		// STRICT_TRANS_TABLES rejects for an int column ("Incorrect integer value:
		// ''"), so coerce empty strings back to NULL before writing.
		$this->load->helper('booking_sanitize');
		$booking_data = nullify_empty_booking_fk($booking_data);

		// Check for critical changes that require status revert to PBC
		$needs_revert = false;
		$revert_reason = '';
		$price_increased = false;

		if ($current_booking) {
			// Check if NetTotal changed
			$new_net_total = isset($booking_data[0]['NetTotal']) ? floatval($booking_data[0]['NetTotal']) : null;
			$old_net_total = isset($current_booking->NetTotal) ? floatval($current_booking->NetTotal) : null;

			if ($new_net_total !== null && $old_net_total !== null && abs($new_net_total - $old_net_total) > 0.01) {
				$needs_revert = true;
				$price_increased = ($new_net_total > $old_net_total);
				$revert_reason = 'Booking total price changed from RM ' . number_format($old_net_total, 2) . ' to RM ' . number_format($new_net_total, 2);
			}
			
			// Check if travel dates changed
			$new_start_date = isset($booking_data[0]['StartDate']) ? $booking_data[0]['StartDate'] : null;
			$new_end_date = isset($booking_data[0]['EndDate']) ? $booking_data[0]['EndDate'] : null;
			$old_start_date = $current_booking->StartDate;
			$old_end_date = $current_booking->EndDate;
			
			// Normalize dates for comparison (handle different formats)
			$normalize_date = function($date) {
				if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return null;
				// Try to parse date
				$parsed = strtotime($date);
				return $parsed ? date('Y-m-d', $parsed) : null;
			};
			
			$new_start_normalized = $normalize_date($new_start_date);
			$new_end_normalized = $normalize_date($new_end_date);
			$old_start_normalized = $normalize_date($old_start_date);
			$old_end_normalized = $normalize_date($old_end_date);
			
			if (($new_start_normalized && $new_start_normalized != $old_start_normalized) ||
			    ($new_end_normalized && $new_end_normalized != $old_end_normalized)) {
				$needs_revert = true;
				$date_change_desc = '';
				if ($new_start_normalized != $old_start_normalized) {
					$date_change_desc .= 'Start date changed from ' . ($old_start_normalized ? date('d/m/Y', strtotime($old_start_normalized)) : 'N/A') . 
					                     ' to ' . ($new_start_normalized ? date('d/m/Y', strtotime($new_start_normalized)) : 'N/A');
				}
				if ($new_end_normalized != $old_end_normalized) {
					if ($date_change_desc) $date_change_desc .= '; ';
					$date_change_desc .= 'End date changed from ' . ($old_end_normalized ? date('d/m/Y', strtotime($old_end_normalized)) : 'N/A') . 
					                     ' to ' . ($new_end_normalized ? date('d/m/Y', strtotime($new_end_normalized)) : 'N/A');
				}
				$revert_reason = $revert_reason ? $revert_reason . '; ' . $date_change_desc : $date_change_desc;
			}

			// Check if pax counts changed (Adult/Children/Infant)
			$pax_change_desc = '';
			foreach (['Adult', 'Children', 'Infant'] as $pax_field) {
				if (!isset($booking_data[0][$pax_field])) continue;
				$new_pax = intval($booking_data[0][$pax_field]);
				$old_pax = intval(isset($current_booking->$pax_field) ? $current_booking->$pax_field : 0);
				if ($new_pax !== $old_pax) {
					if ($pax_change_desc) $pax_change_desc .= '; ';
					$pax_change_desc .= $pax_field . ' changed from ' . $old_pax . ' to ' . $new_pax;
				}
			}
			if ($pax_change_desc) {
				$needs_revert = true;
				$revert_reason = $revert_reason ? $revert_reason . '; ' . $pax_change_desc : $pax_change_desc;
			}

			// Revert to PBC whenever BC has already approved and a material field changed.
			// BC must re-approve from scratch; the booking re-walks PBC -> P -> PBO -> PTV -> PT,
			// so TC re-approval falls out naturally when status reaches PTV again.
			$cancel = isset($current_booking->CancelStatus) ? $current_booking->CancelStatus : null;
			$bc_already_approved = intval(isset($current_booking->bc_approved) ? $current_booking->bc_approved : 0) === 1;
			$is_cancelled = ($cancel === 'Y' || $cancel === 'C');
			$did_revert = false;
			if ($needs_revert && $bc_already_approved && !$is_cancelled) {
				$this->load->model('Booking_Status_Log_Model');
				$this->load->helper('booking_status_log');
				$admin_id = $this->session->userdata('admin_id') ?: 0;

				$booking_data[0]['Status'] = 'PBC';
				$booking_data[0]['bc_approved'] = 0;
				$booking_data[0]['bc_approval_admin_id'] = null;
				$booking_data[0]['bc_approval_date'] = null;

				log_booking_status_change(
					$booking_id,
					'PBC',
					$current_booking->Status,
					$admin_id,
					'Status reverted to PENDING BC CONFIRMATION - BC must re-approve booking: ' . $revert_reason,
					true
				);
				$did_revert = true;
			}

			// Generate PDF snapshot only when we actually revert
			if ($did_revert && !empty($current_booking->Token)) {
				try {
					$this->generate_booking_snapshot($current_booking->Token, $booking_id);
				} catch (\Throwable $e) {
					log_message('error', 'Failed to generate booking snapshot for BookingID ' . $booking_id . ': ' . $e->getMessage());
				}
			}
		}

		// Re-snapshot the team only if the credited agent (TC1) actually changed;
		// an unrelated edit leaves the frozen booking.TeamID untouched.
		$this->_apply_team_snapshot($booking_data);

		$this->db->update_batch('booking', json_decode(json_encode($booking_data)), 'BookingID');

		$data = [];
		$booking = $this->input->post('booking');
		if (!empty($booking) && isset($booking[0])) {
			$booking = $booking[0];
			if (!empty($booking['Customer'])) { $data['name'] = $booking['Customer']; }
			if (!empty($booking['Mobile'])) { $data['phone_number'] = $booking['Mobile']; }
			if (!empty($booking['ChatLanguage'])) { $data['ChatLanguage'] = $booking['ChatLanguage'];}
			if (!empty($this->input->post('ic_passport_no'))) { $data['ic_passport_no'] = strtoupper($this->input->post('ic_passport_no')); }
			if ($this->input->post('tin_no') !== null) { $data['tin_no'] = strtoupper(trim($this->input->post('tin_no'))); }
			// Alternate/secondary customer name from the BC form (blank clears it).
			if ($this->input->post('AltName') !== null) { $data['AltName'] = trim($this->input->post('AltName')) === '' ? null : strtoupper(trim($this->input->post('AltName'))); }
			// Email address from the BC form (kept as typed; blank clears it).
			if ($this->input->post('PrimaryEmail') !== null) { $data['PrimaryEmail'] = trim($this->input->post('PrimaryEmail')) === '' ? null : trim($this->input->post('PrimaryEmail')); }
			if ($data) { $data['updated_at'] = date('Y-m-d H:i:s'); }
		}

		$customerId = $this->input->post('CustomerID');
		if (!empty($customerId) && $customerId != 'undefined' && $customerId != 'null' && is_numeric($customerId)) {			
			$this->load->model('Customer_Model');
			$customer_id = $this->input->post('CustomerID');

			if (!empty($data)) {
				$this->Customer_Model->update_by_id($this->input->post('CustomerID'), $data);
			}
			$customerInfo = get_object_vars($this->Customer_Model->find($this->input->post('CustomerID')));
			if (!empty($customerInfo)) {
				if ($customerInfo['AutocountSyncAction'] == 'C' && $customerInfo['AutocountSyncStatus'] == 'S') {
					$this->Customer_Model->update_by_id($customerInfo['CustomerID'], [
						'AutocountSyncAction' => 'U',
						'AutocountSyncStatus' => 'P'
					]);
				} else if ($customerInfo['AutocountSyncAction'] == 'U' && $customerInfo['AutocountSyncStatus'] == 'S') {
					$this->Customer_Model->update_by_id($customerInfo['CustomerID'], [
						'AutocountSyncAction' => 'U',
						'AutocountSyncStatus' => 'P'
					]);
				} else {
					$this->Customer_Model->update_by_id($customerInfo['CustomerID'], [
						'AutocountSyncStatus'  => 'P',
					]);
				}
			}
		} else {
			if (!empty($data['name']) || !empty($data['phone_number'])) {
				$this->load->model('Customer_Model');

				$data['created_at'] = date('Y-m-d H:i:s');
				$data['AutocountSyncAction'] = 'C';
				$data['AutocountSyncStatus'] = 'P';

				// Hard duplicate-phone block: reuse an existing customer with the
				// same phone (any format) instead of creating a duplicate; only a
				// genuinely new phone creates a new customer + generated code.
				// The agent can override this from the "Existing Customer Found"
				// dialog ("Create new customer anyway") — force_new_customer forces
				// a brand-new customer even when the phone already exists.
				if ($this->input->post('force_new_customer')) {
					$customer_id = $this->Customer_Model->create_with_generated_code($data);
				} else {
					$customer_id = $this->Customer_Model->create_or_reuse_by_phone($data);
				}

				// no need sync directly, cron will sync customer at first
				// // Immediately sync to Autocount
				// $this->load->library('CustomerSync');
				// $this->load->helper('autocount');
				// $config = get_autocount_config();
				// $data['CustomerID'] = $customer_id;
				// $result = $this->customersync->autocount_create($data, $config);

				// if (isset($result['status']) && ($result['status'] == 201 || $result['status'] == 204) && $result['error'] === null) {
				// 	$this->Customer_Model->update_by_id($customer_id, [
				// 		'AutocountSyncStatus'  => 'S',
				// 		'AutocountSyncMessage' => json_encode($result)
				// 	]);
				// } else {
				// 	$this->Customer_Model->update_by_id($customer_id, [
				// 		'AutocountSyncStatus'  => 'F',
				// 		'AutocountSyncMessage' => json_encode($result)
				// 	]);
				// }
			} else {
				$customer_id = null;
			}
		}


		$this->db->set('BookingConfirmationFooterID', null);
		$this->db->where('BookingID', $this->input->post('booking_id'));
		$this->db->where('BookingConfirmationFooterID', '');
		$this->db->update('booking');

		$this->db->set('CustomerID', $customer_id);
		$this->db->where('BookingID', $this->input->post('booking_id'));
		$this->db->where("(CustomerID IS NULL OR CustomerID <> " . $this->db->escape($customer_id) . ")", null, false);
		$this->db->update('booking');

		// A customer attached to a booking (primary or secondary) is a booker, so
		// clear any "non-booker / e-invoice origin" flag they may carry.
		$this->load->model('Customer_Model');
		$this->Customer_Model->Clear_Einvoice_Flag([
			$customer_id,
			isset($this->input->post('booking')[0]['CustomerID2']) ? $this->input->post('booking')[0]['CustomerID2'] : null,
		]);

		$this->db->set('TravelVoucherFooterID', null);
		$this->db->where('BookingID', $this->input->post('booking_id'));
		$this->db->where('TravelVoucherFooterID', '');
		$this->db->update('booking');

		$this->db->set('DepositDeadline', null);
		$this->db->where('BookingID', $this->input->post('booking_id'));
		$this->db->where("CAST(`DepositDeadline` AS CHAR) = '0000-00-00'", null, false);
		$this->db->update('booking');

		$this->db->set('AdditionalPaymentDeadline', null);
		$this->db->where('BookingID', $this->input->post('booking_id'));
		$this->db->where("CAST(`AdditionalPaymentDeadline` AS CHAR) = '0000-00-00'", null, false);
		$this->db->update('booking');

		$this->db->set('StartDate', null);
		$this->db->where('BookingID', $this->input->post('booking_id'));
		$this->db->where("CAST(`StartDate` AS CHAR) = '0000-00-00'", null, false);
		$this->db->update('booking');

		$this->db->set('EndDate', null);
		$this->db->where('BookingID', $this->input->post('booking_id'));
		$this->db->where("CAST(`EndDate` AS CHAR) = '0000-00-00'", null, false);
		$this->db->update('booking');

		$this->db->set('Tag', null);
		$this->db->where('BookingID', $this->input->post('booking_id'));
		$this->db->where('Tag', '');
		$this->db->update('booking');
		
		$this->db->set('BookingRemark', null);
		$this->db->where('BookingID', $this->input->post('booking_id'));
		$this->db->where('BookingRemark', '');
		$this->db->update('booking');

		$this->db->set('Source', null);
		$this->db->where('BookingID', $this->input->post('booking_id'));
		$this->db->where('Source', '');
		$this->db->update('booking');
		
		$this->db->set('BookingConfirmationFooter', null);
		$this->db->where('BookingID', $this->input->post('booking_id'));
		$this->db->where('BookingConfirmationFooter', '');
		$this->db->update('booking');

		$this->db->set('TravelVoucherFooter', null);
		$this->db->where('BookingID', $this->input->post('booking_id'));
		$this->db->where('TravelVoucherFooter', '');
		$this->db->update('booking');

		$this->Sync_Customer_From_Booking($this->input->post('booking_id'));
	}

	/**
	 * Sync the linked customer's name / phone_number from the booking row.
	 *
	 * Called on every booking update so customer contact info stays aligned with the
	 * booking even on partial AJAX saves that don't round-trip Customer/Mobile via POST.
	 * generate_customer_portal_slug() needs both fields to build the portal URL.
	 */
	public function Sync_Customer_From_Booking($booking_id)
	{
		if (empty($booking_id)) {
			return;
		}
		$this->db->query(
			"UPDATE customer c
			 JOIN booking b ON b.CustomerID = c.CustomerID
			 SET c.name = COALESCE(NULLIF(TRIM(b.Customer), ''), c.name),
			     c.phone_number = COALESCE(NULLIF(TRIM(b.Mobile), ''), c.phone_number),
			     c.updated_at = NOW()
			 WHERE b.BookingID = ?",
			[$booking_id]
		);
	}

	function Update_Cancel_Status()
	{
		$array = array(
			'CancelStatus' => $this->input->get('new_cancel_status'),
			'CancellationReasonID' => NULL,
			'CancellationRemark' => NULL,
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s')
		);
		$this->db->where('BookingID', $this->input->get('booking_id'));
		$this->db->update('booking', $array);
	}

	function Update_Cancel_Status_With_Reason()
	{
		$array = array(
			'CancelStatus' => 'Y',
			'CancellationReasonID' => $this->input->post('cancellation_reason_id'),
			'CancellationRemark' => $this->input->post('cancellation_remark') ?: NULL,
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s')
		);
		$this->db->where('BookingID', $this->input->post('booking_id'));
		$this->db->update('booking', $array);
	}

	function Create_Booking_Log_Cancel()
	{
		$array = array(
			'BookingID' => $this->input->post('booking_id'),
			'Column' => 'CancelStatus',
			'CurrentData' => 'N',
			'NewData' => 'Y',
			'InsertBy' => $this->session->admin_id,
			'InsertDate' => date('Y-m-d H:i:s')
		);
		$this->db->insert('booking_log', $array);
	}

	function Update_Partial_Refund_Status()
	{
		// "Cancel With Partial Refund" is treated exactly as a cancellation, so
		// CancelStatus moves in lockstep with PartialRefund (undo clears both).
		$array = array(
			'PartialRefund' => $this->input->get('new_partial_refund_status'),
			'CancelStatus' => $this->input->get('new_partial_refund_status'),
			'CancellationReasonID' => NULL,
			'CancellationRemark' => NULL,
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s')
		);
		$this->db->where('BookingID', $this->input->get('booking_id'));
		$this->db->update('booking', $array);
	}

	function Update_Partial_Refund_Status_With_Reason()
	{
		// "Cancel With Partial Refund" is treated exactly as a cancellation, so
		// it must also set CancelStatus='Y' — every status derivation and BC
		// list filter keys off CancelStatus, not PartialRefund.
		$array = array(
			'PartialRefund' => 'Y',
			'CancelStatus' => 'Y',
			'CancellationReasonID' => $this->input->post('cancellation_reason_id'),
			'CancellationRemark' => $this->input->post('cancellation_remark') ?: NULL,
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s')
		);
		$this->db->where('BookingID', $this->input->post('booking_id'));
		$this->db->update('booking', $array);
	}

	function Create_Booking_Log_Partial_Refund()
	{
		$array = array(
			'BookingID' => $this->input->post('booking_id'),
			'Column' => 'PartialRefund',
			'CurrentData' => 'N',
			'NewData' => 'Y',
			'InsertBy' => $this->session->admin_id,
			'InsertDate' => date('Y-m-d H:i:s')
		);
		$this->db->insert('booking_log', $array);
	}

	function Update_Lock_Status()
	{
		$array = array(
			'LockStatus' => $this->input->get('new_lock_status'),
			'GLSessionLock' => 'N',
			'GLSessionExpiration' => null,
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s')
		);
		if ($this->input->get('new_lock_status') == 'Y') {
			$array['is_submitted'] = 1;
		} else {
			$this->load->model('Guest_List_Model');
			$array['is_submitted'] = $this->Guest_List_Model->Are_All_Guests_Complete($this->input->get('booking_id')) ? 1 : 0;
		}
		$this->db->where('BookingID', $this->input->get('booking_id'));
		$this->db->update('booking', $array);
	}

	function Mark_Guest_List_Submitted($booking_id)
	{
		$array = array(
			'is_submitted' => 1
		);
		$this->db->where('BookingID', $booking_id);
		$this->db->update('booking', $array);
	}

	function Update_Travel_Insurance_Status()
	{
		$array = array(
			'TravelInsuranceStatus' => $this->input->get('new_travel_insurance_status'),
			'GLSessionLock' => 'N',
			'GLSessionExpiration' => null,
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s')
		);
		$this->db->where('BookingID', $this->input->get('booking_id'));
		$this->db->update('booking', $array);
	}

	function Update_After_Sales_Service()
	{
		$array = array(
			'AfterSalesService' => $this->input->get('new_after_sales_service'),
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s')
		);
		$this->db->where('BookingID', $this->input->get('booking_id'));
		$this->db->update('booking', $array);
	}

	function Update_After_Sales_Service2($booking_id)
	{
		$array = array(
			'AfterSalesService' => 'PENDING',
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s')
		);
		$this->db->where('BookingID', $booking_id);
		$this->db->update('booking', $array);
	}

	function Update_Product_Sequence($product_sequence)
	{
		$array = array(
			'ProductSequence' => $product_sequence,
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s')
		);
		$this->db->where('BookingID', $this->input->post('booking_id'));
		$this->db->update('booking', $array);
	}

	function Update_Status($status, $booking_id)
	{
		// Get current status before update
		$this->db->select('Status');
		$this->db->where('BookingID', $booking_id);
		$current_booking = $this->db->get('booking')->row();
		$current_status = $current_booking ? $current_booking->Status : null;
		
		// Update status
		$array = array(
			'Status' => $status,
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s')
		);
		
		// If reverting to PBC, also reset BC approval
		if ($status == 'PBC') {
			$array['bc_approved'] = 0;
			$array['bc_approval_admin_id'] = null;
			$array['bc_approval_date'] = null;
		}
		
		$this->db->where('BookingID', $booking_id);
		$this->db->update('booking', $array);
		
		// Log status change if status actually changed
		if ($current_status && $current_status != $status) {
			$this->load->helper('booking_status_log');
			$created_by = !empty($this->session->userdata('admin_id')) ? $this->session->userdata('admin_id') : 0;
			log_booking_status_update($booking_id, $current_status, $status, $created_by, null, true);
		}
	}

	/**
	 * Revert booking to PBC when its product list changes after BC approval.
	 * Idempotent: no-op if BC has not yet approved, or booking is cancelled.
	 * Called from the controller after booking_product rows are written, so
	 * quantity-only / $0-product edits that leave NetTotal untouched still
	 * force BC re-approval.
	 */
	function Revert_To_PBC_For_Product_Change($booking_id)
	{
		$this->db->select('Status, bc_approved, Token, CancelStatus');
		$this->db->where('BookingID', $booking_id);
		$row = $this->db->get('booking')->row();

		if (!$row) return false;
		if (intval($row->bc_approved) !== 1) return false;
		if ($row->CancelStatus === 'Y' || $row->CancelStatus === 'C') return false;

		$admin_id = $this->session->userdata('admin_id') ?: 0;
		$old_status = $row->Status;

		$this->db->where('BookingID', $booking_id);
		$this->db->update('booking', array(
			'Status' => 'PBC',
			'bc_approved' => 0,
			'bc_approval_admin_id' => null,
			'bc_approval_date' => null,
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s'),
		));

		$this->load->helper('booking_status_log');
		log_booking_status_change(
			$booking_id,
			'PBC',
			$old_status,
			$admin_id,
			'Status reverted to PENDING BC CONFIRMATION - BC must re-approve booking: Booking products changed',
			true
		);

		if (!empty($row->Token)) {
			try {
				$this->generate_booking_snapshot($row->Token, $booking_id);
			} catch (\Throwable $e) {
				log_message('error', 'Failed to generate booking snapshot for BookingID ' . $booking_id . ': ' . $e->getMessage());
			}
		}

		return true;
	}

	function Update_BC_Approval($booking_id, $bc_approved, $admin_id = null)
	{
		$array = array(
			'bc_approved' => $bc_approved ? 1 : 0,
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s')
		);
		
		if ($bc_approved && $admin_id !== null) {
			$array['bc_approval_admin_id'] = $admin_id;
			$array['bc_approval_date'] = date('Y-m-d H:i:s');
			// Keep booking visible in customer portal permanently after first BC approval
			$array['customer_portal_visible'] = 1;
		} else {
			$array['bc_approval_admin_id'] = null;
			$array['bc_approval_date'] = null;
		}
		
		$this->db->where('BookingID', $booking_id);
		$this->db->update('booking', $array);
	}

	function Booking_Document()
	{
		$this->db->select('BookingID, BookingNumber, ReservationNumber, DepositDeadline, FullPaymentDeadline, Customer, booking.Customer2, booking.CustomerID, booking.CustomerID2, booking.Mobile As CustomerMobile, booking.Mobile2 As CustomerMobile2, StartDate, EndDate, Adult, Children, Infant, Subtotal, Discount, NetTotal, DepositPercentage, DepositMode, DepositFixedAmount, booking.BookingConfirmationTitle, BookingConfirmationFooter, TravelVoucherFooter, AfterSalesService, ProductSequence, booking.Status, booking.InsertDate, booking.SalesAgentIsPIC, booking.SalesAgent2IsPIC, admin.CountryCodeID As SalesAgentCountryCode, admin.Name As SalesAgentName, admin.Mobile As SalesAgentMobile, admin2.CountryCodeID As SalesAgent2CountryCode, admin2.Name As SalesAgent2Name, admin2.Mobile As SalesAgent2Mobile, category.Name As DestinationName, TravelVoucherTitle, booking.KeyContacts As TravelVoucherKeyContacts, booking.SpecialRemarks As TravelVoucherSpecialRemarks, country_code.CountryCode, country_code_2.CountryCode As CountryCode2');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('admin admin2', 'admin2.AdminID = booking.SalesAgent2', 'left');
		$this->db->join('category', 'category.CategoryID = booking.Destination', 'left');
		$this->db->join('footer', 'footer.FooterID = booking.TravelVoucherFooterID', 'left');
		$this->db->join('country_code', 'country_code.CountryCodeID = booking.CountryCodeID', 'left');
		$this->db->join('country_code country_code_2', 'country_code_2.CountryCodeID = booking.CountryCodeID2', 'left');
		$this->db->where('Token', $this->input->get('token'));
		return $this->db->get('booking')->row_array();
	}

	function Booking_Document_By_Token($token)
	{
		$this->db->select('BookingID, BookingNumber, ReservationNumber, DepositDeadline, FullPaymentDeadline, Customer, booking.Customer2, booking.CustomerID, booking.CustomerID2, booking.Mobile As CustomerMobile, booking.Mobile2 As CustomerMobile2, StartDate, EndDate, Adult, Children, Infant, Subtotal, Discount, NetTotal, DepositPercentage, DepositMode, DepositFixedAmount, booking.BookingConfirmationTitle, BookingConfirmationFooter, TravelVoucherFooter, AfterSalesService, ProductSequence, booking.Status, booking.InsertDate, booking.SalesAgentIsPIC, booking.SalesAgent2IsPIC, admin.CountryCodeID As SalesAgentCountryCode, admin.Name As SalesAgentName, admin.Mobile As SalesAgentMobile, admin2.CountryCodeID As SalesAgent2CountryCode, admin2.Name As SalesAgent2Name, admin2.Mobile As SalesAgent2Mobile, category.Name As DestinationName, TravelVoucherTitle, booking.KeyContacts As TravelVoucherKeyContacts, booking.SpecialRemarks As TravelVoucherSpecialRemarks, country_code.CountryCode, country_code_2.CountryCode As CountryCode2');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('admin admin2', 'admin2.AdminID = booking.SalesAgent2', 'left');
		$this->db->join('category', 'category.CategoryID = booking.Destination', 'left');
		$this->db->join('footer', 'footer.FooterID = booking.TravelVoucherFooterID', 'left');
		$this->db->join('country_code', 'country_code.CountryCodeID = booking.CountryCodeID', 'left');
		$this->db->join('country_code country_code_2', 'country_code_2.CountryCodeID = booking.CountryCodeID2', 'left');
		$this->db->where('Token', $token);
		return $this->db->get('booking')->row_array();
	}

	function Booking_Document_for_receipt()
	{
		$this->db->select('booking.BookingID, BookingNumber, ReservationNumber, DepositDeadline, DepositMode, DepositPercentage, DepositFixedAmount, FullPaymentDeadline, Customer, booking.Mobile As CustomerMobile, StartDate, EndDate, Adult, Children, Infant, Subtotal, Discount, NetTotal, booking.BookingConfirmationTitle, BookingConfirmationFooter, TravelVoucherFooter, AfterSalesService, ProductSequence, booking.Status, booking.InsertDate, admin.CountryCodeID As SalesAgentCountryCode, admin.Name As SalesAgentName, admin.Mobile As SalesAgentMobile, category.Name As DestinationName, TravelVoucherTitle, CountryCode, customer.CustomerCode');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('category', 'category.CategoryID = booking.Destination', 'left');
		$this->db->join('footer', 'footer.FooterID = booking.TravelVoucherFooterID', 'left');
		$this->db->join('country_code', 'country_code.CountryCodeID = booking.CountryCodeID', 'left');
		$this->db->join('customer', 'customer.CustomerID = booking.CustomerID', 'left');
		$this->db->where('Token', $this->input->get('token'));
		return $this->db->get('booking')->row_array();
	}

	// Rooms are the primary source of truth for displayed pax on customer-facing
	// surfaces (BC, Travel Voucher, Customer Portal). When no rooms have been set up
	// yet we fall back to booking.Adult/Children/Infant so the customer still sees a
	// meaningful figure. Room management changes never write back to those fields.
	function Compute_Pax_Counts($booking_id)
	{
		$this->load->model('Guest_List_Room_Model');

		$adult = 0; $child = 0; $infant = 0;
		$rooms = $this->Guest_List_Room_Model->Read_Rooms_By_Booking_ID($booking_id);
		if(!empty($rooms)) {
			foreach($rooms as $r) {
				$adult  += (int)$r->adult_count;
				$child  += (int)$r->child_count;
				$infant += (int)$r->infant_count;
			}
		} else {
			$this->db->select('Adult, Children, Infant');
			$this->db->where('BookingID', $booking_id);
			$row = $this->db->get('booking')->row();
			if(!empty($row)) {
				$adult  = (int)$row->Adult;
				$child  = (int)$row->Children;
				$infant = (int)$row->Infant;
			}
		}
		return array('adult' => $adult, 'child' => $child, 'infant' => $infant);
	}

	function Detect()
	{
		$this->db->where('BookingNumber', $this->input->post('booking_number'));
		if($this->db->get('booking')->row()) {
			return true;
		} else {
			return false;
		}
	}

	function getAllBookingsWithGuests($booking_id = null)
	{
		// Get all columns from booking table
		$bookingCols = $this->db->list_fields('booking');
		$bookingCols = array_map(function($col) {
			return "booking.`$col`";
		}, $bookingCols);

		// Get all columns from guest_list table, but alias with guest_ prefix
		$guestCols = $this->db->list_fields('guest_list');
		$guestCols = array_map(function($col) {
			return "guest_list.`$col` AS guest_$col";
		}, $guestCols);

		// Merge both columns into one select string
		$allCols = array_merge($bookingCols, $guestCols);
		$this->db->select(implode(', ', $allCols), false);

		// Main query
		$this->db->from('booking');
		$this->db->join('guest_list', 'guest_list.BookingID = booking.BookingID', 'left');

		// Optional filter by BookingID
		if (!is_null($booking_id) && $booking_id !== '') {
			$this->db->where('booking.BookingID', $booking_id);
		}

		$this->db->order_by('booking.BookingID', 'ASC');

		$query = $this->db->get();
		return $query->result();
	}

	function getAllBookingsWithProducts($booking_id = null)
	{
		// Get all columns from booking table
		$bookingCols = $this->db->list_fields('booking');
		$bookingCols = array_map(function($col) {
			return "booking.`$col`";
		}, $bookingCols);

		// Get all columns from booking_product table, alias with product_ prefix
		$productCols = $this->db->list_fields('booking_product');
		$productCols = array_map(function($col) {
			return "booking_product.`$col` AS product_$col";
		}, $productCols);

		// Merge both columns into one select string
		$allCols = array_merge($bookingCols, $productCols);
		$this->db->select(implode(', ', $allCols), false);

		// Main query
		$this->db->from('booking');
		$this->db->join('booking_product', 'booking_product.BookingID = booking.BookingID', 'left');

		// Optional filter by BookingID
		if (!is_null($booking_id) && $booking_id !== '') {
			$this->db->where('booking.BookingID', $booking_id);
		}

		$this->db->order_by('booking.BookingID', 'ASC');

		$query = $this->db->get();
		return $query->result();
	}

	function getBookingById($booking_id)
	{
		$this->db->select('*');
		$this->db->from('booking');
		$this->db->where('BookingID', $booking_id);
		$query = $this->db->get();

		return $query->num_rows() > 0 ? $query->row() : null;
	}


	public function find($booking_id)
    {
        return $this->db->get_where('booking', ['BookingID' => $booking_id])->row();
    }
	
    public function update_by_id($booking_id, $data = [])
    {
        if (empty($data)) return false;

        return $this->db
            ->where('BookingID', $booking_id)
            ->update('booking', $data);
    }

	function getPendingBookingsWithDetails($booking_ids = array())
	{
		$this->load->helper('autocount');
		$config = get_autocount_config();

		$booking_qty_cront = !empty($config['booking_qty_cront'])
			? $config['booking_qty_cront']
			: 10;
		
		// --- 1. Get all booking columns (no prefix) ---
		$bookingCols = $this->db->list_fields('booking');
		$bookingCols = array_map(function($col) {
			return "booking.`$col`";
		}, $bookingCols);
		$this->db->select(implode(', ', $bookingCols), false);

		$statuses = !empty($config['booking_sync_autocount_status']) 
			? $config['booking_sync_autocount_status'] 
			: ['P'];

		$titles   = !empty($config['booking_sync_status']) 
			? $config['booking_sync_status'] 
			: ['BOOKING CONFIRMATION'];

		// base query
		$this->db->from('booking')
			->join('customer', 'booking.CustomerID = customer.CustomerID', 'left')
			->where("(customer.CustomerCode IS NOT NULL AND customer.CustomerCode <> '')")
			->where_in('booking.AutocountSyncStatus', $statuses)
			->where_in('booking.BookingConfirmationTitle', $titles)
			->where('booking.AutocountSyncAction IS NOT NULL')
			->order_by('booking.BookingID', 'ASC')
			->limit($booking_qty_cront);

			// new condition remove have payment only can sync : ->where("EXISTS (SELECT 1 FROM payment WHERE payment.BookingID = booking.BookingID AND payment.Status = 'Y')")

		if (!empty($config['booking_cutoff_date'])) {
			$date = date('Y-m-d', strtotime($config['booking_cutoff_date']));
			$this->db->where('booking.InsertDate >', $date);
		}

		// extra filter if booking_id is provided
		if (!empty($booking_ids)) {
			$this->db->where_in('booking.BookingID', $booking_ids);
		}

		$bookings = $this->db->get()->result_array();

		if (empty($bookings)) {
			return [];
		}

		$bookingIds = array_column($bookings, 'BookingID');

		// --- 2. Guest list with guest_ prefix (flattened, 1st guest only) ---
		$guestCols = $this->db->list_fields('guest_list');
		$guestCols = array_map(function($col) {
			return "guest_list.`$col` AS guest_$col";
		}, $guestCols);
		$this->db->select(implode(', ', $guestCols), false);
		$guests = $this->db
			->where_in('guest_list.BookingID', $bookingIds)
			->get('guest_list')
			->result_array();

		$guestByBooking = [];
		foreach ($guests as $g) {
			$bookingId = $g['guest_BookingID'];
			if (!isset($guestByBooking[$bookingId])) {
				$guestByBooking[$bookingId] = $g; // keep first guest only
			}
		}

		// --- 3. Products with product_ prefix (array under "details") ---
		$productCols = $this->db->list_fields('booking_product');
		$productCols = array_map(function($col) {
			return "booking_product.`$col` AS product_$col";
		}, $productCols);
		$this->db->select(implode(', ', $productCols), false);
		$products = $this->db
			->where_in('booking_product.BookingID', $bookingIds)
			->where('booking_product.Status', 'Y')
			->get('booking_product')
			->result_array();

		$productsByBooking = [];
		foreach ($products as $p) {
			$productsByBooking[$p['product_BookingID']][] = $p;
		}

		// --- 4. Attach guest (flat fields) & products (array as details) ---
		foreach ($bookings as &$b) {
			if (isset($guestByBooking[$b['BookingID']])) {
				$b = array_merge($b, $guestByBooking[$b['BookingID']]);
			}
			$b['booking_product'] = $productsByBooking[$b['BookingID']] ?? [];
		}

		return $bookings;
	}

	public function get_pending_sycn_booking_customer()
	{
		$this->load->helper('autocount');
		$config = get_autocount_config();

		$customer_qty_cront = !empty($config['customer_qty_cront'])
			? (int)$config['customer_qty_cront']
			: 10;

		$statuses = !empty($config['customer_sync_autocount_status'])
			? (array)$config['customer_sync_autocount_status']
			: ['P'];

		$this->db->from('booking');
		$this->db->where_in('CustomerAutocountSyncStatus', $statuses);
		$this->db->where('CustomerAutocountSyncAction IS NOT NULL', null, false);

		if (!empty($config['customer_cutoff_date'])) {
			$date = date('Y-m-d', strtotime($config['customer_cutoff_date']));
			$this->db->where('booking.InsertDate >', $date);
		}

		$this->db->limit($customer_qty_cront);

		return $this->db->get()->result_array();
	}

	/**
	 * Apply filters to the query builder (shared logic for pagination methods)
	 */
	// Resolve the admin IDs of the logged-in user's Team(s), used to scope the
	// listing for a TEAM LEAD (25) / OP TEAM LEAD (45). A leader may oversee
	// several teams (admin_team), so this unions every member of their primary
	// team and each extra team. Uses raw query() so it does NOT flush the
	// query-builder state that apply_booking_filters is mid-way through building.
	private function team_member_ids()
	{
		$this->load->helper('team_scope');
		return team_member_ids_from_db($this->db, $this->session->userdata('admin_id'));
	}

	private function apply_booking_filters()
	{
		$level    = (int) $this->session->userdata('level');
		$admin_id = (int) $this->session->userdata('admin_id');

		// Role-based row scope, driven by team structure (shared admin.TeamID). A
		// booking is "assigned" to three people: TC = booking.SalesAgent,
		// TC2 = booking.SalesAgent2, OP = booking.BookingOP.
		//   Individual roles — SALES AGENT (20) / TC (50): only bookings they are
		//     personally assigned to, in ANY of the three slots.
		//   Team roles — TEAM LEAD (25) / OP (40) / OP TEAM LEAD (45): every booking
		//     whose TC, TC2 or OP belongs to their team. Cross-team: a booking whose
		//     people span teams is visible to each involved team's lead/OP.
		//   Everyone else (Owner 10, Finance 30, Marketing 60): unscoped.
		// The "Payment From Customer Due Soon" card is scoped to the viewer's OWN
		// bookings (SalesAgent/SalesAgent2 = me, same as a TC) for BOTH the Owner
		// (10) and the TC Lead (25) — on the booking listing they render the
		// sales-agent card set (summary_cards_show_agent_set). So their
		// ?customer_payment drill-down must scope to own bookings too, otherwise
		// the default listing (Owner = unscoped/company-wide, TC Lead =
		// team-scoped) shows far more rows than the card counted. Treat levels
		// 10/25 like a TC (own scope) ONLY for this drill-down; their normal
		// listing keeps its wider scope. See CustomerPaymentTcLeadScopeParityTest
		// and CustomerPaymentOwnerScopeParityTest.
		$own_payment_drilldown = (in_array($level, [10, 25]) && !empty($this->input->get('customer_payment')));

		if(in_array($level, [20, 50]) || $own_payment_drilldown) {
			$this->db->group_start();
			$this->db->where('booking.SalesAgent', $admin_id);
			$this->db->or_where('booking.SalesAgent2', $admin_id);
			$this->db->or_where('booking.BookingOP', $admin_id);
			$this->db->group_end();
		} elseif(in_array($level, [25, 40, 45])) {
			$team = $this->team_member_ids();
			$this->db->group_start();
			$this->db->where_in('booking.SalesAgent', $team);
			$this->db->or_where_in('booking.SalesAgent2', $team);
			$this->db->or_where_in('booking.BookingOP', $team);
			$this->db->group_end();
		}

		// Hide completed bookings from TC only (SA can view in listing; detail page still blocks via Booking::View / Payment guards)
		if($this->session->userdata('level') == 50) {
			$this->db->where("NOT (booking.Status = 'Y' AND booking.AfterSalesService = 'COMPLETE')");
		}

		// DataTables global search box: match against EVERY column shown in the
		// listing (and a few useful hidden identifiers). All referenced tables are
		// joined by the two search callers — Read_Bookings_Paginated and
		// Count_Bookings_Filtered (sa2_admin added to the count for this). Dates and
		// NetTotal are matched via LIKE so a partial like "2026-08" or "1500" works.
		$search_value = $this->input->get('search[value]');
		if(!empty($search_value)) {
			$this->db->group_start();
			// Booking / customer identifiers
			$this->db->like('BookingNumber', $search_value);
			$this->db->or_like('ReservationNumber', $search_value);
			$this->db->or_like('Customer', $search_value);
			$this->db->or_like('customer.name', $search_value);
			$this->db->or_like('customer.CustomerCode', $search_value);
			$this->db->or_like('booking.Mobile', $search_value);
			$this->db->or_like('booking.Mobile2', $search_value);
			// People
			$this->db->or_like('admin.Name', $search_value);        // Sales Agent
			$this->db->or_like('sa2_admin.Name', $search_value);    // Sales Agent 2
			$this->db->or_like('op_admin.Name', $search_value);     // OP
			// Booking attributes
			$this->db->or_like('source.Name', $search_value);       // Source
			$this->db->or_like('category.Name', $search_value);     // Destination
			$this->db->or_like('booking.ChatLanguage', $search_value);
			$this->db->or_like('booking.BookingConfirmationTitle', $search_value);
			$this->db->or_like('cancellation_reason.Name', $search_value);
			$this->db->or_like('booking.AutocountSyncStatus', $search_value);
			// Dates (partial text match) and amount
			$this->db->or_like('booking.InsertDate', $search_value);
			$this->db->or_like('booking.StartDate', $search_value);
			$this->db->or_like('booking.EndDate', $search_value);
			$this->db->or_like('booking.NetTotal', $search_value);
			$this->db->group_end();
		}

		// These filters can ignore others
		$ignore = 0;

		// TRIM() both sides so leading/trailing whitespace in either the
		// stored customer / booking.Customer name, or in the user's input,
		// does not hide bookings. Mirrors the customer-list filter via
		// customer_name_search_helper. Matches the customer name only (not
		// guest-list names) so a guest sharing the search term does not pull
		// in an unrelated booking.
		$customer_raw = $this->input->get('customer');
		$customer_q = trim((string) $customer_raw);
		if ($customer_q !== '') {
			$like = $this->db->escape_like_str($customer_q);
			$this->db->group_start();
				$this->db->where(customer_name_trim_like_fragment('booking.Customer', $like, 'both'), null, false);
				$this->db->or_where(customer_name_trim_like_fragment('customer.name', $like, 'both'), null, false);
			$this->db->group_end();
			$ignore = 1;
		}

		if(!empty($this->input->get('booking_number'))) {
			$this->db->where('BookingNumber', $this->input->get('booking_number'));
			$ignore = 1;
		}

		if(!empty($this->input->get('reservation_number'))) {
			$this->db->like('ReservationNumber', $this->input->get('reservation_number'));
			$ignore = 1;
		}

		if($ignore == 0) {
			// Second level ignore
			$level2Ignore = 0;

			if(!empty($this->input->get('deadline'))) {
				$deadline = explode(' - ', $this->input->get('deadline'));
				$start_date = date('Y-m-d', strtotime(str_replace('/', '-', $deadline[0])));
				$end_date = date('Y-m-d', strtotime(str_replace('/', '-', $deadline[1])));
				$this->db->where("((`DepositDeadline` >= '".$start_date."' AND `DepositDeadline` <= '".$end_date."') OR (`FullPaymentDeadline` >= '".$start_date."' AND `FullPaymentDeadline` <= '".$end_date."') OR (`AdditionalPaymentDeadline` >= '".$start_date."' AND `AdditionalPaymentDeadline` <= '".$end_date."')) AND `booking`.`Status` IN ('P','PP')");
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('mobile'))) {
				$this->db->where('booking.Mobile', $this->input->get('mobile'));
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('travel_date'))) {
				$travel_date = explode(' - ', $this->input->get('travel_date'));
				$start_date = date('Y-m-d', strtotime(str_replace('/', '-', $travel_date[0])));
				$end_date = date('Y-m-d', strtotime(str_replace('/', '-', $travel_date[1])));
				$this->db->where("((`StartDate` <= '".$start_date."' AND `EndDate` >= '".$end_date."') OR (`StartDate` >= '".$start_date."' AND `StartDate` <= '".$end_date."') OR (`EndDate` >= '".$start_date."' AND `EndDate` <= '".$end_date."'))");
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('destination'))) {
				$this->db->where_in('Destination', explode(',', $this->input->get('destination')));
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('sales_agent'))) {
				$this->db->where_in('SalesAgent', explode(',', $this->input->get('sales_agent')));
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('booking_op'))) {
				$this->db->where_in('BookingOP', explode(',', $this->input->get('booking_op')));
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('sales_agent_2'))) {
				$this->db->where_in('SalesAgent2', explode(',', $this->input->get('sales_agent_2')));
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('tag'))) {
				$tags = explode(',', $this->input->get('tag'));
				$this->db->group_start();
				foreach($tags as $i => $t) {
					$t = (int)$t;
					if($i == 0) {
						$this->db->where("FIND_IN_SET($t, Tag)", null, false);
					} else {
						$this->db->or_where("FIND_IN_SET($t, Tag)", null, false);
					}
				}
				$this->db->group_end();
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('chat_language'))) {
				$this->db->where_in('booking.ChatLanguage', explode(',', $this->input->get('chat_language')));
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('source'))) {
				$this->db->where_in('Source', explode(',', $this->input->get('source')));
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('customer_type'))) {
				$customer_type_ids = array_filter(array_map('intval', explode(',', $this->input->get('customer_type'))));
				if(!empty($customer_type_ids)) {
					$in = implode(',', $customer_type_ids);
					$this->db->where("EXISTS (SELECT 1 FROM booking_customer_type bct WHERE bct.BookingID = booking.BookingID AND bct.CustomerTypeID IN ($in))", null, false);
				}
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('booking_confirmation_title'))) {
				$this->db->where_in('booking.BookingConfirmationTitle', explode(',', $this->input->get('booking_confirmation_title')));
				$level2Ignore = 1;
			}
			// No default BC-Title restriction on the listing: it shows every
			// confirmation title (BC / QUOTATION / PROFORMA INVOICE) unless the
			// user picks the BC Title filter above. The BOOKING CONFIRMATION rule
			// stays only on the KPI cards' own count queries.
			if(!empty($this->input->get('autocount_status'))) {
				$this->db->where_in('booking.AutocountSyncStatus', explode(',', $this->input->get('autocount_status')));
				$level2Ignore = 1;
			}
			if($this->apply_guest_list_status_filter()) {
				$level2Ignore = 1;
			}
			if($this->apply_checklist_filter()) {
				$level2Ignore = 1;
			}
			if($this->apply_supplier_payout_filter()) {
				$level2Ignore = 1;
			}
			if($this->apply_checklist_payout_filter()) {
				$level2Ignore = 1;
			}
			if($this->apply_customer_payment_filter()) {
				$level2Ignore = 1;
			}
			if($this->apply_slow_conversion_filter()) {
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('exclude_finished'))) {
				// "Exclude completed & pending-review" toggle on the OP
				// "Pending Insurance Checklist" card. Status='Y' is a finished
				// trip in either after-sales state — COMPLETE (review done) or
				// PENDING (AfterSalesService='PENDING', i.e. pending review) —
				// so dropping Status='Y' removes both at once and the drill-down
				// matches the card's count when the switch is on.
				$this->db->where('booking.Status !=', 'Y');
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('cancellation_reason'))) {
				$this->db->where_in('booking.CancellationReasonID', explode(',', $this->input->get('cancellation_reason')));
				$this->db->where('CancelStatus', 'Y');
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('einvoice_status'))) {
				$einvoice_values = array_map('trim', explode(',', $this->input->get('einvoice_status')));
				$has_yes = in_array('yes', $einvoice_values);
				$has_no = in_array('no', $einvoice_values);
				if($has_yes && !$has_no) {
					$this->db->where("(SELECT COUNT(*) FROM invoice_split_pax WHERE invoice_split_pax.BookingID = booking.BookingID AND invoice_split_pax.Status = 'Y') > 0");
				} else if($has_no && !$has_yes) {
					$this->db->where("(SELECT COUNT(*) FROM invoice_split_pax WHERE invoice_split_pax.BookingID = booking.BookingID AND invoice_split_pax.Status = 'Y') = 0");
				}
				$level2Ignore = 1;
			}
			// Departure-date scope (StartDate within range) — distinct from the
			// generic travel_date OVERLAP filter above. Backs the OP "Travelling
			// Tomorrow" cards whose count keys off StartDate = tomorrow exactly;
			// the overlap clause would over-count trips merely spanning the day.
			if(!empty($this->input->get('travel_start_date'))) {
				$tsd = explode(' - ', $this->input->get('travel_start_date'));
				$tsd_start = date('Y-m-d', strtotime(str_replace('/', '-', $tsd[0])));
				$tsd_end   = date('Y-m-d', strtotime(str_replace('/', '-', $tsd[1])));
				$this->db->where('booking.StartDate >=', $tsd_start);
				$this->db->where('booking.StartDate <=', $tsd_end);
				$level2Ignore = 1;
			}
			// Exclude specific booking statuses (comma list). Backs the OP
			// "Travelling Tomorrow – Not Yet Ready" red card (exclude_status=PT),
			// dropping the already-ready Pending Travel BCs so the drill-down
			// matches the card count.
			if(!empty($this->input->get('exclude_status'))) {
				$excluded = array_filter(array_map('trim', explode(',', $this->input->get('exclude_status'))));
				if(!empty($excluded)) {
					$this->db->where_not_in('booking.Status', $excluded);
				}
				$level2Ignore = 1;
			}
			if(!empty($this->input->get('upcoming_not_ready'))) {
				// Mirror the dashboard "Travel in N Days – Not Yet Ready" card query
				// exactly so the count and the linked listing return the same set.
				// The card (Booking::ajax_summary_cards) counts confirmed BCs the TC
				// is *credited* for under the TC1/TC2 cutoff rule, in a not-yet-
				// departed status, whose travel STARTS within the window. Three
				// alignments vs the generic filters:
				//   1. require BOOKING CONFIRMATION (the card excludes quotations);
				//   2. scope by the credited slot, not the broad SalesAgent OR
				//      SalesAgent2 the TC listing applies at the top of this method;
				//   3. constrain on StartDate within the window. The card link also
				//      carries travel_date, whose generic range-overlap clause is
				//      broader; ANDing StartDate-in-window collapses it to the card's
				//      StartDate-BETWEEN (an in-window StartDate already implies
				//      overlap), so no change to that shared block is needed.
				$this->db->where('CancelStatus', 'N');
				$this->db->where_in('booking.Status', array('P','PBO','PGL','PTV'));
				$this->db->where('booking.BookingConfirmationTitle', 'BOOKING CONFIRMATION');

				if(in_array($this->session->userdata('level'), [20, 50])) {
					$this->load->helper('lead_conversion_credit');
					$admin_id = (int) $this->session->userdata('admin_id');
					$cutoff   = LEAD_CONVERSION_TC2_CUTOFF_DATE;
					$this->db->where(
						"((booking.InsertDate < '{$cutoff}' AND booking.SalesAgent = {$admin_id})"
						. " OR (booking.InsertDate >= '{$cutoff}' AND booking.SalesAgent2 = {$admin_id}))",
						null, false
					);
				}

				if(!empty($this->input->get('travel_date'))) {
					$travel_date = explode(' - ', $this->input->get('travel_date'));
					$win_start = date('Y-m-d', strtotime(str_replace('/', '-', $travel_date[0])));
					$win_end   = date('Y-m-d', strtotime(str_replace('/', '-', $travel_date[1])));
					$this->db->where('booking.StartDate >=', $win_start);
					$this->db->where('booking.StartDate <=', $win_end);
				}
				$level2Ignore = 1;
			}
			// Credited-slot scope for the agent "BC Created" card drill-down. The
			// card counts BCs the viewer is *credited* for under the TC1/TC2 cutoff
			// rule (SalesAgent pre-2026-06-01, SalesAgent2 on/after), but the
			// listing's default level scope is broader: unscoped for the Owner (10),
			// any-slot for a TC (20/50), whole team for a TC Lead (25). Without this
			// the drill-down disagrees with the count — e.g. the Owner sees every
			// agent's bookings. ANDing the exact credited predicate collapses the
			// listing to the card's set. Mirrors lead_conversion_credit_booking_clause()
			// byte-for-byte so count and list stay in step.
			if(!empty($this->input->get('credited_agent'))) {
				$ca     = (int) $this->input->get('credited_agent');
				$cutoff = defined('LEAD_CONVERSION_TC2_CUTOFF_DATE') ? LEAD_CONVERSION_TC2_CUTOFF_DATE : '2026-06-01';
				$this->db->where(
					"((booking.InsertDate < '{$cutoff}' AND booking.SalesAgent = {$ca})"
					. " OR (booking.InsertDate >= '{$cutoff}' AND booking.SalesAgent2 = {$ca}))",
					null, false
				);
				$level2Ignore = 1;
			}
			$this->apply_status_filter();
			if(!empty($this->input->get('status'))) {
				$level2Ignore = 1;
			}
		}

		if(!empty($this->input->get('booking_date'))) {
			$booking_date = explode(' - ', $this->input->get('booking_date'));
			$start_date = date('Y-m-d', strtotime(str_replace('/', '-', $booking_date[0])));
			$end_date = date('Y-m-d', strtotime(str_replace('/', '-', $booking_date[1])));
			$this->db->where('CAST(booking.InsertDate AS DATE) >=', $start_date);
			$this->db->where('CAST(booking.InsertDate AS DATE) <=', $end_date);
		}

		$this->db->where('booking.Status !=', 'N');
	}

	/**
	 * Read bookings with pagination for DataTables server-side processing
	 */
	function Read_Bookings_Paginated($start, $length, $order_column, $order_dir)
	{
		$this->db->select('booking.BookingID, BookingNumber, DepositDeadline, FullPaymentDeadline, Customer, booking.Mobile As CustomerMobile, StartDate, EndDate, NetTotal, booking.ChatLanguage, Token, booking.BookingConfirmationTitle, CancelStatus, booking.PartialRefund, LockStatus, booking.is_submitted, AfterSalesService, booking.Status, booking.bc_approved, booking.bc_approval_admin_id, booking.bc_approval_date, booking.InsertDate, admin.Name As SalesAgentName, admin.AdminID AS SalesAgentID, booking.BookingOP, op_admin.Name As BookingOPName, category.Name As DestinationName, CountryCode, booking.AutocountSyncStatus, booking.AutocountSyncMessage, booking.AutocountSyncAction, booking.CustomerAutocountSyncStatus, booking.CustomerAutocountSyncMessage, booking.CustomerAutocountSyncAction, customer.CustomerCode, booking.CustomerID, source.Name AS SourceName, cancellation_reason.Name AS CancellationReasonName, booking.CancellationRemark, booking.SalesAgent2, sa2_admin.Name As SalesAgent2Name');
		$this->db->select("(SELECT COUNT(*) FROM invoice_split_pax WHERE invoice_split_pax.BookingID = booking.BookingID AND invoice_split_pax.Status = 'Y') AS has_einvoice", FALSE);
		$this->db->select("(CASE WHEN booking.CancelStatus = 'Y' THEN 10 WHEN booking.DepositDeadline IS NOT NULL AND ((booking.DepositDeadline < CURDATE() AND booking.Status = 'P') OR (booking.FullPaymentDeadline < CURDATE() AND booking.Status IN ('P','PP'))) THEN 1 WHEN booking.DepositDeadline IS NULL AND booking.FullPaymentDeadline < CURDATE() AND booking.Status IN ('P','PP') THEN 1 WHEN booking.Status = 'P' THEN 2 WHEN booking.Status = 'PP' THEN 3 WHEN booking.Status = 'PBC' THEN 4 WHEN booking.Status = 'PBO' THEN 5 WHEN booking.LockStatus = 'N' AND booking.Status = 'PTV' THEN 6 WHEN booking.LockStatus = 'Y' AND booking.Status = 'PTV' THEN 7 WHEN booking.Status = 'PT' THEN 8 WHEN booking.Status = 'OG' THEN 9 WHEN booking.AfterSalesService = 'PENDING' AND booking.Status = 'Y' THEN 11 WHEN booking.Status = 'Y' THEN 12 ELSE 99 END) AS status_sort_priority", FALSE);
		// Net profit / margin are computed per row in the controller from payments
		// (credit minus debit over approved/pending payments). Mirror that here as
		// sortable aliases so the Net Profit / Net Profit Margin columns sort by their
		// real values instead of NetTotal. Margin = net profit / net sales.
		$this->db->select("(SELECT COALESCE(SUM(CASE WHEN p.Credit <> 0 THEN p.Credit ELSE -p.Debit END), 0) FROM payment p WHERE p.BookingID = booking.BookingID AND p.Status IN ('Y','P')) AS net_profit_sort", FALSE);
		$this->db->select("COALESCE((SELECT COALESCE(SUM(CASE WHEN p.Credit <> 0 THEN p.Credit ELSE -p.Debit END), 0) FROM payment p WHERE p.BookingID = booking.BookingID AND p.Status IN ('Y','P')) / NULLIF(booking.NetTotal, 0), 0) AS profit_margin_sort", FALSE);
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('admin AS sa2_admin', 'sa2_admin.AdminID = booking.SalesAgent2', 'left');
		$this->db->join('admin AS op_admin', 'op_admin.AdminID = booking.BookingOP', 'left');
		$this->db->join('category', 'category.CategoryID = booking.Destination', 'left');
		$this->db->join('country_code', 'country_code.CountryCodeID = booking.CountryCodeID', 'left');
		$this->db->join('customer', 'customer.CustomerID = booking.CustomerID', 'left');
		$this->db->join('source', 'source.SourceID = booking.Source', 'left');
		$this->db->join('cancellation_reason', 'cancellation_reason.CancellationReasonID = booking.CancellationReasonID', 'left');

		$this->apply_booking_filters();

		// Order by
		$this->db->order_by($order_column, $order_dir, FALSE);

		// Pagination
		$this->db->limit($length, $start);

		return $this->db->get('booking')->result();
	}

	/**
	 * Count total bookings without filters (for DataTables recordsTotal)
	 */
	function Count_Bookings_Total()
	{
		$this->db->from('booking');
		if(in_array($this->session->userdata('level'), [20, 50])) {
			$admin_id = $this->session->userdata('admin_id');
			$this->db->group_start();
			$this->db->where('SalesAgent', $admin_id);
			$this->db->or_where('SalesAgent2', $admin_id);
			$this->db->group_end();
		}
		// Hide completed bookings from TC only (SA can view in listing; detail page still blocks via Booking::View / Payment guards)
		if($this->session->userdata('level') == 50) {
			$this->db->where("NOT (booking.Status = 'Y' AND booking.AfterSalesService = 'COMPLETE')");
		}
		$this->db->where('booking.Status !=', 'N');
		return $this->db->count_all_results();
	}

	/**
	 * Count bookings with filters applied (for DataTables recordsFiltered)
	 */
	function Count_Bookings_Filtered()
	{
		$this->db->from('booking');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('admin AS sa2_admin', 'sa2_admin.AdminID = booking.SalesAgent2', 'left');
		$this->db->join('admin AS op_admin', 'op_admin.AdminID = booking.BookingOP', 'left');
		$this->db->join('category', 'category.CategoryID = booking.Destination', 'left');
		$this->db->join('country_code', 'country_code.CountryCodeID = booking.CountryCodeID', 'left');
		$this->db->join('customer', 'customer.CustomerID = booking.CustomerID', 'left');
		$this->db->join('source', 'source.SourceID = booking.Source', 'left');
		$this->db->join('cancellation_reason', 'cancellation_reason.CancellationReasonID = booking.CancellationReasonID', 'left');

		$this->apply_booking_filters();

		return $this->db->count_all_results();
	}

	/**
	 * Calculate summary totals for all filtered bookings
	 * Returns total sales and calculates net profit from payments
	 */
	function Calculate_Summary()
	{
		// First get all filtered booking IDs and their NetTotal
		$this->db->select('booking.BookingID, booking.NetTotal');
		$this->db->from('booking');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('admin AS sa2_admin', 'sa2_admin.AdminID = booking.SalesAgent2', 'left');
		$this->db->join('admin AS op_admin', 'op_admin.AdminID = booking.BookingOP', 'left');
		$this->db->join('category', 'category.CategoryID = booking.Destination', 'left');
		$this->db->join('country_code', 'country_code.CountryCodeID = booking.CountryCodeID', 'left');
		$this->db->join('customer', 'customer.CustomerID = booking.CustomerID', 'left');
		$this->db->join('source', 'source.SourceID = booking.Source', 'left');
		$this->db->join('cancellation_reason', 'cancellation_reason.CancellationReasonID = booking.CancellationReasonID', 'left');

		$this->apply_booking_filters();

		$bookings = $this->db->get()->result();

		$total_sales = 0;
		$total_net_profit = 0;

		// Prefetch every filtered booking's payments in one query instead of one
		// query per booking (N+1). Grouped by BookingID; the per-booking maths below
		// is unchanged.
		$booking_ids = array();
		foreach($bookings as $booking) {
			$booking_ids[] = $booking->BookingID;
		}
		$payments_by_booking = $this->Read_Payments_For_Bookings($booking_ids);

		foreach($bookings as $booking) {
			$total_sales += $booking->NetTotal;

			$payments = isset($payments_by_booking[$booking->BookingID]) ? $payments_by_booking[$booking->BookingID] : array();
			$total_credit = 0;
			$total_debit = 0;

			if(!empty($payments)) {
				foreach($payments as $payment) {
					if($payment->Status == 'Y' || $payment->Status == 'P') {
						if($payment->Credit != 0.00) {
							$total_credit += $payment->Credit;
						} else {
							$total_debit += $payment->Debit;
						}
					}
				}
			}
			$total_net_profit += ($total_credit - $total_debit);
		}

		return array(
			'total_sales' => $total_sales,
			'total_net_profit' => $total_net_profit
		);
	}

	/**
	 * Bulk update AutocountSyncStatus for multiple bookings
	 * @param array $booking_ids Array of booking IDs to update
	 * @param string $status The new status to set (e.g., 'P' for Pending)
	 * @return bool True if at least one row was affected
	 */
	function Update_Autocount_Status_Bulk($booking_ids, $status)
	{
		$this->db->where_in('BookingID', $booking_ids);
		$this->db->update('booking', [
			'AutocountSyncStatus' => $status,
			'AutocountSyncMessage' => null
		]);
		return $this->db->affected_rows() > 0;
	}

	/**
	 * Update AutocountSyncStatus to Pending with reset logic
	 * If status is F: update directly to P
	 * If status is P: update to F first, then to P (to trigger re-sync)
	 * @param array $booking_ids Array of booking IDs to update
	 * @return bool True on success
	 */
	function Update_Autocount_Status_To_Pending_With_Reset($booking_ids)
	{
		// Get current status for all selected bookings
		$this->db->select('BookingID, AutocountSyncStatus');
		$this->db->where_in('BookingID', $booking_ids);
		$bookings = $this->db->get('booking')->result_array();

		foreach ($bookings as $booking) {
			if ($booking['AutocountSyncStatus'] == 'P') {
				// P -> F -> P (intermediate F to reset)
				$this->db->where('BookingID', $booking['BookingID']);
				$this->db->update('booking', [
					'AutocountSyncStatus' => 'F',
					'AutocountSyncMessage' => 'Reset from P status'
				]);
			}
			// Now update to P
			$this->db->where('BookingID', $booking['BookingID']);
			$this->db->update('booking', [
				'AutocountSyncStatus' => 'P',
				'AutocountSyncMessage' => null
			]);
		}

		return true;
	}

	function Read_Booking_Logs($booking_id)
	{
		$this->db->select('booking_log.Column, booking_log.CurrentData, booking_log.NewData, booking_log.InsertDate, booking_log.SnapshotPDF, admin.Name As AdminName');
		$this->db->from('booking_log');
		$this->db->join('admin', 'admin.AdminID = booking_log.InsertBy', 'left');
		$this->db->where('booking_log.BookingID', $booking_id);
		$this->db->order_by('booking_log.InsertDate', 'DESC');
		return $this->db->get()->result_array();
	}

	function Create_GL_From_Rooms($booking_id)
	{
		$this->load->model('Guest_List_Room_Model');
		$this->load->model('Guest_List_Model');
		$rooms = $this->Guest_List_Room_Model->Read_Rooms_By_Booking_ID($booking_id);

		$adult_total = 0;
		$child_total = 0;
		$infant_total = 0;

		if (!empty($rooms)) {
			foreach ($rooms as $room) {
				$adult_total += (int)$room->adult_count;
				$child_total += (int)$room->child_count;
				$infant_total += (int)$room->infant_count;
			}
		}

		// Seed the group leader (first ADULT row) from the booking customer so
		// the Guest List / room management form opens pre-filled rather than
		// blank. Later adults, children and infants stay blank.
		$leader_seed = $this->Leader_Seed_From_Booking($booking_id);

		for ($i = 0; $i < $adult_total; $i++) {
			$seed = ($i === 0) ? $leader_seed : array();
			$this->Guest_List_Model->Create($booking_id, 'ADULT', $seed);
		}
		for ($i = 0; $i < $child_total; $i++) {
			$this->Guest_List_Model->Create($booking_id, 'CHILD');
		}
		for ($i = 0; $i < $infant_total; $i++) {
			$this->Guest_List_Model->Create($booking_id, 'INFANT');
		}

		$this->Guest_List_Model->Auto_Assign_Rooms($booking_id);
	}

	// Build the guest_list column overrides used to pre-fill the group leader
	// row from the booking customer (name, mobile, mobile country code) plus
	// the linked customer's email when one exists.
	function Leader_Seed_From_Booking($booking_id)
	{
		$this->load->helper('guest_leader_helper');

		$booking = $this->db
			->select('Customer, Mobile, CountryCodeID, CustomerID')
			->where('BookingID', $booking_id)
			->get('booking')->row();
		if (!$booking) {
			return array();
		}

		$email = '';
		if (!empty($booking->CustomerID)) {
			$customer = $this->db
				->select('PrimaryEmail')
				->where('CustomerID', $booking->CustomerID)
				->get('customer')->row();
			if ($customer && !empty($customer->PrimaryEmail)) {
				$email = $customer->PrimaryEmail;
			}
		}

		return guest_leader_seed_fields(
			$booking->Customer,
			$booking->Mobile,
			$booking->CountryCodeID,
			$email
		);
	}

	function Sync_GL_From_Rooms($booking_id)
	{
		$this->load->model('Guest_List_Room_Model');
		$this->load->model('Guest_List_Model');
		$rooms = $this->Guest_List_Room_Model->Read_Rooms_By_Booking_ID($booking_id);

		// Skip sync if booking has no rooms set up yet — avoid wiping existing GL entries
		if (empty($rooms)) {
			return;
		}

		$target = array('ADULT' => 0, 'CHILD' => 0, 'INFANT' => 0);
		foreach ($rooms as $room) {
			$target['ADULT'] += (int)$room->adult_count;
			$target['CHILD'] += (int)$room->child_count;
			$target['INFANT'] += (int)$room->infant_count;
		}

		foreach ($target as $type => $needed) {
			// Count current active GL entries of this type
			$this->db->where('BookingID', $booking_id);
			$this->db->where('Type', $type);
			$this->db->where('Status', 'Y');
			$this->db->order_by('GuestListID', 'ASC');
			$existing = $this->db->get('guest_list')->result();
			$current_count = count($existing);

			if ($current_count < $needed) {
				// Create missing GL entries. When a booking gains its very first
				// ADULT row (e.g. a draft that had no rooms until now), seed that
				// row from the booking customer so the leader shows pre-filled.
				$seed_first_adult = ($type === 'ADULT' && $current_count === 0);
				for ($i = 0; $i < ($needed - $current_count); $i++) {
					$seed = ($seed_first_adult && $i === 0) ? $this->Leader_Seed_From_Booking($booking_id) : array();
					$this->Guest_List_Model->Create($booking_id, $type, $seed);
				}
			} elseif ($current_count > $needed) {
				// Soft-delete excess GL entries (from the end, preferring blank ones)
				$to_remove = $current_count - $needed;
				// Sort: prefer removing blank entries (no Name) first
				$blank = array();
				$filled = array();
				foreach ($existing as $gl) {
					if (empty($gl->Name)) {
						$blank[] = $gl;
					} else {
						$filled[] = $gl;
					}
				}
				// Remove from blank first (reversed so we remove latest), then filled
				$removable = array_merge(array_reverse($blank), array_reverse($filled));
				for ($i = 0; $i < $to_remove && $i < count($removable); $i++) {
					$this->Guest_List_Model->Delete($removable[$i]->GuestListID);
				}
			}
		}

		$this->Guest_List_Model->Auto_Assign_Rooms($booking_id);

		// Guest rows were just added/removed — refresh is_submitted so the
		// booking list GL icon stays in sync with actual guest completeness.
		$is_submitted = $this->Guest_List_Model->Are_All_Guests_Complete($booking_id) ? 1 : 0;
		$this->db->where('BookingID', $booking_id);
		$this->db->update('booking', array('is_submitted' => $is_submitted));
	}

}
