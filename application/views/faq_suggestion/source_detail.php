<?php
$esc=function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};
$back_url=base_url('Faq').'?'.http_build_query($return_query);
?>
<div class="d-flex flex-column-fluid"><div class="container-fluid">
    <?php $this->load->view('faq/sections',array('active_section'=>'sources')); ?>
    <?php foreach(array('faq_success'=>'success','faq_error'=>'danger') as $key=>$color) { if($this->session->flashdata($key)) { ?><div class="alert alert-light-<?php echo $color; ?>"><?php echo $esc($this->session->flashdata($key)); ?></div><?php } } ?>
    <div class="card card-custom mb-5"><div class="card-header"><div class="card-title"><h3 class="card-label">Edit Source: <?php echo $esc($source->Title); ?></h3></div><div class="card-toolbar"><a class="btn btn-light" href="<?php echo $esc($back_url); ?>">Back to Knowledge Source</a></div></div><div class="card-body">
        <p>Batch: <strong>#<?php echo (int)$source->BatchID; ?></strong> · Status: <strong><?php echo $esc(ucfirst($source->Status)); ?></strong> · Verified <?php echo $esc($source->VerifiedDate?:'not yet'); ?></p>
        <?php if($source->SourceUrl) { ?><a class="btn btn-light-primary btn-sm mb-3" href="<?php echo $esc($source->SourceUrl); ?>" target="_blank" rel="noopener noreferrer">Open URL</a><?php } ?>
        <?php if($source->StoredName) { ?><a class="btn btn-light-primary btn-sm mb-3" href="<?php echo base_url('Faq_Suggestion/Source_File?id=').(int)$source->SourceID; ?>" target="_blank" rel="noopener">View original file</a><?php } ?>
        <form method="post" action="<?php echo base_url('Faq_Suggestion/Review_Source'); ?>">
            <?php echo faq_workspace_csrf_field($this->session); ?><input type="hidden" name="source_id" value="<?php echo (int)$source->SourceID; ?>">
            <?php foreach($return_query as $key=>$value) { ?><input type="hidden" name="<?php echo $esc($key); ?>" value="<?php echo $esc($value); ?>"><?php } ?>
            <div class="row"><div class="col-md-4 form-group"><label for="faq_source_status">Status</label><select id="faq_source_status" name="state" class="form-control" required>
                <?php foreach(array('approved'=>'Approved','pending'=>'Pending','expired'=>'Expired','rejected'=>'Rejected') as $key=>$label) { ?><option value="<?php echo $key; ?>" <?php echo $source->Status===$key?'selected':''; ?>><?php echo $label; ?></option><?php } ?>
            </select></div></div>
            <?php $this->load->view('faq_suggestion/source_fields',array('source'=>$source,'products'=>$products)); ?>
            <button type="submit" class="btn btn-primary mr-2">Save Changes</button><a class="btn btn-light" href="<?php echo $esc($back_url); ?>">Cancel</a>
        </form>
        <?php if($source->ExtractedText) { ?><details class="mt-5"><summary>Original imported text</summary><pre class="mt-3" style="white-space:pre-wrap;max-height:400px;overflow:auto"><?php echo $esc($source->ExtractedText); ?></pre></details><?php } ?>
    </div></div>
</div></div>
