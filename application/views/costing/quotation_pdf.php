<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Customer-facing Quotation PDF (Dompdf). Layout mirrors the Booking Confirmation
 * PDF (application/views/booking/booking_confirmation.php): embedded logo header,
 * company block, title + No. row, a details table, itemised rows (here: the per-day
 * itinerary), a per-item "Package Includes" list with each line's selling price,
 * and a totals footer. All prices are the customer-facing selling price in MYR —
 * raw cost, margin %, currency snapshot, and profit stay internal and are NOT rendered.
 *
 * When the package has hotel-pricing / flight-schedule data (the 9 Sep 2026
 * feedback screenshot), an ADDITIONAL page is appended showing those tables — it
 * augments this quotation, it does not replace the item/itinerary layout above.
 */
$company    = isset($company) && is_array($company) ? $company : array();
$booking    = isset($booking) ? $booking : array();
$package    = isset($package) ? $package : array();
$itinerary    = isset($itinerary) ? $itinerary : array();
$items        = isset($items) ? $items : array();
$combinations = isset($combinations) ? $combinations : array();
$financials   = isset($financials) ? $financials : array();
$quote_hotels  = isset($quote_hotels) && is_array($quote_hotels) ? $quote_hotels : array();
$quote_flights = isset($quote_flights) && is_array($quote_flights) ? $quote_flights : array();
$quote_flight_options = isset($quote_flight_options) && is_array($quote_flight_options) ? $quote_flight_options : array();
$quote_meta    = isset($quote_meta) && is_array($quote_meta) ? $quote_meta : array();
$public_ref   = isset($public_ref) ? $public_ref : '';

$CompanyName    = isset($company['Name']) ? $company['Name'] : 'HolidayGoGoGo';
$CompanyReg     = isset($company['RegistrationNumber']) ? $company['RegistrationNumber'] : '';
$CompanyLicense = isset($company['LicenseNumber']) ? $company['LicenseNumber'] : '';
// Prefer the dated address (matches Booking Confirmation PDF); fall back to DB.
$CompanyAddress = isset($CompanyAddress) && $CompanyAddress !== ''
    ? $CompanyAddress
    : (isset($company['Address']) ? $company['Address'] : '');
$CompanyWebsite = isset($company['Website']) ? $company['Website'] : '';

$PackageName   = isset($package['name']) ? $package['name'] : '-';
// #9 Sales Person replaces the internal Tour Code on the customer quotation.
$SalesPerson   = isset($package['sales_person']) && $package['sales_person'] !== '' ? $package['sales_person'] : '-';
$DurationDays  = (int) (isset($package['duration_days']) ? $package['duration_days'] : 0);
$DurationNights = (int) (isset($package['duration_nights']) ? $package['duration_nights'] : 0);
$TotalPax      = (int) (isset($booking['total_pax']) ? $booking['total_pax'] : 0);
// Total shown to the customer: additive combination total when combinations
// exist, else the legacy internal revenue (passed as total_selling by the model).
$TotalSelling  = isset($total_selling)
    ? (float) $total_selling
    : (float) (isset($financials['total_revenue']) ? $financials['total_revenue'] : 0);
$HasCombinations = !empty($combinations);

// #2 / #9 Travel Date as a start–end range.
$TravelStart = !empty($booking['travel_date']) && strtotime($booking['travel_date'])
    ? strtoupper(date('d M Y', strtotime($booking['travel_date'])))
    : '-';
$TravelEnd = !empty($booking['travel_date_end']) && strtotime($booking['travel_date_end'])
    ? strtoupper(date('d M Y', strtotime($booking['travel_date_end'])))
    : '';
$TravelDate = ($TravelEnd !== '' && $TravelEnd !== $TravelStart)
    ? ($TravelStart . ' – ' . $TravelEnd)
    : $TravelStart;
$InsertDate = strtoupper(date('d M Y'));

// Embedded logo (same asset the Booking Confirmation uses).
$logoSrc = '';
$logoPath = FCPATH . 'assets/image/pdflogo-new.jpeg';
if (is_file($logoPath)) {
    $logoSrc = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($logoPath));
}

