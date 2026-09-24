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

if ( ! function_exists('customer_analysis_profile_text_fields'))
{
	/** The single-string character-profile fields, in display order. Pure. */
	function customer_analysis_profile_text_fields()
	{
		return array(
			'character'            => 'their personality and characteristics (e.g. decisive, cautious, detail-oriented, price-sensitive, easy-going, demanding, indecisive)',
			'mood'                 => 'their overall mood and emotional tone across the chat and how it shifted (e.g. excited, warm, hesitant, frustrated, impatient, anxious)',
			'behavior'             => 'how they behave in the conversation — how they ask questions, negotiate, decide, whether they read details or skim',
			'language'             => 'the language(s) they chat in (e.g. English, Malay, Chinese, mixed) and their tone/formality',
			'reply_pattern'        => 'their reply timing and rhythm (fast, slow, late replies, replies at night, sporadic, goes quiet) and what it signals',
			'response_expectation' => 'whether they expect or need faster replies from us, and how patient they are waiting',
			'journey'              => 'how their interest evolved across the chat — e.g. asked about Redang then switched to another destination; note the shifts',
			'family_needs'         => 'party/family composition and facility needs — e.g. young kids so they need an extra room, elderly needing accessibility, sea-view room',
			'source'               => 'how they found us or the lead source if evident from the chat, otherwise empty',
			'justification'        => 'the specific chat evidence (paraphrased behaviour or quotes) that backs up the assessment above',
		);
	}
}

if ( ! function_exists('customer_analysis_profile_list_fields'))
{
	/** The list-valued character-profile fields, in display order. Pure. */
	function customer_analysis_profile_list_fields()
	{
		return array(
			'preferences'  => 'concrete travel preferences drawn from the chat — e.g. likes sea view, prefers direct flights, specific destinations, room type, activities, budget style',
			'expectations' => 'what the customer expects from us or from the trip (service, price, timing, itinerary)',
			'complaints'   => 'any complaints, dissatisfaction or frustration they raised',
		);
	}
}

if ( ! function_exists('customer_analysis_json_shape'))
{
	/**
	 * Build the exact JSON shape block for the prompt from the field definitions,
	 * so the schema, normaliser and renderer never drift apart. Pure.
	 */
	function customer_analysis_json_shape()
	{
		$lines = array('Return STRICT JSON only (no markdown fences, no prose outside the object) with this exact shape:', '{');
		$lines[] = '  "summary": "a 1-2 sentence snapshot of who this customer is",';
		foreach (customer_analysis_profile_text_fields() as $key => $desc) {
			$lines[] = '  "' . $key . '": "' . $desc . '",';
		}
		foreach (customer_analysis_profile_list_fields() as $key => $desc) {
			$lines[] = '  "' . $key . '": ["' . $desc . '"],';
		}
		$lines[] = '  "temperature": "hot or cold — classify the customer\'s intention to make a booking",';
		$lines[] = '  "temperature_reason": "one short sentence justifying the hot/cold call",';
		$lines[] = '  "recommended_tours": [{"name": "the EXACT tour/product name copied from OUR PRODUCTS below", "tour_code": "its tour_code from OUR PRODUCTS if given, else empty", "price_myr": 0, "justification": "why THIS specific tour fits this customer — cite their destination, pax/family, budget and preferences from the chat"}],';
		$lines[] = '  "approach_suggestion": "ALWAYS filled (never empty, whatever the temperature) — practical guidance for OUR agent on how to approach this customer next: a warm, ready-to-send WhatsApp-style message written in English, tailored to their interest and preferences, that recommends the tour(s) in recommended_tours by name (with price) and a one-line reason"';
		$lines[] = '}';
		return implode("\n", $lines);
	}
}

