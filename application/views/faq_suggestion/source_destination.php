<?php $destination_escape=function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}; ?>
<label for="<?php echo $destination_escape($field_id); ?>">Destination</label>
<select id="<?php echo $destination_escape($field_id); ?>" name="<?php echo $destination_escape($field_name); ?>" class="form-control selectpicker" data-live-search="true" data-live-search-normalize="true">
    <option value="0">No destination restriction</option>
    <?php foreach($destinations as $destination) { ?><option value="<?php echo (int)$destination->CategoryID; ?>" <?php echo (int)$destination_id===(int)$destination->CategoryID?'selected':''; ?>><?php echo $destination_escape($destination->Name); ?></option><?php } ?>
</select>
