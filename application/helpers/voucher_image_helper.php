<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('validate_voucher_image_upload')) {
    /**
     * Validate a file uploaded via TinyMCE's images_upload_url for the booking
     * voucher editors. Pure (no CI super-object), so the controller can call
     * it before invoking the upload library and tests can drive it with
     * fixture stubs.
     *
     * @param array $file_meta Shape of $_FILES['file']: name, type, size, tmp_name, error.
     * @param int   $max_kb    Max size in kilobytes (matches CI upload library convention).
     * @return array ['ok' => bool, 'error' => string|null]
     */
    function validate_voucher_image_upload(array $file_meta, $max_kb = 5120)
    {
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (empty($file_meta) || empty($file_meta['name'])) {
            return ['ok' => false, 'error' => 'No file uploaded'];
        }

        if (isset($file_meta['error']) && $file_meta['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload error code ' . (int) $file_meta['error']];
        }

        $ext = strtolower(pathinfo($file_meta['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext, true)) {
            return ['ok' => false, 'error' => 'Unsupported file type'];
        }

        $size = isset($file_meta['size']) ? (int) $file_meta['size'] : 0;
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'Empty file'];
        }
        if ($size > $max_kb * 1024) {
            return ['ok' => false, 'error' => 'File exceeds ' . $max_kb . ' KB limit'];
        }

        if (!empty($file_meta['tmp_name']) && is_readable($file_meta['tmp_name'])) {
            $info = @getimagesize($file_meta['tmp_name']);
            if ($info === false || empty($info[0]) || empty($info[1])) {
                return ['ok' => false, 'error' => 'File is not a valid image'];
            }
        }

        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('inline_voucher_images_html')) {
    /**
     * Replace <img src> values that point at this app's voucher uploads with
     * base64 data URIs containing GD-flattened JPEG bytes. DomPDF 1.0.x has
     * a known rendering bug where palette PNGs with a tRNS chunk and PNGs
     * with an alpha channel embed in the PDF stream but draw as fully
     * transparent — flattening onto white via GD and re-encoding as JPEG
     * sidesteps the issue and also avoids server-side HTTP loopback.
     *
     * Only rewrites images that resolve to a local file under FCPATH;
     * external URLs and data URIs pass through untouched.
     *
     * @param string|null $html
     * @return string
     */
    function inline_voucher_images_html($html)
    {
        if (empty($html) || stripos($html, '<img') === false) {
            return (string) $html;
        }

        return preg_replace_callback(
            '/(<img\b[^>]*\bsrc=)(["\'])(.*?)\2/i',
            function ($m) {
                $prefix = $m[1];
                $quote  = $m[2];
                $src    = $m[3];

                if ($src === '' || stripos($src, 'data:') === 0) {
                    return $m[0];
                }

                $path = _voucher_image_local_path($src);
                if ($path === null || !is_file($path) || !is_readable($path)) {
                    return $m[0];
                }

                $bytes = _voucher_image_flatten_to_jpeg($path);
                if ($bytes === null) {
                    return $m[0];
                }

                $data_uri = 'data:image/jpeg;base64,' . base64_encode($bytes);
                return $prefix . $quote . $data_uri . $quote;
            },
            $html
        );
    }
}

if (!function_exists('sanitize_voucher_content_html')) {
    /**
     * Strip layout-breaking inline styles from stored voucher HTML before it is
     * handed to DomPDF (or shown anywhere the raw content renders).
     *
     * Itineraries are frequently pasted from web page builders (Elementor),
     * PDFs, Word or Google Docs. That markup carries two layout-breaking things:
     *   1. a pixel `line-height` (e.g. `line-height: 1.4px`) — on 10pt text this
     *      collapses every line onto the next, so lines overlap and become
     *      unreadable in the generated PDF. This is the common real cause.
     *   2. absolute positioning (position/top/left/right/bottom/z-index) — DomPDF
     *      honours it and stacks blocks on top of each other.
     * Both are stripped. A `px` line-height is removed (falls back to normal
     * spacing) while a sane unitless/em line-height is kept. Fonts, colours,
     * margins and image sizing are left intact.
     *
     * @param string|null $html
     * @return string
     */
    function sanitize_voucher_content_html($html, $strip_indent = false, $strip_font = false)
    {
        if (empty($html) || stripos($html, 'style') === false) {
            return (string) $html;
        }

        $blocked = ['position', 'top', 'left', 'right', 'bottom', 'z-index'];

        // Opt-in (costing quotation itinerary): also drop the horizontal-indent
        // props TinyMCE's "Indent" button writes onto <p>/<ul> (padding-left:40px,
        // margin-left, text-indent). Left in, they shift only the days that were
        // indented in the editor, so the itinerary's Day/Itinerary/Details columns
        // stop lining up (the day 7 / day 14 drift). Normal bullet indentation
        // comes from the default <ul> style, not these, so lists are unaffected.
        // Booking vouchers DON'T pass this flag, so their stored indents are kept
        // (see VoucherContentSanitizeTest "padding-left kept").
        if ($strip_indent) {
            $blocked = array_merge($blocked, ['margin-left', 'padding-left', 'text-indent']);
        }

        // Opt-in (costing quotation): drop inline font-family. dompdf 1.0.2 has no
        // per-glyph fallback, so a pasted/TinyMCE `font-family: Arial` pins that run
        // to a non-CJK font and any Chinese in it renders as empty boxes even when
        // the body font is CJK-capable. Stripping it lets the text inherit the
        // CJK-capable body font. Booking vouchers DON'T pass this flag.
        if ($strip_font) {
            $blocked = array_merge($blocked, ['font-family', 'font']);
        }

        return preg_replace_callback(
            '/\bstyle\s*=\s*(["\'])(.*?)\1/is',
            function ($m) use ($blocked) {
                $quote = $m[1];
                $decls = explode(';', $m[2]);
                $kept  = [];

                foreach ($decls as $decl) {
                    if (trim($decl) === '') {
                        continue;
                    }
                    $prop  = strtolower(trim(strtok($decl, ':')));
                    $value = trim(substr($decl, strlen($prop) + 1));

                    if (in_array($prop, $blocked, true)) {
                        continue; // positioning -> always drop
                    }
                    // A px line-height is what makes pasted lines overlap; drop
                    // it so text falls back to normal spacing. Keep unitless/em/%.
                    if ($prop === 'line-height' && stripos($value, 'px') !== false) {
                        continue;
                    }
                    $kept[] = trim($decl);
                }

                if (empty($kept)) {
                    return 'style=' . $quote . $quote;
                }
                return 'style=' . $quote . implode('; ', $kept) . $quote;
            },
            $html
        );
    }
}

if (!function_exists('_voucher_image_flatten_to_jpeg')) {
    /**
     * Read an image from disk, composite onto a white background, return
     * JPEG bytes. Returns null if the file isn't decodable by GD.
     */
    function _voucher_image_flatten_to_jpeg($path)
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return null;
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $flat = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($flat, 255, 255, 255);
        imagefilledrectangle($flat, 0, 0, $w, $h, $white);
        imagecopy($flat, $src, 0, 0, 0, 0, $w, $h);

        ob_start();
        $ok = @imagejpeg($flat, null, 90);
        $jpeg = ob_get_clean();

        imagedestroy($src);
        imagedestroy($flat);

        if (!$ok || $jpeg === false || $jpeg === '') {
            return null;
        }
        return $jpeg;
    }
}

if (!function_exists('_voucher_image_local_path')) {
    /**
     * Resolve an <img src> to a local filesystem path under FCPATH if it
     * points at this app, else return null. Exposed for tests.
     */
    function _voucher_image_local_path($src)
    {
        if (!defined('FCPATH')) {
            return null;
        }

        $path = $src;

        if (preg_match('#^https?://[^/]+(/.*)$#i', $src, $m)) {
            $path = $m[1];
        }

        $path = ltrim($path, '/');
        $path = strtok($path, '?#'); // strip query/fragment

        if ($path === false || $path === '') {
            return null;
        }

        $candidate = realpath(FCPATH . $path);
        if ($candidate === false) {
            return null;
        }

        $root = realpath(FCPATH);
        if ($root === false || strpos($candidate, $root) !== 0) {
            return null; // path traversal guard
        }

        return $candidate;
    }
}
