<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * FAQ AI Suggestion — the review UI for the AI-mined candidate FAQs.
 *
 * Staff trigger a run from here (the "Generate" button): they pick a date range
 * and an optional mobile number, and the model reads that window of WhatsApp /
 * GHL conversations and stores candidate FAQs in `faq_suggestions`. They then
 * review each one, edit the same fields a FAQ carries, and Accept (create a real
 * FAQ, flag the suggestion 'accepted' for audit) or Dismiss. The heavy lifting
 * lives in Faq_Suggestion_Model + helpers/faq_suggestion_helper.php.
 *
 * Access mirrors the FAQ page: OWNER (level 10) always passes; every other role
 * needs 'FV' (FAQ VIEW) to see suggestions and 'FE' (FAQ EDIT) to edit / accept /
 * dismiss / delete and to trigger a manual generation.
 */
class Faq_Suggestion extends MY_Controller
{
	function __construct()
	{
		parent::__construct();
		$this->load->model('Faq_Suggestion_Model');
		$this->load->model('Faq_Model');
		$this->load->model('Faq_Tag_Model');
		$this->load->model('Universal_Model');
		$this->load->model('Faq_Workspace_Model');
		$this->load->helper('faq_suggestion');
		if (!$this->session->userdata('faq_workspace_csrf')) {
			$this->session->set_userdata('faq_workspace_csrf',bin2hex(random_bytes(32)));
		}
	}

	private function Can_View()
	{
		return (int) $this->session->level === 10 || in_array('FV', (array) $this->session->access_control);
	}

	private function Can_Edit()
	{
		return (int) $this->session->level === 10 || in_array('FE', (array) $this->session->access_control);
	}

	private function Can_Manage_Sources()
	{
		return faq_workspace_can_manage_sources($this->session);
	}

	private function Require_Workspace_Post()
	{
		if (strtoupper((string)$this->input->server('REQUEST_METHOD'))!=='POST' ||
			!is_string($this->input->post('workspace_token')) ||
			!hash_equals((string)$this->session->userdata('faq_workspace_csrf'),$this->input->post('workspace_token'))) {
			show_error('A valid workspace form submission is required.',403); return false;
		}
		return true;
	}

	// Legacy entry point; the consolidated list is the canonical suggestion page.
	function index()
	{
		if (!$this->Can_View()) {
			redirect(base_url('Dashboard'));
			return;
		}

		$tab=$this->input->get('tab');
		if ($tab==='ready') { $tab='pending'; }
		// Preserve feedback across this compatibility redirect.
		foreach (array('faq_success','faq_error') as $key) { $this->session->keep_flashdata($key); }
		redirect(base_url('Faq?section=suggestions').(is_string($tab)&&isset(faq_workspace_filters()[$tab])?'&tab='.rawurlencode($tab):''));
	}

	// Detail of one run — its metadata plus the candidate FAQs it produced.
	function View()
	{
		if (!$this->Can_View()) {
			redirect(base_url('Dashboard'));
			return;
		}
		$run_id = (int) $this->input->get('id');
		$run    = $this->Faq_Suggestion_Model->Read_Run($run_id);
		if ($run === null) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}

