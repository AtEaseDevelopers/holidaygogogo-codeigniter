<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/dompdf/autoload.inc.php';

use Dompdf\Dompdf;

class Booking_PDF_Generator {

	protected $CI;

	public function __construct()
	{
		$this->CI =& get_instance();
		$this->CI->load->model('Booking_Model');
		$this->CI->load->model('Universal_Model');
		$this->CI->load->model('Booking_Product_Model');
		$this->CI->load->model('Company_Model');
		$this->CI->load->model('Payment_Model');
		$this->CI->load->model('Guest_List_Room_Model');
		$this->CI->load->model('Guest_List_Model');
	}

	/**
	 * Prepare booking data array for PDF rendering
	 */
	protected function prepare_data($token)
	{
		$array = $this->CI->Booking_Model->Booking_Document_By_Token($token);

		if (empty($array)) {
			return false;
		}

		$array['DepositDeadline'] = empty($array['DepositDeadline']) ? '-' : strtoupper(date('j M Y', strtotime($array['DepositDeadline'])));

		$deposit_mode = isset($array['DepositMode']) ? $array['DepositMode'] : 'percentage';
		if ($deposit_mode == 'fixed') {
			$array['DepositAmount'] = isset($array['DepositFixedAmount']) ? floatval($array['DepositFixedAmount']) : 0;
		} else {
			$deposit_percentage = isset($array['DepositPercentage']) ? $array['DepositPercentage'] : 0;
			$array['DepositAmount'] = ceil($array['NetTotal'] * $deposit_percentage / 100);
		}

		$array['FullPaymentDeadline'] = strtoupper(date('j M Y', strtotime($array['FullPaymentDeadline'])));

		$array['CustomerMobile'] = $array['CountryCode'] . $array['CustomerMobile'];

		if (!empty($array['StartDate']) && !empty($array['EndDate'])) {
			$array['TravelDate'] = strtoupper(date('j M', strtotime($array['StartDate'])) . ' - ' . date('j M Y', strtotime($array['EndDate'])));
		} else {
			$array['TravelDate'] = '-';
		}

		// Compute PaxNumber from room management totals; fall back to counting
		// guest_list records (Type = ADULT/CHILD/INFANT) when no rooms exist.
		// Shared with the e-invoice "Remark 3" pax line (see booking_pax_helper).
		$this->CI->load->helper('booking_pax');
		$rooms = $this->CI->Guest_List_Room_Model->Read_Rooms_By_Booking_ID($array['BookingID']);
		$guests = empty($rooms) ? $this->CI->Guest_List_Model->Read_Guests_By_Booking_ID($array['BookingID']) : array();
		// Legacy bookings (pre-Room-Management) have neither rooms nor roster;
		// fall back to the booking's own Adult/Children/Infant columns.
		$pax = booking_pax_counts($rooms, $guests, $array);
		$pax_adult = $pax['ADULT']; $pax_child = $pax['CHILD']; $pax_infant = $pax['INFANT'];
		$adult_str = $pax_adult > 0 ? ($pax_adult == 1 ? $pax_adult . ' ADULT ' : $pax_adult . ' ADULTS ') : '';
		$child_str = $pax_child > 0 ? ($pax_child == 1 ? $pax_child . ' CHILD ' : $pax_child . ' CHILDREN ') : '';
		$infant_str = $pax_infant > 0 ? ($pax_infant == 1 ? $pax_infant . ' INFANT ' : $pax_infant . ' INFANTS ') : '';
		$pax_parts = array_filter(array($adult_str, $child_str, $infant_str));
		$array['PaxNumber'] = !empty($pax_parts) ? implode('& ', $pax_parts) : '0 Pax';

		$raw_booking_insert_date = $array['InsertDate'];
		$array['InsertDate'] = strtoupper(date('j M Y', strtotime($array['InsertDate'])));

		$country_code = $this->CI->Universal_Model->Read_Country_Code($array['SalesAgentCountryCode']);
		$array['SalesAgentMobile'] = $country_code . $array['SalesAgentMobile'];

		// From 2026-06-01 onward the BC shows a "Booking PIC" line for TC1
		// (SalesAgent) and moves "Sales Agent" to TC2 (SalesAgent2).
		$array['ShowBookingPIC'] = pdf_show_booking_pic_for_date($raw_booking_insert_date);
		if (!empty($array['SalesAgent2Name'])) {
			$sales_agent_2_country_code = $this->CI->Universal_Model->Read_Country_Code($array['SalesAgent2CountryCode']);
			$array['SalesAgent2Mobile'] = $sales_agent_2_country_code . $array['SalesAgent2Mobile'];
		} else {
			$array['SalesAgent2Name'] = '';
			$array['SalesAgent2Mobile'] = '';
		}

		$array['Title'] = str_replace(' ', '_', $array['BookingNumber'] . '_' . $array['Customer'] . '_' . $array['TravelDate']);

		$subtotal = explode('.', $array['NetTotal']);
		$ringgit = $this->convert_subtotal($subtotal[0]);

		if (isset($subtotal[1]) && $subtotal[1] != 0) {
			$sen = 'AND CENTS ' . $this->convert_subtotal($subtotal[1]);
		} else {
			$sen = '';
		}

		$negative = $subtotal[0] < 0 ? 'NEGATIVE ' : '';
		$array['Text'] = 'RINGGIT MALAYSIA ' . $negative . $ringgit . '' . $sen . ' ONLY';

		if (empty($array['ProductSequence'])) {
			$array['ProductSequence'] = explode(',', $array['ProductSequence']);
			$array['booking_products'] = $this->CI->Booking_Product_Model->Read();
		} else {
			$array['ProductSequence'] = explode(',', $array['ProductSequence']);
			$booking_products = $this->CI->Booking_Product_Model->Read();
			$array['booking_products'] = [];

			for ($i = 0; $i < count($array['ProductSequence']); $i++) {
				foreach ($booking_products as $booking_product) {
					if ($booking_product->BookingProductID == $array['ProductSequence'][$i]) {
						array_push($array['booking_products'], $booking_product);
					}
				}
			}
		}

		foreach ($array['booking_products'] as $booking_product) {
			$booking_product->Name = (explode(' (' . $booking_product->ProductCode . ')', $booking_product->Name))[0];
		}

		$array['num'] = count($array['booking_products']);

		$company = $this->CI->Company_Model->Read();
		$array['CompanyName'] = $company['Name'];
		$array['CompanyRegistrationNumber'] = $company['RegistrationNumber'];
		$array['CompanyLicenseNumber'] = $company['LicenseNumber'];
		$array['CompanyAddress'] = pdf_company_address_for_date($raw_booking_insert_date);
		$array['CompanyWebsite'] = $company['Website'];

		// Generate customer portal profile URL
		$array['CustomerProfileURL'] = base_url('customer/' . generate_customer_portal_slug($array['CustomerID']));

		// Calculate total paid and outstanding balance
		$total_paid = 0;
		$payments = $this->CI->Payment_Model->Read_Approved_Payments($array['BookingID']);
		if (!empty($payments)) {
			foreach ($payments as $payment) {
				if ($payment->Type != 'SUPPLIER REFUND' && $payment->Type != 'AGENT COMMISSION FROM SUPPLIER' && $payment->Credit > 0) {
					$total_paid += $payment->Credit;
				}
			}
		}
		$array['TotalPaid'] = $total_paid;
		$array['OutstandingBalance'] = $array['NetTotal'] - $total_paid;

		return $array;
	}

