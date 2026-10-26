<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Excel export for the Costing Package listing: a multi-sheet workbook mirroring
 * what the listing shows plus the underlying detail. One worksheet each for
 *   Packages      - one row per package (listing columns + latest booking summary)
 *   Cost Items    - every cost line of each package's latest booking snapshot
 *   Hotels        - the per-package hotel quotation rows
 *   Flights       - the per-package flight schedule rows
 *   Combinations  - the latest booking's customer-facing combinations
 *
 * The row-mapper + column functions are pure (no DB / no PhpSpreadsheet) so they
 * can be unit tested; only costing_packages_export_stream() touches PhpSpreadsheet.
 * Follows application/helpers/costing_currency_export_helper.php.
 */

if (!function_exists('costing_packages_export_money')) {
    /**
     * Pure MYR money formatter matching the listing (2 dp, thousands grouped).
     */
    function costing_packages_export_money($value)
    {
        return number_format((float) $value, 2, '.', ',');
    }
}

if (!function_exists('costing_packages_export_date')) {
    /**
     * Pure date formatter. Blank/invalid -> '-'. Keeps plain non-parseable
     * strings as-is (e.g. the free-text flight travel_date).
     */
    function costing_packages_export_date($value, $format = 'd M Y')
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return '-';
        }
        $ts = strtotime($value);
        return $ts ? date($format, $ts) : $value;
    }
}

/* ------------------------------------------------------------------ Packages */

if (!function_exists('costing_packages_export_summary_columns')) {
    function costing_packages_export_summary_columns()
    {
        return array(
            array('No.', 'no'),
            array('Package Name', 'name'),
            array('Tour Code', 'tour_code'),
            array('Customer Name', 'customer_name'),
            array('Contact Number', 'customer_contact'),
            array('Email Address', 'customer_email'),
            array('Sales Person', 'sales_admin_name'),
            array('Duration', 'duration'),
            array('Status', 'status'),
            array('Latest Booking', 'latest_booking_label'),
            array('Travel Date', 'latest_travel_date'),
            array('Total Pax', 'latest_total_pax'),
            array('Margin %', 'margin_percentage'),
            array('Total Cost (MYR)', 'total_cost'),
            array('Selling / Pax (MYR)', 'selling_price_per_pax'),
            array('Revenue (MYR)', 'total_revenue'),
            array('Profit (MYR)', 'total_profit'),
            array('Updated At', 'updated_at'),
        );
    }
}

if (!function_exists('costing_packages_export_summary_rows')) {
    /**
     * Pure mapper: raw package rows (from Read_Packages_For_Export) -> ordered
     * string cells matching costing_packages_export_summary_columns().
     */
    function costing_packages_export_summary_rows($packages)
    {
        $rows = array();
        $number = 1;

        foreach ((array) $packages as $package) {
            $days = (int) (isset($package['duration_days']) ? $package['duration_days'] : 0);
            $nights = (int) (isset($package['duration_nights']) ? $package['duration_nights'] : 0);
            $has_booking = !empty($package['latest_booking_id']);

            $rows[] = array(
                'no' => (string) $number,
                'name' => isset($package['name']) ? (string) $package['name'] : '',
                'tour_code' => isset($package['tour_code']) && $package['tour_code'] !== '' ? (string) $package['tour_code'] : '-',
                'customer_name' => isset($package['customer_name']) && $package['customer_name'] !== '' ? (string) $package['customer_name'] : '-',
                'customer_contact' => isset($package['customer_contact']) && $package['customer_contact'] !== '' ? (string) $package['customer_contact'] : '-',
                'customer_email' => isset($package['customer_email']) && $package['customer_email'] !== '' ? (string) $package['customer_email'] : '-',
                'sales_admin_name' => isset($package['sales_admin_name']) && $package['sales_admin_name'] !== '' ? (string) $package['sales_admin_name'] : '-',
                'duration' => $days . ' Days / ' . $nights . ' Nights',
                'status' => ucfirst((string) (isset($package['status']) ? $package['status'] : '')),
                'latest_booking_label' => $has_booking && isset($package['latest_booking_label']) ? (string) $package['latest_booking_label'] : 'No booking yet',
                'latest_travel_date' => $has_booking ? costing_packages_export_date(isset($package['latest_travel_date']) ? $package['latest_travel_date'] : '') : '-',
                'latest_total_pax' => $has_booking ? (string) (int) (isset($package['latest_total_pax']) ? $package['latest_total_pax'] : 0) : '-',
                'margin_percentage' => $has_booking ? number_format((float) (isset($package['margin_percentage']) ? $package['margin_percentage'] : 0), 2, '.', '') : '-',
                'total_cost' => $has_booking ? costing_packages_export_money(isset($package['total_cost']) ? $package['total_cost'] : 0) : '-',
                'selling_price_per_pax' => $has_booking ? costing_packages_export_money(isset($package['selling_price_per_pax']) ? $package['selling_price_per_pax'] : 0) : '-',
                'total_revenue' => $has_booking ? costing_packages_export_money(isset($package['total_revenue']) ? $package['total_revenue'] : 0) : '-',
                'total_profit' => $has_booking ? costing_packages_export_money(isset($package['total_profit']) ? $package['total_profit'] : 0) : '-',
                'updated_at' => costing_packages_export_date(isset($package['updated_at']) ? $package['updated_at'] : '', 'd M Y H:i'),
            );

            $number++;
        }

        return $rows;
    }
}

