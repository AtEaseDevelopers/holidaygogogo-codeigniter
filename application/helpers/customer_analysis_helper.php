<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * customer_analysis_helper — pure transforms for the AI Customer Analysis
 * feature (no DB, no network, unit-tested). It turns a customer's two chat
 * sources into one prompt, and normalises the model's JSON reply back into a
 * flat record the model layer can store.
 *
 *   customer_analysis_merge_timeline()   merge GHL + uploaded chat into one list
 *   customer_analysis_render_transcript() flatten the timeline into prompt text
 *   customer_analysis_build_request()    shape the Responses API instructions+input
 *   customer_analysis_parse_ai_response() normalise the model's JSON reply
 *
 * The OpenAI HTTP call, token usage and costing are handled by
 * libraries/CustomerAnalysisService, reusing competitor_analysis_helper's
 * competitor_extract_responses_text() / _usage() / _estimate_cost().
 */

if ( ! function_exists('customer_analysis_normalize_ts'))
{
	/**
	 * Best-effort parse of a human chat timestamp into a sortable epoch so the two
	 * sources (GHL "7 Jul 2026, 2:03 PM" and WhatsApp export "7/23/26, 1:35 PM")
	 * can be interleaved chronologically. Returns 0 when unparseable. Pure.
	 */
	function customer_analysis_normalize_ts($when)
	{
		$when = trim((string) $when);
		if ($when === '') {
			return 0;
		}
		$t = strtotime($when);
		return $t === false ? 0 : (int) $t;
	}
}

if ( ! function_exists('customer_analysis_merge_timeline'))
{
	/**
	 * Merge the two chat sources into one chronological list of speaker turns.
	 *
	 * @param array $ghl     Rows from Ghl_Messages_Model::Conversation_By_* —
	 *                       each ['side'=>'in'|'out','body'=>..,'time'=>..].
	 * @param array $uploads Rows from chat_history_parse() —
	 *                       each ['ts'=>..,'body'=>..,'outbound'=>bool,'system'=>bool].
	 * @return array<int,array{sort:int,seq:int,when:string,speaker:string,body:string,origin:string}>
	 *         speaker is 'agent' (us) or 'customer'. Blank and system-only lines
	 *         are dropped. Sorted oldest → newest, insertion order breaking ties.
	 */
	function customer_analysis_merge_timeline($ghl, $uploads)
	{
		$items = array();
		$seq   = 0;

		foreach ((array) $ghl as $m) {
			if ( ! is_array($m)) {
				continue;
			}
			$body = trim((string) (isset($m['body']) ? $m['body'] : ''));
			if ($body === '') {
				continue;
			}
			$side = isset($m['side']) ? (string) $m['side'] : 'in';
			$when = isset($m['time']) ? (string) $m['time'] : '';
			$items[] = array(
				'sort'    => customer_analysis_normalize_ts($when),
				'seq'     => $seq++,
				'when'    => $when,
				'speaker' => ($side === 'out') ? 'agent' : 'customer',
				'body'    => $body,
				'origin'  => 'ghl',
			);
		}

		foreach ((array) $uploads as $m) {
			if ( ! is_array($m) || ! empty($m['system'])) {
				continue;
			}
			$body = trim((string) (isset($m['body']) ? $m['body'] : ''));
			if ($body === '') {
				continue;
			}
			$when = isset($m['ts']) ? (string) $m['ts'] : '';
			$items[] = array(
				'sort'    => customer_analysis_normalize_ts($when),
				'seq'     => $seq++,
				'when'    => $when,
				'speaker' => ! empty($m['outbound']) ? 'agent' : 'customer',
				'body'    => $body,
				'origin'  => 'upload',
			);
		}

		usort($items, function ($a, $b) {
			if ($a['sort'] !== $b['sort']) {
				return $a['sort'] < $b['sort'] ? -1 : 1;
			}
			return $a['seq'] - $b['seq'];
		});

		return $items;
	}
}

