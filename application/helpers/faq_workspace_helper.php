<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Pure, conservative rules shared by generation, staff review and publication. */
function faq_workspace_source_valid($s, $today, $travel_date = '')
{
    $s = (array)$s;
    if ($s['Status']!=='approved' || empty($s['VerifiedBy']) || empty($s['VerifiedDate']) || trim((string)$s['Excerpt'])==='') { return false; }
    foreach (array('ValidFrom','ValidTo','ReviewDue') as $key) {
        if (!empty($s[$key]) && faq_suggestion_valid_date($s[$key])==='') { return false; }
    }
    $applicable_date=$travel_date!==''?$travel_date:$today;
    if ($travel_date!=='' && faq_suggestion_valid_date($travel_date)==='') { return false; }
    if (!empty($s['ValidFrom']) && $s['ValidFrom']>$applicable_date) { return false; }
    if (!empty($s['ValidTo']) && $s['ValidTo']<$applicable_date) { return false; }
    if (!empty($s['ReviewDue']) && $s['ReviewDue']<$today) { return false; }
    if ($travel_date!=='' && ((!empty($s['ValidFrom']) && $travel_date<$s['ValidFrom']) || (!empty($s['ValidTo']) && $travel_date>$s['ValidTo']))) { return false; }
    return true;
}

function faq_workspace_source_fingerprint($s)
{
    $s=(array)$s; $snapshot=array();
    foreach (array('SourceID','Status','Excerpt','ProductID','ResortName','RoomType','Topic','ValidFrom','ValidTo','ReviewDue','AppliesToAllRooms','VerifiedBy','VerifiedDate','SourceUrl','FilePath') as $key) {
        $snapshot[$key]=isset($s[$key])?(string)$s[$key]:'';
    }
    // Preserve legacy citation hashes for unrestricted sources.
    if (!empty($s['DestinationID'])) { $snapshot['DestinationID']=(string)$s['DestinationID']; }
    return hash('sha256',json_encode($snapshot));
}

/** Hash the answer and its evidence without any structured context dependency. */
function faq_workspace_content_hash($s)
{
    $s=(array)$s;
    return hash('sha256',json_encode(array($s['Title'],$s['Description'],$s['DestinationIds']??'',$s['DraftSourcesJson']??null)));
}

/** Keep staff-selected readiness distinct from AI readiness in the existing hash. */
function faq_workspace_manual_review_hash($s)
{
    return hash('sha256','staff_review:'.faq_workspace_content_hash($s));
}

/** A form token detects changes during re-evaluation without an extra database column. */
function faq_workspace_review_version($s)
{
    $s=(array)$s;
    return hash('sha256',json_encode(array(faq_workspace_content_hash($s),$s['State'],$s['ReviewReason']??null,$s['UpdateDate']??null)));
}

function faq_workspace_filters()
{
    return array('all'=>'All','pending'=>'Pending Approval','context'=>'Needs Information',
        'accepted'=>'Approved','dismissed'=>'Rejected');
}

/** Match the entry-count options used by the FAQ Records table. */
function faq_workspace_page_size($value)
{
    if (!is_scalar($value) || !preg_match('/^(100|200|500|-1)$/D',(string)$value)) { return 100; }
    return (int)$value;
}

function faq_workspace_search($value)
{
    return is_string($value)?mb_substr(trim($value),0,200):'';
}

/** Carry only the suggestion list's supported filters between review pages. */
function faq_workspace_return_filters($value)
{
    if (!is_string($value) || $value==='' || strlen($value)>4096) { return ''; }
    parse_str($value,$filters);
    if (!array_intersect(array_keys($filters),array('page_size','run_id','tab','search','page'))) { return ''; }
    $tab=$filters['tab']??'pending'; $statuses=faq_workspace_filters();
    $run=$filters['run_id']??0;
    $run_id=is_scalar($run)?filter_var($run,FILTER_VALIDATE_INT,array('options'=>array('min_range'=>0,'max_range'=>2147483647))):false;
    $page=$filters['page']??1;
    $page=is_scalar($page)?filter_var($page,FILTER_VALIDATE_INT,array('options'=>array('min_range'=>1,'max_range'=>2147483647))):false;
    return http_build_query(array('page_size'=>faq_workspace_page_size($filters['page_size']??null),
        'run_id'=>$run==='latest20'?'latest20':($run_id===false?0:$run_id),
        'tab'=>is_string($tab)&&isset($statuses[$tab])?$tab:'pending',
        'search'=>faq_workspace_search($filters['search']??''),'page'=>$page===false?1:$page),'','&',PHP_QUERY_RFC3986);
}

