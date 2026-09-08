<?php
class Product_Model extends CI_Model
{
	function Read_Product()
	{
		$this->db->select('ProductID, product.SupplierID, ProductCode, product.Name As Product, RetailPrice, SupplierPrice, is_child_or_infant, has_supplier_deposit, category.Name As Category,
			Destination, Duration, DepartureCity, Difficulty, SuitableAge, TargetTraveller,
			Countries, Cities, Themes, TourStyle, LocalTransport,
			Flights, MealBreakfast, MealLunch, MealDinner,
			Hotels, ScenicHighlights, ShoppingStops,
			Inclusions, Exclusions, OptionalTours, Itinerary, SpecialRemarks, SourceUrl');
		$this->db->join('category', 'category.CategoryID = product.CategoryID', 'left');
		$this->db->where('ProductID', $this->input->get('product_id'));
		return $this->db->get('product')->row_array();
	}

	function Read_Products1()
	{
		$this->db->select('ProductID, ProductCode, product.Name As Product, RetailPrice, SupplierPrice, product.Status');
		if(!empty($this->input->get('category'))) {
			$this->db->where('product.CategoryID', $this->input->get('category'));
		}
		if(!empty($this->input->get('supplier'))) {
			$this->db->where('product.SupplierID', $this->input->get('supplier'));
		}
		if(!empty($this->input->get('product_code'))) {
			$this->db->where('ProductCode', $this->input->get('product_code'));
		}
		if(!empty($this->input->get('name'))) {
			$this->db->where('product.Name', $this->input->get('name'));
		}
		$this->db->where('product.Status', 'Y');
		$this->db->order_by('ProductCode', 'ASC');
		$this->db->order_by('product.Name', 'ASC');
		return $this->db->get('product')->result();
	}

	function Read_Products2()
	{
		$this->db->select('ProductCode, product.Name As Product, RetailPrice, SupplierPrice, category.Name As Category, supplier.Name As Supplier');
		$this->db->join('category', 'category.CategoryID = product.CategoryID', 'left');
		$this->db->join('supplier', 'supplier.SupplierID = product.SupplierID', 'left');
		if(!empty($this->input->get('category'))) {
			$this->db->where('product.CategoryID', $this->input->get('category'));
		}
		if(!empty($this->input->get('supplier'))) {
			$this->db->where('product.SupplierID', $this->input->get('supplier'));
		}
		if(!empty($this->input->get('product_code'))) {
			$this->db->where('ProductCode', $this->input->get('product_code'));
		}
		if(!empty($this->input->get('name'))) {
			$this->db->where('product.Name', $this->input->get('name'));
		}
		$this->db->where('product.Status', 'Y');
		$this->db->order_by('ProductCode', 'ASC');
		$this->db->order_by('product.Name', 'ASC');
		return $this->db->get('product')->result();
	}

	/**
	 * Active products that carry real tour detail, for the Competitor Analysis
	 * "Comparison vs Our Products" block. Only products with at least one of the
	 * key tour fields filled (Destination / Itinerary / Inclusions) are returned
	 * — plain line-item products (a hotel room, a transfer) carry no signal for a
	 * tour-vs-tour comparison and would only waste prompt tokens. Newest first.
	 */
	function Read_For_Comparison($limit = 40)
	{
		$this->db->select('ProductCode, product.Name As Name, RetailPrice,
			Destination, Duration, DepartureCity, Difficulty, SuitableAge, TargetTraveller,
			Countries, Cities, Themes, TourStyle, LocalTransport,
			Flights, MealBreakfast, MealLunch, MealDinner,
			Hotels, ScenicHighlights, ShoppingStops,
			Inclusions, Exclusions, OptionalTours, Itinerary, SpecialRemarks');
		$this->db->where('product.Status', 'Y');
		$this->db->group_start();
		$this->db->where("(Destination IS NOT NULL AND Destination != '')");
		$this->db->or_where("(Itinerary IS NOT NULL AND Itinerary != '')");
		$this->db->or_where("(Inclusions IS NOT NULL AND Inclusions != '')");
		$this->db->group_end();
		$this->db->order_by('ProductID', 'DESC');
		if ((int) $limit > 0) {
			$this->db->limit((int) $limit);
		}
		return $this->db->get('product')->result();
	}

	function Read_Categories()
	{
		$this->db->select('CategoryID, Name');
		$this->db->where('Status', 'Y');
		$this->db->order_by('Name', 'ASC');
		return $this->db->get('category')->result();
	}

	function Read_Suppliers()
	{
		$this->db->select('SupplierID, Name');
		$this->db->where('Status', 'Y');
		$this->db->order_by('Name', 'ASC');
		return $this->db->get('supplier')->result();
	}
	
	function Create()
	{
		$product_data = json_decode(json_encode($this->input->post('product')), true);
		
		// Use insert instead of insert_batch for single record to get proper insert_id
		if(!empty($product_data) && isset($product_data[0])) {
			$this->db->insert('product', $product_data[0]);
			$product_id = $this->db->insert_id();
		} else {
			$product_id = false;
		}

		if($product_id && $product_id > 0) {
			$this->db->select('category_code.Name');
			$this->db->join('category_code', 'category_code.CategoryCodeID = category.CategoryCodeID', 'left');
			$this->db->where('CategoryID', $this->input->post('category_id'));
			$result = $this->db->get('category')->row();
			
			if($result && isset($result->Name)) {
				$category_code = $result->Name;
				$this->db->set('ProductCode', $category_code . '-' . $product_id);
				$this->db->where('ProductID', $product_id);
				$this->db->update('product');
			}
		}
		
		return $product_id;
	}
	
	function Update()
	{
		$this->db->update_batch('product', json_decode(json_encode($this->input->post('product'))), 'ProductID');
	}

	// Persist the scraped source URL on its own (called right after an extract is
	// applied) so the "Last extracted from" line survives a refresh even before
	// the user saves the rest of the extracted fields.
	function Update_Source_Url($product_id, $url)
	{
		$this->db->where('ProductID', $product_id);
		$this->db->update('product', array('SourceUrl' => $url));
	}

	function Detect()
	{
		$this->db->where('Name', $this->input->post('name'));
		if($this->db->get('product')->row()) {
			return true;
		} else {
			return false;
		}
	}

	function Get_Max_Name_Length($category_id)
	{
		$this->db->select('category_code.Name');
		$this->db->join('category_code', 'category_code.CategoryCodeID = category.CategoryCodeID', 'left');
		$this->db->where('CategoryID', $category_id);
		$category_code = $this->db->get('category')->row()->Name;

		$next_id = $this->db->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product'")->row()->AUTO_INCREMENT;

		return 99 - strlen($category_code) - 1 - strlen($next_id);
	}

	// ------------------------------------------------------------------
	// Bulk import via Excel (download template -> fill rows -> re-upload).
	// A row whose PRODUCT CODE matches an existing product UPDATES it;
	// a blank / unknown code CREATES a new product (code auto-generated,
	// same {CategoryCode}-{ProductID} scheme as the single-create form).
	// ------------------------------------------------------------------

	// Column order of the import/template sheet. Mirrors Product::Download()
	// so an exported "Product Records" file can be edited and re-uploaded.
	// The 0-based index is how PhpSpreadsheet's toArray(null,true,true,false)
	// hands each row back. CATEGORY, SUPPLIER and NAME are mandatory.
	const IMPORT_COLUMNS = array(
		0 => 'CATEGORY',
		1 => 'SUPPLIER',
		2 => 'PRODUCT CODE',
		3 => 'NAME',
		4 => 'RETAIL PRICE',
		5 => 'SUPPLIER PRICE',
	);

	/**
	 * Turn raw uploaded sheet rows into normalized product entries. Category and
	 * supplier are matched by name (case-insensitive, trimmed) against the passed
	 * maps and resolved to their IDs; RM/comma-formatted prices are parsed to
	 * floats. Pure + DB-free so it can be unit-tested and so the controller only
	 * handles I/O.
	 *
	 * Each returned entry is:
	 *   array('line' => <1-based sheet row>, 'error' => null|string,
	 *         'ProductCode','Name','CategoryID','SupplierID','RetailPrice','SupplierPrice')
	 * The header and fully-blank rows are dropped. A row missing/invalid on any
	 * mandatory field (Category, Supplier, Name) is kept with error set so the
	 * caller can report the exact line + which fields are bad.
	 *
	 * @param array $rows          0-indexed row arrays (PhpSpreadsheet toArray()).
	 * @param array $category_map  UPPER(category name) => CategoryID.
	 * @param array $supplier_map  UPPER(supplier name) => SupplierID.
	 * @return array
	 */
	public static function Parse_Import_Rows($rows, array $category_map = array(), array $supplier_map = array())
	{
		if (!is_array($rows)) {
			return array();
		}
		// Normalise the lookup keys once (UPPER + trim) so matching is forgiving.
		$cats = array();
		foreach ($category_map as $name => $id) { $cats[strtoupper(trim((string) $name))] = (int) $id; }
		$sups = array();
		foreach ($supplier_map as $name => $id) { $sups[strtoupper(trim((string) $name))] = (int) $id; }

		$out  = array();
		$line = 0;
		foreach ($rows as $row) {
			$line++;
			if (!is_array($row)) {
				continue;
			}
			$cell = function ($i) use ($row) {
				return isset($row[$i]) ? trim((string) $row[$i]) : '';
			};

			$category = $cell(0);
			$supplier = $cell(1);
			$code     = $cell(2);
			$name     = $cell(3);
			$retail   = $cell(4);
			$supprice = $cell(5);

			// Drop the header row wherever it sits (matches the template labels).
			if (strtoupper($category) === 'CATEGORY' && strtoupper($supplier) === 'SUPPLIER') {
				continue;
			}
			// Skip a fully-blank row (trailing empty rows Excel leaves behind).
			if ($category === '' && $supplier === '' && $code === '' && $name === ''
				&& $retail === '' && $supprice === '') {
				continue;
			}

			$cat_id = ($category !== '' && isset($cats[strtoupper($category)])) ? $cats[strtoupper($category)] : null;
			$sup_id = ($supplier !== '' && isset($sups[strtoupper($supplier)])) ? $sups[strtoupper($supplier)] : null;

			// Report every mandatory field that is blank/unknown in one pass. A
			// supplied-but-unknown name shows the offending value for easy fixing.
			$missing = array();
			if ($cat_id === null) { $missing[] = $category === '' ? 'Category' : 'Category (' . $category . ')'; }
			if ($sup_id === null) { $missing[] = $supplier === '' ? 'Supplier' : 'Supplier (' . $supplier . ')'; }
			if ($name === '')     { $missing[] = 'Name'; }

			$out[] = array(
				'line'          => $line,
				'error'         => empty($missing) ? null : ('Missing/invalid: ' . implode(', ', $missing)),
				'ProductCode'   => $code !== '' ? strtoupper($code) : null,
				'Name'          => $name !== '' ? $name : null,
				'CategoryID'    => $cat_id,
				'SupplierID'    => $sup_id,
				'RetailPrice'   => self::Parse_Price($retail),
				'SupplierPrice' => self::Parse_Price($supprice),
			);
		}
		return $out;
	}

	/**
	 * Parse a spreadsheet price cell ("RM1,200.50", "1200.5", "") into a float.
	 * Anything that isn't a digit, dot or minus is stripped; blank -> 0.0.
	 */
	public static function Parse_Price($raw)
	{
		$clean = preg_replace('/[^0-9.\-]/', '', (string) $raw);
		return ($clean === '' || $clean === '-' || $clean === '.') ? 0.0 : (float) $clean;
	}

	/**
	 * Decide which uploaded import files to delete so only the newest $keep are
	 * kept as backups. Recency is read from the unix stamp in the filename
	 * (product_import_<unix>.xlsx). Pure so it can be unit tested; the controller
	 * does the unlink. Returns names to DELETE.
	 */
	public static function Prune_Import_Backups($filenames, $keep = 3)
	{
		if (!is_array($filenames)) {
			return array();
		}
		$keep = max(0, (int) $keep);
		$stamped = array();
		foreach ($filenames as $name) {
			$ts = 0;
			if (preg_match('/product_import_(\d+)\./', (string) $name, $m)) {
				$ts = (int) $m[1];
			}
			$stamped[] = array('name' => (string) $name, 'ts' => $ts);
		}
		usort($stamped, function ($a, $b) {
			if ($a['ts'] === $b['ts']) { return 0; }
			return ($a['ts'] < $b['ts']) ? 1 : -1; // newest first
		});
		$prune = array_slice($stamped, $keep); // everything past the newest $keep
		$out = array();
		foreach ($prune as $entry) {
			$out[] = $entry['name'];
		}
		return $out;
	}

	/** UPPER(category name) => CategoryID, for resolving import rows. */
	public function Category_Name_Map()
	{
		$map = array();
		foreach ($this->Read_Categories() as $c) {
			$map[strtoupper(trim($c->Name))] = (int) $c->CategoryID;
		}
		return $map;
	}

	/** UPPER(supplier name) => SupplierID, for resolving import rows. */
	public function Supplier_Name_Map()
	{
		$map = array();
		foreach ($this->Read_Suppliers() as $s) {
			$map[strtoupper(trim($s->Name))] = (int) $s->SupplierID;
		}
		return $map;
	}

	/** The single active product with this ProductCode, or null. */
	public function Find_By_Code($code)
	{
		if ($code === null || $code === '') {
			return null;
		}
		$this->db->where('ProductCode', $code);
		$this->db->where('Status', 'Y');
		$row = $this->db->get('product')->row();
		return $row ?: null;
	}

	/** Build a product code ({CategoryCode}-{ProductID}) for a just-created row. */
	public function Generate_Product_Code($category_id, $product_id)
	{
		$this->db->select('category_code.Name');
		$this->db->join('category_code', 'category_code.CategoryCodeID = category.CategoryCodeID', 'left');
		$this->db->where('CategoryID', $category_id);
		$result = $this->db->get('category')->row();
		if ($result && isset($result->Name)) {
			return $result->Name . '-' . $product_id;
		}
		return null;
	}

	/**
	 * Upsert one product per parsed entry: a row whose ProductCode matches an
	 * existing active product UPDATES its category/supplier/name/prices; a blank
	 * or unknown code CREATES a new product with an auto-generated code. Audit
	 * fields (Insert/Update By+Date) are stamped like the single-create form.
	 *
	 * @param array $parsed  Entries from Parse_Import_Rows().
	 * @param int   $admin_id Acting user (InsertBy/UpdateBy).
	 * @return array Summary: created, updated, failed (each with line + reason).
	 */
	public function Bulk_Import(array $parsed, $admin_id = 0)
	{
		$summary = array(
			'created' => array(), // ['line'=>, 'name'=>, 'code'=>]
			'updated' => array(), // ['line'=>, 'name'=>, 'code'=>]
			'failed'  => array(), // ['line'=>, 'name'=>, 'reason'=>]
		);
		$now = date('Y-m-d H:i:s');

		foreach ($parsed as $entry) {
			$line = isset($entry['line']) ? (int) $entry['line'] : 0;
			$name = isset($entry['Name']) ? $entry['Name'] : null;

			if (!empty($entry['error'])) {
				$summary['failed'][] = array('line' => $line, 'name' => $name, 'reason' => $entry['error']);
				continue;
			}

			$fields = array(
				'CategoryID'    => $entry['CategoryID'],
				'SupplierID'    => $entry['SupplierID'],
				'Name'          => $name,
				'RetailPrice'   => $entry['RetailPrice'],
				'SupplierPrice' => $entry['SupplierPrice'],
			);

			$existing = $this->Find_By_Code(isset($entry['ProductCode']) ? $entry['ProductCode'] : null);
			if ($existing) {
				// Update in place; ProductCode is intentionally left unchanged
				// (matches the single-product update form).
				$fields['UpdateBy']   = $admin_id;
				$fields['UpdateDate'] = $now;
				$this->db->where('ProductID', $existing->ProductID);
				$this->db->update('product', $fields);
				$summary['updated'][] = array('line' => $line, 'name' => $name, 'code' => $existing->ProductCode);
			} else {
				$fields['Status']     = 'Y';
				$fields['InsertBy']   = $admin_id;
				$fields['InsertDate'] = $now;
				$this->db->insert('product', $fields);
				$new_id = $this->db->insert_id();
				if ($new_id && $new_id > 0) {
					$code = $this->Generate_Product_Code($entry['CategoryID'], $new_id);
					if ($code !== null) {
						$this->db->where('ProductID', $new_id);
						$this->db->update('product', array('ProductCode' => $code));
					}
					$summary['created'][] = array('line' => $line, 'name' => $name, 'code' => $code);
				} else {
					$summary['failed'][] = array('line' => $line, 'name' => $name, 'reason' => 'Insert failed');
				}
			}
		}

		return $summary;
	}
}