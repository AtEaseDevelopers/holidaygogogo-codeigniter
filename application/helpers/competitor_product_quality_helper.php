<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** URL identity keeps product/variant queries; only known tracking parameters are ignored. */
function competitor_product_url_key($url)
{
    $parts = parse_url(competitor_normalize_url_dots(trim((string) $url)));
    if (!is_array($parts) || empty($parts['host'])) { return ''; }
    $host = preg_replace('/^www\./i', '', strtolower($parts['host']));
    if (isset($parts['port']) && !in_array((int) $parts['port'], array(80, 443), true)) { $host .= ':' . $parts['port']; }
    $path = isset($parts['path']) ? $parts['path'] : '/';
    $path = preg_replace('#^/en(?:-(?:us|gb|ca|au|eu))?(?=/|$)#i', '', $path);
    $path = rtrim($path, '/');
    $query = array();
    foreach (explode('&', isset($parts['query']) ? $parts['query'] : '') as $pair) {
        if ($pair === '') { continue; }
        $name = urldecode(explode('=', $pair, 2)[0]);
        if (preg_match('/^(?:utm_.*|gclid|fbclid|msclkid|_gl)$/i', $name)) { continue; }
        $query[] = $pair;
    }
    sort($query, SORT_STRING);
    $fragment = isset($parts['fragment']) && !preg_match('/^(?:overview|itinerary|inclusions|exclusions|prices?|dates?|details)$/i', $parts['fragment'])
        ? '#' . $parts['fragment'] : '';
    return $host . ($path === '' ? '/' : $path) . ($query ? '?' . implode('&', $query) : '') . $fragment;
}

/** Accept a same-site detail-page canonical, never a site's homepage or catalogue. */
function competitor_product_canonical_url($html, $url)
{
    if (!is_string($html) || $html === '') { return ''; }
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) { return ''; }
    foreach ($dom->getElementsByTagName('link') as $link) {
        if (!preg_match('/(?:^|\s)canonical(?:\s|$)/i', $link->getAttribute('rel'))) { continue; }
        $canonical = competitor_resolve_url($url, $link->getAttribute('href'));
        if ($canonical === '' || competitor_job_host($canonical) !== competitor_job_host($url)) { continue; }
        $path = (string) parse_url($canonical, PHP_URL_PATH);
        $path = preg_replace('#^/en(?:-(?:us|gb|ca|au|eu))?(?=/|$)#i', '', $path);
        if (trim($path, '/') === '' || competitor_is_category_url($canonical)) { continue; }
        // A query identifying an itinerary/variant must not disappear into a broad canonical.
        $original_key = competitor_product_url_key($url);
        $canonical_key = competitor_product_url_key($canonical);
        $original_query = (string) parse_url('https://' . $original_key, PHP_URL_QUERY);
        $canonical_query = (string) parse_url('https://' . $canonical_key, PHP_URL_QUERY);
        if ($original_query !== '' && $original_query !== $canonical_query) { continue; }
        return $canonical;
    }
    return '';
}

function competitor_product_days($text)
{
    foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
        if (preg_match('/\b(?:cancell?ation|prior|payment|deposit|refund|insurance|visa)\b|^\s*(?:day|hari)\s*\d/iu', $line)) { continue; }
        if (preg_match('/\b(\d{1,2})\s*d\s*\d{1,2}\s*n\b|\b(\d{1,2})\s*days?\b|(\d{1,2})\s*天\s*\d{1,2}\s*[夜晚]|\b(\d{1,2})\s*hari\b/iu', $line, $m)) {
            foreach (array_slice($m, 1) as $value) { if ($value !== '') { return (int) $value; } }
        }
    }
    return 0;
}

/** Protect actual product facts even when repeated across several pages. */
function competitor_product_content_line($text)
{
    return competitor_has_duration((string) $text) || competitor_has_inclusions((string) $text)
        || (bool) preg_match('/(?:^|\n)\s*(?:product:|(?:day\s*\d|hari\s*\d|第\s*\d+\s*天|structured product data|itinerary|exclusions?|terms|travel conditions|cancellation|payment|single supplement)\b)/iu', (string) $text)
        || (bool) preg_match('/(?:rm|myr|usd|cad|aud|gbp|eur|sgd|\$|€|£)\s*\d|\b(?:included|excluded|refundable|non-refundable|minimum\s+\d+\s*(?:pax|people|travellers)|cancellation|single supplement)\b/iu', (string) $text);
}