if ( ! function_exists('customer_analysis_field_guidance'))
{
	/** The classification rules + writing rules appended after the JSON shape. Pure. */
	function customer_analysis_field_guidance()
	{
		return
			"Classification: 'hot' = the customer shows clear intention to make a booking (asking to book, confirming dates/pax, requesting a quote or payment to proceed, actively engaged and close to converting). " .
			"'cold' = little or no booking intention (just browsing, price-shopping without commitment, unresponsive, or went quiet). temperature MUST be exactly \"hot\" or \"cold\".\n" .
			"Recommendations: from the OUR PRODUCTS list provided below, pick the 1-3 tours that best match THIS customer's destination, party/family size, budget and preferences, and list them in recommended_tours. " .
			"Copy each tour's name (and tour_code) EXACTLY as given in OUR PRODUCTS — NEVER invent or rename a tour, and never recommend one that is not in the list. " .
			"ALWAYS recommend at least one tour whenever OUR PRODUCTS contains anything even loosely relevant to the customer's region or interest; only return an empty recommended_tours array when OUR PRODUCTS is empty or genuinely has nothing to offer them — do NOT invent one to fill the gap. " .
			"For each recommended tour write a justification grounded in the chat (what the customer asked for and how this tour meets it). Include price_myr only when OUR PRODUCTS gives a price; otherwise use 0.\n" .
			"Approach: ALWAYS write approach_suggestion — never leave it empty, WHATEVER the temperature. It is concrete, actionable guidance our travel agent can use right now to move THIS customer forward — a warm, human, ready-to-send message written in English (regardless of the customer's chat language). " .
			"Tailor it to their stated interest and preferences; when the customer is HOT, nudge toward booking, and when they are COLD or unclear, write a gentle re-engagement message that re-opens the conversation. " .
			"RECOMMEND the tour(s) in recommended_tours by name (mention the price when known) with a short reason; if recommended_tours is empty, still write a helpful message and ask what destination/dates/pax they have in mind. Suggest the natural next step (e.g. ask for pax/dates/budget, gently nudge to book), and use light structure and emojis where it helps them decide. " .
			"When comparing options, lay them out clearly like a friendly recommendation. NEVER over-promise or state things that vary by date/season as guaranteed (e.g. write \"there's often Live Music / a Live Band in the evening\", not \"there is guaranteed to be a Live Band every night\").\n" .
			"Rules: use an empty string (or empty array for lists) when the transcript gives nothing for a field — do NOT guess. " .
			"Write concrete, specific detail grounded in the chat over generic statements. Write every profile field in English, including approach_suggestion.";
	}
}

if ( ! function_exists('customer_analysis_output_contract'))
{
	/**
	 * The system instructions handed to the model: role, guard-rails and the exact
	 * JSON shape we expect back — a structured customer character profile. Pure.
	 */
	function customer_analysis_output_contract()
	{
		return
			"You are a customer-insight analyst for Holidaygogogo Tours, a Malaysian tour agency. " .
			"You are given the full chat history between our agency ('Agent') and ONE customer ('Customer'). " .
			"Read how the CUSTOMER writes and behaves and build a rich CHARACTER PROFILE of them as a person — " .
			"their personality, mood, expectations and preferences — NOT sales advice. " .
			"Analyse ONLY what the transcript supports — never invent facts.\n\n" .
			customer_analysis_json_shape() . "\n\n" .
			customer_analysis_field_guidance();
	}
}

