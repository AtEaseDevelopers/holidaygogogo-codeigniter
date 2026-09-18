<?php
/**
 * Print-ready (DomPDF) rendering of a saved Competitor Product. Mirrors the
 * on-screen View (view.php + _product.php) but uses a table/inline-block layout
 * DomPDF can render (no flexbox / Bootstrap grid). Handles both shapes:
 *   - Site crawl ($a->products non-empty): one section per product.
 *   - Single (upload / single URL / paste): one product built from the row.
 * Expects $a (the analysis row) — the same object View() passes to the screen.
 */
$is_crawl = ! empty($a->products) && is_array($a->products);
$doc_title = $a->product_name ?: ($a->page_title ?: 'Competitor Product');

// Flat list of product arrays (crawl → stored products; single → one from the
// row) via the shared mapping used by the screen view + translator.
$products = competitor_display_products($a);

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
    body { font-family: <?php echo $pdf_font; ?>; color: #2B2B3F; font-size: 12px; line-height: 1.45; }
    h1 { font-size: 20px; color: #4F6D9E; margin: 0 0 2px 0; }
    h2 { font-size: 15px; color: #3F4254; margin: 18px 0 6px 0; border-bottom: 1px solid #E4E6EF; padding-bottom: 4px; }
    h3 { font-size: 13px; color: #4F6D9E; margin: 12px 0 5px 0; }
    h4 { font-size: 12px; margin: 8px 0 4px 0; }
    .muted { color: #8A94A6; }
    .meta { font-size: 10px; color: #8A94A6; margin-bottom: 4px; }
    .prod { padding-top: 6px; page-break-inside: auto; }
    .prod + .prod { border-top: 2px solid #D7E2F2; margin-top: 22px; padding-top: 14px; }
    .prod-title { font-size: 15px; color: #1B2A4A; font-weight: bold; margin-bottom: 8px; }
    /* table-layout:fixed makes columns honour their declared width so long CJK
       cell text wraps inside the column instead of expanding it past the page
       edge (DomPDF's default auto layout grows columns to fit content). */
    table.facts, table.two, table.three { width: 100%; border-collapse: collapse; table-layout: fixed; }
    table.facts { margin-bottom: 10px; }
    table.facts td { width: 25%; background: #F3F6F9; border: 3px solid #fff; padding: 6px 8px; vertical-align: top; }
    table.facts .lbl { font-size: 9px; color: #8A94A6; font-weight: bold; text-transform: uppercase; }
    table.facts .val { font-size: 12px; font-weight: bold; color: #2B2B3F; }
    table.group { margin-top: 12px; }   /* separates banner-less column groups (web has no header here) */
    table.two td { width: 50%; vertical-align: top; padding: 0 8px 0 0; }
    table.three td { width: 33.33%; vertical-align: top; padding: 0 8px 0 0; }
    /* Break long unspaced runs so nothing overflows a fixed-width cell. */
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
    <?php if($is_crawl) { ?>
        <div class="prod-title"><?php echo ($idx + 1) . '. ' . htmlspecialchars($pname); ?></div>
        <?php if(preg_match('#^https?://#i', $sv($p, 'url'))) { ?>
            <div class="meta"><?php echo htmlspecialchars($lbl('source', 'Source:')); ?> <?php echo htmlspecialchars($sv($p, 'url')); ?></div>
        <?php } ?>
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
    <table class="two">
        <tr>
            <td><h4><?php echo htmlspecialchars($lbl('countries', 'Countries')); ?></h4><?php echo $chips($av($p, 'countries')); ?></td>
            <td><h4><?php echo htmlspecialchars($lbl('cities', 'Cities')); ?></h4><?php echo $chips($av($p, 'cities')); ?></td>
        </tr>
        <tr>
            <td><h4><?php echo htmlspecialchars($lbl('travel_months', 'Travel Months')); ?></h4><?php echo $chips($av($p, 'travel_months')); ?></td>
            <td><h4><?php echo htmlspecialchars($lbl('themes', 'Themes')); ?></h4><?php echo $chips($av($p, 'themes')); ?></td>
        </tr>
        <tr>
            <td><h4><?php echo htmlspecialchars($lbl('tour_style', 'Tour Style')); ?></h4><?php echo $chips($av($p, 'tour_styles')); ?></td>
            <td><h4><?php echo htmlspecialchars($lbl('local_transport', 'Local Transport')); ?></h4><?php echo $chips($av($p, 'local_transport')); ?></td>
        </tr>
    </table>

    <!-- Flights & Meals (no section banner — the web view groups these by column
         header only, so the PDF follows suit). Flight block shows only when the
         analysis has flight data, exactly like the web. -->
    <table class="two group">
        <tr>
            <?php if($has($sv($p, 'flight_departure')) || $has($sv($p, 'flight_return'))) { ?>
            <td>
                <h4><?php echo htmlspecialchars($lbl('flight_details', 'Flight Details')); ?></h4>
                <div class="box">
                    <div class="kv"><span class="k"><?php echo htmlspecialchars($lbl('departure', 'Departure')); ?>:</span> <?php echo htmlspecialchars($sv($p, 'flight_departure') !== '' ? $sv($p, 'flight_departure') : '-'); ?></div>
                    <div class="kv"><span class="k"><?php echo htmlspecialchars($lbl('return', 'Return')); ?>:</span> <?php echo htmlspecialchars($sv($p, 'flight_return') !== '' ? $sv($p, 'flight_return') : '-'); ?></div>
                </div>
            </td>
            <?php } ?>
            <td>
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
            </td>
        </tr>
    </table>

    <!-- Stay & Highlights -->
    <table class="three group">
        <tr>
            <td><h4><?php echo htmlspecialchars($lbl('hotels', 'Hotels')); ?></h4><?php echo $bullets($av($p, 'hotels')); ?></td>
            <td><h4><?php echo htmlspecialchars($lbl('scenic_highlights', 'Scenic Highlights')); ?></h4><?php echo $scenic($av($p, 'scenic_highlights')); ?></td>
            <td><h4><?php echo htmlspecialchars($lbl('shopping_stops', 'Shopping Stops')); ?></h4><?php echo $justified($av($p, 'shopping_stops')); ?></td>
        </tr>
    </table>

    <!-- Inclusions / Exclusions / Optional -->
    <table class="three group">
        <tr>
            <td><h4><?php echo htmlspecialchars($lbl('inclusions', 'Inclusions')); ?></h4><?php echo $bullets($av($p, 'inclusions')); ?></td>
            <td><h4><?php echo htmlspecialchars($lbl('exclusions', 'Exclusions')); ?></h4><?php echo $bullets($av($p, 'exclusions')); ?></td>
            <td><h4><?php echo htmlspecialchars($lbl('optional_tours', 'Optional Tours')); ?></h4><?php echo $bullets($av($p, 'optional_tours')); ?></td>
        </tr>
    </table>

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
                <div style="font-weight:bold; color:#4F6D9E;"><?php echo htmlspecialchars($d_label); ?><?php if($d_title !== '') { echo ' &mdash; <span style="color:#3F4254;">' . htmlspecialchars($d_title) . '</span>'; } ?></div>
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

    <!-- Pros / Cons -->
    <table class="two group">
        <tr>
            <td><h4 style="color:#1BC5BD;"><?php echo htmlspecialchars($lbl('pros', 'Pros')); ?></h4><?php echo $justified($av($p, 'pros')); ?></td>
            <td><h4 style="color:#F64E60;"><?php echo htmlspecialchars($lbl('cons', 'Cons')); ?></h4><?php echo $justified($av($p, 'cons')); ?></td>
        </tr>
    </table>

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
