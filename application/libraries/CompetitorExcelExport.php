<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once FCPATH . 'vendor/autoload.php';

/** Excel workbooks built from the same stored products as the detail view. */
class CompetitorExcelExport
{
    private $book;
    private $long_text = array();

    public function analysis($analyses, $lang = 'en', $include_product_source = false)
    {
        $this->start();
        $this->add_analysis_sheets($analyses, $lang, $include_product_source);
        return $this->finish();
    }

    public function crawl($items, $analysed, $analyses, $job, $source, $selected_only = false, $crawl_summary = null)
    {
        $this->start();
        $saved = array();
        foreach ($analyses as $a) { $saved[(int) $a->id] = $a; }
        $rows = array(); $sources = array();
        foreach ($items as $idx => $item) {
            $text = isset($item['text']) ? (string) $item['text'] : '';
            $url = isset($item['url']) ? (string) $item['url'] : '';
            $title = ! empty($item['title']) ? (string) $item['title'] : competitor_item_title($text, $url);
            $meta = competitor_item_meta($text, $title);
            $id = isset($analysed[$idx]['id']) ? (int) $analysed[$idx]['id'] : 0;
            $a = isset($saved[$id]) ? $saved[$id] : null;
            $quality = isset($item['crawl_metadata']) ? $item['crawl_metadata'] : array();
            $records = isset($quality['source_records']) ? $quality['source_records'] : array();
            $rows[] = array($id > 0 ? competitor_product_code($id, $a && isset($a->feature) ? $a->feature : 'competitor', 1, 1) : '', (int) $idx, $title, $url,
                competitor_item_kind($text) === 'package' ? 'Package' : 'Itinerary', $meta['duration'],
                $id > 0 ? $id : '', $a ? ($a->status === 'done' ? 'Analysed' : 'Analysis failed') : ($id > 0 ? 'Saved record missing' : 'Not analysed'),
                $a ? (float) $a->cost_usd : '', $a ? (string) $a->created_at : '', mb_strlen($text, 'UTF-8'), $text,
                isset($quality['original_url']) ? $quality['original_url'] : $url,
                isset($quality['source_url']) ? $quality['source_url'] : $source,
                isset($quality['canonical_url']) ? $quality['canonical_url'] : '',
                implode("\n", array_column($records, 'original_url')),
                implode("\n", isset($quality['quality_flags']) ? $quality['quality_flags'] : array()),
                max(0, count($records) - 1));
            foreach ($records as $record) {
                $sources[] = array((int) $idx, $title, $record['original_url'], $record['url'], $record['source_url'], $record['canonical_url']);
            }
        }
        $this->add_sheet('Crawl Results', array('Product Code', 'Item Index', 'Product Name', 'URL', 'Type', 'Duration',
            'Analysis ID', 'Analysis Status', 'AI Cost (USD)', 'Analysed At', 'Characters', 'Crawled Text',
            'Original URL', 'Source URL', 'Canonical URL', 'Source URLs', 'Quality Flags', 'Duplicate Records'), $rows);
        if ($crawl_summary === null) { $crawl_summary = competitor_prepare_crawl_items($items, $source)['summary']; }
        $this->add_sheet('Crawl Info', array('Field', 'Value'), array(
            array('Crawl', $job), array('Website', $source), array('Products', count($items)),
            array('Saved analyses', count($analyses)), array('Exported at', date('Y-m-d H:i:s')),
            array('Total crawled records', $crawl_summary['total_records']),
            array('Duplicate records', $crawl_summary['duplicate_records']),
            array('Unique products remaining', $crawl_summary['unique_products']),
            array('Products needing review', $crawl_summary['needs_review']),
            array('Scope', $selected_only
                ? 'Selected products from this crawl, including available saved analyses.'
                : 'All products currently stored for this crawl, including available saved analyses.'),
        ));
        if ( ! empty($analyses)) { $this->add_analysis_sheets($analyses, 'en'); }
        if ($sources) { $this->add_sheet('Crawl Sources', array('Item Index', 'Product Name', 'Original URL', 'Product URL', 'Source URL', 'Canonical URL'), $sources); }
        return $this->finish();
    }

    private function start()
    {
        $this->book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $this->book->removeSheetByIndex(0);
        $this->book->getProperties()->setCreator('HolidayGoGoGo')->setTitle('Competitor Results');
        $this->book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $this->long_text = array();
    }

