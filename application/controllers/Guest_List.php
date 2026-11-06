<?php

require FCPATH.'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlxs;

class Guest_List extends CI_Controller
{
	function __construct()
	{
		$env = [];
		if (file_exists(FCPATH . '.env')) {
			$lines = file(FCPATH . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			foreach ($lines as $line) {
				if (strpos(trim($line), '#') === 0) continue;
				list($key, $value) = explode('=', $line, 2);
				$env[trim($key)] = trim($value);
			}
		}
		$baseUrl = $env['BASE_URL'];
		$domain = parse_url($baseUrl, PHP_URL_HOST);

		parent::__construct();
		if(empty($this->session->userdata('admin_id')) && $_SERVER['SERVER_NAME'] != $domain) {
			redirect($baseUrl . '?gl=' . $this->input->get('gl'));
		}
		$this->load->model('Guest_List_Model');
		$this->load->model('Booking_Model');
		$this->load->model('Universal_Model');
		$this->load->model('Guest_list_lock_model');
		$this->load->model('Guest_List_Room_Model');
		$this->load->helper('booking_flow');
	}

	function index()
	{
		if($this->input->post()) {
			$truncation_error = $this->detect_truncated_post();
			if ($truncation_error !== null) {
				log_message('error', 'Guest_List submit truncated: ' . $truncation_error
					. ' | content_length=' . (isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : '?')
					. ' | post_count=' . count($_POST, COUNT_RECURSIVE)
					. ' | max_input_vars=' . ini_get('max_input_vars'));
			$this->session->set_flashdata('error', $truncation_error);
				redirect(base_url($_SERVER['REQUEST_URI']));
				return;
			}
			$booking_id = $this->Guest_List_Model->Read_Booking_ID();
			// Handle passport copy file uploads for existing guests
			$passport_copy_paths = $this->handle_passport_uploads('passport_copies', $booking_id);

			// Handle passport copy file uploads for new guests
			$new_passport_copy_paths = $this->handle_passport_uploads('new_passport_copies', $booking_id);
			
			// Set uploaded file paths in POST data - ensure array indices match guest indices
			if (!empty($this->input->post('guests'))) {
				// Get the number of guests being updated
				$guest_count = count($this->input->post('guests'));
				$passport_copies_array = array();
				for ($i = 0; $i < $guest_count; $i++) {
					// Check if new file was uploaded for this guest
					if (isset($passport_copy_paths[$i]) && !empty($passport_copy_paths[$i])) {
						$passport_copies_array[$i] = $passport_copy_paths[$i];
					} 
					// Otherwise, keep existing file if available
					elseif (!empty($this->input->post('existing_passport_copies')) && isset($this->input->post('existing_passport_copies')[$i]) && !empty($this->input->post('existing_passport_copies')[$i])) {
						$passport_copies_array[$i] = $this->input->post('existing_passport_copies')[$i];
					}
					// If neither exists, set to empty string (will be saved as null in model)
					else {
						$passport_copies_array[$i] = '';
					}
				}
				$_POST['passport_copies'] = $passport_copies_array;
			}
			
			// Handle new guests passport copies
			if (!empty($new_passport_copy_paths)) {
				$_POST['new_passport_copies'] = $new_passport_copy_paths;
			}
			
			if($this->Guest_List_Model->Update(false, $booking_id) || !empty($this->input->post('new_guests')) || !empty($this->input->post('deleted_guests'))) {
				if(!empty($this->input->post('new_guests'))) {
					$this->Guest_List_Model->Create_Guest($booking_id);
				}
				if(!empty($this->input->post('deleted_guests'))) {
					$deleted_guests = explode(',', $this->input->post('deleted_guests'));
					for($i = 0; $i < count($deleted_guests); $i++) {
						$this->Guest_List_Model->Delete($deleted_guests[$i]);
					}
				}
				$bookingInfo = get_object_vars($this->Booking_Model->find($booking_id));
				if (!empty($bookingInfo)) {
					if (($bookingInfo['AutocountSyncAction'] == 'C' || $bookingInfo['AutocountSyncAction'] == 'U') && $bookingInfo['AutocountSyncStatus'] == 'S') {
							$this->Booking_Model->update_by_id($booking_id, [
							'AutocountSyncAction' => 'U',
							'AutocountSyncStatus' => 'P'
						]);				
					} else {
						$this->Booking_Model->update_by_id($booking_id, [
							'AutocountSyncStatus' => 'P'
						]);
					}
				}
			}
			// Auto-detect if all guests have complete information and auto-lock
			if ($this->Guest_List_Model->Are_All_Guests_Complete($booking_id)) {
				$this->Booking_Model->update_by_id($booking_id, [
					'LockStatus' => 'Y',
					'is_submitted' => 1
				]);
			} else {
				// Not all guests complete - ensure not marked as submitted
				$this->Booking_Model->update_by_id($booking_id, [
					'is_submitted' => 0
				]);
			}

			$this->Guest_List_Model->Update_GL_Session('BookingID', $booking_id, 'N', null);
			redirect('Message?url=' . base_url($_SERVER['REQUEST_URI']));
		} else {
			if(!empty($this->input->get('gl'))) {
				$gl_hash = $this->input->get('gl');
				
				// Check lock status first (server-side check before showing form)
				$lock = $this->Guest_list_lock_model->getByHash($gl_hash);
				$userId = $this->session->userdata('admin_id') ? $this->session->userdata('admin_id') : null;
				
				$isLockedByOther = false;
				if (!empty($lock)) {
					$isExpired = $this->Guest_list_lock_model->isExpired($lock);
					
					// For logged-in users: check by user_id
					if ($userId && $lock->lock_owner_type === 'user') {
						$isSameOwner = ($lock->lock_owner_id == $userId);
					} else {
						// For guests: we can't check token here (it's in sessionStorage)
						// So we'll let JS handle it, but if lock is active and user is logged in
						// and lock is owned by guest, or vice versa, it's different owner
						if ($userId && $lock->lock_owner_type === 'guest') {
							$isSameOwner = false; // Logged-in user vs guest = different
						} elseif (!$userId && $lock->lock_owner_type === 'user') {
							$isSameOwner = false; // Guest vs logged-in user = different
						} else {
							// Both guests - can't determine without token, let JS handle
							$isSameOwner = null; // Unknown, let JS check
						}
					}
					
					// Locked by another user if: lock exists, is active (not expired), and definitely not same owner
					if (!$isExpired && $isSameOwner === false) {
						$isLockedByOther = true;
					}
				}
				
				// If locked by another user, show minimal locked view (no form, no booking info)
				if ($isLockedByOther) {
					$lock_status = $this->Guest_list_lock_model->getStatus($gl_hash);
					$array = array(
						'locked' => true,
						'expires_at' => isset($lock_status['lock_expires_at']) ? $lock_status['lock_expires_at'] : null
					);
					$this->load->view('booking/guest_list_locked', $array);
					return;
				}
				
				// Sync GL entries from room data and auto-assign
				$auto_assign_booking_id = $this->Guest_List_Model->Read_Booking_ID();
				if(!empty($auto_assign_booking_id)) {
					$this->Booking_Model->Sync_GL_From_Rooms($auto_assign_booking_id);
				}

	    		$array['guest_lists'] = $this->Guest_List_Model->Read_Guest_Lists1();
	    		if(!empty($array['guest_lists'])) {
					if (is_sa_blocked_from_completed_booking($this->session->userdata('level'), $array['guest_lists'][0]->Status, $array['guest_lists'][0]->AfterSalesService)) {
						$this->load->view('errors/bc_complete', array('type' => 'Guest List'));
						return;
					}
					if(($this->session->has_userdata('admin_id') && $this->session->has_userdata('level')) || ($array['guest_lists'][0]->Status != 'Y' && $array['guest_lists'][0]->AfterSalesService != 'COMPLETE' || $array['guest_lists'][0]->Status == 'Y' && $array['guest_lists'][0]->AfterSalesService == 'PENDING')) {
						if(true) {
						//if(($array['guest_lists'][0]->GLSessionLock == 'N' && empty($array['guest_lists'][0]->GLSessionExpiration)) || ($array['guest_lists'][0]->GLSessionLock == 'Y' && date('Y-m-d H:i:s') > $array['guest_lists'][0]->GLSessionExpiration)) {
							if($array['guest_lists'][0]->LockStatus == 'N' && ($this->agent->is_browser() || $this->agent->is_mobile())) {
								$this->Guest_List_Model->Create_Guest_List_Log($array['guest_lists'][0]->BookingID);
								$this->Guest_List_Model->Update_GL_Session('BookingNumber', $array['guest_lists'][0]->BookingNumber, 'Y', date('Y-m-d H:i:s', strtotime('+ 10 minutes')));
							}
							foreach($array['guest_lists'] as $guest) {
								if(!empty($guest->DateOfBirth)) {
									$guest->DateOfBirth = date('d/m/Y', strtotime($guest->DateOfBirth));
								}
								if(!empty($guest->Nationality)) {
									$guest->NationalityName = $this->Universal_Model->Read_Country($guest->Nationality);
								} else {
									$guest->NationalityName = null;
								}
							}
							$array['guest_lists'][0]->CustomerMobile = $array['guest_lists'][0]->CountryCode . $array['guest_lists'][0]->CustomerMobile;
							$country_code = $this->Universal_Model->Read_Country_Code($array['guest_lists'][0]->SalesAgentCountryCode);
							$array['guest_lists'][0]->SalesAgentMobile = $country_code . $array['guest_lists'][0]->SalesAgentMobile;
							if (!empty($array['guest_lists'][0]->SalesAgent2)) {
								$sales_agent_2_country_code = $this->Universal_Model->Read_Country_Code($array['guest_lists'][0]->SalesAgent2CountryCode);
								$array['guest_lists'][0]->SalesAgent2Mobile = $sales_agent_2_country_code . $array['guest_lists'][0]->SalesAgent2Mobile;
							}
							// Booking PIC line: mirrors the BC / Travel Voucher. Ticked agents
							// print (both can show); unset ticks fall back to the 2026-06-01
							// date rule (Sales Agent 1 only).
							$gl_pic = booking_pic_display(array(
								'sales_agent_is_pic'   => $array['guest_lists'][0]->SalesAgentIsPIC,
								'sales_agent_2_is_pic' => $array['guest_lists'][0]->SalesAgent2IsPIC,
								'sales_agent_name'     => $array['guest_lists'][0]->SalesAgent,
								'sales_agent_mobile'   => $array['guest_lists'][0]->SalesAgentMobile,
								'sales_agent_2_name'   => $array['guest_lists'][0]->SalesAgent2,
								'sales_agent_2_mobile' => $array['guest_lists'][0]->SalesAgent2Mobile,
								'insert_date'          => $array['guest_lists'][0]->InsertDate,
							));
							$array['guest_lists'][0]->PICLabel = $gl_pic['label'];
							$array['guest_lists'][0]->PICText  = $gl_pic['text'];
							$array['country_codes'] = $this->Guest_List_Model->Read_Country_Codes();

							// Get booking products with category country
							$this->db->select('booking_product.ProductID, product.CategoryID, category.Country As CategoryCountry, country_code.Country As CategoryCountryName');
							$this->db->from('booking_product');
							$this->db->join('product', 'product.ProductID = booking_product.ProductID', 'left');
							$this->db->join('category', 'category.CategoryID = product.CategoryID', 'left');
							$this->db->join('country_code', 'country_code.CountryCodeID = category.Country', 'left');
							$this->db->where('booking_product.BookingID', $array['guest_lists'][0]->BookingID);
							$this->db->where('booking_product.Status', 'Y');
							$array['booking_products'] = $this->db->get()->result();

							if(!empty($array['guest_lists'][0]->StartDate) && !empty($array['guest_lists'][0]->EndDate)) {
								$array['guest_lists'][0]->TravelDate = strtoupper(date('j M', strtotime($array['guest_lists'][0]->StartDate)) . ' - ' . date('j M Y', strtotime($array['guest_lists'][0]->EndDate)));
							} else {
								$array['guest_lists'][0]->TravelDate = '-';
							}

							// Compute PaxNumber from room management totals; fall back to counting
							// guest_list records (Type = ADULT/CHILD/INFANT) when no rooms exist.
							$rooms_for_pax = $this->Guest_List_Room_Model->Read_Rooms_By_Booking_ID($array['guest_lists'][0]->BookingID);
							if (!empty($rooms_for_pax)) {
								$pax_adult = 0; $pax_child = 0; $pax_infant = 0;
								foreach ($rooms_for_pax as $r) {
									$pax_adult += (int)$r->adult_count;
									$pax_child += (int)$r->child_count;
									$pax_infant += (int)$r->infant_count;
								}
							} else {
								$pax_adult = 0; $pax_child = 0; $pax_infant = 0;
								$guests_for_pax = $this->Guest_List_Model->Read_Guests_By_Booking_ID($array['guest_lists'][0]->BookingID);
								foreach ($guests_for_pax as $g) {
									if ($g->Type == 'ADULT') { $pax_adult++; }
									elseif ($g->Type == 'CHILD') { $pax_child++; }
									elseif ($g->Type == 'INFANT') { $pax_infant++; }
								}
							}
							$adult_str = $pax_adult > 0 ? ($pax_adult == 1 ? $pax_adult . ' ADULT ' : $pax_adult . ' ADULTS ') : '';
							$child_str = $pax_child > 0 ? ($pax_child == 1 ? $pax_child . ' CHILD ' : $pax_child . ' CHILDREN ') : '';
							$infant_str = $pax_infant > 0 ? ($pax_infant == 1 ? $pax_infant . ' INFANT ' : $pax_infant . ' INFANTS ') : '';
							$pax_parts = array_filter(array($adult_str, $child_str, $infant_str));
							$array['guest_lists'][0]->PaxNumber = !empty($pax_parts) ? implode('& ', $pax_parts) : '0 Pax';

							// Destination country = the booking's destination category country
							// (NOT the first product, which may be a Malaysia-tagged add-on such as
							// a flight ticket or insurance and would mislabel an overseas trip).
							$destination_country = null;
							$destination_country_name = null;
							$this->db->select('category.Country As CategoryCountry, country_code.Country As CategoryCountryName');
							$this->db->from('booking');
							$this->db->join('category', 'category.CategoryID = booking.Destination', 'left');
							$this->db->join('country_code', 'country_code.CountryCodeID = category.Country', 'left');
							$this->db->where('booking.BookingID', $array['guest_lists'][0]->BookingID);
							$dest_row = $this->db->get()->row();
							if($dest_row && !empty($dest_row->CategoryCountry)) {
								$destination_country = $dest_row->CategoryCountry;
								$destination_country_name = strtoupper($dest_row->CategoryCountryName);
							}
							$array['destination_country'] = $destination_country;
							$array['destination_country_name'] = $destination_country_name;
							
							// Load rooms for this booking
							$array['rooms'] = $this->Guest_List_Room_Model->Read_Rooms_By_Booking_ID($array['guest_lists'][0]->BookingID);

							header('Cache-Control: no-cache, no-store, must-revalidate');
							header('Pragma: no-cache');
							header('Expires: 0');
							$this->load->view('booking/guest_list', $array);
						} else {							
							$array = $this->Guest_List_Model->Read_GL_Session_Expiration();
							$this->load->view('booking/access_denied', $array);
						}
					} else {
						$array = array('type' => 'Guest List');
                		$this->load->view('errors/bc_complete', $array);
					}
	    		} else {
					$this->load->view('errors/access_denied');
				}
	    	} else {
	    		$this->load->view('errors/access_denied');
	    	}
		}
    }

	function Update_GL_Session_Expiration() {
		$this->Guest_List_Model->Update_GL_Session('BookingID', $this->input->post('booking_id'), 'Y', date('Y-m-d H:i:s', strtotime('+ 12 minutes')));
	}
	
	function Populate_Form_Data() {
		$array = $this->Guest_List_Model->Read_Guest_Lists2();
		foreach($array as $guest) {
			if(!empty($guest->DateOfBirth)) {
				$guest->DateOfBirth = date('d/m/Y', strtotime($guest->DateOfBirth));
			}
		}
		if(empty($array[0]->Adult)) {
			$array[0]->Adult = 0;
		}
		if(empty($array[0]->Children)) {
			$array[0]->Children = 0;
		}
		if(empty($array[0]->Infant)) {
			$array[0]->Infant = 0;
		}
		echo json_encode($array);
	}
	
	function Download() {
		if (nav_block_data_export('Guest_List')) { return; }
		$booking_id = $this->input->get('booking_id');
		if (!empty($booking_id)) {
			$booking_row = $this->Booking_Model->find($booking_id);
			if ($booking_row && is_sa_blocked_from_completed_booking($this->session->userdata('level'), $booking_row->Status, $booking_row->AfterSalesService)) {
				$this->load->view('errors/access_denied');
				return;
			}
		}
		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$spreadsheet->getActiveSheet()->setTitle('Guest Lists');
		$spreadsheet->getProperties()->setCreator('HolidayGoGoGo');
		$spreadsheet->getActiveSheet()->setCellValue('A1', 'BOOKING NUMBER');
		$spreadsheet->getActiveSheet()->setCellValue('B1', 'RESERVATION NUMBER');
		$spreadsheet->getActiveSheet()->setCellValue('C1', 'CUSTOMER FIRST NAME');
		$spreadsheet->getActiveSheet()->setCellValue('D1', 'CUSTOMER LAST NAME');
		$spreadsheet->getActiveSheet()->setCellValue('E1', 'CUSTOMER MOBILE');
		$spreadsheet->getActiveSheet()->setCellValue('F1', 'CHAT LANGUAGE');
		$spreadsheet->getActiveSheet()->setCellValue('G1', 'TRAVEL DATE');
		$spreadsheet->getActiveSheet()->setCellValue('H1', 'DESTINATION');
		$spreadsheet->getActiveSheet()->setCellValue('I1', 'BOOKING PIC');
		$spreadsheet->getActiveSheet()->setCellValue('J1', 'GUEST TYPE');
		$spreadsheet->getActiveSheet()->setCellValue('K1', 'GUEST');
		$spreadsheet->getActiveSheet()->setCellValue('L1', 'GENDER');
		$spreadsheet->getActiveSheet()->setCellValue('M1', 'DATE OF BIRTH');
		$spreadsheet->getActiveSheet()->setCellValue('N1', 'NATIONALITY');
		$spreadsheet->getActiveSheet()->setCellValue('O1', 'GUEST IDENTIFICATION NUMBER');
		$spreadsheet->getActiveSheet()->setCellValue('P1', 'PASSPORT NUMBER');
		$spreadsheet->getActiveSheet()->setCellValue('Q1', 'PASSPORT ISSUE DATE');
		$spreadsheet->getActiveSheet()->setCellValue('R1', 'PASSPORT EXPIRY DATE');
		$spreadsheet->getActiveSheet()->setCellValue('S1', 'PASSPORT COPY');
		$spreadsheet->getActiveSheet()->setCellValue('T1', 'DIETARY REQUIREMENT');
		$spreadsheet->getActiveSheet()->setCellValue('U1', 'GUEST MOBILE');
		$spreadsheet->getActiveSheet()->setCellValue('V1', 'EMAIL');
		$spreadsheet->getActiveSheet()->setCellValue('W1', 'MARITAL STATUS');
		$spreadsheet->getActiveSheet()->setCellValue('X1', 'EMPLOYMENT');
		$spreadsheet->getActiveSheet()->setCellValue('Y1', 'ADDRESS');
		$spreadsheet->getActiveSheet()->setCellValue('Z1', 'POSTCODE');
		$spreadsheet->getActiveSheet()->setCellValue('AA1', 'CITY');
		$spreadsheet->getActiveSheet()->setCellValue('AB1', 'STATE');
		$spreadsheet->getActiveSheet()->setCellValue('AC1', 'COUNTRY');
		$spreadsheet->getActiveSheet()->setCellValue('AD1', 'NOMINEE');
		$spreadsheet->getActiveSheet()->setCellValue('AE1', 'NOMINEE CONTACT NUMBER');
		$spreadsheet->getActiveSheet()->setCellValue('AF1', 'NOMINEE IDENTIFICATION NUMBER');
		$spreadsheet->getActiveSheet()->setCellValue('AG1', 'RELATIONSHIP');
		$spreadsheet->getActiveSheet()->setCellValue('AH1', 'ROOM');
		$row = 2;
		$guest_lists = $this->Guest_List_Model->Read_Guest_Lists1();
		$spreadsheet->getActiveSheet()->getStyle('A1:AH1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
		$spreadsheet->getActiveSheet()->getStyle('A1:AH1')->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE);
		$spreadsheet->getActiveSheet()->getStyle('A1:AH1')->getFont()->setBold(true);
		foreach($guest_lists as $guest) {
			$guest->CustomerMobile = $guest->CountryCode . $guest->CustomerMobile;
			if(!empty($guest->StartDate) && !empty($guest->EndDate)) {
				$guest->TravelDate = strtoupper(date('j M', strtotime($guest->StartDate)) . ' - ' . date('j M Y', strtotime($guest->EndDate)));
			} else {
				$guest->TravelDate = null;
			}
			if(!empty($guest->DateOfBirth)) {
				$guest->DateOfBirth = strtoupper(date('j M Y', strtotime($guest->DateOfBirth)));
			}
			if(!empty($guest->PassportIssueDate)) {
				$guest->PassportIssueDate = strtoupper(date('j M Y', strtotime($guest->PassportIssueDate)));
			} else {
				$guest->PassportIssueDate = null;
			}
			if(!empty($guest->PassportExpiryDate)) {
				$guest->PassportExpiryDate = strtoupper(date('j M Y', strtotime($guest->PassportExpiryDate)));
			} else {
				$guest->PassportExpiryDate = null;
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
			$spreadsheet->getActiveSheet()->setCellValueExplicit('B' . $row, $guest->ReservationNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('C' . $row, $guest->Guest, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('D' . $row, $guest->GuestLastName, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('E' . $row, $guest->CustomerMobile, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('F' . $row, $guest->ChatLanguage, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('G' . $row, $guest->TravelDate, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('H' . $row, $guest->Destination, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			// Booking PIC: ticked agent(s), mirrors the BC / Travel Voucher.
			// Falls back to Sales Agent 1 for legacy bookings (unset ticks).
			$gl_pic = booking_pic_display(array(
				'sales_agent_is_pic'   => $guest->SalesAgentIsPIC,
				'sales_agent_2_is_pic' => $guest->SalesAgent2IsPIC,
				'sales_agent_name'     => $guest->SalesAgent,
				'sales_agent_2_name'   => $guest->SalesAgent2,
				'insert_date'          => $guest->InsertDate,
			));
			$guest->BookingPIC = implode(', ', array_column($gl_pic['entries'], 'name'));
			$spreadsheet->getActiveSheet()->setCellValueExplicit('I' . $row, $guest->BookingPIC, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('J' . $row, $guest->Type, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('K' . $row, $guest->Guest, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('L' . $row, $guest->Gender, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('M' . $row, $guest->DateOfBirth, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('N' . $row, $guest->Nationality, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('O' . $row, $guest->IdentificationNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('P' . $row, $guest->PassportNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('Q' . $row, $guest->PassportIssueDate, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('R' . $row, $guest->PassportExpiryDate, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			
			// Passport Copy as clickable link
			if(!empty($guest->PassportCopy) && file_exists(FCPATH . $guest->PassportCopy)) {
				$passport_copy_url = base_url($guest->PassportCopy);
				$passport_copy_filename = basename($guest->PassportCopy);
				$spreadsheet->getActiveSheet()->setCellValue('S' . $row, $passport_copy_filename);
				$spreadsheet->getActiveSheet()->getCell('S' . $row)->getHyperlink()->setUrl($passport_copy_url);
				$spreadsheet->getActiveSheet()->getCell('S' . $row)->getHyperlink()->setTooltip('Click to open passport copy');
				$spreadsheet->getActiveSheet()->getStyle('S' . $row)->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLUE);
				$spreadsheet->getActiveSheet()->getStyle('S' . $row)->getFont()->setUnderline(true);
				$spreadsheet->getActiveSheet()->getStyle('S' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
			} elseif(!empty($guest->PassportCopy)) {
				$spreadsheet->getActiveSheet()->setCellValueExplicit('S' . $row, PASSPORT_DELETED_MESSAGE, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			} else {
				$spreadsheet->getActiveSheet()->setCellValueExplicit('S' . $row, '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			}
			
			$spreadsheet->getActiveSheet()->setCellValueExplicit('T' . $row, $guest->DietaryRequirement, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('U' . $row, $guest->GuestMobile, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('V' . $row, $guest->Email, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('W' . $row, $guest->MaritalStatus, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('X' . $row, $guest->Employment, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('Y' . $row, $guest->Address, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('Z' . $row, $guest->Postcode, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('AA' . $row, $guest->City, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('AB' . $row, $guest->State, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('AC' . $row, $guest->Country, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('AD' . $row, $guest->Nominee, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('AE' . $row, $guest->NomineeContactNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('AF' . $row, $guest->NomineeIdentificationNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('AG' . $row, $guest->Relationship, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('AH' . $row, isset($guest->RoomName) ? $guest->RoomName : '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$row++;
		}
		$spreadsheet->getActiveSheet()->getStyle('A:AH')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
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
		$spreadsheet->getActiveSheet()->getColumnDimension('W')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('X')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('Y')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('Z')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('AA')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('AB')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('AC')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('AD')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('AE')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('AF')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('AG')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('AH')->setWidth(35);
		$guest_lists = 'GUEST_LISTS_' . $guest_lists[0]->BookingNumber . '.xlsx';
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment;filename="' . $guest_lists . '"');
		header('Cache-Control: max-age=0');
		header('Cache-Control: max-age=1');
		$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
		$writer->save('php://output');
	}
	
	function Download_ZIP() {
		if (nav_block_data_export('Guest_List')) { return; }
		// Get booking_id from GET parameter
		$booking_id = $this->input->get('booking_id');
		if(empty($booking_id)) {
			show_error('Booking ID is required');
		}

		$booking_row = $this->Booking_Model->find($booking_id);
		if ($booking_row && is_sa_blocked_from_completed_booking($this->session->userdata('level'), $booking_row->Status, $booking_row->AfterSalesService)) {
			$this->load->view('errors/access_denied');
			return;
		}

		// Get guest lists data
		$guest_lists = $this->Guest_List_Model->Read_Guest_Lists1();
		if(empty($guest_lists)) {
			show_error('No guest list found for this booking');
		}
		
		// Create temporary directory
		$temp_dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'guestlist_' . $booking_id . '_' . time();
		if(!mkdir($temp_dir, 0755, true)) {
			show_error('Failed to create temporary directory');
		}
		
		// Generate Excel file (similar to Download function)
		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$spreadsheet->getActiveSheet()->setTitle('Guest Lists');
		$spreadsheet->getProperties()->setCreator('HolidayGoGoGo');
		$spreadsheet->getActiveSheet()->setCellValue('A1', 'BOOKING NUMBER');
		$spreadsheet->getActiveSheet()->setCellValue('B1', 'RESERVATION NUMBER');
		$spreadsheet->getActiveSheet()->setCellValue('C1', 'CUSTOMER FIRST NAME');
		$spreadsheet->getActiveSheet()->setCellValue('D1', 'CUSTOMER LAST NAME');
		$spreadsheet->getActiveSheet()->setCellValue('E1', 'CUSTOMER MOBILE');
		$spreadsheet->getActiveSheet()->setCellValue('F1', 'CHAT LANGUAGE');
		$spreadsheet->getActiveSheet()->setCellValue('G1', 'TRAVEL DATE');
		$spreadsheet->getActiveSheet()->setCellValue('H1', 'DESTINATION');
		$spreadsheet->getActiveSheet()->setCellValue('I1', 'BOOKING PIC');
		$spreadsheet->getActiveSheet()->setCellValue('J1', 'GUEST TYPE');
		$spreadsheet->getActiveSheet()->setCellValue('K1', 'GUEST');
		$spreadsheet->getActiveSheet()->setCellValue('L1', 'GENDER');
		$spreadsheet->getActiveSheet()->setCellValue('M1', 'DATE OF BIRTH');
		$spreadsheet->getActiveSheet()->setCellValue('N1', 'NATIONALITY');
		$spreadsheet->getActiveSheet()->setCellValue('O1', 'GUEST IDENTIFICATION NUMBER');
		$spreadsheet->getActiveSheet()->setCellValue('P1', 'PASSPORT NUMBER');
		$spreadsheet->getActiveSheet()->setCellValue('Q1', 'PASSPORT ISSUE DATE');
		$spreadsheet->getActiveSheet()->setCellValue('R1', 'PASSPORT EXPIRY DATE');
		$spreadsheet->getActiveSheet()->setCellValue('S1', 'PASSPORT COPY');
		$spreadsheet->getActiveSheet()->setCellValue('T1', 'DIETARY REQUIREMENT');
		$spreadsheet->getActiveSheet()->setCellValue('U1', 'GUEST MOBILE');
		$spreadsheet->getActiveSheet()->setCellValue('V1', 'EMAIL');
		$spreadsheet->getActiveSheet()->setCellValue('W1', 'MARITAL STATUS');
		$spreadsheet->getActiveSheet()->setCellValue('X1', 'EMPLOYMENT');
		$spreadsheet->getActiveSheet()->setCellValue('Y1', 'ADDRESS');
		$spreadsheet->getActiveSheet()->setCellValue('Z1', 'POSTCODE');
		$spreadsheet->getActiveSheet()->setCellValue('AA1', 'CITY');
		$spreadsheet->getActiveSheet()->setCellValue('AB1', 'STATE');
		$spreadsheet->getActiveSheet()->setCellValue('AC1', 'COUNTRY');
		$spreadsheet->getActiveSheet()->setCellValue('AD1', 'NOMINEE');
		$spreadsheet->getActiveSheet()->setCellValue('AE1', 'NOMINEE CONTACT NUMBER');
		$spreadsheet->getActiveSheet()->setCellValue('AF1', 'NOMINEE IDENTIFICATION NUMBER');
		$spreadsheet->getActiveSheet()->setCellValue('AG1', 'RELATIONSHIP');
		$spreadsheet->getActiveSheet()->setCellValue('AH1', 'ROOM');
		$row = 2;
		$spreadsheet->getActiveSheet()->getStyle('A1:AH1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
		$spreadsheet->getActiveSheet()->getStyle('A1:AH1')->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE);
		$spreadsheet->getActiveSheet()->getStyle('A1:AH1')->getFont()->setBold(true);
		
		// Create passport images directory inside temp folder
		$passport_dir = $temp_dir . DIRECTORY_SEPARATOR . 'passport_images';
		if(!mkdir($passport_dir, 0755, true)) {
			$this->cleanup_temp_dir($temp_dir);
			show_error('Failed to create passport images directory');
		}
		
		foreach($guest_lists as $guest) {
			$guest->CustomerMobile = $guest->CountryCode . $guest->CustomerMobile;
			if(!empty($guest->StartDate) && !empty($guest->EndDate)) {
				$guest->TravelDate = strtoupper(date('j M', strtotime($guest->StartDate)) . ' - ' . date('j M Y', strtotime($guest->EndDate)));
			} else {
				$guest->TravelDate = null;
			}
			if(!empty($guest->DateOfBirth)) {
				$guest->DateOfBirth = strtoupper(date('j M Y', strtotime($guest->DateOfBirth)));
			}
			if(!empty($guest->PassportIssueDate)) {
				$guest->PassportIssueDate = strtoupper(date('j M Y', strtotime($guest->PassportIssueDate)));
			} else {
				$guest->PassportIssueDate = null;
			}
			if(!empty($guest->PassportExpiryDate)) {
				$guest->PassportExpiryDate = strtoupper(date('j M Y', strtotime($guest->PassportExpiryDate)));
			} else {
				$guest->PassportExpiryDate = null;
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
			$spreadsheet->getActiveSheet()->setCellValueExplicit('B' . $row, $guest->ReservationNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('C' . $row, $guest->Guest, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('D' . $row, $guest->GuestLastName, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('E' . $row, $guest->CustomerMobile, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('F' . $row, $guest->ChatLanguage, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('G' . $row, $guest->TravelDate, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('H' . $row, $guest->Destination, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			// Booking PIC: ticked agent(s), mirrors the BC / Travel Voucher.
			// Falls back to Sales Agent 1 for legacy bookings (unset ticks).
			$gl_pic = booking_pic_display(array(
				'sales_agent_is_pic'   => $guest->SalesAgentIsPIC,
				'sales_agent_2_is_pic' => $guest->SalesAgent2IsPIC,
				'sales_agent_name'     => $guest->SalesAgent,
				'sales_agent_2_name'   => $guest->SalesAgent2,
				'insert_date'          => $guest->InsertDate,
			));
			$guest->BookingPIC = implode(', ', array_column($gl_pic['entries'], 'name'));
			$spreadsheet->getActiveSheet()->setCellValueExplicit('I' . $row, $guest->BookingPIC, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('J' . $row, $guest->Type, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('K' . $row, $guest->Guest, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('L' . $row, $guest->Gender, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('M' . $row, $guest->DateOfBirth, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('N' . $row, $guest->Nationality, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('O' . $row, $guest->IdentificationNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('P' . $row, $guest->PassportNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('Q' . $row, $guest->PassportIssueDate, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('R' . $row, $guest->PassportExpiryDate, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			
			// Handle passport copy - copy file to temp directory
			if(!empty($guest->PassportCopy)) {
				$passport_copy_path = FCPATH . $guest->PassportCopy;
				$passport_copy_filename = basename($guest->PassportCopy);
				
				// Generate filename: use guest name + last name, fallback to original filename
				$file_extension = pathinfo($passport_copy_filename, PATHINFO_EXTENSION);
				
				// Try to use guest name + last name
				if(!empty($guest->Guest) && !empty($guest->GuestLastName)) {
					$guest_name_safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($guest->Guest . '_' . $guest->GuestLastName));
					$unique_filename = $guest_name_safe . '.' . $file_extension;
				} elseif(!empty($guest->Guest)) {
					// Only first name available
					$guest_name_safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($guest->Guest));
					$unique_filename = $guest_name_safe . '.' . $file_extension;
				} else {
					// Fallback to original passport copy filename
					$unique_filename = $passport_copy_filename;
				}
				
				// Handle filename conflicts by adding a counter
				$base_filename = pathinfo($unique_filename, PATHINFO_FILENAME);
				$final_filename = $unique_filename;
				$counter = 1;
				while(file_exists($passport_dir . DIRECTORY_SEPARATOR . $final_filename)) {
					$final_filename = $base_filename . '_' . $counter . '.' . $file_extension;
					$counter++;
				}
				
				$destination_path = $passport_dir . DIRECTORY_SEPARATOR . $final_filename;
				
				// Copy passport image if it exists
				if(file_exists($passport_copy_path)) {
					if(copy($passport_copy_path, $destination_path)) {
						$spreadsheet->getActiveSheet()->setCellValue('S' . $row, 'passport_images/' . $final_filename);
					} else {
						$spreadsheet->getActiveSheet()->setCellValueExplicit('S' . $row, $passport_copy_filename . ' (copy failed)', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					}
				} else {
					$spreadsheet->getActiveSheet()->setCellValueExplicit('S' . $row, PASSPORT_DELETED_MESSAGE, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				}
			} else {
				$spreadsheet->getActiveSheet()->setCellValueExplicit('S' . $row, '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			}
			
			$spreadsheet->getActiveSheet()->setCellValueExplicit('T' . $row, $guest->DietaryRequirement, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('U' . $row, $guest->GuestMobile, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('V' . $row, $guest->Email, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('W' . $row, $guest->MaritalStatus, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('X' . $row, $guest->Employment, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('Y' . $row, $guest->Address, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('Z' . $row, $guest->Postcode, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('AA' . $row, $guest->City, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('AB' . $row, $guest->State, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('AC' . $row, $guest->Country, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('AD' . $row, $guest->Nominee, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('AE' . $row, $guest->NomineeContactNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('AF' . $row, $guest->NomineeIdentificationNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('AG' . $row, $guest->Relationship, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$spreadsheet->getActiveSheet()->setCellValueExplicit('AH' . $row, isset($guest->RoomName) ? $guest->RoomName : '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$row++;
		}
		$spreadsheet->getActiveSheet()->getStyle('A:AH')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
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
		$spreadsheet->getActiveSheet()->getColumnDimension('W')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('X')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('Y')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('Z')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('AA')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('AB')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('AC')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('AD')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('AE')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('AF')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('AG')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('AH')->setWidth(35);

		// Save Excel file to temp directory
		$excel_filename = 'GUEST_LISTS_' . $guest_lists[0]->BookingNumber . '.xlsx';
		$excel_path = $temp_dir . DIRECTORY_SEPARATOR . $excel_filename;
		$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
		$writer->save($excel_path);
		
		// Create ZIP file
		$zip_filename = 'GUEST_LISTS_' . $guest_lists[0]->BookingNumber . '.zip';
		$zip_path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $zip_filename;
		
		$zip = new \ZipArchive();
		if($zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== TRUE) {
			$this->cleanup_temp_dir($temp_dir);
			show_error('Failed to create ZIP file');
		}
		
		// Add Excel file to ZIP
		$zip->addFile($excel_path, $excel_filename);
		
		// Add all passport images to ZIP
		$passport_files = glob($passport_dir . DIRECTORY_SEPARATOR . '*');
		foreach($passport_files as $file) {
			if(is_file($file)) {
				$zip->addFile($file, 'passport_images/' . basename($file));
			}
		}
		
		$zip->close();
		
		// Download ZIP file
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment;filename="' . $zip_filename . '"');
		header('Content-Length: ' . filesize($zip_path));
		header('Cache-Control: max-age=0');
		readfile($zip_path);
		
		// Clean up temporary files
		unlink($zip_path);
		$this->cleanup_temp_dir($temp_dir);
		exit;
	}
	
	/**
	 * Clean up temporary directory and its contents
	 * @param string $dir Directory path to clean up
	 */
	private function cleanup_temp_dir($dir) {
		if(is_dir($dir)) {
			$files = array_diff(scandir($dir), array('.', '..'));
			foreach($files as $file) {
				$file_path = $dir . DIRECTORY_SEPARATOR . $file;
				if(is_dir($file_path)) {
					$this->cleanup_temp_dir($file_path);
				} else {
					@unlink($file_path);
				}
			}
			@rmdir($dir);
		}
	}
	
	function Unlock() {
		$this->Guest_List_Model->Update_GL_Session('BookingID', $this->input->post('booking_id'), 'N', null);
	}

	/**
	 * Auto-save endpoint called when the GL countdown is about to expire or when
	 * the user declines the extension. Writes whatever the user has typed so far
	 * into the real guest_list rows without enforcing the "all fields required"
	 * validation that gates the normal submit. Does NOT release the lock, does
	 * NOT flip LockStatus / is_submitted, and does NOT redirect — the frontend
	 * runs its existing release-and-reload flow after this call completes.
	 */
	function auto_save() {
		header('Content-Type: application/json');

		if (!$this->input->post()) {
			echo json_encode(array('status' => 'error', 'message' => 'No data'));
			return;
		}

		$truncation_error = $this->detect_truncated_post();
		if ($truncation_error !== null) {
			log_message('error', 'Guest_List auto_save truncated: ' . $truncation_error);
			echo json_encode(array('status' => 'truncated', 'message' => $truncation_error));
			return;
		}

		try {
			$booking_id = $this->Guest_List_Model->Read_Booking_ID();

			// Stale-form guard: the clobber fires the instant the GL lock timer
			// hits zero (lock expired) and a leftover tab may no longer own the
			// lock. Refuse to write when the lock is missing/expired or held by
			// another session, so a dead form can't touch saved guest data.
			// Resolve the lock by the booking Token (auto-save posts no ?gl=, so
			// derive the booking from the first posted guest row).
			$this->load->helper('guest_list_autosave');
			$this->load->model('Guest_list_lock_model');
			if (empty($booking_id)) {
				$booking_id = $this->Guest_List_Model->Booking_Id_For_Posted_Guest();
			}
			$token = $this->Booking_Token_By_Id($booking_id);
			$lock  = !empty($token) ? $this->Guest_list_lock_model->getByHash($token) : null;
			$user_id = $this->session->userdata('admin_id') ?: null;
			$expired = $this->Guest_list_lock_model->isExpired($lock);
			$same_owner = $this->Guest_list_lock_model->isSameOwner($lock, $this->input->post('lock_token'), $user_id);
			if (!gl_autosave_should_write($expired, $same_owner)) {
				echo json_encode(array('status' => 'skipped', 'message' => 'Lock expired or not owned — auto-save discarded'));
				return;
			}

			// Resolve passport copies the same way index() does, so that auto-save
			// respects freshly uploaded files and preserves existing ones.
			$this->resolve_passport_copies($booking_id);

			// preserve_case=true: auto-save must store exactly what the user
			// typed so the reload shows their text verbatim. Normal submit
			// (index()) still uppercases as it always has.
			// skip_empty=true: a blank field must never overwrite saved data.
			$this->Guest_List_Model->Update(true, $booking_id, true);

			if (!empty($this->input->post('new_guests'))) {
				$this->Guest_List_Model->Create_Guest($booking_id, true);
			}

			if (!empty($this->input->post('deleted_guests'))) {
				$deleted_guests = explode(',', $this->input->post('deleted_guests'));
				for ($i = 0; $i < count($deleted_guests); $i++) {
					if ($deleted_guests[$i] !== '') {
						$this->Guest_List_Model->Delete($deleted_guests[$i]);
					}
				}
			}

			echo json_encode(array('status' => 'ok'));
		} catch (Exception $e) {
			log_message('error', 'Guest_List auto_save failed: ' . $e->getMessage());
			echo json_encode(array('status' => 'error', 'message' => $e->getMessage()));
		}
	}

	/** Booking Token (= GL lock hash) for a BookingID; null when unknown. */
	private function Booking_Token_By_Id($booking_id)
	{
		if (empty($booking_id)) {
			return null;
		}
		$row = $this->db->select('Token')->where('BookingID', $booking_id)->get('booking')->row();
		return $row ? $row->Token : null;
	}

	/**
	 * Upload a single passport file triggered by an onchange on the file input.
	 * Accepts a slot in the form "existing:<GuestListID>" or "new:<rowIndex>".
	 * For existing guests the filename is written straight to the DB so it
	 * survives timeout without relying on a subsequent auto_save call.
	 */
	function upload_passport_single() {
		header('Content-Type: application/json');

		$slot = $this->input->post('slot');
		if (empty($slot) || !isset($_FILES['passport_copy'])) {
			echo json_encode(array('status' => 'error', 'message' => 'Missing slot or file'));
			return;
		}

		$booking_id = $this->Guest_List_Model->Read_Booking_ID();
		if (empty($booking_id)) {
			echo json_encode(array('status' => 'error', 'message' => 'Invalid booking'));
			return;
		}

		$result = $this->store_single_passport_file('passport_copy');
		if ($result['status'] !== 'ok') {
			echo json_encode($result);
			return;
		}

		$filename = $result['filename'];

		// existing:<GuestListID> — persist to DB immediately so a reload
		// before auto_save still shows the uploaded file.
		if (strpos($slot, 'existing:') === 0) {
			$guest_list_id = (int) substr($slot, strlen('existing:'));
			if ($guest_list_id > 0) {
				$this->Guest_List_Model->Update_Passport_Copy($guest_list_id, $filename);
			}
		}
		// new:<rowIndex> — just return the filename; the frontend will stuff
		// it into the matching new_passport_copies hidden input and auto_save
		// / normal submit will pick it up.

		echo json_encode(array('status' => 'ok', 'filename' => $filename));
	}

	/**
	 * Detect a POST that the server rejected outright due to post_max_size.
	 * Only catches the unambiguous case where the request body arrived
	 * (Content-Length > 0) but PHP couldn't parse it ($_POST empty).
	 *
	 * The earlier max_input_vars parallel-array check was removed because
	 * disabled inputs, JS-driven form states, and conditional template
	 * blocks all legitimately produce zero-count arrays that can't be
	 * distinguished from truncation. With max_input_vars raised to 10000
	 * via .user.ini, real truncation requires 350+ guests and is not a
	 * realistic concern. Returns null when the payload looks intact.
	 */
	private function detect_truncated_post() {
		$content_length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		if ($content_length > 0 && empty($_POST)) {
			return 'Your submission was too large to be received by the server (post_max_size exceeded). Please contact admin.';
		}
		return null;
	}

	/**
	 * Shared passport merging logic used by both index() and auto_save():
	 * handles freshly uploaded files, preserves existing ones, and mutates
	 * $_POST so Guest_List_Model->Update() / Create_Guest() pick up the paths.
	 */
	private function resolve_passport_copies($booking_id) {
		$passport_copy_paths = $this->handle_passport_uploads('passport_copies', $booking_id);
		$new_passport_copy_paths = $this->handle_passport_uploads('new_passport_copies', $booking_id);

		if (!empty($this->input->post('guests'))) {
			$guest_count = count($this->input->post('guests'));
			$passport_copies_array = array();
			for ($i = 0; $i < $guest_count; $i++) {
				if (isset($passport_copy_paths[$i]) && !empty($passport_copy_paths[$i])) {
					$passport_copies_array[$i] = $passport_copy_paths[$i];
				} elseif (!empty($this->input->post('existing_passport_copies')) && isset($this->input->post('existing_passport_copies')[$i]) && !empty($this->input->post('existing_passport_copies')[$i])) {
					$passport_copies_array[$i] = $this->input->post('existing_passport_copies')[$i];
				} else {
					$passport_copies_array[$i] = '';
				}
			}
			$_POST['passport_copies'] = $passport_copies_array;
		}

		if (!empty($new_passport_copy_paths)) {
			$_POST['new_passport_copies'] = $new_passport_copy_paths;
		}
	}

	/**
	 * Store a single uploaded file (same validation + naming as
	 * handle_passport_uploads) and return the relative path.
	 */
	private function store_single_passport_file($field_key) {
		$upload_path = FCPATH . 'assets/upload/passport/';
		if (!is_dir($upload_path)) {
			mkdir($upload_path, 0755, true);
		}

		if (!isset($_FILES[$field_key]) || $_FILES[$field_key]['error'] != UPLOAD_ERR_OK) {
			return array('status' => 'error', 'message' => 'Upload failed');
		}

		$allowed_extensions = array('pdf', 'jpg', 'jpeg', 'png', 'gif');
		$max_size = 10240 * 1024; // 10MB

		$tmp_name = $_FILES[$field_key]['tmp_name'];
		$original_name = $_FILES[$field_key]['name'];
		$file_size = $_FILES[$field_key]['size'];

		if ($file_size > $max_size) {
			return array('status' => 'error', 'message' => 'File too large (max 10MB)');
		}

		$file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
		if (!in_array($file_extension, $allowed_extensions)) {
			return array('status' => 'error', 'message' => 'Invalid file type');
		}

		$encrypted_name = md5(uniqid(rand(), true) . time()) . '.' . $file_extension;
		$destination = $upload_path . $encrypted_name;

		if (!move_uploaded_file($tmp_name, $destination)) {
			return array('status' => 'error', 'message' => 'Could not move uploaded file');
		}

		return array('status' => 'ok', 'filename' => 'assets/upload/passport/' . $encrypted_name);
	}

	/**
	 * Handle passport copy file uploads
	 * @param string $field_name The name of the file input field
	 * @param int $booking_id The booking ID
	 * @return array Array of file paths indexed by guest index
	 */
	private function handle_passport_uploads($field_name, $booking_id) {
		$uploaded_paths = array();
		$upload_path = FCPATH . 'assets/upload/passport/';
		
		// Create upload directory if it doesn't exist
		if (!is_dir($upload_path)) {
			mkdir($upload_path, 0755, true);
		}
		
		// Handle multiple file uploads
		if (isset($_FILES[$field_name]) && is_array($_FILES[$field_name]['name'])) {
			$file_count = count($_FILES[$field_name]['name']);
			$existing_field = str_replace('new_', '', $field_name);
			$allowed_extensions = array('pdf', 'jpg', 'jpeg', 'png', 'gif');
			$max_size = 10240 * 1024; // 10MB in bytes
			
			for ($i = 0; $i < $file_count; $i++) {
				// Check if file was uploaded for this index
				if (isset($_FILES[$field_name]['name'][$i]) && !empty($_FILES[$field_name]['name'][$i]) && $_FILES[$field_name]['error'][$i] == UPLOAD_ERR_OK) {
					$tmp_name = $_FILES[$field_name]['tmp_name'][$i];
					$original_name = $_FILES[$field_name]['name'][$i];
					$file_size = $_FILES[$field_name]['size'][$i];
					
					// Validate file size
					if ($file_size > $max_size) {
						log_message('error', 'Passport upload failed: File too large for guest index ' . $i);
						// Keep existing file if available
						$existing_key = 'existing_' . $existing_field;
						if (!empty($this->input->post($existing_key)) && isset($this->input->post($existing_key)[$i]) && !empty($this->input->post($existing_key)[$i])) {
							$uploaded_paths[$i] = $this->input->post($existing_key)[$i];
						}
						continue;
					}
					
					// Get file extension
					$file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
					
					// Validate file type
					if (!in_array($file_extension, $allowed_extensions)) {
						log_message('error', 'Passport upload failed: Invalid file type for guest index ' . $i);
						// Keep existing file if available
						$existing_key = 'existing_' . $existing_field;
						if (!empty($this->input->post($existing_key)) && isset($this->input->post($existing_key)[$i]) && !empty($this->input->post($existing_key)[$i])) {
							$uploaded_paths[$i] = $this->input->post($existing_key)[$i];
						}
						continue;
					}
					
					// Generate encrypted filename
					$encrypted_name = md5(uniqid(rand(), true) . time() . $i) . '.' . $file_extension;
					$destination = $upload_path . $encrypted_name;
					
					// Move uploaded file
					if (move_uploaded_file($tmp_name, $destination)) {
						$uploaded_paths[$i] = 'assets/upload/passport/' . $encrypted_name;
					} else {
						log_message('error', 'Passport upload failed: Could not move file for guest index ' . $i);
						// Keep existing file if available
						$existing_key = 'existing_' . $existing_field;
						if (!empty($this->input->post($existing_key)) && isset($this->input->post($existing_key)[$i]) && !empty($this->input->post($existing_key)[$i])) {
							$uploaded_paths[$i] = $this->input->post($existing_key)[$i];
						}
					}
				} else {
					// No new file uploaded, keep existing file if available
					$existing_key = 'existing_' . $existing_field;
					if (!empty($this->input->post($existing_key)) && isset($this->input->post($existing_key)[$i]) && !empty($this->input->post($existing_key)[$i])) {
						$uploaded_paths[$i] = $this->input->post($existing_key)[$i];
					}
				}
			}
		}
		
		return $uploaded_paths;
	}
}
