<?php

require FCPATH.'vendor/autoload.php';  
use PhpOffice\PhpSpreadsheet\Spreadsheet;  
use PhpOffice\PhpSpreadsheet\Writer\Xlxs;

class Product extends MY_Controller
{
	function __construct()
	{
		parent::__construct();
		$this->load->model('Product_Model');
		$this->load->model('Universal_Model');
		$this->load->model('Product_Package_Checklist_Model');
		$this->load->model('Package_Checklist_Model');
		$this->load->model('Cronjob_Model');
		$this->load->helper('product_tour_fields');
	}

	function index()
	{
		$titles = array('tab_title' => 'HolidayGoGoGo | Product', 'breadcrumb_title' => 'Product');
		$array['products'] = $this->Product_Model->Read_Products1();
		$array['categories'] = $this->Product_Model->Read_Categories();
		$array['suppliers'] = $this->Product_Model->Read_Suppliers();
		$array['checklists'] = $this->Product_Package_Checklist_Model->Read_Package_Checklists();
		foreach($array['products'] as $product) {
            $product->RetailPrice = $product->RetailPrice == 0.00 ? '' : number_format($product->RetailPrice, 2, '.', ',');
            $product->SupplierPrice = $product->SupplierPrice == 0.00 ? '' : number_format($product->SupplierPrice, 2, '.', ',');
        }
		$this->load->view('layout/header', $titles);
		$this->load->view('product/index', $array);
		$this->load->view('layout/footer');
	}

	function Create()
	{
		if($this->input->is_ajax_request()) {
			$product_id = $this->Product_Model->Create();
			
			// Only proceed with checklists if product was created successfully
			if($product_id && $product_id > 0) {
				// Get checklist IDs from POST
				$checklist_ids = $this->input->post('checklist_ids');
				if(!empty($checklist_ids) && is_array($checklist_ids)) {
					// Normalize to integers
					$checklist_ids = array_map('intval', $checklist_ids);
					$checklist_ids = array_values(array_filter($checklist_ids, function($id) { return $id > 0; }));
				} else {
					$checklist_ids = array();
				}
				
				// Save checklists for the new product (required ones will be auto-added by the model)
				$this->Product_Package_Checklist_Model->Bulk_Update_Product_Checklists($product_id, $checklist_ids);
				$this->Cronjob_Model->check_and_notify_no_checklist($product_id);
			}
		} else {
			$titles = array('tab_title' => 'HolidayGoGoGo | Product', 'breadcrumb_title' => 'Product >> Create');
			$array = array('ProductID' => 'NA', 'ProductCode' => 'NA', 'is_child_or_infant' => 0, 'has_supplier_deposit' => 0);
			$array['categories'] = $this->Product_Model->Read_Categories();
			$array['suppliers'] = $this->Product_Model->Read_Suppliers();
			
			// Get package checklists for create page
			$this->load->model('Product_Package_Checklist_Model');
			$array['package_checklists'] = $this->Product_Package_Checklist_Model->Read_Package_Checklists();
			$array['selected_checklist_ids'] = array(); // Empty for new product
			
			$this->load->view('layout/header', $titles);
			$this->load->view('product/product', $array);
			$this->load->view('layout/footer');
		}
	}

