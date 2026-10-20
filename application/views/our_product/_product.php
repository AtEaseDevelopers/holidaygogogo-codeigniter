<?php
/**
 * One competitor product's full profile. Shared by the single-analysis view and
 * each product inside a combined site-crawl report. Expects $p = an associative
 * array of the canonical fields (from competitor_parse_ai_response + details).
 * Set $show_product_source = true to print the product's own source URL at top
 * (used inside the crawl accordion, where each product has a distinct URL).
 */
$P  = (array) $p;
// Static UI labels for the current language (English by default). Content values
// are already translated on $p by the controller; these cover the fixed labels.
$L  = (isset($labels) && is_array($labels)) ? $labels
    : (function_exists('competitor_ui_labels') ? competitor_ui_labels('en') : array());
$lbl = function ($k, $fallback) use ($L) { return isset($L[$k]) ? $L[$k] : $fallback; };
$sv = function ($k) use ($P) { $v = isset($P[$k]) ? $P[$k] : ''; return is_array($v) ? '' : trim((string) $v); };
$av = function ($k) use ($P) { $v = isset($P[$k]) ? $P[$k] : array(); return is_array($v) ? $v : array(); };
// Flatten a list into clean non-empty strings — tolerant of nested-array items
// (some AI replies put objects/arrays inside a list), so strval never warns.
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
$chips = function ($items, $bg = '#EEF2F7', $fg = '#3F4254') use ($flat) {
    $items = $flat($items);
    if (empty($items)) { echo '<span class="text-muted" style="font-size:13px;">-</span>'; return; }
    foreach ($items as $it) {
        echo '<span class="label label-inline font-weight-bold mr-2 mb-2" style="font-size:12px; padding:12px 12px; background:'
            . $bg . '; color:' . $fg . ';">' . htmlspecialchars($it) . '</span>';
    }
};
$bullets = function ($items) use ($flat) {
    $items = $flat($items);
    if (empty($items)) { echo '<p class="text-muted" style="font-size:13px;">-</p>'; return; }
    echo '<ul style="font-size:14px; padding-left:18px; margin-bottom:0;">';
    foreach ($items as $it) { echo '<li class="mb-1">' . htmlspecialchars($it) . '</li>'; }
    echo '</ul>';
};
// Customer-POV pros/cons: each item is "<point> — <justification>". Weight the
// point in bold and show the reasoning beneath it so the customer benefit reads
// clearly; falls back to a plain bullet for legacy items with no justification.
$justified = function ($items) use ($flat) {
    $items = $flat($items);
    if (empty($items)) { echo '<p class="text-muted" style="font-size:13px;">-</p>'; return; }
    echo '<ul style="font-size:14px; padding-left:18px; margin-bottom:0;">';
    foreach ($items as $it) {
        $pj = competitor_split_point_justification($it);
        if ($pj['justification'] !== '') {
            echo '<li class="mb-2"><span class="font-weight-bolder">' . htmlspecialchars($pj['point'])
                . '</span><br><span style="font-size:13px; color:#5E6278;">' . htmlspecialchars($pj['justification']) . '</span></li>';
        } else {
            echo '<li class="mb-2">' . htmlspecialchars($it) . '</li>';
        }
    }
    echo '</ul>';
};
// Scenic highlights: each is {name, description}. Weight the place name and show
// what the customer can expect during the visit beneath it; degrades to a plain
// bullet for legacy string-only highlights that carry no description.
$scenic = function ($items) {
    $items = function_exists('competitor_scenic_items') ? competitor_scenic_items($items) : array();
    if (empty($items)) { echo '<p class="text-muted" style="font-size:13px;">-</p>'; return; }
    echo '<ul style="font-size:14px; padding-left:18px; margin-bottom:0;">';
    foreach ($items as $it) {
        $name = isset($it['name']) ? $it['name'] : '';
        $desc = isset($it['description']) ? $it['description'] : '';
        if ($desc !== '') {
            echo '<li class="mb-2"><span class="font-weight-bolder">' . htmlspecialchars($name)
                . '</span><br><span style="font-size:13px; color:#5E6278;">' . htmlspecialchars($desc) . '</span></li>';
        } else {
            echo '<li class="mb-2">' . htmlspecialchars($name) . '</li>';
        }
    }
    echo '</ul>';
};
// Traveller-type suitability: one card per canonical segment, a colour-coded level
// badge (green/amber/red) and a grounded justification beneath. `level` is
// language-independent so the colour holds after a Chinese translation. Renders
// nothing for legacy rows that carry no verdicts.
$segments = function ($items) use ($lbl) {
    $items = function_exists('competitor_traveller_segments') ? competitor_traveller_segments($items) : array();
    if (empty($items)) { echo '<p class="text-muted" style="font-size:13px;">-</p>'; return; }
    $colors = array(
        'high'   => array('#E1F7E7', '#0BB783'),
        'medium' => array('#FFF4DE', '#FFA800'),
        'low'    => array('#FFE2E5', '#F64E60'),
    );
    echo '<div class="row mb-5">';
    foreach ($items as $it) {
        $key   = isset($it['key']) ? $it['key'] : '';
        $seg   = $lbl('seg_' . $key, isset($it['segment']) ? $it['segment'] : $key);
        $level = isset($it['level']) ? $it['level'] : '';
        $just  = isset($it['justification']) ? $it['justification'] : '';
        $badge = '';
        if ($level !== '' && isset($colors[$level])) {
            $badge = '<span class="label label-inline font-weight-bold ml-2" style="font-size:11px; padding:9px 10px; background:'
                . $colors[$level][0] . '; color:' . $colors[$level][1] . ';">' . htmlspecialchars($lbl('suit_' . $level, ucfirst($level))) . '</span>';
        } elseif (isset($it['suitability']) && trim((string) $it['suitability']) !== '') {
            $badge = '<span class="label label-inline font-weight-bold ml-2" style="font-size:11px; padding:9px 10px; background:#EEF2F7; color:#3F4254;">' . htmlspecialchars($it['suitability']) . '</span>';
        }
        echo '<div class="col-md-6 mb-4"><div class="p-4 rounded" style="background:#F3F6F9; height:100%;">';
        echo '<div class="d-flex align-items-center mb-2"><span class="font-weight-bolder text-dark" style="font-size:14px;">' . htmlspecialchars($seg) . '</span>' . $badge . '</div>';
        echo '<div class="text-dark" style="font-size:13px; line-height:1.5;">' . htmlspecialchars($just !== '' ? $just : '-') . '</div>';
        echo '</div></div>';
    }
    echo '</div>';
};
// Price range: prefer explicit from/to, fall back to the headline price string.
$price_range = '';
if ($has($sv('price_from')) || $has($sv('price_to'))) {
    $from = $sv('price_from'); $to = $sv('price_to');
    if ($from !== '' && $to !== '' && $from !== $to) { $price_range = 'From ' . $from . ' to ' . $to; }
    else { $price_range = $from !== '' ? $from : $to; }
}
if ($price_range === '') { $price_range = $sv('price'); }
$meals = $av('meals');
// Unique per-include ids so the Tour Info / AI Analysis tabs stay independent when
// this partial is repeated (one block per product inside a crawl report).
if ( ! isset($GLOBALS['ca_tab_uid'])) { $GLOBALS['ca_tab_uid'] = 0; }
$GLOBALS['ca_tab_uid']++;
$tab_info = 'ca_tab_info_' . $GLOBALS['ca_tab_uid'];
$tab_ai   = 'ca_tab_ai_' . $GLOBALS['ca_tab_uid'];
?>

