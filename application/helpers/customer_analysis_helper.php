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
		$lines[] = '  "recommendation": {';
		$lines[] = '    "intro": "one friendly opening line in English that frames the comparison for THIS customer (who they are / their trip), or empty when there is only one option",';
		$lines[] = '    "options": [{"name": "EXACT product name from OUR PRODUCTS (same tours as recommended_tours)", "tour_code": "its tour_code or empty", "price_myr": 0, "dimensions": [{"label": "a comparison aspect you CHOOSE to fit the product type — use the SAME labels across every option (e.g. beach resort: Beach Vibe / Resort Vibe / Facilities / Rooms / Meals; city or multi-country tour: Itinerary / Pace / Inclusions / Hotels / Food / Budget)", "emoji": "one relevant emoji", "points": ["short bullet grounded in this product", "short bullet"]}], "overall_feel": "a one-line summary of the overall vibe and who it suits", "feel_emoji": "one emoji"}],';
		$lines[] = '    "decision_guide": [{"emoji": "one emoji", "persona": "a type of traveller (e.g. wants comfort + resort feel)", "pick": "the option name that fits them"}],';
		$lines[] = '    "follow_up": "one closing question in English that narrows the choice (usually pax + month + budget per person)",';
		$lines[] = '    "tc_notes": ["agent-only caveats the agent should keep in mind but NOT send verbatim — e.g. live shows / activities vary by date or season, so never promise them as guaranteed"]';
		$lines[] = '  }';
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
			"Recommendation: ALWAYS fill the recommendation object — never leave options empty when OUR PRODUCTS has anything relevant, WHATEVER the temperature. It is the ready-to-send comparison our travel agent gives THIS customer, written in English (regardless of the customer's chat language). " .
			"Put ONE entry in options for each tour you recommend (the SAME tours as recommended_tours), and compare them across a CONSISTENT set of dimensions you CHOOSE to fit the product type — 4-6 aspects, the SAME labels across every option so they line up (e.g. beach resorts: Beach Vibe / Resort Vibe / Facilities / Rooms / Meals; a city or multi-country tour: Itinerary / Pace / Inclusions / Hotels / Food / Budget). " .
			"Tailor every bullet and overall_feel to what THIS customer said they care about (their pax/family, budget and preferences). When only one tour is relevant, still fill options with that one (intro and decision_guide may be brief) — the comparison degrades to a single friendly recommendation. " .
			"decision_guide maps each likely traveller persona to the option that fits them; follow_up asks the one question that best narrows the choice (usually pax + month + budget per person). " .
			"When the customer is HOT, lead toward booking; when COLD or unclear, keep options light and lead with a warm re-engagement intro + follow_up that re-opens the conversation. " .
			"NEVER over-promise things that vary by date/season (live shows, weather, specific activities) — hedge them (write \"there's often Live Music in the evening\", not \"there is guaranteed to be a Live Band every night\") and record such caveats in tc_notes for the agent, NOT in the customer-facing bullets.\n" .
			"References: below the transcript you may be given up to three reference blocks — OUR PRODUCTS (our real tours), COMPETITOR PRODUCTS (rival tours captured via AI) and a KNOWLEDGE BASE of our internal FAQ. Base the whole approach on them together with the customer's profile. " .
			"Use the KNOWLEDGE BASE to answer or pre-empt the customer's open or likely questions (e.g. visa, baggage, deposit, refund, what's included) with OUR real policy — quote it, never invent one; if the FAQ does not cover a question, offer to check rather than guess. " .
			"Use COMPETITOR PRODUCTS ONLY to position OUR PRODUCTS on value (what we include that they may not, or why our price is worth it) — NEVER recommend a competitor tour and never name or run them down.\n" .
			"Rules: use an empty string (or empty array for lists) when the transcript gives nothing for a field — do NOT guess. " .
			"Write concrete, specific detail grounded in the chat over generic statements. Write every profile field in English, including the whole recommendation object.";
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

