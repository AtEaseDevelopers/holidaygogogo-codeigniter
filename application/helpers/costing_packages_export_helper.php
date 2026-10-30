<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Excel EXPORT for the Costing Package listing, in the SAME layout as the Import
 * template (application/helpers/costing_packages_import_helper.php) so an export
 * can be edited and re-uploaded through the Import flow (round-trip). One flat
 * worksheet per import sheet, every package stacked on the same sheet, grouped
 * back to its package by Package Name — plus ONE extra "TOUR CODE" column right
 * after Package Name on every sheet for reference:
 *   Packages      - one row per package (header + latest-booking + margin + quote-level)
 *   Cost Items    - cost lines, grouped by Package Name, optionally into a Combination
 *   Combinations  - the named customer bundles (is_general + manual selling price)
 *   Hotels        - per-package hotel quotation rows
 *   Flights       - per-package flight rows (option title/airline/price repeated per leg)
 *   Itinerary     - per-package day rows (day number / title / description / meal plan)
 *
 * The column SETS are the import maps (costing_import_*_columns()) with a TOUR
 * CODE column inserted after Package Name, so export and import never drift. The
 * import parser is header-aware and simply ignores the unrecognised TOUR CODE
 * column on re-upload (tour codes are auto-assigned on create).
 *
 * Values are formatted for round-trip, NOT for display: blanks stay blank (never
 * "-"), numbers carry no thousands separators, dates are Y-m-d, Y/N for booleans,
 * flight mode / category / currency are the stored codes, and meal plan is the
 * stored comma-separated slug string. The row-mappers + column builders are pure
 * (no DB / no PhpSpreadsheet); only costing_packages_export_stream() touches
 * PhpSpreadsheet.
 */

/* ------------------------------------------------------------ Formatters */

if (!function_exists('costing_packages_export_ymd')) {
    /** Y-m-d for a date-ish value; '' when blank/zero; raw when unparseable. */
    function costing_packages_export_ymd($value)
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return '';
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : $value;
    }
}

if (!function_exists('costing_packages_export_num')) {
    /**
     * A numeric cell with no thousands separators and no trailing zeros
     * (5.00 -> "5", 1200.50 -> "1200.5", 0 -> "0"). The import parser strips
     * commas, so dropping them keeps the file clean and re-parses identically.
     */
    function costing_packages_export_num($value)
    {
        $formatted = number_format((float) $value, 2, '.', '');
        if (strpos($formatted, '.') !== false) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }
        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }
}

if (!function_exists('costing_packages_export_num_or_blank')) {
    /** Like costing_packages_export_num but a null/'' input stays '' (not "0"). */
    function costing_packages_export_num_or_blank($value)
    {
        if ($value === null || trim((string) $value) === '') {
            return '';
        }
        return costing_packages_export_num($value);
    }
}

if (!function_exists('costing_packages_export_optional_yn')) {
    /**
     * Y/N for a nullable boolean flag: a null/'' input (never saved) stays '' so
     * the import applies its own default; 1 -> "Y", 0 -> "N".
     */
    function costing_packages_export_optional_yn($value)
    {
        if ($value === null || trim((string) $value) === '') {
            return '';
        }
        return ((int) $value) === 1 ? 'Y' : 'N';
    }
}

if (!function_exists('costing_packages_export_yn')) {
    /** Y/N for a plain boolean flag (blank counts as N). */
    function costing_packages_export_yn($value)
    {
        return ((int) $value) === 1 ? 'Y' : 'N';
    }
}

if (!function_exists('costing_packages_export_hotel_columns_cell')) {
    /**
     * The quote hotel columns are stored as a JSON array (e.g. ["Twin","Extra
     * Bed"]); the import expects a pipe-separated cell ("Twin|Extra Bed"). Decode
     * and pipe-join; leave an already-plain value untouched.
     */
    function costing_packages_export_hotel_columns_cell($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $parts = array();
            foreach ($decoded as $part) {
                $part = trim((string) $part);
                if ($part !== '') {
                    $parts[] = $part;
                }
            }
            return implode('|', $parts);
        }
        return $value;
    }
}

/* --------------------------------------------------- Column sets (import + tour code) */