// ---- Hotel / flight extra page (screenshot layout). Only rendered when the
// package carries any of this data, so legacy quotations are untouched. --------
$this->load->helper('costing_quote');
$qm = $quote_meta;
$qv = function ($key) use ($qm) {
    return isset($qm[$key]) && $qm[$key] !== null ? trim((string) $qm[$key]) : '';
};
$TourCode       = isset($package['tour_code']) && $package['tour_code'] !== '' ? $package['tour_code'] : '-';
$CustomerName   = isset($package['customer_name']) && $package['customer_name'] !== '' ? $package['customer_name'] : '-';
$PricingBasis   = $qv('quote_pricing_basis');
$TravelDateNote = $qv('quote_travel_date_note');
$HotelNote      = $qv('quote_hotel_note');
// 5 Oct 2026: first-column title (Hotel / Room Type) + Single Supp on/off.
$HotelTitleLabel = costing_quote_normalize_hotel_title_label($qv('quote_hotel_title_label'));
$ShowSingleSupp  = costing_quote_single_supp_enabled(
    isset($qm['quote_show_single_supp']) ? $qm['quote_show_single_supp'] : null
);
$money2 = function ($value) {
    return 'RM ' . number_format((float) $value, 2, '.', ',');
};

// 4.3 Flight mode: none hides the flight section, include shows schedule only,
// FIT/GIT show schedule + pricing.
$FlightMode    = costing_quote_normalize_flight_mode($qv('quote_flight_mode'));
$ShowFlight    = costing_quote_flight_mode_shows_section($FlightMode);
$FlightPricing = costing_quote_flight_mode_shows_pricing($FlightMode);

// 4.2 Hotel pricing columns + per-column prices per hotel row (fallback to the
// legacy single twin/triple column for pre-rework data).
$HotelColumns = costing_quote_hotel_columns_normalize($qv('quote_hotel_columns'));
$HotelColCount = count($HotelColumns);
$HotelRows = array();
foreach ($quote_hotels as $h) {
    $hname = trim((string) (isset($h['hotel_name']) ? $h['hotel_name'] : ''));
    $prices = costing_quote_hotel_prices_normalize(
        (isset($h['prices_json']) && $h['prices_json'] !== null && $h['prices_json'] !== '')
            ? $h['prices_json']
            : array(isset($h['twin_triple_price']) ? $h['twin_triple_price'] : null),
        $HotelColCount
    );
    $single = isset($h['single_supp_price']) && $h['single_supp_price'] !== null && $h['single_supp_price'] !== '' ? $h['single_supp_price'] : null;
    $all_null = ($single === null);
    foreach ($prices as $p) { if ($p !== null) { $all_null = false; break; } }
    if ($hname === '' && $all_null) { continue; }
    $HotelRows[] = array('name' => $hname, 'prices' => $prices, 'single' => $single);
}
// Auto-collapse the Single Supp column when no hotel actually provided a value
// (5 Oct 2026: some hotels don't give single supp — drop the all-dash column).
$ShowSingleSupp = $ShowSingleSupp && costing_quote_hotel_rows_have_single_supp($HotelRows);

// 4.4 Flight OPTIONS (each: title/airline + own schedule + pricing). Legacy data
// without options falls back to a single option built from the package-level
// flight fields + the flat flight rows.
$FlightOptions = array();
foreach ($quote_flight_options as $opt) {
    $rows = array();
    foreach ((array) (isset($opt['flights']) ? $opt['flights'] : array()) as $f) {
        $cells = array(
            isset($f['travel_date']) ? $f['travel_date'] : '',
            isset($f['sector']) ? $f['sector'] : '',
            isset($f['flight_no']) ? $f['flight_no'] : '',
            isset($f['timing']) ? $f['timing'] : '',
            isset($f['duration']) ? $f['duration'] : '',
        );
        $blank = true;
        foreach ($cells as $c) { if (trim((string) $c) !== '') { $blank = false; break; } }
        if (!$blank) { $rows[] = $cells; }
    }
    $FlightOptions[] = array(
        'title'         => trim((string) (isset($opt['title']) ? $opt['title'] : '')),
        'airline'       => trim((string) (isset($opt['airline']) ? $opt['airline'] : '')),
        'price'         => (isset($opt['price']) && $opt['price'] !== null && $opt['price'] !== '') ? (float) $opt['price'] : null,
        'fare_includes' => trim((string) (isset($opt['fare_includes']) ? $opt['fare_includes'] : '')),
        'fare_expiry'   => trim((string) (isset($opt['fare_expiry']) ? $opt['fare_expiry'] : '')),
        'rows'          => $rows,
    );
}
if (empty($FlightOptions)) {
    // Legacy fallback: one option from the old package-level flight fields.
    $legacy_rows = array();
    foreach ($quote_flights as $f) {
        $cells = array(
            isset($f['travel_date']) ? $f['travel_date'] : '',
            isset($f['sector']) ? $f['sector'] : '',
            isset($f['flight_no']) ? $f['flight_no'] : '',
            isset($f['timing']) ? $f['timing'] : '',
            isset($f['duration']) ? $f['duration'] : '',
        );
        $blank = true;
        foreach ($cells as $c) { if (trim((string) $c) !== '') { $blank = false; break; } }
        if (!$blank) { $legacy_rows[] = $cells; }
    }
    $FlightOptions[] = array(
        'title'         => $qv('quote_flight_title'),
        'airline'       => '',
        'price'         => (isset($qm['quote_flight_price']) && $qm['quote_flight_price'] !== null && $qm['quote_flight_price'] !== '') ? (float) $qm['quote_flight_price'] : null,
        'fare_includes' => $qv('quote_flight_fare_note'),
        'fare_expiry'   => $qv('quote_flight_expiry'),
        'rows'          => $legacy_rows,
    );
}

