<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once __DIR__ . '/faq_extraction_helper.php';
require_once __DIR__ . '/faq_workspace_helper.php';
require_once __DIR__ . '/faq_knowledge_helper.php';

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

if (!function_exists('faq_suggestion_json_input')) {
	/** JSON mode requires an explicit JSON instruction in the input messages. */
	function faq_suggestion_json_input($input)
	{
		$messages = is_string($input)
			? array(array('role' => 'user', 'content' => $input))
			: $input;
		// The separate Responses instructions field does not satisfy this check.
		array_unshift($messages, array(
			'role' => 'system',
			'content' => 'Return only a JSON object following the supplied instructions.',
		));
		return $messages;
	}
}

if (!function_exists('faq_suggestion_memory_limit')) {
	/**
	 * Validate a PHP memory_limit value (from .env FAQ_SUGGESTION_MEMORY_LIMIT)
	 * used while processing an uploaded PDF — reading it, base64-encoding it, and
	 * embedding it in the JSON request multiplies the file size several times, so
	 * a 20 MB PDF needs well over the default web limit. Accepts a plain byte
	 * count, a number with a K/M/G suffix, or -1 (unlimited); anything blank or
	 * malformed falls back to $default ('1024M'). Pure so it unit-tests without a
	 * running PHP config.
	 */
	function faq_suggestion_memory_limit($raw, $default = '1024M')
	{
		$v = strtoupper(trim((string) $raw));
		if ($v === '-1' || preg_match('/^\d+[KMG]?$/', $v)) {
			return $v;
		}
		return $default;
	}
}

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
		$built = faq_suggestion_transcript_with_sources($ghl_rows, $wa_messages, $max_chars, false);
		return $built['text'];
	}
}

