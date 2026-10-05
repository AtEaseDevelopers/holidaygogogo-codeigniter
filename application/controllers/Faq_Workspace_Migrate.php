<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Apply only FAQ workspace patches, without running unrelated pending migrations. */
class Faq_Workspace_Migrate extends CI_Controller
{
    function index($mode = 'check')
    {
        if (!$this->input->is_cli_request()) { show_error('CLI only.',403); return; }
        if (!in_array($mode,array('check','apply'),true)) { echo "Usage: php index.php Faq_Workspace_Migrate/index/[check|apply]\n"; return; }
        $files=array('20261002_Create_Faq_Knowledge_Sources.sql','20261003_Add_Faq_Source_Import_Types.sql','20261003_Extend_Faq_Workspace.sql','20261005_Add_Faq_Knowledge_Source_Batches.sql','20261005_Default_Faq_Knowledge_Sources_Approved.sql');
        echo 'FAQ schema on '.$this->db->hostname.' / '.$this->db->database."\n";
        foreach ($files as $file) {
            $recorded=$this->db->table_exists('migrations') && $this->db->where('migration',$file)->get('migrations')->row();
            if ($recorded) { echo 'Already applied: '.$file."\n"; continue; }
            if ($mode==='check') { echo 'Pending: '.$file."\n"; continue; }
            $debug=$this->db->db_debug; $this->db->db_debug=false;
            foreach (array_filter(array_map('trim',explode(';',file_get_contents(APPPATH.'sql/'.$file)))) as $sql) {
                if (!$this->db->query($sql)) {
                    $error=$this->db->error();
                    if (!in_array((int)$error['code'],array(1060,1061,1091,1826),true)) {
                        $this->db->db_debug=$debug;
                        fwrite(STDERR,'FAILED: '.$file.' — '.$error['message']."\n"); exit(1);
                    }
                }
            }
            $this->db->db_debug=$debug;
            if ($this->db->table_exists('migrations')) { $this->db->insert('migrations',array('migration'=>$file)); }
            echo 'Applied: '.$file."\n";
        }
        $ready=true;
        foreach (array('faq_suggestions'=>array('ReviewReason','DraftSourcesJson','DraftHash'),
            'faq_knowledge_sources'=>array('SourceID','BatchID','Excerpt','ExtractedText','FilePath','RetrievedDate','ReviewDue','AppliesToAllRooms'),
            'faq_knowledge_source_batch_sequence'=>array('SequenceID','LastBatchID'),
            'faq_workspace_audit'=>array('BeforeJson','AfterJson')) as $table=>$fields) {
            foreach ($fields as $field) { if (!$this->db->field_exists($field,$table)) { echo 'Missing: '.$table.'.'.$field."\n"; $ready=false; } }
        }
        foreach (array('faq','faq_suggestions') as $table) {
            $column=$this->db->query('SHOW COLUMNS FROM `'.$table.'` LIKE ?',array('Description'))->row();
            if (!$column || !in_array(strtolower($column->Type),array('mediumtext','longtext'),true)) { echo 'Missing capacity: '.$table.'.Description must be MEDIUMTEXT' . "\n"; $ready=false; }
        }
        $source_status=$this->db->query('SHOW COLUMNS FROM faq_knowledge_sources LIKE ?',array('Status'))->row();
        if (!$source_status || $source_status->Default!=='approved') { echo "Missing default: faq_knowledge_sources.Status must default to approved\n"; $ready=false; }
        echo 'Workspace schema ready: '.($ready?'yes':'no')."\n";
    }
}