		$titles = array('tab_title' => 'HolidayGoGoGo | FAQ AI Suggestion', 'breadcrumb_title' => 'FAQ AI Suggestion >> Run');
		$data['run']         = $run;
		$data['suggestions'] = $this->Faq_Suggestion_Model->Read_Suggestions_For_Run($run_id);
		$data['can_edit']    = $this->Can_Edit();
		$this->load->view('layout/header', $titles);
		$this->load->view('faq_suggestion/view', $data);
		$this->load->view('layout/footer');
	}

	/** Compatibility redirect to the source list; new imports use Add_Source. */
	function Sources()
	{
		if (!$this->Can_Manage_Sources()) { show_error('Source manager access required.',403); return; }
		redirect(base_url('Faq?section=sources'));
	}

	function Add_Source()
	{
		if (!$this->Can_Manage_Sources()) { show_error('Source manager access required.',403); return; }
		$this->load->model('Faq_Knowledge_Source_Model');
		$this->load->helper('faq_source_import');
		$data=array('step'=>'type','type'=>'','error'=>'','destination_id'=>0,'destinations'=>$this->Faq_Model->Read_Destinations());
		if (strtoupper((string)$this->input->server('REQUEST_METHOD'))==='POST') {
			if (!$this->Require_Workspace_Post()) { return; }
			try {
				$stage=$this->input->post('stage');
				$type=$this->input->post('type');
				if (!is_string($type) || !in_array($type,array('pdf','document','csv','url','manual'),true)) { throw new Exception('Choose an import type.'); }
				$data['type']=$type;
				if ($stage==='choose') { $data['step']='input'; }
				elseif ($stage==='import') {
					$data['step']='input'; $entries=array();
					$destination=$this->input->post('destination_id')??'0';
					$data['destination_id']=is_string($destination)?$destination:0;
					$destination=$this->Faq_Knowledge_Source_Model->Validate_Destination($destination);
					$row=array('SourceType'=>$type,'Title'=>'','SourceUrl'=>'','StoredName'=>null,'Excerpt'=>'','ProductID'=>'0','DestinationID'=>$destination,'ResortName'=>'','RoomType'=>'','Topic'=>'general_information','ValidFrom'=>'','ValidTo'=>'','ReviewDue'=>'');
					if ($type==='pdf' || $type==='document') {
						$stored=$this->Receive_Knowledge_Document($type==='pdf'?'pdf':'pdf|docx|txt');
						$this->Queue_Source_Import(pathinfo($stored,PATHINFO_EXTENSION)==='pdf'?'pdf':'document',(string)$_FILES['file']['name'],$stored,null,$destination); return;
					} elseif ($type==='csv') {
						$stored=$this->Receive_Knowledge_Csv();
						$this->Queue_Source_Import('csv',(string)($_FILES['file']['name']??$stored),$stored,null,$destination); return;
					} elseif ($type==='url') {
						$this->Queue_Source_Import('url','',null,$this->input->post('source_url'),$destination); return;
					} else {
						$title=$this->input->post('title'); $text=$this->input->post('content');
						if (!is_string($title) || trim($title)==='' || !is_string($text) || trim($text)==='' || strlen($text)>60000) { throw new Exception('Enter a title and content of at most 60,000 bytes.'); }
						$row['Title']=$title; $row['Excerpt']=$text; $row['ExtractedText']=$text; $entries=array($row);
					}
					foreach ($entries as &$entry) { $entry['DestinationID']=$destination; } unset($entry);
					$count=$this->Faq_Knowledge_Source_Model->Create_Import(faq_source_unique_entries($entries));
					$this->session->set_flashdata('faq_success',$count.' knowledge source(s) uploaded and automatically approved. You can delete any source you do not want.');
					redirect(base_url('Faq?section=sources')); return;
				} else { throw new Exception('Invalid import step.'); }
			} catch(Exception $e) { $data['error']=$e->getMessage(); }
		}
		$this->load->view('layout/header',array('tab_title'=>'Knowledge Source | Add Source','breadcrumb_title'=>'Knowledge Source >> Add Source'));
		$this->load->view('faq_suggestion/source_wizard',$data); $this->load->view('layout/footer');
	}

	private function Queue_Source_Import($type, $name, $stored, $url, $destination)
	{
		$this->load->model('Faq_Knowledge_Import_Model');
		$id=$this->Faq_Knowledge_Import_Model->Queue($type,$name,$stored,$url,$destination);
		$this->Prune_Logs();
		if (!$this->Spawn_Worker($id,true)) {
			$this->Faq_Knowledge_Import_Model->Fail($id,'Could not start source extraction. The server needs background worker support.');
		}
		redirect(base_url('Faq_Suggestion/Source_Import?id=').$id);
	}

	function Source_Import()
	{
		if (!$this->Can_Manage_Sources()) { show_error('Source manager access required.',403); return; }
		$this->load->model('Faq_Knowledge_Import_Model');
		$job=$this->Faq_Knowledge_Import_Model->Read($this->input->get('id'),(int)$this->session->userdata('admin_id'));
		if (!$job) { show_404(); return; }
		if ($job->State==='done') {
			$this->session->set_flashdata('faq_success',(int)$job->SourceCount.' knowledge source(s) uploaded and automatically approved. You can delete any source you do not want.');
			redirect(base_url('Faq?section=sources')); return;
		}
		$this->load->view('layout/header',array('tab_title'=>'Knowledge Source | Upload Progress','breadcrumb_title'=>'Knowledge Source >> Upload Progress'));
		$this->load->view('faq_suggestion/source_import',array('job'=>$job)); $this->load->view('layout/footer');
	}

	function Source_Import_Status()
	{
		if (!$this->Can_Manage_Sources()) { show_error('Source manager access required.',403); return; }
		$staff=(int)$this->session->userdata('admin_id');
		if (function_exists('session_write_close')) { session_write_close(); }
		$this->load->model('Faq_Knowledge_Import_Model');
		$job=$this->Faq_Knowledge_Import_Model->Read($this->input->get('id'),$staff);
		if (!$job) { show_404(); return; }
		$this->output->set_content_type('application/json')->set_header('Cache-Control: no-store')->set_output(json_encode(
			array('state'=>$job->State,'count'=>(int)$job->SourceCount,'error'=>(string)$job->ErrorMessage)));
	}

	function Source_Detail()
	{
		if (!$this->Can_Manage_Sources()) { show_error('Source manager access required.',403); return; }
		$s=$this->Faq_Knowledge_Source_Model->Read((int)$this->input->get('id')); if (!$s) { show_404(); return; }
		$this->load->view('layout/header',array('tab_title'=>'Knowledge Source | View','breadcrumb_title'=>'Knowledge Source >> View'));
		$this->load->view('faq_suggestion/source_detail',array('source'=>$s,'products'=>$this->Faq_Workspace_Model->Products(),'return_query'=>$this->Source_List_Query())); $this->load->view('layout/footer');
	}

	function Review_Source()
	{
		if (!$this->Can_Manage_Sources()) { show_error('Source manager access required.',403); return; }
		show_error('Knowledge sources are approved automatically and cannot be edited. Delete the source and upload a replacement.',405);
	}

	function Delete_Source()
	{
		if (!$this->Can_Manage_Sources()) { show_error('Source manager access required.',403); return; }
		if (!$this->Require_Workspace_Post()) { return; }
		try {
			$id=$this->input->post('source_id');
			if (!is_string($id) || !preg_match('/^[1-9][0-9]*$/D',$id)) { throw new Exception('Invalid source ID.'); }
			$this->Faq_Knowledge_Source_Model->Delete((int)$id);
			$this->session->set_flashdata('faq_success','Source deleted. Suggestions using this source have been checked.');
		} catch (Exception $e) { $this->session->set_flashdata('faq_error',$e->getMessage()); }
		redirect(base_url('Faq').'?'.http_build_query($this->Source_List_Query(true)));
	}

	private function Source_List_Query($post=false)
	{
		$method=$post?'post':'get';
		$batch=filter_var($this->input->$method('batch_id'),FILTER_VALIDATE_INT,array('options'=>array('min_range'=>0,'max_range'=>4294967295)));
		$page=$this->input->$method('page'); $page=is_scalar($page)?max(1,(int)$page):1;
		return array('section'=>'sources','batch_id'=>$batch===false?0:$batch,'destination_id'=>faq_workspace_source_destination_filter($this->input->$method('destination_id')),'page'=>$page,'page_size'=>faq_workspace_page_size($this->input->$method('page_size')),'search'=>faq_workspace_search($this->input->$method('search')));
	}

	function Source_File()
	{
		if (!$this->Can_View() && !$this->Can_Manage_Sources()) { show_error('FAQ access required.',403); return; }
		$s=$this->Faq_Knowledge_Source_Model->Read((int)$this->input->get('id'));
		if (!$s || (!$this->Can_Manage_Sources() && $s->Status!=='approved') || !$s->StoredName || !preg_match('/^[a-zA-Z0-9_-]+\.(pdf|csv|docx|txt)$/D',$s->StoredName)) { show_404(); return; }
		$path=FCPATH.Faq_Knowledge_Source_Model::UPLOAD_DIR.$s->StoredName;
		if (!is_file($path)) { show_404(); return; }
		$extension=pathinfo($s->StoredName,PATHINFO_EXTENSION);
		$mimes=array('pdf'=>'application/pdf','csv'=>'text/csv','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','txt'=>'text/plain');
		$this->output->set_content_type($mimes[$extension])->set_header('X-Content-Type-Options: nosniff')
			->set_header('Content-Disposition: '.($extension==='pdf'?'inline':'attachment').'; filename="source.'.$extension.'"')->set_output(file_get_contents($path));
	}

	// Keep existing links working; Update is the combined editing and review screen.
	function Candidate()
	{
		if (!$this->Can_View() && !$this->Can_Edit()) { show_error('FAQ view access required.',403); return; }
		redirect(base_url('Faq_Suggestion/Update?id=').(int)$this->input->get('id'));
	}

	private function Selected_Ids()
	{
		$raw=$this->input->post('suggestion_ids');
		if (!is_array($raw) || !$raw || count($raw)>100) { throw new Exception('Select between 1 and 100 suggestions.'); }
		$ids=array();
		foreach ($raw as $id) {
			if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]*$/D',(string)$id)) { throw new Exception('Invalid suggestion selection.'); }
			$ids[(int)$id]=(int)$id;
		}
		return array_values($ids);
	}

	function Bulk_Action()
	{
		if (!$this->Can_Edit()) { show_error('FAQ edit access required.',403); return; }
		if (!$this->Require_Workspace_Post()) { return; }
		try {
			$ids=$this->Selected_Ids(); $action=$this->input->post('action');
			if (!is_string($action) || !in_array($action,array('approve','reject','delete','reevaluate'),true)) { throw new Exception('Select a valid action.'); }
			if ($action==='reevaluate') {
				if (count($ids)>10) { throw new Exception('Re-evaluate up to 10 suggestions at a time.'); }
				$selected=array();
				foreach ($ids as $id) {
					$s=$this->Faq_Suggestion_Model->Read($id);
					if (!$s || in_array($s->State,array('accepted','dismissed'),true)) { throw new Exception('Select active pending suggestions for re-evaluation.'); }
					$selected[]=$s;
				}
				$this->Show_Reevaluation($selected); return;
			}
			$done=0; $errors=array();
			foreach ($ids as $id) {
				try {
					$s=$this->Faq_Suggestion_Model->Read($id); if (!$s) { throw new Exception('Suggestion not found.'); }
					if ($action==='approve') { $this->Faq_Workspace_Model->Publish($id); }
					elseif ($action==='reject') { $this->Faq_Workspace_Model->Dismiss_Candidate($id); }
					elseif ($action==='delete') { $this->Faq_Suggestion_Model->Delete($id); }
					$done++;
				} catch (Exception $e) { $errors[]='#'.$id.': '.$e->getMessage(); }
			}
			if ($done) { $this->session->set_flashdata('faq_success',$done.' suggestion(s) updated.'); }
			if ($errors) { $this->session->set_flashdata('faq_error',implode(' ',$errors)); }
		} catch (Exception $e) { $this->session->set_flashdata('faq_error',$e->getMessage()); }
		redirect(base_url('Faq?section=suggestions'));
	}

	function Reevaluate()
	{
		if (!$this->Can_Edit()) { show_error('FAQ edit access required.',403); return; }
		if (!$this->Require_Workspace_Post()) { return; }
		try {
			$ids=$this->Selected_Ids(); if (count($ids)>10) { throw new Exception('Re-evaluate up to 10 suggestions at a time.'); }
			$details=$this->input->post('details'); if (!is_array($details)) { throw new Exception('Provide additional information for the selected suggestions.'); }
			$done=0; $errors=array(); $requests=array();
			@set_time_limit(600);
			foreach ($ids as $id) {
				try {
					$d=isset($details[$id])?$details[$id]:null;
					if (!is_array($d) || !isset($d['version'],$d['additional']) || !is_string($d['version']) || !is_string($d['additional'])) { throw new Exception('Invalid additional information.'); }
					$s=$this->Faq_Suggestion_Model->Read($id); if (!$s) { throw new Exception('Suggestion not found.'); }
					if (!hash_equals(faq_workspace_review_version($s),$d['version'])) { throw new Exception('Suggestion changed; reload before re-evaluating.'); }
					$requests[]=array('id'=>$id,'additional'=>$d['additional'],'include_more'=>isset($d['more_messages'])&&$d['more_messages']==='1','version'=>$d['version']);
				} catch (Exception $e) { $errors[]='#'.$id.': '.$e->getMessage(); }
			}
			if ($requests) { $result=$this->Faq_Workspace_Model->Reevaluate_Batch($requests); $done=$result['done']; $errors=array_merge($errors,$result['errors']); }
			if ($done) { $this->session->set_flashdata('faq_success',$done.' suggestion(s) re-evaluated. Review the updated answers before approval.'); }
			if ($errors) { $this->session->set_flashdata('faq_error',implode(' ',$errors)); }
		} catch (Exception $e) { $this->session->set_flashdata('faq_error',$e->getMessage()); }
		redirect(isset($ids)&&count($ids)===1?base_url('Faq_Suggestion/Update?id=').$ids[0]:base_url('Faq?section=suggestions'));
	}

	private function Show_Reevaluation($suggestions)
	{
		$previews=array(); foreach ($suggestions as $s) { $previews[(int)$s->SuggestionID]=$this->Faq_Workspace_Model->Knowledge_For_Candidate($s->SuggestionID,'',true)['selection']; }
		$this->load->view('layout/header',array('tab_title'=>'AI Suggestion | Re-evaluate','breadcrumb_title'=>'AI Suggestion >> Re-evaluate'));
		$this->load->view('faq_suggestion/reevaluate',array('suggestions'=>$suggestions,'knowledge_previews'=>$previews,'can_manage_sources'=>$this->Can_Manage_Sources()));
		$this->load->view('layout/footer');
	}

	/** Read-only preview uses the same retrieval as evaluation, including unsaved questions. */
	function Knowledge_Preview()
	{
		if (!$this->Can_View() && !$this->Can_Edit()) { show_error('FAQ view access required.',403); return; }
		if (!$this->Require_Workspace_Post()) { return; }
		$this->output->set_content_type('application/json');
		try {
			$id=$this->input->post('suggestion_id'); $additional=$this->input->post('additional')??'';
			if (!is_string($id) || !ctype_digit($id) || (int)$id<1) { throw new Exception('Invalid suggestion.'); }
			$edited=null; $title=$this->input->post('title'); $questions=$this->input->post('questions');
			if ($title!==null || $questions!==null) {
				if (!is_string($title) || strlen($title)>1024 || !is_array($questions) || count($questions)>50) { throw new Exception('Invalid question preview.'); }
				$items=array(); foreach ($questions as $q) { if (!is_string($q) || strlen($q)>4000) { throw new Exception('Invalid question preview.'); } $items[]=array('q'=>$q); }
				$edited=array('title'=>$title,'items'=>$items);
				if ($this->input->post('destination_ids_present')==='1') { $edited['destination_ids']=Faq_Model::Normalize_Ids($this->input->post('destination_ids')); }
			}
			$knowledge=$this->Faq_Workspace_Model->Knowledge_For_Candidate((int)$id,$additional,$this->input->post('more_messages')==='1',$edited);
			echo json_encode(array('ok'=>true,'selection'=>$knowledge['selection'],'can_manage_sources'=>$this->Can_Manage_Sources()),JSON_UNESCAPED_UNICODE);
		} catch (Exception $e) { echo json_encode(array('ok'=>false,'error'=>$e->getMessage()),JSON_UNESCAPED_UNICODE); }
	}

	// Ajax: return the exact text sent to the AI for one run (the transcript),
	// loaded on demand by the "AI Input" modal on the runs listing so the grid
	// query never has to carry the (large) transcript for every row.
	function Input()
	{
		if (function_exists('session_write_close')) {
			@session_write_close();
		}
		$this->output->set_content_type('application/json');
		if (!$this->Can_View()) {
			echo json_encode(array('ok' => false, 'input' => ''));
			return;
		}
		$run = $this->Faq_Suggestion_Model->Read_Run((int) $this->input->get('id'));
		if ($run === null) {
			echo json_encode(array('ok' => false, 'input' => ''));
			return;
		}
		echo json_encode(array(
			'ok'    => true,
			'scope' => (string) $run->Scope,
			'input' => (string) $run->InputText,
		));
	}

	function Update()
	{
		if (!$this->Can_View() && !$this->Can_Edit()) { show_error('FAQ view access required.',403); return; }
		$id=(int)$this->input->get('id');
		if ($this->input->post()) {
			if (!$this->Can_Edit()) { show_error('FAQ edit access required.',403); return; }
			if (!$this->Require_Workspace_Post()) { return; }
			$post_id=(int)$this->input->post('suggestion_id');
			$suggestion=$this->Faq_Suggestion_Model->Read($post_id);
			if (!$suggestion) { show_404(); return; }
			$action=$this->input->post('review_action'); $action=$action===null?'save':$action;
			try {
				if (!is_string($action) || !in_array($action,array('save','approve','reevaluate'),true)) { throw new Exception('Choose a valid update action.'); }
				$completed=in_array($suggestion->State,array('accepted','dismissed'),true);
				if ($completed && $action!=='approve') { throw new Exception('This suggestion is completed and cannot be edited.'); }
				$expected=$this->input->post('expected_version');
				if ($expected!==null && (!is_string($expected) || !hash_equals(faq_workspace_review_version($suggestion),$expected))) { throw new Exception('Suggestion changed; reload before saving.'); }
				if (!$completed) { $error=$this->Save_From_Post($post_id); if ($error!==true) { throw new Exception($error); } }
				if ($action==='approve') {
					$this->Faq_Workspace_Model->Publish($post_id);
					$this->session->set_flashdata('faq_success',$completed?'Suggestion approved into the FAQ Library.':'Suggestion saved and approved into the FAQ Library.');
				} elseif ($action==='reevaluate') {
					$this->Show_Reevaluation(array($this->Faq_Suggestion_Model->Read($post_id))); return;
				} else { $this->session->set_flashdata('faq_success','Suggestion updated.'); }
			} catch (Exception $e) { $this->session->set_flashdata('faq_error',$e->getMessage()); }
			redirect(base_url('Faq_Suggestion/Update?id=').$post_id); return;
		}

		$suggestion=$this->Faq_Suggestion_Model->Read($id);
		if (!$suggestion) { show_404(); return; }
		if (!in_array($suggestion->State,array('accepted','dismissed'),true)) {
			$this->Faq_Workspace_Model->Refresh($id); $suggestion=$this->Faq_Suggestion_Model->Read($id);
		}
		$data=array('suggestion'=>$suggestion,'can_edit'=>$this->Can_Edit(),'can_manage_sources'=>$this->Can_Manage_Sources(),
			'run_url'=>base_url('Faq?section=suggestions'),'evidence'=>$this->Faq_Suggestion_Model->Read_Evidence($id),
			'run'=>!empty($suggestion->RunID)?$this->Faq_Suggestion_Model->Read_Run((int)$suggestion->RunID):null,
			'items'=>Faq_Model::Decode_Items($suggestion->Description),'tags'=>$this->Faq_Tag_Model->Read_Active(),
			'destinations'=>$this->Faq_Model->Read_Destinations(),'faq_titles'=>$this->Faq_Model->Read_Titles(),
			'selected_destination_ids'=>Faq_Suggestion_Model::Parse_Id_Csv($suggestion->DestinationIds),
			'audit'=>$this->Faq_Workspace_Model->Read_Review_History($id),
			'faq_comparison'=>$this->Faq_Workspace_Model->Read_FAQ_Comparison($id),
			'knowledge_preview'=>$this->Faq_Workspace_Model->Knowledge_For_Candidate($id)['selection'],
			'knowledge_last'=>$this->Faq_Workspace_Model->Last_Knowledge_Selection($id));
		$this->load->view('layout/header',array('tab_title'=>'HolidayGoGoGo | Update FAQ Suggestion','breadcrumb_title'=>'FAQ AI Suggestion >> Update'));
		$this->load->view('faq_suggestion/form',$data); $this->load->view('layout/footer');
	}

	// Promote a suggestion to a real FAQ. If its (possibly edited) Title matches an
	// existing active FAQ, the suggestion's sub-Q&As are FOLDED INTO that FAQ (its
	// items appended, destinations unioned) — this is what the Title picker's
	// existing-title options are for. Otherwise a new FAQ is created. Either way the
	// suggestion is flagged 'accepted' with a link to the target FAQ (kept for audit).
	function Accept()
	{
		if (!$this->Can_Edit()) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}
		if (!$this->Require_Workspace_Post()) { return; }
		$id=(int)$this->input->post('suggestion_id');
		$suggestion=$this->Faq_Suggestion_Model->Read($id);
		$run_id=(int)$this->input->post('run_id');
		$back=$suggestion && $run_id>0 && $run_id===(int)$suggestion->RunID?base_url('Faq_Suggestion/View?id=').$run_id:base_url('Faq_Suggestion/Update?id=').$id;
		try { $this->Faq_Workspace_Model->Publish($id); $this->session->set_flashdata('faq_success','Suggestion approved into the FAQ Library.'); }
		catch (Exception $e) { $this->session->set_flashdata('faq_error',$e->getMessage()); }
		redirect($back);
	}

	function Dismiss()
	{
		if (!$this->Can_Edit()) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}
		if (!$this->Require_Workspace_Post()) { return; }
		$id = (int) $this->input->post('suggestion_id');
		$suggestion = $this->Faq_Suggestion_Model->Read($id);
		$back = base_url('Faq_Suggestion/Update?id=').$id;
		if ($suggestion !== null) {
			try { $this->Faq_Workspace_Model->Dismiss_Candidate($id); $this->session->set_flashdata('faq_success', 'Suggestion dismissed.'); }
			catch (Exception $e) { $this->session->set_flashdata('faq_error',$e->getMessage()); }
		}
		redirect($back);
	}

	// Soft-delete, wired to the shared Delete_Record() ajax helper (GET ?id=).
	function Delete()
	{
		if (!$this->Can_Edit()) {
			return;
		}
		if (!$this->Require_Workspace_Post()) { return; }
		$id = (int) $this->input->post('id');
		$suggestion=$this->Faq_Suggestion_Model->Read($id);
		$run_id=(int)$this->input->post('run_id');
		$back=$suggestion && $run_id>0 && $run_id===(int)$suggestion->RunID?base_url('Faq_Suggestion/View?id=').$run_id:base_url('Faq_Suggestion');
		if ($suggestion !== null) {
			$this->Faq_Suggestion_Model->Delete($id);
		}
		redirect($back);
	}

	// Manual "Generate" — queues AI mining over the reviewer's
	// chosen date range, optionally scoped to a single mobile number.
	function Generate()
	{
		if (!$this->Can_Edit()) {
			redirect(base_url('Faq?section=suggestions'));
			return;
		}
		if (!$this->input->post()) {
			redirect(base_url('Faq?section=suggestions'));
			return;
		}
		if (!$this->Require_Workspace_Post()) { return; }

		$this->load->helper('faq_suggestion');
		$range = faq_suggestion_date_range($this->input->post('start_date'), $this->input->post('end_date'));
		if ($range['error'] !== '') {
			$this->session->set_flashdata('faq_error', $range['error']);
			redirect(base_url('Faq?section=suggestions'));
			return;
		}
		$mobile = trim((string) $this->input->post('mobile'));

		$run_id = $this->Faq_Suggestion_Model->Queue_Run(array(
			'Source'    => 'chats',
			'StartDate' => substr($range['start'], 0, 10),
			'EndDate'   => substr($range['end'], 0, 10),
			'Mobile'    => $mobile,
		));

		$scope = date('j M Y', strtotime($range['start'])) . ' – ' . date('j M Y', strtotime($range['end']));
		if ($mobile !== '') {
			$scope .= ' for ' . $mobile;
		}
		$this->Dispatch_Run($run_id, 'chats', $scope);
		redirect(base_url('Faq_Suggestion/View?id=').$run_id);
	}

	// Manual "Generate from PDF" — upload a document and mine FAQs from it.
	function Generate_Pdf()
	{
		if (!$this->Can_Edit()) {
			redirect(base_url('Faq?section=suggestions'));
			return;
		}
		if ($this->Post_Exceeded_Limit()) {
			$this->Flash_Upload_Too_Large();
			redirect(base_url('Faq?section=suggestions'));
			return;
		}
		if (!$this->Require_Workspace_Post()) { return; }
		if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
			$this->session->set_flashdata('faq_error', 'Please choose a PDF file to upload.');
			redirect(base_url('Faq?section=suggestions'));
			return;
		}

		try {
			$info = $this->Receive_Pdf();
		} catch (Exception $e) {
			$this->session->set_flashdata('faq_error', $e->getMessage());
			redirect(base_url('Faq?section=suggestions'));
			return;
		}

		$run_id = $this->Faq_Suggestion_Model->Queue_Run(array(
			'Source'     => 'pdf',
			'FileName'   => $info['orig_name'],
			'StoredName' => $info['file_name'],
		));
		$this->Dispatch_Run($run_id, 'pdf', $info['orig_name']);
		redirect(base_url('Faq_Suggestion/View?id=').$run_id);
	}

	// Manual "Generate from Chat File" — upload a WhatsApp .txt export (or a .zip
	// bundling several) and mine FAQs from the conversation. Same background/inline
	// dispatch as the other generators.
	function Generate_Chat_File()
	{
		if (!$this->Can_Edit()) {
			redirect(base_url('Faq?section=suggestions'));
			return;
		}
		if ($this->Post_Exceeded_Limit()) {
			$this->Flash_Upload_Too_Large();
			redirect(base_url('Faq?section=suggestions'));
			return;
		}
		if (!$this->Require_Workspace_Post()) { return; }
		if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
			$this->session->set_flashdata('faq_error', 'Please choose a .txt or .zip chat export to upload.');
			redirect(base_url('Faq?section=suggestions'));
			return;
		}

		try {
			$info = $this->Receive_Chat_File();
		} catch (Exception $e) {
			$this->session->set_flashdata('faq_error', $e->getMessage());
			redirect(base_url('Faq?section=suggestions'));
			return;
		}

		$run_id = $this->Faq_Suggestion_Model->Queue_Run(array(
			'Source'     => 'chatfile',
			'FileName'   => $info['orig_name'],
			'StoredName' => $info['file_name'],
		));
		$this->Dispatch_Run($run_id, 'chatfile', $info['orig_name']);
		redirect(base_url('Faq_Suggestion/View?id=').$run_id);
	}

	/**
	 * Validate + store an uploaded chat export (.txt or .zip) under the same
	 * faq_suggestion upload dir the worker reads. Validation reuses the shared
	 * chat_history rules (.txt <=5MB, .zip <=30MB); the file is moved manually
	 * (not via CI's upload lib, whose .txt MIME detection is unreliable) with a
	 * unique name that KEEPS the real extension so the worker can tell txt from
	 * zip. Returns ['orig_name'=>, 'file_name'=>]. Throws on any problem.
	 */
	private function Receive_Chat_File()
	{
		$this->load->helper('chat_history');
		$file = $_FILES['file'];
		$orig = (string) $file['name'];
		$check = chat_history_validate_upload($orig, (int) $file['size']);
		if (!$check['ok']) {
			throw new Exception($check['error']);
		}
		if ($file['error'] !== UPLOAD_ERR_OK) {
			throw new Exception('Upload failed. Please try again.');
		}

		$dir = FCPATH . 'assets/upload/faq_suggestion/';
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$ext    = strtolower(pathinfo($orig, PATHINFO_EXTENSION)); // 'txt' or 'zip' (validated)
		$stored = 'chat_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
		if (!move_uploaded_file($file['tmp_name'], $dir . $stored)) {
			throw new Exception('Could not save the uploaded file.');
		}
		return array('orig_name' => $orig, 'file_name' => $stored);
	}

	// Soft-delete a whole run (and its suggestions), wired to Delete_Record().
	function Delete_Run()
	{
		if (!$this->Can_Edit()) {
			return;
		}
		if (!$this->Require_Workspace_Post()) { return; }
		$id = (int) $this->input->post('id');
		if ($this->Faq_Suggestion_Model->Read_Run($id) !== null) {
			$this->Faq_Suggestion_Model->Delete_Run($id);
		}
		redirect(base_url('Faq?section=history'));
	}

	/**
	 * True when this POST was silently dropped for exceeding post_max_size: PHP
	 * empties $_POST and $_FILES but the request still carried a body
	 * (CONTENT_LENGTH > 0). Lets the upload endpoints show a clear "too large for
	 * the server" message instead of a misleading "please choose a file".
	 */
	private function Post_Exceeded_Limit()
	{
		if (strtoupper((string) $this->input->server('REQUEST_METHOD')) !== 'POST') {
			return false;
		}
		$len = (int) $this->input->server('CONTENT_LENGTH');
		return $len > 0 && empty($_POST) && empty($_FILES);
	}

	/** Flash a "server upload limit exceeded" message with the current caps. */
	private function Flash_Upload_Too_Large()
	{
		$this->session->set_flashdata('faq_error',
			'The file is too large for the server to accept (current limits: upload ' . ini_get('upload_max_filesize')
			. ', post ' . ini_get('post_max_size') . '). Ask the server admin to raise upload_max_filesize / post_max_size, or upload a smaller file.');
	}

	/** Human label for a run source, shared by the flash messages. */
	private function Source_Label($source)
	{
		if ($source === 'pdf')      { return 'PDF'; }
		if ($source === 'chatfile') { return 'chat file'; }
		return 'chats';
	}

	/** Flash a success / failure message for a completed generation run. */
	private function Flash_Summary($summary, $source, $scope)
	{
		if ($summary['reason'] === 'error') {
			$this->session->set_flashdata('faq_error', 'Generation failed: '.(!empty($summary['error']) ? $summary['error'] : 'Please open the run for details.'));
		} elseif ((int) $summary['created'] > 0) {
			$this->session->set_flashdata('faq_success', 'Generated ' . (int) $summary['created'] . ' new FAQ suggestion(s) from ' . $this->Source_Label($source) . ' (' . $scope . ').');
		} elseif ($summary['reason'] === 'no_messages') {
			$this->session->set_flashdata('faq_error', 'No usable messages found (' . $scope . ').');
		} else {
			$this->session->set_flashdata('faq_error', 'No new suggestions this time (the AI found nothing beyond what already exists).');
		}
	}

	/**
	 * Run a queued run in the background (detached CLI worker) so the slow OpenAI
	 * call doesn't block the request. When exec() is unavailable the run is
	 * processed inline instead (blocks, but still completes). Flashes an
	 * appropriate message either way.
	 */
	protected function Dispatch_Run($run_id, $source, $scope)
	{
		$this->Prune_Logs();
		if ($this->Spawn_Worker($run_id)) {
			$this->session->set_flashdata('faq_success', 'Generation started in the background from ' . $this->Source_Label($source) . ' (' . $scope . '). This page updates as it finishes.');
			return;
		}
		// No process spawn available — process inline as a fallback. This runs under
		// the web SAPI's memory_limit, so a large PDF can OOM; raise it up front
		// (Process_Run also raises it defensively for the PDF path).
		@set_time_limit(600);
		$this->load->helper('faq_suggestion');
		@ini_set('memory_limit', faq_suggestion_memory_limit(get_env('FAQ_SUGGESTION_MEMORY_LIMIT')));
		$summary = $this->Faq_Suggestion_Model->Process_Run($run_id);
		$this->Flash_Summary($summary, $source, $scope);
	}

	/**
	 * Spawn the detached CLI worker for a run. Returns true if launched, false
	 * when exec() is unavailable (caller then falls back to inline processing).
	 */
	private function Spawn_Worker($run_id, $knowledge_import=false)
	{
		if (!function_exists('exec')) {
			return false;
		}
		$log_dir = APPPATH . 'logs/faq_suggestion/';
		if (!is_dir($log_dir)) {
			@mkdir($log_dir, 0755, true);
		}
		$php   = $this->Php_Cli_Bin();
		$index = FCPATH . 'index.php';
		$worker=$knowledge_import?'Faq_Knowledge_Import_Job':'Faq_Suggestion_Job';
		$job_id=$knowledge_import?(string)$run_id:(string)(int)$run_id;
		$out   = $log_dir . ($knowledge_import?'source_':'run_') . $job_id . '.out';
		// Memory ceiling for the worker — a big PDF needs several full-size copies of
		// the file in memory (raw + base64 + data URI + JSON), so default to 1024M
		// and let .env FAQ_SUGGESTION_MEMORY_LIMIT override.
		$this->load->helper('faq_suggestion');
		$mem = faq_suggestion_memory_limit(get_env('FAQ_SUGGESTION_MEMORY_LIMIT'));
		// pcre.jit=0 silences a PCRE-JIT warning in the sandboxed child; the
		// controller name MUST match the file case (Linux is case-sensitive).
		// `& echo $!` backgrounds the worker and prints its PID.
		$cmd = escapeshellarg($php) . ' -d pcre.jit=0 -d memory_limit=' . escapeshellarg($mem) . ' '
			. escapeshellarg($index) . ' ' . escapeshellarg($worker) . ' run ' . escapeshellarg($job_id)
			. ' > ' . escapeshellarg($out) . ' 2>&1 & echo $!';
		$pid = (int) @exec($cmd);
		return $pid > 0;
	}

	/**
	 * Delete worker log (.out) files older than 3 days, at most once per day.
	 * A tiny stamp file throttles it so this runs (opportunistically, on the next
	 * generation of the day) without needing a server crontab.
	 */
	private function Prune_Logs()
	{
		$dir = APPPATH . 'logs/faq_suggestion/';
		if (!is_dir($dir)) {
			return;
		}
		// Throttle to once per calendar day.
		$stamp = $dir . '.last_prune';
		$today = date('Y-m-d');
		if (is_file($stamp) && trim((string) @file_get_contents($stamp)) === $today) {
			return;
		}
		@file_put_contents($stamp, $today);

		$this->load->helper('faq_suggestion');
		$files = array();
		foreach ((array) glob($dir . '{run_,source_}*.out',GLOB_BRACE) as $path) {
			$files[] = array('path' => $path, 'mtime' => (int) @filemtime($path));
		}
		foreach (faq_suggestion_logs_to_prune($files, time(), 3) as $path) {
			@unlink($path);
		}
	}

	/** Resolve the PHP CLI binary (env override, then common paths, then 'php'). */
	private function Php_Cli_Bin()
	{
		$env = get_env('PHP_CLI_BIN');
		if ($env) {
			return $env;
		}
		$cands = array();
		if (defined('PHP_BINDIR') && PHP_BINDIR) {
			$cands[] = rtrim(PHP_BINDIR, '/') . '/php';
		}
		$cands = array_merge($cands, array('/opt/homebrew/bin/php', '/usr/local/bin/php', '/usr/bin/php'));
		foreach ($cands as $c) {
			if (@is_executable($c)) {
				return $c;
			}
		}
		return 'php';
	}

	// Ajax poll for the runs listing: returns each run's live status so the page
	// can update in place while a background run is queued / running.
	function Runs_Status()
	{
		if (!$this->input->is_ajax_request()) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}
		// Read-only endpoint — release the session lock so polling doesn't
		// serialize behind other requests from the same session.
		if (function_exists('session_write_close')) {
			@session_write_close();
		}
		$this->output->set_content_type('application/json');
		if (!$this->Can_View()) {
			echo json_encode(array('running' => false, 'runs' => array()));
			return;
		}

		$running = false;
		$out = array();
		foreach ($this->Faq_Suggestion_Model->Read_Runs() as $r) {
			$state = strtolower((string) $r->RunState);
			if ($state === 'queued' || $state === 'running') {
				$running = true;
			}
			$out[] = array(
				'RunID'        => (int) $r->RunID,
				'Source'       => (string) $r->Source,
				'RunState'     => $state,
				'Source'       => strtolower((string) $r->Source),
				'FileName'     => (string) $r->FileName,
				'HasInput'     => !empty($r->HasInput),
				'Created'      => (int) $r->Created,
				'Proposed'     => (int) $r->Proposed,
				'PendingCount' => (int) $r->PendingCount,
				'CostUsd'      => ($r->CostUsd === null || $r->CostUsd === '') ? null : (float) $r->CostUsd,
				'ErrorMessage' => (string) $r->ErrorMessage,
			);
		}
		echo json_encode(array('running' => $running, 'runs' => $out));
	}

	/**
	 * Store the uploaded document and return CI's upload data (full_path,
	 * orig_name, file_ext). Throws Exception with a human message on any failure.
	 */
	private function Receive_Pdf()
	{
		$dir = FCPATH . 'assets/upload/faq_suggestion/';
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$config = array(
			'upload_path'   => $dir,
			'allowed_types' => 'pdf',
			'max_size'      => 20480, // 20 MB
			'encrypt_name'  => true,
		);
		$this->load->library('upload', $config);
		$this->upload->initialize($config);
		if (!$this->upload->do_upload('file')) {
			throw new Exception(trim(strip_tags($this->upload->display_errors('', ' '))));
		}
		return $this->upload->data();
	}

	private function Receive_Knowledge_Document($allowed_types)
	{
		$dir = FCPATH . Faq_Knowledge_Source_Model::UPLOAD_DIR; if (!is_dir($dir)) { @mkdir($dir,0755,true); }
		$config=array('upload_path'=>$dir,'allowed_types'=>$allowed_types,'max_size'=>20480,'encrypt_name'=>true,'file_ext_tolower'=>true); $this->load->library('upload',$config); $this->upload->initialize($config);
		if(!$this->upload->do_upload('file')) { throw new Exception(trim(strip_tags($this->upload->display_errors('',' ')))); }
		return $this->upload->data('file_name');
	}

	private function Receive_Knowledge_Csv()
	{
		$dir=FCPATH.Faq_Knowledge_Source_Model::UPLOAD_DIR; if (!is_dir($dir)) { @mkdir($dir,0755,true); }
		$config=array('upload_path'=>$dir,'allowed_types'=>'csv','max_size'=>2048,'encrypt_name'=>true);
		$this->load->library('upload',$config); $this->upload->initialize($config);
		if (!$this->upload->do_upload('file')) { throw new Exception(trim(strip_tags($this->upload->display_errors('',' ')))); }
		return $this->upload->data('file_name');
	}

	// Returns true on success, or an error message string on failure. Reuses the
	// FAQ item builder (tags + reference links, no per-item audit trail).
	private function Save_From_Post($id)
	{
		$review_status=$this->input->post('ReviewStatus');
		if ($review_status!==null && (!is_string($review_status) || !in_array($review_status,array('draft_ready','need_context'),true))) {
			return 'Choose Pending Approval or Needs Information.';
		}
		$title = trim((string) $this->input->post('Title'));
		if ($title === '') {
			return 'Failed to save. Title is required.';
		}

		$sub_tags = $this->input->post('sub_tags');
		$sub_tags = is_array($sub_tags) ? array_values($sub_tags) : array();
		$link_labels = $this->input->post('sub_link_labels');
		$link_urls   = $this->input->post('sub_link_urls');
		$link_labels = is_array($link_labels) ? array_values($link_labels) : array();
		$link_urls   = is_array($link_urls)   ? array_values($link_urls)   : array();

		$built = Faq_Model::Build_Items(
			$this->input->post('sub_questions'),
			$this->input->post('sub_answers'),
			null, '', '',
			$sub_tags, $link_labels, $link_urls, true
		);
		if ($built['error'] !== null) {
			return $built['error'];
		}
		if (empty($built['items'])) {
			return 'Add at least one sub-question.';
		}

		$destination_ids = Faq_Model::Normalize_Ids($this->input->post('Destinations'));
		try { $this->Faq_Workspace_Model->Edit_Draft($id, array(
			'Title'          => $title,
			'Description'    => Faq_Model::Encode_Items($built['items']),
			'DestinationIds' => implode(',', $destination_ids),
		), $review_status); } catch (Exception $e) { return $e->getMessage(); }
		return true;
	}
}
