<?php

require FCPATH.'vendor/autoload.php';  
use PhpOffice\PhpSpreadsheet\Spreadsheet;  
use PhpOffice\PhpSpreadsheet\Writer\Xlxs;

class Supplier extends MY_Controller
{
	function __construct()
	{
		parent::__construct();
		$this->load->model('Supplier_Model');
		$this->load->model('Universal_Model');
	}

	function index()
	{
		$titles = array('tab_title' => 'HolidayGoGoGo | Supplier', 'breadcrumb_title' => 'Supplier');
		$array['suppliers'] = $this->Supplier_Model->Read_Suppliers1();
		$this->load->view('layout/header', $titles);
		$this->load->view('supplier/index', $array);
		$this->load->view('layout/footer');
	}

	function Create()
	{
		if($this->input->is_ajax_request()) {
			$this->Supplier_Model->Create();
		} else {
			$titles = array('tab_title' => 'HolidayGoGoGo | Supplier', 'breadcrumb_title' => 'Supplier >> Create');
			$array = array('SupplierID' => 'NA', 'Name' => 'NA');
			$this->load->view('layout/header', $titles);
			$this->load->view('supplier/supplier', $array);
			$this->load->view('layout/footer');
		}
	}

	function Update()
	{
		if($this->input->is_ajax_request()) {
			if(count($this->input->post('supplier')[0]) > 3) {
				$this->Supplier_Model->Update();

				$supplier = get_object_vars($this->Supplier_Model->find($this->input->post('supplier_id')));
				if (!empty($supplier)) {
					if ($supplier['AutocountSyncAction'] == 'C' && $supplier['AutocountSyncStatus'] == 'S') {
						// Update action to 'U' and status to 'P' if action is 'C' and status is 'S'
						$this->Supplier_Model->update_by_id($supplier['SupplierID'], [
							'AutocountSyncAction' => 'U',
							'AutocountSyncStatus' => 'P'
						]);
					} elseif ($supplier['AutocountSyncAction'] == 'U' && $supplier['AutocountSyncStatus'] == 'S') {
						// Update status to 'P' if action is 'U' and status is 'S'
						$this->Supplier_Model->update_by_id($supplier['SupplierID'], [
							'AutocountSyncStatus' => 'P'
						]);
					} else {
						// Just update status to 'P' in all other cases
						$this->Supplier_Model->update_by_id($supplier['SupplierID'], [
							'AutocountSyncStatus' => 'P'
						]);
					}
				}
			}
		} else {
			$valid_supplier_id = $this->Universal_Model->Validate_Id('SupplierID', $this->input->get('supplier_id'), 'supplier');
			if($valid_supplier_id) {
				$titles = array('tab_title' => 'HolidayGoGoGo | Supplier', 'breadcrumb_title' => 'Supplier >> Update');
				$array = $this->Supplier_Model->Read_Supplier();
				$this->load->view('layout/header', $titles);
				$this->load->view('supplier/supplier', $array);
				$this->load->view('layout/footer');
			} else {
				redirect('Supplier');
			}
		}
	}
	
	function Delete() 
	{
		$this->Universal_Model->Delete('SupplierID', $this->input->get('supplier_id'), 'supplier');

		$supplier = get_object_vars($this->Supplier_Model->find($this->input->get('supplier_id')));
		if (!empty($supplier)) {
			if ($supplier['AutocountSyncAction'] == 'C' && $supplier['AutocountSyncStatus'] == 'S') {
				// Update action to 'U' and status to 'P' if action is 'C' and status is 'S'
				$this->Supplier_Model->update_by_id($supplier['SupplierID'], [
					'AutocountSyncAction' => 'D',
					'AutocountSyncStatus' => 'P'
				]);
			} elseif ($supplier['AutocountSyncAction'] == 'U') {
				// Update status to 'P' if action is 'U' and status is 'S'
				$this->Supplier_Model->update_by_id($supplier['SupplierID'], [
					'AutocountSyncAction' => 'D',
					'AutocountSyncStatus' => 'P'
				]);
			} else {
				// Just update status to 'P' in all other cases
				$this->Supplier_Model->update_by_id($supplier['SupplierID'], [
					'AutocountSyncStatus' => 'P'
				]);
			}
		}
	}
	
