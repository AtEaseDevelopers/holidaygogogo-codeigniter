<?php
$packages = isset($packages) ? $packages : array();
$filters = isset($filters) ? $filters : array('search' => '', 'status' => '');
$filter_search = isset($filters['search']) ? $filters['search'] : '';
$filter_tour_code = isset($filters['tour_code']) ? $filters['tour_code'] : '';
$filter_status = isset($filters['status']) ? $filters['status'] : '';
$filter_customer_name = isset($filters['customer_name']) ? $filters['customer_name'] : '';
$filter_customer_contact = isset($filters['customer_contact']) ? $filters['customer_contact'] : '';
$filter_customer_email = isset($filters['customer_email']) ? $filters['customer_email'] : '';
$filter_sales_admin_id = isset($filters['sales_admin_id']) ? (int) $filters['sales_admin_id'] : 0;
$filter_op_admin_id = isset($filters['op_admin_id']) ? (int) $filters['op_admin_id'] : 0;
$filter_created_from = isset($filters['created_from']) ? $filters['created_from'] : '';
$filter_created_to = isset($filters['created_to']) ? $filters['created_to'] : '';
$filter_validity_from = isset($filters['validity_from']) ? $filters['validity_from'] : '';
$filter_validity_to = isset($filters['validity_to']) ? $filters['validity_to'] : '';
$sales_agents = isset($sales_agents) ? $sales_agents : array();
$has_filter = ($filter_search !== '' || $filter_tour_code !== '' || $filter_status !== '' || $filter_customer_name !== '' || $filter_customer_contact !== '' || $filter_customer_email !== '' || $filter_sales_admin_id > 0 || $filter_op_admin_id > 0 || $filter_created_from !== '' || $filter_created_to !== '' || $filter_validity_from !== '' || $filter_validity_to !== '');

// Carry the active listing filters onto the Excel export link so the download
// mirrors exactly what is on screen.
$export_params = array_filter(array(
    'search' => $filter_search,
    'tour_code' => $filter_tour_code,
    'status' => $filter_status,
    'customer_name' => $filter_customer_name,
    'customer_contact' => $filter_customer_contact,
    'customer_email' => $filter_customer_email,
    'sales_admin_id' => $filter_sales_admin_id > 0 ? $filter_sales_admin_id : '',
    'op_admin_id' => $filter_op_admin_id > 0 ? $filter_op_admin_id : '',
    'created_from' => $filter_created_from,
    'created_to' => $filter_created_to,
    'validity_from' => $filter_validity_from,
    'validity_to' => $filter_validity_to,
), function ($value) { return $value !== '' && $value !== null; });
$export_qs = !empty($export_params) ? ('?' . http_build_query($export_params)) : '';
?>

<style>
    /* Keep the Package Name column a sensible width and stop long descriptions
       from ballooning the row height. The full text stays available on hover. */
    .costing-pkg-name-cell {
        min-width: 220px;
        max-width: 300px;
        vertical-align: top;
    }
    .costing-pkg-name {
        word-break: break-word;
    }
    .costing-pkg-desc {
        margin-top: 4px;
        font-size: 0.85rem;
        line-height: 1.35;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
        word-break: break-word;
    }
    /* Keep all action icons on a single row and give the column enough width
       to hold them so none overflow the cell border. */
    table.table th.action,
    table.table td:last-child {
        min-width: 260px;
        white-space: nowrap;
    }
    .costing-action-buttons {
        flex-wrap: nowrap;
    }
    .costing-action-buttons .btn {
        flex: 0 0 auto;
    }
    .costing-action-buttons form {
        margin: 0;
    }
    /* Keep the full tour code (incl. "-REVnn" revisions) on one line so it is never
       cropped, and drop the REV badge onto its own line beneath it. */
    table.table td.costing-tourcode-cell {
        min-width: 140px;
        white-space: nowrap;
    }
    .costing-tourcode {
        white-space: nowrap;
    }
    .costing-tourcode-rev {
        display: block;
        margin-top: 4px;
    }
</style>

