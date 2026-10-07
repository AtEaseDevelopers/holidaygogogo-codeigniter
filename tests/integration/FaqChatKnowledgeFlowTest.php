<?php
/**
 * php tests/integration/FaqChatKnowledgeFlowTest.php
 * Exercises generation, assessment, previews and re-evaluation with in-memory
 * persistence and a captured AI transport. No database or network connection.
 */
define('BASEPATH', __DIR__);
define('FCPATH', dirname(__DIR__,2).'/');
function get_env($key) { return ''; }
class CI_Model
{
    function __construct() {}
    function __get($key) { global $context; return $context->$key; }
}
require_once FCPATH.'application/helpers/faq_suggestion_helper.php';
require_once FCPATH.'application/models/Faq_Model.php';
require_once FCPATH.'application/models/Faq_Suggestion_Model.php';
require_once FCPATH.'application/models/Faq_Workspace_Model.php';
require_once FCPATH.'application/libraries/FaqSuggestionService.php';

function expect($condition, $label)
{
    if (!$condition) { fwrite(STDERR,'FAIL '.$label.PHP_EOL); exit(1); }
    echo 'PASS '.$label.PHP_EOL;
}
class FlowRows
{
    private $rows;
    function __construct($rows) { $this->rows=array_values($rows); }
    function row() { return $this->rows?(object)$this->rows[0]:null; }
    function result() { return array_map(function($r){return (object)$r;},$this->rows); }
    function result_array() { return $this->rows; }
}
class FlowDB
{
    public $tables=array(), $queries=array();
    private $filters=array();
    function select($columns) { return $this; }
    function distinct() { return $this; }
    function order_by($field, $order) { return $this; }
    function where($field, $value) { return $this->where_in($field,array($value)); }
    function where_in($field, $values) { $this->filters[$field]=$values; return $this; }
    private function matches($row)
    {
        foreach ($this->filters as $field=>$values) { if (!in_array($row[$field]??null,$values,true)) { return false; } }
        return true;
    }
    function get($table)
    {
        $this->queries[]=array('table'=>$table,'filters'=>$this->filters);
        $rows=array_filter($this->tables[$table]??array(),function($r){return $this->matches($r);});
        $this->filters=array(); return new FlowRows($rows);
    }
    function query($sql, $params)
    {
        if (strpos($sql,'SELECT * FROM faq_suggestions')!==0) { throw new RuntimeException('Unexpected SQL in test'); }
        return new FlowRows(isset($this->tables['faq_suggestions'][$params[0]])?array($this->tables['faq_suggestions'][$params[0]]):array());
    }
    function update($table, $data)
    {
        foreach ($this->tables[$table] as &$row) { if ($this->matches($row)) { $row=array_replace($row,$data); } }
        unset($row); $this->filters=array(); return true;
    }
    function insert($table, $data) { $this->tables[$table][]=$data; return true; }
    function trans_begin() {}
    function trans_commit() {}
    function trans_rollback() { throw new RuntimeException('Unexpected assessment rollback'); }
    function trans_status() { return true; }
}
class FlowLoader
{
    function helper($name) {}
    function model($name) {}
    function library($name) {}
}
class FlowSession { function userdata($name) { return $name==='admin_id'?1:null; } }
class FlowAI extends FaqSuggestionService
{
    public $requests=array(), $reply;
    function __construct() {}
    protected function request($instructions, $input, $format=null, $feature='FAQ Suggestions')
    {
        $this->requests[]=array('instructions'=>$instructions,'input'=>$input);
        return json_encode($this->reply);
    }
    protected function pack_result($raw) { return array('raw'=>$raw,'model'=>'test','cost_usd'=>0); }
}
class FlowSuggestions extends Faq_Suggestion_Model
{
    public $runs=array(), $messages=array();
    private $next=0;
    function Read_Run($id) { return isset($this->runs[$id])?(object)$this->runs[$id]:null; }
    function Update_Run($id, $data) { $this->runs[$id]=array_replace($this->runs[$id],$data); }
    function Create_Run($data) { $id=count($this->runs)+1; $this->runs[$id]=$data; return $id; }
    function Destination_Name_Map() { return array(10=>'Redang',20=>'Tioman'); }
    function Existing_Faqs() { return array(); }
    function Read($id) { return isset($this->db->tables['faq_suggestions'][$id])?(object)$this->db->tables['faq_suggestions'][$id]:null; }
    function Create($data)
    {
        $id=++$this->next;
        $this->db->tables['faq_suggestions'][$id]=$data+array('SuggestionID'=>$id,'Status'=>'Y','DraftHash'=>null,
            'DraftSourcesJson'=>null,'UpdateDate'=>null);
        return $id;
    }
    function Read_Evidence($id)
    {
        return array_map(function($r){return (object)$r;},array_values(array_filter($this->db->tables['faq_suggestion_sources']??array(),function($r)use($id){return $r['SuggestionID']===$id;})));
    }
    protected function max_transcript_chars() { return 10000; }
    protected function max_suggestions() { return 10; }
    protected function Recent_Ghl_Messages($start, $end, $phone_key='') { return $this->messages; }
    protected function Recent_Wa_Messages($start, $end, $phone_key='') { return array(); }
    protected function Chat_File_Messages($path, $ext)
    {
        return array_map(function($r){return array('body'=>$r['body'],'outbound'=>$r['direction']==='outbound');},$this->messages);
    }
}
class FlowWorkspace extends Faq_Workspace_Model
{
    public $audits=array();
    function __construct() {}
    function Audit($suggestion, $source, $action, $before, $after, $staff=null) { $this->audits[]=array('id'=>$suggestion,'action'=>$action,'after'=>$after); }
}
class FlowKnowledge
{
    function Read($id, $lock=false) { global $context; return (object)$context->db->tables['faq_knowledge_sources'][$id]; }
}
$context=(object)array('load'=>new FlowLoader(),'db'=>new FlowDB(),'session'=>new FlowSession(),
    'Faq_Model'=>new Faq_Model(),'Faq_Suggestion_Model'=>new FlowSuggestions(),
    'Faq_Workspace_Model'=>new FlowWorkspace(),'Faq_Knowledge_Source_Model'=>new FlowKnowledge(),
    'faqsuggestionservice'=>new FlowAI());
