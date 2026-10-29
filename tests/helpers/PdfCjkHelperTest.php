<?php
/**
 * Run with: php tests/helpers/PdfCjkHelperTest.php
 *
 * Drives pdf_cjk_helper — the shared CJK-font support for DomPDF output that lets
 * typed Chinese render in the Costing Quotation / Booking Confirmation / Travel
 * Voucher PDFs (DomPDF 1.0.2 has no CJK glyphs in its default font and no per-glyph
 * fallback, so a CJK TrueType font must be registered as the document font).
 *
 * Covers the pure path/string logic only (does NOT construct a DomPDF instance):
 *  - pdf_cjk_font_path() resolves to an existing .ttf (the bundled font is committed).
 *  - pdf_cjk_font_family() prepends the "cjk" family when a font is available.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}
if (!defined('FCPATH')) {
    define('FCPATH', __DIR__ . '/../../');
}
if (!defined('APPPATH')) {
    define('APPPATH', __DIR__ . '/../../application/');
}

require_once __DIR__ . '/../../application/helpers/utils_helper.php';  // get_env()
require_once __DIR__ . '/../../application/helpers/pdf_cjk_helper.php';

$assertions = [];

// --- pdf_cjk_font_path() -------------------------------------------------------
$path = pdf_cjk_font_path();
$assertions['font path is non-empty']  = $path !== '';
$assertions['font path is a .ttf']     = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'ttf';
$assertions['font path is a real file'] = $path !== '' && is_file($path);

// The bundled fallback must exist regardless of any .env override.
$bundled = FCPATH . 'assets/fonts/pdf/DroidSansFallback.ttf';
$assertions['bundled DroidSansFallback.ttf exists'] = is_file($bundled);

// --- pdf_cjk_font_family() -----------------------------------------------------
// A font IS available (bundled), so the family list is prepended with "cjk".
$fam = pdf_cjk_font_family('"Segoe UI", Arial, sans-serif');
$assertions['font-family prepends cjk'] = $fam === 'cjk, "Segoe UI", Arial, sans-serif';

$famHelv = pdf_cjk_font_family('Helvetica');
$assertions['font-family prepends cjk (Helvetica base)'] = $famHelv === 'cjk, Helvetica';

// Base is preserved verbatim after the "cjk, " prefix.
$assertions['font-family keeps base intact'] = strpos($fam, '"Segoe UI", Arial, sans-serif') !== false;

// --- pdf_cjk_wrap_html() -------------------------------------------------------
// Wraps ONLY the Chinese runs so English keeps the document's original font.
$w = pdf_cjk_wrap_html('Hello 你好 World');
$assertions['wrap: chinese run wrapped in cjk span'] = $w === 'Hello <span style="font-family:cjk !important;">你好</span> World';
$assertions['wrap: english left untouched']          = strpos($w, 'Hello ') === 0 && substr($w, -6) === ' World';

// English-only input is returned verbatim (no spans, cheap no-op).
$assertions['wrap: english-only unchanged'] = pdf_cjk_wrap_html('Unit Price (RM)') === 'Unit Price (RM)';
$assertions['wrap: empty string unchanged'] = pdf_cjk_wrap_html('') === '';

// Tag/attribute contents are never rewritten — only text nodes.
$wTag = pdf_cjk_wrap_html('<td class="x">北京</td>');
$assertions['wrap: tag attrs untouched, inner wrapped'] =
    $wTag === '<td class="x"><span style="font-family:cjk !important;">北京</span></td>';

// <style> bodies are skipped (CSS must not get spans injected).
$wStyle = pdf_cjk_wrap_html('<style>body{font-family:Arial;}</style><p>上海</p>');
$assertions['wrap: style body skipped'] =
    strpos($wStyle, '<style>body{font-family:Arial;}</style>') === 0 &&
    strpos($wStyle, '<span style="font-family:cjk !important;">上海</span>') !== false;

// A CJK run already inside an inline-font span still gets the cjk span (inner wins).
$wInner = pdf_cjk_wrap_html('<span style="font-family:Arial">我们 ok</span>');
$assertions['wrap: nested chinese still wrapped'] =
    strpos($wInner, '<span style="font-family:cjk !important;">我们</span> ok') !== false;

// Bold Chinese: the run inside <strong> is wrapped (bold cjk is registered too).
$wBold = pdf_cjk_wrap_html('<strong>重要</strong>');
$assertions['wrap: bold chinese wrapped'] =
    $wBold === '<strong><span style="font-family:cjk !important;">重要</span></strong>';

$failed = 0;
foreach ($assertions as $label => $ok) {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

echo PHP_EOL . ($failed === 0 ? "All assertions passed.\n" : "$failed assertion(s) failed.\n");
exit($failed === 0 ? 0 : 1);
