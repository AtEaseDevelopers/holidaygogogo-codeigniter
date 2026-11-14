<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once __DIR__ . '/ghl_manual_lead_helper.php';
require_once __DIR__ . '/guest_list_export_helper.php';
require_once __DIR__ . '/manual_lead_status_changes_helper.php';

/** Importable fields in export order. */
function manual_lead_import_columns()
{
    $columns = array_column(guest_list_export_columns('manual'), 1, 0);
    unset($columns['NO.'], $columns['CREATED DATE'], $columns['HIGH CHANCE']);
    return $columns;
}
function manual_lead_import_required_headers()
{
    return array('GUEST NAME', 'COMPANY NAME', 'CLIENT TYPE', 'LEAD STATUS UPDATES',
        'TRAVEL DATE', 'SALES PERSON', 'OP', 'DESTINATION', 'POTENTIAL SALES AMOUNT', 'CONTACT NUM');
}
function manual_lead_import_header_map($row)
{
    $map = array();
    foreach ((array) $row as $i => $label) {
        $label = strtoupper(trim(preg_replace('/\s*\*$/u', '', trim((string) $label))));
        if ($label === '') { continue; }
        if (isset($map[$label])) { throw new InvalidArgumentException('Duplicate column: ' . $label); }
        $map[$label] = $i;
    }
    foreach (manual_lead_import_required_headers() as $label) {
        if (!isset($map[$label])) { throw new InvalidArgumentException('Missing column: ' . $label . '. Please download the latest import template.'); }
    }
    return $map;
}
function manual_lead_import_date($raw)
{
    $raw = trim((string) $raw);
    foreach (array('Y-m-d', 'd/m/Y', 'd M Y') as $format) {
        $d = DateTime::createFromFormat('!' . $format, $raw);
        if ($d && strcasecmp($d->format($format), $raw) === 0) { return $d->format('Y-m-d'); }
    }
    throw new InvalidArgumentException('Invalid date: ' . $raw . '. Use DD/MM/YYYY, YYYY-MM-DD or DD Mon YYYY.');
}
function manual_lead_import_user($name, $users, $label)
{
    $matches = array();
    foreach ($users as $user) {
        if (strcasecmp(trim((string) $user->Name), trim($name)) === 0 || (string) $user->AdminID === trim($name)) {
            $matches[(int) $user->AdminID] = true;
        }
    }
    if (count($matches) !== 1) { throw new InvalidArgumentException($label . ' must match one eligible BC user name or AdminID.'); }
    return (string) array_keys($matches)[0];
}
/** One exported row -> validated new lead + dated history. Never updates existing leads. */
function manual_lead_import_prepare($row, $map, $users, $now, $creator, $uid)
{
    $get = function($label) use ($row, $map) { return isset($map[$label], $row[$map[$label]]) ? trim((string) $row[$map[$label]]) : ''; };
    $nonblank = false;
    foreach (array_keys(manual_lead_import_columns()) as $label) { if ($get($label) !== '') { $nonblank = true; break; } }
    if (!$nonblank) { return null; }
    if ($get('NO.') === 'EXAMPLE - DELETE THIS ROW' || $get('GUEST NAME') === 'ALI BIN ABU (EXAMPLE - DELETE THIS ROW)') { return null; }
    try {
        $post = array();
        $fields = array('GUEST NAME'=>'first_name', 'COMPANY NAME'=>'company_name', 'CLIENT TYPE'=>'client_type',
            'DESTINATION'=>'destination', 'POTENTIAL SALES AMOUNT'=>'potential_sales_amount', 'CONTACT NUM'=>'phone',
            'EMAIL'=>'email', 'ADDRESS'=>'address', 'COUNTRY'=>'country', 'GENDER'=>'gender', 'LANGUAGE'=>'chat_language',
            'RACE'=>'race', 'NATIONALITY'=>'nationality', 'SOURCE'=>'source', 'NATURE OF BUSINESS'=>'nature_of_business',
            'NUMBER OF PAX'=>'number_of_pax', 'STATE'=>'state', 'NOTES'=>'notes', 'LEAD INTRO'=>'lead_intro');
        foreach ($fields as $label => $field) { $post[$field] = $get($label); }
        foreach (manual_lead_import_required_headers() as $label) {
            if ($get($label) === '') { throw new InvalidArgumentException($label . ' is required.'); }
        }
        $post['sales_person_id'] = manual_lead_import_user($get('SALES PERSON'), $users, 'Sales Person');
        $post['op_id'] = manual_lead_import_user($get('OP'), $users, 'OP');
        $travel = preg_split('/\s+-\s+/', $get('TRAVEL DATE'));
        if (count($travel) > 2) { throw new InvalidArgumentException('TRAVEL DATE must contain a start and end date.'); }
        $post['travel_date'] = manual_lead_import_date($travel[0]);
        $post['travel_end_date'] = manual_lead_import_date(isset($travel[1]) ? $travel[1] : $travel[0]);
        $post['date_of_birth'] = $get('DATE OF BIRTH') !== '' ? manual_lead_import_date($get('DATE OF BIRTH')) : '';
        $post['number_of_pax'] = preg_replace('/\s+pax$/i', '', $post['number_of_pax']);
        if (!in_array($post['client_type'], ghl_manual_lead_client_types(), true)) {
            throw new InvalidArgumentException('CLIENT TYPE must match one of the listed options.');
        }
        foreach (array('destination', 'potential_sales_amount') as $field) {
            $length = function_exists('mb_strlen') ? mb_strlen($post[$field]) : strlen($post[$field]);
            if ($length > 255) { throw new InvalidArgumentException(str_replace('_', ' ', $field) . ' must be at most 255 characters.'); }
        }
        $history = $get('LEAD STATUS UPDATES'); $add = array();
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+/', $history)) {
            // Keep embedded newlines in notes; only a new dated line starts an entry.
            foreach (preg_split('/\r?\n(?=\d{4}-\d{2}-\d{2}\s)/', $history) as $entry) {
                if (!preg_match('/^(\d{4}-\d{2}-\d{2}) (.+?) \((.*)\)$/s', $entry, $m)) {
                    throw new InvalidArgumentException('Each status update must be YYYY-MM-DD Status (required note).');
                }
                $add[] = array('status_date'=>$m[1], 'lead_status'=>$m[2], 'note'=>$m[3]);
            }
        } else {
            // A plain note is convenient for a single entry, with its status/date in F/G.
            $add[] = array('status_date'=>manual_lead_import_date($get('STATUS DATE')), 'lead_status'=>$get('LEAD STATUS'), 'note'=>$history);
        }
        $changes = manual_lead_status_changes_prepare(json_encode(array('add'=>$add)), array(), $creator, true);
        if (!$changes['ok']) { throw new InvalidArgumentException($changes['error']); }
        $latest = null;
        foreach ($changes['add'] as $entry) { if ($latest === null || $entry['date'] >= $latest['date']) { $latest = $entry; } }
        if (($get('LEAD STATUS') !== '' && $get('LEAD STATUS') !== $latest['status']) ||
            ($get('STATUS DATE') !== '' && manual_lead_import_date($get('STATUS DATE')) !== $latest['date'])) {
            throw new InvalidArgumentException('LEAD STATUS / STATUS DATE must match the latest status update, or be left blank.');
        }
        $prepared = ghl_manual_lead_prepare($post, $uid, $now, $creator, true);
        if (!$prepared['ok']) { throw new InvalidArgumentException(implode(' ', $prepared['errors'])); }
        $errors = ghl_manual_lead_required_form_errors($prepared['row']);
        if ($errors) { throw new InvalidArgumentException(implode(' ', $errors)); }
        return array('ok'=>true, 'row'=>$prepared['row'], 'changes'=>$changes);
    } catch (InvalidArgumentException $e) {
        return array('ok'=>false, 'error'=>$e->getMessage());
    }
}