<?php if(!empty($show_product_source) && preg_match('#^https?://#i', $sv('url'))) { ?>
    <div class="mb-4">
        <span class="text-muted font-weight-bold mr-2" style="font-size:12px;">Source:</span>
        <a href="<?php echo htmlspecialchars($sv('url')); ?>" target="_blank" rel="noopener" style="font-size:12px;"><?php echo htmlspecialchars($sv('url')); ?></a>
    </div>
<?php } ?>

<!-- ===================== TABS: Tour Information | AI Analysis ===================== -->
<ul class="nav nav-tabs nav-tabs-line mb-5" role="tablist">
    <li class="nav-item">
        <a class="nav-link active font-weight-bold" data-toggle="tab" href="#<?php echo $tab_info; ?>" role="tab" aria-selected="true">
            <span class="nav-icon mr-2"><i class="la la-list-alt" style="color:#6082B6;"></i></span>
            <span class="nav-text"><?php echo htmlspecialchars($lbl('section_tour_info', 'Tour Information')); ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link font-weight-bold" data-toggle="tab" href="#<?php echo $tab_ai; ?>" role="tab" aria-selected="false">
            <span class="nav-icon mr-2"><i class="la la-robot" style="color:#8950FC;"></i></span>
            <span class="nav-text"><?php echo htmlspecialchars($lbl('section_ai_analysis', 'AI Analysis')); ?></span>
        </a>
    </li>
