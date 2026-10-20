<?php
/**
 * Print-ready (DomPDF) rendering of a saved Competitor Product. Mirrors the
 * on-screen View (view.php + _product.php). Long sections use normal block flow
 * so DomPDF can split them across pages; tables are only used for short facts
 * and the BC/TV company letterhead. Handles both shapes:
 *   - Site crawl ($a->products non-empty): one section per product.
 *   - Single (upload / single URL / paste): one product built from the row.
 * Expects $a (the analysis row) — the same object View() passes to the screen.
 */
$is_crawl = ! empty($a->products) && is_array($a->products);
$doc_title = $a->product_name ?: ($a->page_title ?: 'Competitor Product');

// Flat list of product arrays (crawl → stored products; single → one from the
// row) via the shared mapping used by the screen view + translator.
$products = competitor_display_products($a);
// Stable per-product code (same as the screen view + Excel). $ptotal drives the
// -N suffix for a combined report; a single product stays unsuffixed.
$ptotal   = count($products);
$feature  = isset($a->feature) ? $a->feature : 'competitor';

// Static UI labels + body font for the current language. $pdf_font carries a CJK
// family first when rendering Chinese so glyphs aren't dropped.
$labels   = (isset($labels) && is_array($labels)) ? $labels
    : (function_exists('competitor_ui_labels') ? competitor_ui_labels(isset($lang) ? $lang : 'en') : array());
