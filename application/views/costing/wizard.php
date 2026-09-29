<?php
$package = isset($package) ? $package : array();
$booking = isset($booking) ? $booking : array();
$booking_items = isset($booking_items) ? $booking_items : array();
$item_master = isset($item_master) ? $item_master : array();
$currencies = isset($currencies) ? $currencies : array();
$currency_rate_map = isset($currency_rate_map) ? $currency_rate_map : array();
$financials = isset($financials) ? $financials : array();
$itinerary_days = isset($itinerary_days) ? $itinerary_days : array();
$quote_hotels = isset($quote_hotels) ? $quote_hotels : array();
$quote_flights = isset($quote_flights) ? $quote_flights : array();
$quote_meta = isset($quote_meta) ? $quote_meta : array();
$combinations = isset($combinations) ? $combinations : array();
$statuses = isset($statuses) ? $statuses : array('active', 'inactive');
$wizard_steps = isset($wizard_steps) ? $wizard_steps : array('details' => 'Package Details', 'cost' => 'Cost Template & Margin', 'itinerary' => 'Itinerary', 'logistics' => 'Hotel & Flights', 'done' => 'Save & Quotation');
$active_step = isset($active_step) ? $active_step : 'details';
$has_snapshot = !empty($has_snapshot);

$package_id = isset($package['id']) ? (int) $package['id'] : 0;
$booking_id = isset($booking['id']) ? (int) $booking['id'] : 0;
$duration_days = isset($package['duration_days']) ? (int) $package['duration_days'] : 1;
$adult_count = isset($booking['adult_count']) ? (int) $booking['adult_count'] : 2;
$child_count = isset($booking['child_count']) ? (int) $booking['child_count'] : 0;
$total_pax = isset($booking['total_pax']) ? (int) $booking['total_pax'] : ($adult_count + $child_count);
$travel_date = isset($booking['travel_date']) ? $booking['travel_date'] : date('Y-m-d');
$travel_date_end = !empty($booking['travel_date_end']) ? $booking['travel_date_end'] : $travel_date;
// #2 Travel date is one range field. Keep ISO values for the hidden inputs the
// controller reads, plus a DD/MM/YYYY display string for the visible picker.
$travel_date_iso = date('Y-m-d', strtotime($travel_date));
$travel_date_end_iso = date('Y-m-d', strtotime($travel_date_end));
$travel_date_display = date('d/m/Y', strtotime($travel_date)) . ' - ' . date('d/m/Y', strtotime($travel_date_end));
$booking_status_value = isset($booking['status_value']) ? $booking['status_value'] : 'active';
$margin_percentage = isset($financials['margin_percentage']) ? (float) $financials['margin_percentage'] : 0;
$sales_agents = isset($sales_agents) ? $sales_agents : array();
$sales_admin_id = isset($package['sales_admin_id']) ? (int) $package['sales_admin_id'] : 0;

// Multiplier options for the per-row picker in the cost template. Each row copies
// its item's default multiplier, but the user may override it here (e.g. a normally
// per-day guide charged once for this package).
$multiplier_type_options = function_exists('costing_multiplier_types')
    ? costing_multiplier_types()
    : array('per_day' => 'Per Day', 'per_pax' => 'Per Pax', 'per_day_pax' => 'Per Day & Pax', 'fixed' => 'Fixed');

$step_keys = array_keys($wizard_steps);
$active_index = array_search($active_step, $step_keys, true);
if ($active_index === false) {
    $active_index = 0;
}

// Category slug => label map. Combinations are grouped by category in their item
// picker, so the map is needed outside any single cost table now. Resolve via the
// CI superobject (in a view $this is the Loader, which has no model properties).
$CI =& get_instance();
$CI->load->model('Costing_Category_Model');
$cat_labels = $CI->Costing_Category_Model->Read_Category_Map();
?>