if ( ! function_exists('customer_analysis_products_block'))
{
	/**
	 * Build the "OUR PRODUCTS" prompt block from our own tours so the model can
	 * recommend a real tour (with justification) instead of inventing one. Reuses
	 * competitor_products_block() to strip empty fields when it's loaded; otherwise
	 * encodes the list directly. Returns '' when we have no products to offer. Pure.
	 *
	 * @param array $our_products competitor_format_our_products() output.
	 */
	function customer_analysis_products_block($our_products)
	{
		$our_products = is_array($our_products) ? $our_products : array();
		if (empty($our_products)) {
			return '';
		}
		$json = function_exists('competitor_products_block')
			? competitor_products_block($our_products)
			: json_encode(array_values($our_products), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return "\n\nOUR PRODUCTS (JSON, prices in MYR — recommend ONLY from this list, copy names/codes exactly):\n" . $json . "\n";
	}
}

if ( ! function_exists('customer_analysis_build_request'))
{
	/**
	 * Shape the Responses API instructions + input for one customer. When
	 * $our_products is given (competitor_format_our_products() output) it is appended
	 * as an OUR PRODUCTS block so the model recommends a real tour. Pure.
	 *
	 * @return array{instructions:string,input:string}
	 */
	function customer_analysis_build_request($guest_name, $transcript, $our_products = array())
	{
		$name = trim((string) $guest_name);
		$input =
			"CUSTOMER NAME: " . ($name !== '' ? $name : '(unknown)') . "\n\n" .
			"CONVERSATION TRANSCRIPT (chronological; 'Agent' = our travel agency, 'Customer' = the client):\n" .
			"-----\n" . (string) $transcript . "\n-----\n" .
			customer_analysis_products_block($our_products);
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
	function customer_analysis_build_update_request($guest_name, $prior, $new_transcript, $our_products = array())
	{
		$p = (array) $prior;
		$prior_profile = (isset($p['profile']) && is_array($p['profile'])) ? $p['profile'] : array();
		$prior_json = json_encode(array_merge(
			array('summary' => isset($p['summary']) ? (string) $p['summary'] : ''),
			$prior_profile,
			array(
				'temperature'         => isset($p['temperature']) ? (string) $p['temperature'] : '',
				'temperature_reason'  => isset($p['temperature_reason']) ? (string) $p['temperature_reason'] : '',
				'approach_suggestion' => isset($p['approach_suggestion']) ? (string) $p['approach_suggestion'] : '',
			)
		), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

		$instructions =
			"You are a customer-insight analyst for Holidaygogogo Tours, a Malaysian tour agency. " .
			"You are UPDATING an existing CUSTOMER CHARACTER PROFILE: you are given the profile from the last analysis " .
			"plus ONLY the new chat messages since then. Keep traits that still hold, incorporate the new " .
			"messages, drop anything the new messages contradict, re-read the customer's current mood and behaviour, " .
			"and RE-ASSESS the hot/cold booking intent based on the latest state. Do not invent facts.\n\n" .
			customer_analysis_json_shape() . "\n\n" .
			customer_analysis_field_guidance();

		$name  = trim((string) $guest_name);
		$input =
			"CUSTOMER NAME: " . ($name !== '' ? $name : '(unknown)') . "\n\n" .
			"EXISTING PROFILE (from the last analysis):\n" . $prior_json . "\n\n" .
			"NEW MESSAGES since the last analysis (chronological; 'Agent' = our agency, 'Customer' = the client):\n" .
			"-----\n" . (string) $new_transcript . "\n-----\n" .
			customer_analysis_products_block($our_products);

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

if ( ! function_exists('customer_analysis_normalize_recommended_tours'))
{
	/**
	 * Coerce the model's tour recommendations into a clean list of
	 * {name, tour_code, price_myr, justification} — the tours it picked from OUR
	 * PRODUCTS for this customer, each with its reason. Rows without a name are
	 * dropped; price_myr is a float only when a positive number was given, else null.
	 * Pure. Used by both the fresh-response normaliser and the stored-row decoder.
	 */
	function customer_analysis_normalize_recommended_tours($v)
	{
		$out = array();
		if ( ! is_array($v)) {
			return $out;
		}
		foreach ($v as $t) {
			$t    = (array) $t;
			$name = customer_analysis_coerce_str(isset($t['name']) ? $t['name'] : '');
			if ($name === '') {
				continue;
			}
			$price = null;
			if (isset($t['price_myr']) && is_numeric($t['price_myr']) && (float) $t['price_myr'] > 0) {
				$price = (float) $t['price_myr'];
			}
			$out[] = array(
				'name'          => $name,
				'tour_code'     => customer_analysis_coerce_str(isset($t['tour_code']) ? $t['tour_code'] : ''),
				'price_myr'     => $price,
				'justification' => customer_analysis_coerce_str(isset($t['justification']) ? $t['justification'] : ''),
			);
		}
		return $out;
	}
}

if ( ! function_exists('customer_analysis_normalize_profile'))
{
	/**
	 * Coerce a decoded object into the structured character profile — every text
	 * field a trimmed string, every list field a clean array — defaulted so partial
	 * or legacy data renders without notices. Pure. Used by both the fresh-response
	 * normaliser and the model's stored-row decoder so the shape can't drift.
	 */
	function customer_analysis_normalize_profile($data)
	{
		$data    = is_array($data) ? $data : array();
		$profile = array();
		foreach (array_keys(customer_analysis_profile_text_fields()) as $k) {
			$profile[$k] = customer_analysis_coerce_str(isset($data[$k]) ? $data[$k] : '');
		}
		foreach (array_keys(customer_analysis_profile_list_fields()) as $k) {
			$profile[$k] = customer_analysis_coerce_list(isset($data[$k]) ? $data[$k] : array());
		}
		return $profile;
	}
}

if ( ! function_exists('customer_analysis_normalize_record'))
{
	/**
	 * Normalise a decoded AI object into the flat record the model stores/renders:
	 * a short summary, the structured character profile, and the hot/cold call.
	 * Defaults every field so partial replies render cleanly. Pure.
	 */
	function customer_analysis_normalize_record($data)
	{
		return array(
			'temperature'         => customer_analysis_normalize_temperature(isset($data['temperature']) ? $data['temperature'] : ''),
			'temperature_reason'  => customer_analysis_coerce_str(isset($data['temperature_reason']) ? $data['temperature_reason'] : ''),
			'approach_suggestion' => customer_analysis_coerce_str(isset($data['approach_suggestion']) ? $data['approach_suggestion'] : ''),
			'recommended_tours'   => customer_analysis_normalize_recommended_tours(isset($data['recommended_tours']) ? $data['recommended_tours'] : array()),
			'summary'             => customer_analysis_coerce_str(isset($data['summary']) ? $data['summary'] : ''),
			'profile'             => customer_analysis_normalize_profile($data),
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
