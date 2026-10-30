<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pure helpers for the Costing package itinerary.
 *
 * Each DAY carries a plain-text title, one rich-text (TinyMCE) HTML block
 * (description) and a multi-select Meal Plan (stored as comma-separated slugs).
 * Notes, Special Remark and Terms & Conditions apply to the WHOLE itinerary (one
 * set per package), not per day, so they live on costing_packages and are
 * handled by the itinerary-level helpers below.
 *
 * Kept free of the CI super-object so the model can reuse them and PHPUnit can
 * drive them directly.
 */

if (!function_exists('costing_itinerary_html_fields')) {
    /**
     * The per-day rich-text columns on costing_itinerary_days, in display order.
     * (Meal plan is a multi-select, handled separately.)
     *
     * @return string[]
     */
    function costing_itinerary_html_fields()
    {
        return array('description');
    }
}

if (!function_exists('costing_meal_plan_options')) {
    /**
     * The selectable Meal Plan options, slug => label, in display order. A day
     * can have several (breakfast + lunch + dinner), so these render as
     * checkboxes and store as a comma-separated slug list.
     *
     * @return array<string,string>
     */
    function costing_meal_plan_options()
    {
        return array(
            'breakfast'           => 'Breakfast',
            'lunch'               => 'Lunch',
            'dinner'              => 'Dinner',
            'tea_break'           => 'Tea Break',
            'supper'              => 'Supper',
            'special_arrangement' => 'Special Arrangement',
        );
    }
}

if (!function_exists('costing_meal_plan_normalize')) {
    /**
     * Normalise a posted Meal Plan selection into a stored slug string. Accepts
     * the checkbox array (['breakfast','dinner']) or an existing comma-separated
     * string. Keeps only valid slugs, de-duplicated, in canonical option order.
     * Returns null when nothing valid is selected.
     *
     * @param array|string|null $selected
     * @return string|null
     */
    function costing_meal_plan_normalize($selected)
    {
        if (is_string($selected)) {
            $selected = explode(',', $selected);
        }
        $picked = array();
        foreach ((array) $selected as $slug) {
            $picked[trim((string) $slug)] = true;
        }

        $out = array();
        foreach (array_keys(costing_meal_plan_options()) as $slug) {
            if (isset($picked[$slug])) {
                $out[] = $slug;
            }
        }

        return empty($out) ? null : implode(',', $out);
    }
}

if (!function_exists('costing_meal_plan_labels')) {
    /**
     * Human-readable Meal Plan labels for display, from a stored slug string.
     * Falls back to the stripped raw text for legacy (pre-select) HTML values so
     * nothing already saved disappears.
     *
     * @param string|null $stored
     * @return string[]
     */
    function costing_meal_plan_labels($stored)
    {
        $options = costing_meal_plan_options();
        $slugs = array_map('trim', explode(',', (string) $stored));

        $labels = array();
        foreach (array_keys($options) as $slug) {
            if (in_array($slug, $slugs, true)) {
                $labels[] = $options[$slug];
            }
        }

        if (empty($labels)) {
            $legacy = trim(strip_tags((string) $stored));
            if ($legacy !== '') {
                $labels[] = $legacy;
            }
        }

        return $labels;
    }
}