if ( ! function_exists('customer_analysis_render_transcript'))
{
	/**
	 * Flatten a merged timeline into a compact "[when] Speaker: body" transcript
	 * for the prompt. When it would exceed $max_chars we keep the MOST RECENT
	 * messages (drop oldest from the top) so the analysis reflects current intent.
	 * Pure.
	 */
	function customer_analysis_render_transcript($timeline, $max_chars = 24000)
	{
		$lines = array();
		foreach ((array) $timeline as $m) {
			if ( ! is_array($m)) {
				continue;
			}
			$body = trim((string) (isset($m['body']) ? $m['body'] : ''));
			if ($body === '') {
				continue;
			}
			$speaker = (isset($m['speaker']) && $m['speaker'] === 'agent') ? 'Agent' : 'Customer';
			$when    = trim((string) (isset($m['when']) ? $m['when'] : ''));
			$lines[] = ($when !== '' ? '[' . $when . '] ' : '') . $speaker . ': ' . $body;
		}
		$text = implode("\n", $lines);

		$max_chars = (int) $max_chars;
		if ($max_chars > 0 && strlen($text) > $max_chars) {
			$text = substr($text, strlen($text) - $max_chars);
			// Drop the (now partial) first line so we start on a clean turn.
			$nl = strpos($text, "\n");
			if ($nl !== false) {
				$text = substr($text, $nl + 1);
			}
			$text = "[…earlier messages truncated…]\n" . $text;
		}
		return $text;
	}
}

if ( ! function_exists('customer_analysis_output_contract'))
{
	/**
	 * The system instructions handed to the model: role, guard-rails and the exact
	 * JSON shape we expect back (profile + sales intelligence + next actions).
	 * Pure.
	 */
	function customer_analysis_output_contract()
	{
		return
			"You are a senior travel-sales analyst for Holidaygogogo Tours, a Malaysian tour agency. " .
			"You are given the full chat history between our agency ('Agent') and ONE customer ('Customer'). " .
			"Your job is to build a rich CUSTOMER PROFILE and then tell the business owner exactly what to do next to win the sale. " .
			"Analyse ONLY what the transcript supports — never invent facts.\n\n" .
			"Return STRICT JSON only (no markdown fences, no prose outside the object) with this exact shape:\n" .
			"{\n" .
			"  \"summary\": \"a detailed 4-6 sentence customer profile: who they are; their travel style and preferences; party/family composition and any special needs; budget posture and how they make decisions; and where the relationship with us stands and how engaged they are\",\n" .
			"  \"next_actions\": [\"concrete sales next steps the OWNER should take to move this customer toward booking, ordered most important first — each specific and actionable (what to send, what to offer, what to confirm, when to follow up), tailored to this customer's situation, not generic advice\"],\n" .
			"  \"temperature\": \"hot or cold — classify the customer's intention to make a booking\",\n" .
			"  \"temperature_reason\": \"one short sentence justifying the hot/cold call\"\n" .
			"}\n\n" .
			"Classification: 'hot' = the customer shows clear intention to make a booking (asking to book, confirming dates/pax, requesting a quote or payment to proceed, actively engaged and close to converting). " .
			"'cold' = little or no booking intention (just browsing, price-shopping without commitment, unresponsive, or went quiet). temperature MUST be exactly \"hot\" or \"cold\".\n" .
			"Rules: use an empty string or empty array when the transcript gives nothing. Write concrete, business-ready detail and favour specifics from the chat over generic statements. Write every field in English.";
	}
}

if ( ! function_exists('customer_analysis_build_request'))
{
	/**
	 * Shape the Responses API instructions + input for one customer. Pure.
	 *
	 * @return array{instructions:string,input:string}
	 */
	function customer_analysis_build_request($guest_name, $transcript)
	{
		$name = trim((string) $guest_name);
		$input =
			"CUSTOMER NAME: " . ($name !== '' ? $name : '(unknown)') . "\n\n" .
			"CONVERSATION TRANSCRIPT (chronological; 'Agent' = our travel agency, 'Customer' = the client):\n" .
			"-----\n" . (string) $transcript . "\n-----\n";
		return array(
			'instructions' => customer_analysis_output_contract(),
			'input'        => $input,
		);
	}
}

