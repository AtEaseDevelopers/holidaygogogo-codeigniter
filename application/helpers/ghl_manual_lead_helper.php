<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Country-code phone helpers (combine picker code + local number into the stored
// "+60 123456789" shape). Pulled in here so create + bulk-import share it.
require_once __DIR__ . '/phone_country_helper.php';

/**
 * Prepare a hand-entered ("Manual") GHL lead for insertion into ghl_contacts.
 *
 * The GHL Leads page normally reads contacts synced from the GHL API (upserted
 * by their GHL contact_id). A user can also create a lead by hand: we store it
 * in the SAME table so it shows in the same listing, but flag it lead_source =
 * 'manual' and give it a SYNTHETIC contact_id ("manual:<uid>") that can never
 * collide with a real GHL id — so the API sync (which upserts by GHL id) never
 * touches, overwrites or deletes a manual lead.
 *
 * This is a pure mapper/validator (no DB, no clock, no randomness) so it unit
 * tests in isolation: the caller passes the unique suffix and the timestamp.
 *
 * Fields mirror the GHL Leads columns: first/last name, contact number, email,
 * tags, gender, language, race, nationality, date of birth. A lead needs at
 * least ONE identifying value (name, phone or email) — the same "not blank"
 * rule the booking branch applies — otherwise it would be an invisible, useless
 * "—" row.
 *
 * @param array  $post request fields (first_name,last_name,company_name,phone,
 *                     email,address,gender,chat_language,race,nationality,country,
 *                     source,tags,notes,customer_type,lead_intro,date_of_birth).
 *                     NOTE: a lead's status is NOT a ghl_contacts column — it lives
 *                     in the dated lead_status_log. Any 'lead_status' POST key is
 *                     ignored here; the caller seeds it into the log (see
 *                     Manual_Leads::Seed_Status_Log / the bulk Import loop).
 * @param string $uid  a unique suffix for the synthetic contact_id (caller supplies)
 * @param string $now  'Y-m-d H:i:s' capture time (caller supplies)
 * @param int|null $created_by admin id of the creator (caller supplies from the
 *                     session — NEVER from $post; drives per-creator visibility)
 * @return array{ok:bool,errors:string[],row:array}
 */
function ghl_manual_lead_prepare($post, $uid, $now, $created_by = null, $require_country_code = false)
{
    $post = is_array($post) ? $post : array();

    $get = function ($key) use ($post) {
        return isset($post[$key]) ? trim((string) $post[$key]) : '';
    };

    $first_name = $get('first_name');
    $last_name  = $get('last_name');
    $company    = $get('company_name');
    // The create/edit form posts the dial code and the local number separately;
    // combine them into the stored "+60 123456789" shape. Bulk import posts a
    // single 'phone' with no code, which passes through unchanged.
    $phone_local = $get('phone');
    $phone_code  = $get('phone_country_code');
    $phone       = phone_country_combine($phone_code, $phone_local);
    $email       = $get('email');
    $address    = $get('address');
    $gender     = $get('gender');
    $language   = $get('chat_language');
    $race       = $get('race');
    $nationality = $get('nationality');
    $country    = $get('country');
    $source     = $get('source');
    $notes      = $get('notes');
    $customer_type = $get('customer_type');
    $lead_intro = $get('lead_intro');
    $nature     = $get('nature_of_business');
    $number_of_pax = $get('number_of_pax');
    $client_type = $get('client_type');
    $state      = $get('state');
    $dob_raw    = $get('date_of_birth');
    $tags_raw   = isset($post['tags']) ? (string) $post['tags'] : '';

    $errors = array();

    // A lead must carry at least one identifying value, else it is a blank row.
    if ($first_name === '' && $last_name === '' && $phone === '' && $email === '') {
        $errors[] = 'Enter at least a name, contact number or email.';
    }

    // Create/edit form: a contact number must carry a country code. Bulk import
    // (require flag off) keeps accepting a bare number, unchanged.
    if ($require_country_code && $phone_local !== '' && ! phone_country_has_code($phone)) {
        $errors[] = 'Please select the phone country code.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is not valid.';
    }

    // Accept only a real calendar date; HTML5 <input type="date"> emits Y-m-d.
    $dob = ghl_manual_lead_normalize_dob($dob_raw);
    if ($dob_raw !== '' && $dob === null) {
        $errors[] = 'Date of birth is not a valid date.';
    }

    $travel_raw = $get('travel_date');
    $travel_end_raw = $get('travel_end_date');
    // The booking-style picker posts DD/MM/YYYY - DD/MM/YYYY. Keep accepting
    // ISO dates for existing integrations and single-date records.
    if (array_key_exists('travel_date_range', $post)) {
        $range = $get('travel_date_range');
        $travel_raw = $travel_end_raw = '';
        if ($range !== '') {
            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4}) - (\d{2})\/(\d{2})\/(\d{4})$/', $range, $m)) {
                $travel_raw = $m[3] . '-' . $m[2] . '-' . $m[1];
                $travel_end_raw = $m[6] . '-' . $m[5] . '-' . $m[4];
            } else {
                $errors[] = 'Travel date must be a valid date range.';
            }
        }
    }
    $travel_date = ghl_manual_lead_normalize_dob($travel_raw);
    $travel_end_date = ghl_manual_lead_normalize_dob($travel_end_raw);
    if (($travel_raw !== '' && $travel_date === null) || ($travel_end_raw !== '' && $travel_end_date === null)) {
        $errors[] = 'Travel date must be a valid date range.';
    } elseif ($travel_date === null && $travel_end_date !== null) {
        $errors[] = 'Travel start date is required when an end date is provided.';
    } elseif ($travel_date !== null) {
        if ($travel_end_date === null) { $travel_end_date = $travel_date; }
        if ($travel_end_date < $travel_date) {
            $errors[] = 'Travel end date cannot be before the start date.';
        }
    }
    foreach (array('sales_person_id', 'op_id') as $field) {
        if ($get($field) !== '' && (!ctype_digit($get($field)) || (int) $get($field) < 1)) {
            $errors[] = 'Please select a valid ' . ($field === 'op_id' ? 'OP' : 'Sales Person') . '.';
        }
    }

    $gender = ghl_manual_lead_normalize_gender($gender);

    $tags_json = ghl_manual_lead_encode_tags($tags_raw);

    if (!empty($errors)) {
        return array('ok' => false, 'errors' => $errors, 'row' => array());
    }

    $row = array(
        'contact_id'    => 'manual:' . $uid,
        'lead_source'   => 'manual',
        'first_name'    => $first_name !== '' ? $first_name : null,
        'last_name'     => $last_name !== '' ? $last_name : null,
        'company_name'  => $company !== '' ? $company : null,
        'phone'         => $phone !== '' ? $phone : null,
        'email'         => $email !== '' ? $email : null,
        'address'       => $address !== '' ? $address : null,
        'gender'        => $gender !== '' ? $gender : null,
        'chat_language' => $language !== '' ? $language : null,
        'race'          => $race !== '' ? $race : null,
        'nationality'   => $nationality !== '' ? $nationality : null,
        'country'       => $country !== '' ? $country : null,
        'source'        => $source !== '' ? $source : null,
        'notes'         => $notes !== '' ? $notes : null,
        'customer_type' => $customer_type !== '' ? $customer_type : null,
        'lead_intro'    => $lead_intro !== '' ? $lead_intro : null,
        'nature_of_business' => $nature !== '' ? $nature : null,
        'number_of_pax' => $number_of_pax !== '' ? $number_of_pax : null,
        'client_type'   => $client_type !== '' ? $client_type : null,
        'state'         => $state !== '' ? $state : null,
        'travel_date' => $travel_date,
        'travel_end_date' => $travel_end_date,
        'sales_person_id' => $get('sales_person_id') !== '' ? (int) $get('sales_person_id') : null,
        'op_id' => $get('op_id') !== '' ? (int) $get('op_id') : null,
        'destination' => $get('destination') !== '' ? $get('destination') : null,
        'potential_sales_amount' => $get('potential_sales_amount') !== '' ? $get('potential_sales_amount') : null,
        'date_of_birth' => $dob,
        'tags_json'     => $tags_json,
        'date_added'    => $now,
        // Server-supplied creator (session admin id), not a $post field.
        'created_by'    => $created_by !== null ? (int) $created_by : null,
    );

    return array('ok' => true, 'errors' => array(), 'row' => $row);
}