if ( ! function_exists('customer_analysis_format_competitor_products'))
{
	/**
	 * Flatten AI-captured competitor products (Competitor_Analysis_Model::Read_All()
	 * headline rows) into the compact {name, tour_code, destination, duration, price,
	 * currency} shape used for the COMPETITOR PRODUCTS reference block. Rows without a
	 * product name are dropped; empty fields and non-positive prices are omitted so we
	 * never pay to send dead weight. Pure — no DB.
	 *
	 * @param array $rows Rows/objects with product_name, tour_code, destination,
	 *                    duration, price, currency (as returned by Read_All()).
	 */
	function customer_analysis_format_competitor_products($rows)
	{
		$out = array();
		foreach ((array) $rows as $r) {
			$r    = (array) $r;
			$name = customer_analysis_coerce_str(isset($r['product_name']) ? $r['product_name'] : (isset($r['name']) ? $r['name'] : ''));
			if ($name === '') {
				continue;
			}
			$item = array('name' => $name);
			$code = customer_analysis_coerce_str(isset($r['tour_code']) ? $r['tour_code'] : '');
			if ($code !== '') {
				$item['tour_code'] = $code;
			}
			$dest = customer_analysis_coerce_str(isset($r['destination']) ? $r['destination'] : '');
			if ($dest !== '') {
				$item['destination'] = $dest;
			}
			$dur = customer_analysis_coerce_str(isset($r['duration']) ? $r['duration'] : '');
			if ($dur !== '') {
				$item['duration'] = $dur;
			}
			if (isset($r['price']) && is_numeric($r['price']) && (float) $r['price'] > 0) {
				$item['price'] = (float) $r['price'];
				$cur = customer_analysis_coerce_str(isset($r['currency']) ? $r['currency'] : '');
				if ($cur !== '') {
					$item['currency'] = $cur;
				}
			}
			$out[] = $item;
		}
		return $out;
	}
}

if ( ! function_exists('customer_analysis_destination_tokens'))
{
	/**
	 * Collect the destination-ish tokens of a product (its destination string plus
	 * any countries/cities) as a flat list, splitting delimited strings. Used to
	 * match a product against the destinations a customer mentions. Pure.
	 */
	function customer_analysis_destination_tokens($product)
	{
		$product = (array) $product;
		$tokens  = array();
		foreach (array('destination', 'countries', 'cities') as $k) {
			if ( ! isset($product[$k])) {
				continue;
			}
			$v = $product[$k];
			if (is_array($v)) {
				foreach ($v as $x) {
					$tokens[] = (string) $x;
				}
			} else {
				foreach (preg_split('/[,\/;|]+/', (string) $v) as $x) {
					$tokens[] = $x;
				}
			}
		}
		return $tokens;
	}
}

if ( ! function_exists('customer_analysis_filter_products_by_destination'))
{
	/**
	 * Keep only the products whose destination (or a country/city) is mentioned in
	 * $haystack (the chat / prior-profile text) — so the COMPETITOR PRODUCTS block
	 * stays focused on where the customer is actually interested, not the whole
	 * captured catalogue. Matching is case-insensitive and word-level: a token like
	 * "South Korea" matches a customer who only wrote "korea". Words shorter than 4
	 * chars are ignored to avoid noise. Capped to $limit. Returns [] when nothing
	 * matches. Pure.
	 */
	function customer_analysis_filter_products_by_destination($products, $haystack, $limit = 20)
	{
		$out = array();
		if ( ! is_array($products)) {
			return $out;
		}
		$hay = ' ' . strtolower((string) $haystack) . ' ';
		if (trim($hay) === '') {
			return $out;
		}
		$limit = (int) $limit;
		foreach ($products as $p) {
			if (customer_analysis_destination_mentioned($p, $hay)) {
				$out[] = $p;
			}
			if ($limit > 0 && count($out) >= $limit) {
				break;
			}
		}
		return $out;
	}
}

if ( ! function_exists('customer_analysis_destination_mentioned'))
{
	/**
	 * True when any word (>= 4 chars) of a product's destination tokens appears in
	 * the already-lowercased $hay text. Word-level so "South Korea" still matches a
	 * customer who wrote only "korea". Pure.
	 */
	function customer_analysis_destination_mentioned($product, $hay)
	{
		foreach (customer_analysis_destination_tokens($product) as $tok) {
			foreach (preg_split('/\s+/', strtolower(trim((string) $tok))) as $word) {
				if (strlen($word) >= 4 && strpos($hay, $word) !== false) {
					return true;
				}
			}
		}
		return false;
	}
}

if ( ! function_exists('customer_analysis_competitor_block'))
{
	/**
	 * Build the "COMPETITOR PRODUCTS" reference block from AI-captured competitor
	 * tours so the model can position OUR PRODUCTS on value — WITHOUT ever
	 * recommending a competitor. Reuses competitor_products_block() to strip empty
	 * fields when it's loaded. Returns '' when there is nothing to show. Pure.
	 *
	 * @param array $competitor_products customer_analysis_format_competitor_products() output.
	 */
	function customer_analysis_competitor_block($competitor_products)
	{
		$rows = is_array($competitor_products) ? $competitor_products : array();
		if (empty($rows)) {
			return '';
		}
		$json = function_exists('competitor_products_block')
			? competitor_products_block($rows)
			: json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === '' || $json === '[]' || $json === false) {
			return '';
		}
		return "\n\nCOMPETITOR PRODUCTS (captured via AI from rival sites — for competitive positioning ONLY; recommend our own tours, NEVER these, and never disparage them by name):\n" . $json . "\n";
	}
}