function faq_workspace_suggestions_url($return_filters='')
{
    $filters=faq_workspace_return_filters($return_filters);
    return base_url('Faq?section=suggestions').($filters!==''?'&'.$filters:'');
}

function faq_workspace_suggestion_url($id,$return_filters='')
{
    $filters=faq_workspace_return_filters($return_filters);
    return base_url('Faq_Suggestion/Update?id=').(int)$id.($filters!==''?'&return_filters='.rawurlencode($filters):'');
}

/** Sources can be filtered to one destination or to unrestricted entries. */
function faq_workspace_source_destination_filter($value)
{
    if ($value==='none') { return 'none'; }
    if (!is_string($value) && !is_int($value)) { return 0; }
    $id=filter_var($value,FILTER_VALIDATE_INT,array('options'=>array('min_range'=>0,'max_range'=>2147483647)));
    return $id===false?0:$id;
}

/** Shared ranges and numbered navigation for the two server-paginated tables. */
function faq_workspace_pagination($total, $page=1, $page_size=100)
{
    $total=max(0,(int)$total); $page_size=faq_workspace_page_size($page_size);
    $pages=$page_size===-1?1:max(1,(int)ceil($total/$page_size));
    $page=min(max(1,(int)$page),$pages); $offset=$page_size===-1?0:($page-1)*$page_size;
    if ($pages<=7) { $numbers=range(1,$pages); }
    else {
        $start=max(2,min($page-2,$pages-5)); $end=min($pages-1,max($page+2,6));
        $numbers=array(1); if ($start>2) { $numbers[]=null; }
        foreach (range($start,$end) as $number) { $numbers[]=$number; }
        if ($end<$pages-1) { $numbers[]=null; } $numbers[]=$pages;
    }
    return array('total'=>$total,'page'=>$page,'pages'=>$pages,'page_size'=>$page_size,'offset'=>$offset,
        'entry_start'=>$total?$offset+1:0,'entry_end'=>$page_size===-1?$total:min($offset+$page_size,$total),'page_numbers'=>$numbers);
}

function faq_workspace_review_label($state)
{
    return $state==='accepted'?'Approved':($state==='dismissed'?'Rejected':($state==='draft_ready'?'Pending Approval':'Needs Information'));
}

/** Describe changed content, ignoring the per-item audit timestamps. */
function faq_workspace_audit_content_changes($before, $after)
{
    $changes=array();
    if (array_key_exists('Title',$after) && ($before['Title']??'')!==$after['Title']) { $changes[]='title'; }
    if (array_key_exists('Description',$after)) {
        $old=Faq_Model::Decode_Items($before['Description']??'');
        $new=Faq_Model::Decode_Items($after['Description']);
        foreach (array('q'=>'questions','a'=>'answers','tags'=>'tags','links'=>'reference links') as $key=>$label) {
            $values=function($items)use($key){
                return array_map(function($item)use($key){return $item[$key]??(in_array($key,array('q','a'),true)?'':array());},$items);
            };
            if ($values($old)!==$values($new)) { $changes[]=$label; }
        }
    }
    if (array_key_exists('DestinationIds',$after) && (string)($before['DestinationIds']??'')!==(string)$after['DestinationIds']) { $changes[]='destinations'; }
    return $changes;
}