	function Download() {
		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$spreadsheet->getActiveSheet()->setTitle('Supplier Records');
		$spreadsheet->getProperties()->setCreator('HolidayGoGoGo');
		$spreadsheet->getActiveSheet()->setCellValue('A1', 'NAME');
		$spreadsheet->getActiveSheet()->setCellValue('B1', 'PHONE');
		$spreadsheet->getActiveSheet()->setCellValue('C1', 'PRIMARY EMAIL');
		$spreadsheet->getActiveSheet()->setCellValue('D1', 'SECONDARY EMAIL');
		$spreadsheet->getActiveSheet()->setCellValue('E1', 'ADDRESS');
		$spreadsheet->getActiveSheet()->setCellValue('F1', 'CURRENCY CODE');
		$spreadsheet->getActiveSheet()->setCellValue('G1', 'BANK');
		$spreadsheet->getActiveSheet()->setCellValue('H1', 'BANK ACCOUNT');
		$spreadsheet->getActiveSheet()->setCellValue('I1', 'BANK HOLDER');
		$spreadsheet->getActiveSheet()->setCellValue('J1', 'SWIFT CODE');
		$spreadsheet->getActiveSheet()->setCellValue('K1', 'SUPPLIER CODE');
		$row = 2;
		$suppliers = $this->Supplier_Model->Read_Suppliers2();
		$spreadsheet->getActiveSheet()->getStyle('A1:K1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
		$spreadsheet->getActiveSheet()->getStyle('A1:K1')->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE);
		$spreadsheet->getActiveSheet()->getStyle('A1:K1')->getFont()->setBold(true);
		if(!empty($suppliers)) {
			foreach($suppliers as $supplier) {
				$spreadsheet->getActiveSheet()->setCellValueExplicit('A' . $row, $supplier->Name, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('B' . $row, $supplier->Phone, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('C' . $row, $supplier->PrimaryEmail, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('D' . $row, $supplier->SecondaryEmail, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('E' . $row, $supplier->Address, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('F' . $row, $supplier->CurrencyCode, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('G' . $row, $supplier->Bank, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('H' . $row, $supplier->BankAccount, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('I' . $row, $supplier->BankHolder, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('J' . $row, $supplier->SwiftCode, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('K' . $row, $supplier->SupplierCode, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$row++;
			}
			$spreadsheet->getActiveSheet()->getStyle('A:K')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
		} else {
			$spreadsheet->getActiveSheet()->mergeCells('A2:K2');
			$spreadsheet->getActiveSheet()->getCell('A2')->setValue('Supplier Records Not Found');
			$spreadsheet->getActiveSheet()->getStyle('A:K')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
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
		$supplier_records = 'SUPPLIER_RECORDS_' . date('Ymd') . '.xlsx';
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment;filename="' . $supplier_records . '"');
		header('Cache-Control: max-age=0');
		header('Cache-Control: max-age=1');
		$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
		$writer->save('php://output');
	}

	function Detect() {
		$redundant_name = $this->Supplier_Model->Detect();
		if($redundant_name) {
			echo json_encode(true);
		} else {
			echo json_encode(false);
		}
	}

	function DownloadResults() {
		// Get data from session
		$session_data = $this->session->userdata('mapping_results');
		
		if (empty($session_data)) {
			die("Error: No mapping results found. Please run the mapping again.");
		}

		$mapped_data = $session_data['mapped'];
		$unmapped_data = $session_data['unmapped'];
		$duplicate_warnings = isset($session_data['duplicate_warnings']) ? $session_data['duplicate_warnings'] : array();

		// Create Excel file
		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$spreadsheet->getProperties()->setCreator('HolidayGoGoGo');

		// Sheet 1: Mapped Suppliers
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->setTitle('Mapped Suppliers');
		$sheet->setCellValue('A1', 'Row #');
		$sheet->setCellValue('B1', 'Supplier ID');
		$sheet->setCellValue('C1', 'Supplier Name');
		$sheet->setCellValue('D1', 'CSV Supplier Code');
		$sheet->setCellValue('E1', 'DB Supplier Code');
		$sheet->setCellValue('F1', 'Status');
		
		$row = 2;
		foreach ($mapped_data as $item) {
			$sheet->setCellValue('A' . $row, $item['row']);
			$sheet->setCellValue('B' . $row, $item['supplier_id']);
			$sheet->setCellValue('C' . $row, $item['name']);
			$sheet->setCellValue('D' . $row, $item['csv_supplier_code']);
			$sheet->setCellValue('E' . $row, $item['db_supplier_code']);
			
			// Format status
			$status_text = '';
			if (isset($item['update_status'])) {
				switch ($item['update_status']) {
					case 'updated':
						$status_text = 'Updated';
						if (isset($item['original_db_code'])) {
							$status_text .= ' (was: ' . ($item['original_db_code'] ?: 'NULL') . ')';
						}
						break;
					case 'already_exists':
						$status_text = 'Already Exists';
						break;
					case 'csv_empty':
						$status_text = 'CSV Empty';
						break;
					case 'update_failed':
						$status_text = 'Update Failed';
						break;
					default:
						$status_text = 'Not Updated';
				}
			}
			$sheet->setCellValue('F' . $row, $status_text);
			$row++;
		}

		// Style header
		$sheet->getStyle('A1:F1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
		$sheet->getStyle('A1:F1')->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE);
		$sheet->getStyle('A1:F1')->getFont()->setBold(true);

		// Sheet 2: Unmapped Suppliers
		if (!empty($unmapped_data)) {
			$sheet2 = $spreadsheet->createSheet();
			$sheet2->setTitle('Unmapped Suppliers');
			$sheet2->setCellValue('A1', 'Row #');
			$sheet2->setCellValue('B1', 'Supplier Name');
			$sheet2->setCellValue('C1', 'CSV Supplier Code');
			
			$row = 2;
			foreach ($unmapped_data as $item) {
				$sheet2->setCellValue('A' . $row, $item['row']);
				$sheet2->setCellValue('B' . $row, $item['name']);
				$sheet2->setCellValue('C' . $row, isset($item['csv_supplier_code']) ? $item['csv_supplier_code'] : '');
				$row++;
			}

			$sheet2->getStyle('A1:C1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
			$sheet2->getStyle('A1:C1')->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE);
			$sheet2->getStyle('A1:C1')->getFont()->setBold(true);
		}

		// Sheet 3: Warnings
		if (!empty($duplicate_warnings)) {
			$sheet3 = $spreadsheet->createSheet();
			$sheet3->setTitle('Warnings');
			$sheet3->setCellValue('A1', 'Company Name');
			$sheet3->setCellValue('B1', 'Different Codes');
			$sheet3->setCellValue('C1', 'Row Numbers');
			
			$row = 2;
			foreach ($duplicate_warnings as $warning) {
				$sheet3->setCellValue('A' . $row, $warning['name']);
				$codes_display = array();
				foreach ($warning['codes'] as $code) {
					$codes_display[] = $code ?: 'EMPTY';
				}
				$sheet3->setCellValue('B' . $row, implode(', ', $codes_display));
				$sheet3->setCellValue('C' . $row, implode(', ', $warning['rows']));
				$row++;
			}
			
			$sheet3->getStyle('A1:C1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
			$sheet3->getStyle('A1:C1')->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE);
			$sheet3->getStyle('A1:C1')->getFont()->setBold(true);
		}

		// Set column widths
		$sheet->getColumnDimension('A')->setWidth(10);
		$sheet->getColumnDimension('B')->setWidth(15);
		$sheet->getColumnDimension('C')->setWidth(50);
		$sheet->getColumnDimension('D')->setWidth(20);
		$sheet->getColumnDimension('E')->setWidth(20);
		$sheet->getColumnDimension('F')->setWidth(30);

		// Download file
		$filename = 'supplier_mapping_results_' . date('Ymd_His') . '.xlsx';
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment;filename="' . $filename . '"');
		header('Cache-Control: max-age=0');
		
		$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
		$writer->save('php://output');
		exit;
	}

	function MapSupplierCodes() {
		// Handle file upload
		if ($this->input->server('REQUEST_METHOD') == 'POST' && isset($_FILES['csv_file'])) {
			// Check for upload errors
			if ($_FILES['csv_file']['error'] != UPLOAD_ERR_OK) {
				$upload_errors = array(
					UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
					UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
					UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
					UPLOAD_ERR_NO_FILE => 'No file was uploaded',
					UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
					UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
					UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
				);
				$error_msg = isset($upload_errors[$_FILES['csv_file']['error']]) 
					? $upload_errors[$_FILES['csv_file']['error']] 
					: 'Unknown upload error';
				
				$titles = array('tab_title' => 'HolidayGoGoGo | Map Supplier Codes', 'breadcrumb_title' => 'Supplier >> Map Supplier Codes');
				$array['error'] = 'Upload Error: ' . $error_msg;
				$this->load->view('layout/header', $titles);
				$this->load->view('supplier/map_codes', $array);
				$this->load->view('layout/footer');
				return;
			}
			// Configure upload
			$config['upload_path'] = FCPATH . 'assets/upload/temp/';
			$config['allowed_types'] = 'csv';
			$config['max_size'] = 5120; // 5MB
			$config['file_name'] = 'supplier_mapping_' . time() . '.csv';
			
			// Create upload directory if it doesn't exist
			if (!is_dir($config['upload_path'])) {
				mkdir($config['upload_path'], 0755, true);
			}
			
			$this->load->library('upload', $config);
			
			if (!$this->upload->do_upload('csv_file')) {
				$error = $this->upload->display_errors();
				$titles = array('tab_title' => 'HolidayGoGoGo | Map Supplier Codes', 'breadcrumb_title' => 'Supplier >> Map Supplier Codes');
				$array['error'] = $error;
				$this->load->view('layout/header', $titles);
				$this->load->view('supplier/map_codes', $array);
				$this->load->view('layout/footer');
				return;
			}
			
			$upload_data = $this->upload->data();
			$csv_file = $upload_data['full_path'];
		} else {
			// Get CSV file path from parameter or use default (for CLI/API access)
			$csv_file = $this->input->get('csv_file');
			if (empty($csv_file)) {
				// Show upload form if accessed via browser without file
				if (!$this->input->is_cli_request()) {
					$titles = array('tab_title' => 'HolidayGoGoGo | Map Supplier Codes', 'breadcrumb_title' => 'Supplier >> Map Supplier Codes');
					$array = array();
					$this->load->view('layout/header', $titles);
					$this->load->view('supplier/map_codes', $array);
					$this->load->view('layout/footer');
					return;
				}
				$csv_file = FCPATH . 'suppliers.csv'; // Default CSV file location
			} else {
				$csv_file = FCPATH . $csv_file;
			}

			// Check if file exists
			if (!file_exists($csv_file)) {
				if ($this->input->is_cli_request()) {
					die("Error: CSV file not found at: " . $csv_file . "\nPlease provide a valid CSV file path.\n");
				} else {
					$titles = array('tab_title' => 'HolidayGoGoGo | Map Supplier Codes', 'breadcrumb_title' => 'Supplier >> Map Supplier Codes');
					$array['error'] = "Error: CSV file not found at: " . $csv_file;
					$this->load->view('layout/header', $titles);
					$this->load->view('supplier/map_codes', $array);
					$this->load->view('layout/footer');
					return;
				}
			}
		}

		// Read CSV file using PHP's built-in fgetcsv
		$csv_data = array();
		$handle = fopen($csv_file, 'r');
		if ($handle === false) {
			$error = "Error: Could not open CSV file.";
			if ($this->input->is_cli_request()) {
				die($error . "\n");
			} else {
				$titles = array('tab_title' => 'HolidayGoGoGo | Map Supplier Codes', 'breadcrumb_title' => 'Supplier >> Map Supplier Codes');
				$array['error'] = $error;
				$this->load->view('layout/header', $titles);
				$this->load->view('supplier/map_codes', $array);
				$this->load->view('layout/footer');
				return;
			}
		}

		// Read header row
		$headers = fgetcsv($handle, 1000, ',');
		if ($headers === false) {
			fclose($handle);
			$error = "Error: Could not read CSV header row.";
			if ($this->input->is_cli_request()) {
				die($error . "\n");
			} else {
				$titles = array('tab_title' => 'HolidayGoGoGo | Map Supplier Codes', 'breadcrumb_title' => 'Supplier >> Map Supplier Codes');
				$array['error'] = $error;
				$this->load->view('layout/header', $titles);
				$this->load->view('supplier/map_codes', $array);
				$this->load->view('layout/footer');
				return;
			}
		}

		// Clean headers
		$headers = array_map('trim', $headers);

		// Read data rows
		$row_num = 0;
		while (($row = fgetcsv($handle, 1000, ',')) !== false) {
			if (count($row) == count($headers)) {
				$csv_data[$row_num] = array_combine($headers, $row);
				$row_num++;
			}
		}
		fclose($handle);
		
		if (empty($csv_data)) {
			$error = "Error: CSV file is empty or could not be parsed.";
			if ($this->input->is_cli_request()) {
				die($error . "\n");
			} else {
				$titles = array('tab_title' => 'HolidayGoGoGo | Map Supplier Codes', 'breadcrumb_title' => 'Supplier >> Map Supplier Codes');
				$array['error'] = $error;
				$this->load->view('layout/header', $titles);
				$this->load->view('supplier/map_codes', $array);
				$this->load->view('layout/footer');
				return;
			}
		}

		// Get all suppliers for mapping (case-insensitive lookup)
		$supplier_mapping = $this->Supplier_Model->get_all_suppliers_for_mapping();

		// Initialize arrays for tracking
		$mapped = array();
		$unmapped = array();
		$name_column = null;

		// Check for required columns: "Company Name" and "Supplier Code"
		$first_row = reset($csv_data);
		if (empty($first_row)) {
			$error = "Error: CSV file has no data rows.";
			if ($this->input->is_cli_request()) {
				die($error . "\n");
			} else {
				$titles = array('tab_title' => 'HolidayGoGoGo | Map Supplier Codes', 'breadcrumb_title' => 'Supplier >> Map Supplier Codes');
				$array['error'] = $error;
				$this->load->view('layout/header', $titles);
				$this->load->view('supplier/map_codes', $array);
				$this->load->view('layout/footer');
				return;
			}
		}

		// Get all column names from CSV (case-insensitive check)
		$csv_columns = array_keys($first_row);
		$csv_columns_lower = array_map('strtolower', array_map('trim', $csv_columns));
		
		// Check for "Company Name" and "Supplier Code" columns
		$company_name_found = false;
		$supplier_code_found = false;
		$supplier_code_column = null;
		
		foreach ($csv_columns as $col) {
			$col_lower = strtolower(trim($col));
			if ($col_lower === 'company name') {
				$name_column = $col;
				$company_name_found = true;
			}
			if ($col_lower === 'supplier code') {
				$supplier_code_column = $col;
				$supplier_code_found = true;
			}
		}

		// Throw error if required columns are missing
		if (!$company_name_found || !$supplier_code_found) {
			$missing = array();
			if (!$company_name_found) {
				$missing[] = "Company Name";
			}
			if (!$supplier_code_found) {
				$missing[] = "Supplier Code";
			}
			
			$error = "Error: Required columns not found in CSV: " . implode(", ", $missing) . ". Found columns: " . implode(", ", $csv_columns);
			
			if ($this->input->is_cli_request()) {
				die($error . "\n");
			} else {
				$titles = array('tab_title' => 'HolidayGoGoGo | Map Supplier Codes', 'breadcrumb_title' => 'Supplier >> Map Supplier Codes');
				$array['error'] = $error;
				$this->load->view('layout/header', $titles);
				$this->load->view('supplier/map_codes', $array);
				$this->load->view('layout/footer');
				return;
			}
		}

		// Get supplier ID mapping for updates
		$this->db->select('SupplierID, Name, SupplierCode');
		$this->db->where('Status', 'Y');
		$suppliers = $this->db->get('supplier')->result();
		$supplier_id_mapping = array();
		foreach ($suppliers as $supplier) {
			$supplier_id_mapping[trim(strtolower($supplier->Name))] = array(
				'id' => $supplier->SupplierID,
				'name' => $supplier->Name,
				'code' => $supplier->SupplierCode
			);
		}

		// Check for duplicate company names with different codes
		$company_code_map = array();
		$duplicate_warnings = array();
		
		foreach ($csv_data as $row_num => $row) {
			$name = isset($row[$name_column]) ? trim($row[$name_column]) : '';
			$csv_supplier_code = isset($row[$supplier_code_column]) ? trim($row[$supplier_code_column]) : '';
			
			if (empty($name)) {
				continue; // Skip empty names
			}
			
			$name_lower = strtolower($name);
			$row_display = $row_num + 2; // +2 because row 1 is header, and array is 0-indexed
			
			if (isset($company_code_map[$name_lower])) {
				// Company name already seen
				if ($company_code_map[$name_lower]['code'] != $csv_supplier_code) {
					// Different code for same company name
					if (!isset($duplicate_warnings[$name_lower])) {
						$duplicate_warnings[$name_lower] = array(
							'name' => $name,
							'codes' => array($company_code_map[$name_lower]['code'], $csv_supplier_code),
							'rows' => array($company_code_map[$name_lower]['row'], $row_display)
						);
					} else {
						if (!in_array($csv_supplier_code, $duplicate_warnings[$name_lower]['codes'])) {
							$duplicate_warnings[$name_lower]['codes'][] = $csv_supplier_code;
						}
						if (!in_array($row_display, $duplicate_warnings[$name_lower]['rows'])) {
							$duplicate_warnings[$name_lower]['rows'][] = $row_display;
						}
					}
				}
			} else {
				$company_code_map[$name_lower] = array(
					'code' => $csv_supplier_code,
					'row' => $row_display
				);
			}
		}

		// Process each row
		$updated_count = 0;
		foreach ($csv_data as $row_num => $row) {
			$name = isset($row[$name_column]) ? trim($row[$name_column]) : '';
			$csv_supplier_code = isset($row[$supplier_code_column]) ? trim($row[$supplier_code_column]) : '';
			
			if (empty($name)) {
				continue; // Skip empty names
			}

			// Only exact match (case-insensitive)
			$name_lower = strtolower($name);
			$supplier_info = null;
			$matched_name = null;
			
			if (isset($supplier_id_mapping[$name_lower])) {
				$supplier_info = $supplier_id_mapping[$name_lower];
				$matched_name = $supplier_info['name'];
			}
			
			if ($supplier_info) {
				$db_supplier_code = $supplier_info['code'];
				$update_status = 'not_updated';
				$original_db_code = $db_supplier_code; // Keep original for comparison
				
				// Update database if CSV supplier code is not empty and different from DB
				if (!empty($csv_supplier_code) && $csv_supplier_code != $db_supplier_code) {
					$this->db->where('SupplierID', $supplier_info['id']);
					$this->db->update('supplier', array('SupplierCode' => $csv_supplier_code));
					if ($this->db->affected_rows() > 0) {
						$update_status = 'updated';
						$updated_count++;
						// Update the code in our mapping for display
						$db_supplier_code = $csv_supplier_code;
					} else {
						$update_status = 'update_failed';
					}
				} elseif (empty($csv_supplier_code)) {
					$update_status = 'csv_empty';
				} elseif ($csv_supplier_code == $db_supplier_code) {
					$update_status = 'already_exists';
				} elseif (empty($db_supplier_code) && !empty($csv_supplier_code)) {
					// DB is NULL/empty but CSV has code - should update
					$this->db->where('SupplierID', $supplier_info['id']);
					$this->db->update('supplier', array('SupplierCode' => $csv_supplier_code));
					if ($this->db->affected_rows() > 0) {
						$update_status = 'updated';
						$updated_count++;
						$db_supplier_code = $csv_supplier_code;
					} else {
						$update_status = 'update_failed';
					}
				}
				
				$mapped[] = array(
					'row' => $row_num + 2, // +2 because row 1 is header, and array is 0-indexed
					'supplier_id' => $supplier_info['id'],
					'name' => $name,
					'csv_supplier_code' => $csv_supplier_code,
					'db_supplier_code' => $db_supplier_code,
					'original_db_code' => $original_db_code, // Original DB code before update
					'update_status' => $update_status
				);
			} else {
				$unmapped[] = array(
					'row' => $row_num + 2, // +2 because row 1 is header, and array is 0-indexed
					'name' => $name,
					'csv_supplier_code' => $csv_supplier_code
				);
			}
		}

		// Generate log file
		$log_dir = FCPATH . 'application/logs/';
		if (!is_dir($log_dir)) {
			mkdir($log_dir, 0755, true);
		}

		$log_file = $log_dir . 'supplier_mapping_' . date('Ymd_His') . '.log';
		$log_content = "========================================\n";
		$log_content .= "SUPPLIER CODE MAPPING LOG\n";
		$log_content .= "========================================\n";
		$log_content .= "Date: " . date('Y-m-d H:i:s') . "\n";
		$log_content .= "CSV File: " . $csv_file . "\n";
		$log_content .= "Total Rows Processed: " . count($csv_data) . "\n";
		$log_content .= "========================================\n\n";

		// Write mapped items
		$log_content .= "MAPPED SUPPLIERS (" . count($mapped) . ")\n";
		$log_content .= "========================================\n";
		if (!empty($mapped)) {
			foreach ($mapped as $item) {
				$log_content .= "Row " . $item['row'] . ": ";
				$log_content .= "Supplier ID: " . $item['supplier_id'];
				$log_content .= " | Name: \"" . $item['name'] . "\"";
				$log_content .= " | CSV Supplier Code: \"" . ($item['csv_supplier_code'] ?: 'EMPTY') . "\"";
				$log_content .= " | DB Supplier Code: \"" . ($item['db_supplier_code'] ?: 'NULL') . "\"";
				
				// Show update status
				if (isset($item['update_status'])) {
					switch ($item['update_status']) {
						case 'updated':
							$log_content .= " [UPDATED - Changed from \"" . ($item['original_db_code'] ?: 'NULL') . "\" to \"" . $item['csv_supplier_code'] . "\"]";
							break;
						case 'already_exists':
							$log_content .= " [ALREADY EXISTS - Code matches: \"" . $item['csv_supplier_code'] . "\"]";
							break;
						case 'csv_empty':
							$log_content .= " [CSV CODE EMPTY - NOT UPDATED]";
							break;
						case 'update_failed':
							$log_content .= " [UPDATE FAILED]";
							break;
						case 'not_updated':
							$log_content .= " [NOT UPDATED]";
							break;
					}
				}
				
				$log_content .= "\n";
			}
		} else {
			$log_content .= "No suppliers were mapped.\n";
		}
		$log_content .= "\n";

		// Write unmapped items
		$log_content .= "UNMAPPED SUPPLIERS (" . count($unmapped) . ")\n";
		$log_content .= "========================================\n";
		if (!empty($unmapped)) {
			foreach ($unmapped as $item) {
				$log_content .= "Row " . $item['row'] . ": ";
				$log_content .= "Name: \"" . $item['name'] . "\"";
				if (isset($item['csv_supplier_code'])) {
					$log_content .= " | CSV Supplier Code: \"" . ($item['csv_supplier_code'] ?: 'EMPTY') . "\"";
				}
				$log_content .= "\n";
			}
		} else {
			$log_content .= "All suppliers were successfully mapped.\n";
		}
		$log_content .= "\n";

		// Write duplicate warnings to log
		if (!empty($duplicate_warnings)) {
			$log_content .= "WARNINGS - DUPLICATE COMPANY NAMES WITH DIFFERENT CODES\n";
			$log_content .= "========================================\n";
			foreach ($duplicate_warnings as $warning) {
				$log_content .= "Company: \"" . $warning['name'] . "\" appears with different codes: ";
				$log_content .= implode(", ", array_map(function($code) { return "\"" . ($code ?: 'EMPTY') . "\""; }, $warning['codes']));
				$log_content .= " at rows: " . implode(", ", $warning['rows']) . "\n";
			}
			$log_content .= "\n";
		}

		// Write summary
		$log_content .= "SUMMARY\n";
		$log_content .= "========================================\n";
		$log_content .= "Total Rows: " . count($csv_data) . "\n";
		$log_content .= "Successfully Mapped: " . count($mapped) . "\n";
		$log_content .= "Unmapped: " . count($unmapped) . "\n";
		$log_content .= "Database Records Updated: " . $updated_count . "\n";
		if (!empty($duplicate_warnings)) {
			$log_content .= "Warnings: " . count($duplicate_warnings) . " company name(s) have different codes\n";
		}
		$log_content .= "Success Rate: " . (count($csv_data) > 0 ? round((count($mapped) / count($csv_data)) * 100, 2) : 0) . "%\n";
		$log_content .= "========================================\n";

		// Write log file
		file_put_contents($log_file, $log_content);

		// Clean up uploaded temp file if it was uploaded
		if (isset($upload_data) && file_exists($csv_file)) {
			@unlink($csv_file);
		}

		// Output results
		if ($this->input->is_cli_request()) {
			echo $log_content;
			echo "\nLog file saved to: " . $log_file . "\n";
		} else {
			// Display results in UI
			$titles = array('tab_title' => 'HolidayGoGoGo | Map Supplier Codes - Results', 'breadcrumb_title' => 'Supplier >> Map Supplier Codes >> Results');
			// Store data in session for download
			$this->session->set_userdata('mapping_results', array(
				'mapped' => $mapped,
				'unmapped' => $unmapped,
				'duplicate_warnings' => $duplicate_warnings,
				'log_file' => basename($log_file)
			));
			
			$array = array(
				'mapped' => $mapped,
				'unmapped' => $unmapped,
				'duplicate_warnings' => $duplicate_warnings,
				'summary' => array(
					'total' => count($csv_data),
					'mapped_count' => count($mapped),
					'unmapped_count' => count($unmapped),
					'updated_count' => $updated_count,
					'warning_count' => count($duplicate_warnings),
					'success_rate' => count($csv_data) > 0 ? round((count($mapped) / count($csv_data)) * 100, 2) : 0
				),
				'log_file' => $log_file,
				'log_content' => $log_content
			);
			$this->load->view('layout/header', $titles);
			$this->load->view('supplier/map_codes_results', $array);
			$this->load->view('layout/footer');
		}
	}
}