if (!function_exists('costing_itinerary_section_meta')) {
    /**
     * The itinerary-level rich-text sections, in display order. Each applies to
     * the WHOLE itinerary (one set per package) and is shown once beneath the
     * day-by-day plan on the Quotation PDF.
     *
     * Keyed by the short section key (used everywhere the sections travel as an
     * array — itinerary_meta, the posted level fields, the import payload). Each
     * entry is [label, column, post_field]:
     *   - label      human heading (wizard label + PDF section heading)
     *   - column     the costing_packages column it is stored in
     *   - post_field the form field name / Excel-import record key
     *
     * @return array<string,array{0:string,1:string,2:string}>
     */
    function costing_itinerary_section_meta()
    {
        // The storage columns are the ORIGINAL 4-section columns on
        // costing_packages (created by 20260909_Add_Costing_Quotation_Feedback,
        // terms by the earlier Move migration) — reused here rather than adding
        // new ones. post_field is the stable short form-field / Excel-record key.
        return array(
            'include'         => array('Include', 'itinerary_includes', 'itinerary_include'),
            'exclude'         => array('Exclude', 'itinerary_excludes', 'itinerary_exclude'),
            'important_notes' => array('Important Notes', 'itinerary_important_notes', 'itinerary_important_notes'),
            'terms'           => array('Terms & Conditions', 'itinerary_terms_and_conditions', 'itinerary_terms'),
        );
    }
}

if (!function_exists('costing_itinerary_level_fields')) {
    /**
     * The itinerary-level rich-text columns on costing_packages, in display
     * order. Shared once beneath the whole itinerary rather than per day.
     *
     * @return string[]
     */
    function costing_itinerary_level_fields()
    {
        $columns = array();
        foreach (costing_itinerary_section_meta() as $meta) {
            $columns[] = $meta[1];
        }
        return $columns;
    }
}

if (!function_exists('costing_itinerary_section_defaults')) {
    /**
     * The standard boilerplate each section opens with for a BRAND-NEW package.
     * Body only (bullet lists) — the section heading is supplied by the wizard
     * label and the PDF, not stored inside the content. Include is left as an
     * empty bullet because inclusions differ per package.
     *
     * @return array<string,string> section key => default HTML body
     */
    function costing_itinerary_section_defaults()
    {
        return array(
            'include' => '<ul><li></li></ul>',
            'exclude' =>
                '<ul>'
                . '<li>Personal expenses: telephone, fax, mini bar, laundry, etc</li>'
                . '<li>Additional Tour</li>'
                . '<li>Meals other than stated</li>'
                . '<li>Tourism tax</li>'
                . '<li>Activities &amp; Entrance fee other than stated (if applicable)</li>'
                . '<li>Services/ Item not mentioned in the program</li>'
                . '<li><a href="https://www.holidaygogogo.com/travel-insurance-malaysia-domestic-oversea/" target="_blank" rel="noopener">Travel insurance</a> (strongly recommended to purchase)</li>'
                . '<li>Other expenses not stated in the "Includes" section</li>'
                . '<li>Super peak and Peak Season Surcharges (if applicable)</li>'
                . '</ul>',
            'important_notes' =>
                '<ul>'
                . '<li>Rate maybe change with or without prior notice depend on Vietnam new regulations such as petrol fee, entrance fee etc</li>'
                . '</ul>',
            'terms' =>
                '<ul>'
                . '<li>Price quoted per person in Malaysian Ringgit (MYR) based on Twin sharing (rate subject to change due to fluctuation of currency).</li>'
                . '<li>The above quotation is an estimation only, a revised quotation will be given upon confirmation.</li>'
                . '<li>Maximum 10-hour transport usage per day, exceeding the 10 hours usage, an additional charge will be incurred.</li>'
                . '<li>Any applicable tax not included in the quotation is to be paid upon arrival at the hotel.</li>'
                . '<li>Payment must be received in net as stated in the quotation.</li>'
                . '<li>Restaurant locations will be determined once confirmed.</li>'
                . '<li>Quoted rates may vary depending on the availability of the restaurant.</li>'
                . '<li>Any transaction fee charges for the foreign transfer must be borne by the buyer.</li>'
                . '<li>If the rates quoted are other than MYR, the quoted rates may vary from time to time due to currency fluctuations. An updated rate at the time of booking based on the current exchange rates will be provided.</li>'
                . '<li>Due to the floating diesel price, the rate is quoted based on current pricing and subject to fluctuation from time to time. We will re quote if the price fluctuated during booking made.</li>'
                . '<li>The rate quoted is subject to change if there are any revisions on the diesel prices.</li>'
                . '<li>Itinerary and package content is subject to last-minute changes due to weather or operational issues.</li>'
                . '<li>Hotel surcharge may apply on weekends, school holidays, and Malaysia public holidays. (Rate based on booking)</li>'
                . '<li>Activity stated (if included) is provided on a complimentary basis, no refund will be made for cancellation of activities due to weather or operational issues.</li>'
                . '<li>Holidaygogogo reserves the right to change to other accommodation in the same class if the accommodation stated above is not available during the travel date.</li>'
                . '<li>No changes in room arrangement are allowed after full payment has been made.</li>'
                . '<li>Other terms and conditions for booking.</li>'
                . '<li>All pricing is inclusive of related taxes unless stated otherwise.</li>'
                . '<li>Single Supplement will be charged for any Single Room Occupancy.</li>'
                . '<li>Service will start when traveller has arrived at the airport to pick up and ends once traveller has been transferred to the airport for flight departure.</li>'
                . '<li>Service based on a private tour.</li>'
                . '</ul>',
        );
    }
}