</ul>

<div class="tab-content">

<!-- ===================== TAB: Tour Information (extracted facts) ===================== -->
<div class="tab-pane fade show active" id="<?php echo $tab_info; ?>" role="tabpanel">

<!-- Headline facts -->
<div class="row mb-3">
    <?php
    $facts = array(
        $lbl('tour_code', 'Tour Code')           => $sv('tour_code'),
        $lbl('destination', 'Destination')       => $sv('destination'),
        $lbl('duration', 'Duration')             => $sv('duration'),
        $lbl('departure_city', 'Departure City') => $sv('departure_city'),
        $lbl('price_range', 'Price Range')       => $price_range,
        $lbl('currency', 'Currency')             => $sv('currency'),
        $lbl('difficulty', 'Difficulty')         => $sv('difficulty'),
        $lbl('suitable_age', 'Suitable Age')     => $sv('suitable_age'),
    );
    foreach($facts as $label => $val) { ?>
        <div class="col-md-3 col-sm-6 mb-4">
            <div class="p-4 rounded" style="background:#F3F6F9; height:100%;">
                <div class="text-muted font-weight-bold" style="font-size:12px;"><?php echo $label; ?></div>
                <div class="font-weight-bolder mt-1" style="font-size:15px;"><?php echo htmlspecialchars($val !== '' ? $val : '-'); ?></div>
            </div>
        </div>
    <?php } ?>
</div>

<!-- Coverage -->
<h5 class="font-weight-bolder text-dark mt-3 mb-3"><i class="la la-globe mr-1" style="color:#6082B6;"></i><?php echo htmlspecialchars($lbl('coverage', 'Coverage')); ?></h5>
<div class="row mb-4">
    <div class="col-md-6 mb-4">
        <div class="text-muted font-weight-bold mb-2" style="font-size:13px;"><?php echo htmlspecialchars($lbl('countries', 'Countries')); ?></div>
        <?php $chips($av('countries'), '#E1F0FF', '#0073E9'); ?>
    </div>
    <div class="col-md-6 mb-4">
        <div class="text-muted font-weight-bold mb-2" style="font-size:13px;"><?php echo htmlspecialchars($lbl('cities', 'Cities')); ?></div>
        <?php $chips($av('cities')); ?>
    </div>
    <div class="col-md-6 mb-4">
        <div class="text-muted font-weight-bold mb-2" style="font-size:13px;"><?php echo htmlspecialchars($lbl('travel_months', 'Travel Months')); ?></div>
        <?php $chips($av('travel_months'), '#FFF4DE', '#FFA800'); ?>
    </div>
    <div class="col-md-6 mb-4">
        <div class="text-muted font-weight-bold mb-2" style="font-size:13px;"><?php echo htmlspecialchars($lbl('themes', 'Themes')); ?></div>
        <?php $chips($av('themes'), '#EEE5FF', '#8950FC'); ?>
    </div>
    <div class="col-md-6 mb-4">
        <div class="text-muted font-weight-bold mb-2" style="font-size:13px;"><?php echo htmlspecialchars($lbl('tour_style', 'Tour Style')); ?></div>
        <?php $chips($av('tour_styles'), '#C9F7F5', '#1BC5BD'); ?>
    </div>
    <div class="col-md-6 mb-4">
        <div class="text-muted font-weight-bold mb-2" style="font-size:13px;"><?php echo htmlspecialchars($lbl('local_transport', 'Local Transport')); ?></div>
        <?php $bullets($av('local_transport')); ?>
    </div>
</div>

<?php if($has($av('destination_details'))) { ?>
    <div class="mb-5">
        <h5 class="font-weight-bolder text-dark"><?php echo htmlspecialchars($lbl('destination_details', 'Destination Details')); ?></h5>
        <?php $bullets($av('destination_details')); ?>
    </div>
<?php } ?>

