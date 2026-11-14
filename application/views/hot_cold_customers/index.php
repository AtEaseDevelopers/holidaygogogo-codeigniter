<?php
/**
 * Hot / Warm / Cold Customers — listing bucketed by T.R.A.V.E.L and purchase intent from the
 * latest AI chat analysis.
 */
if ( ! function_exists('hcc_render_list')) {
function hcc_render_list($rows, $kind, $filtered = false)
{
    if (empty($rows)) {
        $sub = $filtered
            ? 'No ' . htmlspecialchars($kind) . ' customers match the current filter.'
            : 'Run <strong>AI Analysis</strong> on a customer\'s profile to classify them.';
        echo '<div class="text-center text-muted py-10"><i class="la la-inbox" style="font-size:38px; opacity:.4;"></i><div class="mt-2 font-weight-bold">No ' . htmlspecialchars($kind) . ' customers' . ($filtered ? ' found' : ' yet') . '</div><div style="font-size:12px;">' . $sub . '</div></div>';
        return;
    }
    $can_view_profile = lc_can_view('customer_profile');
    ?>
    <div class="table-responsive">
        <table class="table table-head-custom table-vertical-center">
            <thead>
                <tr class="text-uppercase text-muted" style="font-size:11px;">
                    <th style="min-width:160px;">Customer</th>
                    <th style="min-width:110px;">Type</th>
                    <th style="min-width:260px;">Why <?php echo htmlspecialchars(ucfirst($kind)); ?></th>
                    <th style="min-width:110px;">Analysed</th>
                    <?php if ($can_view_profile) { ?>
                        <th class="text-right" style="min-width:110px;">Action</th>
                    <?php } ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r) {
                    $name = trim((string) $r->guest_name) !== '' ? $r->guest_name : '(unnamed)';
                    $type = trim((string) $r->source_type);
                    $details = json_decode((string) $r->details_json, true);
                    $has_travel = is_array($details) && ! empty($details['travel_assessment']);
                    // show_approach=1 is what reveals the "How to Approach" + "Recommended
                    // Tours" blocks on the profile — only the Hot/Cold page sets it, so the
                    // normal listing entry stays a plain character profile.
                    $profile_url = base_url('Customer_Analysis?dedup_key=') . urlencode($r->dedup_key)
                        . '&name=' . urlencode((string) $r->guest_name)
                        . ($type !== '' ? '&source_type=' . urlencode($type) : '')
                        . '&show_approach=1';
                ?>
                    <tr>
                        <td>
                            <span class="font-weight-bolder text-dark-75" style="font-size:13px;"><?php echo htmlspecialchars($name); ?></span>
                        </td>
                        <td>
                            <?php if ($type !== '') { ?>
                                <span class="label label-light label-inline font-weight-bold" style="font-size:11px;"><?php echo htmlspecialchars($type); ?></span>
                            <?php } else { ?>
                                <span class="text-muted">—</span>
                            <?php } ?>
                        </td>
                        <td class="text-dark-75" style="font-size:12px;"><?php echo htmlspecialchars($r->temperature_reason ?: '—'); ?>
                            <?php if ( ! $has_travel) { ?><div class="text-muted mt-1">Legacy classification<?php if ($can_view_profile) { ?> — reassess in profile<?php } ?>.</div><?php } ?>
                        </td>
                        <td class="text-muted" style="font-size:12px;"><?php echo date('d M Y', strtotime($r->created_at)); ?></td>
                        <?php if ($can_view_profile) { ?>
                            <td class="text-right">
                                <a href="<?php echo $profile_url; ?>" class="btn btn-light-primary btn-sm font-weight-bold" style="font-size:11px;">View Profile</a>
                            </td>
                        <?php } ?>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <?php
}
}

$hot_n  = count($hot);
$warm_n = count($warm);
$cold_n = count($cold);
?>

