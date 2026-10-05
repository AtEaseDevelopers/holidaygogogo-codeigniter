<?php $active_section=isset($active_section)?$active_section:'faq'; if ($active_section==='workspace') { $active_section='suggestions'; } ?>
<nav class="nav nav-pills bg-white rounded p-3 mb-5" aria-label="FAQ sections">
    <?php foreach(array('faq'=>'FAQs','suggestions'=>'AI Suggestion','sources'=>'Knowledge Source','history'=>'Generation History') as $key=>$label) {
        if($key==='sources'&&!faq_workspace_can_manage_sources($this->session)) { continue; } ?>
        <a class="nav-link font-weight-bold <?php echo $active_section===$key?'active':''; ?>" href="<?php echo base_url('Faq?section=').$key; ?>" <?php echo $active_section===$key?'aria-current="page"':''; ?>><?php echo $label; ?></a>
    <?php } ?>
</nav>
