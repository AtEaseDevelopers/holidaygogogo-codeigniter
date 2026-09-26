<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CustomerAnalysisService — the network side of the AI Customer Analysis
 * feature. Given one customer's merged chat timeline (GHL synced messages +
 * uploaded WhatsApp exports), it asks OpenAI's Responses API to produce a
 * combined report: a profile summary, sales intelligence and recommended next
 * actions. Text-only — no tools, no web crawl, one synchronous call.
 *
 * All pure transforms (timeline merge, prompt building, JSON parsing) live in
 * helpers/customer_analysis_helper.php and are unit-tested; the token/usage and
 * costing helpers are reused from helpers/competitor_analysis_helper.php. This
 * class only performs the HTTP call.
 *
 * Config comes from .env via get_env():
 *   OPENAI_API_KEY  (required)
 *   OPENAI_MODEL    (optional, default gpt-4o-mini)
 *   OPENAI_BASE_URL (optional, default https://api.openai.com/v1)
 *   OPENAI_PRICE_INPUT / OPENAI_PRICE_OUTPUT (optional USD per 1M tokens override)
 *
 * analyze() returns the flat record from customer_analysis_parse_ai_response()
 * plus raw_json / model / input_tokens / output_tokens / cost_usd. On any
 * failure it throws Exception with a human-readable message.
 */
class CustomerAnalysisService
{
	protected $CI;

	/** Token usage from the most recent request(), for costing. */
	protected $last_usage = array('input_tokens' => 0, 'output_tokens' => 0);

	public function __construct()
	{
		$this->CI = &get_instance();
		$this->CI->load->helper('customer_analysis');
		// Reuse the competitor helper's response/usage/cost extractors.
		$this->CI->load->helper('competitor_analysis');
	}

	/**
	 * Analyse one customer's chat timeline. $timeline is the merged list from
	 * customer_analysis_merge_timeline(). Returns the parsed record ready for
	 * Customer_Analysis_Model::Create(). Throws on config / API / parse failure.
	 */
	public function analyze($guest_name, array $timeline, array $our_products = array(), array $references = array())
	{
		// 0 = no truncation: always send the FULL chat, no matter how long.
		$transcript = customer_analysis_render_transcript($timeline, 0);
		if (trim($transcript) === '') {
			throw new Exception('No chat messages to analyse for this customer.');
		}

		$req  = customer_analysis_build_request($guest_name, $transcript, $our_products, $references);
		$text = $this->request($req['instructions'], $req['input']);

		$record = customer_analysis_parse_ai_response($text);
		if ( ! is_array($record)) {
			throw new Exception('OpenAI returned no readable analysis. Try again.');
		}

		return $this->stamp($record, $text);
	}

	/**
	 * Incremental update: refresh a stored profile using ONLY the new messages
	 * (the "memory" flow — the model doesn't re-read the whole chat). $prior is the
	 * last done analysis row; $new_timeline is the merged NEW messages.
	 */
	public function analyze_update($guest_name, $prior, array $new_timeline, array $our_products = array(), array $references = array())
	{
		$new_transcript = customer_analysis_render_transcript($new_timeline, 0);
		if (trim($new_transcript) === '') {
			throw new Exception('No new messages to update the analysis with.');
		}
		$req    = customer_analysis_build_update_request($guest_name, $prior, $new_transcript, $our_products, $references);
		$text   = $this->request($req['instructions'], $req['input']);
		$record = customer_analysis_parse_ai_response($text);
		if ( ! is_array($record)) {
			throw new Exception('OpenAI returned no readable analysis. Try again.');
		}
		return $this->stamp($record, $text);
	}

	/** Attach raw response, model and token/cost metadata to a parsed record. */
	protected function stamp($record, $text)
	{
		$record['raw_json']      = $text;
		$record['model']         = $this->model();
		$record['input_tokens']  = $this->last_usage['input_tokens'];
		$record['output_tokens'] = $this->last_usage['output_tokens'];
		$record['cost_usd']      = competitor_estimate_cost(
			$this->model(), $record['input_tokens'], $record['output_tokens'], $this->price_rates()
		);
		return $record;
	}

	protected function model()
	{
		$m = get_env('OPENAI_MODEL');
		return $m ? $m : 'gpt-4o-mini';
	}

	/**
	 * Optional per-1M-token price override from .env. Returns null when unset so
	 * the helper falls back to its built-in per-model price table.
	 */
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
	 * Call the OpenAI Responses API and return the assistant's final text
	 * (expected to be a JSON object). Text-only: no tools.
	 */
	protected function request($instructions, $input)
	{
		$key = get_env('OPENAI_API_KEY');
		if (empty($key)) {
			throw new Exception('OpenAI is not configured. Add OPENAI_API_KEY to the .env file.');
		}
		$base = get_env('OPENAI_BASE_URL');
		$base = $base ? rtrim($base, '/') : 'https://api.openai.com/v1';

		$payload = array(
			'model'        => $this->model(),
			'instructions' => $instructions,
			'input'        => $input,
		);
		// Reasoning models (gpt-5+, o-series) reject a custom temperature.
		if (competitor_model_supports_temperature($this->model())) {
			$payload['temperature'] = 0.2;
		}

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

		if ($errno) {
			throw new Exception('Could not reach OpenAI: ' . $error);
		}
		$json = json_decode($resp, true);
		$this->last_usage = competitor_extract_usage($json);
		if ($code >= 400) {
			$msg = isset($json['error']['message']) ? $json['error']['message'] : ('HTTP ' . $code);
			throw new Exception('OpenAI error: ' . $msg);
		}

		$text = competitor_extract_responses_text($json);
		if ($text === '') {
			throw new Exception('OpenAI returned no readable analysis. Try again.');
		}
		$this->log_usage('Customer Analysis');
		return $text;
	}

	/**
	 * Record this call's tokens + cost to the central ai_usage_log so the owner's
	 * "AI Cost & Usage" page can report it. Best-effort: any failure is swallowed
	 * so logging never breaks the paid AI flow.
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
}