<!-- Flights & Meals -->
<div class="row mb-2">
    <?php if($has($sv('flight_departure')) || $has($sv('flight_return'))) { ?>
        <div class="col-md-6 mb-4">
            <h6 class="font-weight-bolder text-dark"><i class="la la-plane mr-1" style="color:#6082B6;"></i><?php echo htmlspecialchars($lbl('flight_details', 'Flight Details')); ?></h6>
            <div class="p-4 rounded" style="background:#F3F6F9;">
                <div class="mb-2"><span class="text-muted font-weight-bold" style="font-size:12px;"><?php echo htmlspecialchars($lbl('departure', 'Departure')); ?>:</span>
                    <div style="font-size:14px;"><?php echo htmlspecialchars($sv('flight_departure') !== '' ? $sv('flight_departure') : '-'); ?></div></div>
                <div><span class="text-muted font-weight-bold" style="font-size:12px;"><?php echo htmlspecialchars($lbl('return', 'Return')); ?>:</span>
                    <div style="font-size:14px;"><?php echo htmlspecialchars($sv('flight_return') !== '' ? $sv('flight_return') : '-'); ?></div></div>
            </div>
        </div>
    <?php } ?>
    <div class="col-md-6 mb-4">
        <h6 class="font-weight-bolder text-dark"><i class="la la-utensils mr-1" style="color:#6082B6;"></i><?php echo htmlspecialchars($lbl('meals', 'Meals')); ?></h6>
        <div class="p-4 rounded d-flex" style="background:#F3F6F9;">
            <?php
            $meal_labels = array('breakfast' => $lbl('breakfast', 'Breakfast'), 'lunch' => $lbl('lunch', 'Lunch'), 'dinner' => $lbl('dinner', 'Dinner'));
            foreach($meal_labels as $key => $label) { ?>
                <div class="text-center mr-5">
                    <div class="text-muted font-weight-bold" style="font-size:12px;"><?php echo $label; ?></div>
                    <div class="font-weight-bolder mt-1" style="font-size:18px; color:#6082B6;"><?php echo htmlspecialchars(trim((string) (isset($meals[$key]) ? $meals[$key] : '')) !== '' ? $meals[$key] : '-'); ?></div>
                </div>
            <?php } ?>
        </div>
    </div>
</div>

<?php if($has($av('food_beverage'))) { ?>
    <div class="mb-5">
        <h6 class="font-weight-bolder text-dark"><?php echo htmlspecialchars($lbl('food_beverage', 'Food & Beverages')); ?></h6>
        <?php $bullets($av('food_beverage')); ?>
    </div>
<?php } ?>

<!-- Stay & Highlights -->
<div class="row mb-2">
    <div class="col-md-4 mb-4">
        <h6 class="font-weight-bolder text-dark"><i class="la la-hotel mr-1" style="color:#6082B6;"></i><?php echo htmlspecialchars($lbl('hotels', 'Hotels')); ?></h6>
        <?php $bullets($av('hotels')); ?>
    </div>
    <div class="col-md-4 mb-4">
        <h6 class="font-weight-bolder text-dark"><i class="la la-mountain mr-1" style="color:#6082B6;"></i><?php echo htmlspecialchars($lbl('scenic_highlights', 'Scenic Highlights')); ?></h6>
        <?php $scenic($av('scenic_highlights')); ?>
    </div>
    <div class="col-md-4 mb-4">
        <h6 class="font-weight-bolder text-dark"><i class="la la-shopping-bag mr-1" style="color:#6082B6;"></i><?php echo htmlspecialchars($lbl('shopping_stops', 'Shopping Stops')); ?></h6>
        <?php $justified($av('shopping_stops')); ?>
    </div>
</div>

