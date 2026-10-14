<?php
$categories = isset($categories) ? $categories : array();
$category_filters = isset($category_filters) ? $category_filters : array('name' => '');
$fallback_code = isset($fallback_code) ? $fallback_code : 'miscellaneous';
$filter_open = !empty($category_filters['name']);
?>

<style>
    .costing-cat-toolbar { gap: 10px; }
    .costing-cat-code { font-family: monospace; }
</style>

<div class="d-flex flex-column-fluid">
    <div class="container-fluid">
        <div class="card card-custom mb-5">
            <div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
                <div class="card-title">
                    <h3 class="card-label" style="color:#6082B6;"><strong>Costing Category Master</strong></h3>
                </div>
                <div class="card-toolbar costing-cat-toolbar d-flex flex-wrap">
                    <a href="<?php echo base_url('Costing'); ?>" class="btn btn-light-primary font-weight-bold">
                        <i class="la la-arrow-left"></i>Back To Packages
                    </a>
                    <button type="button" class="btn btn-primary font-weight-bold" id="add_category_button" data-toggle="modal" data-target="#category_modal">
                        <i class="la la-plus"></i>Add Category
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

                <div class="d-flex justify-content-between align-items-start flex-wrap mb-6">
                    <div class="mb-3">
                        <div class="font-size-h5 font-weight-bold text-dark mb-2">Category Setup</div>
                        <div class="text-muted">Cost categories used to group items when building a package cost template. Add or rename freely; a category in use by items cannot be deleted.</div>
                    </div>
                </div>

                <div class="accordion accordion-solid accordion-toggle-plus mb-6">
                    <div class="card">
                        <div class="card-header">
                            <div data-toggle="collapse" data-target="#category_filter_body" class="card-title <?php echo $filter_open ? '' : 'collapsed'; ?>" style="font-size:13px;">Filter Categories</div>
                        </div>
                        <div id="category_filter_body" class="collapse <?php echo $filter_open ? 'show' : ''; ?>">
                            <div class="card-body">
                                <form action="<?php echo base_url('Costing_Category'); ?>" method="get" class="form">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Name</label>
                                                <input type="text" name="category_name" value="<?php echo html_escape($category_filters['name']); ?>" autocomplete="off" class="form-control">
                                            </div>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-light-success font-weight-bold" style="width:80px;">Filter</button>
                                    <a href="<?php echo base_url('Costing_Category'); ?>" class="btn btn-light-primary font-weight-bold" style="width:80px;">Reset</a>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="dataTables_wrapper dt-bootstrap4 no-footer">
                    <table class="table table-bordered table-head-custom table-checkable dataTable no-footer">
                        <thead>
                            <tr>
                                <th style="text-align:center; width:70px;">No.</th>
                                <th style="text-align:center;">Category Name</th>
                                <th style="text-align:center; width:220px;">Code</th>
                                <th style="text-align:center; width:160px;">Headcount No.</th>
                                <th style="text-align:center; width:140px;">No Multiply</th>
                                <th class="action" style="text-align:center; width:200px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($categories)) { ?>
                                <tr><td colspan="6" style="text-align:center; padding:16px;">Categories Not Found</td></tr>
                            <?php } else { $count = 1; ?>
                                <?php foreach ($categories as $category) { $is_fallback = ((string) $category['code'] === $fallback_code); ?>
                                    <tr>
                                        <td style="text-align:center; padding:16px 8px;"><?php echo $count; ?></td>
                                        <td><div class="font-weight-bold text-dark"><?php echo html_escape($category['name']); ?></div></td>
                                        <td style="text-align:center;">
                                            <span class="label label-lg label-light-primary label-inline costing-cat-code"><?php echo html_escape($category['code']); ?></span>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if (!empty($category['has_headcount_multiplier'])) { ?>
                                                <span class="label label-lg label-light-success label-inline" title="This category shows a 'No. of …' count per combination that scales its cost">On</span>
                                            <?php } else { ?>
                                                <span class="text-muted">—</span>
                                            <?php } ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if (!empty($category['no_multiplier'])) { ?>
                                                <span class="label label-lg label-light-info label-inline" title="Rows in this category are not multiplied by day/pax — Total = Cost; carried flat (marked up) in the quotation">On</span>
                                            <?php } else { ?>
                                                <span class="text-muted">—</span>
                                            <?php } ?>
                                        </td>
                                        <td>
                                            <div class="d-flex justify-content-center flex-wrap" style="gap:6px;">
                                                <button type="button" class="btn btn-sm btn-light-primary font-weight-bold edit-category" data-category="<?php echo html_escape(json_encode($category)); ?>">Edit</button>
                                                <?php if ($is_fallback) { ?>
                                                    <button type="button" class="btn btn-sm btn-light-secondary font-weight-bold" disabled title="The fallback category cannot be deleted">Delete</button>
                                                <?php } else { ?>
                                                    <form method="post" action="<?php echo base_url('Costing_Category/Delete_Category'); ?>" class="delete-category-form">
                                                        <input type="hidden" name="category_id" value="<?php echo (int) $category['id']; ?>">
                                                        <button type="button" class="btn btn-sm btn-light-danger font-weight-bold delete-category-button" data-category-name="<?php echo html_escape($category['name']); ?>">Delete</button>
                                                    </form>
                                                <?php } ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php $count++; } ?>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="category_modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form method="post" action="<?php echo base_url('Costing_Category/Save_Category'); ?>" id="category_form_modal">
                <input type="hidden" name="category_id" id="modal_category_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="category_modal_title">Add Category</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><i aria-hidden="true" class="ki ki-close"></i></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" name="name" id="modal_category_name" class="form-control" required>
                        <span class="form-text text-muted">A short code is generated automatically on create and stays fixed after.</span>
                    </div>
                    <div class="form-group">
                        <label>Headcount Multiplier</label>
                        <div class="checkbox-inline" style="margin-top:8px;">
                            <label class="checkbox checkbox-lg">
                                <input type="checkbox" name="has_headcount_multiplier" id="modal_category_headcount" value="1"><span></span> Show a "No. of …" count (e.g. <strong>No. of Tour Leaders</strong>)
                            </label>
                        </div>
                        <span class="form-text text-muted">When on, each combination shows a count next to this category's section that multiplies its cost in the summary &amp; quotation PDF (the cost rows stay unchanged).</span>
                    </div>
                    <div class="form-group mb-0">
                        <label>No Multiply (Total = Cost)</label>
                        <div class="checkbox-inline" style="margin-top:8px;">
                            <label class="checkbox checkbox-lg">
                                <input type="checkbox" name="no_multiplier" id="modal_category_no_multiply" value="1"><span></span> Don't multiply by day/pax — <strong>Total = Cost</strong>
                            </label>
                        </div>
                        <span class="form-text text-muted">When on, cost rows in this category are NOT multiplied by the day/pax count — each row's Total equals its MYR-converted cost, and the amount is carried as a flat charge (marked up by the margin) in the quotation rather than divided per pax.</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light-primary font-weight-bold" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    (function () {
        var title = document.getElementById('category_modal_title');
        var idInput = document.getElementById('modal_category_id');
        var nameInput = document.getElementById('modal_category_name');
        var headcountInput = document.getElementById('modal_category_headcount');
        var noMultiplyInput = document.getElementById('modal_category_no_multiply');

        function resetModal() {
            title.textContent = 'Add Category';
            idInput.value = '';
            nameInput.value = '';
            headcountInput.checked = false;
            noMultiplyInput.checked = false;
        }

        document.getElementById('add_category_button').addEventListener('click', resetModal);
        $('#category_modal').on('hidden.bs.modal', resetModal);

        $(document).on('click', '.edit-category', function () {
            try {
                var category = JSON.parse(this.getAttribute('data-category'));
                title.textContent = 'Edit Category';
                idInput.value = category.id || '';
                nameInput.value = category.name || '';
                headcountInput.checked = (String(category.has_headcount_multiplier) === '1');
                noMultiplyInput.checked = (String(category.no_multiplier) === '1');
                $('#category_modal').modal('show');
            } catch (e) { console.error('Unable to read category data.', e); }
        });

        $(document).on('click', '.delete-category-button', function () {
            var form = this.closest('form');
            var name = this.getAttribute('data-category-name') || 'this category';
            if (!form) { return; }
            if (typeof Swal === 'undefined') { if (confirm('Delete ' + name + '?')) { form.submit(); } return; }
            Swal.mixin({ customClass: { confirmButton: 'btn btn-danger', cancelButton: 'btn btn-light-primary' }, buttonsStyling: false })
                .fire({ title: 'Delete Category: ' + name + '?', text: 'This category will be removed from the list.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, Delete', cancelButtonText: 'Cancel', reverseButtons: true })
                .then(function (r) { if (r.isConfirmed) { form.submit(); } });
        });
    })();
</script>
