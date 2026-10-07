<?php
$esc=function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};
$can_edit=isset($can_edit)&&$can_edit;
$filters=faq_workspace_filters();
$workspace_counts=array_replace(array_fill_keys(array_keys($filters),0),isset($workspace_counts)&&is_array($workspace_counts)?$workspace_counts:array());
$run_id=isset($run_id)&&$run_id==='latest20'?'latest20':(isset($run_id)?(int)$run_id:0); $search=isset($search)?$search:'';
$generation_runs=isset($generation_runs)&&is_array($generation_runs)?$generation_runs:array();
$source_labels=array('chats'=>'Chats','pdf'=>'PDF','chatfile'=>'Chat File','reevaluate'=>'Re-evaluation');
$total=isset($total)?$total:count($candidates); $page=isset($page)?$page:1; $pages=isset($pages)?$pages:1;
$workspace_tab=isset($workspace_tab)?$workspace_tab:'pending';
$pagination_query=array('section'=>'suggestions','tab'=>$workspace_tab,'search'=>$search,'run_id'=>$run_id);
?>
<style>
    #faq-workspace .faq-table-container { container-type:inline-size; container-name:faq-suggestions; }
    #faq-suggestions-table { min-width:780px; border-color:#e7ebf1; }
    #faq-suggestions-table td { vertical-align:top; padding:18px 14px; border-left:0; border-right:0; border-color:#e7ebf1; }
    #faq-suggestions-table th { background:#f7f9fc; border-color:#e7ebf1; }
    #faq-suggestions-table tbody tr:nth-child(even) { background:#fcfdff; }
    #faq-suggestions-table .faq-question-cell { width:60%; min-width:320px; }
    #faq-suggestions-table .faq-number-column { width:1%; white-space:nowrap; color:#7e8299; }
    #faq-suggestions-table .faq-question-meta { color:#7e8299; font-size:11px; margin-bottom:7px; }
    #faq-suggestions-table .faq-question-title { display:block; border:0; padding:0; background:none; color:#26354b; font:inherit; font-weight:600; line-height:1.5; text-align:left; }
    #faq-suggestions-table a.faq-question-title:hover { color:#3699ff; }
    #faq-suggestions-table .faq-answer-panel { margin-top:10px; padding:10px 14px; background:#f5f7fa; border-left:3px solid #dce5f2; border-radius:0 6px 6px 0; }
    #faq-suggestions-table .faq-answer-part + .faq-answer-part { margin-top:18px; padding-top:16px; border-top:1px solid #e0e6ee; }
    #faq-suggestions-table .faq-answer-part h6 { font:inherit; font-weight:600; line-height:1.5; margin-bottom:8px; }
    #faq-suggestions-table .label.label-inline { height:auto; min-height:24px; white-space:normal; line-height:1.4; padding-top:4px; padding-bottom:4px; }
    #faq-suggestions-table .faq-status { cursor:help; }
    #faq-suggestions-table .faq-answer { color:#000; font-size:inherit; line-height:1.7; white-space:pre-wrap; overflow-wrap:anywhere; max-width:80ch; }
    #faq-suggestions-table .faq-actions { white-space:nowrap; text-align:center; }
    @container faq-suggestions (max-width:1049px) {
        #faq-suggestions-table .faq-number-column { display:none; }
    }
    @media (max-width:767.98px) {
        #faq-suggestions-table .faq-number-column { display:none; }
    }