$db=$context->db; $suggestions=$context->Faq_Suggestion_Model; $workspace=$context->Faq_Workspace_Model; $ai=$context->faqsuggestionservice;
$db->tables['category']=array(array('CategoryID'=>10,'Name'=>'Redang','IsDestination'=>'YES','Status'=>'Y'),
    array('CategoryID'=>20,'Name'=>'Tioman','IsDestination'=>'YES','Status'=>'Y'));
$db->tables['product']=array(array('ProductID'=>1,'Name'=>'Redang Package','ProductCode'=>'R1','Status'=>'Y'));
$source=array('SourceID'=>10,'Status'=>'approved','VerifiedBy'=>1,'VerifiedDate'=>date('Y-m-d'),
    'Title'=>'Redang breakfast','Excerpt'=>'Daily breakfast is included.','DestinationID'=>10,'ProductID'=>null,
    'ResortName'=>'','RoomType'=>'','Topic'=>'breakfast','AppliesToAllRooms'=>0,
    'ValidFrom'=>null,'ValidTo'=>null,'ReviewDue'=>null,'SourceUrl'=>'','FilePath'=>'');
$db->tables['faq_knowledge_sources']=array(10=>$source,
    20=>array_replace($source,array('SourceID'=>20,'DestinationID'=>20,'Title'=>'Tioman breakfast')),
    30=>array_replace($source,array('SourceID'=>30,'DestinationID'=>null,'Title'=>'Agency breakfast')));
$suggestions->messages=array(array('id'=>1,'direction'=>'inbound','body'=>'Is breakfast included in Redang?'),
    array('id'=>2,'direction'=>'outbound','body'=>'Daily breakfast is included.'),
    array('id'=>3,'direction'=>'inbound','body'=>'Is dinner included in Redang?'));
$ready=faq_suggestion_candidate_example();
$ready['title']='Breakfast in Redang'; $ready['label']='Pending Approval'; $ready['missing_information']=array();
$ready['items']=array(array('q'=>'Is breakfast included in Redang?','a'=>'Daily breakfast is included.'));
$ready['source_refs']=array('S1'); $ready['answer_refs']=array('S2');
$missing=faq_suggestion_candidate_example(); $missing['title']='Dinner in Redang';
$missing['items']=array(array('q'=>'Is dinner included in Redang?','a'=>''));
$missing['source_refs']=array('S3'); $missing['missing_information']=array('Confirm dinner inclusion.');
$ai->reply=array('suggestions'=>array($ready,$missing));
$suggestions->runs[1]=array('Source'=>'chats','RunState'=>'queued','StartDate'=>'2026-10-01','EndDate'=>'2026-10-07','Mobile'=>'');
$result=$suggestions->Process_Run(1);
expect($result['created']===2,'first scan keeps answered and unanswered customer topics');
expect($suggestions->Read(1)->State==='draft_ready' && $suggestions->Read(2)->State==='need_context','first scan saves Pending Approval and Needs Information');
expect(Faq_Model::Decode_Items($suggestions->Read(1)->Description)[0]['a']==='Daily breakfast is included.','first scan saves the supported chat answer');
expect(strpos($ai->requests[0]['input'],'APPROVED KNOWLEDGE')===false,'first scan sends no knowledge block');
expect($ai->requests[0]['input']===$suggestions->runs[1]['InputText'],'generation history records the actual chat input');
expect(!array_filter($db->queries,function($q){return in_array($q['table'],array('faq_knowledge_sources','product'));}),'first scan and assessment do not query knowledge or packages');
$suggestions->runs[2]=array('Source'=>'chatfile','RunState'=>'queued','FileName'=>'Redang.txt','StoredName'=>'fixture.txt');
$result=$suggestions->Process_Run(2);
expect($result['created']===2 && count($ai->requests)===2,'chat exports use one initial request and preserve both review statuses');
expect(strpos($ai->requests[1]['input'],'APPROVED KNOWLEDGE')===false,'chat export initial scan sends no knowledge');

