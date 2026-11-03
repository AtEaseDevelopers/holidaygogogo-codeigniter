<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pure builder for the Costing Package listing's free-text "Search" box.
 *
 * Two behaviours it guarantees:
 *   1. BROAD MATCH, not exact: the search string is split on whitespace into
 *      tokens. Each token must appear (substring, LIKE '%token%') in at least
 *      one of the searched columns — tokens are ANDed, columns are ORed. So
 *      "beijing anthony" matches a row whose name contains "Beijing" AND whose
 *      customer is "Mr. Anthony", regardless of word order, without needing the
 *      two words to sit next to each other.
 *   2. Searches across ALL the meaningful columns it is given (package name,
 *      tour code, description, customer name/contact/email, sales person name),
 *      not just the package name — so a customer like "Mr. Anthony" is findable
 *      from the single Search box.
 *
 * Returns the inner SQL clause WITHOUT a leading WHERE (e.g.
 * "(cp.name LIKE '%a%' OR cp.customer_name LIKE '%a%') AND (...)"), or '' when
 * there is nothing to search. The caller supplies an $escaper callback
 * (typically $this->db->escape_like_str) so this stays DB-free and testable.
 *
 * @param string   $search  raw search box value
 * @param string[] $columns qualified column expressions to match against
 * @param callable $escaper fn(string): string — escapes a LIKE literal
 * @return string inner clause, or '' when empty
 */
function costing_package_search_clause($search, array $columns, callable $escaper)
{
    $search = trim((string) $search);
    if ($search === '' || empty($columns)) {
        return '';
    }

    $tokens = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($tokens)) {
        return '';
    }

    $and_parts = array();
    foreach ($tokens as $token) {
        $like = (string) $escaper($token);
        $or_parts = array();
        foreach ($columns as $column) {
            $or_parts[] = $column . " LIKE '%" . $like . "%'";
        }
        $and_parts[] = '(' . implode(' OR ', $or_parts) . ')';
    }

    return implode(' AND ', $and_parts);
}
