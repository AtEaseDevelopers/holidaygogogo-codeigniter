<?php
$escape=function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};
$selection=$selection??array('sources'=>array()); $sources=$selection['sources'];
$historical=!empty($historical); $heading=$historical?'Sources supplied at the last AI assessment':'Matching Knowledge Sources';
?>
<details class="border rounded p-3 mt-3 faq-knowledge-selection" <?php echo !empty($open)?'open':''; ?>>
    <summary class="font-weight-bold" style="font-size:13px;"><?php echo $heading; ?> · <?php echo count($sources); ?> source(s)<?php if(!empty($selection['when'])) { ?> <span class="small text-muted ml-2"><?php echo $escape($selection['when']); ?></span><?php } ?></summary>
    <?php if(!$historical) { ?><p class="small text-muted mt-2 mb-2">Approved sources matched to the question and its scope. Refresh after changing the question or additional information. Evaluation checks the matches again.</p><?php } ?>
    <?php if(!$sources) { ?><p class="small text-muted mb-0 mt-2">No approved Knowledge Sources matched. Add the missing scope or information, or verify and approve an applicable source.</p><?php } else { ?>
    <div class="table-responsive mt-2"><table class="table table-sm table-bordered mb-0" style="font-size:12px;"><thead><tr><th>Source</th><th>Why selected</th><?php if($historical) { ?><th>AI citation</th><?php } ?></tr></thead><tbody>
    <?php foreach($sources as $source) { ?><tr><td>
        <?php if(!empty($can_manage_sources)) { ?><a href="<?php echo base_url('Faq_Suggestion/Source_Detail?id=').(int)$source['source_id']; ?>" target="_blank" rel="noopener"><?php echo $escape($source['reference'].' · '.$source['title']); ?></a><?php } else { echo $escape($source['reference'].' · '.$source['title']); } ?>
        <details class="mt-1"><summary class="text-muted">Source facts</summary><div class="mt-1" style="white-space:pre-wrap;"><?php echo $escape($source['excerpt']); ?></div></details>
    </td><td><?php echo $escape($source['selection_reason']); ?></td>
    <?php if($historical) { ?><td><?php echo in_array($source['reference'],$selection['cited_refs']??array(),true)?(in_array($source['reference'],$selection['accepted_refs']??array(),true)?'Used':'Cited; applicability check failed'):'Not cited'; ?></td><?php } ?></tr><?php } ?>
    </tbody></table></div><?php } ?>
</details>
