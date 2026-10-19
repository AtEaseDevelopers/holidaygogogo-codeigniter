<?php

class Dashboard extends MY_Controller

{

	function __construct()

	{

		
		parent::__construct();

		// Sales agents historically had no Dashboard access. Visibility is now
		// nav_can_view('dashboard') (legacy rule + Settings > Navigation Access).
		if (function_exists('nav_block_view') && nav_block_view('dashboard')) {
			return;
		}

		$this->load->model('Dashboard_Model');

		$this->load->library('recalculate');



	}



	function index()

	{	

		// Recalculate is a write. View-only staff can open Dashboard but must not mutate bookings.
		if ( ! function_exists('nav_can_edit') || nav_can_edit('dashboard')) {
			$this->recalculate->recalculate_all_bookings();
		}



		$titles = array('tab_title' => 'HolidayGoGoGo | Dashboard', 'breadcrumb_title' => 'Dashboard');

		// Get date filters from query string
		$start_date = $this->input->get('start_date');
		$end_date = $this->input->get('end_date');

		//SA

		$array['sales_agent_upcoming_travels'] = $this->Dashboard_Model->Sales_Agent_Upcoming_Travels($start_date, $end_date);

		$array['sales_agent_overdue_payments'] = $this->Dashboard_Model->Sales_Agent_Overdue_Payments();

		$array['sales_agent_pending_travel_vouchers'] = $this->Dashboard_Model->Sales_Agent_Pending_Travel_Vouchers($start_date, $end_date);

		$sales_agent_profit_margins = $this->Dashboard_Model->Sales_Agent_Profit_Margins();

		$array['sales_agent_negative_profit_margins'] = [];

		$array['sales_agent_profit_margins_less_than_10_percent'] = [];

		foreach($sales_agent_profit_margins as $sales_agent_profit_margin) {

			if($sales_agent_profit_margin->NetProfit < 0) {

				array_push($array['sales_agent_negative_profit_margins'], $sales_agent_profit_margin);

			}

			if($sales_agent_profit_margin->NetProfit > 0 && round(($sales_agent_profit_margin->NetProfit / $sales_agent_profit_margin->NetTotal) * 100) < 10 && $sales_agent_profit_margin->Date >= date('Y-m-d', strtotime('- 30 Days')) && $sales_agent_profit_margin->Date < date('Y-m-d') && $sales_agent_profit_margin->AfterSalesService == 'COMPLETE' && $sales_agent_profit_margin->Status == 'Y') {

				array_push($array['sales_agent_profit_margins_less_than_10_percent'], $sales_agent_profit_margin);

				$sales_agent_profit_margin->Percentage = round(($sales_agent_profit_margin->NetProfit / $sales_agent_profit_margin->NetTotal) * 100) . '%';

			}

		}

		$array['sales_agent_pending_credit_payments'] = $this->Dashboard_Model->Sales_Agent_Pending_Credit_Payments();

		$array['sales_agent_pending_debit_payments'] = $this->Dashboard_Model->Sales_Agent_Pending_Debit_Payments();



		//Owner / Finance

		$level = (int) $this->session->userdata('level');

		$array['sales_agents'] = $this->Dashboard_Model->Sales_Agents();

		// The Owner (Level 10) dashboard was reworked to a lean KPI layout, and the
		// Team Lead (25) now gets an even leaner matrix-only view — so the old
		// travel/payment reminder cards, Leading SA and the ApexCharts are not
		// rendered for either. Skip building their (expensive) data for both; every
		// other non-SA role (Finance, etc.) keeps the old view.
		if($level != 10 && $level != 25) {

		$array['upcoming_travels'] = $this->Dashboard_Model->Upcoming_Travels($start_date, $end_date);

		$array['overdue_payments'] = $this->Dashboard_Model->Overdue_Payments();

		$array['pending_travel_vouchers'] = $this->Dashboard_Model->Pending_Travel_Vouchers($start_date, $end_date);

		$array['pending_reviews'] = $this->Dashboard_Model->Pending_Reviews();

		$profit_margins = $this->Dashboard_Model->Profit_Margins();

		$array['negative_profit_margins'] = [];

		$array['profit_margins_less_than_10_percent'] = [];

		foreach($profit_margins as $profit_margin) {

			if($profit_margin->NetProfit < 0) {

				array_push($array['negative_profit_margins'], $profit_margin);

			}

			if($profit_margin->NetProfit > 0 && round(($profit_margin->NetProfit / $profit_margin->NetTotal) * 100) < 10 && $profit_margin->Date >= date('Y-m-d', strtotime('- 30 Days')) && $profit_margin->Date < date('Y-m-d') && $profit_margin->AfterSalesService == 'COMPLETE' && $profit_margin->Status == 'Y') {

				array_push($array['profit_margins_less_than_10_percent'], $profit_margin);

				$profit_margin->Percentage = round(($profit_margin->NetProfit / $profit_margin->NetTotal) * 100) . '%';

			}

		}



		$array['pending_credit_payments'] = $this->Dashboard_Model->Pending_Credit_Payments();

		$array['pending_debit_payments'] = $this->Dashboard_Model->Pending_Debit_Payments1();

		$array['weekly_top_sa'] = $this->Dashboard_Model->Weekly_Top_SA();

		foreach($array['weekly_top_sa'] as $sa) {

			$sa->Sales = 'Total Sales : RM ' . number_format($sa->Sales, 2, '.', ',');

			if($sa->Gender == 'F') {

				$sa->ProfilePicture = base_url('assets/image/female.svg');

			} else {

				$sa->ProfilePicture = base_url('assets/image/male.svg');

			}

		}

		$array['monthly_top_sa'] = $this->Dashboard_Model->Monthly_Top_SA();

		foreach($array['monthly_top_sa'] as $sa) {

			$sa->Sales = 'Total Sales : RM ' . number_format($sa->Sales, 2, '.', ',');

			if($sa->Gender == 'F') {

				$sa->ProfilePicture = base_url('assets/image/female.svg');

			} else {

				$sa->ProfilePicture = base_url('assets/image/male.svg');

			}

		}

		$array['annual_top_sa'] = $this->Dashboard_Model->Annual_Top_SA();

		foreach($array['annual_top_sa'] as $sa) {

			$sa->Sales = 'Total Sales : RM ' . number_format($sa->Sales, 2, '.', ',');

			if($sa->Gender == 'F') {

				$sa->ProfilePicture = base_url('assets/image/female.svg');

			} else {

				$sa->ProfilePicture = base_url('assets/image/male.svg');

			}

		}

		} // end if(level != 10)

		// ---------- Owner (Level 10) lean KPI cards ----------
		if($level == 10) {

			$this->load->model('Team_Model');
			$this->load->helper('summary_period_helper');
			$today_ymd = date('Y-m-d');

			// Five date windows the Owner KPI cards report over.
			$windows = array(
				'yesterday' => array(date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))),
				'today'     => array(date('Y-m-d'), date('Y-m-d')),
				'week'      => array(date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))),
				'month'     => array(date('Y-m-01'), date('Y-m-t')),
				'year'      => array(date('Y-01-01'), date('Y-12-31')),
			);

			// Same-period-last-year windows: the same span shifted back one year,
			// clamped to "today last year" so a partly-elapsed month/year compares
			// like-for-like (reuses the summary cards' YoY math).
			$ly_windows = array();
			foreach($windows as $key => $range) {
				$prior = summary_prior_year_window($range[0], $range[1], $today_ymd);
				$ly_windows[$key] = array($prior['start'], $prior['end']);
			}

			// Total sales by team — one row per active team, a column per window,
			// each with its same-period-last-year figure for comparison.
			$teams = $this->Team_Model->Read_Teams();
			$team_rows = array();
			foreach($teams as $team) {
				$team_rows[$team->TeamID] = array(
					'name' => $team->Name,
					'cur'  => array('yesterday' => 0, 'today' => 0, 'week' => 0, 'month' => 0, 'year' => 0),
					'ly'   => array('yesterday' => 0, 'today' => 0, 'week' => 0, 'month' => 0, 'year' => 0),
				);
			}
			foreach($windows as $key => $range) {
				foreach($this->Dashboard_Model->Team_Sales($range[0], $range[1]) as $row) {
					if(isset($team_rows[$row->TeamID])) {
						$team_rows[$row->TeamID]['cur'][$key] = (float) $row->Sales;
					}
				}
			}
			foreach($ly_windows as $key => $range) {
				foreach($this->Dashboard_Model->Team_Sales($range[0], $range[1]) as $row) {
					if(isset($team_rows[$row->TeamID])) {
						$team_rows[$row->TeamID]['ly'][$key] = (float) $row->Sales;
					}
				}
			}

			// "Unassigned (No Team)" bucket — BC sales by agents outside any active
			// team (e.g. the owner's own bookings). Appended last so the per-team
			// rows plus this reconcile to the true company-wide BC total.
			$unassigned = array(
				'name' => 'Unassigned (No Team)',
				'cur'  => array('yesterday' => 0, 'today' => 0, 'week' => 0, 'month' => 0, 'year' => 0),
				'ly'   => array('yesterday' => 0, 'today' => 0, 'week' => 0, 'month' => 0, 'year' => 0),
			);
			foreach($windows as $key => $range) {
				$unassigned['cur'][$key] = $this->Dashboard_Model->Unassigned_Sales($range[0], $range[1]);
			}
			foreach($ly_windows as $key => $range) {
				$unassigned['ly'][$key] = $this->Dashboard_Model->Unassigned_Sales($range[0], $range[1]);
			}
			$team_rows['unassigned'] = $unassigned;

			$array['owner_team_sales'] = $team_rows;

			// Monthly & yearly sales targets per team (sum of member agents'
			// targets, set in Admin) for the current period — feeds the
			// "Total Sales vs Target by Team" card, compared against the month
			// & year actuals already in $team_rows above.
			$array['owner_team_targets'] = $this->Dashboard_Model->Team_Targets(
				(int) date('Y'), (int) date('n')
			);

			// Total new leads (GHL) — company-wide count of every lead that LANDED
			// per window (ghl_processed_leads by lead_started_at), regardless of
			// whether it has been picked up. Reuses the Leads By Hour report's
			// query universe so the card total and that grid always match.
			$this->load->model('Report_Model');
			$array['owner_new_leads'] = array();
			foreach($windows as $key => $range) {
				$array['owner_new_leads'][$key] = $this->Report_Model->Leads_Landed_Total($range[0], $range[1]);
			}

			// Top 5 cancellation reasons this year.
			$array['owner_cancellation_reasons'] = $this->Dashboard_Model->Top_Cancellation_Reasons(
				$windows['year'][0], $windows['year'][1], 5
			);
			// Total cancellations this year — shown in the card subtext.
			$array['owner_cancellation_total'] = $this->Dashboard_Model->Total_Cancellations(
				$windows['year'][0], $windows['year'][1]
			);
			// Revenue lost to those cancellations this year — SUM(NetTotal).
			$array['owner_cancellation_revenue_lost'] = $this->Dashboard_Model->Total_Cancellation_Revenue_Lost(
				$windows['year'][0], $windows['year'][1]
			);
			// Total bookings this year — denominator for each reason's % share.
			$array['owner_booking_total'] = $this->Dashboard_Model->Total_Bookings(
				$windows['year'][0], $windows['year'][1]
			);

			// Approved payment OUT (to suppliers) — today, week, month & year.
			$array['owner_payment_out'] = array(
				'today' => $this->Dashboard_Model->Approved_Payment_Out($windows['today'][0], $windows['today'][1]),
				'week'  => $this->Dashboard_Model->Approved_Payment_Out($windows['week'][0], $windows['week'][1]),
				'month' => $this->Dashboard_Model->Approved_Payment_Out($windows['month'][0], $windows['month'][1]),
				'year'  => $this->Dashboard_Model->Approved_Payment_Out($windows['year'][0], $windows['year'][1]),
			);

			// Unapproved (pending) payment OUT — pending pay-outs whose Deadline falls
			// within each window (bounded range), matching the Payment listing's
			// "Total Payment Out" filtered by deadline range + pending + payment-out.
			$array['owner_payment_out_pending'] = array(
				'today' => $this->Dashboard_Model->Unapproved_Payment_Out($windows['today'][0], $windows['today'][1]),
				'week'  => $this->Dashboard_Model->Unapproved_Payment_Out($windows['week'][0], $windows['week'][1]),
				'month' => $this->Dashboard_Model->Unapproved_Payment_Out($windows['month'][0], $windows['month'][1]),
				'year'  => $this->Dashboard_Model->Unapproved_Payment_Out($windows['year'][0], $windows['year'][1]),
			);

			// Approved payment IN (from customers) — today, week, month & year.
			$array['owner_payment_in'] = array(
				'today' => $this->Dashboard_Model->Approved_Payment_In($windows['today'][0], $windows['today'][1]),
				'week'  => $this->Dashboard_Model->Approved_Payment_In($windows['week'][0], $windows['week'][1]),
				'month' => $this->Dashboard_Model->Approved_Payment_In($windows['month'][0], $windows['month'][1]),
				'year'  => $this->Dashboard_Model->Approved_Payment_In($windows['year'][0], $windows['year'][1]),
			);

		}

		$this->load->view('layout/header', $titles);

		$this->load->view('dashboard', $array);

		$this->load->view('layout/footer');

	}



	//SA

	function Sales_Agent_Daily_Sales()

	{	

		$start_time = microtime(true);



		$array['current_week'] = [];

		$array['daily_sales'] = [0, 0, 0, 0, 0, 0, 0];

		for($i = 1; $i <= 7; $i++) {

			switch($i) {

				case 1:

					array_push($array['current_week'], date('j M', strtotime('This Week Monday')));

					break;

				case 2:

					array_push($array['current_week'], date('j M', strtotime('This Week Tuesday')));

					break;

				case 3:

					array_push($array['current_week'], date('j M', strtotime('This Week Wednesday')));

					break;

				case 4:

					array_push($array['current_week'], date('j M', strtotime('This Week Thursday')));

					break;

				case 5:

					array_push($array['current_week'], date('j M', strtotime('This Week Friday')));

					break;

				case 6:

					array_push($array['current_week'], date('j M', strtotime('This Week Saturday')));

					break;

				case 7:

					array_push($array['current_week'], date('j M', strtotime('This Week Sunday')));

					break;

				default:

			}

		}

		$daily_sales = $this->Dashboard_Model->Sales_Agent_Daily_Sales();

		foreach($daily_sales as $daily_sale) {

			$array['daily_sales'][array_search(date('j M', strtotime($daily_sale->Date)), $array['current_week'])] = $daily_sale->Sales;

		}



		$end_time = microtime(true);



		if(isset($_GET['nick'])) {

			$query_time = $end_time - $start_time;

			echo "The query took " . $query_time . " seconds to execute.";exit;

		}



		echo json_encode($array);

	}



	function Sales_Agent_Monthly_Sales()

	{

		$monthly_sales = $this->Dashboard_Model->Sales_Agent_Monthly_Sales();

		$array = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

		foreach($monthly_sales as $monthly_sale) {

			$array[$monthly_sale->Month - 1] = $monthly_sale->Sales;

			$array[12] += $monthly_sale->Sales;

		}

		echo json_encode($array);

	}



	function Sales_Agent_Cancellation_Rates()

	{

		$cancelled_bookings = $this->Dashboard_Model->Sales_Agent_Cancelled_Bookings();

		$monthly_bookings = $this->Dashboard_Model->Sales_Agent_Monthly_Bookings();

		$array = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

		foreach($cancelled_bookings as $cancelled_booking) {

			$array[$cancelled_booking->Month - 1] = round(($cancelled_booking->Total / $monthly_bookings[$cancelled_booking->Month - 1]->Total) * 100);

		}

		echo json_encode($array);

	}



	//Owner / Finance

	function Sales_Agents_Daily_Sales()

	{

		// Get date filters from POST data
		$start_date = $this->input->post('start_date');
		$end_date = $this->input->post('end_date');
		$sales_agents_filter = $this->input->post('sales_agents');

		// Convert comma-separated sales agents to array
		$selected_agents = [];
		if ($sales_agents_filter) {
			$selected_agents = explode(',', $sales_agents_filter);
		}

		// If dates are provided, use them. Otherwise default to current week
		if ($start_date && $end_date) {
			// Generate date range array
			$start = new DateTime($start_date);
			$end = new DateTime($end_date);
			$interval = new DateInterval('P1D');
			$date_range = new DatePeriod($start, $interval, $end->modify('+1 day'));

			$array['current_week'] = [];
			foreach ($date_range as $date) {
				array_push($array['current_week'], $date->format('j M'));
			}
		} else {
			// Default to current week
			$array['current_week'] = [];

			for($i = 1; $i <= 7; $i++) {

				switch($i) {

					case 1:

						array_push($array['current_week'], date('j M', strtotime('This Week Monday')));

						break;

					case 2:

						array_push($array['current_week'], date('j M', strtotime('This Week Tuesday')));

						break;

					case 3:

						array_push($array['current_week'], date('j M', strtotime('This Week Wednesday')));

						break;

					case 4:

						array_push($array['current_week'], date('j M', strtotime('This Week Thursday')));

						break;

					case 5:

						array_push($array['current_week'], date('j M', strtotime('This Week Friday')));

						break;

					case 6:

						array_push($array['current_week'], date('j M', strtotime('This Week Saturday')));

						break;

					case 7:

						array_push($array['current_week'], date('j M', strtotime('This Week Sunday')));

						break;

					default:

				}

			}
		}

		$sales_agents = $this->Dashboard_Model->Sales_Agents();

		$daily_sales = $this->Dashboard_Model->Sales_Agents_Daily_Sales($start_date, $end_date, $selected_agents);

		$array['sales_agents'] = [];

		foreach($sales_agents as $sales_agent) {

			// If filter is applied, only include selected agents
			if (!empty($selected_agents) && !in_array($sales_agent->AdminID, $selected_agents)) {
				continue;
			}

			array_push($array['sales_agents'], $sales_agent->Name);

			$array['daily_sales'][$sales_agent->Name] = array_fill(0, count($array['current_week']), 0);

			foreach($daily_sales as $daily_sale) {

				if($daily_sale->SalesAgent == $sales_agent->AdminID) {

					$date_index = array_search(date('j M', strtotime($daily_sale->Date)), $array['current_week']);
					if ($date_index !== false) {
						$array['daily_sales'][$daily_sale->Name][$date_index] = $daily_sale->Sales;
					}

				}

			}

		}

		echo json_encode($array);

	}



	function Sales_Agents_Monthly_Sales()

	{

		$sales_agents = $this->Dashboard_Model->Sales_Agents();

		$monthly_sales = $this->Dashboard_Model->Sales_Agents_Monthly_Sales();

		$array['sales_agents'] = [];

		foreach($sales_agents as $sales_agent) {

			array_push($array['sales_agents'], $sales_agent->Name);

			$array['monthly_sales'][$sales_agent->Name] = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

			foreach($monthly_sales as $monthly_sale) {

				if($monthly_sale->SalesAgent == $sales_agent->AdminID) {

					$array['monthly_sales'][$monthly_sale->Name][$monthly_sale->Month - 1] = $monthly_sale->Sales;

				}

			}

		}

		echo json_encode($array);

	}



	function Monthly_Sales()

	{

		$monthly_sales = $this->Dashboard_Model->Monthly_Sales();

		$array = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

		foreach($monthly_sales as $monthly_sale) {

			$array[$monthly_sale->Month - 1] = $monthly_sale->Sales;

			$array[12] += $monthly_sale->Sales;

		}

		echo json_encode($array);

	}



	function Cancellation_Rates()

	{

		$cancelled_bookings = $this->Dashboard_Model->Cancelled_Bookings();

		$monthly_bookings = $this->Dashboard_Model->Monthly_Bookings();

		$array = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

		foreach($cancelled_bookings as $cancelled_booking) {

			$array[$cancelled_booking->Month - 1] = round(($cancelled_booking->Total / $monthly_bookings[$cancelled_booking->Month - 1]->Total) * 100);

		}

		echo json_encode($array);

	}



	function Pending_Payments()

	{

		$pending_partial_payments = $this->Dashboard_Model->Pending_Partial_Payments();

		$pending_debit_payments = $this->Dashboard_Model->Pending_Debit_Payments();

		$array['credits'] = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

		$array['debits'] = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

		foreach($pending_partial_payments as $pending_partial_payment) {

			$array['credits'][$pending_partial_payment->Month - 1] += $pending_partial_payment->OutstandingBalance;

			$array['credits'][12] += $pending_partial_payment->OutstandingBalance;

		}

		foreach($pending_debit_payments as $pending_debit_payment) {

			$array['debits'][$pending_debit_payment->Month - 1] = $pending_debit_payment->Debit;

			$array['debits'][12] += $pending_debit_payment->Debit;

		}

		echo json_encode($array);

	}



	function Approved_Payments()

	{

		$approved_credit_payments = $this->Dashboard_Model->Approved_Credit_Payments();

		$approved_debit_payments = $this->Dashboard_Model->Approved_Debit_Payments();

		$array['credits'] = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

		$array['debits'] = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

		foreach($approved_credit_payments as $approved_credit_payment) {

			$array['credits'][$approved_credit_payment->Month - 1] = $approved_credit_payment->Credit;

			$array['credits'][12] += $approved_credit_payment->Credit;

		}

		foreach($approved_debit_payments as $approved_debit_payment) {

			$array['debits'][$approved_debit_payment->Month - 1] = $approved_debit_payment->Debit;

			$array['debits'][12] += $approved_debit_payment->Debit;

		}

		echo json_encode($array);

	}

	

	function Daily_Sales()

	{

		// Get date filters from POST data
		$start_date = $this->input->post('start_date');
		$end_date = $this->input->post('end_date');

		$array['daily_sales'] = [];

		// If dates are provided, use them. Otherwise default to current week
		if ($start_date && $end_date) {
			// Generate date range array
			$start = new DateTime($start_date);
			$end = new DateTime($end_date);
			$interval = new DateInterval('P1D');
			$date_range = new DatePeriod($start, $interval, $end->modify('+1 day'));

			$array['current_week'] = [];
			foreach ($date_range as $date) {
				array_push($array['current_week'], $date->format('j M'));
			}
			$array['daily_sales'] = array_fill(0, count($array['current_week']), 0);
		} else {
			// Default to current week
			$array['current_week'] = [];

			$array['daily_sales'] = [0, 0, 0, 0, 0, 0, 0];

			for($i = 1; $i <= 7; $i++) {

				switch($i) {

					case 1:

						array_push($array['current_week'], date('j M', strtotime('This Week Monday')));

						break;

					case 2:

						array_push($array['current_week'], date('j M', strtotime('This Week Tuesday')));

						break;

					case 3:

						array_push($array['current_week'], date('j M', strtotime('This Week Wednesday')));

						break;

					case 4:

						array_push($array['current_week'], date('j M', strtotime('This Week Thursday')));

						break;

					case 5:

						array_push($array['current_week'], date('j M', strtotime('This Week Friday')));

						break;

					case 6:

						array_push($array['current_week'], date('j M', strtotime('This Week Saturday')));

						break;

					case 7:

						array_push($array['current_week'], date('j M', strtotime('This Week Sunday')));

						break;

					default:

				}

			}
		}

		$daily_sales = $this->Dashboard_Model->Daily_Sales($start_date, $end_date);

		foreach($daily_sales as $daily_sale) {

			$date_index = array_search(date('j M', strtotime($daily_sale->Date)), $array['current_week']);
			if ($date_index !== false) {
				$array['daily_sales'][$date_index] = $daily_sale->Sales;
			}

		}

		echo json_encode($array);

	}

}