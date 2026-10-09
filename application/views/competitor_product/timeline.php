<div class="d-flex flex-column-fluid">
    <div class="container-fluid">
        <div class="card card-custom mb-5">
            <div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
                <div class="card-title">
                    <h3 class="card-label" style="color:#6082B6;">
                        <strong>Crawl Timeline</strong>
                        <span class="text-muted font-weight-normal" style="font-size:13px;">&nbsp; <?php echo htmlspecialchars($host); ?></span>
                        <span class="text-muted font-weight-normal" style="font-size:12px;">&nbsp;·&nbsp; <span id="run_count">0</span> crawl(s)</span>
                    </h3>
                </div>
                <div class="card-toolbar">
                    <span class="label label-light-primary label-inline font-weight-bold mr-3" style="font-size:13px; padding:16px 14px;">
                        <i class="la la-dollar-sign mr-1"></i>Total AI Cost:&nbsp;
                        <strong>USD <span id="total_cost">0.0000</span></strong>
                    </span>
                    <a href="<?php echo base_url('Competitor_Product'); ?>" class="btn btn-secondary font-weight-bold"><i class="la la-arrow-left"></i> Back</a>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted" style="font-size:12px;">Every crawl run for this website, newest first. Pick a run and hit <strong>Review &amp; Select</strong> to choose which products to analyse.</p>
                <div style="overflow-x:auto;">
                    <table class="table table-bordered table-head-custom table-checkable">
                        <thead>
                            <tr>
                                <th style="text-align:center;">No.</th>
                                <th style="text-align:center;">Crawl</th>
                                <th style="text-align:center;">Products</th>
                                <th style="text-align:center;">AI Cost (USD)</th>
                                <th style="text-align:center;">Status</th>
                                <th style="text-align:center;">Date</th>
                                <th class="action" style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="runs_rows">
                            <tr id="no_runs"><td colspan="7" style="text-align:center; padding:12px;" class="text-muted">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    var CA_IMG = '<?php echo base_url('assets/image/sweetalert.jpg') ?>';
    var HOST         = '<?php echo htmlspecialchars($host, ENT_QUOTES); ?>';
    var REVIEW_URL   = '<?php echo base_url('Competitor_Product/Review') ?>?job=';
    var LIST_URL     = '<?php echo base_url('Competitor_Product/Timeline_List') ?>?host=';
    var TERMINATE_URL = '<?php echo base_url('Competitor_Product/Terminate_Job') ?>';

    var runsTimer = null;

    function fmtDur(sec) {
        sec = Math.max(0, Math.round(sec));
        if(sec < 60) return sec + 's';
        var m = Math.floor(sec / 60), s = sec % 60;
        if(m < 60) return m + 'm' + (s ? ' ' + s + 's' : '');
        var h = Math.floor(m / 60); m = m % 60;
        return h + 'h' + (m ? ' ' + m + 'm' : '');
    }
    function parseTs(t) { return t ? new Date(String(t).replace(' ', 'T')).getTime() : 0; }

    // Live ETA for a running crawl (same logic as the Analysis Results table).
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
        return '<span class="label label-light-info label-inline font-weight-bold">Crawled</span>';
    }
    function runActionCell(j) {
        var items = [];
        if(j.state !== 'done' && j.state !== 'error') {
            items.push('<a href="javascript:;" class="dropdown-item terminate-job" data-job="' + j.job + '" style="font-size:11px;"><i class="la la-times mr-2"></i>Terminate</a>');
        } else {
            if(j.reviewable) items.push('<a href="' + REVIEW_URL + encodeURIComponent(j.job) + '" class="dropdown-item" style="font-size:11px;"><i class="la la-list-alt mr-2"></i>Review &amp; Select</a>');
            items.push('<a href="javascript:;" class="dropdown-item delete-job" data-job="' + j.job + '" style="font-size:11px;"><i class="la la-trash mr-2"></i>Delete</a>');
        }
        return '<div class="btn-group">'
            + '<button type="button" data-toggle="dropdown" class="btn btn-light-primary btn-sm dropdown-toggle" style="padding-left:3px;"></button>'
            + '<div class="dropdown-menu dropdown-menu-right">' + items.join('') + '</div></div>';
    }

    // Terminate a running crawl run (kills the worker + drops the task).
    $(document).on('click', '.terminate-job', function() {
        var job = $(this).data('job');
        $(this).closest('tr').fadeOut(200);
        $.post(TERMINATE_URL, { job: job }, function() { loadRuns(); }, 'json').fail(function() { loadRuns(); });
    });

    // Delete a finished/errored crawl run (drops its files; saved analyses stay).
    $(document).on('click', '.delete-job', function() {
        var $b = $(this), job = $b.data('job'), $row = $b.closest('tr');
        Swal.mixin({ customClass: { confirmButton: 'btn btn-light-success m-2', cancelButton: 'btn btn-danger m-2' }, buttonsStyling: true })
            .fire({ width: 500, background: 'url(' + CA_IMG + ')', icon: 'warning',
                title: 'Delete this crawl run?', text: 'The saved analyses (if any) are kept.',
                confirmButtonText: 'Delete', cancelButtonText: 'Cancel', showCancelButton: true })
            .then(function(a) {
                if(a.isConfirmed) {
                    $row.fadeOut(200);
                    $.post(TERMINATE_URL, { job: job }, function() { loadRuns(); }, 'json');
                }
            });
    });

    function renderRuns(res) {
        var $rows = $('#runs_rows');
        if(!$rows.length) return;
        var jobs = (res && res.jobs) ? res.jobs : [];
        var esc = function(s){ return $('<div>').text(s == null ? '' : s).html(); };
        var total = 0, no = 0;
        $('#run_count').text(jobs.length);
        if(!jobs.length) {
            $rows.html('<tr id="no_runs"><td colspan="7" style="text-align:center; padding:12px;" class="text-muted">No crawl runs for this website.</td></tr>');
        } else {
            $rows.html(jobs.map(function(j) {
                no++;
                total += (j.cost_total || 0);
                var analysedChip = j.analysed ? ' <span class="label label-light-success label-inline" style="font-size:9px;">' + j.analysed + ' analysed</span>' : '';
                var products = (j.state === 'done') ? ((j.count > 0 ? j.count : '—') + analysedChip) : '—';
                var cost = (j.cost_total > 0) ? Number(j.cost_total).toFixed(4) : '—';
                var source = '<a href="' + esc(j.url) + '" target="_blank" rel="noopener" style="font-size:12px;">' + esc(j.url) + '</a>'
                    + (j.keyword ? '<br><span class="label label-light-primary label-inline font-weight-bold mt-1" style="font-size:11px;"><i class="la la-filter mr-1"></i>Keyword: ' + esc(j.keyword) + '</span>' : '')
                    + (j.ai_crawl ? ' <span class="label label-light-info label-inline font-weight-bold mt-1" style="font-size:11px;"><i class="la la-robot mr-1"></i>AI crawl</span>' : '');
                return '<tr>'
                    + '<td style="text-align:center; padding:12px 8px;">' + no + '</td>'
                    + '<td style="max-width:280px; word-break:break-all;">' + source + '</td>'
                    + '<td style="text-align:center; font-size:12px;">' + products + '</td>'
                    + '<td style="text-align:center; font-size:12px;">' + cost + '</td>'
                    + '<td style="text-align:center;">' + jobStatusBadge(j) + '</td>'
                    + '<td style="text-align:center; font-size:12px;">' + esc(j.ts) + '</td>'
                    + '<td style="text-align:center;">' + runActionCell(j) + '</td>'
                    + '</tr>';
            }).join(''));
        }
        $('#total_cost').text(total.toFixed(4));

        var running = (res && res.running) ? 1 : 0;
        if(running) { if(!runsTimer) runsTimer = setInterval(loadRuns, 3000); }
        else if(runsTimer) { clearInterval(runsTimer); runsTimer = null; }
    }
    function loadRuns() {
        $.getJSON(LIST_URL + encodeURIComponent(HOST)).done(renderRuns);
    }
    $(document).ready(loadRuns);
</script>