/** Evidence checks are flags for review, not inferred exclusions or invented facts. */
function competitor_product_quality_flags($text)
{
    $text = (string) $text;
    $flags = array();
    $days = array();
    preg_match_all('/(?:^|\n)\s*(?:day\s*(\d{1,2})\b|hari\s*(\d{1,2})\b|第\s*(\d{1,2})\s*天)/iu', $text, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        foreach (array_slice($match, 1) as $number) { if ($number !== '') { $days[(int) $number] = true; break; } }
    }
    $expected = competitor_product_days($text);
    if (!$days) { $flags[] = 'Day-by-day itinerary not detected'; }
    elseif ($expected > 0) {
        $missing = array_diff(range(1, $expected), array_keys($days));
        if ($missing) { $flags[] = 'Incomplete itinerary: missing Day ' . implode(', ', $missing); }
    }
    if (!competitor_has_package_price($text)) { $flags[] = 'Package price not detected'; }
    if (!competitor_has_inclusions($text)) { $flags[] = 'Inclusion information not detected'; }
    elseif (preg_match('/(?:^|\n)\s*(?:inclusions?|what\'?s included|price includes?)\s*[:：]?\s*(?:\n\s*)?(?:$|exclusions?\b|itinerary\b|terms\b)/iu', $text)) {
        $flags[] = 'Inclusion section appears empty';
    }
    return $flags;
}

function competitor_has_package_price($text)
{
    foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
        if (preg_match('/^\s*(?:optional|extra nights?|single supplement|camera fees?|visa fees?|gratuities|SOURCE VERSION|Files:)/i', $line)) { continue; }
        if (competitor_has_price($line)) { return true; }
        if (preg_match('/\b(?:price|from|rate|fare)\s*[:：]?\s*(?:usd|cad|gbp|aud|eur|myr|rm|\$|£|€)\s*\d/iu', $line)) { return true; }
    }
    return false;
}

/** Clean repeated product headings while retaining repeated day/meal/condition text. */
function competitor_clean_product_text($text, $title = '')
{
    $text = competitor_strip_boilerplate((string) $text);
    $heading = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $title)), 'UTF-8');
    $seen_heading = false;
    $out = array();
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', preg_replace('/^\s*Product:\s*/i', '', $line))), 'UTF-8');
        if ($heading !== '' && $key === $heading) {
            if ($seen_heading) { continue; }
            $seen_heading = true;
        }
        $out[] = $line;
    }
    return trim(implode("\n", $out));
}

/** Enrichment supplements scraped facts instead of overwriting prices or conditions. */
function competitor_merge_product_text($text, $extra, $label)
{
    $text = trim((string) $text);
    $extra = trim((string) $extra);
    if ($extra === '' || strpos($text, $extra) !== false) { return $text; }
    if ($text === '' || strpos($extra, $text) !== false) { return $extra; }
    return $text . "\n\n" . $label . ":\n" . $extra;
}

/** Explicit conflicting durations/codes/day routes prevent a canonical from merging itineraries. */
function competitor_product_signature($text)
{
    $signature = array('days' => competitor_product_days($text), 'code' => '', 'route' => array());
    if (preg_match('/(?:^|\n)\s*(?:tour|product|package)\s*(?:code|id)\s*[:：]\s*([^\n]+)/iu', (string) $text, $m)) {
        $signature['code'] = mb_strtolower(trim($m[1]), 'UTF-8');
    }
    preg_match_all('/(?:^|\n)\s*(?:day|hari)\s*(\d{1,2})\s*[:.\-–—]\s*([^\n]+)/iu', (string) $text, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $signature['route'][(int) $match[1]] = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $match[2])), 'UTF-8');
    }
    return $signature;
}

