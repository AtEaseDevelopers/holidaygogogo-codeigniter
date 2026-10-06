<?php
$esc=function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};
$pagination_query=array('section'=>'sources','batch_id'=>$batch_id,'search'=>$search);
$source_return_query=$pagination_query+array('page'=>$page,'page_size'=>$page_size);
?>
<div class="d-flex flex-column-fluid"><div class="container-fluid">
    <?php $this->load->view('faq/sections',array('active_section'=>'sources')); ?>
    <?php foreach(array('faq_success'=>'success','faq_error'=>'danger') as $key=>$color) { if($this->session->flashdata($key)) { ?><div class="alert alert-light-<?php echo $color; ?>"><?php echo $esc($this->session->flashdata($key)); ?></div><?php } } ?>
    <div class="card card-custom mb-5"><div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;"><div class="card-title"><h3 class="card-label" style="color:#6082B6;"><strong>Knowledge Source</strong></h3></div><div class="card-toolbar"><a href="<?php echo base_url('Faq_Suggestion/Add_Source'); ?>" class="btn btn-primary">Add Source</a></div></div>
        <div class="card-body">
        <form method="get" action="<?php echo base_url('Faq'); ?>" class="d-flex flex-wrap align-items-end mb-4">
            <input type="hidden" name="section" value="sources">
            <input type="hidden" name="page_size" value="<?php echo $page_size; ?>">
            <div class="mr-3 mb-2"><label for="faq_source_batch" class="mb-1">Batch ID</label><select id="faq_source_batch" name="batch_id" class="form-control form-control-sm" style="min-width:220px;">
                <option value="0">All batches</option>
                <?php $selected_batch_exists=false; foreach($batches as $batch) { if((int)$batch->BatchID===$batch_id) { $selected_batch_exists=true; } ?><option value="<?php echo (int)$batch->BatchID; ?>" <?php echo (int)$batch->BatchID===$batch_id?'selected':''; ?>>Batch #<?php echo (int)$batch->BatchID; ?> (<?php echo (int)$batch->SourceCount; ?> sources)</option><?php } ?>
                <?php if($batch_id && !$selected_batch_exists) { ?><option value="<?php echo $batch_id; ?>" selected>Batch #<?php echo $batch_id; ?> (0 sources)</option><?php } ?>
            </select></div>
            <div class="mr-3 mb-2"><label for="faq_source_search" class="mb-1">Search</label><input type="search" id="faq_source_search" name="search" class="form-control form-control-sm" style="min-width:260px;" maxlength="200" value="<?php echo $esc($search); ?>" placeholder="Title, content, resort, room or topic"></div>
            <button class="btn btn-primary btn-sm mr-2 mb-2" type="submit">Filter</button>
            <?php if($batch_id || $search!=='') { ?><a class="btn btn-light btn-sm mb-2" href="<?php echo base_url('Faq?section=sources&page_size=').$page_size; ?>">Clear</a><?php } ?>
        </form>
        <div class="dataTables_wrapper dt-bootstrap4 no-footer">
        <?php $this->load->view('faq_suggestion/table_length',array('pagination_query'=>$pagination_query,'page_size'=>$page_size)); ?>
        <div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Source</th><th>Batch ID</th><th>Type</th><th>Scope / topic</th><th>Status</th><th>Verified</th><th>Actions</th></tr></thead><tbody>
        <?php foreach($sources as $source) { $edit_url=base_url('Faq_Suggestion/Source_Detail').'?'.http_build_query(array('id'=>(int)$source->SourceID)+$source_return_query); ?><tr>
            <td><a href="<?php echo $esc($edit_url); ?>"><?php echo $esc($source->Title); ?></a><div class="small text-muted">#<?php echo (int)$source->SourceID; ?></div></td>
            <td class="text-nowrap"><a href="<?php echo $esc(base_url('Faq').'?'.http_build_query(array('section'=>'sources','batch_id'=>(int)$source->BatchID,'page_size'=>$page_size,'search'=>$search))); ?>"><?php echo (int)$source->BatchID; ?></a></td>
            <td><?php echo $esc(ucfirst(str_replace('_',' ',$source->SourceType))); ?></td>
            <td><?php echo $esc(implode(' / ',array_filter(array($source->ResortName,$source->RoomType)))); ?><div class="small text-muted"><?php echo $esc($source->Topic); ?></div></td>
            <td><span class="label label-inline label-light-<?php echo $source->Status==='approved'?'success':'warning'; ?>"><?php echo $esc(ucfirst($source->Status)); ?></span></td>
            <td><?php echo $esc($source->VerifiedDate?:'Pending verification'); ?></td>
            <td class="text-nowrap"><div class="d-flex align-items-center">
                <a class="btn btn-icon btn-light-primary btn-sm mr-2" href="<?php echo $esc($edit_url); ?>" data-toggle="tooltip" title="Edit source" aria-label="Edit source"><i class="la la-edit" aria-hidden="true"></i></a>
                <form method="post" action="<?php echo base_url('Faq_Suggestion/Delete_Source'); ?>" class="mb-0" data-delete-title="<?php echo $esc('Knowledge Source : '.$source->Title); ?>" onsubmit="return Confirm_Delete_Form(this, '<?php echo base_url('assets/image/sweetalert.jpg'); ?>', this.dataset.deleteTitle);">
                    <?php echo faq_workspace_csrf_field($this->session); ?><input type="hidden" name="source_id" value="<?php echo (int)$source->SourceID; ?>">
                    <?php foreach($source_return_query as $key=>$value) { ?><input type="hidden" name="<?php echo $esc($key); ?>" value="<?php echo $esc($value); ?>"><?php } ?>
                    <button type="submit" class="btn btn-icon btn-light-danger btn-sm" data-toggle="tooltip" title="Delete source" aria-label="Delete source"><i class="la la-trash" aria-hidden="true"></i></button>
                </form>
            </div></td>
        </tr><?php } if(!$sources) { ?><tr><td colspan="7" class="text-center text-muted"><?php echo $search!==''?'No knowledge sources match these filters.':($batch_id?'No knowledge sources in Batch #'.$batch_id.'.':'No knowledge sources yet.'); ?></td></tr><?php } ?>
        </tbody></table></div>
        <?php $this->load->view('faq_suggestion/table_pagination',array('pagination_query'=>$pagination_query,'page_size'=>$page_size,'total'=>$total,'page'=>$page,'pages'=>$pages,'entry_start'=>$entry_start,'entry_end'=>$entry_end,'page_numbers'=>$page_numbers)); ?>
        </div></div>
    </div>
</div></div>
