<?php
    $esc=function($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');};
    $failed=$job->State==='error';
?>
<div class="d-flex flex-column-fluid"><div class="container-fluid">
    <?php $this->load->view('faq/sections',array('active_section'=>'sources')); ?>
    <div class="card card-custom mb-5">
        <div class="card-header"><div class="card-title"><h3 class="card-label">Upload Progress</h3></div><div class="card-toolbar"><a class="btn btn-light" href="<?php echo base_url('Faq?section=sources'); ?>">Back to List</a></div></div>
        <div class="card-body" id="faq-source-import" data-status-url="<?php echo $esc(base_url('Faq_Suggestion/Source_Import_Status?id=').$job->ImportID); ?>" data-complete-url="<?php echo $esc(base_url('Faq_Suggestion/Source_Import?id=').$job->ImportID); ?>" data-state="<?php echo $esc($job->State); ?>">
            <p class="font-weight-bold text-break"><?php echo $esc($job->FileName?:$job->SourceUrl); ?></p>
            <div id="faq-source-import-progress" class="<?php echo $failed?'d-none':''; ?>" role="status" aria-live="polite">
                <p><span class="spinner spinner-primary spinner-sm mr-8"></span><span id="faq-source-import-message">Extracting your source…</span></p>
                <p class="text-muted">Your upload is complete. Extraction may take a few minutes. This page updates automatically when your sources are saved and approved.</p>
            </div>
            <div id="faq-source-import-error" class="alert alert-light-danger <?php echo $failed?'':'d-none'; ?>" role="alert"><?php echo $failed?$esc($job->ErrorMessage):''; ?></div>
            <a id="faq-source-import-retry" class="btn btn-primary <?php echo $failed?'':'d-none'; ?>" href="<?php echo base_url('Faq_Suggestion/Add_Source'); ?>">Upload Again</a>
            <noscript><p>Refresh this page to check your upload status.</p></noscript>
        </div>
    </div>
</div></div>
<script src="<?php echo base_url('assets/js/faq_source_import.js').'?v='.filemtime(FCPATH.'assets/js/faq_source_import.js'); ?>"></script>
