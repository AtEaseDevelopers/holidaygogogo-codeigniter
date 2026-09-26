<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Customer_Analysis — an Owner-only (level 10) tool. For one customer (a listing
 * row identified by its dedup_key), it merges the GHL synced chat and any
 * manually-uploaded WhatsApp exports into a single chronological transcript,
 * asks OpenAI to produce a structured customer character profile (personality,
 * mood, behaviour, preferences, expectations, complaints + a hot/cold call), and
 * stores it. Reached from the Action ▸ "AI Analysis" link on the
 * Guest List / Customer / GHL Leads listings.
 *
 *   index()   GET  ?dedup_key=&phone=&name= — page with source counts + history
 *   Analyze() POST (AJAX) — gather chats, call OpenAI, save, return {ok,id}
 *   Delete()  POST (AJAX) — remove one saved analysis
 *
 * The OpenAI call lives in libraries/CustomerAnalysisService; the pure transforms
 * live in helpers/customer_analysis_helper.
 */
class Customer_Analysis extends MY_Controller
{
	/**
	 * Warn the user before analysing when the full transcript exceeds this many
	 * characters. ~450k chars ≈ ~112k tokens, under the 128k context window of
	 * gpt-4o / gpt-4o-mini (leaving room for the instructions + JSON output).
	 */
	const WARN_CHARS = 450000;

	function __construct()
	{
		parent::__construct();
		// Access is granted per-admin on the Leads/Customer > Access Settings grid
		// (module 'customer_profile'). Owner (level 10) keeps implicit full access.
		// View gates opening the page; the paid/mutating endpoints below also
		// require Edit. leads_customer_access helper is autoloaded.
		if (lc_block_view('customer_profile')) {
			return;
		}
		$this->load->model('Customer_Analysis_Model');
		$this->load->model('Ghl_Messages_Model');
		$this->load->model('Guests_Model');
		$this->load->model('Product_Model');
		$this->load->helper('customer_analysis');
		$this->load->helper('competitor_analysis');
		$this->load->helper('chat_history');
	}

	/** Whitelist the origin label so only known types are stored. */
	private function clean_source_type($raw)
	{
		$raw = trim((string) $raw);
		$allowed = array('Customer', 'Guest List', 'GHL Lead', 'Manual Lead');
		return in_array($raw, $allowed, true) ? $raw : '';
	}

	function index()
	{
		$dedup_key   = trim((string) $this->input->get('dedup_key'));
		$phone       = trim((string) $this->input->get('phone'));
		$name        = trim((string) $this->input->get('name'));
		$source_type = $this->clean_source_type($this->input->get('source_type'));
		// The "How to Approach" + "Recommended Tours" blocks are only for the sales
		// follow-up flow off the Hot/Cold Customers page (which sets show_approach=1);
		// the normal listing entry shows just the character profile.
		$show_approach = ((string) $this->input->get('show_approach') === '1');

		if ($dedup_key === '') {
			redirect(base_url('Booking'));
			return;
		}

		$gathered = $this->gather_timeline($dedup_key, $phone);

		// Size of the FULL transcript that would be sent, so the page can warn before
		// a call that may exceed the model's context window (only matters for a full run).
		$transcript_chars = strlen(customer_analysis_render_transcript($gathered['timeline'], 0));

		// Memory / change-detection: compare live chat against the last analysis so
		// the page can say "up to date" or "N new messages" and pick full vs update.
		$prior = $this->Customer_Analysis_Model->Read_Latest_Done($dedup_key);
		$meta  = $this->gather_meta($dedup_key, $phone);
		$mode  = $this->detect_mode($prior, $meta, false);

		$data = array(
			'dedup_key'        => $dedup_key,
			'phone'            => $phone,
			'guest_name'       => $name,
			'source_type'      => $source_type,
			'ghl_count'        => $gathered['ghl_count'],
			'upload_count'     => $gathered['upload_count'],
			'transcript_chars' => $transcript_chars,
			'warn_chars'       => self::WARN_CHARS,
			'has_prior'        => (bool) $prior,
			'last_analysed_at' => $prior ? $prior->created_at : '',
			'run_mode'         => $mode,           // full | incremental | unchanged
			'show_approach'    => $show_approach,  // reveal approach + tours (Hot/Cold entry only)
			'analyses'         => $this->Customer_Analysis_Model->Read_By_Dedup($dedup_key),
		);

		$titles = array(
			'tab_title'        => 'HolidayGoGoGo | Customer Profile',
			'breadcrumb_title' => 'Customer Profile',
		);
		$this->load->view('layout/header', $titles);
		$this->load->view('customer_analysis/index', $data);
		$this->load->view('layout/footer');
	}

