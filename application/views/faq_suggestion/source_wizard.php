<?php 
    $esc=function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}; 
    $types=array('document'=>'Upload PDF / Document','csv'=>'Import CSV','manual'=>'Manual Entry');
    $type_label=$types[$type]??($type==='pdf'?'Import PDF':'Read / Crawl URL');
?>
<div class="d-flex flex-column-fluid"><div class="container-fluid">
    <?php $this->load->view('faq/sections',array('active_section'=>'sources')); ?>
    <div class="card card-custom mb-5"><div class="card-header"><div class="card-title"><h3 class="card-label">Add Source</h3></div><div class="card-toolbar"><a class="btn btn-light" href="<?php echo base_url('Faq?section=sources'); ?>">Back to List</a></div></div><div class="card-body">
        <p class="text-muted">Choose a source type, select its destination and upload. Sources are saved and approved automatically after extraction. You can delete any source you do not want.</p>
        <?php if($error) { ?><div class="alert alert-light-danger"><?php echo $esc($error); ?></div><?php } ?>
        <form method="post" enctype="multipart/form-data" action="<?php echo base_url('Faq_Suggestion/Add_Source'); ?>" id="faq-source-wizard">
            <?php echo faq_workspace_csrf_field($this->session); ?>
            <?php if($step==='type') { ?>
                <h5>Choose how to add your source</h5><input type="hidden" name="stage" value="choose">
                <div class="row mt-4"><?php foreach($types as $key=>$label) { ?><div class="col-md-6 mb-4"><button style="padding: 20px;" type="submit" name="type" value="<?php echo $key; ?>" class="btn btn-light-primary btn-lg w-100 text-left"><?php echo $label; ?></button></div><?php } ?></div>
            <?php } else { ?>
                <input type="hidden" name="type" value="<?php echo $esc($type); ?>"><h5><?php echo $esc($type_label); ?></h5>
                <?php if($step==='input') { ?><input type="hidden" name="stage" value="import">
                    <div class="form-group mt-4"><?php $this->load->view('faq_suggestion/source_destination',array('field_id'=>'faq-source-destination','field_name'=>'destination_id','destination_id'=>$destination_id,'destinations'=>$destinations)); ?><small class="form-text text-muted">Select the destination this source applies to before uploading. Leave unrestricted for guidance that applies to all destinations.</small></div>
                    <?php if(in_array($type,array('pdf','document','csv'),true)) { ?><div class="form-group"><label for="faq-source-file"><?php echo $type==='document'?'PDF / document':strtoupper($type); ?> file</label><input id="faq-source-file" type="file" name="file" class="form-control-file" accept="<?php echo $type==='document'?'.pdf,.docx,.txt':'.'.$type; ?>" required><small class="form-text text-muted"><?php echo $type==='csv'?'UTF-8 CSV, up to 2 MB, with a header row. AI selects useful booking and stay facts, groups related information and preserves conditions.':($type==='pdf'?'PDF, up to 20 MB.':'PDF, Word (.docx), or text (.txt), up to 20 MB.').' Extracted content is saved and approved automatically.'; ?></small></div>
                    <?php } elseif($type==='url') { ?><div class="form-group mt-4"><label>Page URL</label><input type="url" class="form-control" name="source_url" placeholder="https://…" required maxlength="2048"><small class="form-text text-muted">AI reads this page and selects useful booking and stay facts, including charges, age rules, facilities and restrictions. Detailed information on other pages needs its own import.</small></div>
                    <?php } else { ?><div class="form-group mt-4"><label>Title</label><input class="form-control" name="title" required maxlength="255"></div><div class="form-group"><label>Content</label><textarea class="form-control" name="content" rows="8" required maxlength="60000" placeholder="Enter supplier wording or agency guidance. Include its reference and date when relevant."></textarea></div><?php } ?>
                    <a class="btn btn-light mr-2" href="<?php echo base_url('Faq_Suggestion/Add_Source'); ?>">Back</a><button type="submit" class="btn btn-primary"><?php echo $type==='manual'?'Add Source':'Upload Source'; ?></button>
                <?php } ?>
            <?php } ?>
        </form>
        <p id="faq-source-progress" class="text-muted mt-3 mb-0 d-none" role="status">Uploading your file. Extraction continues in the background after the upload completes.</p>
    </div></div>
</div></div>
<script>
$('#faq-source-wizard').on('submit',function() {
    if ($(this).data('submitting')) { return false; }
    $(this).data('submitting',true);
    if ($(this).find('[name="stage"]').val()==='import' && $(this).find('[name="type"]').val()!=='manual') { $('#faq-source-progress').removeClass('d-none'); }
    $(this).find('button[type="submit"],button:not([type])').addClass('disabled');
});
</script>
