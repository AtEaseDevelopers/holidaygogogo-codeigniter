<?php 
    $esc=function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}; 
    // $types=array('pdf'=>'Import PDF','csv'=>'Import CSV','url'=>'Read / Crawl URL','manual'=>'Manual Entry'); 
    $types=array('pdf'=>'Import PDF','csv'=>'Import CSV','manual'=>'Manual Entry'); 
?>
<div class="d-flex flex-column-fluid"><div class="container-fluid">
    <?php $this->load->view('faq/sections',array('active_section'=>'sources')); ?>
    <div class="card card-custom mb-5"><div class="card-header"><div class="card-title"><h3 class="card-label">Add Source</h3></div><div class="card-toolbar"><a class="btn btn-light" href="<?php echo base_url('Faq?section=sources'); ?>">Back to List</a></div></div><div class="card-body">
        <p class="text-muted">1. Choose type → 2. Extract content → 3. Review source entries → Save</p>
        <?php if($error) { ?><div class="alert alert-light-danger"><?php echo $esc($error); ?></div><?php } ?>
        <form method="post" enctype="multipart/form-data" action="<?php echo base_url('Faq_Suggestion/Add_Source'); ?>" id="faq-source-wizard">
            <?php echo faq_workspace_csrf_field($this->session); ?>
            <?php if($step==='type') { ?>
                <h5>Choose how to add your source</h5><input type="hidden" name="stage" value="choose">
                <div class="row mt-4"><?php foreach($types as $key=>$label) { ?><div class="col-md-6 mb-4"><button style="padding: 20px;" type="submit" name="type" value="<?php echo $key; ?>" class="btn btn-light-primary btn-lg w-100 text-left"><?php echo $label; ?></button></div><?php } ?></div>
            <?php } else { ?>
                <input type="hidden" name="type" value="<?php echo $esc($type); ?>"><h5><?php echo $types[$type]; ?></h5>
                <?php if($step==='input') { ?><input type="hidden" name="stage" value="import">
                    <?php if(in_array($type,array('pdf','csv'),true)) { ?><div class="form-group mt-4"><label><?php echo strtoupper($type); ?> file</label><input type="file" name="file" class="form-control-file" accept=".<?php echo $type; ?>" required><small class="form-text text-muted"><?php echo $type==='pdf'?'Up to 20 MB. AI extracts wording for you to check against the original PDF.':'UTF-8 CSV, up to 2 MB, with a header row. AI selects useful booking and stay facts, groups related information and preserves conditions.'; ?></small></div>
                    <?php } elseif($type==='url') { ?><div class="form-group mt-4"><label>Page URL</label><input type="url" class="form-control" name="source_url" placeholder="https://…" required maxlength="2048"><small class="form-text text-muted">AI reads this page and selects useful booking and stay facts, including charges, age rules, facilities and restrictions. Detailed information on other pages needs its own import.</small></div>
                    <?php } else { ?><div class="form-group mt-4"><label>Title</label><input class="form-control" name="title" required maxlength="255"></div><div class="form-group"><label>Content</label><textarea class="form-control" name="content" rows="8" required maxlength="60000" placeholder="Enter supplier wording or agency guidance. Include its reference and date when relevant."></textarea></div><?php } ?>
                    <a class="btn btn-light mr-2" href="<?php echo base_url('Faq_Suggestion/Add_Source'); ?>">Back</a><button class="btn btn-primary"><?php echo $type==='manual'?'Continue to Preview':'Extract with AI'; ?></button>
                <?php } else { ?><input type="hidden" name="stage" value="save"><input type="hidden" name="draft_id" value="<?php echo $esc($draft_id); ?>">
                    <p class="mt-3 text-muted">Keep the entries useful for your customers. Uncheck any you do not want to import, then review their facts and scope. Selected sources are saved as Approved and available for AI evaluation.</p>
                    <?php foreach($draft['entries'] as $i=>$entry) { $prefix='entries['.$i.']'; $included=!isset($selected_entries)||in_array($i,$selected_entries,true); ?><div class="border rounded p-4 mb-4 faq-source-entry"><h6><label class="mb-0"><input type="checkbox" class="faq-source-include" name="selected_entries[]" value="<?php echo $i; ?>" <?php echo $included?'checked':''; ?>> Import entry <?php echo $i+1; ?></label></h6><fieldset <?php echo $included?'':'disabled'; ?>><div class="row">
                        <?php foreach(array('Title'=>'Title','Topic'=>'Topic (e.g. extra_bed)','ResortName'=>'Resort / hotel','RoomType'=>'Room type') as $field=>$label) { ?><div class="col-md-6 form-group"><label><?php echo $label; ?></label><input class="form-control" name="<?php echo $prefix.'['.$field.']'; ?>" value="<?php echo $esc($entry[$field]); ?>" maxlength="<?php echo $field==='Topic'?80:255; ?>" <?php echo in_array($field,array('Title','Topic'),true)?'required':''; ?> <?php echo $field==='Topic'?'pattern="[a-z][a-z0-9_]*"':''; ?>></div><?php } ?>
                        <div class="col-md-6 form-group"><label>Package</label><select class="form-control" name="<?php echo $prefix; ?>[ProductID]"><option value="0">No package restriction</option><?php foreach($products as $p) { ?><option value="<?php echo (int)$p->ProductID; ?>" <?php echo (int)$entry['ProductID']===(int)$p->ProductID?'selected':''; ?>><?php echo $esc($p->Name); ?></option><?php } ?></select></div>
                        <?php foreach(array('ValidFrom'=>'Valid from','ValidTo'=>'Valid until','ReviewDue'=>'Review due') as $field=>$label) { ?><div class="col-md-6 form-group"><label><?php echo $label; ?></label><input type="date" class="form-control" name="<?php echo $prefix.'['.$field.']'; ?>" value="<?php echo $esc($entry[$field]); ?>"></div><?php } ?>
                    </div><div class="form-group"><label>Policy wording / knowledge content</label><textarea class="form-control" rows="8" name="<?php echo $prefix; ?>[Excerpt]" maxlength="60000" required><?php echo $esc($entry['Excerpt']); ?></textarea></div>
                    <?php if(!empty($entry['SourceUrl'])) { ?><p class="text-muted">Retrieved from <?php echo $esc($entry['SourceUrl']); ?></p><?php } ?>
                    <label><input type="checkbox" name="<?php echo $prefix; ?>[AppliesToAllRooms]" value="1" <?php echo !empty($entry['AppliesToAllRooms'])?'checked':''; ?>> The wording explicitly applies to all rooms within this resort/package.</label></fieldset>
                    <?php if(!empty($entry['EvidenceQuotes'])) { ?><details class="mt-2"><summary class="text-muted">Supporting source wording</summary><?php foreach($entry['EvidenceQuotes'] as $quote) { ?><blockquote class="small border-left pl-3 mt-2 mb-2" style="white-space: pre-wrap;"><?php echo $esc($quote['quote']); ?></blockquote><?php } ?></details><?php } ?>
                    </div><?php } ?>
                    <a class="btn btn-light mr-2" href="<?php echo base_url('Faq_Suggestion/Add_Source'); ?>">Start Again</a><button class="btn btn-primary" id="faq-source-save">Save <?php echo isset($selected_entries)?count($selected_entries):count($draft['entries']); ?> Source(s)</button>
                <?php } ?>
            <?php } ?>
        </form>
        <p id="faq-source-progress" class="text-muted mt-3 mb-0 d-none" role="status">AI is extracting source entries. This may take a moment.</p>
    </div></div>
</div></div>
<script>
function updateSourceSelection() {
    var count=$('.faq-source-include:checked').length;
    $('.faq-source-include').each(function() { $(this).closest('.faq-source-entry').find('fieldset').prop('disabled',!this.checked); });
    $('#faq-source-save').prop('disabled',count===0).text('Save '+count+' Source(s)');
}
$('.faq-source-include').on('change',updateSourceSelection);
updateSourceSelection();
$('#faq-source-wizard').on('submit',function() {
    if ($(this).find('[name="stage"]').val()==='import' && $(this).find('[name="type"]').val()!=='manual') { $('#faq-source-progress').removeClass('d-none'); }
    $(this).find('button[type="submit"],button:not([type])').addClass('disabled');
});
</script>