$lbl      = function ($k, $fallback) use ($labels) { return isset($labels[$k]) ? $labels[$k] : $fallback; };
$pdf_font = isset($pdf_font) ? $pdf_font : '"DejaVu Sans", sans-serif';
$company = isset($company) && is_array($company) ? $company : array();
$company_address = isset($company_address) ? $company_address : (isset($company['Address']) ? $company['Address'] : '');
$logo_path = FCPATH . 'assets/image/pdflogo-new.jpeg';
$logo_src = is_file($logo_path) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($logo_path)) : '';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    * { box-sizing: border-box; }
    @page { margin: 165px 40px 35px 40px; }
    body { margin: 0; font-family: <?php echo $pdf_font; ?>; color: #2B2B3F; font-size: 12px; line-height: 1.45; }
    <?php if (isset($lang) && $lang === 'cn') { ?>
    body { word-wrap: break-word; }
    <?php } ?>
    .company-header { position: fixed; top: -145px; left: 0; right: 0; height: 135px; font-family: Helvetica, sans-serif; color: #000; line-height: 1.25; }
    .company-header table { width: 100%; border-collapse: collapse; }
    .company-header td { vertical-align: middle; }
    .company-header .company-name { font-size: 24px; font-weight: bold; margin: 0 0 5px; }
    .company-header p { margin: 0 0 2px; font-size: 11px; }
    .company-header hr { margin: 10px 0 0; }
    h1 { font-size: 20px; color: #4F6D9E; margin: 0 0 2px 0; }
    h2 { font-size: 15px; color: #3F4254; margin: 18px 0 6px 0; border-bottom: 1px solid #E4E6EF; padding-bottom: 4px; }
    h3 { font-size: 13px; color: #4F6D9E; margin: 12px 0 5px 0; }
    h4 { font-size: 12px; margin: 8px 0 4px 0; }
    h1, h2, h3, h4, .prod-title, .itinerary-title { page-break-after: avoid; }
    .muted { color: #8A94A6; }
    .meta { font-size: 10px; color: #8A94A6; margin-bottom: 4px; word-wrap: break-word; }
    .prod { padding-top: 6px; page-break-inside: auto; }
    .prod + .prod { border-top: 2px solid #D7E2F2; margin-top: 22px; padding-top: 14px; }
    .prod-title { font-size: 15px; color: #1B2A4A; font-weight: bold; margin-bottom: 8px; }
    /* table-layout:fixed makes columns honour their declared width so long CJK
       cell text wraps inside the column instead of expanding it past the page
       edge (DomPDF's default auto layout grows columns to fit content). */
    table.facts { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 10px; }
    table.facts td { width: 25%; background: #F3F6F9; border: 3px solid #fff; padding: 6px 8px; vertical-align: top; }
    table.facts .lbl { font-size: 9px; color: #8A94A6; font-weight: bold; text-transform: uppercase; }
    table.facts .val { font-size: 12px; font-weight: bold; color: #2B2B3F; }
    /* DomPDF cannot paginate a table row containing an entire long section. */
    .detail-section { margin-top: 12px; page-break-inside: auto; }
    /* Break long unspaced runs within the available page or cell width. */
    td, .val, .tags, .box, li { word-wrap: break-word; overflow-wrap: break-word; word-break: break-word; }
    /* Tag lists render as wrapping text — DomPDF clips wide inline-block "chips"
       inside table cells, so we avoid them and let words wrap naturally. */
    .tags { font-size: 11px; color: #3F4254; word-wrap: break-word; overflow-wrap: break-word; }
    .tags .t { font-weight: bold; }
    ul { margin: 2px 0 6px 0; padding-left: 16px; }
    li { margin-bottom: 2px; }
    .box { background: #F3F6F9; padding: 7px 9px; border-radius: 4px; margin-bottom: 6px; }
    .cmp { background: #FFF4DE; padding: 8px 10px; border-radius: 4px; white-space: pre-line; }
    .pre { white-space: pre-line; }
    .kv { margin-bottom: 4px; }
    .kv .k { font-size: 9px; color: #8A94A6; font-weight: bold; }
</style>
</head>
<body>

<div class="company-header">
    <table>
        <tr>
            <?php if ($logo_src !== '') { ?>
            <td style="width:25%;"><img src="<?php echo $logo_src; ?>" style="width:160px;"></td>
            <?php } ?>
            <td style="text-align:center;">
                <div class="company-name"><?php echo htmlspecialchars(isset($company['Name']) ? $company['Name'] : 'HolidayGoGoGo'); ?></div>
                <p>(Co. Reg. No. - <?php echo htmlspecialchars(isset($company['RegistrationNumber']) ? $company['RegistrationNumber'] : ''); ?> | Travel Agent License No. - <?php echo htmlspecialchars(isset($company['LicenseNumber']) ? $company['LicenseNumber'] : ''); ?>)</p>
                <p>Address : <?php echo htmlspecialchars($company_address); ?></p>
                <p>Website : <?php echo htmlspecialchars(isset($company['Website']) ? $company['Website'] : ''); ?></p>
            </td>
        </tr>
    </table>
    <hr>
</div>

<?php
$sv   = function ($P, $k) { $v = isset($P[$k]) ? $P[$k] : ''; return is_array($v) ? '' : trim((string) $v); };
$av   = function ($P, $k) { $v = isset($P[$k]) ? $P[$k] : array(); return is_array($v) ? $v : array(); };
$flat = function ($items) {
    if ( ! is_array($items)) { return array(); }
    $out = array();
    foreach ($items as $it) {
        if (is_array($it)) {
            $it = implode(' ', array_filter(array_map(function ($x) { return is_scalar($x) ? (string) $x : ''; }, $it), 'strlen'));
        }
        $it = trim((string) $it);
        if ($it !== '') { $out[] = $it; }
    }
    return $out;
};
$has = function ($v) use ($flat) {
    if (is_array($v)) { return count($flat($v)) > 0; }
    return trim((string) $v) !== '';
};
// A tag list as comma-separated wrapping text (bold items). Reliable in DomPDF —
// unlike inline-block "chips", which it clips when they exceed the cell width.
$chips = function ($items) use ($flat) {
    $items = $flat($items);
    if (empty($items)) { return '<span class="muted">-</span>'; }
    $out = array();
    foreach ($items as $it) { $out[] = '<span class="t">' . htmlspecialchars($it) . '</span>'; }
    return '<span class="tags">' . implode(', ', $out) . '</span>';
};
$bullets = function ($items) use ($flat) {
    $items = $flat($items);
    if (empty($items)) { return '<p class="muted">-</p>'; }
    $out = '<ul>';
    foreach ($items as $it) { $out .= '<li>' . htmlspecialchars($it) . '</li>'; }
    return $out . '</ul>';
};
// Scenic highlights: {name, description}. Bold the place name, show what the
// customer can expect during the visit beneath; plain bullet for legacy
// string-only highlights that carry no description.
$scenic = function ($items) {
    $items = function_exists('competitor_scenic_items') ? competitor_scenic_items($items) : array();
    if (empty($items)) { return '<p class="muted">-</p>'; }
    $out = '<ul>';
    foreach ($items as $it) {
        $name = isset($it['name']) ? $it['name'] : '';
        $desc = isset($it['description']) ? $it['description'] : '';
        if ($desc !== '') {
            $out .= '<li><b>' . htmlspecialchars($name) . '</b><br><span class="muted">'
                . htmlspecialchars($desc) . '</span></li>';
        } else {
            $out .= '<li>' . htmlspecialchars($name) . '</li>';
        }
    }
    return $out . '</ul>';
};
// Customer-POV pros/cons: "<point> — <justification>". Bold the point, show the
// reasoning beneath; plain bullet for legacy items with no justification.
$justified = function ($items) use ($flat) {
    $items = $flat($items);
    if (empty($items)) { return '<p class="muted">-</p>'; }
    $out = '<ul>';
    foreach ($items as $it) {
        $pj = competitor_split_point_justification($it);
        if ($pj['justification'] !== '') {
            $out .= '<li><b>' . htmlspecialchars($pj['point']) . '</b><br><span class="muted">'
                . htmlspecialchars($pj['justification']) . '</span></li>';
        } else {
            $out .= '<li>' . htmlspecialchars($it) . '</li>';
        }
    }
    return $out . '</ul>';
};
?>

<h1><?php echo htmlspecialchars($doc_title); ?></h1>
<div class="meta">
    <?php echo htmlspecialchars($is_crawl ? $lbl('site', 'Site:') : $lbl('source', 'Source:')) . ' '; ?><?php echo htmlspecialchars((string) $a->url); ?>
    <?php if($is_crawl) { echo ' &middot; ' . (int) $a->product_count . ' ' . htmlspecialchars($lbl('products', 'products')); } ?>
    &middot; <?php echo htmlspecialchars($lbl('analysed', 'Analysed')); ?> <?php echo date('d M Y H:i', strtotime($a->created_at)); ?>
    <?php if((float) $a->cost_usd > 0) { echo ' &middot; ' . htmlspecialchars($lbl('ai_cost', 'AI cost')) . ' USD ' . number_format((float) $a->cost_usd, 4); } ?>
</div>

<?php foreach($products as $idx => $p) {
    $pname = $sv($p, 'product_name') ?: ($lbl('product', 'Product') . ' ' . ($idx + 1));
    // Price range: prefer explicit from/to, fall back to the headline price.
    $price_range = '';
    if ($has($sv($p, 'price_from')) || $has($sv($p, 'price_to'))) {
        $from = $sv($p, 'price_from'); $to = $sv($p, 'price_to');
        if ($from !== '' && $to !== '' && $from !== $to) { $price_range = 'From ' . $from . ' to ' . $to; }
        else { $price_range = $from !== '' ? $from : $to; }
    }
    if ($price_range === '') { $price_range = $sv($p, 'price'); }
    $meals = $av($p, 'meals');
?>
<div class="prod">
    <?php $pcode = competitor_product_code((int) $a->id, $feature, $idx + 1, $ptotal); ?>
    <?php if($is_crawl) { ?>
        <div class="prod-title"><?php echo ($idx + 1) . '. ' . htmlspecialchars($pname); ?><?php if($pcode !== '') { ?> <span style="font-weight:normal; color:#6082B6;">(<?php echo htmlspecialchars($pcode); ?>)</span><?php } ?></div>
        <?php if(preg_match('#^https?://#i', $sv($p, 'url'))) { ?>
            <div class="meta"><?php echo htmlspecialchars($lbl('source', 'Source:')); ?> <?php echo htmlspecialchars($sv($p, 'url')); ?></div>
        <?php } ?>
    <?php } elseif($pcode !== '') { ?>
        <div class="meta"><?php echo htmlspecialchars($lbl('product', 'Product')); ?> <?php echo htmlspecialchars($pcode); ?></div>
    <?php } ?>

    <!-- Headline facts -->
    <table class="facts">
        <?php
        $facts = array(
            $lbl('tour_code', 'Tour Code')           => $sv($p, 'tour_code'),
            $lbl('destination', 'Destination')       => $sv($p, 'destination'),
            $lbl('duration', 'Duration')             => $sv($p, 'duration'),
            $lbl('departure_city', 'Departure City') => $sv($p, 'departure_city'),
            $lbl('price_range', 'Price Range')       => $price_range,
            $lbl('currency', 'Currency')             => $sv($p, 'currency'),
            $lbl('difficulty', 'Difficulty')         => $sv($p, 'difficulty'),
            $lbl('suitable_age', 'Suitable Age')     => $sv($p, 'suitable_age'),
        );
        $fi = 0;
        foreach($facts as $label => $val) {
            if($fi % 4 === 0) { echo '<tr>'; }
            echo '<td><div class="lbl">' . $label . '</div><div class="val">' . htmlspecialchars($val !== '' ? $val : '-') . '</div></td>';
            if($fi % 4 === 3) { echo '</tr>'; }
            $fi++;
        }
        while($fi % 4 !== 0) { echo '<td></td>'; if($fi % 4 === 3) { echo '</tr>'; } $fi++; }
        ?>
    </table>

    <?php if($has($sv($p, 'main_usp'))) { ?>
        <h2><?php echo htmlspecialchars($lbl('main_usp', 'Main Selling Point')); ?></h2>
        <div class="box"><?php echo htmlspecialchars($sv($p, 'main_usp')); ?></div>
    <?php } ?>

    <!-- Traveller fit -->
    <?php if($has($sv($p, 'target_traveller')) || $has($sv($p, 'child_friendly')) || $has($sv($p, 'senior_friendly'))) { ?>
        <h2><?php echo htmlspecialchars($lbl('traveller_fit', 'Traveller Fit')); ?></h2>
        <?php
        $fit = array($lbl('target_traveller', 'Target Traveller') => $sv($p, 'target_traveller'), $lbl('child_friendly', 'Child Friendly') => $sv($p, 'child_friendly'), $lbl('senior_friendly', 'Senior Friendly') => $sv($p, 'senior_friendly'));
        foreach($fit as $label => $val) { if(!$has($val)) { continue; } ?>
            <div class="kv"><span class="k"><?php echo htmlspecialchars($label); ?>:</span> <?php echo htmlspecialchars($val); ?></div>
        <?php } ?>
    <?php } ?>

    <!-- Suitability by traveller type -->
    <?php $tsegs = function_exists('competitor_traveller_segments') ? competitor_traveller_segments($av($p, 'traveller_segments')) : array(); if( ! empty($tsegs)) { ?>
        <h2><?php echo htmlspecialchars($lbl('traveller_suitability', 'Suitability by Traveller Type')); ?></h2>
        <?php foreach($tsegs as $it) {
            $seg = $lbl('seg_' . $it['key'], $it['segment']);
            $lvl = $it['level'] !== '' ? $lbl('suit_' . $it['level'], ucfirst($it['level'])) : $it['suitability'];
        ?>
            <div class="kv"><span class="k"><?php echo htmlspecialchars($seg); ?><?php echo $lvl !== '' ? ' (' . htmlspecialchars($lvl) . ')' : ''; ?>:</span> <?php echo htmlspecialchars($it['justification'] !== '' ? $it['justification'] : '-'); ?></div>
        <?php } ?>
    <?php } ?>

    <!-- Unique selling points -->
    <?php if($has($av($p, 'usp'))) { ?>
        <h2><?php echo htmlspecialchars($lbl('usp', 'Unique Selling Points')); ?></h2>
        <?php echo $bullets($av($p, 'usp')); ?>
    <?php } ?>

    <!-- Coverage -->
    <h2><?php echo htmlspecialchars($lbl('coverage', 'Coverage')); ?></h2>
    <?php foreach (array(
        'countries' => array('countries', 'Countries'),
        'cities' => array('cities', 'Cities'),
        'travel_months' => array('travel_months', 'Travel Months'),
        'themes' => array('themes', 'Themes'),
        'tour_styles' => array('tour_style', 'Tour Style'),
        'local_transport' => array('local_transport', 'Local Transport'),
    ) as $key => $heading) { ?>
        <div class="detail-section">
            <h4><?php echo htmlspecialchars($lbl($heading[0], $heading[1])); ?></h4>
            <?php echo $key === 'local_transport' ? $bullets($av($p, $key)) : $chips($av($p, $key)); ?>
        </div>
    <?php } ?>

    <?php if($has($av($p, 'destination_details'))) { ?>
        <h2><?php echo htmlspecialchars($lbl('destination_details', 'Destination Details')); ?></h2>
        <?php echo $bullets($av($p, 'destination_details')); ?>
    <?php } ?>

    <!-- Flights & Meals (no section banner — the web view groups these by column
         header only, so the PDF follows suit). Flight block shows only when the
         analysis has flight data, exactly like the web. -->
    <?php if($has($sv($p, 'flight_departure')) || $has($sv($p, 'flight_return'))) { ?>
        <div class="detail-section">
            <h4><?php echo htmlspecialchars($lbl('flight_details', 'Flight Details')); ?></h4>
            <div class="box">
                <div class="kv"><span class="k"><?php echo htmlspecialchars($lbl('departure', 'Departure')); ?>:</span> <?php echo htmlspecialchars($sv($p, 'flight_departure') !== '' ? $sv($p, 'flight_departure') : '-'); ?></div>
                <div class="kv"><span class="k"><?php echo htmlspecialchars($lbl('return', 'Return')); ?>:</span> <?php echo htmlspecialchars($sv($p, 'flight_return') !== '' ? $sv($p, 'flight_return') : '-'); ?></div>
            </div>
        </div>
    <?php } ?>
    <div class="detail-section">
        <h4><?php echo htmlspecialchars($lbl('meals', 'Meals')); ?></h4>
        <div class="box">
            <?php
            $meal_labels = array('breakfast' => $lbl('breakfast', 'Breakfast'), 'lunch' => $lbl('lunch', 'Lunch'), 'dinner' => $lbl('dinner', 'Dinner'));
            foreach($meal_labels as $key => $label) {
                $mv = trim((string) (isset($meals[$key]) ? $meals[$key] : ''));
                echo '<div class="kv"><span class="k">' . htmlspecialchars($label) . ':</span> ' . htmlspecialchars($mv !== '' ? $mv : '-') . '</div>';
            }
            ?>
        </div>
    </div>

    <?php if($has($av($p, 'food_beverage'))) { ?>
        <h2><?php echo htmlspecialchars($lbl('food_beverage', 'Food & Beverages')); ?></h2>
        <?php echo $bullets($av($p, 'food_beverage')); ?>
    <?php } ?>

    <!-- Stay & Highlights -->
    <div class="detail-section"><h4><?php echo htmlspecialchars($lbl('hotels', 'Hotels')); ?></h4><?php echo $bullets($av($p, 'hotels')); ?></div>
    <div class="detail-section"><h4><?php echo htmlspecialchars($lbl('scenic_highlights', 'Scenic Highlights')); ?></h4><?php echo $scenic($av($p, 'scenic_highlights')); ?></div>
    <div class="detail-section"><h4><?php echo htmlspecialchars($lbl('shopping_stops', 'Shopping Stops')); ?></h4><?php echo $justified($av($p, 'shopping_stops')); ?></div>

    <!-- Inclusions / Exclusions / Optional -->
    <div class="detail-section"><h4><?php echo htmlspecialchars($lbl('inclusions', 'Inclusions')); ?></h4><?php echo $bullets($av($p, 'inclusions')); ?></div>
    <div class="detail-section"><h4><?php echo htmlspecialchars($lbl('exclusions', 'Exclusions')); ?></h4><?php echo $bullets($av($p, 'exclusions')); ?></div>
    <div class="detail-section"><h4><?php echo htmlspecialchars($lbl('optional_tours', 'Optional Tours')); ?></h4><?php echo $bullets($av($p, 'optional_tours')); ?></div>

    <?php if($has($av($p, 'special_remarks'))) { ?>
        <h3><?php echo htmlspecialchars($lbl('special_remarks', 'Special Remarks')); ?></h3>
        <?php echo $bullets($av($p, 'special_remarks')); ?>
    <?php } ?>

    <!-- Daily itinerary -->
    <?php if($has($av($p, 'itinerary'))) { ?>
        <h2><?php echo htmlspecialchars($lbl('daily_itinerary', 'Daily Itinerary')); ?></h2>
        <?php foreach($av($p, 'itinerary') as $day) {
            $day = (array) $day;
            $d_label = trim((string) (isset($day['day']) ? $day['day'] : ''));
            $d_title = trim((string) (isset($day['title']) ? $day['title'] : ''));
            $d_desc  = trim((string) (isset($day['description']) ? $day['description'] : ''));
        ?>
            <div style="margin-bottom:7px;">
                <div class="itinerary-title" style="font-weight:bold; color:#4F6D9E;"><?php echo htmlspecialchars($d_label); ?><?php if($d_title !== '') { echo ' &mdash; <span style="color:#3F4254;">' . htmlspecialchars($d_title) . '</span>'; } ?></div>
                <?php if($d_desc !== '') { ?><div class="pre" style="color:#5E6278;"><?php echo htmlspecialchars($d_desc); ?></div><?php } ?>
            </div>
        <?php } ?>
    <?php } ?>

    <?php $pdf_sum = function_exists('competitor_summary_sections') ? competitor_summary_sections($sv($p, 'summary')) : array(); ?>
    <?php if( ! empty($pdf_sum)) { ?>
        <h2><?php echo htmlspecialchars($lbl('summary', 'Summary')); ?></h2>
        <?php foreach($pdf_sum as $sec) { ?>
            <div style="margin-bottom:6px;">
                <?php if($sec['label'] !== '') { ?><span style="font-weight:bold; color:#4F6D9E;"><?php echo htmlspecialchars($sec['label']); ?>:</span> <?php } ?>
                <span class="pre"><?php echo htmlspecialchars($sec['text']); ?></span>
            </div>
        <?php } ?>
    <?php } ?>

    <?php if($has($av($p, 'customer_advice'))) { ?>
        <h2><?php echo htmlspecialchars($lbl('customer_advice', 'Advice for Your Trip')); ?></h2>
        <?php echo $bullets($av($p, 'customer_advice')); ?>
    <?php } ?>

    <!-- Pros / Cons -->
    <div class="detail-section"><h4 style="color:#1BC5BD;"><?php echo htmlspecialchars($lbl('pros', 'Pros')); ?></h4><?php echo $justified($av($p, 'pros')); ?></div>
    <div class="detail-section"><h4 style="color:#F64E60;"><?php echo htmlspecialchars($lbl('cons', 'Cons')); ?></h4><?php echo $justified($av($p, 'cons')); ?></div>

    <?php if($has($sv($p, 'comparison'))) { ?>
        <h2><?php echo htmlspecialchars($lbl('comparison', 'Comparison vs Our Products')); ?></h2>
        <?php if($has($sv($p, 'matched_product'))) { ?>
            <div class="kv"><span class="muted"><?php echo htmlspecialchars($lbl('compared_against', 'Compared against our product:')); ?></span> <strong><?php echo htmlspecialchars($sv($p, 'matched_product')); ?></strong></div>
        <?php } ?>
        <div class="cmp"><?php echo htmlspecialchars($sv($p, 'comparison')); ?></div>
    <?php } ?>
</div>
<?php } ?>

</body>
</html>