<div class="d-flex flex-column-fluid">
    <div class="container-fluid">
        <div class="card card-custom">
            <div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
                <div class="card-title">
                    <h3 class="card-label" style="color:#6082B6;">
                        <i class="la la-thermometer-half" style="color:#6082B6;"></i>
                        <strong>Hot / Warm / Cold Customers</strong>
                        <span class="text-dark-50 font-weight-normal ml-2" style="font-size:12px;">T.R.A.V.E.L qualification · Hot: at least 3 criteria · Warm: fewer than 3 with medium purchase intent</span>
                    </h3>
                </div>
            </div>
            <div class="card-body">
                <div class="accordion accordion-solid accordion-toggle-plus mb-5">
                    <div class="card">
                        <div class="card-header">
                            <div id="hcc_filter_header" data-toggle="collapse" data-target="#hcc_filter" class="card-title <?php echo ($f_q === '' && $f_type === '') ? 'collapsed' : ''; ?>" style="font-size:13px;">Filter</div>
                        </div>
                        <div id="hcc_filter" class="collapse <?php echo ($f_q !== '' || $f_type !== '') ? 'show' : ''; ?>">
                            <div class="card-body">
                                <form action="<?php echo base_url('Hot_Cold_Customers'); ?>" method="get" class="form">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>Search Name</label>
                                                <div class="input-icon">
                                                    <input type="text" name="q" value="<?php echo htmlspecialchars($f_q, ENT_QUOTES); ?>" autocomplete="off" class="form-control" placeholder="Customer name">
                                                    <span><i class="la la-user"></i></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>Type</label>
                                                <select name="type" class="form-control selectpicker" title="--ALL TYPES--">
                                                    <option value="" <?php if($f_type === '') echo 'selected'; ?>>All Types</option>
                                                    <?php foreach(array('Customer'=>'la-user','Guest List'=>'la-users','GHL Lead'=>'la-bullhorn','Manual Lead'=>'la-user-plus') as $t => $ic) { ?>
                                                        <option data-icon="la <?php echo $ic; ?> font-size-lg bs-icon" value="<?php echo htmlspecialchars($t, ENT_QUOTES); ?>" <?php if($f_type === $t) echo 'selected'; ?>><?php echo $t; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="submit" value="Filter" class="btn btn-light-success font-weight-bold" style="width:80px;">
                                    <a href="<?php echo base_url('Hot_Cold_Customers'); ?>" class="btn btn-light-primary font-weight-bold" style="width:80px;">Reset</a>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <ul class="nav nav-tabs nav-tabs-line mb-5" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active font-weight-bold" data-toggle="tab" href="#hcc_hot" role="tab">
                            <span class="label label-danger label-dot mr-2"></span> Hot
                            <span class="label label-light-danger label-inline font-weight-bolder ml-2"><?php echo $hot_n; ?></span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link font-weight-bold" data-toggle="tab" href="#hcc_warm" role="tab">
                            <span class="label label-warning label-dot mr-2"></span> Warm
                            <span class="label label-light-warning label-inline font-weight-bolder ml-2"><?php echo $warm_n; ?></span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link font-weight-bold" data-toggle="tab" href="#hcc_cold" role="tab">
                            <span class="label label-info label-dot mr-2"></span> Cold
                            <span class="label label-light-info label-inline font-weight-bolder ml-2"><?php echo $cold_n; ?></span>
                        </a>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="hcc_hot" role="tabpanel">
                        <?php hcc_render_list($hot, 'hot', ($f_q !== '' || $f_type !== '')); ?>
                    </div>
                    <div class="tab-pane fade" id="hcc_warm" role="tabpanel">
                        <?php hcc_render_list($warm, 'warm', ($f_q !== '' || $f_type !== '')); ?>
                    </div>
                    <div class="tab-pane fade" id="hcc_cold" role="tabpanel">
                        <?php hcc_render_list($cold, 'cold', ($f_q !== '' || $f_type !== '')); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
