<?php

require FCPATH.'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;  
use PhpOffice\PhpSpreadsheet\Writer\Xlxs;

class Cron extends CI_Controller
{
	public $allowGhlModuleSync = true;
	public $allowGhlModuleLog = true;
	public $allowConvertionProcessing = true;
	public $allowLeadOwnershipProcessing = true;
	public $ghlModuleLogFile = 'GHL_MODULES_SYNC.log';

	function __construct()
	{
		parent::__construct();
		$this->load->model('Booking_Model');
		$this->load->model('Booking_Product_Model');
		$this->load->model('Payment_Model');
		$this->load->model('Admin_Model');
		$this->load->model('Category_Model');
		$this->load->model('Supplier_Model');
		$this->load->model('Booking_Model');
		$this->load->model('Customer_Model');
		$this->load->library('AutoCountService'); // <-- where you put Guzzle API
	}

	function index()
	{
		$this->load->helper('booking_flow');
		$this->load->helper('booking_status_log');

		// 1. Get only bookings with statuses that can transition
		$bookings = $this->Booking_Model->Read_Actionable_Bookings();

		// 2. Run the logic
		foreach($bookings as $booking) {
			if((date('Y-m-d') >= $booking->StartDate && date('Y-m-d') <= $booking->EndDate) && ($booking->Status == 'PT')) {
				$this->Booking_Model->Update_After_Sales_Service2($booking->BookingID);
				$this->Booking_Model->Update_Status('OG', $booking->BookingID);
				$this->Booking_Model->Create_Booking_Log2($booking->Status, 'OG', $booking->BookingID);
			} else {
				if((date('Y-m-d') < $booking->StartDate && ($booking->Status == 'OG'))) {
					$this->Booking_Model->Update_After_Sales_Service2($booking->BookingID);
					$this->Booking_Model->Update_Status('PT', $booking->BookingID);
					$this->Booking_Model->Create_Booking_Log2($booking->Status, 'PT', $booking->BookingID);
				} else {
					if((date('Y-m-d') > $booking->EndDate && ($booking->Status == 'PT' || $booking->Status == 'OG'))) {
						$this->Booking_Model->Update_After_Sales_Service2($booking->BookingID);
						$this->Booking_Model->Update_Status('Y', $booking->BookingID);
						$this->Booking_Model->Create_Booking_Log2($booking->Status, 'Y', $booking->BookingID);
					}
				}
			}

			// Skip payment-based status transitions for completed bookings
			if($booking->Status == 'Y') {
				continue;
			}

			$payments = $this->Booking_Model->Read_Payments($booking->BookingID);
			$total_approved_credit = 0;
			if(!empty($payments)) {
				foreach($payments as $payment) {
					if($payment->Type != 'SUPPLIER REFUND' && $payment->Credit != 0.00 && $payment->Status == 'Y') {
						$total_approved_credit += $payment->Credit;
					}
				}
				if($total_approved_credit != 0) {
					if(strval($total_approved_credit) >= $booking->NetTotal) {
						$full_payment_existed = $this->Payment_Model->Read_Type($booking->BookingID);
						if($full_payment_existed) {
							if($booking->Status == 'P' || $booking->Status == 'PP') {
								// Full payment received — advance to PBO (PENDING BOOKING
								// OPERATION) and let the booking-flow helper decide whether
								// further auto-advance is allowed (only when no checklists
								// are configured or all are complete AND the guest list is
								// locked). Previously this jumped straight to PTV, leaving
								// bookings stuck at "PENDING TRAVEL VOUCHER" with unticked
								// checklists.
								$previous_status = $booking->Status;
								$this->Booking_Model->Update_Status('PBO', $booking->BookingID);
								$this->Booking_Model->Create_Booking_Log2($previous_status, 'PBO', $booking->BookingID);
								log_booking_status_change(
									$booking->BookingID,
									'PBO',
									$previous_status,
									0,
									'Full Payment Received - Ready for Booking Operation',
									true
								);

								$updated_booking = $this->Booking_Model->getBookingById($booking->BookingID);
								if($updated_booking) {
									check_and_advance_status_if_no_checklist_or_all_completed(
										$booking->BookingID,
										$updated_booking,
										0,
										$this
									);
								}
							}
						} else {
							if($booking->Status == 'P') {
								$this->Booking_Model->Update_Status('PP', $booking->BookingID);
								$this->Booking_Model->Create_Booking_Log2($booking->Status, 'PP', $booking->BookingID);
							}
						}
					} else {
						if($booking->Status != 'PP') {
							$this->Booking_Model->Update_Status('PP', $booking->BookingID);
							$this->Booking_Model->Create_Booking_Log2($booking->Status, 'PP', $booking->BookingID);
						}
					}
				} else {
					if($booking->Status != 'P') {
						$this->Booking_Model->Update_Status('P', $booking->BookingID);
						$this->Booking_Model->Create_Booking_Log2($booking->Status, 'P', $booking->BookingID);
					}
				}
			} else {
				if($booking->Status != 'P') {
					$this->Booking_Model->Update_Status('P', $booking->BookingID);
					$this->Booking_Model->Create_Booking_Log2($booking->Status, 'P', $booking->BookingID);
				}
			}
		}

		// Run supplier payment reminder notifications
		$this->load->model('Notification_Model');
		$this->load->model('Cronjob_Model');
		$this->load->model('Product_Package_Checklist_Model');

		$this->db->select('ID');
		$this->db->like('name', 'Payment Out To Supplier (full)');
		$full_checklist = $this->db->get('package_checklist')->row();
		$full_checklist_id = $full_checklist ? (int)$full_checklist->ID : null;

		$this->db->select('ID');
		$this->db->like('name', 'Payment Out To Supplier (deposit)');
		$deposit_checklist = $this->db->get('package_checklist')->row();
		$deposit_checklist_id = $deposit_checklist ? (int)$deposit_checklist->ID : null;

		$reminder_count = 0;

		if($full_checklist_id) {
			$booking_products = $this->Cronjob_Model->get_bookings_with_supplier_date('PaymentOutSupplierFull');
			foreach($booking_products as $bp) {
				if($this->is_supplier_checklist_incomplete($bp->BookingID, $bp->ProductID, $full_checklist_id)) {
					$reminder_count += $this->Cronjob_Model->create_supplier_reminder_notifications(
						$bp->BookingID,
						$bp->BookingNumber,
						'supplier_reminder_full',
						$bp->PaymentOutSupplierFull
					);
				}
			}
		}

		if($deposit_checklist_id) {
			$booking_products = $this->Cronjob_Model->get_bookings_with_supplier_date('PaymentOutSupplierDeposit');
			foreach($booking_products as $bp) {
				if($this->is_supplier_checklist_incomplete($bp->BookingID, $bp->ProductID, $deposit_checklist_id)) {
					$reminder_count += $this->Cronjob_Model->create_supplier_reminder_notifications(
						$bp->BookingID,
						$bp->BookingNumber,
						'supplier_reminder_deposit',
						$bp->PaymentOutSupplierDeposit
					);
				}
			}
		}

		echo "DONE! Reminder notifications: $reminder_count";
	}

	/**
	 * Check if a specific product in a booking has the given checklist assigned but not completed
	 */
	private function is_supplier_checklist_incomplete($booking_id, $product_id, $checklist_id) {
		$assigned_checklists = $this->Product_Package_Checklist_Model->Get_Checklists_For_Product($product_id);

		if(!in_array($checklist_id, $assigned_checklists)) {
			return false;
		}

		$this->db->where('booking_id', $booking_id);
		$this->db->where('product_id', $product_id);
		$this->db->where('package_checklist_id', $checklist_id);
		$completed = $this->db->get('booking_checklist_completion')->num_rows() > 0;

		return !$completed;
	}

	function old_index()
	{
		$this->load->helper('booking_flow');
		$this->load->helper('booking_status_log');
		$startTime = date("Y-m-d H:i:s.u");
		if(in_array('VB', $this->session->access_control)) {
			$titles = array('tab_title' => 'HolidayGoGoGo | Booking', 'breadcrumb_title' => 'Booking');
			$array = array('total_sales' => 0, 'total_net_profit' => 0);
			$bookings = $this->Booking_Model->Read_All_Bookings();
			$array['admins'] = $this->Booking_Model->Read_Admins();
			$array['categories'] = $this->Booking_Model->Read_Categories();
			$array['tags'] = $this->Booking_Model->Read_Tags();
			$array['sources'] = $this->Booking_Model->Read_Sources();
			$total_sales = 0;
			$total_net_profit = 0;

				foreach($bookings as $booking) {
					if((date('Y-m-d') >= $booking->StartDate && date('Y-m-d') <= $booking->EndDate) && ($booking->Status == 'PT')) {
						$this->Booking_Model->Update_After_Sales_Service2($booking->BookingID);
						$this->Booking_Model->Update_Status('OG', $booking->BookingID);
						$this->Booking_Model->Create_Booking_Log2($booking->Status, 'OG', $booking->BookingID);
					} else {
						if((date('Y-m-d') < $booking->StartDate && ($booking->Status == 'OG'))) {
							$this->Booking_Model->Update_After_Sales_Service2($booking->BookingID);
							$this->Booking_Model->Update_Status('PT', $booking->BookingID);
							$this->Booking_Model->Create_Booking_Log2($booking->Status, 'PT', $booking->BookingID);
						} else {
							if((date('Y-m-d') > $booking->EndDate && ($booking->Status == 'PT' || $booking->Status == 'OG'))) {
								$this->Booking_Model->Update_After_Sales_Service2($booking->BookingID);
								$this->Booking_Model->Update_Status('Y', $booking->BookingID);
								$this->Booking_Model->Create_Booking_Log2($booking->Status, 'Y', $booking->BookingID);
							}
						}
					}

					// Skip payment-based status transitions for completed bookings
					if($booking->Status == 'Y') {
						continue;
					}

					$payments = $this->Booking_Model->Read_Payments($booking->BookingID);
					$total_approved_credit = 0;
					if(!empty($payments)) {
						foreach($payments as $payment) {
							if($payment->Type != 'SUPPLIER REFUND' && $payment->Credit != 0.00 && $payment->Status == 'Y') {
								$total_approved_credit += $payment->Credit;
							}
						}
						if($total_approved_credit != 0) {
							if(strval($total_approved_credit) >= $booking->NetTotal) {
								$full_payment_existed = $this->Payment_Model->Read_Type($booking->BookingID);
								if($full_payment_existed) {
									if($booking->Status == 'P' || $booking->Status == 'PP') {
										// Full payment received — advance to PBO and let the
										// booking-flow helper gate any further auto-advance
										// behind the checklist + guest-list-lock checks.
										// Previously this jumped straight to PTV and left
										// bookings stuck at "PENDING TRAVEL VOUCHER" with
										// unticked checklists.
										$previous_status = $booking->Status;
										$this->Booking_Model->Update_Status('PBO', $booking->BookingID);
										$this->Booking_Model->Create_Booking_Log2($previous_status, 'PBO', $booking->BookingID);
										log_booking_status_change(
											$booking->BookingID,
											'PBO',
											$previous_status,
											0,
											'Full Payment Received - Ready for Booking Operation',
											true
										);

										$updated_booking = $this->Booking_Model->getBookingById($booking->BookingID);
										if($updated_booking) {
											check_and_advance_status_if_no_checklist_or_all_completed(
												$booking->BookingID,
												$updated_booking,
												0,
												$this
											);
										}
									}
								} else {
									if($booking->Status == 'P') {
										$this->Booking_Model->Update_Status('PP', $booking->BookingID);
										$this->Booking_Model->Create_Booking_Log2($booking->Status, 'PP', $booking->BookingID);
									}
								}
							} else {
								if($booking->Status != 'PP') {
									$this->Booking_Model->Update_Status('PP', $booking->BookingID);
									$this->Booking_Model->Create_Booking_Log2($booking->Status, 'PP', $booking->BookingID);
								}
							}
						} else {
							if($booking->Status != 'P') {
								$this->Booking_Model->Update_Status('P', $booking->BookingID);
								$this->Booking_Model->Create_Booking_Log2($booking->Status, 'P', $booking->BookingID);
							}
						}
					} else {
						if($booking->Status != 'P') {
							$this->Booking_Model->Update_Status('P', $booking->BookingID);
							$this->Booking_Model->Create_Booking_Log2($booking->Status, 'P', $booking->BookingID);
						}
					}
				}
			

			$array['bookings'] = $this->Booking_Model->Read_Bookings();

			if(isset($_GET['nick'])) {
				print_r($this->db->last_query());exit;
			}

			foreach($array['bookings'] as $booking1) {
				$booking1->CustomerMobile = $booking1->CountryCode . str_replace([' ', '-'], '', $booking1->CustomerMobile);
				if(!empty($booking1->StartDate)) {
					$booking1->StartDate = strtoupper(date('j M Y', strtotime($booking1->StartDate)));
				} else {
					$booking1->StartDate = null;
				}
				if(!empty($booking1->EndDate)) {
					$booking1->EndDate = strtoupper(date('j M Y', strtotime($booking1->EndDate)));
				} else {
					$booking1->EndDate = null;
				}
				if($booking1->BookingConfirmationTitle == 'BOOKING CONFIRMATION') {
					$booking1->BookingConfirmationTitle = 'BC';
				} else {
					if($booking1->BookingConfirmationTitle == 'QUOTATION') {
						$booking1->BookingConfirmationTitle = 'QU';
					} else {
						$booking1->BookingConfirmationTitle = 'PI';
					}
				}

				$payments = $this->Booking_Model->Read_Payments($booking1->BookingID);
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
						$profit_margin = round(($net_profit / $booking1->NetTotal) * 100);
					}
				}
				$booking1->Profit = number_format($net_profit, 2, '.', ',');
				$booking1->ProfitMargin = $net_profit != 0 ? $profit_margin : 0;
				if($booking1->LockStatus == 'N' && $booking1->Status == 'PTV') {
					$booking1->Status = 'PGL';
				}
				if($booking1->AfterSalesService == 'PENDING' && $booking1->Status == 'Y') {
					$booking1->Status = 'PR';
				}
				if(empty($booking1->DepositDeadline)) {
					if(date('Y-m-d') > $booking1->FullPaymentDeadline && ($booking1->Status == 'P' || $booking1->Status == 'PP')) {
						$booking1->Status = 'PO';
					}
				} else {
					if((date('Y-m-d') > $booking1->DepositDeadline && $booking1->Status == 'P') || (date('Y-m-d') > $booking1->FullPaymentDeadline && ($booking1->Status == 'P' || $booking1->Status == 'PP'))) {
						$booking1->Status = 'PO';
					}
				}
				$total_sales += $booking1->NetTotal;
				$total_net_profit += $net_profit;
				$booking1->NetTotal = number_format($booking1->NetTotal, 2, '.', ',');
				$booking1->InsertDate = strtoupper(date('j M Y', strtotime($booking1->InsertDate)));

			}
			