	/**
	 * Gather + merge both chat sources for one customer. Returns the merged
	 * timeline plus per-source counts.
	 *
	 * @return array{timeline:array,ghl_count:int,upload_count:int}
	 */
	private function gather_timeline($dedup_key, $phone)
	{
		// GHL messages: match by phone when the row has one (guest/customer rows),
		// else by dedup_key (phone-less GHL leads) — mirrors the message-log modal.
		// Pull the FULL conversation (high cap) — the whole chat is analysed, untruncated.
		$phone = trim((string) $phone);
		$ghl = ($phone !== '')
			? $this->Ghl_Messages_Model->Conversation_By_Phone($phone, 1000000)
			: $this->Ghl_Messages_Model->Conversation_By_Dedup_Key($dedup_key, 1000000);

		// Uploaded WhatsApp exports: read + parse every stored .txt for this key.
		$uploads = array();
		foreach ($this->Guests_Model->Read_Chat_History($dedup_key) as $file) {
			$path = FCPATH . 'assets/upload/chat_history/' . basename($file->StoredName);
			if (is_file($path)) {
				$parsed = chat_history_parse(file_get_contents($path));
				if (is_array($parsed)) {
					$uploads = array_merge($uploads, $parsed);
				}
			}
		}

		$timeline = customer_analysis_merge_timeline($ghl, $uploads);

		// Count only the turns that actually reached the timeline (blank/system dropped).
		$ghl_count = 0; $upload_count = 0;
		foreach ($timeline as $m) {
			if ($m['origin'] === 'ghl') { $ghl_count++; } else { $upload_count++; }
		}

		return array(
			'timeline'     => $timeline,
			'ghl_count'    => $ghl_count,
			'upload_count' => $upload_count,
		);
	}

	/**
	 * Cheap stats for change-detection (no full fetch): raw GHL count + newest GHL
	 * time, and the uploaded-chat turn count (blank/system dropped to match the
	 * merged timeline). @return array{ghl:array{count:int,latest:?string},upload_count:int}
	 */
	private function gather_meta($dedup_key, $phone)
	{
		$phone = trim((string) $phone);
		$ghl = ($phone !== '')
			? $this->Ghl_Messages_Model->Conversation_Meta_By_Phone($phone)
			: $this->Ghl_Messages_Model->Conversation_Meta_By_Dedup_Key($dedup_key);

		$upload_count = 0;
		foreach ($this->Guests_Model->Read_Chat_History($dedup_key) as $file) {
			$path = FCPATH . 'assets/upload/chat_history/' . basename($file->StoredName);
			if (is_file($path)) {
				$parsed = chat_history_parse(file_get_contents($path));
				if (is_array($parsed)) {
					foreach ($parsed as $mm) {
						if (empty($mm['system']) && trim((string) $mm['body']) !== '') { $upload_count++; }
					}
				}
			}
		}
		return array('ghl' => $ghl, 'upload_count' => $upload_count);
	}

	/** Merged timeline of only the GHL messages newer than $since (incremental delta). */
	private function gather_delta($dedup_key, $phone, $since)
	{
		$phone = trim((string) $phone);
		$ghl = ($phone !== '')
			? $this->Ghl_Messages_Model->Conversation_By_Phone($phone, 1000000, $since)
			: $this->Ghl_Messages_Model->Conversation_By_Dedup_Key($dedup_key, 1000000, $since);
		return customer_analysis_merge_timeline($ghl, array());
	}

