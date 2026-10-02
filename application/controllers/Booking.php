<?php

require FCPATH.'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;  
use PhpOffice\PhpSpreadsheet\Writer\Xlxs;

class Booking extends MY_Controller
{
	/** Whether a clean JSON body has already been emitted for the current AJAX endpoint. */
	private $json_response_sent = false;
	/** Output-buffer nesting level captured before an AJAX endpoint started buffering. */
	private $json_ob_level = 0;

	function __construct()
	{
		parent::__construct();
		$this->load->model('Booking_Model');
		$this->load->model('Booking_Product_Model');
		$this->load->model('Payment_Model');
		$this->load->model('Universal_Model');
		$this->load->model('Customer_Model');
		$this->load->model('Remark_Model');
		$this->load->model('Notification_Model');
		$this->load->model('Booking_Checklist_Completion_Model');
		$this->load->model('Product_Package_Checklist_Model');
		$this->load->model('Package_Checklist_Model');
		$this->load->model('Guest_list_lock_model');
		$this->load->model('Cancellation_Reason_Model');
		$this->load->model('Customer_Type_Model');
		$this->load->model('Quick_Filter_Model');
		$this->load->model('Booking_Supplier_Invoice_Model');
		$this->config->load('autocount'); // load config/autocount.php
	}

	function index()
	{
		$startTime = date("Y-m-d H:i:s.u");
		if(in_array('VB', $this->session->access_control)) {
			$titles = array('tab_title' => 'HolidayGoGoGo | Booking', 'breadcrumb_title' => 'Booking');
			$array = array();

			// Load filter dropdowns data
			$array['admins'] = $this->Booking_Model->Read_Admins();
			$array['notify_admins'] = $this->Notification_Model->Build_Admin_Handles($this->Booking_Model->Read_Notify_Admins());
			$array['booking_op_admins'] = $this->Booking_Model->Read_Booking_OP_Admins();
			$array['categories'] = $this->Booking_Model->Read_Categories();
			$array['tags'] = $this->Booking_Model->Read_Tags();
			$array['sources'] = $this->Booking_Model->Read_Sources();
			$array['customer_types'] = $this->Customer_Type_Model->Read_Customer_Types();
			$array['filter_checklists'] = $this->Package_Checklist_Model->Read_Booking_Filter_Checklists();
			$array['cancellation_reasons'] = $this->Cancellation_Reason_Model->Read_Cancellation_Reasons();
			$array['quick_filters'] = $this->Quick_Filter_Model->Read_Quick_Filters();

			// Hydrate filter values from query string for the shared filter partial
			$filter_field_names = [
				'customer', 'booking_number', 'reservation_number', 'mobile',
				'destination', 'travel_date', 'deadline', 'source',
				'chat_language', 'booking_date', 'status', 'booking_confirmation_title',
				'tag', 'customer_type', 'sales_agent', 'sales_agent_2', 'booking_op',
				'autocount_status', 'guest_list_status', 'checklist_filter',
				'cancellation_reason', 'einvoice_status',
			];
			$filter_values = [];
			foreach($filter_field_names as $fname) {
				$filter_values[$fname] = $this->input->get($fname);
			}
			$array['filter_values'] = $filter_values;

			$this->load->helper('autocount');
			$config = get_autocount_config();

			$array['bulkBookingSyncToAutocount'] = !empty($config['bulkBookingSyncToAutocount']) ? $config['bulkBookingSyncToAutocount'] : false;

			// Load status log helper
			$this->load->helper('booking_status_log');
			
			// Load utils helper for date formatting
			$this->load->helper('utils');
			
			// Update status for all bookings (this runs before the AJAX calls).
			// Same booking set as before; the old N+1 was one payment query per booking
			// on every page load — now the payment lookup is skipped unless the row's
			// status could actually be reset to PBC (see below), which is the only
			// thing that read used it for.
			$bookings = $this->Booking_Model->Read_All_Bookings();
			// Statuses already in a recognised flow are never reset to PBC, so the
			// payment lookup is skipped for them entirely.
			$pbc_reset_excluded = ['PBC', 'PB', 'P', 'PBO', 'PTV', 'PT', 'Y', 'OG', 'SAD'];
			foreach($bookings as $booking) {
				if((date('Y-m-d') >= $booking->StartDate && date('Y-m-d') <= $booking->EndDate) && ($booking->Status == 'PT')) {
					$this->Booking_Model->Update_After_Sales_Service2($booking->BookingID);
					$this->Booking_Model->Update_Status('OG', $booking->BookingID);
					$this->Booking_Model->Create_Booking_Log2($booking->Status, 'OG', $booking->BookingID);
					log_booking_automatic_status_change($booking->BookingID, $booking->Status, 'OG', "Travel date reached - booking is now on-going", true);
				} else {
					if((date('Y-m-d') < $booking->StartDate && ($booking->Status == 'OG'))) {
						$this->Booking_Model->Update_After_Sales_Service2($booking->BookingID);
						$this->Booking_Model->Update_Status('PT', $booking->BookingID);
						$this->Booking_Model->Create_Booking_Log2($booking->Status, 'PT', $booking->BookingID);
						log_booking_automatic_status_change($booking->BookingID, $booking->Status, 'PT', "Travel date not yet reached - status reverted to pending travel", true);
					} else {
						if((date('Y-m-d') > $booking->EndDate && ($booking->Status == 'PT' || $booking->Status == 'OG'))) {
							$this->Booking_Model->Update_After_Sales_Service2($booking->BookingID);
							$this->Booking_Model->Update_Status('Y', $booking->BookingID);
							$this->Booking_Model->Create_Booking_Log2($booking->Status, 'Y', $booking->BookingID);
							log_booking_automatic_status_change($booking->BookingID, $booking->Status, 'Y', "Travel completed - booking marked as completed", true);
						}
					}
				}

				// Payment-based status transitions (respecting new flow)
				// Note: Payment approval status history is now handled in Payment controller
				// when payment status is changed to 'Y' (approved)
				//
				// Only statuses outside the recognised-flow list can be reset to PBC. SAD
				// (Save as Draft) is the draft anchor and PB (Pending BC) is an early flow
				// status — neither must be silently flipped to PBC by this sweep. Reading
				// payments only when a reset is actually possible avoids a query per row.
				if(!in_array($booking->Status, $pbc_reset_excluded)) {
					$payments = $this->Booking_Model->Read_Payments($booking->BookingID);
					if(empty($payments)) {
						// No payments at all, set to PBC
						$this->Booking_Model->Update_Status('PBC', $booking->BookingID);
						$this->Booking_Model->Create_Booking_Log2($booking->Status, 'PBC', $booking->BookingID);
					}
				}
			}

			// Bookings data is now loaded via AJAX (ajax_list method)
			// Summary totals are loaded via AJAX (ajax_summary method)

			$this->load->view('layout/header', $titles);
			$this->load->view('booking/index', $array);
			$this->load->view('layout/footer');

		} else {
			redirect('Dashboard');
		}
	}

	/**
	 * AJAX endpoint for DataTables server-side processing
	 * Returns paginated booking data as JSON
	 */
	/**
	 * Harden a JSON/AJAX endpoint against stray PHP output corrupting the body.
	 *
	 * The booking listing is rendered by DataTables in server-side mode, so the
	 * endpoint MUST return pure JSON. Three things otherwise leak into the body and
	 * produce DataTables' "Invalid JSON response" warning:
	 *   1. PHP warnings/notices printed inline when display_errors is on (which it is
	 *      whenever CI_ENV is unset and ENVIRONMENT falls back to 'development').
	 *   2. Fatal \Error types (TypeError, etc.) that bypass catch (Exception).
	 *   3. True fatals — max_execution_time exceeded, memory exhausted — that abort
	 *      mid-stream with no catch at all.
	 *
	 * This silences display_errors for the request, buffers output so stray text can
	 * be discarded before the real body, and registers a shutdown handler that emits
	 * a valid JSON envelope if a fatal kills the request before send_json() runs.
	 *
	 * @param int $draw DataTables draw counter echoed back in the fatal fallback (0 if N/A).
	 */
	private function begin_json_endpoint($draw = 0)
	{
		@ini_set('display_errors', '0');
		if (!headers_sent()) {
			header('Content-Type: application/json');
		}

		$this->json_response_sent = false;
		$this->json_ob_level = ob_get_level();
		ob_start();

		register_shutdown_function(function () use ($draw) {
			if ($this->json_response_sent) {
				return;
			}
			$error = error_get_last();
			$fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
			if ($error === null || !($error['type'] & $fatal_types)) {
				return;
			}

			// Discard any partial output or fatal-error text the engine appended.
			while (ob_get_level() > $this->json_ob_level) {
				ob_end_clean();
			}
			log_message('error', 'Booking JSON endpoint fatal: ' . $error['message']
				. ' in ' . $error['file'] . ':' . $error['line']);
			if (!headers_sent()) {
				header('Content-Type: application/json');
			}
			echo json_encode(array(
				'error'           => 'An error occurred while loading bookings',
				'draw'            => (int) $draw,
				'recordsTotal'    => 0,
				'recordsFiltered' => 0,
				'data'            => array(),
			));
		});
	}

	/**
	 * Emit the final JSON body for an endpoint prepared by begin_json_endpoint().
	 * Drops anything that leaked into the output buffer (warnings, notices, dumps)
	 * so the body is always clean JSON, then marks the response sent so the shutdown
	 * handler stands down.
	 */
	private function send_json($data)
	{
		while (ob_get_level() > $this->json_ob_level) {
			ob_end_clean();
		}
		if (!headers_sent()) {
			header('Content-Type: application/json');
		}
		$this->json_response_sent = true;
		echo json_encode($data);
	}

	/**
	 * Release the PHP session file lock for a read-only AJAX endpoint.
	 *
	 * The booking listing fires three requests at once — ajax_list, ajax_summary
	 * and ajax_summary_cards. CI's 'files' session driver holds an exclusive lock
	 * on the session file for the whole request, so these otherwise-independent
	 * requests SERIALISE: the slow summary-cards request (~9s) blocks the fast
	 * table request behind it, and the user waits ~9s to see any rows.
	 *
	 * None of these endpoints WRITE to the session, so we close (and unlock) it as
	 * soon as the needed values have been read. $_SESSION stays populated in memory,
	 * so later $this->session->userdata() reads keep working — only writes (which
	 * don't happen here) would be lost. This lets the three requests run in parallel.
	 */
	private function release_session_lock()
	{
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}
	}

	function ajax_list()
	{
		$this->begin_json_endpoint(intval($this->input->get('draw')));

		try {
			// Ensure access_control is an array to prevent warnings
			$access_control = $this->session->access_control ?? array();
			if(!in_array('VB', $access_control)) {
				$this->send_json(array('error' => 'Access denied'));
				return;
			}

			$is_sales_agent = $this->session->userdata('level') == 20;
			// Net Profit / Margin columns are hidden from Sales Agents (20) and
			// Marketing (60). Kept separate from $is_sales_agent, which also drives
			// sales-agent-only row behaviour that must NOT apply to Marketing.
			$hide_profit = admin_hides_profit($this->session->userdata('level'));

			// Read-only endpoint: drop the session lock so it doesn't queue behind
			// the slow summary-cards request (see release_session_lock()).
			$this->release_session_lock();

		$this->load->helper('booking_flow');

		// DataTables parameters
		$draw = intval($this->input->get('draw'));
		$start = intval($this->input->get('start'));
		$length = intval($this->input->get('length'));

		// Order parameters
		$order_column_index = $this->input->get('order[0][column]');
		$order_dir = $this->input->get('order[0][dir]') == 'asc' ? 'ASC' : 'DESC';

		// Map column index to database column
		// Note: Column indices must match the frontend DataTables columns array.
		// Sales Agents (20) and Marketing (60) do NOT see the Net Profit / Net Profit
		// Margin columns, so the indices for BC Status onwards shift left by 2 for them.
		if($hide_profit) {
			$columns = array(
				0 => 'booking.BookingID',             // row number
				1 => 'booking.BookingID',             // checkbox (placeholder)
				2 => 'admin.Name',                    // sales agent
				3 => 'sa2_admin.Name',                // sales agent 2
				4 => 'op_admin.Name',                 // OP
				5 => 'booking.InsertDate',            // creation date
				6 => 'BookingNumber',                 // BC number
				7 => 'booking.BookingConfirmationTitle', // BC title
				8 => 'Customer',                      // customer
				9 => 'source.Name',                   // source
				10 => 'booking.ChatLanguage',          // chat
				11 => 'booking.Mobile',               // mobile
				12 => 'StartDate',                    // start
				13 => 'EndDate',                      // end
				14 => 'category.Name',                // destination
				15 => 'NetTotal',                     // net sales
				16 => "status_sort_priority",          // BC status
				17 => 'LockStatus',                   // GL status
				18 => 'booking.AutocountSyncStatus',  // autocount status
				19 => 'booking.BookingID'             // action
			);
		} else {
			$columns = array(
				0 => 'booking.BookingID',             // row number
				1 => 'booking.BookingID',             // checkbox (placeholder)
				2 => 'admin.Name',                    // sales agent
				3 => 'sa2_admin.Name',                // sales agent 2
				4 => 'op_admin.Name',                 // OP
				5 => 'booking.InsertDate',            // creation date
				6 => 'BookingNumber',                 // BC number
				7 => 'booking.BookingConfirmationTitle', // BC title
				8 => 'Customer',                      // customer
				9 => 'source.Name',                   // source
				10 => 'booking.ChatLanguage',          // chat
				11 => 'booking.Mobile',               // mobile
				12 => 'StartDate',                    // start
				13 => 'EndDate',                      // end
				14 => 'category.Name',                // destination
				15 => 'NetTotal',                     // net sales
				16 => 'net_profit_sort',              // profit
				17 => 'profit_margin_sort',           // profit margin
				18 => "status_sort_priority",          // BC status
				19 => 'LockStatus',                   // GL status
				20 => 'booking.AutocountSyncStatus',  // autocount status
				21 => 'booking.BookingID'             // action
			);
		}

		$order_column = isset($columns[$order_column_index]) ? $columns[$order_column_index] : 'booking.BookingID';

		// Get counts
		$records_total = $this->Booking_Model->Count_Bookings_Total();
		$records_filtered = $this->Booking_Model->Count_Bookings_Filtered();

		// Get paginated data
		$bookings = $this->Booking_Model->Read_Bookings_Paginated($start, $length, $order_column, $order_dir);

		// Process bookings for display
		$data = array();
		$count = $start + 1;
		$current_url = base_url($_SERVER['REQUEST_URI']);

		// Mobile numbers still render as an E.164 wa.me link below (national trunk "0"
		// dropped), so the guest_contact helper is needed for that formatting.
		$this->load->helper('guest_contact');

		// NOTE: the per-page "message log" icon lookup (Ghl_Messages_Model::
		// Phones_With_Messages) was removed from the listing. It scanned the 400k-row
		// ghl_messages table on every page load and, because that table's indexes are
		// far larger than the MySQL buffer pool, cost ~1.5s per request. The chat log
		// is still reachable from the customer/booking pages; it just no longer gates
		// an icon in this listing.

		// Prefetch payments and guest-list locks for the whole page in one query each,
		// instead of one query per row (N+1). Keyed by BookingID / Token for O(1) lookup
		// inside the row loop below.
		$page_booking_ids = array();
		$page_lock_hashes = array();
		foreach($bookings as $b) {
			$page_booking_ids[] = $b->BookingID;
			if(!empty($b->Token)) {
				$page_lock_hashes[] = $b->Token;
			}
		}
		$payments_by_booking = $this->Booking_Model->Read_Payments_For_Bookings($page_booking_ids);
		$locks_by_hash = $this->Guest_list_lock_model->getByHashes($page_lock_hashes);

		foreach($bookings as $booking) {
			// SA-as-TC2 gating: when current SA is only the TC2 (SalesAgent2) of this
			// booking, hide BC link, GL actions, and Customer actions in the row.
			$sa_as_tc2 = is_sa_acting_as_tc2(
				$this->session->userdata('level'),
				$this->session->userdata('admin_id'),
				$booking->SalesAgent2,
				$booking->SalesAgentID
			);

			// Format mobile as E.164 (drops the national trunk "0") so the
			// wa.me link and message-log match agree with GHL's stored number.
			$wa_digits = guest_contact_wa_digits((string) $booking->CountryCode, (string) $booking->CustomerMobile);
			$booking->CustomerMobile = $wa_digits !== '' ? '+' . $wa_digits : '';

			// Format dates
			$start_date_formatted = !empty($booking->StartDate) ? strtoupper(date('j M Y', strtotime($booking->StartDate))) : null;
			$end_date_formatted = !empty($booking->EndDate) ? strtoupper(date('j M Y', strtotime($booking->EndDate))) : null;
			$insert_date_formatted = strtoupper(date('j M Y', strtotime($booking->InsertDate)));

			// Format BC title
			if($booking->BookingConfirmationTitle == 'BOOKING CONFIRMATION') {
				$bc_title = 'BC';
			} else if($booking->BookingConfirmationTitle == 'QUOTATION') {
				$bc_title = 'QU';
			} else {
				$bc_title = 'PI';
			}

			// Calculate profit (payments prefetched above, keyed by BookingID)
			$payments = isset($payments_by_booking[$booking->BookingID]) ? $payments_by_booking[$booking->BookingID] : array();
			$total_credit = 0;
			$total_debit = 0;
			$net_profit = 0;
			$profit_margin = 0;
			$total_credit_approved = 0;
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
				// Settled credit (excludes supplier commission except on a
				// commission-fee booking with no customer payment of its own).
				$total_credit_approved = payment_approved_customer_credit($payments);
				$net_profit = $total_credit - $total_debit;
				if($net_profit != 0 && $booking->NetTotal != 0) {
					$profit_margin = round(($net_profit / $booking->NetTotal) * 100);
				}
			}

			// Determine display status via shared helper (honours paid/deposit guards)
			$deposit_mode_row = !empty($booking->DepositMode) ? $booking->DepositMode : 'percentage';
			if($deposit_mode_row == 'fixed') {
				$deposit_required_row = isset($booking->DepositFixedAmount) ? floatval($booking->DepositFixedAmount) : 0;
			} else {
				$deposit_pct_row = isset($booking->DepositPercentage) ? floatval($booking->DepositPercentage) : 0;
				$deposit_required_row = ceil((floatval($booking->NetTotal) * $deposit_pct_row) / 100);
			}
			$booking->balance_due = floatval($booking->NetTotal) - $total_credit_approved;
			$this->load->helper('booking_flow');
			$has_deposit_deadline_row = !empty($booking->DepositDeadline);
			$booking->deposit_complete = compute_deposit_complete($deposit_required_row, $total_credit_approved, $has_deposit_deadline_row);
			$status_info_row = display_booking_status($booking);
			$display_status = $status_info_row['status_code'];
			$status_color = $status_info_row['status_color'];
			$status_text = $status_info_row['status_text'];

			// Profit color
			$profit_color = $net_profit < 0 ? '#FF2400' : ($net_profit == 0 ? '#F4BB44' : '#00A36C');

			// Autocount status
			$autocount_status_map = array('P' => array('text' => 'P', 'color' => '#FFBF00'), 'S' => array('text' => 'S', 'color' => '#50C878'), 'F' => array('text' => 'F', 'color' => '#FF2400'));
			$autocount_info = isset($autocount_status_map[$booking->AutocountSyncStatus]) ? $autocount_status_map[$booking->AutocountSyncStatus] : array('text' => '-', 'color' => '#000');

			// Build tooltip for autocount
			$tooltip_attr = '';
			if(!empty($booking->AutocountSyncMessage)) {
				$decoded = json_decode($booking->AutocountSyncMessage, true);
				if(json_last_error() === JSON_ERROR_NONE) {
					if(isset($decoded['error']) && $decoded['error'] == null) {
						$tooltip_text = 'SUCCESS';
					} elseif(isset($decoded['error']) && $decoded['error'] !== null) {
						$tooltip_text = 'ERROR: ' . (is_string($decoded['error']) ? $decoded['error'] : json_encode($decoded['error']));
					} else {
						$tooltip_text = $booking->AutocountSyncMessage;
					}
				} else {
					$tooltip_text = $booking->AutocountSyncMessage;
				}
				$tooltip_attr = ' data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars($tooltip_text) . '"';
			}

			// Build row data
			$row = array();

			// Checkbox - show for Failed and Pending autocount status
			if (in_array($booking->AutocountSyncStatus, ['F', 'P'])) {
				$row['checkbox'] = '<input type="checkbox" class="check_item" value="' . $booking->BookingID . '">';
			} else {
				$row['checkbox'] = ''; // Empty for Synced status
			}

			// Row number
			$row['row_number'] = $count;

			// Raw booking id (not a column) — lets the client collect the ids on the
			// current page to scope the agent "chase" summary cards to visible rows.
			$row['booking_id'] = (int) $booking->BookingID;

			// Sales agent, Sales agent 2 and OP
			$row['sales_agent'] = $booking->SalesAgentName;
			$row['sales_agent_2'] = $booking->SalesAgent2Name;
			$row['booking_op'] = $booking->BookingOPName;

			// Insert date
			$row['insert_date'] = $insert_date_formatted;

			// BC Number with link (plain text when SA is only the TC2 of this booking)
			if($sa_as_tc2) {
				$row['booking_number'] = $booking->BookingNumber;
			} else {
				$row['booking_number'] = '<a href="' . base_url('Payment?booking_number=') . $booking->BookingNumber . '&customer=' . str_replace('&', '%26', $booking->Customer) . '" target="_blank">' . $booking->BookingNumber . '</a>';
			}

			// BC Title
			$row['bc_title'] = $bc_title;

			// Customer
			$row['customer'] = $booking->Customer;
			$row['has_einvoice'] = $booking->has_einvoice > 0;

			// Source
			$row['source'] = $booking->SourceName ?? '-';

			// Source
			$row['source'] = $booking->SourceName ?? '-';

			// Customer Code
			$row['customer_code'] = $booking->CustomerCode;

			// Chat Language
			$row['chat_language'] = $booking->ChatLanguage;

			// Mobile (WhatsApp link + message-log icon).
			// The "message log" icon is rendered HIDDEN for every row (no per-row DB
			// lookup here — that scan of the 400k-row ghl_messages table was the old
			// ~1.5s cost). After the table draws, the page asks ajax_message_log_icons
			// which of the visible phones actually have a stored chat and reveals just
			// those icons (see loadMessageLogIcons() in the view). Same button, same
			// modal — only the "does a log exist?" check moved off the critical path.
			$row['mobile'] = '<a href="https://wa.me/' . $booking->CustomerMobile . '" target="_blank" title="Open WhatsApp chat" class="btn btn-light-success d-inline-flex align-items-center btn-sm"><i class="la la-whatsapp"></i></a>';
			$mobile_digits = preg_replace('/\D+/', '', (string) $booking->CustomerMobile);
			if($mobile_digits !== '') {
				// Hidden via the msg-log-hidden class (display:none !important, defined in
				// the view) which beats Bootstrap's d-inline-flex !important. The view
				// removes that class for phones that actually have a chat.
				$row['mobile'] .= ' <a href="javascript:;" title="View message log" class="btn btn-light-primary d-inline-flex align-items-center btn-sm js-msg-log js-msg-log-lazy msg-log-hidden" data-phone="' . htmlspecialchars($mobile_digits, ENT_QUOTES) . '" data-name="' . htmlspecialchars($booking->Customer, ENT_QUOTES) . '"><i class="la la-comments"></i></a>';
			}

			// Start Date
			$row['start_date'] = $start_date_formatted;

			// End Date
			$row['end_date'] = $end_date_formatted;

			// Destination
			$row['destination'] = $booking->DestinationName;

			// Net Total
			$row['net_total'] = number_format($booking->NetTotal, 2, '.', ',');

			// Profit (hidden from Sales Agents and Marketing)
			if(!$hide_profit) {
				$row['profit'] = '<span style="color:' . $profit_color . '">' . number_format($net_profit, 2, '.', ',') . '</span>';
				$row['profit_margin'] = '<span style="color:' . $profit_color . '">' . $profit_margin . '</span>';
			}

			// Status
			if($booking->CancelStatus == 'Y' && !empty($booking->CancellationReasonName)) {
				$cancel_tooltip = 'Reason: ' . $booking->CancellationReasonName;
				if(!empty($booking->CancellationRemark)) {
					$cancel_tooltip .= ' - ' . $booking->CancellationRemark;
				}
				$row['status'] = '<span class="font-weight-bold" style="color:' . $status_color . ';" data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars($cancel_tooltip) . '">' . $status_text . '</span>';
			} else {
				$row['status'] = '<span class="font-weight-bold" style="color:' . $status_color . ';">' . $status_text . '</span>';
			}

			// Draft -> Payment response time is shown inside the booking edit
			// page ("Draft -> Payment response time" alert), so it is intentionally
			// not duplicated here in the BC status cell.

			// GL Status rules:
			// 1. If hard-locked (LockStatus = 'Y') -> locked icon (red)
			// 2. Else if active soft-lock exists -> loading spinner (blue)
			// 3. Else if guest list has submitted data -> submitted icon (orange)
			// 4. Else -> default unlocked icon (green)
			$gl_status_icon = '';
			
			// 1) Hard lock
			if ($booking->LockStatus == 'Y') {
				$gl_status_icon = '<i class="la la-lock text-danger" data-toggle="tooltip" data-placement="top" title="Guest list is locked"></i>';
			} else {
				$has_active_editor_lock = false;

				// 2) Active soft lock (someone is filling) — from prefetched map
				if (!empty($booking->Token)) {
					$lock = isset($locks_by_hash[$booking->Token]) ? $locks_by_hash[$booking->Token] : null;
					if (!empty($lock)) {
						$is_expired = $this->Guest_list_lock_model->isExpired($lock);
						if (!$is_expired) {
							$has_active_editor_lock = true;
							$gl_status_icon = '<i class="la la-spinner la-spin text-primary" data-toggle="tooltip" data-placement="top" title="Guest list is being edited"></i>';
						}
					}
				}

				if (!$has_active_editor_lock) {
					// 3) Submitted
					if (!empty($booking->is_submitted) && intval($booking->is_submitted) === 1) {
						$gl_status_icon = '<i class="la la-check-circle text-warning" data-toggle="tooltip" data-placement="top" title="Guest list submitted"></i>';
					} else {
						// 4) Default unlocked
						$gl_status_icon = '<i class="la la-unlock text-success" data-toggle="tooltip" data-placement="top" title="Guest list is unlocked"></i>';
					}
				}
			}
			$row['gl_status'] = $gl_status_icon;

			// Autocount Status
			$row['autocount_status'] = '<span class="font-weight-bold" style="color:' . $autocount_info['color'] . '"' . $tooltip_attr . '>' . $autocount_info['text'] . '</span>';

			// Action dropdown - simplified for AJAX response
			$row['action'] = $this->build_action_dropdown($booking, $current_url, $is_sales_agent, $sa_as_tc2);

			$data[] = $row;
			$count++;
		}

			$output = array(
				'draw' => $draw,
				'recordsTotal' => $records_total,
				'recordsFiltered' => $records_filtered,
				'data' => $data
			);

			// Discard any stray buffered output, then emit clean JSON.
			$this->send_json($output);
			exit; // Prevent any additional output
		} catch (\Throwable $e) {
			// \Throwable (not just \Exception) so fatal \Error types are caught too.
			log_message('error', 'Booking ajax_list error: ' . $e->getMessage());
			$this->send_json(array(
				'error' => 'An error occurred while loading bookings',
				'draw' => intval($this->input->get('draw') ?? 0),
				'recordsTotal' => 0,
				'recordsFiltered' => 0,
				'data' => array()
			));
			exit;
		}
	}

	/**
	 * Build action dropdown HTML for a booking row
	 */
	private function build_action_dropdown($booking, $current_url, $is_sales_agent, $sa_as_tc2 = false)
	{
		$shown_approve_bc = false;
		$shown_update_booking = false;
		$html = '<div class="btn-group">';
		$html .= '<button type="button" data-toggle="dropdown" class="btn btn-light-primary btn-sm dropdown-toggle" style="padding-left:3px;"></button>';
		$html .= '<div class="dropdown-menu">';

		if(!$sa_as_tc2 && ($booking->Status != 'Y' || (!$is_sales_agent && $booking->Status == 'Y'))) {
			$access_control = $this->session->access_control ?? array();
			if(in_array('RB', $access_control)) {
				$html .= '<button onclick="Delete_Record(\'' . base_url('assets/image/sweetalert.jpg') . '\', \'Booking Record : ' . $booking->BookingNumber . '\', \'' . base_url('Booking/Delete') . '\', \'booking_id\', ' . $booking->BookingID . ', \'' . $booking->Status . '\', \'' . (strpos($current_url, '?') ? base_url('Booking?') . explode('?', $current_url)[1] : base_url('Booking')) . '\')" class="dropdown-item" style="color:#E37383; font-size:11px;">Delete Booking</button>';
			}
			if(in_array('AB', $access_control)) {
				if($booking->CancelStatus == 'Y') {
					$html .= '<a href="' . base_url('Booking/Update_Cancel_Status?booking_id=') . $booking->BookingID . '&current_cancel_status=' . $booking->CancelStatus . '&new_cancel_status=N&param=' . urlencode($current_url) . '" class="dropdown-item" style="color:#93C572; font-size:11px;">Activate Booking</a>';
				} else {
					$html .= '<button onclick="Cancel_Booking(\'' . base_url('assets/image/sweetalert.jpg') . '\', \'' . $booking->BookingNumber . '\', ' . $booking->BookingID . ', \'' . urlencode($current_url) . '\')" class="dropdown-item" style="color:#E0115F; font-size:11px;">Cancel Booking</button>';
					if(!empty($booking->PartialRefund) && $booking->PartialRefund == 'Y') {
						$html .= '<a href="' . base_url('Booking/Update_Partial_Refund_Status?booking_id=') . $booking->BookingID . '&new_partial_refund_status=N&param=' . urlencode($current_url) . '" class="dropdown-item" style="color:#93C572; font-size:11px;">Undo Partial Refund</a>';
					} else {
						$html .= '<button onclick="Cancel_With_Partial_Refund(\'' . base_url('assets/image/sweetalert.jpg') . '\', \'' . $booking->BookingNumber . '\', ' . $booking->BookingID . ', \'' . urlencode($current_url) . '\')" class="dropdown-item" style="color:#E0115F; font-size:11px;">Cancel With Partial Refund</button>';
					}
				}
				// Approve BC - only show when status is Pending BC Confirmation and BC is not approved
				if($booking->Status == 'PBC' && (empty($booking->bc_approved) || $booking->bc_approved == 0)) {
					$html .= '<a href="' . base_url('Booking/Approve_BC?booking_id=') . $booking->BookingID . '&param=' . urlencode($current_url) . '" class="dropdown-item" style="color:#50C878; font-size:11px;">Approve BC</a>';
					$shown_approve_bc = true;
				}
				// PGL with LockStatus=Y is displayed as PTV (see display_booking_status); treat it the same here.
				$effective_status = ($booking->Status == 'PGL' && $booking->LockStatus == 'Y') ? 'PTV' : $booking->Status;
				if($effective_status == 'PBO' || $effective_status == 'PTV' || $effective_status == 'PT') {
					if($effective_status == 'PBO' || $effective_status == 'PTV') {
						$html .= '<a href="' . base_url('Booking/Update_Status?booking_id=') . $booking->BookingID . '&current_status=' . $booking->Status . '&new_status=PT&param=' . urlencode($current_url) . '" class="dropdown-item" style="color:#6082B6; font-size:11px;">Approve Travel Voucher ?</a>';
					} else {
						$html .= '<a href="' . base_url('Booking/Update_Status?booking_id=') . $booking->BookingID . '&current_status=' . $booking->Status . '&new_status=PTV&param=' . urlencode($current_url) . '" class="dropdown-item" style="color:#F4BB44; font-size:11px;">Revert Pending Travel Voucher</a>';
					}
				}
				// Generic Revert Status button
				if($booking->CancelStatus != 'Y') {
					$prev_status = get_previous_status_in_flow($booking->Status);
					if($prev_status !== null) {
						$status_info = get_booking_status_info();
						$display_status = display_booking_status($booking);
						$from_text = $display_status['status_text'];
						$to_text = isset($status_info['texts'][$prev_status]) ? $status_info['texts'][$prev_status] : $prev_status;
						$revert_url = base_url('Booking/Update_Status?booking_id=') . $booking->BookingID . '&current_status=' . $booking->Status . '&new_status=' . $prev_status . '&param=' . urlencode($current_url);
						$html .= '<button onclick="Revert_Booking_Status(\'' . base_url('assets/image/sweetalert.jpg') . '\', \'' . $booking->BookingNumber . '\', \'' . $revert_url . '\', \'' . $from_text . '\', \'' . $to_text . '\')" class="dropdown-item" style="color:#F4BB44; font-size:11px;">Revert Status</button>';
					}
				}
				if(!$sa_as_tc2) {
					$html .= '<a href="' . (strpos($current_url, '?') ? base_url('Booking/Update?booking_id=') . $booking->BookingID . '&' . explode('?', $current_url)[1] : base_url('Booking/Update?booking_id=') . $booking->BookingID) . '" class="dropdown-item" style="font-size:11px;">Update Booking</a>';
					$shown_update_booking = true;
				}
			}
		}

		if(!$sa_as_tc2) {
			// View Booking - Allow Sales Agents without AB access to view their own bookings
			if($is_sales_agent && !$shown_update_booking && !empty($booking->SalesAgentID) && $booking->SalesAgentID == $this->session->userdata('admin_id')) {
				if(!empty($booking->SalesAgentID) && $booking->SalesAgentID == $this->session->userdata('admin_id')) {
					$html .= '<a href="' . (strpos($current_url, '?') ? base_url('Booking/View?booking_id=') . $booking->BookingID . '&' . explode('?', $current_url)[1] : base_url('Booking/View?booking_id=') . $booking->BookingID) . '" class="dropdown-item" style="font-size:11px;">View Booking</a>';
				}
			}
			// Approve BC - Allow SA users to approve their own bookings (only when status is Pending BC Confirmation)
			if($is_sales_agent && $booking->Status == 'PBC' && (empty($booking->bc_approved) || $booking->bc_approved == 0) && !empty($booking->SalesAgentID) && $booking->SalesAgentID == $this->session->userdata('admin_id') && !$shown_approve_bc) {
				$html .= '<a href="' . base_url('Booking/Approve_BC?booking_id=') . $booking->BookingID . '&param=' . urlencode($current_url) . '" class="dropdown-item" style="color:#50C878; font-size:11px;">Approve BC</a>';
			}
			// Complete Booking / Revert Pending Review - Allow SA users to complete after-sales service
			$access_control = $this->session->access_control ?? array();
			if(in_array('AB', $access_control)) {
				if($booking->Status == 'Y' || $booking->Status == 'PR') {
					if($booking->AfterSalesService == 'PENDING') {
						$html .= '<a href="' . base_url('Booking/Update_After_Sales_Service?booking_id=') . $booking->BookingID . '&current_after_sales_service=' . $booking->AfterSalesService . '&new_after_sales_service=COMPLETE&param=' . urlencode($current_url) . '" class="dropdown-item" style="color:#50C878; font-size:11px;">Complete Booking</a>';
					} else if(!$is_sales_agent) {
						// Only non-SA can revert to Pending Review
						$html .= '<a href="' . base_url('Booking/Update_After_Sales_Service?booking_id=') . $booking->BookingID . '&current_after_sales_service=' . $booking->AfterSalesService . '&new_after_sales_service=PENDING&param=' . urlencode($current_url) . '" class="dropdown-item" style="color:#702963; font-size:11px;">Revert Pending Review</a>';
					}
				}
			}
			if(in_array('GB', $this->session->access_control)) {
				$html .= '<a href="' . (strpos($current_url, '?') ? base_url('Booking/Duplicate?booking_id=') . $booking->BookingID . '&' . explode('?', $current_url)[1] : base_url('Booking/Duplicate?booking_id=') . $booking->BookingID) . '" class="dropdown-item" style="font-size:11px;">Duplicate Booking</a>';
			}
			// Edit Checklist - AB users, SA viewing own booking, a TEAM LEAD
			// (level 25) or an OP TEAM LEAD (level 45). The modal/save flow
			// further scopes via can_user_modify_booking_checklist() so only a
			// lead sharing a Team with the booking's TC/TC2/OP (or an assignee)
			// can actually tick; non-matching leads see a read-only modal.
			$is_team_lead_user = (int)$this->session->userdata('level') === 25;
			$is_op_team_lead_user = (int)$this->session->userdata('level') === 45;
			if(in_array('AB', $access_control) || ($is_sales_agent && !empty($booking->SalesAgentID) && $booking->SalesAgentID == $this->session->userdata('admin_id')) || $is_team_lead_user || $is_op_team_lead_user) {
				$html .= '<button onclick="openChecklistModal(' . $booking->BookingID . ')" class="dropdown-item" style="font-size:11px;">Edit Checklist</button>';
			}
		}
		$sales_agent_id = isset($booking->SalesAgentID) ? $booking->SalesAgentID : '';
		$booking_op_id = isset($booking->BookingOP) ? $booking->BookingOP : '';
		$html .= '<button onclick="openRemarksModal(' . $booking->BookingID . ', \'' . addslashes($booking->BookingNumber) . '\', \'' . $sales_agent_id . '\', \'' . $booking_op_id . '\')" class="dropdown-item" style="font-size:11px;">View Remarks</button>';
		$html .= '<div class="dropdown-divider"></div>';
		$html .= '<a href="' . base_url('Booking_Confirmation?token=') . $booking->Token . '" target="_blank" class="dropdown-item" style="font-size:11px;">Booking Confirmation</a>';
		$html .= '<button id="bc_url-' . $booking->BookingID . '" value="' . base_url('Booking_Confirmation?token=') . $booking->Token . '" onclick="Copy_URL(\'BC URL\', ' . $booking->BookingID . ')" class="dropdown-item" style="font-size:11px;">Copy BC Link</button>';
		if(!$sa_as_tc2) {
			$html .= '<div class="dropdown-divider"></div>';
			$sa_blocked_from_gl = is_sa_blocked_from_completed_booking($this->session->userdata('level'), $booking->Status, $booking->AfterSalesService);
			if($booking->Status != 'Y' || (!$is_sales_agent && $booking->Status == 'Y')) {
				$html .= '<a href="' . base_url('Guest_List?gl=') . $booking->Token . '" target="_blank" class="dropdown-item" style="font-size:11px;">Guest List</a>';
			}
			if(!$sa_blocked_from_gl) {
				$html .= '<a href="' . base_url('Guest_List/Download?booking_id=') . $booking->BookingID . '" class="dropdown-item" style="font-size:11px;">Download Guest List</a>';
				$html .= '<a href="' . base_url('Guest_List/Download_ZIP?booking_id=') . $booking->BookingID . '" class="dropdown-item" style="font-size:11px;">Download Guestlist ZIP</a>';
				$html .= '<button id="gl_url-' . $booking->BookingID . '" value="' . base_url('Guest_List?gl=') . $booking->Token . '" onclick="Copy_URL(\'GL URL\', ' . $booking->BookingID . ')" class="dropdown-item" style="font-size:11px;">Copy GL Link</button>';
			}
		}
		$html .= '<div class="dropdown-divider"></div>';
		$html .= '<a href="' . base_url('Travel_Voucher?token=') . $booking->Token . '" target="_blank" class="dropdown-item" style="font-size:11px;">Travel Voucher</a>';
		$html .= '<button id="tv_url-' . $booking->BookingID . '" value="' . base_url('Travel_Voucher?token=') . $booking->Token . '" onclick="Copy_URL(\'TV URL\', ' . $booking->BookingID . ')" class="dropdown-item" style="font-size:11px;">Copy TV Link</button>';
		$html .= '<div class="dropdown-divider"></div>';
		$html .= '<button id="customer_name-' . $booking->BookingID . '" value="' . $booking->Customer . '" onclick="Copy_URL(\'CUSTOMER NAME\', ' . $booking->BookingID . ')" class="dropdown-item" style="font-size:11px;">Copy Customer Name</button>';
		$html .= '<button id="customer_mobile-' . $booking->BookingID . '" value="' . $booking->CustomerMobile . '" onclick="Copy_URL(\'CUSTOMER MOBILE\', ' . $booking->BookingID . ')" class="dropdown-item" style="font-size:11px;">Copy Customer Mobile</button>';
		if(!$sa_as_tc2) {
			$html .= '<div class="dropdown-divider"></div>';
			if($booking->CustomerID != null) {
				// Load helper for generating portal hash
				$this->load->helper('utils');
				$customer_hash = generate_customer_portal_slug($booking->CustomerID);
				if (!empty($customer_hash)) {
					$portal_url = base_url('customer/' . urlencode($customer_hash));
					$html .= '<a href="' . $portal_url . '" target="_blank" class="dropdown-item" style="font-size:11px;">Go to Customer Portal</a>';
					$html .= '<button id="portal_url-' . $booking->BookingID . '" value="' . $portal_url . '" onclick="Copy_URL(\'CUSTOMER PORTAL LINK\', ' . $booking->BookingID . ')" class="dropdown-item" style="font-size:11px;">Copy Customer Portal Link</button>';
				}
			}
			else {
				$html .= '<a href="#" class="dropdown-item" style="font-size:11px; cursor:not-allowed; color:#6c757d;" disabled>Customer ID not found</a>';
			}
		}
		if(!$sa_as_tc2 && !empty($booking->Token)) {
			$booking_page_url = base_url('customer/booking/' . $booking->Token);
			$html .= '<a href="' . $booking_page_url . '" target="_blank" class="dropdown-item" style="font-size:11px;">Go to Booking Page</a>';
			$html .= '<button id="booking_page_url-' . $booking->BookingID . '" value="' . $booking_page_url . '" onclick="Copy_URL(\'BOOKING PAGE LINK\', ' . $booking->BookingID . ')" class="dropdown-item" style="font-size:11px;">Copy Booking Page Link</button>';
		}
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * AJAX endpoint for summary totals
	 * Returns total sales and net profit for all filtered bookings
	 */
	function ajax_summary()
	{
		$this->begin_json_endpoint();

		try {
			if(!in_array('VB', $this->session->access_control)) {
				$this->send_json(array('error' => 'Access denied'));
				return;
			}

			// Net profit is hidden from Sales Agents (20) and Marketing (60).
			$hide_profit = admin_hides_profit($this->session->userdata('level'));

			// Read-only endpoint: drop the session lock (see release_session_lock()).
			$this->release_session_lock();

			$summary = $this->Booking_Model->Calculate_Summary();

			$total_sales = $summary['total_sales'];

			// Format output
			$total_sales_formatted = number_format($total_sales, 2, '.', ',');

			$output = array(
				'total_sales' => $total_sales_formatted,
				'is_sales_agent' => $hide_profit
			);

			// Net profit is restricted to roles that may see profit
			if(!$hide_profit) {
				$total_net_profit = $summary['total_net_profit'];
				if($total_net_profit != 0 && $total_sales != 0) {
					$profit_percentage = round(($total_net_profit / $total_sales) * 100);
					$total_net_profit_formatted = number_format($total_net_profit, 2, '.', ',') . ' (' . $profit_percentage . '%)';
				} else {
					$total_net_profit_formatted = number_format($total_net_profit, 2, '.', ',') . ' (0%)';
				}
				$output['total_net_profit'] = $total_net_profit_formatted;
			}

			$this->send_json($output);
		} catch (\Throwable $e) {
			log_message('error', 'Booking ajax_summary error: ' . $e->getMessage());
			$this->send_json(array('error' => 'An error occurred while loading summary'));
		}
	}

	/**
	 * AJAX endpoint: given the phone digits visible on the current listing page,
	 * return the subset that have at least one stored WhatsApp message. The view
	 * reveals the (pre-rendered, hidden) "message log" icon for those rows.
	 *
	 * This is the message-log lookup that used to run inside ajax_list per page
	 * load and cost ~1.5s (it scans the 400k-row ghl_messages table, whose indexes
	 * exceed the DB buffer pool). Moving it here keeps it OFF the table's critical
	 * path — the rows render immediately and the icons appear when this returns.
	 */
	function ajax_message_log_icons()
	{
		$this->begin_json_endpoint();

		try {
			if(!in_array('VB', $this->session->access_control ?? array())) {
				$this->send_json(array('phones' => array()));
				return;
			}

			// Read-only + intentionally slow: drop the session lock so it can't block
			// other requests (see release_session_lock()).
			$this->release_session_lock();

			$phones = $this->input->post('phones');
			if(!is_array($phones)) {
				$phones = array();
			}
			$clean = array();
			foreach($phones as $p) {
				$digits = preg_replace('/\D+/', '', (string) $p);
				if($digits !== '') {
					$clean[] = $digits;
				}
			}

			if(empty($clean)) {
				$this->send_json(array('phones' => array()));
				return;
			}

			$this->load->model('Ghl_Messages_Model');
			$have = $this->Ghl_Messages_Model->Phones_With_Messages($clean);
			$this->send_json(array('phones' => $have));
		} catch (\Throwable $e) {
			log_message('error', 'Booking ajax_message_log_icons error: ' . $e->getMessage());
			$this->send_json(array('phones' => array()));
		}
	}

	/**
	 * AJAX endpoint for role-based summary cards rendered above the booking listing.
	 * Returns counts/values/links keyed per card; the view partial fills placeholders.
	 */
	// Builds the three sales-agent "chase" cards:
	//   - upcoming_travel_not_ready_op     (Travel in 7 Days  – Not Yet Ready)
	//   - upcoming_travel_not_ready_op_14  (Travel in 14 Days – Not Yet Ready)
	//   - customer_payment_due_soon        (Payment From Customer Due Soon)
	//
	// Always whole-DB over the agent's own bookings so the card count matches its
	// drill-down link exactly (the drill-down applies the same credited / window /
	// own-slot filters, never a per-page row scope).
	private function _agent_upcoming_cards($admin_id)
	{
		$admin_id = (int) $admin_id;
		$this->load->helper('lead_conversion_credit');
		$credit_clause = lead_conversion_credit_booking_clause();

		$today   = date('Y-m-d');
		$base    = base_url('Booking');
		$fmt_dmy = function($d) { return date('d/m/Y', strtotime($d)); };
		$money   = function($v) { return 'RM ' . number_format((float)$v, 2, '.', ','); };
		$qs      = function($params) { return '?' . http_build_query($params); };

		$next7_start  = date('Y-m-d', strtotime('+1 day'));
		$next7_end    = date('Y-m-d', strtotime('+7 days'));
		// Cumulative 14-day window shares the 7-day start (tomorrow).
		$next14_start = $next7_start;
		$next14_end   = date('Y-m-d', strtotime('+14 days'));

		$cards  = array();
		$tables = array();

		// "Travel in N Days – Not Yet Ready" counts confirmed BCs this agent is
		// *credited* for (TC1 pre-cutoff, TC2 on/after). The ?upcoming_not_ready
		// drill-down only self-applies that credited scope for levels 20/50, so
		// the link carries ?credited_agent=<me> to scope the OTHER agent-card
		// roles too — Owner (10, unscoped listing) and TC Lead (25, team-scoped
		// listing) — otherwise their drill-down leaks every agent's bookings.
		foreach(array(
			array('key' => 'upcoming_travel_not_ready_op',    's' => $next7_start,  'e' => $next7_end),
			array('key' => 'upcoming_travel_not_ready_op_14', 's' => $next14_start, 'e' => $next14_end),
		) as $w) {
			$row = $this->db->query(
				"SELECT
				   SUM(CASE WHEN Status='P'   THEN 1 ELSE 0 END) AS s_p,
				   SUM(CASE WHEN Status='PBO' THEN 1 ELSE 0 END) AS s_pbo,
				   SUM(CASE WHEN Status='PGL' THEN 1 ELSE 0 END) AS s_pgl,
				   SUM(CASE WHEN Status='PTV' THEN 1 ELSE 0 END) AS s_ptv
				 FROM booking
				 WHERE BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND CancelStatus='N'
				   AND Status IN ('P','PBO','PGL','PTV')
				   AND StartDate BETWEEN ? AND ?
				   AND {$credit_clause}",
				array($w['s'], $w['e'], $admin_id, $admin_id)
			)->row();
			$cards[$w['key']] = array(
				'count'  => (int)$row->s_p + (int)$row->s_pbo + (int)$row->s_pgl + (int)$row->s_ptv,
				'by_p'   => (int)$row->s_p,
				'by_pbo' => (int)$row->s_pbo,
				'by_pgl' => (int)$row->s_pgl,
				'by_ptv' => (int)$row->s_ptv,
				'link'   => $base . $qs(array(
					'upcoming_not_ready' => 1,
					'travel_date'        => $fmt_dmy($w['s']) . ' - ' . $fmt_dmy($w['e']),
					'credited_agent'     => $admin_id,
				)),
			);
		}

		// "Payment From Customer Due Soon" — own BCs still owing a scheduled
		// customer payment, bucketed Overdue / Today / Tomorrow. Scoped to the
		// agent's own bookings via the broad SalesAgent/SalesAgent2 slot the TC
		// listing applies by default, so the card matches the ?customer_payment
		// drill-down (which relies on that same default scope — no sales_agent
		// param).
		$cust_due_end   = date('Y-m-d', strtotime('+1 day'));  // tomorrow — window upper bound
		$cust_due_start = date('Y') . '-03-01';                // overdue lookback floor: 1 March, current year
		// 3pm cutoff: a deadline falling on today is only "today" while the
		// money-in can still be approved (before 15:00). At/after 15:00 the
		// same-day deadline has lapsed and moves into "overdue", so overdue
		// widens to nd <= today and the today bucket empties. The empty-bucket
		// sentinel must be a MySQL-valid DATE (strict mode / NO_ZERO_DATE
		// rejects '0000-00-00' with error 1525); '1000-01-01' is the minimum
		// valid date and sits outside the BETWEEN window, so it never matches.
		$cust_after3pm  = ((int) date('H') >= 15);
		$cust_overdue_op = $cust_after3pm ? '<=' : '<';
		$cust_today_val  = $cust_after3pm ? '1000-01-01' : $today;
		$cust_nd  = "(CASE WHEN booking.Status IN ('PBC','P') THEN COALESCE(booking.DepositDeadline, booking.FullPaymentDeadline) ELSE booking.FullPaymentDeadline END)";
		$cust_out = "(booking.NetTotal - " . booking_settled_credit_sql() . ")";
		$tc_own = "(booking.SalesAgent = ? OR booking.SalesAgent2 = ?)";
		$row = $this->db->query(
			"SELECT
			    SUM(CASE WHEN t.nd {$cust_overdue_op} ? THEN 1 ELSE 0 END) AS overdue_cnt,
			    COALESCE(SUM(CASE WHEN t.nd {$cust_overdue_op} ? THEN t.outstanding ELSE 0 END), 0) AS overdue_due,
			    SUM(CASE WHEN t.nd =  ? THEN 1 ELSE 0 END) AS today_cnt,
			    COALESCE(SUM(CASE WHEN t.nd =  ? THEN t.outstanding ELSE 0 END), 0) AS today_due,
			    SUM(CASE WHEN t.nd =  ? THEN 1 ELSE 0 END) AS tomorrow_cnt,
			    COALESCE(SUM(CASE WHEN t.nd =  ? THEN t.outstanding ELSE 0 END), 0) AS tomorrow_due
			 FROM (
			    SELECT {$cust_nd} AS nd, {$cust_out} AS outstanding
			    FROM booking
			    WHERE booking.CancelStatus = 'N'
			      AND booking.Status IN ('PBC','P','PP')
			      AND booking.BookingConfirmationTitle = 'BOOKING CONFIRMATION'
			      AND {$tc_own}
			 ) t
			 WHERE t.nd BETWEEN ? AND ?
			   AND t.outstanding > 0",
			array($today, $today, $cust_today_val, $cust_today_val, $cust_due_end, $cust_due_end, $admin_id, $admin_id, $cust_due_start, $cust_due_end)
		)->row();
		$cards['customer_payment_due_soon'] = array(
			'overdue'  => array('count' => (int)$row->overdue_cnt,  'total_due' => $money($row->overdue_due),  'link' => $base . $qs(array('customer_payment' => 'overdue',  'status' => 'A'))),
			'today'    => array('count' => (int)$row->today_cnt,    'total_due' => $money($row->today_due),    'link' => $base . $qs(array('customer_payment' => 'today',    'status' => 'A'))),
			'tomorrow' => array('count' => (int)$row->tomorrow_cnt, 'total_due' => $money($row->tomorrow_due), 'link' => $base . $qs(array('customer_payment' => 'tomorrow', 'status' => 'A'))),
		);

		$cust_rows = $this->db->query(
			"SELECT t.BookingNumber AS booking_number, t.Customer AS customer,
			        t.nd AS earliest_deadline, t.outstanding AS total_due
			 FROM (
			    SELECT booking.BookingNumber AS BookingNumber, booking.Customer AS Customer,
			           {$cust_nd} AS nd, {$cust_out} AS outstanding
			    FROM booking
			    WHERE booking.CancelStatus = 'N'
			      AND booking.Status IN ('PBC','P','PP')
			      AND booking.BookingConfirmationTitle = 'BOOKING CONFIRMATION'
			      AND {$tc_own}
			 ) t
			 WHERE t.nd BETWEEN ? AND ?
			   AND t.outstanding > 0
			 ORDER BY t.nd ASC, t.outstanding DESC
			 LIMIT 5",
			array($admin_id, $admin_id, $cust_due_start, $cust_due_end)
		)->result();
		$cust_out_rows = array();
		foreach($cust_rows as $r) {
			$cust_out_rows[] = array(
				'booking_number'    => $r->booking_number,
				'customer'          => $r->customer,
				'total_due'         => $money($r->total_due),
				'earliest_deadline' => $r->earliest_deadline ? $fmt_dmy($r->earliest_deadline) : '-',
			);
		}
		$tables['customer_payment_due_soon'] = $cust_out_rows;

		return array('cards' => $cards, 'tables' => $tables);
	}

	function ajax_summary_cards()
	{
		$this->begin_json_endpoint();

		try {
		if(!in_array('VB', $this->session->access_control)) {
			$this->send_json(array('error' => 'Access denied'));
			return;
		}

		$level    = (int) $this->session->userdata('level');
		$admin_id = (int) $this->session->userdata('admin_id');

		// Read-only endpoint, but the slowest of the three the listing fires: drop the
		// session lock immediately so it stops blocking ajax_list/ajax_summary behind
		// it (see release_session_lock()).
		$this->release_session_lock();

		$today        = date('Y-m-d');
		$month_start  = date('Y-m-01');
		$month_end    = date('Y-m-t');
		$week_start   = date('Y-m-d', strtotime('monday this week'));
		$week_end     = date('Y-m-d', strtotime('sunday this week'));
		$next7_start  = date('Y-m-d', strtotime('+1 day'));
		$next7_end    = date('Y-m-d', strtotime('+7 days'));
		// Cumulative 14-day window shares the 7-day start (tomorrow) and extends
		// to +14, so "within 14 days" is a superset of "within 7 days".
		$next14_start = $next7_start;
		$next14_end   = date('Y-m-d', strtotime('+14 days'));
		// Current calendar quarter (used for owner-only conversion cards):
		// Q1 Jan–Mar · Q2 Apr–Jun · Q3 Jul–Sep · Q4 Oct–Dec.
		$q_idx         = (int) ceil(((int) date('n')) / 3);
		$q_start_month = ($q_idx - 1) * 3 + 1;
		$quarter_start = date('Y-' . sprintf('%02d', $q_start_month) . '-01');
		$quarter_end   = date('Y-m-t', strtotime($quarter_start . ' +2 months'));

		// Selected reporting period for the TC summary cards' month filter
		// (?month=YYYY-MM). Falls back to the current month. Re-scopes every
		// TC "(Month)" / "(Year)" card; the rolling Today/Week/Month cards and
		// the other roles keep the live current-month windows above.
		$this->load->helper('summary_period_helper');
		$this->load->helper('guest_list_status_filter');
		$this->load->helper('cancellation_rate');
		// Optional ?day=YYYY-MM-DD narrows every "(Month)" card to a single day
		// (the Year card still follows that day's year). Sales Agent / TC / Owner
		// & TC Lead (owner_as_agent) share this scope.
		$period = summary_resolve_month($this->input->get('month'), $today, $this->input->get('day'));
		// Owner dashboard's global Day/Week/Month/Year toggle (?owner_period=...);
		// re-scopes the whole owner per-agent matrix at once. Defaults to month.
		$owner_period = summary_resolve_owner_period($this->input->get('owner_period'), $today);

		$base = base_url('Booking');
		$fmt_dmy = function($d) { return date('d/m/Y', strtotime($d)); };
		$money = function($v) { return 'RM ' . number_format((float)$v, 2, '.', ','); };
		$qs = function($params) { return '?' . http_build_query($params); };

		$cards  = array();
		$tables = array();

		// On the booking listing the partial requests owner_as_agent=1 so Owner
		// (10) and TC Lead (25) receive the sales-agent payload scoped to their
		// own bookings; the Owner Dashboard omits it and keeps the matrix. Shared
		// with the view via summary_card_roles_helper so skeleton and payload agree.
		$this->load->helper('summary_card_roles');
		$owner_as_agent = ((int) $this->input->get('owner_as_agent') === 1);
		$is_tc      = summary_cards_show_agent_set($level, $owner_as_agent);
		$is_tclead  = false; // replaced by the sales-agent card set
		$is_op      = ($level == 40 || $level == 45); // OP and OP TEAM LEAD share the OP cards
		$is_finance = ($level == 30);
		$is_owner   = summary_cards_show_owner_matrix($level, $owner_as_agent);
		// Team Lead (25) gets the SAME per-agent matrix as the Owner on the
		// dashboard, but scoped to their own team members (see the matrix block).
		$is_team_lead = summary_cards_show_team_lead_matrix($level, $owner_as_agent);

		// ---------- TC / TC2 (own bookings) ----------
		// Credited-slot rule: a booking counts for this TC only when they hold
		// the credited slot for InsertDate (TC1 pre-2026-06-01, TC2 on/after).
		// Mirrors lead_conversion_credit_sql_fragment() so summary cards agree
		// with the Lead Dashboard's converted-leads attribution.
		if($is_tc) {
			$this->load->helper('lead_conversion_credit');
			$credit_clause = lead_conversion_credit_booking_clause();

			// Re-scope every TC "(Month)" card to the selected period.
			$month_start = $period['month_start'];
			$month_end   = $period['month_end'];
			$year_start  = $period['year_start'];
			$year_end    = $period['year_end'];

			// Anchor for the rolling Today/Week/Month triplet cards (New Leads,
			// Daily Handle, Avg Reply Time, Outbound). A picked day (?day=)
			// rebases the triplet: Today = the picked day, Week = its Mon–Sun
			// week, Month = its full calendar month; no day picked keeps the live
			// today / this week / this month. The single "(Month)" cards above
			// still collapse to the picked day via $month_start/$month_end.
			$anchor_day      = $period['is_day'] ? $period['day'] : $today;
			$triplet         = summary_agent_triplet_ranges($anchor_day);
			$tc_day          = $triplet['day'];
			$tc_week_start   = $triplet['week_start'];
			$tc_week_end     = $triplet['week_end'];
			$cur_month_start = $triplet['month_start'];
			$cur_month_end   = $triplet['month_end'];

			$row = $this->db->query(
				"SELECT COUNT(*) AS cnt FROM booking
				 WHERE {$credit_clause}
				   AND BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND CancelStatus='N' AND Status!='N'
				   AND CAST(InsertDate AS DATE) BETWEEN ? AND ?",
				array($admin_id, $admin_id, $month_start, $month_end)
			)->row();
			$cards['bc_month'] = array(
				'count' => (int)$row->cnt,
				// credited_agent + BC-title + status=A make the drill-down match the
				// count exactly (own credited BCs, no cancelled/draft/quotation),
				// regardless of the viewer's broader default listing scope.
				'link'  => $base . $qs(array(
					'booking_date'               => $fmt_dmy($month_start) . ' - ' . $fmt_dmy($month_end),
					'credited_agent'             => $admin_id,
					'booking_confirmation_title' => 'BOOKING CONFIRMATION',
					'status'                     => 'A',
				)),
			);

			// BC Created (Year) — same confirmed-BC rule (excludes QUOTATION /
			// PROFORMA INVOICE via BookingConfirmationTitle, and cancelled / draft
			// via CancelStatus / Status) over the selected year.
			$row_y = $this->db->query(
				"SELECT COUNT(*) AS cnt FROM booking
				 WHERE {$credit_clause}
				   AND BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND CancelStatus='N' AND Status!='N'
				   AND CAST(InsertDate AS DATE) BETWEEN ? AND ?",
				array($admin_id, $admin_id, $year_start, $year_end)
			)->row();
			$cards['bc_year'] = array(
				'count' => (int)$row_y->cnt,
				// Same credited-slot drill-down as bc_month. The year window straddles
				// the TC1/TC2 cutoff, so only the credited_agent predicate (which
				// switches slot by InsertDate) matches the count — a plain
				// sales_agent / sales_agent_2 filter would not.
				'link'  => $base . $qs(array(
					'booking_date'               => $fmt_dmy($year_start) . ' - ' . $fmt_dmy($year_end),
					'credited_agent'             => $admin_id,
					'booking_confirmation_title' => 'BOOKING CONFIRMATION',
					'status'                     => 'A',
				)),
			);

			// Sales card "Actual" (both Month and Year): counts ALL credited
			// Booking Confirmations in the period, regardless of payment status.
			// BC-only excludes QUOTATION / PROFORMA INVOICE; CancelStatus='N' /
			// Status!='N' exclude cancelled / deleted. No fully-paid gate (a BC
			// counts whether or not it has been paid). Month and Year share the
			// same logic — only the date range differs.
			$bc_sales_sql =
				"SELECT COALESCE(SUM(NetTotal),0) AS total FROM booking
				 WHERE {$credit_clause}
				   AND BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND CancelStatus='N' AND Status!='N'
				   AND booking.NetTotal > 0
				   AND CAST(InsertDate AS DATE) BETWEEN ? AND ?";

			$row = $this->db->query(
				$bc_sales_sql,
				array($admin_id, $admin_id, $month_start, $month_end)
			)->row();
			$sales_month_actual = (float)$row->total;

			$row_year = $this->db->query(
				$bc_sales_sql,
				array($admin_id, $admin_id, $year_start, $year_end)
			)->row();
			$sales_year_actual = (float)$row_year->total;

			// ---------- "vs same period last year" comparison ----------
			// Own sales this period against the SAME to-date span last year, so a
			// partway-through month/year compares against the same number of days
			// a year earlier (not the full prior period). Reuses the exact BC-only
			// sales SQL — just a shifted date window. For 2025 bookings the
			// credited slot resolves to the main sales person (pre-2026-06-01
			// rule), matching how those bookings are actually credited.
			$ly_month = summary_prior_year_window($month_start, $month_end, $today);
			$ly_year  = summary_prior_year_window($year_start, $year_end, $today);
			$sales_month_ly = (float)$this->db->query(
				$bc_sales_sql,
				array($admin_id, $admin_id, $ly_month['start'], $ly_month['end'])
			)->row()->total;
			$sales_year_ly = (float)$this->db->query(
				$bc_sales_sql,
				array($admin_id, $admin_id, $ly_year['start'], $ly_year['end'])
			)->row()->total;

			// Comparison sub-object the front-end renders as a sub-line. percent
			// is null when there's no prior-year baseline (last year was RM 0 — a
			// percent change would be meaningless); dir drives the ▲/▼ arrow.
			$year_ago_cmp = function($current, $prior) use ($money) {
				$pct = null;
				$dir = 'flat';
				if($prior > 0) {
					$change = round((($current - $prior) / $prior) * 100, 1);
					$pct = ($change > 0 ? '+' : '') . $change . '%';
					$dir = $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat');
				} else {
					$dir = $current > 0 ? 'up' : 'flat';
				}
				return array(
					'value'     => $money($prior),
					'percent'   => $pct,
					'dir'       => $dir,
					'has_prior' => $prior > 0,
				);
			};

			// Target lookups for the selected period — set by Owner / Team Lead
			// via Admin (sales_targets monthly, sales_target_year yearly).
			// Missing row => target 0 => percent rendered "—".
			$this->load->model('Sales_Target_Model');
			$target_amount = $this->Sales_Target_Model->get_amount(
				$admin_id, $period['year'], $period['month']
			);
			$year_target_amount = $this->Sales_Target_Model->get_year_amount(
				$admin_id, $period['year']
			);

			$pct_month = $target_amount > 0
				? round(($sales_month_actual / $target_amount) * 100, 1)
				: null;
			$pct_year = $year_target_amount > 0
				? round(($sales_year_actual / $year_target_amount) * 100, 1)
				: null;

			// raw / raw_target feed the Actual-vs-Target bar charts on the front
			// end; the chart caps the actual bar at 100% of target visually but
			// keeps the true percent in the label (over-achievement allowed).
			$cards['sales_month'] = array(
				'value'      => $money($sales_month_actual),
				'target'     => $money($target_amount),
				'percent'    => $pct_month === null ? '—' : ($pct_month . '%'),
				'has_target' => $target_amount > 0,
				'raw'        => $sales_month_actual,
				'raw_target' => $target_amount,
				'yoy'        => $year_ago_cmp($sales_month_actual, $sales_month_ly),
			);
			$cards['sales_year'] = array(
				'value'      => $money($sales_year_actual),
				'target'     => $money($year_target_amount),
				'percent'    => $pct_year === null ? '—' : ($pct_year . '%'),
				'has_target' => $year_target_amount > 0,
				'raw'        => $sales_year_actual,
				'raw_target' => $year_target_amount,
				'yoy'        => $year_ago_cmp($sales_year_actual, $sales_year_ly),
			);

			// ---------- Sales Commission (Month) — TC (level 50) only ----------
			// The TC's own commission rate (admin.CommissionPercent) applied to the
			// NetTotal of every COMPLETED booking (Status='Y') where they hold the
			// SECOND sales-agent slot (SalesAgent2), created in the selected month.
			// Unlike the other TC cards this ALWAYS credits the TC2 slot — it ignores
			// the TC1/TC2 InsertDate cutoff, per the commission rule. Cancelled /
			// non-BC / zero-value rows are excluded like the sales card.
			if($level === 50) {
				$this->load->helper('tc_commission');
				$commission_sales_sql =
					"SELECT COALESCE(SUM(NetTotal),0) AS total FROM booking
					 WHERE SalesAgent2 = ?
					   AND Status='Y'
					   AND CancelStatus='N'
					   AND BookingConfirmationTitle='BOOKING CONFIRMATION'
					   AND NetTotal > 0
					   AND CAST(InsertDate AS DATE) BETWEEN ? AND ?";
				$commission_base = (float) $this->db->query(
					$commission_sales_sql,
					array($admin_id, $month_start, $month_end)
				)->row()->total;
				$commission_pct = (float) $this->db->query(
					"SELECT COALESCE(CommissionPercent,0) AS pct FROM admin WHERE AdminID = ?",
					array($admin_id)
				)->row()->pct;
				$commission_amt = tc_commission_amount($commission_base, $commission_pct);
				$cards['commission_month'] = array(
					'value'   => $money($commission_amt),
					'base'    => $money($commission_base),
					'percent' => tc_commission_percent_label($commission_pct),
					'raw'     => $commission_amt,
				);
			}

			$row = $this->db->query(
				"SELECT COUNT(*) AS total,
				        SUM(CASE WHEN CancelStatus='Y' THEN 1 ELSE 0 END) AS cancelled
				 FROM booking
				 WHERE {$credit_clause}
				   AND BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND Status!='N'
				   AND " . cancellation_rate_exclude_duplicate_clause('booking') . "
				   AND CAST(InsertDate AS DATE) BETWEEN ? AND ?",
				array($admin_id, $admin_id, $month_start, $month_end)
			)->row();
			$rate = (int)$row->total > 0 ? round(((int)$row->cancelled / (int)$row->total) * 100, 1) : 0;
			$cards['cancellation_rate'] = array(
				'value'  => $rate . '%',
				'detail' => (int)$row->cancelled . ' / ' . (int)$row->total,
				'link'   => $base . $qs(array('status' => 'C', 'booking_date' => $fmt_dmy($month_start) . ' - ' . $fmt_dmy($month_end))),
			);

			// ---------- "Compare the Best" sub-lines for the TC KPI cards ----------
			// Aggregate across every agent under the same TC1/TC2 credited-slot
			// rule that the agent's own cards use, so the comparison universe is
			// apples-to-apples. The comparison population is restricted to the
			// SALES AGENT role only (admin.Level = 20) via the INNER JOIN below,
			// so a non-sales-agent (OP/Finance/Owner/TC) holding a credited slot
			// never appears as the benchmark. When the logged-in TC IS the best,
			// the sub-line shows "Best: You" so they recognise themselves.
			$this->load->helper('best_agent');
			$agent_expr = lead_conversion_credit_agent_expr();

			$pick_best = function($rows, $metric_key, $sort_desc) use ($admin_id) {
				if(empty($rows)) { return null; }
				usort($rows, function($a, $b) use ($metric_key, $sort_desc) {
					$av = (float)$a[$metric_key];
					$bv = (float)$b[$metric_key];
					if($av !== $bv) { return $sort_desc ? ($bv <=> $av) : ($av <=> $bv); }
					return strcmp((string)$a['agent_name'], (string)$b['agent_name']);
				});
				$top = $rows[0];
				if((int)$top['credited_agent_id'] === (int)$admin_id) {
					$top['agent_name'] = 'You';
				}
				return $top;
			};

			// BC count leaderboard counts ALL credited BCs (not just fully paid)
			// so it stays consistent with the bc_month card's own count.
			$best_bc_rows = $this->db->query(
				"SELECT
				   {$agent_expr} AS credited_agent_id,
				   admin.Name AS agent_name,
				   COUNT(*) AS bc_count
				 FROM booking
				 INNER JOIN admin ON admin.AdminID = {$agent_expr} AND admin.Level = '20'
				 WHERE booking.BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND booking.CancelStatus='N'
				   AND booking.Status!='N'
				   AND CAST(booking.InsertDate AS DATE) BETWEEN ? AND ?
				 GROUP BY credited_agent_id, agent_name
				 HAVING credited_agent_id IS NOT NULL AND credited_agent_id > 0",
				array($month_start, $month_end)
			)->result_array();
			$best_bc = $pick_best($best_bc_rows, 'bc_count', true);
			$cards['bc_month']['best'] = $best_bc
				? array('name' => $best_bc['agent_name'], 'value' => (string)(int)$best_bc['bc_count'])
				: null;

			// Same BC leaderboard over the selected year, for the BC (Year) figure.
			$best_bc_year_rows = $this->db->query(
				"SELECT
				   {$agent_expr} AS credited_agent_id,
				   admin.Name AS agent_name,
				   COUNT(*) AS bc_count
				 FROM booking
				 INNER JOIN admin ON admin.AdminID = {$agent_expr} AND admin.Level = '20'
				 WHERE booking.BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND booking.CancelStatus='N'
				   AND booking.Status!='N'
				   AND CAST(booking.InsertDate AS DATE) BETWEEN ? AND ?
				 GROUP BY credited_agent_id, agent_name
				 HAVING credited_agent_id IS NOT NULL AND credited_agent_id > 0",
				array($year_start, $year_end)
			)->result_array();
			$best_bc_year = $pick_best($best_bc_year_rows, 'bc_count', true);
			$cards['bc_year']['best'] = $best_bc_year
				? array('name' => $best_bc_year['agent_name'], 'value' => (string)(int)$best_bc_year['bc_count'])
				: null;

			// Month Sales leaderboard mirrors the agent's own card: all credited
			// Booking Confirmations in the selected month, regardless of payment
			// (no fully-paid gate), so "Best" stays apples-to-apples.
			$best_sales_rows = $this->db->query(
				"SELECT
				   {$agent_expr} AS credited_agent_id,
				   admin.Name AS agent_name,
				   COALESCE(SUM(booking.NetTotal), 0) AS total_sales
				 FROM booking
				 INNER JOIN admin ON admin.AdminID = {$agent_expr} AND admin.Level = '20'
				 WHERE booking.BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND booking.CancelStatus='N'
				   AND booking.Status!='N'
				   AND booking.NetTotal > 0
				   AND CAST(booking.InsertDate AS DATE) BETWEEN ? AND ?
				 GROUP BY credited_agent_id, agent_name
				 HAVING credited_agent_id IS NOT NULL AND credited_agent_id > 0",
				array($month_start, $month_end)
			)->result_array();
			$best_sales = $pick_best($best_sales_rows, 'total_sales', true);
			$cards['sales_month']['best'] = $best_sales
				? array('name' => $best_sales['agent_name'], 'value' => $money($best_sales['total_sales']))
				: null;

			// Year leaderboard mirrors the agent's own Year card: all credited
			// Booking Confirmations in the selected year, regardless of payment
			// (no fully-paid gate), so "Best" stays apples-to-apples with Month.
			$best_sales_year_rows = $this->db->query(
				"SELECT
				   {$agent_expr} AS credited_agent_id,
				   admin.Name AS agent_name,
				   COALESCE(SUM(booking.NetTotal), 0) AS total_sales
				 FROM booking
				 INNER JOIN admin ON admin.AdminID = {$agent_expr} AND admin.Level = '20'
				 WHERE booking.BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND booking.CancelStatus='N'
				   AND booking.Status!='N'
				   AND booking.NetTotal > 0
				   AND CAST(booking.InsertDate AS DATE) BETWEEN ? AND ?
				 GROUP BY credited_agent_id, agent_name
				 HAVING credited_agent_id IS NOT NULL AND credited_agent_id > 0",
				array($year_start, $year_end)
			)->result_array();
			$best_sales_year = $pick_best($best_sales_year_rows, 'total_sales', true);
			$cards['sales_year']['best'] = $best_sales_year
				? array('name' => $best_sales_year['agent_name'], 'value' => $money($best_sales_year['total_sales']))
				: null;

			// Cancellation Rate: drop the CancelStatus filter because the
			// numerator needs the cancelled rows; min-sample 3 keeps a single-BC
			// agent at 0% from dominating.
			$cancel_rows = $this->db->query(
				"SELECT
				   {$agent_expr} AS credited_agent_id,
				   admin.Name AS agent_name,
				   COUNT(*) AS total,
				   SUM(CASE WHEN booking.CancelStatus='Y' THEN 1 ELSE 0 END) AS cancelled
				 FROM booking
				 INNER JOIN admin ON admin.AdminID = {$agent_expr} AND admin.Level = '20'
				 WHERE booking.BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND booking.Status!='N'
				   AND " . cancellation_rate_exclude_duplicate_clause('booking') . "
				   AND CAST(booking.InsertDate AS DATE) BETWEEN ? AND ?
				 GROUP BY credited_agent_id, agent_name
				 HAVING credited_agent_id IS NOT NULL AND credited_agent_id > 0 AND COUNT(*) >= 3",
				array($month_start, $month_end)
			)->result_array();
			foreach($cancel_rows as &$cr) {
				$cr['rate'] = ((float)$cr['cancelled'] / (float)$cr['total']) * 100;
			}
			unset($cr);
			$best_cancel = $pick_best($cancel_rows, 'rate', false);
			$cards['cancellation_rate']['best'] = $best_cancel
				? array(
					'name'  => $best_cancel['agent_name'],
					'value' => round((float)$best_cancel['rate'], 1) . '%',
				)
				: null;

			// Conversion Rate (YTD) — TC card. Fixed year-to-date window (Jan 1 of
			// the current year through today, independent of the month filter).
			// Counts a conversion the same way the Lead Ownership dashboard's
			// "Converted" column does: is_converted = 1 AND booking_id IS NOT NULL,
			// with NO TC1/TC2 credit gate. The '1=1' override neutralises the credit
			// fragment so a lead assigned to the agent counts as their conversion
			// regardless of which TC slot holds the booking — matching the Top
			// Agents – Conversion panel below.
			$this->load->model('Report_Model');
			$ytd_start = date('Y-01-01');
			$ytd_end   = $today;
			$by_agent = $this->Report_Model->Lead_Dashboard_By_Agent(
				array('start_date' => $ytd_start, 'end_date' => $ytd_end),
				'1=1'
			);

			// Resolve the logged-in admin to their GHL UserID(s). Canonical source
			// is admin_lead_dashboard_agents (maintained from Admin / Update);
			// email match is a fallback for admins whose mapping hasn't been
			// configured yet. Team-inbox GHL users use shared Gmail addresses
			// that don't match admin.Email, so an email-only lookup silently
			// drops them — see lead_conversion_credit_helper.php.
			$mapping_rows = $this->db->select('GhlUserID')
				->where('AdminID', $admin_id)
				->get('admin_lead_dashboard_agents')->result();
			$my_ghl_uids = array_values(array_filter(array_map(function($r) {
				return (string)$r->GhlUserID;
			}, $mapping_rows), 'strlen'));
			if(empty($my_ghl_uids)) {
				$email_rows = $this->db->query(
					"SELECT gu.UserID
					 FROM admin a
					 INNER JOIN ghl_users gu
					   ON LOWER(TRIM(gu.Email)) = LOWER(TRIM(a.Email))
					 WHERE a.AdminID = ?",
					array($admin_id)
				)->result();
				$my_ghl_uids = array_values(array_filter(array_map(function($r) {
					return (string)$r->UserID;
				}, $email_rows), 'strlen'));
			}

			$own_rate        = null;
			$own_total_leads = 0;
			$own_converted   = 0;
			if(!empty($my_ghl_uids)) {
				$uid_set = array_flip($my_ghl_uids);
				foreach($by_agent as $a) {
					if(isset($uid_set[(string)$a['agent_id']])) {
						$own_total_leads += (int)$a['total_leads'];
						$own_converted   += (int)$a['converted_leads'];
					}
				}
				$own_rate = $own_total_leads > 0
					? round(($own_converted / $own_total_leads) * 100, 1)
					: null;
			}
			// "Best:" compares against SALES AGENTS plus TC LEADS: restrict the
			// leaderboard pool to GHL users mapping to a level-20 or level-25
			// admin. Including TC Leads (25) lets a logged-in TC Lead who
			// out-converts every sales agent show up as "Best: You" instead of
			// sitting above a lower sales-agent number. The agent's own rate
			// above is unaffected (it filters by $my_ghl_uids).
			$sales_uid_set = $this->sales_agent_ghl_uids(array('20', '25'));
			$conv_pool = array_values(array_filter($by_agent, function($a) use ($sales_uid_set) {
				return isset($sales_uid_set[(string)$a['agent_id']]);
			}));
			$best_conv = best_conversion_rate_agent($conv_pool, 3);
			if($best_conv && !empty($my_ghl_uids) && in_array((string)$best_conv['agent_id'], $my_ghl_uids, true)) {
				$best_conv['agent_name'] = 'You';
			}
			$cards['conversion_rate_ytd'] = array(
				'value'  => $own_rate === null ? '-' : (round($own_rate, 1) . '%'),
				'detail' => $own_converted . ' / ' . $own_total_leads,
				'best'   => $best_conv
					? array(
						'name'  => $best_conv['agent_name'],
						'value' => round((float)$best_conv['conversion_rate'], 1) . '%',
					)
					: null,
			);

			// "My Leads (Today / Week / Month)" card. Scoped to the logged-in TC
			// via Lead_Dashboard_Summary's agent_id filter (NULLIF in_user IN (?)).
			// When the TC isn't linked to GHL we emit a stable empty payload so
			// the front-end still renders zeros instead of "...".
			$fmt_seconds = function($secs) {
				if($secs === null) { return '-'; }
				if($secs >= 3600) { return round($secs / 3600, 1) . 'h'; }
				if($secs >= 60)   { return round($secs / 60,   1) . 'm'; }
				return $secs . 's';
			};
			if(!empty($my_ghl_uids)) {
				// "New Leads" = "New Lead Picked Up" from the Lead Reply Activity
				// dashboard, scoped to the logged-in TC's GHL uid(s). Counts DISTINCT
				// leads she is the real assignee of (ghl_lead_ownership), windowed by
				// the PICK-UP time (assigned_at) -- not by when the conversation
				// started -- so the card equals that report's column exactly. DISTINCT
				// across her inboxes keeps a lead picked up on two of them counted once.
				$leads_count = function($start, $end) use ($my_ghl_uids) {
					return $this->Report_Model->Lead_Reply_Activity_Assigned_New_Leads_For_Uids(
						$my_ghl_uids, $start, $end
					);
				};
				$cards['tc_leads_dwm'] = array(
					'day'   => $leads_count($tc_day, $tc_day),
					'week'  => $leads_count($tc_week_start, $tc_week_end),
					'month' => $leads_count($cur_month_start, $cur_month_end),
				);

				// "Daily Handle Lead Count" card (Today / Week / Month). Each period
				// is the distinct "Lead Responded" leads for the logged-in TC over
				// that window, reusing the Lead Reply Activity dashboard query so the
				// figures match that report's Lead Responded column exactly. The query
				// counts DISTINCT leads across the whole date range, so a week/month
				// window naturally de-dupes a lead replied to on several days.
				// Counts DISTINCT leads across ALL the TC's linked GHL uids in one
				// query -- NOT a per-inbox sum, which would double count a lead handled
				// by two of her own inboxes (e.g. transferred between team inboxes). A
				// single-inbox TC still equals her dashboard row exactly.
				$handle_count = function($start, $end) use ($my_ghl_uids) {
					return $this->Report_Model->Lead_Reply_Activity_Responded_Distinct_For_Uids(
						$my_ghl_uids, $start, $end
					);
				};
				// Drill-down: open the Lead Reply Hourly report for this TC's own
				// inbox, for today. The hourly report is single-owner, so use the
				// first linked GHL uid (matches how the report locks to owner[0]).
				$handle_owner = !empty($my_ghl_uids) ? (string) $my_ghl_uids[0] : '';
				$cards['tc_handle_lead_today'] = array(
					'day'   => $handle_count($tc_day, $tc_day),
					'week'  => $handle_count($tc_week_start, $tc_week_end),
					'month' => $handle_count($cur_month_start, $cur_month_end),
					'link'  => $handle_owner !== ''
						? base_url('Report/Lead_Reply_Activity_Hourly')
							. '?owner=' . urlencode($handle_owner)
							. '&reply_date=' . urlencode($fmt_dmy($tc_day))
						: '',
				);
				// "Avg Reply Time to Inbound" now uses Message-Log logic: every
				// inbound->outbound reply pair in the agent's threads (in working
				// hours, same day) is averaged, windowed by when the REPLY was
				// sent. This matches the Message Log's "Avg time taken" exactly.
				$resp_day   = $this->Report_Model->Ghl_Messages_Avg_Reply_Seconds_For_Uids($tc_day, $tc_day, $my_ghl_uids);
				$resp_week  = $this->Report_Model->Ghl_Messages_Avg_Reply_Seconds_For_Uids($tc_week_start, $tc_week_end, $my_ghl_uids);
				$resp_month = $this->Report_Model->Ghl_Messages_Avg_Reply_Seconds_For_Uids($cur_month_start, $cur_month_end, $my_ghl_uids);
				$resp_round = function($secs) { return $secs === null ? null : (int) round($secs); };
				$cards['tc_response_time_dwm'] = array(
					'day'           => $fmt_seconds($resp_round($resp_day)),
					'week'          => $fmt_seconds($resp_round($resp_week)),
					'month'         => $fmt_seconds($resp_round($resp_month)),
					'day_seconds'   => $resp_round($resp_day),
					'week_seconds'  => $resp_round($resp_week),
					'month_seconds' => $resp_round($resp_month),
				);

				// "Lead Pickup Speed (Month)". Raw wall-clock time from a lead
				// starting a brand-new conversation to the TC's first reply -- how
				// fast you pick a fresh lead up, distinct from the avg-of-5 "My
				// Response Time" above. Windowed by lead start date.
				// A "(Month)" card: follows the selected reporting period
				// ($month_start/$month_end), not the live current month, so the
				// value re-scopes when the TC picks a month in the selector.
				$this->load->helper('response_time');
				$pickup_month = $this->Report_Model->Lead_Pickup_Speed_Summary(array(
					'agent_id'   => $my_ghl_uids,
					'start_date' => $month_start, 'end_date' => $month_end,
				));
				$cards['tc_pickup_speed_month'] = array(
					'value'   => format_response_duration($pickup_month['avg_seconds']),
					'count'   => $pickup_month['count'],
					'seconds' => $pickup_month['avg_seconds'],
					'best'    => $this->tc_pickup_speed_best($month_start, $month_end, $my_ghl_uids),
				);
			} else {
				$cards['tc_leads_dwm'] = array(
					'day' => 0, 'week' => 0, 'month' => 0,
				);
				$cards['tc_handle_lead_today'] = array('day' => 0, 'week' => 0, 'month' => 0, 'link' => '');
				$cards['tc_response_time_dwm'] = array(
					'day' => '-', 'week' => '-', 'month' => '-',
					'day_seconds' => null, 'week_seconds' => null, 'month_seconds' => null,
				);
				// Still show the team-wide "Best:" benchmark even when this TC has
				// no GHL link (own value renders as an em-dash on the front-end).
				$cards['tc_pickup_speed_month'] = array(
					'value' => '-', 'count' => 0, 'seconds' => null,
					'best' => $this->tc_pickup_speed_best($month_start, $month_end, $my_ghl_uids),
				);
			}

			// ---------- "Best:" benchmarks for Avg Reply Time & My Leads ----------
			// Team-wide month-window leaderboard over the anchored month
			// ($cur_month_* = the picked day's month, else the live current
			// month), matching the triplet's Month column above. Fastest avg reply
			// time (min-sample 3 leads) and highest lead volume; "You" when
			// that's the logged-in agent.
			$this->load->helper('response_time');
			// "New Leads" best uses the SAME "New Lead Picked Up" universe as the
			// card's own value (ghl_lead_ownership, windowed by pick-up time) so the
			// two are apples-to-apples. Reshaped to the {agent_id, agent_name,
			// total_leads} shape pick_ghl_best expects.
			$leads_best_rows = array();
			foreach($this->Report_Model->Lead_Reply_Activity_By_Agent(array(
				'start_date' => $cur_month_start, 'end_date' => $cur_month_end,
			)) as $r) {
				// "Best:" compares only against the SALES AGENT role: keep owners
				// whose GHL user maps to a level-20 admin ($sales_uid_set above).
				if(!isset($sales_uid_set[(string)$r['owner_user_id']])) { continue; }
				$leads_best_rows[] = array(
					'agent_id'    => (string)$r['owner_user_id'],
					'agent_name'  => $r['owner_name'],
					'total_leads' => (int)$r['assigned_leads'],
				);
			}
			$pick_ghl_best = function($rows, $field, $sort_desc, $min_leads) use ($my_ghl_uids) {
				$f = array();
				foreach($rows as $r) {
					if((string)$r['agent_id'] === '__unassigned__') { continue; }
					if((int)$r['total_leads'] < $min_leads) { continue; }
					if(!$sort_desc && ($r[$field] === null || (float)$r[$field] <= 0)) { continue; }
					$f[] = $r;
				}
				if(empty($f)) { return null; }
				usort($f, function($a, $b) use ($field, $sort_desc) {
					$av = (float)$a[$field]; $bv = (float)$b[$field];
					if($av !== $bv) { return $sort_desc ? ($bv <=> $av) : ($av <=> $bv); }
					return strcmp((string)$a['agent_name'], (string)$b['agent_name']);
				});
				$top = $f[0];
				if(!empty($my_ghl_uids) && in_array((string)$top['agent_id'], $my_ghl_uids, true)) {
					$top['agent_name'] = 'You';
				}
				return $top;
			};
			// Reply-time "Best:" uses the same Message-Log logic as the card's
				// own value (per-agent, reply-date windowed) so the two are
				// apples-to-apples. Restricted to the SALES AGENT pool; min 3
				// conversations replied to.
				$msg_resp_rows = $this->Report_Model->Ghl_Messages_Avg_Reply_By_Agent($cur_month_start, $cur_month_end);
				$msg_resp_rows = array_values(array_filter($msg_resp_rows, function($r) use ($sales_uid_set) {
					return isset($sales_uid_set[(string)$r['agent_id']]);
				}));
				$best_resp = $pick_ghl_best($msg_resp_rows, 'avg_response_time_seconds', false, 3);
			$cards['tc_response_time_dwm']['best'] = $best_resp
				? array('name' => $best_resp['agent_name'], 'value' => format_response_duration((int)round((float)$best_resp['avg_response_time_seconds'])))
				: null;
			$best_leads = $pick_ghl_best($leads_best_rows, 'total_leads', true, 1);
			$cards['tc_leads_dwm']['best'] = $best_leads
				? array('name' => $best_leads['agent_name'], 'value' => (string)(int)$best_leads['total_leads'])
				: null;

			// ---------- Outbound Messages (Today / Week / Month) ----------
			// Agent-sent (outbound) GHL messages by send time. Own counts scoped to
			// the logged-in TC's GHL uid(s); "Best:" = top sender over the anchored
			// month (the picked day's month, else the live current month),
			// matching the triplet's Month column above.
			$ob_day = $ob_week = $ob_month = 0;
			if(!empty($my_ghl_uids)) {
				$ob_place = implode(',', array_fill(0, count($my_ghl_uids), '?'));
				$ob_count = function($start, $end) use ($my_ghl_uids, $ob_place) {
					$r = $this->db->query(
						"SELECT COUNT(*) AS c FROM ghl_messages
						 WHERE direction='outbound'
						   AND NULLIF(user_id,'') IN ({$ob_place})
						   AND date_added BETWEEN ? AND ?",
						array_merge($my_ghl_uids, array($start . ' 00:00:00', $end . ' 23:59:59'))
					)->row();
					return $r ? (int)$r->c : 0;
				};
				$ob_day   = $ob_count($tc_day, $tc_day);
				$ob_week  = $ob_count($tc_week_start, $tc_week_end);
				$ob_month = $ob_count($cur_month_start, $cur_month_end);
			}
			$ob_best = null;
			$ob_best_row = $this->db->query(
				"SELECT gm.user_id,
				        COALESCE(NULLIF(gu.Name,''), gm.user_id) AS agent_name,
				        COUNT(*) AS c
				 FROM ghl_messages gm
				 LEFT JOIN ghl_users gu ON gu.UserID = gm.user_id
				 WHERE gm.direction='outbound'
				   AND NULLIF(gm.user_id,'') IS NOT NULL
				   AND gm.date_added BETWEEN ? AND ?
				 GROUP BY gm.user_id, agent_name
				 ORDER BY c DESC, agent_name ASC
				 LIMIT 1",
				array($cur_month_start . ' 00:00:00', $cur_month_end . ' 23:59:59')
			)->row();
			if(!empty($ob_best_row)) {
				$ob_name = (!empty($my_ghl_uids) && in_array((string)$ob_best_row->user_id, $my_ghl_uids, true))
					? 'You' : $ob_best_row->agent_name;
				$ob_best = array('name' => $ob_name, 'value' => (string)(int)$ob_best_row->c);
			}
			$cards['outbound_msgs_dwm'] = array(
				'day' => $ob_day, 'week' => $ob_week, 'month' => $ob_month, 'best' => $ob_best,
			);

			// ---------- Follow-up % (Month) ----------
			// Reuses the Lead Ownership dashboard's definition: owned leads whose
			// follow_up_status is sent/completed, over all owned leads. Own scoped
			// to the TC's GHL uid(s); "Best:" = highest rate (min-sample 3 owned).
			// Month-granular: scoped to the anchored month ($cur_month_*, = the
			// picked day's whole month, else the live current month), so the
			// picker moves it by month rather than to a single day.
			$fu_rows = $this->Report_Model->Lead_Ownership_By_Agent(array(
				'start_date' => $cur_month_start, 'end_date' => $cur_month_end,
			));
			$fu_owned = 0; $fu_followed = 0;
			$uid_set_fu = !empty($my_ghl_uids) ? array_flip($my_ghl_uids) : array();
			$fu_best_pick = null;
			foreach($fu_rows as $fr) {
				if(isset($uid_set_fu[(string)$fr['owner_user_id']])) {
					$fu_owned    += (int)$fr['owned_leads'];
					$fu_followed += (int)$fr['follow_up_leads'];
				}
				if((int)$fr['owned_leads'] >= 3) {
					if($fu_best_pick === null
						|| (float)$fr['follow_up_rate'] > (float)$fu_best_pick['follow_up_rate']
						|| ((float)$fr['follow_up_rate'] === (float)$fu_best_pick['follow_up_rate']
							&& strcmp((string)$fr['owner_name'], (string)$fu_best_pick['owner_name']) < 0)) {
						$fu_best_pick = $fr;
					}
				}
			}
			$fu_rate = $fu_owned > 0 ? round(($fu_followed / $fu_owned) * 100, 1) : null;
			$fu_best = null;
			if($fu_best_pick !== null) {
				$fu_bname = (!empty($my_ghl_uids) && in_array((string)$fu_best_pick['owner_user_id'], $my_ghl_uids, true))
					? 'You' : $fu_best_pick['owner_name'];
				$fu_best = array('name' => $fu_bname, 'value' => round((float)$fu_best_pick['follow_up_rate'], 1) . '%');
			}
			$cards['followup_rate'] = array(
				'value'  => $fu_rate === null ? '-' : ($fu_rate . '%'),
				'detail' => $fu_followed . ' / ' . $fu_owned,
				'best'   => $fu_best,
			);

			// ---------- Agent Score (Month) ----------
			// Weighted, best-benchmarked composite scoped to the selected month.
			// Always computed so the leaderboard renders even when this TC has no
			// data of their own. (No Year variant: a year-wide reply-time pass
			// scans the whole message log and blew the request's memory limit.)
			$cards['agent_score_month'] = $this->agent_score_card($month_start, $month_end, $my_ghl_uids, $admin_id);

			// ---------- TC operational cards (own bookings) ----------
			// Mirror three OP cards but scoped to this agent's own bookings so a
			// sales agent can chase their own upcoming readiness and customer
			// payments. They use live rolling windows (today / +7 / +14), so the
			// TC month filter above does NOT re-scope them. Same card keys + DOM
			// ids as the OP cards (TC and OP levels never render together), so the
			// existing JS populates them with no extra wiring.
			//
			// These three cards (Travel in 7 / 14 Days – Not Yet Ready, Payment From
			// Customer Due Soon) are whole-DB over the agent's own bookings (see
			// _agent_upcoming_cards()), so each count matches its drill-down link.
			$upcoming = $this->_agent_upcoming_cards($admin_id);
			$cards    = array_merge($cards, $upcoming['cards']);
			$tables   = array_merge($tables, $upcoming['tables']);
		}

		// ---------- TC LEAD (team-wide lead + booking metrics) ----------
		// Owner no longer shares this block: the owner dashboard is a single
		// per-agent matrix built below (see the $is_owner branch). TC-Lead keeps
		// the full card set unchanged.
		if($is_tclead) {
			if($is_tclead) {
				$row = $this->db->query(
					"SELECT
					   SUM(CASE WHEN CAST(InsertDate AS DATE) BETWEEN ? AND ? THEN 1 ELSE 0 END) AS month_cnt,
					   SUM(CASE WHEN CAST(InsertDate AS DATE) BETWEEN ? AND ? THEN 1 ELSE 0 END) AS week_cnt
					 FROM booking
					 WHERE BookingConfirmationTitle='BOOKING CONFIRMATION'
					   AND CancelStatus='N' AND Status!='N'",
					array($month_start, $month_end, $week_start, $week_end)
				)->row();
				$cards['bc_week_month'] = array(
					'week'       => (int)$row->week_cnt,
					'month'      => (int)$row->month_cnt,
					'link_month' => $base . $qs(array('booking_date' => $fmt_dmy($month_start) . ' - ' . $fmt_dmy($month_end))),
					'link_week'  => $base . $qs(array('booking_date' => $fmt_dmy($week_start) . ' - ' . $fmt_dmy($week_end))),
				);

				$row = $this->db->query(
					"SELECT COUNT(*) AS total,
					        SUM(CASE WHEN CancelStatus='Y' THEN 1 ELSE 0 END) AS cancelled
					 FROM booking
					 WHERE BookingConfirmationTitle='BOOKING CONFIRMATION' AND Status!='N'
					   AND " . cancellation_rate_exclude_duplicate_clause('booking') . "
					   AND CAST(InsertDate AS DATE) BETWEEN ? AND ?",
					array($month_start, $month_end)
				)->row();
				$rate = (int)$row->total > 0 ? round(((int)$row->cancelled / (int)$row->total) * 100, 1) : 0;
				$cards['cancellation_rate'] = array(
					'value'  => $rate . '%',
					'detail' => (int)$row->cancelled . ' / ' . (int)$row->total,
					'link'   => $base . $qs(array('status' => 'C', 'booking_date' => $fmt_dmy($month_start) . ' - ' . $fmt_dmy($month_end))),
				);
			}

			$this->load->model('Report_Model');
			$lead_month = $this->Report_Model->Lead_Dashboard_Summary(array('start_date' => $month_start, 'end_date' => $month_end));
			$lead_week  = $this->Report_Model->Lead_Dashboard_Summary(array('start_date' => $week_start,  'end_date' => $week_end));
			$lead_day   = $this->Report_Model->Lead_Dashboard_Summary(array('start_date' => $today,       'end_date' => $today));

			// Owner uses the full calendar quarter for the conversion-related
			// metrics (Cards 8 & 9). A month is too short a lens for owner-
			// level review and resets every 1st. Card 7 ("Leads" total) keeps
			// its month window via $lead_month.
			$conv_start = $is_owner ? $quarter_start : $month_start;
			$conv_end   = $is_owner ? $quarter_end   : $month_end;
			$lead_conv  = $this->Report_Model->Lead_Dashboard_Summary(array('start_date' => $conv_start, 'end_date' => $conv_end));

			$secs = $lead_conv['avg_response_time_seconds'];
			if($secs === null) {
				$resp_label = '-';
			} elseif($secs >= 3600) {
				$resp_label = round($secs / 3600, 1) . 'h';
			} elseif($secs >= 60) {
				$resp_label = round($secs / 60, 1) . 'm';
			} else {
				$resp_label = $secs . 's';
			}

			$cards['leads_dwm'] = array(
				'day'                   => (int)$lead_day['total_leads'],
				'week'                  => (int)$lead_week['total_leads'],
				'month'                 => (int)$lead_month['total_leads'],
				// Card 8 metrics — computed over $conv_start..$conv_end.
				'converted_month'       => (int)$lead_conv['converted_leads'],
				'responded_month'       => (int)$lead_conv['responded_leads'],
				'total_conv'            => (int)$lead_conv['total_leads'],
				'avg_response_seconds'  => $lead_conv['avg_response_time_seconds'],
				'conversion_rate'       => $lead_conv['conversion_rate'] . '%',
				'response_rate'         => $lead_conv['response_rate'] . '%',
				'avg_response_time'     => $resp_label,
			);

			// Active Leads (Day/Week/Month) — unconverted leads still in
			// agents' GHL inboxes, windowed by lead_started_at. is_converted=0
			// is the only "still open" signal we have today; converted_at is
			// not yet trustworthy enough to distinguish closed-won from closed-
			// lost. Headline matches the leads_dwm windows so users can read
			// "X new this month, Y still open" off the same row.
			$row = $this->db->query(
				"SELECT
				   SUM(CASE WHEN pl.lead_started_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS day_active,
				   SUM(CASE WHEN pl.lead_started_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS week_active,
				   SUM(CASE WHEN pl.lead_started_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS month_active
				 FROM ghl_processed_leads pl
				 WHERE pl.is_converted = 0
				   AND pl.is_bot_bounce = 0",
				array(
					$today       . ' 00:00:00', $today       . ' 23:59:59',
					$week_start  . ' 00:00:00', $week_end    . ' 23:59:59',
					$month_start . ' 00:00:00', $month_end   . ' 23:59:59',
				)
			)->row();
			$cards['active_leads_dwm'] = array(
				'day'   => (int)$row->day_active,
				'week'  => (int)$row->week_active,
				'month' => (int)$row->month_active,
			);

			// Top Agents – Conversion counts a conversion the same way the Lead
			// Ownership dashboard's "Converted" column does: is_converted = 1 AND
			// booking_id IS NOT NULL, with NO TC1/TC2 credit gate. The '1=1'
			// override neutralises the credit fragment so an agent is credited for
			// every converted lead assigned to them, regardless of which TC slot
			// holds the booking. (The TC YTD card at ~line 1172 and the Lead
			// Dashboard report keep the gated default.)
			$by_agent = $this->Report_Model->Lead_Dashboard_By_Agent(
				array('start_date' => $conv_start, 'end_date' => $conv_end),
				'1=1'
			);
			$top_agents = array();
			foreach(array_slice($by_agent, 0, 10) as $a) {
				$top_agents[] = array(
					'agent_name'      => $a['agent_name'],
					'total_leads'     => (int)$a['total_leads'],
					'converted_leads' => (int)$a['converted_leads'],
					'conversion_rate' => $a['conversion_rate'] . '%',
				);
			}
			$tables['agent_conversion'] = $top_agents;

			// Closed Sales by Destination (Month) — fully-paid BCs only, ranked
			// by revenue. "Fully paid" matches the TC Total Sales card at
			// line ~716–721 so the two reconcile.
			$paid_subquery = "COALESCE((
				SELECT SUM(p.Credit) FROM payment p
				WHERE p.BookingID = booking.BookingID
				  AND p.Status = 'Y' AND p.Credit > 0
				  AND (p.Type IS NULL OR p.Type != 'AGENT COMMISSION FROM SUPPLIER')
			), 0)";

			$dest_closed_rows = $this->db->query(
				"SELECT category.Name AS destination, category.CategoryID AS id,
				        COUNT(BookingID) AS cnt, COALESCE(SUM(NetTotal),0) AS total
				 FROM booking
				 LEFT JOIN category ON category.CategoryID = booking.Destination
				 WHERE booking.BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND CancelStatus='N' AND booking.Status!='N'
				   AND booking.NetTotal > 0
				   AND {$paid_subquery} >= booking.NetTotal
				   AND CAST(booking.InsertDate AS DATE) BETWEEN ? AND ?
				 GROUP BY category.Name, category.CategoryID
				 ORDER BY total DESC
				 LIMIT 5",
				array($month_start, $month_end)
			)->result();
			$dest_closed_out = array();
			foreach($dest_closed_rows as $r) {
				$dest_closed_out[] = array(
					'destination' => $r->destination,
					'count'       => (int)$r->cnt,
					'total'       => $money($r->total),
					'link'        => $base . $qs(array(
						'destination'  => $r->id,
						'booking_date' => $fmt_dmy($month_start) . ' - ' . $fmt_dmy($month_end),
					)),
				);
			}
			$tables['destination_closed_sales'] = $dest_closed_out;

			// Active Leads by Tag — unconverted leads bucketed by GHL tag into
			// destination / language / race. Allowlist lives in
			// application/helpers/ghl_tag_categories_helper.php so it can be
			// edited without a migration. Same "active = is_converted=0"
			// scope as the Active Leads card, but sliced by tag instead of
			// by window.
			$this->load->helper('ghl_tag_categories');
			$tables['active_leads_by_tag'] = $this->Report_Model->Active_Leads_By_Tag(
				ghl_tag_categories()
			);

			// Self Gen vs Company (Month) — split BC count + NetTotal by whether
			// booking.Source = 'SELF GEN' (agent's own lead) versus any other
			// source or NULL (company-generated). Attribution is by primary
			// SalesAgent (TC1) regardless of date — NOT the TC1/TC2 credited-slot
			// rule used by Top Agents – Conversion; self-generation is about who
			// hunted the lead, so the primary salesperson is what matters.
			$self_gen = SELF_GEN_SOURCE_NAME;
			$row = $this->db->query(
				"SELECT
				   SUM(CASE WHEN UPPER(TRIM(s.Name)) = ? THEN 1 ELSE 0 END) AS self_gen_cnt,
				   COALESCE(SUM(CASE WHEN UPPER(TRIM(s.Name)) = ? THEN b.NetTotal ELSE 0 END), 0) AS self_gen_total,
				   SUM(CASE WHEN UPPER(TRIM(s.Name)) <> ? OR s.Name IS NULL THEN 1 ELSE 0 END) AS company_cnt,
				   COALESCE(SUM(CASE WHEN UPPER(TRIM(s.Name)) <> ? OR s.Name IS NULL THEN b.NetTotal ELSE 0 END), 0) AS company_total
				 FROM booking b
				 LEFT JOIN source s ON s.SourceID = b.Source
				 WHERE b.BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND b.CancelStatus='N' AND b.Status!='N'
				   AND CAST(b.InsertDate AS DATE) BETWEEN ? AND ?",
				array($self_gen, $self_gen, $self_gen, $self_gen, $month_start, $month_end)
			)->row();
			$cards['lead_source_split'] = array(
				'self_gen_count' => (int)$row->self_gen_cnt,
				'self_gen_total' => $money($row->self_gen_total),
				'company_count'  => (int)$row->company_cnt,
				'company_total'  => $money($row->company_total),
			);

			// Sales by Agent — Self Gen vs Company (Month). One row per
			// SalesAgent that created at least one BC this month; ordered so
			// agents in the same Team appear in consecutive rows (agents with no
			// Team sort last). Used as the per-agent / per-team breakdown for the
			// headline Self Gen vs Company card.
			$agent_rows = $this->db->query(
				"SELECT
				   a.AdminID,
				   a.Name AS agent_name,
				   a.TeamID,
				   t.Name AS team_name,
				   SUM(CASE WHEN UPPER(TRIM(s.Name)) = ? THEN 1 ELSE 0 END) AS self_gen_cnt,
				   COALESCE(SUM(CASE WHEN UPPER(TRIM(s.Name)) = ? THEN b.NetTotal ELSE 0 END), 0) AS self_gen_total,
				   SUM(CASE WHEN UPPER(TRIM(s.Name)) <> ? OR s.Name IS NULL THEN 1 ELSE 0 END) AS company_cnt,
				   COALESCE(SUM(CASE WHEN UPPER(TRIM(s.Name)) <> ? OR s.Name IS NULL THEN b.NetTotal ELSE 0 END), 0) AS company_total,
				   COUNT(*) AS total_cnt
				 FROM booking b
				 INNER JOIN admin a ON a.AdminID = b.SalesAgent
				 LEFT JOIN team t ON t.TeamID = a.TeamID
				 LEFT JOIN source s ON s.SourceID = b.Source
				 WHERE b.BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND b.CancelStatus='N' AND b.Status!='N'
				   AND CAST(b.InsertDate AS DATE) BETWEEN ? AND ?
				   AND b.SalesAgent IS NOT NULL AND b.SalesAgent > 0
				 GROUP BY a.AdminID, a.Name, a.TeamID, t.Name
				 ORDER BY (t.Name IS NULL), t.Name ASC, a.Name ASC",
				array($self_gen, $self_gen, $self_gen, $self_gen, $month_start, $month_end)
			)->result();
			$rows_out = array();
			foreach($agent_rows as $r) {
				$tot = (int)$r->total_cnt;
				$sg_pct = $tot > 0 ? round(((int)$r->self_gen_cnt / $tot) * 100, 1) : 0;
				$rows_out[] = array(
					'agent_name'      => $r->agent_name,
					'team_name'       => $r->team_name ?: 'Unassigned',
					'self_gen_count'  => (int)$r->self_gen_cnt,
					'self_gen_total'  => $money($r->self_gen_total),
					'company_count'   => (int)$r->company_cnt,
					'company_total'   => $money($r->company_total),
					'self_gen_pct'    => $sg_pct . '%',
				);
			}
			$tables['agent_source_split'] = $rows_out;
		}

		// ---------- OWNER (per-agent performance matrix) ----------
		// One row per TC sales agent (Level 20/50) across all 11 owner metrics,
		// scoped to the global Day/Week/Month/Year toggle ($owner_period). Built
		// by owner_agent_matrix(); rendered as a single matrix table.
		if($is_owner || $is_team_lead) {
			// Pass the column-granularity ($owner_period['base']) — "Yesterday"
			// behaves like Day and "Same Period Last Year" like Year for which
			// columns apply. meta.owner_period keeps the raw toggle id so the
			// active button still highlights.
			$owner_matrix_base = isset($owner_period['base']) ? $owner_period['base'] : $owner_period['period'];
			// Team Lead: restrict the matrix (rows, benchmark AND Agent Score) to
			// the lead's own team — every active admin sharing their admin.TeamID.
			// Owner passes null => company-wide, unchanged.
			$restrict_ids = null;
			if($is_team_lead) {
				$this->load->helper('team_scope');
				$restrict_ids = team_member_ids_from_db($this->db, $admin_id);
			}
			$tables['owner_agent_matrix'] = $this->owner_agent_matrix(
				$owner_period['start_date'], $owner_period['end_date'], $owner_matrix_base, $restrict_ids
			);
		}

		// ---------- OP ----------
		// Owner is scoped to lead-conversion cards only, so they no longer
		// trigger this block.
		if($is_op) {
			// Team scope: every OP card except "Pending BC" is scoped to the BCs
			// of the user's whole Team (everyone sharing admin.TeamID) as TC1
			// (booking.SalesAgent) — so e.g. a lead and their members all see each
			// other's BCs. $op_sa_in is the inlined "SalesAgent IN (...)" predicate
			// (admin ids are ints from the admin table, so safe to inline);
			// $op_team_csv backs the drill-down links (sales_agent=<team csv>), so
			// card counts and the filtered listing stay in agreement.
			$this->load->helper('team_scope');
			$op_team_ids = team_member_ids_from_db($this->db, $admin_id);
			$op_team_csv = implode(',', $op_team_ids);
			$op_sa_in    = "booking.SalesAgent IN ({$op_team_csv})";

			// Each scoped card's drill-down link carries sales_agent=<team csv> so
			// the listing filters to the same TC1 set and the count/list agree.
			// The default booking listing (no sales_agent param) still shows all
			// BCs; the "Pending BC" card link omits it and stays team-wide.
			$op_link = function($params) use ($base, $qs, $op_team_csv) {
				$params['sales_agent'] = $op_team_csv;
				return $base . $qs($params);
			};

			if(!isset($cards['bc_week_month'])) {
				$row = $this->db->query(
					"SELECT
					   SUM(CASE WHEN CAST(InsertDate AS DATE) BETWEEN ? AND ? THEN 1 ELSE 0 END) AS month_cnt,
					   SUM(CASE WHEN CAST(InsertDate AS DATE) BETWEEN ? AND ? THEN 1 ELSE 0 END) AS week_cnt
					 FROM booking
					 WHERE BookingConfirmationTitle='BOOKING CONFIRMATION'
					   AND CancelStatus='N' AND Status!='N'
					   AND {$op_sa_in}",
					array($month_start, $month_end, $week_start, $week_end)
				)->row();
				$cards['bc_week_month'] = array(
					'week'       => (int)$row->week_cnt,
					'month'      => (int)$row->month_cnt,
					'link_month' => $op_link(array('booking_date' => $fmt_dmy($month_start) . ' - ' . $fmt_dmy($month_end))),
					'link_week'  => $op_link(array('booking_date' => $fmt_dmy($week_start) . ' - ' . $fmt_dmy($week_end))),
				);
			}

			// Conversion Time (this month, company-wide). How long a booking took
			// to go from being saved as draft (SAD) to reaching PENDING PAYMENT
			// (P) — the same SAD -> first-P gap as the TC "Draft -> Payment Time"
			// card, but across every agent. "Avg Conversion Time" averages the
			// gap over drafts *saved this month* (that have since reached
			// payment); "Slow Conversions (> 24h)" counts the ones that took
			// longer than a day and links to them so OP can analyse why. The
			// window keys off the SAD anchor and is cut off at today, not the
			// future month-end. Card + drill-down (?slow_conversion=1) share one
			// definition so the slow count and the listing agree; lead_month
			// carries the same draft-save month to the listing filter.
			$this->load->helper(array('submitted_payment_response', 'response_time'));
			// Scoped to the OP team's TC1 slots (b.SalesAgent) so the card agrees
			// with the team-scoped slow-conversion drill-down (sales_agent=<team>).
			$ct_row = $this->db->query(
				submitted_payment_conversion_summary_sql(86400, $op_team_ids),
				array($month_start . ' 00:00:00', $today . ' 23:59:59')
			)->row();
			$ct_n    = !empty($ct_row) ? (int) $ct_row->n : 0;
			$ct_secs = ($ct_n > 0 && $ct_row->avg_seconds !== null)
				? (int) round((float) $ct_row->avg_seconds) : null;
			$cards['conversion_time_month'] = array(
				'value'   => format_response_duration($ct_secs),
				'count'   => $ct_n,
				'seconds' => $ct_secs,
			);
			$cards['slow_conversion_month'] = array(
				'count' => !empty($ct_row) ? (int) $ct_row->slow_n : 0,
				'link'  => $op_link(array(
					'slow_conversion' => 1,
					'lead_month'      => date('Y-m', strtotime($month_start)),
					'status'          => 'A',
				)),
			);

			$row = $this->db->query(
				"SELECT
				   SUM(CASE WHEN Status='P'   THEN 1 ELSE 0 END) AS s_p,
				   SUM(CASE WHEN Status='PBO' THEN 1 ELSE 0 END) AS s_pbo,
				   SUM(CASE WHEN Status='PGL' THEN 1 ELSE 0 END) AS s_pgl,
				   SUM(CASE WHEN Status='PTV' THEN 1 ELSE 0 END) AS s_ptv
				 FROM booking
				 WHERE BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND CancelStatus='N'
				   AND Status IN ('P','PBO','PGL','PTV')
				   AND StartDate BETWEEN ? AND ?
				   AND {$op_sa_in}",
				array($next7_start, $next7_end)
			)->row();
			$un_op_p   = (int)$row->s_p;
			$un_op_pbo = (int)$row->s_pbo;
			$un_op_pgl = (int)$row->s_pgl;
			$un_op_ptv = (int)$row->s_ptv;
			$cards['upcoming_travel_not_ready_op'] = array(
				'count'  => $un_op_p + $un_op_pbo + $un_op_pgl + $un_op_ptv,
				'by_p'   => $un_op_p,
				'by_pbo' => $un_op_pbo,
				'by_pgl' => $un_op_pgl,
				'by_ptv' => $un_op_ptv,
				'link'   => $op_link(array(
					'upcoming_not_ready' => 1,
					'travel_date'        => $fmt_dmy($next7_start) . ' - ' . $fmt_dmy($next7_end),
				)),
			);

			// Team-wide "not yet ready" over the cumulative 14-day window.
			$row = $this->db->query(
				"SELECT
				   SUM(CASE WHEN Status='P'   THEN 1 ELSE 0 END) AS s_p,
				   SUM(CASE WHEN Status='PBO' THEN 1 ELSE 0 END) AS s_pbo,
				   SUM(CASE WHEN Status='PGL' THEN 1 ELSE 0 END) AS s_pgl,
				   SUM(CASE WHEN Status='PTV' THEN 1 ELSE 0 END) AS s_ptv
				 FROM booking
				 WHERE BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND CancelStatus='N'
				   AND Status IN ('P','PBO','PGL','PTV')
				   AND StartDate BETWEEN ? AND ?
				   AND {$op_sa_in}",
				array($next14_start, $next14_end)
			)->row();
			$un_op14_p   = (int)$row->s_p;
			$un_op14_pbo = (int)$row->s_pbo;
			$un_op14_pgl = (int)$row->s_pgl;
			$un_op14_ptv = (int)$row->s_ptv;
			$cards['upcoming_travel_not_ready_op_14'] = array(
				'count'  => $un_op14_p + $un_op14_pbo + $un_op14_pgl + $un_op14_ptv,
				'by_p'   => $un_op14_p,
				'by_pbo' => $un_op14_pbo,
				'by_pgl' => $un_op14_pgl,
				'by_ptv' => $un_op14_ptv,
				'link'   => $op_link(array(
					'upcoming_not_ready' => 1,
					'travel_date'        => $fmt_dmy($next14_start) . ' - ' . $fmt_dmy($next14_end),
				)),
			);

			// Shares guest_list_submitted_where() with the drill-down listing
			// (?guest_list_status=submitted) so the card and the list always agree.
			$row = $this->db->query(
				"SELECT COUNT(*) AS cnt FROM booking WHERE " . guest_list_submitted_where() . " AND {$op_sa_in}"
			)->row();
			$cards['gl_submitted'] = array(
				'count' => (int)$row->cnt,
				'link'  => $op_link(array('guest_list_status' => 'submitted')),
			);

			// ---------- OP operational queue cards (team-wide) ----------
			// Pending BC (PB) — bookings parked at "Pending BC", waiting to be
			// confirmed. status=PB mirrors the shared status filter so the card
			// count and the drill-down listing agree row-for-row.
			$row = $this->db->query(
				"SELECT COUNT(*) AS cnt FROM booking
				 WHERE CancelStatus='N' AND Status='PB'"
			)->row();
			$cards['pending_bc_op'] = array(
				'count' => (int)$row->cnt,
				'link'  => $base . $qs(array('status' => 'PB')),
			);

			// Pending BC Confirmation (PBC) — bookings awaiting BC confirmation.
			$row = $this->db->query(
				"SELECT COUNT(*) AS cnt FROM booking
				 WHERE CancelStatus='N' AND Status='PBC'
				   AND {$op_sa_in}"
			)->row();
			$cards['pending_bc_confirmation_op'] = array(
				'count' => (int)$row->cnt,
				'link'  => $op_link(array('status' => 'PBC')),
			);

			// Travelling Today (regardless of status) — BCs whose travel STARTS
			// today, any workflow status. Same shape as Travelling Tomorrow below,
			// scoped to today's departure date.
			$row = $this->db->query(
				"SELECT COUNT(*) AS cnt FROM booking
				 WHERE BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND CancelStatus='N' AND Status!='N'
				   AND StartDate BETWEEN ? AND ?
				   AND {$op_sa_in}",
				array($today, $today)
			)->row();
			$cards['travel_today_op'] = array(
				'count' => (int)$row->cnt,
				'link'  => $op_link(array(
					'travel_start_date'          => $fmt_dmy($today) . ' - ' . $fmt_dmy($today),
					'status'                     => 'A',
					'booking_confirmation_title' => 'BOOKING CONFIRMATION',
				)),
			);

			// Travelling Tomorrow (regardless of status) — BCs whose travel STARTS
			// tomorrow, any workflow status. Drill-down: status=A drops the list's
			// default AfterSalesService='PENDING' gate; travel_start_date scopes
			// StartDate to tomorrow exactly (the generic travel_date overlap clause
			// would also pull in trips merely spanning tomorrow, breaking parity);
			// booking_confirmation_title matches the card's confirmations-only scope.
			$tomorrow = $next7_start; // date('Y-m-d', strtotime('+1 day'))
			$row = $this->db->query(
				"SELECT COUNT(*) AS cnt FROM booking
				 WHERE BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND CancelStatus='N' AND Status!='N'
				   AND StartDate BETWEEN ? AND ?
				   AND {$op_sa_in}",
				array($tomorrow, $tomorrow)
			)->row();
			$cards['travel_tomorrow_op'] = array(
				'count' => (int)$row->cnt,
				'link'  => $op_link(array(
					'travel_start_date'          => $fmt_dmy($tomorrow) . ' - ' . $fmt_dmy($tomorrow),
					'status'                     => 'A',
					'booking_confirmation_title' => 'BOOKING CONFIRMATION',
				)),
			);

			// Travelling Tomorrow & NOT Pending Travel (red card) — same departing-
			// tomorrow set as above but excluding the already-ready Pending Travel
			// (PT) BCs. These are the urgent gap: guests travel tomorrow yet the BC
			// is not flagged "Pending Travel". exclude_status=PT removes PT from the
			// drill-down so it matches the card count.
			$row = $this->db->query(
				"SELECT COUNT(*) AS cnt FROM booking
				 WHERE BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND CancelStatus='N' AND Status!='N' AND Status!='PT'
				   AND StartDate BETWEEN ? AND ?
				   AND {$op_sa_in}",
				array($tomorrow, $tomorrow)
			)->row();
			$cards['travel_tomorrow_not_ready_op'] = array(
				'count' => (int)$row->cnt,
				'link'  => $op_link(array(
					'travel_start_date'          => $fmt_dmy($tomorrow) . ' - ' . $fmt_dmy($tomorrow),
					'status'                     => 'A',
					'exclude_status'             => 'PT',
					'booking_confirmation_title' => 'BOOKING CONFIRMATION',
				)),
			);

			// Travel Completed - Pending Review (team-wide) — BCs whose travel has
			// ended (Status='Y') with after-sales review still pending. status=PR
			// mirrors the PR code in booking_status_filter_helper.
			$row = $this->db->query(
				"SELECT COUNT(*) AS cnt FROM booking
				 WHERE BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND CancelStatus='N'
				   AND AfterSalesService='PENDING'
				   AND Status='Y'
				   AND {$op_sa_in}"
			)->row();
			$cards['pending_review_op'] = array(
				'count' => (int)$row->cnt,
				'link'  => $op_link(array('status' => 'PR')),
			);

			// Pending Insurance Checklist — bookings with an active line whose
			// product carries an "Insurance" package_checklist that hasn't yet
			// been ticked. disable_checklist_payment_out=0 mirrors the
			// modal/filter rule (see CLAUDE memory feedback_checklist_filter)
			// so the card and the drill-down list agree row-for-row.
			// Scoped to travel from 1 March of the current year onwards (same
			// March-1 floor convention as Supplier Due Soon below) so the queue
			// stays actionable instead of dragging in long-finished trips. Uses
			// the SAME range-overlap predicate as the generic ?travel_date
			// filter (Booking_Model::filter_bookings) with a far-future upper
			// bound, so the card and its drill-down stay in exact agreement.
			// Always drop BCs whose travel is already finished (booking.Status='Y'
			// — both COMPLETE and PENDING-REVIEW after-sales states) so the queue
			// shows only still-actionable trips. The drill-down carries
			// ?exclude_finished=1 (honoured by Booking_Model::apply_booking_filters)
			// so it stays in exact agreement with this count.
			$insurance_finished_clause = " AND booking.Status != 'Y'";
			$insurance_window_start = date('Y') . '-03-01';
			$insurance_window_end   = date('Y', strtotime('+5 years')) . '-12-31';
			$insurance_ids = $this->db
				->select('ID')
				->from('package_checklist')
				->like('name', 'insurance', 'both')
				->get()
				->result_array();
			$insurance_ids = array_map(function($r){ return (int)$r['ID']; }, $insurance_ids);
			if(!empty($insurance_ids)) {
				$ids_list = implode(',', $insurance_ids);
				$json_contains_or = implode(' OR ', array_map(function($id) {
					return "JSON_CONTAINS(ppc.package_checklist_json, '{$id}')";
				}, $insurance_ids));
				$row = $this->db->query(
					"SELECT COUNT(DISTINCT booking.BookingID) AS cnt
					 FROM booking
					 WHERE booking.BookingConfirmationTitle='BOOKING CONFIRMATION'
					   AND booking.CancelStatus='N' AND booking.Status!='N'{$insurance_finished_clause}
					   AND ((booking.StartDate <= ? AND booking.EndDate >= ?)
					        OR (booking.StartDate >= ? AND booking.StartDate <= ?)
					        OR (booking.EndDate >= ? AND booking.EndDate <= ?))
					   AND booking.BookingID IN (
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
					   )
					   AND {$op_sa_in}",
					array(
						$insurance_window_start, $insurance_window_end,
						$insurance_window_start, $insurance_window_end,
						$insurance_window_start, $insurance_window_end,
					)
				)->row();
				$insurance_count = (int)$row->cnt;
			} else {
				$insurance_count = 0;
			}
			// status=A + the BOOKING CONFIRMATION title reproduce the card's own
			// row filters (CancelStatus='N', Status!='N', confirmations only) and,
			// crucially, suppress the booking list's default AfterSalesService='PENDING'
			// gate (Booking_Model::apply_booking_filters applies it whenever no
			// `status` is present). Without status=A the drill-down silently drops
			// the already-completed BCs the card counts, so 56 on the card would
			// open as 11 in the list. Card is the source of truth.
			$cards['insurance_pending'] = array(
				'count'            => $insurance_count,
				'link'             => $op_link(array(
					'checklist_filter'           => implode(',', $insurance_ids),
					'travel_date'                => $fmt_dmy($insurance_window_start) . ' - ' . $fmt_dmy($insurance_window_end),
					'status'                     => 'A',
					'booking_confirmation_title' => 'BOOKING CONFIRMATION',
					'exclude_finished'           => 1,
				)),
			);

			// Pending Ferry Transfer Checklist (travel this & next month) —
			// same mechanics as Pending Insurance Checklist (an active line whose
			// product carries the checklist, with no completion record yet, and
			// disable_checklist_payment_out=0), but scoped to BCs whose travel
			// falls in this month or next. The travel window uses the SAME
			// range-overlap predicate as the generic ?travel_date filter
			// (Booking_Model::filter_bookings), so this card and its drill-down
			// (?checklist_filter=<ferry ids>&travel_date=<window>) agree
			// row-for-row. Window: 1st of this month → last day of next month.
			$ferry_window_start = $month_start;                                        // 1st of this month
			$ferry_window_end   = date('Y-m-t', strtotime('first day of next month')); // last day of next month
			$ferry_ids = $this->db
				->select('ID')
				->from('package_checklist')
				->like('name', 'Book Ferry Transfer', 'both')
				->get()
				->result_array();
			$ferry_ids = array_map(function($r){ return (int)$r['ID']; }, $ferry_ids);
			if(!empty($ferry_ids)) {
				$ids_list = implode(',', $ferry_ids);
				$json_contains_or = implode(' OR ', array_map(function($id) {
					return "JSON_CONTAINS(ppc.package_checklist_json, '{$id}')";
				}, $ferry_ids));
				$row = $this->db->query(
					"SELECT COUNT(DISTINCT booking.BookingID) AS cnt
					 FROM booking
					 WHERE booking.BookingConfirmationTitle='BOOKING CONFIRMATION'
					   AND booking.CancelStatus='N' AND booking.Status!='N'
					   AND ((booking.StartDate <= ? AND booking.EndDate >= ?)
					        OR (booking.StartDate >= ? AND booking.StartDate <= ?)
					        OR (booking.EndDate >= ? AND booking.EndDate <= ?))
					   AND booking.BookingID IN (
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
					   )
					   AND {$op_sa_in}",
					array(
						$ferry_window_start, $ferry_window_end,
						$ferry_window_start, $ferry_window_end,
						$ferry_window_start, $ferry_window_end,
					)
				)->row();
				$ferry_count = (int)$row->cnt;
			} else {
				$ferry_count = 0;
			}
			// status=A + the BOOKING CONFIRMATION title reproduce the card's row
			// filters and suppress the list's default AfterSalesService='PENDING'
			// gate, so the drill-down matches the card row-for-row even when a
			// completed BC falls inside the travel window (same fix as insurance).
			$cards['ferry_pending'] = array(
				'count' => $ferry_count,
				'link'  => $op_link(array(
					'checklist_filter'           => implode(',', $ferry_ids),
					'travel_date'                => $fmt_dmy($ferry_window_start) . ' - ' . $fmt_dmy($ferry_window_end),
					'status'                     => 'A',
					'booking_confirmation_title' => 'BOOKING CONFIRMATION',
				)),
			);

			// Supplier Pay-out Due Soon — the immediate payout horizon,
			// bucketed into Overdue (deadline already passed), Today, and
			// Tomorrow so OP can triage by urgency. All three buckets share
			// the same row filters as Supplier Overdue; only the Deadline
			// predicate differs. The per-supplier table spans the whole
			// window (overdue..tomorrow), earliest deadline first.
			$due_soon_end   = date('Y-m-d', strtotime('+1 day'));  // tomorrow — window upper bound
			$due_soon_start = date('Y') . '-03-01';                // overdue lookback floor: 1 March, current year
			$row = $this->db->query(
				"SELECT
				    SUM(CASE WHEN payment.Deadline <  ? THEN 1 ELSE 0 END) AS overdue_cnt,
				    COALESCE(SUM(CASE WHEN payment.Deadline <  ? THEN payment.Debit ELSE 0 END), 0) AS overdue_due,
				    SUM(CASE WHEN payment.Deadline =  ? THEN 1 ELSE 0 END) AS today_cnt,
				    COALESCE(SUM(CASE WHEN payment.Deadline =  ? THEN payment.Debit ELSE 0 END), 0) AS today_due,
				    SUM(CASE WHEN payment.Deadline =  ? THEN 1 ELSE 0 END) AS tomorrow_cnt,
				    COALESCE(SUM(CASE WHEN payment.Deadline =  ? THEN payment.Debit ELSE 0 END), 0) AS tomorrow_due
				 FROM payment
				 JOIN booking b ON b.BookingID = payment.BookingID
				 WHERE payment.Status = 'P'
				   AND payment.Deadline BETWEEN ? AND ?
				   AND payment.Debit > 0
				   AND payment.Type LIKE 'SUPPLIER PAYMENT%'
				   AND payment.SupplierID IS NOT NULL
				   AND b.SalesAgent IN ({$op_team_csv})",
				array($today, $today, $today, $today, $due_soon_end, $due_soon_end, $due_soon_start, $due_soon_end)
			)->row();
			// status=A (active: not deleted, not cancelled) scopes the linked list
			// to real BCs and, crucially, suppresses the no-status default that
			// would otherwise force AfterSalesService=PENDING and hide most matches.
			$cards['supplier_due_soon'] = array(
				'overdue'  => array('count' => (int)$row->overdue_cnt,  'total_due' => $money($row->overdue_due),  'link' => $op_link(array('supplier_payout' => 'overdue',  'status' => 'A'))),
				'today'    => array('count' => (int)$row->today_cnt,    'total_due' => $money($row->today_due),    'link' => $op_link(array('supplier_payout' => 'today',    'status' => 'A'))),
				'tomorrow' => array('count' => (int)$row->tomorrow_cnt, 'total_due' => $money($row->tomorrow_due), 'link' => $op_link(array('supplier_payout' => 'tomorrow', 'status' => 'A'))),
			);

			$due_rows = $this->db->query(
				"SELECT supplier.SupplierID AS sid, supplier.Name AS name,
				        COUNT(*) AS cnt,
				        COALESCE(SUM(payment.Debit), 0) AS total_due,
				        MIN(payment.Deadline) AS earliest_deadline
				 FROM payment
				 JOIN supplier ON supplier.SupplierID = payment.SupplierID
				 JOIN booking b ON b.BookingID = payment.BookingID
				 WHERE payment.Status = 'P'
				   AND payment.Deadline BETWEEN ? AND ?
				   AND payment.Debit > 0
				   AND payment.Type LIKE 'SUPPLIER PAYMENT%'
				   AND b.SalesAgent IN ({$op_team_csv})
				 GROUP BY supplier.SupplierID, supplier.Name
				 ORDER BY MIN(payment.Deadline) ASC, total_due DESC
				 LIMIT 5",
				array($due_soon_start, $due_soon_end)
			)->result();
			$due_out = array();
			foreach($due_rows as $r) {
				$due_out[] = array(
					'supplier_id'       => (int)$r->sid,
					'name'              => $r->name,
					'count'             => (int)$r->cnt,
					'total_due'         => $money($r->total_due),
					'earliest_deadline' => $r->earliest_deadline ? $fmt_dmy($r->earliest_deadline) : '-',
				);
			}
			$tables['supplier_due_soon'] = $due_out;

			// Payment From Customer Due Soon — the money-IN mirror of Supplier
			// Pay-out Due Soon, bucketed into Overdue / Today / Tomorrow so OP can
			// chase customer payments by urgency. Customer deadlines live on the
			// BOOKING (not the payment row), so we derive the operative "next due"
			// deadline per BC and the outstanding balance still owed:
			//   - Status 'PBC' (pending BC confirmation) / 'P' (nothing received):
			//       deposit first -> COALESCE(DepositDeadline, FullPaymentDeadline)
			//   - Status 'PP' (deposit in): balance -> FullPaymentDeadline
			//   - outstanding = NetTotal - approved customer credits (Status='Y',
			//     Credit>0, excluding AGENT COMMISSION FROM SUPPLIER)
			// Mirrors the booking-list PO / P / PP status filters so the clickable
			// drill-down (?customer_payment=...&status=A) agrees with the card.
			$cust_due_end   = date('Y-m-d', strtotime('+1 day'));  // tomorrow — window upper bound
			$cust_due_start = date('Y') . '-03-01';                // overdue lookback floor: 1 March, current year
			// 3pm cutoff: a today deadline is only "today" while the money-in
			// can still be approved (before 15:00); at/after 15:00 it lapses
			// into "overdue" (overdue widens to nd <= today, today empties).
			// Empty-bucket sentinel must be a MySQL-valid DATE ('0000-00-00'
			// trips strict mode / NO_ZERO_DATE with error 1525); '1000-01-01'
			// is the min valid date and falls outside the window, never matching.
			$cust_after3pm  = ((int) date('H') >= 15);
			$cust_overdue_op = $cust_after3pm ? '<=' : '<';
			$cust_today_val  = $cust_after3pm ? '1000-01-01' : $today;
			$cust_nd  = "(CASE WHEN booking.Status IN ('PBC','P') THEN COALESCE(booking.DepositDeadline, booking.FullPaymentDeadline) ELSE booking.FullPaymentDeadline END)";
			$cust_out = "(booking.NetTotal - " . booking_settled_credit_sql() . ")";
			$row = $this->db->query(
				"SELECT
				    SUM(CASE WHEN t.nd {$cust_overdue_op} ? THEN 1 ELSE 0 END) AS overdue_cnt,
				    COALESCE(SUM(CASE WHEN t.nd {$cust_overdue_op} ? THEN t.outstanding ELSE 0 END), 0) AS overdue_due,
				    SUM(CASE WHEN t.nd =  ? THEN 1 ELSE 0 END) AS today_cnt,
				    COALESCE(SUM(CASE WHEN t.nd =  ? THEN t.outstanding ELSE 0 END), 0) AS today_due,
				    SUM(CASE WHEN t.nd =  ? THEN 1 ELSE 0 END) AS tomorrow_cnt,
				    COALESCE(SUM(CASE WHEN t.nd =  ? THEN t.outstanding ELSE 0 END), 0) AS tomorrow_due
				 FROM (
				    SELECT {$cust_nd} AS nd, {$cust_out} AS outstanding
				    FROM booking
				    WHERE booking.CancelStatus = 'N'
				      AND booking.Status IN ('PBC','P','PP')
				      AND {$op_sa_in}
				 ) t
				 WHERE t.nd BETWEEN ? AND ?
				   AND t.outstanding > 0",
				array($today, $today, $cust_today_val, $cust_today_val, $cust_due_end, $cust_due_end, $cust_due_start, $cust_due_end)
			)->row();
			// status=A scopes the linked list to live BCs and suppresses the
			// no-status default (AfterSalesService='PENDING') that would otherwise
			// hide most matches — same reasoning as the supplier payout card.
			$cards['customer_payment_due_soon'] = array(
				'overdue'  => array('count' => (int)$row->overdue_cnt,  'total_due' => $money($row->overdue_due),  'link' => $op_link(array('customer_payment' => 'overdue',  'status' => 'A'))),
				'today'    => array('count' => (int)$row->today_cnt,    'total_due' => $money($row->today_due),    'link' => $op_link(array('customer_payment' => 'today',    'status' => 'A'))),
				'tomorrow' => array('count' => (int)$row->tomorrow_cnt, 'total_due' => $money($row->tomorrow_due), 'link' => $op_link(array('customer_payment' => 'tomorrow', 'status' => 'A'))),
			);

			$cust_rows = $this->db->query(
				"SELECT t.BookingNumber AS booking_number, t.Customer AS customer,
				        t.nd AS earliest_deadline, t.outstanding AS total_due
				 FROM (
				    SELECT booking.BookingNumber AS BookingNumber, booking.Customer AS Customer,
				           {$cust_nd} AS nd, {$cust_out} AS outstanding
				    FROM booking
				    WHERE booking.CancelStatus = 'N'
				      AND booking.Status IN ('PBC','P','PP')
				      AND {$op_sa_in}
				 ) t
				 WHERE t.nd BETWEEN ? AND ?
				   AND t.outstanding > 0
				 ORDER BY t.nd ASC, t.outstanding DESC
				 LIMIT 5",
				array($cust_due_start, $cust_due_end)
			)->result();
			$cust_out_rows = array();
			foreach($cust_rows as $r) {
				$cust_out_rows[] = array(
					'booking_number'    => $r->booking_number,
					'customer'          => $r->customer,
					'total_due'         => $money($r->total_due),
					'earliest_deadline' => $r->earliest_deadline ? $fmt_dmy($r->earliest_deadline) : '-',
				);
			}
			$tables['customer_payment_due_soon'] = $cust_out_rows;

			// Supplier Pay-out Checklist Due Soon — the checklist counterpart to
			// Supplier Pay-out Due Soon above. That card reads the payment table
			// (payouts already created); this one flags BCs whose "Payment Out To
			// Supplier (full|deposit)" CHECKLIST is not ticked yet, bucketed by the
			// payout deadline on the line (booking_product.PaymentOutSupplierFull /
			// PaymentOutSupplierDeposit). Same signal the cron reminders use
			// (Cronjob_Model::get_bookings_with_supplier_date). Count only — no
			// payment record exists yet, so no firm RM amount to total.
			$cp_due_end   = date('Y-m-d', strtotime('+1 day'));  // tomorrow — window upper bound
			$cp_due_start = date('Y') . '-03-01';                // overdue lookback floor: 1 March, current year
			$full_pc = $this->db->select('ID')->from('package_checklist')->like('name', 'Payment Out To Supplier (full)', 'both')->get()->row();
			$dep_pc  = $this->db->select('ID')->from('package_checklist')->like('name', 'Payment Out To Supplier (deposit)', 'both')->get()->row();
			$cp_full_id = $full_pc ? (int)$full_pc->ID : 0;
			$cp_dep_id  = $dep_pc  ? (int)$dep_pc->ID  : 0;

			// One qualifying "due line" branch per payout checklist. A line counts
			// when it is active, its product is non-child/infant, the matching
			// deadline is set, the booking is a live BC, and no completion row
			// exists for that checklist on that line. Assignment rule matches the
			// booking checklist modal: the FULL checklist must be assigned to the
			// product; the DEPOSIT checklist is auto-added by the deposit date, so
			// a deposit line qualifies by its date alone (see
			// checklist_payout_assignment_join_sql).
			$this->load->helper('checklist_payout');
			$cp_branch = function($checklist_id, $date_col) use ($op_team_csv) {
				return "SELECT bp.BookingID AS bid, p.SupplierID AS sid, bp.{$date_col} AS dl"
					. " FROM booking_product bp"
					. " JOIN product p ON p.ProductID = bp.ProductID AND p.is_child_or_infant = 0"
					. checklist_payout_assignment_join_sql($checklist_id, $date_col)
					. " JOIN booking b ON b.BookingID = bp.BookingID"
					. " WHERE bp.Status = 'Y' AND bp.disable_checklist_payment_out = 0"
					. " AND bp.{$date_col} IS NOT NULL"
					. " AND b.BookingConfirmationTitle = 'BOOKING CONFIRMATION'"
					. " AND b.CancelStatus = 'N' AND b.Status != 'N'"
					. " AND b.SalesAgent IN ({$op_team_csv})"
					. " AND NOT EXISTS (SELECT 1 FROM booking_checklist_completion bcc"
					. " WHERE bcc.booking_id = bp.BookingID AND bcc.product_id = bp.ProductID"
					. " AND bcc.package_checklist_id = {$checklist_id})";
			};
			$cp_branches = array();
			if($cp_full_id) { $cp_branches[] = $cp_branch($cp_full_id, 'PaymentOutSupplierFull'); }
			if($cp_dep_id)  { $cp_branches[] = $cp_branch($cp_dep_id,  'PaymentOutSupplierDeposit'); }

			if(!empty($cp_branches)) {
				$cp_union = implode("\nUNION ALL\n", $cp_branches);
				$row = $this->db->query(
					"SELECT
					    COUNT(DISTINCT CASE WHEN dl <  ? THEN bid END) AS overdue_cnt,
					    COUNT(DISTINCT CASE WHEN dl =  ? THEN bid END) AS today_cnt,
					    COUNT(DISTINCT CASE WHEN dl =  ? THEN bid END) AS tomorrow_cnt
					 FROM ({$cp_union}) due
					 WHERE dl BETWEEN ? AND ?",
					array($today, $today, $cp_due_end, $cp_due_start, $cp_due_end)
				)->row();
			} else {
				$row = (object)array('overdue_cnt' => 0, 'today_cnt' => 0, 'tomorrow_cnt' => 0);
			}
			// status=A (active: not deleted, not cancelled) + the confirmation title
			// reproduce the card's own row filters and suppress the booking list's
			// default AfterSalesService='PENDING' gate, so the drill-down matches
			// the card row-for-row (see Booking_Model::apply_checklist_payout_filter).
			$cp_link = function($bucket) use ($op_link) {
				return $op_link(array(
					'checklist_payout'           => $bucket,
					'status'                     => 'A',
					'booking_confirmation_title' => 'BOOKING CONFIRMATION',
				));
			};
			$cards['checklist_payout_due_soon'] = array(
				'overdue'  => array('count' => (int)$row->overdue_cnt,  'link' => $cp_link('overdue')),
				'today'    => array('count' => (int)$row->today_cnt,    'link' => $cp_link('today')),
				'tomorrow' => array('count' => (int)$row->tomorrow_cnt, 'link' => $cp_link('tomorrow')),
			);

			$cp_out = array();
			if(!empty($cp_branches)) {
				$cp_rows = $this->db->query(
					"SELECT supplier.SupplierID AS sid, supplier.Name AS name,
					        COUNT(*) AS cnt,
					        MIN(due.dl) AS earliest_deadline
					 FROM ({$cp_union}) due
					 JOIN supplier ON supplier.SupplierID = due.sid
					 WHERE due.dl BETWEEN ? AND ?
					 GROUP BY supplier.SupplierID, supplier.Name
					 ORDER BY MIN(due.dl) ASC, COUNT(*) DESC
					 LIMIT 5",
					array($cp_due_start, $cp_due_end)
				)->result();
				foreach($cp_rows as $r) {
					$cp_out[] = array(
						'supplier_id'       => (int)$r->sid,
						'name'              => $r->name,
						'count'             => (int)$r->cnt,
						'earliest_deadline' => $r->earliest_deadline ? $fmt_dmy($r->earliest_deadline) : '-',
					);
				}
			}
			$tables['checklist_payout_due_soon'] = $cp_out;

			$dest_rows = $this->db->query(
				"SELECT category.Name AS destination, category.CategoryID AS id,
				        COUNT(BookingID) AS cnt, COALESCE(SUM(NetTotal),0) AS total
				 FROM booking
				 LEFT JOIN category ON category.CategoryID = booking.Destination
				 WHERE booking.BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND CancelStatus='N' AND booking.Status!='N'
				   AND CAST(booking.InsertDate AS DATE) BETWEEN ? AND ?
				   AND {$op_sa_in}
				 GROUP BY category.Name, category.CategoryID
				 ORDER BY cnt DESC
				 LIMIT 5",
				array($month_start, $month_end)
			)->result();
			$dest_out = array();
			foreach($dest_rows as $r) {
				$dest_out[] = array(
					'destination' => $r->destination,
					'count'       => (int)$r->cnt,
					'total'       => $money($r->total),
					'link'        => $op_link(array(
						'destination'  => $r->id,
						'booking_date' => $fmt_dmy($month_start) . ' - ' . $fmt_dmy($month_end),
					)),
				);
			}
			$tables['destination_sales'] = $dest_out;

			// OP Top Products (Month) — same query Finance already runs; OP
			// historically only saw destinations. Surfacing products here lets
			// OP spot which package codes are driving the workload. Scoped to the
			// OP user's own TC1 (booking.SalesAgent) BCs like the other OP cards.
			$prod_rows = $this->db->query(
				"SELECT product.ProductCode AS code, product.Name AS name,
				        COALESCE(SUM(booking_product.Total),0) AS total,
				        COALESCE(SUM(booking_product.Quantity),0) AS qty
				 FROM booking
				 LEFT JOIN booking_product ON booking_product.BookingID = booking.BookingID
				 LEFT JOIN product ON product.ProductID = booking_product.ProductID
				 WHERE booking.BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND booking.CancelStatus='N' AND booking.Status!='N'
				   AND booking_product.Status='Y'
				   AND CAST(booking.InsertDate AS DATE) BETWEEN ? AND ?
				   AND product.ProductID IS NOT NULL
				   AND {$op_sa_in}
				 GROUP BY product.ProductID, product.ProductCode, product.Name
				 ORDER BY total DESC
				 LIMIT 5",
				array($month_start, $month_end)
			)->result();
			$prod_out = array();
			foreach($prod_rows as $r) {
				$prod_out[] = array(
					'code'  => $r->code,
					'name'  => $r->name,
					'qty'   => (int)$r->qty,
					'total' => $money($r->total),
				);
			}
			$tables['product_sales'] = $prod_out;
		}

		// ---------- Finance ----------
		// Owner is scoped to lead-conversion cards only, so they no longer
		// trigger this block.
		if($is_finance) {
			$row = $this->db->query(
				"SELECT
				   COALESCE(SUM(CASE WHEN Date BETWEEN ? AND ? THEN Credit ELSE 0 END),0) AS day_total,
				   COALESCE(SUM(CASE WHEN Date BETWEEN ? AND ? THEN Credit ELSE 0 END),0) AS week_total,
				   COALESCE(SUM(CASE WHEN Date BETWEEN ? AND ? THEN Credit ELSE 0 END),0) AS month_total,
				   SUM(CASE WHEN Date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS day_count,
				   SUM(CASE WHEN Date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS week_count,
				   SUM(CASE WHEN Date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS month_count
				 FROM payment
				 WHERE Status='Y' AND Credit > 0
				   AND (Type IS NULL OR Type != 'AGENT COMMISSION FROM SUPPLIER')",
				array(
					$today, $today, $week_start, $week_end, $month_start, $month_end,
					$today, $today, $week_start, $week_end, $month_start, $month_end,
				)
			)->row();
			$cards['payment_in_dwm'] = array(
				'day'         => $money($row->day_total),
				'week'        => $money($row->week_total),
				'month'       => $money($row->month_total),
				'day_count'   => (int)$row->day_count,
				'week_count'  => (int)$row->week_count,
				'month_count' => (int)$row->month_count,
				'day_raw'     => (float)$row->day_total,
				'week_raw'    => (float)$row->week_total,
				'month_raw'   => (float)$row->month_total,
			);

			if(!isset($tables['destination_sales'])) {
				$dest_rows = $this->db->query(
					"SELECT category.Name AS destination, category.CategoryID AS id,
					        COUNT(BookingID) AS cnt, COALESCE(SUM(NetTotal),0) AS total
					 FROM booking
					 LEFT JOIN category ON category.CategoryID = booking.Destination
					 WHERE booking.BookingConfirmationTitle='BOOKING CONFIRMATION'
					   AND CancelStatus='N' AND booking.Status!='N'
					   AND CAST(booking.InsertDate AS DATE) BETWEEN ? AND ?
					 GROUP BY category.Name, category.CategoryID
					 ORDER BY total DESC
					 LIMIT 5",
					array($month_start, $month_end)
				)->result();
				$dest_out = array();
				foreach($dest_rows as $r) {
					$dest_out[] = array(
						'destination' => $r->destination,
						'count'       => (int)$r->cnt,
						'total'       => $money($r->total),
						'link'        => $base . $qs(array(
							'destination'  => $r->id,
							'booking_date' => $fmt_dmy($month_start) . ' - ' . $fmt_dmy($month_end),
						)),
					);
				}
				$tables['destination_sales'] = $dest_out;
			}

			$prod_rows = $this->db->query(
				"SELECT product.ProductCode AS code, product.Name AS name,
				        COALESCE(SUM(booking_product.Total),0) AS total,
				        COALESCE(SUM(booking_product.Quantity),0) AS qty
				 FROM booking
				 LEFT JOIN booking_product ON booking_product.BookingID = booking.BookingID
				 LEFT JOIN product ON product.ProductID = booking_product.ProductID
				 WHERE booking.BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND booking.CancelStatus='N' AND booking.Status!='N'
				   AND booking_product.Status='Y'
				   AND CAST(booking.InsertDate AS DATE) BETWEEN ? AND ?
				   AND product.ProductID IS NOT NULL
				 GROUP BY product.ProductID, product.ProductCode, product.Name
				 ORDER BY total DESC
				 LIMIT 5",
				array($month_start, $month_end)
			)->result();
			$prod_out = array();
			foreach($prod_rows as $r) {
				$prod_out[] = array(
					'code'  => $r->code,
					'name'  => $r->name,
					'qty'   => (int)$r->qty,
					'total' => $money($r->total),
				);
			}
			$tables['product_sales'] = $prod_out;

			// Sales by Team (Month) — group NetTotal by the FROZEN booking.TeamID snapshot
			// (team the sale was credited under), NOT the agent's live admin.TeamID, so
			// an agent moving team later doesn't rewrite past team totals; NULL or
			// inactive-team snapshots collapse into a single "Unassigned" row.
			$team_rows = $this->db->query(
				"SELECT COALESCE(t.TeamID, 0) AS team_id,
				        COALESCE(t.Name, 'Unassigned') AS team_name,
				        COUNT(*) AS cnt,
				        COALESCE(SUM(booking.NetTotal), 0) AS total
				 FROM booking
				 LEFT JOIN team t ON t.TeamID = booking.TeamID AND t.Status='Y'
				 WHERE booking.BookingConfirmationTitle='BOOKING CONFIRMATION'
				   AND booking.CancelStatus='N' AND booking.Status!='N'
				   AND CAST(booking.InsertDate AS DATE) BETWEEN ? AND ?
				 GROUP BY team_id, team_name
				 ORDER BY total DESC",
				array($month_start, $month_end)
			)->result();
			$team_out = array();
			foreach($team_rows as $r) {
				$team_out[] = array(
					'team_id'   => (int)$r->team_id,
					'team_name' => $r->team_name,
					'count'     => (int)$r->cnt,
					'total'     => $money($r->total),
				);
			}
			$tables['sales_by_team'] = $team_out;

			// Supplier Overdue — payment-out rows past their Deadline still
			// pending payment. Card surfaces the grand totals; table breaks
			// down by supplier so Finance can see who's most exposed. SUM of
			// per-supplier totals reconciles with the headline.
			$row = $this->db->query(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(payment.Debit), 0) AS total_due
				 FROM payment
				 WHERE payment.Status = 'P'
				   AND payment.Deadline < ?
				   AND payment.Debit > 0
				   AND payment.Type LIKE 'SUPPLIER PAYMENT%'
				   AND payment.SupplierID IS NOT NULL",
				array($today)
			)->row();
			$cards['supplier_overdue'] = array(
				'count'     => (int)$row->cnt,
				'total_due' => $money($row->total_due),
			);

			$sup_rows = $this->db->query(
				"SELECT supplier.SupplierID AS sid, supplier.Name AS name,
				        COUNT(*) AS cnt,
				        COALESCE(SUM(payment.Debit), 0) AS total_due,
				        MIN(payment.Deadline) AS earliest_deadline
				 FROM payment
				 JOIN supplier ON supplier.SupplierID = payment.SupplierID
				 WHERE payment.Status = 'P'
				   AND payment.Deadline < ?
				   AND payment.Debit > 0
				   AND payment.Type LIKE 'SUPPLIER PAYMENT%'
				 GROUP BY supplier.SupplierID, supplier.Name
				 ORDER BY total_due DESC
				 LIMIT 5",
				array($today)
			)->result();
			$sup_out = array();
			foreach($sup_rows as $r) {
				$sup_out[] = array(
					'supplier_id'       => (int)$r->sid,
					'name'              => $r->name,
					'count'             => (int)$r->cnt,
					'total_due'         => $money($r->total_due),
					'earliest_deadline' => $r->earliest_deadline ? $fmt_dmy($r->earliest_deadline) : '-',
				);
			}
			$tables['supplier_overdue'] = $sup_out;
		}

		$meta = array();
		$meta['selected_month']       = $period['value'];
		$meta['selected_month_label'] = $period['label'];
		// Day filter: '' when scoped to the whole month, else the resolved day.
		// Lets the front-end round-trip the date picker and re-label the cards.
		$meta['selected_day']         = $period['day'];
		$meta['is_day']               = $period['is_day'];
		if($is_owner || $is_team_lead) {
			// Echo the resolved toggle back so the front-end can highlight the
			// active period tab (covers both the default and the bad-input fallback).
			$meta['owner_period']       = $owner_period['period'];
			// Column-granularity the matrix was actually computed at: "Yesterday"
			// folds to Day and "Same Period Last Year" to Year (see
			// summary_resolve_owner_period). The front-end gates which columns apply
			// off THIS base, not the raw toggle id — otherwise the yesterday/lastyear
			// toggles match no column list and render an all-"—" row.
			$meta['owner_period_base']  = isset($owner_period['base']) ? $owner_period['base'] : $owner_period['period'];
			$meta['owner_period_label'] = $owner_period['label'];

			$row = $this->db
				->select('completed_at')
				->from('ghl_sync_run_log')
				->where('status', 'completed')
				->where('completed_at IS NOT NULL', null, false)
				->order_by('completed_at', 'DESC')
				->limit(1)
				->get()
				->row();
			$last = (!empty($row) && !empty($row->completed_at)) ? $row->completed_at : null;
			$ts = $last ? strtotime($last) : false;
			$meta['last_ghl_sync']         = $last;
			$meta['last_ghl_sync_display'] = $ts ? date('j M Y, H:i', $ts) : 'Never';
		}

		// ---------- Popover content (value-rich, with concrete dates) ----------
		// Each popover follows: Formula → Window → This card (with breakdown
		// and math) → Filters / Excludes. Built server-side so the same date
		// and money formatters that produce the card faces produce the
		// tooltip copy — guarantees the two cannot disagree.
		$popovers = array();
		$fmt_disp = function($d) { return date('j M Y', strtotime($d)); };
		$rng_disp = function($a, $b) use ($fmt_disp) {
			return $fmt_disp($a) . ' &ndash; ' . $fmt_disp($b);
		};
		$plural = function($n, $singular, $plural = null) {
			$word = ($plural === null) ? $singular . 's' : $plural;
			return ((int)$n === 1) ? $singular : $word;
		};
		$tc2_cutoff_disp = '1 Jun 2026';

		$window_month = $rng_disp($month_start, $month_end);
		// Conversion-card window: matches what was queried into $lead_conv /
		// $by_agent above. Owner = current calendar quarter, everyone else =
		// current month. Used only by Card 8 / Card 9 popovers.
		$conv_start_pop = $is_owner ? $quarter_start : $month_start;
		$conv_end_pop   = $is_owner ? $quarter_end   : $month_end;
		$window_conv    = $rng_disp($conv_start_pop, $conv_end_pop);
		$conv_label     = $is_owner ? 'this quarter' : 'this month';
		$window_week  = $rng_disp($week_start, $week_end);
		$window_next7  = $rng_disp($next7_start, $next7_end);
		$window_next14 = $rng_disp($next14_start, $next14_end);

		// TC cards
		if(isset($cards['bc_month'])) {
			$n  = (int)$cards['bc_month']['count'];
			$ny = isset($cards['bc_year']) ? (int)$cards['bc_year']['count'] : 0;
			$window_year = $rng_disp($year_start, $year_end);
			$popovers['pop-bc-month'] =
				'<strong>What it shows:</strong> The number of booking confirmations credited to you, this month and this year.<br><br>' .
				'<strong>Periods</strong> (by the date the booking was created):<br>' .
				'This Month: ' . $window_month . '<br>' .
				'This Year: ' . $window_year . '<br>' .
				'<strong>This card:</strong><br>' .
				'Month &rarr; <strong>' . $n . '</strong> ' . $plural($n, 'booking') . '<br>' .
				'Year &rarr; <strong>' . $ny . '</strong> ' . $plural($ny, 'booking') . '<br><br>' .
				'<strong>Who gets the credit:</strong>' .
				'<ul>' .
				'<li>Before ' . $tc2_cutoff_disp . ': the main sales person on the booking</li>' .
				'<li>From ' . $tc2_cutoff_disp . ': the second sales agent on the booking</li>' .
				'</ul>' .
				'<strong>Not counted:</strong> quotations, proforma invoices, cancelled, and drafts.';
		}

		if(isset($cards['sales_month']) && isset($cards['bc_month'])) {
			$n = (int)$cards['bc_month']['count'];
			$popovers['pop-sales-month'] =
				'<strong>What it shows:</strong> The total value of the bookings credited to you this month.<br><br>' .
				'<strong>Period:</strong> ' . $window_month . ' (by the date the booking was created)<br>' .
				'<strong>This card:</strong><br>' .
				$n . ' ' . $plural($n, 'booking') . ' counted (same as BC Created)<br>' .
				'Total value &rarr; <strong>' . $cards['sales_month']['value'] . '</strong><br><br>' .
				'<strong>Value:</strong> the booking price after discount, before any later refunds.<br>' .
				'<strong>Not counted:</strong> cancelled and drafts. Later refunds are not subtracted.';
		}

		if(isset($cards['sales_year'])) {
			$popovers['pop-sales-year'] =
				'<strong>What it shows:</strong> Your total sales for the year, compared against your yearly target.<br><br>' .
				'<strong>Period:</strong> ' . $rng_disp($year_start, $year_end) . ' (by the date the booking was created)<br>' .
				'<strong>This card:</strong><br>' .
				'Year sales &rarr; <strong>' . $cards['sales_year']['value'] . '</strong><br>' .
				'Yearly target &rarr; <strong>' . $cards['sales_year']['target'] . '</strong> (' . $cards['sales_year']['percent'] . ')<br><br>' .
				'<strong>Value:</strong> adds up all your Booking Confirmations created this year, regardless of payment status (same rule as the Month card, just over the full year).<br>' .
				'<strong>Target:</strong> set for each agent under Admin &rarr; Yearly Target. The percentage is your sales divided by your target.<br>' .
				'<strong>Not counted:</strong> quotations, proforma invoices, cancelled, and drafts.';
		}

		if(isset($cards['cancellation_rate']) && $is_tc) {
			$detail   = $cards['cancellation_rate']['detail']; // "5 / 20"
			$rate     = $cards['cancellation_rate']['value'];  // "25%"
			$parts    = explode(' / ', $detail);
			$canc     = isset($parts[0]) ? (int)$parts[0] : 0;
			$total    = isset($parts[1]) ? (int)$parts[1] : 0;
			$math     = ($total > 0)
				? ($canc . ' &divide; ' . $total . ' &times; 100 = <strong>' . $rate . '</strong>')
				: 'No bookings this month &rarr; <strong>0%</strong>';
			$popovers['pop-cancel-rate'] =
				'<strong>What it shows:</strong> The share of this month&rsquo;s bookings that ended up cancelled.<br><br>' .
				'<strong>Period:</strong> ' . $window_month . ' (by the date the booking was created)<br>' .
				'<strong>This card (your bookings):</strong><br>' .
				$canc . ' cancelled / ' . $total . ' total bookings<br>' .
				'&rarr; ' . $math . '<br><br>' .
				'<strong>Scope:</strong> drafts not counted. Your bookings only.<br>' .
				'<strong>Note:</strong> based on when the booking was created, not when it was cancelled.';
		}

		// TC LEAD / Owner cards
		if(isset($cards['bc_week_month'])) {
			$w = (int)$cards['bc_week_month']['week'];
			$m = (int)$cards['bc_week_month']['month'];
			$bc_wm_html =
				'<strong>What it shows:</strong> The total number of confirmed bookings across all sales agents.<br><br>' .
				'<strong>Periods (by the date the booking was created):</strong><br>' .
				'Week: ' . $window_week . ' (Mon&ndash;Sun)<br>' .
				'Month: ' . $window_month . '<br>' .
				'<strong>This card (team-wide):</strong><br>' .
				'Week &rarr; <strong>' . $w . '</strong> ' . $plural($w, 'booking') . '<br>' .
				'Month &rarr; <strong>' . $m . '</strong> ' . $plural($m, 'booking') . '<br><br>' .
				'<strong>Not counted:</strong> quotations, cancelled, and drafts.';
			$popovers['pop-bc-week-month-tl'] = $bc_wm_html;
			$popovers['pop-bc-week-month-op'] = $bc_wm_html;
		}

		if(isset($cards['cancellation_rate']) && $is_tclead) {
			$detail   = $cards['cancellation_rate']['detail'];
			$rate     = $cards['cancellation_rate']['value'];
			$parts    = explode(' / ', $detail);
			$canc     = isset($parts[0]) ? (int)$parts[0] : 0;
			$total    = isset($parts[1]) ? (int)$parts[1] : 0;
			$math     = ($total > 0)
				? ($canc . ' &divide; ' . $total . ' &times; 100 = <strong>' . $rate . '</strong>')
				: 'No bookings this month &rarr; <strong>0%</strong>';
			$popovers['pop-cancel-rate-tl'] =
				'<strong>What it shows:</strong> The share of this month&rsquo;s bookings that ended up cancelled, across the whole team.<br><br>' .
				'<strong>Period:</strong> ' . $window_month . ' (by the date the booking was created)<br>' .
				'<strong>This card (team-wide):</strong><br>' .
				$canc . ' cancelled / ' . $total . ' total bookings<br>' .
				'&rarr; ' . $math . '<br><br>' .
				'<strong>Scope:</strong> drafts not counted. All agents included.<br>' .
				'<strong>Note:</strong> based on when the booking was created, not when it was cancelled.';
		}

		if(isset($cards['leads_dwm'])) {
			$ld = $cards['leads_dwm'];
			$popovers['pop-leads-dwm'] =
				'<strong>What it shows:</strong> New leads from GHL.<br><br>' .
				'<strong>Periods (by the date the lead came in):</strong><br>' .
				'Today: ' . $fmt_disp($today) . '<br>' .
				'Week: ' . $window_week . ' (Mon&ndash;Sun)<br>' .
				'Month: ' . $window_month . '<br>' .
				'<strong>This card:</strong><br>' .
				'Today &rarr; <strong>' . (int)$ld['day']   . '</strong> ' . $plural($ld['day'], 'lead') . '<br>' .
				'Week &rarr; <strong>' . (int)$ld['week']  . '</strong> ' . $plural($ld['week'], 'lead') . '<br>' .
				'Month &rarr; <strong>' . (int)$ld['month'] . '</strong> ' . $plural($ld['month'], 'lead') . '<br><br>' .
				'Each conversation counts as one lead &mdash; the same customer messaging again doesn\'t count twice.';

			$total_leads = (int)$ld['total_conv'];
			$converted   = (int)$ld['converted_month'];
			$responded   = (int)$ld['responded_month'];
			$conv_math = ($total_leads > 0)
				? ($converted . ' &divide; ' . $total_leads . ' &times; 100 = <strong>' . $ld['conversion_rate'] . '</strong>')
				: 'No leads ' . $conv_label . ' &rarr; <strong>0%</strong>';
			$resp_math = ($total_leads > 0)
				? ($responded . ' &divide; ' . $total_leads . ' &times; 100 = <strong>' . $ld['response_rate'] . '</strong>')
				: 'No leads ' . $conv_label . ' &rarr; <strong>0%</strong>';
			$avg_secs = $ld['avg_response_seconds'];
			$avg_detail = ($avg_secs === null)
				? '<strong>Avg Time:</strong> n/a (no replies measured)'
				: '<strong>Avg Time:</strong> ' . $ld['avg_response_time'] . ' (average of each lead\'s first 5 reply times; if there are fewer than 5 replies, all of them are used; <em>only time during working hours is counted</em>)';
			$popovers['pop-leads-conv'] =
				'<strong>Period:</strong> ' . $window_conv . ' (all leads from ' . $conv_label . ', every agent)<br>' .
				'<strong>This card (' . $total_leads . ' total leads):</strong><br>' .
				'Converted: ' . $converted . ' &rarr; Conversion ' . $conv_math . '<br>' .
				'Responded: ' . $responded . ' &rarr; Response ' . $resp_math . '<br>' .
				$avg_detail . '<br><br>' .
				'<strong>Converted:</strong> the lead is linked to a booking and the agent is credited for that sale (main sales person before ' . $tc2_cutoff_disp . '; second sales agent from ' . $tc2_cutoff_disp . ').<br>' .
				'<strong>Responded:</strong> the lead got at least one reply.<br>' .
				'<strong>Working hours:</strong> everyday 7:00am&ndash;10:00pm Malaysia time &mdash; time outside working hours is not counted in Avg Time.';
		}

		if(isset($cards['active_leads_dwm'])) {
			$a = $cards['active_leads_dwm'];
			$popovers['pop-active-leads'] =
				'<strong>What it shows:</strong> Leads that haven&rsquo;t turned into a booking yet, by the date they came in.<br><br>' .
				'<strong>Periods (by the date the lead came in):</strong><br>' .
				'Today: ' . $fmt_disp($today) . '<br>' .
				'Week: ' . $window_week . ' (Mon&ndash;Sun)<br>' .
				'Month: ' . $window_month . '<br>' .
				'<strong>This card:</strong><br>' .
				'Today &rarr; <strong>' . (int)$a['day']   . '</strong> ' . $plural($a['day'],   'lead') . ' still open<br>' .
				'Week &rarr; <strong>'  . (int)$a['week']  . '</strong> ' . $plural($a['week'],  'lead') . ' still open<br>' .
				'Month &rarr; <strong>' . (int)$a['month'] . '</strong> ' . $plural($a['month'], 'lead') . ' still open<br><br>' .
				'<strong>Open</strong> means the lead has no booking linked to it yet.<br>' .
				'<strong>Compare with:</strong> the &quot;Leads&quot; total to see open versus converted at a glance.';
		}

		if(isset($tables['agent_conversion'])) {
			$rows = count($tables['agent_conversion']);
			$popovers['pop-agent-conversion'] =
				'<strong>Period:</strong> ' . $window_conv . '<br><br>' .
				'<strong>Per agent:</strong>' .
				'<ul>' .
				'<li>Leads &mdash; total leads assigned to them ' . $conv_label . '</li>' .
				'<li>Converted &mdash; leads assigned to them that became a booking (same rule as the Lead Ownership dashboard&rsquo;s Converted column; no main/second sales-agent credit gate)</li>' .
				'<li>Rate &mdash; converted divided by leads</li>' .
				'</ul>' .
				'<strong>Order:</strong> most leads first, then agent name. Top 10.<br>' .
				'<strong>This card:</strong> ' . $rows . ' ' . $plural($rows, 'agent') . ' shown.<br>' .
				'<strong>Not shown:</strong> leads with no agent assigned.';
		}

		if(isset($tables['leads_by_agent'])) {
			$rows = count($tables['leads_by_agent']);
			$popovers['pop-leads-by-agent'] =
				'<strong>What it shows:</strong> New leads from GHL, broken down per agent.<br><br>' .
				'<strong>Periods (by the date the lead came in):</strong><br>' .
				'Today: ' . $fmt_disp($today) . '<br>' .
				'Week: ' . $window_week . ' (Mon&ndash;Sun)<br>' .
				'Month: ' . $window_month . '<br><br>' .
				'<strong>Per agent:</strong> the number of new leads assigned to that agent in each period. The periods nest &mdash; a lead from today is also counted in this week and this month.<br><br>' .
				'<strong>Order:</strong> most leads this month first, then week, day, agent name.<br>' .
				'<strong>This card:</strong> ' . $rows . ' ' . $plural($rows, 'agent') . ' shown.<br>' .
				'<strong>Not shown:</strong> leads with no agent assigned.';
		}

		// OP cards
		if(isset($cards['upcoming_travel_not_ready_op'])) {
			$u   = $cards['upcoming_travel_not_ready_op'];
			$tot = (int)$u['count'];
			$popovers['pop-upcoming-not-ready-op'] =
				'<strong>What it shows:</strong> Bookings starting travel within 7 days that aren&rsquo;t ready yet.<br><br>' .
				'<strong>&ldquo;Not yet ready&rdquo;</strong> means still waiting on: Payment / Booking Op / Guest List / Travel Voucher.<br><br>' .
				'<strong>Period:</strong> ' . $window_next7 . ' (by travel start date)<br>' .
				'<strong>This card (' . ($is_op ? 'team-wide' : 'your bookings') . '):</strong><br>' .
				'Waiting on payment: ' . (int)$u['by_p'] . '<br>' .
				'Waiting on booking operations: ' . (int)$u['by_pbo'] . '<br>' .
				'Waiting on guest list: ' . (int)$u['by_pgl'] . '<br>' .
				'Waiting on travel voucher: ' . (int)$u['by_ptv'] . '<br>' .
				'&rarr; <strong>' . $tot . ' ' . $plural($tot, 'booking') . '</strong><br><br>' .
				'<strong>Not counted:</strong> cancelled, and bookings already at Pending Travel or beyond.<br>' .
				'<strong>Why it matters:</strong> guests travel within a week.';
		}

		if(isset($cards['upcoming_travel_not_ready_op_14'])) {
			$u   = $cards['upcoming_travel_not_ready_op_14'];
			$tot = (int)$u['count'];
			$popovers['pop-upcoming-not-ready-op-14'] =
				'<strong>What it shows:</strong> Bookings starting travel within 14 days that aren&rsquo;t ready yet.<br><br>' .
				'<strong>&ldquo;Not yet ready&rdquo;</strong> means still waiting on: Payment / Booking Op / Guest List / Travel Voucher.<br><br>' .
				'<strong>Period:</strong> ' . $window_next14 . ' (by travel start date)<br>' .
				'<strong>This card (' . ($is_op ? 'team-wide' : 'your bookings') . '):</strong><br>' .
				'Waiting on payment: ' . (int)$u['by_p'] . '<br>' .
				'Waiting on booking operations: ' . (int)$u['by_pbo'] . '<br>' .
				'Waiting on guest list: ' . (int)$u['by_pgl'] . '<br>' .
				'Waiting on travel voucher: ' . (int)$u['by_ptv'] . '<br>' .
				'&rarr; <strong>' . $tot . ' ' . $plural($tot, 'booking') . '</strong><br><br>' .
				'<strong>Includes</strong> the bookings shown in &ldquo;within 7 days&rdquo;.<br>' .
				'<strong>Not counted:</strong> cancelled, and bookings already at Pending Travel or beyond.<br>' .
				'<strong>Why it matters:</strong> a two-week heads-up to get bookings ready.';
		}

		if(isset($cards['insurance_pending'])) {
			$ip = (int)$cards['insurance_pending']['count'];
			$popovers['pop-insurance-pending'] =
				'<strong>What it shows:</strong> Bookings with an insurance checklist not yet ticked off on at least one product line.<br><br>' .
				'<strong>Counted when, for an active product line:</strong>' .
				'<ul>' .
				'<li>The product has an insurance checklist</li>' .
				'<li>That checklist hasn&rsquo;t been completed yet</li>' .
				'<li>The line isn&rsquo;t excluded from checklist pay-outs (same rule the checklist screen uses)</li>' .
				'<li>It is a confirmed booking, not cancelled or draft</li>' .
				'<li>Travel from <strong>' . $fmt_disp($insurance_window_start) . '</strong> onwards (1 March this year)</li>' .
				'<li>Bookings already completed are left out</li>' .
				'</ul>' .
				'<strong>Live list &middot; as of ' . $fmt_disp($today) . '</strong> &mdash; travel from ' . $fmt_disp($insurance_window_start) . ' onwards.<br>' .
				'<strong>This card:</strong> ' .
				'Insurance pending &rarr; <strong>' . $ip . ' ' . $plural($ip, 'booking') . '</strong><br><br>' .
				'<strong>What to do:</strong> click to filter the list to these bookings and tick off insurance.';
		}

		if(isset($cards['ferry_pending'])) {
			$fp = (int)$cards['ferry_pending']['count'];
			$popovers['pop-ferry-pending'] =
				'<strong>What it shows:</strong> Bookings with a &ldquo;Book Ferry Transfer&rdquo; checklist not yet ticked off on at least one product line.<br><br>' .
				'<strong>Counted when, for an active product line:</strong>' .
				'<ul>' .
				'<li>The product has a &ldquo;Book Ferry Transfer&rdquo; checklist</li>' .
				'<li>That checklist hasn&rsquo;t been completed yet</li>' .
				'<li>The line isn&rsquo;t excluded from checklist pay-outs (same rule the checklist screen uses)</li>' .
				'<li>It is a confirmed booking, not cancelled or draft</li>' .
				'</ul>' .
				'<strong>Travel window:</strong> trips that fall between ' . $fmt_disp($ferry_window_start) . ' &ndash; ' . $fmt_disp($ferry_window_end) . ' (this month &amp; next).<br>' .
				'<strong>This card:</strong> ' .
				'Ferry transfer pending &rarr; <strong>' . $fp . ' ' . $plural($fp, 'booking') . '</strong><br><br>' .
				'<strong>What to do:</strong> click to filter the list to these bookings and arrange the ferry transfer.';
		}

		if(isset($cards['gl_submitted'])) {
			$g = (int)$cards['gl_submitted']['count'];
			$popovers['pop-gl-submitted'] =
				'<strong>What it shows:</strong> Bookings where the customer has submitted their guest list but OP hasn&rsquo;t locked it yet.<br><br>' .
				'<strong>Counted when:</strong>' .
				'<ul>' .
				'<li>The customer has submitted their guest list</li>' .
				'<li>OP hasn&rsquo;t locked it yet</li>' .
				'<li>It is a confirmed booking, not cancelled or draft</li>' .
				'</ul>' .
				'<strong>Live list &middot; as of ' . $fmt_disp($today) . '</strong> &mdash; no date limit.<br>' .
				'<strong>This card:</strong> ' .
				'Submitted, not yet locked &rarr; <strong>' . $g . ' ' . $plural($g, 'booking') . '</strong><br><br>' .
				'<strong>What to do:</strong> check the list is complete, then lock it to stop further customer edits.';
		}

		// OP operational queue cards.
		if(isset($cards['pending_bc_op'])) {
			$n = (int)$cards['pending_bc_op']['count'];
			$popovers['pop-pending-bc-op'] =
				'<strong>What it shows:</strong> Bookings sitting at the <strong>Pending BC</strong> stage, waiting to be confirmed.<br><br>' .
				'<strong>Counted when:</strong>' .
				'<ul>' .
				'<li>The booking is at the &ldquo;Pending BC&rdquo; stage</li>' .
				'<li>Not cancelled</li>' .
				'</ul>' .
				'<strong>Team-wide live list &middot; as of ' . $fmt_disp($today) . '</strong> &mdash; no date limit.<br>' .
				'<strong>This card:</strong> ' . $n . ' ' . $plural($n, 'booking') . ' &rarr; <strong>' . $n . '</strong><br><br>' .
				'<strong>What to do:</strong> click to view and move them along to Pending BC Confirmation.';
		}
		if(isset($cards['pending_bc_confirmation_op'])) {
			$n = (int)$cards['pending_bc_confirmation_op']['count'];
			$popovers['pop-pending-bc-confirmation-op'] =
				'<strong>What it shows:</strong> Bookings sitting at the <strong>Pending BC Confirmation</strong> stage, waiting to be approved.<br><br>' .
				'<strong>Counted when:</strong>' .
				'<ul>' .
				'<li>The booking is at the &ldquo;Pending BC Confirmation&rdquo; stage</li>' .
				'<li>Not cancelled</li>' .
				'</ul>' .
				'<strong>Team-wide live list &middot; as of ' . $fmt_disp($today) . '</strong> &mdash; no date limit.<br>' .
				'<strong>This card:</strong> ' . $n . ' ' . $plural($n, 'booking') . ' &rarr; <strong>' . $n . '</strong><br><br>' .
				'<strong>What to do:</strong> click to view and approve the booking confirmation.';
		}
		if(isset($cards['travel_today_op'])) {
			$n = (int)$cards['travel_today_op']['count'];
			$popovers['pop-travel-today-op'] =
				'<strong>What it shows:</strong> All confirmed bookings whose travel starts today, whatever stage they&rsquo;re at.<br><br>' .
				'<strong>Counted when:</strong>' .
				'<ul>' .
				'<li>Travel <strong>starts today</strong> (' . $fmt_disp($today) . ')</li>' .
				'<li>It is a confirmed booking, not cancelled or draft</li>' .
				'<li><strong>Any</strong> stage</li>' .
				'</ul>' .
				'<strong>This card (team-wide):</strong> ' . $n . ' ' . $plural($n, 'booking') . ' &rarr; <strong>' . $n . '</strong><br><br>' .
				'<strong>Note:</strong> based on the departure date (trips that merely pass through today are not included).';
		}
		if(isset($cards['travel_tomorrow_op'])) {
			$n = (int)$cards['travel_tomorrow_op']['count'];
			$popovers['pop-travel-tomorrow-op'] =
				'<strong>What it shows:</strong> All confirmed bookings whose travel starts tomorrow, whatever stage they&rsquo;re at.<br><br>' .
				'<strong>Counted when:</strong>' .
				'<ul>' .
				'<li>Travel <strong>starts tomorrow</strong> (' . $fmt_disp($tomorrow) . ')</li>' .
				'<li>It is a confirmed booking, not cancelled or draft</li>' .
				'<li><strong>Any</strong> stage</li>' .
				'</ul>' .
				'<strong>This card (team-wide):</strong> ' . $n . ' ' . $plural($n, 'booking') . ' &rarr; <strong>' . $n . '</strong><br><br>' .
				'<strong>Note:</strong> based on the departure date (trips that merely pass through tomorrow are not included).';
		}
		if(isset($cards['travel_tomorrow_not_ready_op'])) {
			$n = (int)$cards['travel_tomorrow_not_ready_op']['count'];
			$popovers['pop-travel-tomorrow-not-ready-op'] =
				'<strong>What it shows:</strong> Bookings travelling tomorrow that haven&rsquo;t reached &ldquo;Pending Travel&rdquo; yet.<br><br>' .
				'<strong>Counted when:</strong>' .
				'<ul>' .
				'<li>Travel <strong>starts tomorrow</strong> (' . $fmt_disp($tomorrow) . ')</li>' .
				'<li>The booking is <strong>not</strong> yet at &ldquo;Pending Travel&rdquo;</li>' .
				'<li>It is a confirmed booking, not cancelled or draft</li>' .
				'</ul>' .
				'<strong>This card (team-wide):</strong> ' . $n . ' ' . $plural($n, 'booking') . ' &rarr; <strong>' . $n . '</strong><br><br>' .
				'<strong>Why it matters:</strong> guests travel tomorrow but the booking isn&rsquo;t ready &mdash; chase these first.';
		}
		if(isset($cards['pending_review_op'])) {
			$n = (int)$cards['pending_review_op']['count'];
			$popovers['pop-pending-review-op'] =
				'<strong>What it shows:</strong> Bookings where travel has finished but the after-sales review is still outstanding.<br><br>' .
				'<strong>Counted when:</strong>' .
				'<ul>' .
				'<li>Travel has been completed</li>' .
				'<li>The after-sales review is still pending</li>' .
				'<li>It is a confirmed booking, not cancelled</li>' .
				'</ul>' .
				'<strong>Team-wide live list &middot; as of ' . $fmt_disp($today) . '</strong>.<br>' .
				'<strong>This card:</strong> ' . $n . ' ' . $plural($n, 'booking') . ' &rarr; <strong>' . $n . '</strong><br><br>' .
				'<strong>What to do:</strong> follow up with the customer, then mark the booking complete.';
		}

		if(isset($tables['destination_sales'])) {
			$rows = count($tables['destination_sales']);
			$is_op_view = $is_op;
			$sort_line = $is_op_view
				? '<strong>Order:</strong> most bookings first. Top 5.'
				: '<strong>Order:</strong> highest sales first. Top 5.';
			$dest_html =
				'<strong>Period:</strong> ' . $window_month . ' (by the date the booking was created)<br><br>' .
				'<strong>Per destination:</strong>' .
				'<ul>' .
				'<li>BC &mdash; how many bookings</li>' .
				'<li>Sales &mdash; total sales</li>' .
				'</ul>' .
				$sort_line . '<br>' .
				'<strong>This card:</strong> ' . $rows . ' ' . $plural($rows, 'destination') . ' shown.<br>' .
				'<strong>Counted:</strong> confirmed bookings only, not cancelled or draft.<br>' .
				'<strong>Tip:</strong> click a row to filter the booking list by that destination.';
			if($is_op_view) {
				$popovers['pop-destination-sales-op'] = $dest_html;
			} else {
				$popovers['pop-destination-sales-fin'] = $dest_html;
			}
		}

		if(isset($tables['destination_closed_sales'])) {
			$rows = count($tables['destination_closed_sales']);
			$popovers['pop-destination-closed-sales'] =
				'<strong>Period:</strong> ' . $window_month . ' (bookings created this month)<br><br>' .
				'<strong>Per destination:</strong>' .
				'<ul>' .
				'<li>BC &mdash; how many fully-paid bookings</li>' .
				'<li>Sales &mdash; total sales across those bookings</li>' .
				'</ul>' .
				'<strong>Order:</strong> highest sales first. Top 5.<br>' .
				'<strong>This card:</strong> ' . $rows . ' ' . $plural($rows, 'destination') . ' shown.<br>' .
				'<strong>Counted:</strong> confirmed bookings only, not cancelled or draft, where the customer has fully paid ' .
				'(approved payments cover the full booking amount). Same &ldquo;fully paid&rdquo; rule as the Total Sales card.';
		}

		if(isset($tables['active_leads_by_tag'])) {
			$by_tag = $tables['active_leads_by_tag'];
			$dest_n = isset($by_tag['destination']) ? count($by_tag['destination']) : 0;
			$lang_n = isset($by_tag['language'])    ? count($by_tag['language'])    : 0;
			$race_n = isset($by_tag['race'])        ? count($by_tag['race'])        : 0;
			$popovers['pop-active-leads-by-tag'] =
				'<strong>What it shows:</strong> All open (not-yet-converted) leads currently in agents&rsquo; ' .
				'GHL inboxes &mdash; the same leads as the &ldquo;Active Leads&rdquo; card, ' .
				'just grouped by tag instead of by date.<br><br>' .
				'<strong>The three columns:</strong>' .
				'<ul>' .
				'<li>Destination &mdash; country / island / region tags on the conversation</li>' .
				'<li>Language &mdash; conversation language tags (bm / en / cn)</li>' .
				'<li>Race &mdash; halal / dietary tags (e.g. muslim)</li>' .
				'</ul>' .
				'<strong>Matching:</strong> a tag must match a known tag exactly (not case-sensitive). ' .
				'Partial matches don&rsquo;t count, so &ldquo;redang052026&rdquo; is not counted as &ldquo;redang&rdquo;.<br>' .
				'<strong>Counting:</strong> the same tag on one lead counts once. A lead with tags in more than one ' .
				'column is counted in each (the three columns are separate views of the same leads).<br>' .
				'<strong>Order:</strong> each column is sorted by lead count (highest first), then tag name. Top 10 per column.<br>' .
				'<strong>This card:</strong> ' .
				$dest_n . ' ' . $plural($dest_n, 'destination') . ', ' .
				$lang_n . ' ' . $plural($lang_n, 'language') . ', ' .
				$race_n . ' race ' . ($race_n === 1 ? 'tag' : 'tags') . ' shown.';
		}

		if(isset($cards['lead_source_split'])) {
			$ls = $cards['lead_source_split'];
			$sg_n = (int)$ls['self_gen_count'];
			$co_n = (int)$ls['company_count'];
			$tot  = $sg_n + $co_n;
			$sg_pct = $tot > 0 ? round(($sg_n / $tot) * 100, 1) : 0;
			$popovers['pop-lead-source-split'] =
				'<strong>Period:</strong> ' . $window_month . ' (by the date the booking was created)<br><br>' .
				'<strong>The two groups:</strong>' .
				'<ul>' .
				'<li><strong>Self Gen</strong> &mdash; booking source is &quot;' . SELF_GEN_SOURCE_NAME . '&quot;. The agent brought in the lead themselves.</li>' .
				'<li><strong>Company</strong> &mdash; any other source (WhatsApp, WeChat, Email, Call, Telegram, Facebook, etc.) or no source at all.</li>' .
				'</ul>' .
				'<strong>This card:</strong><br>' .
				'Self Gen &rarr; <strong>' . $sg_n . '</strong> ' . $plural($sg_n, 'booking') . ' &middot; ' . $ls['self_gen_total'] . '<br>' .
				'Company &rarr; <strong>' . $co_n . '</strong> ' . $plural($co_n, 'booking') . ' &middot; ' . $ls['company_total'] . '<br>' .
				($tot > 0 ? 'Self Gen share &rarr; ' . $sg_n . ' &divide; ' . $tot . ' &times; 100 = <strong>' . $sg_pct . '%</strong><br><br>' : '<br>') .
				'<strong>Who it&rsquo;s credited to:</strong> the main sales person on the booking, whatever the date &mdash; this card always uses the main salesperson (self-generation is about who brought in the lead).<br>' .
				'<strong>Counted:</strong> confirmed bookings only, not cancelled or draft.';
		}

		if(isset($tables['agent_source_split'])) {
			$rows = count($tables['agent_source_split']);
			$popovers['pop-agent-source-split'] =
				'<strong>Period:</strong> ' . $window_month . ' (by the date the booking was created)<br><br>' .
				'<strong>Per agent:</strong>' .
				'<ul>' .
				'<li>Self Gen &mdash; bookings whose source is &quot;' . SELF_GEN_SOURCE_NAME . '&quot;</li>' .
				'<li>Company &mdash; bookings whose source is anything else (or none)</li>' .
				'<li>% Self Gen &mdash; Self Gen count divided by (Self Gen + Company)</li>' .
				'</ul>' .
				'<strong>How agents are grouped:</strong> each agent rolls up to their team lead. Agents under the same team lead are listed together; agents with no team lead appear last.<br>' .
				'<strong>This card:</strong> ' . $rows . ' ' . $plural($rows, 'agent') . ' shown.<br>' .
				'<strong>Who it&rsquo;s credited to:</strong> the main sales person on the booking, whatever the date &mdash; same as the Self Gen vs Company card above.<br>' .
				'<strong>Counted:</strong> confirmed bookings only, not cancelled or draft; the agent must have created at least one booking this month.';
		}

		// Finance cards
		if(isset($cards['payment_in_dwm'])) {
			$p = $cards['payment_in_dwm'];
			$popovers['pop-payin'] =
				'<strong>What it shows:</strong> The total customer money received (approved payments coming in).<br><br>' .
				'<strong>Periods (by payment date):</strong><br>' .
				'Today &rarr; ' . $fmt_disp($today) . ' &rarr; <strong>' . $p['day']   . '</strong> across ' . (int)$p['day_count']   . ' ' . $plural($p['day_count'],   'payment') . '<br>' .
				'Week &rarr; ' . $window_week  . ' &rarr; <strong>' . $p['week']  . '</strong> across ' . (int)$p['week_count']  . ' ' . $plural($p['week_count'],  'payment') . '<br>' .
				'Month &rarr; ' . $window_month . ' &rarr; <strong>' . $p['month'] . '</strong> across ' . (int)$p['month_count'] . ' ' . $plural($p['month_count'], 'payment') . '<br><br>' .
				'<strong>Not counted:</strong> unapproved payments, refunds, outgoing payments, and agent commission from suppliers.';
		}

		if(isset($tables['sales_by_team'])) {
			$rows = count($tables['sales_by_team']);
			$grand_total = 0.0;
			$grand_count = 0;
			foreach($tables['sales_by_team'] as $t) {
				// Strip "RM " and thousands separators to reconcile per-team
				// totals against the headline figure in the popover.
				$num = (float)str_replace(array('RM ', ','), '', $t['total']);
				$grand_total += $num;
				$grand_count += (int)$t['count'];
			}
			$popovers['pop-sales-by-team'] =
				'<strong>Period:</strong> ' . $window_month . ' (by the date the booking was created)<br><br>' .
				'<strong>Per team:</strong>' .
				'<ul>' .
				'<li>BC &mdash; how many bookings credited to the team</li>' .
				'<li>Sales &mdash; total sales</li>' .
				'</ul>' .
				'<strong>How teams are grouped:</strong> each booking&rsquo;s sales agent rolls up to their team lead.<br>' .
				'Agents with no team lead fall into a single &quot;Unassigned&quot; row.<br>' .
				'<strong>This card:</strong> ' . $rows . ' ' . $plural($rows, 'team') . ' shown &rarr; <strong>' . $grand_count . '</strong> ' . $plural($grand_count, 'booking') . ' &middot; <strong>' . $money($grand_total) . '</strong> total.<br>' .
				'<strong>Counted:</strong> confirmed bookings only, not cancelled or draft.';
		}

		if(isset($cards['supplier_overdue'])) {
			$so = $cards['supplier_overdue'];
			$rows_n = isset($tables['supplier_overdue']) ? count($tables['supplier_overdue']) : 0;
			$popovers['pop-supplier-overdue'] =
				'<strong>What it shows:</strong> Supplier pay-outs whose deadline has passed but are still unpaid.<br><br>' .
				'<strong>Counted when:</strong>' .
				'<ul>' .
				'<li>It is a payment going out to a supplier</li>' .
				'<li>It hasn&rsquo;t been paid yet</li>' .
				'<li>The deadline is before today (' . $fmt_disp($today) . ')</li>' .
				'<li>It is linked to a supplier</li>' .
				'</ul>' .
				'<strong>This card:</strong><br>' .
				'<strong>' . (int)$so['count'] . '</strong> ' . $plural($so['count'], 'overdue payment') . ' &middot; <strong>' . $so['total_due'] . '</strong> total due<br>' .
				'Top ' . $rows_n . ' ' . $plural($rows_n, 'supplier') . ' shown below; click a row to drill down.<br><br>' .
				'<strong>Not counted:</strong> already paid, deleted, customer payments coming in, and agent-commission entries.';
		}

		if(isset($cards['supplier_due_soon'])) {
			$ds = $cards['supplier_due_soon'];
			$rows_n = isset($tables['supplier_due_soon']) ? count($tables['supplier_due_soon']) : 0;
			$ds_total = (int)$ds['overdue']['count'] + (int)$ds['today']['count'] + (int)$ds['tomorrow']['count'];
			$popovers['pop-supplier-due-soon'] =
				'<strong>What it shows:</strong> Supplier pay-outs still unpaid, with a deadline coming up soon.<br><br>' .
				'<strong>Counted when:</strong>' .
				'<ul>' .
				'<li>It is a payment going out to a supplier</li>' .
				'<li>It hasn&rsquo;t been paid yet</li>' .
				'<li>The deadline falls within ' . $rng_disp($due_soon_start, $due_soon_end) . '</li>' .
				'<li>It is linked to a supplier</li>' .
				'</ul>' .
				'<strong>Grouped by deadline:</strong>' .
				'<ul>' .
				'<li><strong>Overdue</strong> &mdash; ' . $fmt_disp($due_soon_start) . ' to before today (' . $fmt_disp($today) . '): <strong>' . (int)$ds['overdue']['count'] . '</strong> &middot; ' . $ds['overdue']['total_due'] . '</li>' .
				'<li><strong>Today</strong>: <strong>' . (int)$ds['today']['count'] . '</strong> &middot; ' . $ds['today']['total_due'] . '</li>' .
				'<li><strong>Tomorrow</strong> (' . $fmt_disp($due_soon_end) . '): <strong>' . (int)$ds['tomorrow']['count'] . '</strong> &middot; ' . $ds['tomorrow']['total_due'] . '</li>' .
				'</ul>' .
				'<strong>This card:</strong> ' . $ds_total . ' ' . $plural($ds_total, 'payout') . ' across the window; top ' . $rows_n . ' ' . $plural($rows_n, 'supplier') . ' shown, earliest deadline first.<br><br>' .
				'<strong>Not counted:</strong> already paid, deleted, customer payments coming in, and agent-commission entries.';
		}

		if(isset($cards['customer_payment_due_soon'])) {
			$cd = $cards['customer_payment_due_soon'];
			$cd_rows_n = isset($tables['customer_payment_due_soon']) ? count($tables['customer_payment_due_soon']) : 0;
			$cd_total = (int)$cd['overdue']['count'] + (int)$cd['today']['count'] + (int)$cd['tomorrow']['count'];
			$popovers['pop-customer-payment-due-soon'] =
				'<strong>What it shows:</strong> Bookings that still owe a customer payment, with a deadline coming up soon.<br><br>' .
				'<strong>Counted when:</strong>' .
				'<ul>' .
				'<li>The booking still has a scheduled payment to collect</li>' .
				'<li>There is still a balance owing (booking amount minus approved customer payments)</li>' .
				'<li>The next deadline falls within ' . $rng_disp($cust_due_start, $cust_due_end) . '</li>' .
				'<li>Not cancelled</li>' .
				'</ul>' .
				'<strong>Next deadline:</strong> the deposit deadline when nothing is paid yet, otherwise the full-payment deadline once a deposit is in.' .
				'<br><br>' .
				'<strong>Grouped by deadline:</strong>' .
				'<ul>' .
				'<li><strong>Overdue</strong> &mdash; ' . $fmt_disp($cust_due_start) . ' to before today (' . $fmt_disp($today) . '): <strong>' . (int)$cd['overdue']['count'] . '</strong> &middot; ' . $cd['overdue']['total_due'] . '</li>' .
				'<li><strong>Today</strong>: <strong>' . (int)$cd['today']['count'] . '</strong> &middot; ' . $cd['today']['total_due'] . '</li>' .
				'<li><strong>Tomorrow</strong> (' . $fmt_disp($cust_due_end) . '): <strong>' . (int)$cd['tomorrow']['count'] . '</strong> &middot; ' . $cd['tomorrow']['total_due'] . '</li>' .
				'</ul>' .
				'<strong>This card:</strong> ' . $cd_total . ' ' . $plural($cd_total, 'booking') . ' across the window; top ' . $cd_rows_n . ' shown, earliest deadline first. Amounts are the balance still owed.<br><br>' .
				'<strong>Not counted:</strong> fully paid, cancelled, drafts/quotations, and agent-commission credits.';
		}

		if(isset($cards['checklist_payout_due_soon'])) {
			$cp = $cards['checklist_payout_due_soon'];
			$cp_rows_n = isset($tables['checklist_payout_due_soon']) ? count($tables['checklist_payout_due_soon']) : 0;
			$cp_total = (int)$cp['overdue']['count'] + (int)$cp['today']['count'] + (int)$cp['tomorrow']['count'];
			$popovers['pop-checklist-payout-due-soon'] =
				'<strong>What it shows:</strong> Bookings whose &ldquo;Payment Out To Supplier&rdquo; checklist isn&rsquo;t ticked off yet, with a deadline coming up soon.<br><br>' .
				'<strong>Counted when, for an active product line:</strong>' .
				'<ul>' .
				'<li>The product has a supplier pay-out checklist (full or deposit)</li>' .
				'<li>The checklist is <strong>not ticked yet</strong></li>' .
				'<li>The line isn&rsquo;t excluded from checklist pay-outs (same rule the checklist screen uses)</li>' .
				'<li>The pay-out deadline (full or deposit) falls within ' . $rng_disp($cp_due_start, $cp_due_end) . '</li>' .
				'<li>It is a confirmed booking, not cancelled or draft</li>' .
				'</ul>' .
				'<strong>Grouped by deadline:</strong>' .
				'<ul>' .
				'<li><strong>Overdue</strong> &mdash; ' . $fmt_disp($cp_due_start) . ' to before today (' . $fmt_disp($today) . '): <strong>' . (int)$cp['overdue']['count'] . '</strong> bookings</li>' .
				'<li><strong>Today</strong>: <strong>' . (int)$cp['today']['count'] . '</strong> bookings</li>' .
				'<li><strong>Tomorrow</strong> (' . $fmt_disp($cp_due_end) . '): <strong>' . (int)$cp['tomorrow']['count'] . '</strong> bookings</li>' .
				'</ul>' .
				'<strong>This card:</strong> the table lists the top ' . $cp_rows_n . ' ' . $plural($cp_rows_n, 'supplier') . ' across the window, earliest deadline first.<br>' .
				'<strong>How this differs from &ldquo;Supplier Pay-out Due Soon&rdquo;:</strong> that card looks at pay-out records already created; this one flags pay-outs whose checklist still hasn&rsquo;t been actioned, so no RM amount is shown.';
		}

		if(isset($tables['product_sales'])) {
			$rows = count($tables['product_sales']);
			$popovers['pop-product-sales'] =
				'<strong>Period:</strong> ' . $window_month . ' (by the date the booking was created)<br><br>' .
				'<strong>Per product (grouped by product code):</strong>' .
				'<ul>' .
				'<li>Qty &mdash; total quantity sold</li>' .
				'<li>Sales &mdash; total sales</li>' .
				'</ul>' .
				'<strong>Order:</strong> highest sales first. Top 5.<br>' .
				'<strong>This card:</strong> ' . $rows . ' ' . $plural($rows, 'product') . ' shown.<br>' .
				'<strong>Counted:</strong> confirmed bookings only, not cancelled or draft, active product lines only.';
		}

		// Owner-managed per-card visibility: drop the data for any card the owner
		// has hidden from this user so its numbers are never sent. Cards are
		// visible by default; the matching column is also CSS-hidden in the view.
		$this->load->helper('card_visibility');
		$cv_hidden   = card_visibility_hidden_slugs_for($admin_id);
		$cv_registry = card_visibility_registry();
		foreach ($cv_hidden as $cv_slug => $cv_on) {
			if (!isset($cv_registry[$cv_slug])) { continue; }
			foreach ($cv_registry[$cv_slug]['keys'] as $cv_key) {
				unset($cards[$cv_key], $tables[$cv_key]);
			}
		}

		$this->send_json(array(
			'level'    => $level,
			'cards'    => $cards,
			'tables'   => $tables,
			'meta'     => $meta,
			'popovers' => $popovers,
		));
		} catch (\Throwable $e) {
			log_message('error', 'Booking ajax_summary_cards error: ' . $e->getMessage());
			$this->send_json(array('error' => 'An error occurred while loading summary cards'));
		}
	}

	/**
	 * View-only "Cancellations (Month)" card for OP (40) and OP TEAM LEAD (45).
	 *
	 * Its own endpoint (not part of ajax_summary_cards) because the card carries
	 * its own month picker: OP can flip to any month without reloading the whole
	 * summary. Returns the OP team's cancelled BC count and rate for the picked
	 * month, plus a drill-down link to that month's cancelled list.
	 *
	 * Scope + counting rule mirror the other OP cards and the TC cancellation
	 * card: team-wide by booking.SalesAgent, confirmed BCs only, duplicate
	 * cancellations excluded, counted by when the booking was created.
	 */
	function ajax_op_monthly_cancellation()
	{
		$this->begin_json_endpoint();

		try {
			$level = (int) $this->session->userdata('level');
			// Gate to OP / OP TEAM LEAD only (matches the card's display gate).
			if(!in_array('VB', $this->session->access_control) || !in_array($level, array(40, 45), true)) {
				$this->send_json(array('error' => 'Access denied'));
				return;
			}

			$this->load->helper(array('summary_period', 'cancellation_rate', 'op_cancellation', 'team_scope'));

			$admin_id = (int) $this->session->userdata('admin_id');
			$today    = date('Y-m-d');

			// ?month=YYYY-MM picks the reporting month; invalid/missing falls back
			// to the current month (summary_resolve_month bounds it).
			$period      = summary_resolve_month($this->input->get('month'), $today);
			$month_start = $period['month_start'];
			$month_end   = $period['month_end'];

			// Same OP-team scope as the other OP cards: everyone in the user's
			// team(s) (incl. a leader's extra admin_team teams), matched on
			// booking.SalesAgent (TC1).
			$this->load->helper('team_scope');
			$op_team_ids = team_member_ids_from_db($this->db, $admin_id);
			$op_team_csv = implode(',', $op_team_ids);
			$op_sa_in    = "booking.SalesAgent IN ({$op_team_csv})";

			$row = $this->db->query(
				op_monthly_cancellation_sql($op_sa_in),
				array($month_start, $month_end)
			)->row();

			$total        = $row ? (int) $row->total : 0;
			$cancelled    = $row ? (int) $row->cancelled : 0;
			$revenue_lost = $row ? (float) $row->revenue_lost : 0.0;
			$rate         = op_cancellation_rate($cancelled, $total);

			$base    = base_url('Booking');
			$fmt_dmy = function($d) { return date('d/m/Y', strtotime($d)); };
			// Drill-down: the cancelled (status=C) BCs created in the picked
			// month, scoped to the OP team so the list matches the count.
			$link = $base . '?' . http_build_query(array(
				'status'       => 'C',
				'booking_date' => $fmt_dmy($month_start) . ' - ' . $fmt_dmy($month_end),
				'sales_agent'  => $op_team_csv,
			));

			$this->send_json(array(
				'month'        => $period['value'],
				'label'        => $period['label'],
				'cancelled'    => $cancelled,
				'total'        => $total,
				'rate'         => $rate . '%',
				'detail'       => $cancelled . ' / ' . $total,
				'revenue_lost' => 'RM ' . number_format($revenue_lost, 2, '.', ','),
				'link'         => $link,
			));
		} catch (\Throwable $e) {
			log_message('error', 'Booking ajax_op_monthly_cancellation error: ' . $e->getMessage());
			$this->send_json(array('error' => 'An error occurred while loading cancellations'));
		}
	}

	/**
	 * Set of GHL UserIDs that belong to a SALES AGENT (admin.Level = 20). Used
	 * to restrict the GHL-user-keyed "Best:" leaderboards (Reply Time, Leads,
	 * Conversion, Pickup Speed) to the sales-agent role only, mirroring the
	 * admin.Level = 20 filter on the booking-table leaderboards. Mapping table
	 * first (canonical), email match as fallback -- same bridge as the Agent
	 * Score card and the logged-in TC's own resolution.
	 *
	 * @param array $levels admin.Level values to include. Defaults to sales
	 *                      agent (20) only; pass e.g. array('20','25') to also
	 *                      count TC Leads so a TC Lead can be their own "Best".
	 * @return array associative set { ghl_user_id => true } for O(1) membership.
	 */
	private function sales_agent_ghl_uids($levels = array('20'))
	{
		$levels = array_values(array_map('strval', (array)$levels));
		if(empty($levels)) { return array(); }
		$in = implode(',', array_fill(0, count($levels), '?'));

		$uids = array();
		foreach($this->db->query(
			"SELECT alda.GhlUserID
			 FROM admin_lead_dashboard_agents alda
			 INNER JOIN admin a ON a.AdminID = alda.AdminID
			 WHERE a.Level IN ({$in}) AND NULLIF(alda.GhlUserID,'') IS NOT NULL",
			$levels
		)->result() as $r) {
			$uids[(string)$r->GhlUserID] = true;
		}
		foreach($this->db->query(
			"SELECT gu.UserID
			 FROM admin a
			 INNER JOIN ghl_users gu ON LOWER(TRIM(gu.Email)) = LOWER(TRIM(a.Email))
			 WHERE a.Level IN ({$in})",
			$levels
		)->result() as $r) {
			$uid = (string)$r->UserID;
			if($uid !== '') { $uids[$uid] = true; }
		}
		return $uids;
	}

	/**
	 * Build the "Best:" footer payload for the Lead Pickup Speed card -- the
	 * fastest-picking-up SALES AGENT team-wide for the month, shown as "You"
	 * when the winner is the logged-in TC (matched against their own GHL user
	 * ids). The comparison universe is restricted to the sales-agent role
	 * (admin.Level = 20) via sales_agent_ghl_uids(), so we score every agent
	 * row from Lead_Pickup_Speed_By_Agent and apply the same min-sample (n >= 2)
	 * and fastest-wins / name-ASC tie-break that Lead_Pickup_Speed_Best_Agent
	 * used to enforce in SQL.
	 *
	 * @param string $month_start  'Y-m-d'
	 * @param string $month_end    'Y-m-d'
	 * @param array  $my_ghl_uids  GHL user ids belonging to the logged-in TC.
	 * @return array|null { name, value } or null when nobody qualifies.
	 */
	private function tc_pickup_speed_best($month_start, $month_end, $my_ghl_uids)
	{
		$rows = $this->Report_Model->Lead_Pickup_Speed_By_Agent($month_start, $month_end);
		$sales_uid_set = $this->sales_agent_ghl_uids();
		$best = null;
		foreach($rows as $r) {
			if(!isset($sales_uid_set[(string)$r['agent_id']])) { continue; }
			if((int)$r['n'] < 2) { continue; }
			if($best === null
				|| (float)$r['avg_seconds'] < (float)$best['avg_seconds']
				|| ((float)$r['avg_seconds'] === (float)$best['avg_seconds']
					&& strcmp((string)$r['agent_name'], (string)$best['agent_name']) < 0)) {
				$best = $r;
			}
		}
		if($best === null) { return null; }

		$this->load->helper('response_time');
		$name = (!empty($my_ghl_uids) && in_array($best['agent_id'], $my_ghl_uids, true))
			? 'You'
			: $best['agent_name'];
		return array(
			'name'  => $name,
			'value' => format_response_duration($best['avg_seconds']),
		);
	}

	/**
	 * Agent Score card payload for the selected month. Builds a unified per-agent
	 * table by joining three GHL-user-keyed metrics (avg reply time + conversion
	 * via Lead_Dashboard_By_Agent, pickup speed via Lead_Pickup_Speed_By_Agent)
	 * with the admin-keyed credited-sales total, then hands the rows to the pure
	 * agent_score_helper for normalisation + ranking. Returns the logged-in
	 * agent's composite/rank plus the top performer ("You" when that's them).
	 *
	 * @param string $start       'Y-m-d'  period start
	 * @param string $end         'Y-m-d'  period end
	 * @param array  $my_ghl_uids  logged-in agent's GHL user ids (may be empty)
	 * @param int    $admin_id     logged-in agent's AdminID
	 */
	/**
	 * Set of AdminIDs the owner has excluded from the Agent Score (table
	 * agent_score_excluded_agents). Returned as admin_id => true for O(1) lookups.
	 * Excluded agents leave both the TC leaderboard and the owner matrix score.
	 */
	private function agent_score_excluded_ids()
	{
		$out = array();
		foreach($this->db->select('AdminID')->get('agent_score_excluded_agents')->result() as $r) {
			$out[(int)$r->AdminID] = true;
		}
		return $out;
	}

	private function agent_score_card($start, $end, $my_ghl_uids, $admin_id)
	{
		$this->load->helper(array('agent_score', 'lead_conversion_credit'));
		$this->load->model('Report_Model');
		$excluded = $this->agent_score_excluded_ids();

		// 1. GHL-keyed conversion (ungated, '1=1', matching the Conversion Rate
		//    (YTD) card's attribution). Reply time is sourced separately below.
		$by_agent = $this->Report_Model->Lead_Dashboard_By_Agent(
			array('start_date' => $start, 'end_date' => $end), '1=1'
		);
		// 1b. GHL-keyed reply time — the point-in-time lead-ownership reply-pair
		//     metric (Ghl_Ownership_Avg_Reply_By_Owner), the SAME source as the
		//     "Lead Reply Hourly" page's "Avg Response Time" card and the Owner
		//     matrix's REPLY TIME column, so the Agent Score's reply component is
		//     consistent everywhere reply time is shown — and this standalone card
		//     score stays equal to the same agent's row in the Owner matrix.
		$reply = $this->Report_Model->Ghl_Ownership_Avg_Reply_By_Owner($start, $end);
		// 2. GHL-keyed pickup speed (one row per agent).
		$pickup = $this->Report_Model->Lead_Pickup_Speed_By_Agent($start, $end);

		// 2b. GHL-keyed follow-up rate (owned leads with follow-up sent/completed,
		//     over all owned leads) — same definition as the Follow-up % card.
		$followup = $this->Report_Model->Lead_Ownership_By_Agent(
			array('start_date' => $start, 'end_date' => $end)
		);

		// 3. Admin-keyed credited sales: no fully-paid gate (mirrors the Month
		//    Sales card -- a BC counts whether or not it has been paid).
		$agent_expr = lead_conversion_credit_agent_expr();
		$sales_rows = $this->db->query(
			"SELECT
			   {$agent_expr} AS admin_id,
			   admin.Name AS agent_name,
			   COALESCE(SUM(booking.NetTotal), 0) AS total_sales
			 FROM booking
			 INNER JOIN admin ON admin.AdminID = {$agent_expr} AND admin.Level IN ('20','10','25') AND admin.Status='Y'
			 WHERE booking.BookingConfirmationTitle='BOOKING CONFIRMATION'
			   AND booking.CancelStatus='N'
			   AND booking.Status!='N'
			   AND booking.NetTotal > 0
			   AND CAST(booking.InsertDate AS DATE) BETWEEN ? AND ?
			 GROUP BY admin_id, agent_name
			 HAVING admin_id IS NOT NULL AND admin_id > 0",
			array($start, $end)
		)->result_array();

		// 4. Bridge GHL uid -> AdminID for every agent (generalises the logged-in
		//    resolution used by the Conversion Rate card): mapping table first,
		//    email match as fallback.
		// The Agent Score comparison population is the SALES AGENT role
		// (admin.Level = 20) plus the Owner (10) and TC Lead (25), who are scored
		// and ranked but benchmarked against the Level-20 pool only (see
		// $benchmark_admins below) — they never set the "100" anchors. No other
		// role may appear in the leaderboard.
		$map = array(); $name_by_admin = array();
		foreach($this->db->query(
			"SELECT alda.GhlUserID, alda.AdminID, a.Name
			 FROM admin_lead_dashboard_agents alda
			 INNER JOIN admin a ON a.AdminID = alda.AdminID
			 WHERE a.Level IN ('20','10','25') AND a.Status='Y' AND NULLIF(alda.GhlUserID,'') IS NOT NULL"
		)->result() as $r) {
			$map[(string)$r->GhlUserID] = (int)$r->AdminID;
			$name_by_admin[(int)$r->AdminID] = $r->Name;
		}
		foreach($this->db->query(
			"SELECT gu.UserID, a.AdminID, a.Name
			 FROM admin a
			 INNER JOIN ghl_users gu ON LOWER(TRIM(gu.Email)) = LOWER(TRIM(a.Email))
			 WHERE a.Level IN ('20','10','25') AND a.Status='Y'"
		)->result() as $r) {
			$uid = (string)$r->UserID;
			if($uid !== '' && !isset($map[$uid])) { $map[$uid] = (int)$r->AdminID; }
			if(!isset($name_by_admin[(int)$r->AdminID])) { $name_by_admin[(int)$r->AdminID] = $r->Name; }
		}

		// Everyone in the race is shown on the leaderboard — Sales Agent (20),
		// Owner (10) and TC Lead (25) alike — so the "of N agents" total is
		// identical for every viewer, no matter who is looking. The only thing
		// that removes someone from the race is the owner-managed excluded list.

		// 5. Fold everything into one row per AdminID. Reply/pickup are
		//    lead-weighted so a TC owning several GHL inboxes aggregates fairly.
		$u = array();
		$ensure = function(&$u, $aid) use ($name_by_admin) {
			if(!isset($u[$aid])) {
				$u[$aid] = array(
					'name' => isset($name_by_admin[$aid]) ? $name_by_admin[$aid] : '',
					'reply_sum' => 0.0, 'reply_n' => 0,
					'pickup_sum' => 0.0, 'pickup_n' => 0,
					'leads' => 0, 'converted' => 0, 'sales' => 0.0,
					'fu_owned' => 0, 'fu_followed' => 0,
					'has_leads' => false, 'has_sales' => false, 'has_fu' => false,
					'is_pool' => false,
				);
			}
		};

		// Seed the full eligible pool so every active sales agent counts toward the
		// "of N agents" total even with zero activity this period. Without this, an
		// agent who logged no leads/sales/follow-ups vanishes from the leaderboard
		// (its denominator shrinks); with it they still rank — bottom, score 0 —
		// which is the fair reading of "ranked against the whole team". The score
		// helper still drops excluded/hidden members, so the visible pool is
		// unchanged apart from these otherwise-missing zero-activity agents.
		foreach($this->db->query(
			"SELECT AdminID, Name FROM admin WHERE Level IN ('20','10','25') AND Status='Y'"
		)->result() as $r) {
			$aid = (int)$r->AdminID;
			if(!isset($name_by_admin[$aid])) { $name_by_admin[$aid] = $r->Name; }
			$ensure($u, $aid);
			$u[$aid]['name']    = ($u[$aid]['name'] !== '') ? $u[$aid]['name'] : $r->Name;
			$u[$aid]['is_pool'] = true;
		}
		foreach($by_agent as $a) {
			$uid = (string)$a['agent_id'];
			if($uid === '__unassigned__' || !isset($map[$uid])) { continue; }
			$aid = $map[$uid];
			$ensure($u, $aid);
			$u[$aid]['leads']     += (int)$a['total_leads'];
			$u[$aid]['converted'] += (int)$a['converted_leads'];
			$u[$aid]['has_leads']  = true;
		}
		// Reply time: point-in-time lead-ownership metric, weighted by the reply
		// sample (total_leads = conversations replied to), matching the Lead Reply
		// Hourly "Avg Response Time" card and the Owner matrix REPLY TIME column.
		foreach($reply as $r) {
			$uid = (string)$r['agent_id'];
			if(!isset($map[$uid])) { continue; }
			$aid = $map[$uid];
			$ensure($u, $aid);
			$n = (int)$r['total_leads'];
			if($r['avg_response_time_seconds'] !== null && $n > 0) {
				$u[$aid]['reply_sum'] += (float)$r['avg_response_time_seconds'] * $n;
				$u[$aid]['reply_n']   += $n;
			}
		}
		foreach($pickup as $p) {
			$uid = (string)$p['agent_id'];
			if(!isset($map[$uid])) { continue; }
			$aid = $map[$uid];
			$ensure($u, $aid);
			$n = (int)$p['n'];
			if($n > 0) {
				$u[$aid]['pickup_sum'] += (float)$p['avg_seconds'] * $n;
				$u[$aid]['pickup_n']   += $n;
			}
		}
		foreach($sales_rows as $s) {
			$aid = (int)$s['admin_id'];
			if($aid <= 0) { continue; }
			$ensure($u, $aid);
			$u[$aid]['sales']     += (float)$s['total_sales'];
			$u[$aid]['has_sales']  = true;
			if($u[$aid]['name'] === '') { $u[$aid]['name'] = $s['agent_name']; }
		}
		foreach($followup as $fr) {
			$uid = (string)$fr['owner_user_id'];
			if(!isset($map[$uid])) { continue; }
			$aid = $map[$uid];
			$ensure($u, $aid);
			$u[$aid]['fu_owned']    += (int)$fr['owned_leads'];
			$u[$aid]['fu_followed'] += (int)$fr['follow_up_leads'];
			$u[$aid]['has_fu']       = true;
			if($u[$aid]['name'] === '' && !empty($fr['owner_name'])) { $u[$aid]['name'] = $fr['owner_name']; }
		}

		// 6. Eligible = a pool member (seeded above) OR had leads/sales/owned leads.
		//    Derive per-agent metric values. Zero-activity pool members fall through
		//    with null metrics, score 0, and rank last — still counted in the total.
		$agents = array();
		foreach($u as $aid => $row) {
			if(!$row['is_pool'] && !$row['has_leads'] && !$row['has_sales'] && !$row['has_fu']) { continue; }
			$agents[] = array(
				'admin_id'      => $aid,
				'name'          => $row['name'] !== '' ? $row['name'] : '#' . $aid,
				'reply_secs'    => $row['reply_n']  > 0 ? $row['reply_sum']  / $row['reply_n']  : null,
				'pickup_secs'   => $row['pickup_n'] > 0 ? $row['pickup_sum'] / $row['pickup_n'] : null,
				'conv_rate'     => $row['leads'] > 0 ? ($row['converted'] / $row['leads']) * 100 : null,
				'sales'         => $row['has_sales'] ? $row['sales'] : null,
				'followup_rate' => $row['fu_owned'] > 0 ? ($row['fu_followed'] / $row['fu_owned']) * 100 : null,
				'served_leads'  => $row['fu_owned'] > 0 ? $row['fu_owned'] : null,
				'pickup_n'      => $row['pickup_n'],
				'leads_n'       => $row['leads'],
				'owned_n'       => $row['fu_owned'],
				// Nobody is hidden here — every level is shown, so the total is the
				// same for all viewers. Only the excluded list removes an agent.
				'hidden'        => false,
				// Owner-excluded agents drop out of the benchmark + leaderboard.
				'excluded'      => isset($excluded[$aid]),
			);
		}

		$res = agent_score_compute($agents);
		$own = isset($res['by_admin'][(int)$admin_id]) ? $res['by_admin'][(int)$admin_id] : null;
		$top = $res['top'];
		$best = null;
		if($top) {
			$top_name = ((int)$top['admin_id'] === (int)$admin_id) ? 'You' : $top['name'];
			$best = array('name' => $top_name, 'value' => round($top['composite'], 1) . '%');
		}
		// Top-5 leaderboard for the card's right column — the logged-in agent is
		// labelled "You" so they spot themselves even when outside the top 5.
		$leaderboard = array();
		foreach(array_slice($res['ranked'], 0, 5) as $r) {
			$is_you = ((int)$r['admin_id'] === (int)$admin_id);
			$leaderboard[] = array(
				'rank'   => $r['rank'],
				'name'   => $is_you ? 'You' : $r['name'],
				'value'  => round($r['composite'], 1) . '%',
				'is_you' => $is_you,
			);
		}
		return array(
			'value'       => $own ? (round($own['composite'], 1) . '%') : '-',
			'raw'         => $own ? $own['composite'] : null,
			'rank'        => $own ? $own['rank'] : null,
			'total'       => $res['total'],
			'breakdown'   => $own ? $own['norm'] : null,
			'best'        => $best,
			'leaderboard' => $leaderboard,
		);
	}

	/**
	 * OWNER per-agent performance matrix — one row per visible TC sales agent
	 * (admin Level 20/50) with all 11 owner metrics over a single resolved period.
	 * The Owner (10) and TC Lead (25) are folded into the Agent Score CALCULATION
	 * (they set the 100-anchors alongside Level 20) but are NOT rendered as rows —
	 * included in the calc, not displayed:
	 *   1 reply time · 2 pickup speed · 3 new leads · 4 served leads ·
	 *   5 gated conversion · 6 ungated conversion · 7 outbound · 8 sales ·
	 *   9 follow-up % · 10 cancellation % · 11 composite Agent Score.
	 *
	 * Generalises agent_score_card(): it fetches the same GHL-keyed sources plus
	 * outbound + cancellation, bridges GHL uid -> AdminID, and hands everything to
	 * the pure owner_agent_matrix_build() fold (unit-tested in isolation).
	 *
	 * Only the sources the selected period actually reports are fetched — a
	 * column that would render "-" for $period runs no query and no calculation:
	 *   - reply (Message-Log) + follow-up/served: Day / Week / Month only
	 *     (reply over a full year would load ~250k message rows and exhaust memory)
	 *   - gated conversion + cancellation: Year only
	 *   - outbound: Day / Week / Month only
	 *   - sales: Month / Year only
	 *   - Agent Score: Month only
	 * Pickup and new-leads/conversion are cheap grouped queries that feed all-period
	 * columns, so they are always fetched.
	 *
	 * @param string $start  'Y-m-d' period start
	 * @param string $end    'Y-m-d' period end
	 * @param string $period day|week|month|year (drives the skip rules above)
	 * @param int[]|null $restrict_admin_ids  When a non-empty id list, the matrix
	 *        is scoped to exactly these admins — every admin-keyed source (roster,
	 *        GHL bridge, credited sales, cancellation) is filtered to the set, so
	 *        the rendered rows, the 100-anchors AND the Agent Score are all computed
	 *        WITHIN that group. Used by the Team Lead (25) to see only their own
	 *        team. null => company-wide (the Owner view), unchanged.
	 * @return array list of matrix rows (see owner_agent_matrix_helper.php)
	 */
	private function owner_agent_matrix($start, $end, $period, $restrict_admin_ids = null)
	{
		$this->load->helper(array('agent_score', 'owner_agent_matrix', 'lead_conversion_credit'));
		$this->load->model('Report_Model');

		// Optional team scope. Build a safe inlined "IN (...)" of ints once, plus a
		// fast lookup for the PHP-side cancellation filter. A null / empty list
		// leaves every clause blank => the query is company-wide (Owner view).
		$restrict = null;
		if(is_array($restrict_admin_ids) && !empty($restrict_admin_ids)) {
			$restrict = array_values(array_unique(array_filter(array_map('intval', $restrict_admin_ids), function($v) { return $v > 0; })));
		}
		$restrict_lookup = $restrict ? array_flip($restrict) : null;
		$in_list  = $restrict ? implode(',', $restrict) : '';
		$scope_admin = $restrict ? (' AND admin.AdminID IN (' . $in_list . ')') : ''; // tables aliased "admin"/unaliased
		$scope_a     = $restrict ? (' AND a.AdminID IN (' . $in_list . ')') : '';     // tables aliased "a"

		$filters = array('start_date' => $start, 'end_date' => $end);
		// Only Reply time and Served stay Day / Week / Month (they scan raw message
		// rows — a Year window would OOM / run long); every other column is fetched
		// on all periods. $is_dwm gates just those two heavy sources.
		$is_dwm = in_array($period, array('day', 'week', 'month'), true);

		// Ungated ('1=1') gives new-lead totals (D/W/M) + ungated conversion (Year)
		// + the Month score's conversion input — needed on every period, and it is a
		// cheap grouped query. Pickup likewise (grouped) shows on all periods.
		$leads_ungated = $this->Report_Model->Lead_Dashboard_By_Agent($filters, '1=1');
		$pickup        = $this->Report_Model->Lead_Pickup_Speed_By_Agent($start, $end);

		// Reply time uses the point-in-time lead-ownership reply-pair metric — the
		// SAME source as the "Lead Reply Hourly" page's "Avg Response Time" card
		// (Ghl_Ownership_Avg_Reply_By_Owner) — so an agent's matrix reply time equals
		// their number on that report to the second. It credits the reply to whoever
		// OWNED the lead when the message was sent (not whoever is assigned now), and
		// counts only the owner's own outbound replies. Loads every message row in the
		// window and pairs them in PHP, so a full YEAR (~290k rows) exhausts the PHP
		// memory limit (measured OOM) — the one column that MUST stay Day / Week /
		// Month and dash on Year. All other columns open on every period.
		$reply         = $is_dwm ? $this->Report_Model->Ghl_Ownership_Avg_Reply_By_Owner($start, $end) : array();
		// Follow-up source (Follow-up % + Served fallback). A grouped per-agent query
		// (~17k ownership rows/year), so it is cheap enough to run on every period.
		$followup      = $this->Report_Model->Lead_Ownership_By_Agent($filters);

		// Gated conversion (Conv % credited) — same cheap grouped shape as the ungated
		// leads query above, so run it on every period (not Year-only).
		$leads_gated   = $this->Report_Model->Lead_Dashboard_By_Agent($filters);
		// Outbound is reported on every period — a DB-side grouped COUNT over
		// ghl_messages, so even the Year window is a single cheap aggregate (unlike
		// Reply, which loads raw rows into PHP). Fetched for Day / Week / Month / Year.
		$outbound      = $this->Report_Model->Outbound_Messages_By_Agent($start, $end);
		// Cancellation — grouped per-agent aggregate over booking, reported every period.
		$cancellation  = $this->Report_Model->Cancellation_By_Agent($start, $end);
		// Cancellation is admin-keyed and folds via $ensure() (it would otherwise
		// seed a row for any agent), so — unlike the GHL-bridged / SQL-scoped
		// sources — it is the one source the team restriction must trim in PHP.
		if($restrict_lookup !== null) {
			$cancellation = array_values(array_filter($cancellation, function($c) use ($restrict_lookup) {
				return isset($restrict_lookup[(int)$c['admin_id']]);
			}));
		}

		// Admin-keyed credited sales — actual BC value, NO fully-paid gate (mirrors
		// the TC Sales-Actual rule). Fetched on every period: it is the Agent Score's
		// biggest input (Sales = 45% of the composite), so the short-window score has a
		// real value instead of capping at ~55. Scoped to the TC sales-agent role
		// (Level 20/50) via the same credited-slot expression used everywhere else.
		$agent_expr  = lead_conversion_credit_agent_expr();
		$sales_rows  = $this->db->query(
			"SELECT
			   {$agent_expr} AS admin_id,
			   admin.Name AS agent_name,
			   COALESCE(SUM(booking.NetTotal), 0) AS total_sales
			 FROM booking
			 INNER JOIN admin ON admin.AdminID = {$agent_expr} AND admin.Level IN ('20','50','10','25') AND admin.Status='Y'
			 WHERE booking.BookingConfirmationTitle='BOOKING CONFIRMATION'
			   AND booking.CancelStatus='N'
			   AND booking.Status!='N'
			   AND booking.NetTotal > 0
			   AND CAST(booking.InsertDate AS DATE) BETWEEN ? AND ?{$scope_admin}
			 GROUP BY admin_id, agent_name
			 HAVING admin_id IS NOT NULL AND admin_id > 0",
			array($start, $end)
		)->result_array();

		// Bridge GHL uid -> AdminID, restricted to the scored population — the TC
		// sales-agent role (Level 20/50) plus the Owner (10) and TC Lead (25):
		// mapping table first, corporate-email match as fallback.
		$map = array(); $name_by_admin = array();
		foreach($this->db->query(
			"SELECT alda.GhlUserID, alda.AdminID, a.Name
			 FROM admin_lead_dashboard_agents alda
			 INNER JOIN admin a ON a.AdminID = alda.AdminID
			 WHERE a.Level IN ('20','50','10','25') AND a.Status='Y' AND NULLIF(alda.GhlUserID,'') IS NOT NULL{$scope_a}"
		)->result() as $r) {
			$map[(string)$r->GhlUserID] = (int)$r->AdminID;
			$name_by_admin[(int)$r->AdminID] = $r->Name;
		}
		foreach($this->db->query(
			"SELECT gu.UserID, a.AdminID, a.Name
			 FROM admin a
			 INNER JOIN ghl_users gu ON LOWER(TRIM(gu.Email)) = LOWER(TRIM(a.Email))
			 WHERE a.Level IN ('20','50','10','25') AND a.Status='Y'{$scope_a}"
		)->result() as $r) {
			$uid = (string)$r->UserID;
			if($uid !== '' && !isset($map[$uid])) { $map[$uid] = (int)$r->AdminID; }
			if(!isset($name_by_admin[(int)$r->AdminID])) { $name_by_admin[(int)$r->AdminID] = $r->Name; }
		}
		// Complete the name lookup for EVERY scored admin (Level 20/50 + Owner 10 +
		// TC Lead 25), and capture the benchmark + hidden subsets. The bridge above
		// only names agents reachable through a GHL mapping/email; an agent who
		// surfaces solely via an admin-keyed source that carries no name (e.g. a
		// cancelled-only BC) would otherwise fall back to "#<AdminID>".
		// $benchmark_admins are the 100-anchors: Level 20 sales agents PLUS the Owner
		// (10) and TC Lead (25), so an L20 agent's matrix Score equals their Agent
		// Score card (L50 rows are scored against this pool but never anchor).
		// $hidden_admins (Owner only) anchor the score but are dropped from the
		// rendered rows — EXCEPT an owner who personally sold in the window, who is
		// shown as a normal agent row (owner-account sales only; a disabled duplicate
		// account never joins $sales_rows). TC Lead (25) always anchors AND shows.
		$sold_admin_ids = array();
		foreach($sales_rows as $s) {
			if((float)$s['total_sales'] > 0) { $sold_admin_ids[(int)$s['admin_id']] = true; }
		}
		$benchmark_admins = array();
		$roster_rows = $this->db->query(
			"SELECT AdminID, Name, Level FROM admin WHERE Level IN ('20','50','10','25') AND Status='Y'{$scope_admin}"
		)->result();
		foreach($roster_rows as $r) {
			if(!isset($name_by_admin[(int)$r->AdminID])) { $name_by_admin[(int)$r->AdminID] = $r->Name; }
			$lvl = (string)$r->Level;
			if($lvl === '20' || $lvl === '10' || $lvl === '25') { $benchmark_admins[(int)$r->AdminID] = true; }
		}
		// Owners with no own sales this window stay anchored-but-hidden; selling
		// owners drop out of the hidden set and render as rows (see helper test).
		$hidden_admins = owner_agent_matrix_hidden_owner_ids($roster_rows, $sold_admin_ids);

		// Served mirrors the Lead Reply Activity "Lead Responded" metric: distinct
		// leads the agent replied to in the period (business-hours gated, keyed by
		// reply date), de-duplicated across every GHL inbox one agent owns. This is
		// display-only — Follow-up % and the Agent Score still use owned-leads.
		// Reported on Day / Week / Month only, same as the owned-leads source.
		$responded = array();
		if($is_dwm) {
			$uids_by_admin = array();
			foreach($map as $uid => $aid) { $uids_by_admin[(int)$aid][(string)$uid] = true; }
			foreach($uids_by_admin as $aid => $uid_set) {
				$responded[] = array(
					'admin_id'        => (int)$aid,
					'responded_leads' => (int)$this->Report_Model->Lead_Reply_Activity_Responded_Distinct_For_Uids(array_keys($uid_set), $start, $end),
				);
			}
		}

		// Agent Score is reported on Day / Week / Month — every one of its six inputs
		// (reply, pickup, conversion, sales, follow-up, served) is now fetched for
		// those windows. Year is skipped: it has no reply / follow-up / served source,
		// so a year score would silently zero 35% of the composite. Skipping the
		// scoring work there leaves agent_score null and the "-" column does no calc.
		$compute_score = in_array($period, array('day', 'week', 'month'), true);
		return owner_agent_matrix_build(array(
			'leads_ungated' => $leads_ungated,
			'leads_gated'   => $leads_gated,
			'reply'         => $reply,
			'pickup'        => $pickup,
			'followup'      => $followup,
			'responded'     => $responded,
			'outbound'      => $outbound,
			'sales'         => $sales_rows,
			'cancellation'  => $cancellation,
		), $map, $name_by_admin, $benchmark_admins, $compute_score, $this->agent_score_excluded_ids(), $hidden_admins);
	}

	function Create()
	{
		if(in_array('GB', $this->session->access_control)) {
			if ($this->input->is_ajax_request()) {

				$booking_id = $this->Booking_Model->Create();

				// Draft: created before staff know pricing/suppliers. Park the
				// booking in SAD ("SAVE AS DRAFT") so the booking list shows it as
				// a draft, and reflect that in the status log. This SAD log row is
				// the start anchor for the Draft -> Payment response-time metric.
				$is_draft_intake = (string) $this->input->post('is_draft_intake') === '1';
				if ($is_draft_intake) {
					$this->load->helper('booking_status_log');
					$this->Booking_Model->update_by_id($booking_id, array('Status' => 'SAD'));
					$created_by = (int) $this->session->userdata('admin_id');
					log_booking_status_change(
						$booking_id,
						'SAD',
						'PBC',
						$created_by,
						'Booking saved as draft',
						true
					);
				}

				// A draft is created with no products yet, so guard the batch
				// insert (insert_batch errors on an empty set).
				if (!empty($this->input->post('booking_products'))) {
					$this->Booking_Product_Model->Create($this->input->post('booking_products'), $booking_id);

					// Recompute Subtotal from booking_product totals to keep booking.Subtotal authoritative
					$this->Booking_Product_Model->Recompute_Subtotal($booking_id);
				}

				// Create rooms if provided
				$booking_rooms = $this->input->post('booking_rooms');
				if (!empty($booking_rooms)) {
					foreach ($booking_rooms as $room) {
						$this->db->insert('guest_list_room', array(
							'booking_id' => $booking_id,
							'room_name' => strtoupper($room['room_name']),
							'adult_count' => (int)$room['adult_count'],
							'child_count' => (int)$room['child_count'],
							'infant_count' => (int)$room['infant_count'],
							'InsertBy' => $this->session->userdata('admin_id'),
							'InsertDate' => date('Y-m-d H:i:s')
						));
					}
				}

				// Create GL entries based on room data
				$this->Booking_Model->Create_GL_From_Rooms($booking_id);

				// Auto-enable insurance if any product belongs to an insurance category
				$this->db->from('booking_product');
				$this->db->join('product', 'product.ProductID = booking_product.ProductID', 'left');
				$this->db->join('category', 'category.CategoryID = product.CategoryID', 'left');
				$this->db->where('booking_product.BookingID', $booking_id);
				$this->db->where('booking_product.Status', 'Y');
				$this->db->like('category.Name', 'Insurance', 'both');
				if($this->db->count_all_results() > 0) {
					$this->Booking_Model->update_by_id($booking_id, ['TravelInsuranceStatus' => 'Y']);
				}

				$this->Booking_Model->update_by_id($booking_id, [
						'AutocountSyncAction'  => 'C'
				]);
				// $bookingData = $this->Booking_Model->getAllBookingsWithGuests($booking_id);
				// if (!empty($bookingData)) {
				// 	$bookingData = (array) $bookingData[0]; 
				// }

				// $statuses = !empty($this->config->item('booking_sync_status')) ? $this->config->item('booking_sync_status') : ['BOOKING CONFIRMATION'];

				// if (in_array($bookingData['BookingConfirmationTitle'], $statuses)) {
				// 	$bookingProducts = $this->Booking_Model->getAllBookingsWithProducts($booking_id);
				// 	$bookingProducts = array_map('get_object_vars', $bookingProducts); // convert to array

				// 	$quotationData = [
				// 		'BookingNumber'   => $bookingData['BookingNumber'] ?? '',
				// 		'InsertDate'      => $bookingData['InsertDate'] ?? date('Y-m-d'),
				// 		'Customer'        => $bookingData['Customer'] ?? '',
				// 		'guest_email'     => $bookingData['guest_email'] ?? '',
				// 		'guest_address'   => $bookingData['guest_address'] ?? '',
				// 		'guest_phone'     => $bookingData['guest_phone'] ?? '',
				// 		'BokingRemark'    => $bookingData['BokingRemark'] ?? '',
						
				// 		// Fields not in DB → set default or null
				// 		'credit_term'     => null,
				// 		'sales_location'  => '',
				// 		'currency_rate'   => 1,
				// 		'inclusive_tax'   => false,
				// 		'is_round_adj'    => false,
				// 		'tax_code'        => '',

				// 		// Details
				// 		'booking_product' => $bookingProducts
				// 	];
					
				// 	$respond = $this->autocount_create($quotationData);

				// 	$booking = $this->Booking_Model->find($booking_id);
				// 	if ($booking != null && $respond != null) {
				// 		if (!empty($respond['error'])) {
				// 			$this->Booking_Model->update_by_id($booking_id, [
				// 				'AutocountSyncMessage' => json_encode($respond),
				// 			]);
				// 		} elseif ($respond['status'] === 201 || $respond['status'] === 204) {
				// 			$this->Booking_Model->update_by_id($booking_id, [
				// 				'AutocountSyncMessage' => json_encode($respond),
				// 				'AutocountSyncStatus'  => 'C'
				// 			]);
				// 		}
				// 	}
				// }
        } else {
				$titles = array('tab_title' => 'HolidayGoGoGo | Booking', 'breadcrumb_title' => 'Booking >> Create');
					$array = array('BookingID' => 'NA', 'BookingConfirmationFooterID' => 'NA', 'TravelVoucherFooterID' => 'NA', 'BookingNumber' => 'NA', 'Tag' => array(), 'Discount' => 'NA', 'NetTotal' => 'NA', 'ProductSequence' => array(), 'BookingProductID' => ($this->Booking_Product_Model->Read_Last_Booking_Product_ID()) + 1, 'AllowReview' => 1, 'ic_passport_no' => '', 'tin_no' => '', 'customer_types_selected' => array());
					$array['admins'] = $this->Booking_Model->Read_Admins();
					$array['notify_admins'] = $this->Notification_Model->Build_Admin_Handles($this->Booking_Model->Read_Notify_Admins());
					$array['booking_op_admins'] = $this->Booking_Model->Read_Booking_OP_Admins();
					$array['booking_products'][0] = (object) array('BookingProductID' => 'NA');
				$array['categories'] = $this->Booking_Model->Read_Categories();
				$array['products'] = $this->Booking_Model->Read_Products();
				$array['category_products'] = $this->Booking_Model->Read_Category_Products();
				$array['footers'] = $this->Booking_Model->Read_Footers();
				$array['country_codes'] = $this->Booking_Model->Read_Country_Codes();
				$array['tags'] = $this->Booking_Model->Read_Tags();
				$array['sources'] = $this->Booking_Model->Read_Sources();
				$array['customer_types'] = $this->Customer_Type_Model->Read_Customer_Types();
				$array['supplier_invoices'] = [];
				$array['supplier_invoice_suppliers'] = $this->Payment_Model->Read_Suppliers();
				$array['draft_payment_seconds'] = null;
				$array['draft_payment_breakdown'] = null;
				$array['is_slow_conversion'] = false;
				$array['slow_conversion_reasons'] = array();
				$array['slow_conversion_reason_selected'] = array();
				$this->load->view('layout/header', $titles);
				$this->load->view('booking/booking', $array);
				$this->load->view('layout/footer');
			}
		} else {
			redirect('Dashboard');
		}
	}

	function View_Snapshot()
	{
		if (!$this->session->has_userdata('admin_id')) {
			$this->load->view('errors/access_denied');
			return;
		}

		$file = $this->input->get('file');
		if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.pdf$/', $file)) {
			show_404();
			return;
		}

		$path = FCPATH . 'assets/upload/booking_snapshots/' . $file;
		if (!file_exists($path)) {
			show_404();
			return;
		}

		$v = $this->input->get('v');
		$vFresh = !empty($v) && ctype_digit((string)$v) && (time() - intval($v)) <= 5;

		if (!$vFresh) {
			header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
			header('Pragma: no-cache');
			header('Expires: 0');
			header('Location: ' . base_url('Booking/View_Snapshot?file=' . urlencode($file) . '&v=' . time()), true, 302);
			exit;
		}

		if (ob_get_length()) { ob_end_clean(); }

		header('Content-Type: application/pdf');
		header('Content-Disposition: inline; filename="' . $file . '"');
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
		header('Pragma: no-cache');
		header('Expires: 0');
		header('Content-Length: ' . filesize($path));
		readfile($path);
		exit;
	}

	function Update()
	{
		if($this->session->userdata('level') == 20) {
			// check if user is sales agent && booking >= booking.startDate
			$valid_booking_id = $this->Universal_Model->Validate_Id('BookingID', $this->input->get('booking_id'), 'booking');

			if($valid_booking_id) {
				$booking = $this->Booking_Model->Read_Booking();
				$today = strtotime(date('Y-m-d'));
				$startDate = strtotime($booking['StartDate']);

				if ($today >= $startDate) {
					// if travel started, redirect to view booking page
					redirect('Booking/View?booking_id=' . $this->input->get('booking_id'));
				}
			}
		}

		// Block SA and TC from updating completed bookings
		if(in_array($this->session->userdata('level'), [20, 50])) {
			$valid_booking_id = $this->Universal_Model->Validate_Id('BookingID', $this->input->get('booking_id'), 'booking');
			if($valid_booking_id) {
				$booking = $this->Booking_Model->Read_Booking();
				if($booking['Status'] == 'Y' && $booking['AfterSalesService'] == 'COMPLETE') {
					if($this->input->is_ajax_request()) {
						echo json_encode(array('error' => 'Access denied'));
					} else {
						$this->load->view('errors/access_denied');
					}
					return;
				}
			}
		}
		
		if(in_array('AB', $this->session->access_control)) {
			if($this->input->is_ajax_request()) {
				// Capture the booking's pre-save Status so that we can graduate a
				// "Save as Draft" (SAD) booking to PB / PBC once the staff member
				// picks a graduate button.
				$intake_pre_save_status = null;
				$intake_post_booking_id = $this->input->post('booking_id');
				if (!empty($intake_post_booking_id) && is_numeric($intake_post_booking_id)) {
					$intake_pre = $this->db->select('Status')
						->where('BookingID', (int) $intake_post_booking_id)
						->get('booking')->row();
					$intake_pre_save_status = !empty($intake_pre) ? $intake_pre->Status : null;
				}

				// Always persist IC/Passport No. and TIN No. on the linked customer when posted,
				// independent of whether other booking fields changed.
				$posted_ic = $this->input->post('ic_passport_no');
				$posted_tin = $this->input->post('tin_no');
				$posted_customer_id = $this->input->post('CustomerID');
				if (!empty($posted_customer_id) && is_numeric($posted_customer_id) && (!empty($posted_ic) || $posted_tin !== null)) {
					$update = ['updated_at' => date('Y-m-d H:i:s')];
					if (!empty($posted_ic)) {
						$update['ic_passport_no'] = strtoupper($posted_ic);
					}
					if ($posted_tin !== null) {
						$update['tin_no'] = strtoupper(trim($posted_tin));
					}
					$this->Customer_Model->update_by_id($posted_customer_id, $update);
				}

				// Always sync per-booking customer types when posted, independent of
				// whether any booking field changed (booking[0] may have only the
				// stub BookingID/UpdateBy/UpdateDate fields).
				$posted_booking_id = $this->input->post('booking_id');
				$posted_customer_types = $this->input->post('customer_type');
				if (!empty($posted_booking_id) && is_numeric($posted_booking_id) && is_array($posted_customer_types)) {
					$this->load->model('Booking_Customer_Type_Model');
					$this->Booking_Customer_Type_Model->Sync($posted_booking_id, $posted_customer_types);
				}

				// Draft write guard: while a booking sits in SAD ("SAVE AS DRAFT")
				// the admin form disables every field but a small whitelist.
				// Re-enforce that server-side so a tampered request can't write
				// locked columns — strip booking[0] down to the editable fields
				// plus the structural keys the model needs. The guard lifts once
				// the draft graduates out of SAD (Save as Pending BC / PBC).
				if ($intake_pre_save_status === 'SAD') {
					$this->load->helper('booking_draft');
					$posted_booking = $this->input->post('booking');
					if (!empty($posted_booking) && isset($posted_booking[0]) && is_array($posted_booking[0])) {
						$allowed = array_merge(draft_editable_fields(), array('BookingID', 'UpdateBy', 'UpdateDate'));
						foreach (array_keys($posted_booking[0]) as $col) {
							if (!in_array($col, $allowed, true)) {
								unset($posted_booking[0][$col]);
							}
						}
						$_POST['booking'] = $posted_booking;
					}
				}

				// Booking
				// Action : Update
				if(!empty($this->input->post('booking')) && count($this->input->post('booking')[0]) > 3) {
					$this->Booking_Model->Update();
					$this->Booking_Model->Create_Booking_Log();
				} else {
					// Partial booking updates skip Booking_Model::Update() above, but the
					// linked customer's name/phone_number should still stay in sync with the
					// booking row (needed for the customer portal slug).
					$this->Booking_Model->Sync_Customer_From_Booking($this->input->post('booking_id'));
				}

				// Booking Product
				// Action : Create
				if(!empty($this->input->post('booking_products')[0])) {
					$this->Booking_Product_Model->Create($this->input->post('booking_products')[0], $this->input->post('booking_id'));
					$booking = $this->Booking_Model->Read_Product_Sequence();
					$booking_products = $this->Booking_Product_Model->Read_Booking_Products($this->input->post('booking_id'));
					if(!empty($booking['ProductSequence'])) {
						$booking['ProductSequence'] = explode(',', $booking['ProductSequence']);
						foreach($booking_products as $booking_product) {
							if(!in_array($booking_product->BookingProductID, $booking['ProductSequence'])) {
								array_push($booking['ProductSequence'], $booking_product->BookingProductID);
							}
						}
						$this->Booking_Model->Update_Product_Sequence(implode(',', $booking['ProductSequence']));
					}
					// Check if price changed due to new product (will be checked when booking NetTotal is updated)
				}
				// Action : Update
				if(!empty($this->input->post('booking_products')[1])) {
					$this->Booking_Product_Model->Update($this->input->post('booking_products')[1]);
					// Check if price changed due to product update (will be checked when booking NetTotal is updated)
				}
				// Action : Delete
				if(!empty($this->input->post('booking_products')[2])) {
					$this->Booking_Product_Model->Update($this->input->post('booking_products')[2]);
					$booking = $this->Booking_Model->Read_Product_Sequence();
					$booking_products = $this->Booking_Product_Model->Read_Booking_Products($this->input->post('booking_id'));
					$array = [];
					if(!empty($booking['ProductSequence'])) {
						$booking['ProductSequence'] = explode(',', $booking['ProductSequence']);
						foreach($booking_products as $booking_product) {
							array_push($array, $booking_product->BookingProductID);
						}
						for($i = 0; $i < count($booking['ProductSequence']); $i++) {
							if(!in_array($booking['ProductSequence'][$i], $array)) {
								unset($booking['ProductSequence'][$i]);
							}
						}
						$this->Booking_Model->Update_Product_Sequence(implode(',', $booking['ProductSequence']));
					}
					// Check if price changed due to product deletion (will be checked when booking NetTotal is updated)
				}

				// Supplier Invoices: same three-bucket POST shape as booking_products.
				$invoices_post = $this->input->post('booking_supplier_invoices');
				if (!empty($invoices_post[0])) {
					$this->Booking_Supplier_Invoice_Model->Create($invoices_post[0], $this->input->post('booking_id'));
				}
				if (!empty($invoices_post[1])) {
					$this->Booking_Supplier_Invoice_Model->Update($invoices_post[1]);
				}
				if (!empty($invoices_post[2])) {
					$this->Booking_Supplier_Invoice_Model->Update($invoices_post[2]);
				}

				// Bell notification for booking-update fires only when the travel
				// window (StartDate / EndDate) or booking products changed.
				$this->load->helper('booking_change_summary');
				$log_rows = $this->input->post('booking_log') ?: [];
				$bp_for_notify = $this->input->post('booking_products');
				$products_create = (is_array($bp_for_notify) && !empty($bp_for_notify[0])) ? $bp_for_notify[0] : [];
				$products_update = (is_array($bp_for_notify) && !empty($bp_for_notify[1])) ? $bp_for_notify[1] : [];
				$products_delete = (is_array($bp_for_notify) && !empty($bp_for_notify[2])) ? $bp_for_notify[2] : [];

				$travel_date_changed = false;
				foreach ($log_rows as $row) {
					$arr = is_object($row) ? get_object_vars($row) : (array)$row;
					$col = isset($arr['Column']) ? $arr['Column'] : null;
					if (($col === 'StartDate' || $col === 'EndDate')
						&& (string)(isset($arr['CurrentData']) ? $arr['CurrentData'] : '') !== (string)(isset($arr['NewData']) ? $arr['NewData'] : '')) {
						$travel_date_changed = true;
						break;
					}
				}
				$products_changed = booking_products_have_changes($products_create, $products_update, $products_delete);

				if ($products_changed) {
					$this->Booking_Model->Revert_To_PBC_For_Product_Change(
						$this->input->post('booking_id')
					);
				}

				if ($travel_date_changed || $products_changed) {
					$change_summary = build_booking_update_notification_summary(
						$log_rows, $products_create, $products_update, $products_delete
					);
					$this->load->model('Notification_Model');
					$updater_name = $this->session->userdata('name') ?: 'Someone';
					$this->Notification_Model->Create_Booking_Updated_Notification(
						$this->input->post('booking_id'),
						$this->session->userdata('admin_id'),
						$updater_name,
						$change_summary
					);
				}

				// Cleanup invalid supplier dates on booking products
				$this->Booking_Product_Model->Cleanup_Supplier_Dates($this->input->post('booking_id'));

				// Recompute Subtotal from booking_product totals to keep booking.Subtotal authoritative
				$this->Booking_Product_Model->Recompute_Subtotal($this->input->post('booking_id'));

				// If any booking_product was added, edited, or deleted, auto-unlock the
				// E-Invoice Request session so the customer can review the revised
				// figures and resubmit (pax data is preserved).
				$bp_post = $this->input->post('booking_products');
				if (!empty($bp_post[0]) || !empty($bp_post[1]) || !empty($bp_post[2])) {
					$this->load->model('Invoice_Split_Model');
					$this->Invoice_Split_Model->Unlock_Submitted($this->input->post('booking_id'));
				}

				// Auto-enable insurance if any product belongs to an insurance category
				$booking_id = $this->input->post('booking_id');
				$this->db->from('booking_product');
				$this->db->join('product', 'product.ProductID = booking_product.ProductID', 'left');
				$this->db->join('category', 'category.CategoryID = product.CategoryID', 'left');
				$this->db->where('booking_product.BookingID', $booking_id);
				$this->db->where('booking_product.Status', 'Y');
				$this->db->like('category.Name', 'Insurance', 'both');
				if($this->db->count_all_results() > 0) {
					$this->Booking_Model->update_by_id($booking_id, ['TravelInsuranceStatus' => 'Y']);
				}

				$bookingInfo = get_object_vars($this->Booking_Model->find($this->input->post('booking_id')));

				if (!empty($bookingInfo)) {
					$payments = $this->Payment_Model->get_payments_by_booking_id($this->input->post('booking_id'));

					if ($bookingInfo['AutocountSyncAction'] == 'C' && $bookingInfo['AutocountSyncStatus'] == 'S') {
						$this->Booking_Model->update_by_id($this->input->post('booking_id'), [
							'AutocountSyncAction' => 'U',
							'AutocountSyncStatus' => 'P'
						]);
						if (!empty($payments)) {
							foreach($payments as $payment) {
								if ($payment['AutocountSyncAction'] == 'C' && $payment['AutocountSyncStatus'] == 'S') {
									$this->Payment_Model->update_by_id($payment['PaymentID'], [
										'AutocountSyncAction' => 'U',
										'AutocountSyncStatus' => 'P'
									]);
								} elseif ($payment['AutocountSyncAction'] == 'U' && $payment['AutocountSyncStatus'] == 'S') {
									$this->Payment_Model->update_by_id($payment['PaymentID'], [
										'AutocountSyncStatus' => 'P'
									]);
								} else {
									// Just update status to 'P' in all other cases
									$this->Payment_Model->update_by_id($payment['PaymentID'], [
										'AutocountSyncStatus' => 'P'
									]);
								}
							}
						}
					} else if ($bookingInfo['AutocountSyncAction'] == 'U' && $bookingInfo['AutocountSyncStatus'] == 'S') {
						$this->Booking_Model->update_by_id($this->input->post('booking_id'), [
							'AutocountSyncAction' => 'U',
							'AutocountSyncStatus' => 'P'
						]);
						if (!empty($payments)) {
							foreach($payments as $payment) {
								if ($payment['AutocountSyncAction'] == 'C' && $payment['AutocountSyncStatus'] == 'S') {
									$this->Payment_Model->update_by_id($payment['PaymentID'], [
										'AutocountSyncAction' => 'U',
										'AutocountSyncStatus' => 'P'
									]);
								} elseif ($payment['AutocountSyncAction'] == 'U' && $payment['AutocountSyncStatus'] == 'S') {
									$this->Payment_Model->update_by_id($payment['PaymentID'], [
										'AutocountSyncStatus' => 'P'
									]);
								} else {
									// Just update status to 'P' in all other cases
									$this->Payment_Model->update_by_id($payment['PaymentID'], [
										'AutocountSyncStatus' => 'P'
									]);
								}
							}
						}
					} else {
						$this->Booking_Model->update_by_id($this->input->post('booking_id'), [
							'AutocountSyncStatus'  => 'P',
						]);
						if (!empty($payments)) {
							foreach($payments as $payment) {
								if ($payment['AutocountSyncAction'] == 'C' && $payment['AutocountSyncStatus'] == 'S') {
									$this->Payment_Model->update_by_id($payment['PaymentID'], [
										'AutocountSyncAction' => 'U',
										'AutocountSyncStatus' => 'P'
									]);
								} elseif ($payment['AutocountSyncAction'] == 'U' && $payment['AutocountSyncStatus'] == 'S') {
									$this->Payment_Model->update_by_id($payment['PaymentID'], [
										'AutocountSyncStatus' => 'P'
									]);
								} else {
									// Just update status to 'P' in all other cases
									$this->Payment_Model->update_by_id($payment['PaymentID'], [
										'AutocountSyncStatus' => 'P'
									]);
								}
							}
						}
					}

					$customerInfo = get_object_vars($this->Customer_Model->find($bookingInfo['CustomerID']));
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
				}
				
				/*** Fetch Fresh Booking + Products from DB ***/
				// $booking_id = $this->input->post('booking_id');
				// $bookingData = $this->Booking_Model->getAllBookingsWithGuests($booking_id);
				// $bookingProducts = $this->Booking_Product_Model->getAllBookingsWithProducts($booking_id);

				// // Convert objects to arrays
				// $bookingProducts = array_map('get_object_vars', $bookingProducts);

				// $statuses = !empty($this->config->item('booking_sync_status')) ? $this->config->item('booking_sync_status') : ['BOOKING CONFIRMATION'];

				// if (in_array($bookingData['BookingConfirmationTitle'], $statuses)) {
				// 	/*** Call Quotation Update in AutoCount ***/
				// 	$quotationData = [
				// 		'BookingNumber'   => $bookingData['BookingNumber'],
				// 		'DocNo'           => $bookingData['BookingNumber'], // Fallback
				// 		'master'          => [
				// 			'DocDate'        => date('Y-m-d', strtotime($bookingData['InsertDate'])),
				// 			'DebtorName'     => $bookingData['Customer'],
				// 			'Email'          => $bookingData['guest_email'],
				// 			'Address'        => $bookingData['guest_address'],
				// 			'Phone1'         => $bookingData['guest_phone'],
				// 			'DeliverAddress' => $bookingData['guest_address'],
				// 			'DeliverContact' => $bookingData['Customer'],
				// 			'DeliverPhone1'  => $bookingData['guest_phone'],
				// 			'Remark1'        => $bookingData['BokingRemark'],
				// 		],
				// 		'booking_product' => $bookingProducts,
				// 		'tax_code'        => '', // Default tax code if missing
				// 		'saveApprove'     => null
				// 	];

				// 	$respond = $this->autocount_update($quotationData);

				// 	$booking = $this->Booking_Model->find($booking_id);
				// 	if ($booking != null && $respond != null) {
				// 		if ($respond['error']) {
				// 			$this->Booking_Model->update_by_id($booking_id, [
				// 				'AutocountSyncMessage' => json_encode($respond),
				// 			]);
				// 		} elseif ($respond['status'] === 201 || $respond['status'] === 204) {
				// 			$this->Booking_Model->update_by_id($booking_id, [
				// 				'AutocountSyncMessage' => json_encode($respond),
				// 				'AutocountSyncStatus' => 'U'
				// 			]);
				// 		} 
				// 	}
				// }
				
				// Booking Checklist Completion (delegated so the Save_Checklist
				// endpoint can reuse the same write path for users without AB).
				$this->_persist_checklist_completions(
					$this->input->post('booking_id'),
					$this->input->post('checklist_completions'),
					$this->session->userdata('admin_id')
				);

				// Draft lifecycle (draft_save_mode):
				//   approve -> APPROVE + graduate straight to PENDING BC (PB),
				//              stamping the approval flag/date
				//   PB      -> park at PENDING BC (stays editable; can graduate later)
				//   PBC     -> PENDING BC CONFIRMATION; enters the normal flow
				//   draft / '' -> stays SAD
				// Applies from SAD (approve / graduate) or from PB (re-graduate PB->PBC).
				if (in_array($intake_pre_save_status, array('SAD', 'PB'), true) && !empty($intake_post_booking_id)) {
					$this->load->helper('booking_draft');
					$bid  = (int) $intake_post_booking_id;
					$mode = $this->input->post('draft_save_mode');
					$advancer_id = (int) $this->session->userdata('admin_id');

					// "Approve" graduates a saved draft straight to PENDING BC (PB),
					// so it shares the graduate path below with the PB/PBC buttons.
					// The only extra is the DraftApprovedDate approval stamp.
					$is_approve = ($mode === 'approve' && $intake_pre_save_status === 'SAD');
					$target     = $is_approve ? 'PB' : resolve_graduate_status($mode);

					if ($target !== null) {
						$this->load->helper(array('booking_status_log', 'booking_flow'));
						$status_info  = get_booking_status_info();
						$target_label = isset($status_info['texts'][$target]) ? $status_info['texts'][$target] : $target;
						// Graduating implies approval (the graduate buttons only show
						// once approved), so keep the flag set.
						$update = array(
							'Status'        => $target,
							'DraftApproved' => 1,
						);
						if ($is_approve) {
							$update['DraftApprovedDate'] = date('Y-m-d H:i:s');
						}
						$this->Booking_Model->update_by_id($bid, $update);
						log_booking_status_change(
							$bid,
							$target,
							$intake_pre_save_status,
							$advancer_id,
							$is_approve
								? 'Draft — approved and advanced to ' . $target_label
								: 'Draft — booking advanced to ' . $target_label,
							true
						);

						// Drafts are created roomless on purpose, but a graduated
						// booking must carry at least one room (the booking form,
						// guest list and BC all expect it). Seed a default ROOM 1
						// if the draft never had any rooms added.
						$this->db->where('booking_id', $bid);
						$this->db->where('Status', 'Y');
						if ((int) $this->db->count_all_results('guest_list_room') === 0) {
							$this->db->insert('guest_list_room', array(
								'booking_id'  => $bid,
								'room_name'   => 'ROOM 1',
								'adult_count' => 0,
								'child_count' => 0,
								'infant_count' => 0,
								'InsertBy'    => $advancer_id,
								'InsertDate'  => date('Y-m-d H:i:s')
							));
						}
					}
				}
			} else {
				$valid_booking_id = $this->Universal_Model->Validate_Id('BookingID', $this->input->get('booking_id'), 'booking');

				if($valid_booking_id) {
					if(current_url() == base_url('Booking/Update')) {
						$titles = array('tab_title' => 'HolidayGoGoGo | Booking', 'breadcrumb_title' => 'Booking >> Update');
					} else {
						$titles = array('tab_title' => 'HolidayGoGoGo | Booking', 'breadcrumb_title' => 'Booking >> Create');
					}
					$array = $this->Booking_Model->Read_Booking();

					// Reset lock status for Duplicate - new booking should not inherit GL lock
					if (current_url() == base_url('Booking/Duplicate')) {
						$array['LockStatus'] = 'N';
					}

					// Preserve raw Y-m-d deadlines before display formatting so downstream
					// logic (e.g. display_booking_status) can still parse them reliably.
					$array['DepositDeadlineRaw'] = !empty($array['DepositDeadline']) ? $array['DepositDeadline'] : '';
					$array['FullPaymentDeadlineRaw'] = !empty($array['FullPaymentDeadline']) ? $array['FullPaymentDeadline'] : '';

					if(!empty($array['DepositDeadline'])) {
						$array['DepositDeadline'] = date('d/m/Y', strtotime($array['DepositDeadline']));
					}
					if(!empty($array['FullPaymentDeadline'])) {
						$array['FullPaymentDeadline'] = date('d/m/Y', strtotime($array['FullPaymentDeadline']));
					}
					if(!empty($array['AdditionalPaymentDeadline'])) {
						$array['AdditionalPaymentDeadline'] = date('d/m/Y', strtotime($array['AdditionalPaymentDeadline']));
					}
					if(!empty($array['StartDate']) && !empty($array['EndDate'])) {
						$array['TravelDate'] = date('d/m/Y', strtotime($array['StartDate'])) . ' - ' . date('d/m/Y', strtotime($array['EndDate']));
					} else {
						$array['TravelDate'] = null;
					}
					// Ensure AllowReview is set (default to 1 if not set or null)
					if (!isset($array['AllowReview']) || $array['AllowReview'] === null) {
						$array['AllowReview'] = 1;
					} else {
						// Convert to integer to ensure it's 0 or 1
						$array['AllowReview'] = (int)$array['AllowReview'];
					}
					$array['BookingProductID'] = ($this->Booking_Product_Model->Read_Last_Booking_Product_ID()) + 1;
					$array['Tag'] = explode(',', $array['Tag']);
					$array['Subtotal'] = number_format($array['Subtotal'], 2, '.', ',');
					$array['Discount'] = $array['Discount'] != 0.00 ? number_format($array['Discount'], 2, '.', ',') : '';
					// Store raw NetTotal before formatting for deposit calculation
					$net_total_raw = isset($array['NetTotal']) ? floatval($array['NetTotal']) : 0;
					$array['NetTotal'] = number_format($array['NetTotal'], 2, '.', ',');
					// Handle DepositPercentage - store original DB value for comparison
					$array['DepositPercentageOriginal'] = isset($array['DepositPercentage']) ? $array['DepositPercentage'] : 0;
					// Handle DepositMode and DepositFixedAmount - store originals for comparison
					$array['DepositModeOriginal'] = isset($array['DepositMode']) ? $array['DepositMode'] : 'percentage';
					$array['DepositFixedAmountOriginal'] = isset($array['DepositFixedAmount']) ? $array['DepositFixedAmount'] : 0;
					// For Update page: use actual DB value (even if 0). For Create/Duplicate: default to 50 if 0 or not set
					if (current_url() == base_url('Booking/Update')) {
						// Update page: default to 50 if 0 or not set (e.g. pending BC without a deposit)
						if (!isset($array['DepositPercentage']) || $array['DepositPercentage'] == 0) {
							$array['DepositPercentage'] = 50; // Default UI value
						}
						if (!isset($array['DepositMode'])) {
							$array['DepositMode'] = 'percentage';
						}
						if (!isset($array['DepositFixedAmount'])) {
							$array['DepositFixedAmount'] = 0;
						}
					} else {
						// Create/Duplicate page: default to 50 if 0 or not set
						if (!isset($array['DepositPercentage']) || $array['DepositPercentage'] == 0) {
							$array['DepositPercentage'] = 50; // Default UI value
						}
						if (!isset($array['DepositMode'])) {
							$array['DepositMode'] = 'percentage';
						}
						if (!isset($array['DepositFixedAmount'])) {
							$array['DepositFixedAmount'] = 0;
						}
					}
					// Calculate deposit information
					$deposit_mode = isset($array['DepositMode']) ? $array['DepositMode'] : 'percentage';
					if ($deposit_mode == 'fixed') {
						$deposit_total = isset($array['DepositFixedAmount']) ? floatval($array['DepositFixedAmount']) : 0;
					} else {
						$deposit_percentage_raw = isset($array['DepositPercentage']) ? $array['DepositPercentage'] : 0;
						$deposit_total = ($net_total_raw * $deposit_percentage_raw) / 100;
					}
					$array['DepositTotal'] = $deposit_total;
					// Calculate deposit paid from payments
					$deposit_paid = 0;
					$total_credit_approved = 0;
					if (isset($array['BookingID'])) {
						$payments = $this->Booking_Model->Read_Payments($array['BookingID']);
						if (!empty($payments)) {
							foreach ($payments as $payment) {
								if (($payment->Status == 'Y' || $payment->Status == 'P') && $payment->Credit > 0) {
									$deposit_paid += $payment->Credit;
								}
							}
							// Settled credit (guards supplier commission — see listing above).
							$total_credit_approved = payment_approved_customer_credit($payments);
						}
					}
					$array['DepositPaid'] = $deposit_paid;
					$array['balance_due'] = $net_total_raw - $total_credit_approved;
					$this->load->helper('booking_flow');
					$has_deposit_deadline_detail = !empty($array['DepositDeadline']);
					$array['deposit_complete'] = compute_deposit_complete($deposit_total, $total_credit_approved, $has_deposit_deadline_detail);
					$checklist_lead_ids = resolve_booking_checklist_team_lead_ids($array);
					$array['can_modify_checklist'] = can_user_modify_booking_checklist(
						$array,
						$this->session->userdata('admin_id'),
						$this->session->userdata('level'),
						$checklist_lead_ids
					);
					// Calculate deposit status and format Deposit Paid display
					$deposit_difference = $deposit_paid - $deposit_total;
					if ($deposit_paid > 0) {
						$deposit_paid_display = number_format($deposit_paid, 2, '.', ',');

						if ($deposit_difference < -0.01) {
							$deposit_paid_display .= ' (Underpaid: RM ' . number_format(abs($deposit_difference), 2, '.', ',') . ')';
							$array['DepositPaidColor'] = '#FFA500';
						}
					} else {
						$deposit_paid_display = '0.00';
					}
						$array['DepositPaidDisplay'] = $deposit_paid_display;
						$array['admins'] = $this->Booking_Model->Read_Admins();
						$array['notify_admins'] = $this->Notification_Model->Build_Admin_Handles($this->Booking_Model->Read_Notify_Admins());
						$array['booking_op_admins'] = $this->Booking_Model->Read_Booking_OP_Admins();

					if(empty($array['ProductSequence'])) {
						$array['ProductSequence'] = explode(',', $array['ProductSequence']);
						$array['booking_products'] = $this->Booking_Product_Model->Read();
					} else {
						$array['ProductSequence'] = explode(',', $array['ProductSequence']);
						$booking_products = $this->Booking_Product_Model->Read();
						$array['booking_products'] = [];
						for($i = 0; $i < count($array['ProductSequence']); $i++) {
							foreach($booking_products as $booking_product) {
								if($booking_product->BookingProductID == $array['ProductSequence'][$i]) {
									array_push($array['booking_products'], $booking_product);
								}
							}
						}
					}
					$array['categories'] = $this->Booking_Model->Read_Categories();
					$array['products'] = $this->Booking_Model->Read_Products();
					$array['category_products'] = $this->Booking_Model->Read_Category_Products();
					$array['footers'] = $this->Booking_Model->Read_Footers();
					$array['country_codes'] = $this->Booking_Model->Read_Country_Codes();
					$array['tags'] = $this->Booking_Model->Read_Tags();
					$array['sources'] = $this->Booking_Model->Read_Sources_With_Inactive($array['Source']);
					foreach($array['booking_products'] as $booking_product) {
						$booking_product->Price = number_format((float)($booking_product->Price ?? 0), 2, '.', ',');
						$booking_product->Total = number_format((float)($booking_product->Total ?? 0), 2, '.', ',');
						$booking_product->PaymentOutSupplierFull = !empty($booking_product->PaymentOutSupplierFull) ? date('d/m/Y', strtotime($booking_product->PaymentOutSupplierFull)) : '';
						$booking_product->PaymentOutSupplierDeposit = !empty($booking_product->PaymentOutSupplierDeposit) ? date('d/m/Y', strtotime($booking_product->PaymentOutSupplierDeposit)) : '';
					}

					// Draft response time: saved-as-draft (SAD) -> PENDING PAYMENT (P),
					// surfaced on the edit form (matches the "Draft -> Payment Time" card).
					$this->load->helper('response_time');
					$this->load->helper('slow_conversion');
					$array['draft_payment_seconds'] = calculate_submitted_to_payment_seconds($array['BookingID']);
					$array['draft_payment_breakdown'] = calculate_draft_payment_breakdown($array['BookingID']);

					// Slow-conversion reasons card: only for bookings that converted
					// (reached Pending Payment) but took > 24h. Surface the master
					// list + this booking's already-tagged ids so the multi-select
					// can pre-select them.
					$array['is_slow_conversion'] = is_slow_conversion($array['draft_payment_seconds']);
					$this->load->model('Slow_Conversion_Reason_Model');
					$array['slow_conversion_reasons'] = $this->Slow_Conversion_Reason_Model->Read_Slow_Conversion_Reasons();
					$array['slow_conversion_reason_selected'] = $this->Slow_Conversion_Reason_Model->Read_Selected_Reason_Ids($array['BookingID']);

					// Get booking checklists
					$array['booking_checklists'] = $this->get_booking_checklists($array['booking_products']);
					$array['completion_map'] = $this->Booking_Checklist_Completion_Model->Read_Completion_Map($array['BookingID']);

					// Get invoice split data
					$this->load->model('Invoice_Split_Model');
					$array['invoice_split'] = $this->Invoice_Split_Model->Get_Pax_By_Booking($array['BookingID']);

					// Cap for the admin e-invoice "Add Pax" tool. Mirror the customer
					// portal: count real pax from rooms first (Compute_Pax_Counts),
					// then fall back to the existing split-row count. Booking header
					// Adult/Children/Infant are often NULL even when the rooms/guest
					// list are filled, which otherwise pins the cap at 0/1 and leaves
					// the button permanently disabled (e.g. BC-2608-0119).
					$einv_pax = $this->Booking_Model->Compute_Pax_Counts($array['BookingID']);
					$array['einvoice_admin_max_pax'] = (int)$einv_pax['adult'] + (int)$einv_pax['child'] + (int)$einv_pax['infant'];
					if ($array['einvoice_admin_max_pax'] <= 0) {
						$array['einvoice_admin_max_pax'] = count($array['invoice_split']);
					}

					// Surface audit info (status + last admin editor) for the admin
					// E-Invoice card. Reads the first pax row because Save_Split writes
					// these fields uniformly across every pax in the same transaction.
					$array['einvoice_submit_status']    = null;
					$array['einvoice_submitted_date']   = null;
					$array['einvoice_last_edited_by']   = null;
					$array['einvoice_last_edited_date'] = null;
					$array['einvoice_last_editor_name'] = null;
					if (!empty($array['invoice_split'])) {
						$first = $array['invoice_split'][0];
						$array['einvoice_submit_status']    = $first['SubmitStatus'];
						$array['einvoice_submitted_date']   = $first['SubmittedDate'];
						$array['einvoice_last_edited_by']   = $first['LastEditedByAdmin'];
						$array['einvoice_last_edited_date'] = $first['LastEditedDate'];
						if (!empty($first['LastEditedByAdmin'])) {
							$this->load->model('Admin_Model');
							$editor = $this->Admin_Model->find($first['LastEditedByAdmin']);
							if (!empty($editor)) {
								$array['einvoice_last_editor_name'] = $editor->Name;
							}
						}
					}

					// Get custom uploads
					$this->load->model('Custom_Upload_Model');
					$array['custom_uploads'] = $this->Custom_Upload_Model->Read($array['BookingID']);

					// Get booking status log timeline
					$this->load->model('Booking_Status_Log_Model');
					$array['status_logs'] = $this->Booking_Status_Log_Model->get_timeline_data($array['BookingID'], false);

					// Get booking audit logs
					$array['booking_logs'] = $this->Booking_Model->Read_Booking_Logs($array['BookingID']);

					// Get payment change logs
					$array['payment_logs'] = $this->Payment_Model->Read_Payment_Logs($array['BookingID']);

					// Calculate and get display status for the booking
					$this->load->helper('booking_flow');
					$array['display_status'] = display_booking_status($array, true); // true = return all applicable statuses

					// Supplier invoices entered on this booking + dropdown source.
					$array['supplier_invoices']         = $this->Booking_Supplier_Invoice_Model->Read_By_Booking($array['BookingID']);
					$array['supplier_invoice_suppliers'] = $this->Payment_Model->Read_Suppliers();

					if(isset($_GET['nick'])) { echo "<pre>"; print_r($array); exit; }
					$array['customer_types'] = $this->Customer_Type_Model->Read_Customer_Types();
					$this->load->view('layout/header', $titles);
					$this->load->view('booking/booking', $array);
					$this->load->view('layout/footer');
				} else {
					redirect('Booking');
				}
			}
		} else {
			redirect('Dashboard');
		}
	}

	/**
	 * Admin endpoint: edit a submitted e-invoice request from the admin booking
	 * detail page. Reuses the same validator as the customer-portal submit flow
	 * (full allocation required), preserves the original SubmittedDate, and
	 * stamps LastEditedByAdmin / LastEditedDate so the audit strip can render.
	 * Finance is re-notified by email so they always work off the latest copy.
	 *
	 * Any authenticated admin (any admin_id in session) may use this endpoint.
	 */
	public function save_invoice_split_admin($booking_id = null)
	{
		$this->output->set_content_type('application/json');

		$admin_id = $this->session->userdata('admin_id');
		if (empty($admin_id)) {
			$this->output->set_output(json_encode(['success' => false, 'message' => 'Unauthorized']));
			return;
		}

		$booking_id = (int)$booking_id;
		if ($booking_id <= 0) {
			$this->output->set_output(json_encode(['success' => false, 'message' => 'Invalid booking']));
			return;
		}

		$this->db->select('BookingID, Subtotal, Discount, NetTotal');
		$this->db->where('BookingID', $booking_id);
		$this->db->where('Status !=', 'N');
		$booking = $this->db->get('booking')->row_array();
		if (empty($booking)) {
			$this->output->set_output(json_encode(['success' => false, 'message' => 'Booking not found']));
			return;
		}

		$json = $this->input->raw_input_stream;
		$data = json_decode($json, true);

		$this->load->model('Invoice_Split_Model');
		// Admin edits always enforce full allocation, same as a customer Submit.
		$validation = $this->Invoice_Split_Model->Validate_Pax_Input($data, $booking['BookingID'], true);
		if (!$validation['ok']) {
			$this->output->set_output(json_encode(['success' => false, 'message' => $validation['message']]));
			return;
		}

		$result = $this->Invoice_Split_Model->Save_Split(
			$booking['BookingID'],
			$validation['pax_data'],
			floatval($booking['Subtotal']),
			floatval($booking['Discount']),
			'S',
			true,        // preserve_submitted_date
			$admin_id    // admin_editor_id
		);

		if (!$result) {
			$this->output->set_output(json_encode(['success' => false, 'message' => 'Failed to save invoice split']));
			return;
		}

		// Best-effort finance re-notification. Look up the editor's display
		// name once so the email body can name them.
		$this->load->model('Admin_Model');
		$editor = $this->Admin_Model->find($admin_id);
		$editor_name = !empty($editor) ? $editor->Name : '';
		$this->Invoice_Split_Model->Send_Finance_Notification($booking['BookingID'], true, $editor_name);

		$pax = $this->Invoice_Split_Model->Get_Pax_By_Booking($booking['BookingID']);
		$this->output->set_output(json_encode([
			'success' => true,
			'message' => 'E-Invoice request updated successfully',
			'submit_status' => 'S',
			'pax' => $pax,
		]));
	}

	function View()
	{
		// Allow sales agents (level 20) to view their own bookings even without AB access
		$is_sales_agent = $this->session->userdata('level') == 20;
		$has_ab_access = in_array('AB', $this->session->access_control);
		$has_vb_access = in_array('VB', $this->session->access_control);
		
		// Check if user has VB access OR is a sales agent
		if($has_vb_access || $is_sales_agent) {
			$valid_booking_id = $this->Universal_Model->Validate_Id('BookingID', $this->input->get('booking_id'), 'booking');
			
			if($valid_booking_id) {
				$array = $this->Booking_Model->Read_Booking();

				// Block SA and TC from viewing completed bookings
				if(in_array($this->session->userdata('level'), [20, 50]) && $array['Status'] == 'Y' && $array['AfterSalesService'] == 'COMPLETE') {
					$this->load->view('errors/access_denied');
					return;
				}

				// If sales agent without AB access, verify they own the booking
				if($is_sales_agent && !$has_ab_access) {
					if($array['SalesAgent'] != $this->session->userdata('admin_id')) {
						$this->session->set_flashdata('error', 'You can only view bookings assigned to you.');
						redirect('Booking');
						return;
					}
				}
				
				$titles = array('tab_title' => 'HolidayGoGoGo | Booking', 'breadcrumb_title' => 'Booking >> View');
				
				if(!empty($array['DepositDeadline'])) {
					$array['DepositDeadline'] = date('d/m/Y', strtotime($array['DepositDeadline']));
				}
				if(!empty($array['FullPaymentDeadline'])) {
					$array['FullPaymentDeadline'] = date('d/m/Y', strtotime($array['FullPaymentDeadline']));
				}
				if(!empty($array['AdditionalPaymentDeadline'])) {
					$array['AdditionalPaymentDeadline'] = date('d/m/Y', strtotime($array['AdditionalPaymentDeadline']));
				}
				if(!empty($array['PaymentOutSupplierFull'])) {
					$array['PaymentOutSupplierFull'] = date('d/m/Y', strtotime($array['PaymentOutSupplierFull']));
				}
				if(!empty($array['PaymentOutSupplierDeposit'])) {
					$array['PaymentOutSupplierDeposit'] = date('d/m/Y', strtotime($array['PaymentOutSupplierDeposit']));
				}
				if(!empty($array['StartDate']) && !empty($array['EndDate'])) {
					$array['TravelDate'] = date('d/m/Y', strtotime($array['StartDate'])) . ' - ' . date('d/m/Y', strtotime($array['EndDate']));
				} else {
					$array['TravelDate'] = null;
				}
				// Ensure AllowReview is set (default to 1 if not set or null)
				if (!isset($array['AllowReview']) || $array['AllowReview'] === null) {
					$array['AllowReview'] = 1;
				} else {
					// Convert to integer to ensure it's 0 or 1
					$array['AllowReview'] = (int)$array['AllowReview'];
				}
				$array['BookingProductID'] = ($this->Booking_Product_Model->Read_Last_Booking_Product_ID()) + 1;
				$array['Tag'] = explode(',', $array['Tag']);
				$array['Subtotal'] = number_format($array['Subtotal'], 2, '.', ',');
				$array['Discount'] = $array['Discount'] != 0.00 ? number_format($array['Discount'], 2, '.', ',') : '';
				// Store raw NetTotal before formatting for deposit calculation
				$net_total_raw = isset($array['NetTotal']) ? floatval($array['NetTotal']) : 0;
				$array['NetTotal'] = number_format($array['NetTotal'], 2, '.', ',');
				// Handle DepositPercentage - store original DB value for comparison
				$array['DepositPercentageOriginal'] = isset($array['DepositPercentage']) ? $array['DepositPercentage'] : 0;
				$array['DepositModeOriginal'] = isset($array['DepositMode']) ? $array['DepositMode'] : 'percentage';
				$array['DepositFixedAmountOriginal'] = isset($array['DepositFixedAmount']) ? $array['DepositFixedAmount'] : 0;
				// For View page: use actual DB value (even if 0)
				if (!isset($array['DepositPercentage'])) {
					$array['DepositPercentage'] = 0;
				}
				if (!isset($array['DepositMode'])) {
					$array['DepositMode'] = 'percentage';
				}
				if (!isset($array['DepositFixedAmount'])) {
					$array['DepositFixedAmount'] = 0;
				}
				// Calculate deposit information
				$deposit_mode = isset($array['DepositMode']) ? $array['DepositMode'] : 'percentage';
				if ($deposit_mode == 'fixed') {
					$deposit_total = isset($array['DepositFixedAmount']) ? floatval($array['DepositFixedAmount']) : 0;
				} else {
					$deposit_percentage_raw = isset($array['DepositPercentage']) ? $array['DepositPercentage'] : 0;
					$deposit_total = ceil(($net_total_raw * $deposit_percentage_raw) / 100);
				}
				$array['DepositTotal'] = $deposit_total;
				// Calculate deposit paid from payments
				$deposit_paid = 0;
				if (isset($array['BookingID'])) {
					$payments = $this->Booking_Model->Read_Payments($array['BookingID']);
					if (!empty($payments)) {
						foreach ($payments as $payment) {
							if (($payment->Status == 'Y' || $payment->Status == 'P') && $payment->Credit > 0) {
								$deposit_paid += $payment->Credit;
							}
						}
					}
				}
				$array['DepositPaid'] = $deposit_paid;
				// Calculate deposit status and format Deposit Paid display
				$deposit_difference = $deposit_paid - $deposit_total;
				if ($deposit_paid > 0) {
					$deposit_paid_display = number_format($deposit_paid, 2, '.', ',');

					if ($deposit_difference < -0.01) {
						$deposit_paid_display .= ' (Underpaid: RM ' . number_format(abs($deposit_difference), 2, '.', ',') . ')';
						$array['DepositPaidColor'] = '#FFA500';
					}
				} else {
					$deposit_paid_display = '0.00';
				}
					$array['DepositPaidDisplay'] = $deposit_paid_display;
					$array['admins'] = $this->Booking_Model->Read_Admins();
					$array['notify_admins'] = $this->Notification_Model->Build_Admin_Handles($this->Booking_Model->Read_Notify_Admins());
					$array['booking_op_admins'] = $this->Booking_Model->Read_Booking_OP_Admins();

				if(empty($array['ProductSequence'])) {
					$array['ProductSequence'] = explode(',', $array['ProductSequence']);
					$array['booking_products'] = $this->Booking_Product_Model->Read();
				} else {
					$array['ProductSequence'] = explode(',', $array['ProductSequence']);
					$booking_products = $this->Booking_Product_Model->Read();
					$array['booking_products'] = [];
					for($i = 0; $i < count($array['ProductSequence']); $i++) {
						foreach($booking_products as $booking_product) {
							if($booking_product->BookingProductID == $array['ProductSequence'][$i]) {
								array_push($array['booking_products'], $booking_product);
							}
						}
					}
				}
				$array['categories'] = $this->Booking_Model->Read_Categories();
				$array['products'] = $this->Booking_Model->Read_Products();
				$array['footers'] = $this->Booking_Model->Read_Footers();
				$array['country_codes'] = $this->Booking_Model->Read_Country_Codes();
				$array['tags'] = $this->Booking_Model->Read_Tags();
				$array['sources'] = $this->Booking_Model->Read_Sources_With_Inactive($array['Source']);

				// Get Destination Name
				$array['DestinationName'] = '';
				foreach($array['categories'] as $category) {
					if($category->CategoryID == $array['Destination']) {
						$array['DestinationName'] = $category->Name;
						break;
					}
				}

				// Get Source Name
				$array['SourceName'] = '';
				foreach($array['sources'] as $source) {
					if($source->SourceID == $array['Source']) {
						$array['SourceName'] = $source->Name;
						break;
					}
				}
				
				foreach($array['booking_products'] as $booking_product) {
					$booking_product->Price = number_format((float)($booking_product->Price ?? 0), 2, '.', ',');
					$booking_product->Total = number_format((float)($booking_product->Total ?? 0), 2, '.', ',');
				}

				// Get booking checklists
				$array['booking_checklists'] = $this->get_booking_checklists($array['booking_products']);
				$array['completion_map'] = $this->Booking_Checklist_Completion_Model->Read_Completion_Map($array['BookingID']);

				// Get custom uploads
				$this->load->model('Custom_Upload_Model');
				$array['custom_uploads'] = $this->Custom_Upload_Model->Read($array['BookingID']);

				// Get booking status log timeline
				$this->load->model('Booking_Status_Log_Model');
				$array['status_logs'] = $this->Booking_Status_Log_Model->get_timeline_data($array['BookingID'], false);
				
				// Mark as view mode (read-only)
				$array['is_view_mode'] = true;

				if(isset($_GET['nick'])) { echo "<pre>"; print_r($array); exit; }
				$this->load->view('layout/header', $titles);
				$this->load->view('booking/view', $array);
				$this->load->view('layout/footer');
			} else {
				redirect('Booking');
			}
		} else {
			redirect('Dashboard');
		}
	}
	
	function Update_Cancel_Status()
	{
		if(in_array('AB', $this->session->access_control)) {
			if($this->input->is_ajax_request()) {
				$this->Booking_Model->Update_Cancel_Status_With_Reason();
				$this->Booking_Model->Create_Booking_Log_Cancel();
				echo json_encode(true);
			} else {
				$this->Booking_Model->Update_Cancel_Status();
				$this->Booking_Model->Create_Booking_Log();
				if(strpos($this->input->get('param'), '?') == true) {
					redirect('Booking?' . explode('?', $this->input->get('param'))[1]);
				} else {
					redirect('Booking');
				}
			}
		} else {
			redirect('Dashboard');
		}
	}

	function Update_Partial_Refund_Status()
	{
		if(in_array('AB', $this->session->access_control)) {
			if($this->input->is_ajax_request()) {
				$this->Booking_Model->Update_Partial_Refund_Status_With_Reason();
				$this->Booking_Model->Create_Booking_Log_Partial_Refund();
				echo json_encode(true);
			} else {
				$this->Booking_Model->Update_Partial_Refund_Status();
				$this->Booking_Model->Create_Booking_Log();
				if(strpos($this->input->get('param'), '?') == true) {
					redirect('Booking?' . explode('?', $this->input->get('param'))[1]);
				} else {
					redirect('Booking');
				}
			}
		} else {
			redirect('Dashboard');
		}
	}

	function Update_Lock_Status() 
	{
		$booking_id = $this->input->get('booking_id');
		$new_lock_status = $this->input->get('new_lock_status');
		
		// Update lock status
		$this->Booking_Model->Update_Lock_Status();
		$this->Booking_Model->Create_Booking_Log();
		
		// If locking guest list (LockStatus = 'Y'), automatically advance status to PTV if conditions are met
		if ($new_lock_status == 'Y') {
			$this->load->helper('booking_flow');
			
			// Get updated booking
			$booking = $this->Booking_Model->getBookingById($booking_id);
			
			if ($booking) {
				// Determine the correct status based on current state
				$status_info = determine_booking_status_from_state($booking_id, $booking, $this);

				// If determined status is PTV and current status is not PTV, update it
				if ($status_info['status'] == 'PTV' && $booking->Status != 'PTV') {
					$this->Booking_Model->Update_Status('PTV', $booking_id);
					$this->Booking_Model->Create_Booking_Log2($booking->Status, 'PTV', $booking_id);
					
					// Log the status change
					$this->load->helper('booking_status_log');
					$admin_id = $this->session->userdata('admin_id') ?: 0;
					log_booking_status_change(
						$booking_id,
						'PTV',
						$booking->Status,
						$admin_id,
						'Guest list locked - Status advanced to PENDING TRAVEL VOUCHER',
						true
					);
				}
			}
		}
		
		if ($this->input->get('return_to') === 'booking') {
			redirect('Booking/Update?booking_id=' . $booking_id);
		} else {
			redirect('Guest_List?gl=' . $this->input->get('gl'));
		}
	}

	function Update_Travel_Insurance_Status()
	{
		$this->Booking_Model->Update_Travel_Insurance_Status();
		$this->Booking_Model->Create_Booking_Log();
		redirect('Guest_List?gl=' . $this->input->get('gl'));
	}
	
	function Update_After_Sales_Service()
	{
		if(in_array('AB', $this->session->access_control)) {
			$this->Booking_Model->Update_After_Sales_Service();
			$this->Booking_Model->Create_Booking_Log();
			if(strpos($this->input->get('param'), '?') == true) {
				redirect('Booking?' . explode('?', $this->input->get('param'))[1]);
			} else {
				redirect('Booking');
			}
		} else {
			redirect('Dashboard');
		}
	}

	function Update_Status() 
	{
		if(in_array('AB', $this->session->access_control)) {
			// Load booking flow helper
			$this->load->helper('booking_flow');
			
			// Get current booking status
			$booking = $this->Booking_Model->getBookingById($this->input->get('booking_id'));
			if (!$booking) {
				redirect('Dashboard');
				return;
			}
			
			$current_status = $booking->Status;
			$new_status = $this->input->get('new_status');
			
			// Validate status transition
			$validation = validate_booking_status_flow($current_status, $new_status, false);
			
			if (!$validation['valid']) {
				// Set error message and redirect
				$this->session->set_flashdata('error', $validation['message']);
				if(strpos($this->input->get('param'), '?') == true) {
					redirect('Booking?' . explode('?', $this->input->get('param'))[1]);
				} else {
					redirect('Booking');
				}
				return;
			}
			
			$this->Booking_Model->Update_Status($new_status, $this->input->get('booking_id'));
			$this->Booking_Model->Create_Booking_Log();
			
			// Add status history log
			$this->load->helper('booking_status_log');
			$admin_id = $this->session->userdata('admin_id');
			
			// Get status labels for description
			$status_labels = array(
				'PBC' => 'PENDING BC CONFIRMATION',
				'P' => 'PENDING PAYMENT',
				'PBO' => 'PENDING BOOKING OPERATION',
				'PTV' => 'PENDING TRAVEL VOUCHER',
				'PT' => 'PENDING TRAVEL',
				'OG' => 'ON-GOING',
				'Y' => 'COMPLETED',
				'C' => 'CANCELLED'
			);
			
			$from_label = isset($status_labels[$current_status]) ? $status_labels[$current_status] : $current_status;
			$to_label = isset($status_labels[$new_status]) ? $status_labels[$new_status] : $new_status;
			
			// Create description based on status transition
			$description = "Status changed from {$from_label} to {$to_label}";
			if($current_status == 'PTV' && $new_status == 'PT') {
				$description = "Travel Voucher Sent - Status changed to PENDING TRAVEL";
			}
			if($current_status == 'PBO' && $new_status == 'PT') {
				$description = "Approve Travel Voucher - Status changed from PENDING BOOKING OPERATION to PENDING TRAVEL";
			}
			
			log_booking_status_change(
				$this->input->get('booking_id'),
				$new_status,
				$current_status,
				$admin_id,
				$description,
				true
			);
			
			$this->load->config('status_mapping');
			$this->load->helper('autocount');
			$config = get_autocount_config();
			$statusMap = $config['booking_to_autocount_status'];
			$newStatus = $this->input->get('new_status');
			$autoCountStatus = $statusMap[$newStatus] ?? 0; // default Pending

			// $bookingData = $this->Booking_Model->getBookingById(
			// 	$this->input->get('booking_id')
			// );

			// $statuses = !empty($this->config->item('booking_sync_status')) ? $this->config->item('booking_sync_status') : ['BOOKING CONFIRMATION'];

			// if (in_array($bookingData['BookingConfirmationTitle'], $statuses)) {
			// 	if (!empty($bookingData->BookingNumber)) {
			// 		$quotationData = [
			// 			'DocNo'  => $bookingData->BookingNumber,
			// 			'master' => [
			// 				'Status' => $autoCountStatus
			// 			]
			// 		];

			// 		$respond = $this->autocount_update($quotationData);
			// 		$booking = $this->Booking_Model->find($this->input->get('booking_id'));
			// 		if ($booking != null && $respond != null) {
			// 			if ($respond['error']) {
			// 				$this->Booking_Model->update_by_id($this->input->get('booking_id'), [
			// 					'AutocountSyncMessage' => json_encode($respond),
			// 				]);
			// 			} elseif ($respond['status'] === 201 || $respond['status'] === 204) {
			// 				$this->Booking_Model->update_by_id($this->input->get('booking_id'), [
			// 					'AutocountSyncMessage' => json_encode($respond),
			// 					'AutocountSyncStatus' => 'U'
			// 				]);
			// 			} 
			// 		}	
			// 	}
			// }
			// $this->Booking_Model->update_by_id($this->input->get('booking_id'), [
			// 	'AutocountSyncAction'  => 'S',
			// 	'AutocountSyncStatus'  => 'P'
			// ]);

			if(strpos($this->input->get('param'), '?') == true) {
				redirect('Booking?' . explode('?', $this->input->get('param'))[1]);
			} else {
				redirect('Booking');
			}
		} else {
			redirect('Dashboard');
		}
	}

	/**
	 * Approve BC - Change status from PBC (PENDING BC CONFIRMATION) to P (PENDING PAYMENT)
	 * This action should only be available when booking is in PBC status
	 */
	function Approve_BC()
	{
		// Allow AB access control OR Sales Agent (level 20) for their own bookings
		$is_sales_agent = $this->session->userdata('level') == 20;
		$has_ab_access = in_array('AB', $this->session->access_control);
		
		if($has_ab_access || $is_sales_agent) {
			// Load helpers
			$this->load->helper('booking_flow');
			$this->load->helper('booking_status_log');
			$this->load->helper('debug_log');

			// Get and validate booking_id
			$booking_id = $this->input->get('booking_id');
			if (empty($booking_id)) {
				$this->session->set_flashdata('error', 'Booking ID is required.');
				if(strpos($this->input->get('param'), '?') == true) {
					redirect('Booking?' . explode('?', $this->input->get('param'))[1]);
				} else {
					redirect('Booking');
				}
				return;
			}
			
			// Get booking
			$booking = $this->Booking_Model->getBookingById($booking_id);
			if (!$booking) {
				$this->session->set_flashdata('error', 'Booking not found.');
				if(strpos($this->input->get('param'), '?') == true) {
					redirect('Booking?' . explode('?', $this->input->get('param'))[1]);
				} else {
					redirect('Booking');
				}
				return;
			}
			
			// Sales Agents can only approve their own bookings
			if($is_sales_agent && !$has_ab_access) {
				if($booking->SalesAgent != $this->session->userdata('admin_id')) {
					$this->session->set_flashdata('error', 'You can only approve bookings assigned to you.');
					if(strpos($this->input->get('param'), '?') == true) {
						redirect('Booking?' . explode('?', $this->input->get('param'))[1]);
					} else {
						redirect('Booking');
					}
					return;
				}
			}
			
			// Validate that BC is not already approved
			if (!empty($booking->bc_approved) && $booking->bc_approved == 1) {
				$this->session->set_flashdata('error', 'This booking BC has already been approved.');
				if(strpos($this->input->get('param'), '?') == true) {
					redirect('Booking?' . explode('?', $this->input->get('param'))[1]);
				} else {
					redirect('Booking');
				}
				return;
			}
			
			// Determine target status based on current booking state (payment, checklists, travel voucher)
			$status_info = determine_status_after_bc_approval($booking_id, $booking, $this);
			$target_status = $status_info['status'];
			$status_description = $status_info['description'];
			
			// Validate the status transition from current status to target status
			$validation = validate_booking_status_flow($booking->Status, $target_status, false);
			
			if (!$validation['valid']) {
				// If can't progress to determined status, fall back to P
				if ($target_status != 'P') {
					debug_log("Validation failed for {$target_status}, falling back to P", 'Approve_BC');
					$target_status = 'P';
					$status_description = 'BC Approved - Status changed to PENDING PAYMENT';
					$validation = validate_booking_status_flow($booking->Status, $target_status, false);
				}
				
				if (!$validation['valid']) {
					$this->session->set_flashdata('error', $validation['message']);
					if(strpos($this->input->get('param'), '?') == true) {
						redirect('Booking?' . explode('?', $this->input->get('param'))[1]);
					} else {
						redirect('Booking');
					}
					return;
				}
			}
			
			// Update status to target status
			$this->Booking_Model->Update_Status($target_status, $booking_id);
			
			// Update BC approval fields
			$admin_id = $this->session->userdata('admin_id');
			$this->Booking_Model->Update_BC_Approval($booking_id, 1, $admin_id);
			
			// Log the status change with custom description
			log_booking_status_change(
				$booking_id,
				$target_status,  // to_status
				'PBC',  // from_status
				$admin_id,  // created_by
				$status_description,  // description
				true  // show_to_customer
			);
			
			// If status is PBO, check if we can advance further (checklists might be completed)
			if ($target_status == 'PBO') {
				$updated_booking = $this->Booking_Model->getBookingById($booking_id);
				if ($updated_booking) {
					check_and_advance_status_if_no_checklist_or_all_completed($booking_id, $updated_booking, $admin_id, $this);
					// Re-fetch booking in case status was advanced
					$updated_booking = $this->Booking_Model->getBookingById($booking_id);
					$target_status = $updated_booking->Status;
				}
			}
			
			// Set success message
			$success_message = 'Booking BC has been approved.';
			$status_labels = array(
				'P' => 'PENDING PAYMENT',
				'PP' => 'PARTIAL PAYMENT',
				'PBO' => 'PENDING BOOKING OPERATION',
				'PTV' => 'PENDING TRAVEL VOUCHER',
				'PT' => 'PENDING TRAVEL',
				'Y' => 'COMPLETED'
			);
			$target_label = isset($status_labels[$target_status]) ? $status_labels[$target_status] : $target_status;
			$success_message .= " Status advanced to {$target_label}.";
			$this->session->set_flashdata('success', $success_message);
			
			// Redirect back to booking list
			if(strpos($this->input->get('param'), '?') == true) {
				redirect('Booking?' . explode('?', $this->input->get('param'))[1]);
			} else {
				redirect('Booking');
			}
		} else {
			redirect('Dashboard');
		}
	}

	function Delete() 
	{
		if(in_array('RB', $this->session->access_control)) {
			$this->Universal_Model->Delete('BookingID', $this->input->get('booking_id'), 'booking');
			$this->Booking_Model->Create_Booking_Log2($this->input->get('status'), 'N', $this->input->get('booking_id'));
			$this->Universal_Model->Delete('BookingID', $this->input->get('booking_id'), 'booking_product');
			$this->Universal_Model->Delete('BookingID', $this->input->get('booking_id'), 'guest_list');
			$this->Universal_Model->Delete('BookingID', $this->input->get('booking_id'), 'payment');
			//$this->Booking_Model->update_by_id($this->input->get('booking_id'), ['AutocountSyncStatus' => 'D']);
			// Delete from AutoCount (Quotation)

			// $bookingData = $this->Booking_Model->getBookingById($this->input->get('booking_id'));
        	// $bookingNumber = (!empty($bookingData) && !empty($bookingData->BookingNumber)) ? $bookingData->BookingNumber : '';

			// $statuses = !empty($this->config->item('booking_sync_status')) ? $this->config->item('booking_sync_status') : ['BOOKING CONFIRMATION'];

			// if (in_array($bookingData['BookingConfirmationTitle'], $statuses)) {
			// 	if (!empty($bookingNumber)) {
			// 		$respond = $this->autocount_delete([
			// 			'BookingNumber' => $bookingNumber
			// 		]);

			// 		$booking = $this->Booking_Model->find($this->input->get('booking_id'));
			// 		if ($booking != null && $respond != null) {
			// 			if ($respond['error']) {
			// 				$this->Booking_Model->update_by_id($this->input->get('booking_id'), [
			// 					'AutocountSyncMessage' => json_encode($respond),
			// 				]);
			// 			} elseif ($respond['status'] === 201 || $respond['status'] === 204) {
			// 				$this->Booking_Model->update_by_id($this->input->get('booking_id'), [
			// 					'AutocountSyncMessage' => json_encode($respond),
			// 					'AutocountSyncStatus' => 'D'
			// 				]);
			// 			} 
			// 		}						
			// 	}
			// }

			$bookingInfo = get_object_vars($this->Booking_Model->find($this->input->get('booking_id')));
			if (!empty($bookingInfo)) {
				if ($bookingInfo['AutocountSyncAction'] == 'C' && $bookingInfo['AutocountSyncStatus'] == 'S') {
					$this->Booking_Model->update_by_id($this->input->get('booking_id'), [
						'AutocountSyncAction' => 'D',
						'AutocountSyncStatus' => 'P'
					]);				
				} else if ($bookingInfo['AutocountSyncAction'] == 'U') {
					$this->Booking_Model->update_by_id($this->input->get('booking_id'), [
						'AutocountSyncAction' => 'D',
						'AutocountSyncStatus' => 'P'
					]);
				} else {
					$this->Booking_Model->update_by_id($this->input->get('booking_id'), [
						'AutocountSyncStatus' => 'P'
					]);
				}
			}
			// $this->Booking_Model->update_by_id($this->input->get('booking_id'), [
			// 	'AutocountSyncAction'  => 'D',
			// 	'AutocountSyncStatus'  => 'P'
			// ]);
			
		} else {
			redirect('Dashboard');
		}
	}

	function Download() {
		// Sales Agents (20) and Marketing (60) never see profit, so it is left out
		// of their export too (see admin_hides_profit / profit_visibility_helper).
		$hide_profit = admin_hides_profit($this->session->userdata('level'));
		$this->load->helper('booking_export_remark');
		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$spreadsheet->getActiveSheet()->setTitle('Booking Records');
		$spreadsheet->getProperties()->setCreator('HolidayGoGoGo');
		$spreadsheet->getActiveSheet()->setCellValue('A1', 'BOOKING DATE');
		$spreadsheet->getActiveSheet()->setCellValue('B1', 'SALES AGENT 1');
		$spreadsheet->getActiveSheet()->setCellValue('C1', 'SALES AGENT 2');
		$spreadsheet->getActiveSheet()->setCellValue('D1', 'BOOKING NUMBER');
		$spreadsheet->getActiveSheet()->setCellValue('E1', 'RESERVATION NUMBER');
		$spreadsheet->getActiveSheet()->setCellValue('F1', 'CUSTOMER');
		$spreadsheet->getActiveSheet()->setCellValue('G1', 'MOBILE');
		$spreadsheet->getActiveSheet()->setCellValue('H1', 'TRAVEL DATE');
		$spreadsheet->getActiveSheet()->setCellValue('I1', 'PAX NUMBER');
		$spreadsheet->getActiveSheet()->setCellValue('J1', 'DEPOSIT DEADLINE');
		$spreadsheet->getActiveSheet()->setCellValue('K1', 'FULL PAYMENT DEADLINE');
		$spreadsheet->getActiveSheet()->setCellValue('L1', 'DESTINATION');
		$spreadsheet->getActiveSheet()->setCellValue('M1', 'SUBTOTAL');
		$spreadsheet->getActiveSheet()->setCellValue('N1', 'DISCOUNT');
		$spreadsheet->getActiveSheet()->setCellValue('O1', 'NET TOTAL');
		if(!$hide_profit) {
			$spreadsheet->getActiveSheet()->setCellValue('P1', 'PROFIT');
		}
		$spreadsheet->getActiveSheet()->setCellValue('Q1', 'STATUS');
		$spreadsheet->getActiveSheet()->setCellValue('R1', 'REMARK');
		$spreadsheet->getActiveSheet()->setCellValue('S1', 'CHAT LANGUAGE');
		$spreadsheet->getActiveSheet()->setCellValue('T1', 'SOURCE');
		$spreadsheet->getActiveSheet()->setCellValue('U1', 'CANCELLATION REASON');
		$spreadsheet->getActiveSheet()->setCellValue('V1', 'CANCELLATION REMARK');
		$row = 2;
		$bookings = $this->Booking_Model->Read_Bookings_With_Guest_Lists('Y');
		if(isset($_GET['nick'])) {
			print_r($this->db->last_query());exit;
		}
		$spreadsheet->getActiveSheet()->getStyle('A1:V1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
		$spreadsheet->getActiveSheet()->getStyle('A1:V1')->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE);
		$spreadsheet->getActiveSheet()->getStyle('A1:V1')->getFont()->setBold(true);
		if(!empty($bookings)) {
			$total_subtotal = 0;
			$total_discount = 0;
			$total_net_total = 0;
			$total_profit = 0;
			foreach($bookings as $booking) {
				$total_subtotal += $booking->Subtotal;
				$total_discount += $booking->Discount;
				$total_net_total += $booking->NetTotal;
				if(!empty($booking->AdditionalPaymentDeadline)) {
					$booking->AdditionalPaymentDeadline = strtoupper(date('j M Y', strtotime($booking->AdditionalPaymentDeadline)));
				}
				$booking->CustomerMobile = $booking->CountryCode . str_replace([' ', '-'], '', $booking->CustomerMobile);
				if(!empty($booking->StartDate) && !empty($booking->EndDate)) {
					$booking->TravelDate = strtoupper(date('j M', strtotime($booking->StartDate)) . ' - ' . date('j M Y', strtotime($booking->EndDate)));
				} else {
					$booking->TravelDate = null;
				}
				if(!empty($booking->Adult)) {
					$booking->Adult = $booking->Adult == 1 ? $booking->Adult . ' ADULT ' : $booking->Adult . ' ADULTS ';
				}
				if(!empty($booking->Children)) {
					$booking->Children = $booking->Children == 1 ? $booking->Children . ' CHILD ' : $booking->Children . ' CHILDREN ';
				}
				if(!empty($booking->Infant)) {
					$booking->Infant = $booking->Infant == 1 ? $booking->Infant . ' INFANT ' : $booking->Infant . ' INFANTS ';
				}
				if(!empty($booking->Adult) && !empty($booking->Children) && !empty($booking->Infant)) {
					$booking->PaxNumber = $booking->Adult . '& ' . $booking->Children . '& ' . $booking->Infant;
				} else {
					if(!empty($booking->Adult) && empty($booking->Children) && !empty($booking->Infant)) {
						$booking->PaxNumber = $booking->Adult . '& ' . $booking->Infant;
					} else {
						if(!empty($booking->Adult) && !empty($booking->Children) && empty($booking->Infant)) {
							$booking->PaxNumber = $booking->Adult . '& ' . $booking->Children;
						} else {
							if(!empty($booking->Adult) && empty($booking->Children) && empty($booking->Infant)) {
								$booking->PaxNumber = $booking->Adult;
							} else {
								if(empty($booking->Adult) && !empty($booking->Children) && !empty($booking->Infant)) {
									$booking->PaxNumber = $booking->Children . '& ' . $booking->Infant;
								} else {
									if(empty($booking->Adult) && empty($booking->Children) && !empty($booking->Infant)) {
										$booking->PaxNumber = $booking->Infant;
									} else {
										$booking->PaxNumber = $booking->Children;
									}
								}
							}
						}
					}
				}
				$booking->Discount = $booking->Discount == 0.00 ? '' : $booking->Discount;
				if($booking->LockStatus == 'N' && $booking->Status == 'PTV') {
					$booking->Status = 'PGL';
				}
				if($booking->AfterSalesService == 'PENDING' && $booking->Status == 'Y') {
					$booking->Status = 'PR';
				}
				if(empty($booking->DepositDeadline)) {
					if(date('Y-m-d') > $booking->FullPaymentDeadline && ($booking->Status == 'P' || $booking->Status == 'PP')) {
						$booking->Status = 'PO';
					}
				} else {
					if((date('Y-m-d') > $booking->DepositDeadline && $booking->Status == 'P') || (date('Y-m-d') > $booking->FullPaymentDeadline && ($booking->Status == 'P' || $booking->Status == 'PP'))) {
						$booking->Status = 'PO';
					}
				}
				if(!empty($booking->DepositDeadline)) {
					$booking->DepositDeadline = strtoupper(date('j M Y', strtotime($booking->DepositDeadline)));
				}
				$booking->FullPaymentDeadline = strtoupper(date('j M Y', strtotime($booking->FullPaymentDeadline)));
				if($booking->CancelStatus == 'Y') {
					$booking->Status = 'CANCELLED';
				} else {
					switch($booking->Status) {
						case 'Y':
							$booking->Status = 'COMPLETED';
							break;
						case 'PR':
							$booking->Status = 'PENDING REVIEW';
							break;
						case 'P':
							$booking->Status = 'PENDING PAYMENT';
							break;
						case 'PP':
							$booking->Status = 'PARTIAL PAYMENT';
							break;
						case 'PTV':
							$booking->Status = 'PENDING TRAVEL VOUCHER';
							break;
						case 'PGL':
							$booking->Status = 'PENDING GUEST LIST';
							break;
						case 'PT':
							$booking->Status = 'PENDING TRAVEL';
							break;
						case 'OG':
							$booking->Status = 'ON-GOING';
							break;
						case 'PO':
							$booking->Status = 'PAYMENT OVERDUE';
					}
				}
				$booking->InsertDate = strtoupper(date('j M Y', strtotime($booking->InsertDate)));
				$payments = $this->Booking_Model->Read_Payments($booking->BookingID);
				$total_credit = 0;
				$total_debit = 0;
				$net_profit = 0;
				$profit_margin = 0;
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
					$net_profit = $total_credit - $total_debit;
					if($net_profit != 0) {
						$profit_margin = round(($net_profit / $booking->NetTotal) * 100);
					}
				}
				$booking->Profit = $net_profit != 0 ? 'RM ' . number_format($net_profit, 2, '.', ',') . ' (' . $profit_margin . '%)' : 'RM ' . number_format($net_profit, 2, '.', ',') . ' (0%)';
				$total_profit += $net_profit;
				$spreadsheet->getActiveSheet()->setCellValueExplicit('A' . $row, $booking->InsertDate, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('B' . $row, $booking->SalesAgentName, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('C' . $row, $booking->SalesAgent2Name, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('D' . $row, $booking->BookingNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('E' . $row, $booking->ReservationNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('F' . $row, $booking->Customer, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('G' . $row, $booking->CustomerMobile, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('H' . $row, $booking->TravelDate, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('I' . $row, $booking->PaxNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('J' . $row, $booking->DepositDeadline, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('K' . $row, $booking->FullPaymentDeadline, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('L' . $row, $booking->DestinationName, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValue('M' . $row, $booking->Subtotal);
				$spreadsheet->getActiveSheet()->setCellValue('N' . $row, $booking->Discount);
				$spreadsheet->getActiveSheet()->setCellValue('O' . $row, $booking->NetTotal);
				if(!$hide_profit) {
					$spreadsheet->getActiveSheet()->setCellValue('P' . $row, $booking->Profit);
				}
				$spreadsheet->getActiveSheet()->setCellValueExplicit('Q' . $row, $booking->Status, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('R' . $row, booking_export_remark($booking), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('S' . $row, $booking->ChatLanguage, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('T' . $row, $booking->SourceName, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('U' . $row, booking_export_cancellation_reason($booking), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('V' . $row, booking_export_cancellation_remark($booking), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$row++;
			}
			$total_profit = $total_profit != 0 && $total_net_total != 0 ? number_format($total_profit, 2, '.', ',') . ' (' . round(($total_profit / $total_net_total) * 100) . '%)' : number_format($total_profit, 2, '.', ',') . ' (0%)';
			$spreadsheet->getActiveSheet()->getStyle('M')->getNumberFormat()->setFormatCode('"RM "#,##0.00_-');
			$spreadsheet->getActiveSheet()->getStyle('N')->getNumberFormat()->setFormatCode('"RM "#,##0.00_-');
			$spreadsheet->getActiveSheet()->getStyle('O')->getNumberFormat()->setFormatCode('"RM "#,##0.00_-');
			if(!$hide_profit) {
				$spreadsheet->getActiveSheet()->getStyle('P')->getNumberFormat()->setFormatCode('"RM "#,##0.00_-');
			}
			$spreadsheet->getActiveSheet()->getCell('L' . ($row + 2))->setValue('Total');
			$spreadsheet->getActiveSheet()->getStyle('M' . ($row + 2) . ':' . 'P' . ($row + 2))->getBorders()->getTop()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
			$spreadsheet->getActiveSheet()->setCellValue('M' . ($row + 2), $total_subtotal);
			$spreadsheet->getActiveSheet()->setCellValue('N' . ($row + 2), $total_discount);
			$spreadsheet->getActiveSheet()->setCellValue('O' . ($row + 2), $total_net_total);
			if(!$hide_profit) {
				$spreadsheet->getActiveSheet()->setCellValue('P' . ($row + 2), $total_profit);
			}
			$spreadsheet->getActiveSheet()->getStyle('M' . ($row + 2) . ':' . 'P' . ($row + 2))->getBorders()->getBottom()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_DOUBLE);
			$spreadsheet->getActiveSheet()->getStyle('A:V')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
		} else {
			$spreadsheet->getActiveSheet()->mergeCells('A2:V2');
			$spreadsheet->getActiveSheet()->getCell('A2')->setValue('Booking Records Not Found');
			$spreadsheet->getActiveSheet()->getStyle('A:V')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
		}
		$spreadsheet->getActiveSheet()->getColumnDimension('A')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('B')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('C')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('D')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('E')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('F')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('G')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('H')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('I')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('J')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('K')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('L')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('M')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('N')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('O')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('P')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('Q')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('R')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('S')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('T')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('U')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('V')->setWidth(35);
		if($hide_profit) {
			// Profit column left empty for Sales Agents / Marketing — hide it so the
			// sheet reads NET TOTAL -> STATUS with no blank gap.
			$spreadsheet->getActiveSheet()->getColumnDimension('P')->setVisible(false);
		}

		if($this->input->get('checkbox') == 'ON') {
			$spreadsheet->createSheet();
			$spreadsheet->setActiveSheetIndex(1);
			$spreadsheet->getActiveSheet()->setTitle('Guest Lists');
			$spreadsheet->getActiveSheet()->setCellValue('A1', 'BOOKING NUMBER');
			$spreadsheet->getActiveSheet()->setCellValue('B1', 'TRAVEL DATE');
			$spreadsheet->getActiveSheet()->setCellValue('C1', 'DESTINATION');
			$spreadsheet->getActiveSheet()->setCellValue('D1', 'GUEST TYPE');
			$spreadsheet->getActiveSheet()->setCellValue('E1', 'GUEST');
			$spreadsheet->getActiveSheet()->setCellValue('F1', 'GENDER');
			$spreadsheet->getActiveSheet()->setCellValue('G1', 'DATE OF BIRTH');
			$spreadsheet->getActiveSheet()->setCellValue('H1', 'NATIONALITY');
			$spreadsheet->getActiveSheet()->setCellValue('I1', 'GUEST IDENTIFICATION NUMBER');
			$spreadsheet->getActiveSheet()->setCellValue('J1', 'PASSPORT NUMBER');
			$spreadsheet->getActiveSheet()->setCellValue('K1', 'MOBILE');
			$spreadsheet->getActiveSheet()->setCellValue('L1', 'EMAIL');
			$spreadsheet->getActiveSheet()->setCellValue('M1', 'MARITAL STATUS');
			$spreadsheet->getActiveSheet()->setCellValue('N1', 'EMPLOYMENT');
			$spreadsheet->getActiveSheet()->setCellValue('O1', 'ADDRESS');
			$spreadsheet->getActiveSheet()->setCellValue('P1', 'POSTCODE');
			$spreadsheet->getActiveSheet()->setCellValue('Q1', 'CITY');
			$spreadsheet->getActiveSheet()->setCellValue('R1', 'STATE');
			$spreadsheet->getActiveSheet()->setCellValue('S1', 'COUNTRY');
			$spreadsheet->getActiveSheet()->setCellValue('T1', 'NOMINEE');
			$spreadsheet->getActiveSheet()->setCellValue('U1', 'NOMINEE IDENTIFICATION NUMBER');
			$spreadsheet->getActiveSheet()->setCellValue('V1', 'RELATIONSHIP');
			$row = 2;
			$guest_lists = $this->Booking_Model->Read_Bookings_With_Guest_Lists('N');
			$spreadsheet->getActiveSheet()->getStyle('A1:V1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
			$spreadsheet->getActiveSheet()->getStyle('A1:V1')->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE);
			$spreadsheet->getActiveSheet()->getStyle('A1:V1')->getFont()->setBold(true);
			if(!empty($guest_lists)) {
				foreach($guest_lists as $guest) {
					if(!empty($guest->StartDate) && !empty($guest->EndDate)) {
						$guest->TravelDate = strtoupper(date('j M', strtotime($guest->StartDate)) . ' - ' . date('j M Y', strtotime($guest->EndDate)));
					} else {
						$guest->TravelDate = null;
					}
					if(!empty($guest->DateOfBirth)) {
						$guest->DateOfBirth = strtoupper(date('j M Y', strtotime($guest->DateOfBirth)));
					}
					if(!empty($guest->Nationality)) {
						$guest->Nationality = $this->Universal_Model->Read_Country($guest->Nationality);
					}
					if(!empty($guest->GuestCountryCode) && !empty($guest->GuestMobile)) {
						$country_code = $this->Universal_Model->Read_Country_Code($guest->GuestCountryCode);
						$guest->GuestMobile = $country_code . $guest->GuestMobile;
					} else {
						$guest->GuestMobile = null;
					}
					if(!empty($guest->Country)) {
						$guest->Country = $this->Universal_Model->Read_Country($guest->Country);
					}
					$spreadsheet->getActiveSheet()->setCellValueExplicit('A' . $row, $guest->BookingNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('B' . $row, $guest->TravelDate, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('C' . $row, $guest->DestinationName, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('D' . $row, $guest->Type, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('E' . $row, $guest->GuestName, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('F' . $row, $guest->Gender, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('G' . $row, $guest->DateOfBirth, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('H' . $row, $guest->Nationality, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('I' . $row, $guest->IdentificationNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('J' . $row, $guest->PassportNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('K' . $row, $guest->GuestMobile, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('L' . $row, $guest->Email, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('M' . $row, $guest->MaritalStatus, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('N' . $row, $guest->Employment, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('O' . $row, $guest->Address, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('P' . $row, $guest->Postcode, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('Q' . $row, $guest->City, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('R' . $row, $guest->State, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('S' . $row, $guest->Country, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('T' . $row, $guest->Nominee, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('U' . $row, $guest->NomineeIdentificationNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$spreadsheet->getActiveSheet()->setCellValueExplicit('V' . $row, $guest->Relationship, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$row++;
				}
				$spreadsheet->getActiveSheet()->getStyle('A:V')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
			} else {
				$spreadsheet->getActiveSheet()->mergeCells('A2:V2');
				$spreadsheet->getActiveSheet()->getCell('A2')->setValue('Guest List Records Not Found');
				$spreadsheet->getActiveSheet()->getStyle('A:V')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
			}
			$spreadsheet->getActiveSheet()->getColumnDimension('A')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('B')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('C')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('D')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('E')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('F')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('G')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('H')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('I')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('J')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('K')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('L')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('M')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('N')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('O')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('P')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('Q')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('R')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('S')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('T')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('U')->setWidth(35);
			$spreadsheet->getActiveSheet()->getColumnDimension('V')->setWidth(35);
		}
		
		$spreadsheet->setActiveSheetIndex(0);
		$booking_records = 'BOOKING_RECORDS_' . date('Ymd') . '.xlsx';

		if (ob_get_length()) ob_end_clean();

		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment;filename="' . $booking_records . '"');
		header('Cache-Control: max-age=0');
		header('Cache-Control: max-age=1');
		$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
		$writer->save('php://output');
		exit;
	}

	function Duplicate()
	{
		if(in_array('GB', $this->session->access_control)) {
			$this->Update();
		} else {
			redirect('Dashboard');
		}
    }
	
	function Detect() {
		$redundant_booking_number = $this->Booking_Model->Detect();
		if($redundant_booking_number) {
			echo json_encode(true);
		} else {
			echo json_encode(false);
		}
	}

	public function bulkSyncToAutocount()
    {
        // Read raw JSON body
		$input = json_decode($this->input->raw_input_stream, true);
		$booking_ids = $input['booking_ids'] ? $input['booking_ids'] : [];

		if (empty($booking_ids)) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'No bookings selected'
				]));
			return;
		}

        $results = [];

        foreach ($booking_ids as $booking_id) {
            try {
				$booking = $this->Booking_Model->find($booking_id);
                if (!$booking) {
                    $results[$booking_id] = 'Not Found';
                    continue;
                }

				$bookingData = $this->Booking_Model->getAllBookingsWithGuests($booking_id);
				if (!empty($bookingData)) {
					$bookingData = (array) $bookingData[0]; 
				}

				$bookingProducts = $this->Booking_Model->getAllBookingsWithProducts($booking_id);
				$bookingProducts = array_map('get_object_vars', $bookingProducts); // convert to array

				$this->load->helper('autocount');
				$config = get_autocount_config();
				$statuses = !empty($config['booking_sync_status']) ? $config['booking_sync_status'] : ['BOOKING CONFIRMATION'];

				if (in_array($bookingData['BookingConfirmationTitle'], $statuses)) {
					switch ($bookingData['AutocountSyncStatus']) {
						case 'N': // new → create
							$quotationData = [
								'BookingNumber'   => $bookingData['BookingNumber'] ?? '',
								'InsertDate'      => $bookingData['InsertDate'] ?? date('Y-m-d'),
								'Customer'        => $bookingData['Customer'] ?? '',
								'guest_email'     => $bookingData['guest_email'] ?? '',
								'guest_address'   => $bookingData['guest_address'] ?? '',
								'guest_phone'     => $bookingData['guest_phone'] ?? '',
								'BokingRemark'    => $bookingData['BokingRemark'] ?? '',
								
								// Fields not in DB → set default or null
								'credit_term'     => null,
								'sales_location'  => '',
								'currency_rate'   => 1,
								'inclusive_tax'   => false,
								'is_round_adj'    => false,
								'tax_code'        => '',

								// Details
								'booking_product' => $bookingProducts
							];

							$respond = $this->autocount_create($quotationData);
							if ($booking != null && $respond != null) {
								if ($respond['error']) {
									$this->Booking_Model->update_by_id($booking_id, [
										'AutocountSyncMessage' => json_encode($respond),
									]);
									$results[$booking->BookingID] = $respond['error'];
								} elseif ($respond['status'] === 201 || $respond['status'] === 204) {
									$this->Booking_Model->update_by_id($booking_id, [
										'AutocountSyncMessage' => json_encode($respond),
										'AutocountSyncStatus' => 'C'
									]);
								} 
							}	
							$results[$booking->BookingID] = 'Created';
							break;

						case 'U': // update
						case 'C': // created → still allow update
							$quotationData = [
								'BookingNumber'   => $bookingData['BookingNumber'],
								'DocNo'           => $bookingData['BookingNumber'], // Fallback
								'master'          => [
									'DocDate'        => date('Y-m-d', strtotime($bookingData['InsertDate'])),
									'DebtorName'     => $bookingData['Customer'],
									'Email'          => $bookingData['guest_email'],
									'Address'        => $bookingData['guest_address'],
									'Phone1'         => $bookingData['guest_phone'],
									'DeliverAddress' => $bookingData['guest_address'],
									'DeliverContact' => $bookingData['Customer'],
									'DeliverPhone1'  => $bookingData['guest_phone'],
									'Remark1'        => $bookingData['BokingRemark'],
								],
								'booking_product' => $bookingProducts,
								'tax_code'        => '', // Default tax code if missing
								'saveApprove'     => null
							];
							$respond = $this->autocount_update($booking);
							if ($booking != null && $respond != null) {
								if ($respond['error']) {
									$this->Booking_Model->update_by_id($booking_id, [
										'AutocountSyncMessage' => json_encode($respond),
									]);
									$results[$booking->BookingID] = $respond['error'];
								} elseif ($respond['status'] === 201 || $respond['status'] === 204) {
									$this->Booking_Model->update_by_id($booking_id, [
										'AutocountSyncMessage' => json_encode($respond),
										'AutocountSyncStatus' => 'U'
									]);
								} 
							}	
							$results[$booking->BookingID] = 'Updated';
							break;

						case 'D': // delete
							$bookingNumber = (!empty($booking) && !empty($booking->BookingNumber)) ? $booking->BookingNumber : '';

							if (!empty($booking) && $booking->status == 'N') {
								if (!empty($bookingNumber)) {
									$respond = $this->autocount_delete([
										'BookingNumber' => $bookingNumber
									]);
									if ($booking != null && $respond != null) {
										if ($respond['error']) {
											$this->Booking_Model->update_by_id($booking_id, [
												'AutocountSyncMessage' => json_encode($respond),
											]);
											$results[$booking->BookingID] = $respond['error'];
										} elseif ($respond['status'] === 201 || $respond['status'] === 204) {
											$this->Booking_Model->update_by_id($booking_id, [
												'AutocountSyncMessage' => json_encode($respond),
												'AutocountSyncStatus' => 'D'
											]);
										} 
									}	
									$results[$booking->BookingID] = 'Deleted';
									break;
								}
							}
						case 'V': // void
							$bookingNumber = (!empty($booking) && !empty($booking->BookingNumber)) ? $booking->BookingNumber : '';

							if (!empty($bookingNumber)) {
								$respond = $this->autocount_void($booking);
								if ($booking != null && $respond != null) {
									if ($respond['error']) {
										$this->Booking_Model->update_by_id($booking_id, [
											'AutocountSyncMessage' => json_encode($respond),
										]);
										$results[$booking->BookingID] = $respond['error'];
									} elseif ($respond['status'] === 201 || $respond['status'] === 204) {
										$this->Booking_Model->update_by_id($booking_id, [
											'AutocountSyncMessage' => json_encode($respond),
											'AutocountSyncStatus' => 'V'
										]);
									} 
								}
								$results[$booking->BookingID] = 'Voided';
								break;
							}
							

						default:
							$results[$booking->BookingID] = 'Skipped';
							break;
					}
				}

            } catch (\Exception $e) {
                $results[$bookingData['BookingID']] = 'Error: ' . $e->getMessage();
            }
        }

		$this->output
        ->set_content_type('application/json')
        ->set_output(json_encode([
            'success' => true,
            'message' => implode("\n", $results) // return as plain text
        ]));
    }

	/**
	 * Bulk change autocount status to Pending (P) with reset logic
	 * If status is F: update directly to P
	 * If status is P: update to F first, then to P (to trigger re-sync)
	 */
	public function bulkChangeAutocountStatusToPending()
	{
		$json = file_get_contents('php://input');
		$data = json_decode($json, true);
		$booking_ids = isset($data['booking_ids']) ? $data['booking_ids'] : [];

		if (empty($booking_ids)) {
			echo json_encode(['success' => false, 'message' => 'No bookings selected']);
			return;
		}

		$result = $this->Booking_Model->Update_Autocount_Status_To_Pending_With_Reset($booking_ids);

		if ($result) {
			echo json_encode(['success' => true, 'message' => count($booking_ids) . ' booking(s) updated to Pending status']);
		} else {
			echo json_encode(['success' => false, 'message' => 'Failed to update bookings']);
		}
	}

	public function autocount_create($data)
	{
		try {
			// Use the first product row's name as the quotation header description
			$firstProductName = '';
			if (!empty($data['booking_product']) && is_array($data['booking_product'])) {
				$firstProduct = reset($data['booking_product']);
				$firstProductName = arr_get($firstProduct, 'product_Name', '');
			}

			$body['master'] = [
				'docNo'           => arr_get($data, 'BookingNumber'),
				'docNoFormatName' => arr_get($data, 'docNoFormatName', null),
				'docDate'         => arr_get($data, 'InsertDate'),
				'debtorCode'      => arr_get($data, 'debtor_code', ''),
				'debtorName'      => arr_get($data, 'Customer'),
				'email'           => arr_get($data, 'guest_email'),
				'emailCC'         => arr_get($data, 'emailCC', null),
				'emailBCC'        => arr_get($data, 'emailBCC', null),
				'address'         => arr_get($data, 'guest_address'),
				'attention'       => arr_get($data, 'attention', ''),
				'phone1'          => arr_get($data, 'guest_phone'),
				'fax1'            => arr_get($data, 'fax1', ''),
				'deliverAddress'  => arr_get($data, 'guest_address'),
				'deliverContact'  => arr_get($data, 'Customer'),
				'deliverPhone1'   => arr_get($data, 'guest_phone'),
				'deliverFax1'     => arr_get($data, 'deliver_fax1', ''),
				'ref'             => arr_get($data, 'ref', null),
				'description'     => arr_get($data, 'description', $firstProductName),
				'note'            => arr_get($data, 'note', null),
				'salesAgent'      => arr_get($data, 'salesAgent', ''),
				'creditTerm'      => arr_get($data, 'credit_term', 'C.O.D.'),
				'salesLocation'   => arr_get($data, 'sales_location', 'HQ'),
				'remark1'         => arr_get($data, 'BokingRemark'),
				'remark2'         => arr_get($data, 'remark2', null),
				'remark3'         => arr_get($data, 'remark3', null),
				'remark4'         => arr_get($data, 'remark4', null),
				'currencyRate'    => arr_get($data, 'currency_rate', 1),
				'inclusiveTax'    => arr_get($data, 'inclusive_tax', false),
				'isRoundAdj'      => arr_get($data, 'is_round_adj', false),
				'yourRef'         => arr_get($data, 'yourRef', null),
				'validity'        => arr_get($data, 'validity', null),
				'cc'              => arr_get($data, 'cc', null),
				'deliveryTerm'    => arr_get($data, 'deliveryTerm', null),
				'paymentTerm'     => arr_get($data, 'paymentTerm', null),
			];

			$body['details'] = [];
			if (!empty($data['booking_product']) && is_array($data['booking_product'])) {
				foreach ($data['booking_product'] as $product) {
					$body['details'][] = [
						'productCode'        => arr_get($product, 'product_ProductCode'),
						'productVariant'     => arr_get($product, 'productVariant', null),
						'description'        => arr_get($product, 'product_Description'),
						'furtherDescription' => arr_get($product, 'furtherDescription', ''),
						'qty'                => arr_get($product, 'product_Quantity', 1),
						// Null UOM => AutoCount uses the stock item's base UOM. The literal
						// 'unit' is rejected as "unit or multi pack 'unit' not exists".
						'unit'               => arr_get($product, 'unit', null),
						'unitPrice'          => arr_get($product, 'product_Price', 0),
						'discount'           => arr_get($product, 'discount', null),
						'taxCode'            => arr_get($product, 'tax_code', 'S-5'),
						'taxAdjustment'      => arr_get($product, 'taxAdjustment', 0),
						'localTaxAdjustment' => arr_get($product, 'localTaxAdjustment', 0),
						'deptNo'             => arr_get($product, 'deptNo', null),
					];
				}
			}

			$body['autoFillOption'] = [
				'taxCode' => arr_get($data, 'tax_code', true),
			];

			$body['saveApprove'] = arr_get($data, 'save_approve', null);

			// dd(json_encode($body));
			// return '1';
			return autocount_request(
				'POST',
				'quotation.create',
				$body,
				['docNo' => $data['BookingNumber']]
			);

		} catch (Exception $e) {
			log_message('error', 'Autocount create error: ' . $e->getMessage());

			return [
				'status' => 500,
				'error'  => $e->getMessage(),
				'data'   => [],
			];
		}
	}

   	public function autocount_update($data)
	{
		try {
			$docNo = arr_get($data, 'BookingNumber');

			// Use the first product row's name as the quotation header description
			$firstProductName = '';
			if (!empty($data['booking_product']) && is_array($data['booking_product'])) {
				$firstProduct = reset($data['booking_product']);
				$firstProductName = arr_get($firstProduct, 'product_Name', '');
			}

			$body['master'] = [
				'docNo'           => arr_get($data, 'BookingNumber'),
				'docNoFormatName' => arr_get($data, 'docNoFormatName', null),
				'docDate'         => arr_get($data, 'InsertDate'),
				'debtorCode'      => arr_get($data, 'debtor_code', ''),
				'debtorName'      => arr_get($data, 'Customer'),
				'email'           => arr_get($data, 'guest_email'),
				'emailCC'         => arr_get($data, 'emailCC', null),
				'emailBCC'        => arr_get($data, 'emailBCC', null),
				'address'         => arr_get($data, 'guest_address'),
				'attention'       => arr_get($data, 'attention', ''),
				'phone1'          => arr_get($data, 'guest_phone'),
				'fax1'            => arr_get($data, 'fax1', ''),
				'deliverAddress'  => arr_get($data, 'guest_address'),
				'deliverContact'  => arr_get($data, 'Customer'),
				'deliverPhone1'   => arr_get($data, 'guest_phone'),
				'deliverFax1'     => arr_get($data, 'deliver_fax1', ''),
				'ref'             => arr_get($data, 'ref', null),
				'description'     => arr_get($data, 'description', $firstProductName),
				'note'            => arr_get($data, 'note', null),
				'salesAgent'      => arr_get($data, 'salesAgent', ''),
				'creditTerm'      => arr_get($data, 'credit_term', 'C.O.D.'),
				'salesLocation'   => arr_get($data, 'sales_location', 'HQ'),
				'remark1'         => arr_get($data, 'BokingRemark'),
				'remark2'         => arr_get($data, 'remark2', null),
				'remark3'         => arr_get($data, 'remark3', null),
				'remark4'         => arr_get($data, 'remark4', null),
				'currencyRate'    => arr_get($data, 'currency_rate', 1),
				'inclusiveTax'    => arr_get($data, 'inclusive_tax', false),
				'isRoundAdj'      => arr_get($data, 'is_round_adj', false),
				'yourRef'         => arr_get($data, 'yourRef', null),
				'validity'        => arr_get($data, 'validity', null),
				'cc'              => arr_get($data, 'cc', null),
				'deliveryTerm'    => arr_get($data, 'deliveryTerm', null),
				'paymentTerm'     => arr_get($data, 'paymentTerm', null),
			];

			$body['details'] = [];
			if (!empty($data['booking_product']) && is_array($data['booking_product'])) {
				foreach ($data['booking_product'] as $product) {
					$body['details'][] = [
						'productCode'        => arr_get($product, 'product_ProductCode'),
						'productVariant'     => arr_get($product, 'productVariant', null),
						'description'        => arr_get($product, 'product_Description'),
						'furtherDescription' => arr_get($product, 'furtherDescription', ''),
						'qty'                => arr_get($product, 'product_Quantity', 1),
						// Null UOM => AutoCount uses the stock item's base UOM. The literal
						// 'unit' is rejected as "unit or multi pack 'unit' not exists".
						'unit'               => arr_get($product, 'unit', null),
						'unitPrice'          => arr_get($product, 'product_Price', 0),
						'discount'           => arr_get($product, 'discount', null),
						'taxCode'            => arr_get($product, 'tax_code', 'S-5'),
						'taxAdjustment'      => arr_get($product, 'taxAdjustment', 0),
						'localTaxAdjustment' => arr_get($product, 'localTaxAdjustment', 0),
						'deptNo'             => arr_get($product, 'deptNo', null),
					];
				}
			}

			$body['autoFillOption'] = [
				'taxCode' => arr_get($data, 'tax_code', true),
			];

			$body['saveApprove'] = arr_get($data, 'save_approve', null);

			return autocount_request(
				'PUT',
				'quotation.update',
				$body,
				['docNo' => $docNo]
			);

		} catch (Exception $e) {
			log_message('error', 'Autocount update error: ' . $e->getMessage());

			return [
				'status' => 500,
				'error'  => $e->getMessage(),
				'data'   => [],
			];
		}
	}

	public function autocount_update_status($data)
	{
		try {
			$docNo = arr_get($data, 'BookingNumber');
			
			// Map the status to AutoCount status code
			$status = arr_get($data, 'Status', 'S');
			$autoCountStatus = mapAutoCountStatus($status);  // Use the mapping helper

			// If no valid status is found, log an error and return
			if ($autoCountStatus === null) {
				log_message('error', 'Invalid status: ' . $status);
				return [
					'status' => 400,
					'error'  => 'Invalid status value provided.',
					'data'   => [],
				];
			}

			// Prepare the body for the request
			$body = [
				'documentStatus' => $autoCountStatus,  // Use mapped status
				'lostReason'     => arr_get($data, 'reason'),
			];

			// Make the API request
			return autocount_request(
				'PUT',
				'quotation.update_status',
				$body,
				['docNo' => $docNo]
			);

		} catch (Exception $e) {
			log_message('error', 'Autocount update_status error: ' . $e->getMessage());

			return [
				'status' => 500,
				'error'  => $e->getMessage(),
				'data'   => [],
			];
		}
	}

	public function autocount_delete($data)
	{
		try {
			$docNo = arr_get($data, 'BookingNumber');

			return autocount_request(
				'DELETE',
				'quotation.delete',
				[],
				['docNo' => $docNo]
			);

		} catch (Exception $e) {
			log_message('error', 'Autocount delete error: ' . $e->getMessage());

			return [
				'status' => 500,
				'error'  => $e->getMessage(),
				'data'   => [],
			];
		}
	}

	public function autocount_void($data)
	{
		try {
			$docNo = arr_get($data, 'BookingNumber');

			$body = [
				'voidReason' => arr_get($data, 'reason'),
			];

			return autocount_request(
				'POST',
				'quotation.void',
				$body,
				['docNo' => $docNo]
			);

		} catch (Exception $e) {
			log_message('error', 'Autocount void error: ' . $e->getMessage());

			return [
				'status' => 500,
				'error'  => $e->getMessage(),
				'data'   => [],
			];
		}
	}

	public function UpdateAllowReview()
	{
		// Check access control
		if (!in_array('AB', $this->session->access_control)) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Access denied'
				]));
			return;
		}

		// Check if AJAX request
		if (!$this->input->is_ajax_request()) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Invalid request'
				]));
			return;
		}

		try {
			$data = $this->input->post();
			$bookingId = arr_get($data, 'booking_id');
			$booking = $this->input->post('booking');
			$bookingLog = $this->input->post('booking_log');

			// Validate booking ID
			if (empty($bookingId)) {
				$this->output
					->set_content_type('application/json')
					->set_output(json_encode([
						'success' => false,
						'message' => 'Booking ID is required'
					]));
				return;
			}

			// Validate booking data
			if (empty($booking) || !is_array($booking) || !isset($booking[0])) {
				$this->output
					->set_content_type('application/json')
					->set_output(json_encode([
						'success' => false,
						'message' => 'Invalid booking data'
					]));
				return;
			}

			// Get AllowReview value and validate
			$allowReview = isset($booking[0]['AllowReview']) ? (int)$booking[0]['AllowReview'] : null;
			if ($allowReview === null || ($allowReview != 0 && $allowReview != 1)) {
				$this->output
					->set_content_type('application/json')
					->set_output(json_encode([
						'success' => false,
						'message' => 'Invalid AllowReview value'
					]));
				return;
			}

			// Verify booking exists
			$existingBooking = $this->Booking_Model->find($bookingId);
			if (empty($existingBooking)) {
				$this->output
					->set_content_type('application/json')
					->set_output(json_encode([
						'success' => false,
						'message' => 'Booking not found'
					]));
				return;
			}

			// Update AllowReview using update_batch
			$bookingData = [
				[
					'BookingID' => $bookingId,
					'AllowReview' => $allowReview,
					'UpdateBy' => $this->session->userdata('admin_id'),
					'UpdateDate' => date('Y-m-d H:i:s')
				]
			];

			// Update the booking
			$this->db->update_batch('booking', $bookingData, 'BookingID');

			// Create booking log if provided
			if (!empty($bookingLog) && is_array($bookingLog) && !empty($bookingLog[0])) {
				$this->db->insert_batch('booking_log', $bookingLog);
			}

			// Return success response
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => true,
					'message' => 'Allow Review updated successfully'
				]));

		} catch (Exception $e) {
			log_message('error', 'Update allow review error: ' . $e->getMessage());
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'An error occurred while updating Allow Review'
				]));
		}
	}

	/**
	 * Save the slow-conversion reasons tagged on a booking. Replaces the
	 * booking's junction rows with exactly the submitted reason id set
	 * (Save_Booking_Reasons writes only the add/remove difference).
	 */
	public function UpdateSlowConversionReasons()
	{
		if (!in_array('AB', $this->session->access_control)) {
			$this->output->set_content_type('application/json')
				->set_output(json_encode(['success' => false, 'message' => 'Access denied']));
			return;
		}

		if (!$this->input->is_ajax_request()) {
			$this->output->set_content_type('application/json')
				->set_output(json_encode(['success' => false, 'message' => 'Invalid request']));
			return;
		}

		try {
			$bookingId = (int) $this->input->post('booking_id');
			if (empty($bookingId)) {
				$this->output->set_content_type('application/json')
					->set_output(json_encode(['success' => false, 'message' => 'Booking ID is required']));
				return;
			}

			$existingBooking = $this->Booking_Model->find($bookingId);
			if (empty($existingBooking)) {
				$this->output->set_content_type('application/json')
					->set_output(json_encode(['success' => false, 'message' => 'Booking not found']));
				return;
			}

			$reasonIds = $this->input->post('reason_ids');
			if (!is_array($reasonIds)) {
				$reasonIds = array();
			}

			$this->load->model('Slow_Conversion_Reason_Model');
			$this->Slow_Conversion_Reason_Model->Save_Booking_Reasons(
				$bookingId,
				$reasonIds,
				$this->session->userdata('admin_id')
			);

			$this->output->set_content_type('application/json')
				->set_output(json_encode(['success' => true, 'message' => 'Slow conversion reasons saved']));
		} catch (Exception $e) {
			log_message('error', 'Update slow conversion reasons error: ' . $e->getMessage());
			$this->output->set_content_type('application/json')
				->set_output(json_encode(['success' => false, 'message' => 'An error occurred while saving reasons']));
		}
	}

	/**
	 * Upload custom file for booking
	 */
	function Upload_Custom_File()
	{
		if (!in_array('AB', $this->session->access_control)) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Access denied'
				]));
			return;
		}

		if (!$this->input->is_ajax_request()) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Invalid request'
				]));
			return;
		}

		$booking_id = $this->input->post('booking_id');
		$upload_name = $this->input->post('upload_name');

		if (empty($booking_id) || empty($upload_name)) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Booking ID and upload name are required'
				]));
			return;
		}

		// Validate booking exists
		$booking = $this->Booking_Model->find($booking_id);
		if (empty($booking)) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Booking not found'
				]));
			return;
		}

		// Configure upload
		$config['upload_path'] = FCPATH . 'assets/upload/custom/';
		$config['allowed_types'] = 'pdf|jpg|jpeg|png|gif|doc|docx|xls|xlsx|txt';
		$config['max_size'] = 10240; // 10MB
		$config['encrypt_name'] = true;

		// Create upload directory if it doesn't exist
		if (!is_dir($config['upload_path'])) {
			mkdir($config['upload_path'], 0755, true);
		}

		$this->load->library('upload', $config);

		if (!$this->upload->do_upload('upload_file')) {
			$error = $this->upload->display_errors('', '');
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Upload failed: ' . $error
				]));
			return;
		}

		$upload_data = $this->upload->data();
		$file_path = 'assets/upload/custom/' . $upload_data['file_name'];

		// Save to database
		$this->load->model('Custom_Upload_Model');
		$upload_id = $this->Custom_Upload_Model->Create(
			$booking_id,
			$upload_name,
			$file_path,
			$this->session->userdata('admin_id')
		);

		// Get admin name
		$this->db->select('Name');
		$this->db->where('AdminID', $this->session->userdata('admin_id'));
		$admin = $this->db->get('admin')->row_array();
		
		// Load utils helper for date formatting
		$this->load->helper('utils');
		$upload_record = $this->Custom_Upload_Model->Get_By_Id($upload_id);
		$created_at_formatted = return_timestamp_output($upload_record['created_at']);

		$this->output
			->set_content_type('application/json')
			->set_output(json_encode([
				'success' => true,
				'message' => 'File uploaded successfully',
				'upload' => [
					'id' => $upload_id,
					'upload_name' => $upload_name,
					'upload_content' => base_url($file_path),
					'created_by' => $admin['Name'],
					'created_at' => $created_at_formatted
				]
			]));
	}

	/**
	 * Upload an invoice document attached to a single supplier-invoice row.
	 *
	 * Multipart AJAX (the main booking save posts url-encoded JSON and cannot
	 * carry files, so attachments upload immediately on file-select). Restricted
	 * to the 'AB' (All Booking) code — the whole controller is already behind
	 * admin login via MY_Controller, so customers can never reach this.
	 *
	 * Files are commercially sensitive (supplier cost) and are NEVER linked to
	 * directly: they land in a deny-all-protected directory and are read back
	 * only through Supplier_Invoice_File(). For an existing row the new path is
	 * persisted immediately (and any previous file removed); for a brand-new row
	 * the relative path is returned so the main save can persist it.
	 */
	function Upload_Supplier_Invoice_File()
	{
		$this->output->set_content_type('application/json');

		if (!in_array('AB', $this->session->access_control)) {
			$this->output->set_output(json_encode(['success' => false, 'message' => 'Access denied']));
			return;
		}
		if (!$this->input->is_ajax_request()) {
			$this->output->set_output(json_encode(['success' => false, 'message' => 'Invalid request']));
			return;
		}

		$booking_id          = (int) $this->input->post('booking_id');
		$supplier_invoice_id = (int) $this->input->post('supplier_invoice_id'); // 0 for an unsaved row
		if (empty($booking_id)) {
			$this->output->set_output(json_encode(['success' => false, 'message' => 'Booking ID is required']));
			return;
		}
		if (empty($this->Booking_Model->find($booking_id))) {
			$this->output->set_output(json_encode(['success' => false, 'message' => 'Booking not found']));
			return;
		}

		$this->load->helper('supplier_invoice');

		// Extension whitelist BEFORE handing off to the upload library — rejects
		// scripts/executables and double-extension payloads up front.
		$client_name = isset($_FILES['invoice_file']['name']) ? $_FILES['invoice_file']['name'] : '';
		if ($client_name === '' || !supplier_invoice_is_allowed_file($client_name)) {
			$this->output->set_output(json_encode([
				'success' => false,
				'message' => 'Unsupported file type. Allowed: PDF, JPG, PNG, DOC(X), XLS(X).'
			]));
			return;
		}

		$config = [
			'upload_path'   => supplier_invoice_ensure_upload_dir(),
			'allowed_types' => supplier_invoice_allowed_types(),
			'max_size'      => 10240, // 10MB
			'encrypt_name'  => true,
		];
		$this->load->library('upload', $config);

		if (!$this->upload->do_upload('invoice_file')) {
			$error = trim(strip_tags($this->upload->display_errors('', '')));
			$this->output->set_output(json_encode(['success' => false, 'message' => 'Upload failed: ' . $error]));
			return;
		}

		$upload_data = $this->upload->data();
		$rel_path    = supplier_invoice_upload_reldir() . $upload_data['file_name'];

		// Existing row: persist the path now and unlink the file it replaces.
		if ($supplier_invoice_id) {
			$existing = $this->Booking_Supplier_Invoice_Model->Get_By_Id($supplier_invoice_id);
			if (!empty($existing) && (int) $existing->BookingID === $booking_id) {
				if (!empty($existing->InvoiceFilePath) && is_file(FCPATH . $existing->InvoiceFilePath)) {
					@unlink(FCPATH . $existing->InvoiceFilePath);
				}
				$this->Booking_Supplier_Invoice_Model->Update([
					['SupplierInvoiceID' => $supplier_invoice_id, 'InvoiceFilePath' => $rel_path]
				]);
			} else {
				// id didn't resolve to this booking — drop the orphan upload.
				@unlink(FCPATH . $rel_path);
				$this->output->set_output(json_encode(['success' => false, 'message' => 'Invoice not found']));
				return;
			}
		}

		$this->output->set_output(json_encode([
			'success'    => true,
			'message'    => 'File uploaded',
			'file_path'  => $rel_path,                          // travels in the main save for unsaved rows
			'file_name'  => $upload_data['orig_name'],
			'view_url'   => $supplier_invoice_id
				? base_url('Booking/Supplier_Invoice_File/' . $supplier_invoice_id)
				: '',
		]));
	}

	/**
	 * Stream a supplier-invoice attachment to authorised staff only.
	 *
	 * Admin login is enforced controller-wide by MY_Controller; this further
	 * restricts to the 'AB' code. The file is read from disk with readfile() —
	 * it is never exposed at a public asset URL, and a realpath containment
	 * check guarantees only files inside the invoice upload dir can be served
	 * (defends against a tampered InvoiceFilePath / path traversal).
	 */
	function Supplier_Invoice_File($supplier_invoice_id = null)
	{
		if (!in_array('AB', $this->session->access_control)) {
			show_error('Access denied', 403);
			return;
		}

		$invoice = $this->Booking_Supplier_Invoice_Model->Get_By_Id((int) $supplier_invoice_id);
		if (empty($invoice) || empty($invoice->InvoiceFilePath)) {
			show_404();
			return;
		}

		$this->load->helper('supplier_invoice');
		$base = realpath(supplier_invoice_upload_dir());
		$real = realpath(FCPATH . $invoice->InvoiceFilePath);
		if ($real === false || $base === false || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) {
			show_404();
			return;
		}

		$download_name = 'invoice_' . $invoice->SupplierInvoiceID . '.' . strtolower(pathinfo($real, PATHINFO_EXTENSION));
		header('Content-Type: ' . supplier_invoice_file_mime($real));
		header('Content-Disposition: inline; filename="' . $download_name . '"');
		header('Content-Length: ' . filesize($real));
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: private, max-age=0, no-cache');
		readfile($real);
		exit;
	}

	/**
	 * Upload an inline image from the booking voucher TinyMCE editors.
	 *
	 * TinyMCE 5 posts the file as multipart field `file` to images_upload_url
	 * and expects a JSON body of {"location": "<url>"} on success or
	 * {"error": {"message": "...", "remove": true}} on failure.
	 */
	function Upload_Voucher_Image()
	{
		$this->output->set_content_type('application/json');

		if (!in_array('AB', $this->session->access_control)) {
			$this->output
				->set_status_header(403)
				->set_output(json_encode([
					'error' => ['message' => 'Access denied', 'remove' => true]
				]));
			return;
		}

		if (!$this->input->is_ajax_request()) {
			$this->output
				->set_status_header(400)
				->set_output(json_encode([
					'error' => ['message' => 'Invalid request', 'remove' => true]
				]));
			return;
		}

		$this->load->helper('voucher_image');

		$file_meta = isset($_FILES['file']) ? $_FILES['file'] : [];
		$max_kb = 5120;
		$validation = validate_voucher_image_upload($file_meta, $max_kb);
		if (!$validation['ok']) {
			$this->output
				->set_status_header(400)
				->set_output(json_encode([
					'error' => ['message' => $validation['error'], 'remove' => true]
				]));
			return;
		}

		$config['upload_path']   = FCPATH . 'assets/upload/voucher/';
		$config['allowed_types'] = 'jpg|jpeg|png|gif|webp';
		$config['max_size']      = $max_kb;
		$config['encrypt_name']  = true;

		if (!is_dir($config['upload_path'])) {
			mkdir($config['upload_path'], 0755, true);
		}

		$this->load->library('upload', $config);

		if (!$this->upload->do_upload('file')) {
			$error = $this->upload->display_errors('', '');
			$this->output
				->set_status_header(400)
				->set_output(json_encode([
					'error' => ['message' => 'Upload failed: ' . trim(strip_tags($error)), 'remove' => true]
				]));
			return;
		}

		$upload_data = $this->upload->data();
		$full_path = $upload_data['full_path'];

		// Defence-in-depth: reject anything getimagesize() can't recognise as a raster.
		$image_info = @getimagesize($full_path);
		if ($image_info === false || empty($image_info[0]) || empty($image_info[1])) {
			@unlink($full_path);
			$this->output
				->set_status_header(400)
				->set_output(json_encode([
					'error' => ['message' => 'Uploaded file is not a valid image', 'remove' => true]
				]));
			return;
		}

		$file_path = 'assets/upload/voucher/' . $upload_data['file_name'];
		$this->output->set_output(json_encode([
			'location' => base_url($file_path),
		]));
	}

	/**
	 * Delete custom upload
	 */
	function Delete_Custom_Upload()
	{
		if (!in_array('AB', $this->session->access_control)) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Access denied'
				]));
			return;
		}

		if (!$this->input->is_ajax_request()) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Invalid request'
				]));
			return;
		}

		$upload_id = $this->input->post('upload_id');
		if (empty($upload_id)) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Upload ID is required'
				]));
			return;
		}

		$this->load->model('Custom_Upload_Model');
		$upload = $this->Custom_Upload_Model->Get_By_Id($upload_id);

		if (empty($upload)) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Upload not found'
				]));
			return;
		}

		// Delete file
		$file_path = FCPATH . $upload['upload_content'];
		if (file_exists($file_path) && is_file($file_path)) {
			@unlink($file_path);
		}

		// Delete from database
		$this->Custom_Upload_Model->Delete($upload_id);

		$this->output
			->set_content_type('application/json')
			->set_output(json_encode([
				'success' => true,
				'message' => 'Upload deleted successfully'
			]));
	}

    /**
     * AJAX endpoint to get remarks for a booking
     */
    function Get_Remarks()
    {
        if (!in_array('AB', $this->session->access_control)) {
            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'success' => false,
                    'message' => 'Access denied'
                ]));
            return;
        }

        $booking_id = $this->input->get('booking_id');
        if (empty($booking_id)) {
            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'success' => false,
                    'message' => 'Booking ID is required'
                ]));
            return;
        }

        // Get internal remarks (type 1) only
        $remarks = $this->Remark_Model->Read_Remarks('booking', $booking_id, REMARK_TYPE::INTERNAL);

		// Format remarks for JSON response
		$formatted_remarks = array();
		foreach ($remarks as $remark) {
			// Get initials for avatar
			$initials = '';
			if (!empty($remark->CommenterName)) {
				$name_parts = explode(' ', $remark->CommenterName);
				if (count($name_parts) >= 2) {
					$initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[count($name_parts) - 1], 0, 1));
				} else {
					$initials = strtoupper(substr($remark->CommenterName, 0, 2));
				}
			}

			$current_user_id = $this->session->userdata('admin_id');

			// Get remark type label from REMARK_TYPE class
			$remark_type = isset($remark->type) ? $remark->type : REMARK_TYPE::INTERNAL;
			$remark_type_label = REMARK_TYPE::getLabel($remark_type) ?: 'INTERNAL';

			$formatted_remarks[] = array(
				'RemarkID' => $remark->RemarkID,
				'content' => $remark->content,
				'commenter_name' => $remark->CommenterName,
				'commenter_id' => $remark->commenter_id,
				'is_owner' => ($remark->commenter_id == $current_user_id),
				'commenter_initials' => $initials,
				'type' => isset($remark->type) ? $remark->type : 1,
				'type_label' => $remark_type_label,
				'created_at' => return_timestamp_output($remark->created_at),
				'created_at_raw' => $remark->created_at,
			);
		}

		$this->output
			->set_content_type('application/json')
			->set_output(json_encode([
				'success' => true,
				'remarks' => $formatted_remarks
			]));
	}

    /**
     * AJAX endpoint to get customer remarks for a booking
     */
    function Get_Customer_Remarks()
    {
        // Allow AB access control OR Sales Agent (level 20) for their own bookings
        $is_sales_agent = $this->session->userdata('level') == 20;
        $has_ab_access = in_array('AB', $this->session->access_control);
        
        if (!$has_ab_access && !$is_sales_agent) {
            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'success' => false,
                    'message' => 'Access denied'
                ]));
            return;
        }

        $booking_id = $this->input->get('booking_id');
        if (empty($booking_id)) {
            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'success' => false,
                    'message' => 'Booking ID is required'
                ]));
            return;
        }

        // If sales agent without AB access, verify they own the booking
        if($is_sales_agent && !$has_ab_access) {
            $booking = $this->Booking_Model->getBookingById($booking_id);
            if(!$booking || $booking->SalesAgent != $this->session->userdata('admin_id')) {
                $this->output
                    ->set_content_type('application/json')
                    ->set_output(json_encode([
                        'success' => false,
                        'message' => 'You can only view remarks for bookings assigned to you.'
                    ]));
                return;
            }
        }

        // Get customer remarks (type 2) only
        $remarks = $this->Remark_Model->Read_Remarks('booking', $booking_id, REMARK_TYPE::CUSTOMER);
        
        // Format remarks for JSON response
        $formatted_remarks = array();
        foreach ($remarks as $remark) {
            // Determine commenter name - if CommenterName exists, it's an admin, otherwise it's the customer
            $commenter_name = 'Customer';
            $initials = '';
            
            if (!empty($remark->CommenterName)) {
                // Admin created this remark - use admin name from join
                $commenter_name = $remark->CommenterName;
            } else {
                // Customer created this remark - get customer name from booking
                $this->db->select('Customer');
                $this->db->where('BookingID', $booking_id);
                $booking_info = $this->db->get('booking')->row();
                $commenter_name = !empty($booking_info) ? $booking_info->Customer : 'Customer';
            }
            
            // Get initials for avatar
            if (!empty($commenter_name)) {
                $name_parts = explode(' ', $commenter_name);
                if (count($name_parts) >= 2) {
                    $initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[count($name_parts) - 1], 0, 1));
                } else {
                    $initials = strtoupper(substr($commenter_name, 0, 2));
                }
            }
            
            $formatted_remarks[] = array(
                'RemarkID' => $remark->RemarkID,
                'content' => $remark->content,
                'commenter_name' => $commenter_name,
                'commenter_id' => $remark->commenter_id,
                'commenter_initials' => $initials,
                'created_at' => return_timestamp_output($remark->created_at),
                'created_at_raw' => $remark->created_at
            );
        }

        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'success' => true,
                'remarks' => $formatted_remarks
            ]));
    }

    /**
     * AJAX endpoint to add a new remark
     */
    function Add_Remark()
	{
		// Allow AB access control OR Sales Agent (level 20) for their own bookings
		$is_sales_agent = $this->session->userdata('level') == 20;
		$has_ab_access = in_array('AB', $this->session->access_control);
		
		if (!$has_ab_access && !$is_sales_agent) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Access denied'
				]));
			return;
		}

		$booking_id = $this->input->post('booking_id');
		$content = trim($this->input->post('content'));

		if (empty($booking_id)) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Booking ID is required'
				]));
			return;
		}

		if (empty($content)) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Comment content is required'
				]));
			return;
		}

		// Verify booking exists
		$booking = $this->Booking_Model->find($booking_id);
		if (empty($booking)) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Booking not found'
				]));
			return;
		}

		// If sales agent without AB access, verify they own the booking
		if($is_sales_agent && !$has_ab_access) {
			if($booking->SalesAgent != $this->session->userdata('admin_id')) {
				$this->output
					->set_content_type('application/json')
					->set_output(json_encode([
						'success' => false,
						'message' => 'You can only add remarks to bookings assigned to you.'
					]));
				return;
			}
		}

		// Check if this is from Customer Remarks section (type 2) or Internal Comments (type 1)
		$remark_type = $this->input->post('remark_type') == '2' ? REMARK_TYPE::CUSTOMER : REMARK_TYPE::INTERNAL;

		$remark_data = array(
			'owner_type' => 'booking',
			'owner_id' => $booking_id,
			'commenter_id' => $this->session->userdata('admin_id'),
			'content' => $content,
			'type' => $remark_type,
		);

		$remark_id = $this->Remark_Model->Create($remark_data);

		if ($remark_id) {
			// Internal remarks fan out to two notification paths: explicit
			// @mentions parsed from the content, plus auto-notify of the
			// booking's SalesAgent + BookingOP. Both paths are idempotent on
			// (user_id, remark_id), so a user that is both @mentioned and the
			// SA receives exactly one row.
			if ($remark_type == REMARK_TYPE::INTERNAL) {
				$commenter_id = $this->session->userdata('admin_id');

				if (preg_match_all('/@([a-z0-9]+)/', $content, $m) && !empty($m[1])) {
					$tag_user_ids = $this->Notification_Model->Resolve_Handles_To_User_Ids($m[1]);
					if (!empty($tag_user_ids)) {
						$this->Notification_Model->Create_Remark_Notifications_For_Users(
							$booking_id, $remark_id, $commenter_id, $content, $tag_user_ids
						);
					}
				}

				$this->Notification_Model->Create_Remark_Notifications(
					$booking_id, $remark_id, $commenter_id, $content
				);
			}

			// Get the newly created remark with commenter name
			$remark = $this->Remark_Model->Read_Remark($remark_id);
			
			// Get remark type label from REMARK_TYPE class
			$remark_type = isset($remark->type) ? $remark->type : REMARK_TYPE::INTERNAL;
			$remark_type_label = REMARK_TYPE::getLabel($remark_type) ?: 'INTERNAL';
			
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => true,
					'message' => 'Comment added successfully',
					'remark' => array(
						'RemarkID' => $remark->RemarkID,
						'content' => $remark->content,
						'commenter_name' => $remark->CommenterName,
						'type' => $remark_type,
						'type_label' => $remark_type_label,
						'created_at' => date('d/m/Y H:i:s', strtotime($remark->created_at)),
						'created_at_raw' => $remark->created_at
					)
				]));
		} else {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Failed to add comment'
				]));
		}
	}

	/**
	 * AJAX endpoint to delete a remark
	 */
	function Delete_Remark()
	{
		if (!in_array('AB', $this->session->access_control)) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Access denied'
				]));
			return;
		}

		$remark_id = $this->input->post('remark_id');

		if (empty($remark_id)) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Remark ID is required'
				]));
			return;
		}

		// Verify remark exists
		$remark = $this->Remark_Model->Read_Remark($remark_id);
		if (empty($remark)) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Remark not found'
				]));
			return;
		}

		// Check if current user is the owner of the remark
		$current_user_id = $this->session->userdata('admin_id');
		if ($remark->commenter_id != $current_user_id) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'You can only delete your own comments'
				]));
			return;
		}

		// Delete the remark
		$deleted = $this->Remark_Model->Delete($remark_id);

		if ($deleted) {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => true,
					'message' => 'Comment deleted Successfully'
				]));
		} else {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => false,
					'message' => 'Failed to delete comment'
				]));
		}
	}

	/**
	 * Helper function to get relative time (e.g., "2 hours ago")
	 */
	private function time_ago($datetime)
	{
		$timestamp = strtotime($datetime);
		$diff = time() - $timestamp;
		
		if ($diff < 60) {
			return 'Just now';
		} elseif ($diff < 3600) {
			$mins = floor($diff / 60);
			return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
		} elseif ($diff < 86400) {
			$hours = floor($diff / 3600);
			return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
		} elseif ($diff < 604800) {
			$days = floor($diff / 86400);
			return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
		} else {
			return date('M j, Y', $timestamp);
		}
	}

	/**
	 * AJAX endpoint to get checklist data for a booking (used by listing page modal)
	 */
	public function Get_Checklist($booking_id = null)
	{
		if(empty($booking_id)) {
			echo json_encode(array('success' => false, 'message' => 'Invalid booking ID'));
			return;
		}

		// Check access
		$access_control = $this->session->access_control ?? array();
		$is_sales_agent = $this->session->userdata('level') == 20;
		$is_team_lead = (int)$this->session->userdata('level') === 25;
		$is_op_team_lead = (int)$this->session->userdata('level') === 45;
		if(!in_array('AB', $access_control) && !$is_sales_agent && !$is_team_lead && !$is_op_team_lead) {
			echo json_encode(array('success' => false, 'message' => 'Access denied'));
			return;
		}

		// Get booking info
		$booking = $this->Booking_Model->getBookingById($booking_id);
		if(!$booking) {
			echo json_encode(array('success' => false, 'message' => 'Booking not found'));
			return;
		}

		// Sales agents can only access their own bookings
		if($is_sales_agent && !in_array('AB', $access_control)) {
			if(empty($booking->SalesAgentID) || $booking->SalesAgentID != $this->session->userdata('admin_id')) {
				echo json_encode(array('success' => false, 'message' => 'Access denied'));
				return;
			}
		}

		// Strict whitelist: only an assignee — TC (SalesAgent), TC2 (SalesAgent2)
		// or OP (BookingOP) — or an active TEAM LEAD (25) / OP TEAM LEAD (45)
		// sharing a Team with one of them may mutate this booking's checklist. The
		// modal can still load read-only for everyone else with AB access.
		$this->load->helper('booking_flow');
		$lead_ids = resolve_booking_checklist_team_lead_ids($booking);
		$can_modify = can_user_modify_booking_checklist(
			$booking,
			$this->session->userdata('admin_id'),
			$this->session->userdata('level'),
			$lead_ids
		);

		// Get booking products (need ProductID and Name for checklist grouping)
		$this->db->select('bp.BookingProductID, bp.ProductID, p.Name, bp.PaymentOutSupplierFull, bp.PaymentOutSupplierDeposit, bp.disable_checklist_payment_out');
		$this->db->from('booking_product bp');
		$this->db->join('product p', 'p.ProductID = bp.ProductID', 'left');
		$this->db->where('bp.BookingID', $booking_id);
		$this->db->where('bp.Status', 'Y');
		$booking_products = $this->db->get()->result();

		if(empty($booking_products)) {
			echo json_encode(array('success' => false, 'message' => 'No products found for this booking'));
			return;
		}

		// Get checklists and completion map
		$booking_checklists = $this->get_booking_checklists($booking_products);
		$completion_map = $this->Booking_Checklist_Completion_Model->Read_Completion_Map($booking_id);

		// Format completion map for JSON (convert nested associative array)
		$formatted_completion = array();
		foreach($completion_map as $product_id => $checklists) {
			$formatted_completion[$product_id] = array();
			foreach($checklists as $checklist_id => $info) {
				$formatted_completion[$product_id][$checklist_id] = array(
					'created_by_name' => $info['created_by_name'],
					'created_at' => date('d/m/Y h:i A', strtotime($info['created_at']))
				);
			}
		}

		// Serialize checklist groups for JSON
		$groups = array();
		foreach($booking_checklists['groups'] as $group) {
			$checklists = array();
			foreach($group['checklists'] as $cl) {
				$checklists[] = array('ID' => $cl->ID, 'name' => $cl->name);
			}
			$groups[] = array(
				'product_name' => $group['product_name'],
				'product_id' => $group['product_id'],
				'checklists' => $checklists,
				'PaymentOutSupplierFull' => !empty($group['PaymentOutSupplierFull']) ? date('d/m/Y', strtotime($group['PaymentOutSupplierFull'])) : null,
				'PaymentOutSupplierDeposit' => !empty($group['PaymentOutSupplierDeposit']) ? date('d/m/Y', strtotime($group['PaymentOutSupplierDeposit'])) : null
			);
		}

		echo json_encode(array(
			'success' => true,
			'booking_number' => $booking->BookingNumber,
			'is_multi_product' => $booking_checklists['is_multi_product'],
			'groups' => $groups,
			'total_count' => $booking_checklists['total_count'],
			'completion_map' => $formatted_completion,
			'can_modify' => $can_modify
		));
	}

	/**
	 * Get all checklists for booking products
	 * If product doesn't have checklists, create default (required) ones
	 */
	private function get_booking_checklists($booking_products)
	{
		// Get all package checklists
		$package_checklists = $this->Package_Checklist_Model->Read_Package_Checklists();
		$checklist_map = array();
		foreach($package_checklists as $pc) {
			$checklist_map[$pc->ID] = $pc;
		}

		// Get required checklist IDs
		$required_ids = array();
		foreach($package_checklists as $pc) {
			if(isset($pc->is_required) && $pc->is_required == 1) {
				$required_ids[] = $pc->ID;
			}
		}

		// Get deposit checklist ID
		$deposit_checklist_id = null;
		foreach($package_checklists as $pc) {
			if(strpos($pc->name, 'Payment Out To Supplier (deposit)') !== false) {
				$deposit_checklist_id = $pc->ID;
				break;
			}
		}

		// Group booking products by ProductID (collapse duplicates)
		$product_groups = array();
		foreach($booking_products as $booking_product) {
			$product_id = $booking_product->ProductID;
			if(!isset($product_groups[$product_id])) {
				$product_groups[$product_id] = $booking_product;
			}
		}

		// Build groups with checklists
		$groups = array();
		$total_count = 0;

		foreach($product_groups as $product_id => $booking_product) {
			// Skip products with disable_checklist_payment_out enabled
			if(isset($booking_product->disable_checklist_payment_out) && $booking_product->disable_checklist_payment_out == 1) {
				continue;
			}

			// Skip child/infant products — they don't require checklists
			$product_row = $this->db->select('is_child_or_infant, has_supplier_deposit')->where('ProductID', $product_id)->get('product')->row();
			if($product_row && $product_row->is_child_or_infant == 1) {
				continue;
			}

			// Get checklists for this product
			$product_checklist_ids = $this->Product_Package_Checklist_Model->Get_Checklists_For_Product($product_id);

			// If product doesn't have checklists, use required ones and create entry
			if(empty($product_checklist_ids)) {
				$product_checklist_ids = $required_ids;
				$this->Product_Package_Checklist_Model->Bulk_Update_Product_Checklists($product_id, $required_ids);
			}

			// Auto-add deposit checklist if booking product has a PaymentOutSupplierDeposit date
			$has_deposit_date = !empty($booking_product->PaymentOutSupplierDeposit)
				&& $booking_product->PaymentOutSupplierDeposit != '0000-00-00';
			if($deposit_checklist_id && $has_deposit_date) {
				if(!in_array($deposit_checklist_id, $product_checklist_ids)) {
					$product_checklist_ids[] = $deposit_checklist_id;
				}
			}

			// Build checklist list for this group
			$group_checklists = array();
			foreach($product_checklist_ids as $checklist_id) {
				if(isset($checklist_map[$checklist_id])) {
					$group_checklists[] = $checklist_map[$checklist_id];
					$total_count++;
				}
			}

			if(!empty($group_checklists)) {
				$groups[] = array(
					'product_name' => isset($booking_product->Name) ? $booking_product->Name : 'Product #' . $product_id,
					'product_id' => $product_id,
					'checklists' => $group_checklists,
					'PaymentOutSupplierFull' => isset($booking_product->PaymentOutSupplierFull) ? $booking_product->PaymentOutSupplierFull : null,
					'PaymentOutSupplierDeposit' => isset($booking_product->PaymentOutSupplierDeposit) ? $booking_product->PaymentOutSupplierDeposit : null
				);
			}
		}

		return array(
			'is_multi_product' => count($product_groups) > 1,
			'groups' => $groups,
			'total_count' => $total_count
		);
	}

	/**
	 * Log checklist completion changes to booking_log
	 */
	private function log_checklist_changes($booking_id, $previous_completions, $new_completions, $created_by)
	{
		// Both arrays contain "productId_checklistId" strings
		// Extract unique checklist IDs for name lookup
		$all_checklist_ids = array();
		foreach(array_merge($previous_completions, $new_completions) as $key) {
			$parts = explode('_', $key, 2);
			if(count($parts) == 2) {
				$all_checklist_ids[] = intval($parts[1]);
			}
		}
		$all_checklist_ids = array_unique($all_checklist_ids);

		$checklist_name_map = array();
		if(!empty($all_checklist_ids)) {
			$this->db->select('ID, name');
			$this->db->where_in('ID', $all_checklist_ids);
			$checklists = $this->db->get('package_checklist')->result();
			foreach($checklists as $checklist) {
				$checklist_name_map[$checklist->ID] = $checklist->name;
			}
		}

		$booking_logs = array();

		// Find items that were added (ticked)
		$added = array_diff($new_completions, $previous_completions);
		foreach($added as $key) {
			$parts = explode('_', $key, 2);
			$checklist_id = isset($parts[1]) ? intval($parts[1]) : $key;
			$checklist_name = isset($checklist_name_map[$checklist_id]) ? $checklist_name_map[$checklist_id] : 'Checklist ID: ' . $checklist_id;
			$booking_logs[] = array(
				'BookingID' => $booking_id,
				'Column' => 'BookingChecklist',
				'CurrentData' => 'Unchecked',
				'NewData' => 'Checked: ' . $checklist_name,
				'InsertBy' => $created_by,
				'InsertDate' => date('Y-m-d H:i:s')
			);
		}

		// Find items that were removed (unticked)
		$removed = array_diff($previous_completions, $new_completions);
		foreach($removed as $key) {
			$parts = explode('_', $key, 2);
			$checklist_id = isset($parts[1]) ? intval($parts[1]) : $key;
			$checklist_name = isset($checklist_name_map[$checklist_id]) ? $checklist_name_map[$checklist_id] : 'Checklist ID: ' . $checklist_id;
			$booking_logs[] = array(
				'BookingID' => $booking_id,
				'Column' => 'BookingChecklist',
				'CurrentData' => 'Checked: ' . $checklist_name,
				'NewData' => 'Unchecked',
				'InsertBy' => $created_by,
				'InsertDate' => date('Y-m-d H:i:s')
			);
		}

		// Insert logs if there are any changes
		if(!empty($booking_logs)) {
			$this->db->insert_batch('booking_log', $booking_logs);
			log_message('debug', 'Booking Checklist Logs: ' . count($booking_logs) . ' entries inserted for BookingID: ' . $booking_id);
		}
	}

	/**
	 * Shared checklist-write path used by both the full Booking/Update AJAX
	 * handler (AB users editing the form) and the dedicated Save_Checklist
	 * endpoint (Team Leads without AB ticking via the list-page modal).
	 *
	 * Returns ['allowed' => bool, 'success' => bool, 'message' => string].
	 */
	private function _persist_checklist_completions($booking_id, $checklist_completions_raw, $admin_id)
	{
		$result = array('allowed' => false, 'success' => false, 'message' => '');

		if (empty($booking_id) || $checklist_completions_raw === null) {
			$result['message'] = 'Missing booking_id or checklist payload';
			return $result;
		}
		if (empty($admin_id)) {
			$result['message'] = 'No admin session';
			return $result;
		}

		// Normalise to array
		if (!is_array($checklist_completions_raw)) {
			$checklist_completions_raw = !empty($checklist_completions_raw)
				? array($checklist_completions_raw)
				: array();
		}

		// Parse "productId_checklistId" pairs
		$completion_pairs = array();
		foreach ($checklist_completions_raw as $value) {
			$parts = explode('_', $value, 2);
			if (count($parts) == 2) {
				$completion_pairs[] = array(intval($parts[0]), intval($parts[1]));
			}
		}

		if (!$this->db->table_exists('booking_checklist_completion')) {
			log_message('error', 'booking_checklist_completion table does not exist. Please run migration.');
			$result['message'] = 'Checklist storage not provisioned';
			return $result;
		}

		// Snapshot previous completions for diff logging
		$previous_map = $this->Booking_Checklist_Completion_Model->Read_Completion_Map($booking_id);
		$previous_keys = array();
		foreach ($previous_map as $pid => $checklists) {
			foreach ($checklists as $cid => $info) {
				$previous_keys[] = $pid . '_' . $cid;
			}
		}

		$this->load->helper('booking_flow');
		$booking = $this->Booking_Model->getBookingById($booking_id);

		$lead_ids = $booking
			? resolve_booking_checklist_team_lead_ids($booking)
			: array();
		$allowed = $booking && can_user_modify_booking_checklist(
			$booking,
			$admin_id,
			$this->session->userdata('level'),
			$lead_ids
		);

		if (!$allowed) {
			if ($booking) {
				log_message('info', 'Checklist save blocked: admin_id=' . $admin_id . ' is not TC1/OP/Team Lead for booking ' . $booking_id);
			}
			$result['message'] = 'Access denied';
			return $result;
		}

		$result['allowed'] = true;

		$was_all_completed = are_all_checklists_completed($booking_id, $this);
		$this->Booking_Checklist_Completion_Model->Create($booking_id, $completion_pairs, $admin_id);
		$is_now_all_completed = are_all_checklists_completed($booking_id, $this);

		if ($was_all_completed && !$is_now_all_completed) {
			$this->load->helper('booking_status_log');
			if ($booking->Status != 'PBO' && in_array($booking->Status, ['PTV', 'PT'])) {
				$this->Booking_Model->Update_Status('PBO', $booking_id);
				log_booking_status_change(
					$booking_id,
					'PBO',
					$booking->Status,
					$admin_id,
					'Status reverted to PENDING BOOKING OPERATION - Checklist unchecked',
					true
				);
			}
		} else if ($booking->Status == 'PBO' && $is_now_all_completed) {
			$status_info = determine_booking_status_from_state($booking_id, $booking, $this);
			if ($status_info['status'] != 'PBO') {
				$this->load->helper('booking_status_log');
				$this->Booking_Model->Update_Status($status_info['status'], $booking_id);
				log_booking_status_change(
					$booking_id,
					$status_info['status'],
					'PBO',
					$admin_id,
					'All Booking Checklists Completed - Status changed to ' . $status_info['status'],
					true
				);
			}
		}

		$new_keys = array();
		foreach ($completion_pairs as $pair) {
			$new_keys[] = $pair[0] . '_' . $pair[1];
		}
		$this->log_checklist_changes($booking_id, $previous_keys, $new_keys, $admin_id);

		$result['success'] = true;
		return $result;
	}

	/**
	 * AJAX endpoint to save checklist completions from the list-page modal.
	 * Intentionally decoupled from the AB-gated Booking/Update handler so that
	 * level-25 Team Leads (who typically lack AB) can still tick their team's
	 * bookings. Authorisation is delegated to can_user_modify_booking_checklist()
	 * inside _persist_checklist_completions().
	 */
	public function Save_Checklist()
	{
		header('Content-Type: application/json');

		$access_control = $this->session->access_control ?? array();
		if (!in_array('VB', $access_control)) {
			echo json_encode(array('success' => false, 'message' => 'Access denied'));
			return;
		}

		$booking_id = $this->input->post('booking_id');
		$raw_completions = $this->input->post('checklist_completions');
		$admin_id = $this->session->userdata('admin_id');

		$result = $this->_persist_checklist_completions($booking_id, $raw_completions, $admin_id);

		if (!$result['allowed']) {
			echo json_encode(array('success' => false, 'message' => $result['message'] ?: 'Access denied'));
			return;
		}
		if (!$result['success']) {
			echo json_encode(array('success' => false, 'message' => $result['message'] ?: 'Failed to save checklist'));
			return;
		}
		echo json_encode(array('success' => true));
	}

	// TEMP one-shot sweep: recompute is_submitted for bookings where the flag
	// may have gone stale (room edits used to skip recalc). Remove after running.
	// Pass ?dry_run=1 to preview affected BookingIDs without writing.
	function Recompute_All_Is_Submitted()
	{
		if(!$this->session->userdata('admin_id') || empty($this->session->access_control) || !in_array('VB', $this->session->access_control)) {
			show_error('Unauthorized', 403);
			return;
		}

		$this->load->model('Guest_List_Model');
		$dry_run = !empty($this->input->get('dry_run'));

		$this->db->select('BookingID');
		$this->db->where('is_submitted', 1);
		$this->db->where('LockStatus', 'N');
		$candidates = $this->db->get('booking')->result();

		$affected = array();
		foreach($candidates as $row) {
			if(!$this->Guest_List_Model->Are_All_Guests_Complete($row->BookingID)) {
				if(!$dry_run) {
					$this->db->where('BookingID', $row->BookingID);
					$this->db->update('booking', array('is_submitted' => 0));
				}
				$affected[] = $row->BookingID;
			}
		}

		$verb = $dry_run ? 'would correct' : 'corrected';
		log_message('info', 'Recompute_All_Is_Submitted (' . ($dry_run ? 'dry-run' : 'apply') . '): scanned ' . count($candidates) . ', ' . $verb . ' ' . count($affected) . ' -> ' . implode(',', $affected));
		echo json_encode(array(
			'success' => true,
			'dry_run' => $dry_run,
			'scanned' => count($candidates),
			'affected_count' => count($affected),
			'affected_booking_ids' => $affected
		));
	}

}
