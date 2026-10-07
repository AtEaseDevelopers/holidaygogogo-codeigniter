<?php
/**
 * php tests/integration/FaqKnowledgeImportTest.php [existing-upload.pdf]
 * Uses the configured MySQL connection with TEMPORARY copies of every table
 * it writes. No existing source, audit entry, batch or import is changed.
 * AI responses are stubbed; the optional filename uses a real uploaded PDF.
 */
$uploaded=$argv[1]??null;
chdir(dirname(__DIR__,2));
$_SERVER['argv']=array('index.php','Faq_Knowledge_Import_Job','run');
require 'index.php';
$ci=&get_instance();
$ci->load->model('Faq_Knowledge_Import_Model');
$ci->load->model('Faq_Knowledge_Source_Model');
$ci->load->model('Faq_Workspace_Model');
$ci->load->library('FaqSuggestionService');
$ci->load->helper('faq_source_import');

class ImportTestSession
{
    function userdata($key) { return $key==='admin_id'?1:null; }
}
class ImportTestAI extends FaqSuggestionService
{
    public $calls=0;
    public $mode='valid';
    public $beforeReply;
    function __construct() {}
    function extract_knowledge_pdf($path, $name)
    {
        $this->calls++;
        if (!is_file($path)) { throw new RuntimeException('PDF missing'); }
        if ($this->beforeReply) { ($this->beforeReply)(); }
        if ($this->mode==='failure') { throw new RuntimeException('Extraction service unavailable'); }
        return array('raw'=>json_encode(array('title'=>'Redang full board package','text'=>$this->mode==='empty'?'':'Breakfast, lunch and dinner included. [Page 1]')));
    }
    function extract_knowledge_text($type, $title, $records, $products=array())
    {
        $this->calls++;
        $ref=array_key_first($records); $text=$records[$ref];
        $entry=faq_source_ai_example();
        $entry['Title']='Meals included'; $entry['Excerpt']='Breakfast is included.';
        $entry['Topic']='breakfast'; $entry['customer_question']='Is breakfast included?';
        $entry['source_refs']=array($ref); $entry['evidence_quotes']=array(array('reference'=>$ref,'quote'=>'Breakfast is included.'));
        return array('raw'=>json_encode(array('entries'=>array($entry))));
    }
}
function expect($ok, $label)
{
    if (!$ok) { throw new RuntimeException('FAIL: '.$label); }
    echo 'PASS: '.$label.PHP_EOL;
}

