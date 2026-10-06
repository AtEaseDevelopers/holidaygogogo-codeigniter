<?php
/**
 * Run with: php tests/helpers/CostingQuoteHelperTest.php
 *
 * Drives the pure quote helpers that back Costing_Model::Save_Quote_Details:
 * price normalisation, per-row hotel/flight normalisation (blank rows skipped),
 * quote-level field prep (only known columns, price coerced to float), and the
 * footer-notes splitter / default boilerplate.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

require_once __DIR__ . '/../../application/helpers/costing_quote_helper.php';

$assertions = array();

// --- costing_quote_price_normalize -----------------------------------------
$assertions['price plain']       = costing_quote_price_normalize('1999') === 1999.0;
$assertions['price commas']      = costing_quote_price_normalize('1,999.00') === 1999.0;
$assertions['price rm prefix']   = costing_quote_price_normalize('RM 1,708') === 1708.0;
$assertions['price decimals']    = costing_quote_price_normalize('99.5') === 99.5;
$assertions['price blank null']  = costing_quote_price_normalize('') === null;
$assertions['price dash null']   = costing_quote_price_normalize('-') === null;
$assertions['price junk null']   = costing_quote_price_normalize('abc') === null;
$assertions['price array null']  = costing_quote_price_normalize(array(1)) === null;

// --- costing_quote_hotel_prepare_row ---------------------------------------
$hotel = costing_quote_hotel_prepare_row(array(
    'hotel_name'        => '  Tahiti Central Phu Quoc or similar ',
    'twin_triple_price' => '1,999',
    'single_supp_price' => 'RM 699',
));
$assertions['hotel name trimmed']  = $hotel['hotel_name'] === 'Tahiti Central Phu Quoc or similar';
$assertions['hotel twin float']    = $hotel['twin_triple_price'] === 1999.0;
$assertions['hotel single float']  = $hotel['single_supp_price'] === 699.0;
$assertions['hotel not empty']     = $hotel['is_empty'] === false;

$hotelPriceOnly = costing_quote_hotel_prepare_row(array('single_supp_price' => '500'));
$assertions['hotel price-only kept']  = $hotelPriceOnly['is_empty'] === false;
$assertions['hotel price-only null name'] = $hotelPriceOnly['hotel_name'] === null;

$hotelBlank = costing_quote_hotel_prepare_row(array('hotel_name' => '   ', 'twin_triple_price' => '', 'single_supp_price' => ''));
$assertions['hotel all blank empty'] = $hotelBlank['is_empty'] === true;
$assertions['hotel blank null name'] = $hotelBlank['hotel_name'] === null;
$assertions['hotel blank null twin'] = $hotelBlank['twin_triple_price'] === null;

// --- costing_quote_flight_prepare_row --------------------------------------
$flight = costing_quote_flight_prepare_row(array(
    'travel_date' => ' 15 Jan 2027 ',
    'sector'      => 'KUL - PQC',
    'flight_no'   => 'AK 545',
    'timing'      => '1250 - 1355',
    'duration'    => '1hr 45mins',
));
$assertions['flight date trimmed'] = $flight['travel_date'] === '15 Jan 2027';
$assertions['flight sector kept']  = $flight['sector'] === 'KUL - PQC';
$assertions['flight no kept']      = $flight['flight_no'] === 'AK 545';
$assertions['flight timing kept']  = $flight['timing'] === '1250 - 1355';
$assertions['flight duration kept'] = $flight['duration'] === '1hr 45mins';
$assertions['flight not empty']    = $flight['is_empty'] === false;

$flightPartial = costing_quote_flight_prepare_row(array('sector' => 'PQC - KUL'));
$assertions['flight partial kept']    = $flightPartial['is_empty'] === false;
$assertions['flight partial null date'] = $flightPartial['travel_date'] === null;

$flightBlank = costing_quote_flight_prepare_row(array('sector' => '  ', 'flight_no' => ''));
$assertions['flight all blank empty'] = $flightBlank['is_empty'] === true;

// --- costing_quote_level_fields / prepare_level ----------------------------
$fields = costing_quote_level_fields();
$assertions['level has price field'] = in_array('quote_flight_price', $fields, true);
$assertions['level eight fields']    = count($fields) === 8;

$level = costing_quote_prepare_level(array(
    'quote_pricing_basis'    => ' 25paxs + 1FOC ',
    'quote_flight_price'     => 'RM 1,708',
    'quote_flight_expiry'    => 'Expired valid until 10 September 2026',
    'quote_hotel_note'       => '',
    'bogus_field'            => 'should be dropped',
));
$assertions['level basis trimmed']   = $level['quote_pricing_basis'] === '25paxs + 1FOC';
$assertions['level price float']     = $level['quote_flight_price'] === 1708.0;
$assertions['level expiry kept']     = $level['quote_flight_expiry'] === 'Expired valid until 10 September 2026';
$assertions['level blank null']      = $level['quote_hotel_note'] === null;
// Hotel note is rich-text (TinyMCE) now: a visually blank editor ("<p></p>")
// normalises to NULL, real HTML is kept verbatim.
$hotelNoteBlank = costing_quote_prepare_level(array('quote_hotel_note' => '<p><br></p>'));
$assertions['hotel note blank html -> null'] = $hotelNoteBlank['quote_hotel_note'] === null;
$hotelNoteKept = costing_quote_prepare_level(array('quote_hotel_note' => '<p>Lowest room type</p>'));
$assertions['hotel note html kept'] = $hotelNoteKept['quote_hotel_note'] === '<p>Lowest room type</p>';
$assertions['level no bogus key']    = !array_key_exists('bogus_field', $level);
$assertions['level only known keys'] = (array_keys($level) === $fields);

// Footer notes are rich-text (TinyMCE) now and special-cased: a visually blank
// editor ("<p></p>" / whitespace) is persisted as '' (not NULL) so the quote
// renders no footer. NULL stays reserved for "never saved" → default boilerplate.
$levelClearedFooter = costing_quote_prepare_level(array('quote_footer_notes' => '<p><br></p>'));
$assertions['level cleared footer -> empty string'] = $levelClearedFooter['quote_footer_notes'] === '';
$levelKeptFooter = costing_quote_prepare_level(array('quote_footer_notes' => '<p>Custom note</p>'));
$assertions['level custom footer kept'] = $levelKeptFooter['quote_footer_notes'] === '<p>Custom note</p>';

// --- costing_quote_default_footer_notes / footer_notes_html ----------------
$default = costing_quote_default_footer_notes();
$assertions['default footer non-empty'] = trim($default) !== '';
$assertions['default footer is html'] = strpos($default, '<p>') !== false;
// NULL (never saved) falls back to the default boilerplate HTML...
$assertions['null footer -> default html'] = costing_quote_footer_notes_html(null) === $default;
// ...but a deliberately cleared (blank HTML) footer renders nothing at all.
$assertions['blank html footer -> empty'] = costing_quote_footer_notes_html('<p></p>') === '';
$assertions['whitespace footer -> empty'] = costing_quote_footer_notes_html("  \n ") === '';
// Real HTML is returned verbatim.
$assertions['custom footer html kept'] = costing_quote_footer_notes_html('<p>One</p><ul><li>Two</li></ul>') === '<p>One</p><ul><li>Two</li></ul>';

// --- 18 Sep 2026 feedback: flight modes (4.3) ------------------------------
$modes = costing_quote_flight_modes();
$assertions['modes: four options']    = count($modes) === 4;
$assertions['modes: has none/fit/git'] = isset($modes['none'], $modes['include'], $modes['fit'], $modes['git']);
$assertions['mode: normalize known']  = costing_quote_normalize_flight_mode('git') === 'git';
$assertions['mode: normalize blank -> fit'] = costing_quote_normalize_flight_mode('') === 'fit';
$assertions['mode: normalize junk -> fit']  = costing_quote_normalize_flight_mode('zzz') === 'fit';
$assertions['mode: none hides section']  = costing_quote_flight_mode_shows_section('none') === false;
$assertions['mode: include shows section'] = costing_quote_flight_mode_shows_section('include') === true;
$assertions['mode: include no pricing']  = costing_quote_flight_mode_shows_pricing('include') === false;
$assertions['mode: fit shows pricing']   = costing_quote_flight_mode_shows_pricing('fit') === true;
$assertions['mode: git shows pricing']   = costing_quote_flight_mode_shows_pricing('git') === true;
$assertions['mode: none no pricing']     = costing_quote_flight_mode_shows_pricing('none') === false;

// --- airlines (item 3) -----------------------------------------------------
$airlines = costing_quote_airlines();
$assertions['airlines: MH/AK/OD present'] = isset($airlines['MH'], $airlines['AK'], $airlines['OD']);

// --- hotel columns + prices (4.2) ------------------------------------------
$assertions['hotel cols: default one'] = costing_quote_hotel_columns_normalize(null) === array('Twin / Triple');
$assertions['hotel cols: from json']   = costing_quote_hotel_columns_normalize('["Twin","Triple"]') === array('Twin', 'Triple');
$assertions['hotel cols: trims + drops blank'] = costing_quote_hotel_columns_normalize(array(' Twin ', '', 'Triple')) === array('Twin', 'Triple');
$assertions['hotel cols: all blank -> default'] = costing_quote_hotel_columns_normalize(array('', '  ')) === array('Twin / Triple');

$assertions['hotel prices: pads to count'] = costing_quote_hotel_prices_normalize(array('1000'), 3) === array(1000.0, null, null);
$assertions['hotel prices: truncates'] = costing_quote_hotel_prices_normalize(array('1', '2', '3'), 2) === array(1.0, 2.0);
$assertions['hotel prices: from json'] = costing_quote_hotel_prices_normalize('["1,999","699"]', 2) === array(1999.0, 699.0);

$titles = costing_quote_hotel_title_presets();
$assertions['hotel titles: has twin/triple presets'] = in_array('Twin', $titles, true) && in_array('Triple', $titles, true);

// Hotel prepare with multi-column prices.
$hotelMulti = costing_quote_hotel_prepare_row(array(
    'hotel_name' => '4* Hotel',
    'prices'     => array('4268', '3881'),
    'single_supp_price' => '1166',
), 2);
$assertions['hotel multi: prices_json aligned'] = $hotelMulti['prices_json'] === json_encode(array(4268.0, 3881.0));
$assertions['hotel multi: first col -> twin_triple (legacy)'] = $hotelMulti['twin_triple_price'] === 4268.0;
$assertions['hotel multi: single kept'] = $hotelMulti['single_supp_price'] === 1166.0;
$assertions['hotel multi: not empty'] = $hotelMulti['is_empty'] === false;
$hotelMultiBlank = costing_quote_hotel_prepare_row(array('prices' => array('', '')), 2);
$assertions['hotel multi blank: empty'] = $hotelMultiBlank['is_empty'] === true;

// --- flight options (4.4) --------------------------------------------------
$opt = costing_quote_flight_option_prepare(array(
    'title' => ' Option 1 ', 'airline' => 'AK', 'price' => 'RM 1,000',
    'fare_includes' => '20kg + MOB', 'fare_expiry' => '20 Sep 2026',
));
$assertions['opt: title trimmed'] = $opt['title'] === 'Option 1';
$assertions['opt: airline kept']  = $opt['airline'] === 'AK';
$assertions['opt: price float']   = $opt['price'] === 1000.0;
$assertions['opt: fare kept']     = $opt['fare_includes'] === '20kg + MOB';
$assertions['opt: not empty']     = $opt['is_empty'] === false;
$optBlank = costing_quote_flight_option_prepare(array('title' => '', 'price' => ''));
$assertions['opt: all blank empty'] = $optBlank['is_empty'] === true;
$assertions['opt: blank null title'] = $optBlank['title'] === null;

// --- first-column title label + single-supp toggle (5 Oct 2026) -------------
$titleOpts = costing_quote_hotel_title_label_options();
$assertions['title opts: hotel + room type'] = $titleOpts === array('Hotel', 'Room Type');
$assertions['title: default hotel']   = costing_quote_normalize_hotel_title_label('') === 'Hotel';
$assertions['title: junk -> hotel']   = costing_quote_normalize_hotel_title_label('zzz') === 'Hotel';
$assertions['title: room type kept']  = costing_quote_normalize_hotel_title_label('Room Type') === 'Room Type';
$assertions['title: case-insensitive'] = costing_quote_normalize_hotel_title_label('room type') === 'Room Type';

$assertions['single: default on (null)']  = costing_quote_single_supp_enabled(null) === true;
$assertions['single: default on (blank)'] = costing_quote_single_supp_enabled('') === true;
$assertions['single: on when 1']          = costing_quote_single_supp_enabled('1') === true;
$assertions['single: off when 0']         = costing_quote_single_supp_enabled('0') === false;
$assertions['single: off when false']     = costing_quote_single_supp_enabled('false') === false;

// --- costing_quote_hotel_rows_have_single_supp (auto-collapse when all blank) ---
$assertions['any single: none normalised'] = costing_quote_hotel_rows_have_single_supp(array(
    array('name' => 'A', 'single' => null),
    array('name' => 'B', 'single' => null),
)) === false;
$assertions['any single: one has value']   = costing_quote_hotel_rows_have_single_supp(array(
    array('name' => 'A', 'single' => null),
    array('name' => 'B', 'single' => 300.0),
)) === true;
$assertions['any single: raw field']       = costing_quote_hotel_rows_have_single_supp(array(
    array('single_supp_price' => 'RM 500'),
)) === true;
$assertions['any single: raw blank/dash']  = costing_quote_hotel_rows_have_single_supp(array(
    array('single_supp_price' => ''),
    array('single_supp_price' => '-'),
)) === false;
$assertions['any single: empty list']      = costing_quote_hotel_rows_have_single_supp(array()) === false;
$assertions['any single: non-array']       = costing_quote_hotel_rows_have_single_supp('nope') === false;

// --- extra level (mode + hotel columns + title + single supp) ---------------
$extra = costing_quote_prepare_extra_level(array('quote_flight_mode' => 'git', 'quote_hotel_columns' => array('Twin', 'Triple'), 'quote_hotel_title_label' => 'Room Type', 'quote_show_single_supp' => '0'));
$assertions['extra: mode normalized'] = $extra['quote_flight_mode'] === 'git';
$assertions['extra: columns json']    = $extra['quote_hotel_columns'] === json_encode(array('Twin', 'Triple'));
$assertions['extra: title kept']      = $extra['quote_hotel_title_label'] === 'Room Type';
$assertions['extra: single off -> 0'] = $extra['quote_show_single_supp'] === 0;
$assertions['extra: four keys']       = (array_keys($extra) === array('quote_flight_mode', 'quote_hotel_columns', 'quote_hotel_title_label', 'quote_show_single_supp'));
// Defaults when nothing posted: title -> Hotel, single supp -> 1 (on).
$extraDefault = costing_quote_prepare_extra_level(array());
$assertions['extra default: title hotel'] = $extraDefault['quote_hotel_title_label'] === 'Hotel';
$assertions['extra default: single on -> 1'] = $extraDefault['quote_show_single_supp'] === 1;
// Legacy level contract unchanged.
$assertions['level still eight fields'] = count(costing_quote_level_fields()) === 8;

$failed = 0;
foreach ($assertions as $label => $ok) {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

echo PHP_EOL . ($failed === 0 ? "All assertions passed.\n" : "$failed assertion(s) failed.\n");
exit($failed === 0 ? 0 : 1);
