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
	 * allowed destination vocabulary. Returns the raw JSON reply + usage/cost.
	 */
	public function suggest($transcript, $destination_names = array())
	{
		$transcript = trim((string) $transcript);
		if ($transcript === '') {
			throw new Exception('No recent conversations to analyse.');
		}
		$spec = faq_suggestion_build_prompt($transcript, $destination_names);
		$raw  = $this->request($spec['instructions'], $spec['input']);

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
		return $text;
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