if (!function_exists('costing_packages_export_insert_tour_code')) {
    /**
     * Return an import column map (label => key) with a 'TOUR CODE' => 'tour_code'
     * column inserted right after the first column (Package Name). Pure.
     */
    function costing_packages_export_insert_tour_code($columns)
    {
        $out = array();
        $first = true;
        foreach ((array) $columns as $label => $key) {
            $out[$label] = $key;
            if ($first) {
                $out['TOUR CODE'] = 'tour_code';
                $first = false;
            }
        }
        return $out;
    }
}

if (!function_exists('costing_packages_export_sheets_meta')) {
    /**
     * The export's sheet definitions as [key, title, columns], mirroring
     * costing_import_sheets_meta() with a TOUR CODE column added to each sheet.
     * 'key' is the data bucket Read_Packages_For_Export returns. Pure.
     */
    function costing_packages_export_sheets_meta()
    {
        $sheets = array();
        foreach (costing_import_sheets_meta() as $meta) {
            list($key, $title, $columns) = $meta;
            $sheets[] = array($key, $title, costing_packages_export_insert_tour_code($columns));
        }
        return $sheets;
    }
}

/* ------------------------------------------------------------ Row mappers */

if (!function_exists('costing_packages_export_package_record')) {
    /**
     * Map one raw package row (from Read_Packages_For_Export) to the Packages-sheet
     * record keyed to the import column keys (+ tour_code). Booking-derived cells
     * (travel dates, pax, margins) blank out when the package has no booking yet.
     */
    function costing_packages_export_package_record($pkg)
    {
        $pkg = (array) $pkg;
        $has_booking = !empty($pkg['latest_booking_id']);
        $get = function ($key) use ($pkg) {
            return isset($pkg[$key]) ? $pkg[$key] : '';
        };

        return array(
            'name'                   => (string) $get('name'),
            'tour_code'              => (string) $get('tour_code'),
            'customer_name'          => (string) $get('customer_name'),
            'customer_contact'       => (string) $get('customer_contact'),
            'customer_email'         => (string) $get('customer_email'),
            'sales_person'           => (string) $get('sales_admin_name'),
            'duration_days'          => (string) (int) $get('duration_days'),
            'duration_nights'        => (string) (int) $get('duration_nights'),
            'status'                 => (string) $get('status'),
            // Quotation "valid until" date — package-level (not booking-derived), so
            // it exports regardless of whether the package has a booking yet.
            'validity_date'          => costing_packages_export_ymd($get('validity_date')),
            'description'            => (string) $get('description'),
            'travel_date'            => $has_booking ? costing_packages_export_ymd($get('latest_travel_date')) : '',
            'travel_date_end'        => $has_booking ? costing_packages_export_ymd($get('latest_travel_date_end')) : '',
            'adult_count'            => $has_booking ? (string) (int) $get('latest_adult_count') : '',
            'child_count'            => $has_booking ? (string) (int) $get('latest_child_count') : '',
            'margin_percentage'      => $has_booking ? costing_packages_export_num($get('margin_percentage')) : '',
            'commissionable_per_pax' => $has_booking ? costing_packages_export_num($get('commissionable_per_pax')) : '',
            'ad_hoc_per_pax'         => $has_booking ? costing_packages_export_num($get('ad_hoc_per_pax')) : '',
            'quote_flight_mode'      => (string) $get('quote_flight_mode'),
            'quote_hotel_columns'    => costing_packages_export_hotel_columns_cell($get('quote_hotel_columns')),
            'quote_hotel_title_label' => (string) $get('quote_hotel_title_label'),
            'quote_show_single_supp' => costing_packages_export_optional_yn(
                array_key_exists('quote_show_single_supp', $pkg) ? $pkg['quote_show_single_supp'] : null
            ),
            // The four itinerary-wide sections, with the legacy merged-notes split
            // fallback so old packages still export their content. Each section is
            // emitted as "one bullet per line" plain text (one <li> => one line)
            // and re-imports via costing_itinerary_html_from_lines().
        ) + costing_packages_export_itinerary_section_cells($pkg) + array(
            // Standalone Tour Intro header — last itinerary column, after the four
            // sections, to match the import column map order. Flattened "one line
            // per paragraph/bullet" so it re-imports via html_from_lines().
            'itinerary_tour_intro' => costing_itinerary_lines_from_html((string) $get('itinerary_tour_intro')),
        );
    }
}

