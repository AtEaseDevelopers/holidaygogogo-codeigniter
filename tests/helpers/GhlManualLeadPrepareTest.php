<?php
/**
 * Run with: php tests/helpers/GhlManualLeadPrepareTest.php
 *
 * Locks the mapper that turns the "Create Lead" form into a ghl_contacts insert
 * row for a hand-entered ("Manual") GHL lead:
 *   - synthetic contact_id "manual:<uid>" so the GHL API sync never clobbers it,
 *   - lead_source = 'manual' (drives the Type column: Manual vs GHL),
 *   - tags stored as a JSON array (same shape ghl_lead_tags_parse renders),
 *   - a lead must carry at least one identifying value (name / phone / email),
 *   - DOB / email / gender are validated & normalized.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}
require __DIR__ . '/../../application/helpers/ghl_manual_lead_helper.php';

function assert_eq($label, $expected, $actual) {
    if ($expected === $actual) {
        echo "  PASS  {$label} = " . var_export($actual, true) . "\n";
    } else {
        echo "  FAIL  {$label}: expected " . var_export($expected, true)
           . ", got " . var_export($actual, true) . "\n";
        exit(1);
    }
}

$now = '2026-07-24 10:00:00';

// ---- happy path: every visible column filled -------------------------------
$res = ghl_manual_lead_prepare(array(
    'first_name'    => '  Ali  ',
    'last_name'     => 'Bakar',
    'company_name'  => 'Acme Sdn Bhd',
    'phone'         => '+60 12-345 6789',
    'email'         => 'ali@example.com',
    'address'       => 'No 1, Jalan Besar',
    'gender'        => 'male',
    'chat_language' => 'Malay',
    'race'          => 'Malay',
    'nationality'   => 'Malaysia',
    'country'       => 'Malaysia',
    'source'        => 'Facebook',
    'notes'         => 'Called twice, keen on Redang',
    'customer_type' => 'Company',
    'lead_intro'    => 'Referred by existing client',
    'lead_status'   => 'New',
    'date_of_birth' => '1990-05-20',
    'tags'          => 'Redang, VIP, redang',
    'nature_of_business' => '  Travel Agency  ',
    'number_of_pax' => '11-20',
    'client_type'   => 'HRDC',
    'state'         => 'Selangor',
    // created_by is server-supplied; a POST value must NOT leak into the row.
    'created_by'    => '999',
), 'abc123', $now, 42);

assert_eq('happy ok', true, $res['ok']);
// creator comes from the $created_by arg, never from POST
assert_eq('created_by from arg', 42, $res['row']['created_by']);
assert_eq('happy no errors', array(), $res['errors']);
assert_eq('synthetic contact_id', 'manual:abc123', $res['row']['contact_id']);
assert_eq('lead_source', 'manual', $res['row']['lead_source']);
assert_eq('first name trimmed', 'Ali', $res['row']['first_name']);
assert_eq('phone kept raw', '+60 12-345 6789', $res['row']['phone']);
assert_eq('gender normalized', 'Male', $res['row']['gender']);
assert_eq('language', 'Malay', $res['row']['chat_language']);
assert_eq('race', 'Malay', $res['row']['race']);
assert_eq('nationality', 'Malaysia', $res['row']['nationality']);
assert_eq('dob', '1990-05-20', $res['row']['date_of_birth']);
assert_eq('date_added passthrough', $now, $res['row']['date_added']);
// tags: de-duped case-insensitively, first-seen order, JSON array
assert_eq('tags json', '["Redang","VIP"]', $res['row']['tags_json']);
// new extra fields passthrough (trimmed, empty => null)
assert_eq('company_name', 'Acme Sdn Bhd', $res['row']['company_name']);
assert_eq('address', 'No 1, Jalan Besar', $res['row']['address']);
assert_eq('country', 'Malaysia', $res['row']['country']);
assert_eq('source', 'Facebook', $res['row']['source']);
assert_eq('notes', 'Called twice, keen on Redang', $res['row']['notes']);
assert_eq('customer_type', 'Company', $res['row']['customer_type']);
assert_eq('lead_intro', 'Referred by existing client', $res['row']['lead_intro']);
// lead_status is no longer a stored column (status lives in lead_status_log);
// a POST value must NOT leak into the insert row.
assert_eq('lead_status not stored', false, array_key_exists('lead_status', $res['row']));
// business fields: trimmed, empty => null
assert_eq('nature_of_business trimmed', 'Travel Agency', $res['row']['nature_of_business']);
assert_eq('number_of_pax', '11-20', $res['row']['number_of_pax']);
assert_eq('client_type', 'HRDC', $res['row']['client_type']);
assert_eq('state', 'Selangor', $res['row']['state']);

// ---- minimal: name only, everything else null ------------------------------
$min = ghl_manual_lead_prepare(array('first_name' => 'Solo'), 'u2', $now);
assert_eq('minimal ok', true, $min['ok']);
assert_eq('minimal phone null', null, $min['row']['phone']);
assert_eq('minimal email null', null, $min['row']['email']);
assert_eq('minimal tags null', null, $min['row']['tags_json']);
assert_eq('minimal dob null', null, $min['row']['date_of_birth']);
assert_eq('minimal gender null', null, $min['row']['gender']);
// new extra fields default null when omitted
assert_eq('minimal company null', null, $min['row']['company_name']);
assert_eq('minimal address null', null, $min['row']['address']);
assert_eq('minimal country null', null, $min['row']['country']);
assert_eq('minimal source null', null, $min['row']['source']);
assert_eq('minimal notes null', null, $min['row']['notes']);
assert_eq('minimal customer_type null', null, $min['row']['customer_type']);
assert_eq('minimal lead_intro null', null, $min['row']['lead_intro']);
assert_eq('minimal lead_status absent', false, array_key_exists('lead_status', $min['row']));
assert_eq('minimal nature null', null, $min['row']['nature_of_business']);
assert_eq('minimal number_of_pax null', null, $min['row']['number_of_pax']);
assert_eq('minimal client_type null', null, $min['row']['client_type']);
assert_eq('minimal state null', null, $min['row']['state']);
// created_by defaults to null when the caller omits it
assert_eq('minimal created_by null', null, $min['row']['created_by']);

// ---- blank lead rejected ---------------------------------------------------
$blank = ghl_manual_lead_prepare(array('tags' => 'x'), 'u3', $now);
assert_eq('blank rejected', false, $blank['ok']);
assert_eq('blank has error', true, count($blank['errors']) === 1);

// ---- bad email rejected ----------------------------------------------------
$bad = ghl_manual_lead_prepare(array('first_name' => 'A', 'email' => 'nope'), 'u4', $now);
assert_eq('bad email rejected', false, $bad['ok']);

// ---- bad dob rejected ------------------------------------------------------
$baddob = ghl_manual_lead_prepare(array('first_name' => 'A', 'date_of_birth' => '20/05/1990'), 'u5', $now);
assert_eq('bad dob rejected', false, $baddob['ok']);
$rolldob = ghl_manual_lead_prepare(array('first_name' => 'A', 'date_of_birth' => '2026-13-40'), 'u6', $now);
assert_eq('rollover dob rejected', false, $rolldob['ok']);

// ---- unknown gender dropped (not an error) ---------------------------------
$g = ghl_manual_lead_prepare(array('first_name' => 'A', 'gender' => 'wizard'), 'u7', $now);
assert_eq('unknown gender ok', true, $g['ok']);
assert_eq('unknown gender null', null, $g['row']['gender']);

// ---- tags-only encode helper ------------------------------------------------
assert_eq('encode empty', null, ghl_manual_lead_encode_tags('   '));
assert_eq('encode split newlines', '["a","b"]', ghl_manual_lead_encode_tags("a\nb\n"));

// ---- bulk import: column map <-> row mapper stay in lockstep ----------------
$cols = ghl_manual_lead_import_columns();
assert_eq('import col count', 19, count($cols));
assert_eq('first import key', 'first_name', array_values($cols)[0]);
assert_eq('last import key', 'state', array_values($cols)[18]);
// Tags is gone; CLIENT TYPE now sits in the old TAGS slot (index 11).
assert_eq('client_type col', 'client_type', array_values($cols)[11]);

// a filled data row maps 0-indexed cells onto the prepare() POST keys
$rowPost = ghl_manual_lead_row_to_post(array(
    'Ali', 'Acme', '0123456789', 'ali@example.com', 'No 1',
    'Male', 'Malay', 'Malay', 'Malaysia', 'Malaysia', 'Facebook',
    'HRDC', 'keen', 'Company', 'referral', 'New', 'Travel Agency', '11-20', 'Selangor',
));
assert_eq('row first_name', 'Ali', $rowPost['first_name']);
assert_eq('row company', 'Acme', $rowPost['company_name']);
// LEAD STATUS still maps into the POST (import seeds it as a log entry), even
// though it is no longer stored on the ghl_contacts row.
assert_eq('row lead_status', 'New', $rowPost['lead_status']);
assert_eq('row client_type', 'HRDC', $rowPost['client_type']);
assert_eq('row nature', 'Travel Agency', $rowPost['nature_of_business']);
assert_eq('row number_of_pax', '11-20', $rowPost['number_of_pax']);
assert_eq('row state', 'Selangor', $rowPost['state']);
// feeding that mapped row straight into prepare() yields a valid insert row
$rowPrepared = ghl_manual_lead_prepare($rowPost, 'bulk1', $now, 7);
assert_eq('row prepares ok', true, $rowPrepared['ok']);
assert_eq('row prepared status absent', false, array_key_exists('lead_status', $rowPrepared['row']));
assert_eq('row prepared client_type', 'HRDC', $rowPrepared['row']['client_type']);

// header row is dropped (NAME + COMPANY NAME are the first two labels)
assert_eq('header dropped', null, ghl_manual_lead_row_to_post(array(
    'NAME', 'COMPANY NAME', 'CONTACT NUMBER', 'EMAIL',
)));
// fully-blank row is dropped
assert_eq('blank row dropped', null, ghl_manual_lead_row_to_post(array('', '', '', '')));
// non-array is dropped
assert_eq('non-array dropped', null, ghl_manual_lead_row_to_post('nope'));

// ---- country code: form posts dial code + local, combined into stored phone --
$cc = ghl_manual_lead_prepare(array(
    'first_name' => 'Siti', 'phone_country_code' => '+60', 'phone' => '0123456789',
), 'ccp', $now, 7, true);
assert_eq('cc combined stored', '+60 123456789', $cc['row']['phone']);
assert_eq('cc ok with code', true, $cc['ok']);

// require flag on + local number but NO code selected -> error
$ccNo = ghl_manual_lead_prepare(array(
    'first_name' => 'Siti', 'phone_country_code' => '', 'phone' => '0123456789',
), 'ccn', $now, 7, true);
assert_eq('cc missing code fails', false, $ccNo['ok']);
assert_eq('cc missing code error', true, in_array('Please select the phone country code.', $ccNo['errors'], true));

// no phone at all + require flag -> no country-code error (name identifies it)
$ccBlank = ghl_manual_lead_prepare(array('first_name' => 'Siti'), 'ccb', $now, 7, true);
assert_eq('cc blank phone ok', true, $ccBlank['ok']);

// bulk import path (require flag OFF): bare number passes through unchanged
$imp = ghl_manual_lead_prepare(array('first_name' => 'Siti', 'phone' => '0123456789'), 'imp', $now, 7);
assert_eq('import bare phone ok', true, $imp['ok']);
assert_eq('import bare phone stored', '0123456789', $imp['row']['phone']);

$travel = ghl_manual_lead_prepare(array(
    'first_name' => 'Siti', 'travel_date' => '2026-12-15',
    'sales_person_id' => '12', 'op_id' => '34', 'destination' => '  Redang  ',
    'potential_sales_amount' => ' RM 10,000 approx. ', 'client_type' => 'Company trip',
), 'travel', $now, 7);
assert_eq('travel fields valid', true, $travel['ok']);
foreach (array('travel_date' => '2026-12-15', 'sales_person_id' => 12, 'op_id' => 34,
    'destination' => 'Redang', 'potential_sales_amount' => 'RM 10,000 approx.', 'client_type' => 'Company trip') as $field => $expected) {
    assert_eq($field . ' mapped', $expected, $travel['row'][$field]);
}
foreach (array('travel_date' => '2026-02-30', 'sales_person_id' => 'invalid', 'op_id' => '-1') as $field => $invalid) {
    $bad = ghl_manual_lead_prepare(array('first_name' => 'Siti', $field => $invalid), 'bad', $now, 7);
    assert_eq($field . ' invalid rejected', false, $bad['ok']);
}
foreach (array('travel_date', 'sales_person_id', 'op_id', 'destination', 'potential_sales_amount') as $field) {
    assert_eq($field . ' blank optional', null, $ccBlank['row'][$field]);
}

echo "\nAll GhlManualLeadPrepare assertions passed.\n";