	/**
	 * Decide how a run should behave given the last analysis + current chat stats.
	 * Returns 'full' (first run / uploads changed / no watermark),
	 * 'unchanged' (nothing new — reuse stored profile) or 'incremental'.
	 */
	private function detect_mode($prior, $meta)
	{
		if ( ! $prior) {
			return 'full';
		}
		$live_ghl    = (int) $meta['ghl']['count'];
		$live_upload = (int) $meta['upload_count'];
		if ($live_upload !== (int) $prior->covered_upload) {
			return 'full';   // an upload was added/changed — can't delta it reliably
		}
		if ($live_ghl === (int) $prior->covered_ghl && $live_upload === (int) $prior->covered_upload) {
			return 'unchanged';
		}
		if (empty($prior->last_message_at)) {
			return 'full';   // no watermark to delta from
		}
		return 'incremental';
	}

	function Analyze()
	{
		if ( ! $this->input->is_ajax_request()) {
			redirect(base_url('Booking'));
			return;
		}
		// Running the analysis is a paid, mutating action — requires Edit.
		if (lc_block_edit('customer_profile')) {
			return;
		}
		$this->output->set_content_type('application/json');

		$dedup_key   = trim((string) $this->input->post('dedup_key'));
		$phone       = trim((string) $this->input->post('phone'));
		$name        = trim((string) $this->input->post('name'));
		$source_type = $this->clean_source_type($this->input->post('source_type'));

		if ($dedup_key === '') {
			echo json_encode(array('success' => false, 'message' => 'Missing customer reference.'));
			return;
		}

		$prior = $this->Customer_Analysis_Model->Read_Latest_Done($dedup_key);
		$meta  = $this->gather_meta($dedup_key, $phone);
		$mode  = $this->detect_mode($prior, $meta);

		// Nothing new since the last analysis — reuse the stored profile, no AI call.
		if ($mode === 'unchanged') {
			echo json_encode(array('success' => true, 'unchanged' => true,
				'message' => 'No new messages since the last analysis — the profile is already up to date.'));
			return;
		}

		// Build the input for the chosen mode.
		if ($mode === 'incremental') {
			$new_timeline = $this->gather_delta($dedup_key, $phone, $prior->last_message_at);
			if (empty($new_timeline)) {   // safety: watermark says new but delta empty
				echo json_encode(array('success' => true, 'unchanged' => true,
					'message' => 'No new messages to update the analysis with.'));
				return;
			}
		} else {
			$full = $this->gather_timeline($dedup_key, $phone);
			if (empty($full['timeline'])) {
				echo json_encode(array('success' => false, 'message' => 'No chat messages found for this customer to analyse.'));
				return;
			}
		}

		$source_counts = 'ghl:' . (int) $meta['ghl']['count'] . ',upload:' . (int) $meta['upload_count'];

		// Our own tours, so the AI recommends a real product (with justification)
		// instead of inventing one — same source the Competitor Analysis uses.
		$our_products = competitor_format_our_products($this->Product_Model->Read_For_Comparison());

		// Reference material the approach is grounded in: our internal FAQ (answer /
		// pre-empt the customer's questions with our real policy) + competitor tours
		// captured via AI for the destinations this customer is interested in
		// (value positioning). Both are best-effort — never block the analysis.
		$references = $this->build_references(
			($mode === 'incremental') ? $new_timeline : $full['timeline'],
			($mode === 'incremental') ? $prior : null
		);

		@set_time_limit(600);
		$this->load->library('CustomerAnalysisService');
		try {
			$record = ($mode === 'incremental')
				? $this->customeranalysisservice->analyze_update($name, $prior, $new_timeline, $our_products, $references)
				: $this->customeranalysisservice->analyze($name, $full['timeline'], $our_products, $references);
		} catch (Exception $e) {
			$this->Customer_Analysis_Model->Create(array(
				'dedup_key'     => $dedup_key,
				'guest_name'    => $name,
				'source_type'   => $source_type,
				'source_counts' => $source_counts,
				'status'        => 'error',
				'error_message' => $e->getMessage(),
				'created_by'    => $this->session->admin_id,
			));
			echo json_encode(array('success' => false, 'message' => $e->getMessage()));
			return;
		}

		// Advance the watermark to what is now covered so the next run can delta/skip.
		$record['dedup_key']      = $dedup_key;
		$record['guest_name']     = $name;
		$record['source_type']    = $source_type;
		$record['source_counts']  = $source_counts;
		$record['message_count']  = (int) $meta['ghl']['count'] + (int) $meta['upload_count'];
		$record['last_message_at'] = $meta['ghl']['latest'];
		$record['covered_ghl']    = (int) $meta['ghl']['count'];
		$record['covered_upload'] = (int) $meta['upload_count'];
		$record['status']         = 'done';
		$record['created_by']     = $this->session->admin_id;

		$id = $this->Customer_Analysis_Model->Create($record);
		echo json_encode(array('success' => true, 'id' => $id, 'mode' => $mode));
	}

