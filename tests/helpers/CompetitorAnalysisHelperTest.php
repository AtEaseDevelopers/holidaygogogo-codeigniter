<?php
/**
 * Run with: php tests/helpers/CompetitorAnalysisHelperTest.php
 *
 * Locks the pure Competitor Analysis helpers (no DB, no network). The AI agent
 * scrapes the page itself via OpenAI's web_search tool, so these cover the
 * request shaping and reply normalisation only:
 *   - competitor_format_our_products()   flattens the costing dashboard rows
 *   - competitor_build_agent_input()     shapes the Responses API instructions+input
 *   - competitor_extract_responses_text() pulls assistant text from a Responses body
 *   - competitor_parse_ai_response()     normalises the model's JSON reply
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}
require_once __DIR__ . '/../../application/helpers/competitor_analysis_helper.php';

$failures = 0;
function check($label, $expected, $actual) {
    global $failures;
    if ($expected === $actual) {
        echo "PASS  {$label}\n";
    } else {
        $failures++;
        echo "FAIL  {$label}\n";
        echo "      expected: " . json_encode($expected) . "\n";
        echo "      actual:   " . json_encode($actual) . "\n";
    }
}
function check_true($label, $cond) { check($label, true, (bool) $cond); }

// ---- competitor_format_our_products -----------------------------------------
$dashboard = array('packages' => array(
    array(
        'name' => 'Langkawi Getaway', 'tour_code' => 'LGK-01',
        'bookings' => array(array('selling_price_per_pax' => '1200.50')),
    ),
    (object) array(
        'name' => 'Bali Escape', 'tour_code' => 'BAL-02',
        'bookings' => array(),
    ),
    array('name' => '', 'tour_code' => 'SKIP'),   // no name -> skipped
));
$products = competitor_format_our_products($dashboard);
check('format_our_products drops nameless rows', 2, count($products));
check('format_our_products name', 'Langkawi Getaway', $products[0]['name']);
check('format_our_products price from latest booking', 1200.5, $products[0]['price_myr']);
check('format_our_products null price when no booking', null, $products[1]['price_myr']);
check('format_our_products respects limit', 1, count(competitor_format_our_products($dashboard, 1)));

// ---- competitor_products_block (cost trim) ----------------------------------
// Null prices / empty codes carry no signal — they must NOT be sent (save tokens).
$block = competitor_products_block($products);
check_true('products_block keeps known price', strpos($block, '1200.5') !== false);
check_true('products_block keeps tour_code', strpos($block, 'LGK-01') !== false);
check_true('products_block drops null price key', strpos($block, 'price_myr":null') === false);
$decoded_block = json_decode($block, true);
check('products_block omits price_myr when unknown', false, array_key_exists('price_myr', $decoded_block[1]));
$empty_code = json_decode(competitor_products_block(array(array('name' => 'X', 'tour_code' => '', 'price_myr' => null))), true);
check('products_block drops empty tour_code key', false, array_key_exists('tour_code', $empty_code[0]));
check('products_block skips nameless rows', 0,
    count(json_decode(competitor_products_block(array(array('name' => '', 'price_myr' => 9))), true)));

// ---- competitor_build_agent_input -------------------------------------------
$spec = competitor_build_agent_input('https://x.com/tour', $products);
check('build_agent_input keys', array('instructions', 'input'), array_keys($spec));
check_true('build_agent_input tells agent to scrape via web_search',
    stripos($spec['instructions'], 'web_search') !== false);
check_true('build_agent_input demands JSON', stripos($spec['instructions'], 'JSON') !== false);
check_true('build_agent_input input carries url', strpos($spec['input'], 'https://x.com/tour') !== false);
check_true('build_agent_input input carries our products', strpos($spec['input'], 'Langkawi Getaway') !== false);
check_true('build_agent_input does NOT embed page html', stripos($spec['input'], '<html') === false);
// SPA hybrid: pass partial scraped text so the agent fills the gaps
$spec_p = competitor_build_agent_input('https://x.com/tour', $products, 'Bali 5D4N (only metadata scraped)');
check_true('build_agent_input includes partial scraped text',
    strpos($spec_p['input'], 'Bali 5D4N (only metadata scraped)') !== false);
check_true('build_agent_input instructs agent to fill gaps',
    stripos($spec_p['instructions'], 'fill in') !== false);
check_true('build_agent_input still web_search when partial given',
    stripos($spec_p['instructions'], 'web_search') !== false);

// ---- competitor_html_to_text (own scraper) ----------------------------------
$html = '<html><head><title>T</title><style>.x{color:red}</style><script>var a=1;</script></head>'
      . '<body><nav>Menu</nav><h1>Bali 5D4N</h1><p>Great tour &amp; value</p>'
      . '<ul><li>Hotel</li><li>Breakfast</li></ul><!-- note --></body></html>';
$text = competitor_html_to_text($html);
check_true('html_to_text drops nav chrome', strpos($text, 'Menu') === false);
check_true('html_to_text drops script', strpos($text, 'var a=1') === false);
check_true('html_to_text drops style', strpos($text, 'color:red') === false);
check_true('html_to_text drops comment', strpos($text, 'note') === false);
check_true('html_to_text keeps heading', strpos($text, 'Bali 5D4N') !== false);
check_true('html_to_text decodes entities', strpos($text, 'Great tour & value') !== false);
check_true('html_to_text keeps list items',
    strpos($text, 'Hotel') !== false && strpos($text, 'Breakfast') !== false);
check('html_to_text empty on non-string', '', competitor_html_to_text(null));
$long = '<p>' . str_repeat('word ', 20000) . '</p>';
check_true('html_to_text truncates to cap', strlen(competitor_html_to_text($long, 5000)) <= 5000);

// ---- html_to_text table cells -----------------------------------------------
check('html_to_text separates table cells with |', '3D2N Semporna | T/CODE: SEMP',
    competitor_html_to_text("<table><tr><td>3D2N Semporna</td><td>T/CODE: SEMP</td></tr></table>"));

// ---- html_to_text strips forms + dedupes repeated lines (cleaner AI input) ---
check_true('html_to_text drops form widgets',
    strpos(competitor_html_to_text('<form><input name="q"><button>Search</button></form><h1>Bali 5D4N</h1>'), 'Search') === false);
check('html_to_text collapses consecutive duplicate lines',
    "Highlights\nBali 5D4N",
    competitor_html_to_text('<p>Highlights</p><p>Highlights</p><p>Highlights</p><p>Bali 5D4N</p>'));
// "Book Now" is a CTA button — now dropped as boilerplate before the cap.
check('html_to_text drops CTA boilerplate lines', 'Bali 5D4N',
    competitor_html_to_text('<p>Book Now</p><p>Subscribe to our newsletter</p><h1>Bali 5D4N</h1>'));

// ---- competitor_spa_api_url (JS-app adapter) --------------------------------
check('spa_api_url ICE post with category', 'https://www.gd.my/api/v1/posts/65',
    competitor_spa_api_url('https://www.gd.my/web/posts/domestic/65'));
check('spa_api_url ICE post no category', 'https://www.gd.my/api/v1/posts/65',
    competitor_spa_api_url('https://www.gd.my/web/posts/65'));
check('spa_api_url none for a normal page', '',
    competitor_spa_api_url('https://comp.com/tours/bali-5d4n'));
check('spa_api_url none for junk', '', competitor_spa_api_url('not a url'));

// ---- competitor_flatten_json_text -------------------------------------------
check_true('flatten_json keeps prose, skips noise keys',
    strpos(competitor_flatten_json_text(array('@type' => 'Product', 'url' => 'https://x', 'name' => 'Sabah Trip', 'nights' => 3)), 'Sabah Trip') !== false);
check('flatten_json drops url value', false,
    strpos(competitor_flatten_json_text(array('link' => 'https://x/y', 'name' => 'A trip here')), 'https://x/y') !== false);
check('flatten_json drops bare number under non-price key', false,
    strpos(competitor_flatten_json_text(array('nights' => 3, 'name' => 'Trip')), '3') !== false);
check_true('flatten_json keeps number under price key',
    strpos(competitor_flatten_json_text(array('price' => 1999)), '1999') !== false);
check('flatten_json empty on non-array', '', competitor_flatten_json_text('nope'));

// ---- competitor_extract_meta_text -------------------------------------------
$metaHtml = '<head><title>Korea Winter 7D</title>'
    . '<meta property="og:description" content="Ski &amp; snow tour in Korea">'
    . '<meta property="product:price:amount" content="3200">'
    . '<meta name="og:image" content="https://x/p.jpg"></head>';
$metaTxt = competitor_extract_meta_text($metaHtml);
check_true('meta_text reads title', strpos($metaTxt, 'Korea Winter 7D') !== false);
check_true('meta_text reads og:description decoded', strpos($metaTxt, 'Ski & snow tour in Korea') !== false);
check_true('meta_text reads product price', strpos($metaTxt, '3200') !== false);
check_true('meta_text ignores og:image', strpos($metaTxt, 'p.jpg') === false);
check('meta_text empty when none', '', competitor_extract_meta_text('<div>hi</div>'));

// ---- competitor_extract_embedded_json ---------------------------------------
// JSON-LD Product with a long description so it clears the thin gate.
$richLd = '<html><body><div id="app">Loading…</div><script type="application/ld+json">'
    . json_encode(array('@context' => 'https://schema.org', '@type' => 'Product',
        'name' => 'Bali 5D4N Adventure',
        'description' => str_repeat('Explore the beaches, temples and rice terraces of Bali. ', 12),
        'image' => 'https://cdn.co/x.jpg',
        'offers' => array('@type' => 'Offer', 'price' => '1999', 'priceCurrency' => 'MYR')))
    . '</script></body></html>';
$emb = competitor_extract_embedded_json($richLd);
check_true('embedded_json reads JSON-LD name', strpos($emb, 'Bali 5D4N Adventure') !== false);
check_true('embedded_json reads JSON-LD price', strpos($emb, '1999') !== false);
check_true('embedded_json reads JSON-LD currency', strpos($emb, 'MYR') !== false);
check_true('embedded_json drops JSON-LD image url', strpos($emb, 'cdn.co') === false);
check_true('embedded_json rescues a thin JS shell',
    competitor_scrape_is_thin(competitor_html_to_text($richLd)) && ! competitor_scrape_is_thin($emb));

// Next.js hydration blob.
$nextHtml = '<script id="__NEXT_DATA__" type="application/json">'
    . json_encode(array('props' => array('pageProps' => array('tour' => array(
        'title' => 'Japan 6D5N Sakura', 'price' => 4599,
        'cities' => array('Tokyo', 'Osaka'), 'image' => 'https://x/y.png')))))
    . '</script>';
$en = competitor_extract_embedded_json($nextHtml);
check_true('embedded_json reads __NEXT_DATA__ title', strpos($en, 'Japan 6D5N Sakura') !== false);
check_true('embedded_json reads __NEXT_DATA__ price', strpos($en, '4599') !== false);
check_true('embedded_json reads __NEXT_DATA__ nested list',
    strpos($en, 'Tokyo') !== false && strpos($en, 'Osaka') !== false);
check_true('embedded_json drops __NEXT_DATA__ image', strpos($en, 'y.png') === false);

// og/meta only (no JSON blobs).
check_true('embedded_json falls back to meta tags',
    strpos(competitor_extract_embedded_json($metaHtml), 'Korea Winter 7D') !== false);
check('embedded_json empty on non-string', '', competitor_extract_embedded_json(null));
check('embedded_json empty when nothing embedded', '',
    competitor_extract_embedded_json('<html><body>hi there</body></html>'));

// ---- competitor_websearch_cap -----------------------------------------------
check('websearch_cap default when unset', 15, competitor_websearch_cap(false));
check('websearch_cap default when empty', 15, competitor_websearch_cap(''));
check('websearch_cap honours value', 30, competitor_websearch_cap('30'));
check('websearch_cap explicit 0 = unlimited', 0, competitor_websearch_cap('0'));
check('websearch_cap negative -> default', 15, competitor_websearch_cap('-5'));
check('websearch_cap custom default', 5, competitor_websearch_cap(false, 5));

// ---- competitor_headless_cap ------------------------------------------------
check('headless_cap default when unset', 60, competitor_headless_cap(false));
check('headless_cap default when empty', 60, competitor_headless_cap(''));
check('headless_cap honours value', 8, competitor_headless_cap('8'));
check('headless_cap explicit 0 = unlimited', 0, competitor_headless_cap('0'));
check('headless_cap negative -> default', 60, competitor_headless_cap('-2'));
check('headless_cap custom default', 5, competitor_headless_cap(false, 5));

// ---- competitor_job_progress_message ----------------------------------------
check('job_progress queued', 'Queued…', competitor_job_progress_message(array('state' => 'queued')));
// running label reflects the phase: discovering (no total) vs reading/analysing (N/total)
check('job_progress discovering (no total)', 'Discovering…',
    competitor_job_progress_message(array('state' => 'running', 'phase' => 'discovering')));
check('job_progress discovering ignores stale total', 'Discovering…',
    competitor_job_progress_message(array('state' => 'running', 'phase' => 'discovering', 'done' => 0, 'total' => 0)));
check('job_progress reading shows N/total', 'Reading products… 2 / 5',
    competitor_job_progress_message(array('state' => 'running', 'phase' => 'reading', 'done' => 2, 'total' => 5)));
check('job_progress analysing shows N/total', 'Analysing… 4 / 5',
    competitor_job_progress_message(array('state' => 'running', 'phase' => 'analysing', 'done' => 4, 'total' => 5)));
check('job_progress analysing without total', 'Analysing…',
    competitor_job_progress_message(array('state' => 'running', 'phase' => 'analysing')));
check('job_progress paste job = analysing (no discovery)', 'Analysing…',
    competitor_job_progress_message(array('state' => 'running', 'mode' => 'paste')));
check('job_progress upload job = analysing (no discovery)', 'Analysing…',
    competitor_job_progress_message(array('state' => 'running', 'mode' => 'upload')));
check('job_progress done shows N', 'Done — 3', competitor_job_progress_message(array('state' => 'done', 'count' => 3)));
check('job_progress done no count', 'Done.', competitor_job_progress_message(array('state' => 'done')));
check('job_progress error', 'Error: boom',
    competitor_job_progress_message(array('state' => 'error', 'message' => 'boom')));
check('job_progress unknown', 'Job not found.', competitor_job_progress_message(array('state' => 'unknown')));

// ---- competitor_item_title --------------------------------------------------
check('item_title strips Product: label', '10D7N EAST COAST USA',
    competitor_item_title("Product: 10D7N EAST COAST USA\nCode: 10UYY\n...", 'https://x.com/a'));
check('item_title uses first meaningful line', 'LAPLAND NORTHERN LIGHTS',
    competitor_item_title("title: LAPLAND NORTHERN LIGHTS\nmore", 'https://x.com/a'));
check('item_title falls back to url label when no text', 'Bali 5D4N',
    competitor_item_title('', 'https://comp.com/tours/bali-5d4n'));
check_true('item_title truncates long titles',
    mb_strlen(competitor_item_title(str_repeat('A very long tour name ', 20), 'https://x.com/a'), 'UTF-8') <= 91);
check('item_title skips bare nav lines (MENUMENU/Home)', 'Bali 5D4N Getaway',
    competitor_item_title("MENUMENU\nHome\nBali 5D4N Getaway\nFrom RM 1899", 'https://x.com/a'));

// ---- competitor_page_title (real <h1>/og:title, not first body line) --------
check('page_title prefers <h1>', 'Pakej Kunming',
    competitor_page_title('<title>Pakej Kunming | Muslim | Raha Holidays</title><h1 class="t">Pakej Kunming</h1>'));
check('page_title uses og:title when no h1', '9D8N New Zealand',
    competitor_page_title('<meta property="og:title" content="9D8N New Zealand"><body>x</body>'));
check('page_title strips site suffix from <title>', 'Vacations in Tunisia',
    competitor_page_title('<title>Vacations in Tunisia - Raha Holidays</title>'));
check('page_title empty when none', '', competitor_page_title('<div>no title here</div>'));

// ---- competitor_strip_shared_chrome (mega-menu / footer removal) -------------
$menu = "MENUMENU\nHome\nInternational\nThailand\nVietnam";
$foot = "Contact Us\nYour trusted travel partner";
$chromeItems = array(
    array('url' => 'https://x.com/a', 'text' => $menu . "\nVacations in Tunisia\n8 days 7 nights\n" . $foot),
    array('url' => 'https://x.com/b', 'text' => $menu . "\nVacations in Greece\n6 days 5 nights\n" . $foot),
    array('url' => 'https://x.com/c', 'text' => $menu . "\nVacations in Spain\n5 days 4 nights\n" . $foot),
    array('url' => 'https://x.com/d', 'text' => $menu . "\nVacations in Italy\n7 days 6 nights\n" . $foot),
);
$stripped = competitor_strip_shared_chrome($chromeItems);
check('strip_chrome removes leading menu (title now real)', 'Vacations in Tunisia',
    competitor_item_title($stripped[0]['text'], 'https://x.com/a'));
check_true('strip_chrome removes trailing footer',
    strpos($stripped[1]['text'], 'Your trusted travel partner') === false);
check_true('strip_chrome keeps the unique product body',
    strpos($stripped[2]['text'], 'Vacations in Spain') !== false);
check('strip_chrome no-op under 3 items', 2,
    count(competitor_strip_shared_chrome(array($chromeItems[0], $chromeItems[1]))));

// ---- competitor_job_public_view ---------------------------------------------
$pv = competitor_job_public_view(array('job' => 'j1', 'url' => 'https://x.com', 'state' => 'done',
    'count' => 3, 'ts' => '2026-08-19 21:00:00'));
check('job_view id/url/state', array('j1', 'https://x.com', 'done'),
    array($pv['job'], $pv['url'], $pv['state']));
check('job_view message', 'Done — 3', $pv['message']);
check('job_view no analysis_id when unset', 0, $pv['analysis_id']);
check('job_view exposes analysis_id', 42,
    competitor_job_public_view(array('state' => 'done', 'analysis_id' => 42))['analysis_id']);
check('job_view ai_crawl false by default', false, $pv['ai_crawl']);
check('job_view ai_crawl exposes flag', true,
    competitor_job_public_view(array('state' => 'done', 'ai_crawl' => 1))['ai_crawl']);
check('job_view is_paste false for crawl', false, $pv['is_paste']);
check('job_view is_paste true for paste mode', true,
    competitor_job_public_view(array('state' => 'running', 'mode' => 'paste'))['is_paste']);
check('job_view is_upload false for crawl', false, $pv['is_upload']);
check('job_view is_upload true for upload mode', true,
    competitor_job_public_view(array('state' => 'running', 'mode' => 'upload'))['is_upload']);
check('job_view is_paste false for upload mode', false,
    competitor_job_public_view(array('state' => 'running', 'mode' => 'upload'))['is_paste']);

// ---- competitor_job_host ----------------------------------------------------
check('job_host lowercases + strips www', 'example.com',
    competitor_job_host('https://WWW.Example.com/tour/5d4n'));
check('job_host keeps subdomain (only leading www stripped)', 'shop.example.com',
    competitor_job_host('http://shop.example.com/a'));
check('job_host bare host, no path', 'example.com', competitor_job_host('https://example.com'));
check('job_host empty for non-http', '', competitor_job_host('ftp://example.com'));
check('job_host empty for junk', '', competitor_job_host('Pasted text'));
check('job_host empty for empty', '', competitor_job_host(''));

// ---- competitor_group_crawl_jobs --------------------------------------------
// Three crawl runs across two sites; runs of the same host collapse to one row.
$cg_views = array(
    competitor_job_public_view(array('job' => 'a1', 'url' => 'https://acme.com/tour-1', 'state' => 'done',
        'count' => 4, 'analysed' => array('0' => 1, '1' => 1), 'cost_total' => 0.30, 'ts' => '2026-09-01 10:00:00')),
    competitor_job_public_view(array('job' => 'a2', 'url' => 'https://www.acme.com/tour-2', 'state' => 'done',
        'count' => 6, 'analysed' => array('0' => 1), 'cost_total' => 0.20, 'ts' => '2026-09-03 09:00:00')),
    competitor_job_public_view(array('job' => 'b1', 'url' => 'https://beta.com/x', 'state' => 'done',
        'count' => 2, 'cost_total' => 0.10, 'ts' => '2026-09-02 08:00:00')),
);
$cg = competitor_group_crawl_jobs($cg_views);
check('group_crawls collapses to one row per host', 2, count($cg));
// Newest run wins ordering: acme's latest run (09-03) beats beta (09-02).
check('group_crawls newest host first', 'acme.com', $cg[0]['host']);
check('group_crawls second host', 'beta.com', $cg[1]['host']);
check('group_crawls flags is_group', true, $cg[0]['is_group']);
check('group_crawls counts runs', 2, $cg[0]['runs_count']);
check('group_crawls sums cost across runs', 0.5, round($cg[0]['cost_total'], 2));
check('group_crawls sums analysed across runs', 3, $cg[0]['analysed']);
check('group_crawls shows latest run product count', 6, $cg[0]['count']);
check('group_crawls uses latest run url', 'https://www.acme.com/tour-2', $cg[0]['url']);
check('group_crawls uses latest run ts', '2026-09-03 09:00:00', $cg[0]['ts']);
check('group_crawls done host is reviewable', true, $cg[0]['reviewable']);
check('group_crawls done host not running', false, $cg[0]['running']);
// A host with a run still in progress surfaces the running state on the merged row.
$cg_run = competitor_group_crawl_jobs(array(
    competitor_job_public_view(array('job' => 'r1', 'url' => 'https://live.com/a', 'state' => 'done',
        'count' => 3, 'cost_total' => 0.10, 'ts' => '2026-09-01 10:00:00')),
    competitor_job_public_view(array('job' => 'r2', 'url' => 'https://live.com/b', 'state' => 'running',
        'ts' => '2026-09-04 10:00:00')),
));
check('group_crawls one host', 1, count($cg_run));
check('group_crawls surfaces running state', 'running', $cg_run[0]['state']);
check('group_crawls running flag true', true, $cg_run[0]['running']);
check('group_crawls ignores empty input', array(), competitor_group_crawl_jobs(array()));

// ---- competitor_model_supports_temperature ----------------------------------
check('temp: gpt-4o-mini yes', true, competitor_model_supports_temperature('gpt-4o-mini'));
check('temp: gpt-4.1 yes', true, competitor_model_supports_temperature('gpt-4.1'));
check('temp: gpt-5.5 no (reasoning)', false, competitor_model_supports_temperature('gpt-5.5'));
check('temp: gpt-5 no', false, competitor_model_supports_temperature('gpt-5'));
check('temp: gpt-5.5-pro no', false, competitor_model_supports_temperature('gpt-5.5-pro'));
check('temp: gpt-5-chat-latest yes', true, competitor_model_supports_temperature('gpt-5-chat-latest'));
check('temp: o4-mini no', false, competitor_model_supports_temperature('o4-mini'));
check('temp: o3 no', false, competitor_model_supports_temperature('o3'));
check('temp: blank defaults yes', true, competitor_model_supports_temperature(''));

// ---- competitor_web_search_tool_for_model -----------------------------------
check('websearch tool: gpt-4o uses preview', 'web_search_preview', competitor_web_search_tool_for_model('gpt-4o'));
check('websearch tool: gpt-5.5 uses GA', 'web_search', competitor_web_search_tool_for_model('gpt-5.5'));
check('websearch tool: o4-mini uses GA', 'web_search', competitor_web_search_tool_for_model('o4-mini'));
check('websearch tool: env override wins', 'web_search', competitor_web_search_tool_for_model('gpt-4o', 'web_search'));
check('websearch tool: blank override ignored', 'web_search_preview', competitor_web_search_tool_for_model('gpt-4o', ''));

// ---- competitor_estimate_cost knows gpt-5 family ----------------------------
check_true('cost: gpt-5.5 priced above mini fallback',
    competitor_estimate_cost('gpt-5.5', 1000000, 0) > competitor_estimate_cost('gpt-4o-mini', 1000000, 0));

// ---- competitor_build_discovery_agent ---------------------------------------
$disc = competitor_build_discovery_agent('https://comp.com', 8);
check_true('discovery agent uses web_search', strpos($disc['instructions'], 'web_search') !== false);
check_true('discovery agent caps at limit', strpos($disc['instructions'], '8') !== false);
check_true('discovery agent input carries base url', strpos($disc['input'], 'https://comp.com') !== false);
check_true('discovery agent no keyword line when blank', strpos($disc['input'], 'FOCUS') === false);
$discKw = competitor_build_discovery_agent('https://comp.com', 8, 'yunnan');
check_true('discovery agent instructions mention keyword focus', strpos($discKw['instructions'], 'yunnan') !== false);
check_true('discovery agent input mentions keyword', strpos($discKw['input'], 'yunnan') !== false);

// ---- competitor_ice_listing_api_url -----------------------------------------
check('ice_listing_api maps /web/listing + query',
    'https://www.gd.my/api/v1/series?keyword=EAST%20COAST&location_id=85&brands[]=GD_Standard',
    competitor_ice_listing_api_url('https://www.gd.my/web/listing?keyword=EAST%20COAST&location_id=85&brands[]=GD_Standard'));
check('ice_listing_api none for a post page', '',
    competitor_ice_listing_api_url('https://www.gd.my/web/posts/domestic/65'));
check('ice_listing_api none for listing without query', '',
    competitor_ice_listing_api_url('https://www.gd.my/web/listing'));
check('ice_listing_api none for junk', '', competitor_ice_listing_api_url('not a url'));

// ---- competitor_json_api_to_text --------------------------------------------
$apiJson = json_encode(array('data' => array('attributes' => array(
    'title' => 'SABAH      ',
    'body'  => "<table><tr><td>3D2N Semporna</td><td>T/CODE: SEMP</td>"
             . "<td><a href='https://cdn.co/x.pdf'>View File</a></td></tr></table>",
))));
$apiText = competitor_json_api_to_text($apiJson);
check_true('json_api_to_text has trimmed title', strpos($apiText, 'SABAH') !== false && strpos($apiText, 'SABAH   ') === false);
check_true('json_api_to_text has package name', strpos($apiText, '3D2N Semporna') !== false);
check_true('json_api_to_text has tour code', strpos($apiText, 'SEMP') !== false);
check_true('json_api_to_text keeps file url', strpos($apiText, 'https://cdn.co/x.pdf') !== false);
check('json_api_to_text empty on junk', '', competitor_json_api_to_text('nope'));
check('json_api_to_text handles data list', true,
    strpos(competitor_json_api_to_text(json_encode(array('data' => array(
        array('attributes' => array('title' => 'Tour A')),
        array('attributes' => array('title' => 'Tour B')),
    )))), 'Tour B') !== false);

// ---- competitor_ice_post_packages -------------------------------------------
$postJson = json_encode(array('data' => array('attributes' => array('title' => 'SABAH   ', 'body' =>
    "<table class='editorjs_table_attach'><tr><td>3D2N / 4D3N Semporna</td><td>(T/CODE: SEMP - Updated on 29 JAN 2026)</td><td><a href='https://cdn.co/x1'>📎 View File</a></td></tr></table>"
    . "<table class='editorjs_table_attach'><tr><td>5D4N Semporna + Sipadan-Kapalai (Non-divers)</td><td>(T/CODE: SEKK)</td><td><a href='https://cdn.co/x2'>View</a></td></tr></table>"
    . "<p>Some other content</p>"))));
$pkgs = competitor_ice_post_packages($postJson);
check('post_packages splits into 2', 2, count($pkgs));
check('post_packages name', '3D2N / 4D3N Semporna', $pkgs[0]['name']);
check('post_packages tour code', 'SEMP', $pkgs[0]['code']);
check('post_packages pdf file', 'https://cdn.co/x1', $pkgs[0]['file']);
check('post_packages carries post title', 'SABAH', $pkgs[0]['title']);
check('post_packages second code', 'SEKK', $pkgs[1]['code']);
check('post_packages empty on junk', array(), competitor_ice_post_packages('nope'));
check('post_packages empty when no attach tables', array(),
    competitor_ice_post_packages(json_encode(array('data' => array('attributes' => array('body' => '<p>hi</p>'))))));

// ---- competitor_ice_api_kind ------------------------------------------------
check('ice_api_kind series', 'series', competitor_ice_api_kind('https://www.gd.my/api/v1/series/6618'));
check('ice_api_kind posts', 'posts', competitor_ice_api_kind('https://www.gd.my/api/v1/posts/65'));
check('ice_api_kind none', '', competitor_ice_api_kind('https://www.gd.my/web/posts/domestic/65'));

// ---- competitor_ice_series_items --------------------------------------------
$seriesList = json_encode(array('itineraries' => array(
    array('id' => 6618, 'code' => 'FS-P4HAK-1', 'caption' => '4D3N Hainan  Together', 'country' => 'CHINA', 'price' => '1388.0', 'price_currency' => 'MYR'),
    array('id' => 6619, 'code' => 'FS-XYZ', 'caption' => '', 'country' => 'CHINA', 'price' => '999.50', 'price_currency' => 'MYR'),
    array('id' => 6618, 'code' => 'DUP'),   // duplicate id -> deduped
    array('code' => 'NOID'),                // no id -> skipped
)));
$items = competitor_ice_series_items($seriesList, 'https://www.gd.my/');
check('ice_series_items count (dedup + skip no-id)', 2, count($items));
check('ice_series_items url is detail api', 'https://www.gd.my/api/v1/series/6618', $items[0]['url']);
check('ice_series_items label with price trimmed', '4D3N Hainan Together · CHINA · MYR 1388 · FS-P4HAK-1', $items[0]['label']);
check('ice_series_items label falls back to code', 'FS-XYZ · CHINA · MYR 999.5', $items[1]['label']);
check('ice_series_items respects limit', 1, count(competitor_ice_series_items($seriesList, 'https://www.gd.my', 1)));
check('ice_series_items empty on junk', array(), competitor_ice_series_items('nope', 'https://x.com'));

// ---- competitor_ice_series_web_url ------------------------------------------
$webSeries = json_encode(array('code' => 'FS-4VSIT', 'caption' => '4D3N NHA TRANG',
    'tours' => array(array('departure_date' => '26/09/2026'), array('departure_date' => '24/10/2026'))));
check('ice_series_web_url with depart_date', 'https://www.gd.my/web/itinerary/FS-4VSIT?type=series&depart_date=26%2F09%2F2026',
    competitor_ice_series_web_url($webSeries, 'https://www.gd.my/'));
check('ice_series_web_url no tours = no depart_date', 'https://www.gd.my/web/itinerary/FS-4VSIT?type=series',
    competitor_ice_series_web_url(json_encode(array('code' => 'FS-4VSIT')), 'https://www.gd.my'));
check('ice_series_web_url unwraps data', 'https://x.com/web/itinerary/AB-1?type=series',
    competitor_ice_series_web_url(json_encode(array('data' => array('code' => 'AB-1'))), 'https://x.com'));
check('ice_series_web_url empty when no code', '', competitor_ice_series_web_url(json_encode(array('caption' => 'x')), 'https://www.gd.my'));
check('ice_series_web_url empty on junk', '', competitor_ice_series_web_url('nope', 'https://www.gd.my'));

// ---- competitor_ice_series_to_text ------------------------------------------
$seriesDetail = json_encode(array(
    'id' => 6618, 'code' => 'FS-P4HAK-1', 'country' => 'CHINA', 'price_currency' => 'MYR',
    'caption' => "4D3N LET'S GO TO HAINAN", 'other_caption' => '海南',
    'description' => "<p>Direct flight to Hainan</p><p>Shopping stop: Health museum</p>",
    'includings' => array('hotel' => '4 star', 'wifi' => true, 'meal_onboard' => false,
        'accommodation' => array('nights' => '3', 'type' => 'hotel')),
    'tours' => array(
        array('departure_date' => '2026-06-01', 'departure_location' => 'Penang', 'price' => '1388', 'guide_languages' => array('EN', 'CN'), 'highlight' => '<p>Great Wall of Hainan</p>'),
        array('departure_date' => '2026-07-01', 'departure_location' => 'KL', 'price' => '1488'),
    ),
    'file_copy_url' => 'https://www.gd.my/i/FS-P4HAK-1',
));
$stext = competitor_ice_series_to_text($seriesDetail);
check_true('ice_series_to_text has product name', strpos($stext, "4D3N LET'S GO TO HAINAN") !== false);
check_true('ice_series_to_text has code+country', strpos($stext, 'Code: FS-P4HAK-1') !== false && strpos($stext, 'Country: CHINA') !== false);
check_true('ice_series_to_text renders description', strpos($stext, 'Direct flight to Hainan') !== false);
check_true('ice_series_to_text has inclusions', strpos($stext, 'hotel: 4 star') !== false && preg_match('/Inclusions:.*wifi/s', $stext) === 1);
check_true('ice_series_to_text lists not-included', strpos($stext, 'Not included:') !== false && strpos($stext, 'meal onboard') !== false);
check_true('ice_series_to_text nested including', strpos($stext, 'accommodation:') !== false);
check_true('ice_series_to_text has departures', strpos($stext, '2026-06-01 from Penang: 1388') !== false);
check_true('ice_series_to_text has guide langs', strpos($stext, 'guide: EN/CN') !== false);
check_true('ice_series_to_text has highlights', strpos($stext, 'Great Wall of Hainan') !== false);
check_true('ice_series_to_text has file url', strpos($stext, 'https://www.gd.my/i/FS-P4HAK-1') !== false);
check('ice_series_to_text empty on junk', '', competitor_ice_series_to_text('nope'));

// ---- competitor_ice_meals_text ----------------------------------------------
check('ice_meals_text string', 'Breakfast / Lunch', competitor_ice_meals_text('Breakfast / Lunch'));
check('ice_meals_text list', 'Breakfast, Dinner', competitor_ice_meals_text(array('Breakfast', 'Dinner')));
check('ice_meals_text bool map keeps true', 'Breakfast, Dinner', competitor_ice_meals_text(array('breakfast' => true, 'lunch' => false, 'dinner' => true)));
check('ice_meals_text empty', '', competitor_ice_meals_text(null));

// ---- competitor_ice_itinerary_text ------------------------------------------
$plans = array(
    array('day' => 1, 'title' => 'Arrive Nha Trang', 'title_two' => '抵达芽庄', 'display_meals' => array('dinner' => true),
        'activities' => array(
            array('title' => 'Hon Chong', 'title_two' => '钟屿石岬角', 'tagline' => 'Coastal rocks', 'category' => 'sightseeing'),
            array('title' => 'Night Market', 'subtitle' => 'Free time', 'category' => 'sightseeing'),
        )),
    array('day' => 3, 'title' => 'Free & Easy', 'display_meals' => 'Breakfast',
        'activities' => array(array('title' => 'Optional VinWonders', 'tagline' => 'USD 55', 'category' => 'recommended_optional'))),
);
$itin = competitor_ice_itinerary_text($plans, '<p>Overview: relaxed beach tour</p>');
check_true('itinerary_text has general_content', strpos($itin, 'Overview: relaxed beach tour') !== false);
check_true('itinerary_text day1 bilingual title', strpos($itin, 'Day 1: Arrive Nha Trang (抵达芽庄)') !== false);
check_true('itinerary_text day1 meals', strpos($itin, '[Meals: Dinner]') !== false);
check_true('itinerary_text activity bilingual + tagline', strpos($itin, 'Hon Chong (钟屿石岬角) — Coastal rocks') !== false);
check_true('itinerary_text activity subtitle', strpos($itin, 'Night Market: Free time') !== false);
check_true('itinerary_text keeps explicit day number', strpos($itin, 'Day 3: Free & Easy') !== false);
check('itinerary_text empty when no plans', '', competitor_ice_itinerary_text(array(), ''));

// integration: series_to_text folds in the structured itinerary
$seriesWithItin = json_encode(array('code' => 'AB-1', 'caption' => 'Beach Trip',
    'itinerary_plans' => $plans));
check_true('ice_series_to_text includes itinerary', strpos(competitor_ice_series_to_text($seriesWithItin), 'Day 1: Arrive Nha Trang') !== false);

// ---- competitor_ice_includings_split ----------------------------------------
$sp = competitor_ice_includings_split(array(
    'airport_taxes' => true, 'group_departure' => true, 'luggage' => true, 'wifi' => false,
    'meal_onboard' => true, 'hotel' => true, 'gratuities' => false, 'acf' => false, 'accommodation' => false));
check_true('includings_split keeps real inclusions',
    in_array('hotel', $sp['inclusions']) && in_array('airport taxes', $sp['inclusions']) && in_array('meal onboard', $sp['inclusions']));
check_true('includings_split real exclusions',
    in_array('wifi', $sp['exclusions']) && in_array('gratuities', $sp['exclusions']));
check_true('includings_split drops opaque acf',
    ! in_array('acf', $sp['exclusions']) && ! in_array('acf', $sp['inclusions']));
check_true('includings_split drops bare bool accommodation',
    ! in_array('accommodation', $sp['exclusions']) && ! in_array('accommodation', $sp['inclusions']));
check_true('includings_split keeps scalar value',
    in_array('hotel: 4 star', competitor_ice_includings_split(array('hotel' => '4 star'))['inclusions']));
check_true('includings_split keeps nested accommodation object',
    count(competitor_ice_includings_split(array('accommodation' => array('nights' => '3')))['inclusions']) === 1);
check('includings_split empty on junk', array('inclusions' => array(), 'exclusions' => array()),
    competitor_ice_includings_split('nope'));

// ---- competitor_ice_flights_text --------------------------------------------
$flightTours = array(
    array('departure_date' => '24/10/2026', 'flights' => array()),           // empty leg -> skipped
    array('departure_date' => '26/09/2026', 'flights' => array(
        array('airline' => 'AirAsia', 'flight_no' => 'AK 204', 'from_airport' => 'Kuala Lumpur (KUL)',
            'to_airport' => 'Nha Trang (CXR)', 'departure_date' => '26/09/2026', 'departure_time' => '10:10',
            'arrival_date' => '26/09/2026', 'arrival_time' => '11:30'),
        array('airline' => 'AirAsia', 'flight_no' => 'AK 205', 'from_airport' => 'Nha Trang (CXR)',
            'to_airport' => 'Kuala Lumpur (KUL)', 'departure_date' => '29/09/2026', 'departure_time' => '12:00',
            'arrival_date' => '29/09/2026', 'arrival_time' => '15:25'),
    )),
);
$ftext = competitor_ice_flights_text($flightTours);
check_true('ice_flights_text renders outbound leg', strpos($ftext, 'AirAsia AK 204: Kuala Lumpur (KUL) -> Nha Trang (CXR), depart 26/09/2026 10:10, arrive 26/09/2026 11:30') !== false);
check_true('ice_flights_text renders return leg', strpos($ftext, 'AirAsia AK 205: Nha Trang (CXR) -> Kuala Lumpur (KUL)') !== false);
check('ice_flights_text empty when no flights', '', competitor_ice_flights_text(array(array('flights' => array()))));
check('ice_flights_text empty on junk', '', competitor_ice_flights_text('nope'));

// integration: series_to_text surfaces the flight legs
$seriesWithFlights = json_encode(array('code' => 'FS-4VSIT', 'caption' => 'Nha Trang', 'tours' => $flightTours));
check_true('ice_series_to_text includes flights', strpos(competitor_ice_series_to_text($seriesWithFlights), 'Flights:') !== false
    && strpos(competitor_ice_series_to_text($seriesWithFlights), 'AK 204') !== false);

// ---- competitor_scrape_is_thin ----------------------------------------------
check_true('scrape_is_thin true for empty', competitor_scrape_is_thin(''));
check_true('scrape_is_thin true for JS skeleton', competitor_scrape_is_thin('Loading...'));
check('scrape_is_thin false for rich text', false,
    competitor_scrape_is_thin(str_repeat('Bali tour itinerary day. ', 60)));

// ---- competitor_resolve_url (crawler) ---------------------------------------
$B = 'https://comp.com/tours/asia';
check('resolve_url absolute kept', 'https://comp.com/x', competitor_resolve_url($B, 'https://comp.com/x'));
check('resolve_url root-relative', 'https://comp.com/tour/bali', competitor_resolve_url($B, '/tour/bali'));
check('resolve_url protocol-relative', 'https://cdn.com/a', competitor_resolve_url($B, '//cdn.com/a'));
check('resolve_url dir-relative', 'https://comp.com/tours/bali-5d', competitor_resolve_url($B, 'bali-5d'));
check('resolve_url strips fragment', 'https://comp.com/tours/x', competitor_resolve_url($B, 'x#top'));
check('resolve_url drops mailto', '', competitor_resolve_url($B, 'mailto:a@b.com'));
check('resolve_url drops javascript', '', competitor_resolve_url($B, 'javascript:void(0)'));
check('resolve_url empty on blank', '', competitor_resolve_url($B, '   '));

// ---- competitor_extract_links -----------------------------------------------
$page = '<a href="/tours/bali-5d4n">Bali</a> <a href="tours/japan-6d">JP</a>'
      . '<a href="https://other.com/x">off</a> <a href="/logo.png">img</a>'
      . '<a href="mailto:a@b.com">mail</a> <a href="/tours/bali-5d4n">dup</a>'
      . '<a href="/about">About</a>';
$links = competitor_extract_links($page, 'https://comp.com/');
check('extract_links same-host only + deduped + no assets', array(
    'https://comp.com/tours/bali-5d4n',
    'https://comp.com/tours/japan-6d',
    'https://comp.com/about',
), $links);
check('extract_links empty on junk', array(), competitor_extract_links('nope', 'https://comp.com/'));

// ---- competitor_is_product_url ----------------------------------------------
check_true('is_product_url tour slug', competitor_is_product_url('https://comp.com/tours/bali-5d4n'));
check_true('is_product_url package slug', competitor_is_product_url('https://comp.com/holiday-packages/japan-6d5n'));
check('is_product_url skips bare listing', false, competitor_is_product_url('https://comp.com/tours'));
check('is_product_url skips homepage', false, competitor_is_product_url('https://comp.com/'));
check('is_product_url skips about', false, competitor_is_product_url('https://comp.com/tours/about'));
check('is_product_url skips blog', false, competitor_is_product_url('https://comp.com/blog/best-tours'));
check('is_product_url needs a signal', false, competitor_is_product_url('https://comp.com/deals/bali-special-offer'));
// a duration code (5D4N) qualifies on its own, even without the word tour/package
check_true('is_product_url accepts duration-code slug', competitor_is_product_url('https://comp.com/promo/beijing-5d4n'));
// category/listing hubs are NOT products (chanbrothers nav links)
check('is_product_url skips travelstyles hub', false, competitor_is_product_url('https://www.chanbrothers.com/travelstyles/package-tours'));
check('is_product_url skips destinations hub', false, competitor_is_product_url('https://www.chanbrothers.com/destinations/europe/finland'));
check_true('is_product_url keeps real tour under package-tours', competitor_is_product_url('https://www.chanbrothers.com/package-tours/europe/finland/arctic-circle-adventure'));
// WordPress taxonomy/archive roots are listings — dropped even when slug has a keyword
// flat WordPress permalinks: a tour lives at root as a specific multi-word slug
check_true('is_product_url keeps flat root tour permalink',
    competitor_is_product_url('https://www.holidaygogogo.com/3d2n-kl-bukit-tinggi-genting-tour-suggested-itinerary/'));
check_true('is_product_url keeps flat root holiday permalink',
    competitor_is_product_url('https://www.holidaygogogo.com/3d2n-holiday-in-sun-beach-resort-pulau-tioman/'));
check('is_product_url skips bare single-word hub (no hyphen)', false,
    competitor_is_product_url('https://comp.com/tours'));
check('is_product_url skips flat slug without keyword', false,
    competitor_is_product_url('https://www.holidaygogogo.com/bukit-panchor-recreational-forest-penang/'));
check('is_product_url skips WP /theme/ taxonomy', false, competitor_is_product_url('https://www.holidaygogogo.com/theme/bagan-datuk-tour/'));
check('is_product_url skips WP /theme/ duration taxonomy', false, competitor_is_product_url('https://www.holidaygogogo.com/theme/3-7-days/'));

// ---- competitor_is_category_url (drill targets) -----------------------------
check_true('category_url plural -tours slug', competitor_is_category_url('https://www.wtstravel.com.sg/japan-tours/'));
check_true('category_url listing.php', competitor_is_category_url('https://x.com/en/listing.php?travelbadges=Cruise'));
check_true('category_url /category/ path', competitor_is_category_url('https://x.com/category/asia'));
check_true('category_url travelstyle hub', competitor_is_category_url('https://www.chanbrothers.com/travelstyles/package-tours'));
check('category_url false for singular tour product', false, competitor_is_category_url('https://x.com/tours/bali-5d4n-tour'));
check('category_url false for flat tour permalink', false, competitor_is_category_url('https://x.com/3d2n-genting-tour-itinerary'));
check('category_url false for raha tours product', false, competitor_is_category_url('https://rahaholidays.com/tours/yunnan-travel/'));
// Search / filter-result pages are listings (easyeurope /tour-search/?region=… flooded a crawl).
check_true('category_url tour-search page', competitor_is_category_url('https://easyeurope.com.my/tour-search/'));
check_true('category_url search path', competitor_is_category_url('https://x.com/search/'));
check_true('category_url ?region= filter', competitor_is_category_url('https://easyeurope.com.my/tour-search/?region=africa'));
check_true('category_url ?country= filter', competitor_is_category_url('https://easyeurope.com.my/tour-search/?country=egypt'));
check('category_url false for product with harmless query', false, competitor_is_category_url('https://x.com/bali-5d4n-tour/?utm_source=fb'));
check('category_url false for research slug (not -search-)', false, competitor_is_category_url('https://x.com/tours/market-research-trip/'));

// ---- competitor_filter_urls_by_keyword (targeted crawl) ---------------------
$kwUrls = array(
    'https://rahaholidays.com/tours/yunnan-travel/',
    'https://rahaholidays.com/tours/trip-korea/',
    'https://rahaholidays.com/tour/pakej-kunming/',
    'https://rahaholidays.com/vacations/vacations-in-tunisia/',
);
check('keyword filter matches slug (yunnan)', array('https://rahaholidays.com/tours/yunnan-travel/'),
    competitor_filter_urls_by_keyword($kwUrls, 'yunnan'));
check('keyword filter empty keyword returns all', 4,
    count(competitor_filter_urls_by_keyword($kwUrls, '')));
check('keyword filter multi-word matches ANY', 2,
    count(competitor_filter_urls_by_keyword($kwUrls, 'korea tunisia')));
check('keyword filter is case-insensitive', 1,
    count(competitor_filter_urls_by_keyword($kwUrls, 'KUNMING')));
check('keyword filter ignores domain (raha matches nothing in path)', 0,
    count(competitor_filter_urls_by_keyword($kwUrls, 'raha')));
check('keyword filter no match returns empty', 0,
    count(competitor_filter_urls_by_keyword($kwUrls, 'antarctica')));

// ---- competitor_matches_keyword (URL slug OR content) -----------------------
$body = "Product: Trip Yunnan-9D7N\nDay 1 Kunming arrival\nDay 2 Dali old town";
check_true('matches_keyword by URL slug', competitor_matches_keyword('https://x.com/tours/yunnan-travel/', 'no body', 'yunnan'));
check_true('matches_keyword by content (kunming in body, not url)',
    competitor_matches_keyword('https://x.com/tours/yunnan-travel/', $body, 'kunming'));
check('matches_keyword false when neither', false,
    competitor_matches_keyword('https://x.com/tours/yunnan-travel/', $body, 'antarctica'));
check_true('matches_keyword empty keyword = always', competitor_matches_keyword('https://x.com/a', '', ''));
check_true('matches_keyword multi-word ANY', competitor_matches_keyword('https://x.com/tours/bali/', $body, 'japan dali'));

// ---- competitor_item_meta (duration + snippet, no price) --------------------
$m1 = competitor_item_meta("Product: Trip Yunnan-9D7N\nDay 1 Kunming\nDay 2 Dali", 'Trip Yunnan-9D7N');
check('item_meta duration from title (9D7N)', '9D7N', $m1['duration']);
check('item_meta snippet skips the title line', 'Day 1 Kunming', $m1['snippet']);
$m2 = competitor_item_meta("Amazing 5 Days 4 Nights Bali\nBeach and temples", 'Bali Escape');
check('item_meta duration "5 Days 4 Nights"', '5 Days 4 Nights', $m2['duration']);
$m3 = competitor_item_meta('', '');
check('item_meta empty when no text', '', $m3['duration'] . $m3['snippet']);

// ---- competitor_item_kind (package when priced, else itinerary) -------------
check('item_kind price RM -> package', 'package', competitor_item_kind("Bali 5D4N tour\nFrom RM1,899 per pax"));
check('item_kind price SGD with space -> package', 'package', competitor_item_kind('Special deal SGD 250 nett'));
check('item_kind price $ symbol -> package', 'package', competitor_item_kind('Book now for $1,200'));
check('item_kind no price -> itinerary', 'itinerary', competitor_item_kind("Day 1 Kunming\nDay 2 Dali\nDay 3 return"));
check('item_kind bare number (no currency) -> itinerary', 'itinerary', competitor_item_kind('Visit 1899 heritage sites over 5 days'));
check('item_kind empty -> itinerary', 'itinerary', competitor_item_kind(''));
check('is_product_url skips WP /tag/ taxonomy', false, competitor_is_product_url('https://www.holidaygogogo.com/tag/china-tour-packages/'));
check('is_product_url skips WP /author/ archive', false, competitor_is_product_url('https://www.holidaygogogo.com/author/holiday-tour-admin/'));
check('is_product_url skips date archive', false, competitor_is_product_url('https://www.holidaygogogo.com/2024/05/best-tour-deals/'));
check_true('is_product_url keeps slug that merely contains "tag" (vintage)', competitor_is_product_url('https://comp.com/tours/vintage-heritage-tour'));

// ---- competitor_file_binary_kind --------------------------------------------
check('file_kind pdf', 'pdf', competitor_file_binary_kind("%PDF-1.4\nrest"));
check('file_kind jpeg', 'image', competitor_file_binary_kind("\xFF\xD8\xFF\xE0JFIF"));
check('file_kind png', 'image', competitor_file_binary_kind("\x89PNG\r\n\x1a\n...."));
check('file_kind gif', 'image', competitor_file_binary_kind("GIF89a...."));
check('file_kind webp', 'image', competitor_file_binary_kind("RIFF\x24\x00\x00\x00WEBP"));
check('file_kind none for html', '', competitor_file_binary_kind("<html><body>"));
check('file_kind none for tiny', '', competitor_file_binary_kind("ab"));

// ---- competitor_extract_file_links ------------------------------------------
$fileHtml = '<a href="/files/bali.pdf">Bali</a> <a href="brochure.JPG">img</a> '
    . '<a href="https://cdn.co/x.pdf">cdn</a> <a href="/about">About</a> '
    . '<a href="/files/bali.pdf">dup</a> <a href="deck.pptx">deck</a>';
// documents only — linked images (brochure.JPG) are excluded as likely photos.
check('file_links docs only, resolved, deduped', array(
    'https://comp.com/files/bali.pdf',
    'https://cdn.co/x.pdf',
    'https://comp.com/tours/deck.pptx',
), competitor_extract_file_links($fileHtml, 'https://comp.com/tours/'));
check('file_links empty on junk', array(), competitor_extract_file_links('no anchors', 'https://comp.com/'));
check('file_links empty on non-string', array(), competitor_extract_file_links(null, 'https://comp.com/'));
// junk legal/policy PDFs are filtered out
check('file_links drops legal/policy junk', array('https://cdn.co/bali-itinerary.pdf'),
    competitor_extract_file_links(
        '<a href="https://cdn.co/bali-itinerary.pdf">Bali</a>'
        . '<a href="/media/privacy-policy.pdf">privacy</a>'
        . '<a href="/docs/pdpa-notice.pdf">pdpa</a>'
        . '<a href="/x/code-of-conduct.pdf">conduct</a>', 'https://comp.com/'));

// ---- competitor_is_junk_file_url --------------------------------------------
check_true('junk_file privacy', competitor_is_junk_file_url('https://x.com/privacy-policy.pdf'));
check_true('junk_file pdpa', competitor_is_junk_file_url('https://x.com/media/pdpa-notice.pdf'));
check_true('junk_file code of conduct', competitor_is_junk_file_url('https://x.com/code-of-conduct.pdf'));
check('junk_file real brochure kept', false, competitor_is_junk_file_url('https://x.com/bali-5d4n-itinerary.pdf'));
// A "-TC" terms-and-conditions PDF (easyeurope 20260513-EE-TC.pdf) was polluting every page.
check_true('junk_file EE-TC terms pdf', competitor_is_junk_file_url('https://easyeurope.com.my/wp-content/uploads/20260513-EE-TC.pdf'));
check_true('junk_file underscore tc pdf', competitor_is_junk_file_url('https://x.com/booking_tc.pdf'));
check('junk_file brochure code ending -tc kept', false, competitor_is_junk_file_url('https://x.com/9d7n-swmj-enotc.pdf'));

// ---- competitor_jsonld_product_text -----------------------------------------
$ldTrip = '<script type="application/ld+json">' . json_encode(array(
    '@context' => 'https://schema.org', '@type' => 'Trip', 'name' => 'Best of Japan 10D',
    'description' => 'Tokyo Kyoto Osaka highlights tour.',
    'offers' => array('@type' => 'Offer', 'price' => '4999', 'priceCurrency' => 'USD'))) . '</script>';
check_true('jsonld_product reads Trip name', strpos(competitor_jsonld_product_text($ldTrip), 'Best of Japan 10D') !== false);
check_true('jsonld_product reads offer price', strpos(competitor_jsonld_product_text($ldTrip), '4999') !== false);
check('jsonld_product ignores non-product type', '',
    competitor_jsonld_product_text('<script type="application/ld+json">{"@type":"WebSite","name":"X"}</script>'));
$ldGraph = '<script type="application/ld+json">' . json_encode(array('@graph' => array(
    array('@type' => 'Organization', 'name' => 'Org Inc'),
    array('@type' => 'Product', 'name' => 'Bali 5D4N', 'description' => 'beach tour')))) . '</script>';
check_true('jsonld_product finds product inside @graph',
    strpos(competitor_jsonld_product_text($ldGraph), 'Bali 5D4N') !== false);
check_true('jsonld_product skips the Organization node in @graph',
    strpos(competitor_jsonld_product_text($ldGraph), 'Org Inc') === false);
check('jsonld_product empty when no ld+json', '', competitor_jsonld_product_text('<div>hi</div>'));

// ---- competitor_jsonld_types + looks_like_listing (drop catalogue pages) -----
$ldTypes = competitor_jsonld_types($ldTrip . '<script type="application/ld+json">{"@type":"BreadcrumbList"}</script>');
check_true('jsonld_types lists all @types (lowercased)',
    in_array('trip', $ldTypes, true) && in_array('breadcrumblist', $ldTypes, true));
$ldList = '<script type="application/ld+json">{"@type":"CollectionPage","name":"All Tours"}</script>';
check_true('jsonld_types reads CollectionPage', in_array('collectionpage', competitor_jsonld_types($ldList), true));
check('jsonld_types empty when none', array(), competitor_jsonld_types('<div>hi</div>'));
check_true('looks_like_listing true for CollectionPage-only page',
    competitor_looks_like_listing(competitor_jsonld_types($ldList)));
check_true('looks_like_listing true for ItemList search page',
    competitor_looks_like_listing(array('searchresultspage', 'itemlist')));
check('looks_like_listing false when a product type is also present', false,
    competitor_looks_like_listing(array('collectionpage', 'product')));
check('looks_like_listing false for a plain product page', false,
    competitor_looks_like_listing(competitor_jsonld_types($ldTrip)));
check('looks_like_listing false when no JSON-LD (no evidence)', false,
    competitor_looks_like_listing(array()));

// ---- competitor_text_looks_like_listing (content catch, no JSON-LD) ----------
$onePrice = "Bali 5D4N Getaway\nFrom RM 1899 per pax\nDay 1 Arrival\nDay 2 Ubud\nDay 3 Beach\nSingle supplement RM 500";
check('text_listing false for single product (one Day 1)', false,
    competitor_text_looks_like_listing($onePrice));
// A single tour with a departure-price table (many prices, ONE itinerary) must NOT
// be dropped — this was the Trip Yunnan-9D7N false positive (14 prices, 1 Day 1).
$manyPrices = "Trip Yunnan-9D7N\nDay 1 Kunming\nDay 2 Dali";
foreach (array(3299,3599,3899,4099,4399,4599,4899,5099,5399,5599,5899,6199,6599,6999) as $p) {
    $manyPrices .= "\nDeparture RM {$p}";
}
check('text_listing keeps single tour with many departure prices', false,
    competitor_text_looks_like_listing($manyPrices));
$manyItins = "Best of Asia\nDay 1 China\nDay 2 Beijing\nJapan Highlights\nDay 1 Tokyo\nDay 2 Kyoto\nKorea Escape\nDay 1 Seoul\nDay 2 Busan";
check_true('text_listing true for 3+ itineraries (multiple Day 1)',
    competitor_text_looks_like_listing($manyItins));
check('text_listing false on empty', false, competitor_text_looks_like_listing(''));

// ---- competitor_count_tour_cards --------------------------------------------
$hainanCat = "HAINAN 1 - 60 days 4D3N GO AROUND HAINAN FrRM 2,998 4D3N SANYA ATLANTIS GETAWAY FrRM 3,198 "
    . "5D3N MEET IN HAINAN FrRM 2,798 5D4N FAVOURABLE HAINAN FrRM 3,099 6D4N BREEZY HAINAN FrRM 3,499";
check('count_tour_cards counts distinct duration-titled cards', 5, competitor_count_tour_cards($hainanCat));
// The SAME tour's duration repeated (title + header + breadcrumb) collapses to 1.
check('count_tour_cards collapses one tour repeated', 1,
    competitor_count_tour_cards("9D7N MAJESTIC YUNNAN\n9D7N MAJESTIC YUNNAN\nDeparture 9D7N MAJESTIC YUNNAN"));
check('count_tour_cards 0 on empty', 0, competitor_count_tour_cards(''));

// ---- competitor_is_destination_listing (title-aware catalogue catch) ---------
// A bare-destination page ("HAINAN") listing many cards with NO itinerary of its own.
check_true('destination_listing true for place title + many cards, no itinerary',
    competitor_is_destination_listing('HAINAN', $hainanCat));
// A REAL tour whose itinerary we failed to scrape but whose body mentions related tours
// must NOT be dropped — its title leads with a duration, so the title guard protects it.
$realTourNoItin = "8D7N CHARMING JIANGNAN + JIANGXI FrRM 3,799 You may also like: "
    . "9D7N MAJESTIC YUNNAN FrRM 4,299 7D5N CLASSIC ZHANGJIAJIE FrRM 3,198";
check('destination_listing false for real tour (duration-leading title)', false,
    competitor_is_destination_listing('8D7N CHARMING JIANGNAN + TRENDING JIANGXI TOUR (B1F1)', $realTourNoItin));
// A real tour WITH its own day-by-day itinerary is never a catalogue, even titleless.
check('destination_listing false when page has own itinerary', false,
    competitor_is_destination_listing('CHENGDU', "CHENGDU 9D7N TOUR\nDay 1 Arrive\nDay 2 Panda\nDay 3 Leshan\n5D4N OTHER FrRM 1"));
// Below the card threshold → not a catalogue.
check('destination_listing false below card threshold', false,
    competitor_is_destination_listing('HAINAN', "HAINAN 4D3N GO AROUND FrRM 2,998 5D3N MEET IN HAINAN FrRM 2,798"));
check('destination_listing false on empty', false, competitor_is_destination_listing('HAINAN', ''));

// ---- competitor_has_product_signal + looks_like_article ---------------------
check_true('product_signal on price', competitor_has_product_signal('Great trip, only RM 1899 per pax'));
check_true('product_signal on duration code', competitor_has_product_signal('Bali 5D4N package'));
check_true('product_signal on itinerary', competitor_has_product_signal('Day 1 arrival\nDay 2 city tour'));
check('product_signal false on pure prose', false,
    competitor_has_product_signal('We visited the spice garden and enjoyed the fragrant herbs and lovely weather.'));
$prose = str_repeat('The spice garden in Penang is a lovely place to spend an afternoon among fragrant herbs. ', 20);
check_true('looks_like_article true for long prose w/o signal', competitor_looks_like_article($prose));
check('looks_like_article false when it has a price', false,
    competitor_looks_like_article($prose . ' Book this 3D2N tour from RM 899.'));
check('looks_like_article false when thin (benefit of doubt)', false,
    competitor_looks_like_article('Short SPA product shell.'));

// ---- competitor_has_basic_tour_sections (crawl quality gate) -----------------
$fullTour = "Bali 5D4N\nDay 1 arrival and welcome dinner\nDay 2 Ubud tour\n"
    . "Inclusions:\n- 4 nights hotel\n- daily breakfast\nExclusions:\n- personal expenses";
check_true('basic_sections true: itinerary + inclusions', competitor_has_basic_tour_sections($fullTour));
check_true('basic_sections true: "price includes" wording',
    competitor_has_basic_tour_sections("Day 1 arrival\nDay 2 tour\nThe price includes accommodation and meals."));
check_true('basic_sections true: "Day 01" padded',
    competitor_has_basic_tour_sections("Day 01 arrival. Inclusions: hotel, meals, transfers."));
check('basic_sections false: itinerary but no inclusions', false,
    competitor_has_basic_tour_sections("Day 1 arrival\nDay 2 city tour\nDay 3 departure and shopping"));
check('basic_sections false: inclusions but no itinerary', false,
    competitor_has_basic_tour_sections("Package includes hotel and breakfast. Inclusions: transfers."));
check('basic_sections false: stray "include" is not an inclusions section', false,
    competitor_has_basic_tour_sections("Day 1 tour. Our highlights include stunning views and great food."));
check('basic_sections false on empty', false, competitor_has_basic_tour_sections(''));
check('basic_sections false on non-string', false, competitor_has_basic_tour_sections(null));

// ---- competitor_has_tour_itinerary (itinerary-primary crawl gate) -----------
check_true('tour_itinerary true: two distinct days',
    competitor_has_tour_itinerary("Bali tour\nDay 1 arrival and dinner\nDay 2 Ubud and rice terraces"));
check_true('tour_itinerary true: padded Day 01/Day 02',
    competitor_has_tour_itinerary("Day 01 arrival\nDay 02 city tour\nDay 03 departure"));
check_true('tour_itinerary true: non-consecutive days (Day 1 + Day 5)',
    competitor_has_tour_itinerary("Day 1 fly in. Day 5 fly home."));
check_true('tour_itinerary true: single Day 1 + duration code',
    competitor_has_tour_itinerary("Genting 2D1N getaway. Day 1: theme park and hotel."));
check('tour_itinerary false: lone stray Day 1, no duration', false,
    competitor_has_tour_itinerary("Day 1 of your adventure starts here. Book now for great deals."));
check('tour_itinerary false: same day repeated, not day-by-day', false,
    competitor_has_tour_itinerary("Day 1 morning session. Day 1 afternoon session. Day 1 evening."));
check('tour_itinerary false: no day markers at all', false,
    competitor_has_tour_itinerary("A wonderful holiday package with hotels and meals included."));
check('tour_itinerary false on empty', false, competitor_has_tour_itinerary(''));
check('tour_itinerary false on non-string', false, competitor_has_tour_itinerary(null));

// ---- competitor_is_tour_page (crawl KEEP gate, itinerary OR duration+price) --
check_true('tour_page: day-by-day itinerary',
    competitor_is_tour_page("Bali tour\nDay 1 arrival\nDay 2 Ubud\nDay 3 departure"));
// chanbrothers-style: itinerary behind a tab, but clearly a bookable tour.
$cbLike = "7 DAYS BLOSSOMS OF TAIWAN\nCHECK AVAILABILITY From S\$1,488\nOVERVIEW ITINERARY DATES & PRICES\n"
    . "Overnight Stay 6 nights\n5 Cities yilan, taichung, miaoli, nantou, taipei\nMeals: Flower Home Specialty, Xiao Long Bao";
check_true('tour_page: duration + price (tab-hidden itinerary)', competitor_is_tour_page($cbLike));
check_true('tour_page: 5D4N + RM price', competitor_is_tour_page('Genting 5D4N package from RM899 per pax'));
check('tour_page false: duration but no price', false,
    competitor_is_tour_page('A relaxing 7 days exploring the countryside and its people.'));
check('tour_page false: price but no duration', false,
    competitor_is_tour_page('Gift vouchers from RM100 available at our stores.'));
check('tour_page false: plain article', false,
    competitor_is_tour_page('The best noodle shops in Taipei and where to find them.'));
check('tour_page false on empty', false, competitor_is_tour_page(''));

// ---- competitor_needs_more_content (reading escalation trigger) --------------
$fullPage = "Bali 5D4N Cultural Escape\n"
    . "Day 1 Arrival in Denpasar, transfer to hotel and welcome dinner by the beach. "
    . str_repeat("Explore the temples, rice terraces and local markets throughout the day. ", 8)
    . "\nDay 2 Ubud art villages, Tegallalang rice terrace and a traditional dance show.\n"
    . "Inclusions:\n- 4 nights hotel accommodation\n- daily breakfast and 3 dinners\n- English speaking guide and coach transfers";
check_true('needs_more_content sanity: fixture is not thin', mb_strlen($fullPage, 'UTF-8') >= 500);
check('needs_more_content false: full tour page (itinerary + inclusions)', false,
    competitor_needs_more_content($fullPage));
check_true('needs_more_content true: thin/empty', competitor_needs_more_content(''));
check_true('needs_more_content true: short shell', competitor_needs_more_content('Loading...'));
// >500 chars of boilerplate but NO itinerary/inclusions -> still escalate (the fix).
$boiler = str_repeat("Book Now. Enquire now. Follow us on Facebook. ", 40);
check_true('needs_more_content true: long boilerplate w/o tour sections',
    competitor_needs_more_content($boiler));
// A price-only teaser (has a price signal but no itinerary+inclusions) -> escalate.
check_true('needs_more_content true: price teaser, itinerary behind a tab',
    competitor_needs_more_content(str_repeat('Great value from RM1899 per pax. ', 30)));

// ---- competitor_is_boilerplate_line / competitor_strip_boilerplate -----------
check_true('boilerplate: cookie consent', competitor_is_boilerplate_line('We use cookies to improve your experience'));
check_true('boilerplate: subscribe newsletter', competitor_is_boilerplate_line('Subscribe to our newsletter'));
check_true('boilerplate: follow us', competitor_is_boilerplate_line('Follow us on Facebook'));
check_true('boilerplate: standalone social label', competitor_is_boilerplate_line('Instagram'));
check_true('boilerplate: breadcrumb', competitor_is_boilerplate_line('Home > Tours > Japan > Osaka'));
check_true('boilerplate: CTA button', competitor_is_boilerplate_line('Book Now'));
check_true('boilerplate: add to wishlist', competitor_is_boilerplate_line('Add to Wishlist'));
check_true('boilerplate: copyright footer', competitor_is_boilerplate_line('© 2024 Holiday Sdn Bhd. All rights reserved'));
check_true('boilerplate: related carousel heading', competitor_is_boilerplate_line('You may also like'));
// Content must SURVIVE (specific/anchored patterns, no false positives):
check('content kept: cookie factory in itinerary', false,
    competitor_is_boilerplate_line('Day 3: Visit the cookie factory and enjoy local shopping'));
check('content kept: inclusions line', false,
    competitor_is_boilerplate_line('Tour fare includes daily breakfast and hotel accommodation'));
check('content kept: "book this" in prose', false,
    competitor_is_boilerplate_line('Book this 5D4N Bali tour from RM1899 per pax'));
check('content kept: "share" in prose', false,
    competitor_is_boilerplate_line('Share your travel dreams with our friendly consultants'));
check('content kept: "home to" prose (not a breadcrumb)', false,
    competitor_is_boilerplate_line('Home to over 200 ancient temples and shrines'));
$mixed = "Home > Tours > Japan\nJapan 6D5N Highlights\nDay 1 arrival\nSubscribe to our newsletter\nInclusions: breakfast\nFollow us on Facebook";
check('strip_boilerplate keeps content, drops chrome',
    "Japan 6D5N Highlights\nDay 1 arrival\nInclusions: breakfast",
    competitor_strip_boilerplate($mixed));
check('strip_boilerplate empty on non-string', '', competitor_strip_boilerplate(null));

// ---- competitor_page_char_cap -----------------------------------------------
check('page_char_cap default when blank', 60000, competitor_page_char_cap(''));
check('page_char_cap default when zero', 60000, competitor_page_char_cap('0'));
check('page_char_cap honours env override', 90000, competitor_page_char_cap('90000'));
check('page_char_cap custom default', 40000, competitor_page_char_cap(null, 40000));

// ---- html_to_text cap cuts on a line boundary -------------------------------
$capped = competitor_html_to_text("<p>" . str_repeat('AAAA ', 40) . "</p><p>" . str_repeat('B', 200) . "</p>", 210);
check_true('html_to_text cap respects the ceiling', mb_strlen($capped, 'UTF-8') <= 210);
check_true('html_to_text cap cut on a line boundary (no trailing partial B-run)',
    substr($capped, -1) !== 'B');

// ---- competitor_json_alternate_url ------------------------------------------
$altHtml = '<head><link rel="canonical" href="/x">'
    . '<link rel="alternate" type="application/json" href="/api/tour/42.json">'
    . '<link rel="alternate" type="application/rss+xml" href="/feed"></head>';
check('json_alternate resolves the JSON alternate', 'https://comp.com/api/tour/42.json',
    competitor_json_alternate_url($altHtml, 'https://comp.com/tours/'));
check('json_alternate ignores ld+json inline', '',
    competitor_json_alternate_url('<script type="application/ld+json">{}</script>', 'https://comp.com/'));
check('json_alternate none when absent', '',
    competitor_json_alternate_url('<link rel="alternate" type="text/html" href="/x">', 'https://comp.com/'));

// ---- competitor_extract_urls ------------------------------------------------
$filesLine = 'Files: https://cdn.co/a , https://cdn.co/b , see https://cdn.co/a again';
check('extract_urls dedupes + http only', array('https://cdn.co/a', 'https://cdn.co/b'),
    competitor_extract_urls($filesLine));
check('extract_urls strips trailing punctuation', array('https://cdn.co/x'),
    competitor_extract_urls('open https://cdn.co/x.'));
check('extract_urls respects limit', 1, count(competitor_extract_urls($filesLine, 1)));
check('extract_urls empty when none', array(), competitor_extract_urls('no links at all'));
check('extract_urls empty on non-string', array(), competitor_extract_urls(null));

// ---- competitor_is_candidate_url (permissive whole-site sweep net) ----------
check_true('candidate: keyword-less product URL (/detail/12345)',
    competitor_is_candidate_url('https://comp.com/detail/12345', 'comp.com'));
check_true('candidate: id-slug product (/en/12345-osaka)',
    competitor_is_candidate_url('https://comp.com/en/12345-osaka', 'comp.com'));
check_true('candidate: normal product URL',
    competitor_is_candidate_url('https://comp.com/tours/bali-5d4n', 'comp.com'));
check_true('candidate: www-insensitive host match',
    competitor_is_candidate_url('https://www.comp.com/packages/japan', 'comp.com'));
check('candidate false: homepage', false,
    competitor_is_candidate_url('https://comp.com/', 'comp.com'));
check('candidate false: off-host', false,
    competitor_is_candidate_url('https://other.com/tours/bali', 'comp.com'));
check('candidate false: about chrome', false,
    competitor_is_candidate_url('https://comp.com/about-us', 'comp.com'));
check('candidate false: blog post', false,
    competitor_is_candidate_url('https://comp.com/blog/top-10-beaches', 'comp.com'));
check('candidate false: asset', false,
    competitor_is_candidate_url('https://comp.com/img/hero.jpg', 'comp.com'));
check('candidate false: cart', false,
    competitor_is_candidate_url('https://comp.com/checkout/cart', 'comp.com'));
check('candidate false: customer support page', false,
    competitor_is_candidate_url('https://comp.com/customer-support', 'comp.com'));
check('candidate false: help centre', false,
    competitor_is_candidate_url('https://comp.com/help/booking', 'comp.com'));
check('candidate false: customer service', false,
    competitor_is_candidate_url('https://comp.com/customer-service', 'comp.com'));
// A real destination that merely contains "hel"/"support" letters is NOT excluded.
check_true('candidate: Helsinki tour not caught by /help',
    competitor_is_candidate_url('https://comp.com/tours/helsinki-5d4n', 'comp.com'));
check_true('candidate: no base_host given still accepts a content page',
    competitor_is_candidate_url('https://comp.com/x/y'));

// ---- competitor_is_guide_url (travel-guide / planning-article filter) -------
// Real false positives observed on topchinatravel.com:
check_true('guide: how-to-plan URL',
    competitor_is_guide_url('https://x.com/beijing/how-to-plan-a-trip-to-beijing.htm'));
check_true('guide: how-to day-trip URL',
    competitor_is_guide_url('https://x.com/guilin/how-to-plan-a-day-trip-to-longji-rice-terraces.htm'));
check_true('guide: title "How to plan a trip"',
    competitor_is_guide_url('https://x.com/p/12345', 'How to plan a trip to Guilin?'));
check_true('guide: public holidays calendar URL',
    competitor_is_guide_url('https://x.com/china-travel-guide/chinese-public-holidays-calendar.htm'));
check_true('guide: best-time slug',
    competitor_is_guide_url('https://x.com/tibet/best-time-to-visit-tibet.htm'));
check_true('guide: things-to-do slug',
    competitor_is_guide_url('https://x.com/xian/things-to-do-in-xian.htm'));
check_true('guide: title "Best time to visit"',
    competitor_is_guide_url('https://x.com/a/b', 'Best Time to Visit Yunnan'));
// Real TOURS must survive:
check('guide false: real group-tour URL', false,
    competitor_is_guide_url('https://x.com/china-tours/yunnan-group-tour-02/', '7 Days Yunnan Highlights Group Tour'));
check('guide false: asia-tours product', false,
    competitor_is_guide_url('https://x.com/asia-tours/classic-japan-china-tour/', '17 Days Japan & China Group Tour'));
check('guide false: duration-slug tour', false,
    competitor_is_guide_url('https://x.com/tours/bali-5d4n-getaway'));
check('guide false: cruise routes page title', false,
    competitor_is_guide_url('https://x.com/yangtze-cruise/yangtze-cruise-routes.htm', 'Yangtze River Cruise Routes'));
check('guide false on empty', false, competitor_is_guide_url('', ''));

// ---- competitor_path_has_product_keyword (render-worthy on JS SPAs) ---------
check_true('path_kw: section hub /tour-package',
    competitor_path_has_product_keyword('https://esplanad.tio.asia/tour-package'));
check_true('path_kw: numeric-id product /tour-package/1077',
    competitor_path_has_product_keyword('https://esplanad.tio.asia/tour-package/1077'));
check_true('path_kw: chanbrothers package-tours path',
    competitor_path_has_product_keyword('https://www.chanbrothers.com/package-tours/asia/taiwan/blossoms'));
check_true('path_kw: 5D4N duration code',
    competitor_path_has_product_keyword('https://x.com/deals/5d4n-genting'));
check('path_kw false: /hotel', false, competitor_path_has_product_keyword('https://esplanad.tio.asia/hotel'));
check('path_kw false: /about', false, competitor_path_has_product_keyword('https://x.com/about'));
check('path_kw false: homepage', false, competitor_path_has_product_keyword('https://x.com/'));

// ---- competitor_count_child_links (SPA section-hub → listing detection) ------
$tioLinks = array(
    'https://esplanad.tio.asia/tour-package',            // self — not a child
    'https://esplanad.tio.asia/tour-package/1077',
    'https://esplanad.tio.asia/tour-package/1090',
    'https://esplanad.tio.asia/tour-package/1093',
    'https://esplanad.tio.asia/member/order/tour-package', // different branch
    'https://esplanad.tio.asia/about',
);
check('count_child_links: 3 children under /tour-package', 3,
    competitor_count_child_links('https://esplanad.tio.asia/tour-package', $tioLinks));
check('count_child_links: grandchild not counted', 1,
    competitor_count_child_links('https://x.com/a', array('https://x.com/a/b', 'https://x.com/a/b/c')));
check('count_child_links: none for a leaf', 0,
    competitor_count_child_links('https://x.com/tour-package/1077', $tioLinks));

// ---- competitor_paginator_* + listing_item_url (SPA paginated listing) ------
$page1 = array(
    'data' => array(array('id' => 1077, 'name' => 'A'), array('id' => 1090, 'name' => 'B')),
    'links' => array('next' => 'https://x.com/api/tour-package?page=2', 'last' => 'https://x.com/api/tour-package?page=5'),
    'meta' => array('current_page' => 1, 'last_page' => 5, 'total' => 67),
);
check('paginator_items: returns the records', 2, count(competitor_paginator_items($page1)));
check('paginator_next: links.next', 'https://x.com/api/tour-package?page=2', competitor_paginator_next($page1));
check('paginator_items: plain {data} (no pagination) is NOT a listing', 0,
    count(competitor_paginator_items(array('data' => array(array('id' => 1))))));
check('paginator_items: empty data', 0, competitor_paginator_items(array('data' => array(), 'meta' => array('last_page' => 3))) ? 1 : 0);
check('paginator_next: none at last page', '', competitor_paginator_next(array('data' => array(), 'links' => array('next' => null))));
check('listing_item_url: id → listing/{id}', 'https://x.com/tour-package/1077',
    competitor_listing_item_url('https://x.com/tour-package', array('id' => 1077)));
check('listing_item_url: explicit slug wins', 'https://x.com/tour/bali-5d4n',
    competitor_listing_item_url('https://x.com/tour-package', array('id' => 5, 'url' => '/tour/bali-5d4n')));
check('listing_item_url: nothing usable', '',
    competitor_listing_item_url('https://x.com/tour-package', array('name' => 'no id')));

// ---- competitor_is_site_root ------------------------------------------------
check_true('site_root bare host', competitor_is_site_root('https://comp.com'));
check_true('site_root trailing slash', competitor_is_site_root('https://comp.com/'));
check('site_root false for listing', false, competitor_is_site_root('https://comp.com/destinations/europe'));
check('site_root false for product', false, competitor_is_site_root('https://comp.com/tours/bali-5d4n'));

// ---- competitor_robots_sitemaps ---------------------------------------------
$robots = "User-agent: *\nDisallow: /admin\nSitemap: https://comp.com/sitemap.xml\nSitemap: https://comp.com/tours-sitemap.xml\n";
check('robots_sitemaps extracts both', array('https://comp.com/sitemap.xml', 'https://comp.com/tours-sitemap.xml'),
    competitor_robots_sitemaps($robots));
check('robots_sitemaps empty when none', array(), competitor_robots_sitemaps("User-agent: *\nDisallow: /"));

// ---- competitor_parse_sitemap -----------------------------------------------
$idx = '<?xml version="1.0"?><sitemapindex><sitemap><loc>https://comp.com/s1.xml</loc></sitemap><sitemap><loc>https://comp.com/s2.xml</loc></sitemap></sitemapindex>';
$pi = competitor_parse_sitemap($idx);
check('parse_sitemap detects index', true, $pi['is_index']);
check('parse_sitemap index children', array('https://comp.com/s1.xml', 'https://comp.com/s2.xml'), $pi['urls']);
$us = '<urlset><url><loc>https://comp.com/tours/bali-5d4n</loc></url><url><loc>https://comp.com/about</loc></url></urlset>';
$pu = competitor_parse_sitemap($us);
check('parse_sitemap urlset not index', false, $pu['is_index']);
check('parse_sitemap urlset locs', array('https://comp.com/tours/bali-5d4n', 'https://comp.com/about'), $pu['urls']);
check('parse_sitemap empty on junk', array('is_index' => false, 'urls' => array()), competitor_parse_sitemap('nope'));

// ---- competitor_filter_product_urls -----------------------------------------
$discovered = array(
    'https://comp.com/tours/bali-5d4n',
    'https://comp.com/about',
    'https://comp.com/tours/japan-6d5n',
    'https://comp.com/tours/bali-5d4n',   // dup
    'https://comp.com/tours',             // bare listing
    'https://comp.com/packages/korea-7d',
);
check('filter_product_urls keeps products, dedupes', array(
    'https://comp.com/tours/bali-5d4n',
    'https://comp.com/tours/japan-6d5n',
    'https://comp.com/packages/korea-7d',
), competitor_filter_product_urls($discovered));
check('filter_product_urls respects limit', 2, count(competitor_filter_product_urls($discovered, 2)));

// ---- competitor_url_label ---------------------------------------------------
check('url_label slug -> title case', 'Bali 5D4N', competitor_url_label('https://comp.com/tours/bali-5d4n'));
check('url_label strips .html', 'Japan 6D5N', competitor_url_label('https://comp.com/tour/japan-6d5n.html'));
check('url_label underscores', 'Korea Winter', competitor_url_label('https://comp.com/packages/korea_winter'));
check('url_label root -> host', 'comp.com', competitor_url_label('https://www.comp.com/'));
check('url_label decodes %20 + strips .pdf + title-case', 'Charms Of Hangzhou',
    competitor_url_label('https://cdn.com/files/CHARMS%20OF%20HANGZHOU.pdf'));
check('url_label decodes encoded slashes', 'Hangzhou Wuxi Oriental',
    competitor_url_label('https://c.com/f/HANGZHOU%2FWUXI%2FORIENTAL'));
check('url_label truncates very long labels', true,
    mb_strlen(competitor_url_label('https://c.com/x/' . str_repeat('a', 200)), 'UTF-8') <= 81);

// ---- competitor_crawl_row_summary -------------------------------------------
$crawl_products = array(
    array('destination' => 'Bali'), array('destination' => 'Japan'),
    array('destination' => 'Bali'), array('destination' => 'Korea'),
    array('destination' => 'Vietnam'),
);
$summary = competitor_crawl_row_summary('https://www.comp.com/tours', $crawl_products);
check('crawl_summary label strips www + counts', 'comp.com — 5 products', $summary['product_name']);
check('crawl_summary product_count', 5, $summary['product_count']);
check('crawl_summary distinct destinations + overflow', 'Bali, Japan, Korea, +1 more', $summary['destination']);
check('crawl_summary singular label', 'comp.com — 1 product',
    competitor_crawl_row_summary('https://comp.com', array(array('destination' => 'Bali')))['product_name']);

// ---- competitor_build_scraped_agent -----------------------------------------
$sspec = competitor_build_scraped_agent('https://x.com/tour', 'Bali 5D4N. Hotel. Breakfast.', $products);
check('build_scraped_agent keys', array('instructions', 'input'), array_keys($sspec));
check_true('build_scraped_agent does NOT use web_search',
    stripos($sspec['instructions'], 'web_search') === false);
check_true('build_scraped_agent forbids browsing',
    stripos($sspec['instructions'], 'do not browse') !== false);
check_true('build_scraped_agent demands JSON', stripos($sspec['instructions'], 'JSON') !== false);
check_true('build_scraped_agent carries scraped content',
    strpos($sspec['input'], 'Bali 5D4N') !== false);
check_true('build_scraped_agent carries url', strpos($sspec['input'], 'https://x.com/tour') !== false);
check_true('build_scraped_agent carries our products',
    strpos($sspec['input'], 'Langkawi Getaway') !== false);

// ---- competitor_build_discovery_agent (AI fallback for JS sites) ------------
$dspec = competitor_build_discovery_agent('https://www.gd.my/web', 10);
check('discovery_agent keys', array('instructions', 'input'), array_keys($dspec));
check_true('discovery_agent uses web_search', stripos($dspec['instructions'], 'web_search') !== false);
check_true('discovery_agent asks for JSON array', stripos($dspec['instructions'], 'JSON array') !== false);
check_true('discovery_agent carries url', strpos($dspec['input'], 'https://www.gd.my/web') !== false);

// ---- competitor_parse_url_list ----------------------------------------------
$arr = '["https://www.gd.my/web/tour/a","https://www.gd.my/web/tour/b","http://other.com/x","https://www.gd.my/web/tour/a"]';
check('parse_url_list json array, same-host, deduped', array(
    'https://www.gd.my/web/tour/a', 'https://www.gd.my/web/tour/b',
), competitor_parse_url_list($arr, 'www.gd.my'));
check('parse_url_list www-insensitive host', array('https://gd.my/tour/a'),
    competitor_parse_url_list('["https://gd.my/tour/a"]', 'www.gd.my'));
check('parse_url_list objects with url key', array('https://c.com/tour/x'),
    competitor_parse_url_list('[{"url":"https://c.com/tour/x"}]', 'c.com'));
check('parse_url_list code fence + prose fallback', array('https://c.com/tour/y'),
    competitor_parse_url_list("Here:\nhttps://c.com/tour/y.", 'c.com'));
check('parse_url_list respects limit', 1,
    count(competitor_parse_url_list($arr, 'www.gd.my', 1)));
check('parse_url_list empty -> []', array(), competitor_parse_url_list('', 'c.com'));

// ---- competitor_build_file_agent (PDF/image mode) ---------------------------
$fspec = competitor_build_file_agent($products);
check('build_file_agent keys', array('instructions', 'text'), array_keys($fspec));
check_true('build_file_agent tells agent to READ the attached file',
    stripos($fspec['instructions'], 'attached') !== false);
check_true('build_file_agent does NOT use web_search',
    stripos($fspec['instructions'], 'web_search') === false);
check_true('build_file_agent demands JSON', stripos($fspec['instructions'], 'JSON') !== false);
check_true('build_file_agent text carries our products',
    strpos($fspec['text'], 'Langkawi Getaway') !== false);

// ---- competitor_file_input_part ---------------------------------------------
$png = competitor_file_input_part('png', 'QUJD');
check('file_input_part image type', 'input_image', $png['type']);
check('file_input_part image data uri', 'data:image/png;base64,QUJD', $png['image_url']);
check('file_input_part jpg -> jpeg mime', 'data:image/jpeg;base64,QUJD',
    competitor_file_input_part('JPG', 'QUJD')['image_url']);   // case + jpg alias
check('file_input_part strips leading dot', 'input_image',
    competitor_file_input_part('.webp', 'QUJD')['type']);
$pdf = competitor_file_input_part('pdf', 'QUJD');
check('file_input_part pdf type', 'input_file', $pdf['type']);
check('file_input_part pdf data uri', 'data:application/pdf;base64,QUJD', $pdf['file_data']);
check('file_input_part pdf filename', 'competitor.pdf', $pdf['filename']);
check('file_input_part rejects unsupported', null, competitor_file_input_part('exe', 'QUJD'));
check('file_input_part rejects empty ext', null, competitor_file_input_part('', 'QUJD'));

// ---- competitor_extract_responses_text --------------------------------------
// Raw Responses API shape: web_search_call + message(output_text)
$body = array('output' => array(
    array('type' => 'web_search_call', 'id' => 'ws_1'),
    array('type' => 'message', 'role' => 'assistant', 'content' => array(
        array('type' => 'output_text', 'text' => '{"product_name":"Bali 5D4N"}'),
    )),
));
check('extract_responses_text from output array', '{"product_name":"Bali 5D4N"}',
    competitor_extract_responses_text($body));
// SDK convenience top-level string wins when present
check('extract_responses_text honours output_text string', 'HELLO',
    competitor_extract_responses_text(array('output_text' => 'HELLO')));
// output_text as array of strings
check('extract_responses_text joins output_text array', 'AB',
    competitor_extract_responses_text(array('output_text' => array('A', 'B'))));
// concatenates multiple output_text parts
$multi = array('output' => array(array('type' => 'message', 'content' => array(
    array('type' => 'output_text', 'text' => 'foo'),
    array('type' => 'output_text', 'text' => 'bar'),
))));
check('extract_responses_text concatenates parts', 'foobar', competitor_extract_responses_text($multi));
check('extract_responses_text empty on junk', '', competitor_extract_responses_text('nope'));
check('extract_responses_text empty when no message', '',
    competitor_extract_responses_text(array('output' => array(array('type' => 'web_search_call')))));

// ---- competitor_extract_usage -----------------------------------------------
$usage_body = array('usage' => array('input_tokens' => 1200, 'output_tokens' => 340, 'total_tokens' => 1540));
check('extract_usage input tokens', 1200, competitor_extract_usage($usage_body)['input_tokens']);
check('extract_usage output tokens', 340, competitor_extract_usage($usage_body)['output_tokens']);
// Chat Completions fallback names
$chat_usage = array('usage' => array('prompt_tokens' => 50, 'completion_tokens' => 10));
check('extract_usage prompt_tokens fallback', 50, competitor_extract_usage($chat_usage)['input_tokens']);
check('extract_usage completion_tokens fallback', 10, competitor_extract_usage($chat_usage)['output_tokens']);
check('extract_usage zero when absent', array('input_tokens' => 0, 'output_tokens' => 0),
    competitor_extract_usage(array('output' => array())));
check('extract_usage zero on junk', array('input_tokens' => 0, 'output_tokens' => 0),
    competitor_extract_usage('nope'));

// ---- competitor_estimate_cost -----------------------------------------------
// gpt-4o-mini = 0.15 / 0.60 per 1M. 1M in + 1M out = 0.15 + 0.60 = 0.75
check('estimate_cost gpt-4o-mini', 0.75, competitor_estimate_cost('gpt-4o-mini', 1000000, 1000000));
// unknown model falls back to gpt-4o-mini rates
check('estimate_cost unknown model falls back', 0.75, competitor_estimate_cost('mystery', 1000000, 1000000));
// gpt-4o = 2.50 / 10.00 per 1M
check('estimate_cost gpt-4o', 12.5, competitor_estimate_cost('gpt-4o', 1000000, 1000000));
// case-insensitive model key
check('estimate_cost is case-insensitive', 0.75, competitor_estimate_cost('GPT-4O-MINI', 1000000, 1000000));
// explicit .env-style rate override wins over the table
check('estimate_cost honours rate override', 3.0,
    competitor_estimate_cost('gpt-4o-mini', 1000000, 1000000, array('input' => 1.0, 'output' => 2.0)));
check('estimate_cost zero tokens -> 0', 0.0, competitor_estimate_cost('gpt-4o-mini', 0, 0));

// ---- competitor_parse_ai_response -------------------------------------------
$clean = json_encode(array(
    'product_name' => 'Bali 5D4N', 'price' => 'RM1,299', 'currency' => 'MYR',
    'destination' => 'Bali', 'duration' => '5D4N',
    'inclusions' => array('Hotel', 'Breakfast'),
    'pros' => array('Cheap'), 'cons' => array('No flight'),
    'summary' => 'Budget Bali tour.', 'comparison' => 'Cheaper than ours.',
    'matched_product' => 'Bali Deluxe 5D4N',
));
$rec = competitor_parse_ai_response($clean);
check('parse product_name', 'Bali 5D4N', $rec['product_name']);
check('parse inclusions as list', array('Hotel', 'Breakfast'), $rec['inclusions']);
check('parse comparison', 'Cheaper than ours.', $rec['comparison']);
check('parse matched_product', 'Bali Deluxe 5D4N', $rec['matched_product']);
check('parse matched_product default ""', '', competitor_parse_ai_response('{"foo":"bar"}')['matched_product']);

// code-fenced + surrounding prose
$fenced = "Here you go:\n```json\n" . $clean . "\n```\nthanks";
$rec2 = competitor_parse_ai_response($fenced);
check('parse tolerates code fence + prose', 'Bali 5D4N', $rec2['product_name']);

// list field arriving as a string is normalised
$str_list = json_encode(array('product_name' => 'X', 'inclusions' => "Hotel\nBreakfast\nTransfer"));
check('parse coerces string list', array('Hotel', 'Breakfast', 'Transfer'),
    competitor_parse_ai_response($str_list)['inclusions']);

// scalar arriving as array is joined
$arr_scalar = json_encode(array('product_name' => array('A', 'B')));
check('parse joins array scalar', 'A, B', competitor_parse_ai_response($arr_scalar)['product_name']);

check('parse garbage -> null', null, competitor_parse_ai_response('not json at all'));
check('parse empty -> null', null, competitor_parse_ai_response(''));
check('parse missing keys default', '', competitor_parse_ai_response('{"foo":"bar"}')['product_name']);

// end-to-end: Responses body -> text -> parsed record
$e2e = competitor_parse_ai_response(competitor_extract_responses_text($body));
check('e2e responses body -> parsed', 'Bali 5D4N', $e2e['product_name']);

// ---- rich competitor schema ------------------------------------------------
$rich = json_encode(array(
    'product_name' => 'China 8D7N', 'tour_code' => 'CN-8D7N-01',
    'price' => 'RM3999', 'price_from' => 'RM3999', 'price_to' => 'RM4599', 'currency' => 'MYR',
    'destination' => 'China', 'countries' => array('China'),
    'cities' => array('Beijing', 'Shanghai'), 'duration' => '8D7N',
    'travel_months' => array('June', 'July'), 'departure_city' => 'Kuala Lumpur',
    'flight_departure' => 'MH370 KUL-PEK 09:00', 'flight_return' => 'MH371 PVG-KUL 22:00',
    'themes' => array('Culture', 'History'), 'tour_styles' => array('Group tour'),
    'difficulty' => 'Moderate', 'local_transport' => array('Coach', 'Bullet train'),
    'inclusions' => array('Hotel', 'Breakfast'), 'exclusions' => array('Tips', 'Visa'),
    'hotels' => array('Hilton Beijing'),
    'meals' => array('breakfast' => '7', 'lunch' => '5', 'dinner' => '6'),
    'shopping_stops' => array('Silk factory'), 'optional_tours' => array('Kung fu show'),
    'special_remarks' => array('Min 20 pax'),
    'scenic_highlights' => array(
        array('name' => 'Great Wall', 'description' => 'Walk the Mutianyu section with sweeping mountain views'),
        'Tiananmen Square',
    ),
    'target_traveller' => 'Families',
    'suitable_age' => '6-70', 'child_friendly' => 'Yes — gentle pace', 'senior_friendly' => 'Yes',
    'usp' => array('Iconic landmarks'),
    'traveller_segments' => array(
        array('segment' => 'family_kids', 'suitability' => 'High', 'justification' => 'Gentle pace suits children.'),
        array('segment' => 'elderly', 'suitability' => 'Medium', 'justification' => 'Some walking on the Wall.'),
        array('segment' => 'company', 'suitability' => 'Low', 'justification' => 'No corporate facilities.'),
    ),
    'itinerary' => array(
        array('day' => 1, 'title' => 'Arrival', 'description' => 'Land in Beijing'),
        array('day' => 'Day 2', 'title' => 'Great Wall', 'description' => 'Visit the wall'),
    ),
    'pros' => array('Comprehensive'), 'cons' => array('Long flights'),
    'summary' => 'A cultural China tour.', 'comparison' => 'Pricier than ours.',
));
$r = competitor_parse_ai_response($rich);
check('parse tour_code', 'CN-8D7N-01', $r['tour_code']);
check('parse price range', array('RM3999', 'RM4599'), array($r['price_from'], $r['price_to']));
check('parse countries', array('China'), $r['countries']);
check('parse cities', array('Beijing', 'Shanghai'), $r['cities']);
check('parse travel_months', array('June', 'July'), $r['travel_months']);
check('parse departure_city', 'Kuala Lumpur', $r['departure_city']);
check('parse flights', 'MH371 PVG-KUL 22:00', $r['flight_return']);
check('parse themes', array('Culture', 'History'), $r['themes']);
check('parse difficulty', 'Moderate', $r['difficulty']);
check('parse local_transport', array('Coach', 'Bullet train'), $r['local_transport']);
check('parse exclusions', array('Tips', 'Visa'), $r['exclusions']);
check('parse hotels', array('Hilton Beijing'), $r['hotels']);
check('parse meals slots', array('7', '5', '6'),
    array($r['meals']['breakfast'], $r['meals']['lunch'], $r['meals']['dinner']));
check('parse shopping_stops', array('Silk factory'), $r['shopping_stops']);
check('parse optional_tours', array('Kung fu show'), $r['optional_tours']);
check('parse special_remarks', array('Min 20 pax'), $r['special_remarks']);
check('parse scenic_highlights object', array('name' => 'Great Wall', 'description' => 'Walk the Mutianyu section with sweeping mountain views'), $r['scenic_highlights'][0]);
check('parse scenic_highlights legacy string -> name only', array('name' => 'Tiananmen Square', 'description' => ''), $r['scenic_highlights'][1]);
check('parse target_traveller', 'Families', $r['target_traveller']);
check('parse suitable_age', '6-70', $r['suitable_age']);
check('parse child_friendly', 'Yes — gentle pace', $r['child_friendly']);
check('parse usp', array('Iconic landmarks'), $r['usp']);
check('parse traveller_segments count', 3, count($r['traveller_segments']));
check('parse traveller_segments reordered to canonical (elderly before family_kids)',
    array('elderly', 'family_kids', 'company'),
    array($r['traveller_segments'][0]['key'], $r['traveller_segments'][1]['key'], $r['traveller_segments'][2]['key']));
check('parse traveller_segments derives level', 'high', $r['traveller_segments'][1]['level']);
check('parse traveller_segments keeps justification', 'No corporate facilities.', $r['traveller_segments'][2]['justification']);
check('parse itinerary count', 2, count($r['itinerary']));
check('parse itinerary numeric day -> label', 'Day 1', $r['itinerary'][0]['day']);
check('parse itinerary keeps day label', 'Day 2', $r['itinerary'][1]['day']);
check('parse itinerary title', 'Great Wall', $r['itinerary'][1]['title']);

// missing rich fields default cleanly (old/sparse replies)
$min = competitor_parse_ai_response('{"product_name":"X"}');
check('parse missing list -> []', array(), $min['cities']);
check('parse missing meals -> blank slots',
    array('breakfast' => '', 'lunch' => '', 'dinner' => ''), $min['meals']);
check('parse missing itinerary -> []', array(), $min['itinerary']);
check('parse missing scalar -> ""', '', $min['tour_code']);

// itinerary tolerates a bare-string day + skips empty entries
$bare = json_encode(array('itinerary' => array('First day free', array('day' => 3), 'Last day shopping')));
$bi = competitor_parse_ai_response($bare)['itinerary'];
check('parse itinerary bare string count (empty skipped)', 2, count($bi));
check('parse itinerary bare string desc', 'First day free', $bi[0]['description']);
check('parse itinerary bare keeps day counter', 'Day 3', $bi[1]['day']);

// contract advertises the new fields + DERIVE-from-source (no-assumption) instruction
$contract = competitor_output_contract();
check_true('contract lists itinerary', stripos($contract, 'itinerary') !== false);
check_true('contract lists exclusions', stripos($contract, 'exclusions') !== false);
check_true('contract lists meals', stripos($contract, 'meals') !== false);
check_true('contract marks DERIVE fields', strpos($contract, 'DERIVE') !== false);
check_true('contract forbids assumptions', stripos($contract, 'make NO assumptions') !== false);
check_true('contract tells DERIVE to blank when no clue', stripos($contract, 'no clue') !== false);
check_true('contract asks for full flight detail', stripos($contract, 'FULL outbound flight detail') !== false);
check_true('contract asks optionals verbatim with price', stripos($contract, 'each one verbatim WITH its price') !== false);
// summary is now a detailed, labelled, multi-paragraph overview (not a 2-4 sentence blurb)
check_true('contract asks for detailed summary', stripos($contract, 'DETAILED overview') !== false);
check_true('contract summary lists labelled sections', stripos($contract, '"Best for:"') !== false
    && stripos($contract, '"Watch-outs:"') !== false);
check_true('contract summary wants synthesis not restatement', stripos($contract, 'SYNTHESISE') !== false);

// ---- competitor_summary_sections -------------------------------------------
$labelled = "Overview: A 5D4N Bali tour through Denpasar and Ubud.\n\n"
    . "Best for: Couples and families — gentle pace.\n\n"
    . "Watch-outs: Several shopping stops eat into sightseeing time.";
$secs = competitor_summary_sections($labelled);
check('summary_sections count', 3, count($secs));
check('summary_sections label parsed', 'Overview', $secs[0]['label']);
check('summary_sections text parsed', 'A 5D4N Bali tour through Denpasar and Ubud.', $secs[0]['text']);
check('summary_sections last label', 'Watch-outs', $secs[2]['label']);
// single-newline separated paragraphs still split
$one_nl = competitor_summary_sections("Overview: Foo.\nBest for: Bar.");
check('summary_sections splits single newlines', 2, count($one_nl));
check('summary_sections single-nl label', 'Best for', $one_nl[1]['label']);
// legacy plain summary with no labels -> one unlabelled block, text intact
$legacy = competitor_summary_sections('A budget Bali tour. Great value overall.');
check('summary_sections legacy single block', 1, count($legacy));
check('summary_sections legacy no label', '', $legacy[0]['label']);
check('summary_sections legacy keeps full text', 'A budget Bali tour. Great value overall.', $legacy[0]['text']);
// a colon that sits mid-prose (after a sentence) is NOT treated as a label
check('summary_sections mid-sentence colon after period stays prose', '',
    competitor_summary_sections('One sentence. Then: not a label because of the period.')[0]['label']);
// full-width CJK colon is recognised
$cjk = competitor_summary_sections('概览：一个巴厘岛旅行团。');
check('summary_sections full-width colon label', '概览', $cjk[0]['label']);
check('summary_sections empty -> []', array(), competitor_summary_sections(''));
check('summary_sections whitespace -> []', array(), competitor_summary_sections("  \n  "));

// ---- competitor_extract_text_urls (pasted free text) ------------------------
check('extract_text_urls pulls both urls from prose', array('https://a.com/tour', 'http://b.com/x'),
    competitor_extract_text_urls('Please check https://a.com/tour and also http://b.com/x for details.'));
check('extract_text_urls strips trailing punctuation', array('https://a.com/tour'),
    competitor_extract_text_urls('See (https://a.com/tour).'));
check('extract_text_urls dedupes', array('https://a.com/tour'),
    competitor_extract_text_urls("https://a.com/tour\nhttps://a.com/tour"));
check('extract_text_urls respects limit', 2,
    count(competitor_extract_text_urls('https://a.com/1 https://b.com/2 https://c.com/3', 2)));
check('extract_text_urls empty when no url', array(), competitor_extract_text_urls('just some notes, no links'));
check('extract_text_urls empty on non-string', array(), competitor_extract_text_urls(null));

// ---- competitor_paste_source_label ------------------------------------------
check('paste_source_label uses first url', 'https://a.com/tour',
    competitor_paste_source_label('look at https://a.com/tour please'));
check('paste_source_label falls back to Pasted text', 'Pasted text',
    competitor_paste_source_label('no links here'));

// ---- competitor_build_paste_agent -------------------------------------------
$pasteLinks = array(
    array('url' => 'https://a.com/tour', 'text' => 'Bali 5D4N from RM1999, day 1 arrival'),
    array('url' => 'https://b.com/spa',  'text' => ''),   // unreadable (thin JS page)
);
$pa = competitor_build_paste_agent("Compare these two:\nhttps://a.com/tour\nhttps://b.com/spa", $pasteLinks, $products, true);
check_true('paste_agent carries the pasted notes', strpos($pa['input'], 'Compare these two:') !== false);
check_true('paste_agent carries link url', strpos($pa['input'], 'https://a.com/tour') !== false);
check_true('paste_agent carries scraped link text', strpos($pa['input'], 'Bali 5D4N from RM1999') !== false);
check_true('paste_agent marks unreadable link', stripos($pa['input'], 'https://b.com/spa') !== false
    && stripos($pa['input'], 'could not') !== false);
check_true('paste_agent allows browsing unreadable links when told', stripos($pa['instructions'], 'web_search') !== false);
check_true('paste_agent carries our products', strpos($pa['input'], competitor_products_block($products)) !== false);
$paNoBrowse = competitor_build_paste_agent('just notes', array(), $products, false);
check_true('paste_agent no web_search when browsing off', stripos($paNoBrowse['instructions'], 'web_search') === false);
check_true('paste_agent works with notes only (no links)', strpos($paNoBrowse['input'], 'just notes') !== false);

// ---- competitor_pdf_filename ------------------------------------------------
check('pdf_filename slugifies name + id', 'bali-escape-5d4n-42',
    substr(competitor_pdf_filename('Bali Escape 5D4N', 42), 0, -4));
check('pdf_filename ends with .pdf', '.pdf',
    substr(competitor_pdf_filename('Anything', 1), -4));
check('pdf_filename collapses punctuation to single dash', 'yunnan-tour-7-id-9.pdf',
    competitor_pdf_filename('Yunnan Tour (7)  //ID', 9));
check('pdf_filename falls back when name blank', 'competitor-analysis-7.pdf',
    competitor_pdf_filename('   ', 7));
check('pdf_filename casts id to int', 'x-3.pdf',
    competitor_pdf_filename('X', '3abc'));

// ---- competitor_split_point_justification -----------------------------------
$pj = competitor_split_point_justification('Direct flights — less travel fatigue and an extra day there');
check('split em-dash point', 'Direct flights', $pj['point']);
check('split em-dash justification', 'less travel fatigue and an extra day there', $pj['justification']);
check('split spaced-hyphen point', 'Cheap',
    competitor_split_point_justification('Cheap - budget-friendly for families')['point']);
check('split spaced-hyphen keeps hyphenated justification', 'budget-friendly for families',
    competitor_split_point_justification('Cheap - budget-friendly for families')['justification']);
check('split keeps hyphenated word intact (no spaced separator)', 'Well-known operator',
    competitor_split_point_justification('Well-known operator')['point']);
check('split no separator -> empty justification', '',
    competitor_split_point_justification('Comprehensive')['justification']);
check('split only on first separator', 'more time — and less rush',
    competitor_split_point_justification('Slow pace — more time — and less rush')['justification']);
$pj_cn = competitor_split_point_justification('直飞航班—减少旅途疲劳');
check('split CJK em-dash without spaces (point)', '直飞航班', $pj_cn['point']);
check('split CJK em-dash without spaces (justification)', '减少旅途疲劳', $pj_cn['justification']);
// A fact-justified shopping stop splits into the stop and its source-grounded reason.
$pj_shop = competitor_split_point_justification('Pearl gallery — Day 3 itinerary lists a guided visit to a pearl factory');
check('split shopping stop point', 'Pearl gallery', $pj_shop['point']);
check('split shopping stop justification', 'Day 3 itinerary lists a guided visit to a pearl factory', $pj_shop['justification']);
// A bare shopping stop with no supporting detail keeps the whole name as the point.
check('shopping stop with no justification -> stop only', '',
    competitor_split_point_justification('Batik workshop')['justification']);

// ---- scenic_highlights normaliser -------------------------------------------
$sc_obj = competitor_scenic_items(array(
    array('name' => 'Ba Na Hills', 'description' => 'Ride one of the world\'s longest cable cars over lush forest'),
));
check('scenic keeps name + description', array('name' => 'Ba Na Hills', 'description' => 'Ride one of the world\'s longest cable cars over lush forest'), $sc_obj[0]);
$sc_legacy = competitor_scenic_items(array('Golden Bridge', ''));
check('scenic legacy string -> name only', array('name' => 'Golden Bridge', 'description' => ''), $sc_legacy[0]);
check('scenic drops blank entries', 1, count($sc_legacy));
check('scenic splits delimited string', 2, count(competitor_scenic_items("Golden Bridge\nFrench Village")));
check('scenic reads alt keys (title/desc)', array('name' => 'Dragon Bridge', 'description' => 'Fire show on weekends'),
    competitor_scenic_items(array(array('title' => 'Dragon Bridge', 'desc' => 'Fire show on weekends')))[0]);
check('scenic non-array -> []', array(), competitor_scenic_items(null));
check('scenic keeps description-only entry', array('name' => '', 'description' => 'Panoramic sunrise'),
    competitor_scenic_items(array(array('description' => 'Panoramic sunrise')))[0]);

// ---- traveller_segments normaliser ------------------------------------------
check('segment match single from solo', 'single', competitor_segment_match_key('Solo Traveller'));
check('segment match teenager from youth', 'teenager', competitor_segment_match_key('Youth / students'));
check('segment match couple from honeymoon', 'couple', competitor_segment_match_key('Honeymoon couples'));
check('segment match company from corporate', 'company', competitor_segment_match_key('Corporate incentive'));
check('segment match family+kids', 'family_kids', competitor_segment_match_key('Families with young children'));
check('segment match family+elderly beats bare family', 'family_elderly', competitor_segment_match_key('Family with elderly parents'));
check('segment match bare family -> kids', 'family_kids', competitor_segment_match_key('Family'));
check('segment match elderly (not family)', 'elderly', competitor_segment_match_key('Seniors'));
check('segment match unknown -> empty', '', competitor_segment_match_key('Astronauts'));

check('segment level high', 'high', competitor_segment_level('High'));
check('segment level medium from moderate', 'medium', competitor_segment_level('Moderate'));
check('segment level low from poor', 'low', competitor_segment_level('Poor fit'));
check('segment level "not ideal" reads low not high', 'low', competitor_segment_level('Not ideal'));
check('segment level blank -> empty', '', competitor_segment_level(''));

$seg = competitor_traveller_segments(array(
    array('segment' => 'couple', 'suitability' => 'High', 'justification' => 'Romantic pace.'),
    array('type' => 'Solo', 'fit' => 'Low', 'reason' => 'Single supplement.'),
    array('segment' => 'company', 'suitability' => '', 'justification' => ''),   // both blank -> dropped
));
check('segments keeps only content-bearing entries', 2, count($seg));
check('segments returns canonical order (single before couple)',
    array('single', 'couple'), array($seg[0]['key'], $seg[1]['key']));
check('segments reads alt keys (type/fit/reason)',
    array('single', 'low', 'Single supplement.'),
    array($seg[0]['key'], $seg[0]['level'], $seg[0]['justification']));
check('segments english label attached', 'Couple', $seg[1]['segment']);
check('segments non-array -> []', array(), competitor_traveller_segments('nope'));
// Assoc map keyed by segment name is also accepted.
$seg_map = competitor_traveller_segments(array(
    'elderly' => array('suitability' => 'Medium', 'justification' => 'Some walking.'),
));
check('segments accepts assoc map keyed by segment', 'elderly', $seg_map[0]['key']);
// Idempotent + level-preserving: re-normalising a translated entry keeps the colour
// level even though the visible suitability word is no longer English.
$seg_cn = competitor_traveller_segments(array(
    array('key' => 'couple', 'segment' => 'Couple', 'suitability' => '高', 'level' => 'high', 'justification' => '浪漫。'),
));
check('segments preserves explicit level (colour survives translation)', 'high', $seg_cn[0]['level']);

// ---- translation helpers ----------------------------------------------------
check('normalize_lang clamps unknown to en', 'en', competitor_normalize_lang('fr'));
check('normalize_lang accepts cn', 'cn', competitor_normalize_lang('CN'));
check_true('lang_label cn mentions Chinese', stripos(competitor_lang_label('cn'), 'Chinese') !== false);

$prod = array(
    'product_name' => 'Yunnan Tour', 'tour_code' => 'YN-1', 'price' => 'RM3999', 'currency' => 'MYR',
    'destination' => 'Yunnan', 'duration' => '7D6N', 'flight_departure' => 'KUL-KMG',
    'countries' => array('China'), 'cities' => array('Kunming', 'Dali'),
    'meals' => array('breakfast' => '6', 'lunch' => '', 'dinner' => '5'),
    'itinerary' => array(array('day' => 'Day 1', 'title' => 'Arrival', 'description' => 'Land and check in')),
    'scenic_highlights' => array(array('name' => 'Stone Forest', 'description' => 'Wander karst pinnacles at sunset')),
    'usp' => array(), 'pros' => array('Cheap'),
    'traveller_segments' => array(
        array('key' => 'couple', 'segment' => 'Couple', 'suitability' => 'High', 'level' => 'high', 'justification' => 'Romantic scenery.'),
    ),
);
$tx = competitor_extract_translatable($prod);
check_true('extract keeps translatable scalars', isset($tx['scalars']['product_name']) && $tx['scalars']['product_name'] === 'Yunnan Tour');
check_true('extract drops price/code scalars', !isset($tx['scalars']['tour_code']) && !isset($tx['scalars']['price']) && !isset($tx['scalars']['currency']));
check_true('extract keeps lists with content', $tx['lists']['cities'] === array('Kunming', 'Dali'));
check_true('extract drops empty lists', !isset($tx['lists']['usp']));
check_true('extract keeps only non-empty meals', isset($tx['meals']['breakfast']) && !isset($tx['meals']['lunch']));
check_true('extract keeps itinerary text', $tx['itinerary'][0]['title'] === 'Arrival');
check_true('extract keeps scenic name + description', $tx['scenic_highlights'][0]['name'] === 'Stone Forest' && $tx['scenic_highlights'][0]['description'] === 'Wander karst pinnacles at sunset');
check_true('extract drops scenic from lists', !isset($tx['lists']['scenic_highlights']));
check_true('extract keeps traveller_segment suitability + justification', $tx['traveller_segments'][0]['suitability'] === 'High' && $tx['traveller_segments'][0]['justification'] === 'Romantic scenery.');
check_true('extract omits segment key/level (not translated)', !isset($tx['traveller_segments'][0]['key']) && !isset($tx['traveller_segments'][0]['level']));
check_true('extract excludes flight code (not a scalar key)', !isset($tx['scalars']['flight_departure']));

// Apply a (fake) Chinese overlay and confirm it merges by key/index, leaving
// prices/codes and untranslated fields intact.
$overlay = array(
    'scalars' => array('product_name' => '云南之旅', 'destination' => '云南', 'duration' => '7天6晚'),
    'lists' => array('cities' => array('昆明', '大理'), 'pros' => array('便宜')),
    'meals' => array('breakfast' => '6', 'dinner' => '5'),
    'itinerary' => array(array('day' => '第1天', 'title' => '抵达', 'description' => '落地入住')),
    'scenic_highlights' => array(array('name' => '石林', 'description' => '日落时漫步喀斯特石峰')),
    'traveller_segments' => array(array('suitability' => '高', 'justification' => '浪漫风景。')),
);
$merged = competitor_apply_translation($prod, $overlay);
check('apply translates product_name', '云南之旅', $merged['product_name']);
check('apply translates city list', array('昆明', '大理'), $merged['cities']);
check('apply keeps tour_code verbatim', 'YN-1', $merged['tour_code']);
check('apply keeps price verbatim', 'RM3999', $merged['price']);
check('apply translates itinerary title', '抵达', $merged['itinerary'][0]['title']);
check('apply translates scenic name', '石林', $merged['scenic_highlights'][0]['name']);
check('apply translates scenic description', '日落时漫步喀斯特石峰', $merged['scenic_highlights'][0]['description']);
check('apply translates segment suitability', '高', $merged['traveller_segments'][0]['suitability']);
check('apply translates segment justification', '浪漫风景。', $merged['traveller_segments'][0]['justification']);
check('apply keeps segment level from original (colour holds after CN)', 'high', $merged['traveller_segments'][0]['level']);
check('apply keeps original when overlay missing', array('China'), $merged['countries']);
check_true('apply does not mutate input', $prod['product_name'] === 'Yunnan Tour');

$ta = competitor_build_translation_agent(array('a' => 'b'), 'cn');
check_true('translation agent asks for target lang', stripos($ta['instructions'], 'Chinese') !== false);
check_true('translation agent input carries the payload json', strpos($ta['input'], '{"a":"b"}') !== false);
check_true('translation agent input mentions json (for json mode)', stripos($ta['input'], 'json') !== false);

check_true('json_object_from_text strips fence', competitor_json_object_from_text("```json\n{\"x\":1}\n```") === array('x' => 1));
check_true('json_object_from_text grabs outer braces', competitor_json_object_from_text('noise {"y":2} tail') === array('y' => 2));
check_true('json_object_from_text null on garbage', competitor_json_object_from_text('no json here') === null);
// Recover from a stray trailing brace (a real gpt-4.1-mini failure mode).
check_true('json_object_from_text recovers extra trailing brace', competitor_json_object_from_text('{"a":{"b":1}}}') === array('a' => array('b' => 1)));
check_true('json_object_from_text ignores braces inside strings', competitor_json_object_from_text('{"a":"x}y{z"} junk') === array('a' => 'x}y{z'));
check('first_balanced_object returns first object', '{"a":1}', competitor_first_balanced_object('pre {"a":1} {"b":2}'));
check('first_balanced_object empty when unclosed', '', competitor_first_balanced_object('{"a":1'));

// row_to_product + display_products
$rowObj = (object) array('product_name' => 'Solo', 'price' => 'RM1', 'countries' => array('MY'), 'products' => array());
check('row_to_product maps name', 'Solo', competitor_row_to_product($rowObj)['product_name']);
check('display_products single -> 1', 1, count(competitor_display_products($rowObj)));
$crawlObj = (object) array('products' => array(array('product_name' => 'A'), array('product_name' => 'B')));
check('display_products crawl -> N', 2, count(competitor_display_products($crawlObj)));

// apply_translation_to_row (single + crawl)
$single = (object) array('product_name' => 'Yunnan Tour', 'tour_code' => 'YN-1', 'countries' => array('China'), 'cities' => array('Kunming'), 'meals' => array(), 'itinerary' => array(), 'products' => array());
competitor_apply_translation_to_row($single, array('products' => array(array('scalars' => array('product_name' => '云南之旅'), 'lists' => array('cities' => array('昆明'))))));
check('apply_to_row single translates name', '云南之旅', $single->product_name);
check('apply_to_row single translates list', array('昆明'), $single->cities);
check('apply_to_row single keeps code', 'YN-1', $single->tour_code);
$crawl = (object) array('products' => array(array('product_name' => 'A', 'cities' => array('X')), array('product_name' => 'B')));
competitor_apply_translation_to_row($crawl, array('products' => array(array('scalars' => array('product_name' => '甲')), array('scalars' => array('product_name' => '乙')))));
check('apply_to_row crawl p0', '甲', $crawl->products[0]['product_name']);
check('apply_to_row crawl p1', '乙', $crawl->products[1]['product_name']);

// ---- competitor_remove_crawl_item (Review-page per-product delete) -----------
$ci_items = array(
    array('url' => 'https://x/a', 'text' => 'A'),
    array('url' => 'https://x/b', 'text' => 'B'),
    array('url' => 'https://x/c', 'text' => 'C'),
);
$ci_status = array(
    'count' => 3, 'cost_total' => 0.30,
    'analysed' => array('1' => array('id' => 42, 'cost' => 0.10, 'at' => '2026-09-08 10:00:00')),
);
$r = competitor_remove_crawl_item($ci_items, $ci_status, 1);
check_true('remove_item drops the index from items', !array_key_exists(1, $r['items']));
check_true('remove_item keeps other items (no reindex)', array_key_exists(0, $r['items']) && array_key_exists(2, $r['items']));
check('remove_item returns analysed id to delete', 42, $r['deleted_analysis_id']);
check_true('remove_item drops the analysed entry', !isset($r['status']['analysed']['1']));
check('remove_item refunds analysed cost', 0.2, $r['status']['cost_total']);
check('remove_item resyncs count', 2, $r['status']['count']);
check_true('remove_item does not mutate input items', count($ci_items) === 3);
// Removing an un-analysed item: no id, cost untouched.
$r2 = competitor_remove_crawl_item($ci_items, $ci_status, 0);
check('remove_item unanalysed -> no id', 0, $r2['deleted_analysis_id']);
check('remove_item unanalysed keeps cost', 0.3, $r2['status']['cost_total']);
// Missing index is a no-op on items.
$r3 = competitor_remove_crawl_item($ci_items, $ci_status, 9);
check('remove_item missing index keeps all items', 3, count($r3['items']));
check('remove_item missing index -> no id', 0, $r3['deleted_analysis_id']);

// Bulk delete: remove indices 0 and 1 (1 is analysed) in one pass.
$rb = competitor_remove_crawl_items($ci_items, $ci_status, array(0, 1));
check('remove_items leaves only untouched index', array(2), array_keys($rb['items']));
check('remove_items collects analysed ids', array(42), $rb['deleted_analysis_ids']);
check('remove_items refunds analysed cost', 0.2, $rb['status']['cost_total']);
check('remove_items resyncs count', 1, $rb['status']['count']);
check_true('remove_items does not mutate input items', count($ci_items) === 3);
// Empty indices are a no-op.
$rb2 = competitor_remove_crawl_items($ci_items, $ci_status, array());
check('remove_items empty -> all items kept', 3, count($rb2['items']));
check('remove_items empty -> no ids', array(), $rb2['deleted_analysis_ids']);

// ---- competitor_orphan_crawl_rows (re-hydrate crawled DB rows) ---------------
$orphan_db = array(
    (object) array('id' => 7, 'url' => 'https://easyeurope.com.my/golden-egypt/', 'product_name' => 'Golden Egypt', 'cost_usd' => 0.12, 'status' => 'done', 'created_at' => '2026-09-09 10:18:43'),
    (object) array('id' => 9, 'url' => 'https://easyeurope.com.my/wild-kenya/',   'product_name' => 'Wild Kenya',   'cost_usd' => 0.10, 'status' => 'done', 'created_at' => '2026-09-18 00:04:14'),
    (object) array('id' => 4, 'url' => 'https://applevacations.my/en/tour?pckg_id=529', 'product_name' => 'North Vietnam', 'cost_usd' => 0.20, 'status' => 'error', 'created_at' => '2026-09-04 10:32:48'),
);
// Host mode (competitor): a live crawl for easyeurope.com.my hides BOTH its rows.
$host_rows = competitor_orphan_crawl_rows($orphan_db, array('easyeurope.com.my'), 'host');
check('orphan host-mode hides covered host', 1, count($host_rows));
check('orphan host-mode keeps uncovered row', 4, $host_rows[0]['analysis_id']);
check('orphan row is DB-row path', true, $host_rows[0]['is_upload']);
check('orphan row tagged crawled', true, $host_rows[0]['is_crawled']);
check('orphan error row state', 'error', $host_rows[0]['state']);
// Nothing covered -> all three fold in.
$all_rows = competitor_orphan_crawl_rows($orphan_db, array(), 'host');
check('orphan none-covered folds all', 3, count($all_rows));
check('orphan carries cost', 0.12, $all_rows[0]['cost_total']);
// URL mode (Our Product): only the matching URL is hidden.
$url_rows = competitor_orphan_crawl_rows($orphan_db, array('https://easyeurope.com.my/golden-egypt/'), 'url');
check('orphan url-mode hides only matching url', 2, count($url_rows));

// url_dedup_key normalisation.
check('url_dedup strips scheme/www/slash', 'easyeurope.com.my/golden-egypt', competitor_url_dedup_key('https://www.easyeurope.com.my/golden-egypt/'));
check('url_dedup drops query+fragment', 'x.com/tour', competitor_url_dedup_key('http://x.com/tour?a=1#f'));
check('url_dedup bare host+path', 'x.com/tour', competitor_url_dedup_key('x.com/tour'));
check('url_dedup empty', '', competitor_url_dedup_key(''));

// url-mode dedup ignores trailing-slash / www / scheme / query differences.
$fuzzy = competitor_orphan_crawl_rows($orphan_db, array('http://www.easyeurope.com.my/golden-egypt'), 'url');
check('orphan url-mode dedups normalised url', 2, count($fuzzy));
check('orphan url-mode kept row ids', array(9, 4), array($fuzzy[0]['analysis_id'], $fuzzy[1]['analysis_id']));
// Case-insensitive covered match; blank/no-host rows are skipped.
$blank = competitor_orphan_crawl_rows(array((object) array('id' => 1, 'url' => '', 'created_at' => '')), array(), 'host');
check('orphan skips hostless row', 0, count($blank));

check('ui_labels en coverage', 'Coverage', competitor_ui_labels('en')['coverage']);
check('ui_labels cn coverage', '覆盖范围', competitor_ui_labels('cn')['coverage']);
check('ui_labels unknown key falls back to en set', 'Meals', competitor_ui_labels('en')['meals']);
check('ui_labels en traveller_suitability', 'Suitability by Traveller Type', competitor_ui_labels('en')['traveller_suitability']);
check('ui_labels en segment label', 'Family with Elderly', competitor_ui_labels('en')['seg_family_elderly']);
check('ui_labels cn segment label', '亲子家庭', competitor_ui_labels('cn')['seg_family_kids']);
check('ui_labels cn suitability level', '高', competitor_ui_labels('cn')['suit_high']);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "{$failures} FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