	/**
	 * Combine confirmation and footer HTML with page break
	 */
	protected function build_combined_html($array)
	{
		$confirmation_html = $this->CI->load->view('booking/booking_confirmation', $array, true);
		$footer_html = $this->CI->load->view('booking/booking_footer', $array, true);

		// Extract body content from footer page and append with page break
		if (preg_match('/<body[^>]*>(.*)<\/body>/is', $footer_html, $matches)) {
			$footer_body = $matches[1];
			$separator = '<div style="page-break-before: always;"></div>';
			$combined = str_replace('</body></html>', $separator . $footer_body . '</body></html>', $confirmation_html);
		} else {
			$combined = $confirmation_html;
		}

		return $combined;
	}

	/**
	 * Create a DOMPDF instance with the combined HTML
	 */
	protected function create_pdf($array)
	{
		$html = $this->build_combined_html($array);

		$pdf = new Dompdf();
		$pdf->loadHtml($html, 'UTF-8');
		$pdf->set_option('isRemoteEnabled', true);
		$pdf->set_option('enable_html5_parser', true);
		$pdf->setPaper('A4', 'potrait');
		$pdf->render();

		return $pdf;
	}

	/**
	 * Generate PDF and save to file
	 */
	public function generate_to_file($token, $output_path)
	{
		$array = $this->prepare_data($token);
		if (!$array) {
			return false;
		}

		$pdf = $this->create_pdf($array);
		file_put_contents($output_path, $pdf->output());

		return file_exists($output_path);
	}

	/**
	 * Convert number to words
	 */
	protected function convert_subtotal($subtotal)
	{
		if ($subtotal < 0) {
			$subtotal = abs($subtotal);
		}

		if (($subtotal < 0) || ($subtotal > 999999999)) {
			throw new \Exception('Subtotal Is Out Of Range');
		}

		$giga = floor($subtotal / 1000000);
		$subtotal -= $giga * 1000000;
		$kilo = floor($subtotal / 1000);
		$subtotal -= $kilo * 1000;
		$hecto = floor($subtotal / 100);
		$subtotal -= $hecto * 100;
		$deca = floor($subtotal / 10);
		$number = $subtotal % 10;

		$value = '';

		if ($giga) {
			$value .= $this->convert_subtotal($giga) . ' Million';
		}

		if ($kilo) {
			$value .= (empty($value) ? '' : ' ') . $this->convert_subtotal($kilo) . ' Thousand';
		}

		if ($hecto) {
			$value .= (empty($value) ? '' : ' ') . $this->convert_subtotal($hecto) . ' Hundred';
		}

		$ones = array('', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen');
		$tens = array('', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety');

		if ($deca || $number) {
			if (!empty($value)) {
				$value .= ' And ';
			}

			if ($deca < 2) {
				$value .= $ones[$deca * 10 + $number];
			} else {
				$value .= $tens[$deca];
				if ($number) {
					$value .= '-' . $ones[$number];
				}
			}
		}

		if (empty($value)) {
			$value = 'Zero';
		}

		return $value;
	}
}