    private function add_analysis_sheets($analyses, $lang, $include_product_source = false)
    {
        $fields = array(
            'product_name' => 'Product Name', 'url' => 'Product URL', 'tour_code' => 'Tour Code',
            'destination' => 'Destination', 'destination_details' => 'Destination Details',
            'main_usp' => 'Main Selling Point', 'duration' => 'Duration', 'departure_city' => 'Departure City',
            'price' => 'Price', 'price_from' => 'Price From', 'price_to' => 'Price To', 'currency' => 'Currency',
            'difficulty' => 'Difficulty', 'target_traveller' => 'Target Traveller', 'suitable_age' => 'Suitable Age',
            'child_friendly' => 'Child Friendly', 'senior_friendly' => 'Senior Friendly',
            'flight_departure' => 'Departure Flight', 'flight_return' => 'Return Flight',
            'countries' => 'Countries', 'cities' => 'Cities', 'travel_months' => 'Travel Months',
            'themes' => 'Themes', 'tour_styles' => 'Tour Styles', 'local_transport' => 'Local Transport',
            'hotels' => 'Accommodation', 'food_beverage' => 'Food & Beverages',
            'customer_advice' => 'Advice for Your Trip', 'usp' => 'Unique Selling Points', 'inclusions' => 'Inclusions',
            'exclusions' => 'Exclusions', 'shopping_stops' => 'Shopping Stops', 'optional_tours' => 'Optional Tours',
            'special_remarks' => 'Special Remarks', 'pros' => 'Pros', 'cons' => 'Cons', 'summary' => 'Summary',
            'matched_product' => 'Matched Product', 'comparison' => 'Comparison vs Our Products',
        );
        $columns = array_merge(array('Product Code', 'Analysis ID', 'Competitor Name', 'Report Source',
            'Analysed At', 'AI Cost (USD)', 'Status', 'Error Message'), array_values($fields),
            array('Breakfast', 'Lunch', 'Dinner', 'Original URL', 'Source URL', 'Canonical URL', 'Source URLs', 'Quality Flags'));
        if ($include_product_source) { $columns[] = 'Product Source'; }
        $products = array(); $days = array(); $scenic = array(); $segments = array();
        $labels = competitor_ui_labels($lang);
        $list_fields = competitor_translate_list_keys();
        foreach ($analyses as $a) {
            $plist = competitor_display_products($a);
            $ptotal = count($plist);
            foreach ($plist as $pidx => $p) {
                // Stable per-product code (e.g. CP-000123 / CP-000123-2) — the same code the
                // Review/View screens and PDF show, and the join key across the Itinerary /
                // Scenic / Suitability sheets. Replaces the old throwaway row counter.
                $number = competitor_product_code((int) $a->id, isset($a->feature) ? $a->feature : 'competitor', $pidx + 1, $ptotal);
                $name = isset($p['product_name']) ? (string) $p['product_name'] : '';
                $row = array($number, (int) $a->id, isset($a->competitor_name) ? (string) $a->competitor_name : '',
                    (string) $a->url, (string) $a->created_at, (float) $a->cost_usd, (string) $a->status,
                    isset($a->error_message) ? (string) $a->error_message : '');
                foreach ($fields as $key => $label) {
                    $value = isset($p[$key]) ? $p[$key] : '';
                    $row[] = in_array($key, $list_fields, true)
                        ? implode("\n", competitor_list_items($value)) : $this->text($value);
                }
                foreach (array('breakfast', 'lunch', 'dinner') as $meal) {
                    $row[] = $this->text(isset($p['meals'][$meal]) ? $p['meals'][$meal] : '');
                }
                $quality = isset($p['crawl_metadata']) && is_array($p['crawl_metadata']) ? $p['crawl_metadata'] : array();
                $row[] = isset($quality['original_url']) ? $quality['original_url'] : $this->text(isset($p['url']) ? $p['url'] : $a->url);
                $row[] = isset($quality['source_url']) ? $quality['source_url'] : (string) $a->url;
                $row[] = isset($quality['canonical_url']) ? $quality['canonical_url'] : '';
                $row[] = implode("\n", array_column(isset($quality['source_records']) ? $quality['source_records'] : array(), 'original_url'));
                $row[] = implode("\n", isset($quality['quality_flags']) ? $quality['quality_flags'] : array());
                if ($include_product_source) {
                    $row[] = isset($a->feature) && $a->feature === 'our_product' ? 'Our Product' : 'Competitor';
                }
                $products[] = $row;
                $reference = array($number, (int) $a->id, $name);
                foreach (isset($p['itinerary']) && is_array($p['itinerary']) ? $p['itinerary'] : array() as $day) {
                    $day = (array) $day;
                    $days[] = array_merge($reference, array($this->text(isset($day['day']) ? $day['day'] : ''),
                        $this->text(isset($day['title']) ? $day['title'] : ''), $this->text(isset($day['description']) ? $day['description'] : '')));
                }
                foreach (competitor_scenic_items(isset($p['scenic_highlights']) ? $p['scenic_highlights'] : array()) as $place) {
                    $scenic[] = array_merge($reference, array($place['name'], $place['description']));
                }
                foreach (competitor_traveller_segments(isset($p['traveller_segments']) ? $p['traveller_segments'] : array()) as $segment) {
                    $label = isset($labels['seg_' . $segment['key']]) ? $labels['seg_' . $segment['key']] : $segment['segment'];
                    $level = isset($labels['suit_' . $segment['level']]) ? $labels['suit_' . $segment['level']] : $segment['suitability'];
                    $segments[] = array_merge($reference, array($label, $level, $segment['justification']));
                }
            }
        }
        $this->add_sheet('Products', $columns, $products);
        $reference_columns = array('Product Code', 'Analysis ID', 'Product Name');
        $this->add_sheet('Itinerary', array_merge($reference_columns, array('Day', 'Title', 'Description')), $days);
        $this->add_sheet('Scenic Highlights', array_merge($reference_columns, array('Place', 'Description')), $scenic);
        $this->add_sheet('Traveller Suitability', array_merge($reference_columns, array('Traveller Type', 'Suitability', 'Justification')), $segments);
    }