function competitor_product_signatures_conflict($left, $right)
{
    foreach (array('days', 'code') as $field) {
        if (!empty($left[$field]) && !empty($right[$field]) && $left[$field] !== $right[$field]) { return true; }
    }
    $shared = array_intersect_key($left['route'], $right['route']);
    if (count($shared) >= 2) {
        foreach ($shared as $day => $route) { if ($right['route'][$day] !== $route) { return true; } }
    }
    return false;
}

/**
 * Collapse proven URL aliases. Keep stable row indices and every original source URL.
 * Distinct regional prices/conditions are retained in labelled source blocks.
 * Reprocessing a checkpoint is idempotent: its source records and hashes survive.
 */
function competitor_prepare_crawl_items($items, $source_url = '', $analysed = array())
{
    $groups = array();
    $by_key = array();
    $index_map = array();
    foreach (is_array($items) ? $items : array() as $idx => $item) {
        if (!is_array($item)) { continue; }
        $url = isset($item['url']) ? (string) $item['url'] : '';
        $original = !empty($item['original_url']) ? (string) $item['original_url'] : $url;
        $source = !empty($item['source_url']) ? (string) $item['source_url'] : ($source_url !== '' ? (string) $source_url : $original);
        $meta = isset($item['crawl_metadata']) && is_array($item['crawl_metadata']) ? $item['crawl_metadata'] : array();
        $canonical = !empty($meta['canonical_url']) ? $meta['canonical_url'] : (isset($item['canonical_url']) ? $item['canonical_url'] : '');
        $text = competitor_clean_product_text(isset($item['text']) ? $item['text'] : '', isset($item['title']) ? $item['title'] : '');
        $item['text'] = $text;
        $records = isset($meta['source_records']) && is_array($meta['source_records']) ? $meta['source_records'] : array();
        $hashes = isset($meta['content_hashes']) && is_array($meta['content_hashes']) ? $meta['content_hashes'] : array(sha1($text));
        $signatures = isset($meta['signatures']) && is_array($meta['signatures']) ? $meta['signatures'] : array(competitor_product_signature($text));
        if (!$records) {
            $records[] = array('record_id' => sha1($original . "\n" . $url . "\n" . $idx),
                'original_url' => $original, 'url' => $url, 'source_url' => $source, 'canonical_url' => $canonical);
        }
        $keys = array_filter(array_unique(array(competitor_product_url_key($canonical), competitor_product_url_key($url))));
        if (!$keys) { $keys = array('unknown:' . $idx); }
        $target = null;
        $conflicts = array();
        $compatible = array();
        $candidates = array();
        foreach ($keys as $key) { foreach (isset($by_key[$key]) ? $by_key[$key] : array() as $g) { $candidates[$g] = true; } }
        foreach (array_keys($candidates) as $g) {
            $conflict = false;
            foreach ($groups[$g]['meta']['signatures'] as $left) {
                foreach ($signatures as $right) { if (competitor_product_signatures_conflict($left, $right)) { $conflict = true; } }
            }
            if (!$conflict) { $compatible[] = $g; }
            else { $conflicts[] = $g; }
        }
        if (count($compatible) === 1) { $target = $compatible[0]; }
        elseif (count($compatible) > 1) { $conflicts = array_keys($candidates); }
        $meta = array_merge($meta, array('original_url' => isset($meta['original_url']) ? $meta['original_url'] : $original,
            'source_url' => isset($meta['source_url']) ? $meta['source_url'] : $source, 'canonical_url' => $canonical,
            'identity_key' => reset($keys), 'source_records' => $records, 'content_hashes' => $hashes, 'signatures' => $signatures));
        if ($target === null) {
            $target = count($groups);
            $groups[] = array('idx' => $idx, 'item' => $item, 'meta' => $meta, 'members' => array($idx));
        } else {
            $group = &$groups[$target];
            $existing = array_column($group['meta']['source_records'], 'record_id');
            $new_records = array_filter($records, function ($record) use ($existing) { return !in_array($record['record_id'], $existing, true); });
            if ($new_records && array_diff($hashes, $group['meta']['content_hashes'])) {
                if (empty($group['meta']['source_labelled'])) {
                    $group['item']['text'] = 'SOURCE VERSION: ' . $group['meta']['original_url'] . "\n" . $group['item']['text'];
                    $group['meta']['source_labelled'] = true;
                }
                $group['item']['text'] .= "\n\nSOURCE VERSION: " . $meta['original_url'] . "\n" . $text;
            }
            $group['meta']['source_records'] = array_merge($group['meta']['source_records'], array_values($new_records));
            if (empty($group['meta']['canonical_url']) && $canonical !== '') {
                $group['meta']['canonical_url'] = $canonical;
                $group['meta']['identity_key'] = competitor_product_url_key($canonical);
            }
            $group['meta']['content_hashes'] = array_values(array_unique(array_merge($group['meta']['content_hashes'], $hashes)));
            $group['meta']['signatures'] = array_merge($group['meta']['signatures'], $signatures);
            $group['members'][] = $idx;
            if (isset($analysed[$idx]['id']) && !isset($analysed[$group['idx']]['id'])) { $group['idx'] = $idx; }
            unset($group);
        }
        foreach ($keys as $key) { $by_key[$key][] = $target; $by_key[$key] = array_unique($by_key[$key]); }
        foreach ($conflicts as $g) {
            $groups[$g]['meta']['identity_conflict'] = true;
            $groups[$target]['meta']['identity_conflict'] = true;
        }
    }
    $out = array();
    $total = $needs_review = 0;
    foreach ($groups as $group) {
        $meta = $group['meta'];
        $group['item']['text'] = competitor_clean_product_text($group['item']['text'], isset($group['item']['title']) ? $group['item']['title'] : '');
        $meta['quality_flags'] = competitor_product_quality_flags($group['item']['text']);
        if (!empty($meta['identity_conflict'])) { $meta['quality_flags'][] = 'Regional itinerary details differ; review before combining'; }
        if ($meta['quality_flags']) { $needs_review++; }
        $total += count($meta['source_records']);
        $group['item']['crawl_metadata'] = $meta;
        $out[$group['idx']] = $group['item'];
        foreach ($group['members'] as $idx) { $index_map[$idx] = $group['idx']; }
    }
    return array('items' => $out, 'index_map' => $index_map, 'summary' => array(
        'total_records' => $total, 'duplicate_records' => $total - count($out),
        'unique_products' => count($out), 'needs_review' => $needs_review));
}

