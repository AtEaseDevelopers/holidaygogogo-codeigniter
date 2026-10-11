<div class="d-flex flex-column-fluid">
    <div class="container-fluid">
        <div class="card card-custom mb-5">
            <div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
                <div class="card-title">
                    <h3 class="card-label" style="color:#6082B6;">
                        <strong>Review Crawled Products</strong>
                        <span class="text-muted font-weight-normal" style="font-size:12px;">&nbsp; <span id="found_count"><?php echo count($products); ?></span> found</span>
                    </h3>
                </div>
                <div class="card-toolbar">
                    <a href="<?php echo base_url('Our_Product'); ?>" class="btn btn-secondary font-weight-bold mr-3"><i class="la la-arrow-left"></i> Back</a>
                    <button type="button" id="analyse_selected" class="btn btn-primary font-weight-bold" <?php echo empty($has_ai) ? 'disabled title="AI analysis is currently unavailable"' : ''; ?>>
                        <i class="la la-robot"></i> Analyse Selected (<span id="sel_count">0</span>)
                    </button>
                    <button type="button" id="delete_selected" class="btn btn-light-danger font-weight-bold ml-2" disabled>
                        <i class="la la-trash"></i> Delete Selected (<span id="del_count">0</span>)
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($has_ai)) { ?>
                    <div class="alert alert-light-warning" style="font-size:12px;">AI analysis is currently unavailable. You can still review the crawled products below.</div>
                <?php } ?>
                <!-- Search: filter the crawled products by name / description / URL -->
                <div class="form-group mb-3" style="max-width:420px;">
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="la la-search"></i></span>
                        </div>
                        <input type="text" id="product_search" class="form-control" autocomplete="off"
                               placeholder="Search products by name, description or URL…" style="font-size:13px;">
                    </div>
                </div>
                <div style="overflow-x:auto;">
                    <table class="table table-bordered table-head-custom" style="font-size:13px;">
                        <thead>
                            <tr>
                                <th style="width:40px; text-align:center;"><input type="checkbox" id="sel_all"></th>
                                <th style="text-align:center;">No.</th>
                                <th>Product</th>
                                <th style="text-align:center;">AI Cost (USD)</th>
                                <th style="text-align:center;">Analysed On</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="products_body">
                            <?php if (empty($products)) { ?>
                                <tr id="empty_row"><td colspan="6" style="text-align:center; padding:12px;">No products crawled</td></tr>
                            <?php } else { $n = 1; foreach ($products as $p) {
                                $analysed = ((int) $p['analysis_id'] > 0);
                                $hay = strtolower(trim($p['title'] . ' ' . $p['snippet'] . ' ' . $p['url'] . ' ' . $p['duration']));
                            ?>
                                <tr class="product-row" data-search="<?php echo htmlspecialchars($hay); ?>"<?php echo $analysed ? ' style="background:#f6fbf7;"' : ''; ?>>
                                    <td style="text-align:center;"><input type="checkbox" class="sel-item" value="<?php echo (int) $p['i']; ?>" <?php echo $p['chars'] > 0 ? '' : 'disabled'; ?>></td>
                                    <td class="row-no" style="text-align:center; padding:10px 8px;"><?php echo $n; ?></td>
                                    <td>
                                        <strong style="font-size:13px;"><?php echo htmlspecialchars($p['title']); ?></strong>
                                        <?php if ( ! empty($p['duration'])) { ?><span class="label label-light-info label-inline ml-1" style="font-size:10px;"><?php echo htmlspecialchars($p['duration']); ?></span><?php } ?>
                                        <?php echo $p['chars'] > 0 ? '' : ' <span class="label label-light-danger label-inline" style="font-size:10px;">empty</span>'; ?>
                                        <?php if ( ! empty($p['snippet'])) { ?><div class="text-muted" style="font-size:11px; margin-top:2px;"><?php echo htmlspecialchars($p['snippet']); ?></div><?php } ?>
                                        <?php if ( ! empty($p['url'])) { ?><div style="margin-top:2px;"><a href="<?php echo htmlspecialchars($p['url']); ?>" target="_blank" rel="noopener" class="text-muted" style="font-size:11px; word-break:break-all;"><i class="la la-external-link-alt mr-1"></i><?php echo htmlspecialchars($p['url']); ?></a></div><?php } ?>
                                    </td>
                                    <td style="text-align:center; font-size:12px;"><?php echo $analysed && $p['cost'] > 0 ? number_format((float) $p['cost'], 4) : '—'; ?></td>
                                    <td style="text-align:center; font-size:12px;">
                                        <?php if ($analysed && ! empty($p['analysed_at'])) { ?>
                                            <span style="font-size:11px;"><?php echo htmlspecialchars(date('d M Y, g:i A', strtotime($p['analysed_at']))); ?></span>
                                        <?php } else { ?>
                                            <span class="text-muted" style="font-size:11px;">—</span>
                                        <?php } ?>
                                    </td>
                                    <td style="text-align:center; white-space:nowrap;">
                                        <?php if ($analysed) { ?>
                                            <a href="<?php echo base_url('Our_Product/View?id=') . (int) $p['analysis_id']; ?>" class="btn btn-icon btn-light-success btn-sm mr-1" data-toggle="tooltip" title="View analysis">
                                                <i class="la la-search"></i>
                                            </a>
                                        <?php } ?>
                                        <a href="javascript:;" class="btn btn-icon btn-light-danger btn-sm delete-item"
                                           data-index="<?php echo (int) $p['i']; ?>"
                                           data-toggle="tooltip" title="Delete this crawled product">
                                            <i class="la la-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php $n++; } } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    var CA_IMG = '<?php echo base_url('assets/image/sweetalert.jpg') ?>';
    var JOB        = '<?php echo $job; ?>';
    var DELETE_URL = '<?php echo base_url('Our_Product/Delete_Crawl_Items') ?>';

    // Bulk actions apply only to enabled checkboxes in the current search results.
    function selectableItems() {
        return $('#products_body tr.product-row:visible .sel-item:not(:disabled)');
    }
    function selectedItems() {
        return selectableItems().filter(':checked');
    }
    function refreshSel() {
        var total = selectableItems().length;
        var n = selectedItems().length;
        $('#sel_count').text(n);
        $('#del_count').text(n);
        $('#delete_selected').prop('disabled', n === 0);
        $('#sel_all').prop('checked', total > 0 && n === total)
            .prop('indeterminate', n > 0 && n < total)
            .prop('disabled', total === 0);
    }
    $('#sel_all').on('change', function() {
        selectableItems().prop('checked', this.checked);
        refreshSel();
    });
    $(document).on('change', '.sel-item', refreshSel);
    $(document).ready(function() {
        refreshSel();
        $('[data-toggle="tooltip"]').tooltip();
    });

    // Renumber the visible No. column + refresh the "N found" count. Called after
    // a delete or a search so the numbering always matches what's on screen.
    function renumber() {
        var n = 0;
        $('#products_body tr.product-row:visible').each(function() {
            $(this).find('.row-no').text(++n);
        });
        $('#found_count').text(n);
    }

    // Search: show only product rows whose name / description / URL matches, then
    // renumber. A blank query shows everything.
    $('#product_search').on('input', function() {
        var q = $.trim($(this).val()).toLowerCase();
        $('#products_body tr.product-row').each(function() {
            var hay = String($(this).attr('data-search') || '');
            var matches = q === '' || hay.indexOf(q) !== -1;
            $(this).toggle(matches);
            if (!matches) $(this).find('.sel-item').prop('checked', false);
        });
        renumber();
        refreshSel();
    });

    // Resync selection / numbering / empty-state after rows are removed.
    function afterRemoval() {
        refreshSel();
        renumber();
        if (!$('#products_body tr.product-row').length && !$('#empty_row').length) {
            $('#products_body').append('<tr id="empty_row"><td colspan="6" style="text-align:center; padding:12px;">No products crawled</td></tr>');
        }
    }

    // Confirm, then delete the given product indices from the crawl (and any saved
    // analyses) and drop their rows. Shared by the per-row trash and Delete Selected.
    function deleteProducts(indices, $rows, title, text) {
        Swal.mixin({ customClass: { confirmButton: 'btn btn-light-success m-2', cancelButton: 'btn btn-danger m-2' }, buttonsStyling: true })
            .fire({ width: 500, background: 'url(' + CA_IMG + ')', icon: 'warning',
                title: title, text: text,
                confirmButtonText: 'Delete', cancelButtonText: 'Cancel', showCancelButton: true })
            .then(function(a) {
                if (!a.isConfirmed) return;
                $.post(DELETE_URL, { job: JOB, indices: indices }, function(res) {
                    if (res && res.success) {
                        $rows.fadeOut(200);
                        setTimeout(function() { $rows.remove(); afterRemoval(); }, 220);
                    } else {
                        Display_Message(CA_IMG, (res && res.message) ? res.message : 'Could not delete', null);
                    }
                }, 'json').fail(function() { Display_Message(CA_IMG, 'Could not delete. Please try again', null); });
            });
    }

    // Per-row delete.
    $(document).on('click', '.delete-item', function() {
        var $row = $(this).closest('tr');
        deleteProducts([$(this).data('index')], $row,
            'Delete this crawled product?',
            'It is removed from this crawl. Any saved analysis for it is also deleted.');
    });

    // Bulk delete of the checked products in the current search results.
    $('#delete_selected').click(function() {
        var $checked = selectedItems();
        var indices = $checked.map(function() { return $(this).val(); }).get();
        if (!indices.length) { Display_Message(CA_IMG, 'Select at least one product', null); return; }
        deleteProducts(indices, $checked.closest('tr'),
            'Delete ' + indices.length + ' selected product' + (indices.length > 1 ? 's' : '') + '?',
            'They are removed from this crawl. Any saved analyses for them are also deleted.');
    });

    var STATE_URL = '<?php echo base_url('Our_Product/Job_State') ?>?job=';

    function pollAnalyse(analyseJob) {
        var t = setInterval(function() {
            $.getJSON(STATE_URL + encodeURIComponent(analyseJob)).done(function(res) {
                if (!res) return;
                if (res.state === 'done' || res.state === 'error' || res.state === 'unknown') {
                    clearInterval(t);
                    Swal.close();
                    // Reload the Review page — analysed products now show View + cost.
                    window.location.reload();
                } else {
                    Swal.update({ title: res.message || 'Analysing…' });
                    Swal.showLoading();
                }
            });
        }, 2500);
    }

    $('#analyse_selected').click(function() {
        var indices = selectedItems().map(function() { return $(this).val(); }).get();
        if (!indices.length) { Display_Message(CA_IMG, 'Select at least one product', null); return; }
        var $btn = $(this), html = $btn.html();
        $btn.prop('disabled', true).html('<i class="la la-spinner la-spin"></i> Starting…');
        Swal.fire({ background: 'url(' + CA_IMG + ')', title: 'Analysing…', allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });
        $.ajax({
            url: '<?php echo base_url('Our_Product/Analyze_Selected') ?>',
            type: 'post', dataType: 'json',
            data: { job: '<?php echo $job; ?>', indices: indices },
            success: function(res) {
                if (res && res.success && res.job) {
                    pollAnalyse(res.job);
                } else {
                    Swal.close();
                    $btn.prop('disabled', false).html(html);
                    Display_Message(CA_IMG, (res && res.message) ? res.message : 'Could not start analysis', null);
                }
            },
            error: function() {
                Swal.close();
                $btn.prop('disabled', false).html(html);
                Display_Message(CA_IMG, 'Could not start analysis. Please try again', null);
            }
        });
    });
</script>