if ( ! function_exists('customer_analysis_build_update_request'))
{
	/**
	 * Shape an INCREMENTAL update call: give the model the existing stored profile
	 * plus ONLY the new messages since it was made, and ask it to update — so we
	 * don't re-read the whole chat. Same JSON output shape as a fresh analysis.
	 * Pure.
	 *
	 * @param string $guest_name
	 * @param mixed  $prior          Row/array with summary, key_facts[], temperature, temperature_reason.
	 * @param string $new_transcript Rendered transcript of just the new messages.
	 * @return array{instructions:string,input:string}
	 */
	function customer_analysis_build_update_request($guest_name, $prior, $new_transcript)
	{
		$p = (array) $prior;
		$prior_json = json_encode(array(
			'summary'            => isset($p['summary']) ? (string) $p['summary'] : '',
			'next_actions'       => (isset($p['next_actions']) && is_array($p['next_actions'])) ? array_values($p['next_actions']) : array(),
			'temperature'        => isset($p['temperature']) ? (string) $p['temperature'] : '',
			'temperature_reason' => isset($p['temperature_reason']) ? (string) $p['temperature_reason'] : '',
		), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

		$instructions =
			"You are a senior travel-sales analyst for Holidaygogogo Tours, a Malaysian tour agency. " .
			"You are UPDATING an existing customer profile: you are given the profile from the last analysis " .
			"plus ONLY the new chat messages since then. Keep facts that still hold, incorporate the new " .
			"messages, drop anything the new messages contradict, refresh the recommended sales next steps, " .
			"and RE-ASSESS the hot/cold booking intent based on the latest state. Do not invent facts.\n\n" .
			"Return STRICT JSON only (no markdown fences, no prose outside the object) with this exact shape:\n" .
			"{\n" .
			"  \"summary\": \"a detailed 4-6 sentence up-to-date customer profile: who they are, travel style, party/family context, budget posture, and where the relationship stands\",\n" .
			"  \"next_actions\": [\"concrete sales next steps the OWNER should take now to move this customer toward booking, ordered most important first, tailored to the latest state\"],\n" .
			"  \"temperature\": \"hot or cold — current intention to make a booking\",\n" .
			"  \"temperature_reason\": \"one short sentence justifying the hot/cold call\"\n" .
			"}\n\n" .
			"'hot' = clear intention to book (confirming dates/pax, asking to proceed/pay, actively engaged). " .
			"'cold' = little/no intention (browsing, price-shopping, unresponsive, went quiet). " .
			"temperature MUST be exactly \"hot\" or \"cold\". Write concrete, business-ready detail. Write every field in English.";

		$name  = trim((string) $guest_name);
		$input =
			"CUSTOMER NAME: " . ($name !== '' ? $name : '(unknown)') . "\n\n" .
			"EXISTING PROFILE (from the last analysis):\n" . $prior_json . "\n\n" .
			"NEW MESSAGES since the last analysis (chronological; 'Agent' = our agency, 'Customer' = the client):\n" .
			"-----\n" . (string) $new_transcript . "\n-----\n";

		return array('instructions' => $instructions, 'input' => $input);
	}
}

if ( ! function_exists('customer_analysis_coerce_str'))
{
	/** Coerce a model value into a trimmed scalar string (arrays are joined). Pure. */
	function customer_analysis_coerce_str($v)
	{
		if (is_array($v)) {
			return trim(implode(', ', array_map('strval', $v)));
		}
		return trim((string) $v);
	}
}

if ( ! function_exists('customer_analysis_coerce_list'))
{
	/**
	 * Coerce a model value into a list of non-empty strings. Accepts an array, or
	 * a string split on newlines/semicolons. Pure.
	 */
	function customer_analysis_coerce_list($v)
	{
		$out = array();
		if (is_array($v)) {
			foreach ($v as $x) {
				if (is_array($x)) {
					$x = implode(' ', array_map('strval', $x));
				}
				$x = trim((string) $x);
				if ($x !== '') {
					$out[] = $x;
				}
			}
		} elseif (trim((string) $v) !== '') {
			foreach (preg_split('/[\n;]+/', (string) $v) as $x) {
				$x = trim($x);
				if ($x !== '') {
					$out[] = $x;
				}
			}
		}
		return $out;
	}
}

if ( ! function_exists('customer_analysis_normalize_temperature'))
{
	/**
	 * Coerce the model's booking-intent classification to exactly 'hot' or 'cold';
	 * anything else (empty/unknown) becomes '' so the customer stays unclassified
	 * rather than mislabelled. Pure.
	 */
	function customer_analysis_normalize_temperature($v)
	{
		$v = strtolower(trim((string) $v));
		return ($v === 'hot' || $v === 'cold') ? $v : '';
	}
}

if ( ! function_exists('customer_analysis_normalize_record'))
{
	/**
	 * Normalise a decoded AI object into the flat record the model stores/renders,
	 * defaulting every field so partial/old replies render cleanly. Pure.
	 */
	function customer_analysis_normalize_record($data)
	{
		$si = (isset($data['sales_intel']) && is_array($data['sales_intel'])) ? $data['sales_intel'] : array();
		return array(
			'temperature'        => customer_analysis_normalize_temperature(isset($data['temperature']) ? $data['temperature'] : ''),
			'temperature_reason' => customer_analysis_coerce_str(isset($data['temperature_reason']) ? $data['temperature_reason'] : ''),
			'summary'      => customer_analysis_coerce_str(isset($data['summary']) ? $data['summary'] : ''),
			'sales_intel'  => array(
				'stage'                   => customer_analysis_coerce_str(isset($si['stage']) ? $si['stage'] : ''),
				'sentiment'               => customer_analysis_coerce_str(isset($si['sentiment']) ? $si['sentiment'] : ''),
				'language'                => customer_analysis_coerce_str(isset($si['language']) ? $si['language'] : ''),
				'budget_signals'          => customer_analysis_coerce_list(isset($si['budget_signals']) ? $si['budget_signals'] : array()),
				'interested_destinations' => customer_analysis_coerce_list(isset($si['interested_destinations']) ? $si['interested_destinations'] : array()),
				'interested_dates'        => customer_analysis_coerce_list(isset($si['interested_dates']) ? $si['interested_dates'] : array()),
				'objections'              => customer_analysis_coerce_list(isset($si['objections']) ? $si['objections'] : array()),
			),
			'next_actions' => customer_analysis_coerce_list(isset($data['next_actions']) ? $data['next_actions'] : array()),
			'key_facts'    => customer_analysis_coerce_list(isset($data['key_facts']) ? $data['key_facts'] : array()),
		);
	}
}

if ( ! function_exists('customer_analysis_parse_ai_response'))
{
	/**
	 * Normalise the model's JSON reply into a flat record. Tolerates code-fenced
	 * ```json blocks and stray prose around the object. Returns null when no JSON
	 * object can be recovered. Pure.
	 */
	function customer_analysis_parse_ai_response($content)
	{
		$content = trim((string) $content);
		if ($content === '') {
			return null;
		}
		$content = preg_replace('/^```(?:json)?\s*/i', '', $content);
		$content = preg_replace('/\s*```$/', '', $content);

		$data = json_decode($content, true);
		if ( ! is_array($data)) {
			if (preg_match('/\{.*\}/s', $content, $mm)) {
				$data = json_decode($mm[0], true);
			}
		}
		if ( ! is_array($data)) {
			return null;
		}
		return customer_analysis_normalize_record($data);
	}
}
