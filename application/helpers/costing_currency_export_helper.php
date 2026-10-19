<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Excel export for the Costing Currency module: one worksheet per currency, its
 * full MYR rate history laid out to mirror the on-screen Rate History table
 * (No. | Rate | Bank Charges | Updated By | Valid From).
 */

if (!function_exists('costing_currency_export_columns')) {
    /**
     * Ordered [header, key] pairs for the export sheet, matching the Rate
     * History modal table's columns.
     */
    function costing_currency_export_columns()
    {
        return array(
            array('No.', 'no'),
            array('Rate', 'rate'),
            array('Buffer', 'buffer'),
            array('Bank Charges', 'bank_charges'),
            array('Updated By', 'updated_by_name'),
            array('Valid From', 'valid_from'),
        );
    }
}

if (!function_exists('costing_currency_export_myr_only')) {
    /**
     * Pure filter: keep only records whose target currency is MYR - the same
     * subset the Rate History table shows (1 foreign = X MYR). Kept side-effect
     * free for unit testing.
     */
    function costing_currency_export_myr_only($histories)
    {
        $kept = array();

        foreach ((array) $histories as $history) {
            $to = isset($history['to_currency_code']) ? strtoupper((string) $history['to_currency_code']) : '';
            if ($to === 'MYR') {
                $kept[] = $history;
            }
        }

        return $kept;
    }
}

if (!function_exists('costing_currency_export_rows')) {
    /**
     * Pure mapper: turn raw history records into ordered string cells matching
     * costing_currency_export_columns(). Rate/date/charge formatting mirrors the
     * Rate History modal table exactly. Kept side-effect free so it is unit
     * testable without PhpSpreadsheet.
     */
    function costing_currency_export_rows($histories)
    {
        $rows = array();
        $number = 1;

        foreach ((array) $histories as $history) {
            $code = isset($history['from_currency_code']) && $history['from_currency_code'] !== ''
                ? (string) $history['from_currency_code']
                : 'Currency';
            $rate = (float) (isset($history['rate']) ? $history['rate'] : 0);
            $buffer = (float) (isset($history['buffer']) ? $history['buffer'] : 0);
            $bank_charges = (float) (isset($history['bank_charges_myr']) ? $history['bank_charges_myr'] : 0);
            $valid_from = isset($history['valid_from']) ? (string) $history['valid_from'] : '';
            $timestamp = $valid_from !== '' ? strtotime($valid_from) : false;

            $rows[] = array(
                'no' => (string) $number,
                'rate' => '1 ' . $code . ' = ' . number_format($rate, 6, '.', ',') . ' MYR',
                'buffer' => number_format($buffer, 6, '.', ',') . ' MYR',
                'bank_charges' => 'MYR ' . number_format($bank_charges, 2, '.', ','),
                'updated_by_name' => isset($history['updated_by_name']) && $history['updated_by_name'] !== '' ? (string) $history['updated_by_name'] : '-',
                'valid_from' => $timestamp ? strtoupper(date('d M Y H:i', $timestamp)) : ($valid_from !== '' ? $valid_from : 'No date'),
            );

            $number++;
        }

        return $rows;
    }
}

if (!function_exists('costing_currency_export_group_by_currency')) {
    /**
     * Pure grouper: split history records into one bucket per source currency,
     * preserving the incoming (newest-first) order both across and within
     * buckets. Returns [currency_code => [records...]]. Records without a
     * from_currency_code fall under a "-" bucket. Kept DB/PhpSpreadsheet free.
     */
    function costing_currency_export_group_by_currency($histories)
    {
        $groups = array();

        foreach ((array) $histories as $history) {
            $code = isset($history['from_currency_code']) && $history['from_currency_code'] !== ''
                ? (string) $history['from_currency_code']
                : '-';

            if (!isset($groups[$code])) {
                $groups[$code] = array();
            }

            $groups[$code][] = $history;
        }

        return $groups;
    }
}

if (!function_exists('costing_currency_export_sheet_title')) {
    /**
     * Sanitize a currency code into a valid, unique worksheet title (Excel bans
     * : \ / ? * [ ] and caps titles at 31 chars). $used tracks already-taken
     * titles and is updated by reference so callers never collide.
     */
    function costing_currency_export_sheet_title($code, &$used)
    {
        $title = preg_replace('/[:\\\\\/?*\[\]]/', ' ', (string) $code);
        $title = trim($title) === '' ? 'Currency' : trim($title);
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

if (!function_exists('costing_currency_export_stream')) {
    /**
     * Stream the currency rate history as an .xlsx download, one worksheet per
     * source currency.
     */
    function costing_currency_export_stream($histories, $filename)
    {
        require_once FCPATH . 'vendor/autoload.php';
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);

        $cols = costing_currency_export_columns();
        $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($cols));
        $groups = costing_currency_export_group_by_currency(costing_currency_export_myr_only($histories));

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()->setCreator('HolidayGoGoGo');
        $spreadsheet->removeSheetByIndex(0);

        $used_titles = array();
        $sheet_index = 0;

        if (empty($groups)) {
            $groups = array('Currency' => array());
        }

        foreach ($groups as $code => $records) {
            $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet(
                $spreadsheet,
                costing_currency_export_sheet_title($code, $used_titles)
            );
            $spreadsheet->addSheet($sheet, $sheet_index++);

            $sheet->getStyle('A1:' . $last . '1')->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
            $sheet->getStyle('A1:' . $last . '1')->getFont()->getColor()
                ->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE);
            $sheet->getStyle('A1:' . $last . '1')->getFont()->setBold(true);

            foreach ($cols as $i => $c) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValue($col . '1', $c[0]);
                $sheet->getColumnDimension($col)->setWidth(22);
            }

            $row = 2;
            foreach (costing_currency_export_rows($records) as $record) {
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

        $spreadsheet->setActiveSheetIndex(0);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit;
    }
}
