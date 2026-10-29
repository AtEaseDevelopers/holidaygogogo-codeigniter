<?php
/**
 * Run with: php tests/helpers/VoucherContentSanitizeTest.php
 *
 * Drives sanitize_voucher_content_html() — the server-side guard that strips
 * absolute-positioning inline styles from stored voucher HTML before DomPDF
 * renders it. Without it, itineraries pasted from PDFs/Word overlap each other
 * in the generated PDF (their source markup carries position:absolute).
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

require_once __DIR__ . '/../../application/helpers/voucher_image_helper.php';

$assertions = [];

// Positioning declarations are removed, other styles survive.
$overlap = '<div style="position:absolute; top:10px; left:20px; color:red; font-size:12pt;">Day 3</div>';
$clean   = sanitize_voucher_content_html($overlap);
$assertions['position stripped']  = stripos($clean, 'position') === false;
$assertions['top stripped']       = stripos($clean, 'top:') === false;
$assertions['left stripped']      = stripos($clean, 'left:') === false;
$assertions['color kept']         = stripos($clean, 'color:red') !== false;
$assertions['font-size kept']     = stripos($clean, 'font-size:12pt') !== false;

// z-index / right / bottom also removed.
$z = '<span style="right:0; bottom:5px; z-index:99; margin-left:4px;">x</span>';
$zc = sanitize_voucher_content_html($z);
$assertions['right stripped']     = stripos($zc, 'right:') === false;
$assertions['bottom stripped']    = stripos($zc, 'bottom:') === false;
$assertions['z-index stripped']   = stripos($zc, 'z-index') === false;
$assertions['margin-left kept']   = stripos($zc, 'margin-left:4px') !== false;

// A px line-height (the real overlap cause from Elementor/Word paste) is removed,
// but a sane unitless / em line-height is preserved.
$lh = '<div style="line-height: 1.4px; color:green;">Day 3</div>';
$lhc = sanitize_voucher_content_html($lh);
$assertions['px line-height stripped']  = stripos($lhc, 'line-height') === false;
$assertions['px line-height keeps color'] = stripos($lhc, 'color:green') !== false;

$lh2 = '<div style="line-height: 1.4; color:green;">ok</div>';
$assertions['unitless line-height kept'] = stripos(sanitize_voucher_content_html($lh2), 'line-height: 1.4') !== false;

$lh3 = '<div style="line-height: 1.5em;">ok</div>';
$assertions['em line-height kept'] = stripos(sanitize_voucher_content_html($lh3), 'line-height: 1.5em') !== false;

// Real-world Elementor block from booking 9336 (fixed width + px line-height).
$elementor = '<div style="width: 778.117px; margin-block-end: 20px; line-height: 1.4px; background-color: #ffffff;">Overnight stay in Labuan Bajo</div>';
$ec = sanitize_voucher_content_html($elementor);
$assertions['elementor: line-height gone']   = stripos($ec, 'line-height') === false;
$assertions['elementor: bg-color kept']       = stripos($ec, 'background-color: #ffffff') !== false;
$assertions['elementor: margin-block-end kept'] = stripos($ec, 'margin-block-end: 20px') !== false;

// Property-name lookalikes must NOT be stripped.
$lookalike = '<p style="background-position:center; padding-left:8px; padding-top:3px;">y</p>';
$lc = sanitize_voucher_content_html($lookalike);
$assertions['background-position kept'] = stripos($lc, 'background-position:center') !== false;
$assertions['padding-left kept']        = stripos($lc, 'padding-left:8px') !== false;
$assertions['padding-top kept']         = stripos($lc, 'padding-top:3px') !== false;

// Single-quoted style attributes handled.
$single = "<div style='position:absolute;color:blue;'>z</div>";
$sc = sanitize_voucher_content_html($single);
$assertions['single-quote: position stripped'] = stripos($sc, 'position') === false;
$assertions['single-quote: color kept']        = stripos($sc, 'color:blue') !== false;

// Style attribute that becomes empty is left as an empty attribute (valid HTML).
$onlypos = '<div style="position:absolute; top:0;">w</div>';
$op = sanitize_voucher_content_html($onlypos);
$assertions['emptied style has no position'] = stripos($op, 'position') === false;
$assertions['emptied style has no top']      = stripos($op, 'top:') === false;

// --- $strip_indent flag (costing quotation itinerary) -------------------------
// Default keeps indent (booking-voucher contract, asserted above). With the flag
// on, TinyMCE's Indent props are dropped so itinerary days line up, while
// unrelated props survive and the default-indent lookalikes (padding-top /
// background-position) are untouched.
$indented = '<p style="padding-left: 40px; margin-left:20px; text-indent:30px; color:#333; padding-top:3px;">Breakfast</p>';
$stripped = sanitize_voucher_content_html($indented, true);
$assertions['strip_indent: padding-left gone'] = stripos($stripped, 'padding-left') === false;
$assertions['strip_indent: margin-left gone']  = stripos($stripped, 'margin-left') === false;
$assertions['strip_indent: text-indent gone']  = stripos($stripped, 'text-indent') === false;
$assertions['strip_indent: color kept']        = stripos($stripped, 'color:#333') !== false;
$assertions['strip_indent: padding-top kept']   = stripos($stripped, 'padding-top:3px') !== false;
// Default (flag off) still keeps them — unchanged contract for booking vouchers.
$kept = sanitize_voucher_content_html($indented);
$assertions['default keeps padding-left'] = stripos($kept, 'padding-left: 40px') !== false;
$assertions['default keeps margin-left']  = stripos($kept, 'margin-left:20px') !== false;

// --- $strip_font flag (costing quotation, Chinese support) --------------------
// dompdf 1.0.2 has no per-glyph fallback, so a pasted/TinyMCE inline font-family
// pins that run to a non-CJK font and any Chinese in it renders as empty boxes.
// With the flag on, inline font-family / font shorthand are dropped so the text
// inherits the CJK-capable body font; other props survive. Booking vouchers DON'T
// pass the flag, so their inline fonts are kept (unchanged contract).
$fonted = '<span style="font-family: Arial; color:#222; font-size:12pt;">你好 World</span>';
$fontstripped = sanitize_voucher_content_html($fonted, false, true);
$assertions['strip_font: font-family gone']     = stripos($fontstripped, 'font-family') === false;
$assertions['strip_font: color kept']           = stripos($fontstripped, 'color:#222') !== false;
$assertions['strip_font: font-size kept']        = stripos($fontstripped, 'font-size:12pt') !== false;
$font_shorthand = '<p style="font: italic 12px Arial; margin-top:2px;">x</p>';
$fsc = sanitize_voucher_content_html($font_shorthand, false, true);
$assertions['strip_font: font shorthand gone']  = stripos($fsc, 'font:') === false;
$assertions['strip_font: margin-top kept']       = stripos($fsc, 'margin-top:2px') !== false;
// Default (flag off) keeps font-family — unchanged contract for booking vouchers.
$keptfont = sanitize_voucher_content_html($fonted);
$assertions['default keeps font-family'] = stripos($keptfont, 'font-family: Arial') !== false;
// strip_indent and strip_font compose.
$both = '<p style="padding-left:40px; font-family:Arial; color:#111;">文字</p>';
$bothc = sanitize_voucher_content_html($both, true, true);
$assertions['strip both: padding-left gone'] = stripos($bothc, 'padding-left') === false;
$assertions['strip both: font-family gone']  = stripos($bothc, 'font-family') === false;
$assertions['strip both: color kept']        = stripos($bothc, 'color:#111') !== false;

// No style attribute -> returned unchanged.
$plain = '<p>Hello <b>world</b></p>';
$assertions['no-style unchanged'] = sanitize_voucher_content_html($plain) === $plain;

// Empty / null inputs are safe.
$assertions['empty string safe'] = sanitize_voucher_content_html('') === '';
$assertions['null safe']         = sanitize_voucher_content_html(null) === '';

$failed = 0;
foreach ($assertions as $label => $ok) {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

echo PHP_EOL . ($failed === 0 ? "All assertions passed.\n" : "$failed assertion(s) failed.\n");
exit($failed === 0 ? 0 : 1);
