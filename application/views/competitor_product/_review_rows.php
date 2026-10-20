<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * One page of Review product rows, rendered server-side for Competitor_Product::Review_List.
 * $products  lightweight rows (competitor_review_light_row) for this page only
 * $start_no   the "No." of the first row on this page (numbering continues across pages)
 */
$n = isset($start_no) ? (int) $start_no : 1;
foreach ($products as $p) {
    $analysed = ((int) $p['analysis_id'] > 0);
    $kind     = (isset($p['kind']) && $p['kind'] === 'package') ? 'package' : 'itinerary';
?>
    <tr class="product-row"<?php echo $analysed ? ' style="background:#f6fbf7;"' : ''; ?>>
        <td style="text-align:center;"><input type="checkbox" class="sel-item" value="<?php echo (int) $p['i']; ?>" <?php echo $p['chars'] > 0 ? '' : 'disabled'; ?>></td>
        <td class="row-no" style="text-align:center; padding:10px 8px;"><?php echo $n; ?></td>
        <td class="cell-code" style="text-align:center; font-size:12px; white-space:nowrap;">
            <?php $code = competitor_product_code((int) $p['analysis_id'], 'competitor', 1, 1);
                  echo $code !== '' ? htmlspecialchars($code) : '<span class="text-muted">—</span>'; ?>
        </td>
        <td>
            <strong style="font-size:13px;"><?php echo htmlspecialchars($p['title']); ?></strong>
            <?php if (!empty($p['quality_flags'])) { ?>
                <span class="label label-light-warning label-inline ml-1" style="font-size:10px;">Needs review</span>
                <div class="text-warning" style="font-size:11px; margin-top:2px;"><?php echo htmlspecialchars(implode('; ', $p['quality_flags'])); ?></div>
            <?php } ?>
            <?php if (isset($p['source_records']) && count($p['source_records']) > 1) { ?>
                <details class="text-muted" style="font-size:11px; margin-top:2px;">
                    <summary><?php echo count($p['source_records']); ?> source records for this product</summary>
                    <?php foreach ($p['source_records'] as $record) { ?>
                        <div>Original: <a href="<?php echo htmlspecialchars($record['original_url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($record['original_url']); ?></a></div>
                        <div>Source: <?php echo htmlspecialchars($record['source_url']); ?></div>
                    <?php } ?>
                </details>
            <?php } ?>
            <?php if ( ! empty($p['duration'])) { ?><span class="label label-light-info label-inline ml-1" style="font-size:10px;"><?php echo htmlspecialchars($p['duration']); ?></span><?php } ?>
            <?php echo $p['chars'] > 0 ? '' : ' <span class="label label-light-danger label-inline" style="font-size:10px;">empty</span>'; ?>
            <?php if ( ! empty($p['snippet'])) { ?><div class="text-muted" style="font-size:11px; margin-top:2px;"><?php echo htmlspecialchars($p['snippet']); ?></div><?php } ?>
            <?php if ( ! empty($p['url'])) { ?><div style="margin-top:2px;"><a href="<?php echo htmlspecialchars($p['url']); ?>" target="_blank" rel="noopener" class="text-muted" style="font-size:11px; word-break:break-all;"><i class="la la-external-link-alt mr-1"></i><?php echo htmlspecialchars($p['url']); ?></a></div><?php } ?>
            <div style="font-size:11px; margin-top:3px;">
                <a href="#crawled_text_modal" class="view-crawled-text" data-index="<?php echo (int) $p['i']; ?>" aria-controls="crawled_text_modal"><i class="la la-file-alt mr-1"></i>View crawled text</a>
            </div>
        </td>
        <td class="cell-kind" style="text-align:center;">
            <?php if ($kind === 'package') { ?>
                <span class="label label-light-primary label-inline" style="font-size:11px;" data-toggle="tooltip" title="Content mentions a price">Package</span>
            <?php } else { ?>
                <span class="label label-light-warning label-inline" style="font-size:11px;" data-toggle="tooltip" title="No price found — itinerary only">Itinerary</span>
            <?php } ?>
        </td>
        <td class="cell-cost" style="text-align:center; font-size:12px;"><?php echo $analysed && $p['cost'] > 0 ? number_format((float) $p['cost'], 4) : '—'; ?></td>
        <td class="cell-analysed" style="text-align:center; font-size:12px;">
            <?php if ($analysed && ! empty($p['analysed_at'])) { ?>
                <span style="font-size:11px;"><?php echo htmlspecialchars(date('d M Y, g:i A', strtotime($p['analysed_at']))); ?></span>
            <?php } else { ?>
                <span class="text-muted" style="font-size:11px;">—</span>
            <?php } ?>
        </td>
        <td class="cell-action" style="text-align:center; white-space:nowrap;">
            <?php if ($analysed) { ?>
                <a href="<?php echo base_url('Competitor_Product/View?id=') . (int) $p['analysis_id']; ?>" class="btn btn-icon btn-light-success btn-sm mr-1" data-toggle="tooltip" title="View analysis">
                    <i class="la la-search"></i>
                </a>
            <?php } ?>
            <a href="javascript:;" class="btn btn-icon btn-light-danger btn-sm delete-item"
               data-index="<?php echo (int) $p['i']; ?>"
               data-toggle="tooltip" title="Delete this crawled product">
                <i class="la la-trash"></i>
            </a>
        </td>
    </tr>
<?php $n++; } ?>