if (!function_exists('costing_packages_export_itinerary_section_cells')) {
    /**
     * Resolve the four itinerary sections (Include / Exclude / Important Notes /
     * Terms & Conditions) for a package row into their export column keys,
     * applying the legacy merged-notes split fallback. Depends on the
     * costing_itinerary helper (loaded by the Export_Packages controller).
     */
    function costing_packages_export_itinerary_section_cells($pkg)
    {
        $sections = costing_itinerary_sections_from_row($pkg);
        $cells = array();
        foreach (costing_itinerary_section_meta() as $key => $meta) {
            // Key by the import RECORD key (post_field) so the exported cell lines
            // up with the Packages-sheet column. Stored <ul><li> HTML is flattened
            // to one bullet per line so the cell is readable and re-imports cleanly.
            $cells[$meta[2]] = costing_itinerary_lines_from_html(
                isset($sections[$key]) ? (string) $sections[$key] : ''
            );
        }
        return $cells;
    }
}

if (!function_exists('costing_packages_export_item_record')) {
    /** Map one cost-line row to the Cost Items-sheet record. */
    function costing_packages_export_item_record($item)
    {
        $item = (array) $item;
        $get = function ($key) use ($item) {
            return isset($item[$key]) ? $item[$key] : '';
        };

        return array(
            'package_name'    => (string) $get('package_name'),
            'tour_code'       => (string) $get('tour_code'),
            'combination'     => (string) $get('combination'),
            'category'        => (string) $get('category'),
            'name'            => (string) $get('name'),
            'supplier'        => (string) $get('supplier'),
            'pax_type'        => (string) $get('pax_type'),
            'multiplier_type' => (string) $get('multiplier_type'),
            'quantity'        => costing_packages_export_num($get('quantity')),
            'unit_count'      => costing_packages_export_num($get('unit_count')),
            'unit_price'      => costing_packages_export_num($get('unit_price')),
            'currency'        => (string) $get('currency'),
        );
    }
}

if (!function_exists('costing_packages_export_combination_record')) {
    /** Map one combination row to the Combinations-sheet record. */
    function costing_packages_export_combination_record($combo)
    {
        $combo = (array) $combo;
        $get = function ($key) use ($combo) {
            return isset($combo[$key]) ? $combo[$key] : '';
        };

        return array(
            'package_name'          => (string) $get('package_name'),
            'tour_code'             => (string) $get('tour_code'),
            'name'                  => (string) $get('name'),
            'is_general'            => costing_packages_export_yn($get('is_general')),
            'selling_price_per_pax' => costing_packages_export_num_or_blank(
                array_key_exists('selling_price_per_pax', $combo) ? $combo['selling_price_per_pax'] : null
            ),
        );
    }
}

if (!function_exists('costing_packages_export_hotel_record')) {
    /** Map one hotel row to the Hotels-sheet record. */
    function costing_packages_export_hotel_record($hotel)
    {
        $hotel = (array) $hotel;
        $get = function ($key) use ($hotel) {
            return isset($hotel[$key]) ? $hotel[$key] : '';
        };

        return array(
            'package_name'      => (string) $get('package_name'),
            'tour_code'         => (string) $get('tour_code'),
            'hotel_name'        => (string) $get('hotel_name'),
            'twin_triple_price' => costing_packages_export_num($get('twin_triple_price')),
            'single_supp_price' => costing_packages_export_num($get('single_supp_price')),
        );
    }
}

if (!function_exists('costing_packages_export_flight_record')) {
    /** Map one flight leg row (with its option meta) to the Flights-sheet record. */
    function costing_packages_export_flight_record($flight)
    {
        $flight = (array) $flight;
        $get = function ($key) use ($flight) {
            return isset($flight[$key]) ? $flight[$key] : '';
        };

        return array(
            'package_name' => (string) $get('package_name'),
            'tour_code'    => (string) $get('tour_code'),
            'option_title' => (string) $get('option_title'),
            'airline'      => (string) $get('airline'),
            'option_price' => costing_packages_export_num_or_blank(
                array_key_exists('option_price', $flight) ? $flight['option_price'] : null
            ),
            'travel_date'  => (string) $get('travel_date'),
            'sector'       => (string) $get('sector'),
            'flight_no'    => (string) $get('flight_no'),
            'timing'       => (string) $get('timing'),
            'duration'     => (string) $get('duration'),
        );
    }
}

