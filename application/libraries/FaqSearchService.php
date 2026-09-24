<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * FaqSearchService — the network side of the "AI answer" box on the internal FAQ
 * Library page. Given a customer question and the whole FAQ corpus it asks
 * OpenAI's Responses API for a ready-to-send reply grounded only in that corpus,
 * and returns the parsed answer plus token usage / cost.
 *
 * All pure transforms (question validation, corpus + prompt building, response
 * parsing) live in helpers/faq_search_helper.php and are unit-tested; this class
 * only does the HTTP call. It reuses the small response/usage/cost extractors
 * from competitor_analysis_helper.php so every AI feature shares one parser.
 *
 * Config comes from .env via get_env() (same keys the other AI features use):
 *   OPENAI_API_KEY   (required)
 *   OPENAI_MODEL     (optional, default gpt-4o-mini)
 *   OPENAI_BASE_URL  (optional, default https://api.openai.com/v1)
 *
 * answer() returns:
 *   ['found' => bool, 'answer' => string, 'sources' => [string],
 *    'model' => string, 'input_tokens' => int, 'output_tokens' => int, 'cost_usd' => float]
 * On any failure it throws Exception with a human-readable message.
 */
class FaqSearchService
{
	protected $CI;
	protected $last_usage = array('input_tokens' => 0, 'output_tokens' => 0);

	public function __construct()
	{
		$this->CI = &get_instance();
		$this->CI->load->helper('faq_search');
		$this->CI->load->helper('competitor_analysis'); // response/usage/cost extractors
	}

	/** Model id from .env, matching the other AI features' default. */
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
	 * Answer one question from the FAQ corpus. $question is the customer's typed
	 * question; $corpus is faq_search_build_corpus() output. Returns the parsed
	 * answer + usage/cost. Throws on an empty question or any API failure.
	 */
	public function answer($question, $corpus)
	{
		$question = trim((string) $question);
		if ($question === '') {
			throw new Exception('Please type a question.');
		}
		$spec = faq_search_build_prompt($question, $corpus);
		$raw  = $this->request($spec['instructions'], $spec['input']);

		$decoded = json_decode($raw, true);
		$parsed  = faq_search_parse_response($decoded);

		$usage = $this->last_usage;
		return array(
			'found'         => $parsed['found'],
			'answer'        => $parsed['answer'],
			'sources'       => $parsed['sources'],
			'model'         => $this->model(),
			'input_tokens'  => (int) $usage['input_tokens'],
			'output_tokens' => (int) $usage['output_tokens'],
			'cost_usd'      => competitor_estimate_cost(
				$this->model(), $usage['input_tokens'], $usage['output_tokens'], $this->price_rates()
			),
		);
	}

	/**
	 * Call the OpenAI Responses API in json_object mode and return the assistant's
	 * final text (a JSON object). Throws Exception on any failure.
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
			'text'         => array('format' => array('type' => 'json_object')),
		);
		if (competitor_model_supports_temperature($this->model())) {
			$payload['temperature'] = 0.2;
		}

		$this->log('request', array(
			'model'     => $this->model(),
			'input_len' => is_string($input) ? strlen($input) : strlen((string) json_encode($input)),
		));

		$started = microtime(true);
		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL            => $base . '/responses',
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
			throw new Exception('OpenAI returned no readable answer. Try again later.');
		}
		$this->log('response', array('code' => $code, 'usage' => $this->last_usage, 'ms' => $ms));
		$this->log_usage('FAQ Search');
		return $text;
	}

	/**
	 * Record this call's tokens + cost to the central ai_usage_log so the owner's
	 * "AI Cost & Usage" page can report it. Best-effort: never throws.
	 */
	protected function log_usage($feature)
	{
		try {
			$in   = (int) $this->last_usage['input_tokens'];
			$out  = (int) $this->last_usage['output_tokens'];
			$cost = competitor_estimate_cost($this->model(), $in, $out, $this->price_rates());
			$by   = (isset($this->CI->session) && ! empty($this->CI->session->admin_id)) ? (int) $this->CI->session->admin_id : null;
			$this->CI->load->model('Ai_Usage_Model');
			$this->CI->Ai_Usage_Model->Log(array(
				'feature'       => $feature,
				'model'         => $this->model(),
				'input_tokens'  => $in,
				'output_tokens' => $out,
				'cost_usd'      => $cost,
				'created_by'    => $by,
			));
		} catch (Exception $e) {
			// never break the AI flow because usage logging failed
		}
	}

	/** Append one JSON line per call to faq_search_ai.log. Never throws. */
	protected function log($event, array $data = array())
	{
		$entry = array_merge(array('ts' => date('Y-m-d H:i:s'), 'event' => $event), $data);
		$json  = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		@file_put_contents(APPPATH . 'logs/faq_search_ai.log', $json . "\n", FILE_APPEND | LOCK_EX);
		if (function_exists('log_message')) {
			log_message('error', 'FaqSearchAI ' . $json);
		}
	}
}
