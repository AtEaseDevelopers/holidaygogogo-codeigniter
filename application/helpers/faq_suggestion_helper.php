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

if (!function_exists('faq_suggestion_date_range')) {
	/**
	 * Validate + normalise the user-chosen date range (two 'Y-m-d' strings from
	 * the "Generate" form) into the inclusive datetime bounds the generation
	 * queries use: the start floored to 00:00:00 and the end raised to 23:59:59
	 * (so a single-day range still covers the whole day).
	 *
	 * Returns ['start'=>string, 'end'=>string, 'error'=>string]; on any problem
	 * 'error' carries a human message and start/end are ''. Pure.
	 */
	function faq_suggestion_date_range($start, $end)
	{
		$s = faq_suggestion_valid_date($start);
		$e = faq_suggestion_valid_date($end);
		if ($s === '' || $e === '') {
			return array('start' => '', 'end' => '', 'error' => 'Please choose a valid start and end date.');
		}
		if ($s > $e) {
			return array('start' => '', 'end' => '', 'error' => 'The start date must be on or before the end date.');
		}
		return array('start' => $s . ' 00:00:00', 'end' => $e . ' 23:59:59', 'error' => '');
	}
}

if (!function_exists('faq_suggestion_valid_date')) {
	/**
	 * Return the 'Y-m-d' string when $raw is a real calendar date in that format,
	 * or '' otherwise (rejects '2026-02-30', bad formats, blanks). Pure.
	 */
	function faq_suggestion_valid_date($raw)
	{
		$raw = trim((string) $raw);
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
			return '';
		}
		if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
			return '';
		}
		return $raw;
	}
}

