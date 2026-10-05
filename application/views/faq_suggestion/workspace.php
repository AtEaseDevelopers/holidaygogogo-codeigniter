<?php
$esc=function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};
$can_edit=isset($can_edit)&&$can_edit;
$filters=faq_workspace_filters();
$workspace_counts=array_replace(array_fill_keys(array_keys($filters),0),isset($workspace_counts)&&is_array($workspace_counts)?$workspace_counts:array());
$run_id=isset($run_id)&&$run_id==='latest20'?'latest20':(isset($run_id)?(int)$run_id:0); $search=isset($search)?$search:'';
$generation_runs=isset($generation_runs)&&is_array($generation_runs)?$generation_runs:array();
$source_labels=array('chats'=>'Chats','pdf'=>'PDF','chatfile'=>'Chat File','reevaluate'=>'Re-evaluation');
$total=isset($total)?$total:count($candidates); $page=isset($page)?$page:1; $pages=isset($pages)?$pages:1;
$workspace_tab=isset($workspace_tab)?$workspace_tab:'all';
$pagination_query=array('section'=>'suggestions','tab'=>$workspace_tab,'search'=>$search,'run_id'=>$run_id);
?>
<style>
    #faq-suggestions-table td { vertical-align:middle; }
    #faq-suggestions-table .label.label-inline { height:auto; min-height:24px; white-space:normal; line-height:1.4; padding-top:4px; padding-bottom:4px; }
    #faq-suggestions-table .faq-status { cursor:help; }
    #faq-suggestions-table .faq-actions { white-space:nowrap; }
