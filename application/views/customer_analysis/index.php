<?php
/**
 * Customer Profile — per-customer page reached from the listing Action menu.
 * Shows the available chat sources (GHL + uploaded WhatsApp), an "AI Analysis"
 * button, the latest report expanded, and prior reports as a collapsed history.
 * Owner-only (the controller enforces level 10).
 *
 * Renders a combined report from customer_analysis_parse_ai_response():
 *   summary (profile) · sales_intel (stage/sentiment/language/budget/destinations/
 *   dates/objections) · next_actions · key_facts.
 */
$total_msgs  = (int) $ghl_count + (int) $upload_count;
$can_analyse = $total_msgs > 0;

/** Render one saved analysis row (object from Customer_Analysis_Model). */
if ( ! function_exists('ca_render_analysis')) {
function ca_render_analysis($a, $expanded = true)
{
    $si = is_array($a->sales_intel) ? $a->sales_intel : array();
    $chip = function ($label, $items) {
        if (empty($items)) { return ''; }
        $out = '<div class="mb-3"><div class="font-weight-bold text-dark-75 mb-2" style="font-size:12px;">' . htmlspecialchars($label) . '</div><div>';
        foreach ($items as $it) {
            $out .= '<span class="label label-light-primary label-inline font-weight-bold mr-2 mb-2" style="font-size:12px;">' . htmlspecialchars($it) . '</span>';
        }
        return $out . '</div></div>';
    };
    ?>
    <div class="card card-custom mb-5">
        <div class="card-header py-3">
            <div class="card-title">
                <h3 class="card-label">
                    <?php echo $expanded ? 'Latest Analysis' : 'Analysis'; ?>
                    <?php $temp = strtolower(trim((string) $a->temperature));
                    if (false && $temp === 'hot') { // hot/cold badge UI hidden for now; feature retained ?>
                        <span class="label label-danger label-inline font-weight-bolder ml-2" data-toggle="tooltip" title="<?php echo htmlspecialchars($a->temperature_reason ?: ''); ?>"><i class="la la-fire mr-1"></i>HOT</span>
                    <?php } elseif ($temp === 'cold') { ?>
                        <span class="label label-info label-inline font-weight-bolder ml-2" data-toggle="tooltip" title="<?php echo htmlspecialchars($a->temperature_reason ?: ''); ?>"><i class="la la-snowflake mr-1"></i>COLD</span>
                    <?php } ?>
                    <span class="text-muted font-weight-normal ml-2" style="font-size:12px;">
                        <?php echo date('d M Y, H:i', strtotime($a->created_at)); ?>
                    </span>
                </h3>
            </div>
            <div class="card-toolbar">
                <button type="button" class="btn btn-icon btn-light-danger btn-sm js-ca-delete" data-id="<?php echo (int) $a->id; ?>" data-toggle="tooltip" title="Delete this analysis">
                    <i class="la la-trash"></i>
                </button>
            </div>
        </div>
        <div class="card-body">
            <?php if ($a->status === 'error') { ?>
                <div class="alert alert-custom alert-light-danger fade show" role="alert">
                    <div class="alert-icon"><i class="la la-warning"></i></div>
                    <div class="alert-text"><strong>Analysis failed:</strong> <?php echo htmlspecialchars($a->error_message ?: 'Unknown error'); ?></div>
                </div>
            <?php } else { ?>
                <?php if (trim((string) $a->summary) !== '') { ?>
                    <div class="mb-5">
                        <div class="font-weight-bolder text-dark mb-2">Customer Profile</div>
                        <div class="text-dark-75" style="font-size:13px; line-height:1.7;"><?php echo nl2br(htmlspecialchars($a->summary)); ?></div>
                    </div>
                <?php } ?>

                <?php if ( ! empty($a->next_actions)) { ?>
                    <div class="separator separator-dashed my-4"></div>
                    <div class="font-weight-bolder text-dark mb-3"><i class="la la-bullseye text-primary mr-1"></i>Recommended Next Steps (Sales)</div>
                    <div class="mb-2">
                        <?php foreach ($a->next_actions as $i => $step) { ?>
                            <div class="d-flex align-items-start mb-3">
                                <span class="label label-primary label-inline font-weight-bolder mr-3 mt-1" style="min-width:22px;"><?php echo (int) $i + 1; ?></span>
                                <div class="text-dark-75" style="font-size:13px; line-height:1.7;"><?php echo nl2br(htmlspecialchars($step)); ?></div>
                            </div>
                        <?php } ?>
                    </div>
                <?php } ?>

                <?php // Sales Intelligence section disabled for now — flip to true to re-enable.
                $show_sales_intel = false;
                if ($show_sales_intel) { ?>
                <div class="separator separator-dashed my-4"></div>
                <div class="font-weight-bolder text-dark mb-3">Sales Intelligence</div>
                <div class="row mb-2">
                    <div class="col-md-4 mb-3">
                        <div class="text-muted font-weight-bold" style="font-size:11px;">Stage</div>
                        <div class="font-weight-bolder text-primary" style="font-size:14px;"><?php echo htmlspecialchars($si['stage'] !== '' ? $si['stage'] : '—'); ?></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="text-muted font-weight-bold" style="font-size:11px;">Sentiment</div>
                        <div class="font-weight-bolder" style="font-size:14px;"><?php echo htmlspecialchars($si['sentiment'] !== '' ? $si['sentiment'] : '—'); ?></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="text-muted font-weight-bold" style="font-size:11px;">Language</div>
                        <div class="font-weight-bolder" style="font-size:14px;"><?php echo htmlspecialchars($si['language'] !== '' ? $si['language'] : '—'); ?></div>
                    </div>
                </div>
                <?php
                echo $chip('Interested Destinations', $si['interested_destinations']);
                echo $chip('Travel Dates', $si['interested_dates']);
                echo $chip('Budget Signals', $si['budget_signals']);
                echo $chip('Objections / Concerns', $si['objections']);
                } ?>
            <?php } ?>
        </div>
    </div>
    <?php
}
}
?>

