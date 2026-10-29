<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Shared CJK (Chinese) font support for DomPDF output.
 *
 * DomPDF 1.0.2's default font (Helvetica / DejaVu Sans) has no CJK glyphs, so any
 * Chinese typed into a quotation / booking confirmation / travel voucher renders
 * as empty boxes. DomPDF 1.0.2 also has NO per-glyph fallback across a font-family
 * list — it resolves a single font per text run — so the resolved document font
 * must itself cover CJK. We register a CJK TrueType font under the family name
 * "cjk" and prepend it to each view's base font-family; the CJK font covers Latin
 * too, so English is unaffected (only a very slight typeface change). Font
 * subsetting keeps English-only PDFs small.
 *
 * This is the one shared implementation of the pattern previously inlined in
 * Competitor_Product::Download_Pdf() and Our_Product.php.
 */

if ( ! function_exists('pdf_cjk_font_path')) {
    /**
     * Absolute path to a CJK TrueType font, or '' when none is available.
     * Prefers the configured COMPETITOR_PDF_CJK_FONT (shared font resource), else
     * the bundled DroidSansFallback.ttf (always committed). Must be a .ttf —
     * DomPDF's font lib mis-renders .ttc collections, so unsupported overrides
     * fall back to the bundled font.
     */
    function pdf_cjk_font_path()
    {
        $paths = array(
            trim((string) get_env('COMPETITOR_PDF_CJK_FONT')),
            FCPATH . 'assets/fonts/pdf/DroidSansFallback.ttf',
        );
        foreach ($paths as $path) {
            if ($path !== '' && is_file($path) && is_readable($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'ttf') {
                return $path;
            }
        }
        return '';
    }
}

if ( ! function_exists('pdf_cjk_font_family')) {
    /**
     * Prepend the registered "cjk" family to a base CSS font-family list when a
     * CJK font is available (so DomPDF resolves the CJK-capable font for the whole
     * document); return $base unchanged otherwise. Each view keeps its own base
     * (e.g. '"Segoe UI", Arial, sans-serif' or 'Helvetica').
     */
    function pdf_cjk_font_family($base)
    {
        return pdf_cjk_font_path() !== '' ? 'cjk, ' . $base : $base;
    }
}

if ( ! function_exists('pdf_cjk_make_dompdf')) {
    /**
     * Build a \Dompdf\Dompdf with remote + font subsetting enabled and, when a CJK
     * .ttf is available, the "cjk" family registered (normal + bold) with a
     * per-font writable cache dir and a symlink-safe chroot. Callers still set any
     * extra options (e.g. enable_html5_parser), setPaper(), loadHtml(..., 'UTF-8')
     * and render().
     */
    function pdf_cjk_make_dompdf()
    {
        require_once APPPATH . 'libraries/dompdf/autoload.inc.php';

        $cjk_font = pdf_cjk_font_path();
        $use_cjk  = ($cjk_font !== '');

        $options = new \Dompdf\Options();
        $options->setIsRemoteEnabled(true);
        $options->setIsFontSubsettingEnabled(true);
        if ($use_cjk) {
            // Namespace the (writable) font cache per font file so switching the
            // configured font never mixes stale glyph metrics from a previous one.
            $font_dir = APPPATH . 'cache/dompdf_fonts/' . md5($cjk_font) . '/';
            if ( ! is_dir($font_dir)) { @mkdir($font_dir, 0755, true); }
            $options->setFontDir($font_dir);
            $options->setFontCache($font_dir);
            // registerFont resolves symlinks (e.g. /Library/Fonts → /System/...), so
            // the REAL directory must be in the chroot or the font silently won't load.
            $real = realpath($cjk_font);
            $options->setChroot(array_values(array_filter(array(
                FCPATH, APPPATH, dirname($cjk_font), $real ? dirname($real) : null
            ))));
        }

        $dompdf = new \Dompdf\Dompdf($options);
        if ($use_cjk) {
            $fm = $dompdf->getFontMetrics();
            $fm->registerFont(array('family' => 'cjk', 'style' => 'normal', 'weight' => 'normal'), $cjk_font);
            $fm->registerFont(array('family' => 'cjk', 'style' => 'normal', 'weight' => 'bold'), $cjk_font);
        }
        return $dompdf;
    }
}
