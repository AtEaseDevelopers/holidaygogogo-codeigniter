<?php
$pager_esc=function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};
$page_url=function($number)use($pagination_query,$page_size,$pager_esc){return $pager_esc(base_url('Faq').'?'.http_build_query($pagination_query+array('page_size'=>$page_size,'page'=>$number)));};
?>
<div class="row mt-3">
    <div class="col-sm-12 col-md-5"><div class="dataTables_info" role="status" aria-live="polite">Showing <?php echo number_format($entry_start); ?> to <?php echo number_format($entry_end); ?> of <?php echo number_format($total); ?> entries</div></div>
    <div class="col-sm-12 col-md-7"><div class="dataTables_paginate paging_simple_numbers"><nav aria-label="Table pages"><ul class="pagination">
        <li class="paginate_button page-item <?php echo $page<=1?'disabled':''; ?>"><?php if($page>1) { ?><a class="page-link" href="<?php echo $page_url($page-1); ?>" aria-label="Previous page">Previous</a><?php } else { ?><span class="page-link" aria-disabled="true">Previous</span><?php } ?></li>
        <?php foreach($page_numbers as $number) { ?>
            <li class="paginate_button page-item <?php echo $number===null?'disabled':($number===$page?'active':''); ?>">
                <?php if($number===null) { ?><span class="page-link">…</span><?php } elseif($number===$page) { ?><span class="page-link" aria-current="page"><?php echo $number; ?></span><?php } else { ?><a class="page-link" href="<?php echo $page_url($number); ?>" aria-label="Page <?php echo $number; ?>"><?php echo $number; ?></a><?php } ?>
            </li>
        <?php } ?>
        <li class="paginate_button page-item <?php echo $page>=$pages?'disabled':''; ?>"><?php if($page<$pages) { ?><a class="page-link" href="<?php echo $page_url($page+1); ?>" aria-label="Next page">Next</a><?php } else { ?><span class="page-link" aria-disabled="true">Next</span><?php } ?></li>
    </ul></nav></div></div>
</div>
