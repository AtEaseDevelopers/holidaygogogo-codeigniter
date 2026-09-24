<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * FAQ AI-search helpers — pure, DB-free transforms for the "AI answer" box on
 * the internal FAQ Library page (/Faq/Internal). A staff member types a customer
 * question; the model reads the whole FAQ library (assembled here) and writes a
 * ready-to-send answer grounded ONLY in those FAQs.
 *
 * Split out so the prompt building / corpus assembly / response parsing are unit
 * tested without a DB or network (see tests/helpers/FaqSearchHelperTest.php);
 * the network call lives in libraries/FaqSearchService.php.
 */

if (!function_exists('faq_search_valid_question')) {
	/**
	 * Normalise a typed question: trim and collapse inner whitespace. Returns ''
	 * when it is empty or too short to be a real question (< 3 visible chars), so
	 * the caller can reject it before spending an API call.
	 */
	function faq_search_valid_question($raw)
	{
		$q = trim((string) $raw);
		$q = preg_replace('/\s+/u', ' ', $q);
		if ($q === null) {
			$q = '';
		}
		if (mb_strlen($q) < 3) {
			return '';
		}
		return $q;
	}
}

if (!function_exists('faq_search_build_corpus')) {
	/**
	 * Flatten the FAQ library into a compact, numbered text corpus the model reads
	 * to answer. $faqs is a list of arrays:
	 *   ['title' => string, 'destinations' => [names], 'items' => [['q'=>, 'a'=>], ...]]
	 * Each FAQ becomes a "[n] Title (dest, dest)" block followed by its Q/A pairs.
	 * FAQs with no answerable content are skipped. The whole thing is capped to
	 * $max_chars (head-kept: the earliest FAQs win) so a huge library still fits a
	 * single request. Pure — no DB, no objects.
	 */
	function faq_search_build_corpus($faqs, $max_chars = 120000)
	{
		$max_chars = (int) $max_chars;
		if ($max_chars <= 0) {
			$max_chars = 120000;
		}
		$blocks = array();
		$n = 0;
		foreach ((array) $faqs as $faq) {
			if (!is_array($faq)) {
				continue;
			}
			$title = trim((string) (isset($faq['title']) ? $faq['title'] : ''));
			$dests = array();
			if (isset($faq['destinations']) && is_array($faq['destinations'])) {
				foreach ($faq['destinations'] as $d) {
					$d = trim((string) $d);
					if ($d !== '') {
						$dests[] = $d;
					}
				}
			}
			$lines = array();
			if (isset($faq['items']) && is_array($faq['items'])) {
				foreach ($faq['items'] as $item) {
					if (!is_array($item)) {
						continue;
					}
					$q = trim((string) (isset($item['q']) ? $item['q'] : ''));
					$a = trim((string) (isset($item['a']) ? $item['a'] : ''));
					if ($q === '' && $a === '') {
						continue;
					}
					$lines[] = 'Q: ' . ($q === '' ? '(general)' : $q);
					$lines[] = 'A: ' . ($a === '' ? '(no answer recorded)' : $a);
				}
			}
			// A title with no answerable Q/A tells the model nothing, so skip it.
			if (empty($lines) && $title === '') {
				continue;
			}
			if (empty($lines)) {
				continue;
			}
			$n++;
			$head = '[' . $n . '] ' . ($title === '' ? '(untitled)' : $title);
			if (!empty($dests)) {
				$head .= ' (' . implode(', ', $dests) . ')';
			}
			$blocks[] = $head . "\n" . implode("\n", $lines);
		}
		$corpus = implode("\n\n", $blocks);
		if (strlen($corpus) > $max_chars) {
			$corpus = rtrim(substr($corpus, 0, $max_chars));
		}
		return $corpus;
	}
}

if (!function_exists('faq_search_build_prompt')) {
	/**
	 * Build the OpenAI Responses API instructions + input for answering one
	 * question from the FAQ corpus. The model must reply with a JSON OBJECT (the
	 * literal word "json" appears so Responses json_object mode is satisfied)
	 * carrying a ready-to-send answer, a found flag, and the source FAQ titles it
	 * relied on. The answer must be grounded ONLY in the corpus so agents never
	 * paste something invented.
	 */
	function faq_search_build_prompt($question, $corpus)
	{
		$question = trim((string) $question);
		$corpus   = trim((string) $corpus);

		$instructions =
			"You are a customer-service assistant for a Malaysian tour agency. " .
			"A sales agent gives you a customer's question and the agency's internal FAQ library. " .
			"Write a reply the agent can copy and paste straight to the customer. " .
			"Ground your answer ONLY in the FAQ library provided — never invent prices, dates, policies, or details that are not there. " .
			"If the library does not cover the question, set found=false and say politely that you need to check and will get back to them; do NOT guess. " .
			"Write in the same language as the customer's question (English or Malay); keep it warm, clear, and concise. " .
			"Do not mention 'the FAQ', 'the library', or that you are an AI — just answer as the agency would. " .
			"Answer ONLY with a JSON object.";

		$corpus_line = $corpus === '' ? '(the FAQ library is empty)' : $corpus;

		$input =
			"Return json with this exact shape:\n" .
			"{\"found\":true or false," .
			"\"answer\":\"the reply to send the customer, ready to copy-paste\"," .
			"\"sources\":[\"titles of the FAQ entries you used\"]}\n\n" .
			"Customer question:\n" . $question . "\n\n" .
			"FAQ library:\n" . $corpus_line;

		return array('instructions' => $instructions, 'input' => $input);
	}
}

if (!function_exists('faq_search_parse_response')) {
	/**
	 * Normalise the model's JSON reply into a clean result the controller returns
	 * to the browser. $decoded is the json_decode()d object (associative array).
	 * Returns:
	 *   ['found' => bool, 'answer' => string, 'sources' => [string, ...]]
	 * A missing/invalid payload yields found=false and an empty answer, so the UI
	 * can show a graceful "nothing found" state rather than erroring.
	 */
	function faq_search_parse_response($decoded)
	{
		$answer  = '';
		$found   = false;
		$sources = array();

		if (is_array($decoded)) {
			if (isset($decoded['answer'])) {
				$answer = trim((string) $decoded['answer']);
			}
			if (isset($decoded['found'])) {
				$found = filter_var($decoded['found'], FILTER_VALIDATE_BOOLEAN);
			} else {
				// No explicit flag: treat a non-empty answer as found.
				$found = ($answer !== '');
			}
			if (isset($decoded['sources']) && is_array($decoded['sources'])) {
				$seen = array();
				foreach ($decoded['sources'] as $s) {
					$s = trim((string) $s);
					if ($s === '') {
						continue;
					}
					$key = strtolower($s);
					if (isset($seen[$key])) {
						continue;
					}
					$seen[$key] = true;
					$sources[] = $s;
				}
			}
		}

		// An empty answer can never be "found", whatever the model claimed.
		if ($answer === '') {
			$found = false;
		}

		return array('found' => $found, 'answer' => $answer, 'sources' => $sources);
	}
}
