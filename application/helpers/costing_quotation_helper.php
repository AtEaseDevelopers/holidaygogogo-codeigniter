<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Helpers for the Cost Template & Margin "Supplier Quotations" attachments —
 * the PDF/Word/Excel/image rate sheets a supplier sends us, kept against a
 * costing package for future reference. Pure functions (type whitelist, mime,
 * icon, supplier-matching key, grouping) are unit-tested by
 * tests/helpers/CostingQuotationFileHelperTest.php; the FCPATH-touching
 * dir/unlink helpers at the bottom mirror the supplier-invoice pattern.
 */

/**
 * Relative (FCPATH-based) directory where quotation attachments live. Files here
 * are never linked to directly — they are streamed only through the auth-gated
 * Costing::Quotation_File() endpoint, and a deny-all .htaccess (written by
 * costing_quotation_ensure_upload_dir()) blocks direct HTTP fetches. Trailing
 * slash included. These are commercial rate sheets, so they stay out of the
 * public asset tree.
 */
function costing_quotation_upload_reldir()
{
    return 'assets/upload/costing_quotation/';
}

/**
 * CodeIgniter Upload library max_size (KB) for a quotation attachment.
 */
function costing_quotation_max_size_kb()
{
    return 20480; // 20 MB
}

/**
 * CodeIgniter Upload library allowed_types — documents and images only,
 * deliberately excluding scripts/executables.
 */
function costing_quotation_allowed_types()
{
    return 'pdf|doc|docx|xls|xlsx|jpg|jpeg|png|webp|gif';
}

/**
 * Case-insensitive final-extension whitelist check. Tests only the LAST
 * extension, so double-extension tricks such as "rates.pdf.php" are rejected
 * (final ext "php" is not whitelisted). Empty / extension-less names fail.
 */
function costing_quotation_is_allowed_file($filename)
{
    $ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
    if ($ext === '') {
        return false;
    }
    return in_array($ext, explode('|', costing_quotation_allowed_types()), true);
}

/**
 * Map an attachment filename to a Content-Type for inline streaming. Falls back
 * to application/octet-stream for anything not in the whitelist.
 */
function costing_quotation_file_mime($filename)
{
    $map = array(
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    );
    $ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
    return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
}

/**
 * Line-Awesome icon class for a quotation filename, by extension family, so the
 * attachment list shows a recognisable PDF / Word / Excel / image glyph.
 */
function costing_quotation_file_icon($filename)
{
    $ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        return 'la la-file-pdf';
    }
    if (in_array($ext, array('doc', 'docx'), true)) {
        return 'la la-file-word';
    }
    if (in_array($ext, array('xls', 'xlsx'), true)) {
        return 'la la-file-excel';
    }
    if (in_array($ext, array('jpg', 'jpeg', 'png', 'webp', 'gif'), true)) {
        return 'la la-file-image';
    }
    return 'la la-file';
}

/**
 * Coarse file-type "kind" for a quotation filename — pdf / word / excel / image
 * / file — used to colour the type badge in the attachment list. Kept in sync
 * with costing_quotation_file_icon() (same extension families) so the server
 * render and the JS render pick the same badge.
 */
function costing_quotation_file_kind($filename)
{
    $ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        return 'pdf';
    }
    if (in_array($ext, array('doc', 'docx'), true)) {
        return 'word';
    }
    if (in_array($ext, array('xls', 'xlsx'), true)) {
        return 'excel';
    }
    if (in_array($ext, array('jpg', 'jpeg', 'png', 'webp', 'gif'), true)) {
        return 'image';
    }
    return 'file';
}

/**
 * Normalised key used to match a cost item's free-text supplier against an
 * uploaded quotation's supplier (trim + lowercase). Both sides run through this
 * so "Hotel ABC", "hotel abc" and " Hotel ABC " all match. Non-strings => ''.
 */
function costing_quotation_supplier_key($name)
{
    if (!is_string($name)) {
        return '';
    }
    return strtolower(trim($name));
}

/**
 * Group a flat list of quotation rows by their supplier key so a cost item can
 * look up the quotation(s) for its supplier in O(1). Untagged files (blank
 * supplier) collect under the '' key. Preserves input order within a group.
 */
function costing_quotation_group_by_supplier($files)
{
    if (!is_array($files)) {
        return array();
    }
    $out = array();
    foreach ($files as $file) {
        $supplier = '';
        if (is_array($file) && isset($file['supplier'])) {
            $supplier = $file['supplier'];
        } elseif (is_object($file) && isset($file->supplier)) {
            $supplier = $file->supplier;
        }
        $key = costing_quotation_supplier_key($supplier);
        if (!isset($out[$key])) {
            $out[$key] = array();
        }
        $out[$key][] = $file;
    }
    return $out;
}

/**
 * Absolute filesystem directory for quotation attachments.
 */
function costing_quotation_upload_dir()
{
    return FCPATH . costing_quotation_upload_reldir();
}

/**
 * Ensure the quotation upload directory exists and is shielded from direct web
 * access by a deny-all .htaccess (Apache 2.2 + 2.4). Self-healing: the guard is
 * (re)written whenever missing. Returns the directory path.
 */
function costing_quotation_ensure_upload_dir()
{
    $dir = costing_quotation_upload_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $htaccess = $dir . '.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents(
            $htaccess,
            "Order allow,deny\nDeny from all\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
        );
    }
    return $dir;
}

/**
 * Safely delete a quotation attachment given its stored relative (FCPATH-based)
 * path. A realpath containment check guarantees only files inside the quotation
 * upload directory are removed (a tampered "../../config/config.php" resolves
 * outside and is refused). Returns true only when a file was unlinked.
 */
function costing_quotation_delete_file($rel_path)
{
    $rel_path = trim((string) $rel_path);
    if ($rel_path === '') {
        return false;
    }
    $full = realpath(FCPATH . $rel_path);
    $base = realpath(costing_quotation_upload_dir());
    if ($full === false || $base === false) {
        return false;
    }
    $base_with_sep = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos($full, $base_with_sep) !== 0) {
        return false;
    }
    if (!is_file($full)) {
        return false;
    }
    return @unlink($full);
}
