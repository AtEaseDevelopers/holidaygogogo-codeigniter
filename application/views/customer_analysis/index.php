<?php
/**
 * Customer Profile — per-customer page reached from the listing Action menu.
 * Shows the available chat sources (GHL + uploaded WhatsApp), an "AI Analysis"
 * button, the latest report expanded, and prior reports as a collapsed history.
 * Owner-only (the controller enforces level 10).
 *
 * Renders a customer character profile from customer_analysis_parse_ai_response():
 *   summary (characteristics / mood / communication style) · temperature (hot/cold).
 */
$total_msgs  = (int) $ghl_count + (int) $upload_count;
$can_analyse = $total_msgs > 0;
// Approach + Recommended Tours are shown only when the profile is opened from the
// Hot/Cold Customers page (which passes show_approach=1); the normal listing entry
// shows just the character profile.
$show_approach = ! empty($show_approach);

/** Render one saved analysis row (object from Customer_Analysis_Model). */
if ( ! function_exists('ca_render_analysis')) {
function ca_render_analysis($a, $expanded = true, $show_approach = false)
{
    $si = is_array($a->sales_intel) ? $a->sales_intel : array();
    $profile = isset($a->profile) && is_array($a->profile) ? $a->profile : array();
    $chip = function ($label, $items, $tone = 'primary') {
        if (empty($items)) { return ''; }
        $out = '<div class="mb-3"><div class="font-weight-bold text-dark-75 mb-2" style="font-size:12px;">' . htmlspecialchars($label) . '</div><div>';
        foreach ($items as $it) {
            $out .= '<span class="label label-light-' . $tone . ' label-inline font-weight-bold mr-2 mb-2" style="font-size:12px;">' . htmlspecialchars($it) . '</span>';
        }
        return $out . '</div></div>';
    };
    // Character-profile field labels (order = display order). Text fields render as
    // a two-column grid; list fields render as chips below.
    $ca_text_labels = array(
        'character'            => 'Character & Personality',
        'mood'                 => 'Mood',
        'behavior'             => 'Behaviour',
        'reply_pattern'        => 'Reply Pattern',
        'response_expectation' => 'Response Expectation',
        'language'             => 'Language',
        'journey'              => 'Journey',
        'family_needs'         => 'Family & Facility Needs',
        'source'               => 'Source',
    );
    ?>
    <div class="card card-custom mb-5">
        <div class="card-header py-3">
            <div class="card-title">
                <h3 class="card-label">
                    <?php echo $expanded ? 'Latest Analysis' : 'Analysis'; ?>
                    <?php $temp = strtolower(trim((string) $a->temperature));
                    if ($temp === 'hot') { ?>
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

                <?php
                // Structured character profile (details_json). Only show fields the AI filled.
                $ca_text_rows = array();
                foreach ($ca_text_labels as $key => $label) {
                    $val = isset($profile[$key]) ? trim((string) $profile[$key]) : '';
                    if ($val !== '') { $ca_text_rows[$label] = $val; }
                }
                if ( ! empty($ca_text_rows)) { ?>
                    <div class="separator separator-dashed my-4"></div>
                    <div class="row">
                        <?php foreach ($ca_text_rows as $label => $val) { ?>
                            <div class="col-md-6 mb-4">
                                <div class="text-muted font-weight-bolder text-uppercase mb-1" style="font-size:11px; letter-spacing:.5px;"><?php echo htmlspecialchars($label); ?></div>
                                <div class="text-dark-75" style="font-size:13px; line-height:1.6;"><?php echo nl2br(htmlspecialchars($val)); ?></div>
                            </div>
                        <?php } ?>
                    </div>
                <?php }

                $has_lists = ! empty($profile['preferences']) || ! empty($profile['expectations']) || ! empty($profile['complaints']);
                if ($has_lists) { ?>
                    <div class="separator separator-dashed my-4"></div>
                    <?php
                    echo $chip('Preferences', isset($profile['preferences']) ? $profile['preferences'] : array(), 'primary');
                    echo $chip('Expectations', isset($profile['expectations']) ? $profile['expectations'] : array(), 'info');
                    echo $chip('Complaints', isset($profile['complaints']) ? $profile['complaints'] : array(), 'danger');
                }

                $justification = isset($profile['justification']) ? trim((string) $profile['justification']) : '';
                if ($justification !== '') { ?>
                    <div class="separator separator-dashed my-4"></div>
                    <div class="text-muted" style="font-size:12px; line-height:1.6;">
                        <span class="font-weight-bolder"><i class="la la-quote-left mr-1"></i>Justification:</span>
                        <?php echo nl2br(htmlspecialchars($justification)); ?>
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

                <?php // Approach + Recommended Tours: Hot/Cold-entry only (sales follow-up flow).
                if ($show_approach) { ?>
                <?php
                $rec       = isset($a->recommendation) && is_array($a->recommendation) ? $a->recommendation : array();
                $rec_opts  = isset($rec['options']) && is_array($rec['options']) ? $rec['options'] : array();
                $approach  = isset($a->approach_suggestion) ? trim((string) $a->approach_suggestion) : '';
                $tc_notes  = isset($rec['tc_notes']) && is_array($rec['tc_notes']) ? $rec['tc_notes'] : array();
                if ( ! empty($rec_opts) || $approach !== '') { ?>
                    <div class="separator separator-dashed my-4"></div>
                    <div class="js-ca-approach">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <span class="font-weight-bolder text-dark"><i class="la la-comments-o mr-1 text-primary"></i>How to Approach This Customer</span>
                            <button type="button" class="btn btn-sm btn-light-primary font-weight-bold js-ca-copy-approach" data-toggle="tooltip" title="Copy the ready-to-send message">
                                <i class="la la-copy"></i> Copy message
                            </button>
                        </div>
                        <?php // Hidden plain-text copy of the message (excludes agent-only notes). ?>
                        <textarea class="js-ca-approach-text" readonly style="position:absolute; left:-9999px; top:0; opacity:0;"><?php echo htmlspecialchars($approach); ?></textarea>
                        <?php if ( ! empty($rec_opts)) {
                            if (trim((string) (isset($rec['intro']) ? $rec['intro'] : '')) !== '') { ?>
                                <div class="text-dark-75 mb-4" style="font-size:13px; line-height:1.7;"><?php echo nl2br(htmlspecialchars($rec['intro'])); ?></div>
                            <?php }
                            foreach ($rec_opts as $oi => $opt) {
                                $o_name  = trim((string) (isset($opt['name']) ? $opt['name'] : ''));
                                if ($o_name === '') { continue; }
                                $o_code  = trim((string) (isset($opt['tour_code']) ? $opt['tour_code'] : ''));
                                $o_price = (isset($opt['price_myr']) && $opt['price_myr'] !== null && $opt['price_myr'] !== '') ? (float) $opt['price_myr'] : null;
                                $o_emoji = trim((string) (isset($opt['feel_emoji']) ? $opt['feel_emoji'] : ''));
                                $o_feel  = trim((string) (isset($opt['overall_feel']) ? $opt['overall_feel'] : ''));
                                $o_dims  = isset($opt['dimensions']) && is_array($opt['dimensions']) ? $opt['dimensions'] : array();
                                ?>
                                <div class="mb-3 p-4 rounded" style="background-color:#F3F6F9;">
                                    <div class="d-flex align-items-center flex-wrap mb-3">
                                        <span class="font-weight-bolder text-dark mr-2" style="font-size:14px;"><?php echo ($o_emoji !== '' ? htmlspecialchars($o_emoji) . ' ' : '') . (int) ($oi + 1) . '. ' . htmlspecialchars($o_name); ?></span>
                                        <?php if ($o_code !== '') { ?>
                                            <span class="label label-light-primary label-inline font-weight-bold mr-2" style="font-size:11px;"><?php echo htmlspecialchars($o_code); ?></span>
                                        <?php } ?>
                                        <?php if ($o_price !== null && $o_price > 0) { ?>
                                            <span class="label label-light-success label-inline font-weight-bold" style="font-size:11px;">RM <?php echo number_format($o_price, 0); ?></span>
                                        <?php } ?>
                                    </div>
                                    <?php foreach ($o_dims as $dim) {
                                        $d_label = trim((string) (isset($dim['label']) ? $dim['label'] : ''));
                                        $d_emoji = trim((string) (isset($dim['emoji']) ? $dim['emoji'] : ''));
                                        $d_pts   = isset($dim['points']) && is_array($dim['points']) ? $dim['points'] : array();
                                        if ($d_label === '' && empty($d_pts)) { continue; } ?>
                                        <div class="mb-2">
                                            <?php if ($d_label !== '') { ?>
                                                <div class="font-weight-bold text-dark-75 mb-1" style="font-size:12px;"><?php echo ($d_emoji !== '' ? htmlspecialchars($d_emoji) . ' ' : '') . htmlspecialchars($d_label); ?></div>
                                            <?php } ?>
                                            <?php if ( ! empty($d_pts)) { ?>
                                                <ul class="text-dark-75 mb-0 pl-4" style="font-size:12px; line-height:1.7;">
                                                    <?php foreach ($d_pts as $p) { ?><li><?php echo htmlspecialchars((string) $p); ?></li><?php } ?>
                                                </ul>
                                            <?php } ?>
                                        </div>
                                    <?php } ?>
                                    <?php if ($o_feel !== '') { ?>
                                        <div class="text-primary font-weight-bold mt-2" style="font-size:12px; line-height:1.6;"><i class="la la-arrow-circle-right mr-1"></i><?php echo htmlspecialchars($o_feel); ?></div>
                                    <?php } ?>
                                </div>
                            <?php }

                            $dg = isset($rec['decision_guide']) && is_array($rec['decision_guide']) ? $rec['decision_guide'] : array();
                            if ( ! empty($dg)) { ?>
                                <div class="mb-3 p-4 rounded" style="background-color:#FFF8E1;">
                                    <div class="font-weight-bolder text-dark mb-2" style="font-size:12px;"><i class="la la-lightbulb-o mr-1 text-warning"></i>Which to pick</div>
                                    <?php foreach ($dg as $g) {
                                        $g_persona = trim((string) (isset($g['persona']) ? $g['persona'] : ''));
                                        $g_pick    = trim((string) (isset($g['pick']) ? $g['pick'] : ''));
                                        $g_emoji   = trim((string) (isset($g['emoji']) ? $g['emoji'] : ''));
                                        if ($g_persona === '' && $g_pick === '') { continue; } ?>
                                        <div class="text-dark-75 mb-1" style="font-size:12px; line-height:1.6;">
                                            <?php echo ($g_emoji !== '' ? htmlspecialchars($g_emoji) . ' ' : '') . htmlspecialchars($g_persona); ?>
                                            <?php if ($g_pick !== '') { ?><span class="text-muted">→</span> <span class="font-weight-bold text-dark"><?php echo htmlspecialchars($g_pick); ?></span><?php } ?>
                                        </div>
                                    <?php } ?>
                                </div>
                            <?php }

                            if (trim((string) (isset($rec['follow_up']) ? $rec['follow_up'] : '')) !== '') { ?>
                                <div class="text-dark-75 font-weight-bold" style="font-size:13px; line-height:1.7;"><i class="la la-question-circle mr-1 text-primary"></i><?php echo nl2br(htmlspecialchars($rec['follow_up'])); ?></div>
                            <?php }
                        } else { // legacy rows: no structured recommendation, show the stored message ?>
                            <div class="text-dark-75" style="font-size:13px; line-height:1.7; white-space:pre-wrap;"><?php echo htmlspecialchars($approach); ?></div>
                        <?php } ?>
                    </div>

                    <?php if ( ! empty($tc_notes)) { ?>
                        <div class="alert alert-custom alert-light-warning fade show mt-3 mb-0" role="alert">
                            <div class="alert-icon"><i class="la la-info-circle"></i></div>
                            <div class="alert-text" style="font-size:12px; line-height:1.6;">
                                <span class="font-weight-bolder text-dark">Notes for you (do not send to the customer):</span>
                                <ul class="mb-0 mt-1 pl-4">
                                    <?php foreach ($tc_notes as $note) { ?><li><?php echo htmlspecialchars((string) $note); ?></li><?php } ?>
                                </ul>
                            </div>
                        </div>
                    <?php } ?>
                <?php } ?>

                <?php
                // Recommended tours the AI matched from our own products, each justified.
                $rec_tours = isset($a->recommended_tours) && is_array($a->recommended_tours) ? $a->recommended_tours : array();
                if ( ! empty($rec_tours)) { ?>
                    <div class="separator separator-dashed my-4"></div>
                    <div class="font-weight-bolder text-dark mb-3"><i class="la la-map-marked-alt mr-1 text-primary"></i>Recommended Tours for This Customer</div>
                    <?php foreach ($rec_tours as $t) {
                        $t_name  = trim((string) (isset($t['name']) ? $t['name'] : ''));
                        if ($t_name === '') { continue; }
                        $t_code  = trim((string) (isset($t['tour_code']) ? $t['tour_code'] : ''));
                        $t_price = (isset($t['price_myr']) && $t['price_myr'] !== null && $t['price_myr'] !== '') ? (float) $t['price_myr'] : null;
                        $t_just  = trim((string) (isset($t['justification']) ? $t['justification'] : ''));
                        ?>
                        <div class="mb-3 p-4 rounded" style="background-color:#F3F6F9;">
                            <div class="d-flex align-items-center flex-wrap mb-1">
                                <span class="font-weight-bolder text-dark mr-2" style="font-size:13px;"><?php echo htmlspecialchars($t_name); ?></span>
                                <?php if ($t_code !== '') { ?>
                                    <span class="label label-light-primary label-inline font-weight-bold mr-2" style="font-size:11px;"><?php echo htmlspecialchars($t_code); ?></span>
                                <?php } ?>
                                <?php if ($t_price !== null && $t_price > 0) { ?>
                                    <span class="label label-light-success label-inline font-weight-bold" style="font-size:11px;">RM <?php echo number_format($t_price, 0); ?></span>
                                <?php } ?>
                            </div>
                            <?php if ($t_just !== '') { ?>
                                <div class="text-dark-75" style="font-size:12px; line-height:1.6;"><?php echo nl2br(htmlspecialchars($t_just)); ?></div>
                            <?php } ?>
                        </div>
                    <?php } ?>
                <?php } ?>
                <?php } // end show_approach ?>
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
            <?php ca_render_analysis($analyses[0], true, $show_approach); ?>

            <?php if (count($analyses) > 1) { ?>
                <div class="font-weight-bolder text-dark-50 mb-3 ml-1" style="font-size:12px; text-transform:uppercase;">History</div>
                <?php for ($i = 1; $i < count($analyses); $i++) { ca_render_analysis($analyses[$i], false, $show_approach); } ?>
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

    // Copy the ready-to-send approach message to the clipboard.
    $(document).on('click', '.js-ca-copy-approach', function () {
        var $btn = $(this);
        var $src = $btn.closest('.js-ca-approach').find('.js-ca-approach-text');
        var text = $src.is('textarea, input') ? $src.val() : $src.text();
        var done = function () {
            var $icon = $btn.find('i');
            var prev = $icon.attr('class');
            $icon.attr('class', 'la la-check');
            setTimeout(function () { $icon.attr('class', prev); }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, done);
        } else {
            var $t = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
            $t[0].select();
            try { document.execCommand('copy'); } catch (e) {}
            $t.remove();
            done();
        }
    });
})();
</script>
