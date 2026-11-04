<?php
/**
 * Run with: php tests/helpers/GuestListExportTest.php
 *
 * Covers the pure column/format logic behind the Guest List / GHL Leads /
 * Manual Leads Excel export (application/helpers/guest_list_export_helper.php):
 * the per-mode column set and each cell's display formatting (phone, tags, DOB,
 * destination, guest type). The spreadsheet writer itself is not exercised here.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

require_once __DIR__ . '/../../application/helpers/guest_contact_helper.php';
require_once __DIR__ . '/../../application/helpers/ghl_lead_tags_helper.php';
require_once __DIR__ . '/../../application/helpers/guest_list_export_helper.php';

$US = "\x1f"; // calling code <-> mobile
$RS = "\x1e"; // phone <-> phone

$assertions = array();

// ---- column sets per mode ----------------------------------------------
$guest_headers  = array_column(guest_list_export_columns('guest'), 0);
$ghl_headers    = array_column(guest_list_export_columns('ghl'), 0);
$manual_headers = array_column(guest_list_export_columns('manual'), 0);

$assertions['guest has ALT NAME + EMAIL']  = in_array('ALT NAME', $guest_headers, true) && in_array('EMAIL', $guest_headers, true);
$assertions['guest has DESTINATION']       = in_array('DESTINATION', $guest_headers, true);
$assertions['guest has no TAGS']           = !in_array('TAGS', $guest_headers, true);
$assertions['ghl has TYPE column']         = in_array('TYPE', $ghl_headers, true);
$assertions['manual has no TYPE column']   = !in_array('TYPE', $manual_headers, true);
$assertions['ghl has TAGS, manual none']   = in_array('TAGS', $ghl_headers, true) && !in_array('TAGS', $manual_headers, true);
$assertions['manual has business cols']    = in_array('CLIENT TYPE', $manual_headers, true) && in_array('NATURE OF BUSINESS', $manual_headers, true)
	&& in_array('NUMBER OF PAX', $manual_headers, true) && in_array('STATE', $manual_headers, true);
$assertions['manual + ghl have DOB']       = in_array('DATE OF BIRTH', $manual_headers, true) && in_array('DATE OF BIRTH', $ghl_headers, true);
// Manual export is a FULL field dump: it carries all the extra columns the slim
// GHL Leads export omits (email, company, notes, current status + status updates…).
$assertions['manual full-dump cols']       = in_array('EMAIL', $manual_headers, true) && in_array('COMPANY NAME', $manual_headers, true)
	&& in_array('LEAD STATUS', $manual_headers, true) && in_array('LEAD STATUS UPDATES', $manual_headers, true)
	&& in_array('NOTES', $manual_headers, true) && in_array('CREATED BY', $manual_headers, true) && in_array('CREATED DATE', $manual_headers, true);
$assertions['ghl has no EMAIL column']     = !in_array('EMAIL', $ghl_headers, true);
$assertions['unknown mode -> guest cols']  = array_column(guest_list_export_columns('whatever'), 0) === $guest_headers;

// Manual export starts with the requested summary and full history.
$assertions['manual summary order'] = array_slice($manual_headers, 0, 9) === array(
    'NO.', 'CREATED DATE', 'GUEST NAME', 'COMPANY NAME', 'CLIENT TYPE',
    'LEAD STATUS', 'STATUS DATE', 'CREATED BY', 'LEAD STATUS UPDATES',
);
$manual_row = (object) array(
    'Name' => 'Ali', 'Company' => 'Example Sdn Bhd', 'ClientType' => 'HRDC',
    'LeadStatus' => 'Follow Up', 'LeadStatusDate' => '2026-08-12', 'CreatedBy' => 'Sara',
    'CreatedAt' => '2026-08-10 14:30:00',
    'StatusUpdates' => "2026-08-10 New\n2026-08-12 Follow Up (called)",
);
$manual_mx = guest_list_export_matrix(array($manual_row, $manual_row), 'manual');
$assertions['manual sequential numbers'] = $manual_mx['rows'][0][0] === '1' && $manual_mx['rows'][1][0] === '2';
$assertions['manual summary values'] = array_slice($manual_mx['rows'][0], 2, 7) === array(
    'Ali', 'Example Sdn Bhd', 'HRDC', 'Follow Up', '12 Aug 2026', 'Sara', $manual_row->StatusUpdates,
);
$assertions['manual blank status date'] = guest_list_export_cell((object) array('LeadStatusDate' => '0000-00-00'), 'LeadStatusDate') === '';
$assertions['manual matrix width'] = count($manual_mx['rows'][0]) === count($manual_headers);

// ---- phone cell: merged multi-number row -------------------------------
$multi = (object) array('ContactNumbers' => '+60' . $US . '0169546738' . $RS . '+60' . $US . '0198887777');
$assertions['phone multi joined w/ " / "'] = guest_list_export_cell($multi, '__phone') === '+60 169546738 / +60 198887777';

// ---- phone cell: single-number fallback (no ContactNumbers) -------------
$single = (object) array('ContactNum' => '0169546738', 'CallingCode' => '+60');
$assertions['phone single fallback']       = guest_list_export_cell($single, '__phone') === '+60 169546738';

// ---- phone cell: no number -> empty ------------------------------------
$nophone = (object) array('ContactNum' => '', 'CallingCode' => '');
$assertions['phone empty -> ""']           = guest_list_export_cell($nophone, '__phone') === '';

// ---- Tags: flatten GROUP_CONCAT of JSON arrays, drop [system] tags ------
$tagged = (object) array('Tags' => "[\"redang\",\"active contacts\"]\n[\"[whatsapp] - lead capture\",\"redang\"]");
$assertions['tags flattened + deduped']    = guest_list_export_cell($tagged, 'Tags') === 'redang, active contacts';
$assertions['tags empty -> ""']            = guest_list_export_cell((object) array('Tags' => null), 'Tags') === '';

// ---- Type: Manual stays Manual, everything else -> GHL ------------------
$assertions['type Manual']                 = guest_list_export_cell((object) array('Type' => 'Manual'), 'Type') === 'Manual';
$assertions['type default -> GHL']         = guest_list_export_cell((object) array('Type' => ''), 'Type') === 'GHL';

// ---- manual business cells ---------------------------------------------
$assertions['client type passthrough']     = guest_list_export_cell((object) array('ClientType' => 'HRDC'), 'ClientType') === 'HRDC';
$assertions['nature passthrough']          = guest_list_export_cell((object) array('NatureOfBusiness' => 'Travel Agency'), 'NatureOfBusiness') === 'Travel Agency';
$assertions['pax gets " pax" suffix']      = guest_list_export_cell((object) array('NumberOfPax' => '11-20'), 'NumberOfPax') === '11-20 pax';
$assertions['pax empty -> ""']             = guest_list_export_cell((object) array('NumberOfPax' => ''), 'NumberOfPax') === '';
$assertions['state passthrough']           = guest_list_export_cell((object) array('State' => 'Selangor'), 'State') === 'Selangor';
$assertions['status updates passthrough']  = guest_list_export_cell((object) array('StatusUpdates' => '2026-08-10 New; 2026-08-12 Follow Up (called)'), 'StatusUpdates') === '2026-08-10 New; 2026-08-12 Follow Up (called)';
$assertions['created-at formatted']        = guest_list_export_cell((object) array('CreatedAt' => '2026-08-10 14:30:00'), 'CreatedAt') === date('d M Y, g:i A', strtotime('2026-08-10 14:30:00'));
$assertions['created-at blank -> ""']      = guest_list_export_cell((object) array('CreatedAt' => '0000-00-00 00:00:00'), 'CreatedAt') === '';

// ---- DOB: formatted / invalid dropped ----------------------------------
$assertions['dob formatted d M Y']         = guest_list_export_cell((object) array('DOB' => '1990-05-14'), 'DOB') === date('d M Y', strtotime('1990-05-14'));
$assertions['dob 0000 -> ""']              = guest_list_export_cell((object) array('DOB' => '0000-00-00'), 'DOB') === '';
$assertions['dob blank -> ""']             = guest_list_export_cell((object) array('DOB' => ''), 'DOB') === '';

// ---- Destination: split '||', trim, dedupe, join ', ' -------------------
$dest = (object) array('Destination' => 'Redang||Redang|| - ||Langkawi');
$assertions['destination deduped/joined']  = guest_list_export_cell($dest, 'Destination') === 'Redang, Langkawi';

// ---- GuestType: title-cased --------------------------------------------
$assertions['guesttype ADULT -> Adult']    = guest_list_export_cell((object) array('GuestType' => 'ADULT'), 'GuestType') === 'Adult';
$assertions['guesttype unknown passthru']  = guest_list_export_cell((object) array('GuestType' => 'STAFF'), 'GuestType') === 'STAFF';

// ---- matrix shape: header count == row cell count ----------------------
$rows = array(
    (object) array('Name' => 'Ali', 'Tags' => null, 'Type' => 'Manual', 'Gender' => 'M', 'Language' => 'EN', 'Race' => 'Malay', 'Nationality' => 'MY', 'DOB' => '', 'ContactNum' => '+60123', 'CallingCode' => ''),
);
$mx = guest_list_export_matrix($rows, 'ghl');
$assertions['matrix headers == ghl cols']  = $mx['headers'] === $ghl_headers;
$assertions['matrix row width == headers']  = count($mx['rows'][0]) === count($mx['headers']);
$assertions['matrix name cell correct']     = $mx['rows'][0][0] === 'Ali';
$assertions['matrix empty rows -> []']      = guest_list_export_matrix(array(), 'manual')['rows'] === array();

// ---- customer mode column set (Customer List export = dashboard fields) --
$customer_headers = array_column(guest_list_export_columns('customer'), 0);
$assertions['customer has ALT + CUSTOMER NAME'] = in_array('ALT NAME', $customer_headers, true) && in_array('CUSTOMER NAME', $customer_headers, true);
$assertions['customer has CODE + DATE CREATION'] = in_array('CUSTOMER CODE', $customer_headers, true) && in_array('DATE CREATION', $customer_headers, true);
$assertions['customer has filter fields']  = in_array('SALES AGENT', $customer_headers, true) && in_array('SOURCE', $customer_headers, true)
	&& in_array('DESTINATION', $customer_headers, true) && in_array('NATIONALITY', $customer_headers, true)
	&& in_array('NUM OF PAX', $customer_headers, true) && in_array('DATE OF BIRTH', $customer_headers, true);
$assertions['customer keeps AutoCount col'] = in_array('AUTOCOUNT SYNC STATUS', $customer_headers, true);
$assertions['customer has no TYPE/TAGS']    = !in_array('TYPE', $customer_headers, true) && !in_array('TAGS', $customer_headers, true);

// ---- customer Date Creation cell format --------------------------------
$assertions['customer date-creation fmt']  = guest_list_export_cell((object) array('CustomerCreatedAt' => '2026-08-10 09:15:00'), 'CustomerCreatedAt') === date('d M Y, g:i A', strtotime('2026-08-10 09:15:00'));
$assertions['customer date-creation blank'] = guest_list_export_cell((object) array('CustomerCreatedAt' => '0000-00-00 00:00:00'), 'CustomerCreatedAt') === '';

// ---- customer matrix from a rich-listing-like row ----------------------
$crow = (object) array(
	'AltName' => 'Ah Beng', 'Name' => 'Tan Ah Beng', 'ContactNum' => '0123456789', 'CallingCode' => '+60',
	'Email' => 'a@b.com', 'Language' => 'EN', 'AgentName' => 'Sara', 'Source' => 'FB',
	'CustomerType' => 'VIP', 'GuestType' => 'ADULT', 'Destination' => 'Redang', 'Nationality' => 'MY',
	'Gender' => 'Male', 'TotalPax' => 4, 'DOB' => '1988-03-02', 'CustomerCode' => 'C0001',
	'CustomerCreatedAt' => '2026-01-05 10:00:00', 'AutocountSyncStatus' => 'S', 'AutocountSyncMessage' => '',
);
$cmx = guest_list_export_matrix(array($crow), 'customer');
$assertions['customer matrix headers']      = $cmx['headers'] === $customer_headers;
$assertions['customer matrix row width']    = count($cmx['rows'][0]) === count($cmx['headers']);
$assertions['customer matrix phone fmt']     = in_array('+60 123456789', $cmx['rows'][0], true);
$assertions['customer matrix guesttype']     = in_array('Adult', $cmx['rows'][0], true);

// ---- report -------------------------------------------------------------
$failed = 0;
foreach ($assertions as $label => $ok) {
    if (!$ok) {
        $failed++;
        echo "FAIL: {$label}\n";
    }
}
if ($failed === 0) {
    echo 'OK: all ' . count($assertions) . " assertions passed\n";
    exit(0);
}
echo "{$failed} of " . count($assertions) . " assertions FAILED\n";
exit(1);