/** Grey example row, marked so an unchanged sample is never imported as a lead. */
function manual_lead_import_example($users)
{
    $name = isset($users[0]) ? $users[0]->Name : 'BC user name';
    return array(
        'NO.'=>'EXAMPLE - DELETE THIS ROW', 'CREATED DATE'=>'', 'CREATED BY'=>'',
        'GUEST NAME'=>'ALI BIN ABU (EXAMPLE - DELETE THIS ROW)', 'COMPANY NAME'=>'Acme Sdn Bhd', 'CLIENT TYPE'=>'Company trip',
        'LEAD STATUS'=>'FOLLOW UP 1', 'STATUS DATE'=>'05 Nov 2026',
        'LEAD STATUS UPDATES'=>"2026-11-04 NEW LEADS (Received enquiry)\n2026-11-05 FOLLOW UP 1 (Sent package)",
        'TRAVEL DATE'=>'10 Dec 2026 - 15 Dec 2026', 'SALES PERSON'=>$name, 'OP'=>$name,
        'DESTINATION'=>'Redang', 'POTENTIAL SALES AMOUNT'=>'RM10,000',
        'CONTACT NUM'=>'+60 123456789', 'EMAIL'=>'ali@example.com', 'ADDRESS'=>'No 1, Jalan Besar, 50000 KL',
        'COUNTRY'=>'Malaysia', 'GENDER'=>'Male', 'LANGUAGE'=>'Malay', 'RACE'=>'Malay', 'NATIONALITY'=>'Malaysia',
        'SOURCE'=>'Facebook', 'NATURE OF BUSINESS'=>'Travel Agency', 'NUMBER OF PAX'=>'11-20',
        'STATE'=>'Selangor', 'NOTES'=>'Called twice, keen on Redang',
        'LEAD INTRO'=>'Referred by an existing client. Planning a company trip.', 'DATE OF BIRTH'=>'14 May 1990',
    );
}