/* ---------------------------------------------------------------- Cost Items */

if (!function_exists('costing_packages_export_item_columns')) {
    function costing_packages_export_item_columns()
    {
        return array(
            array('Category', 'category'),
            array('Item', 'name'),
            array('Supplier', 'supplier'),
            array('Pax Type', 'pax_type'),
            array('Quantity', 'quantity'),
            array('Unit Price', 'unit_price'),
            array('Currency', 'currency'),
            array('Total', 'total_amount'),
            array('MYR / Unit', 'myr_per_unit'),
        );
    }
}

if (!function_exists('costing_packages_export_item_rows')) {
    /**
     * Pure mapper: raw cost line rows -> ordered string cells. The owning package
     * is named by the sheet it lives on, so no package columns here.
     */
    function costing_packages_export_item_rows($items)
    {
        $rows = array();

        foreach ((array) $items as $item) {
            $rows[] = array(
                'category' => isset($item['category']) && $item['category'] !== '' ? (string) $item['category'] : '-',
                'name' => isset($item['name']) ? (string) $item['name'] : '',
                'supplier' => isset($item['supplier']) && $item['supplier'] !== '' ? (string) $item['supplier'] : '-',
                'pax_type' => isset($item['pax_type']) && $item['pax_type'] !== '' ? (string) $item['pax_type'] : '-',
                'quantity' => number_format((float) (isset($item['quantity']) ? $item['quantity'] : 0), 2, '.', ''),
                'unit_price' => costing_packages_export_money(isset($item['unit_price']) ? $item['unit_price'] : 0),
                'currency' => isset($item['currency']) && $item['currency'] !== '' ? (string) $item['currency'] : '-',
                'total_amount' => costing_packages_export_money(isset($item['total_amount']) ? $item['total_amount'] : 0),
                'myr_per_unit' => isset($item['myr_per_unit']) && $item['myr_per_unit'] !== null && $item['myr_per_unit'] !== ''
                    ? costing_packages_export_money($item['myr_per_unit'])
                    : '-',
            );
        }

        return $rows;
    }
}

/* -------------------------------------------------------------------- Hotels */

if (!function_exists('costing_packages_export_hotel_columns')) {
    function costing_packages_export_hotel_columns()
    {
        return array(
            array('Hotel Name(s)', 'hotel_name'),
            array('Twin / Triple Price', 'twin_triple_price'),
            array('Single Supplement', 'single_supp_price'),
        );
    }
}

if (!function_exists('costing_packages_export_hotel_rows')) {
    function costing_packages_export_hotel_rows($hotels)
    {
        $rows = array();

        foreach ((array) $hotels as $hotel) {
            $rows[] = array(
                'hotel_name' => trim((string) (isset($hotel['hotel_name']) ? $hotel['hotel_name'] : '')),
                'twin_triple_price' => costing_packages_export_money(isset($hotel['twin_triple_price']) ? $hotel['twin_triple_price'] : 0),
                'single_supp_price' => costing_packages_export_money(isset($hotel['single_supp_price']) ? $hotel['single_supp_price'] : 0),
            );
        }

        return $rows;
    }
}

/* ------------------------------------------------------------------- Flights */

if (!function_exists('costing_packages_export_flight_columns')) {
    function costing_packages_export_flight_columns()
    {
        return array(
            array('Date', 'travel_date'),
            array('Sector', 'sector'),
            array('Flight No', 'flight_no'),
            array('Timing', 'timing'),
            array('Duration', 'duration'),
        );
    }
}

