<?php



require_once APPPATH.'libraries/dompdf/autoload.inc.php';

require FCPATH.'vendor/autoload.php';  

use Dompdf\Dompdf;



class Booking_Confirmation extends CI_Controller

{

	function __construct()

	{

		parent::__construct();

		$this->load->model('Booking_Model');

        $this->load->model('Universal_Model');

		$this->load->model('Booking_Product_Model');

		$this->load->model('Company_Model');

		$this->load->model('Payment_Model');
		$this->load->model('Guest_List_Room_Model');
		$this->load->model('Guest_List_Model');
	}



    function index()

	{

        $token = $this->input->get('token');
        $v = $this->input->get('v');
        $vFresh = !empty($v) && ctype_digit((string)$v) && (time() - intval($v)) <= 5;

        if (!empty($token) && !$vFresh && empty($_GET['nick'])) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('Location: ' . base_url('Booking_Confirmation?token=' . urlencode($token) . '&v=' . time()), true, 302);
            exit;
        }

        $identifier = floor(microtime(true) * 1000);



        $array = $this->Booking_Model->Booking_Document();

        // Defensive: strip any " - <CustomerCode>" suffix that may have leaked into booking.Customer
        // (the booking form appends it visually; a JS pre-submit handler normally trims it before save,
        // but the BC PDF must never expose the code regardless).
        if (!empty($array) && !empty($array['CustomerID']) && !empty($array['Customer'])) {
            $code_row = $this->db->select('CustomerCode')
                ->where('CustomerID', $array['CustomerID'])
                ->get('customer')
                ->row_array();
            if (!empty($code_row['CustomerCode'])) {
                $suffix = ' - ' . $code_row['CustomerCode'];
                $cust   = $array['Customer'];
                if (substr($cust, -strlen($suffix)) === $suffix) {
                    $array['Customer'] = substr($cust, 0, -strlen($suffix));
                }
            }
        }