if (!function_exists('costing_itinerary_html_is_blank')) {
    /**
     * True when TinyMCE HTML carries no visible content. Empty editors emit
     * markup like "<p></p>", "<p><br></p>" or "<p>&nbsp;</p>" — treating those
     * as blank stops phantom rows/fields from being stored. Any <img> counts as
     * content (an image-only block is meaningful).
     *
     * @param string|null $html
     * @return bool
     */
    function costing_itinerary_html_is_blank($html)
    {
        $html = (string) $html;
        if (stripos($html, '<img') !== false) {
            return false;
        }
        $text = html_entity_decode($html, ENT_QUOTES);
        // Normalise non-breaking spaces (raw and decoded) to plain spaces.
        $text = str_replace(array("\xc2\xa0", "\xa0"), ' ', $text);
        $text = strip_tags($text);
        return trim($text) === '';
    }
}

if (!function_exists('costing_itinerary_prepare_row')) {
    /**
     * Normalise one posted itinerary day row into an insert-ready shape. Title is
     * trimmed plain text; each HTML field is trimmed and nulled when visually
     * blank; meal_plan is a multi-select normalised to a slug list. 'is_empty' is
     * true only when the title AND description AND meal plan are all blank, so the
     * caller can skip the row entirely. 'day_number' is 0 when not supplied — the
     * caller assigns a sequential fallback.
     *
     * @param array $row
     * @return array {day_number:int, title:?string, description:?string, meal_plan:?string, is_empty:bool}
     */
    function costing_itinerary_prepare_row($row)
    {
        $row = (array) $row;

        $title = trim((string) (isset($row['title']) ? $row['title'] : ''));
        $out = array(
            'day_number' => isset($row['day_number']) && (int) $row['day_number'] > 0 ? (int) $row['day_number'] : 0,
            'title'      => $title !== '' ? $title : null,
        );

        $all_blank = ($title === '');
        foreach (costing_itinerary_html_fields() as $field) {
            $val = trim((string) (isset($row[$field]) ? $row[$field] : ''));
            if ($val !== '' && costing_itinerary_html_is_blank($val)) {
                $val = '';
            }
            $out[$field] = $val !== '' ? $val : null;
            if ($val !== '') {
                $all_blank = false;
            }
        }

        $out['meal_plan'] = costing_meal_plan_normalize(isset($row['meal_plan']) ? $row['meal_plan'] : null);
        if ($out['meal_plan'] !== null) {
            $all_blank = false;
        }

        $out['is_empty'] = $all_blank;
        return $out;
    }
}