<div class="d-flex flex-column-fluid">
    <div class="container-fluid">
        <div class="card card-custom mb-5">
            <div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
                <div class="card-title">
                    <h3 class="card-label" style="color:#6082B6;">
                        <strong>Costing Package Records</strong>
                    </h3>
                </div>
                <div class="card-toolbar">
                    <a href="<?php echo base_url('Costing/Create'); ?>" class="btn btn-primary font-weight-bold mr-2">
                        <i class="la la-plus"></i>Create Package
                    </a>
                    <a href="<?php echo base_url('Costing/Currency'); ?>" class="btn btn-light-primary font-weight-bold mr-2">
                        <i class="la la-coins"></i>Currency Setup
                    </a>
                    <a href="<?php echo base_url('Costing/Export_Packages') . $export_qs; ?>" class="btn btn-light-success font-weight-bold mr-2" data-toggle="tooltip" title="Export all packages with full detail to Excel">
                        <i class="la la-file-excel"></i>Export Excel
                    </a>
                    <a href="<?php echo base_url('Costing/Import_Template'); ?>" class="btn btn-light-info font-weight-bold mr-2" data-toggle="tooltip" title="Download the Excel template for bulk package upload">
                        <i class="la la-file-download"></i>Import Template
                    </a>
                    <button type="button" class="btn btn-info font-weight-bold" data-toggle="modal" data-target="#costing_import_modal" title="Upload a filled template to create packages">
                        <i class="la la-file-import"></i>Import Excel
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if ($this->session->flashdata('message_success')) { ?>
                    <div class="alert alert-success"><?php echo html_escape($this->session->flashdata('message_success')); ?></div>
                <?php } ?>
                <?php if ($this->session->flashdata('message_error')) { ?>
                    <div class="alert alert-danger"><?php echo html_escape($this->session->flashdata('message_error')); ?></div>
                <?php } ?>
                <?php if ($this->session->flashdata('import_result')) { ?>
                    <div class="alert alert-warning">
                        <strong>Import notes:</strong>
                        <ul class="mb-0 mt-2">
                            <?php foreach ((array) $this->session->flashdata('import_result') as $line) { ?>
                                <li><?php echo html_escape($line); ?></li>
                            <?php } ?>
                        </ul>
                    </div>
                <?php } ?>

                <div class="mb-6">
                    <div class="font-size-h5 font-weight-bold text-dark mb-2">Packages</div>
                    <div class="text-muted">Show package records only. Open a package to manage costing breakdown, booking snapshots, and calculations.</div>
                </div>

                <div class="accordion accordion-solid accordion-toggle-plus mb-6">
                    <div class="card">
                        <div class="card-header">
                            <div id="costing_filter_header" data-toggle="collapse" data-target="#costing_filter_body" class="card-title <?php echo $has_filter ? '' : 'collapsed'; ?>" style="font-size:13px;">Filter Packages</div>
                        </div>
                        <div id="costing_filter_body" class="collapse <?php echo $has_filter ? 'show' : ''; ?>">
                            <div class="card-body">
                                <form method="get" action="<?php echo base_url('Costing'); ?>">
                                    <div class="form-group row mb-4">
                                        <div class="col-md-4">
                                            <label class="font-weight-bold">Search</label>
                                            <input type="text" name="search" value="<?php echo html_escape($filter_search); ?>" class="form-control" placeholder="Package name, tour code or description">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="font-weight-bold">Tour Code</label>
                                            <input type="text" name="tour_code" value="<?php echo html_escape($filter_tour_code); ?>" class="form-control" placeholder="Tour code">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="font-weight-bold">Status</label>
                                            <select name="status" class="form-control">
                                                <option value="">All</option>
                                                <option value="active" <?php echo ($filter_status === 'active') ? 'selected' : ''; ?>>Active</option>
                                                <option value="inactive" <?php echo ($filter_status === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="font-weight-bold">Sales Person</label>
                                            <select name="sales_admin_id" class="form-control">
                                                <option value="">All</option>
                                                <?php foreach ($sales_agents as $agent) { ?>
                                                    <option value="<?php echo (int) $agent['AdminID']; ?>" <?php echo ($filter_sales_admin_id === (int) $agent['AdminID']) ? 'selected' : ''; ?>><?php echo html_escape($agent['Name']); ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="font-weight-bold">OP Person</label>
                                            <select name="op_admin_id" class="form-control">
                                                <option value="">All</option>
                                                <?php foreach ($sales_agents as $agent) { ?>
                                                    <option value="<?php echo (int) $agent['AdminID']; ?>" <?php echo ($filter_op_admin_id === (int) $agent['AdminID']) ? 'selected' : ''; ?>><?php echo html_escape($agent['Name']); ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group row mb-4">
                                        <div class="col-md-3">
                                            <label class="font-weight-bold">Created Date</label>
                                            <?php
                                            $created_range_display = '';
                                            if ($filter_created_from !== '' || $filter_created_to !== '') {
                                                $from_disp = $filter_created_from !== '' ? date('d/m/Y', strtotime($filter_created_from)) : '';
                                                $to_disp = $filter_created_to !== '' ? date('d/m/Y', strtotime($filter_created_to)) : $from_disp;
                                                if ($from_disp === '') { $from_disp = $to_disp; }
                                                $created_range_display = $from_disp . ' - ' . $to_disp;
                                            }
                                            ?>
                                            <input type="text" id="costing-created-range" class="form-control" placeholder="Select date range" value="<?php echo html_escape($created_range_display); ?>" autocomplete="off" readonly>
                                            <input type="hidden" id="costing-created-from" name="created_from" value="<?php echo html_escape($filter_created_from); ?>">
                                            <input type="hidden" id="costing-created-to" name="created_to" value="<?php echo html_escape($filter_created_to); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="font-weight-bold">Validity Date</label>
                                            <?php
                                            $validity_range_display = '';
                                            if ($filter_validity_from !== '' || $filter_validity_to !== '') {
                                                $vfrom_disp = $filter_validity_from !== '' ? date('d/m/Y', strtotime($filter_validity_from)) : '';
                                                $vto_disp = $filter_validity_to !== '' ? date('d/m/Y', strtotime($filter_validity_to)) : $vfrom_disp;
                                                if ($vfrom_disp === '') { $vfrom_disp = $vto_disp; }
                                                $validity_range_display = $vfrom_disp . ' - ' . $vto_disp;
                                            }
                                            ?>
                                            <input type="text" id="costing-validity-range" class="form-control" placeholder="Select date range" value="<?php echo html_escape($validity_range_display); ?>" autocomplete="off" readonly>
                                            <input type="hidden" id="costing-validity-from" name="validity_from" value="<?php echo html_escape($filter_validity_from); ?>">
                                            <input type="hidden" id="costing-validity-to" name="validity_to" value="<?php echo html_escape($filter_validity_to); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="font-weight-bold">Customer Name</label>
                                            <input type="text" name="customer_name" value="<?php echo html_escape($filter_customer_name); ?>" class="form-control" placeholder="Customer name">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="font-weight-bold">Contact Number</label>
                                            <input type="text" name="customer_contact" value="<?php echo html_escape($filter_customer_contact); ?>" class="form-control" placeholder="Contact number">
                                        </div>
                                        <div class="col-md-3 mt-4">
                                            <label class="font-weight-bold">Email Address</label>
                                            <input type="text" name="customer_email" value="<?php echo html_escape($filter_customer_email); ?>" class="form-control" placeholder="Email address">
                                        </div>
                                    </div>
                                    <div class="form-group row align-items-end mb-0">
                                        <div class="col-md-12">
                                            <button type="submit" class="btn btn-primary font-weight-bold mr-2">
                                                <i class="la la-search"></i>Filter
                                            </button>
                                            <?php if ($has_filter) { ?>
                                                <a href="<?php echo base_url('Costing'); ?>" class="btn btn-light font-weight-bold">
                                                    <i class="la la-times"></i>Clear
                                                </a>
                                            <?php } ?>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="dataTables_wrapper dt-bootstrap4 no-footer" style="overflow-x:auto;">
                    <table class="table table-bordered table-head-custom table-checkable dataTable no-footer dtr-inline">
                        <thead>
                            <tr>
                                <th style="text-align:center;">No.</th>
                                <th style="text-align:center;">Package Name</th>
                                <th style="text-align:center;">Customer Name</th>
                                <th style="text-align:center;">Contact Number</th>
                                <th style="text-align:center;">Email Address</th>
                                <th style="text-align:center;">Sales Person</th>
                                <th style="text-align:center;">OP Person</th>
                                <th style="text-align:center;">Tour Code</th>
                                <th style="text-align:center;">Duration</th>
                                <th style="text-align:center;">Status</th>
                                <th style="text-align:center;">Validity</th>
                                <th style="text-align:center;">Created At</th>
                                <th style="text-align:center;">Updated At</th>
                                <th class="action" style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($packages)) { ?>
                                <tr>
                                    <td colspan="14" style="text-align:center; padding-top:10px; padding-bottom:10px;">Costing Package Records Not Found</td>
                                </tr>
                            <?php } else { ?>
                                <?php $count = 1; ?>
                                <?php foreach ($packages as $package) { ?>
                                    <tr>
                                        <td style="text-align:center; padding-top:15px; padding-bottom:15px;"><?php echo $count; ?></td>
                                        <td class="costing-pkg-name-cell">
                                            <div class="font-weight-bold costing-pkg-name"><?php echo html_escape($package['name']); ?></div>
                                            <?php if (!empty($package['description'])) { ?>
                                                <div class="text-muted costing-pkg-desc" title="<?php echo html_escape($package['description']); ?>"><?php echo html_escape($package['description']); ?></div>
                                            <?php } ?>
                                        </td>
                                        <td><?php echo !empty($package['customer_name']) ? html_escape($package['customer_name']) : '-'; ?></td>
                                        <td style="text-align:center;"><?php echo !empty($package['customer_contact']) ? html_escape($package['customer_contact']) : '-'; ?></td>
                                        <td><?php echo !empty($package['customer_email']) ? html_escape($package['customer_email']) : '-'; ?></td>
                                        <td style="text-align:center;"><?php echo !empty($package['sales_admin_name']) ? html_escape($package['sales_admin_name']) : '-'; ?></td>
                                        <td style="text-align:center;"><?php echo !empty($package['op_admin_name']) ? html_escape($package['op_admin_name']) : '-'; ?></td>
                                        <td class="costing-tourcode-cell" style="text-align:center;">
                                            <span class="costing-tourcode"><?php echo !empty($package['tour_code']) ? html_escape($package['tour_code']) : '-'; ?></span>
                                            <?php if (!empty($package['revision_number'])) { ?>
                                                <span class="costing-tourcode-rev"><span class="label label-sm label-light-warning label-inline">REV<?php echo str_pad((string) (int) $package['revision_number'], 2, '0', STR_PAD_LEFT); ?></span></span>
                                            <?php } ?>
                                            <?php if (!empty($package['revisions']) && count($package['revisions']) > 1) { ?>
                                                <div class="mt-2">
                                                    <a href="#" class="text-muted font-size-sm revision-history-toggle" data-target="revisions-<?php echo (int) $package['id']; ?>">
                                                        <i class="la la-history"></i> <?php echo count($package['revisions']); ?> versions
                                                    </a>
                                                    <div id="revisions-<?php echo (int) $package['id']; ?>" class="revision-history-list text-left mt-2" style="display:none;">
                                                        <?php foreach ($package['revisions'] as $rev) { ?>
                                                            <div class="d-flex align-items-center justify-content-between py-1" style="border-top:1px solid #ebedf3;">
                                                                <span class="font-size-sm">
                                                                    <?php echo html_escape($rev['tour_code']); ?>
                                                                    <span class="text-muted">(<?php echo html_escape(ucfirst((string) $rev['status'])); ?>)</span>
                                                                </span>
                                                                <span class="text-nowrap">
                                                                    <a href="<?php echo base_url('Costing/Package/' . (int) $rev['id']); ?>" class="btn btn-icon btn-light-primary btn-xs mr-1" title="View this version">
                                                                        <i class="la la-eye"></i>
                                                                    </a>
                                                                    <?php if (!empty($rev['quotation_token']) && strtolower((string) $rev['status']) !== 'inactive') { ?>
                                                                        <a href="<?php echo base_url('Costing/Quotation/' . $rev['quotation_token']); ?>" target="_blank" class="btn btn-icon btn-light-success btn-xs" title="Open Quotation PDF">
                                                                            <i class="la la-file-pdf"></i>
                                                                        </a>
                                                                    <?php } ?>
                                                                </span>
                                                            </div>
                                                        <?php } ?>
                                                    </div>
                                                </div>
                                            <?php } ?>
                                        </td>
                                        <td style="text-align:center;"><?php echo (int) $package['duration_days']; ?>D / <?php echo (int) $package['duration_nights']; ?>N</td>
                                        <td style="text-align:center;">
                                            <span class="label label-lg label-light-primary label-inline"><?php echo html_escape($package['status_label']); ?></span>
                                        </td>
                                        <td style="text-align:center;"><?php echo !empty($package['validity_date']) ? date('Y-m-d', strtotime($package['validity_date'])) : '-'; ?></td>
                                        <td style="text-align:center;"><?php echo !empty($package['created_at']) ? date('d/m/Y H:i', strtotime($package['created_at'])) : 'N/A'; ?></td>
                                        <td style="text-align:center;"><?php echo !empty($package['updated_at']) ? date('d/m/Y H:i', strtotime($package['updated_at'])) : 'N/A'; ?></td>
                                        <td>
                                            <div class="d-flex align-items-center justify-content-center costing-action-buttons" style="gap:6px;">
                                            <a href="<?php echo base_url('Costing/Package/' . (int) $package['id']); ?>" class="btn btn-icon btn-light-primary btn-sm" title="View Package">
                                                <i class="la la-eye"></i>
                                            </a>
                                            <?php if (!empty($package['latest_quotation_token']) && strtolower((string) $package['status']) !== 'inactive') { ?>
                                                <a href="<?php echo base_url('Costing/Quotation/' . $package['latest_quotation_token']); ?>" target="_blank" class="btn btn-icon btn-light-success btn-sm" title="Open Quotation PDF">
                                                    <i class="la la-file-pdf"></i>
                                                </a>
                                            <?php } ?>
                                            <form method="post" action="<?php echo base_url('Costing/Duplicate_Package'); ?>" class="d-inline duplicate-package-form">
                                                <input type="hidden" name="package_id" value="<?php echo (int) $package['id']; ?>">
                                                <button type="button" class="btn btn-icon btn-light-info btn-sm duplicate-package-button" data-name="<?php echo html_escape($package['name']); ?>" title="Duplicate Package">
                                                    <i class="la la-copy"></i>
                                                </button>
                                            </form>
                                            <form method="post" action="<?php echo base_url('Costing/Create_Revision'); ?>" class="d-inline revision-package-form">
                                                <input type="hidden" name="package_id" value="<?php echo (int) $package['id']; ?>">
                                                <button type="button" class="btn btn-icon btn-light-warning btn-sm revision-package-button" data-name="<?php echo html_escape($package['tour_code'] !== '' ? $package['tour_code'] : $package['name']); ?>" title="Create Revision">
                                                    <i class="la la-code-branch"></i>
                                                </button>
                                            </form>
                                            <form method="post" action="<?php echo base_url('Costing/Delete_Package'); ?>" class="d-inline delete-package-form">
                                                <input type="hidden" name="package_id" value="<?php echo (int) $package['id']; ?>">
                                                <button type="button" class="btn btn-icon btn-light-danger btn-sm delete-package-button" data-name="<?php echo html_escape($package['name']); ?>" title="Delete Package">
                                                    <i class="la la-trash"></i>
                                                </button>
                                            </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php $count++; ?>
                                <?php } ?>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        document.querySelectorAll('.delete-package-button').forEach(function (button) {
            button.addEventListener('click', function () {
                var form = button.closest('form');
                var packageName = button.getAttribute('data-name') || 'this package';

                if (typeof Swal === 'undefined') {
                    if (confirm('Delete package: ' + packageName + '?')) {
                        form.submit();
                    }
                    return;
                }

                var swalWithBootstrapButtons = Swal.mixin({
                    customClass: {
                        confirmButton: 'btn btn-danger',
                        cancelButton: 'btn btn-light-primary'
                    },
                    buttonsStyling: false
                });

                swalWithBootstrapButtons.fire({
                    title: 'Delete Package: ' + packageName + '?',
                    text: 'This will delete the package and all related costing records.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Delete',
                    cancelButtonText: 'Cancel',
                    reverseButtons: true
                }).then(function (result) {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            });
        });

        document.querySelectorAll('.duplicate-package-button').forEach(function (button) {
            button.addEventListener('click', function () {
                var form = button.closest('form');
                var packageName = button.getAttribute('data-name') || 'this package';

                if (typeof Swal === 'undefined') {
                    if (confirm('Duplicate package: ' + packageName + '?')) {
                        form.submit();
                    }
                    return;
                }

                var swalWithBootstrapButtons = Swal.mixin({
                    customClass: {
                        confirmButton: 'btn btn-primary',
                        cancelButton: 'btn btn-light-primary'
                    },
                    buttonsStyling: false
                });

                swalWithBootstrapButtons.fire({
                    title: 'Duplicate Package: ' + packageName + '?',
                    text: 'This creates a new package copying the costing, itinerary, hotel and flight details.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Duplicate',
                    cancelButtonText: 'Cancel',
                    reverseButtons: true
                }).then(function (result) {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            });
        });

        document.querySelectorAll('.revision-package-button').forEach(function (button) {
            button.addEventListener('click', function () {
                var form = button.closest('form');
                var packageName = button.getAttribute('data-name') || 'this package';

                if (typeof Swal === 'undefined') {
                    if (confirm('Create a new revision of: ' + packageName + '?')) {
                        form.submit();
                    }
                    return;
                }

                var swalWithBootstrapButtons = Swal.mixin({
                    customClass: {
                        confirmButton: 'btn btn-warning',
                        cancelButton: 'btn btn-light-primary'
                    },
                    buttonsStyling: false
                });

                swalWithBootstrapButtons.fire({
                    title: 'Create Revision of: ' + packageName + '?',
                    text: 'This creates a new editable version (REVnn) that keeps the base quotation number, copied from the latest version.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Create Revision',
                    cancelButtonText: 'Cancel',
                    reverseButtons: true
                }).then(function (result) {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            });
        });

        document.querySelectorAll('.revision-history-toggle').forEach(function (toggle) {
            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                var target = document.getElementById(toggle.getAttribute('data-target'));
                if (target) {
                    target.style.display = (target.style.display === 'none' || target.style.display === '') ? 'block' : 'none';
                }
            });
        });
    })();
</script>

<!-- Bulk Import Costing Packages (Excel) -->
<div class="modal fade" id="costing_import_modal" tabindex="-1" role="dialog" aria-labelledby="costing_import_label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form action="<?php echo base_url('Costing/Import'); ?>" method="post" enctype="multipart/form-data" id="costing_import_form">
            <div class="modal-content">
                <div class="modal-header" style="background-color:#D7E2F2;">
                    <h5 class="modal-title" id="costing_import_label" style="color:#6082B6;"><strong>Import Costing Packages</strong></h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light-primary" role="alert" style="border-left:4px solid #6082B6;">
                        Download <strong>Import Template</strong> first. Fill the <strong>Packages</strong> sheet (one package per row) and attach cost items, combinations, hotels and flights on the other sheets using the same <strong>Package Name</strong>. The <strong>Instructions</strong> sheet lists the valid category / currency codes.
                    </div>
                    <div class="alert alert-light-warning" role="alert" style="border-left:4px solid #b58100;">
                        <strong>New revision?</strong> Use <strong>Export Excel</strong> on an existing package, edit it, and re-upload — rows whose <strong>Tour Code</strong> still matches are offered as a <strong>new revision</strong> of that package on the confirmation screen (you can switch any back to a new package).
                    </div>
                    <div class="form-group">
                        <label>Excel File <span class="text-danger">*</span></label>
                        <div class="custom-file">
                            <input type="file" name="import_file" class="custom-file-input" id="costing_import_file" accept=".xlsx,.xls" required>
                            <label class="custom-file-label" for="costing_import_file" id="costing_import_file_label">Choose .xlsx / .xls file</label>
                        </div>
                        <span class="form-text text-muted">You'll see a confirmation with any errors before anything is created. Each package needs pax &gt; 0 and at least one cost item. The last 3 uploads are kept as backups.</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light font-weight-bold" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info font-weight-bold" id="costing_import_submit">
                        <i class="la la-search"></i>Preview &amp; Validate
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
    $('#costing_import_file').on('change', function() {
        var name = (this.files && this.files.length) ? this.files[0].name : 'Choose .xlsx / .xls file';
        $('#costing_import_file_label').text(name);
    });
    $('#costing_import_form').on('submit', function() {
        $('#costing_import_submit').prop('disabled', true).html('<i class="la la-spinner la-spin"></i>Validating...');
    });

    // Single "Created Date" field: one daterangepicker feeds two hidden inputs
    // (created_from / created_to in ISO) that the controller/model still read.
    jQuery(function () {
        var $range = jQuery('#costing-created-range');
        if (!$range.length || !jQuery.fn.daterangepicker) { return; }
        var fromVal = jQuery('#costing-created-from').val();
        var toVal = jQuery('#costing-created-to').val();
        $range.daterangepicker({
            autoUpdateInput: false,
            startDate: fromVal ? moment(fromVal, 'YYYY-MM-DD') : moment(),
            endDate: toVal ? moment(toVal, 'YYYY-MM-DD') : moment(),
            locale: { format: 'DD/MM/YYYY', cancelLabel: 'Clear' },
            buttonClasses: ' btn',
            applyClass: 'btn-primary',
            cancelClass: 'btn-secondary'
        });
        $range.on('apply.daterangepicker', function (ev, picker) {
            jQuery(this).val(picker.startDate.format('DD/MM/YYYY') + ' - ' + picker.endDate.format('DD/MM/YYYY'));
            jQuery('#costing-created-from').val(picker.startDate.format('YYYY-MM-DD'));
            jQuery('#costing-created-to').val(picker.endDate.format('YYYY-MM-DD'));
        });
        $range.on('cancel.daterangepicker', function () {
            jQuery(this).val('');
            jQuery('#costing-created-from').val('');
            jQuery('#costing-created-to').val('');
        });
    });

    // Validity Date range: same single-field daterangepicker pattern feeding the
    // validity_from / validity_to hidden inputs (filter on cp.validity_date).
    jQuery(function () {
        var $range = jQuery('#costing-validity-range');
        if (!$range.length || !jQuery.fn.daterangepicker) { return; }
        var fromVal = jQuery('#costing-validity-from').val();
        var toVal = jQuery('#costing-validity-to').val();
        $range.daterangepicker({
            autoUpdateInput: false,
            startDate: fromVal ? moment(fromVal, 'YYYY-MM-DD') : moment(),
            endDate: toVal ? moment(toVal, 'YYYY-MM-DD') : moment(),
            locale: { format: 'DD/MM/YYYY', cancelLabel: 'Clear' },
            buttonClasses: ' btn',
            applyClass: 'btn-primary',
            cancelClass: 'btn-secondary'
        });
        $range.on('apply.daterangepicker', function (ev, picker) {
            jQuery(this).val(picker.startDate.format('DD/MM/YYYY') + ' - ' + picker.endDate.format('DD/MM/YYYY'));
            jQuery('#costing-validity-from').val(picker.startDate.format('YYYY-MM-DD'));
            jQuery('#costing-validity-to').val(picker.endDate.format('YYYY-MM-DD'));
        });
        $range.on('cancel.daterangepicker', function () {
            jQuery(this).val('');
            jQuery('#costing-validity-from').val('');
            jQuery('#costing-validity-to').val('');
        });
    });
</script>
