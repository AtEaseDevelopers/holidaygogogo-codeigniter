<?php
/** Run with: php tests/helpers/ManualLeadImportFormatTest.php */
define('BASEPATH', __DIR__);
require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../application/helpers/manual_lead_import_helper.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$users = array((object) array('AdminID'=>7,'Name'=>'SAMANTHA'), (object) array('AdminID'=>9,'Name'=>'BENJAMIN'));
$record = (object) array('Name'=>'Ali', 'Company'=>'Acme Sdn Bhd', 'ClientType'=>'Company trip',
    'CreatedAt'=>'2026-11-04 10:00:00', 'CreatedBy'=>'OTHER CREATOR', 'LeadStatus'=>'FOLLOW UP 1', 'LeadStatusDate'=>'2026-11-05',
    'StatusUpdates'=>"2026-11-04 NEW LEADS (Received enquiry)\n2026-11-05 FOLLOW UP 1 (Sent package (updated)\nWaiting for reply)",
    'TravelDate'=>'2026-12-10', 'TravelEndDate'=>'2026-12-15', 'SalesPerson'=>'SAMANTHA','Op'=>'BENJAMIN',
    'LeadDestination'=>'Redang','PotentialSalesAmount'=>'RM 10,000 approx.','ContactNum'=>'+60 123456789',
    'NumberOfPax'=>'11-20','DOB'=>'1990-05-14','LeadIntro'=>'Referred by existing client');
$matrix = guest_list_export_matrix(array($record), 'manual');
$map = manual_lead_import_header_map($matrix['headers']);
function prepare_import($row, $map, $users) { return manual_lead_import_prepare($row, $map, $users, '2026-11-05 12:00:00', 12, 'test'); }
$result = prepare_import($matrix['rows'][0], $map, $users);
check($result['ok'], $result['error'] ?? 'Export round-trip failed');
check($result['row']['sales_person_id'] === 7 && $result['row']['op_id'] === 9, 'BC user name resolution');
check($result['row']['created_by'] === 12 && $result['row']['date_added'] === '2026-11-05 12:00:00', 'Creator/date must come from server');
check($result['row']['travel_date'] === '2026-12-10' && $result['row']['travel_end_date'] === '2026-12-15', 'Export date range lost');
check($result['row']['number_of_pax'] === '11-20' && $result['row']['date_of_birth'] === '1990-05-14', 'Formatted pax/DOB not normalized');
check(count($result['changes']['add']) === 2 && $result['changes']['add'][1]['note'] === "Sent package (updated)\nWaiting for reply", 'Multiline status notes lost');
foreach (manual_lead_import_required_headers() as $label) {
    $row = $matrix['rows'][0]; $row[$map[$label]] = '';
    check(!prepare_import($row, $map, $users)['ok'], 'Missing compulsory field accepted: '.$label);
}
$row = $matrix['rows'][0]; $row[$map['SALES PERSON']] = 'UNKNOWN';
check(!prepare_import($row, $map, $users)['ok'], 'Unknown BC user accepted');
$duplicate = array_merge($users, array((object) array('AdminID'=>10,'Name'=>'SAMANTHA')));
check(!prepare_import($matrix['rows'][0], $map, $duplicate)['ok'], 'Ambiguous BC user accepted');
$row = $matrix['rows'][0]; $row[$map['SALES PERSON']] = '7';
check(prepare_import($row, $map, $duplicate)['ok'], 'AdminID disambiguation failed');
$row = $matrix['rows'][0]; $row[$map['TRAVEL DATE']] = '15/12/2026 - 10/12/2026';
check(!prepare_import($row, $map, $users)['ok'], 'Reversed dates accepted');
$row = $matrix['rows'][0]; $row[$map['LEAD STATUS UPDATES']] = 'Called guest';
check(prepare_import($row, $map, $users)['ok'], 'Single note with summary status/date rejected');
$row[$map['LEAD STATUS UPDATES']] = '2026-11-05 FOLLOW UP 1 ()';
check(!prepare_import($row, $map, $users)['ok'], 'Empty status note accepted');
$row = $matrix['rows'][0]; $row[$map['LEAD STATUS']] = 'NEW LEADS';
check(!prepare_import($row, $map, $users)['ok'], 'Contradictory latest status accepted');
$row = $matrix['rows'][0]; $row[$map['LEAD STATUS']] = ''; $row[$map['STATUS DATE']] = '';
check(prepare_import($row, $map, $users)['ok'], 'Dated history must work without redundant summary');
check(prepare_import(array(), $map, $users) === null, 'Blank row not skipped');
try { manual_lead_import_header_map(array('NAME','COMPANY NAME')); throw new RuntimeException('Old header silently misread'); } catch (InvalidArgumentException $expected) {}

$book = manual_lead_import_template($users);
$path = tempnam(sys_get_temp_dir(), 'manual-lead-template-');
try {
    \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book, 'Xlsx')->save($path);
    $loaded = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $sheet = $loaded->getSheetByName('Records');
    check(!isset(manual_lead_import_columns()['NO.']) && !isset(manual_lead_import_columns()['CREATED DATE']), 'Automatic fields still in template');
    check(count(manual_lead_import_columns()) === 27, 'Template must have 27 columns');
    check(array_keys(manual_lead_import_header_map($sheet->rangeToArray('A1:AA1')[0])) === array_keys(manual_lead_import_columns()), 'Starred template headers differ from export');
    check($sheet->getCell('A2')->getValue() === 'ALI BIN ABU (EXAMPLE - DELETE THIS ROW)', 'Example guest missing');
    check($sheet->getStyle('A2')->getFont()->getColor()->getARGB() === 'FF999999', 'Example styling differs from old template');
    check($sheet->getStyle('G1')->getFill()->getStartColor()->getARGB() === 'FF000000', 'Header background must stay black');
    $runs = $sheet->getCell('G1')->getValue()->getRichTextElements();
    check(end($runs)->getText() === ' *' && end($runs)->getFont()->getColor()->getARGB() === 'FFFF0000', 'Required marker is not a red star');
    check(prepare_import($sheet->rangeToArray('A2:AA2')[0], manual_lead_import_header_map($sheet->rangeToArray('A1:AA1')[0]), $users) === null, 'Example row must not create a fake lead');
    check(strpos($sheet->getComment('G1')->getText()->getPlainText(), 'Required') !== false, 'Required guidance missing');
    check($loaded->getSheetCount() === 3 && $loaded->getSheetByName('BC Users')->getCell('B2')->getValue() === 'SAMANTHA', 'User guidance missing');
    check($sheet->getStyle('M2')->getNumberFormat()->getFormatCode() === '@', 'Phone leading zeros not protected');
    $templateRow = array_map(function($label) use ($matrix, $map) { return $matrix['rows'][0][$map[$label]]; }, array_keys(manual_lead_import_columns()));
    $sheet->fromArray(array($templateRow), null, 'A2');
    \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($loaded, 'Xlsx')->save($path);
    $reloaded = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheetByName('Records')->toArray(null, true, true, false);
    $parsed = prepare_import($reloaded[1], manual_lead_import_header_map($reloaded[0]), $users);
    check($parsed['ok'] && count($parsed['changes']['add']) === 2, 'Actual workbook import/export round-trip failed');
} finally { unlink($path); }
echo "OK: matching template/export, required fields, BC users, dates, history/notes and XLSX round-trip.\n";
