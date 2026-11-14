<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once __DIR__ . '/manual_lead_sales_amount_helper.php';

/**
 * Excel export for the Guest List / GHL Leads / Manual Leads listings.
 *
 * The three pages share the Guests_Model + view (locked to a mode via Set_Mode),
 * so their downloads share this helper too. The column set matches what each
 * page renders on screen:
 *   - 'guest'  → Guest List (booking-guest columns)
 *   - 'ghl'    → GHL Leads (lead columns + Type)
 *   - 'manual' → Manual Leads (listing summary, status history, then form fields)
 *
 * Cell values are formatted the same way the listing renders them (phone with
 * calling code, tags flattened, DOB "d M Y", destinations de-duplicated, guest
 * type title-cased) so the file reads like the screen.
 */

if (!function_exists('guest_list_export_columns')) {
	/**
	 * Ordered [header label, source field] pairs for a mode. '__phone' is a
	 * synthetic field resolved by guest_list_export_cell().
	 *
	 * @param string $mode 'guest' | 'ghl' | 'manual'
	 * @return array<int,array{0:string,1:string}>
	 */
	function guest_list_export_columns($mode)
	{
		if ($mode === 'customer') {
			// Customer List export: mirrors the on-screen columns (Alt Name, Customer
			// Name, Contact, Email, Language, Guest Type, Destination, Customer Code,
			// Date Creation) PLUS the fields the dashboard filters on but doesn't show
			// as columns (Sales Agent, Source, Customer Type, Nationality, Gender,
			// Num of Pax, DOB), so a filtered download is self-explanatory. AutoCount
			// sync columns are kept for operational use. Field names map to the
			// Read_Customers_Rich_For_Export() SELECT aliases.
			return array(
				array('ALT NAME', 'AltName'),
				array('CUSTOMER NAME', 'Name'),
				array('CONTACT NUM', '__phone'),
				array('EMAIL', 'Email'),
				array('LANGUAGE', 'Language'),
				array('SALES AGENT', 'AgentName'),
				array('SOURCE', 'Source'),
				array('CUSTOMER TYPE', 'CustomerType'),
				array('GUEST TYPE', 'GuestType'),
				array('DESTINATION', 'Destination'),
				array('NATIONALITY', 'Nationality'),
				array('GENDER', 'Gender'),
				array('NUM OF PAX', 'TotalPax'),
				array('DATE OF BIRTH', 'DOB'),
				array('CUSTOMER CODE', 'CustomerCode'),
				array('DATE CREATION', 'CustomerCreatedAt'),
				array('AUTOCOUNT SYNC STATUS', 'AutocountSyncStatus'),
				array('AUTOCOUNT SYNC MESSAGE', 'AutocountSyncMessage'),
			);
		}

		if ($mode === 'manual') {
			// Listing summary first, followed by dated status history and the
			// remaining lead form fields (without repeating summary fields).
			return array(
				array('NO.', '__number'),
				array('CREATED DATE', 'CreatedAt'),
				array('GUEST NAME', 'Name'),
				array('COMPANY NAME', 'Company'),
				array('CLIENT TYPE', 'ClientType'),
				array('LEAD STATUS', 'LeadStatus'),
				array('STATUS DATE', 'LeadStatusDate'),
				array('CREATED BY', 'CreatedBy'),
				array('LEAD STATUS UPDATES', 'StatusUpdates'),
				array('TRAVEL DATE', 'TravelDate'),
				array('SALES PERSON', 'SalesPerson'),
				array('OP', 'Op'),
				array('DESTINATION', 'LeadDestination'),
				array('POTENTIAL SALES AMOUNT', 'PotentialSalesAmount'),
				array('CONTACT NUM', '__phone'),
				array('EMAIL', 'Email'),
				array('ADDRESS', 'Address'),
				array('COUNTRY', 'Country'),
				array('GENDER', 'Gender'),
				array('LANGUAGE', 'Language'),
				array('RACE', 'Race'),
				array('NATIONALITY', 'Nationality'),
				array('SOURCE', 'Source'),
				array('NATURE OF BUSINESS', 'NatureOfBusiness'),
				array('NUMBER OF PAX', 'NumberOfPax'),
				array('STATE', 'State'),
				array('NOTES', 'Notes'),
				array('LEAD INTRO', 'LeadIntro'),
				array('DATE OF BIRTH', 'DOB'),
				array('HIGH CHANCE', 'HighChance'),
			);
		}

		if ($mode === 'ghl') {
			return array(
				array('GUEST FIRST NAME', 'Name'),
				array('CONTACT NUM', '__phone'),
				array('TAGS', 'Tags'),
				array('TYPE', 'Type'),
				array('GENDER', 'Gender'),
				array('LANGUAGE', 'Language'),
				array('RACE', 'Race'),
				array('NATIONALITY', 'Nationality'),
				array('DATE OF BIRTH', 'DOB'),
			);
		}

		// Guest List (booking guests): mirrors the on-screen column order.
		return array(
			array('ALT NAME', 'AltName'),
			array('GUEST FIRST NAME', 'Name'),
			array('CONTACT NUM', '__phone'),
			array('EMAIL', 'Email'),
			array('LANGUAGE', 'Language'),
			array('GUEST TYPE', 'GuestType'),
			array('DESTINATION', 'Destination'),
			array('CUSTOMER CODE', 'CustomerCode'),
		);
	}
}

