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
$sales_agents = isset($sales_agents) ? $sales_agents : array();
$has_filter = ($filter_search !== '' || $filter_tour_code !== '' || $filter_status !== '' || $filter_customer_name !== '' || $filter_customer_contact !== '' || $filter_customer_email !== '' || $filter_sales_admin_id > 0);
?>

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
                    <a href="<?php echo base_url('Costing/Currency'); ?>" class="btn btn-light-primary font-weight-bold">
                        <i class="la la-coins"></i>Currency Setup
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if ($this->session->flashdata('message_success')) { ?>
                    <div class="alert alert-success"><?php echo html_escape($this->session->flashdata('message_success')); ?></div>
                <?php } ?>
                <?php if ($this->session->flashdata('message_error')) { ?>
                    <div class="alert alert-danger"><?php echo html_escape($this->session->flashdata('message_error')); ?></div>
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
                                        <div class="col-md-3">
                                            <label class="font-weight-bold">Status</label>
                                            <select name="status" class="form-control">
                                                <option value="">All</option>
                                                <option value="active" <?php echo ($filter_status === 'active') ? 'selected' : ''; ?>>Active</option>
                                                <option value="inactive" <?php echo ($filter_status === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="font-weight-bold">Sales Person</label>
                                            <select name="sales_admin_id" class="form-control">
                                                <option value="">All</option>
                                                <?php foreach ($sales_agents as $agent) { ?>
                                                    <option value="<?php echo (int) $agent['AdminID']; ?>" <?php echo ($filter_sales_admin_id === (int) $agent['AdminID']) ? 'selected' : ''; ?>><?php echo html_escape($agent['Name']); ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group row align-items-end mb-0">
                                        <div class="col-md-3">
                                            <label class="font-weight-bold">Customer Name</label>
                                            <input type="text" name="customer_name" value="<?php echo html_escape($filter_customer_name); ?>" class="form-control" placeholder="Customer name">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="font-weight-bold">Contact Number</label>
                                            <input type="text" name="customer_contact" value="<?php echo html_escape($filter_customer_contact); ?>" class="form-control" placeholder="Contact number">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="font-weight-bold">Email Address</label>
                                            <input type="text" name="customer_email" value="<?php echo html_escape($filter_customer_email); ?>" class="form-control" placeholder="Email address">
                                        </div>
                                        <div class="col-md-3">
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

                <div class="dataTables_wrapper dt-bootstrap4 no-footer" <?php if (empty($packages)) { echo 'style="overflow-x:auto;"'; } ?>>
                    <table class="table table-bordered table-head-custom table-checkable dataTable no-footer dtr-inline">
                        <thead>
                            <tr>
                                <th style="text-align:center;">No.</th>
                                <th style="text-align:center;">Package Name</th>
                                <th style="text-align:center;">Customer Name</th>
                                <th style="text-align:center;">Contact Number</th>
                                <th style="text-align:center;">Email Address</th>
                                <th style="text-align:center;">Sales Person</th>
                                <th style="text-align:center;">Tour Code</th>
                                <th style="text-align:center;">Duration</th>
                                <th style="text-align:center;">Status</th>
                                <th style="text-align:center;">Updated At</th>
                                <th class="action" style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($packages)) { ?>
                                <tr>
                                    <td colspan="11" style="text-align:center; padding-top:10px; padding-bottom:10px;">Costing Package Records Not Found</td>
                                </tr>
                            <?php } else { ?>
                                <?php $count = 1; ?>
                                <?php foreach ($packages as $package) { ?>
                                    <tr>
                                        <td style="text-align:center; padding-top:15px; padding-bottom:15px;"><?php echo $count; ?></td>
                                        <td>
                                            <div class="font-weight-bold"><?php echo html_escape($package['name']); ?></div>
                                            <div class="text-muted"><?php echo html_escape($package['description']); ?></div>
                                        </td>
                                        <td><?php echo !empty($package['customer_name']) ? html_escape($package['customer_name']) : '-'; ?></td>
                                        <td style="text-align:center;"><?php echo !empty($package['customer_contact']) ? html_escape($package['customer_contact']) : '-'; ?></td>
                                        <td><?php echo !empty($package['customer_email']) ? html_escape($package['customer_email']) : '-'; ?></td>
                                        <td style="text-align:center;"><?php echo !empty($package['sales_admin_name']) ? html_escape($package['sales_admin_name']) : '-'; ?></td>
                                        <td style="text-align:center;">
                                            <?php echo !empty($package['tour_code']) ? html_escape($package['tour_code']) : '-'; ?>
                                            <?php if (!empty($package['revision_number'])) { ?>
                                                <span class="label label-sm label-light-warning label-inline ml-1">REV<?php echo str_pad((string) (int) $package['revision_number'], 2, '0', STR_PAD_LEFT); ?></span>
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
                                        <td style="text-align:center;"><?php echo !empty($package['updated_at']) ? date('d/m/Y H:i', strtotime($package['updated_at'])) : 'N/A'; ?></td>
                                        <td>
                                            <div class="d-flex align-items-center justify-content-start" style="gap:6px;">
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
