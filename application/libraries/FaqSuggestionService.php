<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * FaqSuggestionService — the network side of the "FAQ AI Suggestion" feature.
 * Given a transcript of recent WhatsApp / GHL conversations it asks OpenAI's
 * Responses API to distil the recurring customer questions into candidate FAQ
 * entries, and returns the raw JSON reply plus token usage / cost.
 *
 * All pure transforms (prompt building, response parsing) live in
 * helpers/faq_suggestion_helper.php and are unit-tested; this class only does
 * the HTTP call. It reuses the small, already-tested response/usage/cost
 * extractors from competitor_analysis_helper.php so the two AI features share
 * one parser rather than duplicating it.
 *
 * Config comes from .env via get_env() (same keys the Competitor feature uses):
 *   OPENAI_API_KEY   (required)
 *   OPENAI_MODEL     (optional, default gpt-4o-mini)
 *   OPENAI_BASE_URL  (optional, default https://api.openai.com/v1)
 *
 * suggest() returns:
 *   ['raw' => string(JSON), 'model' => string,
 *    'input_tokens' => int, 'output_tokens' => int, 'cost_usd' => float]
 * On any failure it throws Exception with a human-readable message.
 */
class FaqSuggestionService
{
	protected $CI;

	public function __construct()
	{
		$this->CI = &get_instance();
		$this->CI->load->helper('faq_suggestion');
		$this->CI->load->helper('competitor_analysis'); // response/usage/cost extractors
	}

	/** Model id from .env, matching the Competitor feature's default. */
	protected function model()
	{
		$m = get_env('OPENAI_MODEL');
		return $m ? $m : 'gpt-4o-mini';
	}

	/** Optional per-1M-token price override (USD); null falls back to the built-in table. */
	protected function price_rates()
	{
		$in  = get_env('OPENAI_PRICE_INPUT');
		$out = get_env('OPENAI_PRICE_OUTPUT');
		if (is_numeric($in) && is_numeric($out)) {
			return array('input' => (float) $in, 'output' => (float) $out);
		}
		return null;
	}

	/**
	 * Mine one transcript into candidate FAQs. $transcript is the labelled
	 * conversation text (faq_suggestion_transcript); $destination_names is the
	 * allowed destination vocabulary; $existing_faqs are the FAQs that already
	 * exist (['title'=>, 'questions'=>[]]) so the model skips duplicates. Returns
	 * the raw JSON reply + usage/cost.
	 */
	public function suggest($transcript, $destination_names = array(), $existing_faqs = array())
	{
		$transcript = trim((string) $transcript);
		if ($transcript === '') {
			throw new Exception('No recent conversations to analyse.');
		}
		$spec = faq_suggestion_build_prompt($transcript, $destination_names, $existing_faqs);
		$raw  = $this->request($spec['instructions'], $spec['input']);
		return $this->pack_result($raw);
	}

	/**
	 * Mine an uploaded document into candidate FAQs. $base64 is the raw file
	 * contents base64-encoded; $ext its extension ('pdf', 'png', …). The file is
	 * sent to the Responses API as an input_file / input_image part (reusing the
	 * Competitor feature's pure part-builder). $existing_faqs are the FAQs that
	 * already exist (['title'=>, 'questions'=>[]]) so the model skips duplicates.
	 * Returns the raw JSON reply + usage/cost. Throws on an unsupported type or any
	 * API failure.
	 */
	public function suggest_file($base64, $ext, $destination_names = array(), $existing_faqs = array())
	{
		$part = competitor_file_input_part($ext, (string) $base64);
		if ($part === null) {
			throw new Exception('Unsupported file type. Upload a PDF or image.');
		}
		$spec  = faq_suggestion_build_file_prompt($destination_names, $existing_faqs);
		$input = array(array(
			'role'    => 'user',
			'content' => array(
				array('type' => 'input_text', 'text' => $spec['input']),
				$part,
			),
		));
		$raw = $this->request($spec['instructions'], $input);
		return $this->pack_result($raw);
	}

	/**
	 * Memory-safe PDF path: stream the file straight from disk to OpenAI's Files
	 * API (no base64, no in-memory copy, nothing embedded in the JSON body), then
	 * reference it by file_id in the Responses call. $path is the stored file;
	 * $orig_name is shown to the API. Falls back to the base64 method
	 * (suggest_file) on ANY upload/reference failure, and for non-PDF types, so a
	 * proxy /base_url without a /files endpoint still works. Set
	 * FAQ_SUGGESTION_PDF_UPLOAD=0 in .env to force the base64 path.
	 */
	public function suggest_file_path($path, $ext, $orig_name = '', $destination_names = array(), $existing_faqs = array())
	{
		$ext = strtolower(ltrim((string) $ext, '.'));

		$use_upload = get_env('FAQ_SUGGESTION_PDF_UPLOAD');
		$use_upload = ($use_upload === '' || $use_upload === null) ? true : (bool) (int) $use_upload;

		if ($ext === 'pdf' && $use_upload) {
			try {
				$file_id = $this->upload_file($path, (string) $orig_name);
				$spec  = faq_suggestion_build_file_prompt($destination_names, $existing_faqs);
				$input = array(array(
					'role'    => 'user',
					'content' => array(
						array('type' => 'input_text', 'text' => $spec['input']),
						array('type' => 'input_file', 'file_id' => $file_id),
					),
				));
				$raw = $this->request($spec['instructions'], $input);
				$this->delete_file($file_id); // best-effort cleanup
				return $this->pack_result($raw);
			} catch (Exception $e) {
				// Fall through to the base64 method (worst case = prior behaviour).
				$this->log('file_upload_fallback_base64', array('error' => $e->getMessage()));
			}
		}

		// Fallback / images: read + base64 in memory (peaks well under 128M for the
		// 20MB upload cap, but avoided above for the primary PDF path).
		$data = @file_get_contents($path);
		if ($data === false || $data === '') {
			throw new Exception('Could not read the uploaded file.');
		}
		$b64 = base64_encode($data);
		unset($data);
		return $this->suggest_file($b64, $ext, $destination_names, $existing_faqs);
	}

	/**
	 * Upload a file to OpenAI (POST /files, purpose=user_data) using a CURLFile so
	 * cURL streams it from disk — the file is never loaded into PHP memory. Returns
	 * the file id. Throws Exception on any failure.
	 */
	protected function upload_file($path, $orig_name = '')
	{
		$key = get_env('OPENAI_API_KEY');
		if (empty($key)) {
			throw new Exception('OpenAI is not configured. Add OPENAI_API_KEY to the .env file.');
		}
		if (!is_file($path) || !is_readable($path)) {
			throw new Exception('Could not read the uploaded file.');
		}
		$base = get_env('OPENAI_BASE_URL');
		$base = $base ? rtrim($base, '/') : 'https://api.openai.com/v1';

		$name  = ($orig_name !== '') ? $orig_name : basename($path);
		$cfile = new CURLFile($path, 'application/pdf', $name);

		$started = microtime(true);
		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL            => $base . '/files',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => array('purpose' => 'user_data', 'file' => $cfile),
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT        => 300,
			CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $key),
		));
		$resp  = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		$code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$ms = (int) round((microtime(true) - $started) * 1000);

		if ($errno) {
			$this->log('file_curl_error', array('errno' => $errno, 'error' => $error, 'ms' => $ms));
			throw new Exception('Could not upload the file to OpenAI: ' . $error);
		}
		$json = json_decode($resp, true);
		if ($code >= 400 || empty($json['id'])) {
			$msg = isset($json['error']['message']) ? $json['error']['message'] : ('HTTP ' . $code);
			$this->log('file_http_error', array('code' => $code, 'message' => $msg, 'ms' => $ms));
			throw new Exception('OpenAI file upload error: ' . $msg);
		}
		$this->log('file_uploaded', array('id' => $json['id'], 'ms' => $ms));
		return (string) $json['id'];
	}

	/** Best-effort delete of an uploaded file; never throws. */
	protected function delete_file($file_id)
	{
		$key = get_env('OPENAI_API_KEY');
		if (empty($key) || $file_id === '') {
			return;
		}
		$base = get_env('OPENAI_BASE_URL');
		$base = $base ? rtrim($base, '/') : 'https://api.openai.com/v1';
		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL            => $base . '/files/' . rawurlencode($file_id),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => 'DELETE',
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $key),
		));
		@curl_exec($ch);
		curl_close($ch);
	}

	/** Embedding model id from .env (cheap; used only for semantic dedupe). */
	protected function embed_model()
	{
		$m = get_env('OPENAI_EMBED_MODEL');
		return $m ? $m : 'text-embedding-3-small';
	}

	/**
	 * Embed a batch of texts for semantic dedupe. Returns a list of float
	 * vectors, one per input text, in the same order (index-aligned). An empty
	 * input returns []. Throws Exception on any API failure so the caller can
	 * fail open (skip the semantic pass) rather than lose the whole run.
	 */
	public function embed(array $texts)
	{
		$texts = array_values($texts);
		if (empty($texts)) {
			return array();
		}
		$key = get_env('OPENAI_API_KEY');
		if (empty($key)) {
			throw new Exception('OpenAI is not configured. Add OPENAI_API_KEY to the .env file.');
		}
		$base = get_env('OPENAI_BASE_URL');
		$base = $base ? rtrim($base, '/') : 'https://api.openai.com/v1';

		$payload = array('model' => $this->embed_model(), 'input' => $texts);

		$started = microtime(true);
		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL            => $base . '/embeddings',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . $key,
			),
		));
		$resp  = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		$code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$ms = (int) round((microtime(true) - $started) * 1000);

		if ($errno) {
			$this->log('embed_curl_error', array('errno' => $errno, 'error' => $error, 'ms' => $ms));
			throw new Exception('Could not reach OpenAI (embeddings): ' . $error);
		}
		$json = json_decode($resp, true);
		if ($code >= 400) {
			$msg = isset($json['error']['message']) ? $json['error']['message'] : ('HTTP ' . $code);
			$this->log('embed_http_error', array('code' => $code, 'message' => $msg, 'ms' => $ms));
			throw new Exception('OpenAI embeddings error: ' . $msg);
		}
		if (empty($json['data']) || !is_array($json['data'])) {
			$this->log('embed_empty', array('code' => $code, 'ms' => $ms));
			throw new Exception('OpenAI returned no embeddings.');
		}

		// The API echoes each row's index; order by it so the vectors line up with
		// $texts even if the response is reordered.
		$vectors = array();
		foreach ($json['data'] as $i => $row) {
			$idx = isset($row['index']) ? (int) $row['index'] : $i;
			$vectors[$idx] = isset($row['embedding']) && is_array($row['embedding']) ? $row['embedding'] : array();
		}
		ksort($vectors);
		$out = array_values($vectors);
		if (count($out) !== count($texts)) {
			$this->log('embed_count_mismatch', array('want' => count($texts), 'got' => count($out), 'ms' => $ms));
			throw new Exception('OpenAI returned a mismatched number of embeddings.');
		}
		$this->log('embed', array('model' => $this->embed_model(), 'count' => count($out), 'ms' => $ms));
		// Log embedding spend too. Embeddings bill input tokens only; text-embedding-3-small
		// is ~$0.02 / 1M tokens (override via OPENAI_EMBED_PRICE) — much cheaper than the
		// chat models, so it gets its own price rather than the built-in table's default.
		$eu    = competitor_extract_usage($json);
		$rate  = get_env('OPENAI_EMBED_PRICE');
		$rate  = is_numeric($rate) ? (float) $rate : 0.02;
		$ecost = round(((int) $eu['input_tokens'] / 1000000) * $rate, 6);
		$this->log_usage('FAQ Suggestions (Embedding)', $this->embed_model(), (int) $eu['input_tokens'], 0, $ecost);
		return $out;
	}

	/** Wrap a raw JSON reply with the model + token usage / cost of the last call. */
	protected function pack_result($raw)
	{
		$usage = $this->last_usage;
		return array(
			'raw'           => $raw,
			'model'         => $this->model(),
			'input_tokens'  => (int) $usage['input_tokens'],
			'output_tokens' => (int) $usage['output_tokens'],
			'cost_usd'      => competitor_estimate_cost(
				$this->model(), $usage['input_tokens'], $usage['output_tokens'], $this->price_rates()
			),
		);
	}

	/** Token usage from the most recent request(). */
	protected $last_usage = array('input_tokens' => 0, 'output_tokens' => 0);

	/**
	 * Call the OpenAI Responses API in json_object mode and return the
	 * assistant's final text (a JSON object). Throws Exception on any failure.
	 */
	protected function request($instructions, $input)
	{
		$key = get_env('OPENAI_API_KEY');
		if (empty($key)) {
			$this->log('config_error', array('message' => 'OPENAI_API_KEY missing'));
			throw new Exception('OpenAI is not configured. Add OPENAI_API_KEY to the .env file.');
		}
		$base = get_env('OPENAI_BASE_URL');
		$base = $base ? rtrim($base, '/') : 'https://api.openai.com/v1';

		$payload = array(
			'model'        => $this->model(),
			'instructions' => $instructions,
			'input'        => $input,
			// Force a syntactically valid JSON object reply (the input contains
			// the word "json", which the Responses API json_object mode requires).
			'text'         => array('format' => array('type' => 'json_object')),
		);
		// Reasoning models (gpt-5+, o-series) reject a custom temperature.
		if (competitor_model_supports_temperature($this->model())) {
			$payload['temperature'] = 0.2;
		}

		$this->log('request', array(
			'model'      => $this->model(),
			'input_len'  => is_string($input) ? strlen($input) : strlen((string) json_encode($input)),
		));

		$started = microtime(true);
		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL            => $base . '/responses',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT        => 300,
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . $key,
			),
		));
		$resp  = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		$code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$ms = (int) round((microtime(true) - $started) * 1000);

		if ($errno) {
			$this->log('curl_error', array('errno' => $errno, 'error' => $error, 'ms' => $ms));
			throw new Exception('Could not reach OpenAI: ' . $error);
		}
		$json = json_decode($resp, true);
		$this->last_usage = competitor_extract_usage($json);
		if ($code >= 400) {
			$msg = isset($json['error']['message']) ? $json['error']['message'] : ('HTTP ' . $code);
			$this->log('http_error', array('code' => $code, 'message' => $msg, 'ms' => $ms));
			throw new Exception('OpenAI error: ' . $msg);
		}

		$text = competitor_extract_responses_text($json);
		if ($text === '') {
			$this->log('empty_response', array('code' => $code, 'usage' => $this->last_usage, 'ms' => $ms));
			throw new Exception('OpenAI returned no readable suggestions. Try again later.');
		}
		$this->log('response', array('code' => $code, 'usage' => $this->last_usage, 'ms' => $ms));
		$cost = competitor_estimate_cost(
			$this->model(), $this->last_usage['input_tokens'], $this->last_usage['output_tokens'], $this->price_rates()
		);
		$this->log_usage('FAQ Suggestions', $this->model(), (int) $this->last_usage['input_tokens'], (int) $this->last_usage['output_tokens'], $cost);
		return $text;
	}

	/**
	 * Record one call's tokens + cost to the central ai_usage_log so the owner's
	 * "AI Cost & Usage" page can report it. Best-effort: never throws so usage
	 * logging can never break the paid AI flow.
	 */
	protected function log_usage($feature, $model, $input_tokens, $output_tokens, $cost_usd)
	{
		try {
			$by = (isset($this->CI->session) && ! empty($this->CI->session->admin_id)) ? (int) $this->CI->session->admin_id : null;
			$this->CI->load->model('Ai_Usage_Model');
			$this->CI->Ai_Usage_Model->Log(array(
				'feature'       => $feature,
				'model'         => $model,
				'input_tokens'  => (int) $input_tokens,
				'output_tokens' => (int) $output_tokens,
				'cost_usd'      => $cost_usd,
				'created_by'    => $by,
			));
		} catch (Exception $e) {
			// never break the AI flow because usage logging failed
		}
	}

	/** Append one JSON line per call to faq_suggestion_ai.log. Never throws. */
	protected function log($event, array $data = array())
	{
		$entry = array_merge(array('ts' => date('Y-m-d H:i:s'), 'event' => $event), $data);
		$json  = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		@file_put_contents(APPPATH . 'logs/faq_suggestion_ai.log', $json . "\n", FILE_APPEND | LOCK_EX);
		if (function_exists('log_message')) {
			log_message('error', 'FaqSuggestionAI ' . $json);
		}
	}
}