// The hotel/flight page is always appended to the quotation. Empty tables show a
// "to be confirmed" placeholder row. The footer falls back to the default
// boilerplate only when it was never saved (NULL); a deliberately cleared footer
// ('') renders nothing. Pass the raw value — $qv collapses NULL to '' — so the
// splitter can still tell the two apart.
$ShowLogisticsPage = true;
$FooterLines = costing_quote_footer_note_lines(
    array_key_exists('quote_footer_notes', $qm) ? $qm['quote_footer_notes'] : null
);
?>
<html><head>
    <meta charset="utf-8">
    <title>Quotation <?php echo html_escape($public_ref); ?></title>
</head><style type="text/css">
    body {
        font-family: "Segoe UI", Arial, sans-serif !important;
        font-size: 13px;
        padding-left: 10px;
        padding-right: 10px;
    }
    h1 { font-size: 24px; text-align: center; margin-top: 0px; margin-bottom: 5px; }
    h3 { font-size: 16px; margin: 0px; margin-bottom: 5px; }
    p { margin-top: 0px; margin-bottom: 2px; }
    .margin-top-10 { margin-top: 10px; }
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .small-font { font-size: 13px; }
    .red { color: #c0392b; }
    table.kv, table.data { width: 100%; border-collapse: collapse; }
    table.kv td { border: 1px solid #444; padding: 5px 8px; font-size: 12px; vertical-align: top; }
    table.kv td.k { width: 26%; font-weight: bold; background: #f2f2f2; }
    table.data th, table.data td { border: 1px solid #444; padding: 5px 8px; font-size: 12px; }
    table.data th { background: #d9d9d9; text-align: center; font-weight: bold; }
    .section-title { font-weight: bold; text-align: center; margin: 14px 0 6px 0; }
    .note-row td { background: #f7f7f7; font-style: italic; }
    .footer-notes { margin-top: 14px; }
    .footer-notes p { background: #fff3a3; font-style: italic; font-size: 11px; padding: 2px 4px; margin-bottom: 4px; }
    /* Reserve top/bottom margin on every page for the repeating header/footer. */
    @page { margin: 165px 40px 95px 40px; }
    #pdf-header { position: fixed; top: -145px; left: 0px; right: 0px; height: 135px; }
    #pdf-footer { position: fixed; bottom: -78px; left: 0px; right: 0px; height: 68px; }
</style><body>
    <!-- Repeats on every page (fixed) -->
    <div id="pdf-header">
        <table>
            <tr>
                <?php if ($logoSrc !== '') { ?>
                    <td style="width:15%"><img src="<?php echo $logoSrc; ?>" style="width:160px;"></td>
                <?php } ?>
                <td style="width:85%; text-align: center;">
                    <h1><?php echo html_escape($CompanyName); ?></h1>
                    <small>(Co. Reg. No. - <?php echo html_escape($CompanyReg); ?> | Travel Agent License No. - <?php echo html_escape($CompanyLicense); ?>)</small>
                    <p class="text-center small-font"><small>Address : <?php echo html_escape($CompanyAddress); ?></small></p>
                    <p class="text-center small-font"><small>Website : <?php echo html_escape($CompanyWebsite); ?></small></p>
                </td>
            </tr>
        </table>
        <hr class="margin-top-10">
    </div>

    <!-- Repeats on every page (fixed) -->
    <div id="pdf-footer">
        <hr style="margin-bottom:5px;">
        <p style="font-size:12px; text-align:center;"><i>This Is A Computer Generated Quotation. No Signature Required.</i></p>
    </div>

    <table style="width:100%;">
        <tr>
            <th style="width:65%;"><h3 class="text-right">Custom Quotation</h3></th>
            <th style="width:35%; font-weight:700; text-align:right;">No : <?php echo html_escape($public_ref); ?></th>
        </tr>
    </table>

    <?php
    // Feedback 28 Sep 2026: the "Custom Quotation" title + No. row is kept, but the
    // Tour Package / Duration / Date / Travel Date / No. Of Pax / Sales Person
    // details table below it is NOT rendered — with the pricing breakdown gone it
    // left a near-empty first page, and every one of those fields is repeated on
    // the Hotel & Flight logistics page's key/value table below. $PackageName /
    // $TravelDate / $InsertDate remain available for internal callers.
    ?>

    <?php
    // Feedback 18 Sep 2026 (item 5): the per-item PRICING BREAKDOWN (the "Package
    // Options" list with each combination's per-pax/total price and its cost-item
    // names, and the legacy flat "Package Includes" price list) is intentionally
    // NOT rendered on the customer quotation. The detailed pricing stays internal
    // in the Costing Template; the customer sees prices only on the Hotel & Flight
    // table below, alongside the Itinerary, Inclusions, Exclusions and T&C.
    // $combinations / $items / $TotalSelling remain available for internal callers.
    ?>

    <!-- EXTRA PAGE: Hotel pricing + flight schedule (screenshot layout). Appended
         only when the package carries this data — augments, never replaces. -->
    <?php if ($ShowLogisticsPage) { ?>
    <div>
        <!-- Key / value details -->
        <table class="kv">
            <tr>
                <td class="k">SALES PERSON</td>
                <td><?php echo html_escape($SalesPerson); ?></td>
            </tr>
            <tr>
                <td class="k">TOUR PACKAGE</td>
                <td><?php echo html_escape($PackageName); ?></td>
            </tr>
            <tr>
                <td class="k">NAME</td>
                <td><?php echo html_escape($CustomerName); ?></td>
            </tr>
            <tr>
                <td class="k">TRAVEL DATE</td>
                <td>
                    <?php echo html_escape($TravelDate); ?>
                    <?php if ($TravelDateNote !== '') { ?>
                        <span class="red">Note: <?php echo html_escape($TravelDateNote); ?></span>
                    <?php } ?>
                </td>
            </tr>
            <tr>
                <td class="k">TOTAL PAX</td>
                <td><?php echo $TotalPax > 0 ? ($TotalPax . 'pax') : '-'; ?></td>
            </tr>
        </table>

        <!-- Hotel pricing (dynamic columns — 4.2) -->
        <div class="section-title">
            Pricing per person<?php if ($PricingBasis !== '') { ?> (Quoted based on <span class="red"><?php echo html_escape($PricingBasis); ?></span>)<?php } ?>
        </div>
        <?php $hotel_colspan = 1 + $HotelColCount + ($ShowSingleSupp ? 1 : 0); ?>
        <table class="data">
            <thead>
                <tr>
                    <th style="text-align:left;"><?php echo html_escape($HotelTitleLabel); ?></th>
                    <?php foreach ($HotelColumns as $col_label) { ?>
                        <th><?php echo html_escape($col_label); ?> (RM)</th>
                    <?php } ?>
                    <?php if ($ShowSingleSupp) { ?><th>Single Supp (RM)</th><?php } ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($HotelRows)) { ?>
                    <tr><td colspan="<?php echo $hotel_colspan; ?>" class="text-center">Hotel pricing to be confirmed.</td></tr>
                <?php } else { foreach ($HotelRows as $h) { ?>
                    <tr>
                        <td><?php echo html_escape($h['name'] !== '' ? $h['name'] : '-'); ?></td>
                        <?php foreach ($h['prices'] as $price) { ?>
                            <td class="text-center"><?php echo $price !== null ? $money2($price) : '-'; ?></td>
                        <?php } ?>
                        <?php if ($ShowSingleSupp) { ?><td class="text-center"><?php echo $h['single'] !== null ? $money2($h['single']) : '-'; ?></td><?php } ?>
                    </tr>
                <?php } } ?>
                <?php if ($HotelNote !== '') { ?>
                    <tr class="note-row"><td colspan="<?php echo $hotel_colspan; ?>" class="text-center"><?php echo html_escape($HotelNote); ?></td></tr>
                <?php } ?>
            </tbody>
        </table>

        <!-- Flight (mode-driven; multiple airline options — 4.3 / 4.4) -->
        <?php if ($ShowFlight) { $opt_no = 0; foreach ($FlightOptions as $opt) { $opt_no++;
            $opt_heading = $opt['title'] !== '' ? $opt['title'] : ('Flight Option ' . $opt_no);
            if ($opt['airline'] !== '') { $opt_heading .= ' — ' . $opt['airline']; }
        ?>
        <div class="section-title"><?php echo html_escape(strtoupper($opt_heading)); ?></div>
        <table class="data">
            <thead>
                <tr>
                    <th>Travel Date</th>
                    <th>Sector</th>
                    <th>Flight No</th>
                    <th>Timing</th>
                    <th>Duration</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($opt['rows'])) { ?>
                    <tr><td colspan="5" class="text-center">Flight schedule to be confirmed.</td></tr>
                <?php } else { foreach ($opt['rows'] as $cells) { ?>
                    <tr>
                        <?php foreach ($cells as $c) { ?>
                            <td class="text-center"><?php echo html_escape(trim((string) $c) !== '' ? $c : '-'); ?></td>
                        <?php } ?>
                    </tr>
                <?php } } ?>
                <?php if ($FlightPricing) { ?>
                    <?php if ($opt['price'] !== null) { ?>
                        <tr>
                            <td colspan="4" style="font-weight:bold; text-align:right;">Price per person (Adult / Child)</td>
                            <td class="text-center" style="font-weight:bold;"><?php echo $money2($opt['price']); ?></td>
                        </tr>
                    <?php } ?>
                    <?php if ($opt['fare_includes'] !== '') { ?>
                        <tr class="note-row"><td colspan="5" class="text-center">Fare quote includes <?php echo html_escape($opt['fare_includes']); ?></td></tr>
                    <?php } ?>
                    <?php if ($opt['fare_expiry'] !== '') { ?>
                        <tr class="note-row"><td colspan="5" class="text-center" style="font-weight:bold; font-style:normal;"><?php echo html_escape($opt['fare_expiry']); ?></td></tr>
                    <?php } ?>
                <?php } ?>
            </tbody>
        </table>
        <?php } } ?>

        <!-- Boilerplate footer notes (highlighted) -->
        <?php if (!empty($FooterLines)) { ?>
            <div class="footer-notes">
                <?php foreach ($FooterLines as $line) { ?>
                    <p><?php echo html_escape($line); ?></p>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
    <?php } ?>

    <!-- PAGE: Itinerary -->
    <div style="page-break-before: always;">
        <h3 class="text-center" style="margin-bottom:5px;"><?php echo html_escape($PackageName); ?> — Itinerary</h3>
        <p class="text-center small-font" style="margin-bottom:8px;"><?php echo $DurationDays; ?> Days / <?php echo $DurationNights; ?> Nights</p>
        <hr style="margin-bottom:0px;">
        <table style="width:100%; font-size:13px;">
            <tr style="font-weight:700;">
                <td style="width:8%;">Day</td>
                <td style="width:32%;">Itinerary</td>
                <td style="width:60%;">Details</td>
            </tr>
        </table>
        <hr style="margin-top:0px;">

        <?php if (empty($itinerary)) { ?>
            <table style="width:100%; font-size:11px; border-spacing:5px;">
                <tr><td style="text-align:center; padding:10px;">Itinerary details to be confirmed.</td></tr>
            </table>
        <?php } else {
            $this->load->helper('costing_itinerary');
            foreach ($itinerary as $day) {
                $meals = costing_meal_plan_labels(isset($day['meal_plan']) ? $day['meal_plan'] : ''); ?>
                <table style="width:100%; font-size:11px; border-spacing:5px;">
                    <tr style="vertical-align:baseline;">
                        <td style="width:8%;">Day <?php echo (int) $day['day_number']; ?></td>
                        <td style="width:32%;"><strong><?php echo html_escape(isset($day['title']) ? $day['title'] : ''); ?></strong></td>
                        <td style="width:60%;">
                            <div><?php echo isset($day['description']) ? $day['description'] : ''; ?></div>
                            <?php if (!empty($meals)) { ?>
                                <div style="margin-top:4px;"><strong style="color:#2b3a55;">Meal Plan:</strong> <?php echo html_escape(implode(', ', $meals)); ?></div>
                            <?php } ?>
                        </td>
                    </tr>
                </table>
            <?php } ?>
        <?php }

        // Itinerary-wide Notes — one merged block (Include / Exclude / Important
        // Notes / Terms & Conditions) shown once beneath the day-by-day plan. The
        // content carries its own section headings.
        $itin_meta = isset($itinerary_meta) ? $itinerary_meta : array();
        $itin_notes = isset($itin_meta['notes']) ? $itin_meta['notes'] : '';
        if (trim(strip_tags($itin_notes, '<img>')) !== '' || stripos($itin_notes, '<img') !== false) { ?>
            <table style="width:100%; font-size:11px; border-spacing:5px; margin-top:8px;">
                <tr><td><?php echo $itin_notes; ?></td></tr>
            </table>
        <?php } ?>
    </div>
</body></html>
