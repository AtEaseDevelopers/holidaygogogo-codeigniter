<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Uploaded sources are extracted by a CLI worker, never by a long web request. */
class Faq_Knowledge_Import_Model extends CI_Model
{
    function Queue($type, $name, $stored, $url, $destination)
    {
        if (!$this->db->table_exists('faq_knowledge_imports')) { throw new Exception('Apply the FAQ database update before importing sources.'); }
        $staff=(int)$this->session->userdata('admin_id');
        if (!$staff || !in_array($type,array('pdf','document','csv','url'),true)) { throw new Exception('Invalid source import.'); }
        if ($type==='url') {
            if (!is_string($url) || strlen($url)>2048 || !filter_var($url,FILTER_VALIDATE_URL)) { throw new Exception('Enter a valid source URL.'); }
        } elseif (!is_string($stored) || !preg_match('/^[a-zA-Z0-9_-]+\.(pdf|csv|docx|txt)$/D',$stored)) { throw new Exception('Invalid source filename.'); }
        $id=bin2hex(random_bytes(32));
        if (!$this->db->insert('faq_knowledge_imports',array('ImportID'=>$id,'SourceType'=>$type,
            'FileName'=>mb_strcut((string)$name,0,255,'UTF-8'),'StoredName'=>$stored,'SourceUrl'=>$url,
            'DestinationID'=>$destination,'InsertBy'=>$staff,'InsertDate'=>date('Y-m-d H:i:s')))) { throw new Exception('Could not queue the source import.'); }
        return $id;
    }

    function Read($id, $staff=null)
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D',$id)) { return null; }
        $this->db->where('ImportID',$id);
        if ($staff!==null) { $this->db->where('InsertBy',(int)$staff); }
        $job=$this->db->get('faq_knowledge_imports')->row();
        if ($job && (($job->State==='queued' && strtotime($job->InsertDate)<time()-120) ||
            ($job->State==='running' && strtotime($job->StartedDate)<time()-900))) {
            $this->Fail($id,'Source processing stopped before completion. Please upload the source again.',$job->State);
            // Re-read in case the worker completed or started as the status was read.
            $job=$this->db->where('ImportID',$id)->get('faq_knowledge_imports')->row();
        }
        return $job;
    }

    function Fail($id, $message, $state=null)
    {
        if ($state!==null) { $this->db->where('State',$state); }
        $this->db->where('ImportID',$id)->where_in('State',array('queued','running'))
            ->update('faq_knowledge_imports',array('State'=>'error','ErrorMessage'=>mb_strcut($message,0,4000,'UTF-8'),'FinishedDate'=>date('Y-m-d H:i:s')));
    }

    function Process($id)
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D',$id)) { return; }
        // Only one worker may claim the upload, even if a job is dispatched twice.
        $this->db->where('ImportID',$id)->where('State','queued')->update('faq_knowledge_imports',array('State'=>'running','StartedDate'=>date('Y-m-d H:i:s')));
        if ($this->db->affected_rows()!==1) { return; }
        $transaction=false;
        try {
            $job=$this->Read($id);
            $this->load->model('Faq_Knowledge_Source_Model');
            $this->load->model('Faq_Workspace_Model');
            $this->load->library('FaqSourceImport');
            $this->load->library('FaqSuggestionService');
            $this->load->helper('faq_source_import');
            $path=FCPATH.Faq_Knowledge_Source_Model::UPLOAD_DIR.$job->StoredName;
            if ($job->SourceType==='pdf') {
                $result=$this->faqsuggestionservice->extract_knowledge_pdf($path,$job->FileName);
                $parsed=json_decode($result['raw'],true);
                if (!is_array($parsed) || !isset($parsed['text']) || !is_string($parsed['text']) || trim($parsed['text'])==='') { throw new Exception('Could not extract readable policy wording from this PDF.'); }
                $title=is_string($parsed['title']??null)?trim($parsed['title']):'';
                $entries=array(array('SourceType'=>'pdf','Title'=>mb_strcut($title!==''?$title:pathinfo($job->FileName,PATHINFO_FILENAME),0,255,'UTF-8'),
                    'SourceUrl'=>'','StoredName'=>$job->StoredName,'Excerpt'=>mb_strcut($parsed['text'],0,60000,'UTF-8'),'ExtractedText'=>$parsed['text'],
                    'ProductID'=>'0','ResortName'=>'','RoomType'=>'','Topic'=>'general_information','ValidFrom'=>'','ValidTo'=>'','ReviewDue'=>''));
            } else {
                $title=$job->FileName; $metadata=array('stored'=>$job->StoredName);
                if ($job->SourceType==='document') { $records=array('D1'=>$this->faqsourceimport->Document($path)); }
                elseif ($job->SourceType==='csv') { $records=faq_source_ai_csv_records($this->faqsourceimport->CSV($path)); }
                else {
                    $read=$this->faqsourceimport->Read_URL($job->SourceUrl); $title=$read['title'];
                    $records=array('U1'=>$read['text']); $metadata=array('url'=>$read['url'],'retrieved'=>date('Y-m-d H:i:s'));
                }
                $products=$this->Faq_Workspace_Model->Products(); $metadata['title']=$title;
                faq_source_ai_build_prompt($job->SourceType,$title,$records,$products);
                $result=$this->faqsuggestionservice->extract_knowledge_text($job->SourceType,$title,$records,$products);
                $entries=faq_source_ai_parse_entries($result['raw'],$job->SourceType,$records,$products,$metadata);
            }
            foreach ($entries as &$entry) { $entry['DestinationID']=$job->DestinationID??0; } unset($entry);
            // Saving sources and marking completion commit together. A terminated worker
            // cannot leave saved sources behind with a retryable/incomplete status.
            $this->db->trans_begin(); $transaction=true;
            $count=$this->Faq_Knowledge_Source_Model->Create_Import(faq_source_unique_entries($entries),(int)$job->InsertBy);
            $this->db->where('ImportID',$id)->where('State','running')->update('faq_knowledge_imports',
                array('State'=>'done','SourceCount'=>$count,'FinishedDate'=>date('Y-m-d H:i:s')));
            if ($this->db->affected_rows()!==1 || !$this->db->trans_status()) { throw new Exception('Could not finish the source import.'); }
            $this->db->trans_commit(); $transaction=false;
        } catch (Throwable $e) {
            if ($transaction) { $this->db->trans_rollback(); }
            $this->Fail($id,$e->getMessage());
            log_message('error','FAQ knowledge import '.$id.': '.$e->getMessage());
        }
    }
}
