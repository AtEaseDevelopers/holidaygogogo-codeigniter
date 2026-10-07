<?php
$esc=function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};
$back_url=base_url('Faq').'?'.http_build_query($return_query);
$package_name=''; foreach($products as $product) { if ((int)$product->ProductID===(int)$source->ProductID) { $package_name=$product->Name; break; } }
?>
<div class="d-flex flex-column-fluid"><div class="container-fluid">
    <?php $this->load->view('faq/sections',array('active_section'=>'sources')); ?>
    <?php foreach(array('faq_success'=>'success','faq_error'=>'danger') as $key=>$color) { if($this->session->flashdata($key)) { ?><div class="alert alert-light-<?php echo $color; ?>"><?php echo $esc($this->session->flashdata($key)); ?></div><?php } } ?>
    <div class="card card-custom mb-5"><div class="card-header"><div class="card-title"><h3 class="card-label">View Source: <?php echo $esc($source->Title); ?></h3></div><div class="card-toolbar"><a class="btn btn-light" href="<?php echo $esc($back_url); ?>">Back to Knowledge Source</a></div></div><div class="card-body">
        <p>Batch: <strong>#<?php echo (int)$source->BatchID; ?></strong> · Status: <strong><?php echo $esc($source->Status==='approved'?'Automatically approved':ucfirst($source->Status)); ?></strong> · Added <?php echo $esc($source->InsertDate); ?></p>
        <p class="text-muted">Sources are approved automatically after upload. Delete any source you do not want, or upload a replacement.</p>
        <?php if($source->SourceUrl) { ?><a class="btn btn-light-primary btn-sm mb-3" href="<?php echo $esc($source->SourceUrl); ?>" target="_blank" rel="noopener noreferrer">Open URL</a><?php } ?>
        <?php if($source->StoredName) { ?><a class="btn btn-light-primary btn-sm mb-3" href="<?php echo base_url('Faq_Suggestion/Source_File?id=').(int)$source->SourceID; ?>" target="_blank" rel="noopener">View original file</a><?php } ?>
        <dl class="row">
            <?php foreach(array('Destination'=>$source->DestinationName?:'No destination restriction','Source type'=>ucfirst(str_replace('_',' ',$source->SourceType)),
                'Topic'=>$source->Topic,'Package'=>$package_name?:'No package restriction','Resort'=>$source->ResortName,'Room type'=>$source->RoomType,
                'Valid from'=>$source->ValidFrom,'Valid until'=>$source->ValidTo,'Review due'=>$source->ReviewDue) as $label=>$value) { if ($value!==null && $value!=='') { ?>
            <dt class="col-sm-3"><?php echo $esc($label); ?></dt><dd class="col-sm-9"><?php echo $esc($value); ?></dd>
            <?php } } ?>
        </dl>
        <h5>Knowledge content</h5><div class="border rounded p-4 mb-4" style="white-space:pre-wrap;"><?php echo $esc($source->Excerpt); ?></div>
        <form method="post" action="<?php echo base_url('Faq_Suggestion/Delete_Source'); ?>" data-delete-title="<?php echo $esc('Knowledge Source : '.$source->Title); ?>" onsubmit="return Confirm_Delete_Form(this, '<?php echo base_url('assets/image/sweetalert.jpg'); ?>', this.dataset.deleteTitle);">
            <?php echo faq_workspace_csrf_field($this->session); ?><input type="hidden" name="source_id" value="<?php echo (int)$source->SourceID; ?>">
            <?php foreach($return_query as $key=>$value) { ?><input type="hidden" name="<?php echo $esc($key); ?>" value="<?php echo $esc($value); ?>"><?php } ?>
            <button type="submit" class="btn btn-danger">Delete Source</button>
        </form>
        <?php if($source->ExtractedText) { ?><details class="mt-5"><summary>Original imported text</summary><pre class="mt-3" style="white-space:pre-wrap;max-height:400px;overflow:auto"><?php echo $esc($source->ExtractedText); ?></pre></details><?php } ?>
    </div></div>
</div></div>
