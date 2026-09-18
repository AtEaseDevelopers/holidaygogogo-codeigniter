<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Competitor Analysis — pure, DB-less helpers shared by the controller, the
 * OpenAI service and the unit test. Anything that can be reasoned about without
 * a network call or the database lives here so it can be locked by
 * tests/helpers/CompetitorAnalysisHelperTest.php.
 *
 * The AI agent does its own scraping: we only hand OpenAI the URL and our own
 * products, and the built-in web_search tool opens and reads the page. So the
 * helpers here shape the request and normalise the reply — there is no HTML
 * fetching/stripping on our side.
 *
 *   competitor_format_our_products()  dashboard rows -> compact "our products"
 *   competitor_build_agent_input()    url + products -> {instructions, input}
 *   competitor_extract_responses_text() Responses API JSON -> assistant text
 *   competitor_extract_usage()        Responses API JSON -> {input,output} tokens
 *   competitor_estimate_cost()        model + tokens   -> USD cost estimate
 *   competitor_parse_ai_response()    assistant text -> flat, DB-ready record
 *
 * The OpenAI HTTP call itself lives in libraries/CompetitorAnalysisService.php.
 */

if ( ! function_exists('competitor_format_our_products'))
{
	/**
	 * Flatten "our products" into a compact list for the OUR PRODUCTS prompt
	 * block. Handles two inputs:
	 *   - Product_Model::Read_For_Comparison() rows (the current source): our own
	 *     products enriched with tour detail (destination, duration, itinerary,
	 *     inclusions, flights, ...) so the AI can compare like-for-like.
	 *   - Legacy Costing dashboard (['packages'=>[...]] or a bare list) — name,
	 *     tour_code and the latest booking's per-pax selling price.
	 * Tolerates arrays or objects. Empty fields are omitted downstream by
	 * competitor_products_block() to keep prompt tokens (and cost) low.
	 */
	function competitor_format_our_products($source, $limit = 40)
	{
		$rows = array();
		if (is_array($source) && isset($source['packages'])) {
			$rows = $source['packages'];
		} elseif (is_array($source)) {
			$rows = $source;
		}

		// Product columns (from Read_For_Comparison) -> compact prompt keys.
		$single_map = array(
			'Destination' => 'destination', 'Duration' => 'duration',
			'DepartureCity' => 'departure_city', 'Difficulty' => 'difficulty',
			'SuitableAge' => 'suitable_age', 'TargetTraveller' => 'target_traveller',
			'TourStyle' => 'tour_style',
		);
		$list_map = array(
			'Countries' => 'countries', 'Cities' => 'cities', 'Themes' => 'themes',
			'LocalTransport' => 'local_transport', 'Hotels' => 'hotels',
			'ScenicHighlights' => 'scenic_highlights', 'ShoppingStops' => 'shopping_stops',
			'Inclusions' => 'inclusions', 'Exclusions' => 'exclusions',
			'OptionalTours' => 'optional_tours', 'Itinerary' => 'itinerary',
			'SpecialRemarks' => 'special_remarks',
		);

		$out = array();
		foreach ($rows as $p) {
			$p = (array) $p;
			$is_product = array_key_exists('Name', $p) || array_key_exists('ProductCode', $p) || array_key_exists('RetailPrice', $p);

			if ( ! $is_product) {
				// Legacy costing package shape.
				$name = isset($p['name']) ? trim((string) $p['name']) : '';
				if ($name === '') {
					continue;
				}
				$price = null;
				if ( ! empty($p['bookings'])) {
					$first = (array) reset($p['bookings']);
					if (isset($first['selling_price_per_pax']) && $first['selling_price_per_pax'] !== null && $first['selling_price_per_pax'] !== '') {
						$price = (float) $first['selling_price_per_pax'];
					}
				}
				$out[] = array(
					'name'      => $name,
					'tour_code' => isset($p['tour_code']) ? (string) $p['tour_code'] : '',
					'price_myr' => $price,
				);
			} else {
				// Our own product enriched with tour detail.
				$name = isset($p['Name']) ? trim((string) $p['Name']) : '';
				if ($name === '') {
					continue;
				}
				$item = array('name' => $name);
				$code = isset($p['ProductCode']) ? trim((string) $p['ProductCode']) : '';
				if ($code !== '') {
					$item['tour_code'] = $code;
				}
				if (isset($p['RetailPrice']) && $p['RetailPrice'] !== null && $p['RetailPrice'] !== '' && (float) $p['RetailPrice'] > 0) {
					$item['price_myr'] = (float) $p['RetailPrice'];
				}
				foreach ($single_map as $col => $key) {
					if (isset($p[$col]) && trim((string) $p[$col]) !== '') {
						$item[$key] = trim((string) $p[$col]);
					}
				}
				$meals = array();
				foreach (array('MealBreakfast' => 'breakfast', 'MealLunch' => 'lunch', 'MealDinner' => 'dinner') as $col => $key) {
					if (isset($p[$col]) && trim((string) $p[$col]) !== '') {
						$meals[$key] = trim((string) $p[$col]);
					}
				}
				if ( ! empty($meals)) {
					$item['meals'] = $meals;
				}
				foreach ($list_map as $col => $key) {
					if (isset($p[$col]) && trim((string) $p[$col]) !== '') {
						$lines = preg_split('/\r\n|\r|\n/', trim((string) $p[$col]));
						$lines = array_values(array_filter(array_map('trim', $lines), function ($x) { return $x !== ''; }));
						if ( ! empty($lines)) {
							$item[$key] = $lines;
						}
					}
				}
				// Multiple structured flights -> readable one-liners.
				if (isset($p['Flights']) && trim((string) $p['Flights']) !== '' && function_exists('product_flights_decode')) {
					$flight_lines = array();
					foreach (product_flights_decode($p['Flights']) as $fl) {
						$s = product_flight_summary($fl);
						if ($s !== '') {
							$flight_lines[] = $s;
						}
					}
					if ( ! empty($flight_lines)) {
						$item['flights'] = $flight_lines;
					}
				}
				$out[] = $item;
			}

			if ($limit > 0 && count($out) >= $limit) {
				break;
			}
		}
		return $out;
	}
}

if ( ! function_exists('competitor_output_contract'))
{
	/**
	 * The shared JSON-shape contract every mode (URL / PDF / image) must obey,
	 * so competitor_parse_ai_response() reads back the same fields regardless of
	 * how the competitor product was supplied.
	 */
	function competitor_output_contract()
	{
		$schema_hint = '{'
			. '"product_name": string, '
			. '"tour_code": string (competitor product/reference code, "" if none), '
			. '"price": string (headline price with currency as shown, "" if unknown), '
			. '"price_from": string (lowest price e.g. "RM1999", "" if unknown), '
			. '"price_to": string (highest price, "" if unknown), '
			. '"currency": string (ISO code if identifiable, else ""), '
			. '"destination": string (main destination label), '
			. '"countries": string[] (every country visited), '
			. '"cities": string[] (every city/town visited), '
			. '"duration": string (days/nights e.g. "5D4N"), '
			. '"travel_months": string[] (departure months/dates offered), '
			. '"departure_city": string (where the tour departs from), '
			. '"flight_departure": string (FULL outbound flight detail EXACTLY as given: airline + flight no + from/to airports + date + time for every outbound leg, joining connecting legs with " -> "; "" if none), '
			. '"flight_return": string (FULL return flight detail the same way, all legs; "" if none), '
			. '"themes": string[] (DERIVE trip themes e.g. "Nature","Culture","Shopping"), '
			. '"tour_styles": string[] (DERIVE e.g. "Group tour","Free & easy","Luxury"), '
			. '"difficulty": string (DERIVE physical difficulty e.g. "Easy","Moderate","Challenging"), '
			. '"local_transport": string[] (coach, bullet train, cruise, ferry, etc.), '
			. '"inclusions": string[] (what the price includes), '
			. '"exclusions": string[] (what is NOT included), '
			. '"hotels": string[] (hotels/accommodation named), '
			. '"meals": {"breakfast": string, "lunch": string, "dinner": string} (for each meal, work through the day-by-day meal plan and give the COUNT plus the exact days it is provided e.g. "3 (Day 2,3,4)"; "" if the plan never provides that meal — do not guess a count), '
			. '"shopping_stops": string[] (EVERY shopping/factory/retail stop the source states. Format EACH item as "<stop> — <justification>" where the justification is GROUNDED IN FACT FROM THE SOURCE — state the concrete detail directly (the itinerary day and what happens there), e.g. "Pearl gallery — Day 3 includes a guided visit to a pearl factory". Do NOT invent stops or reasons; if the source names a stop but gives no supporting detail, list the stop alone with no justification), '
			. '"optional_tours": string[] (EVERY optional/add-on tour listed, each one verbatim WITH its price and conditions e.g. min pax / what is included — never drop, merge or summarise any), '
			. '"special_remarks": string[] (EVERY important note/term/condition, each listed separately — e.g. guide/commentary language, nationality restriction, room & single-supplement rules, insurance, disclaimers; do not omit any), '
			. '"scenic_highlights": [{"name": string, "description": string}] (DERIVE the key scenic/sightseeing highlights; "name" is the place/attraction, "description" tells the customer what to EXPECT during their visit there — the sights, views, activities and experience from the traveller\'s point of view, roughly 1-2 sentences. Only include a highlight when the source gives concrete clues about it; leave "description" "" if the source names the place but says nothing about the experience), '
			. '"target_traveller": string (DERIVE ideal traveller e.g. "Families","Seniors","Couples"), '
			. '"suitable_age": string (DERIVE suitable age range), '
			. '"child_friendly": string (DERIVE "Yes"/"No" + short reason), '
			. '"senior_friendly": string (DERIVE "Yes"/"No" + short reason), '
			. '"usp": string[] (DERIVE unique selling points for the target traveller), '
			. '"traveller_segments": [{"segment": string (EXACTLY one of: "single","elderly","teenager","couple","family_kids","family_elderly","company"), "suitability": string ("High"/"Medium"/"Low"), "justification": string (a THOROUGH, well-reasoned verdict of AT LEAST 4-6 sentences on WHY this tour does or does not suit that traveller type. Do NOT give a thin one-liner. Build the case by: (1) citing SEVERAL CONCRETE tour attributes by name — pace & walking difficulty, itinerary intensity & free time, meals, hotel tier, specific activities/attractions, child/senior friendliness, price/value, group vs free-and-easy style; (2) explaining HOW each cited attribute helps or hurts THIS specific traveller type (e.g. why long walking days matter for elderly, why the activity mix matters for teenagers); (3) naming BOTH the strongest reasons it fits AND the main caveats or trade-offs, so the reasoning is balanced; (4) ending with a clear takeaway that justifies the High/Medium/Low level given. Ground every claim in the source — do NOT invent facts.)}] '
				. '(Assess ALL SEVEN traveller types once each, in that order. Judge each strictly from concrete clues in the source; give a suitability level and a rich, well-evidenced justification as described above. If the source genuinely gives no basis to judge a type, still include it with suitability "" and a short note on exactly what information is missing to make the call — do NOT invent facts), '
			. '"itinerary": [{"day": string, "title": string, "description": string}] (one entry per day; the description must list ALL places/activities visited that day, not just a few), '
			. '"pros": string[] (advantages FROM THE CUSTOMER\'S POINT OF VIEW — what a traveller booking this tour actually gains, not marketing spin. Format EACH item as "<benefit> — <justification>" where the justification explains WHY it matters to the customer, e.g. "Direct flights — less travel fatigue and a full extra day at the destination"), '
			. '"cons": string[] (drawbacks FROM THE CUSTOMER\'S POINT OF VIEW — what a traveller should be wary of before booking. Format EACH item as "<drawback> — <justification>" explaining WHY it matters to the customer, e.g. "Many shopping stops — less sightseeing time and possible sales pressure"), '
			. '"summary": string (a DETAILED overview, roughly 150-250 words, written as several short labelled paragraphs each separated by a BLANK LINE. Use these exact labels, each on its own line immediately followed by its text: '
				. '"Overview:" (what the tour is — main destination, the FULL route through the key cities/regions in order, total duration, departure city, and the overall tour style & pace); '
				. '"Best for:" (the ideal traveller for this tour and, if the source hints it, who it is NOT suited to, each with a short reason); '
				. '"Inclusions & value:" (the key things the price covers — flights, hotel tier, meals, transport — and how the pricing is positioned: budget / mid-range / premium relative to what is offered); '
				. '"Highlights:" (the 2-3 standout experiences that make this tour worth booking); '
				. '"Watch-outs:" (the main limitations, extra costs or things a traveller should check before booking). '
				. 'SYNTHESISE the source into genuine insight — do NOT merely restate raw field values. Omit any single labelled paragraph whose information is genuinely absent from the source rather than guessing, but keep the rest.), '
			. '"comparison": string (FIRST find a product in OUR PRODUCTS below that MATCHES or is SIMILAR to this competitor product — same/overlapping destination or tour type. If one matches, compare against THAT product only: pricing, value, gaps, and a recommendation. If NONE of our products match or are similar, do NOT force a comparison — state plainly that we have no comparable product on our side for this destination/type.), '
			. '"matched_product": string (the EXACT "name" from OUR PRODUCTS you compared against in "comparison"; "" if none matched)'
			. '}';
		return "Reply with ONLY a single JSON object, no markdown, no code fences, matching exactly this shape: "
			. $schema_hint . ". "
			. "CRITICAL: make NO assumptions. Every value must be grounded in the supplied source (the scraped/pasted/attached content and the itinerary) — treat the source as your only evidence. "
			. "WRITING STYLE: every human-readable value (all descriptions, justifications, pros, cons, highlights, summary and notes) is shown to — and shared directly with — the traveller, so write it as ready-to-send customer copy. State facts directly as facts and speak to the traveller naturally. NEVER refer to the evidence or how you know it: do not write \"the source states\", \"the source mentions\", \"the itinerary states/says/lists\", \"the terms require/state\", \"according to the source/page\" or any similar meta-reference. Grounding governs WHAT you may say, not HOW you phrase it — e.g. instead of \"The source states guests can relax by the pool\" write \"Unwind by the resort swimming pool\"; instead of \"the itinerary states 566KM for Aswan-Abu Simbel\" write \"Long drives such as 566KM Aswan-Abu Simbel-Aswan can be tiring\"; instead of \"the terms require travellers to be physically and medically fit\" write \"You should be physically and medically fit\". "
			. "Fields marked DERIVE: only fill them when the source contains concrete clues that support the value; base the value strictly on those clues, not on general knowledge, typical-tour patterns, or what is likely. "
			. "If the source gives no clue for a DERIVE field, leave it \"\" or []. Do NOT guess. "
			. "For every other field use \"\" or [] when the source does not state it — never invent literal facts like prices, hotel names, dates or codes.";
	}
}

