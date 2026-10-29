<?php
class Travel_Voucher extends CI_Controller
{
	function __construct()
	{
		parent::__construct();
		$this->load->model('Booking_Model');
        $this->load->model('Universal_Model');
		$this->load->model('Booking_Product_Model');
		$this->load->model('Company_Model');
		$this->load->model('Guest_List_Model');
		$this->load->model('Guest_List_Room_Model');
	}
    
	function index()
	{
        $token = $this->input->get('token');
        $v = $this->input->get('v');
        $vFresh = !empty($v) && ctype_digit((string)$v) && (time() - intval($v)) <= 5;

        if (!empty($token) && !$vFresh) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('Location: ' . base_url('Travel_Voucher?token=' . urlencode($token) . '&v=' . time()), true, 302);
            exit;
        }

        $array = $this->Booking_Model->Booking_Document();
		if(empty($array)) {
			$this->load->view('errors/access_denied');
		} else {
            if(($this->session->has_userdata('admin_id') && $this->session->has_userdata('level')) || ($array['Status'] != 'Y' && $array['AfterSalesService'] != 'COMPLETE' || $array['Status'] == 'Y' && $array['AfterSalesService'] == 'PENDING')) {
                $array['DepositDeadline'] = empty($array['DepositDeadline']) ? '-' : strtoupper(date('j M Y', strtotime($array['DepositDeadline'])));
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
                $adult_str  = $pax_adult  > 0 ? ($pax_adult  == 1 ? $pax_adult  . ' ADULT '  : $pax_adult  . ' ADULTS '  ) : '';
                $child_str  = $pax_child  > 0 ? ($pax_child  == 1 ? $pax_child  . ' CHILD '  : $pax_child  . ' CHILDREN ') : '';
                $infant_str = $pax_infant > 0 ? ($pax_infant == 1 ? $pax_infant . ' INFANT ' : $pax_infant . ' INFANTS ' ) : '';
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

                // Booking PIC line: mirrors the BC. Ticked agents print; unset
                // ticks fall back to the 2026-06-01 date rule (Sales Agent 1 only).
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
                foreach($array['booking_products'] as $booking_product) {
                    $booking_product->Name = (explode(' (' . $booking_product->ProductCode . ')', $booking_product->Name))[0];
                }
                $company = $this->Company_Model->Read();
                $array['CompanyName'] = $company['Name'];
                $array['CompanyRegistrationNumber'] = $company['RegistrationNumber'];
                $array['CompanyLicenseNumber'] = $company['LicenseNumber'];
                $array['CompanyAddress'] = pdf_company_address_for_date($RawBookingInsertDate);
                $array['CompanyWebsite'] = $company['Website'];

                // Get guest list data
                $bookingID = $array['BookingID'];
                $this->load->model('Guest_List_Model');
                $this->Guest_List_Model->Auto_Assign_Rooms($bookingID);
                $this->db->select('guest_list.Name As GuestFirstName, guest_list.LastName As GuestLastName, guest_list.Gender, DateOfBirth, guest_list.IdentificationNumber, guest_list.PassportNumber, Type, guest_list_room.room_name As RoomName');
                $this->db->join('guest_list_room', 'guest_list_room.id = guest_list.guest_list_room_id', 'left');
                $this->db->where('guest_list.BookingID', $bookingID);
                $this->db->where('guest_list.Status', 'Y');
                // Arrange by room: assigned rooms first (natural sorted), unassigned last,
                // then by Type (ADULT first) and Name within each room.
                $this->db->order_by('guest_list_room.room_name IS NULL', 'ASC', FALSE);
                $this->db->order_by('LENGTH(guest_list_room.room_name)', 'ASC', FALSE);
                $this->db->order_by('guest_list_room.room_name', 'ASC');
                $this->db->order_by("FIELD(Type, 'ADULT', 'CHILD', 'INFANT')", 'ASC', FALSE);
                $this->db->order_by('guest_list.Name', 'ASC');
                $array['guest_lists'] = $this->db->get('guest_list')->result();
                
                $array['CustomerProfileURL'] = base_url('customer/' . generate_customer_portal_slug($array['CustomerID']));

                // Inline locally-uploaded images so DomPDF doesn't have to fetch
                // them back over HTTP (loopback often fails on Herd / self-signed).
                $this->load->helper('voucher_image');
                $array['TravelVoucherFooter']         = inline_voucher_images_html(sanitize_voucher_content_html(isset($array['TravelVoucherFooter']) ? $array['TravelVoucherFooter'] : ''));
                $array['TravelVoucherKeyContacts']    = inline_voucher_images_html(sanitize_voucher_content_html(isset($array['TravelVoucherKeyContacts']) ? $array['TravelVoucherKeyContacts'] : ''));
                $array['TravelVoucherSpecialRemarks'] = inline_voucher_images_html(sanitize_voucher_content_html(isset($array['TravelVoucherSpecialRemarks']) ? $array['TravelVoucherSpecialRemarks'] : ''));

                $this->load->library('pdf');
                $this->dompdf->loadHtml($this->load->view('booking/travel_voucher', $array, true));
                $this->dompdf->set_option('isRemoteEnabled', true);
                $this->dompdf->setPaper('A4', 'potrait');
                $this->dompdf->render();
                $pdfOutput = $this->dompdf->output();

                if (ob_get_length()) { ob_end_clean(); }

                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="' . $array['Title'] . '.pdf"');
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
                header('Pragma: no-cache');
                header('Expires: 0');
                header('Content-Length: ' . strlen($pdfOutput));
                echo $pdfOutput;
                exit;
            } else {
                $array = array('type' => 'Travel Voucher');
                $this->load->view('errors/bc_complete', $array);
            }
		}
    }
}