<?php
/**
 * Print-ready (DomPDF) rendering of a saved Competitor Product. Mirrors the
 * on-screen View (view.php + _product.php). Long sections use normal block flow
 * so DomPDF can split them across pages; tables are only used for short facts
 * and accommodation records. Handles both shapes:
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
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    * { box-sizing: border-box; }
    @page { margin: 38px 40px 36px; }
    body { margin: 0; font-family: <?php echo $pdf_font; ?>; color: #203247; font-size: 11px; line-height: 1.45; }
    <?php if (isset($lang) && $lang === 'cn') { ?>
    body { word-wrap: break-word; }
    <?php } ?>
    h1 { font-size: 21px; color: #142e45; margin: 0 0 2px 0; }
    h2 { font-size: 13px; color: #125b62; margin: 14px 0 6px; }
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
    table.facts td { width: 33.33%; background: #f8fafc; border: 3px solid #fff; padding: 6px 8px; vertical-align: top; }
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
    li { margin-bottom: 2px; page-break-inside: avoid; }
    .box { background: #F3F6F9; padding: 7px 9px; border-radius: 4px; margin-bottom: 6px; }
    .chapter { border-bottom: 1px solid #dce8eb; margin: 12px 0; padding-bottom: 6px; page-break-after: avoid; }
    .chapter span { color: #006c76; font-weight: bold; letter-spacing: 1px; font-size: 9px; }
    .analysis-chapter { page-break-before: always; }
    .chapter h2 { margin: 2px 0 0; font-size: 17px; color: #142e45; }
    .source-link { color: #006c76; }
    .price, .caution { background: #fffbf3; border-left: 3px solid #bd8133; }
    .positive { background: #f4faf6; border-left: 3px solid #198058; }
    .selling-point { background: #e8f4f4; border-left: 3px solid #087b82; }
    .ca-accommodation { width: 100%; table-layout: fixed; border-collapse: collapse; }
    .ca-accommodation th, .ca-accommodation td { padding: 5px 7px; border-bottom: 1px solid #dce8eb; text-align: left; vertical-align: top; }
    .ca-accommodation th { background: #edf6f6; color: #254b62; }
    .ca-accommodation th:first-child { width: 20%; }
    .ca-accommodation th:nth-child(2) { width: 25%; }
    .price strong, .selling-point { font-size: 13px; }
    .fit-card, .day-card { border-left: 3px solid #087b82; }
    .ranked-list { padding-left: 22px; }
    .ranked-list li { border-bottom: 1px solid #e0e8ed; padding: 4px; }
    .pre { white-space: pre-line; }
    .kv { margin-bottom: 4px; }
    .kv .k { font-size: 9px; color: #8A94A6; font-weight: bold; }
</style>
</head>
<body>

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
<?php if ($is_crawl) { ?>
    <div class="meta"><?php echo htmlspecialchars($lbl('site', 'Site:')) . ' ' . htmlspecialchars((string) $a->url); ?></div>
<?php } ?>

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
        <div class="prod-title"><?php echo ($idx + 1) . '. ' . htmlspecialchars($pname); ?></div>
    <?php } ?>
    <div class="meta">
        <?php echo htmlspecialchars(implode(' · ', array_filter(array($sv($p, 'destination'), $sv($p, 'duration'), $pcode), 'strlen'))); ?>
    </div>
    <?php $source_url = $is_crawl ? $sv($p, 'url') : (string) $a->url; ?>
    <?php if(preg_match('#^https?://#i', $source_url)) { ?>
        <div class="meta"><a class="source-link" href="<?php echo htmlspecialchars($source_url); ?>"><?php echo htmlspecialchars($lbl('view_original_tour', 'View original tour')); ?></a></div>
    <?php } elseif($source_url !== '') { ?>
        <div class="meta"><?php echo htmlspecialchars($lbl('source', 'Source:') . ' ' . $source_url); ?></div>
    <?php } ?>

    <div class="chapter"><span>01</span><h2><?php echo htmlspecialchars($lbl('section_tour_info', 'Tour Information')); ?></h2></div>
    <h2><?php echo htmlspecialchars($lbl('at_a_glance', 'At a Glance')); ?></h2>
    <table class="facts">
        <?php
        $facts = array(
            $lbl('tour_code', 'Tour Code')           => $sv($p, 'tour_code'),
            $lbl('destination', 'Destination')       => $sv($p, 'destination'),
            $lbl('duration', 'Duration')             => $sv($p, 'duration'),
            $lbl('departure_city', 'Departure City') => $sv($p, 'departure_city'),
            $lbl('currency', 'Currency')             => $sv($p, 'currency'),
            $lbl('difficulty', 'Difficulty')         => $sv($p, 'difficulty'),
            $lbl('suitable_age', 'Suitable Age')     => $sv($p, 'suitable_age'),
        );
        $fi = 0;
        foreach($facts as $label => $val) {
            if($fi % 3 === 0) { echo '<tr>'; }
            echo '<td><div class="lbl">' . $label . '</div><div class="val">' . htmlspecialchars($val !== '' ? $val : '-') . '</div></td>';
            if($fi % 3 === 2) { echo '</tr>'; }
            $fi++;
        }
        while($fi % 3 !== 0) { echo '<td></td>'; if($fi % 3 === 2) { echo '</tr>'; } $fi++; }
        ?>
    </table>

    <?php if($has($price_range)) { ?>
        <h2><?php echo htmlspecialchars($lbl('package_pricing', 'Package Pricing')); ?></h2>
        <div class="box price">
            <strong><?php echo htmlspecialchars($price_range); ?></strong>
            <?php if($has($sv($p, 'price')) && $sv($p, 'price') !== $price_range) { ?>
                <div><?php echo htmlspecialchars($sv($p, 'price')); ?></div>
            <?php } ?>
        </div>
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
    ) as $key => $heading) { if (!$has($av($p, $key))) { continue; } ?>
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
    <?php $hotel_entries = $flat($av($p, 'hotels')); if ($hotel_entries) { ?>
        <h2><?php echo htmlspecialchars($lbl('hotels', 'Accommodation')); ?></h2>
        <?php include __DIR__ . '/_accommodation.php'; ?>
    <?php } ?>
    <?php if($has($av($p, 'scenic_highlights'))) { ?>
        <div class="detail-section"><h4><?php echo htmlspecialchars($lbl('scenic_highlights', 'Scenic Highlights')); ?></h4><?php echo $scenic($av($p, 'scenic_highlights')); ?></div>
    <?php } ?>
    <?php if($has($av($p, 'shopping_stops'))) { ?>
        <div class="detail-section"><h4><?php echo htmlspecialchars($lbl('shopping_stops', 'Shopping Stops')); ?></h4><?php echo $justified($av($p, 'shopping_stops')); ?></div>
    <?php } ?>

    <!-- Inclusions / Exclusions / Optional -->
    <div class="detail-section"><h4><?php echo htmlspecialchars($lbl('inclusions', 'Inclusions')); ?></h4><div class="box positive"><?php echo $bullets($av($p, 'inclusions')); ?></div></div>
    <?php if($has($av($p, 'exclusions'))) { ?>
        <div class="detail-section"><h4><?php echo htmlspecialchars($lbl('exclusions', 'Exclusions')); ?></h4><div class="box caution"><?php echo $bullets($av($p, 'exclusions')); ?></div></div>
    <?php } ?>
    <?php if($has($av($p, 'optional_tours'))) { ?>
        <div class="detail-section"><h4><?php echo htmlspecialchars($lbl('optional_tours', 'Optional Tours')); ?></h4><?php echo $bullets($av($p, 'optional_tours')); ?></div>
    <?php } ?>

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
            <div class="box day-card">
                <div class="itinerary-title" style="font-weight:bold; color:#4F6D9E;"><?php echo htmlspecialchars($d_label); ?><?php if($d_title !== '') { echo ' &mdash; <span style="color:#3F4254;">' . htmlspecialchars($d_title) . '</span>'; } ?></div>
                <?php if($d_desc !== '') { ?><div class="pre" style="color:#5E6278;"><?php echo htmlspecialchars($d_desc); ?></div><?php } ?>
            </div>
        <?php } ?>
    <?php } ?>

    <div class="chapter analysis-chapter"><span>02</span><h2><?php echo htmlspecialchars($lbl('section_ai_analysis', 'AI Analysis')); ?></h2></div>
    <?php if($has($sv($p, 'main_usp'))) { ?>
        <h2><?php echo htmlspecialchars($lbl('main_usp', 'Main Selling Point')); ?></h2>
        <div class="box selling-point"><?php echo htmlspecialchars($sv($p, 'main_usp')); ?></div>
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
            <div class="box fit-card"><span class="k"><?php echo htmlspecialchars($seg); ?><?php echo $lvl !== '' ? ' (' . htmlspecialchars($lvl) . ')' : ''; ?>:</span> <?php echo htmlspecialchars($it['justification'] !== '' ? $it['justification'] : '-'); ?></div>
        <?php } ?>
    <?php } ?>

    <!-- Unique selling points -->
    <?php if($has($av($p, 'usp'))) { ?>
        <h2><?php echo htmlspecialchars($lbl('usp', 'Unique Selling Points')); ?></h2>
        <ol class="ranked-list">
        <?php foreach ($flat($av($p, 'usp')) as $item) { ?>
            <li><?php echo htmlspecialchars($item); ?></li>
        <?php } ?>
        </ol>
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
    <div class="detail-section"><h4 style="color:#12745e;"><?php echo htmlspecialchars($lbl('pros', 'Pros')); ?></h4><div class="box positive"><?php echo $justified($av($p, 'pros')); ?></div></div>
    <div class="detail-section"><h4 style="color:#925d22;"><?php echo htmlspecialchars($lbl('cons', 'Cons')); ?></h4><div class="box caution"><?php echo $justified($av($p, 'cons')); ?></div></div>


</div>
<?php } ?>

</body>
</html>
