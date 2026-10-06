<div class="d-flex flex-column-fluid"><div class="container-fluid">
    <?php $this->load->view('faq/sections',array('active_section'=>'suggestions')); ?>
    <?php foreach(array('faq_success'=>'success','faq_error'=>'danger') as $key=>$color) { if($this->session->flashdata($key)) { ?>
        <div class="alert alert-light-<?php echo $color; ?>"><?php echo htmlspecialchars($this->session->flashdata($key),ENT_QUOTES,'UTF-8'); ?></div>
    <?php } } ?>
    <div class="card card-custom mb-5"><div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
        <div class="card-title"><h3 class="card-label" style="color:#6082B6;"><strong>AI Suggestion</strong><small class="d-block mt-2" style="color:#6082B6;">Review suggestions from all generation runs.</small></h3></div>
        <?php if($can_edit) { ?><div class="card-toolbar">
            <button type="button" class="btn btn-primary mr-2" data-toggle="modal" data-target="#faq_sugg_generate_modal">Generate from Chats</button>
            <button type="button" class="btn btn-info mr-2" data-toggle="modal" data-target="#faq_sugg_pdf_modal">From PDF</button>
            <button type="button" class="btn btn-success" data-toggle="modal" data-target="#faq_sugg_chatfile_modal">From Chat File</button>
        </div><?php } ?>
    </div></div>
    <?php $this->load->view('faq_suggestion/workspace',array('candidates'=>$candidates,'workspace_tab'=>$workspace_tab,'workspace_counts'=>$workspace_counts,
        'can_edit'=>$can_edit,'search'=>$search??'','run_id'=>$run_id??0,'generation_runs'=>$generation_runs??array(),'total'=>$total,'page'=>$page,'pages'=>$pages,
        'page_size'=>$page_size,'entry_start'=>$entry_start,'entry_end'=>$entry_end,'page_numbers'=>$page_numbers)); ?>
</div></div>
<?php $this->load->view('faq_suggestion/generation_modals',array('can_edit'=>$can_edit)); ?>
