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
                    <a href="<?php echo base_url('Competitor_Product'); ?>" id="back_btn" class="btn btn-secondary font-weight-bold mr-3"><i class="la la-arrow-left"></i> Back</a>
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
                                <th style="text-align:center;">Type</th>
                                <th style="text-align:center;">AI Cost (USD)</th>
                                <th style="text-align:center;">Analysed On</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="products_body">
                            <?php if (empty($products)) { ?>
                                <tr id="empty_row"><td colspan="7" style="text-align:center; padding:12px;">No products crawled</td></tr>
                            <?php } else { $n = 1; foreach ($products as $p) {
                                $analysed = ((int) $p['analysis_id'] > 0);
                                $kind     = (isset($p['kind']) && $p['kind'] === 'package') ? 'package' : 'itinerary';
                                $hay = strtolower(trim($p['title'] . ' ' . $p['snippet'] . ' ' . $p['url'] . ' ' . $p['duration'] . ' ' . $kind));
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
                                    <td class="cell-kind" style="text-align:center;">
                                        <?php if ($kind === 'package') { ?>
                                            <span class="label label-light-primary label-inline" style="font-size:11px;" data-toggle="tooltip" title="Content mentions a price">Package</span>
                                        <?php } else { ?>
                                            <span class="label label-light-warning label-inline" style="font-size:11px;" data-toggle="tooltip" title="No price found — itinerary only">Itinerary</span>
                                        <?php } ?>
                                    </td>
                                    <td class="cell-cost" style="text-align:center; font-size:12px;"><?php echo $analysed && $p['cost'] > 0 ? number_format((float) $p['cost'], 4) : '—'; ?></td>
                                    <td class="cell-analysed" style="text-align:center; font-size:12px;">
                                        <?php if ($analysed && ! empty($p['analysed_at'])) { ?>
                                            <span style="font-size:11px;"><?php echo htmlspecialchars(date('d M Y, g:i A', strtotime($p['analysed_at']))); ?></span>
                                        <?php } else { ?>
                                            <span class="text-muted" style="font-size:11px;">—</span>
                                        <?php } ?>
                                    </td>
                                    <td class="cell-action" style="text-align:center; white-space:nowrap;">
                                        <?php if ($analysed) { ?>
                                            <a href="<?php echo base_url('Competitor_Product/View?id=') . (int) $p['analysis_id']; ?>" class="btn btn-icon btn-light-success btn-sm mr-1" data-toggle="tooltip" title="View analysis">
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
    var DELETE_URL = '<?php echo base_url('Competitor_Product/Delete_Crawl_Items') ?>';

    function refreshSel() {
        var n = $('.sel-item:checked').length;
        $('#sel_count').text(n);
        $('#del_count').text(n);
        $('#delete_selected').prop('disabled', n === 0);
    }
    $('#sel_all').on('change', function() {
        $('.sel-item').prop('checked', this.checked);
        refreshSel();
    });
    $(document).on('change', '.sel-item', refreshSel);
    $(document).ready(function() {
        refreshSel();
        $('[data-toggle="tooltip"]').tooltip();
        resumeRun();   // re-attach to a background analysis left running before a refresh

        // Back button: return to wherever the user opened Review from (Jobs list or a
        // host's Timeline) instead of always dumping them on the fresh-crawl index.
        // Only trust an in-app referrer; otherwise keep the index fallback href.
        var ref = document.referrer || '';
        if (ref.indexOf('/Competitor_Product') !== -1 && ref.indexOf('/Review') === -1) {
            $('#back_btn').attr('href', ref).on('click', function(e) {
                if (window.history.length > 1) { e.preventDefault(); window.history.back(); }
            });
        }
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
            $(this).toggle(q === '' || hay.indexOf(q) !== -1);
        });
        renumber();
    });

    // Resync selection / numbering / empty-state after rows are removed.
    function afterRemoval() {
        refreshSel();
        renumber();
        if (!$('#products_body tr.product-row').length && !$('#empty_row').length) {
            $('#products_body').append('<tr id="empty_row"><td colspan="7" style="text-align:center; padding:12px;">No products crawled</td></tr>');
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

    // Bulk delete of every checked product.
    $('#delete_selected').click(function() {
        var $checked = $('.sel-item:checked');
        var indices = $checked.map(function() { return $(this).val(); }).get();
        if (!indices.length) { Display_Message(CA_IMG, 'Select at least one product', null); return; }
        deleteProducts(indices, $checked.closest('tr'),
            'Delete ' + indices.length + ' selected product' + (indices.length > 1 ? 's' : '') + '?',
            'They are removed from this crawl. Any saved analyses for them are also deleted.');
    });

    var STATE_URL = '<?php echo base_url('Competitor_Product/Job_State') ?>?job=';
    var VIEW_URL  = '<?php echo base_url('Competitor_Product/View?id=') ?>';
    var analysing = false;   // guards against starting a second run over the first

    // Remember the in-flight analyse job (per crawl) so a page refresh can re-attach to
    // it — the worker runs server-side and merges when done, but the "Analysing…" rows
    // + progress poll live only in this page and would otherwise vanish on reload.
    var LS_KEY = 'cp_analyse_' + JOB;
    function saveRun(analyseJob, indices) {
        try { localStorage.setItem(LS_KEY, JSON.stringify({ job: analyseJob, indices: indices })); } catch (e) {}
    }
    function clearRun() { try { localStorage.removeItem(LS_KEY); } catch (e) {} }
    function loadRun() {
        try { var r = JSON.parse(localStorage.getItem(LS_KEY)); return (r && r.job) ? r : null; } catch (e) { return null; }
    }

    // Non-blocking toast (top-right, no backdrop) so the user can keep working — or
    // leave the page entirely — while the analysis runs in the background.
    var Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false });

    // Format a 'Y-m-d H:i:s' timestamp the same way the server renders "Analysed On".
    function fmtWhen(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(String(s || ''));
        if (!m) return s || '';
        var d = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
        var mon = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getMonth()];
        var h = d.getHours(), ap = h < 12 ? 'AM' : 'PM', h12 = (h % 12) || 12;
        return d.getDate() + ' ' + mon + ' ' + d.getFullYear() + ', ' + h12 + ':' + ('0' + d.getMinutes()).slice(-2) + ' ' + ap;
    }

    function $rowFor(idx) { return $('.sel-item[value="' + idx + '"]').closest('tr'); }

    // Show a spinner in the "Analysed On" cell of each in-flight row. On a resume after
    // refresh, rows the worker already finished are re-rendered as analysed by PHP —
    // skipParkAnalysed keeps their View link/timestamp instead of covering it with a spinner.
    function markPending(indices, skipAnalysed) {
        $.each(indices, function(_, idx) {
            var $row = $rowFor(idx);
            if (skipAnalysed && $row.find('.cell-action .btn-light-success').length) return;
            $row.find('.cell-analysed').html('<span class="text-primary" style="font-size:11px;"><i class="la la-spinner la-spin mr-1"></i>Analysing…</span>');
        });
    }
    // Restore a row's "Analysed On" cell to a dash (used when a run fails to start / errors).
    function clearPending(indices) {
        $.each(indices, function(_, idx) {
            var $c = $rowFor(idx).find('.cell-analysed');
            if ($c.find('.la-spinner').length) $c.html('<span class="text-muted" style="font-size:11px;">—</span>');
        });
    }

    // Fold a finished result into its row in place: cost, analysed-on, a View link, and
    // the analysed row tint — no full-page reload, so search/scroll are preserved.
    function applyResult(idx, r) {
        var $row = $rowFor(idx);
        if (!$row.length || !r) return;
        $row.css('background', '#f6fbf7');
        $row.find('.cell-cost').text(r.cost > 0 ? (+r.cost).toFixed(4) : '—');
        $row.find('.cell-analysed').html('<span style="font-size:11px;">' + fmtWhen(r.at) + '</span>');
        var $act = $row.find('.cell-action');
        if (r.id && !$act.find('.btn-light-success').length) {
            $act.prepend('<a href="' + VIEW_URL + r.id + '" class="btn btn-icon btn-light-success btn-sm mr-1" data-toggle="tooltip" title="View analysis"><i class="la la-search"></i></a>');
            $act.find('[data-toggle="tooltip"]').tooltip();
        }
    }

    function pollAnalyse(analyseJob, indices) {
        var t = setInterval(function() {
            $.getJSON(STATE_URL + encodeURIComponent(analyseJob)).done(function(res) {
                if (!res) return;
                if (res.state === 'done' || res.state === 'error' || res.state === 'unknown') {
                    clearInterval(t);
                    clearRun();
                    analysing = false;
                    $('#analyse_selected').prop('disabled', <?php echo empty($has_ai) ? 'true' : 'false'; ?>).html('<i class="la la-robot"></i> Analyse Selected (<span id="sel_count">' + $('.sel-item:checked').length + '</span>)');
                    if (res.state === 'done') {
                        var results = res.results || {}, applied = 0;
                        $.each(indices, function(_, idx) {
                            if (results[idx]) { applyResult(idx, results[idx]); applied++; }
                        });
                        clearPending(indices);   // any selected item OpenAI returned nothing for
                        Toast.fire({ icon: 'success', title: applied + ' product' + (applied === 1 ? '' : 's') + ' analysed', timer: 4000, timerProgressBar: true });
                    } else {
                        clearPending(indices);
                        Toast.fire({ icon: 'error', title: (res.message || 'Analysis failed'), timer: 6000, timerProgressBar: true });
                    }
                } else {
                    Toast.update({ title: res.message || 'Analysing…' });
                }
            });
        }, 2500);
    }

    // On page load, if a background analyse job for this crawl was left running before a
    // refresh, re-mark its pending rows and resume polling. The poll self-heals: a job
    // that finished (or was deleted) returns done/unknown, folds in results, and clears.
    function resumeRun() {
        var run = loadRun();
        if (!run || !run.job || !(run.indices && run.indices.length)) return;
        analysing = true;
        $('#analyse_selected').prop('disabled', true).html('<i class="la la-spinner la-spin"></i> Analysing in background…');
        markPending(run.indices, true);
        Toast.fire({ icon: 'info', title: 'Resuming background analysis…' });
        pollAnalyse(run.job, run.indices);
    }

    $('#analyse_selected').click(function() {
        if (analysing) return;
        var indices = $('.sel-item:checked').map(function() { return $(this).val(); }).get();
        if (!indices.length) { Display_Message(CA_IMG, 'Select at least one product', null); return; }
        var $btn = $(this);
        analysing = true;
        $btn.prop('disabled', true).html('<i class="la la-spinner la-spin"></i> Analysing in background…');
        markPending(indices);
        $.ajax({
            url: '<?php echo base_url('Competitor_Product/Analyze_Selected') ?>',
            type: 'post', dataType: 'json',
            data: { job: '<?php echo $job; ?>', indices: indices },
            success: function(res) {
                if (res && res.success && res.job) {
                    saveRun(res.job, indices);
                    Toast.fire({ icon: 'info', title: 'Analysing in the background — you can keep working or leave this page.' });
                    pollAnalyse(res.job, indices);
                } else {
                    analysing = false;
                    clearPending(indices);
                    $btn.prop('disabled', false).html('<i class="la la-robot"></i> Analyse Selected (<span id="sel_count">' + $('.sel-item:checked').length + '</span>)');
                    Display_Message(CA_IMG, (res && res.message) ? res.message : 'Could not start analysis', null);
                }
            },
            error: function() {
                analysing = false;
                clearPending(indices);
                $btn.prop('disabled', false).html('<i class="la la-robot"></i> Analyse Selected (<span id="sel_count">' + $('.sel-item:checked').length + '</span>)');
                Display_Message(CA_IMG, 'Could not start analysis. Please try again', null);
            }
        });
    });
</script>