/** Build export-compatible columns with the original styling and a grey example row. */
function manual_lead_import_template($users)
{
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $book->getProperties()->setCreator('HolidayGoGoGo');
    $sheet = $book->getActiveSheet(); $sheet->setTitle('Records');
    $required = manual_lead_import_required_headers();
    $examples = array('GUEST NAME'=>'ALI BIN ABU','COMPANY NAME'=>'Acme Sdn Bhd','CLIENT TYPE'=>'Company trip',
        'LEAD STATUS'=>'FOLLOW UP 1','STATUS DATE'=>'05 Nov 2026',
        'LEAD STATUS UPDATES'=>"2026-11-04 NEW LEADS (Received enquiry)\n2026-11-05 FOLLOW UP 1 (Sent package)",
        'TRAVEL DATE'=>'10 Dec 2026 - 15 Dec 2026','SALES PERSON'=>isset($users[0]) ? $users[0]->Name : 'BC user name',
        'OP'=>isset($users[0]) ? $users[0]->Name : 'BC user name','DESTINATION'=>'Redang',
        'POTENTIAL SALES AMOUNT'=>'RM10,000','CONTACT NUM'=>'+60 123456789','NUMBER OF PAX'=>'11-20',
        'LEAD INTRO'=>'Referred by an existing client. Planning a company trip.');
    $system = array('NO.', 'CREATED DATE', 'CREATED BY');
    $guide = $book->createSheet(); $guide->setTitle('Instructions');
    $guide->mergeCells('A1:C1')->setCellValue('A1', 'Manual Leads import guide');
    $guide->mergeCells('A2:C2')->setCellValue('A2', 'Fill Records, keeping row 1 unchanged. Headers with a red * are compulsory. Delete the grey example row before importing. The template omits automatic No. and Created Date columns. Existing Excel exports can still be imported. Each row creates a NEW lead, not an update.');
    $guide->mergeCells('A3:C3')->setCellValue('A3', 'NO., CREATED DATE and CREATED BY are ignored on import. Number/date are generated and the creator is the signed-in uploader. Sales Person / OP must match the BC Users sheet; AdminID is accepted if names are duplicated.');
    $guide->mergeCells('A4:C4')->setCellValue('A4', 'LEAD STATUS UPDATES: one YYYY-MM-DD Status (note) per line, with a note for every status. Alternatively enter a single note here and fill LEAD STATUS and STATUS DATE. With dated history, the summary columns can be blank; if filled they must match the latest entry.');
    $guide->mergeCells('A5:C5')->setCellValue('A5', 'Travel dates accept DD Mon YYYY, DD/MM/YYYY or YYYY-MM-DD, separated by space - space. One date means a same-day trip. Contact numbers require a country code. Client Type: ' . implode(', ', ghl_manual_lead_client_types()));
    $guide->fromArray(array('FIELD','REQUIREMENT','EXAMPLE'), null, 'A7');
    $i = 0;
    foreach (manual_lead_import_columns() as $label => $field) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $cell = $col . '1';
        $rule = in_array($label, $required, true) ? 'Required' : 'Optional';
        if (in_array($label, $system, true)) { $rule = 'Automatic; ignored on import'; }
        if (in_array($label, array('LEAD STATUS','STATUS DATE'), true)) { $rule = 'Required for a plain note; optional when dated history is supplied'; }
        $sheet->setCellValueExplicit($cell, $label, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->getColumnDimension($col)->setWidth($field === 'StatusUpdates' || $field === 'LeadIntro' ? 42 : ($field === 'TravelDate' ? 28 : 20));
        $sheet->getStyle($cell)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF000000');
        $sheet->getComment($cell)->getText()->createTextRun($rule . (isset($examples[$label]) ? "\nExample: " . $examples[$label] : ''));
        $guide->fromArray(array($label, $rule, $examples[$label] ?? ''), null, 'A' . (8 + $i));
        $i++;
    }
    $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
    $sheet->getStyle('A1:' . $last . '1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle('A1:' . $last . '1')->getAlignment()->setWrapText(true);
    $sheet->getRowDimension(1)->setRowHeight(45);
    // Preserve the old black/white headers and grey sample row. Only the
    // compulsory marker is red, leaving the column labels unchanged.
    $example = manual_lead_import_example($users);
    foreach (array_keys(manual_lead_import_columns()) as $index => $label) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
        if (in_array($label, $required, true)) {
            $rich = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
            $rich->createTextRun($label)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $rich->createTextRun(' *')->getFont()->setBold(true)->getColor()->setARGB('FFFF0000');
            $sheet->setCellValue($col . '1', $rich);
        }
        $sheet->setCellValueExplicit($col . '2', $example[$label] ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }
    $sheet->getStyle('A2:' . $last . '2')->getFont()->getColor()->setARGB('FF999999');
    $sheet->getStyle('A2:' . $last . '2')->getAlignment()->setWrapText(true)->setVertical('top');
    $sheet->getRowDimension(2)->setRowHeight(90);
    $sheet->getStyle('A2:' . $last . '1001')->getNumberFormat()->setFormatCode('@');
    $historyCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(array_search('LEAD STATUS UPDATES', array_keys(manual_lead_import_columns()), true) + 1);
    $sheet->getStyle($historyCol . '2:' . $historyCol . '1001')->getAlignment()->setWrapText(true);
    $sheet->freezePane('A2'); $sheet->setAutoFilter('A1:' . $last . '1');
    foreach (array('A'=>30,'B'=>44,'C'=>65) as $col=>$width) { $guide->getColumnDimension($col)->setWidth($width); }
    $guide->getStyle('A1:C' . (7 + $i))->getAlignment()->setWrapText(true)->setVertical('top');
    $guide->getStyle('A1:C1')->getFont()->setBold(true)->setSize(16);
    $guide->getStyle('A7:C7')->getFont()->setBold(true);
    foreach (array(2=>42,3=>42,4=>58,5=>42) as $row=>$height) { $guide->getRowDimension($row)->setRowHeight($height); }
    $guide->getRowDimension(8 + array_search('LEAD STATUS UPDATES', array_keys(manual_lead_import_columns()), true))->setRowHeight(60); // multiline status example
    $userSheet = $book->createSheet(); $userSheet->setTitle('BC Users');
    $userSheet->fromArray(array('ADMIN ID','BC USER NAME'), null, 'A1');
    $row = 2;
    foreach ($users as $user) {
        $userSheet->setCellValueExplicit('A'.$row, (string) $user->AdminID, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $userSheet->setCellValueExplicit('B'.$row, $user->Name, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $row++;
    }
    $userSheet->getColumnDimension('A')->setWidth(16); $userSheet->getColumnDimension('B')->setWidth(36);
    $userSheet->getStyle('A1:B1')->getFont()->setBold(true); $userSheet->freezePane('A2');
    $book->setActiveSheetIndex(0);
    return $book;
}