if (!function_exists('costing_itinerary_prepare_level')) {
    /**
     * Normalise the posted itinerary-level sections into a package-update shape.
     * Include / Exclude / Important Notes / Terms & Conditions are four separate
     * rich-text fields, keyed by their short section key (include / exclude /
     * important_notes / terms). Each is trimmed and nulled when visually blank;
     * the returned keys are the costing_packages columns.
     *
     * @param array $post section key => posted HTML
     * @return array {itinerary_includes:?string, itinerary_excludes:?string, itinerary_important_notes:?string, itinerary_terms_and_conditions:?string}
     */
    function costing_itinerary_prepare_level($post)
    {
        $post = (array) $post;

        $out = array();
        foreach (costing_itinerary_section_meta() as $key => $meta) {
            $column = $meta[1];
            $val = trim((string) (isset($post[$key]) ? $post[$key] : ''));
            if ($val !== '' && costing_itinerary_html_is_blank($val)) {
                $val = '';
            }
            $out[$column] = $val !== '' ? $val : null;
        }

        return $out;
    }
}

if (!function_exists('costing_itinerary_line_text')) {
    /**
     * Flatten one HTML fragment (the inside of an <li> or a paragraph) to a single
     * line of plain text: tags stripped, entities decoded, non-breaking spaces and
     * runs of whitespace collapsed to one space, trimmed. Shared by the Excel
     * round-trip so a bullet exports as clean text and re-imports cleanly.
     *
     * @param string $fragment
     * @return string
     */
    function costing_itinerary_line_text($fragment)
    {
        $text = strip_tags((string) $fragment);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = str_replace(array("\xc2\xa0", "\xa0"), ' ', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }
}

if (!function_exists('costing_itinerary_html_from_lines')) {
    /**
     * Turn an Excel "one bullet per line" cell into the <ul><li> HTML the four
     * itinerary-level sections (Include / Exclude / Important Notes / Terms) store
     * and the Quotation PDF renders. Each non-blank line becomes one bullet, in
     * order; blank lines are dropped.
     *
     * A cell that already carries block HTML (an old export, or markup pasted in
     * by hand) is kept verbatim so it still round-trips — only plain-text cells
     * are promoted to a bullet list.
     *
     * @param string $text newline-separated bullets (or existing HTML)
     * @return string <ul>…</ul> HTML, or '' when nothing visible
     */
    function costing_itinerary_html_from_lines($text)
    {
        $text = (string) $text;
        if (preg_match('/<(ul|ol|li|p|br|h[1-6])\b/i', $text)) {
            return trim($text);
        }

        $items = array();
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $items[] = '<li>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</li>';
        }

        return empty($items) ? '' : '<ul>' . implode('', $items) . '</ul>';
    }
}

