<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Faq_Knowledge_Source_Model extends CI_Model
{
	const UPLOAD_DIR = 'assets/upload/faq_knowledge_sources/';

	function Listing($page=1, $batch_id=0, $page_size=100, $search='', $destination_id=0) {
		$this->load->helper('faq_workspace');
		$batch_id=filter_var($batch_id,FILTER_VALIDATE_INT,array('options'=>array('min_range'=>0,'max_range'=>4294967295)));
		$batch_id=$batch_id===false?0:$batch_id;
		$search=faq_workspace_search($search);
		$destination_id=faq_workspace_source_destination_filter($destination_id);
		$this->Filter_Sources($batch_id,$search,$destination_id);
		$pagination=faq_workspace_pagination($this->db->count_all_results('faq_knowledge_sources'),$page,$page_size);
		$this->Filter_Sources($batch_id,$search,$destination_id);
		$this->db->select('SourceID, BatchID, SourceType, Title, faq_knowledge_sources.Status, Topic, ResortName, RoomType, VerifiedDate, faq_knowledge_sources.InsertDate, DestinationID, category.Name AS DestinationName')
			->join('category','category.CategoryID = faq_knowledge_sources.DestinationID','left')->order_by('SourceID','DESC');
		if ($pagination['page_size']!==-1) { $this->db->limit($pagination['page_size'],$pagination['offset']); }
		$rows=$this->db->get('faq_knowledge_sources')->result();
		$batches=$this->db->select('BatchID, COUNT(*) AS SourceCount')->group_by('BatchID')->order_by('BatchID','DESC')->get('faq_knowledge_sources')->result();
		return array('sources'=>$rows,'batch_id'=>$batch_id,'batches'=>$batches,'search'=>$search,'destination_id'=>$destination_id)+$pagination;
	}

	private function Filter_Sources($batch_id, $search, $destination_id)
	{
		if ($batch_id) { $this->db->where('BatchID',$batch_id); }
		if ($destination_id==='none') { $this->db->group_start()->where('DestinationID',null)->or_where('DestinationID',0)->group_end(); }
		elseif ($destination_id) { $this->db->where('DestinationID',$destination_id); }
		if ($search==='') { return; }
		$this->db->group_start()->like('Title',$search);
		foreach (array('Excerpt','ResortName','RoomType','Topic','SourceUrl') as $field) { $this->db->or_like($field,$search); }
		$this->db->or_where('DestinationID IN (SELECT CategoryID FROM category WHERE Name LIKE '.$this->db->escape('%'.$this->db->escape_like_str($search).'%')." ESCAPE '!')",null,false);
		$this->db->group_end();
	}

	function For_Topic($topic, $lock = false) {
		return $lock ? $this->db->query('SELECT * FROM faq_knowledge_sources WHERE Topic = ? FOR UPDATE',array($topic))->result()
			: $this->db->where('Topic',$topic)->get('faq_knowledge_sources')->result();
	}
	function Read($id, $lock = false) {
		return $lock ? $this->db->query('SELECT * FROM faq_knowledge_sources WHERE SourceID = ? FOR UPDATE',array((int)$id))->row()
			: $this->db->select('faq_knowledge_sources.*, category.Name AS DestinationName')->join('category','category.CategoryID = faq_knowledge_sources.DestinationID','left')
				->where('SourceID',(int)$id)->get('faq_knowledge_sources')->row();
	}

	function Validate($data, $require_file = true)
	{
		$this->load->helper('faq_suggestion');
		foreach (array('SourceType','Title','SourceUrl','StoredName','Excerpt','ExtractedText','ResortName','RoomType','Topic','ValidFrom','ValidTo','ReviewDue') as $key) {
			if (isset($data[$key]) && !is_string($data[$key])) { throw new Exception('Invalid source field: '.$key); }
		}
		if (!isset($data['SourceType']) || !in_array($data['SourceType'],array('pdf','document','csv','url','manual','supplier_confirmation','approved_staff_reply'),true)) { throw new Exception('Invalid source type.'); }
		foreach (array('Title'=>255,'ResortName'=>255,'RoomType'=>255,'Topic'=>80,'SourceUrl'=>2048,'Excerpt'=>60000) as $key=>$max) {
			$data[$key]=isset($data[$key])?trim($data[$key]):'';
			if (strlen($data[$key])>$max) { throw new Exception($key.' is too long.'); }
		}
		if ($data['Title']==='' || !preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$data['Topic'])) { throw new Exception('Title and a valid lowercase topic are required.'); }
		if ($data['RoomType']!=='' && $data['ResortName']==='') { throw new Exception('A room source must name its resort.'); }
		$url=$data['SourceUrl'];
		if (($data['SourceType']==='url' && $url==='') || ($url!=='' && (!filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),array('http','https'),true) || parse_url($url,PHP_URL_USER) || parse_url($url,PHP_URL_PASS)))) {
			throw new Exception('Enter a valid official HTTP/HTTPS URL without credentials.');
		}
		foreach (array('ValidFrom','ValidTo','ReviewDue') as $key) {
			$value=isset($data[$key])?trim($data[$key]):'';
			if ($value!=='' && faq_suggestion_valid_date($value)==='') { throw new Exception('Invalid date: '.$key); }
			$data[$key]=$value!==''?$value:null;
		}
		if ($data['ValidFrom'] && $data['ValidTo'] && $data['ValidFrom']>$data['ValidTo']) { throw new Exception('Validity dates are reversed.'); }
		if (!isset($data['ProductID']) || (!is_string($data['ProductID']) && !is_int($data['ProductID'])) || !preg_match('/^[0-9]*$/D',(string)$data['ProductID'])) { throw new Exception('Invalid product ID.'); }
		$data['ProductID']=(int)$data['ProductID']?:null;
		if ($data['ProductID'] && !$this->db->where('ProductID',$data['ProductID'])->where('Status','Y')->get('product')->row()) { throw new Exception('Select an active existing package.'); }
		$data['DestinationID']=$this->Validate_Destination($data['DestinationID']??0);
		$data['StoredName']=!empty($data['StoredName'])?$data['StoredName']:null;
		if ($data['StoredName'] && (!preg_match('/^[a-zA-Z0-9_-]+\.(pdf|csv|docx|txt)$/D',$data['StoredName']) || basename($data['StoredName'])!==$data['StoredName'])) { throw new Exception('Invalid source filename.'); }
		if ($data['StoredName'] && in_array($data['SourceType'],array('pdf','csv'),true) && pathinfo($data['StoredName'],PATHINFO_EXTENSION)!==$data['SourceType']) { throw new Exception('Source file type does not match.'); }
		if ($data['StoredName'] && $data['SourceType']==='document' && !in_array(pathinfo($data['StoredName'],PATHINFO_EXTENSION),array('docx','txt'),true)) { throw new Exception('Source file type does not match.'); }
		if ($require_file && $data['SourceType']==='pdf' && (!$data['StoredName'] || !is_file(FCPATH.self::UPLOAD_DIR.$data['StoredName']))) { throw new Exception('Upload the official PDF.'); }
		if ($require_file && $data['SourceType']==='pdf') {
			$path=FCPATH.self::UPLOAD_DIR.$data['StoredName']; $mime=new finfo(FILEINFO_MIME_TYPE);
			if ($mime->file($path)!=='application/pdf' || filesize($path)>20480*1024) { throw new Exception('The official file must be a PDF no larger than 20 MB.'); }
		}
		if ($require_file && $data['SourceType']==='csv' && (!$data['StoredName'] || !is_file(FCPATH.self::UPLOAD_DIR.$data['StoredName']))) { throw new Exception('Upload the CSV file.'); }
		if ($require_file && $data['SourceType']==='document') {
			if (!$data['StoredName']) { throw new Exception('Upload a Word (.docx) or text (.txt) document.'); }
			$this->load->library('FaqSourceImport');
			$this->faqsourceimport->Document(FCPATH.self::UPLOAD_DIR.$data['StoredName']);
		}
		if (isset($data['ExtractedText']) && strlen($data['ExtractedText'])>2000000) { throw new Exception('Imported content is too large.'); }
		$data['FilePath']=$data['StoredName']?self::UPLOAD_DIR.$data['StoredName']:null;
		$data['AppliesToAllRooms']=!empty($data['AppliesToAllRooms'])?1:0;
		return array_intersect_key($data,array_flip(array('SourceType','Title','SourceUrl','StoredName','FilePath','Excerpt','ExtractedText','RetrievedDate','ProductID','DestinationID','ResortName','RoomType','Topic','ValidFrom','ValidTo','ReviewDue','AppliesToAllRooms')));
	}

	function Validate_Destination($value)
	{
		if ((!is_string($value) && !is_int($value)) || !preg_match('/^[0-9]+$/D',(string)$value) || (float)$value>2147483647) { throw new Exception('Invalid destination.'); }
		$id=(int)$value;
		if ($id && !$this->db->where('CategoryID',$id)->where('IsDestination','YES')->where('Status','Y')->get('category')->row()) { throw new Exception('Select an active existing destination.'); }
		return $id?:null;
	}

	function Create_Import($rows, $staff=null) {
		$this->load->model('Faq_Workspace_Model'); $validated=array();
		$staff=$staff===null?(int)$this->session->userdata('admin_id'):(int)$staff;
		$now=date('Y-m-d H:i:s');
		foreach ($rows as $row) { $row=$this->Validate($row); $validated[]=$row+$this->Automatic_Approval_Fields($row,$now,$staff); }
		if (!$validated) { return 0; }
		$this->db->trans_begin();
		try {
			// Lock one counter for the entire import. Failed imports also roll back the number.
			$result=$this->db->query('SELECT LastBatchID FROM faq_knowledge_source_batch_sequence WHERE SequenceID = 1 FOR UPDATE');
			$sequence=$result?$result->row():null;
			if (!$sequence) { throw new Exception('Knowledge source batch counter is unavailable. Apply the FAQ database update.'); }
			$batch_id=(int)$sequence->LastBatchID+1;
			if (!$this->db->where('SequenceID',1)->update('faq_knowledge_source_batch_sequence',array('LastBatchID'=>$batch_id))) { throw new Exception('Could not allocate the source batch number.'); }
			foreach ($validated as $row) {
				$row+=array('BatchID'=>$batch_id,'InsertBy'=>$staff,'InsertDate'=>$now,'UpdateBy'=>$staff,'UpdateDate'=>$now);
				$this->db->insert('faq_knowledge_sources',$row); $id=(int)$this->db->insert_id();
				$this->Faq_Workspace_Model->Audit(null,$id,'source_imported',null,$row,$staff);
			}
			if (!$this->db->trans_status()) { throw new Exception('Source import failed.'); }
			$this->db->trans_commit(); return count($validated);
		} catch(Exception $e) { $this->db->trans_rollback(); throw $e; }
	}

	private function Automatic_Approval_Fields($row, $now, $staff)
	{
		if ($row['Excerpt']==='') { throw new Exception('Knowledge sources must contain content.'); }
		if (!$staff) { throw new Exception('A staff identity is required to upload a knowledge source.'); }
		// Record automatic approval by the uploader. Applicability dates are checked during evaluation.
		return array('Status'=>'approved','VerifiedBy'=>$staff,'VerifiedDate'=>$now);
	}

	function Review($id, $state, $data)
	{
		throw new Exception('Knowledge sources are approved automatically and cannot be edited. Delete the source and upload a replacement.');
	}

	function Delete($id)
	{
		$this->load->model('Faq_Workspace_Model');
		$this->db->trans_begin();
		try {
			$old=$this->Read($id,true); if (!$old) { throw new Exception('Source not found.'); }
			$this->db->where('SourceID',(int)$id)->delete('faq_knowledge_sources');
			$this->Faq_Workspace_Model->Audit(null,(int)$id,'source_deleted',$old,null);
			$this->Faq_Workspace_Model->Source_Changed($old->Topic);
			if (!$this->db->trans_status()) { throw new Exception('Source deletion failed.'); }
			$this->db->trans_commit(); return true;
		} catch (Exception $e) { $this->db->trans_rollback(); throw $e; }
	}
}
