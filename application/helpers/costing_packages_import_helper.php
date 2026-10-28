<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Excel IMPORT for the Costing Package listing — the mirror of
 * application/helpers/costing_packages_export_helper.php. A workbook can carry MANY
 * packages; the detail sheets group back to their package by Package Name.
 *
 * Sheets (same five the export produces, extended with the few fields the save
 * methods need but the export omits):
 *   Packages      - one row per package (header + booking + margin + quote-level)
 *   Cost Items    - cost lines, grouped by Package Name, optionally into a Combination
 *   Combinations  - the named customer bundles (is_general + manual selling price)
 *   Hotels        - per-package hotel quotation rows
 *   Flights       - per-package flight rows, grouped into a Flight Option
 *   Itinerary     - per-package day rows (day number, title, description, meal plan)
 *
 * The parser (costing_import_sheet_to_records) + the assembler
 * (costing_import_assemble_packages) are PURE (no DB / no PhpSpreadsheet) so they
 * unit-test in isolation; the controller feeds them DB lookup maps and then hands
 * each assembled package to Costing_Model::Import_Package, which reuses the exact
 * save paths the wizard uses (Save_Package -> Generate_Booking_Snapshot ->
 * Save_Quote_Details). Only costing_packages_import_template_stream() touches
 * PhpSpreadsheet.
 */

/* ------------------------------------------------------------ Column maps */

if (!function_exists('costing_import_package_columns')) {
    /** Packages sheet: label => record key, in column order. */
    function costing_import_package_columns()
    {
        return array(
            'PACKAGE NAME'           => 'name',
            'CUSTOMER NAME'          => 'customer_name',
            'CONTACT NUMBER'         => 'customer_contact',
            'EMAIL ADDRESS'          => 'customer_email',
            'SALES PERSON'           => 'sales_person',
            'DURATION DAYS'          => 'duration_days',
            'DURATION NIGHTS'        => 'duration_nights',
            'STATUS'                 => 'status',
            'DESCRIPTION'            => 'description',
            'TRAVEL DATE'            => 'travel_date',
            'TRAVEL DATE END'        => 'travel_date_end',
            'ADULT PAX'              => 'adult_count',
            'CHILD PAX'              => 'child_count',
            'MARGIN %'               => 'margin_percentage',
            'COMMISSIONABLE PER PAX' => 'commissionable_per_pax',
            'AD HOC PER PAX'         => 'ad_hoc_per_pax',
            'FLIGHT MODE'            => 'quote_flight_mode',
            'HOTEL COLUMNS'          => 'quote_hotel_columns',
            'HOTEL TITLE LABEL'      => 'quote_hotel_title_label',
            'SHOW SINGLE SUPPLEMENT' => 'quote_show_single_supp',
            'ITINERARY NOTES'        => 'itinerary_notes',
        );
    }
}

if (!function_exists('costing_import_item_columns')) {
    /** Cost Items sheet: label => record key, in column order. */
    function costing_import_item_columns()
    {
        return array(
            'PACKAGE NAME'    => 'package_name',
            'COMBINATION'     => 'combination',
            'CATEGORY'        => 'category',
            'ITEM'            => 'name',
            'SUPPLIER'        => 'supplier',
            'PAX TYPE'        => 'pax_type',
            'MULTIPLIER TYPE' => 'multiplier_type',
            'QUANTITY'        => 'quantity',
            'UNIT COUNT'      => 'unit_count',
            'UNIT PRICE'      => 'unit_price',
            'CURRENCY'        => 'currency',
        );
    }
}

if (!function_exists('costing_import_combination_columns')) {
    /** Combinations sheet: label => record key, in column order. */
    function costing_import_combination_columns()
    {
        return array(
            'PACKAGE NAME'          => 'package_name',
            'COMBINATION NAME'      => 'name',
            'IS GENERAL'            => 'is_general',
            'SELLING PRICE PER PAX' => 'selling_price_per_pax',
        );
    }
}

if (!function_exists('costing_import_hotel_columns')) {
    /** Hotels sheet: label => record key, in column order. */
    function costing_import_hotel_columns()
    {
        return array(
            'PACKAGE NAME'       => 'package_name',
            'HOTEL NAME(S)'      => 'hotel_name',
            'TWIN / TRIPLE PRICE' => 'twin_triple_price',
            'SINGLE SUPPLEMENT'  => 'single_supp_price',
        );
    }
}

if (!function_exists('costing_import_flight_columns')) {
    /** Flights sheet: label => record key, in column order. */
    function costing_import_flight_columns()
    {
        return array(
            'PACKAGE NAME'  => 'package_name',
            'FLIGHT OPTION' => 'option_title',
            'AIRLINE'       => 'airline',
            'OPTION PRICE'  => 'option_price',
            'DATE'          => 'travel_date',
            'SECTOR'        => 'sector',
            'FLIGHT NO'     => 'flight_no',
            'TIMING'        => 'timing',
            'DURATION'      => 'duration',
        );
    }
}