	function Update()
	{
		if($this->input->is_ajax_request()) {
			if(count($this->input->post('product')[0]) > 3) {
				$this->Product_Model->Update();
			}
		} else {
			$valid_product_id = $this->Universal_Model->Validate_Id('ProductID', $this->input->get('product_id'), 'product');
			if($valid_product_id) {
				$titles = array('tab_title' => 'HolidayGoGoGo | Product', 'breadcrumb_title' => 'Product >> Update');
				$array = $this->Product_Model->Read_Product();
				$array['RetailPrice'] = $array['RetailPrice'] == 0.00 ? null : number_format($array['RetailPrice'], 2, '.', ',');
            	$array['SupplierPrice'] = $array['SupplierPrice'] == 0.00 ? null : number_format($array['SupplierPrice'], 2, '.', ',');
				$array['is_child_or_infant'] = $array['is_child_or_infant'] ?? 0;
			$array['has_supplier_deposit'] = $array['has_supplier_deposit'] ?? 0;
				$array['categories'] = $this->Product_Model->Read_Categories();
				$array['suppliers'] = $this->Product_Model->Read_Suppliers();
            	$array['maxNameLength'] = 99 - strlen($array['ProductCode']) - 3;
				
				// Get package checklists for this product
				$array['package_checklists'] = $this->Product_Package_Checklist_Model->Read_Package_Checklists();
				$array['selected_checklist_ids'] = $this->Product_Package_Checklist_Model->Get_Checklists_For_Product($array['ProductID']);
				
				// Ensure required checklists are always included
				$required_ids = array();
				foreach($array['package_checklists'] as $checklist) {
					if(isset($checklist->is_required) && $checklist->is_required == 1) {
						$required_ids[] = $checklist->ID;
					}
				}
				foreach($required_ids as $req_id) {
					if(!in_array($req_id, $array['selected_checklist_ids'])) {
						$array['selected_checklist_ids'][] = $req_id;
					}
				}
				
				$this->load->view('layout/header', $titles);
				$this->load->view('product/product', $array);
				$this->load->view('layout/footer');
			} else {
				redirect('Product');
			}
		}
	}
	
	function Delete() 
	{
		$this->Universal_Model->Delete('ProductID', $this->input->get('product_id'), 'product');
	}