/**
 * Normalize the free-typed tags field into the SAME storage shape the GHL Leads
 * listing already parses (a JSON array of tag strings, exactly like one
 * ghl_conversations.tags_json row), so ghl_lead_tags_parse() renders manual and
 * synced tags identically. Splits on comma / newline, trims, drops blanks, and
 * de-duplicates case-insensitively (first-seen order). Returns null when empty.
 *
 * @param string $raw
 * @return string|null JSON array, or null when no tags
 */
function ghl_manual_lead_encode_tags($raw)
{
    $raw = (string) $raw;
    if (trim($raw) === '') {
        return null;
    }

    $out  = array();
    $seen = array();
    foreach (preg_split('/[,\r\n]+/', $raw) as $tag) {
        $tag = trim($tag);
        if ($tag === '') {
            continue;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($tag) : strtolower($tag);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $tag;
    }

    if (empty($out)) {
        return null;
    }

    return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Accept only an actual Y-m-d calendar date (what <input type="date"> sends);
 * anything else — blank, "0000-00-00", garbage — becomes null.
 *
 * @param string $raw
 * @return string|null 'Y-m-d' or null
 */
function ghl_manual_lead_normalize_dob($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $raw);
    if ($d === false) {
        return null;
    }
    // createFromFormat is lax (2026-13-40 rolls over); reject if it round-trips
    // to a different string.
    if ($d->format('Y-m-d') !== $raw) {
        return null;
    }
    return $raw;
}

/**
 * Constrain gender to the three values the Gender filter/column uses; anything
 * else is dropped to '' (stored as null by the caller).
 *
 * @param string $raw
 * @return string 'Male' | 'Female' | 'Other' | ''
 */
function ghl_manual_lead_normalize_gender($raw)
{
    $raw = trim((string) $raw);
    foreach (array('Male', 'Female', 'Other') as $allowed) {
        if (strcasecmp($raw, $allowed) === 0) {
            return $allowed;
        }
    }
    return '';
}

/**
 * Legacy positional template columns (retained for older helper integrations).
 * The current server import uses manual_lead_import_helper and export headers.
 * Sheet column label => the POST key
 * ghl_manual_lead_prepare() reads. One place so the template (Import_Template)
 * and the parser (row_to_post) can never drift apart.
 *
 * @return array ordered label => post-key
 */
function ghl_manual_lead_import_columns()
{
    return array(
        'NAME'            => 'first_name',
        'COMPANY NAME'    => 'company_name',
        'CONTACT NUMBER'  => 'phone',
        'EMAIL'           => 'email',
        'ADDRESS'         => 'address',
        'GENDER'          => 'gender',
        'LANGUAGE'        => 'chat_language',
        'RACE'            => 'race',
        'NATIONALITY'     => 'nationality',
        'COUNTRY'         => 'country',
        'SOURCE'          => 'source',
        'CLIENT TYPE'     => 'client_type',
        'NOTES'           => 'notes',
        'CUSTOMER TYPE'   => 'customer_type',
        'LEAD INTRO'      => 'lead_intro',
        'LEAD STATUS'     => 'lead_status',
        'NATURE OF BUSINESS' => 'nature_of_business',
        'NUMBER OF PAX'   => 'number_of_pax',
        'STATE'           => 'state',
    );
}

/**
 * The fixed "Client Type" dropdown options for a Manual Lead (stored in
 * ghl_contacts.client_type; replaces the old free-typed Tags field). One place so
 * the create/edit form, the filter and the import share the exact same list.
 *
 * @return string[]
 */
function ghl_manual_lead_client_types()
{
    return array('HRDC', 'Meeting', 'Incentive', 'Conference', 'Expo', 'Leisure', 'Company trip');
}

/**
 * The fixed "Number of Pax" bucket options (stored verbatim in
 * ghl_contacts.number_of_pax as the label so the multi-select IN filter matches).
 *
 * @return string[]
 */
function ghl_manual_lead_pax_options()
{
    return array('1-10', '11-20', '21-30', '31-40', '41-50', '51-60', '61-70', '71-80', '81-90', '91-100', '100+');
}

/**
 * The Malaysian states + federal territories for the "State" dropdown (stored in
 * ghl_contacts.state).
 *
 * @return string[]
 */
function ghl_manual_lead_states()
{
    return array(
        'Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang',
        'Perak', 'Perlis', 'Pulau Pinang', 'Sabah', 'Sarawak', 'Selangor',
        'Terengganu', 'Kuala Lumpur', 'Labuan', 'Putrajaya',
    );
}

/**
 * Map ONE raw uploaded sheet row (0-indexed cell array, as PhpSpreadsheet
 * toArray() emits) to the same associative shape ghl_manual_lead_prepare() reads,
 * so bulk import reuses the exact create-form validation. Returns null for the
 * header row and for a fully-blank row (trailing empties Excel leaves behind).
 * Pure + DB-free so it unit-tests in isolation.
 *
 * @param array $row 0-indexed cells
 * @return array|null POST-shaped assoc, or null to skip this row
 */
function ghl_manual_lead_row_to_post($row)
{
    if (!is_array($row)) {
        return null;
    }
    $keys  = array_values(ghl_manual_lead_import_columns()); // post keys, in column order
    $post  = array();
    $blank = true;
    foreach ($keys as $i => $key) {
        $val = isset($row[$i]) ? trim((string) $row[$i]) : '';
        if ($val !== '') {
            $blank = false;
        }
        $post[$key] = $val;
    }

    // Drop the header row wherever it sits (matches the template's first labels).
    if (strcasecmp($post['first_name'], 'NAME') === 0
        && strcasecmp($post['company_name'], 'COMPANY NAME') === 0) {
        return null;
    }
    if ($blank) {
        return null;
    }
    return $post;
}

/** Required listing fields on the interactive Create/Edit form (imports stay separate). */
function ghl_manual_lead_required_form_errors($row)
{
    $errors = array();
    $labels = array(
        'first_name' => 'Guest Name', 'phone' => 'Contact Number',
        'company_name' => 'Company Name', 'client_type' => 'Client Type',
        'travel_date' => 'Travel start date', 'travel_end_date' => 'Travel end date',
        'sales_person_id' => 'Sales Person', 'op_id' => 'OP',
        'destination' => 'Destination', 'potential_sales_amount' => 'Potential Sales Amount',
    );
    foreach ($labels as $field => $label) {
        if (!isset($row[$field]) || trim((string) $row[$field]) === '') { $errors[] = $label . ' is required.'; }
    }
    return $errors;
}