			$array['total_sales'] = number_format($total_sales, 2, '.', ',');
			$array['total_net_profit'] = $total_net_profit != 0 && $total_sales != 0 ? number_format($total_net_profit, 2, '.', ',') . ' (' . round(($total_net_profit / $total_sales) * 100) . '%)' : number_format($total_net_profit, 2, '.', ',') . ' (0%)';
			$this->load->view('layout/header', $titles);
			$this->load->view('booking/index', $array);
			$this->load->view('layout/footer');
			
		} else {
			redirect('Dashboard');
		}
	}

	/**
     * Master function: run all sync jobs
     */
    public function syncAll()
    {
		// check if request is from CLI (cron job)
		if (is_cli()) {
			$this->runSync();
			return;
		}
		$this->load->helper('autocount');
		$config = get_autocount_config();
		
		$apiKey = $this->input->get('key');
    	$expectedKey = $config['manual_sync_autocount_key']; // store in config or .env

		if ($apiKey !== $expectedKey) {
			show_error('Unauthorized access', 401);
			return;
		}
		$this->runSync();
    }

	/**
	 * Daily exchange rates.
	 *
	 * Pulls the once-a-day MYR-based rates from open.er-api.com (free, no key)
	 * and refreshes every foreign currency on /Costing/Currency (inverted to
	 * FOREIGN->MYR). Every run is traced in exchange_rate_run_log. On a failed
	 * fetch nothing is written, so the last good rates stay in effect.
	 *
	 *   CLI (cron):  php index.php Cron fetchExchangeRates
	 *   Web (manual, gated by the AutoCount manual-sync key):
	 *                /Cron/fetchExchangeRates?key=XXXX
	 */
	public function fetchExchangeRates()
	{
		// Web access is gated by the same manual-sync key as syncAll(); CLI is open.
		if (!is_cli()) {
			$this->load->helper('autocount');
			$config = get_autocount_config();
			if ($this->input->get('key') !== $config['manual_sync_autocount_key']) {
				show_error('Unauthorized access', 401);
				return;
			}
		}

		$this->load->helper('currency_rate');
		$this->load->model('Exchange_Rate_Model');

		// Open the audit trail row (status=running) up front so even a crash
		// mid-run leaves a trace of the attempt.
		$runId = $this->Exchange_Rate_Model->Start_Run_Log(array(
			'source' => is_cli() ? 'cli' : 'web',
		));

		$base  = 'MYR';
		$quote = 'USD';
		$url   = 'https://open.er-api.com/v6/latest/MYR';

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		$raw   = curl_exec($ch);
		$errNo = curl_errno($ch);
		$err   = curl_error($ch);
		curl_close($ch);

		if ($errNo) {
			$this->fetchExchangeRates_fallback("curl_error:{$err}");
			$this->Exchange_Rate_Model->Finish_Run_Log($runId, 'failed', array('error' => "curl_error:{$err}"));
			return;
		}

		$all = currency_rate_parse_erapi_all($raw);
		if (!$all['ok']) {
			$this->fetchExchangeRates_fallback($all['error']);
			$this->Exchange_Rate_Model->Finish_Run_Log($runId, 'failed', array('error' => $all['error']));
			return;
		}

		// Headline MYR->USD rate, kept in the run log for a quick daily trace.
		$usd_rate = isset($all['rates'][$quote]) ? $all['rates'][$quote] : null;

		// Stamp rates under the REAL run date, not the API's own "last update"
		// date. open.er-api refreshes at 00:00 UTC (08:00 MYT) and its date field
		// can lag a day or more from a cached edge; keying on it would freeze the
		// stored rate (see live log: a newer rate skipped because the API date
		// hadn't advanced). Run date guarantees one fresh row per day the cron
		// runs, and still protects same-day manual edits.
		$storeDate = date('Y-m-d');

		$msg = "[CRON] fetchExchangeRates {$base}->{$quote} = " . ($usd_rate !== null ? $usd_rate : 'n/a') . " (api {$all['rate_date']}, stored {$storeDate})";
		$this->customCronLogging($msg);
		if (is_cli()) {
			echo $msg . PHP_EOL;
		}

		// Auto-feed every foreign currency on /Costing/Currency from the same
		// call (inverted to FOREIGN->MYR). Skips any currency already set today.
		$this->load->model('Costing_Model');
		$res = $this->Costing_Model->Auto_Update_Rates_From_Feed($all['rates'], $storeDate);
		$costMsg = '[CRON] fetchExchangeRates costing rates updated=[' . implode(',', $res['updated']) . '] skipped=[' . implode(',', $res['skipped']) . ']';
		$this->customCronLogging($costMsg);
		if (is_cli()) {
			echo $costMsg . PHP_EOL;
		}

		// Close the audit trail row with the outcome. rate_date = the date we
		// stored under (real day); the API's own date is in the log line above.
		$this->Exchange_Rate_Model->Finish_Run_Log($runId, 'completed', array_merge(array(
			'base_code'  => $base,
			'quote_code' => $quote,
			'rate'       => $usd_rate,
			'rate_date'  => $storeDate,
		), currency_rate_run_log_summary($res)));
	}

	/**
	 * Fetch failed: nothing is written (last good rates stay in effect); just
	 * log why today was skipped, noting the last successful run for context.
	 */
	private function fetchExchangeRates_fallback($reason)
	{
		$last = $this->Exchange_Rate_Model->Read_Last_Completed_Run();
		$have = $last ? "last good {$last['rate']} ({$last['rate_date']}) still in effect" : 'NO prior successful run';
		$msg  = "[CRON] fetchExchangeRates failed ({$reason}); {$have}";
		$this->customCronLogging($msg);
		if (is_cli()) {
			echo $msg . PHP_EOL;
		}
	}

	private function runSync()
	{
		$this->syncCustomer();
		$this->syncSupplier();
		$this->syncBookings();
		$this->syncPayments();
		$this->syncDeletedPayments();

		// GHL sync intentionally NOT called here. It runs on its own dedicated
		// cron (Cron syncGhlModules) so a hang in the AutoCount steps above can
		// no longer block it. See crontab: */10 * * * * ... Cron syncGhlModules
	}

	public function syncGhlModules()
	{
		$this->customCronLogging('[CRON] syncGhlModules');
		
		// Run GHL sync every 20 minutes at :00, :20, and :40.
		if ($this->shouldRunHourly(0) || $this->shouldRunHourly(20) || $this->shouldRunHourly(40)) {
			if($this->allowGhlModuleSync) {
				$this->customCronLogging('[CRON-00/20/40] syncGhlModules');
				$this->syncGhlUsers();
				$this->syncGhlCustomFields();
				$this->syncGhlContacts();
				$this->syncGhlConversations();
				$this->syncGhlMessages();
			}
		}

		// Process leads 10 minutes after each GHL sync window: :10, :30, and :50.
		// Lead processing also updates follow_up_status, which ownership reporting reads after conversion processing.
		if ($this->shouldRunHourly(10) || $this->shouldRunHourly(30) || $this->shouldRunHourly(50)) {
			if($this->allowGhlModuleSync) {
				$this->customCronLogging('[CRON-10/30/50] allowGhlModuleSync - process_ghl_leads');
				$this->process_ghl_leads();
			}

			if($this->allowConvertionProcessing) {
				$this->customCronLogging('[CRON-10/30/50] allowConvertionProcessing - process_ghl_lead_conversions');
				$this->process_ghl_lead_conversions();
			}

			if($this->allowLeadOwnershipProcessing) {
				$this->customCronLogging('[CRON-10/30/50] allowLeadOwnershipProcessing - process_ghl_lead_ownership');
				$this->process_ghl_lead_ownership(1000, true);
			}
		}

		
		
		// just a sample code to show
		// run this daily at 00:00
		// if ($this->shouldRunDaily(0, 0)) {
		// }	
	}

	private function shouldRunHourly($minute = 0)
	{
		return (int) date('i') === (int) $minute;
	}

	private function shouldRunDaily($hour, $minute = 0)
	{
		return (int) date('G') === (int) $hour
			&& (int) date('i') === (int) $minute;
	}

	public function process_ghl_leads($chunkSize = 100)
	{
		if (!$this->input->is_cli_request()) {
			show_error('This script can only be run from the command line.', 403);
			return;
		}

		$this->load->model('Ghl_Processed_Leads_Model');

		$args = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
		$uriSegments = $this->uri->segment_array();
		$cliArgs = array_merge(
			array_slice($args, 3),
			$uriSegments ? array_slice($uriSegments, 2) : array()
		);

		$flags = array();
		$until = null;
		$chunkSizeResolved = false;
		for ($i = 0; $i < count($cliArgs); $i++) {
			$arg = $cliArgs[$i];
			if ((string) $arg === 'process_ghl_leads') {
				continue;
			}

			if (strncmp((string) $arg, '--', 2) === 0) {
				if (strpos((string) $arg, '--until=') === 0) {
					$until = substr((string) $arg, strlen('--until='));
				} elseif ((string) $arg === '--until' && isset($cliArgs[$i + 1])) {
					$until = (string) $cliArgs[++$i];
				}
				$flags[] = (string) $arg;
				continue;
			}

			if (strtolower((string) $arg) === 'until' && isset($cliArgs[$i + 1])) {
				$until = (string) $cliArgs[++$i];
				continue;
			}

			if (!is_numeric($arg)) {
				continue;
			}

			if (!$chunkSizeResolved) {
				$chunkSize = (int) $arg;
				$chunkSizeResolved = true;
			}
		}

		$chunkSize = (int) $chunkSize;
		if ($chunkSize <= 0) {
			$chunkSize = 100;
		}

		$upperBound = $this->resolve_ghl_processing_upper_bound($until);

		$shouldRebuild = in_array('--rebuild', $flags, true)
			|| in_array('--restart', $flags, true)
			|| in_array('--reset', $flags, true);
		$shouldReconcile = !$shouldRebuild && !in_array('--no-reconcile', $flags, true);
		$reconcileDays = (int) (get_env('GHL_LEAD_RECONCILE_DAYS') ?: get_env('GHL_MESSAGES_SYNC_DAYS') ?: 3);
		if ($reconcileDays <= 0) {
			$reconcileDays = 3;
		}

		$summary = array(
			'conversations_processed' => 0,
			'leads_rebuilt' => 0,
			'batches' => 0,
			'reconciliation_conversations' => 0,
		);

		echo "=== GHL Lead Processing Start ===" . PHP_EOL;
		echo "Chunk size: {$chunkSize}" . PHP_EOL;
		if ($upperBound !== null) {
			echo "Processing messages updated before: {$upperBound}" . PHP_EOL;
		}

		if (!$this->Ghl_Processed_Leads_Model->acquire_processor_lock('ghl_leads_processor', 0)) {
			echo "Another ghl lead processor run is already active." . PHP_EOL;
			return;
		}

		try {
			if ($shouldRebuild) {
				if ($upperBound === null) {
					$upperBound = $this->Ghl_Processed_Leads_Model->get_current_processing_upper_bound();
					echo "Processing messages updated before: {$upperBound}" . PHP_EOL;
				}

				echo "Rebuild mode: clearing ghl_processed_leads and ghl_processing_state before processing." . PHP_EOL;

				if (!$this->Ghl_Processed_Leads_Model->reset_processing_data()) {
					show_error('Failed resetting ghl lead processing data.', 500);
				}

				$lastConversationId = '';

				while (true) {
					$conversations = $this->Ghl_Processed_Leads_Model->get_rebuild_conversation_batch(
						$lastConversationId,
						$chunkSize,
						$upperBound
					);

					if (empty($conversations)) {
						break;
					}

					$summary['batches']++;
					echo "Processing rebuild batch {$summary['batches']} with " . count($conversations) . " conversation(s)" . PHP_EOL;

					foreach ($conversations as $conversationMeta) {
						$lastConversationId = (string) $conversationMeta['conversation_id'];
						$leadCount = $this->process_single_ghl_conversation(
							$lastConversationId,
							(int) $conversationMeta['first_new_message_row_id'],
							$upperBound
						);
						$summary['conversations_processed']++;
						$summary['leads_rebuilt'] += $leadCount;

						echo " - {$lastConversationId}: {$leadCount} lead(s)" . PHP_EOL;
					}
				}

				$completionCursor = $this->Ghl_Processed_Leads_Model->get_processing_completion_cursor($upperBound);
				$this->Ghl_Processed_Leads_Model->save_processor_state(
					'ghl_leads_processor',
					$completionCursor['last_processed_message_row_id'],
					$completionCursor['last_processed_at']
				);
			} else {
				while (true) {
					$batch = $this->Ghl_Processed_Leads_Model->get_next_conversation_batch('ghl_leads_processor', $chunkSize, $upperBound);

					if (empty($batch['conversations'])) {
						break;
					}

					$summary['batches']++;
					echo "Processing batch {$summary['batches']} with " . count($batch['conversations']) . " conversation(s)" . PHP_EOL;

					foreach ($batch['conversations'] as $conversationMeta) {
						$leadCount = $this->process_single_ghl_conversation(
							$conversationMeta['conversation_id'],
							(int) $conversationMeta['first_new_message_row_id'],
							$upperBound
						);
						$summary['conversations_processed']++;
						$summary['leads_rebuilt'] += $leadCount;

						echo " - {$conversationMeta['conversation_id']}: {$leadCount} lead(s)" . PHP_EOL;
					}

					$this->Ghl_Processed_Leads_Model->save_processor_state(
						'ghl_leads_processor',
						$batch['cursor']['last_processed_message_row_id'],
						$batch['cursor']['last_processed_at']
					);
				}

				if ($shouldReconcile) {
					$reconcileBatch = $this->Ghl_Processed_Leads_Model->get_uncovered_recent_inbound_conversation_batch(
						$reconcileDays,
						$chunkSize,
						$upperBound
					);

					if (!empty($reconcileBatch)) {
						$summary['batches']++;
						echo "Reconciling " . count($reconcileBatch) . " recent conversation(s) with uncovered inbound messages from the last {$reconcileDays} day(s)" . PHP_EOL;

						foreach ($reconcileBatch as $conversationMeta) {
							$leadCount = $this->process_single_ghl_conversation(
								$conversationMeta['conversation_id'],
								(int) $conversationMeta['first_new_message_row_id'],
								$upperBound
							);
							$summary['conversations_processed']++;
							$summary['reconciliation_conversations']++;
							$summary['leads_rebuilt'] += $leadCount;

							echo " - {$conversationMeta['conversation_id']}: {$leadCount} lead(s)" . PHP_EOL;
						}
					}
				}
			}
		} finally {
			$this->Ghl_Processed_Leads_Model->release_processor_lock('ghl_leads_processor');
		}

		echo "Processed conversations: {$summary['conversations_processed']}" . PHP_EOL;
		echo "Reconciled conversations: {$summary['reconciliation_conversations']}" . PHP_EOL;
		echo "Rebuilt leads: {$summary['leads_rebuilt']}" . PHP_EOL;
		echo "Batches: {$summary['batches']}" . PHP_EOL;
		echo "=== GHL Lead Processing End ===" . PHP_EOL;
	}

	private function resolve_ghl_processing_upper_bound($until)
	{
		$until = trim((string) $until);
		if ($until === '') {
			return null;
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) {
			$timestamp = strtotime($until . ' +1 day');
		} else {
			$timestamp = strtotime($until);
		}

		if ($timestamp === false) {
			show_error('Invalid --until value. Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.', 500);
		}

		return date('Y-m-d H:i:s', $timestamp);
	}

	public function process_ghl_lead_conversions($chunkSize = null)
	{
		if (!$this->input->is_cli_request()) {
			show_error('This script can only be run from the command line.', 403);
			return;
		}

		$this->load->model('Ghl_Processed_Leads_Model');

		$args = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
		$uriSegments = $this->uri->segment_array();
		$cliArgs = array_merge(
			array_slice($args, 3),
			$uriSegments ? array_slice($uriSegments, 2) : array()
		);

		$flags = array();
		$targetLeadId = null;
		$targetConversationId = null;
		$until = null;
		$chunkSizeResolved = false;
		for ($i = 0; $i < count($cliArgs); $i++) {
			$arg = $cliArgs[$i];
			if (strncmp((string) $arg, '--', 2) === 0) {
				if (strpos((string) $arg, '--lead-id=') === 0) {
					$targetLeadId = (int) substr((string) $arg, strlen('--lead-id='));
				} elseif (strpos((string) $arg, '--conversation-id=') === 0) {
					$targetConversationId = (string) substr((string) $arg, strlen('--conversation-id='));
				} elseif (strpos((string) $arg, '--until=') === 0) {
					$until = substr((string) $arg, strlen('--until='));
				} elseif ((string) $arg === '--until' && isset($cliArgs[$i + 1])) {
					$until = (string) $cliArgs[++$i];
				}
				$flags[] = (string) $arg;
				continue;
			}

			if (strtolower((string) $arg) === 'until' && isset($cliArgs[$i + 1])) {
				$until = (string) $cliArgs[++$i];
				continue;
			}

			if (is_numeric($arg)) {
				if (!$chunkSizeResolved) {
					$chunkSize = (int) $arg;
					$chunkSizeResolved = true;
					continue;
				}

				if ($targetLeadId === null) {
					$targetLeadId = (int) $arg;
				}

				continue;
			}

			if ($targetConversationId === null) {
				$targetConversationId = (string) $arg;
			}

			if (!$chunkSizeResolved) {
				continue;
			}
		}

		$upperBound = $this->resolve_ghl_processing_upper_bound($until);

		// When invoked as `... <method> --rebuild` (no numeric chunk), CodeIgniter
		// binds the "--rebuild" segment to the $chunkSize param. Casting that to
		// int gives 0 -> null -> "ALL" -> the whole table in one batch, which
		// exhausts PHP memory on a full rebuild. If the caller never gave a real
		// numeric chunk, fall back to the safe default so the flag alone can't
		// force an all-in-one batch.
		if (!$chunkSizeResolved && !is_numeric($chunkSize)) {
			$chunkSize = 1000;
		}

		if ($chunkSize !== null) {
			$chunkSize = (int) $chunkSize;
			if ($chunkSize <= 0) {
				$chunkSize = null;
			}
		}

		$shouldRebuild = in_array('--rebuild', $flags, true)
			|| in_array('--restart', $flags, true)
			|| in_array('--reset', $flags, true);

		if ($shouldRebuild) {
			echo "Rebuild mode: refreshing processed leads before conversion matching." . PHP_EOL;
			$this->process_ghl_leads($chunkSize !== null ? $chunkSize : 100);
		}

		$summary = array(
			'leads_scanned' => 0,
			'leads_converted' => 0,
			'batches' => 0,
		);
		$runId = $this->start_ghl_processor_run_log(
			'process_ghl_lead_conversions',
			array(
				'full_sync' => $shouldRebuild ? 1 : 0,
			)
		);

		echo "=== GHL Lead Conversion Processing Start ===" . PHP_EOL;
		echo 'Chunk size: ' . ($chunkSize === null ? 'ALL' : $chunkSize) . PHP_EOL;
		if ($upperBound !== null) {
			echo "Matching bookings inserted before: {$upperBound}" . PHP_EOL;
		}

		if (!$this->Ghl_Processed_Leads_Model->acquire_processor_lock('ghl_lead_conversion_processor', 0)) {
			$this->finish_ghl_processor_run_log(
				$runId,
				'failed',
				array(
					'total_page' => $summary['batches'],
					'total_data' => $summary['leads_scanned'],
					'updated_count' => $summary['leads_converted'],
				)
			);
			echo "Another ghl lead conversion processor run is already active." . PHP_EOL;
			return;
		}

		try {
			if ($shouldRebuild) {
				echo "Rebuild mode: resetting conversion flags before processing." . PHP_EOL;

				if (!$this->Ghl_Processed_Leads_Model->reset_conversion_data()) {
					show_error('Failed resetting ghl lead conversion data.', 500);
				}
			}

			$lastLeadId = 0;

			while (true) {
				$batch = $this->Ghl_Processed_Leads_Model->get_open_lead_conversion_batch(
					$chunkSize,
					$lastLeadId,
					$targetLeadId,
					$targetConversationId
				);
				if (empty($batch)) {
					break;
				}

				$summary['batches']++;
				echo "Processing conversion batch {$summary['batches']} with " . count($batch) . " lead(s)" . PHP_EOL;

				foreach ($batch as $lead) {
					$lastLeadId = (int) $lead['id'];
					$summary['leads_scanned']++;

					$phoneVariants = $this->build_ghl_lead_phone_variants(isset($lead['lead_phone']) ? $lead['lead_phone'] : null);
					if (empty($phoneVariants)) {
						continue;
					}

					$conversion = $this->Ghl_Processed_Leads_Model->find_first_booking_conversion(
						$phoneVariants,
						$lead['lead_started_at'],
						$upperBound
					);

					if (empty($conversion)) {
						continue;
					}

					$updated = $this->Ghl_Processed_Leads_Model->mark_lead_as_converted(
						(int) $lead['id'],
						(int) $conversion['BookingID'],
						$conversion['converted_at']
					);

					if (!$updated) {
						show_error('Failed updating ghl processed lead conversion: ' . $lead['id'], 500);
					}

					$summary['leads_converted']++;
					echo " - lead {$lead['id']} converted by booking {$conversion['BookingNumber']} at {$conversion['converted_at']}" . PHP_EOL;
				}

				if ($targetLeadId !== null || $targetConversationId !== null) {
					break;
				}
			}

			$this->finish_ghl_processor_run_log(
				$runId,
				'completed',
				array(
					'total_page' => $summary['batches'],
					'total_data' => $summary['leads_scanned'],
					'updated_count' => $summary['leads_converted'],
				)
			);
		} catch (Throwable $e) {
			$this->finish_ghl_processor_run_log(
				$runId,
				'failed',
				array(
					'total_page' => $summary['batches'],
					'total_data' => $summary['leads_scanned'],
					'updated_count' => $summary['leads_converted'],
				)
			);

			throw $e;
		} finally {
			$this->Ghl_Processed_Leads_Model->release_processor_lock('ghl_lead_conversion_processor');
		}

		echo "Leads scanned: {$summary['leads_scanned']}" . PHP_EOL;
		echo "Leads converted: {$summary['leads_converted']}" . PHP_EOL;
		echo "Batches: {$summary['batches']}" . PHP_EOL;
		echo "=== GHL Lead Conversion Processing End ===" . PHP_EOL;
	}

	public function process_ghl_lead_ownership($chunkSize = 1000, $forceRebuild = false)
	{
		if (!$this->input->is_cli_request()) {
			show_error('This script can only be run from the command line.', 403);
			return;
		}

		$this->load->model('Ghl_Lead_Ownership_Model');
		$this->load->helper('ghl_lead_ownership');

		$args = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
		$uriSegments = $this->uri->segment_array();
		$cliArgs = array_slice($args, 3);
		if (empty($cliArgs) && !empty($uriSegments)) {
			$cliArgs = array_slice($uriSegments, 2);
		}

		$flags = array();
		$targetLeadId = null;
		$targetConversationId = null;
		$chunkSizeResolved = false;

		foreach ($cliArgs as $arg) {
			if ((string) $arg === 'process_ghl_lead_ownership') {
				continue;
			}

			$plainArg = strtolower(trim((string) $arg));
			if (in_array($plainArg, array('rebuild', 'restart', 'reset'), true)) {
				$flags[] = '--' . $plainArg;
				continue;
			}

			if (strncmp((string) $arg, '--', 2) === 0) {
				if (strpos((string) $arg, '--lead-id=') === 0) {
					$targetLeadId = (int) substr((string) $arg, strlen('--lead-id='));
				} elseif (strpos((string) $arg, '--conversation-id=') === 0) {
					$targetConversationId = (string) substr((string) $arg, strlen('--conversation-id='));
				}
				$flags[] = (string) $arg;
				continue;
			}

			if (is_numeric($arg)) {
				if (!$chunkSizeResolved) {
					$chunkSize = (int) $arg;
					$chunkSizeResolved = true;
					continue;
				}

				if ($targetLeadId === null) {
					$targetLeadId = (int) $arg;
				}

				continue;
			}

			if ($targetConversationId === null) {
				$targetConversationId = (string) $arg;
			}
		}

		// When invoked as `... <method> --rebuild` (no numeric chunk), CodeIgniter
		// binds the "--rebuild" segment to the $chunkSize param. Casting that to
		// int gives 0 -> null -> "ALL" -> the whole table in one batch, which
		// exhausts PHP memory on a full rebuild. If the caller never gave a real
		// numeric chunk, fall back to the safe default so the flag alone can't
		// force an all-in-one batch.
		if (!$chunkSizeResolved && !is_numeric($chunkSize)) {
			$chunkSize = 1000;
		}

		if ($chunkSize !== null) {
			$chunkSize = (int) $chunkSize;
			if ($chunkSize <= 0) {
				$chunkSize = null;
			}
		}

		$shouldRebuild = in_array('--rebuild', $flags, true)
			|| in_array('--restart', $flags, true)
			|| in_array('--reset', $flags, true)
			|| $forceRebuild;

		// Reply-owner rule: an agent who sends AT LEAST 1 outbound reply in a
		// lead's window is a reply owner. The query is "HAVING COUNT(*) > N", so
		// N = 0 means "more than 0" = 1+ replies. (Previously 3, i.e. 4+ replies.)
		$replyThreshold = 0;
		$summary = array(
			'leads_scanned' => 0,
			'ownership_rows' => 0,
			'batches' => 0,
		);
		$runId = $this->start_ghl_processor_run_log(
			'process_ghl_lead_ownership',
			array(
				'full_sync' => $shouldRebuild ? 1 : 0,
			)
		);

		echo "=== GHL Lead Ownership Processing Start ===" . PHP_EOL;
		echo 'Chunk size: ' . ($chunkSize === null ? 'ALL' : $chunkSize) . PHP_EOL;
		echo "Reply threshold: more than {$replyThreshold} outbound replies" . PHP_EOL;

		if (!$this->Ghl_Lead_Ownership_Model->acquire_processor_lock('ghl_lead_ownership_processor', 0)) {
			$this->finish_ghl_processor_run_log(
				$runId,
				'failed',
				array(
					'module_name' => 'process_ghl_lead_ownership',
					'total_page' => $summary['batches'],
					'total_data' => $summary['leads_scanned'],
					'updated_count' => $summary['ownership_rows'],
				)
			);
			echo "Another ghl lead ownership processor run is already active." . PHP_EOL;
			return;
		}

		try {
			if ($shouldRebuild) {
				echo "Rebuild mode: clearing ghl_lead_ownership before processing." . PHP_EOL;
				if (!$this->Ghl_Lead_Ownership_Model->reset_ownership_data()) {
					show_error('Failed resetting ghl lead ownership data.', 500);
				}
			}

			$lastLeadId = 0;

			while (true) {
				$batch = $this->Ghl_Lead_Ownership_Model->get_processed_lead_batch(
					$chunkSize,
					$lastLeadId,
					$targetLeadId,
					$targetConversationId
				);

				if (empty($batch)) {
					break;
				}

				$summary['batches']++;
				echo "Processing ownership batch {$summary['batches']} with " . count($batch) . " lead(s)" . PHP_EOL;

				$leadIds = array();
				foreach ($batch as $lead) {
					$leadIds[] = (int) $lead['id'];
					$lastLeadId = (int) $lead['id'];
				}

					$replyOwnerMap = $this->Ghl_Lead_Ownership_Model->get_reply_owners_for_leads($leadIds, $replyThreshold);
					$assignmentHistoryMap = $this->Ghl_Lead_Ownership_Model->get_assignment_history_for_leads($batch);
					$calculatedAt = date('Y-m-d H:i:s');
					$ownershipRows = array();

					foreach ($batch as $lead) {
						$summary['leads_scanned']++;
						$leadReplyOwners = isset($replyOwnerMap[(int) $lead['id']]) ? $replyOwnerMap[(int) $lead['id']] : array();
						$leadAssignmentRows = isset($assignmentHistoryMap[(string) $lead['conversation_id']])
							? $assignmentHistoryMap[(string) $lead['conversation_id']]
							: array();
						$assignmentOwners = $this->Ghl_Lead_Ownership_Model->resolve_assignment_owners_for_lead($lead, $leadAssignmentRows);
						$leadOwnershipRows = ghl_build_lead_ownership_rows($lead, $leadReplyOwners, $calculatedAt, $assignmentOwners);
						$ownershipRows = array_merge($ownershipRows, $leadOwnershipRows);
					}

				$replaced = $this->Ghl_Lead_Ownership_Model->replace_ownership_for_leads($leadIds, $ownershipRows);
				if (!$replaced) {
					show_error('Failed replacing ghl lead ownership rows.', 500);
				}

				$summary['ownership_rows'] += count($ownershipRows);
				echo " - ownership rows: " . count($ownershipRows) . PHP_EOL;

				if ($targetLeadId !== null || $targetConversationId !== null) {
					break;
				}
			}

			$this->finish_ghl_processor_run_log(
				$runId,
				'completed',
				array(
					'module_name' => 'process_ghl_lead_ownership',
					'total_page' => $summary['batches'],
					'total_data' => $summary['leads_scanned'],
					'updated_count' => $summary['ownership_rows'],
				)
			);
		} catch (Throwable $e) {
			$this->finish_ghl_processor_run_log(
				$runId,
				'failed',
				array(
					'module_name' => 'process_ghl_lead_ownership',
					'total_page' => $summary['batches'],
					'total_data' => $summary['leads_scanned'],
					'updated_count' => $summary['ownership_rows'],
				)
			);

			throw $e;
		} finally {
			$this->Ghl_Lead_Ownership_Model->release_processor_lock('ghl_lead_ownership_processor');
		}

		echo "Leads scanned: {$summary['leads_scanned']}" . PHP_EOL;
		echo "Ownership rows: {$summary['ownership_rows']}" . PHP_EOL;
		echo "Batches: {$summary['batches']}" . PHP_EOL;
		echo "=== GHL Lead Ownership Processing End ===" . PHP_EOL;
	}

	public function rebuild_ghl_conversations()
	{
		if (!$this->input->is_cli_request()) {
			show_error('This script can only be run from the command line.', 403);
			return;
		}

		$this->load->model('Ghl_Processed_Leads_Model');

		$args = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
		$uriSegments = $this->uri->segment_array();
		$conversationIds = array();

		$cliArgs = array_merge(
			array_slice($args, 3),
			$uriSegments ? array_slice($uriSegments, 2) : array()
		);

		foreach ($cliArgs as $arg) {
			$conversationId = trim((string) $arg);
			if ($conversationId === ''
				|| strncmp($conversationId, '--', 2) === 0
				|| $conversationId === 'rebuild_ghl_conversations') {
				continue;
			}

			$conversationIds[] = $conversationId;
		}

		$conversationIds = array_values(array_unique($conversationIds));

		if (empty($conversationIds)) {
			show_error('At least one conversation id is required.', 400);
			return;
		}

		echo "=== GHL Conversation Rebuild Start ===" . PHP_EOL;

		foreach ($conversationIds as $conversationId) {
			$leadCount = $this->process_single_ghl_conversation($conversationId, 0);
			echo " - {$conversationId}: {$leadCount} lead(s)" . PHP_EOL;
		}

		echo "=== GHL Conversation Rebuild End ===" . PHP_EOL;
	}

	protected function start_ghl_processor_run_log($moduleName, $data = array())
	{
		$this->load->model('Ghl_Sync_Model');

		$moduleName = trim((string) $moduleName);
		if ($moduleName === '') {
			return '';
		}

		$runId = $this->Ghl_Sync_Model->generate_run_id($moduleName);
		$payload = array_merge(
			array(
				'RunID' => $runId,
				'module_name' => $moduleName,
				'status' => 'running',
			),
			$data
		);

		$this->Ghl_Sync_Model->create_log($payload);

		return $runId;
	}

	protected function finish_ghl_processor_run_log($runId, $status, $data = array())
	{
		$runId = trim((string) $runId);
		if ($runId === '') {
			return;
		}

		$this->load->model('Ghl_Sync_Model');
		$payload = array_merge(
			array(
				'RunID' => $runId,
				'module_name' => 'process_ghl_lead_conversions',
				'status' => $status,
				'completed_at' => date('Y-m-d H:i:s'),
			),
			$data
		);

		$this->Ghl_Sync_Model->create_log($payload);
	}

	private function process_single_ghl_conversation($conversationId, $firstNewMessageRowId = 0, $messageUpdatedBefore = null)
	{
		$this->load->helper('duty_hours');
		$this->load->helper('ghl_lead_segmentation');
		$this->load->helper('ghl_bot_autoreply');

		$messages = $this->Ghl_Processed_Leads_Model->get_conversation_messages($conversationId, $messageUpdatedBefore);
		$existingConversions = $this->Ghl_Processed_Leads_Model->get_existing_conversion_map($conversationId);
		$currentAssignedTo = $this->Ghl_Processed_Leads_Model->get_conversation_assigned_to($conversationId);

		if (empty($messages)) {
			$replaced = $this->Ghl_Processed_Leads_Model->replace_conversation_leads($conversationId, array());
			if (!$replaced) {
				show_error('Failed clearing processed leads for conversation: ' . $conversationId, 500);
			}
			return 0;
		}

		$leads = array();
		$currentLead = null;
		$fallbackContactId = null;
		$lastMessageTimestamp = null;
		$lastMessageDirection = null;
		$now = date('Y-m-d H:i:s');

		foreach ($messages as $message) {
			$messageTimestamp = strtotime($message['message_timestamp']);

			if ($messageTimestamp === false) {
				continue;
			}

			if ($fallbackContactId === null && !empty($message['contact_id'])) {
				$fallbackContactId = $message['contact_id'];
			}

			if ($message['direction'] === 'inbound') {
				$startsNewLead = ($currentLead === null)
					|| ghl_should_start_new_processed_lead(
						$currentLead,
						$existingConversions,
						$lastMessageTimestamp,
						$messageTimestamp
					);

				if ($startsNewLead) {
					if ($currentLead !== null) {
						$currentLead['lead_ended_at'] = $message['message_timestamp'];
						$this->finalize_ghl_processed_lead($currentLead);
						$leads[] = $currentLead;
					}

					$currentLead = array(
						'conversation_id' => $conversationId,
						'contact_id' => !empty($message['contact_id']) ? $message['contact_id'] : $fallbackContactId,
						// Rebuild from the canonical message stream only. Persisted processed leads are
						// derived data and must not become future split boundaries.
						'assigned_to_user_id' => $currentAssignedTo,
						'lead_started_at' => $message['message_timestamp'],
						'lead_ended_at' => null,
						'first_customer_message_id' => $message['message_id'],
						'tracked_message_count' => 0,
						'responded_message_count' => 0,
						'avg_first_5_response_seconds' => null,
						'recent_tracked_message_count' => 0,
						'recent_responded_message_count' => 0,
						'avg_recent_5_response_seconds' => null,
						'follow_up_status' => 'pending',
						'follow_up_sent_at' => null,
						'follow_up_replied_at' => null,
						'follow_up_expired_at' => null,
						'is_converted' => 0,
						'booking_id' => null,
						'converted_at' => null,
						// Instant inbound right after our outbound blast = bot auto-reply,
						// not a real new lead. Flag it so reports can exclude it.
						'is_bot_bounce' => (int) ghl_is_bot_autoreply_bounce(
							$lastMessageDirection,
							$lastMessageTimestamp,
							$messageTimestamp
						),
						'created_at' => $now,
						'updated_at' => $now,
						'_response_history' => array(),
						'_pending_response_indexes' => array(),
						'_last_agent_message_at' => null,
					);
					$this->initialize_ghl_processed_lead_response_slots($currentLead);
				}

				if ($currentLead !== null) {
					$followUpSentTimestamp = !empty($currentLead['follow_up_sent_at'])
						? strtotime((string) $currentLead['follow_up_sent_at'])
						: false;

					if ($currentLead['follow_up_status'] === 'sent'
						&& $followUpSentTimestamp !== false
						&& $messageTimestamp >= $followUpSentTimestamp) {
						$currentLead['follow_up_status'] = 'completed';
						$currentLead['follow_up_replied_at'] = $message['message_timestamp'];
						$currentLead['follow_up_expired_at'] = null;
					}

					$currentLead['_last_agent_message_at'] = null;

					if (empty($currentLead['_pending_response_indexes'])) {
						$currentLead['_response_history'][] = array(
							'customer_message_id' => $message['message_id'],
							'customer_message_at' => $message['message_timestamp'],
							'agent_message_id' => null,
							'agent_message_at' => null,
							'seconds' => null,
						);
						$currentLead['_pending_response_indexes'][] = count($currentLead['_response_history']) - 1;
					}
				}
			} elseif ($message['direction'] === 'outbound' && $currentLead !== null) {
				$lastAgentTimestamp = !empty($currentLead['_last_agent_message_at'])
					? strtotime((string) $currentLead['_last_agent_message_at'])
					: false;

				if ($lastAgentTimestamp !== false
					&& $messageTimestamp >= $lastAgentTimestamp
					&& ($messageTimestamp - $lastAgentTimestamp) >= 86400
					&& $currentLead['follow_up_status'] !== 'completed') {
					$currentLead['follow_up_status'] = 'sent';
					$currentLead['follow_up_sent_at'] = $message['message_timestamp'];
					$currentLead['follow_up_replied_at'] = null;
					$currentLead['follow_up_expired_at'] = null;
				}

				$currentLead['_last_agent_message_at'] = $message['message_timestamp'];

				if (!empty($currentLead['_pending_response_indexes'])) {
					$historyIndex = (int) $currentLead['_pending_response_indexes'][0];
					$customerMessage = isset($currentLead['_response_history'][$historyIndex])
						? $currentLead['_response_history'][$historyIndex]
						: null;
					$customerTimestamp = !empty($customerMessage['customer_message_at'])
						? strtotime((string) $customerMessage['customer_message_at'])
						: false;

					if ($customerTimestamp !== false && $messageTimestamp >= $customerTimestamp) {
						array_shift($currentLead['_pending_response_indexes']);
						if (isset($currentLead['_response_history'][$historyIndex])) {
							$currentLead['_response_history'][$historyIndex]['agent_message_id'] = $message['message_id'];
							$currentLead['_response_history'][$historyIndex]['agent_message_at'] = $message['message_timestamp'];
							$currentLead['_response_history'][$historyIndex]['seconds'] = calculate_duty_response_seconds(
								$customerMessage['customer_message_at'],
								$message['message_timestamp']
							);
						}
					}
				}
			}

			$lastMessageTimestamp = $messageTimestamp;
			$lastMessageDirection = $message['direction'];
		}

		if ($currentLead !== null) {
			$this->finalize_ghl_processed_lead($currentLead);
			$leads[] = $currentLead;
		}

		foreach ($leads as &$lead) {
			$conversionKey = $lead['lead_started_at'] . '|' . $lead['first_customer_message_id'];

			if (isset($existingConversions[$conversionKey])) {
				if (empty($lead['assigned_to_user_id']) && !empty($existingConversions[$conversionKey]['assigned_to_user_id'])) {
					$lead['assigned_to_user_id'] = $existingConversions[$conversionKey]['assigned_to_user_id'];
				}
				$lead['is_converted'] = (int) $existingConversions[$conversionKey]['is_converted'];
				$lead['booking_id'] = $existingConversions[$conversionKey]['booking_id'];
				$lead['converted_at'] = $existingConversions[$conversionKey]['converted_at'];
			}

			unset($lead['_response_history'], $lead['_pending_response_indexes'], $lead['_last_agent_message_at']);
		}
		unset($lead);

		$leads = $this->filter_ghl_processed_leads_for_current_assignment($leads, $currentAssignedTo);

		$replaced = $this->Ghl_Processed_Leads_Model->replace_conversation_leads($conversationId, $leads);
		if (!$replaced) {
			show_error('Failed rebuilding processed leads for conversation: ' . $conversationId, 500);
		}

		return count($leads);
	}

	private function finalize_ghl_processed_lead(&$lead)
	{
		$this->initialize_ghl_processed_lead_response_slots($lead);
		$this->initialize_ghl_processed_lead_recent_response_slots($lead);

		$responseHistory = !empty($lead['_response_history']) && is_array($lead['_response_history'])
			? $lead['_response_history']
			: array();

		$firstResponses = !empty($responseHistory)
			? array_slice($responseHistory, 0, 5)
			: array();
		$recentResponses = $this->build_recent_replied_response_history($responseHistory);

		$firstResponseStats = $this->assign_ghl_processed_lead_response_slots($lead, $firstResponses, 'response_');
		$recentResponseStats = $this->assign_ghl_processed_lead_response_slots($lead, $recentResponses, 'recent_response_');

		$lead['tracked_message_count'] = $firstResponseStats['tracked_count'];
		$lead['responded_message_count'] = $firstResponseStats['responded_count'];
		$lead['avg_first_5_response_seconds'] = $firstResponseStats['avg_seconds'];
		$lead['recent_tracked_message_count'] = $recentResponseStats['tracked_count'];
		$lead['recent_responded_message_count'] = $recentResponseStats['responded_count'];
		$lead['avg_recent_5_response_seconds'] = $recentResponseStats['avg_seconds'];
		$lead['updated_at'] = date('Y-m-d H:i:s');
	}

	private function build_recent_replied_response_history($responseHistory)
	{
		$respondedResponses = array();

		foreach ((array) $responseHistory as $response) {
			if (!isset($response['seconds']) || $response['seconds'] === null) {
				continue;
			}

			$respondedResponses[] = $response;
		}

		if (empty($respondedResponses)) {
			return array();
		}

		$recentResponses = array_slice($respondedResponses, -5);
		return array_reverse(array_values($recentResponses));
	}

	private function initialize_ghl_processed_lead_response_slots(&$lead)
	{
		for ($slotNumber = 1; $slotNumber <= 5; $slotNumber++) {
			$lead['response_' . $slotNumber . '_customer_message_id'] = null;
			$lead['response_' . $slotNumber . '_customer_message_at'] = null;
			$lead['response_' . $slotNumber . '_agent_message_id'] = null;
			$lead['response_' . $slotNumber . '_agent_message_at'] = null;
			$lead['response_' . $slotNumber . '_seconds'] = null;
		}
	}

	private function initialize_ghl_processed_lead_recent_response_slots(&$lead)
	{
		for ($slotNumber = 1; $slotNumber <= 5; $slotNumber++) {
			$lead['recent_response_' . $slotNumber . '_customer_message_id'] = null;
			$lead['recent_response_' . $slotNumber . '_customer_message_at'] = null;
			$lead['recent_response_' . $slotNumber . '_agent_message_id'] = null;
			$lead['recent_response_' . $slotNumber . '_agent_message_at'] = null;
			$lead['recent_response_' . $slotNumber . '_seconds'] = null;
		}
	}

	private function assign_ghl_processed_lead_response_slots(&$lead, $responses, $prefix)
	{
		$responseTotal = 0;
		$responseCount = 0;
		$trackedCount = is_array($responses) ? count($responses) : 0;

		foreach ((array) $responses as $index => $response) {
			$slotNumber = $index + 1;
			$lead[$prefix . $slotNumber . '_customer_message_id'] = isset($response['customer_message_id']) ? $response['customer_message_id'] : null;
			$lead[$prefix . $slotNumber . '_customer_message_at'] = isset($response['customer_message_at']) ? $response['customer_message_at'] : null;
			$lead[$prefix . $slotNumber . '_agent_message_id'] = isset($response['agent_message_id']) ? $response['agent_message_id'] : null;
			$lead[$prefix . $slotNumber . '_agent_message_at'] = isset($response['agent_message_at']) ? $response['agent_message_at'] : null;
			$lead[$prefix . $slotNumber . '_seconds'] = isset($response['seconds']) ? $response['seconds'] : null;

			if (isset($response['seconds']) && $response['seconds'] !== null) {
				$responseTotal += (int) $response['seconds'];
				$responseCount++;
			}
		}

		return array(
			'tracked_count' => $trackedCount,
			'responded_count' => $responseCount,
			'avg_seconds' => $responseCount > 0
				? (int) round($responseTotal / $responseCount)
				: null,
		);
	}

	private function filter_ghl_processed_leads_for_current_assignment($leads, $currentAssignedTo)
	{
		if (empty($leads)) {
			return $leads;
		}

		$currentAssignedTo = !empty($currentAssignedTo) ? (string) $currentAssignedTo : null;

		// Once a conversation has a newer assignee, drop legacy assignee-owned leads so
		// the dashboard only reflects the current owner from the point their lead begins.
		if ($currentAssignedTo !== null) {
			$filteredLeads = array();

			foreach ($leads as $lead) {
				$leadAssignedTo = !empty($lead['assigned_to_user_id']) ? (string) $lead['assigned_to_user_id'] : null;
				if ($leadAssignedTo === $currentAssignedTo) {
					$filteredLeads[] = $lead;
				}
			}

			if (!empty($filteredLeads)) {
				return array_values($filteredLeads);
			}
		}

		return array_values($leads);
	}

	private function build_ghl_lead_phone_variants($phone)
	{
		$phone = trim((string) $phone);
		if ($phone === '') {
			return array();
		}

		$digits = preg_replace('/\D+/', '', $phone);
		if ($digits === '') {
			return array();
		}

		$localDigits = $digits;
		if (strpos($localDigits, '60') === 0) {
			$localDigits = substr($localDigits, 2);
		}

		if (strpos($localDigits, '0') === 0) {
			$localDigits = substr($localDigits, 1);
		}

		$variants = array($phone, $digits, $localDigits);

		if ($localDigits !== '') {
			$variants[] = '0' . $localDigits;
			$variants[] = '60' . $localDigits;
			$variants[] = '+60' . $localDigits;
		}

		return array_values(array_filter(array_unique($variants)));
	}

	/**
     * Sync Customer
     */
	private function syncCustomer()
	{
		echo "=== Sync Customer Start ===\n";

		$customers = $this->Customer_Model->get_pending_sycn_customers();

		$this->load->library('CustomerSync');
		$this->load->helper('autocount');
		$this->load->helper('customer_code');
		$config = get_autocount_config();

		foreach ($customers as $customer) {
			echo "Customer {$customer['CustomerID']} [{$customer['AutocountSyncAction']}]... ";

			$customer = $this->enrichCustomer($customer);
			$updateData = [];

			try {
				switch ($customer['AutocountSyncAction']) {
					case 'C':
						$result = $this->customersync->autocount_create($customer, $config);

						// Self-heal an orphaned-code collision: AutoCount already
						// owns this AccNo (it exists in their Chart of Account but
						// not in our DB). Bump to the next free code and retry.
						$bump = 0;
						while ($bump < 5
							&& isset($result['error'])
							&& is_customer_code_clash($result['error'])) {
							$bump++;
							$newCode = $this->Customer_Model->bump_customer_code(
								$customer['CustomerID'],
								$customer['name']
							);
							if (empty($newCode) || $newCode === $customer['CustomerCode']) {
								break; // series exhausted — leave it Failed
							}
							$customer['CustomerCode'] = $newCode;
							echo "BUMP->{$newCode} ";
							$result = $this->customersync->autocount_create($customer, $config);
						}
						break;
					case 'U':
						$result = $this->customersync->autocount_update($customer, $config);
						break;
					case 'S':
						$result = $this->customersync->autocount_update_status($customer, $config);
						break;
					case 'D':
						$result = $this->customersync->autocount_delete($customer, $config);
						break;
					default:
						$result = ['error' => 'ERROR Autocount Sync Action'];
				}
				if (isset($result['status']) && ($result['status'] == 201 || $result['status'] == 204) && $result['error'] === null) {
					$this->Customer_Model->update_by_id($customer['CustomerID'], [
						'AutocountSyncStatus'  => 'S',
						'AutocountSyncMessage' => json_encode($result)
					]);

					if (isset($result['docNo']) && !empty($result['docNo'])) {
						// Don't overwrite with a code another local customer already
						// holds — that re-creates the very duplicate we're avoiding.
						$clash = $this->db
							->where('CustomerCode', $result['docNo'])
							->where('CustomerID !=', $customer['CustomerID'])
							->count_all_results('customer');
						if ($clash == 0) {
							$updateData['CustomerCode'] = $result['docNo'];
						} else {
							log_message('error', "syncCustomer: AutoCount docNo {$result['docNo']} already used by another local customer; not overwriting CustomerID {$customer['CustomerID']}.");
						}
					}
					if (!empty($updateData)) {
						$this->Customer_Model->update_by_id($customer['CustomerID'], $updateData);
					}
					echo "SUCCESS\n";
				} else {
					$this->Customer_Model->update_by_id($customer['CustomerID'], [
						'AutocountSyncStatus'  => 'F',
						'AutocountSyncMessage' => json_encode($result)
					]);
					echo "FAILED\n";
				}
			} catch (\Exception $e) {
				$this->Customer_Model->update_by_id($customer['CustomerID'], [
					'AutocountSyncStatus'  => 'F',
					'AutocountSyncMessage' => $e->getMessage()
				]);
				echo "ERROR: {$e->getMessage()}\n";
			}
		}
		echo "=== Sync Customer End ===\n\n";
	}

	private function enrichCustomer($customer)
	{
		return $customer;
	}

	/**
     * Sync Supplier
     */
	private function syncSupplier() // creditor
	{
		echo "=== Sync Supplier Start ===\n";

		$suppliers = $this->Supplier_Model->get_pending_sycn_suppliers();

		$this->load->library('SupplierSync');
		$this->load->helper('autocount');
		$config = get_autocount_config();
		
		foreach ($suppliers as $supplier) {
			echo "Supplier ID {$supplier['SupplierID']} [{$supplier['AutocountSyncAction']}]... ";

			$supplier = $this->enrichSupplier($supplier);

			try {
				switch ($supplier['AutocountSyncAction']) {
					case 'C':
						$result = $this->suppliersync->autocount_create($supplier,$config);
						break;
					case 'U':
						$result = $this->suppliersync->autocount_update($supplier, $config);
						break;
					case 'S':
						$result = $this->suppliersync->autocount_update_status($supplier, $config);
						break;
					case 'D':
						$result = $this->suppliersync->autocount_delete($supplier, $config);
						break;
					default:
						$result = ['error' => 'ERROR Autocount Sync Action'];
				}
				if (isset($result['status']) && ($result['status'] == 201 || $result['status'] == 204) && $result['error'] === null) {
					$this->Supplier_Model->update_by_id($supplier['SupplierID'], [
						'AutocountSyncStatus'  => 'S',
						'AutocountSyncMessage' => json_encode($result)
					]);

					if (isset($result['docNo']) && !empty($result['docNo'])) {
						$updateData['SupplierCode'] = $result['docNo'];
					}
					if (!empty($updateData)){
						$this->Supplier_Model->update_by_id($supplier['SupplierID'], $updateData);
					}
					echo "SUCCESS\n";
				} else {
					$this->Supplier_Model->update_by_id($supplier['SupplierID'], [
						'AutocountSyncStatus'  => 'F',
						'AutocountSyncMessage' => json_encode($result)
					]);
					echo "FAILED\n";
				}
			} catch (\Exception $e) {
				$this->Supplier_Model->update_by_id($supplier['SupplierID'], [
					'AutocountSyncStatus'  => 'F',
					'AutocountSyncMessage' => $e->getMessage()
				]);
				echo "ERROR: {$e->getMessage()}\n";
			}
		}
		echo "=== Sync Suppliers End ===\n\n";
	}

	private function enrichSupplier($supplier)
	{
		return $supplier;
	}

    /**
     * Sync Bookings
     */
	private function syncBookings()
	{
		echo "=== Sync Booking Start ===\n";

		$bookings = $this->Booking_Model->getPendingBookingsWithDetails();

		$this->load->library('BookingSync');
		$this->load->helper('autocount');
		$config = get_autocount_config();
		
		foreach ($bookings as $booking) {
			echo "Booking ID {$booking['BookingID']} [{$booking['AutocountSyncAction']}]... ";

			$booking = $this->enrichBooking($booking);

			try {
				switch ($booking['AutocountSyncAction']) {
					case 'C':
						$result = $this->bookingsync->autocount_create($booking);
						break;
					case 'U':
						$result = $this->bookingsync->autocount_update($booking);
						break;
					case 'S':
						$result = $this->bookingsync->autocount_update_status($booking);
						break;
					case 'D':
						$result = $this->bookingsync->autocount_delete($booking);
						break;
					default:
						$result = ['error' => 'ERROR Autocount Sync Action'];
				}
				if (isset($result['status']) && ($result['status'] == 201 || $result['status'] == 204) && $result['error'] === null) {
					$this->Booking_Model->update_by_id($booking['BookingID'], [
						'AutocountSyncStatus'  => 'S',
						'AutocountSyncMessage' => json_encode($result)
					]);
					echo "SUCCESS\n";
				} else {
					$this->Booking_Model->update_by_id($booking['BookingID'], [
						'AutocountSyncStatus'  => 'F',
						'AutocountSyncMessage' => json_encode($result)
					]);
					echo "FAILED\n";
				}
			} catch (\Exception $e) {
				$this->Booking_Model->update_by_id($booking['BookingID'], [
					'AutocountSyncStatus'  => 'F',
					'AutocountSyncMessage' => $e->getMessage()
				]);
				echo "ERROR: {$e->getMessage()}\n";
			}
		}
		echo "=== Sync Bookings End ===\n\n";
	}


	private function enrichBooking($booking)
	{
		// Sales agent
		if (!empty($booking['SalesAgent'])) {
			$sale_agent = $this->Admin_Model->find($booking['SalesAgent']);
			if ($sale_agent) {
				$booking['salesAgent'] = $sale_agent->Name;
			}
		}

		//validity
		if (!empty($booking['StartDate']) && !empty($booking['EndDate'])) {
			$booking['validity'] = $booking['StartDate'] . ' - ' . $booking['EndDate'];
			$booking['BokingRemark'] = $booking['StartDate'] . ' - ' . $booking['EndDate'];
		}

		// yourRef
		if (!empty($booking['ReservationNumber'])) {
			$booking['yourRef'] = $booking['ReservationNumber'];
			$booking['remark2'] = $booking['ReservationNumber'];
		}

		// cc 
		// Check for Adult, Children, and Infant; if empty, set to 0
		$booking['cc'] = '';

		$booking['cc'] .= (!empty($booking['Adult']) ? $booking['Adult'] : 0) . ' A, ';
		$booking['cc'] .= (!empty($booking['Children']) ? $booking['Children'] : 0) . ' C, ';
		$booking['cc'] .= (!empty($booking['Infant']) ? $booking['Infant'] : 0) . ' IN';

		// Remove trailing comma and space
		$booking['cc'] = rtrim($booking['cc'], ', ');

		if (!empty($booking['cc'])) {
			$booking['remark3'] = $booking['cc'];
		}

		// deliveryTerm  
		if (!empty($booking['Destination'])) {
			$Destination = $this->Category_Model->find($booking['Destination']);
			if ($Destination) {
				$booking['Destination'] = $Destination->Name;
				$booking['deliveryTerm'] = $booking['Destination'];
				$booking['remark4'] = $booking['Destination'];
			}
		}

		if (!empty($booking['CustomerID'])) {
			$customer = $this->Customer_Model->find($booking['CustomerID']);
			if (!empty($customer)) {
				if (!empty($customer->CustomerCode) && $customer->CustomerCode != null) {
					$booking['CustomerCode'] = $customer->CustomerCode;
				}
			}
		}

		return $booking;
	}

    /**
     * Sync Payments
     */
  	private function syncPayments()
	{
		echo "=== Sync Payments Start ===\n";
		$payments = $this->Payment_Model->getAllPaymentsWithBookingAndSupplier(null, false);

		$this->load->library('PaymentSync');
		$this->load->helper('autocount');
		$config = get_autocount_config();
		
		foreach ($payments as $payment) {
			echo "Payment ID {$payment['PaymentID']} [{$payment['AutocountSyncAction']}]... ";

			$payment = $this->enrichPayment($payment, $config);

			try {
				switch ($payment['AutocountSyncAction']) {
					case 'C':
						$result = $this->paymentsync->autocount_create($payment, $config);
						break;
					case 'U':
						$result = $this->paymentsync->autocount_update($payment, $config);
						break;
					case 'D':
						$result = $this->paymentsync->autocount_delete($payment, $config);
						break;
					default:
						$result = ['error' => 'ERROR Autocount Sync Action'];
				}

				// Nothing to post (zero-amount payment): mark resolved so it
				// leaves the queue instead of failing on every run.
				if (!empty($result['skipped'])) {
					$this->Payment_Model->update_by_id($payment['PaymentID'], [
						'AutocountSyncStatus'  => 'S',
						'AutocountSyncMessage' => json_encode($result)
					]);
					echo "SKIPPED: " . ($result['message'] ?? 'zero amount') . "\n";
					continue;
				}

				if (isset($result['status']) && ($result['status'] == 201 || $result['status'] == 204) && $result['error'] === null) {
					$updateData = [
						'AutocountSyncStatus'  => 'S',
						'AutocountSyncMessage' => json_encode($result)
					];

					if (isset($result['docNo']) && !empty($result['docNo'])) {
						$updateData['AutocountReferenceNumber'] = $result['docNo'];
					}
					if (!empty($updateData)){
						$this->Payment_Model->update_by_id($payment['PaymentID'], $updateData);
					}
					echo "SUCCESS\n";
				} else {
					$this->Payment_Model->update_by_id($payment['PaymentID'], [
						'AutocountSyncStatus'  => 'F',
						'AutocountSyncMessage' => json_encode($result)
					]);
					echo "FAILED: " . ($result['error'] ?? 'Unknown error') . "\n";
				}

			} catch (\Exception $e) {
				$this->Payment_Model->update_by_id($payment['PaymentID'], [
					'AutocountSyncStatus'  => 'F',
					'AutocountSyncMessage' => $e->getMessage()
				]);
				echo "ERROR: {$e->getMessage()}\n";
			}
		}

		echo "=== Sync Payments End ===\n\n";
	}

	private function syncDeletedPayments()
	{
		echo "=== Sync Deleted Payments Start ===\n";
		$payments = $this->Payment_Model->getAllPaymentsWithBookingAndSupplier(null, true);

		$this->load->library('PaymentSync');
		$this->load->helper('autocount');
		$config = get_autocount_config();
		
		foreach ($payments as $payment) {
			echo "Payment ID {$payment['PaymentID']} [{$payment['AutocountSyncAction']}]... ";

			$payment = $this->enrichPayment($payment, $config);

			try {
				switch ($payment['AutocountSyncAction']) {
					case 'D':
						$result = $this->paymentsync->autocount_delete($payment, $config);
						break;
					default:
						$result = ['error' => 'ERROR Autocount Sync Action'];
				}

				if (isset($result['status']) && ($result['status'] == 201 || $result['status'] == 204) && $result['error'] === null) {
					$this->Payment_Model->update_by_id($payment['PaymentID'], [
						'AutocountSyncStatus'  => 'S',
						'AutocountSyncMessage' => json_encode($result)
					]);
					echo "SUCCESS\n";
				} else {
					$this->Payment_Model->update_by_id($payment['PaymentID'], [
						'AutocountSyncStatus'  => 'F',
						'AutocountSyncMessage' => json_encode($result)
					]);
					echo "FAILED: " . ($result['error'] ?? 'Unknown error') . "\n";
				}

			} catch (\Exception $e) {
				$this->Payment_Model->update_by_id($payment['PaymentID'], [
					'AutocountSyncStatus'  => 'F',
					'AutocountSyncMessage' => $e->getMessage()
				]);
				echo "ERROR: {$e->getMessage()}\n";
			}
		}

		echo "=== Sync Deleted Payments End ===\n\n";
	}

	private function enrichPayment($payment, $config)
	{
		// Sales agent
		if (!empty($payment['SalesAgent'])) {
			$sale_agent = $this->Admin_Model->find($payment['SalesAgent']);
			if ($sale_agent) {
				$payment['salesAgent'] = $sale_agent->Name;
			}
		}

		// description
		$desc = !empty($payment['Customer']) ? $payment['Customer'] : '';
		// if ($payment['Credit'] != 0.00) { // OR
		// 	$desc = !empty($payment['Customer']) ? $payment['Customer'] : '';
		// } else { // PV
		// 	$desc = !empty($payment['supplier_name']) ? $payment['supplier_name'] : (!empty($payment['Customer']) ? $payment['Customer'] : '');
		// }

		if (!empty($payment['StartDate']) && !empty($payment['EndDate'])) {
			$travelDate = $payment['StartDate'] . ' - ' . $payment['EndDate'];
			$desc = !empty($desc) ? $desc . ' (' . $travelDate . ')' : $travelDate;
			$payment['travelDate'] = $travelDate;
		}

		if (!empty($payment['Type'])) {
			if(!empty($payment['ReservationNumber'])) {
				$payment['ReservationNumber'] .= ' ' . $payment['Type'];
			} else {
				$payment['ReservationNumber'] = $payment['Type'];
			}
		}

		$payment['description'] = trim($desc);

		// deal with
		if ($payment['Credit'] != 0.00) { // OR
			// Commission received FROM a supplier is a receipt addressed to that
			// supplier, not the customer — even though it's money in (an OR).
			if (!empty($payment['Type']) && $payment['Type'] === 'AGENT COMMISSION FROM SUPPLIER') {
				$payment['dealWith'] = !empty($payment['supplier_name']) ? $payment['supplier_name'] : '';
			} else {
				$payment['dealWith'] = !empty($payment['Customer']) ? $payment['Customer'] : '';
			}
		} else { // PV
			$payment['dealWith'] = !empty($payment['supplier_name']) ? $payment['supplier_name'] : '';
		}
		
		$payment['bankaccNo'] = '';

		if (!empty($payment['Type'])) {
			$type = [
				'AGENT COMMISSION',
				'CUSTOMER REFUND',
				'BANK CHARGES',
				'CREDIT CARD CHARGES',
				'ONE-TIME PAYMENT'
			];		
			if (in_array($payment['Type'], $type)) {
				$payment['dealWith'] = $payment['BankHolder'];
			}

			$bank_acc_code_types = [
				'BANK CHARGES',
				'CREDIT CARD CHARGES'
			];
			if (in_array($payment['Type'], $bank_acc_code_types)) {
				$payment['bankaccNo'] = $config['payment_acc_no_3'];
			}
		}

		$payment['CustomerCode'] = $config['payment_acc_no_1'];
		if (!empty($payment['CustomerID'])) {
			$customer = $this->Customer_Model->find($payment['CustomerID']);
			if (!empty($customer)) {
				if (!empty($customer->CustomerCode) && $customer->CustomerCode != null) {
					$payment['CustomerCode'] = $customer->CustomerCode;
				}
			}
		}

		$payment['SupplierCode'] = $config['payment_acc_no_2'];
		if (!empty($payment['SupplierID'])) {
			$supplier = $this->Supplier_Model->find($payment['SupplierID']);
			if (!empty($supplier)) {
				if (!empty($supplier->SupplierCode) && $supplier->SupplierCode != null) {
					$payment['SupplierCode'] = $supplier->SupplierCode;
				}
			}
		}

		// Foreign-currency handling. The AutoCount document follows the payment's
		// currency; a MYR-forced payload makes AutoCount's tax-currency-rate math
		// fail ("ToTaxCurrencyRate should be 1"). The row stores Currency as a
		// costing_currencies ID + ForeignCurrency as the amount in that currency.
		// For a foreign doc we send the real currency code, the foreign->MYR rate,
		// and the foreign amount (AutoCount line amounts are in document currency).
		$payment['currency_code']  = 'MYR';
		$payment['currency_rate']  = 1;
		$payment['foreign_amount'] = null;
		if (!empty($payment['Currency']) && (float)$payment['ForeignCurrency'] > 0) {
			$currency = $this->db->select('code')
				->get_where('costing_currencies', ['id' => $payment['Currency']])
				->row();
			if ($currency && strtoupper($currency->code) !== 'MYR') {
				$localAmt   = ((float)$payment['Credit'] != 0.00) ? (float)$payment['Credit'] : (float)$payment['Debit'];
				$foreignAmt = (float)$payment['ForeignCurrency'];
				if ($localAmt > 0 && $foreignAmt > 0) {
					$payment['currency_code']  = strtoupper($currency->code);
					$payment['currency_rate']  = round($localAmt / $foreignAmt, 8);
					$payment['foreign_amount'] = $foreignAmt;
				}
			}
		}

		return $payment;
	}

	// public function generateSupplierCustomerSQL() // generatequery 
	// {
	// 	$apiKey = "75be787f-d7fb-4f40-ab8d-32848af7a169";
	// 	$keyId = "f66b6d44-9433-42b1-8399-2bb5135783f3";

	// 	$supplierUrl = "https://accounting-api.autocountcloud.com/26516/creditor/listing?activeOnly=true&field=accNo&field=parentAccNo&field=desc2&field=registerNo&field=isActive&field=address&field=postCode&field=phone1&field=phone2&field=fax1&field=fax2&field=areaCode&field=emailAddress&field=webURL&field=attention&field=natureOfBusiness&field=taxCode&field=taxRegisterNo&field=note";
	// 	$customerUrl = "https://accounting-api.autocountcloud.com/26516/debtor/listing?activeOnly=true&field=accNo&field=parentAccNo&field=desc2&field=registerNo&field=isActive&field=address&field=postCode&field=phone1&field=phone2&field=fax1&field=fax2&field=deliverAddress&field=deliverPostCode&field=areaCode&field=emailAddress&field=webURL&field=attention&field=natureOfBusiness&field=salesAgent&field=taxCode&field=taxRegisterNo&field=taxExemptionNo&field=taxExemptionExpiryDate&field=note";

	// 	// Fetch data
	// 	//$responseSuppliers = $this->fetchAllAutoCount($supplierUrl, $apiKey, $keyId);
	// 	$responseCustomers = $this->fetchAllAutoCount($customerUrl, $apiKey, $keyId);


	// 	$allSuppliers = []; //$responseSuppliers ?? [];
	// 	$allCustomers = $responseCustomers ?? [];

	// 	$now = date('Y-m-d H:i:s');
	// 	$userId = 1;

	// 	$queries = [
	// 		'supplier' => [],
	// 		'customer' => []
	// 	];

	// 	// Supplier queries
	// 	// foreach ($allSuppliers as $supplier) {
	// 	// 	$supplierCode = $supplier['AccNo'] ?? null;
	// 	// 	if (!$supplierCode) continue;

	// 	// 	$exists = \DB::table('supplier')->where('SupplierCode', $supplierCode)->exists();

	// 	// 	$name = addslashes($supplier['CompanyName'] ?? '');
	// 	// 	$phone = addslashes($supplier['Phone1'] ?? '');
	// 	// 	$email = addslashes($supplier['EmailAddress'] ?? '');
	// 	// 	$currency = addslashes($supplier['CurrencyCode'] ?? '');
	// 	// 	$address = addslashes($supplier['Address'] ?? '');

	// 	// 	if ($exists) {
	// 	// 		$query = "UPDATE supplier SET
	// 	// 			Name = '{$name}',
	// 	// 			Phone = '{$phone}',
	// 	// 			PrimaryEmail = '{$email}',
	// 	// 			CurrencyCode = '{$currency}',
	// 	// 			Addressa = '{$address}',
	// 	// 			UpdateDate = '{$now}',
	// 	// 			UpdateBy = {$userId}
	// 	// 			WHERE SupplierCode = '{$supplierCode}';";
	// 	// 	} else {
	// 	// 		$query = "INSERT INTO supplier
	// 	// 			(Name, SupplierCode, Phone, PrimaryEmail, CurrencyCode, Addressa, InsertDate, InsertBy, UpdateDate, UpdateBy)
	// 	// 			VALUES
	// 	// 			('{$name}', '{$supplierCode}', '{$phone}', '{$email}', '{$currency}', '{$address}', '{$now}', {$userId}, '{$now}', {$userId});";
	// 	// 	}

	// 	// 	$queries['supplier'][] = $query;
	// 	// }

	// 	// foreach ($allSuppliers as $supplier) {
	// 	// 	$supplierCode = $supplier['AccNo'] ?? null;
	// 	// 	if (!$supplierCode) continue;

	// 	// 	$name = addslashes($supplier['CompanyName'] ?? '');
	// 	// 	$phone = addslashes($supplier['Phone1'] ?? '');
	// 	// 	$email = addslashes($supplier['EmailAddress'] ?? '');
	// 	// 	$currency = addslashes($supplier['CurrencyCode'] ?? '');
	// 	// 	$address = addslashes($supplier['Address'] ?? '');

	// 	// 	$query = "INSERT INTO supplier
	// 	// 			(Name, SupplierCode, Phone, PrimaryEmail, CurrencyCode, Address, InsertDate, InsertBy, UpdateDate, UpdateBy)
	// 	// 			VALUES
	// 	// 			('{$name}', '{$supplierCode}', '{$phone}', '{$email}', '{$currency}', '{$address}', '{$now}', '{$userId}', '{$now}', '{$userId}')";

	// 	// 	$queries['supplier'][] = $query;
	// 	// }

	// 	// Customer queries
	// 	// foreach ($allCustomers as $customer) {
	// 	// 	$customerCode = $customer['AccNo'] ?? null;
	// 	// 	if (!$customerCode) continue;

	// 	// 	$exists = \DB::table('customer')->where('CustomerCode', $customerCode)->exists();

	// 	// 	$name = addslashes($customer['CompanyName'] ?? '');
	// 	// 	$phone = addslashes($customer['Phone1'] ?? '');

	// 	// 	if ($exists) {
	// 	// 		$query = "UPDATE customer SET
	// 	// 			name = '{$name}',
	// 	// 			phone_number = '{$phone}',
	// 	// 			updated_at = '{$now}'
	// 	// 			WHERE CustomerCode = '{$customerCode}';";
	// 	// 	} else {
	// 	// 		$query = "INSERT INTO customer
	// 	// 			(name, CustomerCode, phone_number, created_at, updated_at)
	// 	// 			VALUES
	// 	// 			('{$name}', '{$customerCode}', '{$phone}', '{$now}', '{$now}');";
	// 	// 	}

	// 	// 	$queries['customer'][] = $query;
	// 	// }

	// 	foreach ($allCustomers as $customer) {
	// 			$customerCode = $customer['AccNo'] ?? null;
	// 		if (!$customerCode) continue;
	// 			$name = addslashes($customer['CompanyName'] ?? '');
	// 		$phone = addslashes($customer['Phone1'] ?? '');

	// 		$query = "INSERT INTO customer
	// 				(name, CustomerCode, phone_number, created_at, updated_at)
	// 				VALUES
	// 				('{$name}', '{$customerCode}', '{$phone}', '{$now}', '{$now}');";

	// 		$queries['customer'][] = $query;
	// 	}

	// 	// Optionally echo
	// 	// foreach ($queries['supplier'] as $index => $sql) {
	// 	// 	echo   $sql . PHP_EOL . PHP_EOL . '<br>';
	// 	// }
	// 	foreach ($queries['customer'] as $index => $sql) {
	// 		echo $sql . PHP_EOL . PHP_EOL . '<br>';
	// 	}

	// 	return $queries;
	// }

	// public function syncSuppliersAndCustomers() // straight execute
	// {
	// 	$apiKey = "75be787f-d7fb-4f40-ab8d-32848af7a169";
	// 	$keyId = "f66b6d44-9433-42b1-8399-2bb5135783f3";

	// 	$supplierUrl = "https://accounting-api.autocountcloud.com/26516/creditor/listing?activeOnly=true&field=accNo&field=parentAccNo&field=desc2&field=registerNo&field=isActive&field=address&field=postCode&field=phone1&field=phone2&field=fax1&field=fax2&field=areaCode&field=emailAddress&field=webURL&field=attention&field=natureOfBusiness&field=taxCode&field=taxRegisterNo&field=note";
	// 	$customerUrl = "https://accounting-api.autocountcloud.com/26516/debtor/listing?activeOnly=true&field=accNo&field=parentAccNo&field=desc2&field=registerNo&field=isActive&field=address&field=postCode&field=phone1&field=phone2&field=fax1&field=fax2&field=deliverAddress&field=deliverPostCode&field=areaCode&field=emailAddress&field=webURL&field=attention&field=natureOfBusiness&field=salesAgent&field=taxCode&field=taxRegisterNo&field=taxExemptionNo&field=taxExemptionExpiryDate&field=note";

	// 	// Fetch data
	// 	$responseSuppliers = $this->fetchAllAutoCount($supplierUrl, $apiKey, $keyId);
	// 	$responseCustomers = $this->fetchAllAutoCount($customerUrl, $apiKey, $keyId);

	// 	$allSuppliers = $responseSuppliers['data'] ?? [];
	// 	$allCustomers = $responseCustomers['data'] ?? [];

	// 	echo "Total suppliers: " . count($allSuppliers) . PHP_EOL;
	// 	echo "Total customers: " . count($allCustomers) . PHP_EOL;

	// 	$now = date('Y-m-d H:i:s');
	// 	$userId = 1; // fixed InsertBy / UpdateBy user ID as you requested

	// 	// Process suppliers
	// 	foreach ($allSuppliers as $supplier) {
	// 		$supplierCode = $supplier['AccNo'] ?? null;
	// 		if (!$supplierCode) {
	// 			continue; // skip if no AccNo
	// 		}

	// 		// Check if supplier exists in DB by SupplierCode
	// 		$exists = \DB::table('supplier')->where('SupplierCode', $supplierCode)->exists();

	// 		$data = [
	// 			'Name'          => $supplier['CompanyName'] ?? '',
	// 			'SupplierCode'  => $supplierCode,
	// 			'Phone'         => $supplier['Phone1'] ?? '',
	// 			'PrimaryEmail'  => $supplier['EmailAddress'] ?? '',
	// 			'CurrencyCode'  => $supplier['CurrencyCode'] ?? '',
	// 			'Addressa'      => $supplier['Address'] ?? '',
	// 		];

	// 		if ($exists) {
	// 			// Update existing
	// 			\DB::table('supplier')
	// 				->where('SupplierCode', $supplierCode)
	// 				->update(array_merge($data, [
	// 					'UpdateDate' => $now,
	// 					'UpdateBy'   => $userId,
	// 				]));
	// 			echo "Updated supplier: $supplierCode" . PHP_EOL;
	// 		} else {
	// 			// Insert new
	// 			\DB::table('supplier')->insert(array_merge($data, [
	// 				'InsertDate' => $now,
	// 				'InsertBy'   => $userId,
	// 				'UpdateDate' => $now,
	// 				'UpdateBy'   => $userId,
	// 			]));
	// 			echo "Inserted supplier: $supplierCode" . PHP_EOL;
	// 		}
	// 	}

	// 	// Process customers
	// 	foreach ($allCustomers as $customer) {
	// 		$customerCode = $customer['AccNo'] ?? null;
	// 		if (!$customerCode) {
	// 			continue; // skip if no AccNo
	// 		}

	// 		// Check if customer exists in DB by CustomerCode
	// 		$exists = \DB::table('customer')->where('CustomerCode', $customerCode)->exists();

	// 		$data = [
	// 			'name'          => $customer['CompanyName'] ?? '',
	// 			'CustomerCode'  => $customerCode,
	// 			'phone_number'  => $customer['Phone1'] ?? '',
	// 			// add other fields here if needed
	// 		];

	// 		if ($exists) {
	// 			// Update existing
	// 			\DB::table('customer')
	// 				->where('CustomerCode', $customerCode)
	// 				->update(array_merge($data, [
	// 					'updated_at' => $now,
	// 				]));
	// 			echo "Updated customer: $customerCode" . PHP_EOL;
	// 		} else {
	// 			// Insert new
	// 			\DB::table('customer')->insert(array_merge($data, [
	// 				'created_at' => $now,
	// 				'updated_at' => $now,
	// 			]));
	// 			echo "Inserted customer: $customerCode" . PHP_EOL;
	// 		}
	// 	}
	// }


	public function fetchAllAutoCount($url, $apiKey, $keyId)
	{
		$allData = [];
		$page = 1;
		$totalCount = null;

		do {
			$fullUrl = $url . "&page=" . $page;

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $fullUrl);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, [
				"API-Key: $apiKey",
				"Key-ID: $keyId",
				"Content-Type: application/json"
			]);

			$response = curl_exec($ch);
			curl_close($ch);

			$json = json_decode($response, true);

			if (!$json || !isset($json['data'])) {
				break; // invalid response
			}

			// Set totalCount once
			if ($totalCount === null && isset($json['totalCount'])) {
				$totalCount = (int) $json['totalCount'];
			}

			// Merge results
			$allData = array_merge($allData, $json['data']);

			$page++;
		
		} while (count($allData) < $totalCount);

		return $allData;
	}

	public function mapSupplierCustomerFromAutoCount()
	{
		$this->load->helper('autocount');
		$config = get_autocount_config();
		
		$apiKey = $this->input->get('key');
    	$expectedKey = $config['manual_sync_autocount_key'];

		if ($apiKey !== $expectedKey) {
			show_error('Unauthorized access', 401);
			return;
		}

		$dryRun = true; // SET TO FALSE after testing

		$apiKey = "75be787f-d7fb-4f40-ab8d-32848af7a169";
		$keyId = "f66b6d44-9433-42b1-8399-2bb5135783f3";

		$supplierUrl = "https://accounting-api.autocountcloud.com/26516/creditor/listing?activeOnly=true&field=accNo&field=companyName";
		$customerUrl = "https://accounting-api.autocountcloud.com/26516/debtor/listing?activeOnly=true&field=accNo&field=companyName";

		// Fetch from API
		$apiSuppliers = $this->fetchAllAutoCount($supplierUrl, $apiKey, $keyId) ?? [];
		$apiCustomers = $this->fetchAllAutoCount($customerUrl, $apiKey, $keyId) ?? [];

		// INDEX API DATA BY NAME
		$supplierIndex = [];
		foreach ($apiSuppliers as $item) {
			$key = trim(strtoupper($item['CompanyName'] ?? ''));
			if ($key) $supplierIndex[$key] = $item;
		}

		$customerIndex = [];
		foreach ($apiCustomers as $item) {
			$key = trim(strtoupper($item['CompanyName'] ?? ''));
			if ($key) $customerIndex[$key] = $item;
		}

		// SYSTEM SUPPLIERS
		$systemSuppliers = $this->db
			->where('SupplierCode IS NULL', null, false)
			->where('AutocountSyncStatus', 'P')
			->where('AutocountSyncAction', 'C')
			->where('Name IS NOT NULL', null, false)
			->where('Name !=', '')
			->get('supplier')
			->result();

		// SYSTEM CUSTOMERS
		$systemCustomers = $this->db
			->where('CustomerCode IS NULL', null, false)
			->where('AutocountSyncStatus', 'P')
			->where('AutocountSyncAction', 'C')
			->where('Status', 'Y')
			->where('name IS NOT NULL', null, false)
			->where('name !=', '')
			->get('customer')
			->result();

		$now = date('Y-m-d H:i:s');
		$supplierUpdates = 0;
		$customerUpdates = 0;

		echo "===== SUPPLIER MATCHES =====\n";

		foreach ($systemSuppliers as $row) {

			$nameKey = strtoupper(trim($row->Name));

			if (isset($supplierIndex[$nameKey])) {

				$apiItem = $supplierIndex[$nameKey];

				echo "Match Supplier: {$row->Name} → {$apiItem['AccNo']}\n";

				if (!$dryRun) {

					$this->db->where('SupplierID', $row->SupplierID)
						->update('supplier', [
							'SupplierCode'         => $apiItem['AccNo'],
							'AutocountSyncStatus'  => 'S',
							'AutocountSyncMessage' => json_encode($apiItem),
							'UpdateDate'           => $now,
							'UpdateBy'             => 1,
						]);
				}

				$supplierUpdates++;
			}
		}

		echo "\n===== CUSTOMER MATCHES =====\n";

		foreach ($systemCustomers as $row) {

			$nameKey = strtoupper(trim($row->name));

			if (isset($customerIndex[$nameKey])) {

				$apiItem = $customerIndex[$nameKey];

				echo "Match Customer: {$row->name} → {$apiItem['AccNo']}\n";

				if (!$dryRun) {

					$this->db->where('CustomerID', $row->CustomerID)
						->update('customer', [
							'CustomerCode'         => $apiItem['AccNo'],
							'AutocountSyncStatus'  => 'S',
							'AutocountSyncMessage' => json_encode($apiItem),
							'updated_at'           => $now,
						]);
				}

				$customerUpdates++;
			}
		}

		echo "\n===== SUMMARY =====\n";
		print_r(json_encode([
			'dry_run_mode'            => $dryRun,
			'total_api_suppliers'     => count($apiSuppliers),
			'total_api_customers'     => count($apiCustomers),
			'system_suppliers_to_map' => count($systemSuppliers),
			'system_customers_to_map' => count($systemCustomers),
			'supplier_matched'        => $supplierUpdates,
			'customer_matched'        => $customerUpdates,
		]));
	}

	/**
	 * GHL users sync. CLI only: php index.php Cron syncGhlUsers
	 */
	public function syncGhlUsers()
	{
		if (!$this->input->is_cli_request()) {
			show_error('Not allowed', 403);
			return;
		}

		$this->load->library('GhlUsersSyncService');
		$result = $this->ghluserssyncservice->sync();

		$this->output
			->set_content_type('application/json')
			->set_output(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * GHL contacts sync. CLI only: php index.php Cron syncGhlContacts
	 */
	public function syncGhlContacts()
	{
		if (!$this->input->is_cli_request()) {
			show_error('Not allowed', 403);
			return;
		}

		$args = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
		$uriSegments = $this->uri->segment_array();
		$flags = array_merge(
			array_slice($args, 3),
			$uriSegments ? array_slice($uriSegments, 2) : array()
		);

		$this->load->library('GhlContactsSyncService');
		$result = $this->ghlcontactssyncservice->sync(array(
			'mode' => in_array('--full', $flags, true) ? 'full' : 'recent',
		));

		$this->output
			->set_content_type('application/json')
			->set_output(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * GHL custom fields sync. CLI only: php index.php Cron syncGhlCustomFields
	 */
	public function syncGhlCustomFields()
	{
		if (!$this->input->is_cli_request()) {
			show_error('Not allowed', 403);
			return;
		}

		$args = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
		$uriSegments = $this->uri->segment_array();
		$flags = array_merge(
			array_slice($args, 3),
			$uriSegments ? array_slice($uriSegments, 2) : array()
		);

		$model = 'contact';
		foreach ($flags as $flag) {
			if (strpos((string) $flag, '--model=') === 0) {
				$model = substr((string) $flag, 8);
				break;
			}
		}

		$this->load->library('GhlCustomFieldsSyncService');
		$result = $this->ghlcustomfieldssyncservice->sync(array(
			'model' => $model,
		));

		$this->output
			->set_content_type('application/json')
			->set_output(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * GHL conversations sync. CLI only: php index.php Cron syncGhlConversations
	 */
	public function syncGhlConversations()
	{
		if (!$this->input->is_cli_request()) {
			show_error('Not allowed', 403);
			return;
		}

		$args = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
		$uriSegments = $this->uri->segment_array();
		$flags = array_merge(
			array_slice($args, 3),
			$uriSegments ? array_slice($uriSegments, 2) : array()
		);

		$this->load->library('GhlConversationsSyncService');
		$result = $this->ghlconversationssyncservice->sync(array(
			'mode' => in_array('--full', $flags, true) ? 'full' : 'recent',
		));

		$this->output
			->set_content_type('application/json')
			->set_output(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * GHL messages sync. CLI only: php index.php Cron syncGhlMessages
	 * Targeted history repair: php index.php Cron syncGhlMessages contact CONTACT_ID
	 *                         php index.php Cron syncGhlMessages conversation CONVERSATION_ID
	 */
	public function syncGhlMessages()
	{
		if (!$this->input->is_cli_request()) {
			show_error('Not allowed', 403);
			return;
		}

		$args = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
		$uriSegments = $this->uri->segment_array();
		$flags = array_merge(
			array_slice($args, 3),
			$uriSegments ? array_slice($uriSegments, 2) : array()
		);

		$this->load->library('GhlMessagesSyncService');
		$contactId = '';
		$conversationId = '';
		for ($i = 0, $flagCount = count($flags); $i < $flagCount; $i++) {
			$flag = $flags[$i];
			if (strpos($flag, '--contact-id=') === 0) {
				$contactId = trim(substr($flag, strlen('--contact-id=')));
			} elseif (strpos($flag, '--conversation-id=') === 0) {
				$conversationId = trim(substr($flag, strlen('--conversation-id=')));
			// CodeIgniter treats "--contact-id=..." as an invalid URI. Support
			// URI-safe CLI segments as well: contact CONTACT_ID / conversation ID.
			} elseif ($flag === 'contact' && isset($flags[$i + 1])) {
				$contactId = trim((string) $flags[++$i]);
			} elseif ($flag === 'conversation' && isset($flags[$i + 1])) {
				$conversationId = trim((string) $flags[++$i]);
			}
		}
		$result = $this->ghlmessagessyncservice->sync(array(
			'mode' => in_array('--full', $flags, true) ? 'full' : 'recent',
			'contact_id' => $contactId,
			'conversation_id' => $conversationId,
		));

		$this->output
			->set_content_type('application/json')
			->set_output(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Background worker for campaign → GHL workflow enrolment. CLI only:
	 *   php index.php Cron syncGhlCampaigns
	 *
	 * Drains pending campaign sync runs one at a time. Each run is processed
	 * in small chunks under a per-run time budget so a single cron tick can
	 * dispatch multiple runs and so a crash mid-chunk only loses progress for
	 * that one chunk (the next tick reclaims via stale-ClaimedAt). Suggested
	 * crontab: every minute.
	 */
	public function syncGhlCampaigns()
	{
		if (!$this->input->is_cli_request()) {
			show_error('Not allowed', 403);
			return;
		}

		$this->load->model('Campaign_Ghl_Sync_Model');
		$this->load->library('GhlCampaignSyncService');

		// Overall wall budget for one tick. Each cron invocation gets ~50s of
		// real work; the cron runs every minute so this leaves headroom.
		$tick_budget       = 50;
		$per_run_budget    = 45;
		$chunk_size        = 10;
		$tick_started      = microtime(true);
		$runs_handled      = 0;
		$summary           = array();

		$this->customCronLogging('[CRON] syncGhlCampaigns start');

		while ((microtime(true) - $tick_started) < $tick_budget) {
			$claimed = $this->Campaign_Ghl_Sync_Model->claim_next_pending_run();
			if (!$claimed) {
				if ($runs_handled === 0) {
					$this->customCronLogging('[CRON] syncGhlCampaigns no pending runs');
				}
				break;
			}

			$runs_handled++;
			$this->customCronLogging(sprintf(
				'[CRON] syncGhlCampaigns claimed run=%s campaign=%d offset=%d/%d',
				$claimed->RunID, (int) $claimed->CampaignID, (int) $claimed->CurrentOffset, (int) $claimed->TotalGuests
			));

			$drain = $this->ghlcampaignsyncservice->run_to_completion(
				(int) $claimed->CampaignID,
				(string) $claimed->RunID,
				(int) $claimed->InsertBy,
				$per_run_budget,
				$chunk_size
			);

			if (!empty($drain['done'])) {
				$final = $this->ghlcampaignsyncservice->complete_run((string) $claimed->RunID, (int) $claimed->InsertBy);
				$this->customCronLogging(sprintf(
					'[CRON] syncGhlCampaigns finished run=%s status=%s enrolled=%d failed=%d',
					$claimed->RunID,
					$final ? $final->Status : 'unknown',
					$final ? (int) $final->EnrolledCount : 0,
					$final ? (int) $final->FailedCount : 0
				));
				$summary[] = array(
					'run_id' => (string) $claimed->RunID,
					'done'   => true,
				);
			} else {
				// Time budget hit — leave the run in 'running' state with the
				// updated CurrentOffset so the next tick resumes from there.
				$this->customCronLogging(sprintf(
					'[CRON] syncGhlCampaigns yielded run=%s next_offset=%d',
					$claimed->RunID, isset($drain['next_offset']) ? (int) $drain['next_offset'] : -1
				));
				$summary[] = array(
					'run_id'      => (string) $claimed->RunID,
					'done'        => false,
					'next_offset' => isset($drain['next_offset']) ? (int) $drain['next_offset'] : null,
				);
				break; // Don't claim another run when this one didn't finish.
			}
		}

		$this->customCronLogging('[CRON] syncGhlCampaigns end runs_handled=' . $runs_handled);

		$this->output
			->set_content_type('application/json')
			->set_output(json_encode(array(
				'ok'           => true,
				'runs_handled' => $runs_handled,
				'runs'         => $summary,
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	public function AddCustomerFromAutoCount()
	{
		$this->load->helper('autocount');
		$config = get_autocount_config();

		// 🔐 Simple API key protection
		$apiKey = $this->input->get('key');
		$expectedKey = $config['manual_sync_autocount_key'];

		if ($apiKey !== $expectedKey) {
			show_error('Unauthorized access', 401);
			return;
		}

		// 🔁 Dry run mode (SET FALSE AFTER TESTING)
		$dryRun = true;

		// 🔑 AutoCount API credentials
		$apiKey = "75be787f-d7fb-4f40-ab8d-32848af7a169";
		$keyId  = "f66b6d44-9433-42b1-8399-2bb5135783f3";

		// 🌐 AutoCount customer listing API
		$customerUrl = "https://accounting-api.autocountcloud.com/26516/debtor/listing"
			. "?activeOnly=true"
			. "&field=accNo"
			. "&field=companyName"
			. "&field=phone1";

		// 📡 Fetch customers from AutoCount
		$apiCustomers = $this->fetchAllAutoCount($customerUrl, $apiKey, $keyId) ?? [];

		// 📦 Existing CustomerCode index (FAST lookup)
		$existingCodes = $this->db
			->select('CustomerCode')
			->where('CustomerCode IS NOT NULL', null, false)
			->get('customer')
			->result_array();

		$existingIndex = array_flip(
			array_map('strtoupper', array_column($existingCodes, 'CustomerCode'))
		);

		$now       = date('Y-m-d H:i:s');
		$inserted  = 0;
		$ignored   = 0;

		echo "\n===== AUTOCOUNT CUSTOMER SYNC =====\n";

		foreach ($apiCustomers as $item) {

			$accNo = strtoupper(trim($item['AccNo'] ?? ''));

			// Skip invalid records
			if (!$accNo) {
				continue;
			}

			// 🚫 Ignore if already exists
			if (isset($existingIndex[$accNo])) {
				echo "Ignored (exists): {$accNo}\n";
				$ignored++;
				continue;
			}

			echo "Insert New Customer: {$accNo} - {$item['CompanyName']}\n";

			if (!$dryRun) {
				// $this->db->insert('customer', [
				// 	'CustomerCode'         => $item['AccNo'],
				// 	'name'                 => $item['CompanyName'] ?? '',
				// 	'phone_number'         => $item['Phone1'] ?? '',
				// 	'Status'               => 'Y',
				// 	'AutocountSyncStatus'  => 'S',
				// 	'AutocountSyncAction'  => 'C',
				// 	'AutocountSyncMessage' => json_encode($item),
				// 	'created_at'           => $now,
				// 	'updated_at'           => $now,
				// ]);
			}

			// Add to index to prevent double insert in same run
			$existingIndex[$accNo] = true;
			$inserted++;
		}

		echo "\n===== SUMMARY =====\n";
		echo json_encode([
			'dry_run_mode'        => $dryRun,
			'total_api_customers' => count($apiCustomers),
			'inserted'            => $inserted,
			'ignored_existing'    => $ignored,
		], JSON_PRETTY_PRINT);
	}

	public function customCronLogging($message, $meta = array())
	{
		if ($this->allowGhlModuleLog) {
			logInFile('GHL_MODULES_SYNC', $message, $meta);
		}
	}

}