	/**
	 * Gather the reference material the sales approach must be grounded in:
	 *   faq_corpus          — our internal FAQ library, so the model answers the
	 *                         customer's questions with our real policy, not a guess.
	 *   competitor_products — competitor tours captured via AI, narrowed to the
	 *                         destinations this customer talked about, for value
	 *                         positioning of our own tours.
	 * Both are best-effort context: any failure returns an empty block rather than
	 * breaking the paid analysis. $timeline is the messages driving this run;
	 * $prior (on incremental runs) widens the destination match to the whole history.
	 */
	private function build_references($timeline, $prior = null)
	{
		$refs = array('competitor_products' => array(), 'faq_corpus' => '');

		// Internal FAQ corpus — same shape the FAQ AI search feeds the model.
		try {
			$this->load->model('Faq_Model');
			$this->load->helper('faq_search');
			$library = array();
			foreach ((array) $this->Faq_Model->Read_Faqs() as $faq) {
				if ( ! isset($faq->Type) || $faq->Type !== 'internal') {
					continue;
				}
				$dest_raw = ($faq->Destinations === null) ? '' : (string) $faq->Destinations;
				$library[] = array(
					'title'        => (string) $faq->Title,
					'destinations' => ($dest_raw === '') ? array() : explode('||', $dest_raw),
					'items'        => Faq_Model::Decode_Items($faq->Description),
				);
			}
			if ( ! empty($library) && function_exists('faq_search_build_corpus')) {
				$refs['faq_corpus'] = faq_search_build_corpus($library);
			}
		} catch (Exception $e) {
			// FAQ is best-effort context — never block the analysis on it.
		}

		// Destination haystack: what the customer talked about, plus the prior
		// profile on incremental runs so an interest raised earlier still matches.
		$haystack = customer_analysis_render_transcript((array) $timeline, 0);
		if ($prior) {
			$p = (array) $prior;
			$haystack .= ' ' . (isset($p['summary']) ? (string) $p['summary'] : '');
			if (isset($p['profile']) && is_array($p['profile'])) {
				foreach ($p['profile'] as $v) {
					$haystack .= ' ' . (is_array($v) ? implode(' ', $v) : (string) $v);
				}
			}
		}

		// Competitor tours captured via AI, narrowed to the customer's destinations.
		try {
			$this->load->model('Competitor_Analysis_Model');
			$captured = customer_analysis_format_competitor_products($this->Competitor_Analysis_Model->Read_All());
			$refs['competitor_products'] = customer_analysis_filter_products_by_destination($captured, $haystack, 20);
		} catch (Exception $e) {
			// Competitor context is best-effort too.
		}

		return $refs;
	}

	function Delete()
	{
		if ( ! $this->input->is_ajax_request()) {
			redirect(base_url('Booking'));
			return;
		}
		// Removing a saved analysis is a mutating action — requires Edit.
		if (lc_block_edit('customer_profile')) {
			return;
		}
		$this->output->set_content_type('application/json');
		$id = (int) $this->input->post('id');
		if ($id > 0) {
			$this->Customer_Analysis_Model->Delete($id);
		}
		echo json_encode(array('success' => true));
	}
}