<!-- Inclusions / Exclusions / Optional -->
<div class="row mb-2">
    <div class="col-md-4 mb-4">
        <h6 class="font-weight-bolder" style="color:#1BC5BD;"><?php echo htmlspecialchars($lbl('inclusions', 'Inclusions')); ?></h6>
        <?php $bullets($av('inclusions')); ?>
    </div>
    <div class="col-md-4 mb-4">
        <h6 class="font-weight-bolder" style="color:#F64E60;"><?php echo htmlspecialchars($lbl('exclusions', 'Exclusions')); ?></h6>
        <?php $bullets($av('exclusions')); ?>
    </div>
    <div class="col-md-4 mb-4">
        <h6 class="font-weight-bolder" style="color:#8950FC;"><?php echo htmlspecialchars($lbl('optional_tours', 'Optional Tours')); ?></h6>
        <?php $bullets($av('optional_tours')); ?>
    </div>
</div>

<?php if($has($av('special_remarks'))) { ?>
    <div class="mb-5">
        <h6 class="font-weight-bolder text-dark"><i class="la la-info-circle mr-1" style="color:#FFA800;"></i><?php echo htmlspecialchars($lbl('special_remarks', 'Special Remarks')); ?></h6>
        <?php $bullets($av('special_remarks')); ?>
    </div>
<?php } ?>

<!-- Daily itinerary -->
<?php if($has($av('itinerary'))) { ?>
    <div class="mb-5">
        <h5 class="font-weight-bolder text-dark mb-3"><i class="la la-route mr-1" style="color:#6082B6;"></i><?php echo htmlspecialchars($lbl('daily_itinerary', 'Daily Itinerary')); ?></h5>
        <div style="border-left:2px solid #E4E6EF; padding-left:20px;">
            <?php foreach($av('itinerary') as $day) {
                $day = (array) $day;
                $d_label = trim((string) (isset($day['day']) ? $day['day'] : ''));
                $d_title = trim((string) (isset($day['title']) ? $day['title'] : ''));
                $d_desc  = trim((string) (isset($day['description']) ? $day['description'] : ''));
            ?>
                <div class="mb-4" style="position:relative;">
                    <span style="position:absolute; left:-27px; top:2px; width:12px; height:12px; border-radius:50%; background:#6082B6; border:2px solid #fff;"></span>
                    <div class="font-weight-bolder" style="font-size:14px; color:#6082B6;"><?php echo htmlspecialchars($d_label); ?><?php if($d_title !== '') { echo ' — <span style="color:#3F4254;">' . htmlspecialchars($d_title) . '</span>'; } ?></div>
                    <?php if($d_desc !== '') { ?><div class="text-dark-50 mt-1" style="font-size:13px; white-space:pre-line;"><?php echo htmlspecialchars($d_desc); ?></div><?php } ?>
                </div>
            <?php } ?>
        </div>
    </div>
<?php } ?>

</div><!-- /tab-pane Tour Information -->

<!-- ===================== TAB: AI Analysis (assessment with reasoning) ===================== -->
<div class="tab-pane fade" id="<?php echo $tab_ai; ?>" role="tabpanel">

<?php if($has($sv('main_usp'))) { ?>
    <div class="p-4 rounded mb-5" style="background:#E1F0FF;">
        <h5 class="font-weight-bolder text-dark"><?php echo htmlspecialchars($lbl('main_usp', 'Main Selling Point')); ?></h5>
        <div style="font-size:14px;"><?php echo htmlspecialchars($sv('main_usp')); ?></div>
    </div>
<?php } ?>

<!-- Traveller fit -->
<?php if($has($sv('target_traveller')) || $has($sv('child_friendly')) || $has($sv('senior_friendly'))) { ?>
    <h5 class="font-weight-bolder text-dark mt-3 mb-3"><i class="la la-users mr-1" style="color:#6082B6;"></i><?php echo htmlspecialchars($lbl('traveller_fit', 'Traveller Fit')); ?></h5>
    <div class="row mb-2">
        <?php
        $fit = array($lbl('target_traveller', 'Target Traveller') => $sv('target_traveller'), $lbl('child_friendly', 'Child Friendly') => $sv('child_friendly'), $lbl('senior_friendly', 'Senior Friendly') => $sv('senior_friendly'));
        foreach($fit as $label => $val) { if(!$has($val)) { continue; } ?>
            <div class="col-md-4 mb-4">
                <div class="p-4 rounded" style="background:#F3F6F9; height:100%;">
                    <div class="text-muted font-weight-bold" style="font-size:12px;"><?php echo $label; ?></div>
                    <div class="font-weight-bold mt-1" style="font-size:14px;"><?php echo htmlspecialchars($val); ?></div>
                </div>
            </div>
        <?php } ?>
    </div>
<?php } ?>