</style>
<div id="faq-workspace" class="card card-custom mb-5" data-confirm-background="<?php echo base_url('assets/image/sweetalert.jpg'); ?>"><div class="card-body">
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
            <select name="action" id="faq-bulk-action" class="form-control w-auto mr-2" required><option value="">Choose action</option><option value="reevaluate">Re-evaluate with AI</option><option value="approve">Approve</option><option value="delete">Delete</option></select>
            <button class="btn btn-primary" id="faq-bulk-submit" disabled>Apply to selected</button>
        </div><?php } ?>
        <div class="table-responsive faq-table-container"><table id="faq-suggestions-table" class="table table-bordered table-head-custom"><thead><tr>
            <?php if($can_edit) { ?><th><input type="checkbox" id="faq-select-all" aria-label="Select all suggestions on this page"></th><?php } ?>
            <th class="faq-number-column" scope="col">#</th>
            <th>Question &amp; answer</th><th>Status</th><th>Destination</th><th>AI Cost</th><th style="text-align:center;">Actions</th>
        </tr></thead><tbody>
        <?php foreach($candidates as $s) {
            $id=(int)$s->SuggestionID; $completed=in_array($s->State,array('accepted','dismissed'),true);
            $status_details=trim((string)$s->ReviewReason) ?: (trim((string)$s->Reason) ?: faq_workspace_review_label($s->State));
            $evidence=isset($s->Evidence)&&is_array($s->Evidence)?$s->Evidence:array();
            $destination_names=empty($s->Destinations)?array():explode('||',$s->Destinations);
            $answer_items=Faq_Model::Decode_Items($s->Description);
        ?><tr data-suggestion-id="<?php echo $id; ?>">
            <?php if($can_edit) { ?><td><input type="checkbox" class="faq-select" name="suggestion_ids[]" value="<?php echo $id; ?>" aria-label="Select suggestion <?php echo $id; ?>"></td><?php } ?>
            <td class="faq-number-column"><?php echo $id; ?></td>
            <td class="faq-question-cell">
                <?php if(count($answer_items)>1) { ?><div class="faq-question-meta"><?php echo count($answer_items); ?> questions</div><?php } ?>
                <a class="faq-question-title" href="<?php echo base_url('Faq_Suggestion/Update?id=').$id; ?>"><?php echo $esc($s->Title); ?></a>
                <?php if($answer_items) { ?><div class="faq-answer-panel">
                    <div class="faq-full-answer" id="faq-full-answer-<?php echo $id; ?>">
                        <?php foreach($answer_items as $item) { ?><section class="faq-answer-part">
                            <?php if(count($answer_items)>1&&trim($item['q'])!=='') { ?><h6><?php echo $esc($item['q']); ?></h6><?php } ?>
                            <div class="faq-answer"><?php echo $esc($item['a']?:'No answer drafted yet.'); ?></div>
                        </section><?php } ?>
                    </div>
                </div><?php } else { ?><p class="text-muted mt-2 mb-0">No answer drafted yet.</p><?php } ?>
            </td>
            <td><span class="faq-status label label-inline label-pill font-weight-bold text-center label-light-<?php echo $s->State==='accepted'?'success':($s->State==='dismissed'?'danger':($s->State==='draft_ready'?'info':'warning')); ?>" tabindex="0" data-toggle="tooltip" data-html="false" title="<?php echo $esc($status_details); ?>"><?php echo $esc(faq_workspace_review_label($s->State)); ?></span></td>
            <td><?php if(!$destination_names) { ?><span class="text-muted">—</span><?php } else { foreach($destination_names as $destination_name) { ?><span class="label label-inline label-pill label-light-primary font-weight-bold mr-1 mb-1"><?php echo $esc($destination_name); ?></span><?php } } ?></td>
            <td class="text-nowrap"><?php if($s->CostUsd===null||$s->CostUsd==='') { ?><span class="text-muted">—</span><?php } else { ?><span data-toggle="tooltip" title="This suggestion's share of the AI cost for its generation run">$<?php echo number_format((float)$s->CostUsd,4); ?></span><?php } ?></td>
            <td class="faq-actions"><div class="btn-group">
                <button class="btn btn-light-primary btn-sm dropdown-toggle" style="padding-left:3px;" type="button" id="faq-actions-<?php echo $id; ?>" data-toggle="dropdown" data-boundary="viewport" aria-haspopup="true" aria-expanded="false" aria-label="Actions for suggestion <?php echo $id; ?>"></button>
                <div class="dropdown-menu" aria-labelledby="faq-actions-<?php echo $id; ?>">
                    <a class="dropdown-item" style="font-size:11px;" href="<?php echo base_url('Faq_Suggestion/Update?id=').$id; ?>"><?php echo $can_edit&&!$completed?'Edit suggestion':'View suggestion'; ?></a>
                    <button class="dropdown-item" style="font-size:11px;" type="button" data-toggle="modal" data-target="#faq_evidence_modal_<?php echo $id; ?>" <?php echo $evidence?'':'disabled'; ?>>Detected from (<?php echo count($evidence); ?> source<?php echo count($evidence)===1?'':'s'; ?>)</button>
                    <?php if($can_edit) { ?>
                        <button class="dropdown-item" style="font-size:11px; color:#28a745;" type="submit" form="faq-approve-<?php echo $id; ?>">Approve</button>
                        <button class="dropdown-item" style="font-size:11px;" type="submit" form="faq-reevaluate-<?php echo $id; ?>" <?php echo $completed?'disabled':''; ?>>Re-evaluate with AI</button>
                    <?php } ?>
                    <?php if(!empty($s->AcceptedFAQID)) { ?>
                        <a class="dropdown-item" style="font-size:11px; color:#28a745;" href="<?php echo $can_edit?base_url('Faq/Update?faq_id=').(int)$s->AcceptedFAQID:base_url('Faq'); ?>">View approved FAQ</a>
                    <?php } ?>
                    <?php if($can_edit) { ?>
                        <button class="dropdown-item" style="font-size:11px; color:#E37383;" type="submit" form="faq-delete-<?php echo $id; ?>">Delete</button>
                    <?php } ?>
                </div>
            </div></td>
        </tr><?php } if(!$candidates) { ?><tr><td colspan="<?php echo $can_edit?7:6; ?>" class="text-muted text-center">No suggestions match these filters.</td></tr><?php } ?>
        </tbody></table></div>
    </form>
    <?php if($can_edit) { foreach($candidates as $s) { $id=(int)$s->SuggestionID; foreach(array('approve','delete','reevaluate') as $action) { ?>
        <form id="faq-<?php echo $action.'-'.$id; ?>" method="post" action="<?php echo base_url('Faq_Suggestion/Bulk_Action'); ?>" <?php if($action==='approve') { ?>class="faq-approve-form" data-approve-title="<?php echo $esc('Suggestion : '.$s->Title); ?>"<?php } elseif($action==='delete') { ?>data-delete-title="<?php echo $esc('Suggestion : '.$s->Title); ?>" onsubmit="return Confirm_Delete_Form(this, '<?php echo base_url('assets/image/sweetalert.jpg'); ?>', this.dataset.deleteTitle);"<?php } ?>><?php echo faq_workspace_csrf_field($this->session); ?><input type="hidden" name="suggestion_ids[]" value="<?php echo $id; ?>"><input type="hidden" name="action" value="<?php echo $action; ?>"></form>
    <?php } } } ?>
    <?php $this->load->view('faq_suggestion/table_pagination',array('pagination_query'=>$pagination_query,'page_size'=>$page_size,'total'=>$total,'page'=>$page,'pages'=>$pages,'entry_start'=>$entry_start,'entry_end'=>$entry_end,'page_numbers'=>$page_numbers)); ?>
    </div>
</div></div>
<?php $this->load->view('faq_suggestion/evidence_modals',array('suggestions'=>$candidates)); ?>
<script src="<?php echo base_url('assets/js/faq_suggestions_workspace.js?v=').filemtime(FCPATH.'assets/js/faq_suggestions_workspace.js'); ?>"></script>
