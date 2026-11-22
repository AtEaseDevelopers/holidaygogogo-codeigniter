<!-- Shared by crawl list, timeline and product selection. -->
<div class="modal fade" id="crawl_export_modal" tabindex="-1" role="dialog" aria-labelledby="crawl_export_title" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="crawl_export_title">Generate crawl Excel</h5></div>
        <div class="modal-body">
            <p id="crawl_export_phase" role="status" aria-live="polite">Starting export…</p>
            <div class="progress" style="height:22px;"><div id="crawl_export_bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" aria-label="Current export stage" style="width:100%;"></div></div>
            <p id="crawl_export_count" class="text-muted mt-2 mb-0"></p>
            <p class="text-muted mt-3 mb-0" style="font-size:12px;">The file is removed from the server after sending. Files awaiting download expire after one hour. If a download is interrupted, generate the file again.</p>
        </div>
        <div class="modal-footer">
            <button type="button" id="crawl_export_cancel" class="btn btn-light-danger">Cancel generation</button>
            <a id="crawl_export_download" class="btn btn-success" style="display:none;" href="#">Download Excel</a>
            <button type="button" id="crawl_export_close" class="btn btn-secondary" data-dismiss="modal" style="display:none;">Close</button>
        </div>
    </div></div>
</div>
<script>
(function () {
    var BASE = <?php echo json_encode(base_url('Competitor_Product/')); ?>;
    var KEY = 'competitor-crawl-export-' + <?php echo json_encode((string) $this->session->admin_id); ?>;
    var id = null, timer = null, inFlight = false, sending = false, starting = false;
    function remember(value) { try { if (value) sessionStorage.setItem(KEY, value); else sessionStorage.removeItem(KEY); } catch (e) {} }
    function later() { clearTimeout(timer); timer = setTimeout(poll, 1500); }
    function error(message) {
        clearTimeout(timer);
        $('#crawl_export_phase').text(message);
        $('#crawl_export_bar').removeClass('progress-bar-animated');
        $('#crawl_export_cancel,#crawl_export_download').hide();
        $('#crawl_export_close').show();
        remember(null); id = null; sending = false;
    }
    function render(row) {
        $('#crawl_export_phase').text(row.phase);
        var known = row.percent !== null && typeof row.percent !== 'undefined';
        var percent = known ? Math.max(0, Math.min(100, Number(row.percent))) : 100;
        $('#crawl_export_bar').css('width', percent + '%').text(known ? percent + '%' : '')
            .toggleClass('progress-bar-animated', row.state === 'running' || row.state === 'queued' || row.state === 'cancelling');
        if (known) $('#crawl_export_bar').attr('aria-valuenow', percent);
        else $('#crawl_export_bar').removeAttr('aria-valuenow');
        $('#crawl_export_count').text(row.state === 'running' && row.total > 0 ? Number(row.done).toLocaleString() + ' / ' + Number(row.total).toLocaleString() + ' rows in this stage' : '');
        var terminal = ['cancelled', 'error', 'downloaded'].indexOf(row.state) !== -1;
        $('#crawl_export_cancel').toggle(!terminal && !sending && row.state !== 'downloading').prop('disabled', row.state === 'cancelling').text(row.state === 'ready' ? 'Delete file' : 'Cancel generation');
        $('#crawl_export_download').toggle(row.state === 'ready' && !sending).attr('href', row.download || '#');
        $('#crawl_export_close').toggle(terminal || row.state === 'ready');
        if (terminal) { remember(null); id = null; sending = false; clearTimeout(timer); }
        else later();
    }
    function poll() {
        if (!id || inFlight) { if (id) later(); return; }
        inFlight = true;
        $.getJSON(BASE + 'Crawl_Export_Status', {export: id}).done(render).fail(function (xhr) {
            if (xhr.status === 404) { error((xhr.responseJSON || {}).message || 'Export expired. Generate it again.'); }
            else { $('#crawl_export_phase').text('Connection interrupted. Retrying export status…'); later(); }
        }).always(function () { inFlight = false; });
    }
    window.CompetitorCrawlExport = {start: function (job, indices) {
        $('#crawl_export_modal').modal('show');
        if (id) { poll(); return; }
        if (starting) return;
        starting = true;
        sending = false;
        $('#crawl_export_phase').text('Starting export…');
        $('#crawl_export_count').text('');
        $('#crawl_export_bar').css('width', '100%').text('').addClass('progress-bar-animated');
        $('#crawl_export_download,#crawl_export_close').hide();
        $('#crawl_export_cancel').show().prop('disabled', true).text('Cancel generation');
        $.ajax({url: BASE + 'Start_Crawl_Export', type: 'POST', dataType: 'json', data: {job: job, indices: (indices || []).join(',')}})
            .done(function (res) {
                if (!res.success) { error(res.message || 'Could not start export.'); return; }
                id = res.export; remember(id); $('#crawl_export_cancel').prop('disabled', false); poll();
            }).fail(function (xhr) { error((xhr.responseJSON || {}).message || 'Could not start export. Please try again.'); })
            .always(function () { starting = false; });
    }};
    $(document).on('click', 'a[href*="Competitor_Product/Download_Crawl_Excel"]', function (e) {
        e.preventDefault();
        var parsed = new URL(this.href, window.location.href);
        window.CompetitorCrawlExport.start(parsed.searchParams.get('job'));
    });
    $('#crawl_export_cancel').on('click', function () {
        if (!id) return;
        $(this).prop('disabled', true);
        $.ajax({url: BASE + 'Cancel_Crawl_Export', type: 'POST', dataType: 'json', data: {export: id}})
            .done(render).fail(function () { $('#crawl_export_phase').text('Could not confirm cancellation. Retrying status…'); $('#crawl_export_cancel').prop('disabled', false); later(); });
    });
    $('#crawl_export_download').on('click', function (e) {
        e.preventDefault();
        if (sending || !id) return;
        sending = true;
        $('#crawl_export_download,#crawl_export_cancel,#crawl_export_close').hide();
        $('#crawl_export_phase').text('Sending Excel to your browser…');
        var frame = $('<iframe title="Excel download" style="display:none;"></iframe>').attr('src', this.href).appendTo('body');
        setTimeout(function () { frame.remove(); }, 3600000);
        later();
    });
    $(function () {
        try { id = sessionStorage.getItem(KEY); } catch (e) {}
        if (id) { $('#crawl_export_modal').modal('show'); poll(); }
    });
})();
</script>