<!-- Suitability by traveller type -->
<?php if($has($av('traveller_segments'))) { ?>
    <h5 class="font-weight-bolder text-dark mt-3 mb-3"><i class="la la-user-check mr-1" style="color:#6082B6;"></i><?php echo htmlspecialchars($lbl('traveller_suitability', 'Suitability by Traveller Type')); ?></h5>
    <?php $segments($av('traveller_segments')); ?>
<?php } ?>

<!-- Unique selling points -->
<?php if($has($av('usp'))) { ?>
    <div class="mb-5">
        <div class="text-muted font-weight-bold mb-2" style="font-size:13px;"><?php echo htmlspecialchars($lbl('usp', 'Unique Selling Points')); ?></div>
        <?php $bullets($av('usp')); ?>
    </div>
<?php } ?>

<?php
// Summary is now a detailed, labelled overview ("Overview:", "Best for:", ...).
// Split into sections so each label reads as a heading over its body; a legacy
// plain-text summary degrades to a single unlabelled block.
$sum_sections = function_exists('competitor_summary_sections') ? competitor_summary_sections($sv('summary')) : array();
?>
<?php if( ! empty($sum_sections)) { ?>
    <div class="mb-5">
        <h5 class="font-weight-bolder text-dark"><?php echo htmlspecialchars($lbl('summary', 'Summary')); ?></h5>
        <div style="border-left:4px solid #6082B6; background:#F8FAFC; border-radius:4px; padding:16px 20px;">
            <?php foreach($sum_sections as $i => $sec) { ?>
                <div class="<?php echo $i + 1 < count($sum_sections) ? 'mb-3' : ''; ?>">
                    <?php if($sec['label'] !== '') { ?>
                        <div class="font-weight-bolder text-dark" style="font-size:13px; letter-spacing:.02em;"><?php echo htmlspecialchars($sec['label']); ?></div>
                    <?php } ?>
                    <div class="text-dark-75" style="font-size:14px; white-space:pre-line; margin-top:<?php echo $sec['label'] !== '' ? '2px' : '0'; ?>;"><?php echo htmlspecialchars($sec['text']); ?></div>
                </div>
            <?php } ?>
        </div>
    </div>
<?php } ?>

<?php if($has($av('customer_advice'))) { ?>
    <div class="mb-5">
        <h5 class="font-weight-bolder text-dark"><?php echo htmlspecialchars($lbl('customer_advice', 'Advice for Your Trip')); ?></h5>
        <?php $bullets($av('customer_advice')); ?>
    </div>
<?php } ?>

<!-- Pros / Cons (from the customer's point of view, with justification) -->
<div class="row">
    <div class="col-md-6 mb-5">
        <h6 class="font-weight-bolder" style="color:#1BC5BD;"><?php echo htmlspecialchars($lbl('pros', 'Pros')); ?></h6>
        <?php $justified($av('pros')); ?>
    </div>
    <div class="col-md-6 mb-5">
        <h6 class="font-weight-bolder" style="color:#F64E60;"><?php echo htmlspecialchars($lbl('cons', 'Cons')); ?></h6>
        <?php $justified($av('cons')); ?>
    </div>
</div>

<?php if($has($sv('comparison'))) { ?>
    <div class="mt-3">
        <h5 class="font-weight-bolder text-dark"><?php echo htmlspecialchars($lbl('comparison', 'Comparison vs Our Products')); ?></h5>
        <?php if($has($sv('matched_product'))) { ?>
            <div class="mb-2">
                <span class="text-muted" style="font-size:13px;"><?php echo htmlspecialchars($lbl('compared_against', 'Compared against our product:')); ?></span>
                <span class="label label-inline label-light-success font-weight-bolder ml-1" style="font-size:13px; padding:10px 12px;"><?php echo htmlspecialchars($sv('matched_product')); ?></span>
            </div>
        <?php } ?>
        <div class="p-4 rounded" style="background:#FFF4DE; font-size:14px; white-space:pre-line;"><?php echo htmlspecialchars($sv('comparison')); ?></div>
    </div>
<?php } ?>

</div><!-- /tab-pane AI Analysis -->

</div><!-- /tab-content -->