if (!function_exists('costing_packages_export_flight_rows')) {
    function costing_packages_export_flight_rows($flights)
    {
        $rows = array();

        foreach ((array) $flights as $flight) {
            $rows[] = array(
                'travel_date' => isset($flight['travel_date']) && trim((string) $flight['travel_date']) !== '' ? (string) $flight['travel_date'] : '-',
                'sector' => isset($flight['sector']) && $flight['sector'] !== '' ? (string) $flight['sector'] : '-',
                'flight_no' => isset($flight['flight_no']) && $flight['flight_no'] !== '' ? (string) $flight['flight_no'] : '-',
                'timing' => isset($flight['timing']) && $flight['timing'] !== '' ? (string) $flight['timing'] : '-',
                'duration' => isset($flight['duration']) && $flight['duration'] !== '' ? (string) $flight['duration'] : '-',
            );
        }

        return $rows;
    }
}

/* -------------------------------------------------------------- Combinations */

if (!function_exists('costing_packages_export_combination_columns')) {
    function costing_packages_export_combination_columns()
    {
        return array(
            array('Combination Name', 'name'),
            array('Selling / Pax (MYR)', 'selling_price_per_pax'),
        );
    }
}

if (!function_exists('costing_packages_export_combination_rows')) {
    function costing_packages_export_combination_rows($combinations)
    {
        $rows = array();

        foreach ((array) $combinations as $combination) {
            $rows[] = array(
                'name' => isset($combination['name']) ? (string) $combination['name'] : '',
                'selling_price_per_pax' => isset($combination['selling_price_per_pax']) && $combination['selling_price_per_pax'] !== null && $combination['selling_price_per_pax'] !== ''
                    ? costing_packages_export_money($combination['selling_price_per_pax'])
                    : '-',
            );
        }

        return $rows;
    }
}

/* ------------------------------------------------------- One sheet / package */

if (!function_exists('costing_packages_export_summary_pairs')) {
    /**
     * Pure: a single package's summary as ordered [label, value] pairs for the
     * vertical block at the top of its own sheet. Reuses the summary row mapper
     * for formatting and drops the listing-only running "No." column.
     */
    function costing_packages_export_summary_pairs($package)
    {
        $mapped = costing_packages_export_summary_rows(array($package));
        $values = isset($mapped[0]) ? $mapped[0] : array();

        $pairs = array();
        foreach (costing_packages_export_summary_columns() as $column) {
            if ($column[1] === 'no') {
                continue;
            }
            $pairs[] = array($column[0], isset($values[$column[1]]) ? $values[$column[1]] : '');
        }

        return $pairs;
    }
}

if (!function_exists('costing_packages_export_group_by_package')) {
    /**
     * Pure grouper: fold the flat {packages, items, hotels, flights,
     * combinations} data set into one bundle per package, preserving the
     * packages' order. Detail rows attach to their package via the stamped
     * package_id. Returns [{package, items, hotels, flights, combinations}, ...].
     */
    function costing_packages_export_group_by_package($data)
    {
        $data = (array) $data;
        $packages = isset($data['packages']) ? (array) $data['packages'] : array();

        $buckets = array();
        foreach (array('items', 'hotels', 'flights', 'combinations') as $kind) {
            $buckets[$kind] = array();
            foreach ((isset($data[$kind]) ? (array) $data[$kind] : array()) as $row) {
                $pid = isset($row['package_id']) ? (int) $row['package_id'] : 0;
                $buckets[$kind][$pid][] = $row;
            }
        }

        $bundles = array();
        foreach ($packages as $package) {
            $pid = isset($package['id']) ? (int) $package['id'] : 0;
            $bundles[] = array(
                'package' => $package,
                'items' => isset($buckets['items'][$pid]) ? $buckets['items'][$pid] : array(),
                'hotels' => isset($buckets['hotels'][$pid]) ? $buckets['hotels'][$pid] : array(),
                'flights' => isset($buckets['flights'][$pid]) ? $buckets['flights'][$pid] : array(),
                'combinations' => isset($buckets['combinations'][$pid]) ? $buckets['combinations'][$pid] : array(),
            );
        }

        return $bundles;
    }
}

if (!function_exists('costing_packages_export_sheet_title')) {
    /**
     * Sanitize a package into a valid, unique worksheet title. Prefers the
     * package name, falls back to tour code then "Package". Excel bans
     * : \ / ? * [ ] and caps titles at 31 chars; $used tracks taken titles
     * (updated by reference) so revisions sharing a name never collide.
     */
    function costing_packages_export_sheet_title($package, &$used)
    {
        $raw = isset($package['name']) ? trim((string) $package['name']) : '';
        if ($raw === '') {
            $raw = isset($package['tour_code']) ? trim((string) $package['tour_code']) : '';
        }
        if ($raw === '') {
            $raw = 'Package';
        }

        $title = preg_replace('/[:\\\\\/?*\[\]]/', ' ', $raw);
        $title = trim($title) === '' ? 'Package' : trim($title);
        $title = mb_substr($title, 0, 31);

        $base = $title;
        $suffix = 2;
        while (isset($used[$title])) {
            $tail = ' (' . $suffix . ')';
            $title = mb_substr($base, 0, 31 - strlen($tail)) . $tail;
            $suffix++;
        }

        $used[$title] = true;
        return $title;
    }
}