if (!function_exists('costing_import_itinerary_columns')) {
    /** Itinerary sheet: label => record key, in column order. */
    function costing_import_itinerary_columns()
    {
        return array(
            'PACKAGE NAME' => 'package_name',
            'DAY NUMBER'   => 'day_number',
            'TITLE'        => 'title',
            'DESCRIPTION'  => 'description',
            'MEAL PLAN'    => 'meal_plan',
        );
    }
}

if (!function_exists('costing_import_sheets_meta')) {
    /**
     * The five data sheets, in parse/template order: [key, title, columns].
     * 'key' is the bucket name costing_import_assemble_packages() reads.
     */
    function costing_import_sheets_meta()
    {
        return array(
            array('packages',     'Packages',     costing_import_package_columns()),
            array('cost_items',   'Cost Items',   costing_import_item_columns()),
            array('combinations', 'Combinations', costing_import_combination_columns()),
            array('hotels',       'Hotels',       costing_import_hotel_columns()),
            array('flights',      'Flights',      costing_import_flight_columns()),
            array('itinerary',    'Itinerary',    costing_import_itinerary_columns()),
        );
    }
}

/* ------------------------------------------------------------ Parsing */

if (!function_exists('costing_import_sheet_to_records')) {
    /**
     * Convert one raw sheet (0-indexed cell arrays, as PhpSpreadsheet
     * toArray(null,true,true,false) emits) into a list of associative records
     * keyed by the column map's record keys. Skips the header row (first cell
     * matches the first label, case-insensitive) and fully-blank rows. Pure.
     *
     * @param array $sheet   list of 0-indexed cell arrays
     * @param array $columns label => key map (costing_import_*_columns())
     * @return array list of record assoc arrays
     */
    function costing_import_sheet_to_records($sheet, $columns)
    {
        $labels = array_keys($columns);
        $keys   = array_values($columns);
        $first_label = isset($labels[0]) ? $labels[0] : '';

        $records = array();
        foreach ((array) $sheet as $row) {
            if (!is_array($row)) {
                continue;
            }
            $record = array();
            $blank  = true;
            foreach ($keys as $i => $key) {
                $val = isset($row[$i]) ? trim((string) $row[$i]) : '';
                if ($val !== '') {
                    $blank = false;
                }
                $record[$key] = $val;
            }
            if ($blank) {
                continue;
            }
            // Header row wherever it sits.
            $first_val = isset($row[0]) ? trim((string) $row[0]) : '';
            if ($first_label !== '' && strcasecmp($first_val, $first_label) === 0) {
                continue;
            }
            $records[] = $record;
        }
        return $records;
    }
}

/* ------------------------------------------------------------ Small coercers */

if (!function_exists('costing_import_bool')) {
    /** Y / Yes / True / 1 => true; everything else => false. */
    function costing_import_bool($value)
    {
        $v = strtolower(trim((string) $value));
        return in_array($v, array('y', 'yes', 'true', '1'), true);
    }
}

if (!function_exists('costing_import_num')) {
    /** Parse a loosely-typed numeric cell (strips thousands commas). */
    function costing_import_num($value)
    {
        $v = trim((string) $value);
        if ($v === '') {
            return 0.0;
        }
        return (float) str_replace(',', '', $v);
    }
}

if (!function_exists('costing_import_pax_type')) {
    /** Normalise a pax-type cell to '', 'adult' or 'child'. */
    function costing_import_pax_type($value)
    {
        $v = strtolower(trim((string) $value));
        return in_array($v, array('adult', 'child'), true) ? $v : '';
    }
}

if (!function_exists('costing_import_meal_plan_tokens')) {
    /**
     * Turn a comma-separated Meal Plan cell into the slug tokens
     * costing_meal_plan_normalize() understands. Accepts either slugs
     * ("tea_break") or display labels ("Tea Break", "Breakfast"): each token is
     * lowercased and its spaces turned into underscores. Returns an array (empty
     * when the cell is blank).
     */
    function costing_import_meal_plan_tokens($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return array();
        }
        $out = array();
        foreach (explode(',', $value) as $part) {
            $slug = strtolower(trim($part));
            $slug = preg_replace('/\s+/', '_', $slug);
            if ($slug !== '') {
                $out[] = $slug;
            }
        }
        return $out;
    }
}

/* ------------------------------------------------------------ Assembly */