if (!function_exists('faq_suggestion_transcript_with_sources')) {
	/**
	 * As faq_suggestion_transcript(), but also assigns each retained line a
	 * reference (S1, S2, ...), returned as a server-side source map. The visible
	 * references let the model cite evidence without ever inventing database IDs.
	 */
	function faq_suggestion_transcript_with_sources($ghl_rows, $wa_messages, $max_chars = 0, $include_refs = true)
	{
		// 1) Unify into an ordered list of ['who'=>, 'body'=>], dropping blanks/system.
		$msgs = array();
		foreach ((array) $ghl_rows as $r) {
			$body = trim((string) (isset($r['body']) ? $r['body'] : ''));
			if ($body === '') {
				continue;
			}
			$dir = strtolower((string) (isset($r['direction']) ? $r['direction'] : ''));
			$msgs[] = array('who' => ($dir === 'inbound') ? 'Customer' : 'Agent', 'body' => $body,
				'source_type' => 'ghl_message', 'ghl_message_id' => isset($r['id']) ? (int) $r['id'] : null,
				'chat_file_id' => null, 'message_index' => null, 'conversation_key'=>isset($r['conversation_id'])?'ghl:'.$r['conversation_id']:'');
		}
		foreach ((array) $wa_messages as $m) {
			if (!empty($m['system'])) {
				continue;
			}
			$body = trim((string) (isset($m['body']) ? $m['body'] : ''));
			if ($body === '') {
				continue;
			}
			$msgs[] = array('who' => !empty($m['outbound']) ? 'Agent' : 'Customer', 'body' => $body,
				'source_type' => isset($m['source_type']) ? (string) $m['source_type'] : 'whatsapp_history',
				'ghl_message_id' => null, 'chat_file_id' => isset($m['chat_file_id']) ? (int) $m['chat_file_id'] : null,
				'message_index' => isset($m['message_index']) ? (int) $m['message_index'] : null,
				'conversation_key'=>isset($m['conversation_key'])?$m['conversation_key']:(isset($m['chat_file_id'])?'wa:'.$m['chat_file_id']:''));
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
			$ck=$m['conversation_key'].'|'.$k;
			$counts[$ck] = (isset($counts[$ck]) ? $counts[$ck] : 0) + 1;
		}

		$seen  = array();
		$lines = array();
		$sources = array();
		$number = 0;
		$conversations=array();
		foreach ($kept as $m) {
			$k  = faq_suggestion_norm_msg($m['body']);
			$ck=$m['conversation_key'].'|'.$k;
			$sk = $m['who'] . '|' . $ck;
			if ($k !== '' && isset($seen[$sk])) {
				continue; // duplicate of an already-emitted line (this side)
			}
			if ($k !== '') {
				$seen[$sk] = true;
			}
			$number++;
			$ref = 'S' . $number;
			$line = ($include_refs ? '[' . $ref . '] ' : '') . $m['who'] . ': ' . $m['body'];
			if ($m['who'] === 'Customer' && $k !== '' && isset($counts[$ck]) && $counts[$ck] > 1) {
				$line .= ' (asked ' . $counts[$ck] . ' times)';
			}
			if ($include_refs && $m['conversation_key']!=='') {
				if (!isset($conversations[$m['conversation_key']])) { $conversations[$m['conversation_key']]='C'.(count($conversations)+1); }
				$line.=' [conversation '.$conversations[$m['conversation_key']].']';
			}
			$lines[] = $line;
			$sources[$ref] = array(
				'source_type' => $m['source_type'], 'ghl_message_id' => $m['ghl_message_id'],
				'chat_file_id' => $m['chat_file_id'], 'message_index' => $m['message_index'],
				'excerpt' => $m['who'] . ': ' . $m['body'],
				'conversation_key'=>$m['conversation_key'],
			);
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
		// If the tail cap removed lines, only retain sources that remain visible.
		if ($include_refs && $text !== '') {
			$visible = array();
			foreach ($sources as $ref => $source) {
				if (strpos($text, '[' . $ref . '] ') !== false) { $visible[$ref] = $source; }
			}
			$sources = $visible;
		}
		return array('text' => $text, 'sources' => $sources);
	}
}

if (!function_exists('faq_suggestion_existing_block')) {
	/**
	 * Render the FAQs that already exist into a compact bullet list for the
	 * prompt, so the model can compare against them and skip questions already
	 * covered. $existing_faqs is a list of
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
    /** Extract candidates from a labelled conversation transcript. */
    function faq_suggestion_build_prompt($transcript, $destination_names = array(), $existing_faqs = array(), $max = 0, $knowledge=array(), $packages=array())
    {
        return array('instructions'=>faq_suggestion_extraction_instructions($max),
            'input'=>faq_suggestion_candidate_input($destination_names,$existing_faqs,$knowledge,$packages).
                ((int)$max>0?"\nReturn at most ".(int)$max." FAQs; keep well-supported tour-specific FAQs as well as agency-wide ones.":"\nList every FAQ you can extract; retain supported tour-specific details.").
                "\nConversations (each [S#] is an evidence reference):\n".(string)$transcript);
    }
}

if (!function_exists('faq_suggestion_build_file_prompt')) {
    /** Extract candidates from the attached document. */
    function faq_suggestion_build_file_prompt($destination_names = array(), $existing_faqs = array(), $max = 0, $knowledge=array(), $packages=array())
    {
        return array('instructions'=>faq_suggestion_extraction_instructions($max,true),
            'input'=>faq_suggestion_candidate_input($destination_names,$existing_faqs,$knowledge,$packages).
                "\nRead the attached document, referenced as D1. Use empty source_refs when no [S#] references are supplied; answer_refs may include D1.");
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
		if ($source === 'reevaluate') { return 'Re-evaluation · '.trim((string)$get('FileName')); }
		if ($source === 'pdf') {
			$file = trim((string) $get('FileName'));
			return $file !== '' ? $file : 'Uploaded PDF';
		}
		if ($source === 'chatfile') {
			$file = trim((string) $get('FileName'));
			return $file !== '' ? $file : 'Uploaded chat file';
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

if (!function_exists('faq_suggestion_input_preview')) {
	/**
	 * A short, single-line preview of the exact text sent to the AI (the
	 * customer/agent transcript for a chats / chat-file run). Collapses all
	 * whitespace to single spaces and caps the length with an ellipsis, for use
	 * as a tooltip / inline hint on the runs listing. Blank in → '' out. Pure.
	 */
	function faq_suggestion_input_preview($text, $max = 200)
	{
		$text = trim(preg_replace('/\s+/u', ' ', (string) $text));
		if ($text === '') {
			return '';
		}
		$max = (int) $max;
		if ($max < 1) {
			$max = 1;
		}
		if (function_exists('mb_strlen')) {
			if (mb_strlen($text, 'UTF-8') <= $max) {
				return $text;
			}
			return rtrim(mb_substr($text, 0, $max, 'UTF-8')) . '…';
		}
		if (strlen($text) <= $max) {
			return $text;
		}
		return rtrim(substr($text, 0, $max)) . '…';
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
	function faq_suggestion_parse_response($decoded, $dest_name_to_id = array(), $max = 30, $strict_review = false)
	{
		if (is_string($decoded)) {
			$decoded = json_decode($decoded, true);
		}
		if (!is_array($decoded)) {
			return array();
		}
		if ($strict_review && (!isset($decoded['suggestions']) || !is_array($decoded['suggestions']))) { return array(); }
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
			$assessed=$strict_review && (array_key_exists('label',$s) || array_key_exists('status',$s));
			if ($assessed) { try { $assessment=faq_suggestion_assessment($s); } catch (Exception $e) { continue; } }
			if ($strict_review && (!isset($s['title']) || !is_string($s['title']) ||
				(isset($s['reason']) && !is_string($s['reason'])))) { continue; }
			$title = trim((string) (isset($s['title']) ? $s['title'] : ''));
			if ($title === '') {
				continue;
			}
			if (strlen($title) > 255) {
				$title = mb_strcut($title, 0, 255, 'UTF-8');
			}

			$reason = trim((string) (isset($s['reason']) ? $s['reason'] : ''));
			if (strlen($reason) > 500) {
				$reason = mb_strcut($reason, 0, 500, 'UTF-8');
			}

			$items = array();
			$raw_items = isset($s['items']) && is_array($s['items']) ? $s['items'] : array();
			foreach ($raw_items as $it) {
				if (!is_array($it)) {
					continue;
				}
				if ($strict_review && (!isset($it['q']) || !is_string($it['q']) ||
					(isset($it['a']) && !is_string($it['a'])))) { continue; }
				$q = trim((string) (isset($it['q']) ? $it['q'] : ''));
				$a = trim((string) (isset($it['a']) ? $it['a'] : ''));
				if ($strict_review && !$assessed) { $a = 'insufficient verified source'; }
				if ($q === '' || (!$assessed && $a === '')) {
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
				if ($strict_review && !is_string($dn)) { continue; }
				$k = strtolower(trim((string) $dn));
				if ($k !== '' && isset($map[$k]) && !in_array($map[$k], $dest_ids, true)) {
					$dest_ids[] = $map[$k];
				}
			}

			$refs = array();
			foreach ((isset($s['source_refs']) && is_array($s['source_refs'])) ? $s['source_refs'] : array() as $ref) {
				if ($strict_review && !is_string($ref)) { continue; }
				$ref = strtoupper(trim((string) $ref));
				if (preg_match('/^S[1-9][0-9]*$/', $ref) && !in_array($ref, $refs, true)) { $refs[] = $ref; }
			}
			$candidate = array('title' => $title, 'reason' => $reason, 'destination_ids' => $dest_ids, 'items' => $items, 'source_refs' => $refs);
			if ($strict_review) {
				if ($assessed) {
					$candidate['assessment']=$assessment;
					$all_refs=array_merge($refs,$assessment['answer_refs']);
					$candidate['source_refs']=array_values(array_unique(array_filter($all_refs,function($ref){return preg_match('/^S[1-9][0-9]*$/D',$ref);} )));
				}
			}
			$out[] = $candidate;
			if ($max > 0 && count($out) >= $max) {
				break;
			}
		}
		return $out;
	}
}
