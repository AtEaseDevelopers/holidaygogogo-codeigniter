<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * FAQ AI Suggestion helpers — pure, DB-free functions behind the "FAQ AI
 * Suggestion" feature.
 *
 * A scheduled cron reads the last N days of WhatsApp chats (uploaded exports +
 * GHL messages), hands the transcript to OpenAI, and stores the returned
 * candidate FAQs for a human to review, edit and promote to a real FAQ. These
 * helpers own every pure transform (window cut-off, transcript assembly, prompt
 * building, response parsing, dedupe) so they unit-test without a DB or network
 * (see tests/helpers/FaqSuggestionHelperTest.php). The controller / model /
 * service only do the IO around them.
 */

if (!function_exists('faq_suggestion_cutoff')) {
	/**
	 * The 'Y-m-d H:i:s' datetime $days before $now_ts — the lower bound of the
	 * "last N days" window. $days is floored to >= 1 so a blank/zero config can't
	 * silently widen the window to everything.
	 */
	function faq_suggestion_cutoff($now_ts, $days = 3)
	{
		$days = (int) $days;
		if ($days < 1) {
			$days = 1;
		}
		return date('Y-m-d H:i:s', (int) $now_ts - $days * 86400);
	}
}

if (!function_exists('faq_suggestion_norm_msg')) {
	/**
	 * Normalise a message body for duplicate detection: lowercase, every run of
	 * non-alphanumerics (unicode-aware) collapsed to a single space, trimmed. So
	 * "How much deposit??" and "how much deposit" share one key. Pure.
	 */
	function faq_suggestion_norm_msg($body)
	{
		$b = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $body)) : strtolower(trim((string) $body));
		$b = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $b);
		return trim(preg_replace('/\s+/', ' ', $b));
	}
}

if (!function_exists('faq_suggestion_is_noise')) {
	/**
	 * True when a message carries no FAQ value and should be dropped before the
	 * AI sees it — so tokens aren't spent on chatter and no real question is lost:
	 *   - blank / emoji-only / punctuation-only / single-character bodies
	 *   - WhatsApp media & system placeholders (<Media omitted>, deleted, missed call…)
	 *   - bare acknowledgements / greetings ("ok", "thanks", "noted", "hi"…)
	 * A longer message that merely starts with a greeting ("hi how much is Japan?")
	 * is NOT noise — only the whole body matching an ack/greeting is. Pure.
	 */
	function faq_suggestion_is_noise($body)
	{
		$b = trim((string) $body);
		if ($b === '') {
			return true;
		}
		// Emoji / punctuation only (nothing alphanumeric), or a single character.
		$alnum = preg_replace('/[^\p{L}\p{N}]+/u', '', $b);
		if ($alnum === '' || (function_exists('mb_strlen') ? mb_strlen($alnum) : strlen($alnum)) <= 1) {
			return true;
		}

		$low = strtolower($b);
		static $media = array(
			'media omitted', 'image omitted', 'sticker omitted', 'audio omitted',
			'video omitted', 'gif omitted', 'document omitted', 'contact card omitted',
			'this message was deleted', 'you deleted this message',
			'missed voice call', 'missed video call', 'null',
		);
		foreach ($media as $p) {
			if (strpos($low, $p) !== false) {
				return true;
			}
		}

		$norm = faq_suggestion_norm_msg($b);
		static $acks = array(
			'ok', 'okay', 'okey', 'k', 'kk', 'ok ok', 'ok noted', 'ok thanks', 'okay thanks',
			'thanks', 'thank you', 'thankyou', 'thanks ya', 'ty', 'tq', 'tqvm', 'tks', 'thx',
			'noted', 'noted thanks', 'sure', 'yes', 'yea', 'yeah', 'ya', 'yup', 'yep',
			'no', 'nope', 'welcome', 'you are welcome', 'alright', 'all right', 'great',
			'good', 'nice', 'fine', 'done', 'received', 'ok tq', 'hi', 'hello', 'hey', 'hai',
			'good morning', 'good afternoon', 'good evening', 'morning',
		);
		return in_array($norm, $acks, true);
	}
}