$preview=$workspace->Knowledge_For_Candidate(2,'Tioman breakfast',false);
expect($preview['input']===array(),'preview does not infer a destination from title, chats or staff text');
$reevaluated=$ready; $reevaluated['title']='Dinner in Redang'; $reevaluated['items']=array(array('q'=>'Is dinner included in Redang?','a'=>'Dinner is included.'));
$reevaluated['destinations']=array('Redang'); $reevaluated['source_refs']=array('S3'); $reevaluated['answer_refs']=array('A1');
$ai->reply=array('suggestions'=>array($reevaluated));
$workspace->Reevaluate(2,'Dinner is included in Redang.',false,faq_workspace_review_version($suggestions->Read(2)),$ai);
$input=json_decode($ai->requests[2]['input'],true);
expect($input['approved_knowledge']===array(),'re-evaluation without an attached destination sends no knowledge');
expect($suggestions->Read(2)->DestinationIds==='' && $suggestions->Read(2)->State==='draft_ready','staff information can complete an answer without AI attaching a destination');

$db->tables['faq_suggestions'][1]['DestinationIds']='10';
$preview=$workspace->Knowledge_For_Candidate(1,'Tioman breakfast',false);
expect(array_keys($preview['map'])===array('K10'),'attaching a destination includes only its approved sources');
$ai->reply=array('suggestions'=>array(array_replace($ready,array('answer_refs'=>array('K10'),'destinations'=>array('Tioman')))));
$workspace->Reevaluate(1,'Tioman breakfast',false,faq_workspace_review_version($suggestions->Read(1)),$ai);
$input=json_decode($ai->requests[3]['input'],true);
expect($input['approved_knowledge']===$preview['input'],'preview and actual re-evaluation supply identical knowledge');
expect($suggestions->Read(1)->DestinationIds==='10' && $suggestions->Read(1)->State==='draft_ready','re-evaluation preserves the attached destination and validates its answer');
$citations=json_decode($suggestions->Read(1)->DraftSourcesJson,true);
expect($citations[0]['kind']==='knowledge' && $citations[0]['source_id']===10,'re-evaluation saves the destination knowledge citation');
expect(!array_filter($db->queries,function($q){return $q['table']==='product' && isset($q['filters']['DestinationID']);}),'destination filtering never leaks into the package query');

$db->tables['faq_suggestions'][2]['DestinationIds']='20';
$db->tables['faq_suggestions'][2]['Title']='Tioman breakfast';
$db->tables['faq_suggestions'][2]['Description']=Faq_Model::Encode_Items(array(array('q'=>'Is breakfast included in Tioman?','a'=>'')));
$ai->reply=array('results'=>array(
    array('candidate_id'=>1,'suggestions'=>array(array_replace($ready,array('answer_refs'=>array('K10'))))),
    array('candidate_id'=>2,'suggestions'=>array(array_replace($ready,array('title'=>'Tioman breakfast','source_refs'=>array(),'answer_refs'=>array('K20')))))));
$requests=array();
foreach (array(1,2) as $id) { $requests[]=array('id'=>$id,'additional'=>'','include_more'=>false,'version'=>faq_workspace_review_version($suggestions->Read($id))); }
$result=$workspace->Reevaluate_Batch($requests,$ai);
$input=json_decode($ai->requests[4]['input'],true);
expect($result['done']===2 && !$result['errors'],'batch re-evaluation completes both suggestions in one request');
expect(array_column($input['candidates'][0]['input']['approved_knowledge'],'reference')===array('K10')
    && array_column($input['candidates'][1]['input']['approved_knowledge'],'reference')===array('K20'),'batch candidates receive only their own attached destination sources');
echo 'All chat knowledge flow checks passed.'.PHP_EOL;
