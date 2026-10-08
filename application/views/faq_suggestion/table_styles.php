<style>
    .faq-table-container { container-type:inline-size; container-name:faq-suggestions; }
    #faq-suggestions-table { min-width:780px; border-color:#e7ebf1; }
    #faq-suggestions-table td { vertical-align:top; padding:18px 14px; border-left:0; border-right:0; border-color:#e7ebf1; }
    #faq-suggestions-table th { background:#f7f9fc; border-color:#e7ebf1; }
    #faq-suggestions-table tbody tr:nth-child(even) { background:#fcfdff; }
    #faq-suggestions-table .faq-question-cell { min-width:320px; }
    #faq-suggestions-table .faq-number-column { width:1%; white-space:nowrap; color:#7e8299; }
    #faq-suggestions-table .faq-question-meta { color:#7e8299; font-size:11px; margin-bottom:7px; }
    #faq-suggestions-table .faq-question-title { display:block; border:0; padding:0; background:none; color:#26354b; font:inherit; font-weight:600; line-height:1.5; text-align:left; }
    #faq-suggestions-table a.faq-question-title:hover { color:#3699ff; }
    #faq-suggestions-table .faq-answer-panel { margin-top:10px; padding:10px 14px; background:#f5f7fa; border-left:3px solid #dce5f2; border-radius:0 6px 6px 0; }
    #faq-suggestions-table .faq-answer-part + .faq-answer-part { margin-top:18px; padding-top:16px; border-top:1px solid #e0e6ee; }
    #faq-suggestions-table .faq-answer-part h6 { font:inherit; font-weight:600; line-height:1.5; margin-bottom:8px; }
    #faq-suggestions-table .label.label-inline { height:auto; min-height:24px; white-space:normal; line-height:1.4; padding-top:4px; padding-bottom:4px; }
    #faq-suggestions-table .faq-status { cursor:help; }
    #faq-suggestions-table .faq-answer { color:#000; font-size:inherit; line-height:1.7; white-space:pre-wrap; overflow-wrap:anywhere; }
    #faq-suggestions-table .faq-actions { white-space:nowrap; text-align:center; }
    #faq-workspace #faq-suggestions-table { min-width:1100px; }
    #faq-suggestions-table .faq-generation-cell { min-width:220px; color:#000; white-space:normal; overflow-wrap:anywhere; }
    #faq-suggestions-table .faq-created-cell { white-space:nowrap; }
    @media (min-width:1024px) {
        #faq-suggestions-table { table-layout:fixed; width:100%; }
        #faq-suggestions-table th, #faq-suggestions-table td { overflow-wrap:anywhere; }
        #faq-suggestions-table .faq-question-cell { width:40%; }
        #faq-suggestions-table .faq-select-column { width:44px; }
        #faq-suggestions-table .faq-number-column { width:48px; }
        #faq-suggestions-table .faq-created-cell { width:124px; }
        #faq-suggestions-table .faq-cost-column { width:90px; }
        #faq-suggestions-table .faq-actions { width:76px; }
        #faq-suggestions-table .faq-generation-cell { min-width:0; }
    }
    @container faq-suggestions (max-width:1049px) {
        #faq-suggestions-table .faq-number-column { display:none; }
    }
    @media (max-width:767.98px) {
        #faq-suggestions-table .faq-number-column { display:none; }
    }
</style>
