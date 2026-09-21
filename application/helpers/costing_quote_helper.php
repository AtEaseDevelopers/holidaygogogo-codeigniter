<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pure helpers for the Costing Quotation hotel-pricing + flight-schedule tables
 * (the customer-facing Quotation PDF, screenshot layout from the 9 Sep 2026
 * feedback). Each HOTEL row is a name + twin/triple price + single supplement;
 * each FLIGHT row is a travel date + sector + flight no + timing + duration. The
 * surrounding free-text (pricing basis, notes, expiry, flight fare price) applies
 * to the WHOLE quote and lives on costing_packages.
 *
 * Kept free of the CI super-object so the model can reuse them and PHPUnit can
 * drive them directly. All values are plain text (no rich HTML), so "blank" is
 * simply an empty trim.
 */

if (!function_exists('costing_quote_price_normalize')) {
    /**
     * Normalise a posted price into a float, or null when blank/non-numeric.
     * Accepts "1,999", "RM 1999", "1999.00", etc.
     *
     * @param mixed $value
     * @return float|null
     */
    function costing_quote_price_normalize($value)
    {
        if (is_array($value)) {
            return null;
        }
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);
        if ($clean === '' || $clean === '-' || $clean === '.') {
            return null;
        }
        if (!is_numeric($clean)) {
            return null;
        }
        return round((float) $clean, 2);
    }
}

if (!function_exists('costing_quote_hotel_prepare_row')) {
    /**
     * Normalise one posted hotel row into an insert-ready shape. 'is_empty' is
     * true only when the name AND both prices are blank, so the caller can skip
     * the row entirely.
     *
     * @param array $row
     * @return array {hotel_name:?string, twin_triple_price:?float, single_supp_price:?float, is_empty:bool}
     */
    function costing_quote_hotel_prepare_row($row, $col_count = 1)
    {
        $row = (array) $row;

        $name  = trim((string) (isset($row['hotel_name']) ? $row['hotel_name'] : ''));
        $single = costing_quote_price_normalize(isset($row['single_supp_price']) ? $row['single_supp_price'] : null);

        // Feedback 18 Sep 2026 (4.2): a hotel row can carry a price per configurable
        // pricing column (Twin / Triple / 2–4 Pax …). Prefer the explicit per-column
        // 'prices' array; fall back to the single legacy twin_triple_price column.
        if (array_key_exists('prices', $row)) {
            $prices = costing_quote_hotel_prices_normalize($row['prices'], $col_count);
        } else {
            $prices = costing_quote_hotel_prices_normalize(
                array(isset($row['twin_triple_price']) ? $row['twin_triple_price'] : null),
                $col_count
            );
        }
        $twin = $prices[0]; // legacy first column + PDF fallback

        $all_prices_null = true;
        foreach ($prices as $p) {
            if ($p !== null) { $all_prices_null = false; break; }
        }

        return array(
            'hotel_name'        => $name !== '' ? $name : null,
            'twin_triple_price' => $twin,
            'single_supp_price' => $single,
            'prices_json'       => json_encode($prices),
            'is_empty'          => ($name === '' && $single === null && $all_prices_null),
        );
    }
}

if (!function_exists('costing_quote_hotel_title_presets')) {
    /**
     * Selectable pricing-column titles for the hotel table (feedback 4.2). Users
     * can also type their own; these are just the quick-pick presets.
     *
     * @return string[]
     */
    function costing_quote_hotel_title_presets()
    {
        return array('Twin or Triple', 'Twin', 'Triple', '2–4 Pax', '5–6 Pax', '6–8 Pax');
    }
}

if (!function_exists('costing_quote_hotel_columns_normalize')) {
    /**
     * Normalise the hotel pricing-column labels (a JSON string or array) into a
     * clean list of at least one label. Blank labels are dropped; an all-blank set
     * falls back to the default single "Twin / Triple" column.
     *
     * @param mixed $value JSON string, array of strings, or array of {label}
     * @return string[]
     */
    function costing_quote_hotel_columns_normalize($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : array($value);
        }
        $out = array();
        foreach ((array) $value as $label) {
            if (is_array($label)) {
                $label = isset($label['label']) ? $label['label'] : '';
            }
            $label = trim((string) $label);
            if ($label !== '') {
                $out[] = $label;
            }
        }
        if (empty($out)) {
            $out = array('Twin / Triple');
        }
        return $out;
    }
}