if (!function_exists('guest_list_export_phone')) {
	/**
	 * All distinct contact numbers of a row, each with its calling code, joined
	 * with " / " — the same numbers the listing shows (merged rows carry every
	 * distinct phone in ContactNumbers; un-merged rows carry ContactNum).
	 */
	function guest_list_export_phone($g)
	{
		if (isset($g->ContactNumbers) && (string) $g->ContactNumbers !== '') {
			$phones = guest_contact_parse_multi($g->ContactNumbers);
		} else {
			$phones = array();
			if (isset($g->ContactNum) && (string) $g->ContactNum !== '') {
				$phones[] = array(
					'calling_code' => isset($g->CallingCode) ? (string) $g->CallingCode : '',
					'mobile'       => (string) $g->ContactNum,
				);
			}
		}

		$out = array();
		foreach ($phones as $p) {
			$disp = guest_contact_format_display($p['calling_code'], $p['mobile']);
			if ($disp !== '') {
				$out[] = $disp;
			}
		}
		return implode(' / ', $out);
	}
}

if (!function_exists('guest_list_export_cell')) {
	/**
	 * One formatted cell value for a row + field, matching the listing's display.
	 */
	function guest_list_export_cell($g, $field, $number = null)
	{
		if ($field === '__number') {
			return $number === null ? '' : (string) $number;
		}

		if ($field === '__phone') {
			return guest_list_export_phone($g);
		}

		$raw = isset($g->$field) ? (string) $g->$field : '';

		if ($field === 'HighChance') {
			return $raw === '1' ? 'Yes' : 'No';
		}

		if ($field === 'PotentialSalesAmount') {
			return manual_lead_display_sales_amount($raw);
		}

		if ($field === 'Tags') {
			return implode(', ', ghl_lead_tags_parse($raw));
		}

		if ($field === 'Type') {
			return ($raw === 'Manual') ? 'Manual' : 'GHL';
		}

		if ($field === 'NumberOfPax') {
			$raw = trim($raw);
			return $raw === '' ? '' : $raw . ' pax';
		}

		if ($field === 'CreatedAt' || $field === 'CustomerCreatedAt') {
			$raw = trim($raw);
			if ($raw === '' || $raw === '0000-00-00 00:00:00' || strtotime($raw) === false) {
				return '';
			}
			return date('d M Y, g:i A', strtotime($raw));
		}

		if ($field === 'TravelDate') {
			$start = trim($raw);
			if ($start === '' || strpos($start, '0000-00-00') === 0 || strtotime($start) === false) { return ''; }
			$end = isset($g->TravelEndDate) ? trim((string) $g->TravelEndDate) : '';
			$display = date('d M Y', strtotime($start));
			if ($end !== '' && strpos($end, '0000-00-00') !== 0 && strtotime($end) !== false) {
				return $display . ' - ' . date('d M Y', strtotime($end));
			}
			return $display;
		}

		if ($field === 'DOB' || $field === 'LeadStatusDate') {
			$raw = trim($raw);
			if ($raw === '' || $raw === '0000-00-00' || strtotime($raw) === false) {
				return '';
			}
			return date('d M Y', strtotime($raw));
		}

		if ($field === 'Destination') {
			$dests = array();
			foreach (explode('||', $raw) as $d) {
				$d = trim($d);
				if ($d === '' || $d === '-') {
					continue;
				}
				if (!in_array($d, $dests, true)) {
					$dests[] = $d;
				}
			}
			return implode(', ', $dests);
		}

		if ($field === 'GuestType') {
			$map = array('ADULT' => 'Adult', 'CHILD' => 'Child', 'INFANT' => 'Infant');
			$raw = trim($raw);
			return isset($map[$raw]) ? $map[$raw] : $raw;
		}

		return trim($raw);
	}
}

