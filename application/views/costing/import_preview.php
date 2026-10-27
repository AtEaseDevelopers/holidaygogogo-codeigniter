<?php
$summary = isset($summary) ? $summary : array('rows' => array(), 'global_errors' => array(), 'create_count' => 0, 'skip_count' => 0, 'total' => 0);
$rows = isset($summary['rows']) ? $summary['rows'] : array();
$global_errors = isset($summary['global_errors']) ? $summary['global_errors'] : array();
$create_count = (int) (isset($summary['create_count']) ? $summary['create_count'] : 0);
$skip_count = (int) (isset($summary['skip_count']) ? $summary['skip_count'] : 0);
$total = (int) (isset($summary['total']) ? $summary['total'] : 0);
$import_token = isset($import_token) ? $import_token : '';
?>

<div class="d-flex flex-column-fluid">
    <div class="container-fluid">
        <div class="card card-custom mb-5">
            <div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
                <div class="card-title">
                    <h3 class="card-label" style="color:#6082B6;">
                        <strong>Confirm Costing Package Import</strong>
                    </h3>
                </div>
            </div>
            <div class="card-body">

                <div class="alert <?php echo $create_count > 0 ? 'alert-light-primary' : 'alert-light-danger'; ?>" style="border-left:4px solid #6082B6;">
                    Review the <strong><?php echo $total; ?></strong> package(s) found in the file.
                    <strong class="text-success"><?php echo $create_count; ?></strong> will be created<?php if ($skip_count > 0) { ?>,
                    <strong class="text-danger"><?php echo $skip_count; ?></strong> will be skipped<?php } ?>.
                    Nothing is saved until you press <strong>Confirm &amp; Import</strong>.
                </div>

                <?php if (!empty($global_errors)) { ?>
                    <div class="alert alert-warning">
                        <strong>File issues</strong> (rows that could not be matched to a package):
                        <ul class="mb-0 mt-2">
                            <?php foreach ($global_errors as $ge) { ?>
                                <li><?php echo html_escape($ge); ?></li>
                            <?php } ?>
                        </ul>
                    </div>
                <?php } ?>

                <div class="table-responsive">
                    <table class="table table-bordered table-vertical-center">
                        <thead>
                            <tr style="background-color:#F3F6F9;">
                                <th style="width:40px;">#</th>
                                <th>Package</th>
                                <th class="text-center">Pax</th>
                                <th class="text-center">Combinations</th>
                                <th class="text-center">Cost Items</th>
                                <th class="text-center">Hotels</th>
                                <th class="text-center">Flight Options</th>
                                <th class="text-center">Itinerary Days</th>
                                <th class="text-center" style="width:110px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)) { ?>
                                <tr><td colspan="9" class="text-center text-muted">No package rows found.</td></tr>
                            <?php } ?>
                            <?php foreach ($rows as $i => $r) { ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td>
                                        <div class="font-weight-bold"><?php echo html_escape($r['name']); ?></div>
                                        <?php if (!empty($r['errors'])) { ?>
                                            <ul class="mb-0 mt-1 text-danger" style="font-size:12px;">
                                                <?php foreach ($r['errors'] as $e) { ?>
                                                    <li><?php echo html_escape($e); ?></li>
                                                <?php } ?>
                                            </ul>
                                        <?php } ?>
                                        <?php if (!empty($r['warnings'])) { ?>
                                            <ul class="mb-0 mt-1" style="font-size:12px;color:#b58100;">
                                                <?php foreach ($r['warnings'] as $w) { ?>
                                                    <li><?php echo html_escape($w); ?></li>
                                                <?php } ?>
                                            </ul>
                                        <?php } ?>
                                    </td>
                                    <td class="text-center"><?php echo (int) $r['pax']; ?></td>
                                    <td class="text-center"><?php echo (int) $r['combinations']; ?></td>
                                    <td class="text-center"><?php echo (int) $r['cost_items']; ?></td>
                                    <td class="text-center"><?php echo (int) $r['hotels']; ?></td>
                                    <td class="text-center"><?php echo (int) $r['flight_options']; ?></td>
                                    <td class="text-center"><?php echo (int) $r['itinerary_days']; ?></td>
                                    <td class="text-center">
                                        <?php if (!empty($r['creatable'])) { ?>
                                            <span class="label label-light-success label-inline font-weight-bold">Will create</span>
                                        <?php } else { ?>
                                            <span class="label label-light-danger label-inline font-weight-bold">Skip</span>
                                        <?php } ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-end mt-4">
                    <a href="<?php echo base_url('Costing'); ?>" class="btn btn-light font-weight-bold mr-3">
                        <i class="la la-times"></i>Cancel
                    </a>
                    <form action="<?php echo base_url('Costing/Import_Commit'); ?>" method="post" id="costing_import_commit_form">
                        <input type="hidden" name="import_token" value="<?php echo html_escape($import_token); ?>">
                        <button type="submit" class="btn btn-success font-weight-bold" id="costing_import_commit_btn" <?php echo $create_count > 0 ? '' : 'disabled'; ?>>
                            <i class="la la-check"></i>Confirm &amp; Import <?php echo $create_count; ?> Package(s)
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
    $('#costing_import_commit_form').on('submit', function() {
        $('#costing_import_commit_btn').prop('disabled', true).html('<i class="la la-spinner la-spin"></i>Importing...');
    });
</script>