if (!function_exists('costing_quote_hotel_prices_normalize')) {
    /**
     * Normalise a hotel row's per-column prices (JSON string or array) into exactly
     * $count floats-or-nulls, aligned to the configured pricing columns.
     *
     * @param mixed $value JSON string or array of prices
     * @param int   $count number of pricing columns
     * @return array<int, float|null>
     */
    function costing_quote_hotel_prices_normalize($value, $count)
    {
        $count = max(1, (int) $count);
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : array($value);
        }
        $value = array_values((array) $value);
        $out = array();
        for ($i = 0; $i < $count; $i++) {
            $out[] = costing_quote_price_normalize(isset($value[$i]) ? $value[$i] : null);
        }
        return $out;
    }
}

if (!function_exists('costing_quote_flight_modes')) {
    /**
     * Flight section modes (feedback 4.3), code => label:
     *   none    — no flight details or pricing required
     *   include — flight details only (no pricing table)
     *   fit     — flight details + pricing table
     *   git     — flight details + pricing table
     *
     * @return array<string,string>
     */
    function costing_quote_flight_modes()
    {
        return array(
            'none'    => 'No Flight',
            'include' => 'Include Flight (details only)',
            'fit'     => 'FIT Flight (details + pricing)',
            'git'     => 'GIT Flight (details + pricing)',
        );
    }
}

if (!function_exists('costing_quote_normalize_flight_mode')) {
    /**
     * Clamp a flight mode to a known code. Legacy/unknown defaults to 'fit' so
     * existing quotations (which showed schedule + pricing) are unchanged.
     *
     * @param mixed $mode
     * @return string one of none|include|fit|git
     */
    function costing_quote_normalize_flight_mode($mode)
    {
        $mode = strtolower(trim((string) $mode));
        return array_key_exists($mode, costing_quote_flight_modes()) ? $mode : 'fit';
    }
}

if (!function_exists('costing_quote_flight_mode_shows_section')) {
    /**
     * Whether the flight section is shown at all (everything except 'none').
     *
     * @param mixed $mode
     * @return bool
     */
    function costing_quote_flight_mode_shows_section($mode)
    {
        return costing_quote_normalize_flight_mode($mode) !== 'none';
    }
}

if (!function_exists('costing_quote_flight_mode_shows_pricing')) {
    /**
     * Whether the flight section shows a pricing table (FIT / GIT only).
     *
     * @param mixed $mode
     * @return bool
     */
    function costing_quote_flight_mode_shows_pricing($mode)
    {
        return in_array(costing_quote_normalize_flight_mode($mode), array('fit', 'git'), true);
    }
}

if (!function_exists('costing_quote_airlines')) {
    /**
     * Common airline quick-picks for a flight option (feedback item 3). Users can
     * still type any airline; these are the presets. Code => label.
     *
     * @return array<string,string>
     */
    function costing_quote_airlines()
    {
        return array(
            'MH' => 'Malaysia Airlines (MH)',
            'AK' => 'AirAsia (AK)',
            'D7' => 'AirAsia X (D7)',
            'OD' => 'Batik Air (OD)',
            'SQ' => 'Singapore Airlines (SQ)',
            'TR' => 'Scoot (TR)',
            'VN' => 'Vietnam Airlines (VN)',
            'VJ' => 'VietJet Air (VJ)',
            'TG' => 'Thai Airways (TG)',
            'SL' => 'Thai Lion Air (SL)',
            'CX' => 'Cathay Pacific (CX)',
            'EK' => 'Emirates (EK)',
        );
    }
}

if (!function_exists('costing_quote_flight_option_prepare')) {
    /**
     * Normalise one posted flight OPTION's meta (feedback 4.4 — multiple airline
     * options in a single quotation). The option's schedule rows are handled
     * separately (costing_quote_flight_prepare_row). 'is_empty' is true only when
     * every meta field is blank — the caller keeps the option anyway if it still
     * has schedule rows.
     *
     * @param array $row
     * @return array {title, airline, price, fare_includes, fare_expiry, is_empty}
     */
    function costing_quote_flight_option_prepare($row)
    {
        $row = (array) $row;
        $title   = trim((string) (isset($row['title']) ? $row['title'] : ''));
        $airline = trim((string) (isset($row['airline']) ? $row['airline'] : ''));
        $price   = costing_quote_price_normalize(isset($row['price']) ? $row['price'] : null);
        $fare    = trim((string) (isset($row['fare_includes']) ? $row['fare_includes'] : ''));
        $expiry  = trim((string) (isset($row['fare_expiry']) ? $row['fare_expiry'] : ''));

        return array(
            'title'         => $title !== '' ? $title : null,
            'airline'       => $airline !== '' ? $airline : null,
            'price'         => $price,
            'fare_includes' => $fare !== '' ? $fare : null,
            'fare_expiry'   => $expiry !== '' ? $expiry : null,
            'is_empty'      => ($title === '' && $airline === '' && $price === null && $fare === '' && $expiry === ''),
        );
    }
}