/** Present stored audit records as who did what and when, without changing them. */
function faq_workspace_review_history($audit)
{
    $audit=array_values((array)$audit); $history=array();
    $labels=array('extracted'=>'Created this suggestion from source material',
        'ai_assessed'=>'Assessed the draft answer','ai_reevaluated'=>'Re-evaluated the suggestion',
        'content_edited'=>'Saved the suggestion','review_status_changed'=>'Changed the review status',
        'accepted'=>'Approved and published to the FAQ Library',
        'dismissed'=>'Rejected the suggestion','candidate_deleted'=>'Deleted the suggestion',
        'run_deleted'=>'Deleted the generation run');
    for ($i=0;$i<count($audit);$i++) {
        $row=(array)$audit[$i]; $action=$row['Action']??'';
        $before=json_decode((string)($row['BeforeJson']??''),true); $before=is_array($before)?$before:array();
        $after=json_decode((string)($row['AfterJson']??''),true); $after=is_array($after)?$after:array();
        // A completed re-evaluation and its assessment describe one user action.
        if ($action==='ai_reevaluated' && isset($audit[$i+1])) {
            $assessment=(array)$audit[$i+1];
            if (($assessment['Action']??'')==='ai_assessed'
                && (int)($row['AuditID']??0)===(int)($assessment['AuditID']??0)+1
                && ($row['SuggestionID']??null)===($assessment['SuggestionID']??null)
                && ($row['InsertDate']??'')===($assessment['InsertDate']??'')
                && ($row['InsertBy']??null)===($assessment['InsertBy']??null)) {
                $before=json_decode((string)($assessment['BeforeJson']??''),true); $before=is_array($before)?$before:array();
                $assessed=json_decode((string)($assessment['AfterJson']??''),true);
                if (is_array($assessed)) { $after=array_merge($after,$assessed); }
                $i++;
            }
        }
        $ai=in_array($action,array('extracted','ai_assessed','ai_reevaluated'),true);
        $staff=trim((string)($row['ActorName']??''));
        if ($staff==='' && !empty($row['InsertBy'])) { $staff='Staff #'.(int)$row['InsertBy']; }
        $details=array(); $changes=faq_workspace_audit_content_changes($before,$after);
        $title=$labels[$action]??ucfirst(str_replace('_',' ',$action));
        if ($action==='content_edited') {
            $title=$changes?'Edited the suggestion':'Saved the suggestion';
            $details[]=$changes?'Changed: '.implode(', ',$changes).'.':'No content changes.';
        } elseif (in_array($action,array('ai_assessed','ai_reevaluated','review_status_changed'),true) && $changes) {
            $details[]=($action==='review_status_changed'?'Changed: ':'Updated: ').implode(', ',$changes).'.';
        }
        $state=$after['State']??($after['state']??null);
        if ($state!==null && in_array($action,array('ai_assessed','ai_reevaluated','content_edited','review_status_changed'),true)) {
            $label=faq_workspace_review_label($state); $previous=isset($before['State'])?faq_workspace_review_label($before['State']):'';
            $details[]='Status: '.($previous!=='' && $previous!==$label?$previous.' → ':'').$label.'.';
        }
        if ($action==='accepted' && !empty($after['FAQID'])) { $details[]='Published as FAQ #'.(int)$after['FAQID'].'.'; }
        $date=DateTime::createFromFormat('Y-m-d H:i:s',(string)($row['InsertDate']??''));
        $history[]=array('who'=>$ai?'AI':($staff!==''?$staff:'Not recorded'),
            'requested_by'=>$ai?$staff:'','action'=>$title,'details'=>$details,
            'when'=>$date?$date->format('j M Y, g:i:s a'):(string)($row['InsertDate']??''));
    }
    return $history;
}

function faq_workspace_reevaluate_prompt($candidate, $messages, $additional, $sources=array())
{
    return array('instructions'=>faq_suggestion_extraction_instructions(1).' Re-evaluate exactly this existing candidate; do not create unrelated suggestions. Approved knowledge includes only valid sources for destinations already attached to the candidate. If no destination is attached, use only the supplied chats and staff information. Review every supplied source and decide which facts answer the question. Sharing a destination does not make a resort, package or room policy universal; preserve each source scope and validity. Retain the existing selected destinations exactly, including an empty selection. Use the same suggestions JSON contract as initial generation: '.json_encode(array('suggestions'=>array(faq_suggestion_candidate_example())),JSON_UNESCAPED_UNICODE),
        'input'=>json_encode(array('existing_candidate'=>$candidate,'conversation_evidence'=>$messages,'staff_additional_information'=>$additional,
            'approved_knowledge'=>$sources,'staff_information_reference'=>$additional!==''?'A1':null),JSON_UNESCAPED_UNICODE));
}

function faq_workspace_csrf_field($session)
{
    return '<input type="hidden" name="workspace_token" value="'.htmlspecialchars((string)$session->userdata('faq_workspace_csrf'),ENT_QUOTES,'UTF-8').'">';
}

function faq_workspace_can_manage_sources($session)
{
    return (int)$session->level===10 || in_array('FKS',(array)$session->access_control,true)
        || (in_array((int)$session->level,array(25,45),true) && in_array('FE',(array)$session->access_control,true));
}