if (!function_exists('costing_import_assemble_packages')) {
    /**
     * Turn parsed sheet records into ready-to-save package payloads. Pure: all DB
     * knowledge arrives via $lookups.
     *
     * @param array $parsed  ['packages'=>[], 'cost_items'=>[], 'combinations'=>[],
     *                        'hotels'=>[], 'flights'=>[]] (each a record list)
     * @param array $lookups ['currency_by_code'=>[UPPERCODE=>id],
     *                         'category_codes'=>[lowercode=>canonicalCode],
     *                         'sales_by_name'=>[lowername=>AdminID]]
     * @return array ['packages'=>[ {name, save_package, snapshot_payload, hotels,
     *                  flight_options, quote_level, errors[]} ], 'global_errors'=>[]]
     */
    function costing_import_assemble_packages($parsed, $lookups)
    {
        $currency_by_code = isset($lookups['currency_by_code']) ? (array) $lookups['currency_by_code'] : array();
        $category_codes   = isset($lookups['category_codes']) ? (array) $lookups['category_codes'] : array();
        $sales_by_name    = isset($lookups['sales_by_name']) ? (array) $lookups['sales_by_name'] : array();

        $package_records = isset($parsed['packages']) ? (array) $parsed['packages'] : array();
        $item_records    = isset($parsed['cost_items']) ? (array) $parsed['cost_items'] : array();
        $combo_records   = isset($parsed['combinations']) ? (array) $parsed['combinations'] : array();
        $hotel_records   = isset($parsed['hotels']) ? (array) $parsed['hotels'] : array();
        $flight_records  = isset($parsed['flights']) ? (array) $parsed['flights'] : array();
        $itin_records    = isset($parsed['itinerary']) ? (array) $parsed['itinerary'] : array();

        $global_errors = array();

        // Index packages by trimmed name; flag duplicates.
        $order = array();          // name => position (preserves sheet order)
        $by_name = array();        // lower name => package record
        foreach ($package_records as $rec) {
            $name = trim((string) $rec['name']);
            if ($name === '') {
                $global_errors[] = 'A Packages row has a blank Package Name and was skipped.';
                continue;
            }
            $lk = strtolower($name);
            if (isset($by_name[$lk])) {
                $global_errors[] = 'Duplicate Package Name "' . $name . '" in the file — only the first is used.';
                continue;
            }
            $by_name[$lk] = $rec;
            $order[] = $lk;
        }

        // Bucket detail rows under their package (by name).
        $group = function ($records, $sheet_label) use ($by_name, &$global_errors) {
            $out = array();
            foreach ($records as $rec) {
                $pkg = strtolower(trim((string) (isset($rec['package_name']) ? $rec['package_name'] : '')));
                if ($pkg === '' || !isset($by_name[$pkg])) {
                    $global_errors[] = $sheet_label . ' row references unknown Package Name "'
                        . trim((string) (isset($rec['package_name']) ? $rec['package_name'] : '')) . '".';
                    continue;
                }
                $out[$pkg][] = $rec;
            }
            return $out;
        };

        $items_by_pkg   = $group($item_records, 'Cost Items');
        $combos_by_pkg  = $group($combo_records, 'Combinations');
        $hotels_by_pkg  = $group($hotel_records, 'Hotels');
        $flights_by_pkg = $group($flight_records, 'Flights');
        $itin_by_pkg    = $group($itin_records, 'Itinerary');

        $packages = array();
        foreach ($order as $lk) {
            $rec = $by_name[$lk];
            $name = trim((string) $rec['name']);
            $errors = array();    // block creation of this package
            $warnings = array();  // shown in the confirmation; package is still created

            // --- Header / Save_Package ---
            $sales_key = strtolower(trim((string) $rec['sales_person']));
            $sales_admin_id = ($sales_key !== '' && isset($sales_by_name[$sales_key])) ? (int) $sales_by_name[$sales_key] : 0;
            if ($sales_key !== '' && $sales_admin_id === 0) {
                // Non-blocking: a typo'd sales name should not stop the package.
                $warnings[] = 'Sales Person "' . trim((string) $rec['sales_person']) . '" not found — will be left unset.';
            }

            $status_raw = trim((string) $rec['status']);
            if ($status_raw !== '' && !in_array(strtolower($status_raw), array('active', 'inactive'), true)) {
                $warnings[] = 'Status "' . $status_raw . '" not recognised — will default to active.';
            }

            // Required header fields (Package Name is already guaranteed by grouping).
            if (trim((string) $rec['travel_date']) === '') {
                $errors[] = 'Travel Date is required.';
            }
            $dur_raw = trim((string) $rec['duration_days']);
            if ($dur_raw === '') {
                $errors[] = 'Duration Days is required.';
            } elseif (!is_numeric(str_replace(',', '', $dur_raw)) || (int) costing_import_num($dur_raw) < 1) {
                $errors[] = 'Duration Days must be a number of 1 or more (got "' . $dur_raw . '").';
            }

            $save_package = array(
                'id'              => 0,
                'name'            => $name,
                'customer_name'   => trim((string) $rec['customer_name']),
                'customer_contact' => trim((string) $rec['customer_contact']),
                'customer_email'  => trim((string) $rec['customer_email']),
                'sales_admin_id'  => $sales_admin_id,
                'duration_days'   => (int) costing_import_num($rec['duration_days']),
                'duration_nights' => (int) costing_import_num($rec['duration_nights']),
                'description'     => trim((string) $rec['description']),
                'status'          => $status_raw !== '' ? $status_raw : 'active',
            );

            // --- Cost rows + combinations ---
            // Declared combinations (name => meta) from the Combinations sheet.
            $combo_meta = array();  // lower name => ['name','is_general','selling']
            foreach (isset($combos_by_pkg[$lk]) ? $combos_by_pkg[$lk] : array() as $crec) {
                $cname = trim((string) $crec['name']);
                if ($cname === '') {
                    continue;
                }
                $selling_raw = trim((string) $crec['selling_price_per_pax']);
                $combo_meta[strtolower($cname)] = array(
                    'name'       => $cname,
                    'is_general' => costing_import_bool($crec['is_general']) ? 1 : 0,
                    'selling'    => ($selling_raw !== '' && is_numeric(str_replace(',', '', $selling_raw)))
                        ? round(max(0, costing_import_num($selling_raw)), 2) : null,
                );
            }

            $top_rows = array();     // blank-combination cost rows
            $combo_rows = array();   // lower combo name => [rows]
            foreach (isset($items_by_pkg[$lk]) ? $items_by_pkg[$lk] : array() as $irec) {
                $built = costing_import_build_cost_row($irec, $currency_by_code, $category_codes, $errors, $warnings);
                if ($built === null) {
                    continue;
                }
                $combo = trim((string) $irec['combination']);
                if ($combo === '') {
                    $top_rows[] = $built;
                } else {
                    $ck = strtolower($combo);
                    $combo_rows[$ck][] = $built;
                    if (!isset($combo_meta[$ck])) {
                        // Item names a combination not listed on the Combinations sheet;
                        // create it leniently (no manual price, not general).
                        $combo_meta[$ck] = array('name' => $combo, 'is_general' => 0, 'selling' => null);
                    }
                }
            }

            // Build the combinations payload (only those that actually have rows).
            $combinations = array();
            foreach ($combo_meta as $ck => $meta) {
                $rows = isset($combo_rows[$ck]) ? $combo_rows[$ck] : array();
                if (empty($rows)) {
                    continue;
                }
                $combinations[] = array(
                    'name'                 => $meta['name'],
                    'is_general'           => $meta['is_general'],
                    'selling_price_per_pax' => $meta['selling'],
                    'rows'                 => $rows,
                );
            }

            $adult = (int) costing_import_num($rec['adult_count']);
            $child = (int) costing_import_num($rec['child_count']);
            if (($adult + $child) <= 0) {
                $errors[] = 'Total pax is zero — set Adult Pax and/or Child Pax.';
            }
            // Cost items are OPTIONAL: a package with no (valid) Cost Items rows is
            // still imported with an empty cost list — only the information actually
            // given is saved. Invalid cost rows (unknown category/currency, missing
            // name/price) still raise per-item errors in costing_import_build_cost_row
            // above, so a half-filled row is caught; a genuinely empty sheet is fine.

            $snapshot_payload = array(
                'booking_id'             => 0,
                'travel_date'            => trim((string) $rec['travel_date']),
                'travel_date_end'        => trim((string) $rec['travel_date_end']),
                'adult_count'            => $adult,
                'child_count'            => $child,
                'status'                 => $save_package['status'],
                'rows'                   => $top_rows,
                'combinations'           => $combinations,
                'margin_percentage'      => costing_import_num($rec['margin_percentage']),
                'commissionable_per_pax' => costing_import_num($rec['commissionable_per_pax']),
                'ad_hoc_per_pax'         => costing_import_num($rec['ad_hoc_per_pax']),
                // Import allows a package with zero cost items (wizard saves do not);
                // Generate_Booking_Snapshot honours this flag to still create the
                // booking snapshot so itinerary/hotels/flights have a home.
                'allow_empty_items'      => true,
            );

            // --- Hotels ---
            $hotels = array();
            foreach (isset($hotels_by_pkg[$lk]) ? $hotels_by_pkg[$lk] : array() as $hrec) {
                $hotels[] = array(
                    'hotel_name'        => (string) $hrec['hotel_name'],
                    'twin_triple_price' => $hrec['twin_triple_price'],
                    'single_supp_price' => $hrec['single_supp_price'],
                );
            }

            // --- Flights (grouped into options) ---
            $flight_options = costing_import_group_flight_options(
                isset($flights_by_pkg[$lk]) ? $flights_by_pkg[$lk] : array()
            );

            // --- Itinerary days (ordered; meal_plan left as tokens for the model) ---
            $itinerary = costing_import_order_itinerary(
                isset($itin_by_pkg[$lk]) ? $itin_by_pkg[$lk] : array()
            );
            $itinerary_level = array('notes' => trim((string) (isset($rec['itinerary_notes']) ? $rec['itinerary_notes'] : '')));

            // Flight mode: map the template's labels (No / Include / FIT / GIT) to the
            // stored codes so "No" becomes 'none' rather than defaulting to 'fit'.
            $flight_mode_raw = trim((string) $rec['quote_flight_mode']);
            $flight_mode = costing_import_flight_mode($flight_mode_raw);
            if ($flight_mode_raw !== '' && $flight_mode === null) {
                $warnings[] = 'Flight Mode "' . $flight_mode_raw . '" not recognised — will default to FIT.';
            }

            $quote_level = array(
                'quote_flight_mode'       => $flight_mode !== null ? $flight_mode : $flight_mode_raw,
                'quote_hotel_columns'     => costing_import_split_columns($rec['quote_hotel_columns']),
                'quote_hotel_title_label' => trim((string) $rec['quote_hotel_title_label']),
                'quote_show_single_supp'  => trim((string) $rec['quote_show_single_supp']) === '' ? 1
                    : (costing_import_bool($rec['quote_show_single_supp']) ? 1 : 0),
            );

            $packages[] = array(
                'name'             => $name,
                'save_package'     => $save_package,
                'snapshot_payload' => $snapshot_payload,
                'hotels'           => $hotels,
                'flight_options'   => $flight_options,
                'itinerary'        => $itinerary,
                'itinerary_level'  => $itinerary_level,
                'quote_level'      => $quote_level,
                'errors'           => $errors,
                'warnings'         => $warnings,
                'creatable'        => empty($errors),
            );
        }

        return array('packages' => $packages, 'global_errors' => $global_errors);
    }
}