if (!function_exists('costing_packages_export_itinerary_record')) {
    /** Map one itinerary day row to the Itinerary-sheet record. */
    function costing_packages_export_itinerary_record($day)
    {
        $day = (array) $day;
        $get = function ($key) use ($day) {
            return isset($day[$key]) ? $day[$key] : '';
        };

        return array(
            'package_name' => (string) $get('package_name'),
            'tour_code'    => (string) $get('tour_code'),
            'day_number'   => (string) (int) $get('day_number'),
            'title'        => (string) $get('title'),
            'description'  => (string) $get('description'),
            'meal_plan'    => (string) $get('meal_plan'),
        );
    }
}

if (!function_exists('costing_packages_export_sheet_rows')) {
    /**
     * Map a data bucket (by sheet key) through its row-mapper. Pure. Returns a list
     * of records keyed to the sheet's column keys.
     */
    function costing_packages_export_sheet_rows($key, $rows)
    {
        $rows = (array) $rows;
        $mappers = array(
            'packages'     => 'costing_packages_export_package_record',
            'cost_items'   => 'costing_packages_export_item_record',
            'combinations' => 'costing_packages_export_combination_record',
            'hotels'       => 'costing_packages_export_hotel_record',
            'flights'      => 'costing_packages_export_flight_record',
            'itinerary'    => 'costing_packages_export_itinerary_record',
        );
        if (!isset($mappers[$key])) {
            return array();
        }
        $out = array();
        foreach ($rows as $row) {
            $out[] = $mappers[$key]($row);
        }
        return $out;
    }
}

/* -------------------------------------------------------------------- Stream */

if (!function_exists('costing_packages_export_stream')) {
    /**
     * Build and stream the .xlsx download: one flat worksheet per import sheet
     * (Packages / Cost Items / Combinations / Hotels / Flights / Itinerary), each
     * with a TOUR CODE column after Package Name. Sheets are always written (even
     * empty) so the file mirrors the import template and re-imports cleanly.
     *
     * @param array  $data     Read_Packages_For_Export() output (bucket per sheet)
     * @param string $filename download name
     */
    function costing_packages_export_stream($data, $filename)
    {
        require_once FCPATH . 'vendor/autoload.php';
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);

        $data = (array) $data;

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()->setCreator('HolidayGoGoGo');
        $spreadsheet->removeSheetByIndex(0);

        $wide_keys = array('name', 'description', 'itinerary_include', 'itinerary_exclude', 'itinerary_important_notes', 'itinerary_terms', 'hotel_name', 'title', 'sector');

        $index = 0;
        foreach (costing_packages_export_sheets_meta() as $meta) {
            list($key, $title, $columns) = $meta;
            $labels = array_keys($columns);
            $keys   = array_values($columns);

            $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, $title);
            $spreadsheet->addSheet($sheet, $index++);

            $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($labels));

            // Header row (black fill, white bold) — matches the import template.
            $sheet->getStyle('A1:' . $last . '1')->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
            $sheet->getStyle('A1:' . $last . '1')->getFont()->getColor()
                ->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE);
            $sheet->getStyle('A1:' . $last . '1')->getFont()->setBold(true);

            foreach ($labels as $i => $label) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValueExplicit($col . '1', $label, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->getColumnDimension($col)->setWidth(in_array($keys[$i], $wide_keys, true) ? 40 : 20);
            }

            $row = 2;
            foreach (costing_packages_export_sheet_rows($key, isset($data[$key]) ? $data[$key] : array()) as $record) {
                foreach ($keys as $i => $ck) {
                    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                    $sheet->setCellValueExplicit(
                        $col . $row,
                        isset($record[$ck]) ? $record[$ck] : '',
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                    );
                }
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