<div class="d-flex flex-column-fluid">
    <div class="container-fluid">

        <!-- Header / source summary -->
        <div class="card card-custom mb-5">
            <div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
                <div class="card-title">
                    <h3 class="card-label" style="color:#6082B6;">
                        <i class="la la-user-circle" style="color:#6082B6;"></i>
                        <strong>Customer Profile</strong>
                        <?php if (trim((string) $guest_name) !== '') { ?>
                            <span class="text-dark-50 font-weight-normal ml-2">&mdash; <?php echo htmlspecialchars($guest_name); ?></span>
                        <?php } ?>
                    </h3>
                </div>
                <div class="card-toolbar">
                    <button type="button" class="btn btn-light-primary font-weight-bold mr-3" onclick="history.back();">
                        <i class="la la-arrow-left"></i> Back
                    </button>
                    <button type="button" id="ca_run" class="btn btn-primary font-weight-bold" <?php echo $can_analyse ? '' : 'disabled'; ?>>
                        <i class="la la-robot"></i> AI Analysis
                    </button>
                </div>
            </div>
            <div class="card-body py-4">
                <span class="text-muted font-weight-bold mr-2">Chat sources for this customer:</span>
                <span class="label label-light-success label-inline font-weight-bold mr-2" style="font-size:12px;">GHL messages: <?php echo (int) $ghl_count; ?></span>
                <span class="label label-light-info label-inline font-weight-bold mr-2" style="font-size:12px;">Uploaded chat: <?php echo (int) $upload_count; ?></span>
                <?php if ( ! $can_analyse) { ?>
                    <div class="text-danger font-weight-bold mt-3" style="font-size:12px;">
                        <i class="la la-info-circle"></i> No chat messages found for this customer. Add a GHL conversation or upload a WhatsApp export (Action &raquo; Chat History) before analysing.
                    </div>
                <?php } else { ?>
                    <div class="text-muted mt-2" style="font-size:11px;">
                        <?php if (empty($has_prior)) { ?>
                            Not analysed yet — <strong>AI Analysis</strong> reads the full chat.
                        <?php } elseif ($run_mode === 'unchanged') { ?>
                            <i class="la la-check-circle text-success"></i> Up to date — last analysed <?php echo date('d M Y, H:i', strtotime($last_analysed_at)); ?>. No new messages since.
                        <?php } elseif ($run_mode === 'incremental') { ?>
                            <i class="la la-sync text-primary"></i> New messages since <?php echo date('d M Y, H:i', strtotime($last_analysed_at)); ?> — <strong>AI Analysis</strong> will update the saved profile (only the new messages are sent).
                        <?php } else { ?>
                            Chat changed since <?php echo date('d M Y, H:i', strtotime($last_analysed_at)); ?> — <strong>AI Analysis</strong> will do a full re-read.
                        <?php } ?>
                        <span class="ml-1">Full transcript: <?php echo number_format($transcript_chars); ?> characters.</span>
                        <?php if ($transcript_chars > $warn_chars) { ?>
                            <span class="text-danger font-weight-bold"><i class="la la-exclamation-triangle"></i> A full run may exceed the AI limit — you'll be warned before it runs.</span>
                        <?php } ?>
                    </div>
                <?php } ?>
                <div id="ca_status" class="mt-3" style="display:none;"></div>
            </div>
        </div>

        <?php if (empty($analyses)) { ?>
            <div class="card card-custom mb-5">
                <div class="card-body text-center text-muted py-10">
                    <i class="la la-comments" style="font-size:42px; opacity:.4;"></i>
                    <div class="mt-3 font-weight-bold">No analysis yet</div>
                    <div style="font-size:12px;">Click <strong>AI Analysis</strong> to generate the first report for this customer.</div>
                </div>
            </div>
        <?php } else { ?>
            <?php ca_render_analysis($analyses[0], true); ?>

            <?php if (count($analyses) > 1) { ?>
                <div class="font-weight-bolder text-dark-50 mb-3 ml-1" style="font-size:12px; text-transform:uppercase;">History</div>
                <?php for ($i = 1; $i < count($analyses); $i++) { ca_render_analysis($analyses[$i], false); } ?>
            <?php } ?>
        <?php } ?>

    </div>
