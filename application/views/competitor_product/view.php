<?php
/**
 * Competitor Product detail. Two shapes, both rendered with the shared
 * _product.php partial:
 *   - Site crawl  ($a->products non-empty): a combined report — header with the
 *     base URL + product count + total cost, then an accordion, one product per
 *     panel (first open).
 *   - Single      (upload / single URL): one product profile built from the row.
 */
$is_crawl = ! empty($a->products) && is_array($a->products);
$title = $a->product_name ?: ($a->page_title ?: 'Competitor Product');
// Language state (set by the controller). $labels holds the current-language UI
// strings; content values on $a are already translated. Defaults keep the view
// usable if opened without them.
$lang   = isset($lang) ? $lang : 'en';
$labels = (isset($labels) && is_array($labels)) ? $labels
    : (function_exists('competitor_ui_labels') ? competitor_ui_labels($lang) : array());
$L      = function ($k, $fallback) use ($labels) { return isset($labels[$k]) ? $labels[$k] : $fallback; };
$has_cn = ! empty($has_cn);
$pdf_qs = 'id=' . (int) $a->id . ($lang !== 'en' ? '&lang=' . $lang : '');
?>
<div class="d-flex flex-column-fluid">
    <div class="container-fluid">
        <div class="card card-custom mb-5">
            <div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
                <div class="card-title">
                    <h3 class="card-label" style="color:#6082B6;">
                        <strong><?php echo htmlspecialchars($title); ?></strong>
                        <?php if($is_crawl) { ?>
                            <span class="label label-light-primary label-inline font-weight-bold ml-2" style="font-size:12px;"><?php echo (int) $a->product_count; ?> <?php echo htmlspecialchars($L('products', 'products')); ?></span>
                        <?php } elseif(!empty($a->tour_code)) { ?>
                            <span class="label label-light-primary label-inline font-weight-bold ml-2" style="font-size:12px;"><?php echo htmlspecialchars($a->tour_code); ?></span>
                        <?php } ?>
                        <?php if(!empty($a->competitor_name)) { ?>
                            <span class="label label-light-success label-inline font-weight-bold ml-2" style="font-size:12px;"><i class="la la-building mr-1"></i><?php echo htmlspecialchars($a->competitor_name); ?></span>
                        <?php } ?>
                    </h3>
                </div>
                <div class="card-toolbar">
                    <!-- Language toggle: EN is the stored original; 中文 is AI-translated
                         (generated once, then cached). The PDF follows the chosen language. -->
                    <div class="btn-group mr-3" role="group" data-toggle="tooltip" title="View language / 语言">
                        <a href="<?php echo base_url('Competitor_Product/View?id=' . (int) $a->id); ?>"
                           class="btn btn-sm font-weight-bold <?php echo $lang === 'en' ? 'btn-primary' : 'btn-light-primary'; ?>">EN</a>
                        <a href="javascript:;" id="ca_lang_cn"
                           class="btn btn-sm font-weight-bold <?php echo $lang === 'cn' ? 'btn-primary' : 'btn-light-primary'; ?>">中文</a>
                    </div>
                    <a href="<?php echo base_url('Competitor_Product/Download_Pdf?' . $pdf_qs); ?>" class="btn btn-light-danger font-weight-bold mr-2" data-toggle="tooltip" title="Download this analysis as a PDF">
                        <i class="la la-file-pdf"></i> <?php echo htmlspecialchars($L('download_pdf', 'Download PDF')); ?>
                    </a>
                    <a href="<?php echo base_url('Competitor_Product'); ?>" class="btn btn-light-primary font-weight-bold">
                        <i class="la la-arrow-left"></i> <?php echo htmlspecialchars($L('back', 'Back')); ?>
                    </a>
                </div>
            </div>
            <div class="card-body">

                <?php if($a->status === 'error') { ?>
                    <div class="alert alert-custom alert-light-danger fade show mb-5" role="alert">
                        <div class="alert-icon"><i class="la la-warning"></i></div>
                        <div class="alert-text"><strong><?php echo htmlspecialchars($L('analysis_failed', 'Analysis failed:')); ?></strong> <?php echo htmlspecialchars($a->error_message ?: 'Unknown error'); ?></div>
                    </div>
                <?php } ?>

                <div class="mb-5">
                    <span class="text-muted font-weight-bold mr-2"><?php echo htmlspecialchars($is_crawl ? $L('site', 'Site:') : $L('source', 'Source:')); ?></span>
                    <?php if(preg_match('#^https?://#i', (string) $a->url)) { ?>
                        <a href="<?php echo htmlspecialchars($a->url); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($a->url); ?></a>
                    <?php } else { ?>
                        <span><i class="la la-file"></i> <?php echo htmlspecialchars($a->url); ?> <span class="text-muted">(<?php echo htmlspecialchars($L('uploaded_file', 'uploaded file')); ?>)</span></span>
                    <?php } ?>
                </div>

                <div class="mb-5">
                    <span class="text-muted" style="font-size:12px;"><?php echo htmlspecialchars($L('analysed', 'Analysed')); ?> <?php echo date('d M Y H:i', strtotime($a->created_at)); ?><?php if((float) $a->cost_usd > 0) { echo ' · ' . htmlspecialchars($L('ai_cost', 'AI cost')) . ' USD ' . number_format((float) $a->cost_usd, 4); } ?></span>
                </div>

                <?php if($is_crawl) { ?>
                    <div class="accordion accordion-toggle-arrow" id="ca_products">
                        <?php foreach($a->products as $i => $p) {
                            $p = (array) $p;
                            $pid   = 'ca_p_' . $i;
                            $pname = trim((string) (isset($p['product_name']) ? $p['product_name'] : '')) ?: ($L('product', 'Product') . ' ' . ($i + 1));
                            $pprice = trim((string) (isset($p['price']) ? $p['price'] : ''));
                            $pdest  = trim((string) (isset($p['destination']) ? $p['destination'] : ''));
                            $open   = $i === 0;
                        ?>
                            <div class="card">
                                <div class="card-header" id="head_<?php echo $pid; ?>">
                                    <div class="card-title <?php echo $open ? '' : 'collapsed'; ?>" data-toggle="collapse" data-target="#body_<?php echo $pid; ?>" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>" style="cursor:pointer; font-size:15px;">
                                        <span class="font-weight-bolder text-dark"><?php echo ($i + 1) . '. ' . htmlspecialchars($pname); ?></span>
                                        <?php if($pdest !== '') { ?><span class="text-muted ml-2" style="font-size:13px;"><?php echo htmlspecialchars($pdest); ?></span><?php } ?>
                                        <?php if($pprice !== '') { ?><span class="label label-light-success label-inline font-weight-bold ml-2" style="font-size:12px;"><?php echo htmlspecialchars($pprice); ?></span><?php } ?>
                                    </div>
                                </div>
                                <div id="body_<?php echo $pid; ?>" class="collapse <?php echo $open ? 'show' : ''; ?>" data-parent="#ca_products">
                                    <div class="card-body">
                                        <?php $show_product_source = true; include __DIR__ . '/_product.php'; ?>
                                    </div>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                <?php } else {
                    // Single analysis: canonical $p from the row (Read_One already merged
                    // details_json onto $a) — shared mapping so it lives in one place.
                    $p = competitor_row_to_product($a);
                    $show_product_source = false;
                    include __DIR__ . '/_product.php';
                } ?>

            </div>
        </div>
    </div>
</div>

<script>
    // 中文 toggle. English is the stored original (instant). Chinese is AI-translated
    // in the BACKGROUND (a detached CLI worker) on first use, then cached server-side.
    // While it translates the button is disabled (a spinner) — you can only click it
    // to view the translated page once the poll reports the translation is ready. The
    // PDF reads the same cached overlay, so it follows the translation automatically.
    (function() {
        var CUR_LANG  = '<?php echo $lang; ?>';
        var HAS_CN    = <?php echo $has_cn ? 'true' : 'false'; ?>;
        var ID        = '<?php echo (int) $a->id; ?>';
        var CN_URL    = '<?php echo base_url('Competitor_Product/View?id=' . (int) $a->id . '&lang=cn'); ?>';
        var STATE_URL = '<?php echo base_url('Competitor_Product/Job_State?job='); ?>';
        var LS_KEY    = 'cpx_tr_' + ID + '_cn';   // remembers an in-flight job across a refresh
        var LABEL     = '中文';

        var $btn    = $('#ca_lang_cn');
        var polling = false;
        // Non-blocking toast (top-right, no backdrop) so the user can keep working — or
        // leave the page entirely — while the translation runs in the background.
        var Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false });

        function setTranslating() {
            polling = true;
            $btn.html('<i class="la la-spinner la-spin mr-1"></i>翻译中…').css('pointer-events', 'none').css('opacity', 0.65);
        }
        function setReady() {
            HAS_CN = true; polling = false;
            try { localStorage.removeItem(LS_KEY); } catch (e) {}
            $btn.html(LABEL).css('pointer-events', '').css('opacity', '')
                .attr('data-original-title', 'View in 中文 (translation ready)');
        }
        function setIdleError(msg) {
            polling = false;
            try { localStorage.removeItem(LS_KEY); } catch (e) {}
            $btn.html(LABEL).css('pointer-events', '').css('opacity', '');
            Toast.fire({ icon: 'error', title: msg || 'Translation failed', timer: 6000, timerProgressBar: true });
        }

        function poll(job) {
            var t = setInterval(function() {
                $.getJSON(STATE_URL + encodeURIComponent(job)).done(function(res) {
                    if (!res) { return; }
                    if (res.state === 'done') {
                        clearInterval(t); setReady();
                        Toast.fire({ icon: 'success', title: 'Translation ready — click 中文 to view', timer: 5000, timerProgressBar: true });
                    } else if (res.state === 'error' || res.state === 'unknown') {
                        clearInterval(t); setIdleError(res.message);
                    }
                });
            }, 2500);
        }

        $btn.on('click', function() {
            if (CUR_LANG === 'cn') { return; }                     // already showing Chinese
            if (polling) { return; }                               // translating → not clickable yet
            if (HAS_CN) { window.location.href = CN_URL; return; } // ready → view it

            setTranslating();
            $.ajax({
                url: '<?php echo base_url('Competitor_Product/Translate'); ?>',
                type: 'post', dataType: 'json', data: { id: ID, lang: 'cn' },
                success: function(res) {
                    if (res && res.success && res.done) { setReady(); window.location.href = CN_URL; return; }
                    if (res && res.success && res.job) {
                        try { localStorage.setItem(LS_KEY, res.job); } catch (e) {}
                        Toast.fire({ icon: 'info', title: 'Translating in the background…', timer: 4000, timerProgressBar: true });
                        poll(res.job);
                        return;
                    }
                    setIdleError(res && res.message ? res.message : 'Translation failed');
                },
                error: function() { setIdleError('Translation failed. Please try again.'); }
            });
        });

        // Resume a background translation left running before a refresh. The poll
        // self-heals: a job that already finished (or was deleted) returns done/unknown.
        if (CUR_LANG !== 'cn' && ! HAS_CN) {
            var job = '';
            try { job = localStorage.getItem(LS_KEY) || ''; } catch (e) {}
            if (job) {
                setTranslating();
                Toast.fire({ icon: 'info', title: 'Resuming background translation…', timer: 3000, timerProgressBar: true });
                poll(job);
            }
        }
    })();
</script>