if (!function_exists('guest_list_export_matrix')) {
	/**
	 * Pure headers + rows-of-strings for the given rows + mode (no I/O), so the
	 * column/format logic is unit-testable apart from the spreadsheet writer.
	 *
	 * @return array{headers:string[],rows:array<int,string[]>}
	 */
	function guest_list_export_matrix($rows, $mode)
	{
		$cols    = guest_list_export_columns($mode);
		$headers = array();
		foreach ($cols as $c) {
			$headers[] = $c[0];
		}

		$out = array();
		foreach ((array) $rows as $g) {
			$line = array();
			foreach ($cols as $c) {
				$line[] = guest_list_export_cell($g, $c[1], count($out) + 1);
			}
			$out[] = $line;
		}

		return array('headers' => $headers, 'rows' => $out);
	}
}

if (!function_exists('guest_list_export_stream')) {
	/**
	 * Build the .xlsx for a mode and stream it to the browser as an attachment.
	 * Styling matches the app's other exports (black header row, white bold text,
	 * wide columns, string cells so leading zeros / long numbers survive).
	 *
	 * $result is the CI DB result OBJECT from Read_Guests_For_Export() (or null).
	 * It is walked with unbuffered_row() — one row materialised at a time — so a
	 * whole-history export never holds every guest in a PHP array at once. The
	 * memory/time ceilings are lifted because PhpSpreadsheet still buffers the
	 * built workbook in memory (no streaming xlsx writer is available) and a large
	 * unfiltered list would otherwise trip the default limits.
	 */
	function guest_list_export_stream($result, $mode, $filename)
	{
		require_once FCPATH . 'vendor/autoload.php';
		@ini_set('memory_limit', '1024M');
		@set_time_limit(0);

		$cols = guest_list_export_columns($mode);
		$last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($cols));

		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();
		$sheet->setTitle('Records');
		$spreadsheet->getProperties()->setCreator('HolidayGoGoGo');

		// Fields whose cell holds several lines (e.g. the Manual Leads "Lead Status
		// Updates" — one dated entry per line). Their columns get wrap-text so the
		// newlines actually render as separate rows inside the cell, plus a wider
		// width to fit the dates + notes.
		$multiline_fields = array('StatusUpdates');
		$wrap_cols = array();
		foreach ($cols as $i => $c) {
			$col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
			$sheet->setCellValue($col . '1', $c[0]);
			if (in_array($c[1], $multiline_fields, true)) {
				$sheet->getColumnDimension($col)->setWidth(42);
				$wrap_cols[] = $col;
			} else {
				$sheet->getColumnDimension($col)->setWidth(28);
			}
		}
		$sheet->getStyle('A1:' . $last . '1')->getFill()
			->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
			->getStartColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
		$sheet->getStyle('A1:' . $last . '1')->getFont()->getColor()
			->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE);
		$sheet->getStyle('A1:' . $last . '1')->getFont()->setBold(true);

		$row = 2;
		if ($result !== null) {
			// unbuffered_row(): pull + write one row at a time, letting each guest
			// object fall out of scope before the next — the workbook grows, but the
			// source rows never pile up in a big array.
			while ($g = $result->unbuffered_row('object')) {
				foreach ($cols as $i => $c) {
					$col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
					$sheet->setCellValueExplicit(
						$col . $row,
						guest_list_export_cell($g, $c[1], $row - 1),
						\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
					);
				}
				$row++;
			}
		}

		// Wrap-text (top-aligned) on the multi-line columns so each newline-separated
		// entry shows on its own row inside the cell.
		if ($row > 2 && !empty($wrap_cols)) {
			foreach ($wrap_cols as $col) {
				$style = $sheet->getStyle($col . '2:' . $col . ($row - 1))->getAlignment();
				$style->setWrapText(true);
				$style->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
			}
		}

		if ($row === 2) {
			$sheet->mergeCells('A2:' . $last . '2');
			$sheet->getCell('A2')->setValue('Records Not Found');
			$sheet->getStyle('A2:' . $last . '2')->getAlignment()
				->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
		}

		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment;filename="' . $filename . '"');
		header('Cache-Control: max-age=0');
		$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
		$writer->save('php://output');
		exit;
	}
}
