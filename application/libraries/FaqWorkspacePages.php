<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Shared rendering for the section-based FAQ page; mutations stay in their guarded controller. */
class FaqWorkspacePages
{
    protected $CI;
    function __construct()
    {
        $this->CI=&get_instance();
        $this->CI->load->helper('faq_suggestion');
        $this->CI->load->model('Faq_Workspace_Model');
        $this->CI->load->model('Faq_Suggestion_Model');
        if (!$this->CI->session->userdata('faq_workspace_csrf')) {
            $this->CI->session->set_userdata('faq_workspace_csrf',bin2hex(random_bytes(32)));
        }
    }

    function Workspace()
    {
        $ci=$this->CI;
        if ((int)$ci->session->level!==10 && !in_array('FV',(array)$ci->session->access_control,true)) { show_error('FAQ view access required.',403); return; }
        $ci->Faq_Workspace_Model->Reconcile_Open_Candidates();
        $tab=$ci->input->get('tab'); $filters=faq_workspace_filters();
        $aliases=array('new'=>'pending','ready'=>'pending','input'=>'context','source'=>'context','completed'=>'all');
        if (is_string($tab) && isset($aliases[$tab])) { $tab=$aliases[$tab]; }
        $tab=is_string($tab)&&isset($filters[$tab])?$tab:'all';
        $search=$ci->input->get('search'); $search=is_string($search)?mb_substr(trim($search),0,200):'';
        $page=$ci->input->get('page'); $page=is_scalar($page)?max(1,(int)$page):1;
        $run=$ci->input->get('run_id'); $run=$run==='latest20'?'latest20':(is_scalar($run)?max(0,(int)$run):0);
        $generation_runs=$ci->Faq_Suggestion_Model->Read_Recent_Generation_Runs(20);
        $recent_ids=array_map(function($r){return (int)$r->RunID;},$generation_runs);
        $run_filter=$run==='latest20'?$recent_ids:$run;
        // Links from Generation History may select a run older than the dropdown's recent window.
        if (is_int($run) && $run>0 && !in_array($run,$recent_ids,true)) {
            $selected=$ci->Faq_Suggestion_Model->Read_Run($run);
            if ($selected) { $generation_runs[]=$selected; }
        }
        $listing=$ci->Faq_Workspace_Model->Suggestion_List($tab,$search,$page,$run_filter,$ci->input->get('page_size'));
        $data=$listing+array('workspace_tab'=>$tab,'search'=>$search,'run_id'=>$run,'generation_runs'=>$generation_runs,
            'workspace_counts'=>$ci->Faq_Workspace_Model->Suggestion_Counts($search,$run_filter),
            'can_edit'=>(int)$ci->session->level===10 || in_array('FE',(array)$ci->session->access_control,true));
        $ci->load->view('layout/header',array('tab_title'=>'HolidayGoGoGo | AI Suggestion','breadcrumb_title'=>'FAQ >> AI Suggestion'));
        $ci->load->view('faq_suggestion/index',$data); $ci->load->view('layout/footer');
    }

    function History()
    {
        $ci=$this->CI;
        if ((int)$ci->session->level!==10 && !in_array('FV',(array)$ci->session->access_control,true)) { show_error('FAQ view access required.',403); return; }
        $data=array('runs'=>$ci->Faq_Suggestion_Model->Read_Runs(),
            'can_edit'=>(int)$ci->session->level===10 || in_array('FE',(array)$ci->session->access_control,true));
        $ci->load->view('layout/header',array('tab_title'=>'FAQ | Generation History','breadcrumb_title'=>'FAQ >> Generation History'));
        $ci->load->view('faq_suggestion/history',$data); $ci->load->view('layout/footer');
    }

    function Sources()
    {
        $ci=$this->CI;
        if (!faq_workspace_can_manage_sources($ci->session)) { show_error('Knowledge Sources requires source manager access.',403); return; }
        $ci->load->model('Faq_Knowledge_Source_Model');
        $page=$ci->input->get('page'); $page=is_scalar($page)?max(1,(int)$page):1;
        $batch=$ci->input->get('batch_id'); $batch=is_scalar($batch)?$batch:0;
        $data=$ci->Faq_Knowledge_Source_Model->Listing($page,$batch,$ci->input->get('page_size'),$ci->input->get('search'));
        $ci->load->view('layout/header',array('tab_title'=>'FAQ | Knowledge Sources','breadcrumb_title'=>'FAQ >> Knowledge Sources'));
        $ci->load->view('faq_suggestion/sources',$data); $ci->load->view('layout/footer');
    }
}