if (!function_exists('costing_import_flight_mode')) {
    /**
     * Map a Flight Mode cell (template labels or stored codes) to a stored code
     * (none|include|fit|git), or null when unrecognised so the caller can warn.
     * Blank stays blank (Save_Quote_Details then applies its own default).
     */
    function costing_import_flight_mode($value)
    {
        $v = strtolower(trim((string) $value));
        if ($v === '') {
            return '';
        }
        $map = array(
            'no' => 'none', 'none' => 'none', 'no flight' => 'none', 'n' => 'none',
            'include' => 'include', 'inc' => 'include', 'details only' => 'include',
            'fit' => 'fit', 'git' => 'git',
        );
        return isset($map[$v]) ? $map[$v] : null;
    }
}

if (!function_exists('costing_import_build_cost_row')) {
    /**
     * Build one Generate_Booking_Snapshot-shaped cost row from an item record.
     * Resolves currency code -> id and validates the category code, appending to
     * $errors on failure (row dropped, package blocked) and non-blocking field
     * notes to $warnings. Returns null (row dropped) when the row is unusable.
     * bank_charges_myr / myr_per_unit / total_amount are deliberately NOT set so
     * the model recomputes them from the live rates.
     */
    function costing_import_build_cost_row($rec, $currency_by_code, $category_codes, &$errors, &$warnings)
    {
        $name     = trim((string) $rec['name']);
        $cat_raw  = trim((string) $rec['category']);
        $cur_raw  = strtoupper(trim((string) $rec['currency']));
        $price_raw = trim((string) $rec['unit_price']);

        // A row carrying only its Package Name (and maybe Combination) is an empty
        // filler line — skip it silently. Only once it has ANY cost content do we
        // demand the required fields (name / category / currency / unit price).
        $has_content = ($name !== '' || $cat_raw !== '' || $cur_raw !== '' || $price_raw !== ''
            || trim((string) $rec['supplier']) !== '' || trim((string) $rec['quantity']) !== ''
            || trim((string) $rec['unit_count']) !== '' || trim((string) $rec['pax_type']) !== ''
            || trim((string) $rec['multiplier_type']) !== '');
        if (!$has_content) {
            return null;
        }

        if ($name === '') {
            $errors[] = 'A Cost Items row is missing its required Item name'
                . ($cat_raw !== '' ? ' (category "' . $cat_raw . '")' : '') . '.';
            return null;
        }

        if ($cat_raw === '' || !isset($category_codes[strtolower($cat_raw)])) {
            $errors[] = 'Item "' . $name . '": ' . ($cat_raw === '' ? 'Category is required.' : 'unknown Category "' . $cat_raw . '".');
            return null;
        }
        $category = $category_codes[strtolower($cat_raw)];

        if ($cur_raw === '' || !isset($currency_by_code[$cur_raw])) {
            $errors[] = 'Item "' . $name . '": ' . ($cur_raw === '' ? 'Currency is required.' : 'unknown Currency "' . trim((string) $rec['currency']) . '".');
            return null;
        }
        $currency_id = (int) $currency_by_code[$cur_raw];

        if ($price_raw === '' || !is_numeric(str_replace(',', '', $price_raw))) {
            $errors[] = 'Item "' . $name . '": Unit Price is required (a number)' . ($price_raw !== '' ? ' — got "' . $price_raw . '"' : '') . '.';
            return null;
        }

        // Non-blocking field checks (the model normalises these; we just surface them).
        $mult_raw = trim((string) $rec['multiplier_type']);
        if ($mult_raw !== '' && !in_array(strtolower($mult_raw), array('fixed', 'per_day', 'per_pax', 'per_day_pax'), true)) {
            $warnings[] = 'Item "' . $name . '": Multiplier Type "' . $mult_raw . '" not recognised — will default to fixed.';
        }
        $pax_raw = trim((string) $rec['pax_type']);
        if ($pax_raw !== '' && !in_array(strtolower($pax_raw), array('adult', 'child'), true)) {
            $warnings[] = 'Item "' . $name . '": Pax Type "' . $pax_raw . '" not recognised — will be left blank.';
        }

        $quantity = round(max(0, costing_import_num($rec['quantity'])), 2);
        $unit_count = round(max(0, costing_import_num($rec['unit_count'])), 2);

        return array(
            'include'         => 1,
            'name'            => $name,
            'category'        => $category,
            'pax_type'        => costing_import_pax_type($rec['pax_type']),
            'multiplier_type' => $mult_raw,
            'quantity'        => $quantity > 0 ? $quantity : 1,
            'unit_count'      => $unit_count > 0 ? $unit_count : 1,
            'unit_price'      => round(max(0, costing_import_num($rec['unit_price'])), 2),
            'currency_id'     => $currency_id,
            'remark'          => '',
            'supplier'        => trim((string) $rec['supplier']),
        );
    }
}