$fixture=false; $document=null;
try {
    foreach (array('faq_knowledge_imports','faq_knowledge_sources','faq_knowledge_source_batch_sequence','faq_workspace_audit') as $table) {
        $definition=$ci->db->query('SHOW CREATE TABLE `'.$table.'`')->row_array()['Create Table'];
        $ci->db->query(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$definition));
    }
    $ci->db->insert('faq_knowledge_source_batch_sequence',array('SequenceID'=>1,'LastBatchID'=>0));
    $ci->session=new ImportTestSession();
    $ai=new ImportTestAI(); $ci->faqsuggestionservice=$ai;
    if ($uploaded===null) {
        $uploaded='import_test_'.bin2hex(random_bytes(8)).'.pdf'; $fixture=true;
        file_put_contents(FCPATH.Faq_Knowledge_Source_Model::UPLOAD_DIR.$uploaded,"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    }
    expect(preg_match('/^[a-zA-Z0-9_-]+\.pdf$/D',$uploaded) && is_file(FCPATH.Faq_Knowledge_Source_Model::UPLOAD_DIR.$uploaded),'test PDF exists');
    $destination=$ci->db->where('IsDestination','YES')->where('Status','Y')->get('category')->row();
    $destination=$destination?(int)$destination->CategoryID:0;
    $jobs=$ci->Faq_Knowledge_Import_Model;
    $id=$jobs->Queue('pdf','Redang.pdf',$uploaded,null,$destination?:null);
    expect($jobs->Read($id,1)->State==='queued' && $ai->calls===0,'upload queues without making a slow AI request');
    expect($jobs->Read($id,2)===null && $jobs->Read('../invalid')===null,'import belongs to its uploader and invalid IDs are rejected');
    $ai->beforeReply=function() use ($jobs,$id) {
        expect($jobs->Read($id,1)->State==='running','status remains available during extraction');
        $jobs->Process($id); // A second worker cannot claim an already-running job.
    };
    $jobs->Process($id); $ai->beforeReply=null;
    expect($jobs->Read($id,1)->State==='done' && (int)$jobs->Read($id,1)->SourceCount===1,'successful extraction saves and finishes');
    $source=$ci->db->get('faq_knowledge_sources')->row();
    expect($source->Status==='approved' && (int)$source->InsertBy===1 && (int)$source->VerifiedBy===1 && (int)$source->DestinationID===$destination,'worker preserves automatic approval, staff attribution and destination');
    expect((int)$ci->db->get('faq_workspace_audit')->row()->InsertBy===1,'worker audit is attributed to uploader');
    $jobs->Process($id);
    expect($ai->calls===1 && $ci->db->count_all('faq_knowledge_sources')===1,'duplicate dispatch cannot extract or import twice');
    $jobs->Fail($id,'Late worker failure');
    expect($jobs->Read($id,1)->State==='done','completed import cannot be overwritten by an error');

    foreach (array('failure','empty') as $mode) {
        $ai->mode=$mode;
        $failed=$jobs->Queue('pdf','Redang.pdf',$uploaded,null,null); $jobs->Process($failed);
        expect($jobs->Read($failed,1)->State==='error' && $ci->db->count_all('faq_knowledge_sources')===1,$mode.' extraction reports an error without adding sources');
    }
    $ai->mode='valid';
    $interrupted=$jobs->Queue('pdf','Redang.pdf',$uploaded,null,null);
    $ai->beforeReply=function() use ($jobs,$interrupted) { $jobs->Fail($interrupted,'Stopped'); };
    $jobs->Process($interrupted); $ai->beforeReply=null;
    expect($jobs->Read($interrupted,1)->State==='error' && $ci->db->count_all('faq_knowledge_sources')===1,'completion failure rolls back sources and batch allocation');
    expect((int)$ci->db->get('faq_knowledge_source_batch_sequence')->row()->LastBatchID===1,'failed import leaves batch counter intact');

    $stale=$jobs->Queue('pdf','Redang.pdf',$uploaded,null,null);
    $ci->db->where('ImportID',$stale)->update('faq_knowledge_imports',array('InsertDate'=>date('Y-m-d H:i:s',time()-180)));
    expect($jobs->Read($stale,1)->State==='error','worker startup failure does not poll forever');
    $stale=$jobs->Queue('pdf','Redang.pdf',$uploaded,null,null);
    $ci->db->where('ImportID',$stale)->update('faq_knowledge_imports',array('State'=>'running','StartedDate'=>date('Y-m-d H:i:s',time()-1000)));
    expect($jobs->Read($stale,1)->State==='error','interrupted running worker does not poll forever');

    $document='import_test_'.bin2hex(random_bytes(8)).'.txt';
    file_put_contents(FCPATH.Faq_Knowledge_Source_Model::UPLOAD_DIR.$document,'Breakfast is included.');
    $id=$jobs->Queue('document','Meals.txt',$document,null,$destination?:null); $jobs->Process($id);
    expect($jobs->Read($id,1)->State==='done' && $ci->db->count_all('faq_knowledge_sources')===2,'text document extraction still saves through the background flow');
    echo 'All knowledge import checks passed.'.PHP_EOL;
} finally {
    if ($fixture) { unlink(FCPATH.Faq_Knowledge_Source_Model::UPLOAD_DIR.$uploaded); }
    if ($document) { unlink(FCPATH.Faq_Knowledge_Source_Model::UPLOAD_DIR.$document); }
}