		if(empty($array)) {

			$this->load->view('errors/access_denied');

		} else {

            if(($this->session->has_userdata('admin_id') && $this->session->has_userdata('level')) || ($array['Status'] != 'Y' && $array['AfterSalesService'] != 'COMPLETE' || $array['Status'] == 'Y' && $array['AfterSalesService'] == 'PENDING')) {

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

                if (!empty($array['CustomerMobile2'])) {
                    $array['CustomerMobile2'] = (!empty($array['CountryCode2']) ? $array['CountryCode2'] : '') . $array['CustomerMobile2'];
                }

                if(!empty($array['StartDate']) && !empty($array['EndDate'])) {

                    $array['TravelDate'] = strtoupper(date('j M', strtotime($array['StartDate'])) . ' - ' . date('j M Y', strtotime($array['EndDate'])));

                } else {

                    $array['TravelDate'] = '-';

                }

                $pax = $this->Booking_Model->Compute_Pax_Counts($array['BookingID']);
                $pax_adult = $pax['adult']; $pax_child = $pax['child']; $pax_infant = $pax['infant'];
                $adult_str = $pax_adult > 0 ? ($pax_adult == 1 ? $pax_adult . ' ADULT ' : $pax_adult . ' ADULTS ') : '';
                $child_str = $pax_child > 0 ? ($pax_child == 1 ? $pax_child . ' CHILD ' : $pax_child . ' CHILDREN ') : '';
                $infant_str = $pax_infant > 0 ? ($pax_infant == 1 ? $pax_infant . ' INFANT ' : $pax_infant . ' INFANTS ') : '';
                $pax_parts = array_filter(array($adult_str, $child_str, $infant_str));
                $array['PaxNumber'] = !empty($pax_parts) ? implode('& ', $pax_parts) : '0 Pax';

                $RawBookingInsertDate = $array['InsertDate'];
                $array['InsertDate'] = strtoupper(date('j M Y', strtotime($array['InsertDate'])));

                $country_code = $this->Universal_Model->Read_Country_Code($array['SalesAgentCountryCode']);

                $array['SalesAgentMobile'] = $country_code . $array['SalesAgentMobile'];

                if (!empty($array['SalesAgent2Name'])) {
                    $sales_agent_2_country_code = $this->Universal_Model->Read_Country_Code($array['SalesAgent2CountryCode']);
                    $array['SalesAgent2Mobile'] = $sales_agent_2_country_code . $array['SalesAgent2Mobile'];
                } else {
                    $array['SalesAgent2Name'] = '';
                    $array['SalesAgent2Mobile'] = '';
                }

                // Booking PIC line: staff tick Sales Agent 1 / 2 on the form.
                // Ticked agents print; unset ticks fall back to the 2026-06-01
                // date rule (Sales Agent 1 only).
                $pic = booking_pic_display(array(
                    'sales_agent_is_pic'   => isset($array['SalesAgentIsPIC'])   ? $array['SalesAgentIsPIC']   : null,
                    'sales_agent_2_is_pic' => isset($array['SalesAgent2IsPIC']) ? $array['SalesAgent2IsPIC'] : null,
                    'sales_agent_name'     => $array['SalesAgentName'],
                    'sales_agent_mobile'   => $array['SalesAgentMobile'],
                    'sales_agent_2_name'   => $array['SalesAgent2Name'],
                    'sales_agent_2_mobile' => $array['SalesAgent2Mobile'],
                    'insert_date'          => $RawBookingInsertDate,
                ));
                $array['PICLabel'] = $pic['label'];
                $array['PICText']  = $pic['text'];

                $array['Title'] = str_replace(' ', '_', $array['BookingNumber'] . '_' . $array['Customer'] . '_' . $array['TravelDate']);

                $subtotal = explode('.', $array['NetTotal']);

                $ringgit = $this->Convert_Subtotal($subtotal[0]);

                if(isset($subtotal[1]) && $subtotal[1] != 0) {

                    $sen = 'AND CENTS ' . $this->Convert_Subtotal($subtotal[1]);

                } else {

                    $sen = '';

                }

                $negative = $subtotal[0] < 0 ? 'NEGATIVE ' : '';

                $array['Text'] = 'RINGGIT MALAYSIA ' . $negative . $ringgit . '' . $sen . ' ONLY';

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



                    if(isset($_GET['nick'])) {

                        echo "<pre>";

                        print_r($array['ProductSequence']);exit;

                    }

                }

                

                foreach($array['booking_products'] as $booking_product) {

                    $booking_product->Name = (explode(' (' . $booking_product->ProductCode . ')', $booking_product->Name))[0];

                }

                $array['num'] = count($array['booking_products']);

                $company = $this->Company_Model->Read();

                $array['CompanyName'] = $company['Name'];

                $array['CompanyRegistrationNumber'] = $company['RegistrationNumber'];

                $array['CompanyLicenseNumber'] = $company['LicenseNumber'];

                $array['CompanyAddress'] = pdf_company_address_for_date($RawBookingInsertDate);

                $array['CompanyWebsite'] = $company['Website'];

                // Generate customer portal profile URL
                $array['CustomerProfileURL'] = base_url('customer/' . generate_customer_portal_slug($array['CustomerID']));

                // Calculate total paid and outstanding balance - start
                $total_paid = 0;
                $payments = $this->Payment_Model->Read_Approved_Payments($array['BookingID']);
                if(!empty($payments)) {
                    foreach($payments as $payment) {
                        if($payment->Type != 'SUPPLIER REFUND' && $payment->Type != 'AGENT COMMISSION FROM SUPPLIER' && $payment->Credit > 0) {
                            $total_paid += $payment->Credit;
                        }
                    }
                }
                $array['TotalPaid'] = $total_paid;
                $array['OutstandingBalance'] = $array['NetTotal'] - $total_paid;
                // Calculate total paid and outstanding balance - end

                if(isset($_GET['nick'])) {

                    $this->load->view('booking/booking_confirmation', $array); exit;

                }

                // Inline locally-uploaded TinyMCE images so DomPDF doesn't have
                // to fetch them back over HTTP (loopback often fails on Herd /
                // self-signed HTTPS).
                $this->load->helper('voucher_image');
                $array['BookingConfirmationFooter']   = inline_voucher_images_html(sanitize_voucher_content_html(isset($array['BookingConfirmationFooter']) ? $array['BookingConfirmationFooter'] : ''));
                $array['TravelVoucherFooter']         = inline_voucher_images_html(sanitize_voucher_content_html(isset($array['TravelVoucherFooter']) ? $array['TravelVoucherFooter'] : ''));
                $array['TravelVoucherKeyContacts']    = inline_voucher_images_html(sanitize_voucher_content_html(isset($array['TravelVoucherKeyContacts']) ? $array['TravelVoucherKeyContacts'] : ''));
                $array['TravelVoucherSpecialRemarks'] = inline_voucher_images_html(sanitize_voucher_content_html(isset($array['TravelVoucherSpecialRemarks']) ? $array['TravelVoucherSpecialRemarks'] : ''));

                $pdf1 = new Dompdf();

                $pdf1->loadHtml($this->load->view('booking/booking_confirmation', $array, true), 'UTF-8');

                $pdf1->set_option('isRemoteEnabled', true);

                $pdf1->set_option('enable_html5_parser', true);

                $pdf1->setPaper('A4', 'potrait');

                $pdf1->render();

                $output = $pdf1->output();

                file_put_contents('assets/upload/1_'.$identifier.'.pdf', $output);

                // -- footer -- //

                $pdf2 = new Dompdf();

                $pdf2->loadHtml($this->load->view('booking/booking_footer', $array, true), 'UTF-8');

                $pdf2->set_option('isRemoteEnabled', true);

                $pdf2->set_option('enable_html5_parser', true);

                $pdf2->setPaper('A4', 'potrait');

                $pdf2->render();

                $output2 = $pdf2->output();

                file_put_contents('assets/upload/2_'.$identifier.'.pdf', $output2);

                $merger = new \setasign\Fpdi\Fpdi();

                $pageCount1 = $merger->setSourceFile('assets/upload/1_'.$identifier.'.pdf');
                for ($i = 1; $i <= $pageCount1; $i++) {
                    $tpl = $merger->importPage($i);
                    $size = $merger->getTemplateSize($tpl);
                    $merger->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $merger->useTemplate($tpl);
                }

                $pageCount2 = $merger->setSourceFile('assets/upload/2_'.$identifier.'.pdf');
                for ($i = 1; $i <= $pageCount2; $i++) {
                    $tpl = $merger->importPage($i);
                    $size = $merger->getTemplateSize($tpl);
                    $merger->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $merger->useTemplate($tpl);
                }

                $pdfBuffer = $merger->Output('S', $array['Title'].'3.pdf');

                unlink('assets/upload/1_'.$identifier.'.pdf');

                unlink('assets/upload/2_'.$identifier.'.pdf');

                if (ob_get_length()) { ob_end_clean(); }

                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="'.$array['Title'].'3.pdf"');
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
                header('Pragma: no-cache');
                header('Expires: 0');
                header('Content-Length: '.strlen($pdfBuffer));

                echo $pdfBuffer;
                exit;

                

                // $this->dompdf->stream('assets/upload/booking/'.$array['Title'] . '.pdf', array('Attachment' => 0));

            } else {

                $array = array('type' => 'Booking Confirmation');

                $this->load->view('errors/bc_complete', $array);

            }

		}

    }

    

	function Convert_Subtotal($subtotal) 

    {

        if($subtotal < 0) {

            $negative = true;

            $subtotal = abs($subtotal);

        } else {

            $negative = false;

        }



        if(($subtotal < 0) || ($subtotal > 999999999)) {

            throw new Exception('Subtotal Is Out Of Range');

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

        if($giga) {

            $value .= $this->Convert_Subtotal($giga) . ' Million';

        }

        if($kilo) {

            $value .= (empty($value) ? '' : ' ') . $this->Convert_Subtotal($kilo) . ' Thousand';

        }

        if($hecto) {

            $value .= (empty($value) ? '' : ' ') . $this->Convert_Subtotal($hecto) . ' Hundred';

        }

        $ones = array('', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen');

        $tens = array('', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety');

        if($deca || $number) {

            if(!empty($value)) {

                $value .= ' And ';

            }

            if($deca < 2) {

                $value .= $ones[$deca * 10 + $number];

            } else {

                $value .= $tens[$deca];

                if($number) {

                    $value .= '-' . $ones[$number];

                }

            }

        }

        if(empty($value)) {

            $value = 'Zero';

        }

        return $value;

    }

}