    private function text($value)
    {
        if (is_object($value)) { $value = (array) $value; }
        if ( ! is_array($value)) { return is_scalar($value) ? (string) $value : ''; }
        $lines = array();
        foreach ($value as $key => $item) {
            $line = $this->text($item);
            if ($line !== '') { $lines[] = is_int($key) ? $line : ucfirst(str_replace('_', ' ', $key)) . ': ' . $line; }
        }
        return implode("\n", $lines);
    }

    private function add_sheet($title, $columns, $rows)
    {
        $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($this->book, $title);
        $this->book->addSheet($sheet);
        $data = $rows;
        array_unshift($data, $columns);
        foreach ($data as $row_index => $row) {
            foreach ($row as $column_index => $value) {
                $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column_index + 1) . ($row_index + 1);
                $numeric = is_int($value) || is_float($value);
                $value = $numeric ? $value : $this->text($value);
                // Excel limits a cell to 32,767 UTF-16 units. Preserve longer crawl
                // content in numbered parts instead of allowing silent truncation.
                if ( ! $numeric) {
                    $utf16 = mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
                    if (strlen($utf16) > 65534) {
                        $part = 0;
                        for ($offset = 0; $offset < strlen($utf16); $offset += strlen($chunk)) {
                            $chunk = mb_strcut($utf16, $offset, 60000, 'UTF-16LE');
                            $this->long_text[] = array($title, $cell, ++$part, mb_convert_encoding($chunk, 'UTF-8', 'UTF-16LE'));
                        }
                        $value = mb_substr($value, 0, 500, 'UTF-8') . "\n[Full text in Long Text: " . $title . '!' . $cell . ']';
                    }
                }
                // Scraped and AI text is always literal text, even if it starts '='.
                $sheet->setCellValueExplicit($cell, $value, $numeric
                    ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                    : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        $last_column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns));
        $last_row = count($data);
        $sheet->getStyle('A1:' . $last_column . $last_row)->getAlignment()->setWrapText(true)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
        $header = $sheet->getStyle('A1:' . $last_column . '1');
        $header->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $header->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF6082B6');
        $sheet->getRowDimension(1)->setRowHeight(32);
        $sheet->freezePane('D2');
        $sheet->setAutoFilter('A1:' . $last_column . $last_row);
        foreach ($columns as $index => $label) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $width = preg_match('/Text|Description|Details|Advice|Summary|Comparison|Justification|Remarks/', $label) ? 70 : 25;
            if (in_array($label, array('Main Selling Point', 'Unique Selling Points', 'Local Transport', 'Accommodation', 'Food & Beverages', 'Inclusions', 'Exclusions', 'Pros', 'Cons', 'Optional Tours', 'Shopping Stops'), true)) { $width = 60; }
            if (strpos($label, 'URL') !== false || strpos($label, 'Source') !== false) { $width = 45; }
            if (strpos($label, 'No.') !== false || $label === 'Part') { $width = 12; }
            $sheet->getColumnDimension($column)->setWidth($width);
            if (strpos($label, 'AI Cost') !== false && $last_row > 1) {
                $sheet->getStyle($column . '2:' . $column . $last_row)->getNumberFormat()->setFormatCode('0.0000');
            }
        }
    }

    private function finish()
    {
        if ( ! empty($this->long_text)) {
            $this->add_sheet('Long Text', array('Worksheet', 'Cell', 'Part', 'Text'), $this->long_text);
            $this->long_text = array();
        }
        $this->book->setActiveSheetIndex(0);
        return $this->book;
    }
}