if (!function_exists('faq_suggestion_transcript')) {
	/**
	 * Merge GHL + WhatsApp messages into a single labelled transcript for the AI
	 * prompt, reduced to preserve FAQ meaning while cutting tokens:
	 *   1. Each message → "Customer: ..." (their side) or "Agent: ..." (ours).
	 *      System notices and blank bodies dropped.
	 *   2. Noise dropped (faq_suggestion_is_noise): media/system placeholders,
	 *      emoji-only, bare acks/greetings.
	 *   3. Near-duplicate bodies collapsed (per side) to a single line — a repeated
	 *      customer question is emitted ONCE with "(asked N times)", so the model
	 *      still sees how common it is without paying for every repeat, and repeated
	 *      agent blasts don't bloat the input.
	 *   4. Optional char backstop: when $max_chars > 0 and the result overflows,
	 *      the OLDEST lines are dropped (recent tail kept) on a line boundary.
	 *      $max_chars = 0 (default) means no cap — steps 1-3 do the reduction.
	 *
	 * $ghl_rows   : list of ['direction'=>'inbound'|'outbound', 'body'=>string]
	 * $wa_messages: list of ['outbound'=>bool, 'body'=>string, 'system'=>bool]
	 */
	function faq_suggestion_transcript($ghl_rows, $wa_messages, $max_chars = 0)
	{
		// 1) Unify into an ordered list of ['who'=>, 'body'=>], dropping blanks/system.
		$msgs = array();
		foreach ((array) $ghl_rows as $r) {
			$body = trim((string) (isset($r['body']) ? $r['body'] : ''));
			if ($body === '') {
				continue;
			}
			$dir = strtolower((string) (isset($r['direction']) ? $r['direction'] : ''));
			$msgs[] = array('who' => ($dir === 'inbound') ? 'Customer' : 'Agent', 'body' => $body);
		}
		foreach ((array) $wa_messages as $m) {
			if (!empty($m['system'])) {
				continue;
			}
			$body = trim((string) (isset($m['body']) ? $m['body'] : ''));
			if ($body === '') {
				continue;
			}
			$msgs[] = array('who' => !empty($m['outbound']) ? 'Agent' : 'Customer', 'body' => $body);
		}

		// 2) Drop noise.
		$kept = array();
		foreach ($msgs as $m) {
			if (!faq_suggestion_is_noise($m['body'])) {
				$kept[] = $m;
			}
		}

		// 3) Count customer-question repeats, then emit each unique body once
		// (per side), tagging a repeated customer question with its frequency.
		$counts = array();
		foreach ($kept as $m) {
			if ($m['who'] !== 'Customer') {
				continue;
			}
			$k = faq_suggestion_norm_msg($m['body']);
			if ($k === '') {
				continue;
			}
			$counts[$k] = (isset($counts[$k]) ? $counts[$k] : 0) + 1;
		}

		$seen  = array();
		$lines = array();
		foreach ($kept as $m) {
			$k  = faq_suggestion_norm_msg($m['body']);
			$sk = $m['who'] . '|' . $k;
			if ($k !== '' && isset($seen[$sk])) {
				continue; // duplicate of an already-emitted line (this side)
			}
			if ($k !== '') {
				$seen[$sk] = true;
			}
			$line = $m['who'] . ': ' . $m['body'];
			if ($m['who'] === 'Customer' && $k !== '' && isset($counts[$k]) && $counts[$k] > 1) {
				$line .= ' (asked ' . $counts[$k] . ' times)';
			}
			$lines[] = $line;
		}

		// 4) Char backstop (0 = uncapped).
		$text = implode("\n", $lines);
		$max_chars = (int) $max_chars;
		if ($max_chars > 0 && strlen($text) > $max_chars) {
			$text = substr($text, strlen($text) - $max_chars);
			$nl = strpos($text, "\n");
			if ($nl !== false) {
				$text = substr($text, $nl + 1); // drop the partial leading line
			}
		}
		return $text;
	}
}

if (!function_exists('faq_suggestion_build_prompt')) {
	/**
	 * Build the OpenAI Responses API instructions + input for FAQ mining. The
	 * model is asked to return a JSON OBJECT (the literal word "json" appears in
	 * the input, which the Responses API json_object mode requires) with a
	 * "suggestions" list; each suggestion carries a title, optional destination
	 * names (chosen from the provided vocabulary) and a list of question/answer
	 * pairs — the same shape a FAQ stores. $destination_names is the allowed
	 * destination vocabulary so the model tags with real, resolvable destinations.
	 */
	function faq_suggestion_build_prompt($transcript, $destination_names = array())
	{
		$dest = array();
		foreach ((array) $destination_names as $n) {
			$n = trim((string) $n);
			if ($n !== '') {
				$dest[] = $n;
			}
		}
		$dest_line = empty($dest) ? '(none configured)' : implode(', ', $dest);

		$instructions =
			"You are a customer-support knowledge analyst for a Malaysian tour agency. " .
			"You read recent WhatsApp / CRM conversations between customers and sales agents " .
			"and distil the questions customers ask into reusable internal FAQ entries. " .
			"PRIORITISE from the customer's point of view: surface the questions that help the MOST customers — " .
			"the most frequently asked and the ones that most affect a booking decision " .
			"(e.g. pricing & deposits, payment, booking / cancellation / refund process, what's included, " .
			"visa & documents, flights & logistics, itinerary specifics). " .
			"Group similar questions together and write a clear, generic answer based on how the agents actually replied. " .
			"For EACH FAQ also give a short 'reason' (one sentence) explaining why it is valuable to customers — " .
			"how often it came up in the chats and why it matters to a booking. " .
			"ORDER the suggestions from most to least helpful — highest customer impact and frequency FIRST. " .
			"Never include a specific customer's name, phone number, a price quoted to one person, or any other private data. " .
			"Prefer 5 to 12 high-value FAQs; skip one-off or purely transactional chatter. " .
			"Answer ONLY with a JSON object.";

		$input =
			"Return json with this exact shape, with the most helpful FAQ first:\n" .
			"{\"suggestions\":[{" .
			"\"title\":\"short FAQ title\"," .
			"\"reason\":\"one sentence: why this FAQ helps customers (how often asked / booking impact)\"," .
			"\"destinations\":[\"zero or more of the allowed destination names\"]," .
			"\"items\":[{\"q\":\"the customer question\",\"a\":\"a clear reusable answer\"}]" .
			"}]}\n\n" .
			"List the suggestions in order of how much they help customers — most impactful and most frequently asked first.\n\n" .
			"Allowed destinations (copy names verbatim, or leave the array empty when the FAQ is not destination-specific): " .
			$dest_line . "\n\n" .
			"Conversations:\n" . (string) $transcript;

		return array('instructions' => $instructions, 'input' => $input);
	}
}

