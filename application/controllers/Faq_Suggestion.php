<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * FAQ AI Suggestion — the review UI for the AI-mined candidate FAQs.
 *
 * A scheduled cron (Cron::generate_faq_suggestions) reads the last few days of
 * WhatsApp / GHL conversations and stores candidate FAQs in `faq_suggestions`.
 * Staff review them here: edit the same fields a FAQ carries, then Accept (create
 * a real FAQ, flag the suggestion 'accepted' for audit) or Dismiss. The heavy
 * lifting lives in Faq_Suggestion_Model + helpers/faq_suggestion_helper.php.
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
	}

	private function Can_View()
	{
		return (int) $this->session->level === 10 || in_array('FV', (array) $this->session->access_control);
	}

	private function Can_Edit()
	{
		return (int) $this->session->level === 10 || in_array('FE', (array) $this->session->access_control);
	}

	function index()
	{
		if (!$this->Can_View()) {
			redirect(base_url('Dashboard'));
			return;
		}
		$state = (string) $this->input->get('state');
		if (!in_array($state, array('pending', 'accepted', 'dismissed', 'all'), true)) {
			$state = 'pending';
		}

		$titles = array('tab_title' => 'HolidayGoGoGo | FAQ AI Suggestion', 'breadcrumb_title' => 'FAQ AI Suggestion');
		$data['suggestions']   = $this->Faq_Suggestion_Model->Read_Suggestions($state);
		$data['state']         = $state;
		$data['pending_count'] = $this->Faq_Suggestion_Model->Count_Pending();
		$data['can_edit']      = $this->Can_Edit();
		$this->load->view('layout/header', $titles);
		$this->load->view('faq_suggestion/index', $data);
		$this->load->view('layout/footer');
	}

	function Update()
	{
		if (!$this->Can_Edit()) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}
		$id = (int) $this->input->get('id');

		if ($this->input->post()) {
			$post_id = (int) $this->input->post('suggestion_id');
			if (!$this->Universal_Model->Validate_Id('SuggestionID', $post_id, 'faq_suggestions')) {
				redirect(base_url('Faq_Suggestion'));
				return;
			}
			$error = $this->Save_From_Post($post_id);
			if ($error !== true) {
				$this->session->set_flashdata('faq_error', $error);
				redirect(base_url('Faq_Suggestion/Update?id=') . $post_id);
				return;
			}
			$this->session->set_flashdata('faq_success', 'Suggestion updated.');
			redirect(base_url('Faq_Suggestion'));
			return;
		}

		$suggestion = $this->Faq_Suggestion_Model->Read($id);
		if ($suggestion === null) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}

		$titles = array('tab_title' => 'HolidayGoGoGo | FAQ AI Suggestion', 'breadcrumb_title' => 'FAQ AI Suggestion >> Edit');
		$data['suggestion'] = $suggestion;
		$data['items']      = Faq_Model::Decode_Items($suggestion->Description);
		$data['tags']       = $this->Faq_Tag_Model->Read_Active();
		$data['destinations'] = $this->Faq_Model->Read_Destinations();
		$data['selected_destination_ids'] = Faq_Suggestion_Model::Parse_Id_Csv($suggestion->DestinationIds);
		$this->load->view('layout/header', $titles);
		$this->load->view('faq_suggestion/form', $data);
		$this->load->view('layout/footer');
	}

	// Promote a suggestion to a real internal FAQ. Creates the FAQ from the
	// (possibly edited) suggestion fields, syncs its destinations, then flags the
	// suggestion 'accepted' with a link to the FAQ (kept for audit).
	function Accept()
	{
		if (!$this->Can_Edit()) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}
		$id = (int) $this->input->get('id');
		$suggestion = $this->Faq_Suggestion_Model->Read($id);
		if ($suggestion === null) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}
		if ($suggestion->State === 'accepted') {
			$this->session->set_flashdata('faq_error', 'This suggestion has already been accepted.');
			redirect(base_url('Faq_Suggestion'));
			return;
		}

		$title = trim((string) $suggestion->Title);
		if ($title === '' || trim((string) $suggestion->Description) === '') {
			$this->session->set_flashdata('faq_error', 'This suggestion has no title or questions to accept. Edit it first.');
			redirect(base_url('Faq_Suggestion/Update?id=') . $id);
			return;
		}

		// The suggestion's Description is already stored in the FAQ JSON format.
		// AI-suggested FAQs are published as 'external' type.
		$faq_id = $this->Faq_Model->Create(array(
			'Title'       => $title,
			'Slug'        => $this->Faq_Model->Generate_Slug($title, 0),
			'Description' => (string) $suggestion->Description,
			'Type'        => 'external',
		));
		$this->Faq_Model->Sync_Destinations($faq_id, Faq_Suggestion_Model::Parse_Id_Csv($suggestion->DestinationIds));
		$this->Faq_Suggestion_Model->Set_State($id, 'accepted', $faq_id);

		$this->session->set_flashdata('faq_success', 'FAQ created from suggestion.');
		redirect(base_url('Faq_Suggestion'));
	}

	function Dismiss()
	{
		if (!$this->Can_Edit()) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}
		$id = (int) $this->input->get('id');
		if ($this->Faq_Suggestion_Model->Read($id) !== null) {
			$this->Faq_Suggestion_Model->Set_State($id, 'dismissed');
			$this->session->set_flashdata('faq_success', 'Suggestion dismissed.');
		}
		redirect(base_url('Faq_Suggestion'));
	}

	// Soft-delete, wired to the shared Delete_Record() ajax helper (GET ?id=).
	function Delete()
	{
		if (!$this->Can_Edit()) {
			return;
		}
		$id = (int) $this->input->get('id');
		if ($this->Faq_Suggestion_Model->Read($id) !== null) {
			$this->Faq_Suggestion_Model->Delete($id);
		}
	}

	// Manual "Generate now" — runs the same routine as the cron, synchronously.
	function Generate()
	{
		if (!$this->Can_Edit()) {
			redirect(base_url('Faq_Suggestion'));
			return;
		}
		$days = (int) get_env('FAQ_SUGGESTION_DAYS');
		$days = $days > 0 ? $days : 3;
		try {
			$summary = $this->Faq_Suggestion_Model->Generate($days);
		} catch (Exception $e) {
			$this->session->set_flashdata('faq_error', 'Could not generate suggestions: ' . $e->getMessage());
			redirect(base_url('Faq_Suggestion'));
			return;
		}
		if ((int) $summary['created'] > 0) {
			$this->session->set_flashdata('faq_success', 'Generated ' . (int) $summary['created'] . ' new FAQ suggestion(s) from the last ' . $days . ' days of chats.');
		} elseif ($summary['reason'] === 'no_messages') {
			$this->session->set_flashdata('faq_error', 'No WhatsApp / GHL messages found in the last ' . $days . ' days.');
		} else {
			$this->session->set_flashdata('faq_error', 'No new suggestions this time (the AI found nothing beyond what already exists).');
		}
		redirect(base_url('Faq_Suggestion'));
	}

	// Returns true on success, or an error message string on failure. Reuses the
	// FAQ item builder (tags + reference links, no per-item audit trail).
	private function Save_From_Post($id)
	{
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
			$sub_tags, $link_labels, $link_urls
		);
		if ($built['error'] !== null) {
			return $built['error'];
		}
		if (empty($built['items'])) {
			return 'Add at least one sub-question and answer.';
		}

		$destination_ids = Faq_Model::Normalize_Ids($this->input->post('Destinations'));
		$this->Faq_Suggestion_Model->Update($id, array(
			'Title'          => $title,
			'Description'    => Faq_Model::Encode_Items($built['items']),
			'DestinationIds' => implode(',', $destination_ids),
		));
		return true;
	}
}
