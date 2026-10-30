<?php
/**
 * Run with: php tests/helpers/CostingItinerarySectionsTest.php
 *
 * Covers the four-section "Notes & Terms" split (Include / Exclude / Important
 * Notes / Terms & Conditions) that replaced the single merged itinerary Notes
 * field:
 *   - costing_itinerary_section_meta / _section_defaults  canonical definitions
 *   - costing_itinerary_split_notes      best-effort split of LEGACY merged HTML
 *   - costing_itinerary_sections_from_row prefer the 4 columns, else split legacy
 *
 * Guards: legacy packages (merged itinerary_notes, four columns blank) must still
 * surface their Include/Exclude/Important/Terms content, and the new columns must
 * win whenever any is populated.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

require_once __DIR__ . '/../../application/helpers/costing_itinerary_helper.php';

$assertions = array();

// --- section meta: keys, columns, post fields ------------------------------
$meta = costing_itinerary_section_meta();
$assertions['four sections']        = array_keys($meta) === array('include', 'exclude', 'important_notes', 'terms');
$assertions['include column']       = $meta['include'][1] === 'itinerary_includes';
$assertions['exclude column']       = $meta['exclude'][1] === 'itinerary_excludes';
$assertions['important column']     = $meta['important_notes'][1] === 'itinerary_important_notes';
$assertions['terms column']         = $meta['terms'][1] === 'itinerary_terms_and_conditions';
$assertions['terms label']          = $meta['terms'][0] === 'Terms & Conditions';
$assertions['include post field']   = $meta['include'][2] === 'itinerary_include';

// --- section defaults ------------------------------------------------------
$defaults = costing_itinerary_section_defaults();
$assertions['defaults four keys']   = array_keys($defaults) === array('include', 'exclude', 'important_notes', 'terms');
$assertions['include default empty bullet'] = $defaults['include'] === '<ul><li></li></ul>';
$assertions['exclude default has insurance'] = strpos($defaults['exclude'], 'Travel insurance') !== false;
$assertions['terms default has twin sharing'] = strpos($defaults['terms'], 'Twin sharing') !== false;
// Defaults carry NO embedded heading — the heading is supplied by the UI/PDF.
$assertions['defaults headingless']  = stripos($defaults['exclude'], '<strong>Exclude</strong>') === false;

// --- split_notes: the standard merged default splits cleanly ---------------
$merged =
    '<p><strong>Include</strong></p><ul><li>5 nights hotel</li></ul>'
    . '<p><strong>Exclude</strong></p><ul><li>Return flights</li></ul>'
    . '<p><strong>Important Notes</strong></p><ul><li>Weather changes</li></ul>'
    . '<p><strong>Terms &amp; Conditions</strong></p><ul><li>Twin sharing</li></ul>';
$split = costing_itinerary_split_notes($merged);
$assertions['split include body']    = $split['include'] === '<ul><li>5 nights hotel</li></ul>';
$assertions['split exclude body']    = $split['exclude'] === '<ul><li>Return flights</li></ul>';
$assertions['split important body']  = $split['important_notes'] === '<ul><li>Weather changes</li></ul>';
$assertions['split terms body']      = $split['terms'] === '<ul><li>Twin sharing</li></ul>';
// Headings are dropped, not kept inside the bodies.
$assertions['split drops headings']  = stripos($split['terms'], '<strong>') === false;

// "Terms and Conditions" (word "and") and plural "Includes" headings resolve too.
$altMerged =
    '<p><strong>Includes</strong></p><ul><li>Guide</li></ul>'
    . '<p><strong>Terms and Conditions</strong></p><ul><li>Net payment</li></ul>';
$altSplit = costing_itinerary_split_notes($altMerged);
$assertions['split plural include']  = $altSplit['include'] === '<ul><li>Guide</li></ul>';
$assertions['split and-conditions']  = $altSplit['terms'] === '<ul><li>Net payment</li></ul>';

// Content before the first recognised heading folds into Include.
$leading = costing_itinerary_split_notes('<p>Intro line</p><p><strong>Exclude</strong></p><ul><li>X</li></ul>');
$assertions['split leading -> include'] = strpos($leading['include'], 'Intro line') !== false;
$assertions['split leading exclude']    = $leading['exclude'] === '<ul><li>X</li></ul>';

// Blank in -> all sections blank.
$blankSplit = costing_itinerary_split_notes('');
$assertions['split blank all empty'] = $blankSplit['include'] === '' && $blankSplit['exclude'] === ''
    && $blankSplit['important_notes'] === '' && $blankSplit['terms'] === '';

// --- sections_from_row: new columns win ------------------------------------
$rowNew = array(
    'itinerary_includes'             => '<ul><li>Breakfast</li></ul>',
    'itinerary_excludes'             => '<ul><li>Tips</li></ul>',
    'itinerary_important_notes'      => '',
    'itinerary_terms_and_conditions' => '<ul><li>No refund</li></ul>',
    'itinerary_notes'                => '<p><strong>Include</strong></p><ul><li>STALE</li></ul>',
);
$fromNew = costing_itinerary_sections_from_row($rowNew);
$assertions['row new include wins'] = $fromNew['include'] === '<ul><li>Breakfast</li></ul>';
$assertions['row new ignores legacy'] = strpos($fromNew['include'], 'STALE') === false;
$assertions['row new blank important'] = $fromNew['important_notes'] === '';
$assertions['row new keys complete'] = array_keys($fromNew) === array('include', 'exclude', 'important_notes', 'terms');

// --- sections_from_row: legacy fallback when all four columns blank ---------
$rowLegacy = array(
    'itinerary_includes'             => '',
    'itinerary_excludes'             => null,
    'itinerary_important_notes'      => '<p><br></p>',   // visually blank
    'itinerary_terms_and_conditions' => '',
    'itinerary_notes'                => $merged,
);
$fromLegacy = costing_itinerary_sections_from_row($rowLegacy);
$assertions['row legacy include split'] = $fromLegacy['include'] === '<ul><li>5 nights hotel</li></ul>';
$assertions['row legacy terms split']   = $fromLegacy['terms'] === '<ul><li>Twin sharing</li></ul>';

// --- sections_from_row: nothing anywhere -> all blank ----------------------
$fromEmpty = costing_itinerary_sections_from_row(array());
$assertions['row empty all blank'] = $fromEmpty['include'] === '' && $fromEmpty['terms'] === '';

// --- Excel "one bullet per line" <-> <ul><li> round-trip -------------------
// html_from_lines: each non-blank line becomes one <li>, in order.
$assertions['lines->html single']   = costing_itinerary_html_from_lines('Daily breakfast') === '<ul><li>Daily breakfast</li></ul>';
$assertions['lines->html multi']    = costing_itinerary_html_from_lines("Hotel\n5 nights\nGuide") === '<ul><li>Hotel</li><li>5 nights</li><li>Guide</li></ul>';
$assertions['lines->html crlf/blank'] = costing_itinerary_html_from_lines("A\r\n\r\nB\n ") === '<ul><li>A</li><li>B</li></ul>';
$assertions['lines->html escapes']  = costing_itinerary_html_from_lines('Tea & scones <x>') === '<ul><li>Tea &amp; scones &lt;x&gt;</li></ul>';
$assertions['lines->html empty']    = costing_itinerary_html_from_lines("\n  \n") === '';
// Already-HTML cells (old export / pasted markup) pass through untouched.
$assertions['lines->html passthru'] = costing_itinerary_html_from_lines('<ul><li>Kept</li></ul>') === '<ul><li>Kept</li></ul>';

// lines_from_html: one <li> per line; entities decoded, tags/whitespace cleaned.
$assertions['html->lines list']     = costing_itinerary_lines_from_html('<ul><li>Hotel</li><li>Return transfers</li></ul>') === "Hotel\nReturn transfers";
$assertions['html->lines entities'] = costing_itinerary_lines_from_html('<ul><li>Tea &amp; scones</li></ul>') === 'Tea & scones';
$assertions['html->lines paras']    = costing_itinerary_lines_from_html('<p>One</p><p>Two</p>') === "One\nTwo";
$assertions['html->lines plain']    = costing_itinerary_lines_from_html('No markup here') === 'No markup here';
$assertions['html->lines empty']    = costing_itinerary_lines_from_html('') === '';
// Full cycle: stored HTML -> export cell -> re-import HTML is stable.
$cycle = costing_itinerary_html_from_lines(costing_itinerary_lines_from_html('<ul><li>Hotel</li><li>Guide</li></ul>'));
$assertions['round-trip stable']    = $cycle === '<ul><li>Hotel</li><li>Guide</li></ul>';

// --- DAY description: mixed <p>+<ul><li> <-> readable text round-trip --------
// This is the bug the export used to dump raw markup into the Excel cell.
$dayHtml =
    '<p>Arrive at Kuala Lumpur International Airport. Meet &amp; greet by our local tour guide.</p>'
    . '<ul><li>Royal Palace – photo stop</li><li>KLCC Aquaria (entrance ticket included)</li></ul>'
    . '<p>Transfer to hotel and check in. Overnight in Kuala Lumpur.</p>';
$dayText = costing_itinerary_day_text_from_html($dayHtml);
$expectedText =
    "Arrive at Kuala Lumpur International Airport. Meet & greet by our local tour guide.\n"
    . "- Royal Palace – photo stop\n"
    . "- KLCC Aquaria (entrance ticket included)\n"
    . "Transfer to hotel and check in. Overnight in Kuala Lumpur.";
$assertions['day html->text no tags']   = strpos($dayText, '<') === false;         // no raw coding
$assertions['day html->text keeps para'] = strpos($dayText, 'Arrive at Kuala') === 0; // intro para kept
$assertions['day html->text bullets']    = strpos($dayText, "- Royal Palace") !== false;
$assertions['day html->text entity']     = strpos($dayText, 'Meet & greet') !== false; // &amp; decoded
$assertions['day html->text exact']       = $dayText === $expectedText;

// text -> HTML: "- " lines fold into one <ul>, other lines become <p>.
$dayBack = costing_itinerary_day_html_from_text($dayText);
$expectedBack =
    '<p>Arrive at Kuala Lumpur International Airport. Meet &amp; greet by our local tour guide.</p>'
    . '<ul><li>Royal Palace – photo stop</li><li>KLCC Aquaria (entrance ticket included)</li></ul>'
    . '<p>Transfer to hotel and check in. Overnight in Kuala Lumpur.</p>';
$assertions['day text->html rebuild'] = $dayBack === $expectedBack;
// Full cycle is stable (export cell re-imports to the same HTML).
$assertions['day round-trip stable']  = costing_itinerary_day_html_from_text($dayText) === $expectedBack;

// Bullet-only day.
$assertions['day bullets only'] = costing_itinerary_day_text_from_html('<ul><li>A</li><li>B</li></ul>') === "- A\n- B";
$assertions['day bullets only back'] = costing_itinerary_day_html_from_text("- A\n- B") === '<ul><li>A</li><li>B</li></ul>';
// Paragraph-only day.
$assertions['day para only'] = costing_itinerary_day_text_from_html('<p>Free day at leisure.</p>') === 'Free day at leisure.';
$assertions['day para only back'] = costing_itinerary_day_html_from_text('Free day at leisure.') === '<p>Free day at leisure.</p>';
// Blank in -> blank out.
$assertions['day blank text'] = costing_itinerary_day_text_from_html('') === '';
$assertions['day blank html'] = costing_itinerary_day_html_from_text('') === '';
// Already-HTML cell passes through untouched (hand-pasted / legacy export).
$assertions['day text->html passthru'] = costing_itinerary_day_html_from_text('<p>Kept</p>') === '<p>Kept</p>';
// A bare bullet char (•) is also recognised as a bullet.
$assertions['day unicode bullet'] = costing_itinerary_day_html_from_text("• One") === '<ul><li>One</li></ul>';
// Loose text with only <br> still flattens readably.
$assertions['day br fallback'] = costing_itinerary_day_text_from_html('Line one<br>Line two') === "Line one\nLine two";

$failed = 0;
foreach ($assertions as $label => $ok) {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

echo PHP_EOL . ($failed === 0 ? "All assertions passed.\n" : "$failed assertion(s) failed.\n");
exit($failed === 0 ? 0 : 1);
