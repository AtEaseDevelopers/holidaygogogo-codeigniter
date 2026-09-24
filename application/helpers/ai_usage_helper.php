<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ai_usage_helper — pure transforms behind the owner-only "AI Cost & Usage" page
 * (see controllers/Ai_Usage.php). Every AI feature (Competitor Analysis, Customer
 * Analysis, FAQ Search, FAQ Suggestions and their embeddings) writes one row per
 * OpenAI call to the ai_usage_log table; this page reads those rows back and rolls
 * them up into totals and per-dimension breakdowns.
 *
 * All functions here are side-effect free and operate on plain associative-array
 * rows (Ai_Usage_Model returns result_array()), so they are unit-tested in
 * tests/helpers/AiUsageHelperTest.php without a database.
 *
 * A "row" is expected to carry at least:
 *   input_tokens, output_tokens, cost_usd, feature, model, created_at
 * plus optional created_by_name for the "by user" breakdown.
 */

if ( ! function_exists('ai_usage_num')) {
	/** Coerce a possibly-missing array value to a number (0 when absent/non-numeric). */
	function ai_usage_num($row, $key)
	{
		if ( ! is_array($row) || ! isset($row[$key]) || ! is_numeric($row[$key])) {
			return 0;
		}
		return $row[$key] + 0; // int or float
	}
}

if ( ! function_exists('ai_usage_summary')) {
	/**
	 * Totals across a set of log rows: number of calls, token counts and USD cost.
	 * Pure. total_tokens is derived (input + output) so it stays consistent even if
	 * a row stored a stale total.
	 */
	function ai_usage_summary($rows)
	{
		$out = array(
			'calls'         => 0,
			'input_tokens'  => 0,
			'output_tokens' => 0,
			'total_tokens'  => 0,
			'cost_usd'      => 0.0,
		);
		if ( ! is_array($rows)) {
			return $out;
		}
		foreach ($rows as $row) {
			$in  = (int) ai_usage_num($row, 'input_tokens');
			$o   = (int) ai_usage_num($row, 'output_tokens');
			$out['calls']++;
			$out['input_tokens']  += $in;
			$out['output_tokens'] += $o;
			$out['total_tokens']  += $in + $o;
			$out['cost_usd']      += (float) ai_usage_num($row, 'cost_usd');
		}
		$out['cost_usd'] = round($out['cost_usd'], 6);
		return $out;
	}
}

if ( ! function_exists('ai_usage_group_key')) {
	/**
	 * The label a row falls under for a given breakdown dimension. 'month' is
	 * derived from created_at (YYYY-MM); any other $field reads that column. A
	 * blank / missing value becomes a readable placeholder. Pure.
	 */
	function ai_usage_group_key($row, $field)
	{
		if ($field === 'month') {
			$dt = is_array($row) && isset($row['created_at']) ? (string) $row['created_at'] : '';
			return strlen($dt) >= 7 ? substr($dt, 0, 7) : 'Unknown';
		}
		$val = is_array($row) && isset($row[$field]) ? trim((string) $row[$field]) : '';
		return $val === '' ? '(none)' : $val;
	}
}

if ( ! function_exists('ai_usage_group')) {
	/**
	 * Roll rows up by one dimension ('feature', 'model', 'created_by_name' or the
	 * special 'month'). Returns a list of groups, each carrying the same shape as
	 * ai_usage_summary() plus a 'key'. Sorted by cost desc, then calls desc, then
	 * key asc — except 'month', which is sorted chronologically ascending so it
	 * reads as a timeline. Pure.
	 */
	function ai_usage_group($rows, $field)
	{
		$groups = array();
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$key = ai_usage_group_key($row, $field);
				if ( ! isset($groups[$key])) {
					$groups[$key] = array(
						'key'           => $key,
						'calls'         => 0,
						'input_tokens'  => 0,
						'output_tokens' => 0,
						'total_tokens'  => 0,
						'cost_usd'      => 0.0,
					);
				}
				$in = (int) ai_usage_num($row, 'input_tokens');
				$o  = (int) ai_usage_num($row, 'output_tokens');
				$groups[$key]['calls']++;
				$groups[$key]['input_tokens']  += $in;
				$groups[$key]['output_tokens'] += $o;
				$groups[$key]['total_tokens']  += $in + $o;
				$groups[$key]['cost_usd']      += (float) ai_usage_num($row, 'cost_usd');
			}
		}
		foreach ($groups as $k => $g) {
			$groups[$k]['cost_usd'] = round($g['cost_usd'], 6);
		}
		$list = array_values($groups);
		if ($field === 'month') {
			usort($list, function ($a, $b) {
				return strcmp($a['key'], $b['key']);
			});
		} else {
			usort($list, function ($a, $b) {
				if ($a['cost_usd'] != $b['cost_usd']) {
					return ($a['cost_usd'] < $b['cost_usd']) ? 1 : -1;
				}
				if ($a['calls'] != $b['calls']) {
					return ($b['calls'] - $a['calls']);
				}
				return strcmp($a['key'], $b['key']);
			});
		}
		return $list;
	}
}

if ( ! function_exists('ai_usage_fmt_usd')) {
	/** Format a USD amount for display, e.g. 0.01234 -> "$0.0123". Pure. */
	function ai_usage_fmt_usd($v, $dp = 4)
	{
		return '$' . number_format((float) $v, (int) $dp);
	}
}

if ( ! function_exists('ai_usage_fmt_int')) {
	/** Thousands-separated integer, e.g. 12345 -> "12,345". Pure. */
	function ai_usage_fmt_int($v)
	{
		return number_format((int) $v);
	}
}
