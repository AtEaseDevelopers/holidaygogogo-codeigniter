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

if ( ! function_exists('pdf_cjk_bold_font_path')) {
    /**
     * Absolute path to a *bold* CJK TrueType font, or '' when none is available.
     *
     * DroidSansFallback.ttf (the normal CJK face) ships in a single weight, so
     * registering it for the "cjk" bold slot made bold Chinese render at regular
     * weight — while the surrounding English bold used a real Helvetica-Bold, so
     * Chinese looked lighter than the English it sat beside. We bundle a genuine
     * bold CJK face (Noto Sans SC Bold, the modern successor to Droid Sans, subset
     * to DroidSansFallback's coverage) and register it for the bold weight so bold
     * Chinese matches bold English.
     *
     * Prefers the configured COMPETITOR_PDF_CJK_BOLD_FONT, else the bundled
     * NotoSansSC-Bold.ttf. Must be a .ttf (DomPDF mis-renders .ttc collections).
     */
    function pdf_cjk_bold_font_path()
    {
        $paths = array(
            trim((string) get_env('COMPETITOR_PDF_CJK_BOLD_FONT')),
            FCPATH . 'assets/fonts/pdf/NotoSansSC-Bold.ttf',
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

if ( ! function_exists('pdf_cjk_wrap_html')) {
    /**
     * Wrap every run of CJK (Chinese / Japanese / fullwidth) characters in the
     * already-rendered HTML in a <span> that uses the registered "cjk" font, so
     * ONLY Chinese switches font while the surrounding Latin text keeps the view's
     * original font (Arial / Segoe UI / Helvetica) and metrics.
     *
     * This is how we render Chinese without changing the English look or breaking
     * the pixel-tuned layouts: DomPDF 1.0.2 resolves a single font per text run and
     * has no per-glyph fallback, so prepending the CJK font to the whole document
     * would render ALL text (English too) in the CJK font and grow every line box.
     * Wrapping just the CJK runs localises the taller font to lines that actually
     * contain Chinese.
     *
     * Only text nodes are touched — tags, attributes and <style>/<script> bodies
     * are left untouched (CSS/markup never carry CJK we need to render anyway). No-op
     * when no CJK font is available, or when the HTML has no CJK characters.
     */
    function pdf_cjk_wrap_html($html, $family = 'cjk')
    {
        if ($html === '' || $html === null || pdf_cjk_font_path() === '') {
            return (string) $html;
        }

        // CJK symbols/punctuation, Hiragana, Katakana, CJK Ext-A, CJK Unified,
        // Compatibility Ideographs, and Fullwidth/Halfwidth forms.
        $cjk = '/[\x{3000}-\x{303F}\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{FF00}-\x{FFEF}]+/u';
        if ( ! preg_match($cjk, $html)) {
            return (string) $html; // English-only — nothing to wrap.
        }

        // Split into tags (captured) and the text between them so we only ever
        // rewrite text nodes, never tag/attribute contents.
        $parts   = preg_split('/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $in_skip = false;
        foreach ($parts as &$part) {
            if ($part === '') {
                continue;
            }
            if ($part[0] === '<') {
                if (preg_match('/^<\s*(style|script)\b/i', $part)) {
                    $in_skip = true;
                } elseif (preg_match('/^<\s*\/\s*(style|script)\s*>/i', $part)) {
                    $in_skip = false;
                }
                continue; // never modify a tag
            }
            if ($in_skip) {
                continue; // inside <style>/<script>
            }
            $part = preg_replace_callback($cjk, function ($m) use ($family) {
                // !important is REQUIRED: the PDF views set `font-family: ... !important`
                // on <body>, and DomPDF lets that author-important rule override a
                // normal inline style — so a plain inline font-family on this span
                // loses and the Chinese falls back to the (glyph-less) base font,
                // rendering as empty boxes. An inline !important wins the cascade.
                return '<span style="font-family:' . $family . ' !important;">' . $m[0] . '</span>';
            }, $part);
        }
        unset($part);

        return implode('', $parts);
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

        $cjk_font  = pdf_cjk_font_path();
        $use_cjk   = ($cjk_font !== '');
        // Genuine bold face for the "cjk" bold slot; fall back to the normal face
        // (legacy behaviour — bold renders at regular weight) when unavailable.
        $bold_font = $use_cjk ? pdf_cjk_bold_font_path() : '';
        if ($bold_font === '') { $bold_font = $cjk_font; }

        $options = new \Dompdf\Options();
        $options->setIsRemoteEnabled(true);
        $options->setIsFontSubsettingEnabled(true);
        if ($use_cjk) {
            // Namespace the (writable) font cache per font file so switching the
            // configured font never mixes stale glyph metrics from a previous one.
            // Keyed by both faces so adding/swapping the bold font busts the cache.
            $font_dir = APPPATH . 'cache/dompdf_fonts/' . md5($cjk_font . '|' . $bold_font) . '/';
            if ( ! is_dir($font_dir)) { @mkdir($font_dir, 0755, true); }
            $options->setFontDir($font_dir);
            $options->setFontCache($font_dir);
            // registerFont resolves symlinks (e.g. /Library/Fonts → /System/...), so
            // the REAL directory must be in the chroot or the font silently won't load.
            $real      = realpath($cjk_font);
            $real_bold = realpath($bold_font);
            $options->setChroot(array_values(array_filter(array(
                FCPATH, APPPATH, dirname($cjk_font), dirname($bold_font),
                $real ? dirname($real) : null, $real_bold ? dirname($real_bold) : null
            ))));
        }

        $dompdf = new \Dompdf\Dompdf($options);
        if ($use_cjk) {
            $fm = $dompdf->getFontMetrics();
            $fm->registerFont(array('family' => 'cjk', 'style' => 'normal', 'weight' => 'normal'), $cjk_font);
            $fm->registerFont(array('family' => 'cjk', 'style' => 'normal', 'weight' => 'bold'), $bold_font);
        }
        return $dompdf;
    }
}