if (!function_exists('costing_quote_prepare_extra_level')) {
    /**
     * Normalise the NEW package-level quote fields introduced by the 18 Sep 2026
     * feedback (flight mode + hotel pricing columns). Kept separate from
     * costing_quote_prepare_level so that helper's stable 8-field contract holds.
     *
     * @param array $post
     * @return array {quote_flight_mode:string, quote_hotel_columns:string(JSON)}
     */
    function costing_quote_prepare_extra_level($post)
    {
        $post = (array) $post;
        return array(
            'quote_flight_mode'   => costing_quote_normalize_flight_mode(isset($post['quote_flight_mode']) ? $post['quote_flight_mode'] : null),
            'quote_hotel_columns' => json_encode(costing_quote_hotel_columns_normalize(isset($post['quote_hotel_columns']) ? $post['quote_hotel_columns'] : null)),
        );
    }
}

if (!function_exists('costing_quote_flight_prepare_row')) {
    /**
     * Normalise one posted flight row into an insert-ready shape. 'is_empty' is
     * true only when every field is blank.
     *
     * @param array $row
     * @return array {travel_date:?string, sector:?string, flight_no:?string, timing:?string, duration:?string, is_empty:bool}
     */
    function costing_quote_flight_prepare_row($row)
    {
        $row = (array) $row;
        $fields = array('travel_date', 'sector', 'flight_no', 'timing', 'duration');

        $out = array();
        $all_blank = true;
        foreach ($fields as $field) {
            $val = trim((string) (isset($row[$field]) ? $row[$field] : ''));
            $out[$field] = $val !== '' ? $val : null;
            if ($val !== '') {
                $all_blank = false;
            }
        }
        $out['is_empty'] = $all_blank;
        return $out;
    }
}

if (!function_exists('costing_quote_level_fields')) {
    /**
     * The quote-level free-text columns on costing_packages, in a stable order.
     * quote_flight_price is a decimal; the rest are text.
     *
     * @return string[]
     */
    function costing_quote_level_fields()
    {
        return array(
            'quote_pricing_basis',
            'quote_travel_date_note',
            'quote_hotel_note',
            'quote_flight_title',
            'quote_flight_price',
            'quote_flight_fare_note',
            'quote_flight_expiry',
            'quote_footer_notes',
        );
    }
}

if (!function_exists('costing_quote_prepare_level')) {
    /**
     * Normalise the posted quote-level fields into a package-update shape. Text
     * fields are trimmed and nulled when blank; quote_flight_price is normalised
     * to a float (or null). Only known columns are returned, so arbitrary posted
     * keys can never reach the update.
     *
     * @param array $post
     * @return array
     */
    function costing_quote_prepare_level($post)
    {
        $post = (array) $post;
        $out  = array();
        foreach (costing_quote_level_fields() as $column) {
            if ($column === 'quote_flight_price') {
                $out[$column] = costing_quote_price_normalize(isset($post[$column]) ? $post[$column] : null);
                continue;
            }
            $val = trim((string) (isset($post[$column]) ? $post[$column] : ''));
            $out[$column] = $val !== '' ? $val : null;
        }
        return $out;
    }
}

if (!function_exists('costing_quote_default_footer_notes')) {
    /**
     * The default boilerplate footer notes shown (highlighted) at the bottom of
     * the quote. Seeded into the editor when the package has none saved yet, and
     * used verbatim on the PDF when the package field is blank. One note per line.
     *
     * @return string
     */
    function costing_quote_default_footer_notes()
    {
        return implode("\n", array(
            'Note: Pricing quoted as Group Rate. Please verify the fare breakdown before you accept the fare quote. Seats are limited and subject to availability.',
            '*Fares available on a first-come-first serve basis or it will expire. Seats are not guaranteed until booked. Taxes are subject to change.',
            '*All flight schedules are correct at the time of publication and dissemination; however, these are subject to change without prior notice.',
            '*Each flight sector quote reduction or increase for the number of passengers will affect the air fare & need to re-quote accordingly.',
            '*Seat subject to availability & fare subject to change without prior notice; NO seat HOLD on this stage.',
        ));
    }
}

if (!function_exists('costing_quote_footer_note_lines')) {
    /**
     * Split a stored footer-notes blob into trimmed, non-empty lines for display.
     * Falls back to the default boilerplate when nothing is saved.
     *
     * @param string|null $stored
     * @return string[]
     */
    function costing_quote_footer_note_lines($stored)
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            $stored = costing_quote_default_footer_notes();
        }
        $lines = preg_split('/\r\n|\r|\n/', $stored);
        $out = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }
}
