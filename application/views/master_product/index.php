<?php
// The Destination filter follows the per-analysis individual cities (details_json → cities),
// NOT the free-form `destination` label (which lumps several cities into one string). Build a
// per-record city list (reused on each row) + a deduped, sorted union for the dropdown options.
$row_cities = array();   // record id => array of individual city names
$cities     = array();   // dedup set of every city across all records
foreach($records as $r) {
    $details = json_decode((string) $r->details_json, true);
    $list    = (is_array($details) && isset($details['cities']) && is_array($details['cities'])) ? $details['cities'] : array();
    $clean   = array();
    foreach($list as $c) {
        $c = trim((string) $c);
        if($c !== '') { $clean[$c] = true; $cities[$c] = true; }
    }
    $row_cities[(int) $r->id] = array_keys($clean);
}
$cities = array_keys($cities);
natcasesort($cities);
$cities = array_values($cities);
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
                                            <select id="mp_source" class="form-control selectpicker" multiple data-selected-text-format="count > 1" title="--ALL SOURCES--">
                                                <option value="our_product">Our Product</option>
                                                <option value="competitor">Competitor</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label>Status</label>
                                            <select id="mp_status" class="form-control selectpicker" multiple data-selected-text-format="count > 1" title="--ALL STATUSES--">
                                                <option value="done">Analysed</option>
                                                <option value="error">Error</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label>Destination</label>
                                            <select id="mp_dest" class="form-control selectpicker" multiple data-actions-box="true" data-selected-text-format="count > 1" data-live-search="true" data-live-search-style="contains" title="--ALL DESTINATIONS--">
                                                <?php foreach($cities as $c) { ?>
                                                    <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
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
                    <button type="button" id="mp_download_tour_info" class="btn btn-light-success font-weight-bold mr-3 mb-2" <?php echo empty($records) ? 'disabled' : ''; ?>
                            title="Split-table layout: summary + itinerary, scenic, selling points, inclusion, exclusion, accommodation">
                        <i class="la la-table"></i> <span id="mp_download_tour_info_label">Tour Info (Excel)</span>
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
                                data-cities="<?php echo htmlspecialchars(implode('|', $row_cities[(int) $r->id])); ?>"
                                data-search="<?php echo htmlspecialchars($search); ?>">
                                <td style="text-align:center;"><input type="checkbox" class="mp-select" value="<?php echo (int) $r->id; ?>" aria-label="Select <?php echo htmlspecialchars($name); ?>"></td>
                                <td class="mp-no" style="text-align:center; padding:12px 8px;"><?php echo $no; ?></td>
                                <td style="max-width:280px; word-break:break-word;">
                                    <span style="font-size:13px;"><strong><?php echo htmlspecialchars($name); ?></strong></span>
                                    <?php if(trim((string) $r->tour_code) !== '') { ?>
                                        <div class="text-muted" style="font-size:11px;">Code: <?php echo htmlspecialchars($r->tour_code); ?></div>
                                    <?php } ?>
                                    <?php $flow = competitor_flow_badge(isset($r->itinerary_flow_json) ? $r->itinerary_flow_json : null, $r->product_count, 'en'); ?>
                                    <div style="margin-top:3px;"><span class="mp-flow-badge" data-id="<?php echo (int) $r->id; ?>"><?php
                                        if($flow === 'ready') { ?><span class="label label-light-success label-inline font-weight-bold" style="font-size:10px;" title="Daily Itinerary Flow already generated by AI — the Tour Info export won't need another AI call for this record"><i class="la la-check mr-1"></i>AI flow ready</span><?php }
                                        elseif($flow === 'partial') { ?><span class="label label-light-warning label-inline font-weight-bold" style="font-size:10px;" title="Some products still need their Daily Itinerary Flow generated by AI"><i class="la la-adjust mr-1"></i>AI flow partial</span><?php }
                                    ?></span></div>
                                </td>
                                <td style="text-align:center;">
                                    <?php if($is_our) { ?>
                                        <span class="label label-light-success label-inline font-weight-bold" style="font-size:11px;"><i class="la la-box mr-1"></i>Our Product</span>
                                    <?php } else { ?>
                                        <span class="label label-light-warning label-inline font-weight-bold" style="font-size:11px;"><i class="la la-robot mr-1"></i>Competitor</span>
                                        <?php
                                            $cname = trim((string) ($r->competitor_name ?? ''));
                                            $curl  = trim((string) ($r->url ?? ''));
                                            if($cname !== '') { ?>
                                            <div class="text-muted" style="font-size:11px;"><?php echo htmlspecialchars($cname); ?></div>
                                        <?php } elseif($curl !== '') {
                                            $chost = parse_url($curl, PHP_URL_HOST);
                                            $clabel = $chost ? $chost : $curl; ?>
                                            <div style="font-size:11px;"><a href="<?php echo htmlspecialchars($curl); ?>" target="_blank" rel="noopener" title="<?php echo htmlspecialchars($curl); ?>"><?php echo htmlspecialchars($clabel); ?></a></div>
                                        <?php } ?>
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
    // True while a background flow extraction is running — keeps mpUpdateSelection from
    // clobbering the Tour Info button's spinner/label when filters or checkboxes change.
    var mpBusy = false;

    function mpFilteredCheckboxes() {
        return $('.mp-row:visible .mp-select');
    }
    function mpUpdateSelection() {
        var $boxes = mpFilteredCheckboxes();
        var count = $boxes.filter(':checked').length;
        $('#mp_selected_count').text(count);
        $('#mp_download_selected').prop('disabled', count === 0);
        $('#mp_download_results').prop('disabled', $boxes.length === 0);
        if(!mpBusy) {
            $('#mp_download_tour_info').prop('disabled', $boxes.length === 0);
            $('#mp_download_tour_info_label').text(count > 0 ? 'Tour Info — Selected (' + count + ')' : 'Tour Info (Excel)');
        }
        $('#mp_select_all').prop('disabled', $boxes.length === 0)
            .prop('checked', $boxes.length > 0 && count === $boxes.length)
            .prop('indeterminate', count > 0 && count < $boxes.length);
    }

    // Client-side filtering over the rendered rows (Search + Source + Status +
    // Destination). Renumbers the visible rows and toggles the empty-state row.
    function mpApplyFilters() {
        var q    = ($('#mp_search').val() || '').toLowerCase().trim();
        var src  = $('#mp_source').val() || [];
        var st   = $('#mp_status').val() || [];
        var dest = $('#mp_dest').val() || [];
        var n = 0;
        $('.mp-row').each(function() {
            var $r = $(this), ok = true;
            if(src.length  && src.indexOf(String($r.data('source'))) === -1)  ok = false;
            if(ok && st.length   && st.indexOf(String($r.data('status'))) === -1)   ok = false;
            if(ok && dest.length) {
                var rowCities = String($r.data('cities') || '').split('|');
                ok = dest.some(function(c) { return rowCities.indexOf(c) !== -1; });
            }
            if(ok && q    && String($r.data('search') || '').indexOf(q) === -1) ok = false;
            $r.toggle(ok);
            if(!ok) { $r.find('.mp-select').prop('checked', false); }
            if(ok) { n++; $r.find('.mp-no').text(n); }
        });
        $('#mp_empty').toggle(n === 0);
        $('#mp_count').text(n);
        $('#mp_download_results_label').text(q || src.length || st.length || dest.length ? 'Download Filtered (Excel)' : 'Download All (Excel)');
        mpUpdateSelection();
    }
    $('#mp_select_all').on('change', function() {
        mpFilteredCheckboxes().prop('checked', this.checked);
        mpUpdateSelection();
    });
    $(document).on('change', '.mp-select', mpUpdateSelection);
    var MP_EXPORT_WIDE   = '<?php echo base_url('Master_Product/Download_Excel'); ?>';
    var MP_EXPORT_TABLES = '<?php echo base_url('Master_Product/Download_Excel_Tables'); ?>';
    function mpDownloadExcel(selectedOnly, action) {
        var $boxes = mpFilteredCheckboxes();
        if(selectedOnly) { $boxes = $boxes.filter(':checked'); }
        var ids = $boxes.map(function() { return this.value; }).get();
        if(!ids.length) { return; }
        var form = document.getElementById('mp_export_form');
        form.action = action || MP_EXPORT_WIDE;
        $('#mp_export_ids').val(ids.join(','));
        form.submit();
    }
    $('#mp_download_results').on('click', function() { mpDownloadExcel(false, MP_EXPORT_WIDE); });
    $('#mp_download_selected').on('click', function() { mpDownloadExcel(true, MP_EXPORT_WIDE); });
    // Tour Info export: selected rows if any are checked, otherwise all filtered rows.
    // If any products still need the AI "Daily Itinerary Flow", extract them in the
    // BACKGROUND (button disabled + "Generating k/N" spinner) and auto-download when
    // done; if all cached, download straight away. Each product is saved as it finishes.
    var MP_FLOW_STATUS  = '<?php echo base_url('Master_Product/Flow_Cache_Status'); ?>';
    var MP_EXTRACT_FLOW = '<?php echo base_url('Master_Product/Extract_Flow'); ?>';
    var MP_JOB_STATE    = '<?php echo base_url('Master_Product/Job_State?job='); ?>';
    var MP_FLOW_LS      = 'mp_tour_info_flow';   // {job, ids} — resume the spinner/poll across a refresh
    var mpToast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false });

    function mpFlowRemember(job, ids) { try { localStorage.setItem(MP_FLOW_LS, JSON.stringify({ job: job, ids: ids })); } catch(e) {} }
    function mpFlowForget() { try { localStorage.removeItem(MP_FLOW_LS); } catch(e) {} }
    // Flip the involved rows' badge to "ready" once their flow is cached — no reload.
    var MP_READY_BADGE = '<span class="label label-light-success label-inline font-weight-bold" style="font-size:10px;" title="Daily Itinerary Flow already generated by AI"><i class="la la-check mr-1"></i>AI flow ready</span>';
    function mpMarkFlowReady(ids) { (ids || []).forEach(function(id) { $('.mp-flow-badge[data-id="' + id + '"]').html(MP_READY_BADGE); }); }

    function mpSetTourInfoBusy(text) {
        mpBusy = true;
        $('#mp_download_tour_info').prop('disabled', true);
        $('#mp_download_tour_info_label').html('<i class="la la-spinner la-spin"></i> ' + text);
    }
    function mpRestoreTourInfoBtn() {
        mpBusy = false;
        mpUpdateSelection();   // restores label + disabled from current selection
    }
    // Download EXACTLY the ids captured at click (not live checkboxes — the user may
    // have changed the selection while the background extraction was running).
    function mpSubmitExport(ids) {
        var form = document.getElementById('mp_export_form');
        form.action = MP_EXPORT_TABLES;
        $('#mp_export_ids').val(ids.join(','));
        form.submit();
    }
    function mpPollFlow(job, ids) {
        var t = setInterval(function() {
            $.getJSON(MP_JOB_STATE + encodeURIComponent(job)).done(function(res) {
                if(!res) { return; }
                if(res.state === 'done') {
                    clearInterval(t);
                    mpFlowForget();
                    mpMarkFlowReady(ids);
                    mpSubmitExport(ids);
                    mpRestoreTourInfoBtn();
                    mpToast.fire({ icon: 'success', title: 'Itinerary flow ready — downloading…', timer: 4000, timerProgressBar: true });
                } else if(res.state === 'error' || res.state === 'unknown') {
                    clearInterval(t);
                    mpFlowForget();
                    mpRestoreTourInfoBtn();
                    mpToast.fire({ icon: 'error', title: (res.message || 'Flow extraction failed'), timer: 6000, timerProgressBar: true });
                } else {
                    mpSetTourInfoBusy('Generating ' + (res.done || 0) + '/' + (res.total || 0) + '…');
                }
            }).fail(function() { /* transient — keep polling */ });
        }, 2500);
    }
    function mpStartFlow(ids) {
        mpSetTourInfoBusy('Generating…');
        $.post(MP_EXTRACT_FLOW, { ids: ids.join(',') }, function(res) {
            if(res && res.success && (res.done || res.fallback)) { mpMarkFlowReady(ids); mpSubmitExport(ids); mpRestoreTourInfoBtn(); return; }
            if(res && res.success && res.job) {
                mpFlowRemember(res.job, ids);
                mpToast.fire({ icon: 'info', title: 'Generating itinerary flow in the background…', timer: 4000, timerProgressBar: true });
                mpPollFlow(res.job, ids);
                return;
            }
            mpRestoreTourInfoBtn();
            mpToast.fire({ icon: 'error', title: (res && res.message ? res.message : 'Could not start extraction'), timer: 6000, timerProgressBar: true });
        }, 'json').fail(function() {
            mpRestoreTourInfoBtn();
            mpToast.fire({ icon: 'error', title: 'Could not start extraction. Please try again.', timer: 6000, timerProgressBar: true });
        });
    }
    $('#mp_download_tour_info').on('click', function() {
        if(mpBusy) { return; }
        var hasChecked = mpFilteredCheckboxes().filter(':checked').length > 0;
        var $boxes = hasChecked ? mpFilteredCheckboxes().filter(':checked') : mpFilteredCheckboxes();
        var ids = $boxes.map(function() { return this.value; }).get();
        if(!ids.length) { return; }
        var go = function() { mpDownloadExcel(hasChecked, MP_EXPORT_TABLES); };
        $.post(MP_FLOW_STATUS, { ids: ids.join(',') }, function(res) {
            if(res && res.uncached > 0) {
                Swal.mixin({ customClass: { confirmButton: 'btn btn-light-success m-2', cancelButton: 'btn btn-secondary m-2' }, buttonsStyling: true })
                    .fire({ icon: 'info', title: 'Generate itinerary flow with AI?',
                        html: '<b>' + res.uncached + '</b> of <b>' + res.total + '</b> product(s) still need their <b>Daily Itinerary Flow</b> generated by <b>AI</b>. This runs in the background; the download starts automatically when it\'s ready, and results are saved for next time.',
                        showCancelButton: true, confirmButtonText: 'Generate & Download', cancelButtonText: 'Cancel' })
                    .then(function(a) { if(a.isConfirmed) { mpStartFlow(ids); } });
            } else { go(); }
        }, 'json').fail(go);
    });
    $('#mp_search').on('input', mpApplyFilters);
    $('#mp_source, #mp_status, #mp_dest').on('change', mpApplyFilters);
    $('#mp_reset').on('click', function() {
        $('#mp_search').val('');
        var $sel = $('#mp_source, #mp_status, #mp_dest').val([]);
        if($.fn.selectpicker) { $sel.selectpicker('refresh'); }
        mpApplyFilters();
    });
    mpApplyFilters();

    // Resume a background flow extraction that was still running when the page was
    // refreshed/reopened — restore the spinner + progress and keep polling the SAME
    // job (the detached worker kept running + caching), then auto-download on done.
    (function mpResumeFlow() {
        var raw = ''; try { raw = localStorage.getItem(MP_FLOW_LS) || ''; } catch(e) {}
        if(!raw) { return; }
        var data = null; try { data = JSON.parse(raw); } catch(e) {}
        if(!data || !data.job || !data.ids || !data.ids.length) { mpFlowForget(); return; }
        mpSetTourInfoBusy('Generating…');
        mpToast.fire({ icon: 'info', title: 'Resuming itinerary flow extraction…', timer: 3000, timerProgressBar: true });
        mpPollFlow(data.job, data.ids);
    })();

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