if ( ! function_exists('competitor_products_block'))
{
	/**
	 * Compact JSON of our own products for the "OUR PRODUCTS" prompt block.
	 * To minimise input tokens (and cost) on every OpenAI call we strip dead
	 * weight: keep `name` always, include `tour_code` only when non-empty and
	 * `price_myr` only when known. Null prices / empty codes carry no signal for
	 * the comparison, so we never pay to send them.
	 */
	function competitor_products_block($our_products)
	{
		$rows = is_array($our_products) ? $our_products : array();
		$compact = array();
		foreach ($rows as $p) {
			$p = (array) $p;
			$name = isset($p['name']) ? trim((string) $p['name']) : '';
			if ($name === '') {
				continue;
			}
			// Keep `name` always; drop every empty field (null, '', []) — they
			// carry no signal for the comparison, so we never pay to send them.
			$item = array('name' => $name);
			foreach ($p as $k => $v) {
				if ($k === 'name') {
					continue;
				}
				if ($v === null || $v === '' || (is_array($v) && count($v) === 0)) {
					continue;
				}
				$item[$k] = $v;
			}
			$compact[] = $item;
		}
		return json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
}

if ( ! function_exists('competitor_build_agent_input'))
{
	/**
	 * Build the Responses API request pieces for the URL/browsing agent — the
	 * fallback for a JavaScript SPA our own scraper couldn't read. The agent opens
	 * and reads the page itself via the web_search tool. When we DID manage to
	 * scrape partial content (e.g. SEO JSON-LD / metadata on an otherwise-blank SPA
	 * shell), pass it as $partial_text so the agent starts from it and browses only
	 * to FILL THE GAPS (full itinerary, prices, inclusions) — a hybrid that costs
	 * less browsing and grounds the answer in real page data.
	 * Returns ['instructions' => system prompt, 'input' => user text].
	 */
	function competitor_build_agent_input($url, $our_products, $partial_text = '')
	{
		$partial_text = trim((string) $partial_text);
		$instructions = "You are a travel-industry competitor analyst for HolidayGoGoGo, a Malaysian tour operator. "
			. "The competitor page is a JavaScript app our scraper could not fully read. "
			. "Use the web_search tool to OPEN and READ the competitor product page at the URL the user gives — "
			. "scrape its full content yourself; do not rely on prior knowledge. "
			. ($partial_text !== ''
				? "Partial content we already scraped is provided; treat it as a starting point and use web_search to FILL IN the missing full details (day-by-day itinerary, prices, inclusions). "
				: "")
			. "Then extract the competitor product's details and compare it against the user's own products. "
			. competitor_output_contract();

		$input = "COMPETITOR URL (open and scrape this page):\n" . $url . "\n\n";
		if ($partial_text !== '') {
			$input .= "PARTIAL CONTENT WE ALREADY SCRAPED (may be incomplete — verify/complete it by browsing):\n"
				. $partial_text . "\n\n";
		}
		$input .= "OUR PRODUCTS (JSON, prices in MYR):\n" . competitor_products_block($our_products);

		return array('instructions' => $instructions, 'input' => $input);
	}
}

if ( ! function_exists('competitor_is_boilerplate_line'))
{
	/**
	 * True when a single scraped line is unambiguous SITE CHROME / NOISE rather than
	 * tour content — cookie banners, newsletter/subscribe prompts, social-share
	 * widgets, breadcrumbs, CTA/booking buttons, account nav, copyright footers, and
	 * "related tours" carousel headings. Patterns are deliberately SPECIFIC/anchored
	 * so real tour lines survive (e.g. "Day 3: visit the cookie factory", "Tour fare
	 * includes daily breakfast", "Book this 5D4N tour from RM1899" are all kept). Pure.
	 */
	function competitor_is_boilerplate_line($line)
	{
		$s = trim((string) $line);
		if ($s === '') {
			return false;   // blanks are handled by the caller
		}
		static $patterns = array(
			// Cookie consent (needs the consent phrasing — not a bare "cookie").
			'/\b(?:we use cookies|this (?:website|site) uses cookies|accept all cookies|cookie (?:policy|settings|preferences|consent)|manage cookies)\b/i',
			// Newsletter / subscribe prompts.
			'/\b(?:subscribe to (?:our )?newsletter|sign ?up (?:for|to)(?: our)? newsletter|join our (?:mailing list|newsletter)|subscribe (?:now|to our))\b/i',
			// Social prompts + standalone social labels.
			'/\bfollow us on\b/i',
			'/\bshare (?:this|on)\b/i',
			'/^(?:facebook|instagram|twitter|youtube|tiktok|whatsapp|linkedin|pinterest|telegram)$/i',
			// Breadcrumb trail ("Home > Tours > Japan").
			'#^home\s*[>»/|]\s*\S#i',
			// CTA / booking / UI buttons (whole line only).
			'/^(?:book now|enquire now|enquiry now|make (?:an )?enquiry|add to (?:cart|wishlist|itinerary)|read more|view more|load more|show more|view details|see more|back to top|print|download(?: brochure| itinerary)?|whatsapp us|call us|chat with us|get a quote|request (?:a )?quote|book this (?:tour|trip|package))$/i',
			// Account / header nav (whole line only).
			'#^(?:log ?in|sign ?in|sign ?up|register|my account|my cart|cart|wishlist|login/register)$#i',
			// Copyright / footer.
			'/(?:all rights reserved|©\s*\d{4}|copyright\s*©)/i',
			// "Related / you may also like" carousel headings.
			'/^(?:you may also like|you might also like|related (?:tours|products|packages|trips)|recommended (?:for you|tours|packages)|similar (?:tours|packages|trips)|other (?:tours|packages))\b/i',
		);
		foreach ($patterns as $re) {
			if (preg_match($re, $s)) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists('competitor_strip_boilerplate'))
{
	/**
	 * Drop boilerplate/chrome lines (competitor_is_boilerplate_line) from a block of
	 * already-extracted text — the per-page de-noise pass used on EVERY source
	 * (HTML, JSON API, PDF/OCR), so noise the cross-item chrome strip can't catch
	 * (single-product crawls, mid-page widgets, non-HTML sources) is removed before
	 * the AI sees it. Pure. Returns '' for non-strings/empties.
	 */
	function competitor_strip_boilerplate($text)
	{
		if ( ! is_string($text) || $text === '') {
			return '';
		}
		$out = array();
		foreach (preg_split('/\r\n|\r|\n/', $text) as $ln) {
			if ( ! competitor_is_boilerplate_line($ln)) {
				$out[] = $ln;
			}
		}
		return implode("\n", $out);
	}
}

if ( ! function_exists('competitor_page_char_cap'))
{
	/**
	 * The per-page character cap for scraped text (bounds AI token cost). Reads an
	 * env override (COMPETITOR_MAX_PAGE_CHARS) when it's a positive integer, else the
	 * default. Raised from the old 40k so a long multi-day itinerary + inclusions +
	 * optional-tours tail isn't truncated away. Pure. 0/blank env -> default.
	 */
	function competitor_page_char_cap($env = null, $default = 60000)
	{
		$n = (int) $env;
		return $n > 0 ? $n : (int) $default;
	}
}

if ( ! function_exists('competitor_html_to_text'))
{
	/**
	 * Reduce raw page HTML to clean, readable text for the AI — our own scraper's
	 * output. Drops noise blocks (script/style/head/svg) and comments, turns block
	 * boundaries into newlines so the itinerary keeps its shape, strips remaining
	 * tags, decodes entities, collapses whitespace, and drops boilerplate/chrome
	 * lines (competitor_is_boilerplate_line) BEFORE the cap so the char budget is
	 * spent on real content, not menus. Capped at $max_chars — cut on a line
	 * boundary, not mid-itinerary — to bound token cost (0 = uncapped). Pure — the
	 * network fetch lives in the service. Returns '' for non-strings/empties.
	 */
	function competitor_html_to_text($html, $max_chars = 60000)
	{
		if ( ! is_string($html) || $html === '') {
			return '';
		}
		// Whole blocks whose content is noise (incl. site nav/footer/sidebar chrome,
		// search/booking widgets), plus HTML comments.
		$html = preg_replace('#<(script|style|noscript|template|svg|head|nav|footer|aside|form)\b[^>]*>.*?</\1>#is', ' ', $html);
		$html = preg_replace('#<!--.*?-->#s', ' ', $html);
		// Preserve structure: block/line boundaries become newlines; table cells
		// get a " | " separator so a row's fields don't run together.
		$html = preg_replace('#<br\s*/?>#i', "\n", $html);
		$html = preg_replace('#</(td|th)>#i', ' | ', $html);
		$html = preg_replace('#</(p|div|li|tr|h[1-6]|section|article|ul|ol|table|header|footer)>#i', "\n", $html);
		$text = strip_tags($html);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		// Collapse spaces/tabs/nbsp, trim each line, tidy cell separators, drop blanks.
		$text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
		$out = array();
		$prev = null;
		foreach (preg_split('/\r\n|\r|\n/', $text) as $ln) {
			$ln = preg_replace('/\s*\|\s*/', ' | ', $ln);
			$ln = trim($ln, " |");
			// Drop blanks, consecutive duplicate lines (repeated menu/CTA chrome), and
			// boilerplate/chrome lines (cookie/subscribe/social/breadcrumb/CTA/footer).
			if ($ln !== '' && $ln !== $prev && ! competitor_is_boilerplate_line($ln)) {
				$out[] = $ln;
				$prev  = $ln;
			}
		}
		$text = implode("\n", $out);
		if ($max_chars > 0 && mb_strlen($text, 'UTF-8') > $max_chars) {
			$text = mb_substr($text, 0, $max_chars, 'UTF-8');
			// Cut back to the last line boundary so we don't truncate mid-itinerary
			// (unless that would discard more than half the budget — no newlines).
			$nl = mb_strrpos($text, "\n", 0, 'UTF-8');
			if ($nl !== false && $nl > (int) ($max_chars * 0.5)) {
				$text = mb_substr($text, 0, $nl, 'UTF-8');
			}
		}
		return $text;
	}
}

if ( ! function_exists('competitor_scrape_is_thin'))
{
	/**
	 * True when our scrape returned too little usable text to analyse — the tell
	 * for a JS-rendered page that plain fetching can't read. The service falls
	 * back to the web_search agent in that case. Pure.
	 */
	function competitor_scrape_is_thin($text, $min_chars = 500)
	{
		return mb_strlen(trim((string) $text), 'UTF-8') < (int) $min_chars;
	}
}

if ( ! function_exists('competitor_flatten_json_text'))
{
	/**
	 * Flatten a decoded JSON value (from JSON-LD or a framework state blob) into
	 * readable lines for the AI, keeping only human-meaningful scalars. Skips
	 * structural/noise keys (@context, @type, id, url, image, css class…) and
	 * values that carry no prose signal (pure urls, asset paths, booleans, empty);
	 * bare numbers are kept only under a price-like key. Recurses arrays/objects,
	 * bounded against pathological input by depth + node caps. Pure.
	 */
	function competitor_flatten_json_text($data, $depth = 0)
	{
		static $noise_keys = array('@context', '@type', '@id', 'id', 'url', 'href',
			'src', 'image', 'images', 'icon', 'logo', 'thumbnail', 'class', 'classname',
			'style', 'type', 'key', 'slug', 'width', 'height', 'color', 'background', 'sku');
		if ($depth > 8 || ! is_array($data)) {
			return '';
		}
		$lines = array();
		$count = 0;
		foreach ($data as $k => $v) {
			if (++$count > 5000) {
				break;
			}
			$key = is_string($k) ? strtolower($k) : '';
			if ($key !== '' && in_array($key, $noise_keys, true)) {
				continue;
			}
			if (is_array($v)) {
				$sub = competitor_flatten_json_text($v, $depth + 1);
				if ($sub !== '') {
					$lines[] = $sub;
				}
				continue;
			}
			if (is_bool($v) || $v === null) {
				continue;
			}
			$val = trim((string) $v);
			if ($val === '') {
				continue;
			}
			// Drop pure urls / asset paths — no prose signal, just noise + tokens.
			if (preg_match('#^(https?:)?//#i', $val)
				|| preg_match('#\.(png|jpe?g|gif|webp|svg|css|js|ico)(\?|$)#i', $val)) {
				continue;
			}
			// Bare numbers are noise unless the key names a price/amount.
			$is_price_key = $key !== '' && preg_match('/price|amount|cost|fare|rate/', $key);
			if ( ! preg_match('/\p{L}/u', $val) && ! $is_price_key) {
				continue;
			}
			$line = ($key !== '' ? $k . ': ' : '') . $val;
			$lines[] = trim(preg_replace('/\s+/', ' ', $line));
		}
		return implode("\n", array_filter($lines, 'strlen'));
	}
}

if ( ! function_exists('competitor_extract_meta_text'))
{
	/**
	 * Pull OpenGraph / SEO meta tags into readable lines — the thin-but-free
	 * fallback when a page carries no JSON-LD or state blob. Reads <title>,
	 * og:title/description/site_name, meta description and product/og price meta.
	 * Deduped, order-preserving. Pure.
	 */
	function competitor_extract_meta_text($html)
	{
		if ( ! is_string($html) || $html === '') {
			return '';
		}
		$lines = array();
		if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
			$t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			if ($t !== '') {
				$lines[] = 'Title: ' . $t;
			}
		}
		$wanted = array(
			'og:title'              => 'Title',
			'og:description'        => 'Description',
			'og:site_name'          => 'Site',
			'description'           => 'Description',
			'product:price:amount'  => 'Price',
			'product:price:currency'=> 'Currency',
			'og:price:amount'       => 'Price',
			'og:price:currency'     => 'Currency',
		);
		if (preg_match_all('#<meta\b[^>]*>#i', $html, $tags)) {
			foreach ($tags[0] as $tag) {
				$name = '';
				if (preg_match('#(?:property|name)\s*=\s*(["\'])(.*?)\1#i', $tag, $nm)) {
					$name = strtolower(trim($nm[2]));
				}
				if ($name === '' || ! isset($wanted[$name])) {
					continue;
				}
				if (preg_match('#content\s*=\s*(["\'])(.*?)\1#is', $tag, $cm)) {
					$val = trim(html_entity_decode($cm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
					if ($val !== '') {
						$lines[] = $wanted[$name] . ': ' . $val;
					}
				}
			}
		}
		$seen = array();
		$out  = array();
		foreach ($lines as $l) {
			$key = mb_strtolower($l, 'UTF-8');
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$out[] = $l;
		}
		return implode("\n", $out);
	}
}

if ( ! function_exists('competitor_jsonld_product_text'))
{
	/**
	 * Extract ONLY the travel-PRODUCT schema.org JSON-LD from a page
	 * (<script type="application/ld+json">), flattened to readable text — the
	 * @type is Product/Trip/TouristTrip/TouristAttraction/TouristDestination/Offer/
	 * AggregateOffer/Event. Handles a bare node, an array of nodes, or an @graph.
	 * Ignores non-product JSON-LD (WebSite/BreadcrumbList/Organization/…). Deduped.
	 * Used to LEAD the source text with clean structured facts even when the HTML
	 * itself isn't thin (noisy SPA shells otherwise bury the real data). Returns ''
	 * when no product JSON-LD is present. Pure.
	 */
	function competitor_jsonld_product_text($html)
	{
		if ( ! is_string($html) || $html === '') {
			return '';
		}
		if ( ! preg_match_all('#<script[^>]*type\s*=\s*(["\'])application/ld\+json\1[^>]*>(.*?)</script>#is', $html, $m)) {
			return '';
		}
		$wanted = array('product', 'trip', 'touristtrip', 'touristattraction',
			'touristdestination', 'offer', 'aggregateoffer', 'event', 'tourpackage');

		$lines = array();
		$seen  = array();
		foreach ($m[2] as $blob) {
			$data = json_decode(trim($blob), true);
			if ( ! is_array($data)) {
				continue;
			}
			// Candidate nodes: an @graph, a bare list, or the single object.
			$nodes = array();
			if (isset($data['@graph']) && is_array($data['@graph'])) {
				$nodes = $data['@graph'];
			} elseif (array_keys($data) === range(0, count($data) - 1)) {
				$nodes = $data;
			} else {
				$nodes = array($data);
			}
			foreach ($nodes as $node) {
				if ( ! is_array($node) || ! isset($node['@type'])) {
					continue;
				}
				$types = is_array($node['@type']) ? $node['@type'] : array($node['@type']);
				$match = false;
				foreach ($types as $t) {
					if (in_array(strtolower(trim((string) $t)), $wanted, true)) {
						$match = true;
						break;
					}
				}
				if ( ! $match) {
					continue;
				}
				foreach (preg_split('/\r\n|\r|\n/', competitor_flatten_json_text($node)) as $ln) {
					$ln  = trim($ln);
					$key = mb_strtolower($ln, 'UTF-8');
					if ($ln === '' || isset($seen[$key])) {
						continue;
					}
					$seen[$key] = true;
					$lines[] = $ln;
				}
			}
		}
		return implode("\n", $lines);
	}
}

if ( ! function_exists('competitor_jsonld_types'))
{
	/**
	 * All schema.org @type values declared in a page's JSON-LD blocks, lowercased
	 * (nodes inside @graph and bare lists included). Lets us tell a single product
	 * page from a category/listing page. Pure. Returns [] when none.
	 */
	function competitor_jsonld_types($html)
	{
		if ( ! is_string($html) || $html === '') {
			return array();
		}
		if ( ! preg_match_all('#<script[^>]*type\s*=\s*(["\'])application/ld\+json\1[^>]*>(.*?)</script>#is', $html, $m)) {
			return array();
		}
		$types = array();
		foreach ($m[2] as $blob) {
			$data = json_decode(trim($blob), true);
			if ( ! is_array($data)) {
				continue;
			}
			if (isset($data['@graph']) && is_array($data['@graph'])) {
				$nodes = $data['@graph'];
			} elseif (array_keys($data) === range(0, count($data) - 1)) {
				$nodes = $data;
			} else {
				$nodes = array($data);
			}
			foreach ($nodes as $node) {
				if ( ! is_array($node) || ! isset($node['@type'])) {
					continue;
				}
				foreach ((is_array($node['@type']) ? $node['@type'] : array($node['@type'])) as $t) {
					$t = strtolower(trim((string) $t));
					if ($t !== '') {
						$types[$t] = true;
					}
				}
			}
		}
		return array_keys($types);
	}
}

if ( ! function_exists('competitor_looks_like_listing'))
{
	/**
	 * True when a page's JSON-LD marks it as a category/listing/search page
	 * (ItemList, CollectionPage, SearchResultsPage…) and NOT a single product —
	 * so we can drop it instead of analysing a whole catalogue as one "product".
	 * A page that ALSO carries a product type is kept (product wins). Pure.
	 */
	function competitor_looks_like_listing($types)
	{
		if ( ! is_array($types) || empty($types)) {
			return false;   // no evidence → don't drop
		}
		$listing = array('itemlist', 'collectionpage', 'searchresultspage', 'offercatalog');
		$product = array('product', 'trip', 'touristtrip', 'touristattraction',
			'touristdestination', 'offer', 'aggregateoffer', 'event', 'tourpackage');
		$has_listing = (bool) array_intersect($types, $listing);
		$has_product = (bool) array_intersect($types, $product);
		return $has_listing && ! $has_product;
	}
}

if ( ! function_exists('competitor_text_looks_like_listing'))
{
	/**
	 * Content-level catch for a CATALOGUE page that had no listing JSON-LD to flag it
	 * — a page that lays out SEVERAL separate itineraries (each restarting at "Day 1").
	 * A single tour has exactly one "Day 1"; only a multi-product listing/comparison
	 * page repeats it. True = looks like a multi-product listing, so the service drops
	 * it. Pure.
	 *
	 * NOTE: price count is deliberately NOT used — a single tour with a departure
	 * calendar legitimately shows many distinct prices (e.g. Trip Yunnan-9D7N has 14),
	 * so a price threshold wrongly drops real products.
	 */
	function competitor_text_looks_like_listing($text, $min_itineraries = 3)
	{
		if ( ! is_string($text) || $text === '') {
			return false;
		}
		// Several separate itineraries on one page (each product restarts at "Day 1").
		return preg_match_all('/\bday\s*0?1\b/iu', $text) >= $min_itineraries;
	}
}

if ( ! function_exists('competitor_has_product_signal'))
{
	/**
	 * True when the text carries a hallmark of a real TOUR PRODUCT — a price, a
	 * duration (5D4N / "8 days" / "7 nights"), a day-by-day itinerary, inclusions, or
	 * the schema.org product marker. Used to tell a product from a blog article /
	 * info page. Pure.
	 */
	function competitor_has_product_signal($text)
	{
		if ( ! is_string($text) || $text === '') {
			return false;
		}
		return (bool) (
			preg_match('/(?:rm|myr|sgd|usd|eur|php|idr|thb|aud|\$|£|€)\s*[0-9][0-9,]{2,}/iu', $text)   // price
			|| preg_match('/\b\d{1,2}\s*d\s*\d{1,2}\s*n\b/i', $text)                                    // 5D4N
			|| preg_match('/\b\d{1,2}\s*(?:days?|nights?)\b/i', $text)                                  // "8 days"
			|| preg_match('/\bday\s*0?1\b/i', $text)                                                     // itinerary
			|| preg_match('/\b(?:inclusion|exclusion|itinerary|twin\s+share|half\s+board|full\s+board)\b/i', $text)
			|| stripos($text, 'STRUCTURED PRODUCT DATA') !== false
		);
	}
}

if ( ! function_exists('competitor_looks_like_article'))
{
	/**
	 * True when a page is a blog ARTICLE / info page rather than a bookable product —
	 * substantial prose with NO product signal (price / duration / itinerary). Fixes
	 * WordPress content sites (holidaygogogo) where posts like "spice-up-your-holiday…"
	 * carry a product keyword in the URL but are not tours. A THIN page is given the
	 * benefit of the doubt (may be an under-scraped SPA product), so only rich prose
	 * is dropped. Pure.
	 */
	function competitor_looks_like_article($text, $min_len = 1200)
	{
		if ( ! is_string($text) || mb_strlen($text, 'UTF-8') < $min_len) {
			return false;
		}
		return ! competitor_has_product_signal($text);
	}
}

if ( ! function_exists('competitor_has_basic_tour_sections'))
{
	/**
	 * True when a scraped page carries the BASIC hallmarks of a real, bookable tour
	 * product — a day-by-day itinerary AND an inclusions section (what the price
	 * covers). This is the crawl quality gate: a full tour page has both; a
	 * landing/overview/category page or an under-scraped SPA shell does not, so the
	 * crawler drops it. Exclusions are deliberately NOT required — many genuine tour
	 * pages bury or omit an explicit exclusions list. Pure.
	 */
	function competitor_has_basic_tour_sections($text)
	{
		if ( ! is_string($text) || $text === '') {
			return false;
		}
		// Day-by-day itinerary — a real tour has a "Day 1" (also "Day 01" / "Day01").
		$has_itinerary = (bool) preg_match('/\bday\s*0?1\b/iu', $text);
		// An inclusions section / "price includes" wording (not a stray "include").
		$has_inclusions = (bool) preg_match(
			'/\binclusions?\b'
			. '|\b(?:price|package|tour|trip|fare|cost|holiday)\s+includes?\b'
			. '|\bwhat\'?s\s+included\b'
			. '|\bincludes?\s*:'
			. '|\bincluded\s+in\s+(?:the\s+)?(?:price|tour|package|fare|cost)\b/iu',
			$text
		);
		return $has_itinerary && $has_inclusions;
	}
}

if ( ! function_exists('competitor_has_tour_itinerary'))
{
	/**
	 * True when the text carries a REAL day-by-day itinerary — the itinerary-primary
	 * crawl gate. A genuine tour page has at least two DISTINCT day markers ("Day 1"
	 * … "Day 2" …), or a single "Day 1" together with an explicit trip duration
	 * (5D4N / "3 days"). A lone stray "Day 1" does NOT qualify. Inclusions/exclusions
	 * are treated as bonus, not required — many real tour pages never label them in
	 * words, so requiring them dropped genuine tours. Multi-product LISTING pages
	 * (many "Day 1"s) are filtered earlier by competitor_text_looks_like_listing. Pure.
	 */
	function competitor_has_tour_itinerary($text)
	{
		if ( ! is_string($text) || $text === '') {
			return false;
		}
		if (preg_match_all('/\bday\s*0?([1-9][0-9]?)\b/iu', $text, $m)) {
			$nums = array_unique(array_map('intval', $m[1]));
			if (count($nums) >= 2) {
				return true;   // a real multi-day day-by-day itinerary
			}
		}
		// Single-day itinerary: a "Day 1" plus an explicit tour duration.
		$has_day1 = (bool) preg_match('/\bday\s*0?1\b/iu', $text);
		$has_dur  = (bool) preg_match('/\b\d{1,2}\s*d\s*\d{1,2}\s*n\b/i', $text)
			|| (bool) preg_match('/\b\d{1,2}\s*(?:days?|nights?)\b/i', $text);
		return $has_day1 && $has_dur;
	}
}

if ( ! function_exists('competitor_is_tour_page'))
{
	/**
	 * The crawl KEEP test: is this a bookable tour product page? True when it has a
	 * real day-by-day itinerary (competitor_has_tour_itinerary), OR — for sites that
	 * hide the itinerary behind a tab/accordion the scrape can't open (e.g.
	 * chanbrothers) — when the page carries the unmistakable signals of a bookable
	 * tour: a trip DURATION ("7 Days" / 5D4N) together with a PRICE. A travel guide or
	 * listicle rarely has both a duration and a real price, and the guide-URL filter
	 * backstops the rest. Pure.
	 */
	function competitor_is_tour_page($text)
	{
		if ( ! is_string($text) || $text === '') {
			return false;
		}
		if (competitor_has_tour_itinerary($text)) {
			return true;
		}
		$has_duration = (bool) preg_match('/\b\d{1,2}\s*d\s*\d{1,2}\s*n\b/i', $text)
			|| (bool) preg_match('/\b\d{1,2}\s*(?:days?|nights?)\b/i', $text);
		$has_price = (bool) preg_match('/(?:rm|myr|sgd|usd|php|thb|idr|aud|eur|s\$|\$|£|€)\s*[0-9][0-9,]{2,}/iu', $text);
		return $has_duration && $has_price;
	}
}

if ( ! function_exists('competitor_needs_more_content'))
{
	/**
	 * True when a scrape does NOT yet hold a full, analysable tour page — either it's
	 * thin (competitor_scrape_is_thin) OR it lacks the basic tour sections a real
	 * product page has (competitor_has_basic_tour_sections: day-by-day itinerary +
	 * inclusions). This drives the reading escalation cascade (SPA JSON API ->
	 * embedded JSON -> json-alternate feed -> headless render): the crawler keeps
	 * trying cheaper-to-costlier readers until it has a full tour page or exhausts
	 * them — so a JS/boilerplate shell whose itinerary loads via a tab/XHR gets
	 * rendered instead of scraped shallow and dropped by the crawl's sections gate.
	 * Costlier readers stay bounded by the per-crawl render budget. Pure.
	 */
	function competitor_needs_more_content($text, $min_chars = 500)
	{
		if (competitor_scrape_is_thin($text, $min_chars)) {
			return true;
		}
		return ! competitor_has_tour_itinerary($text);
	}
}

if ( ! function_exists('competitor_json_alternate_url'))
{
	/**
	 * A page can advertise a machine-readable JSON version of itself via
	 * <link rel="alternate" type="application/json" href="…"> (or type ending
	 * +json, e.g. application/ld+json is skipped — that's inline). This returns the
	 * resolved absolute URL of the first such alternate, so a JS-rendered page can
	 * be read from its own JSON feed before falling back to the browsing agent.
	 * Returns '' when none. Pure — the fetch lives in the service.
	 */
	function competitor_json_alternate_url($html, $base_url)
	{
		if ( ! is_string($html) || $html === '') {
			return '';
		}
		if ( ! preg_match_all('#<link\b[^>]*>#i', $html, $m)) {
			return '';
		}
		foreach ($m[0] as $tag) {
			if ( ! preg_match('#\brel\s*=\s*(["\'])\s*alternate\s*\1#i', $tag)) {
				continue;
			}
			if ( ! preg_match('#\btype\s*=\s*(["\'])\s*application/(?:[a-z0-9.+-]*\+)?json\s*\1#i', $tag)) {
				continue;
			}
			if (preg_match('#\bhref\s*=\s*(["\'])(.*?)\1#i', $tag, $h)) {
				$abs = competitor_resolve_url($base_url, $h[2]);
				if ($abs !== '') {
					return $abs;
				}
			}
		}
		return '';
	}
}

if ( ! function_exists('competitor_extract_embedded_json'))
{
	/**
	 * Read the JSON data a JavaScript SPA ships INSIDE its served HTML even when
	 * the visible <body> is an empty shell — the AI-free, browser-free way to read
	 * a JS page. Three sources, richest first:
	 *   1. JSON-LD    <script type="application/ld+json"> (schema.org Product/Trip…)
	 *   2. __NEXT_DATA__ <script id="__NEXT_DATA__" type="application/json"> (Next.js)
	 *   3. OpenGraph / meta tags (og:title, og:description, product:price:amount)
	 * Each JSON blob is flattened via competitor_flatten_json_text(); lines are
	 * deduped (blobs overlap). Returns '' when nothing usable is embedded, so the
	 * caller falls through to the SPA API / web_search. Pure — the fetch lives in
	 * the service. (Extend with __NUXT__/__APOLLO_STATE__ as those platforms appear.)
	 */
	function competitor_extract_embedded_json($html)
	{
		if ( ! is_string($html) || $html === '') {
			return '';
		}
		$parts = array();

		// 1) JSON-LD blocks (may be several per page).
		if (preg_match_all('#<script[^>]*type\s*=\s*(["\'])application/ld\+json\1[^>]*>(.*?)</script>#is', $html, $m)) {
			foreach ($m[2] as $blob) {
				$t = competitor_flatten_json_text(json_decode(trim($blob), true));
				if ($t !== '') {
					$parts[] = $t;
				}
			}
		}

		// 2) Next.js hydration blob.
		if (preg_match('#<script[^>]*id\s*=\s*(["\'])__NEXT_DATA__\1[^>]*>(.*?)</script>#is', $html, $mm)) {
			$t = competitor_flatten_json_text(json_decode(trim($mm[2]), true));
			if ($t !== '') {
				$parts[] = $t;
			}
		}

		// 3) OpenGraph / meta tags.
		$meta = competitor_extract_meta_text($html);
		if ($meta !== '') {
			$parts[] = $meta;
		}

		// Dedupe lines across blobs, drop blanks.
		$seen = array();
		$out  = array();
		foreach ($parts as $chunk) {
			foreach (preg_split('/\r\n|\r|\n/', $chunk) as $ln) {
				$ln  = trim($ln);
				$key = mb_strtolower($ln, 'UTF-8');
				if ($ln === '' || isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$out[] = $ln;
			}
		}
		return implode("\n", $out);
	}
}

if ( ! function_exists('competitor_resolve_url'))
{
	/**
	 * Resolve an anchor href against the page it was found on into an absolute
	 * URL. Handles absolute, protocol-relative (//host), root-relative (/path)
	 * and directory-relative hrefs; drops the #fragment. Returns '' for empty or
	 * unsupported schemes (mailto:, tel:, javascript:, data:). Pure.
	 */
	function competitor_resolve_url($base, $href)
	{
		$href = trim((string) $href);
		$href = preg_replace('/#.*$/', '', $href);
		if ($href === '') {
			return '';
		}
		if (preg_match('#^(mailto:|tel:|javascript:|data:)#i', $href)) {
			return '';
		}
		if (preg_match('#^https?://#i', $href)) {
			return $href;
		}
		$parts = parse_url((string) $base);
		if (empty($parts['scheme']) || empty($parts['host'])) {
			return '';
		}
		$origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
		if (strpos($href, '//') === 0) {
			return $parts['scheme'] . ':' . $href;
		}
		if (strpos($href, '/') === 0) {
			return $origin . $href;
		}
		$path = isset($parts['path']) ? $parts['path'] : '/';
		$dir  = preg_replace('#/[^/]*$#', '/', $path);
		if ($dir === '') {
			$dir = '/';
		}
		$href = preg_replace('#^\./#', '', $href);
		return $origin . $dir . $href;
	}
}

if ( ! function_exists('competitor_extract_links'))
{
	/**
	 * Pull the distinct, same-host, absolute page links out of a page's HTML —
	 * the crawler's link discovery. Skips off-site links and asset files
	 * (images, css/js, pdf, archives, media, fonts). Order-preserving, deduped.
	 * Pure — the fetch lives in the service.
	 */
	function competitor_extract_links($html, $base_url)
	{
		if ( ! is_string($html) || $html === '') {
			return array();
		}
		$base_host = parse_url((string) $base_url, PHP_URL_HOST);
		if ( ! $base_host) {
			return array();
		}
		$asset = '#\.(jpe?g|png|gif|webp|svg|css|js|ico|pdf|zip|rar|gz|mp4|mp3|avi|mov|webm|woff2?|ttf|eot|xml|json|rss)(\?|$)#i';
		$out = array();
		if (preg_match_all('#<a\b[^>]*\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')#i', $html, $m, PREG_SET_ORDER)) {
			foreach ($m as $mm) {
				$href = ($mm[1] !== '') ? $mm[1] : (isset($mm[2]) ? $mm[2] : '');
				// Decode HTML entities in the href (e.g. &amp; → &) so query-string
				// product URLs (tour.php?a=1&amp;b=2) resolve to a valid, fetchable URL.
				$href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
				$abs  = competitor_resolve_url($base_url, $href);
				if ($abs === '' || parse_url($abs, PHP_URL_HOST) !== $base_host) {
					continue;
				}
				if (preg_match($asset, $abs)) {
					continue;
				}
				$out[$abs] = true;
			}
		}
		return array_keys($out);
	}
}

if ( ! function_exists('competitor_is_product_url'))
{
	/**
	 * Heuristic: does this URL look like a single tour/product page (not the
	 * homepage, a bare listing, or an about/blog/contact page)? True when the
	 * path carries a product keyword (tour/package/holiday/trip/…), has at least
	 * two path segments (so a bare "/tours" listing is skipped), the last segment
	 * looks like a slug, and no excluded keyword appears. Rules are fixed — the
	 * chosen trade-off is simplicity over per-site accuracy. Pure.
	 */
	function competitor_is_product_url($url)
	{
		$path = parse_url((string) $url, PHP_URL_PATH);
		if ( ! is_string($path) || $path === '') {
			return false;
		}
		$path = strtolower($path);
		$excludes = array('about', 'contact', 'blog', 'news', 'article', 'faq', 'term',
			'privacy', 'policy', 'login', 'signin', 'register', 'signup', 'cart',
			'checkout', 'account', 'career', 'job', 'sitemap', 'wishlist',
			// category / listing hubs (not a single product)
			'travelstyle', 'destination', 'inspiration', 'promotion', 'category', 'catalog');
		foreach ($excludes as $ex) {
			if (strpos($path, $ex) !== false) {
				return false;
			}
		}
		$segments = array_values(array_filter(explode('/', $path), 'strlen'));
		if (empty($segments)) {
			return false;   // homepage
		}
		// WordPress (and common-CMS) taxonomy/archive roots are LISTINGS, not a single
		// product — skip them even when the slug carries a product keyword, e.g.
		// /theme/bagan-datuk-tour/, /tag/china-tour-packages/, /author/x/, /2024/05/….
		// Matched as an exact FIRST segment (substring would nuke 'vintage', 'heritage').
		$taxonomy_roots = array('tag', 'tags', 'category', 'categories', 'author', 'authors',
			'theme', 'themes', 'topic', 'topics', 'type', 'label', 'archive', 'archives');
		if (in_array($segments[0], $taxonomy_roots, true) || preg_match('/^\d{4}$/', $segments[0])) {
			return false;
		}
		// A FLAT permalink (WordPress) puts a product at the root as a specific,
		// multi-word slug (e.g. /3d2n-genting-tour-itinerary/). Require a hyphen for a
		// single-segment path so a bare section hub (/tours, /packages) is NOT taken
		// for a product — a multi-segment path already implies a real sub-page.
		if (count($segments) === 1 && strpos($segments[0], '-') === false) {
			return false;
		}
		$keywords = array('tour', 'package', 'holiday', 'trip', 'itinerary', 'itineraries',
			'vacation', 'getaway', 'cruise', 'product');
		$has_kw = false;
		foreach ($segments as $seg) {
			// A duration code (5d4n / 3d2n) is a strong product signal on its own — so
			// a tour URL that omits the word "tour"/"package" still qualifies.
			if (preg_match('/\d{1,2}d\d{1,2}n/i', $seg)) {
				$has_kw = true;
				break;
			}
			foreach ($keywords as $kw) {
				if (strpos($seg, $kw) !== false) {
					$has_kw = true;
					break 2;
				}
			}
		}
		if ( ! $has_kw) {
			return false;
		}
		$last = end($segments);
		return preg_match('/[a-z]/', $last) === 1;
	}
}

if ( ! function_exists('competitor_is_candidate_url'))
{
	/**
	 * PERMISSIVE net for the whole-site sweep: is this a same-host CONTENT page worth
	 * reading (so the itinerary gate can judge it), as opposed to the homepage, an
	 * asset, or obvious account/legal/blog chrome? Unlike competitor_is_product_url
	 * this does NOT require a product keyword — so oddly-named tours (/detail/12345,
	 * /en/12345-osaka) are still fetched and let the gate decide. $base_host, when
	 * given, restricts to that host (www-insensitive). Pure — no network.
	 */
	function competitor_is_candidate_url($url, $base_host = '')
	{
		$host = parse_url((string) $url, PHP_URL_HOST);
		if ( ! $host) {
			return false;
		}
		$norm = function ($h) { return preg_replace('/^www\./i', '', strtolower((string) $h)); };
		if ($base_host !== '' && $norm($host) !== $norm($base_host)) {
			return false;   // same host only
		}
		$path = (string) parse_url((string) $url, PHP_URL_PATH);
		if ($path === '' || $path === '/') {
			return false;   // the homepage is a hub, not a product candidate
		}
		if (preg_match('#\.(jpe?g|png|gif|webp|svg|css|js|ico|zip|rar|gz|mp4|mp3|avi|mov|webm|woff2?|ttf|eot|rss|xml|json)(\?|$)#i', $path)) {
			return false;   // asset, not a page
		}
		$p = strtolower($path);
		// Obvious non-tour chrome — skip so the sweep doesn't burn fetches on them
		// (the gate would drop them anyway). Kept deliberately short so real tour
		// sections aren't excluded by accident.
		static $chrome = array('/about', '/contact', '/blog', '/news', '/faq', '/privacy',
			'/policy', '/policies', '/login', '/signin', '/sign-in', '/register', '/signup',
			'/sign-up', '/cart', '/checkout', '/account', '/career', '/careers', '/job',
			'/jobs', '/sitemap', '/wishlist', '/terms', '/search', '/tag/', '/tags/',
			'/author/', '/feed', '/wp-admin', '/wp-login', '/wp-json',
			// customer-support chrome (help centre / customer service) — not tours
			'/support', '/help', '/customer');
		foreach ($chrome as $c) {
			if (strpos($p, $c) !== false) {
				return false;
			}
		}
		return true;
	}
}

if ( ! function_exists('competitor_path_has_product_keyword'))
{
	/**
	 * True when a URL's PATH carries a tour/product keyword at ANY depth (tour,
	 * package, holiday, trip, itinerary, vacation, getaway, cruise, product, or a
	 * 5D4N duration code). Looser than competitor_is_product_url — it doesn't care
	 * about the last segment being a slug — so it also matches a section hub
	 * (/tour-package) and a numeric-id product (/tour-package/1077). Used to decide
	 * which pages are worth a (budget-limited) browser render on JS-SPA sites. Pure.
	 */
	function competitor_path_has_product_keyword($url)
	{
		$path = strtolower((string) parse_url((string) $url, PHP_URL_PATH));
		if ($path === '') {
			return false;
		}
		if (preg_match('/\d{1,2}d\d{1,2}n/i', $path)) {
			return true;
		}
		static $kw = array('tour', 'package', 'holiday', 'trip', 'itinerar',
			'vacation', 'getaway', 'cruise', 'product');
		foreach ($kw as $k) {
			if (strpos($path, $k) !== false) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists('competitor_count_child_links'))
{
	/**
	 * Count how many of $links are DIRECT children of $url's path — i.e. their path
	 * is "<url-path>/<one-more-segment>". A page that links to several of its own
	 * sub-pages (e.g. /tour-package → /tour-package/1077, /tour-package/1090, …) is an
	 * index/listing of them, so the crawler should drill those children rather than
	 * keep the hub. Pure — no network.
	 */
	function competitor_count_child_links($url, $links)
	{
		$base = rtrim((string) parse_url((string) $url, PHP_URL_PATH), '/');
		if ($base === '') {
			return 0;
		}
		$seen = array();
		foreach ((array) $links as $l) {
			$p = rtrim((string) parse_url((string) $l, PHP_URL_PATH), '/');
			if ($p === $base || strpos($p, $base . '/') !== 0) {
				continue;
			}
			$rest = substr($p, strlen($base) + 1);
			if ($rest !== '' && strpos($rest, '/') === false) {
				$seen[$rest] = true;   // exactly one segment deeper
			}
		}
		return count($seen);
	}
}

if ( ! function_exists('competitor_paginator_items'))
{
	/**
	 * Return the record list from a decoded Laravel-style paginated API response
	 * ({data:[…], links:{next}, meta:{last_page}}), or [] when the JSON isn't such a
	 * paginator. Requires a pagination signal (meta.last_page / meta.current_page /
	 * links.next|last) so a plain {data:[…]} blob isn't mistaken for a listing. Pure.
	 */
	function competitor_paginator_items($json)
	{
		if ( ! is_array($json) || ! isset($json['data']) || ! is_array($json['data']) || empty($json['data'])) {
			return array();
		}
		$paged = isset($json['meta']['last_page']) || isset($json['meta']['current_page'])
			|| isset($json['links']['next']) || isset($json['links']['last']);
		if ( ! $paged) {
			return array();
		}
		$first = reset($json['data']);
		return is_array($first) ? $json['data'] : array();   // items must be records
	}
}

if ( ! function_exists('competitor_paginator_next'))
{
	/** The next-page URL of a Laravel-style paginator (links.next), or '' at the end. Pure. */
	function competitor_paginator_next($json)
	{
		if (is_array($json) && isset($json['links']['next']) && is_string($json['links']['next'])) {
			return $json['links']['next'];
		}
		return '';
	}
}

if ( ! function_exists('competitor_listing_item_url'))
{
	/**
	 * Build the product-page URL for one paginated-listing record: an explicit
	 * url/slug/link/permalink field (resolved against the listing) when present, else
	 * the listing path plus the record's numeric id (e.g. /tour-package + 1077 →
	 * /tour-package/1077). Returns '' when neither is available. Pure.
	 */
	function competitor_listing_item_url($listing_url, $item)
	{
		$item = (array) $item;
		foreach (array('url', 'slug', 'link', 'permalink') as $k) {
			if ( ! empty($item[$k]) && is_string($item[$k])) {
				return competitor_resolve_url($listing_url, $item[$k]);
			}
		}
		if (isset($item['id']) && (is_int($item['id']) || ctype_digit((string) $item['id']))) {
			return rtrim((string) $listing_url, '/') . '/' . $item['id'];
		}
		return '';
	}
}

if ( ! function_exists('competitor_is_guide_url'))
{
	/**
	 * True when a page is a travel GUIDE / planning ARTICLE rather than a bookable
	 * tour — "How to plan a trip to Beijing", "Best time to visit…", "Things to do in
	 * …", "…Public Holidays Calendar". These slip past the itinerary gate because a
	 * day-by-day *plan* reads like a day-by-day *itinerary*, so we exclude them by the
	 * tell-tale URL slug (and, as a backstop, a title that opens like a guide). Real
	 * tour slugs (…-group-tour, …-5d4n, /china-tours/…) don't carry these. Pure.
	 */
	function competitor_is_guide_url($url, $title = '')
	{
		$path = strtolower((string) parse_url((string) $url, PHP_URL_PATH));
		static $slug = array(
			'how-to', 'how_to', 'plan-a-trip', 'plan-your-trip', 'trip-planner',
			'travel-planner', 'things-to-do', 'what-to-', 'where-to-', 'when-to-',
			'best-time', 'best-months', 'best-places', 'travel-guide', 'travel-tips',
			'travel-advice', 'public-holiday', '-calendar', 'weather', 'travel-faq',
		);
		foreach ($slug as $s) {
			if (strpos($path, $s) !== false) {
				return true;
			}
		}
		$t = strtolower(trim((string) $title));
		if ($t !== '' && preg_match(
			'/^(?:how to\b|how do\b|best time\b|things to do\b|what to\b|where to\b|when to\b|top \d|ultimate guide\b|travel guide\b|a guide to\b|guide to\b)/',
			$t
		)) {
			return true;
		}
		return false;
	}
}

if ( ! function_exists('competitor_is_category_url'))
{
	/**
	 * True when a URL is a CATEGORY / listing page (a page OF products) rather than a
	 * single product — a plural "…-tours/-packages/-holidays" slug, a known listing
	 * path (listing.php, /category/, /destination/, /travelstyle/, /collections/), or a
	 * SEARCH / filter-result page (a "…-search" path, or a filter query such as
	 * ?region=/?country=/?destination= that just re-lists the same catalogue). The
	 * crawler drills these to their individual products instead of analysing the
	 * catalogue as one item — and, being near-identical, they'd otherwise flood the
	 * crawl with dozens of redundant copies of the same search page. Pure.
	 */
	function competitor_is_category_url($url)
	{
		$path = strtolower((string) parse_url((string) $url, PHP_URL_PATH));
		if ($path === '') {
			return false;
		}
		if (preg_match('#(?:listing|/category/|/categories/|catalog|/tag/|/destination|/travelstyle|/collections?/)#', $path)) {
			return true;
		}
		// A search page (/search, /tour-search) is a results/listing page, not a product.
		if (preg_match('#(?:^|/)[a-z0-9]*[-_]?search[a-z0-9]*/?$#', $path)) {
			return true;
		}
		// A filter query (?region=/?country=/?destination=/…) re-lists the catalogue —
		// each value is another copy of the same listing, so treat it as one.
		$query = (string) parse_url((string) $url, PHP_URL_QUERY);
		if ($query !== '') {
			parse_str($query, $qa);
			foreach (array('region', 'country', 'destination', 'category', 'filter', 'keyword', 'tag') as $fk) {
				if (isset($qa[$fk]) && $qa[$fk] !== '') {
					return true;
				}
			}
		}
		$segs = array_values(array_filter(explode('/', $path), 'strlen'));
		if (empty($segs)) {
			return false;
		}
		return (bool) preg_match('/-(?:tours|packages|holidays|cruises|deals|vacations|getaways)$/', end($segs));
	}
}

if ( ! function_exists('competitor_filter_urls_by_keyword'))
{
	/**
	 * Keep only product URLs whose PATH (slug) contains the keyword — so a crawl can
	 * target e.g. "yunnan" or "japan" instead of the whole catalogue. Case-insensitive;
	 * multiple space/comma-separated words match ANY (OR). Matches the path only so the
	 * domain (e.g. "raha" in rahaholidays.com) never matches every URL. Empty keyword =
	 * no filtering (return all). Pure.
	 */
	function competitor_filter_urls_by_keyword($urls, $keyword)
	{
		$keyword = trim((string) $keyword);
		if ($keyword === '' || ! is_array($urls)) {
			return $urls;
		}
		$tokens = array_values(array_filter(
			preg_split('/[\s,]+/', mb_strtolower($keyword, 'UTF-8')),
			'strlen'
		));
		if (empty($tokens)) {
			return $urls;
		}
		$out = array();
		foreach ($urls as $u) {
			$path = mb_strtolower((string) parse_url((string) $u, PHP_URL_PATH), 'UTF-8');
			if ($path === '') {
				continue;
			}
			foreach ($tokens as $t) {
				if (mb_strpos($path, $t) !== false) {
					$out[] = $u;
					break;
				}
			}
		}
		return $out;
	}
}

if ( ! function_exists('competitor_matches_keyword'))
{
	/**
	 * True when a crawled product matches the keyword — in its URL slug OR its
	 * (chrome-stripped) content text. Case-insensitive; multiple space/comma words
	 * match ANY (OR). Empty keyword = always true. Pure.
	 *
	 * IMPORTANT: pass the CLEANED content text (menu/footer already stripped) — raw
	 * HTML would match a site mega-menu that lists every destination on every page.
	 */
	function competitor_matches_keyword($url, $text, $keyword)
	{
		$keyword = trim((string) $keyword);
		if ($keyword === '') {
			return true;
		}
		$tokens = array_values(array_filter(
			preg_split('/[\s,]+/', mb_strtolower($keyword, 'UTF-8')),
			'strlen'
		));
		if (empty($tokens)) {
			return true;
		}
		$path = mb_strtolower((string) parse_url((string) $url, PHP_URL_PATH), 'UTF-8');
		$body = mb_strtolower((string) $text, 'UTF-8');
		foreach ($tokens as $t) {
			if (($path !== '' && mb_strpos($path, $t) !== false)
				|| ($body !== '' && mb_strpos($body, $t) !== false)) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists('competitor_file_binary_kind'))
{
	/**
	 * Classify a fetched file's bytes by its magic number so the service reads it
	 * with the right tool: 'pdf' (%PDF-), 'image' (JPEG/PNG/GIF/WEBP), or '' for
	 * anything else. Content-type headers on these CDN links are unreliable/absent,
	 * so we sniff the bytes. Pure.
	 */
	function competitor_file_binary_kind($bytes)
	{
		if ( ! is_string($bytes) || strlen($bytes) < 4) {
			return '';
		}
		if (substr($bytes, 0, 5) === '%PDF-') {
			return 'pdf';
		}
		if (substr($bytes, 0, 3) === "\xFF\xD8\xFF") {                     // JPEG
			return 'image';
		}
		if (substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") {                // PNG
			return 'image';
		}
		if (substr($bytes, 0, 6) === 'GIF87a' || substr($bytes, 0, 6) === 'GIF89a') {
			return 'image';
		}
		if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
			return 'image';
		}
		return '';
	}
}

if ( ! function_exists('competitor_is_junk_file_url'))
{
	/**
	 * True when a file URL is boilerplate legal/policy/marketing junk (privacy,
	 * terms, PDPA, code-of-conduct, complaint policy, cookie, disclaimer, brochure
	 * catalogues, EDMs…) rather than a product brochure/itinerary — so the crawler
	 * doesn't fetch + OCR footer PDFs that dilute the product text. Matched on the
	 * URL path/filename. Pure.
	 */
	function competitor_is_junk_file_url($url)
	{
		$path = strtolower((string) parse_url((string) $url, PHP_URL_PATH));
		if ($path === '') {
			return false;
		}
		return (bool) preg_match(
			'#(policy|policies|privacy|pdpa|gdpr|abac|conduct|complaint|cookie|disclaimer'
			. '|terms|tnc|t-and-c|t_c|[-_]tc(?=[-_.])|gst|sst|corporate-governance|whistle|anti-bribery|edm)#',
			$path
		);
	}
}

if ( ! function_exists('competitor_extract_file_links'))
{
	/**
	 * Pull brochure/DOCUMENT links out of raw page HTML — the <a href> targets
	 * whose path ends in a document extension (pdf/doc/xls/ppt). html_to_text strips
	 * hrefs, so on a plain HTML competitor page the PDF brochure links would be
	 * lost; this recovers them so the service can fetch + read them. Images are
	 * deliberately EXCLUDED: a linked .jpg/.png on a generic page is almost always a
	 * photo/gallery/thumbnail, not a brochure (real image brochures arrive via the
	 * ICE/JSON "Files:" path instead). Skips legal/policy junk
	 * (competitor_is_junk_file_url). Absolute, deduped, order-preserving, any host.
	 * Pure.
	 */
	function competitor_extract_file_links($html, $base_url)
	{
		if ( ! is_string($html) || $html === '') {
			return array();
		}
		$exts = 'pdf|docx?|xlsx?|pptx?';
		$out  = array();
		if (preg_match_all('#<a\b[^>]*\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')#i', $html, $m, PREG_SET_ORDER)) {
			foreach ($m as $mm) {
				$href = ($mm[1] !== '') ? $mm[1] : (isset($mm[2]) ? $mm[2] : '');
				// Decode HTML entities in the href (e.g. &amp; → &) so query-string
				// product URLs (tour.php?a=1&amp;b=2) resolve to a valid, fetchable URL.
				$href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
				$abs  = competitor_resolve_url($base_url, $href);
				if ($abs === '' || isset($out[$abs])) {
					continue;
				}
				$path = parse_url($abs, PHP_URL_PATH);
				if (is_string($path) && preg_match('#\.(' . $exts . ')$#i', $path)
					&& ! competitor_is_junk_file_url($abs)) {
					$out[$abs] = true;
				}
			}
		}
		return array_keys($out);
	}
}

if ( ! function_exists('competitor_extract_urls'))
{
	/**
	 * Pull distinct http(s) URLs out of a block of source text — used to find the
	 * linked brochure/itinerary files (e.g. the "Files: …" line an ICE post/series
	 * carries) so the service can fetch + read them for richer AI input. Strips
	 * trailing punctuation, dedupes, order-preserving, capped at $limit (0 = all).
	 * Pure — the fetch lives in the service.
	 */
	function competitor_extract_urls($text, $limit = 10)
	{
		if ( ! is_string($text) || $text === '') {
			return array();
		}
		$out = array();
		if (preg_match_all('#https?://[^\s"\'<>)\]]+#i', $text, $m)) {
			foreach ($m[0] as $u) {
				$u = rtrim($u, '.,);]');
				if ($u === '' || isset($out[$u])) {
					continue;
				}
				$out[$u] = true;
				if ($limit > 0 && count($out) >= $limit) {
					break;
				}
			}
		}
		return array_keys($out);
	}
}

if ( ! function_exists('competitor_is_site_root'))
{
	/**
	 * True when the URL is a site ROOT/homepage (empty or "/" path) — the case where
	 * we should enumerate the WHOLE site via its sitemap rather than crawl one page.
	 * A deeper path (a listing or product) is scoped by the user and left alone. Pure.
	 */
	function competitor_is_site_root($url)
	{
		$path = parse_url((string) $url, PHP_URL_PATH);
		return (is_string($path) ? trim($path, '/') : '') === '';
	}
}

if ( ! function_exists('competitor_robots_sitemaps'))
{
	/**
	 * Extract the `Sitemap:` URLs advertised in a robots.txt. Order-preserving,
	 * deduped. Returns [] when none. Pure.
	 */
	function competitor_robots_sitemaps($txt)
	{
		if ( ! is_string($txt) || $txt === '') {
			return array();
		}
		$out = array();
		if (preg_match_all('#^\s*sitemap\s*:\s*(\S+)#im', $txt, $m)) {
			foreach ($m[1] as $u) {
				$u = trim($u);
				if ($u !== '' && ! in_array($u, $out, true)) {
					$out[] = $u;
				}
			}
		}
		return $out;
	}
}

if ( ! function_exists('competitor_parse_sitemap'))
{
	/**
	 * Parse a sitemap XML into ['is_index' => bool, 'urls' => [<loc>…]]. A
	 * <sitemapindex> lists child sitemaps (is_index true); a <urlset> lists page
	 * URLs. Locs are decoded + deduped. Returns empty urls for junk. Pure.
	 */
	function competitor_parse_sitemap($xml)
	{
		if ( ! is_string($xml) || $xml === '') {
			return array('is_index' => false, 'urls' => array());
		}
		$is_index = stripos($xml, '<sitemapindex') !== false;
		$urls = array();
		if (preg_match_all('#<loc>\s*(.*?)\s*</loc>#is', $xml, $m)) {
			foreach ($m[1] as $u) {
				$u = trim(html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
				if ($u !== '' && ! in_array($u, $urls, true)) {
					$urls[] = $u;
				}
			}
		}
		return array('is_index' => $is_index, 'urls' => $urls);
	}
}

if ( ! function_exists('competitor_filter_product_urls'))
{
	/**
	 * Keep only the likely product URLs from a discovered link list, deduped and
	 * capped at $limit (the per-crawl cost cap — each product is one paid AI
	 * call). Preserves discovery order. Pure.
	 */
	function competitor_filter_product_urls($urls, $limit = 10)
	{
		$out = array();
		foreach ((array) $urls as $u) {
			$u = trim((string) $u);
			if ($u === '' || isset($out[$u])) {
				continue;
			}
			if (competitor_is_product_url($u)) {
				$out[$u] = true;
				if ($limit > 0 && count($out) >= $limit) {
					break;
				}
			}
		}
		return array_keys($out);
	}
}

if ( ! function_exists('competitor_url_label'))
{
	/**
	 * A readable label for a discovered product URL, shown in the crawl-results
	 * pick list before analysis (we haven't fetched the page's real title). Takes
	 * the last path segment, drops any .html/.php extension, turns -/_ into
	 * spaces and Title-Cases it; falls back to the host for a bare/root URL. Pure.
	 */
	function competitor_url_label($url)
	{
		$path = parse_url((string) $url, PHP_URL_PATH);
		if ( ! is_string($path) || trim($path, '/') === '') {
			$host = parse_url((string) $url, PHP_URL_HOST);
			return $host ? preg_replace('/^www\./i', '', $host) : (string) $url;
		}
		// Take the last path segment, then URL-decode it (many links percent-encode
		// spaces/slashes into a single messy segment). Drop a page/file extension,
		// flatten separators, Title-Case, and cap the length so the pick list stays
		// tidy — some sites bury the whole tour name in one long segment.
		$segments = array_values(array_filter(explode('/', $path), 'strlen'));
		$last = rawurldecode(end($segments));
		$last = preg_replace('/\.(html?|php|aspx?|jsp|pdf)$/i', '', $last);
		$last = trim(preg_replace('/\s+/', ' ', str_replace(array('-', '_', '/'), ' ', $last)));
		if ($last === '') {
			return (string) $url;
		}
		if (mb_strlen($last, 'UTF-8') > 80) {
			$last = rtrim(mb_substr($last, 0, 80, 'UTF-8')) . '…';
		}
		return mb_convert_case($last, MB_CASE_TITLE, 'UTF-8');
	}
}

if ( ! function_exists('competitor_crawl_row_summary'))
{
	/**
	 * Build the listing-row summary for a combined site crawl: a "host — N
	 * products" label, the distinct destinations (first 3 + "+N more"), and the
	 * product count. Pure — feeds Competitor_Analysis_Model::Create().
	 */
	function competitor_crawl_row_summary($base_url, $products)
	{
		$products = is_array($products) ? $products : array();
		$count = count($products);
		$host  = parse_url((string) $base_url, PHP_URL_HOST);
		$host  = $host ? preg_replace('/^www\./i', '', $host) : trim((string) $base_url);

		$dests = array();
		foreach ($products as $p) {
			$p = (array) $p;
			$d = isset($p['destination']) ? trim((string) $p['destination']) : '';
			if ($d !== '' && ! in_array($d, $dests, true)) {
				$dests[] = $d;
			}
		}
		$dest_label = '';
		if ($dests) {
			$dest_label = implode(', ', array_slice($dests, 0, 3));
			if (count($dests) > 3) {
				$dest_label .= ', +' . (count($dests) - 3) . ' more';
			}
		}
		return array(
			'product_name'  => $host . ' — ' . $count . ' product' . ($count === 1 ? '' : 's'),
			'destination'   => $dest_label,
			'product_count' => $count,
		);
	}
}

if ( ! function_exists('competitor_websearch_cap'))
{
	/**
	 * Resolve the per-crawl web_search (browsing) budget from the raw
	 * COMPETITOR_MAX_WEBSEARCH .env value. Each blank-shell SPA product costs one
	 * browsing call (a real per-call fee), so this caps a crawl's surprise bill.
	 * Unset/blank -> $default; an explicit 0 -> 0 = UNLIMITED (opt-in); a negative
	 * -> $default; otherwise the integer. Pure.
	 */
	function competitor_websearch_cap($raw, $default = 15)
	{
		if ($raw === false || $raw === null || trim((string) $raw) === '') {
			return (int) $default;
		}
		$n = (int) $raw;
		return $n < 0 ? (int) $default : $n;
	}
}

if ( ! function_exists('competitor_headless_cap'))
{
	/**
	 * Per-crawl cap on headless-browser renders (each ~2-4s) from the raw
	 * COMPETITOR_MAX_HEADLESS .env value — bounds how long a JS-heavy crawl can run.
	 * Unset/blank -> $default; explicit 0 -> 0 = UNLIMITED; negative -> $default;
	 * otherwise the integer. Pure.
	 */
	function competitor_headless_cap($raw, $default = 60)
	{
		if ($raw === false || $raw === null || trim((string) $raw) === '') {
			return (int) $default;
		}
		$n = (int) $raw;
		return $n < 0 ? (int) $default : $n;
	}
}

if ( ! function_exists('competitor_job_progress_message'))
{
	/**
	 * Turn a background crawl job's status array into a short human line for the
	 * progress popup. States: queued / running (phase discovering|reading with
	 * done/total) / done (with product count) / error (with message). Pure.
	 */
	function competitor_job_progress_message($status)
	{
		$s     = is_array($status) ? $status : array();
		$state = isset($s['state']) ? $s['state'] : '';
		if ($state === 'queued') {
			return 'Queued…';
		}
		if ($state === 'error') {
			return 'Error: ' . (isset($s['message']) && $s['message'] !== '' ? $s['message'] : 'crawl failed');
		}
		if ($state === 'done') {
			$c = isset($s['count']) ? (int) $s['count'] : 0;
			return $c > 0 ? ('Done — ' . $c) : 'Done.';
		}
		if ($state === 'unknown' || $state === '') {
			return 'Job not found.';
		}
		// running — the label reflects the actual phase: discovery has no known total
		// (enumerating the site), reading shows per-product progress N / total.
		$phase = isset($s['phase']) ? $s['phase'] : '';
		$done  = isset($s['done']) ? (int) $s['done'] : 0;
		$total = isset($s['total']) ? (int) $s['total'] : 0;
		if ($phase === 'reading' && $total > 0) {
			return 'Reading products… ' . $done . ' / ' . $total;
		}
		if ($phase === 'analysing') {
			return $total > 0 ? ('Analysing… ' . $done . ' / ' . $total) : 'Analysing…';
		}
		// A pasted-text or uploaded-file job has no discovery phase — it goes
		// straight to analysing.
		if (in_array(($mode = (isset($s['mode']) ? $s['mode'] : '')), array('paste', 'upload'), true)) {
			return 'Analysing…';
		}
		return 'Discovering…';
	}
}

if ( ! function_exists('competitor_strip_shared_chrome'))
{
	/**
	 * Remove site chrome (mega-menu / header / footer) that our tag-based scraper
	 * missed because it lives in plain <div>/<ul> — not <nav>/<footer>. Every product
	 * on a site repeats the SAME leading menu + trailing footer verbatim, so we detect
	 * the longest run of leading (and trailing) lines shared by a majority of the
	 * crawled items and strip it from each. Markup-agnostic; fixes titles reading
	 * "MENUMENU" and stops the identical menu bloating every product's AI input.
	 * Pure — takes/returns [['url'=>,'text'=>], …]. No-op with < 3 items.
	 */
	function competitor_strip_shared_chrome($items, $min_share = 0.6, $max_scan = 300)
	{
		$items = array_values(is_array($items) ? $items : array());
		$n = count($items);
		if ($n < 3) {
			return $items;   // too few samples to tell chrome from content
		}
		$lines = array();
		foreach ($items as $it) {
			$t = isset($it['text']) ? (string) $it['text'] : '';
			$lines[] = ($t === '') ? array() : explode("\n", $t);
		}
		$threshold = max(2, (int) ceil($n * $min_share));

		// Longest run of positions whose modal line is shared by >= threshold items.
		$shared_run = function ($rows) use ($threshold, $max_scan) {
			$run = array();
			for ($i = 0; $i < $max_scan; $i++) {
				$counts = array();
				$present = 0;
				foreach ($rows as $r) {
					if ( ! isset($r[$i])) { continue; }
					$present++;
					if ($r[$i] === '') { continue; }
					$counts[$r[$i]] = isset($counts[$r[$i]]) ? $counts[$r[$i]] + 1 : 1;
				}
				if ($present < $threshold || empty($counts)) { break; }
				arsort($counts);
				$top = array_keys($counts)[0];
				if ($counts[$top] >= $threshold) { $run[] = $top; } else { break; }
			}
			return $run;
		};

		$lead = $shared_run($lines);
		$tail = array_reverse($shared_run(array_map('array_reverse', $lines)));
		if (empty($lead) && empty($tail)) {
			return $items;
		}

		$clen = count($lead);
		$tlen = count($tail);
		foreach ($items as $k => $it) {
			$ln = $lines[$k];
			if ($clen) {
				$match = true;
				for ($i = 0; $i < $clen; $i++) {
					if ( ! isset($ln[$i]) || $ln[$i] !== $lead[$i]) { $match = false; break; }
				}
				if ($match) { $ln = array_slice($ln, $clen); }
			}
			if ($tlen && count($ln) >= $tlen) {
				$m = count($ln);
				$match = true;
				for ($i = 0; $i < $tlen; $i++) {
					if ($ln[$m - $tlen + $i] !== $tail[$i]) { $match = false; break; }
				}
				if ($match) { $ln = array_slice($ln, 0, $m - $tlen); }
			}
			$items[$k]['text'] = trim(implode("\n", $ln));
		}
		return $items;
	}
}

if ( ! function_exists('competitor_page_title'))
{
	/**
	 * The real product name from a page's HTML — far more reliable than the first
	 * body line (which is often menu/CTA/inquiry-form chrome). Prefers the first
	 * <h1>, then og:title, then <title> with a trailing " - Site" / " | Site" suffix
	 * trimmed. Decodes entities, collapses whitespace. Pure. '' when none found.
	 */
	function competitor_page_title($html)
	{
		if ( ! is_string($html) || $html === '') {
			return '';
		}
		$clean = function ($s) {
			$s = html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			return trim(preg_replace('/\s+/u', ' ', strip_tags($s)));
		};
		// 1) first <h1>
		if (preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $m)) {
			$t = $clean($m[1]);
			if (mb_strlen($t, 'UTF-8') >= 3) {
				return $t;
			}
		}
		// 2) og:title (either attribute order)
		if (preg_match('#<meta[^>]+property=["\']og:title["\'][^>]*content=["\']([^"\']+)#i', $html, $m)
			|| preg_match('#<meta[^>]+content=["\']([^"\']+)["\'][^>]*property=["\']og:title["\']#i', $html, $m)) {
			$t = $clean($m[1]);
			if ($t !== '') {
				return $t;
			}
		}
		// 3) <title>, drop the trailing site-name segment ("… - Raha Holidays")
		if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
			$t = $clean($m[1]);
			$t = preg_replace('/\s*[|\x{2013}\x{2014}\-]\s*[^|\x{2013}\x{2014}\-]{2,40}$/u', '', $t);
			if ($t !== '') {
				return $t;
			}
		}
		return '';
	}
}

if ( ! function_exists('competitor_item_meta'))
{
	/**
	 * Extra at-a-glance info for the review list: the trip DURATION (e.g. "9D7N" or
	 * "5 Days") taken from the title first — reliable — then the body, and a short
	 * SNIPPET of the real content (first meaningful line past the title/label).
	 * Deliberately NO price — these pages carry many noisy prices (deposits, related
	 * tours) so a single figure would mislead. Pure. Returns
	 * ['duration' => '', 'snippet' => ''].
	 */
	function competitor_item_meta($text, $title = '')
	{
		$duration = '';
		foreach (array((string) $title, (string) $text) as $src) {
			if ($src === '') { continue; }
			if (preg_match('/\b(\d{1,2})\s*d\s*(\d{1,2})\s*n\b/i', $src, $m)) {
				$duration = strtoupper($m[1] . 'D' . $m[2] . 'N');
				break;
			}
			if (preg_match('/\b(\d{1,2})\s*days?\b(?:\s*\/?\s*(\d{1,2})\s*nights?)?/i', $src, $m)) {
				$duration = $m[1] . ' Days' . (isset($m[2]) && $m[2] !== '' ? ' ' . $m[2] . ' Nights' : '');
				break;
			}
		}

		// Snippet: first content line that isn't the label/title itself.
		$snippet = '';
		$titleLc = mb_strtolower(trim((string) $title), 'UTF-8');
		foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $ln) {
			$ln = trim(preg_replace('/^\s*(product|title|name|caption)\s*:\s*/i', '', trim($ln)));
			if ($ln === '' || ! preg_match('/\p{L}/u', $ln) || mb_strlen($ln, 'UTF-8') < 8) {
				continue;
			}
			if (mb_strtolower($ln, 'UTF-8') === $titleLc) {
				continue;   // skip the title line
			}
			$snippet = mb_strlen($ln, 'UTF-8') > 140 ? rtrim(mb_substr($ln, 0, 140, 'UTF-8')) . '…' : $ln;
			break;
		}
		return array('duration' => $duration, 'snippet' => $snippet);
	}
}

if ( ! function_exists('competitor_item_kind'))
{
	/**
	 * Classify one crawled product for the Review list: 'package' when its content
	 * carries a price (a currency figure like "RM1,899" / "SGD 250" / "$1,200"),
	 * otherwise 'itinerary'. The rule is simply: content mentions a price → it is a
	 * priced PACKAGE; no price → a plain ITINERARY. Uses the same price pattern as
	 * competitor_has_product_signal() so both agree on what "a price" is. Pure.
	 */
	function competitor_item_kind($text)
	{
		if ( ! is_string($text) || $text === '') {
			return 'itinerary';
		}
		$has_price = (bool) preg_match('/(?:rm|myr|sgd|usd|eur|php|idr|thb|aud|\$|£|€)\s*[0-9][0-9,]{2,}/iu', $text);
		return $has_price ? 'package' : 'itinerary';
	}
}

if ( ! function_exists('competitor_item_title'))
{
	/**
	 * A short display title for one crawled product, for the review/select list.
	 * Takes the first meaningful line of the extracted text (stripping a leading
	 * "Product:"/"title:"/"name:" label + skipping bare nav tokens), capped; falls
	 * back to the URL label when the text has none. Pure.
	 */
	function competitor_item_title($text, $url = '')
	{
		// Bare navigation/boilerplate lines that are never a product name.
		$nav = array('menu', 'menumenu', 'home', 'search', 'login', 'log in', 'sign in',
			'register', 'cart', 'wishlist', 'contact', 'contact us', 'book now', 'read more');
		foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $ln) {
			$ln = trim(preg_replace('/^\s*(product|title|name|caption)\s*:\s*/i', '', trim($ln)));
			if ($ln === '' || ! preg_match('/\p{L}/u', $ln) || mb_strlen($ln, 'UTF-8') < 4) {
				continue;
			}
			if (in_array(mb_strtolower($ln, 'UTF-8'), $nav, true)) {
				continue;
			}
			return mb_strlen($ln, 'UTF-8') > 90 ? rtrim(mb_substr($ln, 0, 90, 'UTF-8')) . '…' : $ln;
		}
		return competitor_url_label($url);
	}
}

if ( ! function_exists('competitor_job_public_view'))
{
	/**
	 * Shape a raw job status array into the browser-safe view the jobs rows show:
	 * id, url, state, human message, product count, timestamp; `analysis_id` when an
	 * analyse job saved a report; `reviewable` when a finished CRAWL has products to
	 * pick from. Pure.
	 */
	function competitor_job_public_view($status)
	{
		$s     = is_array($status) ? $status : array();
		$state = isset($s['state']) ? (string) $s['state'] : 'unknown';
		$mode  = isset($s['mode']) ? (string) $s['mode'] : 'crawl';
		return array(
			'job'         => isset($s['job']) ? (string) $s['job'] : '',
			'url'         => isset($s['url']) ? (string) $s['url'] : '',
			'state'       => $state,
			'mode'        => $mode,
			'message'     => competitor_job_progress_message($s),
			'count'       => isset($s['count']) ? (int) $s['count'] : 0,
			'keyword'     => isset($s['keyword']) ? (string) $s['keyword'] : '',
			'ai_crawl'    => ! empty($s['ai_crawl']),
			'is_paste'    => ($mode === 'paste'),
			'is_upload'   => ($mode === 'upload'),
			// Fixed submission time (when pasted + Analyse clicked), not last update.
			'ts'          => isset($s['created']) ? (string) $s['created'] : (isset($s['ts']) ? (string) $s['ts'] : ''),
			// Live-ETA inputs (reading phase): progress + when reading began.
			'done'        => isset($s['done']) ? (int) $s['done'] : 0,
			'total'       => isset($s['total']) ? (int) $s['total'] : 0,
			'read_start'  => isset($s['read_start']) ? (string) $s['read_start'] : '',
			'cost_total'  => isset($s['cost_total']) ? (float) $s['cost_total'] : 0.0,
			'analysed'    => isset($s['analysed']) && is_array($s['analysed']) ? count($s['analysed']) : 0,
			'analysis_id' => isset($s['analysis_id']) ? (int) $s['analysis_id'] : 0,
			'reviewable'  => ($state === 'done' && $mode === 'crawl' && (int) (isset($s['count']) ? $s['count'] : 0) > 0),
		);
	}
}

if ( ! function_exists('competitor_job_host'))
{
	/**
	 * Normalised host of an http(s) URL — lowercased, with a leading "www." stripped
	 * — so every crawl of the same website groups under one key regardless of the
	 * path or a www prefix. Returns '' for empty / non-http(s) input (e.g. a pasted
	 * "Pasted text" label), which the caller keeps ungrouped. Pure.
	 */
	function competitor_job_host($url)
	{
		$url = trim((string) $url);
		if ($url === '' || ! preg_match('#^https?://#i', $url)) {
			return '';
		}
		$host = parse_url($url, PHP_URL_HOST);
		if ( ! is_string($host) || $host === '') {
			return '';
		}
		return preg_replace('/^www\./i', '', strtolower($host));
	}
}

if ( ! function_exists('competitor_group_crawl_jobs'))
{
	/**
	 * Collapse many crawl job-views (competitor_job_public_view, mode 'crawl') into
	 * ONE merged row per website host, so the Analysis Results listing shows a single
	 * line for a site crawled repeatedly and the per-run history lives behind a
	 * timeline page. Each merged row carries: is_group, host, the LATEST run's url /
	 * ts / product count / keyword / ai_crawl, the SUM of cost + analysed across all
	 * runs, runs_count, and whether ANY run is still queued/running (so the row keeps
	 * polling) or reviewable. When a run is in progress its state/message is surfaced
	 * on the merged row (the site is "working"); otherwise the latest finished run's
	 * state/message shows. Newest-crawled host first. A view with no host (shouldn't
	 * happen for crawls) is skipped. Pure — no DB, no files.
	 */
	function competitor_group_crawl_jobs($views)
	{
		$views = is_array($views) ? $views : array();
		$groups = array();   // host => list of runs
		foreach ($views as $v) {
			if ( ! is_array($v)) {
				continue;
			}
			$host = competitor_job_host(isset($v['url']) ? $v['url'] : '');
			if ($host === '') {
				continue;
			}
			$groups[$host][] = $v;
		}

		$rows = array();
		foreach ($groups as $host => $runs) {
			// Newest run first (ts sorts lexically as it's Y-m-d H:i:s); tie-break on
			// job id so the order is deterministic for the tests.
			usort($runs, function ($a, $b) {
				$ta = (string) (isset($a['ts']) ? $a['ts'] : '');
				$tb = (string) (isset($b['ts']) ? $b['ts'] : '');
				if ($ta === $tb) {
					return strcmp((string) (isset($b['job']) ? $b['job'] : ''), (string) (isset($a['job']) ? $a['job'] : ''));
				}
				return strcmp($tb, $ta);
			});
			$latest = $runs[0];

			$cost = 0.0;
			$analysed = 0;
			$running = false;
			$reviewable = false;
			$active = null;   // the first in-progress run, to surface on the merged row
			foreach ($runs as $r) {
				$cost     += (float) (isset($r['cost_total']) ? $r['cost_total'] : 0);
				$analysed += (int) (isset($r['analysed']) ? $r['analysed'] : 0);
				$state = (string) (isset($r['state']) ? $r['state'] : '');
				if (in_array($state, array('queued', 'running'), true)) {
					$running = true;
					if ($active === null) {
						$active = $r;
					}
				}
				if ( ! empty($r['reviewable'])) {
					$reviewable = true;
				}
			}
			$face = $active !== null ? $active : $latest;

			$rows[] = array(
				'is_group'   => true,
				'host'       => $host,
				'url'        => (string) (isset($latest['url']) ? $latest['url'] : ''),
				'runs_count' => count($runs),
				'state'      => (string) (isset($face['state']) ? $face['state'] : 'unknown'),
				'message'    => (string) (isset($face['message']) ? $face['message'] : ''),
				'count'      => (int) (isset($latest['count']) ? $latest['count'] : 0),
				'analysed'   => $analysed,
				'cost_total' => $cost,
				'keyword'    => (string) (isset($latest['keyword']) ? $latest['keyword'] : ''),
				'ai_crawl'   => ! empty($latest['ai_crawl']),
				'ts'         => (string) (isset($latest['ts']) ? $latest['ts'] : ''),
				'done'       => (int) (isset($face['done']) ? $face['done'] : 0),
				'total'      => (int) (isset($face['total']) ? $face['total'] : 0),
				'read_start' => (string) (isset($face['read_start']) ? $face['read_start'] : ''),
				'running'    => $running,
				'reviewable' => $reviewable,
			);
		}

		// Newest-crawled host first.
		usort($rows, function ($a, $b) {
			if ($a['ts'] === $b['ts']) {
				return strcmp($b['host'], $a['host']);
			}
			return strcmp($b['ts'], $a['ts']);
		});
		return $rows;
	}
}

if ( ! function_exists('competitor_spa_api_url'))
{
	/**
	 * Map a JavaScript-app page URL to the JSON API URL that actually holds its
	 * content, for known SPA platforms. Today that's the ICE Holidays platform
	 * (e.g. gd.my): a page like /web/posts/<category>/<id> (or /web/posts/<id>)
	 * is served by /api/v1/posts/<id>. Returns '' when no known mapping applies.
	 * Extend with more patterns as other SPA competitors appear. Pure.
	 */
	function competitor_spa_api_url($url)
	{
		$parts = parse_url((string) $url);
		if (empty($parts['scheme']) || empty($parts['host'])) {
			return '';
		}
		$origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
		$path   = isset($parts['path']) ? $parts['path'] : '';

		// ICE Holidays platform: /web/posts/<category>/<id> or /web/posts/<id>.
		if (preg_match('#/web/posts/(?:[^/]+/)?(\d+)#', $path, $m)) {
			return $origin . '/api/v1/posts/' . $m[1];
		}
		return '';
	}
}

if ( ! function_exists('competitor_ice_listing_api_url'))
{
	/**
	 * Map an ICE Holidays LISTING/search page URL to the JSON API that returns its
	 * FILTERED products, preserving the query verbatim: /web/listing?<query> ->
	 * /api/v1/series?<query> (e.g. keyword=EAST COAST&location_id=85&brands[]=…).
	 * Returns '' for non-listing URLs or a listing with no query (nothing to
	 * filter). Lets a pasted filtered listing analyse only its matches instead of
	 * the whole catalogue. Pure.
	 */
	function competitor_ice_listing_api_url($url)
	{
		$parts = parse_url((string) $url);
		if (empty($parts['scheme']) || empty($parts['host'])) {
			return '';
		}
		$path = isset($parts['path']) ? $parts['path'] : '';
		if ( ! preg_match('#/web/listing/?$#i', $path) || empty($parts['query'])) {
			return '';
		}
		$origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
		return $origin . '/api/v1/series?' . $parts['query'];
	}
}

if ( ! function_exists('competitor_json_api_to_text'))
{
	/**
	 * Turn a JSON:API response body ({data:{attributes:{title, body, …}}} or a
	 * list of them) into readable text for the AI: the title, plus the HTML
	 * `body`/`content`/`description` rendered to text (tables kept), plus any
	 * file/brochure URLs found in it (so PDF links aren't lost). Returns '' when
	 * nothing usable is present. Pure.
	 */
	function competitor_json_api_to_text($json)
	{
		$data = is_string($json) ? json_decode($json, true) : (is_array($json) ? $json : null);
		if ( ! is_array($data)) {
			return '';
		}
		$nodes = array();
		if (isset($data['data'])) {
			$d = $data['data'];
			if (isset($d['attributes']) && is_array($d['attributes'])) {
				$nodes[] = $d['attributes'];
			} elseif (is_array($d)) {
				foreach ($d as $it) {
					if (is_array($it) && isset($it['attributes']) && is_array($it['attributes'])) {
						$nodes[] = $it['attributes'];
					}
				}
			}
		}
		if (empty($nodes) && isset($data['attributes']) && is_array($data['attributes'])) {
			$nodes[] = $data['attributes'];
		}
		if (empty($nodes)) {
			$nodes[] = $data;
		}

		$parts = array();
		foreach ($nodes as $attr) {
			if ( ! is_array($attr)) {
				continue;
			}
			foreach (array('title', 'name', 'heading') as $k) {
				if ( ! empty($attr[$k]) && is_string($attr[$k])) {
					$parts[] = trim(preg_replace('/\s+/', ' ', $attr[$k]));
					break;
				}
			}
			foreach (array('body', 'content', 'description', 'details', 'html', 'itinerary') as $k) {
				if ( ! empty($attr[$k]) && is_string($attr[$k])) {
					$parts[] = competitor_html_to_text($attr[$k]);
					if (preg_match_all('#https?://[^\s"\'<>]+#i', $attr[$k], $mm)) {
						$parts[] = 'Files: ' . implode(' , ', array_slice(array_values(array_unique($mm[0])), 0, 20));
					}
				}
			}
		}
		$text = trim(implode("\n\n", array_filter(array_map('trim', $parts), 'strlen')));
		return preg_replace('/\n{3,}/', "\n\n", $text);
	}
}

if ( ! function_exists('competitor_ice_post_packages'))
{
	/**
	 * Split an ICE "posts" page (e.g. gd.my /api/v1/posts/65 — a promo that LISTS
	 * several packages) into its individual products. The body carries one
	 * `editorjs_table_attach` table per package with 3 cells: name, tour code, and
	 * a "View File" PDF link. Returns [{name, code, file, title}] (title = the post
	 * heading, e.g. "SABAH"), one per package. So a post showing 5 packages becomes
	 * 5 products instead of 1. Empty when the body has no such tables. Pure.
	 */
	function competitor_ice_post_packages($json)
	{
		$data = is_string($json) ? json_decode($json, true) : (is_array($json) ? $json : null);
		if ( ! is_array($data)) {
			return array();
		}
		$attr = isset($data['data']['attributes']) && is_array($data['data']['attributes'])
			? $data['data']['attributes']
			: (isset($data['attributes']) && is_array($data['attributes']) ? $data['attributes'] : $data);
		$body = isset($attr['body']) && is_string($attr['body']) ? $attr['body'] : '';
		if ($body === '') {
			return array();
		}
		$title = '';
		foreach (array('title', 'name', 'heading') as $k) {
			if ( ! empty($attr[$k]) && is_string($attr[$k])) {
				$title = trim(preg_replace('/\s+/', ' ', $attr[$k]));
				break;
			}
		}
		$dec = function ($html) {
			return trim(preg_replace('/\s+/', ' ',
				html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
		};
		$out = array();
		if (preg_match_all('#<table[^>]*editorjs_table_attach[^>]*>(.*?)</table>#is', $body, $tm)) {
			foreach ($tm[1] as $tbl) {
				preg_match_all('#<td[^>]*>(.*?)</td>#is', $tbl, $cm);
				$cells = isset($cm[1]) ? $cm[1] : array();
				$name  = isset($cells[0]) ? $dec($cells[0]) : '';
				$code  = '';
				if (isset($cells[1]) && preg_match('#T\s*/?\s*CODE\s*:?\s*([A-Z0-9\-/]+)#i', $dec($cells[1]), $cc)) {
					$code = strtoupper($cc[1]);
				}
				$file = preg_match('#href\s*=\s*([\'"])(.*?)\1#i', $tbl, $hm) ? trim($hm[2]) : '';
				if ($name !== '' || $file !== '') {
					$out[] = array('name' => $name, 'code' => $code, 'file' => $file, 'title' => $title);
				}
			}
		}
		return $out;
	}
}

if ( ! function_exists('competitor_ice_api_kind'))
{
	/**
	 * Classify an ICE Holidays JSON API URL so the analyser reads it with the
	 * right extractor: 'series' for /api/v1/series/<id> (a tour product),
	 * 'posts' for /api/v1/posts/<id> (a CMS/brochure page), '' otherwise. Pure.
	 */
	function competitor_ice_api_kind($url)
	{
		$path = parse_url((string) $url, PHP_URL_PATH);
		if ( ! is_string($path)) {
			return '';
		}
		if (preg_match('#/api/v1/series/\d+#', $path)) {
			return 'series';
		}
		if (preg_match('#/api/v1/posts/\d+#', $path)) {
			return 'posts';
		}
		return '';
	}
}

if ( ! function_exists('competitor_ice_series_web_url'))
{
	/**
	 * Build the customer-facing web itinerary URL for an ICE series from its detail
	 * JSON — "<origin>/web/itinerary/<code>?type=series[&depart_date=<first tour date>]",
	 * the page a visitor actually browses. The crawler reads the machine /api/v1/series/<id>
	 * JSON, but we store/show THIS friendly URL instead. depart_date is passed through
	 * in the API's own DD/MM/YYYY form. Returns '' when the JSON carries no code. Pure.
	 */
	function competitor_ice_series_web_url($json, $origin)
	{
		$d = is_string($json) ? json_decode($json, true) : $json;
		if ( ! is_array($d)) {
			return '';
		}
		if ( ! isset($d['code']) && ! isset($d['caption']) && isset($d['data']) && is_array($d['data'])) {
			$d = $d['data'];
		}
		$code = isset($d['code']) ? trim((string) $d['code']) : '';
		if ($code === '') {
			return '';
		}
		$url = rtrim((string) $origin, '/') . '/web/itinerary/' . rawurlencode($code) . '?type=series';
		if ( ! empty($d['tours']) && is_array($d['tours'])) {
			foreach ($d['tours'] as $t) {
				if (is_array($t) && ! empty($t['departure_date'])) {
					$url .= '&depart_date=' . rawurlencode(trim((string) $t['departure_date']));
					break;
				}
			}
		}
		return $url;
	}
}

if ( ! function_exists('competitor_ice_series_items'))
{
	/**
	 * Turn an ICE `/api/v1/series?keyword=…` response (its `itineraries[]`) into
	 * pick-list items: [{url, label}] where url is the series DETAIL API
	 * (/api/v1/series/<id>, fetched at analysis time) and label is a human string
	 * "<caption> · <country> · <currency> <price> · <code>". Deduped, capped at
	 * $limit. Pure.
	 */
	function competitor_ice_series_items($json, $origin, $limit = 10)
	{
		$data = is_string($json) ? json_decode($json, true) : $json;
		if ( ! is_array($data) || empty($data['itineraries']) || ! is_array($data['itineraries'])) {
			return array();
		}
		$origin = rtrim((string) $origin, '/');
		$out = array();
		foreach ($data['itineraries'] as $it) {
			if ( ! is_array($it) || ! isset($it['id']) || $it['id'] === '') {
				continue;
			}
			$id  = (string) $it['id'];
			$url = $origin . '/api/v1/series/' . $id;
			if (isset($out[$url])) {
				continue;
			}
			$s = function ($k) use ($it) { return isset($it[$k]) ? trim(preg_replace('/\s+/', ' ', (string) $it[$k])) : ''; };
			$caption = $s('caption');
			$code    = $s('code');
			$country = $s('country');
			$price   = $s('price');
			if (preg_match('/^\d+\.\d+$/', $price)) {
				$price = rtrim(rtrim($price, '0'), '.');
			}
			$name = $caption !== '' ? $caption : ($code !== '' ? $code : ('Series ' . $id));
			$meta = array();
			if ($country !== '') { $meta[] = $country; }
			if ($price !== '')   { $meta[] = trim($s('price_currency') . ' ' . $price); }
			if ($code !== '' && $caption !== '') { $meta[] = $code; }
			$out[$url] = array('url' => $url, 'label' => $name . ($meta ? ' · ' . implode(' · ', $meta) : ''));
			if ($limit > 0 && count($out) >= $limit) {
				break;
			}
		}
		return array_values($out);
	}
}

if ( ! function_exists('competitor_ice_series_to_text'))
{
	/**
	 * Flatten an ICE `/api/v1/series/<id>` detail object into rich text for the
	 * AI: caption/code/country/currency, the HTML description, the `includings`
	 * map, the `tours[]` departures (date/location/price/guide), one highlight
	 * block, and the brochure file URLs. Returns '' when empty. Pure.
	 */
	function competitor_ice_series_to_text($json)
	{
		$d = is_string($json) ? json_decode($json, true) : $json;
		if ( ! is_array($d)) {
			return '';
		}
		if ( ! isset($d['caption']) && ! isset($d['code']) && isset($d['data']) && is_array($d['data'])) {
			$d = $d['data'];
		}
		$g = function ($k) use ($d) {
			if ( ! isset($d[$k])) { return ''; }
			if (is_string($d[$k])) { return trim(preg_replace('/\s+/', ' ', $d[$k])); }
			return is_scalar($d[$k]) ? trim((string) $d[$k]) : '';
		};

		$lines = array();
		if ($g('caption') !== '')       { $lines[] = 'Product: ' . $g('caption'); }
		if ($g('other_caption') !== '') { $lines[] = 'Also known as: ' . $g('other_caption'); }
		$head = array();
		foreach (array('code' => 'Code', 'country' => 'Country', 'price_currency' => 'Currency', 'category' => 'Category') as $k => $lbl) {
			if ($g($k) !== '') { $head[] = $lbl . ': ' . $g($k); }
		}
		if ($head) { $lines[] = implode('  ', $head); }

		if ( ! empty($d['description']) && is_string($d['description'])) {
			$t = competitor_html_to_text($d['description'], 8000);
			if ($t !== '') { $lines[] = "Description:\n" . $t; }
		}

		if ( ! empty($d['includings']) && is_array($d['includings'])) {
			$split = competitor_ice_includings_split($d['includings']);
			if ($split['inclusions']) { $lines[] = "Inclusions:\n- " . implode("\n- ", $split['inclusions']); }
			if ($split['exclusions']) { $lines[] = "Not included:\n- " . implode("\n- ", $split['exclusions']); }
		}

		if ( ! empty($d['tours']) && is_array($d['tours'])) {
			$deps = array();
			foreach (array_slice($d['tours'], 0, 8) as $t) {
				if ( ! is_array($t)) { continue; }
				$dd  = isset($t['departure_date']) ? trim((string) $t['departure_date']) : '';
				$loc = isset($t['departure_location']) ? trim((string) $t['departure_location']) : '';
				$pr  = isset($t['price']) ? trim((string) $t['price']) : '';
				$lang = '';
				if (isset($t['guide_languages'])) {
					$lang = is_array($t['guide_languages']) ? implode('/', array_map('strval', $t['guide_languages'])) : (string) $t['guide_languages'];
				}
				$seg = trim($dd . ($loc !== '' ? ' from ' . $loc : '') . ($pr !== '' ? ': ' . $pr : '') . ($lang !== '' ? ' (guide: ' . $lang . ')' : ''));
				if ($seg !== '') { $deps[] = $seg; }
			}
			if ($deps) { $lines[] = "Departures & prices:\n- " . implode("\n- ", $deps); }
			foreach ($d['tours'] as $t) {
				if (is_array($t) && ! empty($t['highlight']) && is_string($t['highlight'])) {
					$hl = competitor_html_to_text($t['highlight'], 3000);
					if ($hl !== '') { $lines[] = "Highlights:\n" . $hl; }
					break;
				}
			}
		}

		// Flight legs — carried per-departure in tours[].flights[], never in the
		// description text, so surface them explicitly or the AI has no flight data.
		$flt = competitor_ice_flights_text(isset($d['tours']) ? $d['tours'] : array());
		if ($flt !== '') { $lines[] = "Flights:\n- " . $flt; }

		// Day-by-day itinerary — the structured `itinerary_plans` the /web/itinerary
		// page renders (when the product ships one; many gd.my tours carry it only in
		// the brochure PDF instead, which the caller reads separately).
		$itin = competitor_ice_itinerary_text(
			isset($d['itinerary_plans']) ? $d['itinerary_plans'] : array(),
			isset($d['general_content']) ? $d['general_content'] : ''
		);
		if ($itin !== '') { $lines[] = "Itinerary:\n" . $itin; }

		$files = array();
		foreach (array('file_copy_url', 'file_url') as $fk) {
			if ( ! empty($d[$fk]) && is_string($d[$fk])) { $files[] = $d[$fk]; }
		}
		if ($files) { $lines[] = 'Files: ' . implode(' , ', $files); }

		$text = trim(implode("\n\n", array_filter(array_map('trim', $lines), 'strlen')));
		return preg_replace('/\n{3,}/', "\n\n", $text);
	}
}

if ( ! function_exists('competitor_ice_meals_text'))
{
	/**
	 * Render an ICE day-plan's `display_meals` into a short string, tolerating the
	 * shapes it can take: a plain string ("Breakfast / Lunch"), a list of meal names,
	 * or a map meal=>bool / meal=>label. Returns '' when there's nothing to show. Pure.
	 */
	function competitor_ice_meals_text($m)
	{
		if (is_string($m)) { return trim(preg_replace('/\s+/', ' ', $m)); }
		if ( ! is_array($m) || empty($m)) { return ''; }
		$assoc = array_keys($m) !== range(0, count($m) - 1);
		$out = array();
		foreach ($m as $k => $v) {
			if ($assoc) {
				if (is_bool($v)) {
					if ($v) { $out[] = ucwords(str_replace('_', ' ', (string) $k)); }
				} elseif (is_scalar($v) && trim((string) $v) !== '') {
					$out[] = trim((string) $v);
				}
			} elseif (is_scalar($v) && trim((string) $v) !== '') {
				$out[] = trim((string) $v);
			}
		}
		return implode(', ', $out);
	}
}

if ( ! function_exists('competitor_ice_itinerary_text'))
{
	/**
	 * Flatten an ICE series' structured day-by-day itinerary — the `itinerary_plans`
	 * array the /web/itinerary page renders — into readable "Day N: …" text for the
	 * AI. Each plan = {day, title, title_two, display_meals, activities[]}; each
	 * activity = {title, tagline, subtitle (+ *_two second-language variants),
	 * category}. English (the primary field) leads, the second language is appended
	 * in parentheses when it differs. `$general` is the optional general_content HTML
	 * intro. Returns '' when there is no structured itinerary. Pure.
	 */
	function competitor_ice_itinerary_text($plans, $general = '')
	{
		// Prefer the primary (English) string; append the alt-language one when it adds info.
		$bi = function ($a, $b) {
			$a = trim(preg_replace('/\s+/', ' ', (string) $a));
			$b = trim(preg_replace('/\s+/', ' ', (string) $b));
			if ($a === '') { return $b; }
			if ($b === '' || strcasecmp($a, $b) === 0) { return $a; }
			return $a . ' (' . $b . ')';
		};

		$out = array();
		$g = trim((string) $general);
		if ($g !== '') {
			$gt = competitor_html_to_text($g, 4000);
			if ($gt !== '') { $out[] = $gt; }
		}

		if (is_array($plans)) {
			$days = array();
			foreach (array_values($plans) as $i => $plan) {
				if ( ! is_array($plan)) {
					$s = trim((string) $plan);
					if ($s !== '') { $days[] = 'Day ' . ($i + 1) . ': ' . $s; }
					continue;
				}
				$dayno = (isset($plan['day']) && trim((string) $plan['day']) !== '') ? trim((string) $plan['day']) : (string) ($i + 1);
				$title = $bi(isset($plan['title']) ? $plan['title'] : '', isset($plan['title_two']) ? $plan['title_two'] : '');
				$head  = 'Day ' . $dayno . ($title !== '' ? ': ' . $title : '');
				$meals = competitor_ice_meals_text(isset($plan['display_meals']) ? $plan['display_meals'] : null);
				if ($meals !== '') { $head .= '  [Meals: ' . $meals . ']'; }

				$acts = array();
				if ( ! empty($plan['activities']) && is_array($plan['activities'])) {
					foreach ($plan['activities'] as $a) {
						if ( ! is_array($a)) {
							$s = trim(preg_replace('/\s+/', ' ', (string) $a));
							if ($s !== '') { $acts[] = $s; }
							continue;
						}
						$at  = $bi(isset($a['title']) ? $a['title'] : '', isset($a['title_two']) ? $a['title_two'] : '');
						$tag = $bi(isset($a['tagline']) ? $a['tagline'] : '', isset($a['tagline_two']) ? $a['tagline_two'] : '');
						$sub = $bi(isset($a['subtitle']) ? $a['subtitle'] : '', isset($a['subtitle_two']) ? $a['subtitle_two'] : '');
						$seg = $at;
						if ($tag !== '') { $seg = trim($seg . ($seg !== '' ? ' — ' : '') . $tag); }
						if ($sub !== '') { $seg = ($seg !== '' ? $seg . ': ' . $sub : $sub); }
						$seg = trim(preg_replace('/\s+/', ' ', $seg));
						if ($seg !== '') { $acts[] = $seg; }
					}
				}
				$days[] = $head . ($acts ? "\n- " . implode("\n- ", $acts) : '');
			}
			if ($days) { $out[] = implode("\n", $days); }
		}

		return $out ? implode("\n\n", $out) : '';
	}
}

if ( ! function_exists('competitor_ice_flights_text'))
{
	/**
	 * Render an ICE series' flight legs into readable text for the AI. The flight
	 * detail lives in `tours[].flights[]` (airline, flight_no, from/to airports,
	 * departure/arrival date+time) and only the SELECTED/first bookable departure
	 * carries it, so we render the first tour that actually has flights. Each leg =
	 * "AirAsia AK 204: Kuala Lumpur (KUL) -> Nha Trang (CXR), depart 26/09/2026
	 * 10:10, arrive 26/09/2026 11:30". Returns '' when no flights are present. Pure.
	 */
	function competitor_ice_flights_text($tours)
	{
		if ( ! is_array($tours)) {
			return '';
		}
		$flights = array();
		foreach ($tours as $t) {
			if (is_array($t) && ! empty($t['flights']) && is_array($t['flights'])) {
				$flights = $t['flights'];
				break;
			}
		}
		if (empty($flights)) {
			return '';
		}
		$g = function ($a, $k) {
			return isset($a[$k]) && is_scalar($a[$k]) ? trim((string) $a[$k]) : '';
		};
		$legs = array();
		foreach ($flights as $f) {
			if ( ! is_array($f)) { continue; }
			$carrier = trim($g($f, 'airline') . ' ' . $g($f, 'flight_no'));
			$route   = trim($g($f, 'from_airport') . ($g($f, 'to_airport') !== '' ? ' -> ' . $g($f, 'to_airport') : ''));
			$dep     = trim($g($f, 'departure_date') . ' ' . $g($f, 'departure_time'));
			$arr     = trim($g($f, 'arrival_date') . ' ' . $g($f, 'arrival_time'));
			$seg = $carrier !== '' ? $carrier : 'Flight';
			if ($route !== '') { $seg .= ': ' . $route; }
			if ($dep !== '')   { $seg .= ', depart ' . $dep; }
			if ($arr !== '')   { $seg .= ', arrive ' . $arr; }
			$seg = trim(preg_replace('/\s+/', ' ', $seg));
			if ($seg !== '') { $legs[] = $seg; }
		}
		return $legs ? implode("\n- ", $legs) : '';
	}
}

if ( ! function_exists('competitor_ice_includings_split'))
{
	/**
	 * Split an ICE series' `includings` flag-map into what the price DOES and does
	 * NOT include, so the AI reports real exclusions instead of inventing them from
	 * opaque internal flags. Truthy bool / non-empty scalar / non-empty nested array
	 * => included; a bool false => "not included". Internal flags that carry no
	 * customer meaning as a bare yes/no are dropped entirely: `acf`, and a BARE
	 * boolean `accommodation` flag (it contradicts a separately-named hotel — a
	 * nested accommodation object with details is still kept). Returns
	 * ['inclusions' => string[], 'exclusions' => string[]]. Pure.
	 */
	function competitor_ice_includings_split($includings)
	{
		$out = array('inclusions' => array(), 'exclusions' => array());
		if ( ! is_array($includings)) {
			return $out;
		}
		$opaque = array('acf', 'accommodation'); // meaningless/misleading as a bare boolean
		foreach ($includings as $k => $v) {
			$key = str_replace('_', ' ', (string) $k);
			if (is_bool($v)) {
				if (in_array((string) $k, $opaque, true)) { continue; }
				if ($v) { $out['inclusions'][] = $key; }
				else    { $out['exclusions'][] = $key; }
			} elseif (is_scalar($v)) {
				$vv = trim((string) $v);
				if ($vv !== '') { $out['inclusions'][] = $key . ': ' . $vv; }
			} elseif (is_array($v)) {
				$flat = array();
				foreach ($v as $vk => $vv2) {
					if (is_scalar($vv2)) {
						$s = is_bool($vv2) ? ($vv2 ? 'yes' : 'no') : trim((string) $vv2);
						if ($s !== '') { $flat[] = str_replace('_', ' ', (string) $vk) . ' ' . $s; }
					}
				}
				if ($flat) { $out['inclusions'][] = $key . ': ' . implode(', ', array_slice($flat, 0, 10)); }
			}
		}
		return $out;
	}
}

if ( ! function_exists('competitor_build_scraped_agent'))
{
	/**
	 * Build the Responses API pieces when WE scraped the page ourselves: the
	 * already-extracted page text is the source, so there is NO web_search tool
	 * and the model must not browse. Same output contract as the other modes.
	 * Returns ['instructions' => system prompt, 'input' => user text].
	 */
	function competitor_build_scraped_agent($url, $text, $our_products)
	{
		$instructions = "You are a travel-industry competitor analyst for HolidayGoGoGo, a Malaysian tour operator. "
			. "The user gives you the TEXT already scraped from a competitor product page — use ONLY that text; "
			. "do not browse the web and do not rely on prior knowledge. "
			. "Extract the competitor product's details and compare it against the user's own products. "
			. competitor_output_contract();

		$input = "COMPETITOR PAGE URL: " . $url . "\n\n"
			. "SCRAPED PAGE CONTENT:\n" . $text . "\n\n"
			. "OUR PRODUCTS (JSON, prices in MYR):\n" . competitor_products_block($our_products);

		return array('instructions' => $instructions, 'input' => $input);
	}
}

if ( ! function_exists('competitor_extract_text_urls'))
{
	/**
	 * Pull the http(s) URLs out of a block of pasted free text (notes the user
	 * typed, with links dropped in). Strips trailing punctuation/brackets,
	 * dedupes (keeping order), caps at $limit. Pure — no network. Returns [].
	 */
	function competitor_extract_text_urls($text, $limit = 10)
	{
		$text = (string) $text;
		if ($text === '' || ! preg_match_all('#https?://[^\s"\'<>)\]]+#i', $text, $m)) {
			return array();
		}
		$out = array();
		foreach ($m[0] as $u) {
			$u = preg_replace('/[.,);\]]+$/', '', trim($u));
			if ($u === '' || ! preg_match('#^https?://#i', $u) || isset($out[$u])) {
				continue;
			}
			$out[$u] = true;
			if ($limit > 0 && count($out) >= $limit) {
				break;
			}
		}
		return array_keys($out);
	}
}

if ( ! function_exists('competitor_paste_source_label'))
{
	/**
	 * A short "source" label for a pasted-text analysis history row: the first
	 * URL found in the text, else the generic "Pasted text". Pure.
	 */
	function competitor_paste_source_label($text)
	{
		$urls = competitor_extract_text_urls($text, 1);
		return ! empty($urls) ? $urls[0] : 'Pasted text';
	}
}

if ( ! function_exists('competitor_build_paste_agent'))
{
	/**
	 * Build the Responses API pieces for a PASTED-TEXT analysis: the user typed
	 * some notes and (optionally) dropped in one or more links. We fetch each
	 * link's page text OURSELVES and pass it here, so the model sees the pasted
	 * notes plus the scraped content of every link. $links is a list of
	 * ['url' => …, 'text' => …] ('' text = a page we could not read). When
	 * $allow_browse is true (some link came back unreadable and web_search is
	 * available) the instructions let the model open those URLs itself; the
	 * caller adds the web_search tool. Returns ['instructions', 'input'].
	 */
	function competitor_build_paste_agent($notes, $links, $our_products, $allow_browse = false)
	{
		$links = is_array($links) ? $links : array();

		$instructions = "You are a travel-industry competitor analyst for HolidayGoGoGo, a Malaysian tour operator. "
			. "The user pasted some notes and one or more competitor links. Use the pasted notes together with the "
			. "SCRAPED PAGE CONTENT of each link below — do not invent facts that are not shown. ";
		if ($allow_browse) {
			$instructions .= "For any link marked as unreadable, use the web_search tool to OPEN and READ that page "
				. "before analysing it. ";
		} else {
			$instructions .= "Do not browse the web; rely only on what is given. ";
		}
		$instructions .= "Extract the competitor product's details and compare it against the user's own products. "
			. competitor_output_contract();

		$input = "PASTED NOTES:\n" . trim((string) $notes) . "\n\n";
		if ( ! empty($links)) {
			$n = 0;
			foreach ($links as $ln) {
				$n++;
				$url  = isset($ln['url']) ? (string) $ln['url'] : '';
				$body = isset($ln['text']) ? trim((string) $ln['text']) : '';
				$input .= "LINK " . $n . " URL: " . $url . "\n";
				$input .= ($body !== '')
					? ("SCRAPED PAGE CONTENT:\n" . $body . "\n\n")
					: ("(This page's content could not be read automatically" . ($allow_browse ? " — open it with web_search." : ".") . ")\n\n");
			}
		}
		$input .= "OUR PRODUCTS (JSON, prices in MYR):\n" . competitor_products_block($our_products);

		return array('instructions' => $instructions, 'input' => $input);
	}
}

if ( ! function_exists('competitor_build_discovery_agent'))
{
	/**
	 * Build the Responses API pieces for AI-assisted product DISCOVERY — the
	 * fallback when our HTML crawl finds nothing (a JavaScript-rendered site).
	 * The web_search agent browses the site and returns a JSON array of individual
	 * tour/product page URLs (not analysis, just the links). Returns
	 * ['instructions', 'input']; the caller adds the web_search tool.
	 */
	function competitor_build_discovery_agent($base_url, $limit = 10, $keyword = '')
	{
		$limit   = (int) $limit > 0 ? (int) $limit : 10;
		$keyword = trim((string) $keyword);
		$focus   = ($keyword !== '')
			? "Collect ONLY tours whose destination/title matches the keyword \"" . $keyword . "\"; skip unrelated tours. "
			: "";
		$instructions = "You are a web researcher for a Malaysian tour operator. "
			. "Use the web_search tool to OPEN and BROWSE the competitor travel website at the URL given — "
			. "including its tour/package listing pages — and collect the URLs of individual TOUR/PRODUCT DETAIL "
			. "pages (a specific tour, not the homepage and not a category/listing page). "
			. $focus
			. "Only include pages on the same website. "
			. "Reply with ONLY a JSON array of up to " . $limit . " absolute URL strings — no prose, no markdown.";
		$input = "SITE URL (browse this and its tour listings): " . $base_url . "\n"
			. ($keyword !== '' ? "FOCUS keyword (match tours to this): " . $keyword . "\n" : "")
			. "Return up to " . $limit . " individual tour/product page URLs as a JSON array.";
		return array('instructions' => $instructions, 'input' => $input);
	}
}

if ( ! function_exists('competitor_parse_url_list'))
{
	/**
	 * Normalise the discovery agent's reply into a clean product-URL list.
	 * Accepts a JSON array of strings or of {url:…} objects; falls back to
	 * scraping bare http(s) URLs out of prose. Keeps http(s) only, restricts to
	 * $base_host when given (www-insensitive), dedupes, caps at $limit. Pure.
	 */
	function competitor_parse_url_list($text, $base_host = '', $limit = 10)
	{
		$text = trim((string) $text);
		if ($text === '') {
			return array();
		}
		$text = preg_replace('/^```(?:json)?\s*/i', '', $text);
		$text = preg_replace('/\s*```$/', '', $text);

		$raw  = array();
		$data = json_decode($text, true);
		if (is_array($data)) {
			foreach ($data as $item) {
				if (is_string($item)) {
					$raw[] = $item;
				} elseif (is_array($item) && isset($item['url'])) {
					$raw[] = $item['url'];
				}
			}
		}
		if (empty($raw) && preg_match_all('#https?://[^\s"\'<>)\]]+#i', $text, $m)) {
			$raw = $m[0];
		}

		$norm = function ($h) { return preg_replace('/^www\./i', '', strtolower((string) $h)); };
		$want = $norm($base_host);
		$out  = array();
		foreach ($raw as $u) {
			$u = preg_replace('/[.,);\]]+$/', '', trim((string) $u));
			if ($u === '' || ! preg_match('#^https?://#i', $u)) {
				continue;
			}
			if ($base_host !== '' && $norm(parse_url($u, PHP_URL_HOST)) !== $want) {
				continue;
			}
			if (isset($out[$u])) {
				continue;
			}
			$out[$u] = true;
			if ($limit > 0 && count($out) >= $limit) {
				break;
			}
		}
		return array_keys($out);
	}
}

if ( ! function_exists('competitor_build_file_agent'))
{
	/**
	 * Build the Responses API pieces for an uploaded PDF/image competitor
	 * product sheet. Returns ['instructions' => system prompt, 'text' => the
	 * user text prelude]; the caller appends the file content part (see
	 * competitor_file_input_part()) to the same user message. No web_search —
	 * the document IS the source.
	 */
	function competitor_build_file_agent($our_products)
	{
		$instructions = "You are a travel-industry competitor analyst for HolidayGoGoGo, a Malaysian tour operator. "
			. "READ the attached competitor product document/image (a brochure, flyer, itinerary or screenshot) — "
			. "use only what it shows; do not rely on prior knowledge. "
			. "Extract the competitor product's details and compare it against the user's own products. "
			. competitor_output_contract();

		$text = "Analyse the attached competitor product file.\n\n"
			. "OUR PRODUCTS (JSON, prices in MYR):\n" . competitor_products_block($our_products);

		return array('instructions' => $instructions, 'text' => $text);
	}
}

if ( ! function_exists('competitor_file_input_part'))
{
	/**
	 * Turn an uploaded file's extension + base64 data into the Responses API
	 * content part: an `input_image` for pictures, an `input_file` for PDFs.
	 * Returns null for anything unsupported. Pure — the caller does the base64.
	 */
	function competitor_file_input_part($ext, $base64)
	{
		$ext = strtolower(ltrim((string) $ext, '.'));
		$image_mimes = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
		);
		if (isset($image_mimes[$ext])) {
			return array(
				'type'      => 'input_image',
				'image_url' => 'data:' . $image_mimes[$ext] . ';base64,' . $base64,
			);
		}
		if ($ext === 'pdf') {
			return array(
				'type'      => 'input_file',
				'filename'  => 'competitor.pdf',
				'file_data' => 'data:application/pdf;base64,' . $base64,
			);
		}
		return null;
	}
}

if ( ! function_exists('competitor_extract_responses_text'))
{
	/**
	 * Pull the assistant's final text out of a decoded OpenAI Responses API
	 * body. The raw HTTP response carries an `output` array (web_search_call
	 * items plus a `message` item whose content holds `output_text` parts);
	 * `output_text` at the top level is an SDK convenience we still honour when
	 * present. Returns '' when nothing usable is found.
	 */
	function competitor_extract_responses_text($decoded)
	{
		if ( ! is_array($decoded)) {
			return '';
		}
		// Top-level convenience field (string or array of strings).
		if (isset($decoded['output_text'])) {
			$ot = $decoded['output_text'];
			if (is_string($ot) && trim($ot) !== '') {
				return trim($ot);
			}
			if (is_array($ot)) {
				$joined = trim(implode('', array_map('strval', $ot)));
				if ($joined !== '') {
					return $joined;
				}
			}
		}
		// Walk the output array for message -> output_text parts.
		$text = '';
		if (isset($decoded['output']) && is_array($decoded['output'])) {
			foreach ($decoded['output'] as $item) {
				if ( ! is_array($item) || (isset($item['type']) && $item['type'] !== 'message')) {
					continue;
				}
				if ( ! isset($item['content']) || ! is_array($item['content'])) {
					continue;
				}
				foreach ($item['content'] as $part) {
					if (is_array($part) && isset($part['type']) && $part['type'] === 'output_text' && isset($part['text'])) {
						$text .= (string) $part['text'];
					}
				}
			}
		}
		return trim($text);
	}
}

if ( ! function_exists('competitor_extract_usage'))
{
	/**
	 * Pull token counts out of a decoded OpenAI response body so we can cost the
	 * call. The Responses API reports `usage.input_tokens` / `usage.output_tokens`;
	 * we also accept the Chat Completions names (prompt_/completion_tokens) as a
	 * fallback. Returns ['input_tokens' => int, 'output_tokens' => int], zeros when
	 * usage is absent.
	 */
	function competitor_extract_usage($decoded)
	{
		$in = 0; $out = 0;
		if (is_array($decoded) && isset($decoded['usage']) && is_array($decoded['usage'])) {
			$u = $decoded['usage'];
			if (isset($u['input_tokens'])) {
				$in = (int) $u['input_tokens'];
			} elseif (isset($u['prompt_tokens'])) {
				$in = (int) $u['prompt_tokens'];
			}
			if (isset($u['output_tokens'])) {
				$out = (int) $u['output_tokens'];
			} elseif (isset($u['completion_tokens'])) {
				$out = (int) $u['completion_tokens'];
			}
		}
		return array('input_tokens' => $in, 'output_tokens' => $out);
	}
}

if ( ! function_exists('competitor_model_prices'))
{
	/**
	 * Published OpenAI list prices in USD per 1,000,000 tokens as [input, output].
	 * Used to turn token counts into a cost estimate. Keys are lower-case model
	 * ids; unknown models fall back to gpt-4o-mini in competitor_estimate_cost().
	 * Token-based only — the web_search tool's per-call fee is not included.
	 */
	function competitor_model_prices()
	{
		return array(
			'gpt-4o-mini'  => array(0.15, 0.60),
			'gpt-4o'       => array(2.50, 10.00),
			'gpt-4.1'      => array(2.00, 8.00),
			'gpt-4.1-mini' => array(0.40, 1.60),
			'gpt-4.1-nano' => array(0.10, 0.40),
			'o4-mini'      => array(1.10, 4.40),
			// GPT-5 family — approximate launch-era list prices (USD/1M tokens). Set
			// OPENAI_PRICE_INPUT / OPENAI_PRICE_OUTPUT in .env for exact costing.
			'gpt-5'        => array(1.25, 10.00),
			'gpt-5-mini'   => array(0.25, 2.00),
			'gpt-5-nano'   => array(0.05, 0.40),
			'gpt-5.4-mini' => array(0.25, 2.00),
			'gpt-5.5'      => array(1.25, 10.00),
			'gpt-5.5-pro'  => array(15.00, 120.00),
		);
	}
}

if ( ! function_exists('competitor_model_supports_temperature'))
{
	/**
	 * Whether a model accepts a custom `temperature` on the Responses API.
	 * Reasoning models (o-series, gpt-5+) only allow the default and 400 when a
	 * temperature is sent — EXCEPT their `-chat` variants. Pure.
	 */
	function competitor_model_supports_temperature($model)
	{
		$m = strtolower(trim((string) $model));
		if ($m === '') {
			return true;
		}
		$is_reasoning = (bool) preg_match('/^o\d/', $m) || (bool) preg_match('/^gpt-5/', $m);
		return ! ($is_reasoning && strpos($m, 'chat') === false);
	}
}

if ( ! function_exists('competitor_web_search_tool_for_model'))
{
	/**
	 * The web_search tool `type` a given model expects. GPT-5 / o-series use the
	 * GA `web_search`; older gpt-4o uses `web_search_preview`. A non-empty
	 * OPENAI_WEB_SEARCH_TOOL override always wins. Pure.
	 */
	function competitor_web_search_tool_for_model($model, $override = '')
	{
		$override = trim((string) $override);
		if ($override !== '') {
			return $override;
		}
		$m = strtolower(trim((string) $model));
		if (preg_match('/^o\d/', $m) || preg_match('/^gpt-5/', $m)) {
			return 'web_search';
		}
		return 'web_search_preview';
	}
}

if ( ! function_exists('competitor_estimate_cost'))
{
	/**
	 * Estimate the USD cost of one call from its token counts. Pass explicit
	 * $rates = ['input' => x, 'output' => y] (USD per 1M tokens, e.g. from .env)
	 * to override; otherwise the model is looked up in competitor_model_prices(),
	 * defaulting to gpt-4o-mini. Rounded to 6 dp. Pure.
	 */
	function competitor_estimate_cost($model, $input_tokens, $output_tokens, $rates = null)
	{
		if ( ! is_array($rates) || ! isset($rates['input']) || ! isset($rates['output'])) {
			$table = competitor_model_prices();
			$key   = strtolower(trim((string) $model));
			$rate  = isset($table[$key]) ? $table[$key] : $table['gpt-4o-mini'];
			$rates = array('input' => $rate[0], 'output' => $rate[1]);
		}
		$cost = ((int) $input_tokens  / 1000000) * (float) $rates['input']
		      + ((int) $output_tokens / 1000000) * (float) $rates['output'];
		return round($cost, 6);
	}
}

if ( ! function_exists('competitor_parse_ai_response'))
{
	/**
	 * Normalise the model's JSON reply into a flat record the model layer can
	 * store. Tolerates code-fenced ```json blocks and stray prose around the
	 * object. Returns null when no JSON object can be recovered. List fields
	 * are always arrays of strings; scalar fields always strings.
	 */
	function competitor_parse_ai_response($content)
	{
		$content = trim((string) $content);
		if ($content === '') {
			return null;
		}
		// Strip a leading/trailing ```json ... ``` fence if present.
		$content = preg_replace('/^```(?:json)?\s*/i', '', $content);
		$content = preg_replace('/\s*```$/', '', $content);

		$data = json_decode($content, true);
		if ( ! is_array($data)) {
			// Last resort: grab the outermost {...} span.
			if (preg_match('/\{.*\}/s', $content, $m)) {
				$data = json_decode($m[0], true);
			}
		}
		if ( ! is_array($data)) {
			return null;
		}

		$str = function ($v) {
			if (is_array($v)) {
				$v = implode(', ', array_map('strval', $v));
			}
			return trim((string) $v);
		};
		$list = function ($v) {
			if (is_string($v)) {
				$v = $v === '' ? array() : preg_split('/\s*[\n;•]\s*/u', $v);
			}
			if ( ! is_array($v)) {
				return array();
			}
			$out = array();
			foreach ($v as $item) {
				$item = trim((string) (is_array($item) ? implode(' ', $item) : $item));
				if ($item !== '') {
					$out[] = $item;
				}
			}
			return array_values($out);
		};
		// Meals always returns the three known slots (blank when unknown) so the
		// view can render a stable breakfast/lunch/dinner row.
		$meals = function ($v) use ($str) {
			$v = is_array($v) ? $v : array();
			return array(
				'breakfast' => $str(isset($v['breakfast']) ? $v['breakfast'] : ''),
				'lunch'     => $str(isset($v['lunch']) ? $v['lunch'] : ''),
				'dinner'    => $str(isset($v['dinner']) ? $v['dinner'] : ''),
			);
		};
		// Itinerary: a clean [{day,title,description}] list. A bare-string entry
		// becomes that day's description; a numeric day gets a "Day N" label.
		$itin = function ($v) use ($str) {
			if ( ! is_array($v)) {
				return array();
			}
			$out = array();
			$n = 0;
			foreach ($v as $d) {
				$n++;
				if ( ! is_array($d)) {
					$desc = trim((string) $d);
					if ($desc === '') { continue; }
					$out[] = array('day' => 'Day ' . $n, 'title' => '', 'description' => $desc);
					continue;
				}
				$day = $str(isset($d['day']) ? $d['day'] : '');
				if ($day === '') {
					$day = 'Day ' . $n;
				} elseif (preg_match('/^\d+$/', $day)) {
					$day = 'Day ' . $day;
				}
				$title = $str(isset($d['title']) ? $d['title'] : '');
				$desc  = $str(isset($d['description']) ? $d['description'] : (isset($d['desc']) ? $d['desc'] : ''));
				if ($title === '' && $desc === '') { continue; }
				$out[] = array('day' => $day, 'title' => $title, 'description' => $desc);
			}
			return array_values($out);
		};

		$get = function ($key) use ($data) {
			return isset($data[$key]) ? $data[$key] : null;
		};

		return array(
			// Scalars.
			'product_name'     => $str($get('product_name')),
			'tour_code'        => $str($get('tour_code')),
			'price'            => $str($get('price')),
			'price_from'       => $str($get('price_from')),
			'price_to'         => $str($get('price_to')),
			'currency'         => $str($get('currency')),
			'destination'      => $str($get('destination')),
			'duration'         => $str($get('duration')),
			'departure_city'   => $str($get('departure_city')),
			'flight_departure' => $str($get('flight_departure')),
			'flight_return'    => $str($get('flight_return')),
			'difficulty'       => $str($get('difficulty')),
			'target_traveller' => $str($get('target_traveller')),
			'suitable_age'     => $str($get('suitable_age')),
			'child_friendly'   => $str($get('child_friendly')),
			'senior_friendly'  => $str($get('senior_friendly')),
			'summary'          => $str($get('summary')),
			'comparison'       => $str($get('comparison')),
			'matched_product'  => $str($get('matched_product')),
			// Multi-entry lists.
			'countries'        => $list($get('countries')),
			'cities'           => $list($get('cities')),
			'travel_months'    => $list($get('travel_months')),
			'themes'           => $list($get('themes')),
			'tour_styles'      => $list($get('tour_styles')),
			'local_transport'  => $list($get('local_transport')),
			'inclusions'       => $list($get('inclusions')),
			'exclusions'       => $list($get('exclusions')),
			'hotels'           => $list($get('hotels')),
			'shopping_stops'   => $list($get('shopping_stops')),
			'optional_tours'   => $list($get('optional_tours')),
			'special_remarks'  => $list($get('special_remarks')),
			'usp'              => $list($get('usp')),
			'pros'             => $list($get('pros')),
			'cons'             => $list($get('cons')),
			// Structured.
			'meals'            => $meals($get('meals')),
			'itinerary'        => $itin($get('itinerary')),
			'scenic_highlights'=> competitor_scenic_items($get('scenic_highlights')),
			'traveller_segments'=> competitor_traveller_segments($get('traveller_segments')),
		);
	}
}

if ( ! function_exists('competitor_scenic_items'))
{
	/**
	 * Coerce the scenic_highlights value into a clean [{name, description}] list.
	 * "name" is the attraction; "description" is what the customer can expect to
	 * see/do during the visit (customer POV). Tolerates every shape the field has
	 * carried: the new object list, legacy bare strings (name only, blank
	 * description), a single newline/semicolon/bullet-delimited string, and stray
	 * nested arrays. Drops entries with neither a name nor a description. Pure.
	 */
	function competitor_scenic_items($value)
	{
		if (is_string($value)) {
			$value = $value === '' ? array() : preg_split('/\s*[\n;•]\s*/u', $value);
		}
		if ( ! is_array($value)) {
			return array();
		}
		$out = array();
		foreach ($value as $it) {
			$name = '';
			$desc = '';
			if (is_array($it)) {
				foreach (array('name', 'title', 'highlight', 'place') as $nk) {
					if (isset($it[$nk]) && trim((string) $it[$nk]) !== '') { $name = trim((string) $it[$nk]); break; }
				}
				foreach (array('description', 'desc', 'expect', 'what_to_expect', 'experience') as $dk) {
					if (isset($it[$dk]) && trim((string) $it[$dk]) !== '') { $desc = trim((string) $it[$dk]); break; }
				}
				// A bare list of scalars (no recognised keys) collapses to the name.
				if ($name === '' && $desc === '') {
					$name = trim(implode(' ', array_filter(array_map(function ($x) {
						return is_scalar($x) ? (string) $x : '';
					}, $it), 'strlen')));
				}
			} else {
				$name = trim((string) $it);
			}
			if ($name === '' && $desc === '') { continue; }
			$out[] = array('name' => $name, 'description' => $desc);
		}
		return array_values($out);
	}
}

if ( ! function_exists('competitor_traveller_segment_defs'))
{
	/**
	 * The seven canonical traveller types the analysis is judged against, in display
	 * order: key => English label. The key is the stable identifier the AI returns
	 * and the UI/translation layers key off; the label is the English fallback used
	 * where a localized UI label is unavailable. Pure.
	 */
	function competitor_traveller_segment_defs()
	{
		return array(
			'single'         => 'Single / Solo',
			'elderly'        => 'Elderly',
			'teenager'       => 'Teenager',
			'couple'         => 'Couple',
			'family_kids'    => 'Family with Kids',
			'family_elderly' => 'Family with Elderly',
			'company'        => 'Company / Corporate',
		);
	}
}

if ( ! function_exists('competitor_segment_match_key'))
{
	/**
	 * Map a free-text traveller label to one of the seven canonical segment keys, or
	 * '' when nothing matches. Order matters: the compound family types are tested
	 * before the bare ones so "family with elderly" resolves to family_elderly (not
	 * family_kids or elderly). Pure.
	 */
	function competitor_segment_match_key($text)
	{
		$t = strtolower(trim((string) $text));
		if ($t === '') { return ''; }
		if (array_key_exists($t, competitor_traveller_segment_defs())) { return $t; }
		$has = function ($needles) use ($t) {
			foreach ((array) $needles as $n) { if (strpos($t, $n) !== false) { return true; } }
			return false;
		};
		$family = $has(array('family', 'families'));
		if ($has(array('compan', 'corporate', 'business', 'incentive', 'colleague'))) { return 'company'; }
		if ($family && $has(array('elder', 'senior', 'grandparent', 'parent', 'multi-gen', 'multigen', 'multi gen'))) { return 'family_elderly'; }
		if ($family) { return 'family_kids'; }   // a bare "family" defaults to with-kids
		if ($has(array('couple', 'honeymoon', 'partner', 'romantic'))) { return 'couple'; }
		if ($has(array('teen', 'youth', 'student', 'adolescent'))) { return 'teenager'; }
		if ($has(array('elder', 'senior', 'retire', 'old'))) { return 'elderly'; }
		if ($has(array('single', 'solo', 'individual', 'alone'))) { return 'single'; }
		return '';
	}
}

if ( ! function_exists('competitor_segment_level'))
{
	/**
	 * Normalise a free-text suitability value into 'high' | 'medium' | 'low' | '' so
	 * the UI colours the badge language-independently (the persisted level survives
	 * translation of the visible label). "not ideal" etc. is checked before the
	 * positive words so a negated phrase never reads as high. Pure.
	 */
	function competitor_segment_level($suitability)
	{
		$s = strtolower(trim((string) $suitability));
		if ($s === '') { return ''; }
		$has = function ($needles) use ($s) {
			foreach ((array) $needles as $n) { if (strpos($s, $n) !== false) { return true; } }
			return false;
		};
		if ($has(array('not suitable', 'unsuitable', 'not ideal', 'poor', 'low', 'avoid'))) { return 'low'; }
		if ($has(array('high', 'very', 'ideal', 'excellent', 'perfect', 'great', 'strongly', 'recommended', 'best'))) { return 'high'; }
		if ($has(array('medium', 'moderate', 'fair', 'partial', 'somewhat', 'okay', 'average', 'mixed'))) { return 'medium'; }
		if ($s === 'ok') { return 'medium'; }
		if ($s === 'no') { return 'low'; }
		if ($s === 'yes') { return 'high'; }
		return '';
	}
}

if ( ! function_exists('competitor_traveller_segments'))
{
	/**
	 * Coerce the traveller_segments value into a clean list of per-type verdicts —
	 * one entry per canonical segment that carries content, in canonical order:
	 * [{key, segment, suitability, level, justification}]. Accepts the AI's list of
	 * objects, an assoc map keyed by segment name, and tolerates alternate field
	 * names. `level` is derived from `suitability` so the badge colour survives
	 * translation. Entries with neither a suitability nor a justification are dropped
	 * (legacy rows render nothing). Pure.
	 */
	function competitor_traveller_segments($value)
	{
		if ( ! is_array($value)) { return array(); }
		$pick = function ($arr, $keys) {
			foreach ((array) $keys as $k) {
				if (isset($arr[$k]) && ! is_array($arr[$k]) && trim((string) $arr[$k]) !== '') {
					return trim((string) $arr[$k]);
				}
			}
			return '';
		};
		$found = array();   // canonical key => [suitability, level, justification]
		foreach ($value as $k => $it) {
			$seg_text = '';
			$suit = '';
			$just = '';
			$level = '';
			if (is_array($it)) {
				$seg_text = $pick($it, array('segment', 'type', 'name', 'traveller', 'label', 'key'));
				$suit     = $pick($it, array('suitability', 'fit', 'rating', 'score', 'verdict'));
				$just     = $pick($it, array('justification', 'reason', 'why', 'info', 'detail', 'details', 'note', 'notes', 'explanation'));
				$level    = $pick($it, array('level'));   // preserved when re-normalising an already-built entry
			} else {
				$just = trim((string) $it);
			}
			// An assoc map ("single" => {...} / "single" => "text") carries the segment
			// name in the array key.
			if ($seg_text === '' && is_string($k)) { $seg_text = $k; }
			$ckey = competitor_segment_match_key($seg_text);
			if ($ckey === '') { continue; }
			if ($suit === '' && $just === '') { continue; }
			if ( ! isset($found[$ckey])) {   // first non-empty verdict for a type wins
				$found[$ckey] = array('suitability' => $suit, 'level' => $level, 'justification' => $just);
			}
		}
		$out = array();
		foreach (competitor_traveller_segment_defs() as $ckey => $label) {
			if ( ! isset($found[$ckey])) { continue; }
			$suit  = $found[$ckey]['suitability'];
			// An explicit level (from an already-normalised entry) wins so the badge
			// colour survives translation of the visible suitability wording; a fresh
			// AI entry has none, so derive it from the English suitability text.
			$level = $found[$ckey]['level'] !== '' ? $found[$ckey]['level'] : competitor_segment_level($suit);
			$out[] = array(
				'key'           => $ckey,
				'segment'       => $label,
				'suitability'   => $suit,
				'level'         => $level,
				'justification' => $found[$ckey]['justification'],
			);
		}
		return $out;
	}
}

if ( ! function_exists('competitor_split_point_justification'))
{
	/**
	 * Split a customer-POV pros/cons item shaped "<point> — <justification>" into
	 * its two halves so the UI can weight the point over the reasoning. Recognises
	 * an em/en dash or a spaced hyphen as the separator and only splits on the
	 * FIRST one, so a justification that itself contains dashes stays intact.
	 * Returns ['point' => ..., 'justification' => '']; justification is '' when the
	 * item carries no separator (a legacy bare pro/con). Pure.
	 */
	function competitor_split_point_justification($item)
	{
		$item  = trim((string) $item);
		// Em/en dash split even without surrounding spaces (translated CJK output
		// often drops them); a hyphen only counts when spaced, so hyphenated words
		// like "well-known" stay intact.
		$parts = preg_split('/\s*[—–]+\s*|\s+-\s+/u', $item, 2);
		return array(
			'point'         => isset($parts[0]) ? trim($parts[0]) : $item,
			'justification' => isset($parts[1]) ? trim($parts[1]) : '',
		);
	}
}

if ( ! function_exists('competitor_summary_sections'))
{
	/**
	 * Break the (now richer) summary text into labelled paragraphs so the UI can
	 * weight each "Overview:" / "Best for:" / ... label over its body. The AI is
	 * asked to separate paragraphs with a blank line; we split on blank lines and
	 * fall back to single newlines when it collapses them. A paragraph is treated
	 * as labelled only when it opens with a SHORT phrase (<=40 chars, no sentence
	 * punctuation) followed by a colon — so a plain 2-4 sentence legacy summary, or
	 * a sentence that merely contains a mid-clause colon, degrades to one unlabelled
	 * block. Handles both the ASCII ":" and the full-width "：" of translated CJK.
	 * Returns [['label' => ..., 'text' => ...], ...]; label is '' when absent. Pure.
	 */
	function competitor_summary_sections($summary)
	{
		$summary = trim((string) $summary);
		if ($summary === '') { return array(); }
		// Prefer blank-line paragraphs; if the model gave none, treat each line as one.
		$blocks = preg_split('/\R\s*\R/u', $summary);
		if (count($blocks) === 1) { $blocks = preg_split('/\R/u', $summary); }
		$out = array();
		foreach ($blocks as $b) {
			$b = trim((string) $b);
			if ($b === '') { continue; }
			$label = '';
			$text  = $b;
			// A leading "<short label>:" with real body text after it. The label must
			// not span a line break and must carry no sentence-ending punctuation, so
			// ordinary prose with an internal colon is left whole.
			if (preg_match('/^([^\r\n:：.!?]{1,40})[:：][ \t]*(\S.*)$/su', $b, $m)) {
				$label = trim($m[1]);
				$text  = trim($m[2]);
			}
			$out[] = array('label' => $label, 'text' => $text);
		}
		return $out;
	}
}

if ( ! function_exists('competitor_pdf_filename'))
{
	/**
	 * A safe download filename for a saved analysis PDF: the product/page name
	 * slugified (spaces->_, punctuation dropped), suffixed with the row id, and
	 * ending in .pdf. Falls back to "competitor-analysis" when the name is blank.
	 * Pure — no DB/filesystem.
	 */
	function competitor_pdf_filename($name, $id)
	{
		$slug = strtolower(trim((string) $name));
		$slug = preg_replace('/[^a-z0-9]+/', '-', $slug);   // non-alnum -> single dash
		$slug = trim((string) $slug, '-');
		if ($slug === '') { $slug = 'competitor-analysis'; }
		$slug = substr($slug, 0, 60);                       // keep filenames sane
		return $slug . '-' . (int) $id . '.pdf';
	}
}

/* ---------------------------------------------------------------------------
 * Translation (EN / CN) — pure helpers for the "translate this analysis"
 * feature. The AI-extracted content is stored in English; on demand we ask
 * OpenAI to translate the human-readable VALUES into another language and cache
 * the result. These helpers decide which fields are translatable, shape the
 * request, and merge the translated values back onto a product record. The
 * network call lives in CompetitorAnalysisService::translate_analysis().
 * ------------------------------------------------------------------------- */

if ( ! function_exists('competitor_supported_langs'))
{
	/** Languages the UI offers. 'en' is the stored original; others are translated. */
	function competitor_supported_langs()
	{
		return array('en', 'cn');
	}
}

if ( ! function_exists('competitor_normalize_lang'))
{
	/** Clamp any input to a supported language code, defaulting to 'en'. */
	function competitor_normalize_lang($lang)
	{
		$lang = strtolower(trim((string) $lang));
		return in_array($lang, competitor_supported_langs(), true) ? $lang : 'en';
	}
}

if ( ! function_exists('competitor_lang_label'))
{
	/** The target-language name handed to the translator model. */
	function competitor_lang_label($lang)
	{
		switch (competitor_normalize_lang($lang)) {
			case 'cn': return 'Simplified Chinese (简体中文)';
			default:   return 'English';
		}
	}
}

if ( ! function_exists('competitor_translate_scalar_keys'))
{
	/** Single-value fields whose text is translated (prices/codes are excluded). */
	function competitor_translate_scalar_keys()
	{
		return array(
			'product_name', 'destination', 'duration', 'departure_city', 'difficulty',
			'target_traveller', 'suitable_age', 'child_friendly', 'senior_friendly',
			'summary', 'comparison', 'matched_product',
		);
	}
}

if ( ! function_exists('competitor_translate_list_keys'))
{
	/** List fields (arrays of strings) whose items are translated. */
	function competitor_translate_list_keys()
	{
		return array(
			'countries', 'cities', 'travel_months', 'themes', 'tour_styles', 'local_transport',
			'inclusions', 'exclusions', 'hotels', 'shopping_stops', 'optional_tours',
			'special_remarks', 'usp', 'pros', 'cons',
		);
	}
}

if ( ! function_exists('competitor_extract_translatable'))
{
	/**
	 * Pull only the translatable, non-empty pieces of one product record into a
	 * compact structure: { scalars:{}, lists:{}, meals:{}, itinerary:[{day,title,
	 * description}] }. Empty sections are omitted so we don't pay to translate
	 * blanks. Structure/keys are preserved so the reply can be merged back by key
	 * and index. Pure.
	 */
	function competitor_extract_translatable($product)
	{
		$P = (array) $product;
		$out = array();

		$scalars = array();
		foreach (competitor_translate_scalar_keys() as $k) {
			$v = isset($P[$k]) ? $P[$k] : '';
			if ( ! is_array($v)) {
				$v = trim((string) $v);
				if ($v !== '') { $scalars[$k] = $v; }
			}
		}
		if ($scalars) { $out['scalars'] = $scalars; }

		$flat = function ($items) {
			$acc = array();
			if ( ! is_array($items)) { return $acc; }
			foreach ($items as $it) {
				if (is_array($it)) {
					$it = implode(' ', array_filter(array_map(function ($x) { return is_scalar($x) ? (string) $x : ''; }, $it), 'strlen'));
				}
				$it = trim((string) $it);
				if ($it !== '') { $acc[] = $it; }
			}
			return $acc;
		};
		$lists = array();
		foreach (competitor_translate_list_keys() as $k) {
			$items = $flat(isset($P[$k]) ? $P[$k] : array());
			if ($items) { $lists[$k] = $items; }
		}
		if ($lists) { $out['lists'] = $lists; }

		$m = isset($P['meals']) && is_array($P['meals']) ? $P['meals'] : array();
		$meals = array();
		foreach (array('breakfast', 'lunch', 'dinner') as $mk) {
			$mv = trim((string) (isset($m[$mk]) ? $m[$mk] : ''));
			if ($mv !== '') { $meals[$mk] = $mv; }
		}
		if ($meals) { $out['meals'] = $meals; }

		$it = isset($P['itinerary']) && is_array($P['itinerary']) ? $P['itinerary'] : array();
		if ($it) {
			$itin = array();
			foreach ($it as $day) {
				$day = (array) $day;
				$row = array();
				foreach (array('day', 'title', 'description') as $dk) {
					$dv = trim((string) (isset($day[$dk]) ? $day[$dk] : ''));
					if ($dv !== '') { $row[$dk] = $dv; }
				}
				$itin[] = $row;   // keep blanks to preserve index alignment
			}
			$out['itinerary'] = $itin;
		}

		// Scenic highlights: translate both the place name and the what-to-expect
		// description, keeping the index so the overlay merges back cleanly.
		$sc = isset($P['scenic_highlights']) ? competitor_scenic_items($P['scenic_highlights']) : array();
		if ($sc) {
			$scenic = array();
			foreach ($sc as $s) {
				$row = array();
				foreach (array('name', 'description') as $sk) {
					$sv = trim((string) (isset($s[$sk]) ? $s[$sk] : ''));
					if ($sv !== '') { $row[$sk] = $sv; }
				}
				$scenic[] = $row;   // keep blanks to preserve index alignment
			}
			$out['scenic_highlights'] = $scenic;
		}

		// Traveller-type verdicts: translate only the free-text suitability + reason,
		// keeping the index so the overlay merges back cleanly. The colour level is
		// re-derived from the original at apply time, so it is not sent.
		$ts = isset($P['traveller_segments']) ? competitor_traveller_segments($P['traveller_segments']) : array();
		if ($ts) {
			$seg = array();
			foreach ($ts as $s) {
				$row = array();
				foreach (array('suitability', 'justification') as $sk) {
					$sv = trim((string) (isset($s[$sk]) ? $s[$sk] : ''));
					if ($sv !== '') { $row[$sk] = $sv; }
				}
				$seg[] = $row;   // keep blanks to preserve index alignment
			}
			$out['traveller_segments'] = $seg;
		}
		return $out;
	}
}

if ( ! function_exists('competitor_apply_translation'))
{
	/**
	 * Overlay a translated structure (from competitor_extract_translatable, with
	 * values swapped for the target language) back onto the original product
	 * record. Missing/blank translations keep the original text, so a partial
	 * reply degrades gracefully. Lists are matched by key, itinerary by index.
	 * Returns a translated COPY; never mutates the input. Pure.
	 */
	function competitor_apply_translation($product, $tr)
	{
		$out = (array) $product;
		$tr  = (array) $tr;

		if (isset($tr['scalars']) && is_array($tr['scalars'])) {
			$allowed = competitor_translate_scalar_keys();
			foreach ($tr['scalars'] as $k => $v) {
				if (in_array($k, $allowed, true) && is_string($v) && trim($v) !== '') {
					$out[$k] = $v;
				}
			}
		}

		if (isset($tr['lists']) && is_array($tr['lists'])) {
			$allowed = competitor_translate_list_keys();
			foreach ($tr['lists'] as $k => $v) {
				if ( ! in_array($k, $allowed, true) || ! is_array($v)) { continue; }
				$items = array();
				foreach ($v as $it) {
					$it = trim((string) (is_array($it) ? implode(' ', $it) : $it));
					if ($it !== '') { $items[] = $it; }
				}
				if ($items) { $out[$k] = $items; }
			}
		}

		if (isset($tr['meals']) && is_array($tr['meals'])) {
			$m = isset($out['meals']) && is_array($out['meals']) ? $out['meals'] : array();
			foreach (array('breakfast', 'lunch', 'dinner') as $mk) {
				if (isset($tr['meals'][$mk]) && is_string($tr['meals'][$mk]) && trim($tr['meals'][$mk]) !== '') {
					$m[$mk] = $tr['meals'][$mk];
				}
			}
			$out['meals'] = $m;
		}

		if (isset($tr['itinerary']) && is_array($tr['itinerary'])
			&& isset($out['itinerary']) && is_array($out['itinerary'])) {
			$days = $out['itinerary'];
			foreach ($days as $i => $day) {
				$day = (array) $day;
				if (isset($tr['itinerary'][$i]) && is_array($tr['itinerary'][$i])) {
					foreach (array('day', 'title', 'description') as $dk) {
						$tv = isset($tr['itinerary'][$i][$dk]) ? $tr['itinerary'][$i][$dk] : null;
						if (is_string($tv) && trim($tv) !== '') { $day[$dk] = $tv; }
					}
				}
				$days[$i] = $day;
			}
			$out['itinerary'] = $days;
		}

		if (isset($tr['scenic_highlights']) && is_array($tr['scenic_highlights'])) {
			$items = competitor_scenic_items(isset($out['scenic_highlights']) ? $out['scenic_highlights'] : array());
			foreach ($items as $i => $s) {
				if (isset($tr['scenic_highlights'][$i]) && is_array($tr['scenic_highlights'][$i])) {
					foreach (array('name', 'description') as $sk) {
						$tv = isset($tr['scenic_highlights'][$i][$sk]) ? $tr['scenic_highlights'][$i][$sk] : null;
						if (is_string($tv) && trim($tv) !== '') { $s[$sk] = $tv; }
					}
				}
				$items[$i] = $s;
			}
			$out['scenic_highlights'] = $items;
		}

		if (isset($tr['traveller_segments']) && is_array($tr['traveller_segments'])) {
			$items = competitor_traveller_segments(isset($out['traveller_segments']) ? $out['traveller_segments'] : array());
			foreach ($items as $i => $s) {
				if (isset($tr['traveller_segments'][$i]) && is_array($tr['traveller_segments'][$i])) {
					foreach (array('suitability', 'justification') as $sk) {
						$tv = isset($tr['traveller_segments'][$i][$sk]) ? $tr['traveller_segments'][$i][$sk] : null;
						if (is_string($tv) && trim($tv) !== '') { $s[$sk] = $tv; }
					}
					// `level` is left as re-derived from the ORIGINAL suitability by
					// competitor_traveller_segments() above, so the badge colour stays
					// correct even though the visible label is now translated.
				}
				$items[$i] = $s;
			}
			$out['traveller_segments'] = $items;
		}
		return $out;
	}
}

if ( ! function_exists('competitor_build_translation_agent'))
{
	/**
	 * Shape the OpenAI Responses request that translates a payload's string
	 * values into $lang while preserving JSON structure. Prices, codes, dates and
	 * URLs are kept verbatim. Returns {instructions, input}. Pure.
	 */
	function competitor_build_translation_agent($payload, $lang)
	{
		$target = competitor_lang_label($lang);
		$instructions =
			"You are a professional travel-industry translator. The user sends a JSON object. "
			. "Translate every human-readable string VALUE into {$target}. "
			. "STRICT RULES: (1) Keep the JSON structure, keys, and array order EXACTLY the same. "
			. "(2) Translate values only — never rename keys. "
			. "(3) DO NOT translate or alter numbers, prices, currency codes, dates, tour codes, "
			. "airport/flight codes, or URLs — copy them verbatim. "
			. "(4) Render place names naturally in {$target}. "
			. "(5) Return ONLY the JSON object — no commentary, no markdown, no code fence.";
		// The input must mention "json" for the Responses API json_object format mode
		// (which guarantees a syntactically valid reply — the model otherwise
		// occasionally emits an unbalanced brace on long CJK output).
		$input = "Translate the string values in this JSON object to {$target} and return a "
			. "JSON object of the exact same shape:\n"
			. json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return array(
			'instructions' => $instructions,
			'input'        => $input,
		);
	}
}

if ( ! function_exists('competitor_json_object_from_text'))
{
	/**
	 * Parse a JSON object out of a model's text reply — strips a ```json fence and
	 * falls back to the outermost {...} span. Returns an assoc array or null. Pure.
	 */
	function competitor_json_object_from_text($text)
	{
		$text = trim((string) $text);
		if ($text === '') { return null; }
		$text = preg_replace('/^```(?:json)?\s*/i', '', $text);
		$text = preg_replace('/\s*```$/', '', $text);
		$data = json_decode($text, true);
		if (is_array($data)) { return $data; }

		// Fallbacks for a slightly-dirty reply: (a) the outermost {...} span, then
		// (b) a string-aware walk that returns the first balanced object — this
		// trims trailing prose or a stray extra brace some models append.
		if (preg_match('/\{.*\}/s', $text, $m)) {
			$data = json_decode($m[0], true);
			if (is_array($data)) { return $data; }
		}
		$balanced = competitor_first_balanced_object($text);
		if ($balanced !== '') {
			$data = json_decode($balanced, true);
			if (is_array($data)) { return $data; }
		}
		return null;
	}
}

if ( ! function_exists('competitor_first_balanced_object'))
{
	/**
	 * Return the first brace-balanced {...} object in $text (string-literal aware,
	 * so braces inside quoted values don't count), or '' if none. Lets us recover a
	 * valid object when the model appended trailing junk or an extra closer. Pure.
	 */
	function competitor_first_balanced_object($text)
	{
		$text = (string) $text;
		$start = strpos($text, '{');
		if ($start === false) { return ''; }
		$depth = 0; $in_str = false; $esc = false;
		$len = strlen($text);
		for ($i = $start; $i < $len; $i++) {
			$c = $text[$i];
			if ($esc) { $esc = false; continue; }
			if ($c === '\\') { $esc = true; continue; }
			if ($c === '"') { $in_str = ! $in_str; continue; }
			if ($in_str) { continue; }
			if ($c === '{') { $depth++; }
			elseif ($c === '}') {
				$depth--;
				if ($depth === 0) { return substr($text, $start, $i - $start + 1); }
			}
		}
		return '';   // never closed
	}
}

if ( ! function_exists('competitor_row_to_product'))
{
	/**
	 * Build the canonical product array from a single-analysis row ($a from
	 * Read_One, which already merged details_json onto the row). Shared by the
	 * detail view, the PDF and the translator so the single/upload/paste shape is
	 * mapped in exactly one place. Pure.
	 */
	function competitor_row_to_product($a)
	{
		$a = (object) $a;
		$g = function ($k, $default = '') use ($a) {
			return isset($a->$k) ? $a->$k : $default;
		};
		return array(
			'url' => $g('url'),
			'product_name' => $g('product_name'), 'tour_code' => $g('tour_code'),
			'price' => $g('price'), 'price_from' => $g('price_from'), 'price_to' => $g('price_to'),
			'currency' => $g('currency'), 'destination' => $g('destination'), 'duration' => $g('duration'),
			'departure_city' => $g('departure_city'), 'flight_departure' => $g('flight_departure'), 'flight_return' => $g('flight_return'),
			'difficulty' => $g('difficulty'), 'target_traveller' => $g('target_traveller'), 'suitable_age' => $g('suitable_age'),
			'child_friendly' => $g('child_friendly'), 'senior_friendly' => $g('senior_friendly'),
			'summary' => $g('summary'), 'comparison' => $g('comparison'), 'matched_product' => $g('matched_product'),
			'countries' => $g('countries', array()), 'cities' => $g('cities', array()), 'travel_months' => $g('travel_months', array()),
			'themes' => $g('themes', array()), 'tour_styles' => $g('tour_styles', array()), 'local_transport' => $g('local_transport', array()),
			'inclusions' => $g('inclusions', array()), 'exclusions' => $g('exclusions', array()), 'hotels' => $g('hotels', array()),
			'shopping_stops' => $g('shopping_stops', array()), 'optional_tours' => $g('optional_tours', array()), 'special_remarks' => $g('special_remarks', array()),
			'scenic_highlights' => $g('scenic_highlights', array()), 'usp' => $g('usp', array()),
			'traveller_segments' => $g('traveller_segments', array()),
			'pros' => $g('pros', array()), 'cons' => $g('cons', array()), 'meals' => $g('meals', array()), 'itinerary' => $g('itinerary', array()),
		);
	}
}

if ( ! function_exists('competitor_display_products'))
{
	/**
	 * Normalise an analysis row into a flat list of product arrays: a site crawl
	 * yields its stored products, a single/upload/paste yields one product built
	 * from the row. The one source of truth the view, PDF and translator share.
	 */
	function competitor_display_products($a)
	{
		$a = (object) $a;
		if ( ! empty($a->products) && is_array($a->products)) {
			$out = array();
			foreach ($a->products as $p) { $out[] = (array) $p; }
			return $out;
		}
		return array(competitor_row_to_product($a));
	}
}

if ( ! function_exists('competitor_apply_translation_to_row'))
{
	/**
	 * Overlay a cached translation ({products:[overlay,...]}) onto an analysis row
	 * ($a from Read_One) so the view/PDF render in the target language. For a site
	 * crawl each stored product is overlaid; for a single row the translated keys
	 * are written back onto $a. Returns the (same, mutated) row. Falls back to the
	 * original text wherever a translation is missing.
	 */
	function competitor_apply_translation_to_row($a, $translation)
	{
		$overlays = (is_array($translation) && isset($translation['products']) && is_array($translation['products']))
			? $translation['products'] : array();
		if (empty($overlays)) { return $a; }

		if ( ! empty($a->products) && is_array($a->products)) {
			$prods = $a->products;
			foreach ($prods as $i => $p) {
				if (isset($overlays[$i])) {
					$prods[$i] = competitor_apply_translation((array) $p, $overlays[$i]);
				}
			}
			$a->products = $prods;
			return $a;
		}

		$translated = competitor_apply_translation(competitor_row_to_product($a), $overlays[0]);
		$keys = array_merge(competitor_translate_scalar_keys(), competitor_translate_list_keys(), array('meals', 'itinerary', 'scenic_highlights', 'traveller_segments'));
		foreach ($keys as $k) {
			if (array_key_exists($k, $translated)) { $a->$k = $translated[$k]; }
		}
		return $a;
	}
}

if ( ! function_exists('competitor_ui_labels'))
{
	/**
	 * The static UI labels for the detail view + PDF, per language. English is the
	 * source; 'cn' is Simplified Chinese. Anything unknown falls back to English so
	 * a missing key never blanks a label. Pure.
	 */
	function competitor_ui_labels($lang)
	{
		$en = array(
			'tour_code' => 'Tour Code', 'destination' => 'Destination', 'duration' => 'Duration',
			'departure_city' => 'Departure City', 'price_range' => 'Price Range', 'currency' => 'Currency',
			'difficulty' => 'Difficulty', 'suitable_age' => 'Suitable Age',
			'traveller_fit' => 'Traveller Fit', 'target_traveller' => 'Target Traveller',
			'child_friendly' => 'Child Friendly', 'senior_friendly' => 'Senior Friendly', 'usp' => 'Unique Selling Points',
			'traveller_suitability' => 'Suitability by Traveller Type',
			'seg_single' => 'Single / Solo', 'seg_elderly' => 'Elderly', 'seg_teenager' => 'Teenager',
			'seg_couple' => 'Couple', 'seg_family_kids' => 'Family with Kids',
			'seg_family_elderly' => 'Family with Elderly', 'seg_company' => 'Company / Corporate',
			'suit_high' => 'High', 'suit_medium' => 'Medium', 'suit_low' => 'Low',
			'coverage' => 'Coverage', 'countries' => 'Countries', 'cities' => 'Cities', 'travel_months' => 'Travel Months',
			'themes' => 'Themes', 'tour_style' => 'Tour Style', 'local_transport' => 'Local Transport',
			'flight_details' => 'Flight Details', 'departure' => 'Departure', 'return' => 'Return',
			'meals' => 'Meals', 'breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner',
			'hotels' => 'Hotels', 'scenic_highlights' => 'Scenic Highlights',
			'shopping_stops' => 'Shopping Stops', 'inclusions' => 'Inclusions', 'exclusions' => 'Exclusions',
			'optional_tours' => 'Optional Tours', 'special_remarks' => 'Special Remarks',
			'daily_itinerary' => 'Daily Itinerary', 'summary' => 'Summary', 'pros' => 'Pros', 'cons' => 'Cons',
			'comparison' => 'Comparison vs Our Products', 'compared_against' => 'Compared against our product:',
			'site' => 'Site:', 'source' => 'Source:', 'analysed' => 'Analysed', 'products' => 'products',
			'ai_cost' => 'AI cost', 'uploaded_file' => 'uploaded file', 'back' => 'Back',
			'download_pdf' => 'Download PDF', 'product' => 'Product', 'analysis_failed' => 'Analysis failed:',
			'section_tour_info' => 'Tour Information', 'section_ai_analysis' => 'AI Analysis',
		);
		$cn = array(
			'tour_code' => '行程代码', 'destination' => '目的地', 'duration' => '行程天数',
			'departure_city' => '出发城市', 'price_range' => '价格范围', 'currency' => '货币',
			'difficulty' => '难度', 'suitable_age' => '适合年龄',
			'traveller_fit' => '适合人群', 'target_traveller' => '目标旅客',
			'child_friendly' => '适合儿童', 'senior_friendly' => '适合长者', 'usp' => '独特卖点',
			'traveller_suitability' => '各类旅客适合度',
			'seg_single' => '单身 / 独自旅行', 'seg_elderly' => '长者', 'seg_teenager' => '青少年',
			'seg_couple' => '情侣', 'seg_family_kids' => '亲子家庭',
			'seg_family_elderly' => '携长者家庭', 'seg_company' => '公司 / 团体',
			'suit_high' => '高', 'suit_medium' => '中', 'suit_low' => '低',
			'coverage' => '覆盖范围', 'countries' => '国家', 'cities' => '城市', 'travel_months' => '出行月份',
			'themes' => '主题', 'tour_style' => '行程风格', 'local_transport' => '当地交通',
			'flight_details' => '航班详情', 'departure' => '去程', 'return' => '回程',
			'meals' => '餐食', 'breakfast' => '早餐', 'lunch' => '午餐', 'dinner' => '晚餐',
			'hotels' => '酒店', 'scenic_highlights' => '精华景点',
			'shopping_stops' => '购物站', 'inclusions' => '包含项目', 'exclusions' => '不包含项目',
			'optional_tours' => '自费项目', 'special_remarks' => '特别说明',
			'daily_itinerary' => '每日行程', 'summary' => '总结', 'pros' => '优点', 'cons' => '缺点',
			'comparison' => '与我方产品比较', 'compared_against' => '对比我方产品：',
			'site' => '网站：', 'source' => '来源：', 'analysed' => '分析于', 'products' => '个产品',
			'ai_cost' => 'AI 成本', 'uploaded_file' => '上传文件', 'back' => '返回',
			'download_pdf' => '下载 PDF', 'product' => '产品', 'analysis_failed' => '分析失败：',
			'section_tour_info' => '行程资料', 'section_ai_analysis' => 'AI 分析',
		);
		return (competitor_normalize_lang($lang) === 'cn') ? array_merge($en, $cn) : $en;
	}
}

if ( ! function_exists('competitor_remove_crawl_item'))
{
	/**
	 * Remove ONE crawled product (by its original index) from a crawl's items +
	 * status, for the Review page's per-product delete. Pure: takes the decoded
	 * items array and status array, returns the updated pair plus the analysis row
	 * id that must be deleted from the DB (0 when the product was never analysed).
	 *
	 * The other indices are LEFT IN PLACE (the array stays sparse) so the status
	 * `analysed` map — which is keyed by original index — keeps pointing at the
	 * right products. When the removed product had already been analysed, its
	 * entry is dropped and its cost refunded from the running `cost_total`. The
	 * displayed `count` is resynced to the number of remaining items. Inputs are
	 * not mutated.
	 */
	function competitor_remove_crawl_item($items, $status, $index)
	{
		$items  = is_array($items) ? $items : array();
		$status = is_array($status) ? $status : array();
		$index  = (int) $index;

		$deleted_analysis_id = 0;

		if (array_key_exists($index, $items)) {
			unset($items[$index]);   // keep other keys as-is (no reindex)
		}

		$analysed = isset($status['analysed']) && is_array($status['analysed']) ? $status['analysed'] : array();
		$key = (string) $index;
		if (isset($analysed[$key]) && is_array($analysed[$key])) {
			$deleted_analysis_id = isset($analysed[$key]['id']) ? (int) $analysed[$key]['id'] : 0;
			$cost = isset($analysed[$key]['cost']) ? (float) $analysed[$key]['cost'] : 0.0;
			$prev = isset($status['cost_total']) ? (float) $status['cost_total'] : 0.0;
			$status['cost_total'] = round(max(0, $prev - $cost), 6);
			unset($analysed[$key]);
		}
		$status['analysed'] = $analysed;
		$status['count']    = count($items);

		return array(
			'items'               => $items,
			'status'              => $status,
			'deleted_analysis_id' => $deleted_analysis_id,
		);
	}
}

if ( ! function_exists('competitor_remove_crawl_items'))
{
	/**
	 * Remove SEVERAL crawled products at once (bulk delete on the Review page) by
	 * folding competitor_remove_crawl_item() over each index. Returns the updated
	 * items + status and the list of analysis row ids to delete from the DB. Pure;
	 * inputs are not mutated.
	 */
	function competitor_remove_crawl_items($items, $status, $indices)
	{
		$items   = is_array($items) ? $items : array();
		$status  = is_array($status) ? $status : array();
		$indices = is_array($indices) ? $indices : array();

		$deleted_ids = array();
		foreach ($indices as $idx) {
			$res    = competitor_remove_crawl_item($items, $status, $idx);
			$items  = $res['items'];
			$status = $res['status'];
			if ($res['deleted_analysis_id'] > 0) {
				$deleted_ids[] = $res['deleted_analysis_id'];
			}
		}

		return array(
			'items'                => $items,
			'status'               => $status,
			'deleted_analysis_ids' => $deleted_ids,
		);
	}
}

if ( ! function_exists('competitor_url_dedup_key'))
{
	/**
	 * Normalise a URL to a stable host+path key for de-duplication: lowercased,
	 * scheme- and www-insensitive, trailing slash / query / fragment dropped. So
	 * "https://x.com/tour/", "http://www.x.com/tour?a=1#f" and "x.com/tour" all
	 * collapse to "x.com/tour". Returns '' when no host can be parsed. Pure.
	 */
	function competitor_url_dedup_key($url)
	{
		$url  = trim((string) $url);
		if ($url === '') {
			return '';
		}
		// parse_url needs a scheme to find the host; add one for bare host/paths.
		$parts = parse_url(preg_match('#^https?://#i', $url) ? $url : 'http://' . $url);
		$host  = isset($parts['host']) ? strtolower($parts['host']) : '';
		if ($host === '') {
			return '';
		}
		$host = preg_replace('/^www\./', '', $host);
		$path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
		return $host . strtolower($path);
	}
}

if ( ! function_exists('competitor_orphan_crawl_rows'))
{
	/**
	 * Re-hydrate crawl-analysed DB rows into the results listing as standalone
	 * rows, so a saved analysis keeps showing after its transient crawl job file
	 * is pruned (job files are deleted at 7 days, but the DB row lives forever —
	 * that gap is why crawled analyses vanished from the listing while still
	 * appearing on the Master Product page, which reads the DB directly).
	 *
	 * $db_rows      analysis rows (objects/arrays) with id, url, product_name,
	 *               cost_usd, status, created_at.
	 * $covered_keys keys already represented by a LIVE crawl job row — so we don't
	 *               double-show a crawl whose file still exists (its products are
	 *               reachable via Review/Timeline). Compared case-insensitively.
	 * $key_mode     'host' (competitor listing merges by host) or 'url' (Our
	 *               Product lists each pasted URL on its own).
	 *
	 * Returns listing-row arrays shaped like the folded upload rows (is_upload so
	 * the DB-row View/Delete path applies) but tagged is_crawled for the label.
	 * Pure; no DB or filesystem access.
	 */
	function competitor_orphan_crawl_rows($db_rows, $covered_keys, $key_mode = 'host')
	{
		$db_rows = is_array($db_rows) ? $db_rows : array();
		// url mode dedups on a normalised host+path (so a trailing slash, query,
		// fragment or http/https/www difference between the pasted URL and the
		// saved analysis URL still counts as the same page); host mode on the host.
		$norm = function ($v) use ($key_mode) {
			return ($key_mode === 'url')
				? competitor_url_dedup_key((string) $v)
				: strtolower(competitor_job_host((string) $v));
		};
		$covered = array();
		foreach ((is_array($covered_keys) ? $covered_keys : array()) as $k) {
			$nk = ($key_mode === 'url') ? competitor_url_dedup_key((string) $k) : strtolower((string) $k);
			if ($nk !== '') {
				$covered[$nk] = true;
			}
		}

		$rows = array();
		foreach ($db_rows as $r) {
			$r   = (object) $r;
			$url = (string) (isset($r->url) ? $r->url : '');
			$key = $norm($url);
			if ($key === '' || isset($covered[$key])) {
				continue;   // shown by a live crawl job row already
			}
			$ts = (string) (isset($r->created_at) ? $r->created_at : '');
			$rows[] = array(
				'job'         => 'crawl_' . (int) $r->id,
				'is_upload'   => true,                                   // reuse the DB-row (View/Delete) path
				'is_crawled'  => true,                                   // label as "Crawled", link the URL
				'analysis_id' => (int) $r->id,
				'url'         => $url,
				'title'       => (string) (isset($r->product_name) ? $r->product_name : ''),
				'state'       => ((isset($r->status) ? $r->status : '') === 'error') ? 'error' : 'done',
				'message'     => 'Crawled',
				'count'       => 1,
				'analysed'    => 1,
				'cost_total'  => (float) (isset($r->cost_usd) ? $r->cost_usd : 0),
				'ts'          => $ts,
				'keyword'     => '',
				'reviewable'  => false,
				'done'        => 1,
				'total'       => 1,
				'read_start'  => '',
				'_sort'       => strtotime($ts) ?: 0,
			);
		}
		return $rows;
	}
}
