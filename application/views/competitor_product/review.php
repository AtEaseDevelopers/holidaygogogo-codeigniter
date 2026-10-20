<div class="d-flex flex-column-fluid">
    <div class="container-fluid">
        <div class="card card-custom mb-5">
            <div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
                <div class="card-title">
                    <h3 class="card-label" style="color:#6082B6;">
                        <strong>Review Crawled Products</strong>
                        <span class="text-muted font-weight-normal" style="font-size:12px;">&nbsp; <span id="found_count">0</span> found</span>
                    </h3>
                </div>
                <div class="card-toolbar">
                    <button type="button" id="download_excel" class="btn btn-light-success font-weight-bold mr-3" title="Download checked products, or all products when none are checked">
                        <i class="la la-file-excel"></i> <span id="download_excel_label">Download All (Excel)</span>
                    </button>
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
                <div id="crawl_summary"></div>
                <form id="crawl_export_form" method="post" action="<?php echo base_url('Competitor_Product/Download_Crawl_Excel'); ?>" style="display:none;">
                    <input type="hidden" name="job" value="<?php echo htmlspecialchars($job); ?>">
                    <input type="hidden" name="indices" id="crawl_export_indices">
                </form>
                <?php if (empty($has_ai)) { ?>
                    <div class="alert alert-light-warning" style="font-size:12px;">AI analysis is currently unavailable. You can still review the crawled products below.</div>
                <?php } ?>
                <!-- Search: filter the crawled products by name / description / URL (server-side) -->
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
                                <th style="text-align:center;">Product Code</th>
                                <th>Product</th>
                                <th style="text-align:center;">Type</th>
                                <th style="text-align:center;">AI Cost (USD)</th>
                                <th style="text-align:center;">Analysed On</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="products_body">
                            <tr id="loading_row"><td colspan="8" style="text-align:center; padding:12px;" class="text-muted">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="row mt-3 align-items-center">
                    <div class="col-sm-12 col-md-5">
                        <div id="products_page_info" class="dataTables_info" role="status" aria-live="polite"></div>
                    </div>
                    <div class="col-sm-12 col-md-7">
                        <nav class="dataTables_paginate paging_simple_numbers" aria-label="Product pages">
                            <ul id="products_pagination" class="pagination justify-content-end"></ul>
                        </nav>
                    </div>
                </div>
                <div id="products_load_error" class="text-danger mt-2" style="font-size:12px;" role="alert"></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="crawled_text_modal" tabindex="-1" role="dialog" aria-labelledby="crawled_text_title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="crawled_text_title">Crawled text</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <p id="crawled_text_retrieved_at" class="text-muted" style="font-size:12px;"></p>
                <pre id="crawled_text_body" style="white-space:pre-wrap; word-break:break-word; max-height:65vh; overflow:auto; background:#f7f9fc; border:1px solid #e4e6ef; border-radius:6px; padding:12px; font-size:13px;">Loading…</pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light font-weight-bold" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
    var CA_IMG = '<?php echo base_url('assets/image/sweetalert.jpg') ?>';
    var JOB        = '<?php echo $job; ?>';
    var DELETE_URL = '<?php echo base_url('Competitor_Product/Delete_Crawl_Items') ?>';
    var EXCEL_URL  = '<?php echo base_url('Competitor_Product/Download_Crawl_Excel') ?>';
    var LIST_URL   = '<?php echo base_url('Competitor_Product/Review_List') ?>';

    // Paging is server-side: each request returns one page of rows. Selection is kept
    // client-side as a set of product indices so checks persist across pages, and
    // "select all" uses the full matching index list the server returns.
    var revPage = 1, revPageSize = 25, revSearch = '';
    var selected = {};        // idx -> true for every checked product (all pages)
    var currentAll = [];      // every matching product index for the current search
    var listRequest = null, listVersion = 0;

    var crawledTextRequest = null;
    $(document).on('click', '.view-crawled-text', function(event) {
        event.preventDefault();
        if (crawledTextRequest) crawledTextRequest.abort();
        $('#crawled_text_title').text('Crawled text');
        $('#crawled_text_retrieved_at').text('');
        $('#crawled_text_body').text('Loading…');
        $('#crawled_text_modal').modal('show');
        crawledTextRequest = $.ajax({
            url: '<?php echo base_url('Competitor_Product/Crawled_Text'); ?>',
            method: 'GET', dataType: 'json', timeout: 30000,
            data: { job: JOB, index: $(this).data('index') }
        }).done(function(response) {
            if (!response || !response.success) {
                $('#crawled_text_body').text((response && response.message) || 'Could not load the saved text.');
                return;
            }
            $('#crawled_text_title').text(response.title || 'Crawled text');
            $('#crawled_text_retrieved_at').text('Content retrieved at: ' + (response.retrieved_at_label || 'Not recorded'));
            $('#crawled_text_body').text(response.text || 'No text was saved for this product.');
        }).fail(function(xhr, status) {
            if (status === 'abort') return;
            $('#crawled_text_body').text((xhr.responseJSON && xhr.responseJSON.message) || 'Could not load the saved text. Please try again.');
        });
    });
    $('#crawled_text_modal').on('hidden.bs.modal', function() {
        if (crawledTextRequest) crawledTextRequest.abort();
    });

    // --- selection (spans pages via the `selected` set) ---------------------------
    function refreshSel() {
        var n = Object.keys(selected).length;
        $('#sel_count').text(n);
        $('#del_count').text(n);
        $('#delete_selected').prop('disabled', n === 0);
        $('#download_excel_label').text(n > 0 ? 'Download Selected (' + n + ') (Excel)' : 'Download All (Excel)');
        // "Select all" reflects the current search match across all pages.
        var pickable = currentAll.filter(function(i) { return !disabledIdx[i]; });
        var picked = pickable.filter(function(i) { return selected[i]; }).length;
        $('#sel_all').prop('checked', pickable.length > 0 && picked === pickable.length)
            .prop('indeterminate', picked > 0 && picked < pickable.length)
            .prop('disabled', pickable.length === 0);
    }
    // Indices whose checkbox is disabled (empty products) — never selectable.
    var disabledIdx = {};
    $(document).on('change', '.sel-item', function() {
        var v = this.value;
        if (this.checked) selected[v] = true; else delete selected[v];
        refreshSel();
    });
    $('#sel_all').on('change', function() {
        var on = this.checked;
        currentAll.forEach(function(i) {
            if (disabledIdx[i]) return;
            if (on) selected[i] = true; else delete selected[i];
        });
        $('#products_body .sel-item:not(:disabled)').prop('checked', on);
        refreshSel();
    });

    // --- paginated load -----------------------------------------------------------
    function buildPager(paging) {
        var pager = [];
        var addPage = function(page, label, disabled, active, direction) {
            pager.push('<li class="page-item' + (direction ? ' ' + direction : '') + (disabled ? ' disabled' : '') + (active ? ' active' : '') + '">'
                + '<button type="button" class="page-link" data-products-page="' + page + '"'
                + (disabled || active ? ' disabled' : '') + (active ? ' aria-current="page"' : '')
                + (direction ? ' aria-label="' + (direction === 'previous' ? 'Previous page' : 'Next page') + '"' : '')
                + '>' + label + '</button></li>');
        };
        addPage(paging.page - 1, '<i class="ki ki-arrow-back" aria-hidden="true"></i>', paging.page <= 1, false, 'previous');
        var lastPage = 0;
        for (var p = 1; p <= paging.pages; p++) {
            if (p !== 1 && p !== paging.pages && Math.abs(p - paging.page) > 2) continue;
            if (lastPage && p > lastPage + 1) pager.push('<li class="page-item disabled"><span class="page-link">…</span></li>');
            addPage(p, p, false, p === paging.page);
            lastPage = p;
        }
        addPage(paging.page + 1, '<i class="ki ki-arrow-next" aria-hidden="true"></i>', paging.page >= paging.pages, false, 'next');
        $('#products_pagination').html(pager.join(''));
    }
    function renderSummary(s) {
        if (!s) { $('#crawl_summary').empty(); return; }
        var esc = function(v) { return $('<div>').text(v == null ? '' : v).html(); };
        $('#crawl_summary').html('<div class="alert alert-light-info mb-4" style="font-size:13px;">'
            + '<strong>Crawl Summary:</strong> '
            + esc(s.total_records) + ' crawled records &middot; '
            + esc(s.duplicate_records) + ' duplicate records &middot; '
            + esc(s.unique_products) + ' unique products remaining &middot; '
            + esc(s.needs_review) + ' need review</div>');
    }
    function renderReview(res) {
        var paging = res.pagination || { page: 1, page_size: revPageSize, pages: 1, start: 0, end: 0 };
        revPage = paging.page;
        revPageSize = paging.page_size;
        currentAll = res.all_indices || [];
        $('#found_count').text(res.total || 0);
        renderSummary(res.summary);

        var $body = $('#products_body');
        if (!res.rows_html) {
            $body.html('<tr><td colspan="8" style="text-align:center; padding:12px;" class="text-muted">'
                + (revSearch ? 'No products match your search.' : 'No products crawled') + '</td></tr>');
        } else {
            $body.html(res.rows_html);
        }
        // Rebuild the disabled-index map + restore checks from the selection set.
        disabledIdx = {};
        $body.find('.sel-item').each(function() {
            if (this.disabled) disabledIdx[this.value] = true;
            this.checked = !!selected[this.value];
        });
        $body.find('[data-toggle="tooltip"]').tooltip();

        var info = 'Showing ' + (paging.start || 0).toLocaleString() + ' to ' + (paging.end || 0).toLocaleString()
            + ' of ' + (res.found || 0).toLocaleString() + ' entries';
        if ((res.found || 0) !== (res.total || 0)) info += ' (filtered from ' + (res.total || 0).toLocaleString() + ')';
        $('#products_page_info').text(info);
        buildPager(paging);
        refreshSel();
    }
    function loadReview() {
        var version = ++listVersion;
        if (listRequest) listRequest.abort();
        $('#products_load_error').text('');
        listRequest = $.getJSON(LIST_URL, { job: JOB, page: revPage, page_size: revPageSize, search: revSearch })
            .done(function(res) { if (version === listVersion) renderReview(res); })
            .fail(function(xhr, status) {
                if (status !== 'abort' && version === listVersion) {
                    $('#products_load_error').text('Could not load products. Please try again.');
                    $('#products_body').html('<tr><td colspan="8" style="text-align:center; padding:12px;" class="text-danger">Could not load products.</td></tr>');
                }
            })
            .always(function() { if (version === listVersion) listRequest = null; });
    }

    var productSearchTimer = null;
    $('#product_search').on('input', function() {
        revSearch = $.trim($(this).val());
        revPage = 1;
        clearTimeout(productSearchTimer);
        productSearchTimer = setTimeout(loadReview, 300);
    });
    $(document).on('click', '#products_pagination button[data-products-page]', function() {
        if (this.disabled) return;
        revPage = Number($(this).attr('data-products-page'));
        loadReview();
        // Start each new page from the top of the list instead of leaving the user
        // scrolled down by the pager.
        var card = $('.card-custom').offset();
        $('html, body').animate({ scrollTop: (card ? card.top : 0) - 20 }, 200);
    });

    $('#download_excel').on('click', function() {
        var indices = Object.keys(selected);
        if (!indices.length) {
            window.location.href = EXCEL_URL + '?job=' + encodeURIComponent(JOB);
            return;
        }
        $('#crawl_export_indices').val(indices.join(','));
        document.getElementById('crawl_export_form').submit();
    });

    $(document).ready(function() {
        loadReview();
        resumeRun();   // re-attach to a background analysis left running before a refresh

        // Back button: return to wherever the user opened Review from (Jobs list or a
        // host's Timeline) instead of always dumping them on the fresh-crawl index.
        var ref = document.referrer || '';
        if (ref.indexOf('/Competitor_Product') !== -1 && ref.indexOf('/Review') === -1) {
            $('#back_btn').attr('href', ref).on('click', function(e) {
                if (window.history.length > 1) { e.preventDefault(); window.history.back(); }
            });
        }
    });

    // --- delete -------------------------------------------------------------------
    // Confirm, then delete the given product indices from the crawl (and any saved
    // analyses). On success, drop them from the selection and reload the current page.
    function deleteProducts(indices, title, text) {
        Swal.mixin({ customClass: { confirmButton: 'btn btn-light-success m-2', cancelButton: 'btn btn-danger m-2' }, buttonsStyling: true })
            .fire({ width: 500, background: 'url(' + CA_IMG + ')', icon: 'warning',
                title: title, text: text,
                confirmButtonText: 'Delete', cancelButtonText: 'Cancel', showCancelButton: true })
            .then(function(a) {
                if (!a.isConfirmed) return;
                $.post(DELETE_URL, { job: JOB, indices: indices }, function(res) {
                    if (res && res.success) {
                        indices.forEach(function(i) { delete selected[i]; });
                        loadReview();
                    } else {
                        Display_Message(CA_IMG, (res && res.message) ? res.message : 'Could not delete', null);
                    }
                }, 'json').fail(function() { Display_Message(CA_IMG, 'Could not delete. Please try again', null); });
            });
    }
    $(document).on('click', '.delete-item', function() {
        deleteProducts([String($(this).data('index'))],
            'Delete this crawled product?',
            'It is removed from this crawl. Any saved analysis for it is also deleted.');
    });
    $('#delete_selected').click(function() {
        var indices = Object.keys(selected);
        if (!indices.length) { Display_Message(CA_IMG, 'Select at least one product', null); return; }
        deleteProducts(indices,
            'Delete ' + indices.length + ' selected product' + (indices.length > 1 ? 's' : '') + '?',
            'They are removed from this crawl. Any saved analyses for them are also deleted.');
    });

    // --- analyse (background) -----------------------------------------------------
    var STATE_URL = '<?php echo base_url('Competitor_Product/Job_State') ?>?job=';
    var analysing = false;   // guards against starting a second run over the first

    // Remember the in-flight analyse job (per crawl) so a page refresh can re-attach.
    var LS_KEY = 'cp_analyse_' + JOB;
    function saveRun(analyseJob, indices) {
        try { localStorage.setItem(LS_KEY, JSON.stringify({ job: analyseJob, indices: indices })); } catch (e) {}
    }
    function clearRun() { try { localStorage.removeItem(LS_KEY); } catch (e) {} }
    function loadRun() {
        try { var r = JSON.parse(localStorage.getItem(LS_KEY)); return (r && r.job) ? r : null; } catch (e) { return null; }
    }

    // Non-blocking toast so the user can keep working while the analysis runs.
    var Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false });

    function $rowFor(idx) { return $('.sel-item[value="' + idx + '"]').closest('tr'); }

    // Spinner in the "Analysed On" cell of each in-flight row that is on the current page.
    function markPending(indices) {
        $.each(indices, function(_, idx) {
            $rowFor(idx).find('.cell-analysed').html('<span class="text-primary" style="font-size:11px;"><i class="la la-spinner la-spin mr-1"></i>Analysing…</span>');
        });
    }

    function pollAnalyse(analyseJob, indices) {
        var t = setInterval(function() {
            $.getJSON(STATE_URL + encodeURIComponent(analyseJob)).done(function(res) {
                if (!res) return;
                if (res.state === 'done' || res.state === 'error' || res.state === 'unknown') {
                    clearInterval(t);
                    clearRun();
                    analysing = false;
                    $('#analyse_selected').prop('disabled', <?php echo empty($has_ai) ? 'true' : 'false'; ?>).html('<i class="la la-robot"></i> Analyse Selected (<span id="sel_count">' + Object.keys(selected).length + '</span>)');
                    if (res.state === 'done') {
                        var applied = res.results ? Object.keys(res.results).length : 0;
                        Toast.fire({ icon: 'success', title: applied + ' product' + (applied === 1 ? '' : 's') + ' analysed', timer: 4000, timerProgressBar: true });
                    } else {
                        Toast.fire({ icon: 'error', title: (res.message || 'Analysis failed'), timer: 6000, timerProgressBar: true });
                    }
                    loadReview();   // refresh the current page so analysed status/cost show
                } else {
                    Toast.update({ title: res.message || 'Analysing…' });
                }
            });
        }, 2500);
    }

    // On load, re-attach to a background analyse job for this crawl left running before a refresh.
    function resumeRun() {
        var run = loadRun();
        if (!run || !run.job || !(run.indices && run.indices.length)) return;
        analysing = true;
        $('#analyse_selected').prop('disabled', true).html('<i class="la la-spinner la-spin"></i> Analysing in background…');
        markPending(run.indices);
        Toast.fire({ icon: 'info', title: 'Resuming background analysis…' });
        pollAnalyse(run.job, run.indices);
    }

    $('#analyse_selected').click(function() {
        if (analysing) return;
        var indices = Object.keys(selected);
        if (!indices.length) { Display_Message(CA_IMG, 'Select at least one product', null); return; }
        var $btn = $(this);
        analysing = true;
        $btn.prop('disabled', true).html('<i class="la la-spinner la-spin"></i> Analysing in background…');
        markPending(indices);
        $.ajax({
            url: '<?php echo base_url('Competitor_Product/Analyze_Selected') ?>',
            type: 'post', dataType: 'json',
            data: { job: JOB, indices: indices },
            success: function(res) {
                if (res && res.success && res.job) {
                    saveRun(res.job, indices);
                    Toast.fire({ icon: 'info', title: 'Analysing in the background — you can keep working or leave this page.' });
                    pollAnalyse(res.job, indices);
                } else {
                    analysing = false;
                    $btn.prop('disabled', false).html('<i class="la la-robot"></i> Analyse Selected (<span id="sel_count">' + Object.keys(selected).length + '</span>)');
                    Display_Message(CA_IMG, (res && res.message) ? res.message : 'Could not start analysis', null);
                    loadReview();
                }
            },
            error: function() {
                analysing = false;
                $btn.prop('disabled', false).html('<i class="la la-robot"></i> Analyse Selected (<span id="sel_count">' + Object.keys(selected).length + '</span>)');
                Display_Message(CA_IMG, 'Could not start analysis. Please try again', null);
                loadReview();
            }
        });
    });
</script>
