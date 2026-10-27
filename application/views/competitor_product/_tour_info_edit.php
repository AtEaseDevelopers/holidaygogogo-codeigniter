<?php
/**
 * Inline edit form for the Tour Information tab of a SINGLE competitor analysis.
 * Included by _product.php only when $editable is set (single, English view —
 * see view.php). Hidden by default; the Edit button in view.php toggles it with
 * #ca_ti_view. Shares the $sv/$av readers and $P from _product.php, and $a (the
 * row, for the id) from view.php. Posts to Competitor_Product/Save_Tour_Info.
 *
 * Simple scalar/list fields are driven by competitor_tour_info_simple_fields() so
 * the form and the save parser stay in lock-step. The four structured fields
 * (meals, scenic highlights, shopping stops, itinerary) use repeating-row widgets.
 */
$scenic_rows   = function_exists('competitor_scenic_items') ? competitor_scenic_items($av('scenic_highlights')) : array();
$shopping_rows = array();
foreach ($av('shopping_stops') as $it) {
    $pj = competitor_split_point_justification(is_array($it) ? implode(' ', $it) : $it);
    if ($pj['point'] === '' && $pj['justification'] === '') { continue; }
    $shopping_rows[] = $pj;
}
$itin_rows = array();
foreach ($av('itinerary') as $day) {
    $day = (array) $day;
    $itin_rows[] = array(
        'day'         => isset($day['day']) ? (string) $day['day'] : '',
        'title'       => isset($day['title']) ? (string) $day['title'] : '',
        'description' => isset($day['description']) ? (string) $day['description'] : '',
    );
}
$meals = $av('meals');
$mv = function ($k) use ($meals) { return isset($meals[$k]) ? (string) $meals[$k] : ''; };
?>
<div id="ca_ti_edit" style="display:none;">
    <form id="ca_ti_form" onsubmit="return false;">
        <input type="hidden" name="id" value="<?php echo (int) $a->id; ?>">

        <?php foreach (competitor_tour_info_simple_fields() as $section => $fields) { ?>
            <h5 class="font-weight-bolder text-dark mt-3 mb-3"><?php echo htmlspecialchars($section); ?></h5>
            <div class="row">
                <?php foreach ($fields as $f) {
                    $key = $f['key'];
                    if ($f['kind'] === 'list') {
                        $val = implode("\n", array_map(function ($x) { return is_array($x) ? implode(' ', $x) : (string) $x; }, $av($key)));
                    } else {
                        $val = $sv($key);
                    }
                ?>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="font-weight-bold"><?php echo htmlspecialchars($f['label']); ?>
                                <?php if ($f['kind'] === 'list') { ?><span class="text-muted font-weight-normal" style="font-size:11px;">(one per line)</span><?php } ?>
                            </label>
                            <?php if ($f['kind'] === 'list') { ?>
                                <textarea name="<?php echo $key; ?>" rows="3" class="form-control" autocomplete="off"><?php echo htmlspecialchars($val); ?></textarea>
                            <?php } else { ?>
                                <input type="text" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars($val); ?>" class="form-control" autocomplete="off">
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>

        <!-- Meals -->
        <h5 class="font-weight-bolder text-dark mt-3 mb-3">Meals</h5>
        <div class="row">
            <?php foreach (array('breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner') as $mk => $mlabel) { ?>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="font-weight-bold"><?php echo $mlabel; ?></label>
                        <input type="text" name="meal_<?php echo $mk; ?>" value="<?php echo htmlspecialchars($mv($mk)); ?>" class="form-control" autocomplete="off">
                    </div>
                </div>
            <?php } ?>
        </div>

        <!-- Scenic highlights -->
        <h5 class="font-weight-bolder text-dark mt-3 mb-2">Scenic Highlights</h5>
        <div id="ca_scenic_rows">
            <?php foreach ($scenic_rows as $r) { ?>
                <div class="form-group row ca-rep-row">
                    <div class="col-md-4"><input type="text" name="scenic_name[]" value="<?php echo htmlspecialchars($r['name']); ?>" class="form-control" placeholder="Name" autocomplete="off"></div>
                    <div class="col-md-7"><input type="text" name="scenic_desc[]" value="<?php echo htmlspecialchars($r['description']); ?>" class="form-control" placeholder="Description" autocomplete="off"></div>
                    <div class="col-md-1"><button type="button" class="btn btn-icon btn-light-danger ca-rep-del"><i class="la la-trash"></i></button></div>
                </div>
            <?php } ?>
        </div>
        <button type="button" class="btn btn-sm btn-light-primary font-weight-bold mb-4" data-add="ca_scenic_rows"><i class="la la-plus"></i> Add highlight</button>

        <!-- Shopping stops -->
        <h5 class="font-weight-bolder text-dark mt-3 mb-2">Shopping Stops</h5>
        <div id="ca_shopping_rows">
            <?php foreach ($shopping_rows as $r) { ?>
                <div class="form-group row ca-rep-row">
                    <div class="col-md-4"><input type="text" name="shopping_point[]" value="<?php echo htmlspecialchars($r['point']); ?>" class="form-control" placeholder="Stop" autocomplete="off"></div>
                    <div class="col-md-7"><input type="text" name="shopping_just[]" value="<?php echo htmlspecialchars($r['justification']); ?>" class="form-control" placeholder="Justification" autocomplete="off"></div>
                    <div class="col-md-1"><button type="button" class="btn btn-icon btn-light-danger ca-rep-del"><i class="la la-trash"></i></button></div>
                </div>
            <?php } ?>
        </div>
        <button type="button" class="btn btn-sm btn-light-primary font-weight-bold mb-4" data-add="ca_shopping_rows"><i class="la la-plus"></i> Add shopping stop</button>

        <!-- Daily itinerary -->
        <h5 class="font-weight-bolder text-dark mt-3 mb-2">Daily Itinerary</h5>
        <div id="ca_itin_rows">
            <?php foreach ($itin_rows as $r) { ?>
                <div class="ca-rep-row border rounded p-3 mb-3">
                    <div class="form-group row mb-2">
                        <div class="col-md-3"><input type="text" name="itin_day[]" value="<?php echo htmlspecialchars($r['day']); ?>" class="form-control" placeholder="Day (e.g. Day 1)" autocomplete="off"></div>
                        <div class="col-md-8"><input type="text" name="itin_title[]" value="<?php echo htmlspecialchars($r['title']); ?>" class="form-control" placeholder="Title" autocomplete="off"></div>
                        <div class="col-md-1"><button type="button" class="btn btn-icon btn-light-danger ca-rep-del"><i class="la la-trash"></i></button></div>
                    </div>
                    <textarea name="itin_desc[]" rows="2" class="form-control" placeholder="Description" autocomplete="off"><?php echo htmlspecialchars($r['description']); ?></textarea>
                </div>
            <?php } ?>
        </div>
        <button type="button" class="btn btn-sm btn-light-primary font-weight-bold mb-4" data-add="ca_itin_rows"><i class="la la-plus"></i> Add day</button>

        <!-- Empty-row templates (cloned by JS; kept out of the POST via a non-field container) -->
        <template id="ca_tpl_ca_scenic_rows">
            <div class="form-group row ca-rep-row">
                <div class="col-md-4"><input type="text" name="scenic_name[]" value="" class="form-control" placeholder="Name" autocomplete="off"></div>
                <div class="col-md-7"><input type="text" name="scenic_desc[]" value="" class="form-control" placeholder="Description" autocomplete="off"></div>
                <div class="col-md-1"><button type="button" class="btn btn-icon btn-light-danger ca-rep-del"><i class="la la-trash"></i></button></div>
            </div>
        </template>
        <template id="ca_tpl_ca_shopping_rows">
            <div class="form-group row ca-rep-row">
                <div class="col-md-4"><input type="text" name="shopping_point[]" value="" class="form-control" placeholder="Stop" autocomplete="off"></div>
                <div class="col-md-7"><input type="text" name="shopping_just[]" value="" class="form-control" placeholder="Justification" autocomplete="off"></div>
                <div class="col-md-1"><button type="button" class="btn btn-icon btn-light-danger ca-rep-del"><i class="la la-trash"></i></button></div>
            </div>
        </template>
        <template id="ca_tpl_ca_itin_rows">
            <div class="ca-rep-row border rounded p-3 mb-3">
                <div class="form-group row mb-2">
                    <div class="col-md-3"><input type="text" name="itin_day[]" value="" class="form-control" placeholder="Day (e.g. Day 1)" autocomplete="off"></div>
                    <div class="col-md-8"><input type="text" name="itin_title[]" value="" class="form-control" placeholder="Title" autocomplete="off"></div>
                    <div class="col-md-1"><button type="button" class="btn btn-icon btn-light-danger ca-rep-del"><i class="la la-trash"></i></button></div>
                </div>
                <textarea name="itin_desc[]" rows="2" class="form-control" placeholder="Description" autocomplete="off"></textarea>
            </div>
        </template>

        <div class="mt-4 mb-2 d-flex">
            <button type="button" id="ca_ti_save" class="btn btn-primary font-weight-bold mr-2"><i class="la la-save"></i> Save</button>
            <button type="button" id="ca_ti_cancel" class="btn btn-light-primary font-weight-bold">Cancel</button>
        </div>
    </form>
</div>

<script>
    // Repeating-row add/remove for the Tour Information edit form. Clones the matching
    // <template> into the target container; delete removes its own row.
    (function () {
        $(document).on('click', '#ca_ti_edit [data-add]', function () {
            var target = $(this).data('add');
            var tpl = document.getElementById('ca_tpl_' + target);
            if (tpl) { $('#' + target).append(tpl.innerHTML.trim()); }
        });
        $(document).on('click', '#ca_ti_edit .ca-rep-del', function () {
            $(this).closest('.ca-rep-row').remove();
        });
    })();
</script>
