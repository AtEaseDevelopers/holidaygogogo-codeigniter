<div class="d-flex flex-column-fluid">
    <div class="container-fluid">

        <!-- Analyse a competitor URL -->
        <div class="card card-custom mb-5">
            <div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
                <div class="card-title">
                    <h3 class="card-label" style="color:#6082B6;">
                        <strong>Analyse Competitor Product</strong>
                    </h3>
                </div>
            </div>
            <div class="card-body">
                <!-- Competitor name — a label the user keys in; recorded + shown in the
                     results table only. It is NOT sent to the AI. Applies to whichever
                     input below is submitted (crawl / upload / paste). -->
                <div class="form-group mb-3">
                    <label style="font-size:13px;"><strong>Competitor name</strong> <span class="text-muted font-weight-normal">(optional)</span></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="la la-building"></i></span>
                        </div>
                        <input type="text" id="competitor_name" class="form-control" autocomplete="off"
                               placeholder="e.g. Apple Vacations" style="font-size:14px;" maxlength="255">
                    </div>
                    <span class="form-text text-muted" style="font-size:12px;">Just a label to identify this competitor in the results — not sent to the AI.</span>
                </div>

                <!-- Section 1: Crawl a competitor website (URL + keyword in one row) -->
                <div class="row align-items-start">
                    <div class="col-md-6">
                        <div class="form-group mb-2">
                            <label style="font-size:13px;"><strong>Competitor Website (base URL)</strong></label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><i class="la la-link"></i></span>
                                </div>
                                <input type="url" id="competitor_url" class="form-control" autocomplete="off"
                                       placeholder="https://competitor.com" style="font-size:14px;">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group mb-2">
                            <label style="font-size:13px;"><strong>Keyword</strong> <span class="text-muted font-weight-normal">(optional)</span></label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><i class="la la-filter"></i></span>
                                </div>
                                <input type="text" id="competitor_keyword" class="form-control" autocomplete="off"
                                       placeholder="e.g. yunnan japan  (blank = whole site)" style="font-size:14px;">
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Crawl with AI (moved to bottom of Section 1) -->
                <div class="form-group mb-2">
                    <label class="d-inline-flex align-items-center mb-1" style="cursor:pointer; font-size:13px;">
                        <input type="checkbox" id="competitor_ai_crawl" class="mr-2" style="width:16px; height:16px;">
                        <span class="font-weight-bold">Crawl with AI</span>
                    </label>
                    <span class="form-text text-muted" style="font-size:12px;">Lets AI browse the site to find the tour pages — best for JS sites the quick crawl can’t read.</span>
                </div>

                <!-- OR divider -->
                <div class="text-center my-3"><span class="text-muted font-weight-bold" style="font-size:12px;">OR</span></div>

                <!-- Section 2: Upload a file (full row) -->
                <div class="form-group mb-2">
                    <label style="font-size:13px;"><strong>Upload PDF or Image</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="la la-file-upload"></i></span>
                        </div>
                        <div class="custom-file">
                            <input type="file" id="competitor_file" class="custom-file-input"
                                   accept=".pdf,.jpg,.jpeg,.png,.gif,.webp">
                            <label class="custom-file-label" id="competitor_file_label" for="competitor_file" style="font-size:13px;">Choose a PDF or image…</label>
                        </div>
                    </div>
                    <span class="form-text text-muted" style="font-size:12px;">
                        A brochure, flyer, itinerary or screenshot (PDF / JPG / PNG / GIF / WEBP, max 20&nbsp;MB).
                    </span>
                </div>

                <!-- OR divider -->
                <div class="text-center my-3"><span class="text-muted font-weight-bold" style="font-size:12px;">OR</span></div>

                <!-- Section 3: Paste free text / links (full row) -->
                <div class="form-group mb-2">
                    <label style="font-size:13px;"><strong>Paste text or links</strong></label>
                    <textarea id="competitor_paste" class="form-control" rows="4" style="font-size:14px;"
                              placeholder="Paste competitor notes here. Any links you drop in are opened and read, then everything is sent to AI for analysis."></textarea>
                    <span class="form-text text-muted" style="font-size:12px;">
                        We fetch the content of every link in the text and hand it — plus your notes — to the AI.
                    </span>
                </div>

                <button type="button" id="analyze_btn" class="btn btn-primary font-weight-bold mt-2" style="min-width:200px;">
                    <i class="la la-robot"></i> Analyse with AI
                </button>
            </div>
        </div>

        <!-- History (also shows in-progress background crawls at the top) -->
        <div class="card card-custom mb-5">
            <div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
                <div class="card-title">
                    <h3 class="card-label" style="color:#6082B6;">
                        <strong>Analysis Results</strong>
                        <span class="text-muted font-weight-normal" style="font-size:12px;">&nbsp; crawls &amp; uploaded files</span>
                    </h3>
                </div>
                <div class="card-toolbar">
                    <span class="label label-light-primary label-inline font-weight-bold" style="font-size:13px; padding:16px 14px;">
                        <i class="la la-dollar-sign mr-1"></i>Total AI Cost:&nbsp;
                        <strong>USD <span id="total_cost">0.0000</span></strong>
                    </span>
                </div>
            </div>
            <div class="card-body">
                <form id="jobs_filter_form" class="mb-4">
                    <div class="row align-items-end">
                        <div class="col-md-3 form-group">
                            <label for="jobs_status_filter">Process Status</label>
                            <select id="jobs_status_filter" class="form-control" name="status">
                                <option value="" data-label="All">All</option>
                                <?php foreach (array('queued'=>'Queued', 'running'=>'In Progress', 'done'=>'Completed', 'error'=>'Error') as $value=>$label) { ?>
                                <option value="<?php echo $value; ?>" data-label="<?php echo $label; ?>"><?php echo $label; ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label for="jobs_db_status_filter">Database Status</label>
                            <select id="jobs_db_status_filter" class="form-control" name="db_status">
                                <option value="" data-label="All">All</option>
                                <?php foreach (array('waiting'=>'Waiting for Crawl', 'not_saved'=>'Awaiting Analysis', 'analysis_queued'=>'Analysis Queued',
                                    'analysing'=>'Analysing', 'partial'=>'Partly Saved', 'saved'=>'Analysis Complete', 'error'=>'Analysis Failed',
                                    'missing'=>'Saved Record Missing', 'empty'=>'No Products') as $value=>$label) { ?>
                                <option value="<?php echo $value; ?>" data-label="<?php echo $label; ?>"><?php echo $label; ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group">
                            <label for="jobs_search">Search</label>
                            <input type="search" id="jobs_search" name="search" class="form-control" autocomplete="off"
                                   placeholder="Website, name or file…" maxlength="200" aria-label="Search all analysis results">
                        </div>
                        <div class="col-md-2 form-group">
                            <div class="d-flex flex-wrap">
                                <button type="submit" class="btn btn-light-primary mr-2">Search</button>
                                <button type="button" id="jobs_filter_reset" class="btn btn-light">Reset</button>
                            </div>
                        </div>
                    </div>
                </form>
                <p class="text-muted" style="font-size:12px;">Crawled products stay in JSON. Successful AI analysis saves the results to the database.</p>
                <div class="dataTables_wrapper dt-bootstrap4">
                <div class="row align-items-center mb-3">
                    <div class="col-sm-12 col-md-6">
                        <div class="dataTables_length">
                            <label>Show
                                <select id="jobs_page_size" class="custom-select custom-select-sm form-control form-control-sm" aria-label="Results per page">
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                                entries
                            </label>
                        </div>
                    </div>
                </div>
                <div style="overflow-x:auto;">
                    <table class="table table-bordered table-head-custom table-checkable">
                        <thead>
                            <tr>
                                <th style="text-align:center;">No.</th>
                                <th style="text-align:center; width:260px;">Website</th>
                                <th style="text-align:center;">Products</th>
                                <th style="text-align:center;">AI Cost (USD)</th>
                                <th style="text-align:center;">Process Status</th>
                                <th style="text-align:center; min-width:150px;">Database Status</th>
                                <th style="text-align:center;">Date</th>
                                <th class="action" style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="jobs_rows">
                            <tr id="no_jobs"><td colspan="8" style="text-align:center; padding:12px;" class="text-muted">Loading results…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="row mt-3 align-items-center">
                    <div class="col-sm-12 col-md-5">
                        <div id="jobs_page_info" class="dataTables_info" role="status" aria-live="polite"></div>
                    </div>
                    <div class="col-sm-12 col-md-7">
                        <nav class="dataTables_paginate paging_simple_numbers" aria-label="Analysis result pages">
                            <ul id="jobs_pagination" class="pagination justify-content-end"></ul>
                        </nav>
                    </div>
                </div>
                </div>
                <div id="jobs_load_error" class="text-danger mt-2" style="font-size:12px;" role="alert"></div>
            </div>
        </div>

    </div>
</div>

<script>
    var CA_IMG = '<?php echo base_url('assets/image/sweetalert.jpg') ?>';

    // Show the chosen file name in the label.
    $('#competitor_file').on('change', function() {
        var name = (this.files && this.files.length) ? this.files[0].name : 'Choose a PDF or image…';
        $('#competitor_file_label').text(name);
    });

    // ---- Background crawl jobs, shown as rows AT THE TOP of Analysis History ----
    var jobsTimer = null;
    var jobsPage = 1, jobsPageSize = 25, jobsSearch = '';
    var jobsStatus = '', jobsDbStatus = '';
    var jobsRequest = null, jobsRequestVersion = 0, jobsSearchTimer = null;
    var VIEW_URL     = '<?php echo base_url('Competitor_Product/View?id=') ?>';
    var PDF_URL      = '<?php echo base_url('Competitor_Product/Download_Pdf?id=') ?>';
    var REVIEW_URL   = '<?php echo base_url('Competitor_Product/Review') ?>?job=';
    var TIMELINE_URL = '<?php echo base_url('Competitor_Product/Timeline') ?>?host=';

    function fmtDur(sec) {
        sec = Math.max(0, Math.round(sec));
        if(sec < 60) return sec + 's';
        var m = Math.floor(sec / 60), s = sec % 60;
        if(m < 60) return m + 'm' + (s ? ' ' + s + 's' : '');
        var h = Math.floor(m / 60); m = m % 60;
        return h + 'h' + (m ? ' ' + m + 'm' : '');
    }
    function parseTs(t) { return t ? new Date(String(t).replace(' ', 'T')).getTime() : 0; }

    // Live ETA for a running crawl: reading phase has a known total, so estimate
    // from time-per-product; during discovery just show elapsed. Returns a labelled
    // string for its own line.
    function jobEta(j) {
        if(j.total > 0 && j.done > 0 && j.done < j.total && j.read_start) {
            var el = (Date.now() - parseTs(j.read_start)) / 1000;
            if(el > 1) return 'Time remaining: ~' + fmtDur(el / j.done * (j.total - j.done));
        } else if(!j.read_start && j.ts) {
            var el2 = (Date.now() - parseTs(j.ts)) / 1000;
            if(el2 > 2) return 'Elapsed: ' + fmtDur(el2);
        }
        return '';
    }
    function jobStatusBadge(j) {
        if(j.state === 'error') return '<span class="label label-light-danger label-inline font-weight-bold">Error</span>';
        // Queued behind a running crawl (one crawl at a time) — pause icon, black text on grey.
        if(j.state === 'queued') {
            return '<span class="label label-inline font-weight-bold" style="background-color:#eeeeee; color:#000;" title="' + $('<div>').text(j.message || 'Queued').html() + '"><i class="la la-pause-circle mr-1" style="color:#000;"></i>Queued</span>';
        }
        if(j.state !== 'done') {
            // Multi-chunk crawl: show read X / Y (+ %) from discovered vs done counts.
            if(j.read_total > 0) {
                var pct = Math.min(100, Math.round((j.read_done || 0) * 100 / j.read_total));
                return '<span class="label label-light-warning label-inline font-weight-bold"><i class="la la-spinner la-spin mr-1"></i>Reading ' + (j.read_done||0).toLocaleString() + ' / ' + j.read_total.toLocaleString() + ' (' + pct + '%)</span>';
            }
            var badge = '<span class="label label-light-warning label-inline font-weight-bold"><i class="la la-spinner la-spin mr-1"></i>' + $('<div>').text(j.message || 'Working…').html() + '</span>';
            var eta = jobEta(j);
            return badge + (eta ? '<br><span style="font-size:10px; color:#8ba0c4;">' + $('<div>').text(eta).html() + '</span>' : '');
        }
        return '<span class="label label-light-info label-inline font-weight-bold">' + (j.is_paste ? 'Analysed' : (j.is_crawled ? 'Crawled' : (j.is_upload ? 'Uploaded' : 'Crawled'))) + '</span>';
    }
    function jobDatabaseStatus(j) {
        var badges = {
            waiting: ['dark', 'Waiting for crawl'],
            not_saved: ['warning', 'Awaiting analysis'],
            analysis_queued: ['warning', 'Analysis queued'],
            analysing: ['primary', 'Analysing'],
            partial: ['warning', 'Partly saved'],
            saved: ['success', 'Analysis Complete'],
            error: ['danger', 'Analysis failed'],
            missing: ['danger', 'Saved record missing'],
            empty: ['dark', 'No products']
        };
        var badge = badges[j.db_status] || ['dark', 'Unknown'];
        var html = '<span class="label label-light-' + badge[0] + ' label-inline font-weight-bold">' + badge[1] + '</span>';
        if(j.db_total > 0) {
            html += '<div class="text-muted mt-1" style="font-size:11px;">'
                + Number(j.db_saved || 0).toLocaleString() + ' of ' + Number(j.db_total).toLocaleString() + ' saved'
                + (j.is_group && j.runs_count > 1 ? ' (latest crawl)' : '') + '</div>';
        }
        if(j.db_missing > 0) {
            html += '<div class="text-danger mt-1" style="font-size:11px;">' + Number(j.db_missing).toLocaleString() + ' saved record(s) unavailable</div>';
        }
        if(j.analysis_state === 'running' || j.analysis_state === 'queued') {
            html += '<div class="text-muted mt-1" style="font-size:11px;">'
                + (j.analysis_earlier_crawl ? 'Earlier crawl: ' : '')
                + Number(j.analysis_done || 0).toLocaleString() + ' of ' + Number(j.analysis_total || 0).toLocaleString() + ' processed</div>';
        }
        return html;
    }
    function jobActionCell(j) {
        var items = [];
        if(j.is_group) {
            // A merged website row — its per-run history (Review & Select, Terminate,
            // Delete) lives on the Timeline page.
            items.push('<a href="' + TIMELINE_URL + encodeURIComponent(j.host) + '" class="dropdown-item" style="font-size:11px;"><i class="la la-history mr-2"></i>View Timeline</a>');
        } else if(j.state !== 'done' && j.state !== 'error') {
            // Running → Terminate (kills the worker + drops the task).
            items.push('<a href="javascript:;" class="dropdown-item terminate-job" data-job="' + j.job + '" style="font-size:11px;"><i class="la la-times mr-2"></i>Terminate</a>');
        } else {
            if(j.analysis_id) {
                items.push('<a href="' + VIEW_URL + j.analysis_id + '" class="dropdown-item" style="font-size:11px;"><i class="la la-search mr-2"></i>View Analysis</a>');
                items.push('<a href="' + PDF_URL + j.analysis_id + '" class="dropdown-item" style="font-size:11px;"><i class="la la-file-pdf mr-2"></i>Download PDF</a>');
            }
            else if(j.reviewable) items.push('<a href="' + REVIEW_URL + encodeURIComponent(j.job) + '" class="dropdown-item" style="font-size:11px;"><i class="la la-list-alt mr-2"></i>Review &amp; Select</a>');
            if(j.is_upload) items.push('<a href="javascript:;" class="dropdown-item delete-upload" data-id="' + j.analysis_id + '" style="font-size:11px;"><i class="la la-trash mr-2"></i>Delete</a>');
            else items.push('<a href="javascript:;" class="dropdown-item delete-job" data-job="' + j.job + '" style="font-size:11px;"><i class="la la-trash mr-2"></i>Delete</a>');
        }
        return '<div class="btn-group">'
            + '<button type="button" data-toggle="dropdown" class="btn btn-light-primary btn-sm dropdown-toggle" style="padding-left:3px;"></button>'
            + '<div class="dropdown-menu dropdown-menu-right">' + items.join('') + '</div></div>';
    }

    // Terminate a running job: kill the worker + drop the task (row disappears).
    $(document).on('click', '.terminate-job', function() {
        var job = $(this).data('job');
        $(this).closest('tr').fadeOut(200);   // optimistic drop
        $.post('<?php echo base_url('Competitor_Product/Terminate_Job') ?>', { job: job }, function() { loadJobs(); }, 'json')
            .fail(function() { loadJobs(); });
    });

    // Delete a finished/errored job (drops its files; the analysis history stays).
    $(document).on('click', '.delete-job', function() {
        var $b = $(this), job = $b.data('job'), $row = $b.closest('tr');
        Swal.mixin({ customClass: { confirmButton: 'btn btn-light-success m-2', cancelButton: 'btn btn-danger m-2' }, buttonsStyling: true })
            .fire({ width: 500, background: 'url(' + CA_IMG + ')', icon: 'warning',
                title: 'Delete this crawl job?', text: 'The saved analysis (if any) is kept.',
                confirmButtonText: 'Delete', cancelButtonText: 'Cancel', showCancelButton: true })
            .then(function(a) {
                if(a.isConfirmed) {
                    $row.fadeOut(200);
                    $.post('<?php echo base_url('Competitor_Product/Terminate_Job') ?>', { job: job }, function() { loadJobs(); }, 'json');
                }
            });
    });

    // Delete an uploaded PDF/image analysis (a DB row, not a crawl job).
    $(document).on('click', '.delete-upload', function() {
        var $b = $(this), id = $b.data('id'), $row = $b.closest('tr');
        Swal.mixin({ customClass: { confirmButton: 'btn btn-light-success m-2', cancelButton: 'btn btn-danger m-2' }, buttonsStyling: true })
            .fire({ width: 500, background: 'url(' + CA_IMG + ')', icon: 'warning',
                title: 'Delete this uploaded analysis?', text: 'This removes it permanently.',
                confirmButtonText: 'Delete', cancelButtonText: 'Cancel', showCancelButton: true })
            .then(function(a) {
                if(a.isConfirmed) {
                    $row.fadeOut(200);
                    $.post('<?php echo base_url('Competitor_Product/Delete') ?>', { id: id }, function() { loadJobs(); }, 'json')
                        .fail(function() { loadJobs(); });
                }
            });
    });

    // Render crawl rows into the Crawled Results table (No · Website · Products ·
    // AI Cost · Status · Date · Action). Paging happens on the server; only the
    // selected page is returned, while the cost still covers the complete history.
    function renderJobs(res) {
        var $rows = $('#jobs_rows');
        if(!$rows.length) return;
        var jobs = (res && res.jobs) ? res.jobs : [];
        var esc = function(s){ return $('<div>').text(s == null ? '' : s).html(); };
        var paging = res.pagination;
        jobsPage = paging.page;
        jobsPageSize = paging.page_size;
        $('#jobs_page_size').val(jobsPageSize);
        if(res.filter_counts) {
            updateJobFilterCounts('#jobs_status_filter', res.filter_counts.status);
            updateJobFilterCounts('#jobs_db_status_filter', res.filter_counts.db_status);
        }
        var no = paging.start > 0 ? paging.start - 1 : 0;
        if(!jobs.length) {
            $rows.html('<tr id="no_jobs"><td colspan="8" style="text-align:center; padding:12px;" class="text-muted">'
                + (jobsSearch || jobsStatus || jobsDbStatus ? 'No results match your filters' : 'No results yet — crawl a site or upload a PDF/image') + '</td></tr>');
        } else {
            $rows.html(jobs.map(function(j) {
                no++;
                var products = (j.is_upload && !j.is_crawled)
                    ? '<span class="text-muted">—</span>'
                    : (j.is_group
                        ? ((j.count > 0 ? j.count.toLocaleString() : '—') + (j.runs_count > 1 ? '<div class="text-muted" style="font-size:10px;">Latest crawl</div>' : ''))
                        : ((j.state === 'done') ? j.count.toLocaleString() : '—'));
                var cost = (j.cost_total > 0) ? Number(j.cost_total).toFixed(4) : '—';
                // Source cell: a merged website row shows the host + a "N crawls" tag;
                // a single crawl shows a clickable URL (+ keyword/full-render chips);
                // a pasted analysis shows its label (a link when it's a URL) + a "Text" tag;
                // an upload shows the file name + a "File" tag.
                var isHttp = /^https?:\/\//i.test(j.url || '');
                var kwChip = j.keyword ? '<br><span class="label label-light-primary label-inline font-weight-bold mt-1" style="font-size:11px;"><i class="la la-filter mr-1"></i>Keyword: ' + esc(j.keyword) + '</span>' : '';
                var aiChip = j.ai_crawl ? ' <span class="label label-light-info label-inline font-weight-bold mt-1" style="font-size:11px;"><i class="la la-robot mr-1"></i>AI crawl</span>' : '';
                var source = j.is_group
                    ? '<a href="' + esc(j.url) + '" target="_blank" rel="noopener" style="font-size:12px;">' + esc(j.host) + '</a>'
                        + ' <span class="label label-light-dark label-inline font-weight-bold" style="font-size:10px;"><i class="la la-history mr-1"></i>' + j.runs_count + ' crawl' + (j.runs_count > 1 ? 's' : '') + '</span>'
                        + kwChip + aiChip
                    : j.is_paste
                    ? (isHttp
                        ? '<a href="' + esc(j.url) + '" target="_blank" rel="noopener" style="font-size:12px;"><i class="la la-paste mr-1"></i>' + esc(j.url) + '</a>'
                        : '<span style="font-size:12px;"><i class="la la-paste mr-1"></i>' + esc(j.url) + '</span>')
                        + ' <span class="label label-light-primary label-inline font-weight-bold" style="font-size:10px;">Text</span>'
                        + (j.title ? '<div class="text-muted" style="font-size:11px;">' + esc(j.title) + '</div>' : '')
                    : j.is_crawled
                    ? '<a href="' + esc(j.url) + '" target="_blank" rel="noopener" style="font-size:12px;">' + esc(j.url) + '</a>'
                        + ' <span class="label label-light-dark label-inline font-weight-bold" style="font-size:10px;">Crawled</span>'
                        + (j.title ? '<div class="text-muted" style="font-size:11px;">' + esc(j.title) + '</div>' : '')
                    : j.is_upload
                    ? '<span style="font-size:12px;"><i class="la la-file-alt mr-1"></i>' + esc(j.url) + '</span>'
                        + ' <span class="label label-light-info label-inline font-weight-bold" style="font-size:10px;">File</span>'
                        + (j.title ? '<div class="text-muted" style="font-size:11px;">' + esc(j.title) + '</div>' : '')
                    : '<a href="' + esc(j.url) + '" target="_blank" rel="noopener" style="font-size:12px;">' + esc(j.url) + '</a>' + kwChip + aiChip;
                // User-supplied competitor name (label only) shown above the source.
                var nameChip = j.name ? '<div class="mb-1"><span class="label label-light-success label-inline font-weight-bold" style="font-size:11px;"><i class="la la-building mr-1"></i>' + esc(j.name) + '</span></div>' : '';
                return '<tr>'
                    + '<td style="text-align:center; padding:12px 8px;">' + no + '</td>'
                    + '<td style="max-width:260px; word-break:break-all;">' + nameChip + source + '</td>'
                    + '<td style="text-align:center; font-size:12px;">' + products + '</td>'
                    + '<td style="text-align:center; font-size:12px;">' + cost + '</td>'
                    + '<td style="text-align:center;">' + jobStatusBadge(j) + '</td>'
                    + '<td style="text-align:center;">' + jobDatabaseStatus(j) + '</td>'
                    + '<td style="text-align:center; font-size:12px;">' + esc(j.ts) + '</td>'
                    + '<td style="text-align:center;">' + jobActionCell(j) + '</td>'
                    + '</tr>';
            }).join(''));
        }
        $('#total_cost').text(Number(res.cost_total || 0).toFixed(4));
        var info = 'Showing ' + paging.start.toLocaleString() + ' to ' + paging.end.toLocaleString()
            + ' of ' + paging.total.toLocaleString() + ' entries';
        if(paging.total !== paging.total_results) {
            info += ' (filtered from ' + paging.total_results.toLocaleString() + ')';
        }
        $('#jobs_page_info').text(info);
        var pager = [];
        var addPage = function(page, label, disabled, active, direction) {
            pager.push('<li class="page-item' + (direction ? ' ' + direction : '') + (disabled ? ' disabled' : '') + (active ? ' active' : '') + '">'
                + '<button type="button" class="page-link" data-jobs-page="' + page + '"'
                + (disabled || active ? ' disabled' : '') + (active ? ' aria-current="page"' : '')
                + (direction ? ' aria-label="' + (direction === 'previous' ? 'Previous page' : 'Next page') + '"' : '')
                + '>' + label + '</button></li>');
        };
        addPage(jobsPage - 1, '<i class="ki ki-arrow-back" aria-hidden="true"></i>', jobsPage <= 1, false, 'previous');
        var lastPage = 0;
        for(var p = 1; p <= paging.pages; p++) {
            if(p !== 1 && p !== paging.pages && Math.abs(p - jobsPage) > 2) continue;
            if(lastPage && p > lastPage + 1) {
                pager.push('<li class="page-item disabled"><span class="page-link">…</span></li>');
            }
            addPage(p, p, false, p === jobsPage);
            lastPage = p;
        }
        addPage(jobsPage + 1, '<i class="ki ki-arrow-next" aria-hidden="true"></i>', jobsPage >= paging.pages, false, 'next');
        $('#jobs_pagination').html(pager.join(''));

        var running = (res && res.running) ? 1 : 0;
        if(running) {
            if(!jobsTimer) jobsTimer = setInterval(function() {
                if(!jobsRequest) loadJobs();
            }, 3000);
        }
        else if(jobsTimer) { clearInterval(jobsTimer); jobsTimer = null; }
    }
    function loadJobs() {
        if(!$('#jobs_rows').length) return;
        var version = ++jobsRequestVersion;
        if(jobsRequest) jobsRequest.abort();
        $('#jobs_load_error').text('');
        jobsRequest = $.getJSON('<?php echo base_url('Competitor_Product/Jobs_List') ?>', {
            page: jobsPage, page_size: jobsPageSize, search: jobsSearch, status: jobsStatus, db_status: jobsDbStatus
        }).done(function(res) {
            if(version === jobsRequestVersion) renderJobs(res);
        }).fail(function(xhr, status) {
            if(status !== 'abort' && version === jobsRequestVersion) {
                $('#jobs_load_error').text('Could not load results. Please try again.');
            }
        }).always(function() {
            if(version === jobsRequestVersion) jobsRequest = null;
        });
    }
    $('#jobs_page_size').on('change', function() {
        jobsPageSize = Number($(this).val());
        jobsPage = 1;
        loadJobs();
    });
    $('#jobs_search').on('input', function() {
        jobsSearch = $.trim($(this).val());
        jobsPage = 1;
        updateJobFilterUrl();
        clearTimeout(jobsSearchTimer);
        ++jobsRequestVersion;
        if(jobsRequest) {
            jobsRequest.abort();
            jobsRequest = null;
        }
        jobsSearchTimer = setTimeout(loadJobs, 250);
    });
    $(document).on('click', '#jobs_pagination button[data-jobs-page]', function() {
        if(this.disabled) return;
        jobsPage = Number($(this).attr('data-jobs-page'));
        loadJobs();
    });
    function refreshJobFilters() {
        $('#jobs_status_filter').val(jobsStatus);
        $('#jobs_db_status_filter').val(jobsDbStatus);
    }
    function updateJobFilterCounts(selector, counts) {
        $(selector + ' option').each(function() {
            var count = counts[this.value || 'all'] || 0;
            $(this).text($(this).attr('data-label') + ' (' + Number(count).toLocaleString() + ')');
        });
    }
    function updateJobFilterUrl() {
        var url = new URL(window.location.href);
        if(jobsStatus) url.searchParams.set('status', jobsStatus); else url.searchParams.delete('status');
        if(jobsDbStatus) url.searchParams.set('db_status', jobsDbStatus); else url.searchParams.delete('db_status');
        if(jobsSearch) url.searchParams.set('search', jobsSearch); else url.searchParams.delete('search');
        window.history.replaceState(null, '', url.toString());
    }
    function applyJobFilters() {
        jobsStatus = $('#jobs_status_filter').val() || '';
        jobsDbStatus = $('#jobs_db_status_filter').val() || '';
        jobsSearch = $.trim($('#jobs_search').val());
        jobsPage = 1;
        clearTimeout(jobsSearchTimer);
        updateJobFilterUrl();
        loadJobs();
    }
    $('#jobs_filter_form').on('submit', function(event) {
        event.preventDefault();
        applyJobFilters();
    });
    $('#jobs_status_filter, #jobs_db_status_filter').on('change', applyJobFilters);
    $('#jobs_filter_reset').on('click', function() {
        jobsStatus = '';
        jobsDbStatus = '';
        jobsSearch = '';
        jobsPage = 1;
        $('#jobs_search').val('');
        clearTimeout(jobsSearchTimer);
        refreshJobFilters();
        updateJobFilterUrl();
        loadJobs();
    });
    $(document).ready(function() {
        var params = new URL(window.location.href).searchParams;
        jobsStatus = (params.get('status') || '').split(',')[0];
        jobsDbStatus = (params.get('db_status') || '').split(',')[0];
        jobsSearch = params.get('search') || '';
        $('#jobs_search').val(jobsSearch);
        refreshJobFilters();
        jobsStatus = $('#jobs_status_filter').val() || '';
        jobsDbStatus = $('#jobs_db_status_filter').val() || '';
        updateJobFilterUrl();
        loadJobs();
    });

    // Shared: POST a FormData to Analyze. All three inputs (URL crawl, pasted
    // text/links, uploaded PDF/image) run as fire-and-forget background jobs —
    // non-blocking; the row shows at the top of Analysis Results and polls itself.
    function runAnalyze(form, $btn, title) {
        var html = $btn.html();
        $btn.prop('disabled', true).html('<i class="la la-spinner la-spin"></i> Working…');
        var isBackground = form.has && (form.has('url') || form.has('paste') || form.has('file')) && $('#jobs_rows').length > 0;
        if(!isBackground) {
            Swal.fire({ background: 'url(' + CA_IMG + ')', title: title, allowOutsideClick: false,
                didOpen: function() { Swal.showLoading(); } });
        }
        $.ajax({
            url: '<?php echo base_url('Competitor_Product/Analyze') ?>',
            type: 'post', data: form, processData: false, contentType: false, dataType: 'json',
            success: function(res) {
                $btn.prop('disabled', false).html(html);
                if(res && res.job) {
                    // Background crawl / paste / upload analysis started — free the user immediately.
                    $('#competitor_url').val('');
                    $('#competitor_file').val('');
                    $('#competitor_file_label').text('Choose a PDF or image…');
                    $('#competitor_name').val('');
                    jobsPage = 1;
                    jobsSearch = '';
                    jobsStatus = '';
                    jobsDbStatus = '';
                    $('#jobs_search').val('');
                    clearTimeout(jobsSearchTimer);
                    refreshJobFilters();
                    updateJobFilterUrl();
                    Swal.fire({ toast: true, position: 'top-end', icon: 'success',
                        title: 'Started in the background',
                        text: 'It appears at the top of Analysis Results — you can keep working.',
                        showConfirmButton: false, timer: 4000, timerProgressBar: true });
                    loadJobs();
                    return;
                }
                Swal.close();
                if(res && res.success && res.id) {
                    window.location.href = '<?php echo base_url('Competitor_Product/View?id=') ?>' + res.id;
                } else {
                    Display_Message(CA_IMG, (res && res.message) ? res.message : 'Analysis Failed', null);
                }
            },
            error: function() {
                Swal.close();
                $btn.prop('disabled', false).html(html);
                Display_Message(CA_IMG, 'Analysis Failed. Please Try Again', null);
            }
        });
    }

    // Only the site's BASE URL is allowed (homepage). Strips tracking params and
    // rejects a deep path; returns the clean base URL or '' if invalid.
    function baseUrlOnly(raw) {
        var u;
        try { u = new URL(raw); } catch(e) { return ''; }
        if(!/^https?:$/i.test(u.protocol)) return '';
        var path = (u.pathname || '/').replace(/\/+$/, '');
        if(path !== '') return '';           // has a path → not a base URL
        return u.protocol + '//' + u.host + '/';
    }

    // One button for both: a chosen file wins, otherwise the pasted base URL.
    $('#analyze_btn').click(function() {
        var fileInput = $('#competitor_file')[0];
        var hasFile = fileInput && fileInput.files && fileInput.files.length > 0;
        var pasteVal = $.trim($('#competitor_paste').val());
        var form = new FormData(), title;
        // Optional competitor name — a label only, attached to whichever input runs.
        var compName = $.trim($('#competitor_name').val());
        if(compName !== '') { form.append('competitor_name', compName); }
        if(hasFile) {
            form.append('file', fileInput.files[0]);
            title = 'Reading file &amp; analysing with AI…';
        } else if(pasteVal !== '') {
            form.append('paste', pasteVal);
            title = 'Reading links &amp; analysing with AI…';
            $('#competitor_paste').val('');
        } else {
            var base = baseUrlOnly($.trim($('#competitor_url').val()));
            if(base === '') {
                Display_Message(CA_IMG, 'Enter the website’s base URL only, e.g. https://competitor.com', null);
                return;
            }
            $('#competitor_url').val(base);
            form.append('url', base);
            var kw = $.trim($('#competitor_keyword').val());
            if(kw !== '') { form.append('keyword', kw); }
            if($('#competitor_ai_crawl').is(':checked')) { form.append('ai_crawl', '1'); }
            title = kw !== '' ? ('Crawling for “' + kw + '”…') : 'Crawling the site…';
            // Reset the crawl inputs so the next crawl starts clean (the keyword +
            // AI crawl are remembered on the queued crawl row, not the form).
            $('#competitor_keyword').val('');
            $('#competitor_ai_crawl').prop('checked', false);
        }
        runAnalyze(form, $(this), title);
    });
</script>
