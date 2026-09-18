<?php
/**
 * Our Product detail. Two shapes, both rendered with the shared
 * _product.php partial:
 *   - Site crawl  ($a->products non-empty): a combined report — header with the
 *     base URL + product count + total cost, then an accordion, one product per
 *     panel (first open).
 *   - Single      (upload / single URL): one product profile built from the row.
 */
$is_crawl = ! empty($a->products) && is_array($a->products);
$title = $a->product_name ?: ($a->page_title ?: 'Our Product');
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
                    </h3>
                </div>
                <div class="card-toolbar">
                    <!-- Language toggle: EN is the stored original; 中文 is AI-translated
                         (generated once, then cached). The PDF follows the chosen language. -->
                    <div class="btn-group mr-3" role="group" data-toggle="tooltip" title="View language / 语言">
                        <a href="<?php echo base_url('Our_Product/View?id=' . (int) $a->id); ?>"
                           class="btn btn-sm font-weight-bold <?php echo $lang === 'en' ? 'btn-primary' : 'btn-light-primary'; ?>">EN</a>
                        <a href="javascript:;" id="ca_lang_cn"
                           class="btn btn-sm font-weight-bold <?php echo $lang === 'cn' ? 'btn-primary' : 'btn-light-primary'; ?>">中文</a>
                    </div>
                    <a href="<?php echo base_url('Our_Product/Download_Pdf?' . $pdf_qs); ?>" class="btn btn-light-danger font-weight-bold mr-2" data-toggle="tooltip" title="Download this analysis as a PDF">
                        <i class="la la-file-pdf"></i> <?php echo htmlspecialchars($L('download_pdf', 'Download PDF')); ?>
                    </a>
                    <a href="<?php echo base_url('Our_Product'); ?>" class="btn btn-light-primary font-weight-bold">
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
    // on first use, cached server-side, then the page reloads to ?lang=cn so both the
    // view and the PDF (which reads the same cached overlay) follow the translation.
    (function() {
        var CUR_LANG = '<?php echo $lang; ?>';
        var HAS_CN   = <?php echo $has_cn ? 'true' : 'false'; ?>;
        var ID       = '<?php echo (int) $a->id; ?>';
        var CN_URL   = '<?php echo base_url('Our_Product/View?id=' . (int) $a->id . '&lang=cn'); ?>';
        var IMG      = '<?php echo base_url('assets/image/sweetalert.jpg'); ?>';

        $('#ca_lang_cn').on('click', function() {
            if (CUR_LANG === 'cn') { return; }                 // already showing Chinese
            if (HAS_CN) { window.location.href = CN_URL; return; }  // cached → just switch

            Swal.fire({ background: 'url(' + IMG + ')', title: '翻译中… / Translating…',
                allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });
            $.ajax({
                url: '<?php echo base_url('Our_Product/Translate'); ?>',
                type: 'post', dataType: 'json', data: { id: ID, lang: 'cn' },
                success: function(res) {
                    Swal.close();
                    if (res && res.success) { window.location.href = CN_URL; }
                    else { Display_Message(IMG, (res && res.message) ? res.message : 'Translation failed', null); }
                },
                error: function() {
                    Swal.close();
                    Display_Message(IMG, 'Translation failed. Please try again.', null);
                }
            });
        });
    })();
</script>