if (!function_exists('costing_import_group_flight_options')) {
    /**
     * Group flight rows into Save_Quote_Details-shaped options. Rows sharing a
     * Flight Option title belong to one option; rows with a blank option title
     * fall into a single default option. Preserves first-seen order.
     */
    function costing_import_group_flight_options($records)
    {
        $order = array();
        $options = array();
        foreach ((array) $records as $rec) {
            $title = trim((string) $rec['option_title']);
            $key = strtolower($title); // '' => shared default option
            if (!isset($options[$key])) {
                $options[$key] = array(
                    'title'         => $title,
                    'airline'       => trim((string) $rec['airline']),
                    'price'         => trim((string) $rec['option_price']),
                    'fare_includes' => '',
                    'fare_expiry'   => '',
                    'rows'          => array(),
                );
                $order[] = $key;
            }
            // Keep the first non-blank airline / price seen for the option.
            if ($options[$key]['airline'] === '' && trim((string) $rec['airline']) !== '') {
                $options[$key]['airline'] = trim((string) $rec['airline']);
            }
            if ($options[$key]['price'] === '' && trim((string) $rec['option_price']) !== '') {
                $options[$key]['price'] = trim((string) $rec['option_price']);
            }
            $options[$key]['rows'][] = array(
                'travel_date' => trim((string) $rec['travel_date']),
                'sector'      => trim((string) $rec['sector']),
                'flight_no'   => trim((string) $rec['flight_no']),
                'timing'      => trim((string) $rec['timing']),
                'duration'    => trim((string) $rec['duration']),
            );
        }

        $out = array();
        foreach ($order as $key) {
            $out[] = $options[$key];
        }
        return $out;
    }
}