</div>

<script>
(function () {
    var RUN_URL    = '<?php echo base_url('Customer_Analysis/Analyze'); ?>';
    var DELETE_URL = '<?php echo base_url('Customer_Analysis/Delete'); ?>';
    var PARAMS = {
        dedup_key:   <?php echo json_encode($dedup_key); ?>,
        phone:       <?php echo json_encode($phone); ?>,
        name:        <?php echo json_encode($guest_name); ?>,
        source_type: <?php echo json_encode(isset($source_type) ? $source_type : ''); ?>
    };
    var TRANSCRIPT_CHARS = <?php echo (int) $transcript_chars; ?>;
    var WARN_CHARS       = <?php echo (int) $warn_chars; ?>;
    var RUN_MODE         = <?php echo json_encode(isset($run_mode) ? $run_mode : 'full'); ?>;

    var $status = $('#ca_status');
    function setStatus(html, cls) {
        $status.attr('class', 'mt-3 alert alert-custom alert-light-' + (cls || 'primary') + ' fade show')
               .html('<div class="alert-text">' + html + '</div>').show();
    }

    // Warn only when a FULL run will actually happen (a full run sends the whole
    // transcript); an incremental update only sends the small delta.
    function confirmIfLong() {
        if (TRANSCRIPT_CHARS <= WARN_CHARS) { return true; }
        return window.confirm('This conversation is very long — about '
            + TRANSCRIPT_CHARS.toLocaleString() + ' characters.\n\n'
            + 'It may exceed the AI\'s limit and fail, or be slow and more costly.\n\n'
            + 'Proceed with the full analysis anyway?');
    }

    function run($btn) {
        // A full run happens on the first-ever run or when the chat changed in a
        // way that needs a full re-read; otherwise it's an incremental update.
        var isFull = RUN_MODE === 'full';
        if (isFull && !confirmIfLong()) { return; }

        var buttons = $('#ca_run');
        var orig = $btn.html();
        buttons.prop('disabled', true);
        $btn.html('<i class="la la-spinner la-spin"></i> Analysing…');
        setStatus(isFull
            ? 'Re-reading the full conversation and running AI analysis…'
            : 'Updating the profile from the latest messages…', 'primary');

        $.post(RUN_URL, PARAMS, function (res) {
            if (res && res.success && res.unchanged) {
                setStatus((res.message || 'Already up to date.'), 'success');
                buttons.prop('disabled', false);
                $btn.html(orig);
            } else if (res && res.success) {
                setStatus('Analysis ready. Reloading…', 'success');
                window.location.reload();
            } else {
                setStatus((res && res.message) ? res.message : 'Analysis failed. Please try again.', 'danger');
                buttons.prop('disabled', false);
                $btn.html(orig);
            }
        }, 'json').fail(function () {
            setStatus('The analysis request could not be completed. Please try again.', 'danger');
            buttons.prop('disabled', false);
            $btn.html(orig);
        });
    }

    $('#ca_run').on('click', function () { run($(this)); });

    $(document).on('click', '.js-ca-delete', function () {
        if (!confirm('Delete this analysis? This cannot be undone.')) { return; }
        var id = $(this).attr('data-id');
        $.post(DELETE_URL, { id: id }, function () { window.location.reload(); }, 'json');
    });
})();
</script>
