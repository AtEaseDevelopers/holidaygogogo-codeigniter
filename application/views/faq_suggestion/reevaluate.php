<?php $esc=function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}; $return_filters=faq_workspace_return_filters($return_filters??''); ?>
<div class="d-flex flex-column-fluid"><div class="container-fluid">
    <?php $this->load->view('faq/sections',array('active_section'=>'suggestions')); ?>
    <form method="post" action="<?php echo base_url('Faq_Suggestion/Reevaluate'); ?>" id="faq-reevaluation-form">
        <?php echo faq_workspace_csrf_field($this->session); ?>
        <input type="hidden" name="return_filters" value="<?php echo $esc($return_filters); ?>">
        <div class="card card-custom mb-5"><div class="card-body"><h3>Add Information &amp; Re-evaluate</h3><p class="text-muted">Add the missing details or supporting replies below. AI will reassess each question using the chats, additional information and Knowledge Sources for attached destinations. Attach and save a destination on the suggestion to include its sources. Review the updated answers before approval.</p></div></div>
        <?php foreach($suggestions as $s) { $id=(int)$s->SuggestionID; $prefix='details['.$id.']'; ?>
        <div class="card card-custom mb-5"><div class="card-body"><h5>#<?php echo $id; ?> · <?php echo $esc($s->Title); ?></h5>
            <p class="text-muted"><?php echo $esc($s->ReviewReason); ?></p>
            <?php if(!Faq_Suggestion_Model::Parse_Id_Csv($s->DestinationIds)) { ?><p class="small text-muted">No destination is attached. <a href="<?php echo $esc(faq_workspace_suggestion_url($id,$return_filters)); ?>">Attach a destination</a> to include its Knowledge Sources during re-evaluation.</p><?php } ?>
            <input type="hidden" name="suggestion_ids[]" value="<?php echo $id; ?>"><input type="hidden" name="<?php echo $prefix; ?>[version]" value="<?php echo $esc(faq_workspace_review_version($s)); ?>">
            <div class="form-group"><label>Additional information for this suggestion</label><textarea class="form-control" name="<?php echo $prefix; ?>[additional]" rows="5" maxlength="20000" placeholder="Paste additional relevant chats, destination or package details, child ages, or explain what needs to be clarified."></textarea></div>
            <label class="d-flex align-items-center"><input type="checkbox" checked name="<?php echo $prefix; ?>[more_messages]" value="1" class="mr-2">Include additional linked chat messages (up to 100, within the input size limit)</label>
            <div class="faq-knowledge-preview mt-2" data-mode="reevaluate" data-suggestion-id="<?php echo $id; ?>" data-target="#faq-knowledge-<?php echo $id; ?>" data-url="<?php echo base_url('Faq_Suggestion/Knowledge_Preview'); ?>" data-source-url="<?php echo base_url('Faq_Suggestion/Source_Detail?id='); ?>">
                <div id="faq-knowledge-<?php echo $id; ?>" aria-live="polite"><?php $this->load->view('faq_suggestion/knowledge_selection',array('selection'=>$knowledge_previews[$id]??array('sources'=>array()),'open'=>true,'can_manage_sources'=>$can_manage_sources??false)); ?></div>
            </div>
        </div></div><?php } ?>
        <div class="mb-5"><a class="btn btn-light mr-2" href="<?php echo $esc(faq_workspace_suggestions_url($return_filters)); ?>">Cancel</a><button class="btn btn-primary" id="faq-reevaluation-submit">Re-evaluate <?php echo count($suggestions); ?> suggestion(s)</button></div>
    </form>
</div></div>
<script>
$('#faq-reevaluation-form').on('submit',function() { $('#faq-reevaluation-submit').prop('disabled',true).text('Re-evaluating… Please wait'); });
</script>
<script src="<?php echo base_url('assets/js/faq_knowledge_preview.js?v=').filemtime(FCPATH.'assets/js/faq_knowledge_preview.js'); ?>"></script>
