<?php
// Distinct, non-empty destinations for the Destination filter dropdown.
$destinations = array();
foreach($records as $r) {
    $d = trim((string) $r->destination);
    if($d !== '') { $destinations[$d] = true; }
}
$destinations = array_keys($destinations);
sort($destinations);
?>
<div class="d-flex flex-column-fluid">
    <div class="container-fluid">

        <div class="card card-custom mb-5">
            <div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
                <div class="card-title">
                    <h3 class="card-label" style="color:#6082B6;">
                        <strong>Master Product</strong>
                        <span class="text-muted font-weight-normal" style="font-size:12px;">&nbsp; all analysed records (Competitor + Our Product)</span>
                    </h3>
                </div>
                <div class="card-toolbar">
                    <span class="label label-light-primary label-inline font-weight-bold" style="font-size:13px; padding:16px 14px;">
                        <i class="la la-dollar-sign mr-1"></i>Total AI Cost:&nbsp;
                        <strong>USD <?php echo number_format((float) $total_cost, 4); ?></strong>
                    </span>
                </div>
            </div>
            <div class="card-body">

                <!-- Filters (mirrors the Booking listing filter panel) -->
                <div class="accordion accordion-solid accordion-toggle-plus mb-5">
                    <div class="card">
                        <div class="card-header">
                            <div id="mp_filter_header" data-toggle="collapse" data-target="#mp_filter_body" class="card-title collapsed" style="font-size:13px;">Filter Records</div>
                        </div>
                        <div id="mp_filter_body" class="collapse">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label>Search</label>
                                            <div class="input-icon">
                                                <input type="text" id="mp_search" autocomplete="off" class="form-control" placeholder="Product, code, destination or URL">
                                                <span><i class="la la-search"></i></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label>Source</label>
                                            <select id="mp_source" class="form-control selectpicker" title="--ALL SOURCES--">
                                                <option value="">All</option>
                                                <option value="our_product">Our Product</option>
                                                <option value="competitor">Competitor</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label>Status</label>
                                            <select id="mp_status" class="form-control selectpicker" title="--ALL STATUSES--">
                                                <option value="">All</option>
                                                <option value="done">Analysed</option>
                                                <option value="error">Error</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label>Destination</label>
                                            <select id="mp_dest" class="form-control selectpicker" data-live-search="true" data-live-search-style="contains" title="--ALL DESTINATIONS--">
                                                <option value="">All</option>
                                                <?php foreach($destinations as $d) { ?>
                                                    <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <input type="button" id="mp_reset" value="Reset" class="btn btn-light-primary font-weight-bold" style="width:80px;">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center mb-4">
                    <button type="button" id="mp_download_results" class="btn btn-light-success font-weight-bold mr-3 mb-2" <?php echo empty($records) ? 'disabled' : ''; ?>>
                        <i class="la la-file-excel"></i> <span id="mp_download_results_label">Download All (Excel)</span>
                    </button>
                    <button type="button" id="mp_download_selected" class="btn btn-light-primary font-weight-bold mr-3 mb-2" disabled>
                        <i class="la la-file-excel"></i> Download Selected (<span id="mp_selected_count">0</span>)
                    </button>
                    <span class="text-muted mb-2" style="font-size:12px;">Downloads include records matching the current filters.</span>
                </div>
                <form id="mp_export_form" method="post" action="<?php echo base_url('Master_Product/Download_Excel'); ?>" style="display:none;">
                    <input type="hidden" name="ids" id="mp_export_ids">
                </form>

                <div style="overflow-x:auto;">
                    <table class="table table-bordered table-head-custom table-checkable">
                        <thead>
                            <tr>
                                <th style="width:40px; text-align:center;"><input type="checkbox" id="mp_select_all" aria-label="Select all filtered records"></th>
                                <th style="text-align:center;">No.</th>
                                <th>Product</th>
                                <th style="text-align:center;">Source</th>
                                <th>Destination</th>
                                <th style="text-align:center;">Price</th>
                                <th style="text-align:center;">Duration</th>
                                <th style="text-align:center;">AI Cost (USD)</th>
                                <th style="text-align:center;">Status</th>
                                <th style="text-align:center;">Date</th>
                                <th class="action" style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php $no = 0; foreach($records as $r) {
                            $no++;
                            $is_our = ($r->feature === 'our_product');
                            $route  = $is_our ? 'Our_Product' : 'Competitor_Product';
                            $name   = trim((string) ($r->product_name ?: ($r->page_title ?: $r->url)));
                            $price  = trim((string) $r->currency . ' ' . (string) $r->price);
                            $search = strtolower(trim($name . ' ' . (string) $r->tour_code . ' ' . (string) $r->destination . ' ' . (string) $r->url));
                        ?>
                            <tr class="mp-row"
                                data-source="<?php echo $is_our ? 'our_product' : 'competitor'; ?>"
                                data-status="<?php echo ($r->status === 'error') ? 'error' : 'done'; ?>"
                                data-dest="<?php echo htmlspecialchars(trim((string) $r->destination)); ?>"
                                data-search="<?php echo htmlspecialchars($search); ?>">
                                <td style="text-align:center;"><input type="checkbox" class="mp-select" value="<?php echo (int) $r->id; ?>" aria-label="Select <?php echo htmlspecialchars($name); ?>"></td>
                                <td class="mp-no" style="text-align:center; padding:12px 8px;"><?php echo $no; ?></td>
                                <td style="max-width:280px; word-break:break-word;">
                                    <span style="font-size:13px;"><strong><?php echo htmlspecialchars($name); ?></strong></span>
                                    <?php if(trim((string) $r->tour_code) !== '') { ?>
                                        <div class="text-muted" style="font-size:11px;">Code: <?php echo htmlspecialchars($r->tour_code); ?></div>
                                    <?php } ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if($is_our) { ?>
                                        <span class="label label-light-success label-inline font-weight-bold" style="font-size:11px;"><i class="la la-box mr-1"></i>Our Product</span>
                                    <?php } else { ?>
                                        <span class="label label-light-warning label-inline font-weight-bold" style="font-size:11px;"><i class="la la-robot mr-1"></i>Competitor</span>
                                    <?php } ?>
                                </td>
                                <td style="font-size:12px;"><?php echo htmlspecialchars((string) $r->destination) ?: '<span class="text-muted">—</span>'; ?></td>
                                <td style="text-align:center; font-size:12px;"><?php echo ($price !== '') ? htmlspecialchars($price) : '<span class="text-muted">—</span>'; ?></td>
                                <td style="text-align:center; font-size:12px;"><?php echo htmlspecialchars((string) $r->duration) ?: '<span class="text-muted">—</span>'; ?></td>
                                <td style="text-align:center; font-size:12px;"><?php echo ((float) $r->cost_usd > 0) ? number_format((float) $r->cost_usd, 4) : '—'; ?></td>
                                <td style="text-align:center;">
                                    <?php if($r->status === 'error') { ?>
                                        <span class="label label-light-danger label-inline font-weight-bold">Error</span>
                                    <?php } else { ?>
                                        <span class="label label-light-info label-inline font-weight-bold">Analysed</span>
                                    <?php } ?>
                                </td>
                                <td style="text-align:center; font-size:12px;"><?php echo htmlspecialchars((string) $r->created_at); ?></td>
                                <td style="text-align:center;">
                                    <div class="btn-group">
                                        <button type="button" data-toggle="dropdown" class="btn btn-light-primary btn-sm dropdown-toggle" style="padding-left:3px;"></button>
                                        <div class="dropdown-menu dropdown-menu-right">
                                            <a href="<?php echo base_url($route . '/View?id=' . (int) $r->id); ?>" class="dropdown-item" style="font-size:11px;"><i class="la la-search mr-2"></i>View Analysis</a>
                                            <a href="<?php echo base_url($route . '/Download_Pdf?id=' . (int) $r->id); ?>" class="dropdown-item" style="font-size:11px;"><i class="la la-file-pdf mr-2"></i>Download PDF</a>
                                            <a href="javascript:;" class="dropdown-item delete-record" data-id="<?php echo (int) $r->id; ?>" style="font-size:11px;"><i class="la la-trash mr-2"></i>Delete</a>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php } ?>
                            <tr id="mp_empty" style="display:none;"><td colspan="11" style="text-align:center; padding:12px;" class="text-muted">No records match the filters</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="text-muted mt-2" style="font-size:12px;">Showing <strong id="mp_count"><?php echo count($records); ?></strong> record(s)</div>
            </div>
        </div>

    </div>
</div>

<script>
    var CA_IMG = '<?php echo base_url('assets/image/sweetalert.jpg') ?>';

    function mpFilteredCheckboxes() {
        return $('.mp-row:visible .mp-select');
    }
    function mpUpdateSelection() {
        var $boxes = mpFilteredCheckboxes();
        var count = $boxes.filter(':checked').length;
        $('#mp_selected_count').text(count);
        $('#mp_download_selected').prop('disabled', count === 0);
        $('#mp_download_results').prop('disabled', $boxes.length === 0);
        $('#mp_select_all').prop('disabled', $boxes.length === 0)
            .prop('checked', $boxes.length > 0 && count === $boxes.length)
            .prop('indeterminate', count > 0 && count < $boxes.length);
    }

    // Client-side filtering over the rendered rows (Search + Source + Status +
    // Destination). Renumbers the visible rows and toggles the empty-state row.
    function mpApplyFilters() {
        var q    = ($('#mp_search').val() || '').toLowerCase().trim();
        var src  = $('#mp_source').val();
        var st   = $('#mp_status').val();
        var dest = $('#mp_dest').val();
        var n = 0;
        $('.mp-row').each(function() {
            var $r = $(this), ok = true;
            if(src  && String($r.data('source')) !== src)  ok = false;
            if(ok && st   && String($r.data('status')) !== st)   ok = false;
            if(ok && dest && String($r.data('dest'))   !== dest) ok = false;
            if(ok && q    && String($r.data('search') || '').indexOf(q) === -1) ok = false;
            $r.toggle(ok);
            if(!ok) { $r.find('.mp-select').prop('checked', false); }
            if(ok) { n++; $r.find('.mp-no').text(n); }
        });
        $('#mp_empty').toggle(n === 0);
        $('#mp_count').text(n);
        $('#mp_download_results_label').text(q || src || st || dest ? 'Download Filtered (Excel)' : 'Download All (Excel)');
        mpUpdateSelection();
    }
    $('#mp_select_all').on('change', function() {
        mpFilteredCheckboxes().prop('checked', this.checked);
        mpUpdateSelection();
    });
    $(document).on('change', '.mp-select', mpUpdateSelection);
    function mpDownloadExcel(selectedOnly) {
        var $boxes = mpFilteredCheckboxes();
        if(selectedOnly) { $boxes = $boxes.filter(':checked'); }
        var ids = $boxes.map(function() { return this.value; }).get();
        if(!ids.length) { return; }
        $('#mp_export_ids').val(ids.join(','));
        document.getElementById('mp_export_form').submit();
    }
    $('#mp_download_results').on('click', function() { mpDownloadExcel(false); });
    $('#mp_download_selected').on('click', function() { mpDownloadExcel(true); });
    $('#mp_search').on('input', mpApplyFilters);
    $('#mp_source, #mp_status, #mp_dest').on('change', mpApplyFilters);
    $('#mp_reset').on('click', function() {
        $('#mp_search').val('');
        var $sel = $('#mp_source, #mp_status, #mp_dest').val('');
        if($.fn.selectpicker) { $sel.selectpicker('refresh'); }
        mpApplyFilters();
    });
    mpApplyFilters();

    // Delete one analysed record (routes to Master_Product/Delete, cross-feature).
    $(document).on('click', '.delete-record', function() {
        var $b = $(this), id = $b.data('id'), $row = $b.closest('tr');
        Swal.mixin({ customClass: { confirmButton: 'btn btn-light-success m-2', cancelButton: 'btn btn-danger m-2' }, buttonsStyling: true })
            .fire({ width: 500, background: 'url(' + CA_IMG + ')', icon: 'warning',
                title: 'Delete this analysed record?', text: 'This removes it permanently.',
                confirmButtonText: 'Delete', cancelButtonText: 'Cancel', showCancelButton: true })
            .then(function(a) {
                if(a.isConfirmed) {
                    $.post('<?php echo base_url('Master_Product/Delete') ?>', { id: id }, function(res) {
                        if(res && res.success) { $row.fadeOut(200, function() { $(this).remove(); mpApplyFilters(); }); }
                        else { Display_Message(CA_IMG, 'Could not delete the record.', null); }
                    }, 'json').fail(function() { Display_Message(CA_IMG, 'Could not delete the record.', null); });
                }
            });
    });
</script>