if ( ! function_exists('customer_analysis_faq_block'))
{
	/**
	 * Build the "KNOWLEDGE BASE (FAQ)" reference block from our internal FAQ corpus
	 * so the model answers/pre-empts the customer's questions with our real policy
	 * instead of inventing one. $faq_corpus is faq_search_build_corpus() output.
	 * Returns '' when empty. Pure.
	 */
	function customer_analysis_faq_block($faq_corpus)
	{
		$corpus = trim((string) $faq_corpus);
		if ($corpus === '') {
			return '';
		}
		return "\n\nKNOWLEDGE BASE — our internal FAQ (ground any factual answer to the customer's questions in these; never invent a policy/price/detail beyond them):\n" . $corpus . "\n";
	}
}

if ( ! function_exists('customer_analysis_references_block'))
{
	/**
	 * Assemble the optional reference blocks appended after the transcript:
	 * OUR PRODUCTS, COMPETITOR PRODUCTS and the FAQ KNOWLEDGE BASE. Each part omits
	 * itself when it has nothing to add. $references may carry 'competitor_products'
	 * (formatted list) and 'faq_corpus' (string). Pure.
	 */
	function customer_analysis_references_block($our_products, $references = array())
	{
		$references = is_array($references) ? $references : array();
		$competitor = isset($references['competitor_products']) ? $references['competitor_products'] : array();
		$faq_corpus = isset($references['faq_corpus']) ? $references['faq_corpus'] : '';
		return customer_analysis_products_block($our_products)
			. customer_analysis_competitor_block($competitor)
			. customer_analysis_faq_block($faq_corpus);
	}
}