if (!function_exists('costing_packages_export_sections')) {
    /**
     * Pure: the ordered detail sections of one package bundle, each as
     * [title, columns, mapped-rows], so the stream fn (and tests) iterate
     * uniformly.
     */
    function costing_packages_export_sections($bundle)
    {
        $bundle = (array) $bundle;
        return array(
            array('Cost Items', costing_packages_export_item_columns(), costing_packages_export_item_rows(isset($bundle['items']) ? $bundle['items'] : array())),
            array('Hotels', costing_packages_export_hotel_columns(), costing_packages_export_hotel_rows(isset($bundle['hotels']) ? $bundle['hotels'] : array())),
            array('Flights', costing_packages_export_flight_columns(), costing_packages_export_flight_rows(isset($bundle['flights']) ? $bundle['flights'] : array())),
            array('Combinations', costing_packages_export_combination_columns(), costing_packages_export_combination_rows(isset($bundle['combinations']) ? $bundle['combinations'] : array())),
        );
    }
}

/* -------------------------------------------------------------------- Stream */

if (!function_exists('costing_packages_export_stream')) {
    /**
     * Build and stream the .xlsx download with ONE worksheet per package. Each
     * sheet stacks the package summary (label/value) above its detail sections
     * (Cost Items, Hotels, Flights, Combinations). An empty data set yields a
     * single placeholder sheet.
     */
    function costing_packages_export_stream($data, $filename)
    {
        require_once FCPATH . 'vendor/autoload.php';
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()->setCreator('HolidayGoGoGo');
        $spreadsheet->removeSheetByIndex(0);

        $bundles = costing_packages_export_group_by_package($data);
        $used_titles = array();
        $sheet_index = 0;

        if (empty($bundles)) {
            $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'Packages');
            $spreadsheet->addSheet($sheet, 0);
            $sheet->setCellValue('A1', 'Costing Package Records Not Found');
            $sheet->getColumnDimension('A')->setWidth(40);
        }

        foreach ($bundles as $bundle) {
            $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet(
                $spreadsheet,
                costing_packages_export_sheet_title($bundle['package'], $used_titles)
            );
            $spreadsheet->addSheet($sheet, $sheet_index++);
            $sheet->getColumnDimension('A')->setWidth(22);

            $row = 1;

            // Package name banner.
            $name = isset($bundle['package']['name']) && $bundle['package']['name'] !== ''
                ? (string) $bundle['package']['name']
                : 'Package';
            $sheet->setCellValueExplicit('A' . $row, $name, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->mergeCells('A' . $row . ':B' . $row);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
            $row += 2;

            // Summary label/value block.
            foreach (costing_packages_export_summary_pairs($bundle['package']) as $pair) {
                $sheet->setCellValueExplicit('A' . $row, $pair[0], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->getStyle('A' . $row)->getFont()->setBold(true);
                $sheet->setCellValueExplicit('B' . $row, $pair[1], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $row++;
            }
            $sheet->getColumnDimension('B')->setWidth(40);

            // Detail sections, each with its own banner + column headers.
            foreach (costing_packages_export_sections($bundle) as $section) {
                list($title, $cols, $rows) = $section;
                $row++; // blank spacer line

                $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($cols));

                // Section banner.
                $sheet->setCellValue('A' . $row, $title);
                $sheet->mergeCells('A' . $row . ':' . $last . $row);
                $sheet->getStyle('A' . $row)->getFont()->setBold(true);
                $sheet->getStyle('A' . $row)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFD7E2F2');
                $row++;

                // Column headers.
                foreach ($cols as $i => $c) {
                    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                    $sheet->setCellValue($col . $row, $c[0]);
                    $sheet->getColumnDimension($col)->setWidth(22);
                }
                $sheet->getStyle('A' . $row . ':' . $last . $row)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
                $sheet->getStyle('A' . $row . ':' . $last . $row)->getFont()->getColor()
                    ->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE);
                $sheet->getStyle('A' . $row . ':' . $last . $row)->getFont()->setBold(true);
                $row++;

                if (empty($rows)) {
                    $sheet->setCellValue('A' . $row, 'None');
                    $row++;
                    continue;
                }

                foreach ($rows as $record) {
                    foreach ($cols as $i => $c) {
                        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                        $sheet->setCellValueExplicit(
                            $col . $row,
                            isset($record[$c[1]]) ? $record[$c[1]] : '',
                            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                        );
                    }
                    $row++;
                }
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