if (!function_exists('costing_itinerary_lines_from_html')) {
    /**
     * Inverse of costing_itinerary_html_from_lines(): render a stored section's
     * HTML back to a "one bullet per line" cell for the Excel export. Each <li>
     * becomes one line; when there are no list items the paragraph / line-break
     * boundaries are used instead so legacy free-text still exports readably.
     *
     * @param string $html stored section HTML
     * @return string newline-separated bullet text (one bullet per line)
     */
    function costing_itinerary_lines_from_html($html)
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }

        $lines = array();
        if (preg_match_all('/<li\b[^>]*>(.*?)<\/li>/is', $html, $m)) {
            foreach ($m[1] as $frag) {
                $line = costing_itinerary_line_text($frag);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        if (empty($lines)) {
            $norm = preg_replace('/<br\s*\/?>|<\/(p|div|li|h[1-6])>/i', "\n", $html);
            foreach (preg_split('/\r\n|\r|\n/', $norm) as $frag) {
                $line = costing_itinerary_line_text($frag);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return implode("\n", $lines);
    }
}

if (!function_exists('costing_itinerary_split_notes')) {
    /**
     * Best-effort split of a LEGACY merged Notes block (one editor holding all
     * four sections, each introduced by a bold heading like
     * "<p><strong>Include</strong></p>") into the four separate section bodies.
     * The heading paragraphs are dropped — the body (usually a <ul>) is kept.
     *
     * Only used as a fallback for packages saved before the four columns existed,
     * so it stays lenient: content before the first recognised heading is folded
     * into Include, and an unrecognised heading keeps its paragraph with the
     * section currently being filled.
     *
     * @param string|null $html
     * @return array<string,string> section key => HTML body
     */
    function costing_itinerary_split_notes($html)
    {
        $out = array();
        foreach (array_keys(costing_itinerary_section_meta()) as $key) {
            $out[$key] = '';
        }

        $html = (string) $html;
        if (trim($html) === '') {
            return $out;
        }

        // Normalise a heading's visible text to a section key, or null.
        $to_key = function ($text) {
            $t = html_entity_decode(strip_tags((string) $text), ENT_QUOTES);
            $t = strtolower(trim(preg_replace('/\s+/', ' ', $t)));
            $t = trim($t, " :\t");
            switch ($t) {
                case 'include':
                case 'includes':
                case 'inclusion':
                case 'inclusions':
                    return 'include';
                case 'exclude':
                case 'excludes':
                case 'exclusion':
                case 'exclusions':
                    return 'exclude';
                case 'important note':
                case 'important notes':
                    return 'important_notes';
                case 'terms':
                case 'terms & conditions':
                case 'terms and conditions':
                case 'terms and condition':
                case 'terms & condition':
                    return 'terms';
            }
            return null;
        };

        // Split on heading paragraphs — a <p> (or <h1..6>) whose text is a short
        // bold label. Capture the label so we know which section follows.
        $pattern = '/<(p|h[1-6])\b[^>]*>\s*(?:<strong>|<b>)?\s*([^<]{1,40}?)\s*(?:<\/strong>|<\/b>)?\s*<\/\1>/i';
        $parts = preg_split($pattern, $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            $out['include'] = $html;
            return $out;
        }

        // preg_split with two capture groups yields: [pre, tag1, label1, body1, tag2, label2, body2, ...]
        $current = 'include';
        $out['include'] .= isset($parts[0]) ? $parts[0] : '';
        $count = count($parts);
        for ($i = 1; $i < $count; $i += 3) {
            $tag   = $parts[$i];
            $label = isset($parts[$i + 1]) ? $parts[$i + 1] : '';
            $body  = isset($parts[$i + 2]) ? $parts[$i + 2] : '';
            $key   = $to_key($label);
            if ($key === null) {
                // Not a section heading — keep the paragraph verbatim in the
                // section we are currently filling.
                $out[$current] .= '<' . $tag . '>' . $label . '</' . $tag . '>' . $body;
            } else {
                $out[$key] .= $body;
                $current = $key;
            }
        }

        foreach ($out as $key => $val) {
            $out[$key] = costing_itinerary_html_is_blank($val) ? '' : trim($val);
        }

        return $out;
    }
}

if (!function_exists('costing_itinerary_sections_from_row')) {
    /**
     * Resolve the four itinerary sections (include / exclude / important_notes /
     * terms) for display from a costing_packages (or joined booking) row.
     *
     * Prefers the four dedicated columns. When ALL four are blank but the legacy
     * merged itinerary_notes column has content, it is split on the fly so old
     * packages keep rendering their notes. Always returns every section key.
     *
     * @param array $row
     * @return array<string,string> section key => HTML
     */
    function costing_itinerary_sections_from_row($row)
    {
        $row = (array) $row;

        $out = array();
        $has_any = false;
        foreach (costing_itinerary_section_meta() as $key => $meta) {
            $val = isset($row[$meta[1]]) ? (string) $row[$meta[1]] : '';
            $out[$key] = $val;
            if (!costing_itinerary_html_is_blank($val)) {
                $has_any = true;
            }
        }

        if (!$has_any) {
            $legacy = isset($row['itinerary_notes']) ? (string) $row['itinerary_notes'] : '';
            if (!costing_itinerary_html_is_blank($legacy)) {
                return costing_itinerary_split_notes($legacy);
            }
        }

        return $out;
    }
}
