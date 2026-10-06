<?php
/**
 * Run with: php tests/helpers/CostingQuotationFileHelperTest.php
 *
 * Pure helpers behind the Cost Template & Margin "Supplier Quotations" uploads
 * (no DB / no HTTP / no FCPATH): the allowed-type whitelist + last-extension
 * guard, mime + display-icon mapping, the supplier matching key, and grouping a
 * flat list of quotation rows by supplier so each cost item can surface the
 * quotation(s) for its supplier.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

require_once __DIR__ . '/../../application/helpers/costing_quotation_helper.php';

$assertions = array();

// --- reldir + sizing -------------------------------------------------------
$assertions['reldir']   = costing_quotation_upload_reldir() === 'assets/upload/costing_quotation/';
$assertions['max size'] = costing_quotation_max_size_kb() === 20480;

// --- allowed types / last-extension guard ----------------------------------
$assertions['types list'] = costing_quotation_allowed_types() === 'pdf|doc|docx|xls|xlsx|jpg|jpeg|png|webp|gif';
$assertions['ok pdf']     = costing_quotation_is_allowed_file('rates.pdf') === true;
$assertions['ok xlsx up'] = costing_quotation_is_allowed_file('SHEET.XLSX') === true;  // case-insensitive
$assertions['ok docx']    = costing_quotation_is_allowed_file('quote.docx') === true;
$assertions['ok webp']    = costing_quotation_is_allowed_file('scan.webp') === true;
$assertions['bad double']  = costing_quotation_is_allowed_file('rates.pdf.php') === false; // only final ext counts
$assertions['bad noext']   = costing_quotation_is_allowed_file('README') === false;
$assertions['bad empty']   = costing_quotation_is_allowed_file('') === false;
$assertions['bad bmp']     = costing_quotation_is_allowed_file('pic.bmp') === false;

// --- mime mapping ----------------------------------------------------------
$assertions['mime pdf']   = costing_quotation_file_mime('a.pdf') === 'application/pdf';
$assertions['mime docx']  = costing_quotation_file_mime('a.docx') === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
$assertions['mime xlsx']  = costing_quotation_file_mime('a.xlsx') === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
$assertions['mime png']   = costing_quotation_file_mime('a.PNG') === 'image/png';
$assertions['mime other'] = costing_quotation_file_mime('a.zip') === 'application/octet-stream';

// --- display icon ----------------------------------------------------------
$assertions['icon pdf']   = costing_quotation_file_icon('a.pdf') === 'la la-file-pdf';
$assertions['icon doc']   = costing_quotation_file_icon('a.doc') === 'la la-file-word';
$assertions['icon docx']  = costing_quotation_file_icon('a.docx') === 'la la-file-word';
$assertions['icon xls']   = costing_quotation_file_icon('a.xls') === 'la la-file-excel';
$assertions['icon xlsx']  = costing_quotation_file_icon('a.xlsx') === 'la la-file-excel';
$assertions['icon jpg']   = costing_quotation_file_icon('a.jpg') === 'la la-file-image';
$assertions['icon webp']  = costing_quotation_file_icon('a.webp') === 'la la-file-image';
$assertions['icon other'] = costing_quotation_file_icon('a.zip') === 'la la-file';

// --- file kind (badge family) ----------------------------------------------
$assertions['kind pdf']   = costing_quotation_file_kind('a.pdf') === 'pdf';
$assertions['kind word']  = costing_quotation_file_kind('a.DOCX') === 'word';
$assertions['kind excel'] = costing_quotation_file_kind('a.xls') === 'excel';
$assertions['kind image'] = costing_quotation_file_kind('a.webp') === 'image';
$assertions['kind other'] = costing_quotation_file_kind('a.zip') === 'file';
$assertions['kind noext'] = costing_quotation_file_kind('README') === 'file';

// --- supplier matching key -------------------------------------------------
$assertions['key trims/lowers'] = costing_quotation_supplier_key('  Hotel ABC ') === 'hotel abc';
$assertions['key empty']        = costing_quotation_supplier_key('') === '';
$assertions['key non-string']   = costing_quotation_supplier_key(null) === '';

// --- group by supplier -----------------------------------------------------
$files = array(
    array('id' => 1, 'supplier' => 'Hotel ABC'),
    array('id' => 2, 'supplier' => 'hotel abc'),  // same supplier, different case
    array('id' => 3, 'supplier' => 'Air XYZ'),
    array('id' => 4, 'supplier' => ''),           // untagged
);
$grouped = costing_quotation_group_by_supplier($files);
$assertions['group abc count']  = isset($grouped['hotel abc']) && count($grouped['hotel abc']) === 2;
$assertions['group abc ids']    = $grouped['hotel abc'][0]['id'] === 1 && $grouped['hotel abc'][1]['id'] === 2;
$assertions['group xyz count']  = isset($grouped['air xyz']) && count($grouped['air xyz']) === 1;
$assertions['group untagged']   = isset($grouped['']) && count($grouped['']) === 1;
$assertions['group non-array']  = costing_quotation_group_by_supplier('nope') === array();

$failed = 0;
foreach ($assertions as $label => $ok) {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

echo PHP_EOL . ($failed === 0 ? "All assertions passed.\n" : "$failed assertion(s) failed.\n");
exit($failed === 0 ? 0 : 1);