if (!function_exists('costing_import_order_itinerary')) {
    /**
     * Shape + order the itinerary day rows for Save_Itinerary_Days. Rows with an
     * explicit Day Number sort ascending by it; rows without one keep sheet order
     * and follow (Save_Itinerary_Days assigns them a sequential fallback). meal_plan
     * is passed as a token array — the model's costing_itinerary_prepare_row (via
     * costing_meal_plan_normalize) turns it into the stored slug string and drops
     * fully-blank rows.
     */
    function costing_import_order_itinerary($records)
    {
        $numbered = array();
        $unnumbered = array();
        foreach ((array) $records as $i => $rec) {
            $row = array(
                'day_number'  => (int) costing_import_num(isset($rec['day_number']) ? $rec['day_number'] : 0),
                'title'       => trim((string) (isset($rec['title']) ? $rec['title'] : '')),
                'description' => trim((string) (isset($rec['description']) ? $rec['description'] : '')),
                'meal_plan'   => costing_import_meal_plan_tokens(isset($rec['meal_plan']) ? $rec['meal_plan'] : ''),
            );
            if ($row['day_number'] > 0) {
                $numbered[] = array('order' => $i, 'row' => $row);
            } else {
                $unnumbered[] = $row;
            }
        }

        // Stable sort by day_number, then original sheet order for ties.
        usort($numbered, function ($a, $b) {
            if ($a['row']['day_number'] === $b['row']['day_number']) {
                return $a['order'] - $b['order'];
            }
            return $a['row']['day_number'] - $b['row']['day_number'];
        });

        $out = array();
        foreach ($numbered as $n) {
            $out[] = $n['row'];
        }
        foreach ($unnumbered as $row) {
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('costing_import_split_columns')) {
    /** Split a "Twin|Triple" hotel-columns cell into an array (blank => empty). */
    function costing_import_split_columns($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return array();
        }
        $parts = array();
        foreach (preg_split('/[|]/', $value) as $p) {
            $p = trim($p);
            if ($p !== '') {
                $parts[] = $p;
            }
        }
        return $parts;
    }
}

/* ------------------------------------------------------------ Preview summary */

if (!function_exists('costing_import_preview_summary')) {
    /**
     * Turn the assembled structure into a flat, view-ready confirmation summary:
     * one display row per package (counts + creatable flag + errors/warnings) plus
     * the file-level issues and the create/skip tallies. Pure.
     *
     * @param array $assembled costing_import_assemble_packages() output
     * @return array {rows:[], global_errors:[], create_count:int, skip_count:int, total:int}
     */
    function costing_import_preview_summary($assembled)
    {
        $rows = array();
        $create = 0;
        foreach (isset($assembled['packages']) ? (array) $assembled['packages'] : array() as $pkg) {
            $sp = isset($pkg['snapshot_payload']) ? $pkg['snapshot_payload'] : array();

            $item_count = count(isset($sp['rows']) ? (array) $sp['rows'] : array());
            foreach (isset($sp['combinations']) ? (array) $sp['combinations'] : array() as $combo) {
                $item_count += count(isset($combo['rows']) ? (array) $combo['rows'] : array());
            }

            $creatable = !empty($pkg['creatable']);
            if ($creatable) {
                $create++;
            }

            $rows[] = array(
                'name'           => isset($pkg['name']) ? $pkg['name'] : '',
                'pax'            => (int) (isset($sp['adult_count']) ? $sp['adult_count'] : 0)
                                    + (int) (isset($sp['child_count']) ? $sp['child_count'] : 0),
                'combinations'   => count(isset($sp['combinations']) ? (array) $sp['combinations'] : array()),
                'cost_items'     => $item_count,
                'hotels'         => count(isset($pkg['hotels']) ? (array) $pkg['hotels'] : array()),
                'flight_options' => count(isset($pkg['flight_options']) ? (array) $pkg['flight_options'] : array()),
                'itinerary_days' => count(isset($pkg['itinerary']) ? (array) $pkg['itinerary'] : array()),
                'creatable'      => $creatable,
                'errors'         => isset($pkg['errors']) ? (array) $pkg['errors'] : array(),
                'warnings'       => isset($pkg['warnings']) ? (array) $pkg['warnings'] : array(),
            );
        }

        $total = count($rows);
        return array(
            'rows'          => $rows,
            'global_errors' => isset($assembled['global_errors']) ? (array) $assembled['global_errors'] : array(),
            'create_count'  => $create,
            'skip_count'    => $total - $create,
            'total'         => $total,
        );
    }
}

/* ------------------------------------------------------------ Template stream */

if (!function_exists('costing_import_template_sheets')) {
    /**
     * Pure assembly of the template's sheet definitions:
     * [ [title, labels[], sample_row[]], ... ] plus the trailing Instructions sheet.
     * $lookups supplies the reference lists shown on the Instructions sheet.
     */
    function costing_import_template_sheets($lookups)
    {
        $samples = costing_import_template_samples();
        $sheets = array();
        foreach (costing_import_sheets_meta() as $meta) {
            list($key, $title, $columns) = $meta;
            $sheets[] = array(
                'title'   => $title,
                'labels'  => array_keys($columns),
                'samples' => isset($samples[$key]) ? $samples[$key] : array(),
            );
        }
        $sheets[] = array(
            'title'       => 'Instructions',
            'instruction' => costing_import_instruction_rows($lookups),
        );
        return $sheets;
    }
}

if (!function_exists('costing_import_template_samples')) {
    /** One greyed sample row per data sheet (delete before importing). */
    function costing_import_template_samples()
    {
        return array(
            'packages' => array(array(
                'Japan Spring 2027', 'Tan Family', '+60 123456789', 'tan@example.com',
                '', '6', '5', 'active', 'Sakura season highlights',
                '2027-04-01', '2027-04-06', '4', '0', '15', '0', '0',
                'FIT', 'Twin|Triple', 'Hotel', 'Y',
                'Price includes 5 nights hotel, daily breakfast & tour guide.',
            )),
            'cost_items' => array(
                array('Japan Spring 2027', 'Option A - 4 Star', 'accommodation', '5N Hotel Twin', 'JTB', 'adult', 'per_pax', '1', '5', '1200', 'MYR'),
                array('Japan Spring 2027', '', 'tour_leader', 'Tour Leader', '', '', 'fixed', '1', '1', '3000', 'MYR'),
            ),
            'combinations' => array(
                array('Japan Spring 2027', 'Option A - 4 Star', 'N', '4500'),
            ),
            'hotels' => array(
                array('Japan Spring 2027', "Hotel Nikko\nShinjuku Prince", '1200', '600'),
            ),
            'flights' => array(
                array('Japan Spring 2027', 'Option 1 - MAS', 'Malaysia Airlines', '2500', '01 Apr 2027', 'KUL - NRT', 'MH088', '2355 - 0805', '7h 10m'),
            ),
            'itinerary' => array(
                array('Japan Spring 2027', '1', 'Arrival in Tokyo', 'Airport transfer and check-in. Evening free.', 'Dinner'),
                array('Japan Spring 2027', '2', 'Tokyo City Tour', 'Asakusa, Meiji Shrine and Shibuya.', 'Breakfast, Lunch'),
            ),
        );
    }
}

if (!function_exists('costing_import_instruction_rows')) {
    /**
     * The Instructions / Valid Values sheet content as a list of [col1, col2] rows.
     * Pure: the reference lists arrive via $lookups.
     */
    function costing_import_instruction_rows($lookups)
    {
        $rows = array();
        $rows[] = array('HOW TO USE', '');
        $rows[] = array('1.', 'Fill one package per row on the Packages sheet. Package Name must be unique.');
        $rows[] = array('2.', 'On Cost Items / Combinations / Hotels / Flights, put the SAME Package Name to attach rows to a package.');
        $rows[] = array('3.', 'A Cost Item with a COMBINATION joins that customer option; leave it blank for a plain internal cost line.');
        $rows[] = array('4.', 'Delete the grey sample rows before uploading. A package needs pax > 0; cost items are optional (a package with none still imports).');
        $rows[] = array('5.', 'Itinerary: one row per day. Set Day Number to order days; Meal Plan is comma-separated (e.g. Breakfast, Dinner). Package-level notes go in the Packages sheet ITINERARY NOTES column.');
        $rows[] = array('', '');

        $rows[] = array('REQUIRED FIELDS', '(blank/invalid = package skipped)');
        $rows[] = array('Packages', 'PACKAGE NAME (unique), TRAVEL DATE, DURATION DAYS (>=1), and ADULT PAX + CHILD PAX summing to 1 or more.');
        $rows[] = array('Cost Items', 'Optional — a package with no cost items still imports. But if you add a row: ITEM, CATEGORY, UNIT PRICE and CURRENCY are required.');
        $rows[] = array('Optional (nullable)', 'Customer fields, Sales Person, Duration Nights, Description, Travel Date End, Margin %, Commissionable, Ad Hoc, Supplier, Quantity, Unit Count, Pax Type, Multiplier Type, all Hotels/Flights/Itinerary fields, Itinerary Notes.');
        $rows[] = array('', '');

        $rows[] = array('VALID CATEGORY CODES', '');
        foreach ((array) (isset($lookups['category_map']) ? $lookups['category_map'] : array()) as $code => $label) {
            $rows[] = array((string) $code, (string) $label);
        }
        $rows[] = array('', '');

        $rows[] = array('VALID CURRENCY CODES', '');
        foreach ((array) (isset($lookups['currency_codes']) ? $lookups['currency_codes'] : array()) as $code) {
            $rows[] = array((string) $code, '');
        }
        $rows[] = array('', '');

        $rows[] = array('STATUS', 'active / inactive');
        $rows[] = array('PAX TYPE', 'adult / child / (blank)');
        $rows[] = array('MULTIPLIER TYPE', 'fixed / per_day / per_pax');
        $rows[] = array('FLIGHT MODE', 'No / Include / FIT / GIT');
        $rows[] = array('IS GENERAL / SHOW SINGLE SUPPLEMENT', 'Y / N');
        $rows[] = array('MEAL PLAN', 'Breakfast / Lunch / Dinner / Tea Break / Supper / Special Arrangement');
        $rows[] = array('', '');

        $rows[] = array('SALES PERSON NAMES', '');
        foreach ((array) (isset($lookups['sales_names']) ? $lookups['sales_names'] : array()) as $sn) {
            $rows[] = array((string) $sn, '');
        }
        return $rows;
    }
}

if (!function_exists('costing_packages_import_template_stream')) {
    /**
     * Build and stream the multi-sheet import template .xlsx. Only this function
     * touches PhpSpreadsheet. Mirrors costing_packages_export_stream().
     */
    function costing_packages_import_template_stream($lookups, $filename)
    {
        require_once FCPATH . 'vendor/autoload.php';
        @ini_set('memory_limit', '512M');
        @set_time_limit(0);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()->setCreator('HolidayGoGoGo');
        $spreadsheet->removeSheetByIndex(0);

        $index = 0;
        foreach (costing_import_template_sheets($lookups) as $def) {
            $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, $def['title']);
            $spreadsheet->addSheet($sheet, $index++);

            if (isset($def['instruction'])) {
                $r = 1;
                foreach ($def['instruction'] as $pair) {
                    $sheet->setCellValueExplicit('A' . $r, isset($pair[0]) ? $pair[0] : '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $r, isset($pair[1]) ? $pair[1] : '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $r++;
                }
                $sheet->getColumnDimension('A')->setWidth(32);
                $sheet->getColumnDimension('B')->setWidth(60);
                continue;
            }

            $labels = $def['labels'];
            $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($labels));
            $sheet->getStyle('A1:' . $last . '1')->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
            $sheet->getStyle('A1:' . $last . '1')->getFont()->getColor()
                ->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE);
            $sheet->getStyle('A1:' . $last . '1')->getFont()->setBold(true);

            foreach ($labels as $i => $label) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValueExplicit($col . '1', $label, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->getColumnDimension($col)->setWidth(22);
            }

            $row = 2;
            foreach ($def['samples'] as $sample) {
                foreach (array_values($sample) as $i => $val) {
                    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                    $sheet->setCellValueExplicit($col . $row, (string) $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
                $sheet->getStyle('A' . $row . ':' . $last . $row)->getFont()->getColor()->setARGB('FF999999');
                $row++;
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit;
    }
}