<style>
    .cw-wrap { max-width: 1180px; margin: 0 auto; }
    .cw-stepper { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 26px; }
    .cw-step {
        flex: 1 1 190px; display: flex; align-items: center; gap: 12px;
        border: 1px solid #e4e6ef; border-radius: 8px; padding: 14px 16px;
        background: #ffffff; color: #3f4254; text-decoration: none;
    }
    .cw-step .cw-num {
        width: 34px; height: 34px; border-radius: 50%; flex: 0 0 34px;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 15px; background: #eef1f7; color: #6082B6;
    }
    .cw-step .cw-label { font-weight: 600; font-size: 14px; line-height: 1.2; }
    .cw-step.is-active { border-color: #6082B6; background: #eef4ff; }
    .cw-step.is-active .cw-num { background: #6082B6; color: #fff; }
    .cw-step.is-done .cw-num { background: #1BC5BD; color: #fff; }
    .cw-step.is-upcoming { color: #9aa0b3; }
    .cw-panel {
        border: 1px solid #e4e6ef; border-radius: 8px; background: #fff;
        padding: 26px; margin-bottom: 20px;
    }
    .cw-panel-title { font-size: 19px; font-weight: 700; color: #3f4254; margin-bottom: 4px; }
    .cw-panel-sub { color: #6d7288; margin-bottom: 22px; }
    .cw-cost-table th { background: #f3f6fb; color: #3f4254; font-size: 12.5px; text-align: center; white-space: nowrap; }
    .cw-cost-table td { vertical-align: middle; }
    .cw-cost-table tr.cw-cat-row td,
    .cw-cost-table tr.cw-combo-cat-row td {
        background: #eef4ff; color: #45608a; font-weight: 700; font-size: 12.5px;
        text-transform: uppercase; letter-spacing: .5px; padding: 9px 12px;
    }
    .cw-cost-table input, .cw-cost-table select { min-width: 90px; }
    /* Remark sits on its own full-width row directly beneath its cost row. */
    .cw-cost-table tr.cw-crmk td { border-top: 0; padding-top: 0; }
    .cw-cost-table tr.cw-crmk .cw-remark { min-width: 0; width: 100%; }
    .cw-myr { font-weight: 700; color: #3f4254; white-space: nowrap; }
    .cw-bank-note { display: block; font-size: 11.5px; font-weight: 600; color: #8a6d3b; margin-top: 2px; }
    .cw-total { font-weight: 700; color: #187DE4; white-space: nowrap; }
    .cw-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-top: 20px; }
    .cw-summary-box { border: 1px solid #e4e6ef; border-radius: 8px; padding: 14px 16px; background: #f9fbff; }
    .cw-summary-box .lbl { font-size: 11px; text-transform: uppercase; font-weight: 700; color: #6d7288; letter-spacing: .4px; }
    .cw-summary-box .val { font-size: 20px; font-weight: 700; color: #3f4254; margin-top: 4px; }
    .cw-actions { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-top: 8px; }
    .cw-itin-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 20px; }
    .cw-itin-field { min-width: 0; }
    @media (max-width: 767px) { .cw-itin-fields { grid-template-columns: 1fr; } }
    .cw-combo-card { border: 1px solid #e4e6ef; border-radius: 8px; padding: 16px; margin-bottom: 16px; background: #fbfdff; }
    .cw-combo-head { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
    .cw-combo-head .cw-combo-name { font-weight: 700; }
    .cw-combo-summary { margin-top: 14px; }
    .cw-combo-summary .cw-summary-box { background: #fff; }
    .cw-combo-empty { color: #9aa0b3; font-style: italic; }
</style>

<div class="d-flex flex-column-fluid">
    <div class="container-fluid cw-wrap">

        <div class="d-flex justify-content-between align-items-center mb-5 flex-wrap" style="gap:10px;">
            <div>
                <div class="font-size-h4 font-weight-bolder text-dark"><?php echo $package_id > 0 ? html_escape($package['name']) : 'New Costing Package'; ?></div>
                <div class="text-muted"><?php echo $package_id > 0 ? ((int) $duration_days . 'D / ' . (int) (isset($package['duration_nights']) ? $package['duration_nights'] : 0) . 'N Costing Package') : 'Start with the package details below.'; ?></div>
            </div>
            <a href="<?php echo base_url('Costing'); ?>" class="btn btn-light-primary font-weight-bold">
                <i class="la la-arrow-left"></i>Back To Packages
            </a>
        </div>

        <?php if ($this->session->flashdata('message_success')) { ?>
            <div class="alert alert-success"><?php echo html_escape($this->session->flashdata('message_success')); ?></div>
        <?php } ?>
        <?php if ($this->session->flashdata('message_error')) { ?>
            <div class="alert alert-danger"><?php echo html_escape($this->session->flashdata('message_error')); ?></div>
        <?php } ?>

        <div class="cw-stepper">
            <?php $i = 0; foreach ($wizard_steps as $key => $label) {
                $state = $i === $active_index ? 'is-active' : ($i < $active_index ? 'is-done' : 'is-upcoming');
                // Navigable only once the package exists (saved).
                $tag = $package_id > 0 ? 'a' : 'span';
                $href = $package_id > 0 ? ' href="' . base_url('Costing/Package/' . $package_id . '?step=' . $key) . '"' : '';
            ?>
                <<?php echo $tag . $href; ?> class="cw-step <?php echo $state; ?>">
                    <span class="cw-num"><?php echo ($state === 'is-done') ? '<i class="la la-check"></i>' : ($i + 1); ?></span>
                    <span class="cw-label"><?php echo html_escape($label); ?></span>
                </<?php echo $tag; ?>>
            <?php $i++; } ?>
        </div>

        <?php if ($active_step === 'details') { ?>
        <!-- STEP 1: PACKAGE DETAILS -->
        <form method="post" action="<?php echo base_url('Costing/Save_Package'); ?>">
            <input type="hidden" name="package_id" value="<?php echo $package_id; ?>">
            <input type="hidden" name="wizard_next" value="cost">
            <div class="cw-panel">
                <div class="cw-panel-title">Package Details</div>
                <div class="cw-panel-sub">Name the package and set its duration and status.</div>
                <div class="row">
                    <div class="col-md-12">
                        <div class="form-group">
                            <label>Package Name</label>
                            <input type="text" name="name" class="form-control" required value="<?php echo html_escape($package['name']); ?>">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Customer Name</label>
                            <input type="text" name="customer_name" class="form-control" value="<?php echo html_escape(isset($package['customer_name']) ? $package['customer_name'] : ''); ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Contact Number</label>
                            <input type="text" name="customer_contact" class="form-control" value="<?php echo html_escape(isset($package['customer_contact']) ? $package['customer_contact'] : ''); ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="customer_email" class="form-control" value="<?php echo html_escape(isset($package['customer_email']) ? $package['customer_email'] : ''); ?>">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Sales Person</label>
                            <select name="sales_admin_id" class="form-control">
                                <option value="">— Select sales person —</option>
                                <?php foreach ($sales_agents as $agent) { ?>
                                    <option value="<?php echo (int) $agent['AdminID']; ?>" <?php echo ($sales_admin_id === (int) $agent['AdminID']) ? 'selected' : ''; ?>><?php echo html_escape($agent['Name']); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Days</label>
                            <input type="number" min="1" name="duration_days" class="form-control" required value="<?php echo (int) $duration_days; ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Nights</label>
                            <input type="number" min="0" name="duration_nights" class="form-control" required value="<?php echo (int) (isset($package['duration_nights']) ? $package['duration_nights'] : 0); ?>">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <?php foreach ($statuses as $status_option) { ?>
                                    <option value="<?php echo html_escape($status_option); ?>" <?php echo (isset($package['status_value']) && $package['status_value'] === $status_option) ? 'selected' : ''; ?>><?php echo html_escape(ucfirst($status_option)); ?></option>
                                <?php } ?>
                            </select>
                            <span class="form-text text-muted" style="font-size:11px;">
                                <strong>Active</strong> — customer is still considering, not yet confirmed or cancelled.
                                <strong>Inactive</strong> — cancelled, or confirmed no longer proceeding.
                            </span>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description" rows="3" class="form-control"><?php echo html_escape(isset($package['description']) ? $package['description'] : ''); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <div class="cw-actions">
                <a href="<?php echo base_url('Costing'); ?>" class="btn btn-light font-weight-bold">Cancel</a>
                <button type="submit" class="btn btn-primary font-weight-bold">Save &amp; Continue<i class="la la-arrow-right ml-2"></i></button>
            </div>
        </form>

        <?php } elseif ($active_step === 'cost') { ?>
        <!-- STEP 2: COST TEMPLATE + MARGIN -->
        <form method="post" action="<?php echo base_url('Costing/Save_Cost_Step'); ?>" id="cw-cost-form">
            <input type="hidden" name="package_id" value="<?php echo $package_id; ?>">
            <input type="hidden" name="booking_id" value="<?php echo $booking_id; ?>">
            <input type="hidden" name="status" value="<?php echo html_escape($booking_status_value); ?>">
            <div class="cw-panel">
                <div class="cw-panel-title">Cost Template &amp; Margin</div>
                <div class="cw-panel-sub">Set the pax and margin, then build the customer's price as one or more combinations below. Each combination holds its own cost items — enter each cost in its currency and MYR converts automatically (bank charges included) using the Costing Currency rates.</div>

                <div class="row mb-2">
                    <div class="col-md-4">
                        <div class="form-group mb-2">
                            <label>Travel Date</label>
                            <input type="text" id="cw-travel-range" class="form-control" autocomplete="off" placeholder="Select date range" value="<?php echo html_escape($travel_date_display); ?>">
                            <input type="hidden" name="travel_date_start" id="cw-travel-start" value="<?php echo html_escape($travel_date_iso); ?>">
                            <input type="hidden" name="travel_date_end" id="cw-travel-end" value="<?php echo html_escape($travel_date_end_iso); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group mb-2">
                            <label>Adults</label>
                            <input type="number" min="0" id="cw-adult" name="adult_count" class="form-control" value="<?php echo (int) $adult_count; ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group mb-2">
                            <label>Children</label>
                            <input type="number" min="0" id="cw-child" name="child_count" class="form-control" value="<?php echo (int) $child_count; ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group mb-2">
                            <label>Total Pax</label>
                            <input type="number" id="cw-total-pax" class="form-control" value="<?php echo (int) $total_pax; ?>" readonly>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group mb-0">
                            <label>Margin (Markup on Cost %)</label>
                            <input type="number" step="0.01" min="0" id="cw-margin" name="margin_percentage" class="form-control" value="<?php echo html_escape($margin_percentage); ?>">
                            <span class="form-text text-muted">Cost after Markup = Cost ÷ (1 − margin%) (gross margin). You can still override the Selling Price per combination.</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- COMBINATIONS: customer-facing bundles shown on the Quotation PDF -->
            <div class="cw-panel">
                <div class="cw-panel-title">Combinations</div>
                <div class="cw-panel-sub">Alternative packages shown on the customer Quotation PDF &mdash; the customer picks ONE. Add cost items to each combination straight from the item master. Each combination is priced on its own (selling = its cost &divide; (1 &minus; margin), gross margin); there is no combined total.</div>

                <div id="cw-combos"></div>

                <div class="d-flex flex-wrap align-items-center mt-2" style="gap:8px;">
                    <button type="button" class="btn btn-light-primary font-weight-bold" id="cw-combo-add"><i class="la la-plus"></i>Add Combination</button>
                    <a href="<?php echo base_url('Costing_Item'); ?>" target="_blank" class="btn btn-light font-weight-bold" title="Manage item master">Manage Items</a>
                </div>
                <?php if (empty($item_master)) { ?>
                    <div class="text-muted mt-2">Add items to the master first under <a href="<?php echo base_url('Costing_Item'); ?>" target="_blank">Costing Item</a> to build combinations.</div>
                <?php } ?>

                <div class="cw-cur-panel" id="cw-cur-panel" style="margin-top:24px;display:none;">
                    <div class="cw-panel-title" style="font-size:14px;">Currency Breakdown</div>
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0 w-100" id="cw-cur-table">
                            <thead>
                                <tr>
                                    <th style="text-align:left;">Currency</th>
                                    <th class="text-right">Rate to MYR</th>
                                    <th class="text-right">Bank Charge / line</th>
                                    <th class="text-right">Total (currency)</th>
                                    <th class="text-right">Total in MYR</th>
                                </tr>
                            </thead>
                            <tbody id="cw-cur-body"></tbody>
                        </table>
                    </div>
                </div>

            </div>

            <div class="cw-actions">
                <a href="<?php echo base_url('Costing/Package/' . $package_id . '?step=details'); ?>" class="btn btn-light font-weight-bold"><i class="la la-arrow-left mr-2"></i>Back</a>
                <button type="submit" class="btn btn-primary font-weight-bold">Save &amp; Continue<i class="la la-arrow-right ml-2"></i></button>
            </div>
        </form>

        <?php } elseif ($active_step === 'itinerary') { ?>
        <!-- STEP 3: ITINERARY -->
        <?php
        $itin_rows = !empty($itinerary_days) ? $itinerary_days : array();
        if (empty($itin_rows)) {
            for ($d = 1; $d <= max(1, $duration_days); $d++) {
                $itin_rows[] = array('day_number' => $d);
            }
        }
        // Each day = a plain-text title + a rich-text Description + a multi-select
        // Meal Plan. Notes / Special Remark / Terms apply to the whole itinerary.
        $this->load->helper('costing_itinerary');
        $itin_fields = array(
            'description' => 'Description',
        );
        $meal_options = costing_meal_plan_options();
        // Itinerary-wide rich-text blocks (one set for the whole itinerary).
        $itin_meta = isset($itinerary_meta) ? $itinerary_meta : array();
        // #7 One merged Notes field. A blank field opens with the four sections
        // (Include / Exclude / Important Notes / Terms & Conditions) each with an
        // empty bullet, ready to fill in.
        $itin_level_fields = array(
            'itinerary_notes' => array('Notes', 'notes'),
        );
        $itin_notes_default = '<p><strong>Include</strong></p><ul><li></li></ul>'
            . '<p><strong>Exclude</strong></p><ul><li></li></ul>'
            . '<p><strong>Important Notes</strong></p><ul><li></li></ul>'
            . '<p><strong>Terms &amp; Conditions</strong></p><ul><li></li></ul>';
        // Renders one day card. $tpl=true emits the blank JS template with an
        // "__I__" index placeholder (values blank); otherwise a saved/seed row.
        $render_itin_card = function ($i, $day, $tpl = false) use ($itin_fields, $meal_options) {
            $idx  = $tpl ? '__I__' : (int) $i;
            $dayn = $tpl ? '' : (int) (isset($day['day_number']) ? $day['day_number'] : ((int) $i + 1));
            ob_start(); ?>
            <div class="cw-panel cw-itin-day">
                <div class="d-flex align-items-end mb-4" style="gap:10px;">
                    <div style="width:80px;">
                        <label class="font-weight-bold mb-1">Day</label>
                        <input type="number" min="1" class="form-control" name="itinerary[<?php echo $idx; ?>][day_number]" value="<?php echo $dayn; ?>">
                    </div>
                    <div style="flex:1;">
                        <label class="font-weight-bold mb-1">Title</label>
                        <input type="text" class="form-control" name="itinerary[<?php echo $idx; ?>][title]" value="<?php echo $tpl ? '' : html_escape(isset($day['title']) ? $day['title'] : ''); ?>" placeholder="Day title e.g. Arrival &amp; City Tour">
                    </div>
                    <button type="button" class="btn btn-icon btn-light-danger cw-itin-remove" data-toggle="tooltip" title="Remove day"><i class="la la-trash"></i></button>
                </div>
                <div class="cw-itin-fields">
                    <?php foreach ($itin_fields as $key => $label) { ?>
                        <div class="cw-itin-field">
                            <label class="font-weight-bold mb-1"><?php echo $label; ?></label>
                            <textarea id="cw-ed-<?php echo $idx; ?>-<?php echo $key; ?>" class="form-control cw-itin-editor" name="itinerary[<?php echo $idx; ?>][<?php echo $key; ?>]"><?php echo $tpl ? '' : html_escape(isset($day[$key]) ? $day[$key] : ''); ?></textarea>
                        </div>
                    <?php }
                    // Meal Plan is a multi-select (a day can have several meals).
                    $meal_sel = $tpl ? array() : array_map('trim', explode(',', (string) (isset($day['meal_plan']) ? $day['meal_plan'] : ''))); ?>
                    <div class="cw-itin-field">
                        <label class="font-weight-bold mb-1">Meal Plan</label>
                        <div class="d-flex flex-wrap" style="gap:10px 22px;">
                            <?php foreach ($meal_options as $slug => $mlabel) { ?>
                                <label style="display:inline-flex; align-items:center; gap:8px; margin:0; font-weight:400; cursor:pointer;">
                                    <input type="checkbox" style="width:16px; height:16px; margin:0;" name="itinerary[<?php echo $idx; ?>][meal_plan][]" value="<?php echo $slug; ?>"<?php echo in_array($slug, $meal_sel, true) ? ' checked' : ''; ?>>
                                    <span><?php echo $mlabel; ?></span>
                                </label>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php return ob_get_clean();
        };
        ?>
        <form method="post" action="<?php echo base_url('Costing/Save_Itinerary'); ?>" id="cw-itin-form">
            <input type="hidden" name="package_id" value="<?php echo $package_id; ?>">
            <div class="cw-panel">
                <div class="cw-panel-title">Itinerary</div>
                <div class="cw-panel-sub">Per-day plan shown on the customer Quotation PDF. Each block is a rich-text editor.</div>
            </div>
            <div id="cw-itin-body">
                <?php foreach ($itin_rows as $i => $day) { echo $render_itin_card($i, $day); } ?>
            </div>
            <template id="cw-itin-tpl"><?php echo $render_itin_card(0, array(), true); ?></template>
            <div class="cw-panel">
                <button type="button" class="btn btn-light-primary font-weight-bold" id="cw-itin-add"><i class="la la-plus"></i>Add Day</button>
            </div>
            <div class="cw-panel" id="cw-itin-meta">
                <div class="cw-panel-title">Notes &amp; Terms</div>
                <div class="cw-panel-sub">Shown once at the bottom of the Quotation PDF — applies to the whole itinerary, not a single day.</div>
                <?php foreach ($itin_level_fields as $key => $meta) {
                    list($label, $post_key) = $meta;
                    // #7 A blank Notes field opens with the four-section template so
                    // the user fills each bullet in one editor.
                    $meta_val = isset($itin_meta[$post_key]) ? (string) $itin_meta[$post_key] : '';
                    if (trim(strip_tags($meta_val)) === '' && stripos($meta_val, '<img') === false) {
                        $meta_val = $itin_notes_default;
                    } ?>
                    <div class="cw-itin-field mb-4">
                        <label class="font-weight-bold mb-1"><?php echo $label; ?></label>
                        <textarea id="cw-ed-meta-<?php echo $key; ?>" class="form-control cw-itin-editor" name="<?php echo $key; ?>"><?php echo html_escape($meta_val); ?></textarea>
                    </div>
                <?php } ?>
            </div>
            <div class="cw-actions">
                <a href="<?php echo base_url('Costing/Package/' . $package_id . '?step=cost'); ?>" class="btn btn-light font-weight-bold"><i class="la la-arrow-left mr-2"></i>Back</a>
                <button type="submit" class="btn btn-primary font-weight-bold">Save &amp; Continue<i class="la la-arrow-right ml-2"></i></button>
            </div>
        </form>

        <?php } elseif ($active_step === 'logistics') { ?>
        <!-- STEP 4: HOTEL PRICING + FLIGHT OPTIONS (drives the Quotation PDF) -->
        <?php
        $this->load->helper('costing_quote');
        $qm = $quote_meta;
        $q_val = function ($key) use ($qm) {
            return isset($qm[$key]) && $qm[$key] !== null ? (string) $qm[$key] : '';
        };
        // Seed the footer notes editor with the standard boilerplate when empty.
        $footer_notes_val = trim($q_val('quote_footer_notes'));
        if ($footer_notes_val === '') {
            $footer_notes_val = costing_quote_default_footer_notes();
        }

        // Hotel pricing columns (4.2) + flight mode (4.3), normalised.
        $hotel_columns = costing_quote_hotel_columns_normalize($q_val('quote_hotel_columns'));
        $flight_mode   = costing_quote_normalize_flight_mode($q_val('quote_flight_mode'));

        // Hotel rows for the JS renderer (name + per-column prices + single supp).
        $hotel_state = array();
        foreach ((array) $quote_hotels as $h) {
            $prices = costing_quote_hotel_prices_normalize(
                (isset($h['prices_json']) && $h['prices_json'] !== null && $h['prices_json'] !== '')
                    ? $h['prices_json']
                    : array(isset($h['twin_triple_price']) ? $h['twin_triple_price'] : null),
                count($hotel_columns)
            );
            $hotel_state[] = array(
                'hotel_name'        => isset($h['hotel_name']) ? (string) $h['hotel_name'] : '',
                'prices'            => array_map(function ($p) { return $p === null ? '' : (string) $p; }, $prices),
                'single_supp_price' => (isset($h['single_supp_price']) && $h['single_supp_price'] !== null && $h['single_supp_price'] !== '')
                    ? number_format((float) $h['single_supp_price'], 2, '.', '') : '',
            );
        }

        // Flight options for the JS renderer (each with its own schedule rows).
        $flight_option_state = array();
        foreach ((array) $quote_flight_options as $opt) {
            $rows = array();
            foreach ((array) (isset($opt['flights']) ? $opt['flights'] : array()) as $f) {
                $rows[] = array(
                    'travel_date' => (string) (isset($f['travel_date']) ? $f['travel_date'] : ''),
                    'sector'      => (string) (isset($f['sector']) ? $f['sector'] : ''),
                    'flight_no'   => (string) (isset($f['flight_no']) ? $f['flight_no'] : ''),
                    'timing'      => (string) (isset($f['timing']) ? $f['timing'] : ''),
                    'duration'    => (string) (isset($f['duration']) ? $f['duration'] : ''),
                );
            }
            $flight_option_state[] = array(
                'title'         => (string) (isset($opt['title']) ? $opt['title'] : ''),
                'airline'       => (string) (isset($opt['airline']) ? $opt['airline'] : ''),
                'price'         => (isset($opt['price']) && $opt['price'] !== null && $opt['price'] !== '')
                    ? number_format((float) $opt['price'], 2, '.', '') : '',
                'fare_includes' => (string) (isset($opt['fare_includes']) ? $opt['fare_includes'] : ''),
                'fare_expiry'   => (string) (isset($opt['fare_expiry']) ? $opt['fare_expiry'] : ''),
                'rows'          => $rows,
            );
        }
        $combination_pricing = isset($combination_pricing) ? $combination_pricing : array();
        ?>
        <form method="post" action="<?php echo base_url('Costing/Save_Logistics'); ?>" id="cw-logi-form">
            <input type="hidden" name="package_id" value="<?php echo $package_id; ?>">

            <!-- Hotel pricing -->
            <div class="cw-panel">
                <div class="cw-panel-title">Hotel Pricing (per person)</div>
                <div class="cw-panel-sub">The pricing table shown on the customer Quotation PDF. Prices are per person in RM. Add columns for different room types / pax bands, and pull hotel names &amp; selling rates straight from the Costing Template.</div>
                <div class="row">
                    <div class="col-md-6 mb-4">
                        <label class="font-weight-bold mb-1">Pricing basis</label>
                        <input type="text" class="form-control" name="quote_pricing_basis" value="<?php echo html_escape($q_val('quote_pricing_basis')); ?>" placeholder="e.g. 25paxs + 1FOC">
                        <small class="text-muted">Shown in the "Quoted based on ..." heading.</small>
                    </div>
                    <div class="col-md-6 mb-4">
                        <label class="font-weight-bold mb-1">Travel date note</label>
                        <input type="text" class="form-control" name="quote_travel_date_note" value="<?php echo html_escape($q_val('quote_travel_date_note')); ?>" placeholder="e.g. 8-11/1/2027 (hotel fully booked allotment)">
                        <small class="text-muted">Red note shown beside the travel date.</small>
                    </div>
                </div>
                <div class="d-flex flex-wrap mb-3" style="gap:8px;">
                    <button type="button" class="btn btn-sm btn-light-success font-weight-bold" id="cw-hotel-pull" data-toggle="tooltip" title="Fill hotels + prices from the Costing Template combinations"><i class="la la-download"></i>Pull from Costing</button>
                    <button type="button" class="btn btn-sm btn-light-primary font-weight-bold" id="cw-hotel-addcol"><i class="la la-columns"></i>Add Price Column</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm cw-logi-table" id="cw-hotel-table"><thead></thead><tbody></tbody></table>
                </div>
                <button type="button" class="btn btn-light-primary font-weight-bold" id="cw-hotel-add"><i class="la la-plus"></i>Add Hotel</button>
                <div class="mt-4">
                    <label class="font-weight-bold mb-1">Hotel note</label>
                    <input type="text" class="form-control" name="quote_hotel_note" value="<?php echo html_escape($q_val('quote_hotel_note')); ?>" placeholder="The hotel is based on the lowest room type &amp; subject to change upon availability.">
                </div>
            </div>

            <!-- Flight -->
            <div class="cw-panel">
                <div class="cw-panel-title">Flight</div>
                <div class="cw-panel-sub">Choose how flights appear on the quotation, then add one or more airline options for comparison.</div>
                <div class="row">
                    <div class="col-md-5 mb-4">
                        <label class="font-weight-bold mb-1">Flight mode</label>
                        <select class="form-control" name="quote_flight_mode" id="cw-flight-mode">
                            <?php foreach (costing_quote_flight_modes() as $mk => $ml) { ?>
                                <option value="<?php echo html_escape($mk); ?>" <?php echo ($flight_mode === $mk) ? 'selected' : ''; ?>><?php echo html_escape($ml); ?></option>
                            <?php } ?>
                        </select>
                        <small class="text-muted">No Flight hides the whole flight section. Include shows the schedule only; FIT / GIT also show pricing.</small>
                    </div>
                </div>
                <div id="cw-flight-wrap">
                    <div id="cw-flight-options"></div>
                    <button type="button" class="btn btn-light-primary font-weight-bold" id="cw-flight-add-option"><i class="la la-plus"></i>Add Flight Option</button>
                </div>
            </div>

            <!-- Boilerplate footer notes -->
            <div class="cw-panel">
                <div class="cw-panel-title">Footer Notes</div>
                <div class="cw-panel-sub">Shown (highlighted) at the bottom of the quotation — one note per line.</div>
                <textarea class="form-control" name="quote_footer_notes" rows="6" style="font-size:13px;"><?php echo html_escape($footer_notes_val); ?></textarea>
            </div>

            <div class="cw-actions">
                <a href="<?php echo base_url('Costing/Package/' . $package_id . '?step=itinerary'); ?>" class="btn btn-light font-weight-bold"><i class="la la-arrow-left mr-2"></i>Back</a>
                <button type="submit" class="btn btn-primary font-weight-bold">Save &amp; Continue<i class="la la-arrow-right ml-2"></i></button>
            </div>

            <!-- Bootstrap state for the JS renderer -->
            <script type="application/json" id="cw-logi-state"><?php echo json_encode(array(
                'columns'      => $hotel_columns,
                'hotels'       => $hotel_state,
                'flightMode'   => $flight_mode,
                'options'      => $flight_option_state,
                'comboPricing' => $combination_pricing,
                'airlines'     => costing_quote_airlines(),
                'titlePresets' => costing_quote_hotel_title_presets(),
            )); ?></script>
        </form>

        <?php } else { ?>
        <!-- STEP 5: DONE + QUOTATION PDF -->
        <div class="cw-panel">
            <?php if ($has_snapshot) { ?>
                <div class="d-flex align-items-center justify-content-between flex-wrap mb-4" style="gap:10px;">
                    <div>
                        <div class="cw-panel-title"><i class="la la-check-circle text-success"></i> Costing Saved</div>
                        <div class="cw-panel-sub mb-0">Your customer quotation is ready below.</div>
                    </div>
                    <div class="d-flex flex-wrap" style="gap:8px;">
                        <a href="<?php echo base_url('Costing/Quotation/' . $package_id); ?>" target="_blank" class="btn btn-light-success font-weight-bold"><i class="la la-external-link-alt"></i>Open in New Tab</a>
                        <a href="<?php echo base_url('Costing/Package/' . $package_id . '?step=cost'); ?>" class="btn btn-light-primary font-weight-bold"><i class="la la-edit"></i>Edit Costing</a>
                        <a href="<?php echo base_url('Costing'); ?>" class="btn btn-primary font-weight-bold">Done</a>
                    </div>
                </div>
                <iframe src="<?php echo base_url('Costing/Quotation/' . $package_id); ?>" title="Quotation PDF" style="width:100%; height:820px; border:1px solid #e4e6ef; border-radius:8px;"></iframe>
            <?php } else { ?>
                <div class="cw-panel-title">Almost There</div>
                <div class="cw-panel-sub">Add the cost template first — the quotation is generated from your saved costing.</div>
                <a href="<?php echo base_url('Costing/Package/' . $package_id . '?step=cost'); ?>" class="btn btn-primary font-weight-bold">Go to Cost Template</a>
            <?php } ?>
        </div>
        <?php } ?>

    </div>
</div>

<?php if ($active_step === 'cost') { ?>
<script>
(function () {
    var RATE_MAP = <?php echo json_encode($currency_rate_map); ?> || {};
    var CAT_LABELS = <?php echo json_encode($cat_labels); ?> || {};
    var DURATION_DAYS = <?php echo (int) $duration_days; ?>;

    // #6 Differentiate items by colour — one distinct colour per category (Tour
    // Leader, Ground, Flight, …). Each category gets its OWN hue, spaced evenly
    // around the colour wheel by the number of categories, so no two collide no
    // matter how many categories exist (a fixed palette wrapped and repeated).
    var CAT_KEYS = Object.keys(CAT_LABELS);
    var CAT_COLORS = {};
    (function () {
        var n = Math.max(1, CAT_KEYS.length);
        CAT_KEYS.forEach(function (cat, i) {
            var hue = Math.round((360 / n) * i);
            CAT_COLORS[cat] = {
                bg: 'hsl(' + hue + ', 70%, 93%)',
                fg: 'hsl(' + hue + ', 55%, 32%)',
                border: 'hsl(' + hue + ', 62%, 50%)'
            };
        });
    })();
    var CAT_FALLBACK = { bg: '#eceff1', fg: '#455a64', border: '#78909c' };
    function catColor(cat) { return CAT_COLORS[cat] || CAT_FALLBACK; }

    var adultInput = document.getElementById('cw-adult');
    var childInput = document.getElementById('cw-child');
    var totalPaxInput = document.getElementById('cw-total-pax');
    var marginInput = document.getElementById('cw-margin');
    var combosWrap = document.getElementById('cw-combos');
    var comboSeq = 0; // monotonic combination index for unique field names

    // Saved combinations to re-render on load (edit mode). Each: name + its items.
    var EXISTING_COMBOS = <?php echo json_encode(array_map(function ($combo) {
        return array(
            'name' => isset($combo['name']) ? $combo['name'] : '',
            'is_general' => !empty($combo['is_general']) ? 1 : 0,
            'selling_price_per_pax' => (isset($combo['selling_price_per_pax']) && $combo['selling_price_per_pax'] !== null && $combo['selling_price_per_pax'] !== '')
                ? (float) $combo['selling_price_per_pax'] : null,
            'items' => array_map(function ($it) {
                return array(
                    'name'            => isset($it['name']) ? $it['name'] : '',
                    'category'        => isset($it['category']) ? $it['category'] : 'miscellaneous',
                    'multiplier_type' => isset($it['multiplier_type']) ? $it['multiplier_type'] : 'fixed',
                    'currency_id'     => (int) (isset($it['currency_id']) ? $it['currency_id'] : 0),
                    'unit_price'      => (float) (isset($it['unit_price']) ? $it['unit_price'] : 0),
                    'count'           => (float) (isset($it['quantity']) ? $it['quantity'] : 1),
                    'remark'          => isset($it['remark']) ? $it['remark'] : '',
                );
            }, isset($combo['items']) ? $combo['items'] : array()),
        );
    }, $combinations)); ?> || [];

    function money(n) { return 'RM ' + (Math.round((Number(n) || 0) * 100) / 100).toFixed(2); }
    function totalPax() { return (parseInt(adultInput.value, 10) || 0) + (parseInt(childInput.value, 10) || 0); }

    // Pure per-unit MYR conversion (NO bank charge). The bank charge is a per-line
    // fee added ONCE to the line total, not per unit (feedback 18 Sep 2026, 2.2).
    function rowMyrPerUnit(row) {
        var currencyId = row.querySelector('.cw-currency').value;
        var info = RATE_MAP[currencyId] || { rate_to_myr: 0, bank_charges_myr: 0 };
        var cost = parseFloat(row.querySelector('.cw-cost').value) || 0;
        return Math.round(cost * (Number(info.rate_to_myr) || 0) * 100) / 100;
    }

    // Line total in MYR: converted amount + the bank charge once (only when the
    // line actually has an amount).
    function rowTotalMyr(row) {
        var perUnit = rowMyrPerUnit(row);
        var count = parseFloat(row.querySelector('.cw-count').value) || 0;
        var info = RATE_MAP[row.querySelector('.cw-currency').value] || { bank_charges_myr: 0 };
        var converted = Math.round(perUnit * count * 100) / 100;
        var bank = Number(info.bank_charges_myr) || 0;
        return converted > 0 ? Math.round((converted + bank) * 100) / 100 : 0;
    }

    function autoCount(row) {
        var type = row.querySelector('.cw-mult-type').value;
        if (type === 'per_day') { return DURATION_DAYS; }
        if (type === 'per_pax') { return totalPax(); }
        if (type === 'per_day_pax') { return DURATION_DAYS * totalPax(); }
        return null; // fixed / custom -> leave user value
    }

    // Price a single combination cost row: refresh its day/pax count, freeze the
    // MYR-convert + bank charge onto its hidden inputs, paint the MYR/Total cells.
    // Returns { total }. Combination rows have no Use checkbox — always included.
    function processRow(row) {
        var auto = autoCount(row);
        var countInput = row.querySelector('.cw-count');
        if (auto !== null && document.activeElement !== countInput) { countInput.value = auto; }

        var perUnit = rowMyrPerUnit(row);
        var count = parseFloat(countInput.value) || 0;
        var total = rowTotalMyr(row);

        // Freeze the pure "MYR (convert)" per-unit figure + the per-line bank charge
        // onto hidden inputs so the saved value is exactly what the user sees. Bank
        // is applied once to the line total, never baked into the per-unit figure.
        var info = RATE_MAP[row.querySelector('.cw-currency').value] || { bank_charges_myr: 0 };
        var myrHidden = row.querySelector('.cw-myr-hidden');
        var bankHidden = row.querySelector('.cw-bank-hidden');
        var bankVal = Number(info.bank_charges_myr) || 0;
        if (myrHidden) { myrHidden.value = perUnit; }
        if (bankHidden) { bankHidden.value = bankVal; }

        var myrCell = row.querySelector('.cw-myr');
        myrCell.querySelector('.cw-myr-val').textContent = money(perUnit);
        myrCell.querySelector('.cw-bank-note').textContent = (bankVal > 0 && total > 0) ? ('+ ' + money(bankVal) + ' bank / line') : '';
        row.querySelector('.cw-total').textContent = money(total);
        return { total: total };
    }

    function currentMargin() {
        var margin = parseFloat(marginInput.value) || 0;
        return margin < 0 ? 0 : margin;
    }

    // Combinations are ALTERNATIVES — the customer picks ONE, so each is priced on
    // its own with no grand total. Each card gets its own P&L using GROSS MARGIN:
    //   cost after markup / pax = cost/pax ÷ (1 − margin%/100)   [suggested]
    //   selling price / pax     = manual override, else the suggestion
    //   revenue = selling/pax × pax, profit/pax = selling/pax − cost/pax.
    // Sum a card's own cost rows (also freezes each row's MYR via processRow).
    function cardRowsCost(card) {
        var cost = 0;
        card.querySelectorAll('.cw-crow').forEach(function (row) { cost += processRow(row).total; });
        return Math.round(cost * 100) / 100;
    }

    function recalcCombos(margin, pax) {
        function set(card, sel, val) { var el = card.querySelector(sel); if (el) { el.textContent = money(val); } }

        // General Combination (item 6): its items are common to all, so its cost is
        // ADDED to every regular combination. Compute + show its subtotal first.
        var generalTotal = 0;
        var generalCard = combosWrap.querySelector('.cw-combo-card.cw-combo-general');
        if (generalCard) {
            generalTotal = cardRowsCost(generalCard);
            set(generalCard, '.cw-combo-cost', generalTotal);
        }

        combosWrap.querySelectorAll('.cw-combo-card').forEach(function (card) {
            if (card.classList.contains('cw-combo-general')) { return; } // priced above
            var cost = Math.round((cardRowsCost(card) + generalTotal) * 100) / 100;
            var costPax = Math.round((cost / pax) * 100) / 100;
            var divisor = 1 - (margin / 100);
            var markupPax = divisor > 0 ? Math.round((costPax / divisor) * 100) / 100 : costPax;

            // Manual Selling Price wins once the user edits it; otherwise it tracks
            // the suggested Cost after Markup.
            var sellInput = card.querySelector('.cw-combo-sell-input');
            var touched = sellInput && sellInput.getAttribute('data-touched') === '1';
            if (sellInput && !touched && document.activeElement !== sellInput) { sellInput.value = markupPax.toFixed(2); }
            var sellPax = sellInput ? (parseFloat(sellInput.value) || 0) : markupPax;
            if (sellPax < 0) { sellPax = 0; }

            var revenue = Math.round(sellPax * pax * 100) / 100;
            var profitPax = Math.round((sellPax - costPax) * 100) / 100;
            var totalProfit = Math.round((revenue - cost) * 100) / 100;

            set(card, '.cw-combo-cost', cost);
            set(card, '.cw-combo-costpax', costPax);
            set(card, '.cw-combo-markuppax', markupPax);
            set(card, '.cw-combo-revenue', revenue);
            set(card, '.cw-combo-profitpax', profitPax);
            var tp = card.querySelector('.cw-combo-profit-total');
            if (tp) { tp.textContent = 'Total Profit: ' + money(totalProfit); }
        });
    }

    function recalc() {
        totalPaxInput.value = totalPax();
        var margin = currentMargin();
        var pax = totalPax() || 1;
        recalcCombos(margin, pax);
        renderCurrencyBreakdown();
    }

    // Roll every combination row up per currency: rate to MYR + total owed in each
    // currency (mirrors the costing_currency_breakdown() PHP helper).
    function renderCurrencyBreakdown() {
        var panel = document.getElementById('cw-cur-panel');
        var tbody = document.getElementById('cw-cur-body');
        if (!panel || !tbody) { return; }

        var acc = {};
        combosWrap.querySelectorAll('.cw-crow').forEach(function (row) {
            var cid = row.querySelector('.cw-currency').value;
            var info = RATE_MAP[cid] || { code: '', rate_to_myr: 0, bank_charges_myr: 0 };
            var unit = parseFloat(row.querySelector('.cw-cost').value) || 0;
            var count = parseFloat(row.querySelector('.cw-count').value) || 0;
            if (!acc[cid]) {
                acc[cid] = {
                    code: info.code || '',
                    rate: Number(info.rate_to_myr) || 0,
                    bank: Number(info.bank_charges_myr) || 0,
                    foreign: 0,
                    myr: 0
                };
            }
            acc[cid].foreign += unit * count;
            // Bank charge is added once per line inside rowTotalMyr().
            acc[cid].myr += rowTotalMyr(row);
        });

        var list = Object.keys(acc).map(function (k) { return acc[k]; });
        list.sort(function (a, b) { return a.code < b.code ? -1 : (a.code > b.code ? 1 : 0); });

        if (!list.length) { panel.style.display = 'none'; tbody.innerHTML = ''; return; }
        panel.style.display = '';

        function num(n, d) { return (Math.round((Number(n) || 0) * Math.pow(10, d)) / Math.pow(10, d)).toFixed(d); }
        tbody.innerHTML = list.map(function (e) {
            return '<tr>' +
                '<td style="text-align:left;">' + (e.code || '?') + '</td>' +
                '<td class="text-right">' + num(e.rate, 4) + '</td>' +
                '<td class="text-right">' + (e.bank > 0 ? 'RM ' + num(e.bank, 2) : '&mdash;') + '</td>' +
                '<td class="text-right">' + (e.code || '?') + ' ' + num(e.foreign, 2) + '</td>' +
                '<td class="text-right">RM ' + num(e.myr, 2) + '</td>' +
                '</tr>';
        }).join('');
    }

    function currencyOptions(selectedId) {
        var opts = '';
        <?php foreach ($currencies as $currency) { ?>
        opts += '<option value="<?php echo (int) $currency['id']; ?>"' + (String(selectedId) === '<?php echo (int) $currency['id']; ?>' ? ' selected' : '') + '><?php echo html_escape($currency['code']); ?></option>';
        <?php } ?>
        return opts;
    }

    // Combination cost items are added from the master, never free text. Each entry
    // carries its category + display labels so the per-combination picker can group
    // and label its options like the old internal table did.
    var MASTER = <?php echo json_encode(array_map(function ($mi) {
        return array(
            'name'             => isset($mi['name']) ? $mi['name'] : '',
            'category'         => isset($mi['category']) ? $mi['category'] : 'miscellaneous',
            'category_label'   => isset($mi['category_label']) ? $mi['category_label'] : 'Miscellaneous',
            'multiplier_type'  => isset($mi['multiplier_type']) ? $mi['multiplier_type'] : 'fixed',
            'multiplier_label' => isset($mi['multiplier_label']) ? $mi['multiplier_label'] : 'Fixed',
            'currency_id'      => (int) (isset($mi['default_currency_id']) ? $mi['default_currency_id'] : 0),
            'currency_code'    => isset($mi['currency_code']) ? $mi['currency_code'] : '',
            'unit_price'       => 0,
        );
    }, $item_master)); ?> || [];

    function masterCount(type) {
        if (type === 'per_day') { return DURATION_DAYS; }
        if (type === 'per_pax') { return totalPax(); }
        if (type === 'per_day_pax') { return DURATION_DAYS * totalPax(); }
        return 1;
    }

    // Build the multiplier <select> options for a freshly added row, mirroring the
    // costing_multiplier_types() helper so it stays in one source of truth server-side.
    var MULT_TYPES = <?php echo json_encode($multiplier_type_options); ?> || {};
    function multiplierOptions(selectedType) {
        var opts = '';
        Object.keys(MULT_TYPES).forEach(function (key) {
            opts += '<option value="' + key + '"' + (key === selectedType ? ' selected' : '') + '>' + MULT_TYPES[key] + '</option>';
        });
        return opts;
    }

    /* -------------------------------------------------------------------- *
     *  COMBINATIONS — customer bundles shown on the Quotation PDF.          *
     *  Each bundle owns its cost rows, added straight from the item master. *
     * -------------------------------------------------------------------- */

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

    // One item picker per combination, sourced from the whole master and grouped by
    // category. Option value = index into MASTER.
    function masterItemPicker() {
        var sel = document.createElement('select');
        sel.className = 'form-control form-control-sm cw-combo-pick';
        var html = '<option value="">— Add item —</option>';
        var byCat = {};
        MASTER.forEach(function (mi, idx) {
            var cat = CAT_LABELS[mi.category] ? mi.category : 'miscellaneous';
            (byCat[cat] = byCat[cat] || []).push(idx);
        });
        Object.keys(CAT_LABELS).forEach(function (cat) {
            if (!byCat[cat]) { return; }
            html += '<optgroup label="' + esc(CAT_LABELS[cat]) + '">';
            byCat[cat].forEach(function (idx) {
                var mi = MASTER[idx];
                html += '<option value="' + idx + '">' + esc(mi.name) + ' (' + esc(mi.currency_code) + ' · ' + esc(mi.multiplier_label) + ')</option>';
            });
            html += '</optgroup>';
        });
        sel.innerHTML = html;
        return sel;
    }

    // A category header row inside a combination's cost table.
    function comboCatHeader(cat) {
        var tr = document.createElement('tr');
        tr.className = 'cw-combo-cat-row';
        tr.setAttribute('data-cat', cat);
        var col = catColor(cat);
        tr.innerHTML = '<td colspan="7" style="background:' + col.bg + '; color:' + col.fg + '; border-left:4px solid ' + col.border + ';">' + esc(CAT_LABELS[cat] || 'Miscellaneous') + '</td>';
        return tr;
    }

    // Drop an item row (+ its remark row) under its category header inside a combo
    // table, creating the header in canonical category order when it's the first of
    // its kind — so the combination reads as grouped sections, not one flat list.
    function insertComboRow(tbody, cat, itemRow, remarkRow) {
        var order = Object.keys(CAT_LABELS);
        var header = tbody.querySelector('.cw-combo-cat-row[data-cat="' + cat + '"]');
        if (!header) {
            header = comboCatHeader(cat);
            var myIdx = order.indexOf(cat);
            var ref = null, existing = tbody.querySelectorAll('.cw-combo-cat-row');
            for (var i = 0; i < existing.length; i++) {
                if (order.indexOf(existing[i].getAttribute('data-cat')) > myIdx) { ref = existing[i]; break; }
            }
            tbody.insertBefore(header, ref);
        }
        var node = header.nextSibling, last = header;
        while (node) {
            if (node.nodeType === 1 && node.classList.contains('cw-combo-cat-row')) { break; }
            if (node.nodeType === 1 && node.getAttribute('data-cat') === cat) { last = node; }
            node = node.nextSibling;
        }
        tbody.insertBefore(itemRow, last.nextSibling);
        tbody.insertBefore(remarkRow, itemRow.nextSibling);
    }

    // Append a cost row to a combination card. `item` supplies the master values
    // (or the saved values in edit mode). Each item is two <tr>s: the cost row and
    // a full-width remark row directly beneath it; both grouped under the category.
    function addComboRow(card, item) {
        var c = card.getAttribute('data-c');
        var r = parseInt(card.dataset.rseq, 10) || 0;
        card.dataset.rseq = (r + 1);
        var base = 'combinations[' + c + '][rows][' + r + ']';
        var cat = (item.category && CAT_LABELS[item.category]) ? item.category : 'miscellaneous';

        var tr = document.createElement('tr');
        tr.className = 'cw-row cw-crow';
        tr.setAttribute('data-cat', cat);
        tr.innerHTML =
            '<td><input type="text" class="form-control" name="' + base + '[name]" value="" readonly>' +
            '<select class="form-control form-control-sm cw-mult-type mt-2" name="' + base + '[multiplier_type]" title="How this cost scales">' + multiplierOptions(item.multiplier_type) + '</select>' +
            '<input type="hidden" name="' + base + '[include]" value="1">' +
            '<input type="hidden" name="' + base + '[category]" value="miscellaneous">' +
            '<input type="hidden" name="' + base + '[unit_count]" value="1">' +
            '<input type="hidden" name="' + base + '[pax_type]" value="">' +
            '<input type="hidden" class="cw-myr-hidden" name="' + base + '[myr_per_unit]" value="">' +
            '<input type="hidden" class="cw-bank-hidden" name="' + base + '[bank_charges_myr]" value=""></td>' +
            '<td><select class="form-control cw-currency" name="' + base + '[currency_id]">' + currencyOptions(item.currency_id) + '</select></td>' +
            '<td><input type="number" step="0.01" min="0" class="form-control cw-cost" name="' + base + '[unit_price]" value="0"></td>' +
            '<td class="text-right cw-myr"><span class="cw-myr-val">0.00</span><span class="cw-bank-note"></span></td>' +
            '<td><input type="number" step="1" min="0" class="form-control cw-count" name="' + base + '[quantity]" value="1"></td>' +
            '<td class="text-right cw-total">0.00</td>' +
            '<td class="text-center"><button type="button" class="btn btn-icon btn-light-danger btn-sm cw-remove" title="Remove row"><i class="la la-trash"></i></button></td>';

        var remarkTr = document.createElement('tr');
        remarkTr.className = 'cw-crmk';
        remarkTr.setAttribute('data-cat', cat);
        remarkTr.innerHTML = '<td colspan="7"><textarea class="form-control form-control-sm cw-remark" name="' + base + '[remark]" rows="2" placeholder="Remark (optional)"></textarea></td>';

        tr.querySelector('input[name="' + base + '[name]"]').value = item.name || '';
        tr.querySelector('input[name="' + base + '[category]"]').value = cat;
        tr.querySelector('.cw-mult-type').value = item.multiplier_type || 'fixed';
        tr.querySelector('.cw-cost').value = (item.unit_price !== undefined ? item.unit_price : 0);
        tr.querySelector('.cw-count').value = (item.count !== undefined && item.count !== null && item.count !== '') ? item.count : masterCount(item.multiplier_type);
        remarkTr.querySelector('.cw-remark').value = item.remark || '';

        // #6 Colour-tag every cell of the item's rows to match its category so each
        // category (item / tour leader / ground …) is clearly a different colour.
        var rowCol = catColor(cat);
        Array.prototype.forEach.call(tr.children, function (td) { td.style.background = rowCol.bg; });
        Array.prototype.forEach.call(remarkTr.children, function (td) { td.style.background = rowCol.bg; });
        tr.firstElementChild.style.borderLeft = '4px solid ' + rowCol.border;
        remarkTr.firstElementChild.style.borderLeft = '4px solid ' + rowCol.border;

        insertComboRow(card.querySelector('.cw-combo-body'), cat, tr, remarkTr);
        return tr;
    }

    // Read a combination card's current cost rows back into plain item objects, so
    // a combination can be duplicated into a new one (feedback 18 Sep 2026, 2.1 —
    // common cost items like Tour Leader / Flight are shared across combinations,
    // only the Ground Cost differs, so the user copies then amends what's different).
    function readComboItems(card) {
        var out = [];
        card.querySelectorAll('.cw-crow').forEach(function (row) {
            var remark = row.nextElementSibling;
            var nameEl = row.querySelector('input[name$="[name]"]');
            var catEl = row.querySelector('input[name$="[category]"]');
            out.push({
                name: nameEl ? nameEl.value : '',
                category: catEl ? catEl.value : 'miscellaneous',
                multiplier_type: row.querySelector('.cw-mult-type').value,
                currency_id: row.querySelector('.cw-currency').value,
                unit_price: row.querySelector('.cw-cost').value,
                count: row.querySelector('.cw-count').value,
                remark: (remark && remark.querySelector('.cw-remark')) ? remark.querySelector('.cw-remark').value : ''
            });
        });
        return out;
    }

    // Build a combination card (name + its own cost table + item picker + totals).
    // sellingPerPax: saved manual selling price (edit mode), or null to auto-fill
    // from the suggested Cost after Markup on every recalc until the user edits it.
    // isGeneral = the single "General Combination" whose common items (Tour Leader,
    // Flight, …) are added to every other combination (item 6). It has no selling
    // price / profit of its own and cannot be removed or duplicated.
    function addComboCard(name, items, sellingPerPax, isGeneral) {
        var c = comboSeq++;
        var card = document.createElement('div');
        card.className = 'cw-combo-card' + (isGeneral ? ' cw-combo-general' : '');
        card.setAttribute('data-c', c);
        card.dataset.rseq = '0';

        var headHtml = isGeneral
            ? '<div class="cw-combo-head">' +
                  '<input type="text" class="form-control cw-combo-name font-weight-bold" name="combinations[' + c + '][name]" value="General – applies to all packages" readonly style="background:#f3f6f9;">' +
                  '<input type="hidden" name="combinations[' + c + '][is_general]" value="1">' +
              '</div>' +
              '<div class="cw-panel-sub" style="margin:-4px 0 8px;">Common items entered here (Tour Leader, Flight, …) are automatically added to every combination below — no need to re-key them.</div>'
            : '<div class="cw-combo-head">' +
                  '<input type="text" class="form-control cw-combo-name" name="combinations[' + c + '][name]" placeholder="Combination name (e.g. Premium)" value="">' +
                  '<button type="button" class="btn btn-icon btn-light-primary cw-combo-dup" title="Duplicate this combination (copy all cost items into a new one)"><i class="la la-copy"></i></button>' +
                  '<button type="button" class="btn btn-icon btn-light-danger cw-combo-remove" title="Remove combination"><i class="la la-trash"></i></button>' +
              '</div>';

        var summaryHtml = isGeneral
            ? '<div class="cw-summary cw-combo-summary">' +
                  '<div class="cw-summary-box"><div class="lbl">General Cost (added to every package)</div><div class="val cw-combo-cost" style="color:#187DE4;">RM 0.00</div></div>' +
              '</div>'
            : '<div class="cw-summary cw-combo-summary">' +
                  '<div class="cw-summary-box"><div class="lbl">Total Cost <span style="font-weight:400; font-size:10px; color:#8a8f9c;">(incl. general)</span></div><div class="val cw-combo-cost">RM 0.00</div></div>' +
                  '<div class="cw-summary-box"><div class="lbl">Cost / Pax</div><div class="val cw-combo-costpax">RM 0.00</div></div>' +
                  '<div class="cw-summary-box"><div class="lbl">Cost after Markup</div><div class="val cw-combo-markuppax" style="color:#187DE4;">RM 0.00</div></div>' +
                  '<div class="cw-summary-box"><div class="lbl">Selling Price / Pax</div><input type="number" step="0.01" min="0" class="form-control cw-combo-sell-input mt-1" name="combinations[' + c + '][selling_price_per_pax]" placeholder="0.00"></div>' +
                  '<div class="cw-summary-box"><div class="lbl">Total Revenue</div><div class="val cw-combo-revenue">RM 0.00</div></div>' +
                  '<div class="cw-summary-box"><div class="lbl">Profit / Pax</div><div class="val cw-combo-profitpax" style="color:#1BC5BD;">RM 0.00</div><div class="cw-combo-profit-total" style="font-size:11px; font-weight:600; color:#6d7288; margin-top:3px;">Total Profit: RM 0.00</div></div>' +
              '</div>';

        card.innerHTML =
            headHtml +
            // #2.3 Add-item toolbar sits at the TOP so items can be added without
            // scrolling to the bottom of a long combination. New rows still slot into
            // their category section automatically.
            '<div class="d-flex align-items-center mb-2" style="gap:8px;"><span class="cw-combo-pick-slot"></span>' +
                '<button type="button" class="btn btn-sm btn-success font-weight-bold cw-combo-add-item"><i class="la la-plus"></i>Add Item</button></div>' +
            '<div class="table-responsive"><table class="table table-bordered cw-cost-table mb-2">' +
                '<thead><tr>' +
                    '<th style="text-align:left;">Details</th>' +
                    '<th style="width:120px;">Currency</th>' +
                    '<th style="width:120px;">Cost</th>' +
                    '<th style="width:140px;">MYR (convert)</th>' +
                    '<th style="width:120px;">No of Day / Pax</th>' +
                    '<th style="width:140px;">Total in MYR</th>' +
                    '<th style="width:44px;"></th>' +
                '</tr></thead>' +
                '<tbody class="cw-combo-body"></tbody>' +
            '</table></div>' +
            summaryHtml;

        card.querySelector('.cw-combo-pick-slot').appendChild(masterItemPicker());
        combosWrap.appendChild(card);
        if (!isGeneral) { card.querySelector('.cw-combo-name').value = name || ''; }
        // A saved manual selling price is authoritative — mark it "touched" so the
        // recalc never overwrites it with the auto Cost after Markup.
        if (sellingPerPax !== undefined && sellingPerPax !== null && sellingPerPax !== '') {
            var sellInput = card.querySelector('.cw-combo-sell-input');
            if (sellInput) { sellInput.value = (Math.round(Number(sellingPerPax) * 100) / 100).toFixed(2); sellInput.setAttribute('data-touched', '1'); }
        }
        (items || []).forEach(function (it) { addComboRow(card, it); });

        if (window.jQuery && jQuery.fn.select2) {
            jQuery(card).find('.cw-combo-pick').select2({ placeholder: '— Add item —', allowClear: true, width: '260px' });
        }
        return card;
    }

    // Once the user types a Selling Price it becomes authoritative (stop auto-fill).
    combosWrap.addEventListener('input', function (e) {
        if (e.target.classList && e.target.classList.contains('cw-combo-sell-input')) {
            e.target.setAttribute('data-touched', '1');
        }
    });
    combosWrap.addEventListener('input', recalc);
    combosWrap.addEventListener('change', recalc);
    combosWrap.addEventListener('click', function (e) {
        var addItem = e.target.closest('.cw-combo-add-item');
        if (addItem) {
            var card = addItem.closest('.cw-combo-card');
            var pick = card.querySelector('.cw-combo-pick');
            if (!pick || pick.value === '') {
                if (typeof Swal !== 'undefined') { Swal.fire({ icon: 'info', title: 'Pick an item', text: 'Select a cost item from the master first.' }); }
                else { alert('Select a cost item from the master first.'); }
                return;
            }
            var item = MASTER[parseInt(pick.value, 10)];
            if (item) {
                addComboRow(card, item);
                pick.value = '';
                if (window.jQuery && jQuery.fn.select2) { jQuery(pick).trigger('change.select2'); }
                recalc();
            }
            return;
        }
        var rmBtn = e.target.closest('.cw-remove');
        if (rmBtn && rmBtn.closest('.cw-crow')) {
            var row = rmBtn.closest('.cw-crow');
            var tbody = row.parentNode;
            var cat = row.getAttribute('data-cat');
            var rmk = row.nextElementSibling;
            if (rmk && rmk.classList.contains('cw-crmk')) { rmk.remove(); }
            row.remove();
            // Drop the category header once its last item is gone, so empty sections
            // don't linger.
            if (tbody && cat) {
                var stillHas = Array.prototype.some.call(tbody.querySelectorAll('.cw-crow'), function (x) { return x.getAttribute('data-cat') === cat; });
                if (!stillHas) {
                    var hdr = tbody.querySelector('.cw-combo-cat-row[data-cat="' + cat + '"]');
                    if (hdr) { hdr.remove(); }
                }
            }
            recalc();
            return;
        }
        var dupCard = e.target.closest('.cw-combo-dup');
        if (dupCard) {
            var src = dupCard.closest('.cw-combo-card');
            if (src) {
                var srcName = src.querySelector('.cw-combo-name').value;
                addComboCard(srcName ? (srcName + ' (copy)') : '', readComboItems(src), null);
                recalc();
            }
            return;
        }
        var rmCard = e.target.closest('.cw-combo-remove');
        if (rmCard) { var cc = rmCard.closest('.cw-combo-card'); if (cc) { cc.remove(); recalc(); } }
    });

    document.getElementById('cw-combo-add').addEventListener('click', function () { addComboCard('', [], null, false); recalc(); });
    adultInput.addEventListener('input', recalc);
    childInput.addEventListener('input', recalc);
    marginInput.addEventListener('input', recalc);

    // Render the General Combination first (its common items fold into every combo),
    // then the saved regular combinations. Exactly one general card always exists.
    var generalExisting = null, regularCombos = [];
    EXISTING_COMBOS.forEach(function (combo) {
        if (combo.is_general && !generalExisting) { generalExisting = combo; }
        else { regularCombos.push(combo); }
    });
    addComboCard(generalExisting ? generalExisting.name : '', generalExisting ? generalExisting.items : [], null, true);
    regularCombos.forEach(function (combo) { addComboCard(combo.name, combo.items, combo.selling_price_per_pax, false); });

    recalc();
})();
</script>
<script>
// #2 Single "Travel Date" field: one daterangepicker feeds two hidden inputs
// (travel_date_start / travel_date_end in ISO) that the controller still reads.
jQuery(function () {
    var $range = jQuery('#cw-travel-range');
    if (!$range.length || !jQuery.fn.daterangepicker) { return; }
    var startVal = jQuery('#cw-travel-start').val();
    var endVal = jQuery('#cw-travel-end').val();
    $range.daterangepicker({
        startDate: startVal ? moment(startVal, 'YYYY-MM-DD') : moment(),
        endDate: endVal ? moment(endVal, 'YYYY-MM-DD') : moment(),
        locale: { format: 'DD/MM/YYYY', cancelLabel: 'Clear' },
        buttonClasses: ' btn',
        applyClass: 'btn-primary',
        cancelClass: 'btn-secondary'
    });
    $range.on('apply.daterangepicker', function (ev, picker) {
        jQuery(this).val(picker.startDate.format('DD/MM/YYYY') + ' - ' + picker.endDate.format('DD/MM/YYYY'));
        jQuery('#cw-travel-start').val(picker.startDate.format('YYYY-MM-DD'));
        jQuery('#cw-travel-end').val(picker.endDate.format('YYYY-MM-DD'));
    });
});
</script>
<?php } elseif ($active_step === 'itinerary') { ?>
<script>
// Runs on jQuery ready so the TinyMCE bundle (loaded later in the footer) is
// available. Each itinerary day has five rich-text editors; new days are cloned
// from a blank <template> and their editors initialised on the fly.
jQuery(function () {
    var body = document.getElementById('cw-itin-body');
    var addBtn = document.getElementById('cw-itin-add');
    var tpl = document.getElementById('cw-itin-tpl');
    var form = document.getElementById('cw-itin-form');
    if (!body || !addBtn || !tpl || !form) { return; }

    var hasTiny = (typeof window.tinymce !== 'undefined');
    var seq = body.querySelectorAll('.cw-itin-day').length;

    // Shared editor config — text formatting + lists; pasted images embed as
    // base64 (rendered inline by DomPDF). Positioning styles are dropped so
    // Word/PDF pastes don't overlap on the printed quotation.
    var editorConfig = {
        menubar: false,
        height: 70,
        branding: false,
        statusbar: false,
        toolbar: 'undo redo | bold italic underline | forecolor backcolor | bullist numlist | alignleft aligncenter alignright | removeformat',
        plugins: 'lists paste',
        paste_data_images: true,
        paste_webkit_styles: 'none',
        paste_remove_styles_if_webkit: true,
        invalid_styles: { '*': 'position top left right bottom z-index' },
        content_style: 'body{font-size:13px} img{max-width:100%;height:auto}'
    };

    function initEditors(scope) {
        if (!hasTiny) { return; }
        scope.querySelectorAll('.cw-itin-editor').forEach(function (el) {
            tinymce.init(Object.assign({}, editorConfig, { target: el }));
        });
    }

    function removeEditors(scope) {
        if (!hasTiny) { return; }
        scope.querySelectorAll('.cw-itin-editor').forEach(function (el) {
            var ed = tinymce.get(el.id);
            if (ed) { ed.remove(); }
        });
    }

    initEditors(body);

    // Itinerary-wide Notes/Special Remark/Terms editors live outside #cw-itin-body.
    var meta = document.getElementById('cw-itin-meta');
    if (meta) { initEditors(meta); }

    addBtn.addEventListener('click', function () {
        var html = tpl.innerHTML.replace(/__I__/g, seq++);
        var wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        var card = wrap.firstElementChild;
        card.querySelector('input[name$="[day_number]"]').value = body.querySelectorAll('.cw-itin-day').length + 1;
        body.appendChild(card);
        initEditors(card);
        if (window.jQuery && jQuery.fn.tooltip) {
            jQuery(card).find('[data-toggle="tooltip"]').tooltip();
        }
    });

    body.addEventListener('click', function (e) {
        var btn = e.target.closest('.cw-itin-remove');
        if (!btn) { return; }
        var card = btn.closest('.cw-itin-day');
        var cards = body.querySelectorAll('.cw-itin-day');
        if (cards.length <= 1) {
            // Keep at least one day — clear it instead of removing.
            card.querySelector('input[name$="[title]"]').value = '';
            card.querySelectorAll('input[type="checkbox"]').forEach(function (cb) { cb.checked = false; });
            if (hasTiny) {
                card.querySelectorAll('.cw-itin-editor').forEach(function (el) {
                    var ed = tinymce.get(el.id);
                    if (ed) { ed.setContent(''); } else { el.value = ''; }
                });
            }
            return;
        }
        removeEditors(card);
        card.remove();
    });

    // Sync every editor back to its textarea before the form posts.
    form.addEventListener('submit', function () {
        if (hasTiny) { tinymce.triggerSave(); }
    });

    if (window.jQuery && jQuery.fn.tooltip) {
        jQuery('[data-toggle="tooltip"]').tooltip();
    }
});
</script>
<?php } elseif ($active_step === 'logistics') { ?>
<script>
// Hotel pricing + flight options are STATE-DRIVEN (rendered from a JS model), so
// dynamic pricing columns (4.2), pull-from-costing (4.1), flight modes (4.3) and
// multiple airline options (item 3 + 4.4) all stay in sync. On any structural
// change we read the DOM back into state, mutate, then re-render.
jQuery(function () {
    var stateEl = document.getElementById('cw-logi-state');
    if (!stateEl) { return; }
    var S = JSON.parse(stateEl.textContent || '{}');
    S.columns = (S.columns && S.columns.length) ? S.columns : ['Twin / Triple'];
    S.hotels = S.hotels || [];
    S.options = S.options || [];
    S.comboPricing = S.comboPricing || [];
    S.airlines = S.airlines || {};
    S.titlePresets = S.titlePresets || [];
    S.flightMode = S.flightMode || 'fit';
    if (!S.hotels.length) { S.hotels = [blankHotel()]; }
    if (!S.options.length) { S.options = [blankOption()]; }

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function blankHotel() { return { hotel_name: '', prices: S && S.columns ? S.columns.map(function () { return ''; }) : [''], single_supp_price: '' }; }
    function blankRow() { return { travel_date: '', sector: '', flight_no: '', timing: '', duration: '' }; }
    function blankOption() { return { title: '', airline: '', price: '', fare_includes: '', fare_expiry: '', rows: [blankRow()] }; }

    /* ---------------- HOTELS ---------------- */
    var hotelTable = document.getElementById('cw-hotel-table');
    var hotelThead = hotelTable.querySelector('thead');
    var hotelTbody = hotelTable.querySelector('tbody');

    // Datalist of pricing-column title presets (shared by every column header).
    var titleListId = 'cw-hotel-title-presets';
    (function () {
        var dl = document.createElement('datalist'); dl.id = titleListId;
        dl.innerHTML = S.titlePresets.map(function (t) { return '<option value="' + esc(t) + '">'; }).join('');
        hotelTable.parentNode.appendChild(dl);
    })();

    function readHotels() {
        S.columns = Array.prototype.map.call(hotelThead.querySelectorAll('.cw-hcol-label'), function (el) { return el.value; });
        if (!S.columns.length) { S.columns = ['Twin / Triple']; }
        S.hotels = Array.prototype.map.call(hotelTbody.querySelectorAll('.cw-hotel-row'), function (tr) {
            return {
                hotel_name: tr.querySelector('.cw-hotel-name').value,
                prices: Array.prototype.map.call(tr.querySelectorAll('.cw-hotel-price'), function (el) { return el.value; }),
                single_supp_price: tr.querySelector('.cw-hotel-single').value
            };
        });
    }

    function renderHotels() {
        var ths = '<tr><th>Hotel</th>';
        S.columns.forEach(function (label, c) {
            ths += '<th style="width:160px;"><input type="text" class="form-control form-control-sm cw-hcol-label" name="quote_hotel_columns[]" list="' + titleListId + '" value="' + esc(label) + '" placeholder="Column title">' +
                (S.columns.length > 1 ? '<button type="button" class="btn btn-icon btn-sm btn-light-danger mt-1 cw-hcol-remove" data-c="' + c + '" data-toggle="tooltip" title="Remove column"><i class="la la-times"></i></button>' : '') +
                '</th>';
        });
        ths += '<th class="text-right" style="width:150px;">Single Supp (RM)</th><th style="width:44px;"></th></tr>';
        hotelThead.innerHTML = ths;

        hotelTbody.innerHTML = S.hotels.map(function (h, i) {
            var priceCells = S.columns.map(function (label, c) {
                var v = (h.prices && h.prices[c] != null) ? h.prices[c] : '';
                return '<td><input type="text" class="form-control text-right cw-hotel-price" name="hotels[' + i + '][prices][' + c + ']" value="' + esc(v) + '" placeholder="0.00"></td>';
            }).join('');
            return '<tr class="cw-hotel-row">' +
                '<td><input type="text" class="form-control cw-hotel-name" name="hotels[' + i + '][hotel_name]" value="' + esc(h.hotel_name || '') + '" placeholder="e.g. 4* Hotel or similar"></td>' +
                priceCells +
                '<td><input type="text" class="form-control text-right cw-hotel-single" name="hotels[' + i + '][single_supp_price]" value="' + esc(h.single_supp_price || '') + '" placeholder="0.00"></td>' +
                '<td class="text-center"><button type="button" class="btn btn-icon btn-light-danger btn-sm cw-hotel-remove" data-i="' + i + '" data-toggle="tooltip" title="Remove hotel"><i class="la la-trash"></i></button></td>' +
                '</tr>';
        }).join('');
        retip(hotelTable);
    }

    hotelTable.addEventListener('click', function (e) {
        var rmCol = e.target.closest('.cw-hcol-remove');
        if (rmCol) { readHotels(); var c = +rmCol.getAttribute('data-c'); if (S.columns.length > 1) { S.columns.splice(c, 1); S.hotels.forEach(function (h) { h.prices.splice(c, 1); }); renderHotels(); } return; }
        var rmRow = e.target.closest('.cw-hotel-remove');
        if (rmRow) { readHotels(); S.hotels.splice(+rmRow.getAttribute('data-i'), 1); if (!S.hotels.length) { S.hotels = [blankHotel()]; } renderHotels(); return; }
    });
    document.getElementById('cw-hotel-add').addEventListener('click', function () { readHotels(); S.hotels.push(blankHotel()); renderHotels(); });
    document.getElementById('cw-hotel-addcol').addEventListener('click', function () { readHotels(); S.columns.push(''); S.hotels.forEach(function (h) { h.prices.push(''); }); renderHotels(); });

    // 4.1 — pull hotel names + first-column selling rate from the Costing combinations.
    document.getElementById('cw-hotel-pull').addEventListener('click', function () {
        if (!S.comboPricing.length) {
            if (typeof Swal !== 'undefined') { Swal.fire({ icon: 'info', title: 'No combinations', text: 'Add combinations with a selling price in the Cost Template first.' }); }
            else { alert('Add combinations in the Cost Template first.'); }
            return;
        }
        var doPull = function () {
            readHotels();
            S.hotels = S.comboPricing.map(function (combo) {
                var prices = S.columns.map(function () { return ''; });
                prices[0] = (combo.price_per_pax != null && combo.price_per_pax !== '') ? String(combo.price_per_pax) : '';
                return { hotel_name: combo.name || '', prices: prices, single_supp_price: '' };
            });
            if (!S.hotels.length) { S.hotels = [blankHotel()]; }
            renderHotels();
        };
        if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: 'question', title: 'Pull from Costing?', text: 'This replaces the hotel rows with your ' + S.comboPricing.length + ' combination(s) and their selling price per pax.', showCancelButton: true, confirmButtonText: 'Pull' })
                .then(function (r) { if (r.value) { doPull(); } });
        } else if (confirm('Replace hotel rows with the costing combinations?')) { doPull(); }
    });

    /* ---------------- FLIGHTS ---------------- */
    var modeSel = document.getElementById('cw-flight-mode');
    var flightWrap = document.getElementById('cw-flight-wrap');
    var optionsWrap = document.getElementById('cw-flight-options');
    var airlineListId = 'cw-airline-presets';
    (function () {
        var dl = document.createElement('datalist'); dl.id = airlineListId;
        dl.innerHTML = Object.keys(S.airlines).map(function (k) { return '<option value="' + esc(S.airlines[k]) + '">'; }).join('');
        optionsWrap.parentNode.appendChild(dl);
    })();

    function showsSection() { return modeSel.value !== 'none'; }
    function showsPricing() { return modeSel.value === 'fit' || modeSel.value === 'git'; }

    function readOptions() {
        S.options = Array.prototype.map.call(optionsWrap.querySelectorAll('.cw-fopt'), function (card) {
            return {
                title: card.querySelector('.cw-fopt-title').value,
                airline: card.querySelector('.cw-fopt-airline').value,
                price: card.querySelector('.cw-fopt-price') ? card.querySelector('.cw-fopt-price').value : '',
                fare_includes: card.querySelector('.cw-fopt-fare') ? card.querySelector('.cw-fopt-fare').value : '',
                fare_expiry: card.querySelector('.cw-fopt-expiry') ? card.querySelector('.cw-fopt-expiry').value : '',
                rows: Array.prototype.map.call(card.querySelectorAll('.cw-frow'), function (tr) {
                    return {
                        travel_date: tr.querySelector('[data-f="travel_date"]').value,
                        sector: tr.querySelector('[data-f="sector"]').value,
                        flight_no: tr.querySelector('[data-f="flight_no"]').value,
                        timing: tr.querySelector('[data-f="timing"]').value,
                        duration: tr.querySelector('[data-f="duration"]').value
                    };
                })
            };
        });
    }

    function renderOptions() {
        flightWrap.style.display = showsSection() ? '' : 'none';
        var pricing = showsPricing();
        optionsWrap.innerHTML = S.options.map(function (o, oi) {
            var rows = (o.rows && o.rows.length) ? o.rows : [blankRow()];
            var rowsHtml = rows.map(function (r, ri) {
                function inp(f, ph) { return '<td><input type="text" class="form-control form-control-sm" data-f="' + f + '" name="flight_options[' + oi + '][rows][' + ri + '][' + f + ']" value="' + esc(r[f] || '') + '" placeholder="' + ph + '"></td>'; }
                return '<tr class="cw-frow">' + inp('travel_date', '13 Nov 2026') + inp('sector', 'KUL - HCM') + inp('flight_no', 'AK 001') + inp('timing', '0700 - 1000') + inp('duration', '3hr 15mins') +
                    '<td class="text-center"><button type="button" class="btn btn-icon btn-sm btn-light-danger cw-frow-remove" data-o="' + oi + '" data-r="' + ri + '" data-toggle="tooltip" title="Remove flight"><i class="la la-trash"></i></button></td></tr>';
            }).join('');

            var pricingHtml = pricing ? (
                '<div class="row mt-2">' +
                    '<div class="col-md-4 mb-2"><label class="font-weight-bold mb-1">Price / pax (Adult / Child) RM</label><input type="text" class="form-control text-right cw-fopt-price" name="flight_options[' + oi + '][price]" value="' + esc(o.price || '') + '" placeholder="0.00"></div>' +
                    '<div class="col-md-8 mb-2"><label class="font-weight-bold mb-1">Fare includes</label><input type="text" class="form-control cw-fopt-fare" name="flight_options[' + oi + '][fare_includes]" value="' + esc(o.fare_includes || '') + '" placeholder="e.g. 20kg baggage, standard seat"></div>' +
                    '<div class="col-md-12 mb-2"><label class="font-weight-bold mb-1">Fare expiry</label><input type="text" class="form-control cw-fopt-expiry" name="flight_options[' + oi + '][fare_expiry]" value="' + esc(o.fare_expiry || '') + '" placeholder="e.g. Valid until 10 Oct 2026"></div>' +
                '</div>'
            ) : '';

            return '<div class="cw-combo-card cw-fopt" data-o="' + oi + '" style="margin-bottom:16px;">' +
                '<div class="cw-combo-head">' +
                    '<input type="text" class="form-control cw-fopt-title" name="flight_options[' + oi + '][title]" value="' + esc(o.title || '') + '" placeholder="Option ' + (oi + 1) + ' title (e.g. Option 1 - AirAsia)">' +
                    '<button type="button" class="btn btn-icon btn-light-danger cw-fopt-remove" data-o="' + oi + '" data-toggle="tooltip" title="Remove flight option"><i class="la la-trash"></i></button>' +
                '</div>' +
                '<div class="row mb-2"><div class="col-md-5"><label class="font-weight-bold mb-1">Airline</label><input type="text" class="form-control cw-fopt-airline" name="flight_options[' + oi + '][airline]" list="' + airlineListId + '" value="' + esc(o.airline || '') + '" placeholder="e.g. Malaysia Airlines (MH)"></div></div>' +
                '<div class="table-responsive"><table class="table table-sm cw-logi-table mb-2"><thead><tr><th>Travel Date</th><th>Sector</th><th>Flight No</th><th>Timing</th><th>Duration</th><th style="width:44px;"></th></tr></thead>' +
                    '<tbody>' + rowsHtml + '</tbody></table></div>' +
                '<button type="button" class="btn btn-sm btn-light-primary font-weight-bold cw-frow-add" data-o="' + oi + '"><i class="la la-plus"></i>Add Flight</button>' +
                pricingHtml +
            '</div>';
        }).join('');
        retip(optionsWrap);
    }

    optionsWrap.addEventListener('click', function (e) {
        var rmOpt = e.target.closest('.cw-fopt-remove');
        if (rmOpt) { readOptions(); S.options.splice(+rmOpt.getAttribute('data-o'), 1); if (!S.options.length) { S.options = [blankOption()]; } renderOptions(); return; }
        var addRow = e.target.closest('.cw-frow-add');
        if (addRow) { readOptions(); var o = +addRow.getAttribute('data-o'); S.options[o].rows = S.options[o].rows || []; S.options[o].rows.push(blankRow()); renderOptions(); return; }
        var rmRow = e.target.closest('.cw-frow-remove');
        if (rmRow) { readOptions(); var oo = +rmRow.getAttribute('data-o'); S.options[oo].rows.splice(+rmRow.getAttribute('data-r'), 1); if (!S.options[oo].rows.length) { S.options[oo].rows = [blankRow()]; } renderOptions(); return; }
    });
    document.getElementById('cw-flight-add-option').addEventListener('click', function () { readOptions(); S.options.push(blankOption()); renderOptions(); });
    modeSel.addEventListener('change', function () { readOptions(); renderOptions(); });

    function retip(scope) { if (window.jQuery && jQuery.fn.tooltip) { jQuery(scope).find('[data-toggle="tooltip"]').tooltip(); } }

    renderHotels();
    renderOptions();
    retip(document);
});
</script>
<?php } ?>