/** Deterministic checks on AI output; missing fields remain empty and are flagged. */
function competitor_flag_analysis_gaps($record)
{
    $gaps = array();
    $itinerary = isset($record['itinerary']) && is_array($record['itinerary']) ? $record['itinerary'] : array();
    $numbered = array();
    foreach ($itinerary as $day) {
        if (is_array($day) && isset($day['day']) && preg_match('/(?:day\s*)?(\d+)/i', $day['day'], $match)) { $numbered[(int) $match[1]] = true; }
    }
    $days = $numbered ? count($numbered) : count($itinerary);
    $expected = competitor_product_days(isset($record['duration']) ? $record['duration'] : '');
    if (!$days) { $gaps[] = 'Day-by-day itinerary is missing; confirm before booking'; }
    elseif ($expected > $days) { $gaps[] = 'Day-by-day itinerary is incomplete (' . $days . ' of ' . $expected . ' days); confirm the missing days'; }
    if (empty($record['price']) && empty($record['price_from']) && empty($record['price_to'])) { $gaps[] = 'Package price is missing; confirm the current price and its basis'; }
    if (empty($record['inclusions'])) { $gaps[] = 'Package inclusions are missing; confirm what the price covers'; }
    $remarks = isset($record['special_remarks']) ? competitor_list_items($record['special_remarks']) : array();
    foreach ($gaps as $gap) {
        $remark = 'Information gap: ' . $gap;
        if (!in_array($remark, $remarks, true)) { $remarks[] = $remark; }
    }
    $record['special_remarks'] = $remarks;
    return $record;
}
