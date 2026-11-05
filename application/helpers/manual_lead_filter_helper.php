<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once __DIR__ . '/guest_contact_helper.php';

/** Manual-only filters shared by the listing, count and full Excel export. */
function manual_lead_filter_sql($get)
{
    $get = is_array($get) ? $get : array();
    $sql = ''; $params = array();
    $text = function($key) use ($get) { return isset($get[$key]) && is_scalar($get[$key]) ? trim((string) $get[$key]) : ''; };
    $pattern = function($value) {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        return '%' . str_replace(array('!', '%', '_'), array('!!', '!%', '!_'), $value) . '%';
    };
    $q = $text('q');
    if ($q !== '') {
        // Match every word anywhere in the displayed guest or company name,
        // independent of word order, repeated spaces, case and punctuation.
        $words = preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) { $words = array($q); }
        foreach (array_unique($words) as $word) {
            $sql .= " AND LOWER(CONCAT_WS(' ', gc.first_name, gc.last_name, gc.company_name)) LIKE ? ESCAPE '!' ";
            $params[] = $pattern($word);
        }
    }
    foreach (array('company_name' => 'gc.company_name', 'manual_destination' => 'gc.destination', 'potential_sales_amount' => 'gc.potential_sales_amount') as $key => $column) {
        if ($text($key) !== '') {
            $sql .= " AND LOWER({$column}) LIKE ? ESCAPE '!' ";
            $params[] = $pattern($text($key));
        }
    }
    foreach (array('manual_sales_person' => 'gc.sales_person_id', 'manual_op' => 'gc.op_id') as $key => $column) {
        $values = guest_list_multi_values(isset($get[$key]) ? $get[$key] : null);
        if ($values) {
            $sql .= " AND {$column} IN (" . implode(',', array_fill(0, count($values), '?')) . ') ';
            $params = array_merge($params, $values);
        }
    }
    // Overlapping travel ranges, including legacy single-day travel dates.
    $range = guest_list_parse_date_range($text('manual_travel_date'));
    if ($range !== null) {
        $sql .= ' AND gc.travel_date <= ? AND COALESCE(gc.travel_end_date, gc.travel_date) >= ? ';
        $params[] = $range[1]; $params[] = $range[0];
    }
    return array($sql, $params);
}
