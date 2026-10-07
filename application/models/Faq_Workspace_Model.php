<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Faq_Workspace_Model extends CI_Model
{
    function __construct()
    {
        parent::__construct();
        $this->load->helper('faq_suggestion');
        $this->load->model('Faq_Suggestion_Model');
        $this->load->model('Faq_Knowledge_Source_Model');
        $this->load->model('Faq_Model');
    }

    function Audit($suggestion, $source, $action, $before, $after, $staff=null)
    {
        $this->db->insert('faq_workspace_audit',array('SuggestionID'=>$suggestion,'SourceID'=>$source,'Action'=>$action,
            'BeforeJson'=>$before===null?null:json_encode($before,JSON_UNESCAPED_UNICODE),
            'AfterJson'=>$after===null?null:json_encode($after,JSON_UNESCAPED_UNICODE),
            'InsertBy'=>$staff===null?$this->session->userdata('admin_id'):$staff,'InsertDate'=>date('Y-m-d H:i:s')));
    }

    function Read_Review_History($suggestion)
    {
        return $this->db->select('a.*, staff.Name AS ActorName')->from('faq_workspace_audit a')
            ->join('admin staff','staff.AdminID = a.InsertBy','left')
            ->where('a.SuggestionID',(int)$suggestion)->order_by('a.AuditID','DESC')->limit(30)->get()->result();
    }

    /** The extraction snapshot records an advisory match, never a publication target. */
    function Read_FAQ_Comparison($suggestion)
    {
        $row=$this->db->select('AfterJson')->where('SuggestionID',(int)$suggestion)->where('Action','extracted')
            ->order_by('AuditID','DESC')->limit(1)->get('faq_workspace_audit')->row();
        if (!$row) { return null; }
        $snapshot=json_decode((string)$row->AfterJson,true); $comparison=$snapshot['faq_comparison']??null;
        if (!is_array($comparison) || empty($comparison['faq_id'])) { return null; }
        $faq=$this->db->select('FAQID, Title, Slug')->where('FAQID',(int)$comparison['faq_id'])->where('Status','Y')->get('faq')->row();
        return $faq ? $comparison + array('faq'=>$faq) : null;
    }

    function Products()
    {
        return $this->db->select('ProductID, Name, ProductCode')->where('Status','Y')->order_by('Name','ASC')->get('product')->result();
    }

    /** Retrieve reference material locally; this never makes an AI request. */
    function Knowledge_Input($text, $options=array())
    {
        $options['destinations']=$this->Faq_Model->Read_Destinations();
        $options['all_destination_sources']=true;
        if (!empty($options['attached_destinations_only'])) {
            $ids=Faq_Model::Normalize_Ids(is_array($text)?($text['destination_ids']??array()):array());
            if (!$ids) { return faq_knowledge_select($text,array(),array(),date('Y-m-d'),$options); }
        }
        $packages=$this->Products();
        if (!empty($options['attached_destinations_only'])) { $this->db->where_in('DestinationID',$ids); }
        $rows=$this->db->select('SourceID, Status, VerifiedBy, VerifiedDate, Title, Excerpt, ProductID, DestinationID, ResortName, RoomType, Topic, AppliesToAllRooms, ValidFrom, ValidTo, ReviewDue, SourceUrl, FilePath')
            ->where('Status','approved')->order_by('SourceID','DESC')->get('faq_knowledge_sources')->result_array();
        $options['scope_catalog']=$this->Knowledge_Catalog();
        return faq_knowledge_select($text,$rows,$packages,date('Y-m-d'),$options);
    }

    private function Knowledge_Catalog()
    {
        return $this->db->select('ResortName, RoomType')->distinct()->get('faq_knowledge_sources')->result_array();
    }

    function Knowledge_For_Candidate($id, $additional='', $include_more=false, $edited=null)
    {
        if (!is_string($additional) || strlen($additional)>20000) { throw new Exception('Additional information must be at most 20,000 bytes.'); }
        $s=$this->Faq_Suggestion_Model->Read($id); if (!$s) { throw new Exception('Suggestion not found.'); }
        $candidate=$edited??array('title'=>$s->Title,'items'=>Faq_Model::Decode_Items($s->Description));
        if (!isset($candidate['destination_ids'])) { $candidate['destination_ids']=Faq_Suggestion_Model::Parse_Id_Csv($s->DestinationIds); }
        return $this->Knowledge_Input(faq_knowledge_candidate_query($candidate,$additional,$this->Reevaluation_Messages($id,$include_more)),array('attached_destinations_only'=>true));
    }

    /** Historical selections come from the assessment snapshot, not today's matching sources. */
    function Last_Knowledge_Selection($id)
    {
        $row=$this->db->select('AfterJson, InsertDate')->where('SuggestionID',(int)$id)->where('Action','ai_assessed')
            ->order_by('AuditID','DESC')->limit(1)->get('faq_workspace_audit')->row();
        if (!$row) { return null; } $data=json_decode((string)$row->AfterJson,true);
        if (!isset($data['knowledge_selection'])) { return null; }
        return $data['knowledge_selection']+array('when'=>$row->InsertDate);
    }

    /** Store the assessed answer and cited evidence; no separate context record is required. */
    function Apply_Assessment($id, $candidate, $evidence, $knowledge, $expected_version=null, $run_id=0, $selection=null)
    {
        $this->db->trans_begin();
        try {
            $s=$this->Candidate($id,true);
            if ($expected_version!==null && !hash_equals(faq_workspace_review_version($s),(string)$expected_version)) { throw new Exception('Suggestion changed during re-evaluation; reload and try again.'); }
            $assessment=$candidate['assessment']; $missing=$assessment['missing_information']; $citations=array(); $answer_evidence=false;
            $context=array();
            foreach (array_unique(array_merge($candidate['source_refs'],array('A1'))) as $ref) { if (isset($evidence[$ref])) { $context[]=$evidence[$ref]; } }
            if ($selection!==null && empty($selection['batch'])) { $context[]=array('excerpt'=>$selection['query']); }
            $applicable=array('map'=>array());
            if ($knowledge) {
                $options=array('unlimited'=>true,'scope_catalog'=>$this->Knowledge_Catalog(),
                    'destinations'=>$this->Faq_Model->Read_Destinations(),
                    'attached_destinations_only'=>!empty($selection['attached_destinations_only']));
                if (!empty($selection['travel_dates'])) { $options['travel_dates']=$selection['travel_dates']; }
                $applicable=faq_knowledge_select(faq_knowledge_candidate_query($candidate,'',$context),array_values($knowledge),$this->Products(),date('Y-m-d'),$options);
            }
            foreach ($assessment['answer_refs'] as $ref) {
                if (isset($knowledge[$ref])) {
                    $source=$knowledge[$ref]; $current=$this->Faq_Knowledge_Source_Model->Read($source['SourceID'],true);
                    $dates=$applicable['selection']['travel_dates']; $valid=$current && faq_workspace_source_valid($current,date('Y-m-d'),$dates?reset($dates):'');
                    foreach ($dates as $date) { if ($current && !faq_workspace_source_valid($current,date('Y-m-d'),$date)) { $valid=false; } }
                    if (!$valid || !isset($applicable['map'][$ref]) || !hash_equals(faq_workspace_source_fingerprint($source),faq_workspace_source_fingerprint($current))) {
                        $missing[]='The cited Knowledge Source changed, is no longer valid, or does not apply to this question.'; continue;
                    }
                    $citations[]=array('kind'=>'knowledge','reference'=>$ref,'source_id'=>(int)$source['SourceID'],'title'=>$source['Title'],
                        'url'=>$source['SourceUrl'],'file'=>$source['FilePath'],'excerpt'=>$source['Excerpt'],'travel_dates'=>$dates,'fingerprint'=>faq_workspace_source_fingerprint($source));
                    $answer_evidence=true;
                } elseif (isset($evidence[$ref])) {
                    $e=$evidence[$ref];
                    $citations[]=array('kind'=>'conversation','reference'=>$ref,'title'=>$e['source_type'],'excerpt'=>$e['excerpt'],'fingerprint'=>hash('sha256',$e['excerpt']));
                    if (strpos($e['excerpt'],'Customer:')!==0) { $answer_evidence=true; }
                } else { $missing[]='Supply the message or source cited as '.$ref.'.'; }
            }
            foreach (array_unique(array_merge($candidate['source_refs'],$assessment['answer_refs'])) as $ref) {
                if (!isset($evidence[$ref])) { continue; }
                $existing=$this->db->where('SuggestionID',(int)$id)->where('SourceRef',$ref)->get('faq_suggestion_sources')->row();
                if (!$existing) { $this->Faq_Suggestion_Model->Create_Source($id,$run_id,$ref,$evidence[$ref]); }
                elseif ($existing->SourceExcerpt!==$evidence[$ref]['excerpt']) { $this->db->where('SuggestionID',(int)$id)->where('SourceRef',$ref)->update('faq_suggestion_sources',array('SourceExcerpt'=>$evidence[$ref]['excerpt'],'RunID'=>$run_id)); }
            }
            $items=$candidate['items'];
            if ($assessment['status']==='pending_approval') {
                if (!$answer_evidence) { $missing[]='Provide supporting evidence for the answer.'; }
                foreach ($items as $item) { if (trim($item['a'])==='' || trim($item['a'])==='insufficient verified source') { $missing[]='Provide enough information to draft a complete answer.'; } }
            } elseif (!$missing) { $missing[]=$candidate['reason']?:'Add supporting answer information.'; }
            $missing=array_values(array_unique($missing)); $ready=$assessment['status']==='pending_approval' && !$missing;
            $data=array('Title'=>$candidate['title'],'Description'=>Faq_Model::Encode_Items($items),
                'State'=>$ready?'draft_ready':'need_context','ReviewReason'=>$ready?($candidate['reason']?:'Review the drafted answer before approval.'):implode(' ',$missing),
                'DraftSourcesJson'=>json_encode($citations,JSON_UNESCAPED_UNICODE),'DraftHash'=>null);
            if (isset($candidate['destination_ids'])) { $data['DestinationIds']=implode(',',$candidate['destination_ids']); }
            if ($ready) { $data['DraftHash']=faq_workspace_content_hash(array_merge((array)$s,$data)); }
            $this->Save($id,$data); $audit=$data;
            if ($selection!==null) { $audit['knowledge_selection']=$selection+array('cited_refs'=>$assessment['answer_refs'],'accepted_refs'=>array_column($citations,'reference')); }
            $this->Audit($id,null,'ai_assessed',$s,$audit); $this->Commit(); return $data['State'];
        } catch (Exception $e) { $this->db->trans_rollback(); throw $e; }
    }

    private function Citations_Valid($s)
    {
        $citations=json_decode((string)$s->DraftSourcesJson,true);
        if (!is_array($citations) || !$citations) { return false; }
        $evidence=array(); foreach ($this->Faq_Suggestion_Model->Read_Evidence($s->SuggestionID) as $e) { $evidence[$e->SourceRef]=$e->SourceExcerpt; }
        foreach ($citations as $citation) {
            if (!isset($citation['kind'],$citation['reference'],$citation['fingerprint'])) { return false; }
            if ($citation['kind']==='knowledge') {
                if (!isset($citation['source_id'])) { return false; }
                $current=$this->Faq_Knowledge_Source_Model->Read($citation['source_id'],true);
                $dates=$citation['travel_dates']??array();
                if (!$current || !faq_workspace_source_valid($current,date('Y-m-d'),$dates?reset($dates):'') || !hash_equals($citation['fingerprint'],faq_workspace_source_fingerprint($current))) { return false; }
                foreach ($dates as $date) { if (!faq_workspace_source_valid($current,date('Y-m-d'),$date)) { return false; } }
            } elseif ($citation['kind']==='conversation') {
                if (!isset($evidence[$citation['reference']]) || !hash_equals($citation['fingerprint'],hash('sha256',$evidence[$citation['reference']]))) { return false; }
            } else { return false; }
        }
        return true;
    }

    private function Candidate($id, $lock=false, $allow_completed=false)
    {
        $s=$lock?$this->db->query("SELECT * FROM faq_suggestions WHERE SuggestionID = ? AND Status = 'Y' FOR UPDATE",array((int)$id))->row():$this->Faq_Suggestion_Model->Read($id);
        if (!$s) { throw new Exception('Candidate not found.'); }
        if (!$allow_completed && in_array($s->State,array('accepted','dismissed'),true)) { throw new Exception('This candidate is completed.'); }
        return $s;
    }

    private function Save($id, $data)
    {
        $data['UpdateBy']=$this->session->userdata('admin_id'); $data['UpdateDate']=date('Y-m-d H:i:s');
        $this->db->where('SuggestionID',(int)$id)->update('faq_suggestions',$data);
    }

    private function Commit()
    {
        if (!$this->db->trans_status()) { throw new Exception('Workspace save failed. Please retry.'); }
        $this->db->trans_commit();
    }

    function Refresh($id)
    {
        $this->db->trans_begin();
        try { $s=$this->Candidate($id,true); $this->Refresh_Locked($s); $this->Commit(); }
        catch (Exception $e) { $this->db->trans_rollback(); throw $e; }
    }

    private function Refresh_Locked($s)
    {
        // An explicit staff review remains selected until content or status changes.
        if ($s->State==='draft_ready' && $s->DraftHash && hash_equals($s->DraftHash,faq_workspace_manual_review_hash($s))) { return; }
        if ($s->State==='draft_ready' && (!$s->DraftHash || !hash_equals($s->DraftHash,faq_workspace_content_hash($s)) || !$this->Citations_Valid($s))) {
            $this->Save($s->SuggestionID,array('State'=>'need_context','DraftHash'=>null,'ReviewReason'=>'The draft or its supporting evidence changed or expired. Re-evaluate with current information.'));
        }
    }

    /** Staff may approve any review status; repeated approvals reuse the published FAQ. */
    function Publish($id)
    {
        $this->db->trans_begin();
        try {
            $s=$this->Candidate($id,true,true);
            if ($s->State==='accepted' && (int)$s->AcceptedFAQID>0) {
                $this->Commit(); return (int)$s->AcceptedFAQID;
            }
            $citations=json_decode((string)$s->DraftSourcesJson,true);
            $citations=is_array($citations)?$citations:array();
            $existing=$this->Faq_Model->Read_By_Title($s->Title);
            if ($existing) {
                $this->db->query('SELECT FAQID FROM faq WHERE FAQID = ? FOR UPDATE',array((int)$existing->FAQID));
                $existing=$this->Faq_Model->Read_By_Title($s->Title);
                $items=array_merge(Faq_Model::Decode_Items($existing->Description),Faq_Model::Decode_Items($s->Description));
                $this->Faq_Model->Update($existing->FAQID,array('Title'=>$existing->Title,'Slug'=>$existing->Slug,'Description'=>Faq_Model::Encode_Items($items),'Type'=>$existing->Type));
                $faq_id=(int)$existing->FAQID;
                $destinations=array_unique(array_merge($this->Faq_Model->Read_Destination_Ids($faq_id),Faq_Suggestion_Model::Parse_Id_Csv($s->DestinationIds)));
            } else {
                $faq_id=$this->Faq_Model->Create(array('Title'=>$s->Title,'Slug'=>$this->Faq_Model->Generate_Slug($s->Title,0),'Description'=>$s->Description,'Type'=>'external'));
                $destinations=Faq_Suggestion_Model::Parse_Id_Csv($s->DestinationIds);
            }
            if (!$faq_id) { throw new Exception('Could not create FAQ.'); }
            $this->Faq_Model->Sync_Destinations($faq_id,$destinations);
            $this->Save($id,array('State'=>'accepted','AcceptedFAQID'=>$faq_id,'ReviewReason'=>'Approved by staff.'));
            $this->Audit((int)$id,null,'accepted',$s,array('FAQID'=>$faq_id,'citations'=>$citations));
            $this->Commit(); return $faq_id;
        } catch (Exception $e) { $this->db->trans_rollback(); throw $e; }
    }

    function Dismiss_Candidate($id)
    {
        $this->db->trans_begin();
        try { $s=$this->Candidate($id,true); $this->Save($id,array('State'=>'dismissed')); $this->Audit((int)$id,null,'dismissed',$s,null); $this->Commit(); }
        catch (Exception $e) { $this->db->trans_rollback(); throw $e; }
    }

    function Edit_Draft($id, $data, $review_status=null)
    {
        if ($review_status!==null && (!is_string($review_status) || !in_array($review_status,array('draft_ready','need_context'),true))) {
            throw new Exception('Choose Pending Approval or Needs Information.');
        }
        $this->db->trans_begin();
        try {
            $s=$this->Candidate($id,true);
            $items=Faq_Model::Decode_Items($data['Description']); $complete=(bool)$items;
            foreach ($items as $item) { if (trim($item['q'])==='' || trim($item['a'])==='') { $complete=false; } }
            if ($review_status!==null) {
                $data['State']=$review_status;
                if ($review_status!==$s->State) {
                    $data['ReviewReason']='Review status set to '.faq_workspace_review_label($review_status).' by staff.';
                } elseif ($s->Title!==$data['Title'] || $s->Description!==$data['Description'] || (string)$s->DestinationIds!==(string)$data['DestinationIds']) {
                    $data['ReviewReason']='Draft edited by staff. Review the answer and its supporting evidence before approval.';
                }
                $data['DraftHash']=$review_status==='draft_ready'?faq_workspace_manual_review_hash(array_merge((array)$s,$data)):null;
            } elseif ($s->State==='draft_ready' && (string)$s->DestinationIds===(string)$data['DestinationIds'] && $complete && $this->Citations_Valid($s)) {
                $data['ReviewReason']='Draft edited by staff. Review the answer and its supporting evidence before approval.';
                $data['DraftHash']=faq_workspace_content_hash(array_merge((array)$s,$data));
            } elseif ($s->Title!==$data['Title'] || $s->Description!==$data['Description'] || (string)$s->DestinationIds!==(string)$data['DestinationIds']) {
                $data+=array('DraftHash'=>null,'State'=>'need_context','ReviewReason'=>'Content edited: re-evaluate the answer before approval.');
            }
            $action=$review_status!==null && faq_workspace_review_label($review_status)!==faq_workspace_review_label($s->State)?'review_status_changed':'content_edited';
            $this->Save($id,$data); $this->Audit((int)$id,null,$action,$s,$data); $this->Commit();
        } catch (Exception $e) { $this->db->trans_rollback(); throw $e; }
    }

    private function Filter_Suggestions($filter, $search, $run)
    {
        $this->db->where('Status','Y');
        if ($filter==='pending' || $filter==='ready') { $this->db->where('State','draft_ready'); }
        elseif ($filter==='context') { $this->db->where_not_in('State',array('accepted','dismissed','draft_ready')); }
        elseif (in_array($filter,array('accepted','dismissed'),true)) { $this->db->where('State',$filter); }
        if ($search!=='') { $this->db->group_start()->like('Title',$search)->or_like('Description',$search)->group_end(); }
        if (is_array($run)) {
            $ids=array_values(array_filter(array_map('intval',$run),function($id){return $id>0;}));
            if ($ids) { $this->db->where_in('RunID',$ids); } else { $this->db->where('1 = 0',null,false); }
        } elseif ($run>0) { $this->db->where('RunID',(int)$run); }
    }

    /** $run is 0 for all generations, a RunID, or an array of RunIDs (empty means no generations). */
    function Suggestion_List($filter='all', $search='', $page=1, $run=0, $page_size=100)
    {
        $this->Filter_Suggestions($filter,$search,$run);
        $pagination=faq_workspace_pagination($this->db->count_all_results('faq_suggestions'),$page,$page_size);
        $this->Filter_Suggestions($filter,$search,$run);
        $this->db->order_by('SuggestionID','DESC');
        if ($pagination['page_size']!==-1) { $this->db->limit($pagination['page_size'],$pagination['offset']); }
        $rows=$this->db->get('faq_suggestions')->result();
        $rows=$this->Faq_Suggestion_Model->Decorate_Suggestions($rows);
        return array('candidates'=>$rows)+$pagination;
    }

    function Suggestion_Counts($search='', $run=0)
    {
        $out=array_fill_keys(array_keys(faq_workspace_filters()),0); $this->Filter_Suggestions('all',$search,$run);
        foreach ($this->db->select('State, COUNT(*) AS Total')->group_by('State')->get('faq_suggestions')->result() as $r) {
            $n=(int)$r->Total; $out['all']+=$n;
            if (in_array($r->State,array('accepted','dismissed'),true)) { $out[$r->State]+=$n; }
            elseif ($r->State==='draft_ready') { $out['pending']+=$n; } else { $out['context']+=$n; }
        }
        return $out;
    }

    function Reevaluation_Messages($id, $include_more)
    {
        $messages=array(); $conversations=array(); $seen=array(); $number=0; $bytes=0;
        foreach ($this->Faq_Suggestion_Model->Read_Evidence($id) as $e) {
            $source=array('source_type'=>$e->SourceType,'excerpt'=>$e->SourceExcerpt,'ghl_message_id'=>$e->GhlMessageID,'chat_file_id'=>$e->ChatFileID,'message_index'=>$e->MessageIndex);
            $messages[]=array('reference'=>$e->SourceRef,'text'=>$e->SourceExcerpt,'source'=>$source);
            $seen[$e->SourceRef]=true;
            if ($e->GhlMessageID) { $seen['message:'.$e->GhlMessageID]=true; }
            if (preg_match('/^S([0-9]+)$/D',$e->SourceRef,$m)) { $number=max($number,(int)$m[1]); }
            if (!empty($e->ConversationID)) { $conversations[$e->ConversationID]=true; }
        }
        if (!$include_more) { return $messages; }
        $extra=array();
        if ($conversations) {
            $rows=$this->db->select('id, body, direction, date_added')->where_in('conversation_id',array_keys($conversations))
                ->order_by('date_added','DESC')->order_by('id','DESC')->limit(100)->get('ghl_messages')->result();
            foreach (array_reverse($rows) as $m) {
                if (isset($seen['message:'.$m->id]) || faq_suggestion_is_noise($m->body)) { continue; }
                $excerpt=($m->direction==='inbound'?'Customer: ':'Agent: ').mb_substr((string)$m->body,0,4000);
                $extra[]=array('reference'=>'S'.(++$number),'text'=>$excerpt,'source'=>array('source_type'=>'ghl_message','excerpt'=>$excerpt,'ghl_message_id'=>(int)$m->id,'chat_file_id'=>null,'message_index'=>null));
            }
        } else {
            $s=$this->Candidate($id);
            $map=$this->Faq_Suggestion_Model->Uploaded_Chat_Evidence($s->RunID);
            $keys=array_keys($map); $positions=array_flip($keys); $selected=array();
            foreach ($messages as $message) {
                $anchor=$message['reference'];
                if (!isset($map[$anchor]) || $map[$anchor]['excerpt']!==$message['text']) {
                    $matches=array(); foreach ($map as $ref=>$source) { if ($source['excerpt']===$message['text']) { $matches[]=$ref; } }
                    if (count($matches)!==1) { continue; } $anchor=$matches[0];
                }
                $position=$positions[$anchor];
                $conversation=$map[$anchor]['conversation_key']??'';
                for ($i=max(0,$position-15);$i<min(count($keys),$position+26);$i++) {
                    if (($map[$keys[$i]]['conversation_key']??'')===$conversation) { $selected[$i]=true; }
                }
            }
            ksort($selected);
            $original_text=array_column($messages,'text');
            foreach ($selected as $i=>$unused) {
                $ref=$keys[$i]; if (in_array($map[$ref]['excerpt'],$original_text,true)) { continue; }
                $extra[]=array('reference'=>'S'.(++$number),'text'=>mb_substr($map[$ref]['excerpt'],0,4000),'source'=>$map[$ref]);
                if (count($extra)>=100) { break; }
            }
        }
        foreach ($extra as $m) {
            $size=strlen(json_encode($m,JSON_UNESCAPED_UNICODE));
            if ($bytes+$size>120000) { break; }
            $bytes+=$size; $m['source']['excerpt']=$m['text']; $messages[]=$m;
        }
        return $messages;
    }

    private function Prepare_Reevaluation($id, $additional, $include_more, $expected_version)
    {
        if (!is_string($additional) || strlen($additional)>20000) { throw new Exception('Additional information must be at most 20,000 bytes.'); }
        $s=$this->Candidate($id);
        if (!hash_equals(faq_workspace_review_version($s),(string)$expected_version)) { throw new Exception('Suggestion changed; reload the re-evaluation form.'); }
        $messages=$this->Reevaluation_Messages($id,$include_more); $evidence=array(); $input_messages=array();
        foreach ($messages as $message) {
            if ($message['reference']==='A1' && $additional!=='') { continue; }
            $evidence[$message['reference']]=$message['source']; $input_messages[]=array('reference'=>$message['reference'],'text'=>$message['text']);
        }
        if ($additional!=='') { $evidence['A1']=array('source_type'=>'staff_information','excerpt'=>$additional,'ghl_message_id'=>null,'chat_file_id'=>null,'message_index'=>null); }
        $candidate=array('id'=>(int)$id,'title'=>$s->Title,'items'=>Faq_Model::Decode_Items($s->Description),'destination_ids'=>Faq_Suggestion_Model::Parse_Id_Csv($s->DestinationIds));
        $destination_names=$this->Faq_Suggestion_Model->Destination_Name_Map();
        $candidate['destinations']=array_values(array_intersect_key($destination_names,array_flip($candidate['destination_ids'])));
        $knowledge=$this->Knowledge_Input(faq_knowledge_candidate_query($candidate,$additional,$input_messages),array('attached_destinations_only'=>true));
        $prompt=faq_workspace_reevaluate_prompt($candidate,$input_messages,$additional,$knowledge['input']);
        return array('id'=>(int)$id,'version'=>$expected_version,'candidate'=>$candidate,'messages'=>$input_messages,'additional'=>$additional,
            'sources'=>$knowledge['input'],'knowledge'=>$knowledge['map'],'selection'=>$knowledge['selection'],'evidence'=>$evidence,'prompt'=>$prompt);
    }

    function Reevaluate($id, $additional, $include_more, $expected_version, $service=null)
    {
        $result=$this->Reevaluate_Batch(array(array('id'=>$id,'additional'=>$additional,'include_more'=>$include_more,'version'=>$expected_version)),$service);
        if ($result['errors']) { throw new Exception($result['errors'][0]); }
        return $this->Faq_Suggestion_Model->Read($id)->State;
    }

    /** One AI call for the whole selected group; each candidate keeps its own evidence. */
    function Reevaluate_Batch($requests, $service=null)
    {
        if (!$requests || count($requests)>10) { throw new Exception('Re-evaluate between 1 and 10 suggestions at a time.'); }
        $prepared=array(); $payload=array();
        foreach ($requests as $request) {
            $p=$this->Prepare_Reevaluation($request['id'],$request['additional'],$request['include_more'],$request['version']);
            if (isset($prepared[$p['id']])) { throw new Exception('Select each suggestion once.'); }
            $prepared[$p['id']]=$p;
            $payload[]=array('candidate_id'=>$p['id'],'input'=>json_decode($p['prompt']['input'],true));
        }
        $input=count($prepared)===1?reset($prepared)['prompt']['input']:json_encode(array('candidates'=>$payload),JSON_UNESCAPED_UNICODE);
        if (count($prepared)>1 && strlen($input)>250000) { throw new Exception('All destination knowledge is included for each suggestion, and this group is too large for one request. Re-evaluate fewer suggestions at a time.'); }
        if ($service===null) { $this->load->library('FaqSuggestionService'); $service=$this->faqsuggestionservice; }
        $runs=array();
        foreach ($prepared as $id=>$p) {
            $runs[$id]=$this->Faq_Suggestion_Model->Create_Run(array('Source'=>'reevaluate','FileName'=>'Suggestion #'.$id,'RunState'=>'running'));
            $this->Faq_Suggestion_Model->Update_Run($runs[$id],array('InputText'=>$input));
        }
        try {
            if (count($prepared)===1) {
                $p=reset($prepared); $result=$service->reevaluate($p['candidate'],$p['messages'],$p['additional'],$p['sources']);
                $responses=array($p['id']=>json_decode($result['raw'],true));
            } else {
                $result=$service->reevaluate_batch($payload); $decoded=json_decode($result['raw'],true); $responses=array();
                if (!is_array($decoded) || !isset($decoded['results']) || !is_array($decoded['results'])) { throw new Exception('AI returned an invalid re-evaluation group.'); }
                foreach ($decoded['results'] as $row) {
                    if (!is_array($row) || !isset($row['candidate_id']) || !is_int($row['candidate_id']) || !isset($prepared[$row['candidate_id']]) || isset($responses[$row['candidate_id']])) { throw new Exception('AI returned an invalid or duplicated candidate reference.'); }
                    $responses[$row['candidate_id']]=$row;
                }
                if (count($responses)!==count($prepared)) { throw new Exception('AI did not return all selected suggestions.'); }
            }
            $destinations=$this->Faq_Suggestion_Model->Destination_Name_Map(); $destination_map=array_flip($destinations); $parsed=array();
            foreach ($responses as $id=>$response) {
                $items=faq_suggestion_parse_response($response,$destination_map,1,true);
                if (count($items)!==1 || !isset($items[0]['assessment'])) { throw new Exception('AI returned an invalid FAQ assessment.'); }
                // Re-evaluation retains the destinations selected by staff.
                $items[0]['destination_ids']=$prepared[$id]['candidate']['destination_ids'];
                $parsed[$id]=$items[0];
            }
            $done=0; $errors=array(); $cost=(float)$result['cost_usd']/count($prepared);
            foreach ($prepared as $id=>$p) {
                try {
                    $state=$this->Apply_Assessment($id,$parsed[$id],$p['evidence'],$p['knowledge'],$p['version'],$runs[$id],$p['selection']);
                    $this->Faq_Suggestion_Model->Update_Run($runs[$id],array('RunState'=>'done','Proposed'=>1,'Created'=>0,'Model'=>$result['model'],'CostUsd'=>$cost));
                    $this->Audit($id,null,'ai_reevaluated',null,array('run_id'=>$runs[$id],'state'=>$state)); $done++;
                } catch (Exception $e) {
                    $this->Faq_Suggestion_Model->Update_Run($runs[$id],array('RunState'=>'error','ErrorMessage'=>$e->getMessage(),'Model'=>$result['model'],'CostUsd'=>$cost));
                    $errors[]='#'.$id.': '.$e->getMessage();
                }
            }
            return array('done'=>$done,'errors'=>$errors);
        } catch (Exception $e) {
            foreach ($runs as $run) {
                $data=array('RunState'=>'error','ErrorMessage'=>$e->getMessage());
                if (isset($result)) { $data+=array('Model'=>$result['model'],'CostUsd'=>(float)$result['cost_usd']/count($runs)); }
                $this->Faq_Suggestion_Model->Update_Run($run,$data);
            }
            throw $e;
        }
    }

    function Reconcile_Open_Candidates()
    {
        foreach ($this->db->select('SuggestionID')->where('Status','Y')->where('State','draft_ready')->get('faq_suggestions')->result() as $row) { $this->Refresh($row->SuggestionID); }
    }

    function Source_Changed($topic)
    {
        // Only drafts citing changed knowledge can lose readiness; checking ready drafts is cheap.
        $this->Reconcile_Open_Candidates();
    }
}