</style>
<div class="card card-custom mb-5"><div class="card-body">
    <form method="get" action="<?php echo base_url('Faq'); ?>" class="mb-4">
        <input type="hidden" name="section" value="suggestions">
        <input type="hidden" name="page_size" value="<?php echo $page_size; ?>">
        <div class="row align-items-end">
            <div class="col-md-4 form-group"><label for="faq-generation-filter">Generation History</label><select class="form-control" id="faq-generation-filter" name="run_id" onchange="this.form.submit()">
                <option value="0" <?php echo $run_id===0?'selected':''; ?>>All</option>
                <?php $selected_found=false; foreach($generation_runs as $r) {
                    $id=(int)$r->RunID; $selected=$run_id===$id; $selected_found=$selected_found||$selected;
                    $date=$r->InsertDate?date('j M Y H:i',strtotime($r->InsertDate)):'';
                    $label='#'.$id.' · '.$date.' · '.($source_labels[$r->Source]??$r->Source).' · '.$r->Scope;
                ?><option value="<?php echo $id; ?>" <?php echo $selected?'selected':''; ?>><?php echo $esc($label); ?></option><?php } ?>
                <?php if(is_int($run_id)&&$run_id>0&&!$selected_found) { ?><option value="<?php echo $run_id; ?>" selected>Run #<?php echo $run_id; ?> (unavailable)</option><?php } ?>
            </select></div>
            <div class="col-md-3 form-group"><label for="faq-review-filter">Status</label><select class="form-control" id="faq-review-filter" name="tab" onchange="this.form.submit()">
                <?php foreach($filters as $key=>$label) { ?><option value="<?php echo $key; ?>" <?php echo $workspace_tab===$key?'selected':''; ?>><?php echo $label.' ('.(int)$workspace_counts[$key].')'; ?></option><?php } ?>
            </select></div>
            <div class="col-md-4 form-group"><label for="faq-search">Search</label><input class="form-control" id="faq-search" name="search" value="<?php echo $esc($search); ?>" maxlength="200"></div>
            <div class="col-md-1 form-group"><button class="btn btn-light-primary">Search</button></div>
        </div>
    </form>
    <div class="dataTables_wrapper dt-bootstrap4 no-footer">
    <?php $this->load->view('faq_suggestion/table_length',array('pagination_query'=>$pagination_query,'page_size'=>$page_size)); ?>
    <form id="faq-bulk-form" method="post" action="<?php echo base_url('Faq_Suggestion/Bulk_Action'); ?>">
        <?php echo faq_workspace_csrf_field($this->session); ?>
        <?php if($can_edit) { ?><div class="d-flex flex-wrap align-items-center mb-4">
            <span id="faq-selected-count" class="mr-3 text-muted">0 selected</span>
            <select name="action" id="faq-bulk-action" class="form-control w-auto mr-2" required><option value="">Choose action</option><option value="reevaluate">Re-evaluate with AI</option><option value="approve">Approve</option><option value="reject">Reject</option><option value="delete">Delete</option></select>
            <button class="btn btn-primary" id="faq-bulk-submit" disabled>Apply to selected</button>
        </div><?php } ?>
        <div class="table-responsive"><table id="faq-suggestions-table" class="table table-bordered table-head-custom"><thead><tr>
            <?php if($can_edit) { ?><th><input type="checkbox" id="faq-select-all" aria-label="Select all suggestions on this page"></th><?php } ?>
            <th>Title</th><th>Status</th><th>Destination</th><th>Detected from</th><th>AI Cost</th><th>Actions</th>
        </tr></thead><tbody>
        <?php foreach($candidates as $s) {
            $id=(int)$s->SuggestionID; $completed=in_array($s->State,array('accepted','dismissed'),true);
            $status_details=trim((string)$s->ReviewReason) ?: (trim((string)$s->Reason) ?: faq_workspace_review_label($s->State));
            $evidence=isset($s->Evidence)&&is_array($s->Evidence)?$s->Evidence:array();
            $destination_names=empty($s->Destinations)?array():explode('||',$s->Destinations);
        ?><tr>
            <?php if($can_edit) { ?><td><input type="checkbox" class="faq-select" name="suggestion_ids[]" value="<?php echo $id; ?>" aria-label="Select suggestion <?php echo $id; ?>"></td><?php } ?>
            <td>
                <a href="<?php echo base_url('Faq_Suggestion/Update?id=').$id; ?>"><strong><?php echo $esc($s->Title); ?></strong></a>
                <?php if(!empty($s->Reason)) { ?><div class="text-muted mt-1" style="font-size:12px;"><i class="la la-info-circle"></i> <?php echo $esc($s->Reason); ?></div><?php } ?>
            </td>
            <td><span class="faq-status label label-inline label-pill font-weight-bold text-center label-light-<?php echo $s->State==='accepted'?'success':($s->State==='dismissed'?'danger':($s->State==='draft_ready'?'info':'warning')); ?>" tabindex="0" data-toggle="tooltip" data-html="false" title="<?php echo $esc($status_details); ?>"><?php echo $esc(faq_workspace_review_label($s->State)); ?></span></td>
            <td><?php if(!$destination_names) { ?><span class="text-muted">—</span><?php } else { foreach($destination_names as $destination_name) { ?><span class="label label-inline label-pill label-light-primary font-weight-bold mr-1 mb-1"><?php echo $esc($destination_name); ?></span><?php } } ?></td>
            <td><?php if(!$evidence) { ?><span class="text-muted">—</span><?php } else { ?><button type="button" class="btn btn-light-primary btn-sm font-weight-bold text-nowrap" data-toggle="modal" data-target="#faq_evidence_modal_<?php echo $id; ?>"><i class="la la-comments"></i> View <?php echo count($evidence); ?> source<?php echo count($evidence)===1?'':'s'; ?></button><?php } ?></td>
            <td class="text-nowrap"><?php if($s->CostUsd===null||$s->CostUsd==='') { ?><span class="text-muted">—</span><?php } else { ?><span data-toggle="tooltip" title="This suggestion's share of the AI cost for its generation run">$<?php echo number_format((float)$s->CostUsd,4); ?></span><?php } ?></td>
            <td class="faq-actions"><div class="btn-group">
                <a class="btn btn-icon btn-light-primary btn-sm" href="<?php echo base_url('Faq_Suggestion/Update?id=').$id; ?>" data-toggle="tooltip" title="<?php echo $can_edit&&!$completed?'Edit suggestion':'View suggestion'; ?>" aria-label="<?php echo $can_edit&&!$completed?'Edit suggestion':'View suggestion'; ?>"><i class="la la-edit" aria-hidden="true"></i></a>
                <?php if($can_edit) { ?>
                    <button class="btn btn-icon btn-light-success btn-sm ml-1" type="submit" form="faq-approve-<?php echo $id; ?>" data-toggle="tooltip" title="Approve suggestion" aria-label="Approve suggestion"><i class="la la-check" aria-hidden="true"></i></button>
                    <button class="btn btn-icon btn-light-danger btn-sm ml-1" type="submit" form="faq-delete-<?php echo $id; ?>" data-toggle="tooltip" title="Delete suggestion" aria-label="Delete suggestion"><i class="la la-trash" aria-hidden="true"></i></button>
                    <span class="d-inline-block ml-1" data-toggle="tooltip" title="<?php echo $completed?'This suggestion is already completed':'Re-evaluate with AI'; ?>" <?php echo $completed?'tabindex="0"':''; ?>><button class="btn btn-icon btn-light-info btn-sm" type="submit" form="faq-reevaluate-<?php echo $id; ?>" aria-label="Re-evaluate with AI" <?php echo $completed?'disabled':''; ?>><i class="la la-redo-alt" aria-hidden="true"></i></button></span>
                <?php } ?>
            </div></td>
        </tr><?php } if(!$candidates) { ?><tr><td colspan="<?php echo $can_edit?7:6; ?>" class="text-muted text-center">No suggestions match these filters.</td></tr><?php } ?>
        </tbody></table></div>
    </form>
    <?php if($can_edit) { foreach($candidates as $s) { $id=(int)$s->SuggestionID; foreach(array('approve','delete','reevaluate') as $action) { ?>
        <form id="faq-<?php echo $action.'-'.$id; ?>" method="post" action="<?php echo base_url('Faq_Suggestion/Bulk_Action'); ?>" <?php if($action==='delete') { ?>data-delete-title="<?php echo $esc('Suggestion : '.$s->Title); ?>" onsubmit="return Confirm_Delete_Form(this, '<?php echo base_url('assets/image/sweetalert.jpg'); ?>', this.dataset.deleteTitle);"<?php } ?>><?php echo faq_workspace_csrf_field($this->session); ?><input type="hidden" name="suggestion_ids[]" value="<?php echo $id; ?>"><input type="hidden" name="action" value="<?php echo $action; ?>"></form>
    <?php } } } ?>
    <?php $this->load->view('faq_suggestion/table_pagination',array('pagination_query'=>$pagination_query,'page_size'=>$page_size,'total'=>$total,'page'=>$page,'pages'=>$pages,'entry_start'=>$entry_start,'entry_end'=>$entry_end,'page_numbers'=>$page_numbers)); ?>
    </div>
</div></div>
<?php $this->load->view('faq_suggestion/evidence_modals',array('suggestions'=>$candidates)); ?>
<script>
(function() {
    $('#faq-suggestions-table [data-toggle="tooltip"]').tooltip();
    function updateSelection() {
        var total=$('.faq-select').length, selected=$('.faq-select:checked').length;
        $('#faq-selected-count').text(selected+' selected'); $('#faq-bulk-submit').prop('disabled',selected===0);
        $('#faq-select-all').prop('checked',total>0&&selected===total).prop('indeterminate',selected>0&&selected<total);
    }
    $('#faq-select-all').on('change',function() { $('.faq-select').prop('checked',this.checked); updateSelection(); });
    $('.faq-select').on('change',updateSelection);
    $('#faq-bulk-form').on('submit',function() {
        var action=$('#faq-bulk-action').val();
        if (!$('.faq-select:checked').length) { return false; }
        if (action==='reevaluate'&&$('.faq-select:checked').length>10) { alert('Select up to 10 suggestions for re-evaluation.'); return false; }
        if (action==='delete') {
            var count=$('.faq-select:checked').length;
            return Confirm_Delete_Form(this, '<?php echo base_url('assets/image/sweetalert.jpg'); ?>', 'the '+count+' selected suggestion'+(count===1?'':'s'));
        }
        $('#faq-bulk-submit').prop('disabled',true);
    });
})();
</script>