if ( ! function_exists('customer_analysis_build_request'))
{
	/**
	 * Shape the Responses API instructions + input for one customer. When
	 * $our_products is given (competitor_format_our_products() output) it is appended
	 * as an OUR PRODUCTS block so the model recommends a real tour. $references may
	 * add a COMPETITOR PRODUCTS block ('competitor_products') and an internal-FAQ
	 * KNOWLEDGE BASE block ('faq_corpus') so the approach is grounded in our real
	 * policies and positioned against rivals. Pure.
	 *
	 * @return array{instructions:string,input:string}
	 */
	function customer_analysis_build_request($guest_name, $transcript, $our_products = array(), $references = array())
	{
		$name = trim((string) $guest_name);
		$input =
			"CUSTOMER NAME: " . ($name !== '' ? $name : '(unknown)') . "\n\n" .
			"CONVERSATION TRANSCRIPT (chronological; 'Agent' = our travel agency, 'Customer' = the client):\n" .
			"-----\n" . (string) $transcript . "\n-----\n" .
			customer_analysis_references_block($our_products, $references);
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
	function customer_analysis_build_update_request($guest_name, $prior, $new_transcript, $our_products = array(), $references = array())
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
			customer_analysis_references_block($our_products, $references);

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

if ( ! function_exists('customer_analysis_normalize_recommendation'))
{
	/**
	 * Coerce the model's structured recommendation into a clean, stable shape:
	 * a friendly intro, one option per compared tour (each with the dimensions the
	 * model chose to fit the product type), a persona→pick decision guide, a
	 * narrowing follow-up question and agent-only caveats (tc_notes — NEVER sent to
	 * the customer). Empty parts default cleanly so partial/legacy data renders
	 * without notices. Pure. Used by both the fresh-response normaliser and the
	 * stored-row decoder so the shape can't drift.
	 */
	function customer_analysis_normalize_recommendation($v)
	{
		$v = is_array($v) ? $v : array();

		$options = array();
		if (isset($v['options']) && is_array($v['options'])) {
			foreach ($v['options'] as $opt) {
				$opt  = (array) $opt;
				$name = customer_analysis_coerce_str(isset($opt['name']) ? $opt['name'] : '');
				if ($name === '') {
					continue;
				}
				$price = null;
				if (isset($opt['price_myr']) && is_numeric($opt['price_myr']) && (float) $opt['price_myr'] > 0) {
					$price = (float) $opt['price_myr'];
				}
				$dimensions = array();
				if (isset($opt['dimensions']) && is_array($opt['dimensions'])) {
					foreach ($opt['dimensions'] as $dim) {
						$dim   = (array) $dim;
						$label = customer_analysis_coerce_str(isset($dim['label']) ? $dim['label'] : '');
						$points = customer_analysis_coerce_list(isset($dim['points']) ? $dim['points'] : array());
						if ($label === '' && empty($points)) {
							continue;
						}
						$dimensions[] = array(
							'label'  => $label,
							'emoji'  => customer_analysis_coerce_str(isset($dim['emoji']) ? $dim['emoji'] : ''),
							'points' => $points,
						);
					}
				}
				$options[] = array(
					'name'         => $name,
					'tour_code'    => customer_analysis_coerce_str(isset($opt['tour_code']) ? $opt['tour_code'] : ''),
					'price_myr'    => $price,
					'dimensions'   => $dimensions,
					'overall_feel' => customer_analysis_coerce_str(isset($opt['overall_feel']) ? $opt['overall_feel'] : ''),
					'feel_emoji'   => customer_analysis_coerce_str(isset($opt['feel_emoji']) ? $opt['feel_emoji'] : ''),
				);
			}
		}

		$decision = array();
		if (isset($v['decision_guide']) && is_array($v['decision_guide'])) {
			foreach ($v['decision_guide'] as $g) {
				$g       = (array) $g;
				$persona = customer_analysis_coerce_str(isset($g['persona']) ? $g['persona'] : '');
				$pick    = customer_analysis_coerce_str(isset($g['pick']) ? $g['pick'] : '');
				if ($persona === '' && $pick === '') {
					continue;
				}
				$decision[] = array(
					'emoji'   => customer_analysis_coerce_str(isset($g['emoji']) ? $g['emoji'] : ''),
					'persona' => $persona,
					'pick'    => $pick,
				);
			}
		}

		return array(
			'intro'          => customer_analysis_coerce_str(isset($v['intro']) ? $v['intro'] : ''),
			'options'        => $options,
			'decision_guide' => $decision,
			'follow_up'      => customer_analysis_coerce_str(isset($v['follow_up']) ? $v['follow_up'] : ''),
			'tc_notes'       => customer_analysis_coerce_list(isset($v['tc_notes']) ? $v['tc_notes'] : array()),
		);
	}
}

if ( ! function_exists('customer_analysis_render_recommendation_text'))
{
	/**
	 * Flatten a normalised recommendation into a ready-to-send, WhatsApp-style
	 * plain-text message (the copyable/back-compat approach_suggestion). Agent-only
	 * tc_notes are deliberately EXCLUDED — they are never part of the customer
	 * message. Returns '' when there is nothing to send. Pure.
	 */
	function customer_analysis_render_recommendation_text($rec)
	{
		$rec = customer_analysis_normalize_recommendation($rec);
		if (empty($rec['options'])) {
			return '';
		}
		$blocks = array();

		if ($rec['intro'] !== '') {
			$blocks[] = $rec['intro'];
		}

		$n = 0;
		foreach ($rec['options'] as $opt) {
			$n++;
			$head = ($opt['feel_emoji'] !== '' ? $opt['feel_emoji'] . ' ' : '') . $n . '. ' . $opt['name'];
			if ($opt['price_myr'] !== null && $opt['price_myr'] > 0) {
				$head .= ' — RM ' . number_format($opt['price_myr'], 0);
			}
			$lines = array($head);
			foreach ($opt['dimensions'] as $dim) {
				$label = trim(($dim['emoji'] !== '' ? $dim['emoji'] . ' ' : '') . $dim['label']);
				if ($label !== '') {
					$lines[] = $label;
				}
				foreach ($dim['points'] as $p) {
					$lines[] = '• ' . $p;
				}
			}
			if ($opt['overall_feel'] !== '') {
				$lines[] = '👉 ' . $opt['overall_feel'];
			}
			$blocks[] = implode("\n", $lines);
		}

		if ( ! empty($rec['decision_guide'])) {
			$lines = array('💡 Which to pick:');
			foreach ($rec['decision_guide'] as $g) {
				$prefix = $g['emoji'] !== '' ? $g['emoji'] . ' ' : '';
				$lines[] = $prefix . $g['persona'] . ($g['pick'] !== '' ? ' → ' . $g['pick'] : '');
			}
			$blocks[] = implode("\n", $lines);
		}

		if ($rec['follow_up'] !== '') {
			$blocks[] = $rec['follow_up'];
		}

		return implode("\n\n", $blocks);
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
		$recommendation = customer_analysis_normalize_recommendation(isset($data['recommendation']) ? $data['recommendation'] : array());
		// The copyable/back-compat message is the flattened recommendation; fall back
		// to a literal approach_suggestion string if the model returned the old shape.
		$approach = customer_analysis_render_recommendation_text($recommendation);
		if ($approach === '') {
			$approach = customer_analysis_coerce_str(isset($data['approach_suggestion']) ? $data['approach_suggestion'] : '');
		}
		return array(
			'temperature'         => customer_analysis_normalize_temperature(isset($data['temperature']) ? $data['temperature'] : ''),
			'temperature_reason'  => customer_analysis_coerce_str(isset($data['temperature_reason']) ? $data['temperature_reason'] : ''),
			'approach_suggestion' => $approach,
			'recommendation'      => $recommendation,
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