if (!function_exists('faq_suggestion_parse_response')) {
	/**
	 * Parse the AI's JSON reply into a clean list of suggestions:
	 *   [ ['title'=>string, 'reason'=>string, 'destination_ids'=>[int], 'items'=>[['q'=>,'a'=>], ...]], ... ]
	 *
	 * $decoded is the already-json_decoded assoc array, or a raw JSON string.
	 * $dest_name_to_id maps a destination name => CategoryID so the returned
	 * names resolve to ids (matched case-insensitively; unknown names dropped).
	 * A suggestion with no title or no complete Q&A item is dropped. The list is
	 * capped at $max entries.
	 */
	function faq_suggestion_parse_response($decoded, $dest_name_to_id = array(), $max = 30)
	{
		if (is_string($decoded)) {
			$decoded = json_decode($decoded, true);
		}
		if (!is_array($decoded)) {
			return array();
		}
		// Accept either {"suggestions":[...]} or a bare [...] list.
		if (isset($decoded['suggestions']) && is_array($decoded['suggestions'])) {
			$list = $decoded['suggestions'];
		} elseif (array_key_exists(0, $decoded)) {
			$list = $decoded;
		} else {
			$list = array();
		}

		$map = array();
		if (is_array($dest_name_to_id)) {
			foreach ($dest_name_to_id as $name => $id) {
				$map[strtolower(trim((string) $name))] = (int) $id;
			}
		}

		$max = (int) $max;
		$out = array();
		foreach ($list as $s) {
			if (!is_array($s)) {
				continue;
			}
			$title = trim((string) (isset($s['title']) ? $s['title'] : ''));
			if ($title === '') {
				continue;
			}
			if (strlen($title) > 255) {
				$title = substr($title, 0, 255);
			}

			$reason = trim((string) (isset($s['reason']) ? $s['reason'] : ''));
			if (strlen($reason) > 500) {
				$reason = substr($reason, 0, 500);
			}

			$items = array();
			$raw_items = isset($s['items']) && is_array($s['items']) ? $s['items'] : array();
			foreach ($raw_items as $it) {
				if (!is_array($it)) {
					continue;
				}
				$q = trim((string) (isset($it['q']) ? $it['q'] : ''));
				$a = trim((string) (isset($it['a']) ? $it['a'] : ''));
				if ($q === '' || $a === '') {
					continue;
				}
				$items[] = array('q' => $q, 'a' => $a);
			}
			if (empty($items)) {
				continue;
			}

			$dest_ids = array();
			$raw_dests = isset($s['destinations']) && is_array($s['destinations']) ? $s['destinations'] : array();
			foreach ($raw_dests as $dn) {
				$k = strtolower(trim((string) $dn));
				if ($k !== '' && isset($map[$k]) && !in_array($map[$k], $dest_ids, true)) {
					$dest_ids[] = $map[$k];
				}
			}

			$out[] = array('title' => $title, 'reason' => $reason, 'destination_ids' => $dest_ids, 'items' => $items);
			if ($max > 0 && count($out) >= $max) {
				break;
			}
		}
		return $out;
	}
}

if (!function_exists('faq_suggestion_norm_title')) {
	/**
	 * Normalise a title for duplicate detection: lowercase, every run of
	 * non-alphanumerics collapsed to a single space, trimmed. Pure.
	 */
	function faq_suggestion_norm_title($title)
	{
		$t = strtolower(trim((string) $title));
		$t = preg_replace('/[^a-z0-9]+/', ' ', $t);
		return trim($t);
	}
}

if (!function_exists('faq_suggestion_filter_new')) {
	/**
	 * Drop suggestions whose normalised title already exists in $existing_titles
	 * (titles already stored as a FAQ or a prior suggestion) or is duplicated
	 * within this batch — so a scheduled run doesn't re-propose the same FAQ over
	 * and over. Order is preserved. Pure.
	 */
	function faq_suggestion_filter_new($suggestions, $existing_titles = array())
	{
		$seen = array();
		foreach ((array) $existing_titles as $t) {
			$key = faq_suggestion_norm_title($t);
			if ($key !== '') {
				$seen[$key] = true;
			}
		}
		$out = array();
		foreach ((array) $suggestions as $s) {
			$key = faq_suggestion_norm_title(isset($s['title']) ? $s['title'] : '');
			if ($key === '' || isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$out[] = $s;
		}
		return $out;
	}
}