if (!function_exists('faq_suggestion_phone_key')) {
	/**
	 * The last-9-digits key used to match a typed mobile number against stored
	 * conversations — the same normalisation guest_contact_normalize_key() uses
	 * for the chat_history_files.dedup_key and enough of the tail to match a GHL
	 * from_number / to_number regardless of country-code formatting. Returns ''
	 * when there are no digits (no mobile filter). Pure.
	 */
	function faq_suggestion_phone_key($raw)
	{
		$digits = preg_replace('/\D+/', '', (string) $raw);
		if ($digits === '' || $digits === null) {
			return '';
		}
		return strlen($digits) <= 9 ? $digits : substr($digits, -9);
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

if (!function_exists('faq_suggestion_existing_block')) {
	/**
	 * Render the FAQs that already exist into a compact bullet list for the
	 * prompt, so the model can compare against them and skip anything already
	 * covered (a semantic dedupe the title-only post-filter can't do — it catches
	 * a reworded duplicate before it's ever proposed). $existing_faqs is a list of
	 * ['title'=>string, 'questions'=>[string,...]]; each becomes
	 *   "- <title> (<q1>; <q2>; …)".
	 * Capped at $max entries so a large FAQ corpus can't blow the prompt up.
	 * Returns '' when there is nothing to show. Pure.
	 */
	function faq_suggestion_existing_block($existing_faqs, $max = 300)
	{
		$max   = (int) $max;
		$lines = array();
		foreach ((array) $existing_faqs as $f) {
			$title = trim((string) (isset($f['title']) ? $f['title'] : ''));
			$qs    = isset($f['questions']) && is_array($f['questions']) ? $f['questions'] : array();
			$clean = array();
			foreach ($qs as $q) {
				$q = trim((string) $q);
				if ($q !== '') {
					$clean[] = $q;
				}
			}
			if ($title === '' && empty($clean)) {
				continue;
			}
			$label = $title !== '' ? $title : $clean[0];
			$line  = '- ' . $label;
			if (!empty($clean)) {
				$line .= ' (' . implode('; ', $clean) . ')';
			}
			$lines[] = $line;
			if ($max > 0 && count($lines) >= $max) {
				break;
			}
		}
		return implode("\n", $lines);
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
	 * $existing_faqs (['title'=>, 'questions'=>[]] list) are the FAQs that already
	 * exist — injected so the model skips duplicates up front (see
	 * faq_suggestion_existing_block).
	 */
	function faq_suggestion_build_prompt($transcript, $destination_names = array(), $existing_faqs = array())
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
			"You are a knowledge analyst for a Malaysian tour agency. " .
			"You read recent WhatsApp / CRM conversations between customers and sales agents " .
			"and distil them into reusable FAQ entries. " .
			"Be EXHAUSTIVE: list every distinct question or reusable piece of knowledge you can extract from the chats — " .
			"any topic a FAQ could capture (e.g. pricing & deposits, payment, booking / cancellation / refund process, " .
			"what's included, visa & documents, flights & logistics, itinerary specifics, and any niche or one-off point). " .
			"Do NOT limit yourself to the most common questions; include the less frequent and edge-case ones too. " .
			"Group near-identical questions together and write a clear, generic answer based on how the agents actually replied. " .
			"For EACH FAQ also give a short 'reason' (one sentence) noting where it came up or why it is useful. " .
			"Roughly ORDER the suggestions with the more broadly useful ones first, but still list everything. " .
			"Compare every candidate against the EXISTING FAQs listed below and do NOT propose one that is already covered — " .
			"skip it even if you would word the question differently; only return genuinely NEW questions. " .
			"Never include a specific customer's name, phone number, a price quoted to one person, or any other private data. " .
			"Answer ONLY with a JSON object.";

		$existing_block = faq_suggestion_existing_block($existing_faqs);
		$existing_line  = $existing_block === '' ? '' :
			"EXISTING FAQs — these are ALREADY answered, so do NOT propose any FAQ already covered here " .
			"(skip it even if worded differently); only return questions NOT in this list:\n" .
			$existing_block . "\n\n";

		$input =
			"Return json with this exact shape:\n" .
			"{\"suggestions\":[{" .
			"\"title\":\"short FAQ title\"," .
			"\"reason\":\"one sentence: where this came up or why it is useful\"," .
			"\"destinations\":[\"zero or more of the allowed destination names\"]," .
			"\"items\":[{\"q\":\"the question\",\"a\":\"a clear reusable answer\"}]" .
			"}]}\n\n" .
			"List every FAQ you can extract; put the more broadly useful ones first.\n\n" .
			"Allowed destinations (copy names verbatim, or leave the array empty when the FAQ is not destination-specific): " .
			$dest_line . "\n\n" .
			$existing_line .
			"Conversations:\n" . (string) $transcript;

		return array('instructions' => $instructions, 'input' => $input);
	}
}

if (!function_exists('faq_suggestion_build_file_prompt')) {
	/**
	 * Build the OpenAI Responses API instructions + user prelude text for mining
	 * FAQs from an UPLOADED DOCUMENT (a PDF brochure / itinerary / price sheet or
	 * a screenshot). The caller appends the file itself as an input_file /
	 * input_image content part. Same JSON contract as
	 * faq_suggestion_build_prompt() so faq_suggestion_parse_response() handles
	 * both — the literal word "json" appears so Responses json_object mode is
	 * satisfied. $destination_names is the allowed destination vocabulary.
	 * $existing_faqs (['title'=>, 'questions'=>[]] list) are the FAQs that already
	 * exist — injected so the model skips duplicates up front.
	 */
	function faq_suggestion_build_file_prompt($destination_names = array(), $existing_faqs = array())
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
			"You are a knowledge analyst for a Malaysian tour agency. " .
			"You read an uploaded document (a tour brochure, itinerary, price sheet, or a screenshot of one) " .
			"and distil it into reusable FAQ entries. " .
			"Be EXHAUSTIVE: list every distinct question or reusable piece of knowledge the document supports — " .
			"any topic a FAQ could capture (e.g. pricing & deposits, payment, booking / cancellation / refund process, " .
			"what's included, visa & documents, flights & logistics, itinerary specifics, and any niche or one-off detail). " .
			"Do NOT limit yourself to the most common questions; include the less frequent and edge-case ones too. " .
			"Write a clear, generic answer grounded in the document's contents. " .
			"For EACH FAQ also give a short 'reason' (one sentence) noting where it came from or why it is useful. " .
			"Roughly ORDER the suggestions with the more broadly useful ones first, but still list everything. " .
			"Compare every candidate against the EXISTING FAQs listed below and do NOT propose one that is already covered — " .
			"skip it even if worded differently; only return genuinely NEW questions. " .
			"Never invent facts not supported by the document, and never include a specific customer's private data. " .
			"Answer ONLY with a JSON object.";

		$existing_block = faq_suggestion_existing_block($existing_faqs);
		$existing_line  = $existing_block === '' ? '' :
			"EXISTING FAQs — these are ALREADY answered, so do NOT propose any FAQ already covered here " .
			"(skip it even if worded differently); only return questions NOT in this list:\n" .
			$existing_block . "\n\n";

		$input =
			"Return json with this exact shape:\n" .
			"{\"suggestions\":[{" .
			"\"title\":\"short FAQ title\"," .
			"\"reason\":\"one sentence: where this came from or why it is useful\"," .
			"\"destinations\":[\"zero or more of the allowed destination names\"]," .
			"\"items\":[{\"q\":\"the question\",\"a\":\"a clear reusable answer\"}]" .
			"}]}\n\n" .
			"Allowed destinations (copy names verbatim, or leave the array empty when the FAQ is not destination-specific): " .
			$dest_line . "\n\n" .
			$existing_line .
			"Read the attached document and extract the FAQs now.";

		return array('instructions' => $instructions, 'input' => $input);
	}
}

if (!function_exists('faq_suggestion_logs_to_prune')) {
	/**
	 * Which worker log (.out) files to delete when keeping only the last
	 * $keep_days days. $files is a list of ['path'=>string, 'mtime'=>int]; a file
	 * is pruned when its mtime is older than $keep_days*86400 before $now_ts.
	 * $keep_days is floored to >= 1 so a bad config can't wipe everything. Pure.
	 */
	function faq_suggestion_logs_to_prune($files, $now_ts, $keep_days = 3)
	{
		$keep_days = (int) $keep_days;
		if ($keep_days < 1) {
			$keep_days = 1;
		}
		$cutoff = (int) $now_ts - $keep_days * 86400;
		$out = array();
		foreach ((array) $files as $f) {
			$path  = isset($f['path']) ? (string) $f['path'] : '';
			$mtime = isset($f['mtime']) ? (int) $f['mtime'] : 0;
			if ($path !== '' && $mtime < $cutoff) {
				$out[] = $path;
			}
		}
		return $out;
	}
}

if (!function_exists('faq_suggestion_run_scope')) {
	/**
	 * A short human label describing what a generation run covered, for the runs
	 * listing / detail header. For a PDF run it's the uploaded file name; for a
	 * chats run it's the date range and (optionally) the mobile number filter.
	 * Accepts the run as an array or object. Pure.
	 */
	function faq_suggestion_run_scope($run)
	{
		$get = function ($k) use ($run) {
			if (is_array($run)) {
				return isset($run[$k]) ? $run[$k] : '';
			}
			return isset($run->$k) ? $run->$k : '';
		};

		$source = strtolower(trim((string) $get('Source')));
		if ($source === 'pdf') {
			$file = trim((string) $get('FileName'));
			return $file !== '' ? $file : 'Uploaded PDF';
		}

		$start = faq_suggestion_fmt_date($get('StartDate'));
		$end   = faq_suggestion_fmt_date($get('EndDate'));
		$parts = array();
		if ($start !== '' && $end !== '') {
			$parts[] = $start . ' – ' . $end;
		}
		$mobile = trim((string) $get('Mobile'));
		if ($mobile !== '') {
			$parts[] = $mobile;
		}
		return empty($parts) ? 'Recent chats' : implode(' · ', $parts);
	}
}

if (!function_exists('faq_suggestion_fmt_date')) {
	/**
	 * Format a 'Y-m-d' (or 'Y-m-d H:i:s') string as 'j M Y' ("17 Sep 2026"),
	 * passing blanks / unparseable values through as ''. Pure.
	 */
	function faq_suggestion_fmt_date($raw)
	{
		$raw = trim((string) $raw);
		if ($raw === '' || strpos($raw, '0000-00-00') === 0) {
			return '';
		}
		$ts = strtotime($raw);
		return $ts ? date('j M Y', $ts) : '';
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
	 * Safety-net dedupe (the prompt already asks the model to skip existing FAQs;
	 * this catches what slips through). Drop a suggestion when its normalised title
	 * OR any of its normalised questions already exists — matched against
	 * $existing_titles AND $existing_questions (both drawn from FAQs / prior
	 * suggestions), or against something emitted earlier in this same batch. This
	 * is what makes a REWORDED title for an existing question ("Deposit info" vs
	 * the FAQ "Deposit amount", both asking "How much is the deposit?") still get
	 * dropped. Order is preserved. Pure.
	 */
	function faq_suggestion_filter_new($suggestions, $existing_titles = array(), $existing_questions = array())
	{
		$seen = array();
		$remember = function ($text) use (&$seen) {
			$key = faq_suggestion_norm_title($text);
			if ($key !== '') {
				$seen[$key] = true;
			}
		};
		foreach ((array) $existing_titles as $t) {
			$remember($t);
		}
		foreach ((array) $existing_questions as $q) {
			$remember($q);
		}

		$out = array();
		foreach ((array) $suggestions as $s) {
			$title_key = faq_suggestion_norm_title(isset($s['title']) ? $s['title'] : '');
			if ($title_key === '') {
				continue;
			}
			// This suggestion's keys: its title plus each of its questions.
			$keys = array($title_key);
			if (isset($s['items']) && is_array($s['items'])) {
				foreach ($s['items'] as $it) {
					$qk = faq_suggestion_norm_title(isset($it['q']) ? $it['q'] : '');
					if ($qk !== '') {
						$keys[] = $qk;
					}
				}
			}
			$dup = false;
			foreach ($keys as $k) {
				if (isset($seen[$k])) {
					$dup = true;
					break;
				}
			}
			if ($dup) {
				continue;
			}
			foreach ($keys as $k) {
				$seen[$k] = true;
			}
			$out[] = $s;
		}
		return $out;
	}
}

if (!function_exists('faq_suggestion_embed_text')) {
	/**
	 * Build the canonical text used to EMBED one FAQ / candidate for semantic
	 * dedupe. It folds the title AND every question + answer into a single
	 * whitespace-normalised string, so two entries that ask the SAME thing in
	 * different words but share the same answer body still embed close together
	 * (which is exactly the reworded-question duplicate the normalised-title
	 * filter misses). $qas is a list of ['q'=>, 'a'=>] pairs. Pure.
	 */
	function faq_suggestion_embed_text($title, $qas)
	{
		$parts = array();
		$title = trim((string) $title);
		if ($title !== '') {
			$parts[] = $title;
		}
		foreach ((array) $qas as $qa) {
			$q = trim((string) (isset($qa['q']) ? $qa['q'] : ''));
			$a = trim((string) (isset($qa['a']) ? $qa['a'] : ''));
			$line = trim($q . ' ' . $a);
			if ($line !== '') {
				$parts[] = $line;
			}
		}
		$text = preg_replace('/\s+/', ' ', implode(' ', $parts));
		return trim((string) $text);
	}
}

if (!function_exists('faq_suggestion_cosine')) {
	/**
	 * Cosine similarity of two equal-length numeric vectors, in [-1, 1] (in
	 * practice [0, 1] for embedding vectors). Returns 0.0 for empty, mismatched
	 * length, non-array, or zero-magnitude inputs so a bad vector never counts
	 * as a match. Pure.
	 */
	function faq_suggestion_cosine($a, $b)
	{
		if (!is_array($a) || !is_array($b)) {
			return 0.0;
		}
		$a = array_values($a);
		$b = array_values($b);
		$n = count($a);
		if ($n === 0 || $n !== count($b)) {
			return 0.0;
		}
		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$x = (float) $a[$i];
			$y = (float) $b[$i];
			$dot += $x * $y;
			$na  += $x * $x;
			$nb  += $y * $y;
		}
		if ($na <= 0 || $nb <= 0) {
			return 0.0;
		}
		return $dot / (sqrt($na) * sqrt($nb));
	}
}

if (!function_exists('faq_suggestion_filter_semantic')) {
	/**
	 * Semantic-dedupe safety net (the layer the normalised-title/question filter
	 * can't do). Drop a candidate whose embedding vector is cosine-similar at or
	 * above $threshold to ANY existing FAQ's vector, OR to a candidate kept
	 * earlier in this same batch (in-batch dedupe). $sug_vectors is indexed
	 * parallel to $suggestions; $existing_vectors is the vectors of the FAQs that
	 * already exist. Order is preserved.
	 *
	 * Fail-open by design: a $threshold outside (0, 1) disables the pass (returns
	 * everything), and a candidate with no usable vector is KEPT — a missing
	 * embedding must never silently drop a real suggestion; the title/question
	 * filter already ran before this. Pure.
	 */
	function faq_suggestion_filter_semantic($suggestions, $sug_vectors, $existing_vectors, $threshold)
	{
		$suggestions = array_values((array) $suggestions);
		$threshold   = (float) $threshold;
		if ($threshold <= 0 || $threshold >= 1) {
			return $suggestions;
		}
		$existing_vectors = array_values((array) $existing_vectors);

		$kept         = array();
		$kept_vectors = array();
		foreach ($suggestions as $i => $s) {
			$vec = isset($sug_vectors[$i]) ? $sug_vectors[$i] : null;
			if (!is_array($vec) || empty($vec)) {
				$kept[] = $s; // can't judge -> keep
				continue;
			}
			$dup = false;
			foreach ($existing_vectors as $ev) {
				if (is_array($ev) && faq_suggestion_cosine($vec, $ev) >= $threshold) {
					$dup = true;
					break;
				}
			}
			if (!$dup) {
				foreach ($kept_vectors as $kv) {
					if (faq_suggestion_cosine($vec, $kv) >= $threshold) {
						$dup = true;
						break;
					}
				}
			}
			if ($dup) {
				continue;
			}
			$kept[]         = $s;
			$kept_vectors[] = $vec;
		}
		return $kept;
	}
}
