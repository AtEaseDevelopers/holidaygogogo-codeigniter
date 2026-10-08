<?php
$esc=function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};
$answer_items=Faq_Model::Decode_Items($suggestion->Description);
$question_title=trim((string)($answer_items[0]['q']??'')) ?: $suggestion->Title;
?>
<?php if(count($answer_items)>1) { ?><div class="faq-question-meta"><?php echo count($answer_items); ?> questions</div><?php } ?>
<a class="faq-question-title" href="<?php echo $esc(faq_workspace_suggestion_url($suggestion->SuggestionID,$return_filters??'')); ?>"><?php echo $esc($question_title); ?></a>
<?php if($answer_items) { ?><div class="faq-answer-panel">
    <div class="faq-full-answer" id="faq-full-answer-<?php echo (int)$suggestion->SuggestionID; ?>">
        <?php foreach($answer_items as $item_index=>$item) { ?><section class="faq-answer-part">
            <?php if($item_index>0&&trim($item['q'])!=='') { ?><h6><?php echo $esc($item['q']); ?></h6><?php } ?>
            <div class="faq-answer"><?php echo $esc($item['a']?:'No answer drafted yet.'); ?></div>
        </section><?php } ?>
    </div>
</div><?php } else { ?><p class="text-muted mt-2 mb-0">No answer drafted yet.</p><?php } ?>
