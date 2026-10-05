<?php $length_esc=function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}; ?>
<div class="row mb-3"><div class="col-sm-12 col-md-6">
    <div class="dataTables_length"><form method="get" action="<?php echo base_url('Faq'); ?>">
        <?php foreach($pagination_query as $key=>$value) { ?><input type="hidden" name="<?php echo $length_esc($key); ?>" value="<?php echo $length_esc($value); ?>"><?php } ?>
        <label>Show <select name="page_size" aria-label="Entries per page" class="custom-select custom-select-sm form-control form-control-sm" onchange="this.form.submit()">
            <?php foreach(array(100=>'100',200=>'200',500=>'500',-1=>'All') as $size=>$label) { ?><option value="<?php echo $size; ?>" <?php echo $page_size===$size?'selected':''; ?>><?php echo $label; ?></option><?php } ?>
        </select> entries</label>
    </form></div>
</div></div>