	function UpdateChecklists()
	{
		if($this->input->is_ajax_request()) {
			$product_id = $this->input->post('product_id');
			$checklist_ids = $this->input->post('checklist_ids');
			
			if(empty($product_id)) {
				$this->output
					->set_content_type('application/json')
					->set_output(json_encode(['success' => false, 'message' => 'Product ID is required']));
				return;
			}
			
			// Ensure checklist_ids is an array
			if(!is_array($checklist_ids)) {
				$checklist_ids = array();
			}
			
			// Convert string IDs to integers (preserve order!)
			$checklist_ids = array_map('intval', $checklist_ids);
			$checklist_ids = array_filter($checklist_ids, function($id) { return $id > 0; }); // Remove zeros/negatives
			$checklist_ids = array_values($checklist_ids); // Re-index array to preserve order
			
			// Log the order being saved
			log_message('debug', 'UpdateChecklists - Product ID: ' . $product_id . ', Order: ' . json_encode($checklist_ids));
			
			$result = $this->Product_Package_Checklist_Model->Bulk_Update_Product_Checklists($product_id, $checklist_ids);

			if($result) {
				$this->Cronjob_Model->check_and_notify_no_checklist($product_id);
				$this->output
					->set_content_type('application/json')
					->set_output(json_encode(['success' => true, 'message' => 'Product checklists updated successfully', 'order' => $checklist_ids]));
			} else {
				$this->output
					->set_content_type('application/json')
					->set_output(json_encode(['success' => false, 'message' => 'Failed to update product checklists']));
			}
		} else {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode(['success' => false, 'message' => 'Invalid request']));
		}
	}
	
	function BulkAddChecklist()
	{
		if($this->input->is_ajax_request()) {
			$checklist_ids = $this->input->post('checklist_ids');
			$product_ids = $this->input->post('product_ids');

			if(empty($checklist_ids) || !is_array($checklist_ids) || empty($product_ids) || !is_array($product_ids)) {
				$this->output
					->set_content_type('application/json')
					->set_output(json_encode(['success' => false, 'message' => 'Checklist(s) and product IDs are required']));
				return;
			}

			$checklist_ids = array_map('intval', $checklist_ids);
			$checklist_ids = array_values(array_filter($checklist_ids, function($id) { return $id > 0; }));

			$success_count = 0;
			$fail_count = 0;

			foreach($product_ids as $product_id) {
				$product_id = (int)$product_id;
				if($product_id <= 0) continue;

				// Get current checklists for this product
				$current_ids = $this->Product_Package_Checklist_Model->Get_Checklists_For_Product($product_id);

				// Add the new checklists if not already present
				foreach($checklist_ids as $checklist_id) {
					if(!in_array($checklist_id, $current_ids)) {
						$current_ids[] = $checklist_id;
					}
				}

				$result = $this->Product_Package_Checklist_Model->Bulk_Update_Product_Checklists($product_id, $current_ids);
				if($result) {
					$success_count++;
					$this->Cronjob_Model->check_and_notify_no_checklist($product_id);
				} else {
					$fail_count++;
				}
			}

			$checklist_count = count($checklist_ids);
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode([
					'success' => true,
					'message' => "{$checklist_count} checklist(s) added to {$success_count} product(s)." . ($fail_count > 0 ? " {$fail_count} failed." : ''),
					'success_count' => $success_count,
					'fail_count' => $fail_count
				]));
		} else {
			$this->output
				->set_content_type('application/json')
				->set_output(json_encode(['success' => false, 'message' => 'Invalid request']));
		}
	}

	function Download() {
		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$spreadsheet->getActiveSheet()->setTitle('Product Records');
		$spreadsheet->getProperties()->setCreator('HolidayGoGoGo');
		$spreadsheet->getActiveSheet()->setCellValue('A1', 'CATEGORY');
		$spreadsheet->getActiveSheet()->setCellValue('B1', 'SUPPLIER');
		$spreadsheet->getActiveSheet()->setCellValue('C1', 'PRODUCT CODE');
		$spreadsheet->getActiveSheet()->setCellValue('D1', 'NAME');
		$spreadsheet->getActiveSheet()->setCellValue('E1', 'RETAIL PRICE');
		$spreadsheet->getActiveSheet()->setCellValue('F1', 'SUPPLIER PRICE');
		$row = 2;
		$products = $this->Product_Model->Read_Products2();
		$spreadsheet->getActiveSheet()->getStyle('A1:F1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
		$spreadsheet->getActiveSheet()->getStyle('A1:F1')->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE);
		$spreadsheet->getActiveSheet()->getStyle('A1:F1')->getFont()->setBold(true);
		if(!empty($products)) {
			foreach($products as $product) {
				$product->RetailPrice = $product->RetailPrice == 0.00 ? '' : $product->RetailPrice;
				$product->SupplierPrice = $product->SupplierPrice == 0.00 ? '' : $product->SupplierPrice;
				$spreadsheet->getActiveSheet()->setCellValueExplicit('A' . $row, $product->Category, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('B' . $row, $product->Supplier, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('C' . $row, $product->ProductCode, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValueExplicit('D' . $row, $product->Product, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
				$spreadsheet->getActiveSheet()->setCellValue('E' . $row, $product->RetailPrice);
				$spreadsheet->getActiveSheet()->setCellValue('F' . $row, $product->SupplierPrice);

				$spreadsheet->getActiveSheet()->getStyle('E' . $row)->getNumberFormat()->setFormatCode('"RM"#,##0.00_-');
				$spreadsheet->getActiveSheet()->getStyle('F' . $row)->getNumberFormat()->setFormatCode('"RM"#,##0.00_-');
				
				$row++;
			}
			$spreadsheet->getActiveSheet()->getStyle('A:F')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
		} else {
			$spreadsheet->getActiveSheet()->mergeCells('A2:F2');
			$spreadsheet->getActiveSheet()->getCell('A2')->setValue('Product Records Not Found');
			$spreadsheet->getActiveSheet()->getStyle('A:F')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
		}
		$spreadsheet->getActiveSheet()->getColumnDimension('A')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('B')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('C')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('D')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('E')->setWidth(35);
		$spreadsheet->getActiveSheet()->getColumnDimension('F')->setWidth(35);
		$product_records = 'PRODUCT_RECORDS_' . date('Ymd') . '.xlsx';
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment;filename="' . $product_records . '"');
		header('Cache-Control: max-age=0');
		header('Cache-Control: max-age=1');
		$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
		$writer->save('php://output');
	}

	// Download a blank .xlsx template (same columns as the "Product Records"
	// export) so users can fill one product per row and re-upload via Import().
	function Import_Template() {
		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->setTitle('Product Import');
		$spreadsheet->getProperties()->setCreator('HolidayGoGoGo');

		$col = 'A';
		foreach (Product_Model::IMPORT_COLUMNS as $label) {
			$sheet->setCellValue($col . '1', $label);
			$sheet->getColumnDimension($col)->setWidth(28);
			$col++;
		}
		$last = chr(ord('A') + count(Product_Model::IMPORT_COLUMNS) - 1);
		$sheet->getStyle('A1:' . $last . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
		$sheet->getStyle('A1:' . $last . '1')->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE);
		$sheet->getStyle('A1:' . $last . '1')->getFont()->setBold(true);

		// One greyed sample row so the expected shape is obvious.
		$sample = array('Hotel', 'Sunrise Travel', '', 'Deluxe Sea View Room', '1200.00', '900.00');
		$col = 'A';
		foreach ($sample as $val) {
			$sheet->setCellValueExplicit($col . '2', $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$col++;
		}
		$sheet->getStyle('A2:' . $last . '2')->getFont()->getColor()->setARGB('FF9E9E9E');

		$filename = 'PRODUCT_IMPORT_TEMPLATE_' . date('Ymd') . '.xlsx';
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment;filename="' . $filename . '"');
		header('Cache-Control: max-age=0');
		$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
		$writer->save('php://output');
	}

	// Bulk upsert products from a filled template: PRODUCT CODE matching an
	// existing product updates it, blank/unknown code creates a new product.
	function Import() {
		if ($this->input->server('REQUEST_METHOD') !== 'POST' || empty($_FILES['import_file']['name'])) {
			redirect(base_url('Product'));
			return;
		}

		$file = $_FILES['import_file'];
		if ($file['error'] !== UPLOAD_ERR_OK) {
			$this->session->set_flashdata('product_import_error', 'Upload failed. Please try again.');
			redirect(base_url('Product'));
			return;
		}
		$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
		if (!in_array($ext, array('xlsx', 'xls'), true)) {
			$this->session->set_flashdata('product_import_error', 'Please upload an Excel file (.xlsx or .xls).');
			redirect(base_url('Product'));
			return;
		}
		if ($file['size'] > 10 * 1024 * 1024) {
			$this->session->set_flashdata('product_import_error', 'File too large. Maximum size is 10MB.');
			redirect(base_url('Product'));
			return;
		}

		// Save the upload as a backup, then keep only the newest 3.
		$dir = FCPATH . 'assets/upload/product_import/';
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}
		$dest = $dir . 'product_import_' . time() . '.' . $ext;
		if (!move_uploaded_file($file['tmp_name'], $dest)) {
			$this->session->set_flashdata('product_import_error', 'Could not save the uploaded file.');
			redirect(base_url('Product'));
			return;
		}
		$existing = array();
		foreach (glob($dir . 'product_import_*') as $path) {
			$existing[] = basename($path);
		}
		foreach (Product_Model::Prune_Import_Backups($existing, 3) as $old) {
			@unlink($dir . $old);
		}

		try {
			$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($dest);
			$rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
		} catch (\Exception $e) {
			$this->session->set_flashdata('product_import_error', 'Could not read the Excel file. Please use the downloaded template.');
			redirect(base_url('Product'));
			return;
		}

		$parsed = Product_Model::Parse_Import_Rows(
			$rows,
			$this->Product_Model->Category_Name_Map(),
			$this->Product_Model->Supplier_Name_Map()
		);
		if (empty($parsed)) {
			$this->session->set_flashdata('product_import_error', 'No product rows found in the file. Nothing was imported.');
			redirect(base_url('Product'));
			return;
		}

		$admin_id = (int) $this->session->userdata('admin_id');
		$summary = $this->Product_Model->Bulk_Import($parsed, $admin_id);

		$msg = count($summary['created']) . ' product(s) created, ' . count($summary['updated']) . ' updated.';
		if (!empty($summary['failed'])) {
			$lines = array();
			foreach ($summary['failed'] as $f) {
				$lines[] = 'row ' . $f['line'] . ' — ' . $f['reason'];
			}
			$msg .= ' Failed ' . count($lines) . ' row(s): ' . implode('; ', $lines) . '.';
		}

		// Anything created/updated -> success banner (with any fail notes); nothing -> error.
		$key = (!empty($summary['created']) || !empty($summary['updated'])) ? 'product_import_success' : 'product_import_error';
		$this->session->set_flashdata($key, $msg);
		redirect(base_url('Product'));
	}

	function Detect() {
		$redundant_name = $this->Product_Model->Detect();
		if($redundant_name) {
			echo json_encode(true);
		} else {
			echo json_encode(false);
		}
	}

	function GetMaxNameLength() {
		$category_id = $this->input->post('category_id');
		$max_name_length = $this->Product_Model->Get_Max_Name_Length($category_id);
		echo json_encode($max_name_length);
	}

	/**
	 * AJAX: "Extract from link" on the Product form. Scrapes the pasted tour URL
	 * and uses OpenAI to fill the Tour Details + Flights tabs. Returns
	 * {success, fields:{Col=>value}, flights:[...], cost} — the JS applies these
	 * to the form (nothing is saved until the user hits Update/Create).
	 */
	function Extract() {
		$this->output->set_content_type('application/json');
		if ( ! $this->input->is_ajax_request()) {
			echo json_encode(array('success' => false, 'message' => 'Invalid request'));
			return;
		}
		$url = trim((string) $this->input->post('url'));
		if ($url === '' || ! preg_match('#^https?://#i', $url)) {
			echo json_encode(array('success' => false, 'message' => 'Please enter a valid http(s) link.'));
			return;
		}

		@set_time_limit(600);
		$this->load->helper('product_extract');
		$this->load->helper('product_tour_fields');
		$this->load->library('CompetitorAnalysisService');
		$this->competitoranalysisservice->set_usage_feature('Product Extraction');
		try {
			$res    = $this->competitoranalysisservice->extract_for_product($url);
			$mapped = product_extract_map($res['data']);
			echo json_encode(array(
				'success' => true,
				'fields'  => $mapped['fields'],
				'flights' => $mapped['flights'],
				'cost'    => isset($res['cost']) ? $res['cost'] : 0,
			));
		} catch (Exception $e) {
			echo json_encode(array('success' => false, 'message' => $e->getMessage()));
		}
	}

	/**
	 * AJAX: persist the scraped source URL for an existing product the instant an
	 * extract is applied, so the "Last extracted from" line survives a page
	 * refresh without waiting for the full Update save. New products (no ID yet)
	 * save it together with the record on Create.
	 */
	function Update_Source_Url() {
		$this->output->set_content_type('application/json');
		if ( ! $this->input->is_ajax_request()) {
			echo json_encode(array('success' => false, 'message' => 'Invalid request'));
			return;
		}
		$product_id = (int) $this->input->post('product_id');
		$url        = trim((string) $this->input->post('url'));
		if ($product_id <= 0 || $url === '' || ! preg_match('#^https?://#i', $url)) {
			echo json_encode(array('success' => false, 'message' => 'Invalid product or link.'));
			return;
		}
		if ( ! $this->Universal_Model->Validate_Id('ProductID', $product_id, 'product')) {
			echo json_encode(array('success' => false, 'message' => 'Product not found.'));
			return;
		}
		$this->Product_Model->Update_Source_Url($product_id, $url);
		echo json_encode(array('success' => true));
	}
}