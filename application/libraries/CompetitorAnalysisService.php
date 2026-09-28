<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CompetitorAnalysisService — the network side of the Competitor Analysis
 * feature. A URL is treated as a SITE: analyze_site() crawls it ourselves (fetch
 * base page → discover same-host links → keep tour/product URLs up to a cap) and
 * analyses each product page. Per page we scrape OURSELVES first (fetch_url +
 * competitor_html_to_text) and send the extracted text to OpenAI's Responses API
 * with no web_search tool — cheaper, no per-call browse fee; only when our scrape
 * is too thin (a JS-rendered page a plain fetch can't read) do we fall back to
 * the browsing agent (web_search) for that page. Uploads (PDF/image) stay a
 * single-product analysis with the file as the source.
 *
 * All pure transforms (prompt building, response/JSON parsing) live in
 * helpers/competitor_analysis_helper.php and are unit-tested; this class only
 * does the HTTP call.
 *
 * Config comes from .env via get_env():
 *   OPENAI_API_KEY         (required)
 *   OPENAI_MODEL           (optional, default gpt-4o-mini — must support web_search)
 *   OPENAI_BASE_URL        (optional, default https://api.openai.com/v1)
 *   OPENAI_WEB_SEARCH_TOOL (optional; auto-picks web_search for gpt-5/o-series,
 *                           web_search_preview for gpt-4o — override to force one)
 *
 * analyze() returns a flat record (see competitor_parse_ai_response) plus
 * 'raw_json' and 'model'. On any failure it throws Exception with a
 * human-readable message the controller surfaces to the user.
 */
class CompetitorAnalysisService
{
	protected $CI;

	/**
	 * Feature label this instance logs its AI usage under (ai_usage_log.feature).
	 * The same service powers several pages — Competitor Product, Our Product and
	 * Product extraction — so callers set this via set_usage_feature() to attribute
	 * cost correctly. Defaults to Competitor Analysis.
	 */
	protected $usage_feature = 'Competitor Analysis';

	/** Token usage from the most recent request(), for costing to_record(). */
	protected $last_usage = array('input_tokens' => 0, 'output_tokens' => 0);

	/**
	 * Running USD cost of every OpenAI call since the last reset — used to bill the
	 * AI-crawl DISCOVERY step (its web_search call), whose cost isn't attached to any
	 * saved product row. Reset at the start of each crawl_to_text().
	 */
	protected $run_cost = 0.0;

	/**
	 * Set by extract_source_text(): true when the page was a blank-shell JS SPA and
	 * we only recovered PARTIAL metadata (not full API content) — analyze_url() then
	 * uses the web_search agent (seeded with that metadata) to fill the gaps.
	 */
	protected $last_spa_partial = false;

	/**
	 * Set by extract_source_text(): true when the fetched page's JSON-LD marks it a
	 * category/listing page (not a single product) — expand_source_items() drops it
	 * so a whole catalogue isn't analysed as one bogus "product".
	 */
	protected $last_is_listing = false;
	// Set per page while reading: the page's JSON-LD declares it a Product/Trip — a
	// language-neutral keep-signal for the tour gate (competitor_jsonld_is_product).
	protected $last_is_product = false;
	// Per-crawl memo of collect_sitemap_locs() keyed by origin (the sitemap tree is
	// walked by discovery, the coverage target and the recrawl sweep — fetch it once).
	protected $sitemap_locs_cache = array();
	// Adaptive politeness: host => unix timestamp until which to hold off, set when the
	// host returns 429/503 (honouring Retry-After). Both fetch paths wait it out so we
	// back off instead of hammering a throttling host into more 429s.
	protected $host_cooldown = array();

	/** Set by extract_source_text(): the page's real product name (<h1>/og:title). */
	protected $last_page_title = '';

	/** Product + PDF links found on the last-read page's HTML (for listing drill-down). */
	protected $last_page_links = array();
	/** Same-host links on the last page, UNFILTERED (product-URL heuristic not applied)
	 *  — so a listing's numeric-id children (/tour-package/1077) are seen for the
	 *  child-link listing test even though they fail competitor_is_product_url. */
	protected $last_page_links_raw = array();
	/** The last-read page's (possibly rendered) HTML — for classic ?page=N/rel=next
	 *  pagination following in html_paginated_child_urls(). */
	protected $last_page_html = '';

	/** True when expand_source_items() dropped the last URL because it's a listing. */
	protected $last_expand_was_listing = false;

	/** Site-wide nav/menu links (from the homepage) — excluded when drilling listings. */
	protected $nav_links = array();

	/**
	 * Set by extract_source_text() when the page was an ICE series API URL: the
	 * customer-facing /web/itinerary/<code> URL to store/show instead of the raw
	 * /api/v1/series/<id> URL the crawler actually read. '' for non-ICE pages.
	 */
	protected $last_ice_web_url = '';

	/** JSON API bodies captured by the Playwright render service on the last render. */
	protected $last_render_apis = array();

	/**
	 * How the current crawl discovered its products ('ice' | 'sitemap' | 'html' |
	 * 'headless' | 'pdf' | ''). Set by discover_product_urls(); crawl_to_text() uses
	 * it to gate headless during READING — a structured-catalogue site (sitemap/ICE)
	 * never needs a browser to read a page, so we don't risk one hanging.
	 */
	protected $last_discovery_source = '';

	/** Whether reading a product page may fall back to headless Chrome (see above). */
	protected $reading_allow_headless = true;

	/**
	 * The site's AUTHORITATIVE product total for the current crawl, when known exactly
	 * (set by discover_ice() to the enumerated ICE catalogue size). 0 = unknown here;
	 * authoritative_total() then falls back to the listing's declared count. Drives the
	 * self-healing coverage recrawl in crawl_to_text().
	 */
	protected $last_authoritative_total = 0;

	/** True for the first FULL crawl of a host (no learned baseline): union headless with
	 * the normal discovery to establish the site's true best (recorded as the "note"). */
	protected $thorough_discovery = false;

	/** Optional file the discovered URL list is written to (set per-crawl by the worker,
	 * truncated at the start of each crawl). '' = don't persist. */
	protected $discovery_url_file = '';

	/** Set the per-crawl discovered-URL file (the work queue). NOT truncated here: the first
	 * run writes it fresh (each crawl has its own per-job file), and a continuation run REUSES
	 * it to skip re-discovery (discover-once-then-reuse). Pass '' to disable. */
	public function set_discovery_url_file($path)
	{
		$this->discovery_url_file = (string) $path;
	}

	/** Hard cap on URLs collected during discovery — the memory guard that stops a mega-site
	 * (millions of sitemap entries) from OOM-killing the worker. COMPETITOR_MAX_DISCOVER_URLS
	 * overrides; default 5000 (far above any real competitor, only ever bites aggregators). */
	protected function discovery_cap()
	{
		$n = (int) get_env('COMPETITOR_MAX_DISCOVER_URLS');
		// Generous by default so a full catalogue is captured (real competitors are well under
		// this; only a mega-aggregator hits it). It's just a memory guard — 20k URLs is a few MB.
		return $n > 0 ? $n : 20000;
	}

	/** Max products READ per full crawl. Default 0 = no cap — reading is instead bounded by
	 * the discovery cap and made safe by chunked checkpointing (below). COMPETITOR_MAX_READ
	 * overrides; set > 0 only if you want an explicit hard sample. */
	protected function read_cap()
	{
		$n = get_env('COMPETITOR_MAX_READ');
		return ($n === '' || $n === null) ? 0 : (int) $n;
	}

	/** One uniform CHUNK size for EVERY crawl: read this many URLs per run, checkpoint the items
	 * file, then (if more remain) re-spawn to continue. A small site finishes in one chunk; a big
	 * site auto-continues over many paced runs. COMPETITOR_READ_CHUNK overrides; default 500.
	 * <= 0 disables chunking (read everything in one run, single final write). */
	protected function read_chunk()
	{
		$n = get_env('COMPETITOR_READ_CHUNK');
		return ($n === '' || $n === null) ? 500 : (int) $n;
	}

	/** Optional items file crawl_to_text checkpoints to after each chunk (set per-crawl by the
	 * worker). '' = don't checkpoint mid-read. */
	protected $items_checkpoint_file = '';

	/** Set the file crawl_to_text flushes read products to after each chunk (crash-resilience). */
	public function set_items_checkpoint_file($path)
	{
		$this->items_checkpoint_file = (string) $path;
	}

	/** Optional file tracking URLs already ATTEMPTED across resumable chunk runs. When set (with
	 * a positive read_chunk), a crawl reads only the next chunk and carries prior progress. */
	protected $done_file = '';

	/** True after crawl_to_text if more discovered URLs remain unread — the worker re-spawns the
	 * job to continue the next chunk. */
	protected $last_has_more = false;

	/** The URLs read in the current run's chunk — marked "done" AFTER reading so a mid-chunk
	 * crash loses nothing (the chunk is re-read next run and deduped). */
	protected $last_run_urls = array();

	/** Set the resume "attempted URLs" file to enable resumable chunked crawling. */
	public function set_done_file($path)
	{
		$this->done_file = (string) $path;
	}

	/** Whether the last crawl_to_text left unread URLs (→ worker should re-spawn to continue). */
	public function crawl_has_more()
	{
		return (bool) $this->last_has_more;
	}

	/** The discovery method used ('sitemap','ice','html','headless','ai','resume',…) — the worker
	 * persists this after the first run so continuation runs can restore it (keeps headless
	 * gating correct across chunks; e.g. an ICE site must NOT headless-render on resume). */
	public function discovery_source()
	{
		return (string) $this->last_discovery_source;
	}

	/** Restore the discovery source on a continuation run (see discovery_source()). '' = ignore. */
	public function set_forced_source($source)
	{
		$this->forced_source = (string) $source;
	}
	protected $forced_source = '';

	/** Flush the products read so far to the checkpoint file (best-effort, atomic-ish). */
	protected function checkpoint_items($out)
	{
		if ($this->items_checkpoint_file === '') {
			return;
		}
		$tmp = $this->items_checkpoint_file . '.tmp';
		if (@file_put_contents($tmp, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
			@rename($tmp, $this->items_checkpoint_file);   // atomic swap — never a half-written file
		}
	}

	/** Below this many chars, an SPA's recovered metadata is treated as partial. */
	const SPA_PARTIAL_MAX = 2000;

	/** Per-crawl web_search (browsing) budget — cap + how many we've spent. */
	protected $websearch_cap   = 0;
	protected $websearch_count = 0;

	/** Per-crawl headless-render budget (JS pages) — cap + how many we've spent. */
	protected $headless_cap   = 0;
	protected $headless_count = 0;

	public function __construct()
	{
		$this->CI = &get_instance();
		$this->CI->load->helper('competitor_analysis');
	}

	/** Hard ceiling on pages fetched per crawl so a big site can't run away. */
	const CRAWL_MAX_FETCHES = 25;

	/** Flatten discovery results ([{url,label}] or [url,…]) to a URL string list. */
	protected function discovered_urls($found)
	{
		$out = array();
		foreach ((array) $found as $it) {
			$u = is_array($it) ? (isset($it['url']) ? $it['url'] : '') : $it;
			$u = trim((string) $u);
			if ($u !== '' && ! in_array($u, $out, true)) {
				$out[] = $u;
			}
		}
		return $out;
	}

	/**
	 * Crawl the base URL and return the discovered product-page URLs (heuristic) —
	 * the cheap, AI-free discovery step. $limit <= 0 = unbounded (every product);
	 * a positive value caps the count.
	 */
	public function discover_product_urls($base_url, $limit = 0)
	{
		$base_url = trim((string) $base_url);
		if ( ! preg_match('#^https?://#i', $base_url)) {
			throw new Exception('Please enter a valid http(s) URL.');
		}
		$limit = (int) $limit;   // <= 0 means unbounded
		$this->last_discovery_source = '';

		// ICE Holidays LISTING/search page: read its OWN filtered API so a pasted
		// filtered listing analyses only its matches, not the whole catalogue.
		$listing = $this->discover_ice_listing($base_url, $limit);
		if ($listing !== null) {
			$this->last_discovery_source = 'ice';
			return $listing;
		}

		// ICE Holidays platform (e.g. gd.my): enumerate real products via its JSON
		// API — accurate AND free (no AI). Returns [{url,label}] or null if not ICE.
		$ice = $this->discover_ice($base_url, $limit);
		if ($ice !== null) {
			$this->last_discovery_source = 'ice';
			return $ice;
		}

		// Site ROOT pasted → SWEEP the whole same-host site: enumerate EVERY candidate
		// page (sitemap + a broad same-host crawl, NOT filtered by product-keyword),
		// read them all and let the itinerary gate keep the real tours. This is how we
		// get ALL tours — including oddly-named ones (/detail/12345) the product-URL
		// heuristic misses. Deeper URLs (a user-pasted listing/product) stay scoped, so
		// the sweep is site-root only.
		if (competitor_is_site_root($base_url)) {
			$sweep = $this->discover_all_urls($base_url, $limit);
			// First crawl of this host (thorough learn): ALSO render (headless) + mine the
			// hydration blobs, then union — so a JS site's links are learned even when the
			// sitemap/BFS alone would miss them. Slow, once per new host; the note then
			// carries the baseline forward so later crawls skip this.
			if ($this->thorough_discovery) {
				$rendered = $this->discover_via_headless($base_url, $limit);
				$before   = count($sweep);
				$sweep    = array_values(array_unique(array_merge(
					$this->discovered_urls($sweep), $this->discovered_urls($rendered))));
				$this->log_crawl('thorough_union', array('sweep' => $before,
					'headless_added' => count($sweep) - $before, 'total' => count($sweep)));
				if ( ! empty($sweep)) {
					$this->last_discovery_source = 'thorough';   // reading may render JS tours
					return $sweep;
				}
			}
			if ( ! empty($sweep)) {
				$this->last_discovery_source = 'sweep';   // not sitemap/ice → reading may render JS tours
				return $sweep;
			}
		}

		// Plain same-host HTML crawl. If it finds nothing (a JS listing whose tour
		// links are injected by JavaScript, e.g. chanbrothers), render the page — and
		// its category pages — with headless Chrome and pull the product links.
		$urls = $this->crawl_product_urls($base_url, $limit);
		if (count($urls) < 3) {
			// Many SPAs (Next.js/Nuxt) ship every tour's url/slug in a hydration blob
			// INSIDE the served HTML even when the DOM has no anchors — mine it before
			// paying for a headless render. Often turns a "needs headless" SPA into a
			// cheap plain-HTML crawl.
			$embedded = competitor_urls_from_embedded_json($this->fetch_url($base_url), $base_url);
			if ( ! empty($embedded)) {
				$urls = array_values(array_unique(array_merge($urls, $embedded)));
				$this->log_crawl('embedded_json_discovery', array('base_url' => $base_url, 'products' => count($embedded)));
			}
		}
		if (count($urls) >= 3) {
			$this->last_discovery_source = 'html';   // enough static links — trust it
		} else {
			// Too few from static HTML → likely a JS site whose products are injected.
			// Render (homepage + categories) and keep whichever yields more products.
			$rendered = $this->discover_via_headless($base_url, $limit);
			if (count($rendered) > count($urls)) {
				$urls = $rendered;
				$this->last_discovery_source = 'headless';
			} elseif ( ! empty($urls)) {
				$this->last_discovery_source = 'html';
			}
		}

		// Still nothing? The listing may link straight to PDF brochures (one per
		// tour, e.g. cit.travel) — those are the products. Our link crawler skips
		// PDFs as assets, so recover them here.
		if (empty($urls)) {
			$html = $this->fetch_url($base_url);
			$pdfs = array();
			foreach (competitor_extract_file_links($html, $base_url) as $f) {
				if (preg_match('#\.pdf(\?|$)#i', $f)) {
					$pdfs[] = $f;
				}
			}
			if ( ! empty($pdfs)) {
				$urls = ((int) $limit > 0) ? array_slice($pdfs, 0, (int) $limit) : $pdfs;
				$this->last_discovery_source = 'pdf';
				$this->log_crawl('pdf_brochure_discovery', array('base_url' => $base_url, 'count' => count($urls)));
			}
		}
		return $urls;
	}

	/**
	 * Walk the site's sitemap (robots.txt entries or /sitemap.xml, following
	 * <sitemapindex> children uncapped — the seen-set terminates it) and return ALL
	 * same-host <loc> URLs, unfiltered (products AND category/hub pages). Gzipped
	 * (.xml.gz) bodies are inflated. Shared by product discovery and the keyword
	 * category drill-down. Returns [] when there's no usable sitemap.
	 */
	protected function collect_sitemap_locs($base_url)
	{
		$parts = parse_url($base_url);
		if (empty($parts['scheme']) || empty($parts['host'])) {
			return array();
		}
		$origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
		// Walk the whole sitemap tree at most ONCE per origin per crawl — discovery, the
		// coverage-target estimate and the recrawl sweep all ask for it, and it's dozens
		// of fetches on a big site. Cached on the instance (one crawl = one origin).
		if (isset($this->sitemap_locs_cache[$origin])) {
			return $this->sitemap_locs_cache[$origin];
		}
		$norm   = function ($h) { return preg_replace('/^www\./i', '', strtolower((string) $h)); };
		$host   = $norm($parts['host']);

		// Prefer the sitemap(s) robots.txt DECLARES (authoritative). If it declares none,
		// PROBE the well-known locations so a site that keeps its sitemap off the default
		// path (WordPress /wp-sitemap.xml, Yoast /sitemap_index.xml…) still gets found
		// instead of collapsing to the slow headless fallback.
		$declared = competitor_robots_sitemaps($this->fetch_url($origin . '/robots.txt'));
		$queue    = ! empty($declared) ? $declared : competitor_sitemap_candidates($origin);

		$cap  = $this->discovery_cap();   // memory guard — stop before a mega-sitemap OOMs us
		$seen = array();
		$locs = array();
		while ( ! empty($queue)) {
			if (count($locs) >= $cap) {
				$this->log_crawl('sitemap_cap', array('cap' => $cap, 'origin' => $origin));
				break;   // bounded: don't hold millions of sitemap URLs in RAM
			}
			$sm = array_shift($queue);
			if (isset($seen[$sm])) {
				continue;
			}
			$seen[$sm] = true;

			// fetch_url already retries transient failures (timeout / 5xx / 429 / empty-200)
			// on a fresh connection — don't add more hits here (retrying INTO a rate-limit
			// only makes a 429 worse). An empty result just skips this sitemap.
			$xml = $this->fetch_url($sm);
			if ($xml === '') {
				// A declared sitemap that comes back EMPTY is the tell-tale of a site blanking
				// the response to automated fetches (seen on lovelyvacation.com.my) — log it so
				// the "few products" cause is visible instead of silently falling to headless.
				$this->log_crawl('sitemap_empty', array('sm' => $sm));
				continue;
			}
			if (substr($xml, 0, 2) === "\x1f\x8b" && function_exists('gzdecode')) {
				$xml = (string) @gzdecode($xml);
			}
			$parsed = competitor_parse_sitemap($xml);
			if ($parsed['is_index']) {
				foreach ($parsed['urls'] as $child) {
					if ( ! isset($seen[$child])) { $queue[] = $child; }
				}
				continue;
			}
			foreach ($parsed['urls'] as $u) {
				if (isset($seen[$u]) || $norm(parse_url($u, PHP_URL_HOST)) !== $host) {
					continue;
				}
				$seen[$u] = true;
				$locs[] = $u;
				if (count($locs) >= $cap) { break; }   // stop mid-file too
			}
		}
		// Cache only a NON-empty result — an empty one is almost always a transient fetch
		// failure (cold-start/timeout), and caching it would lock the whole crawl out of
		// the sitemap; leaving it uncached lets a later caller retry and recover.
		if ( ! empty($locs)) {
			$this->sitemap_locs_cache[$origin] = $locs;
		}
		return $locs;
	}

	/**
	 * Keyword category drill-down for JS sites: a SPA (e.g. chanbrothers) lists only
	 * category pages in its sitemap (/destinations/europe/finland) and JS-injects the
	 * actual tour links — so a keyword crawl finds nothing. Here we take the sitemap's
	 * NON-product hub pages whose slug matches the keyword, render each with headless
	 * Chrome, and extract the product links the JS drew. Returns those product URLs
	 * (bounded by the per-crawl headless budget). [] when nothing matches / no browser.
	 */
	protected function discover_keyword_hub_products($base_url, $keyword)
	{
		if (trim((string) $keyword) === '') {
			return array();
		}
		$locs = $this->collect_sitemap_locs($base_url);
		$hubs = array();
		foreach (competitor_filter_urls_by_keyword($locs, $keyword) as $u) {
			if ( ! competitor_is_product_url($u)) {   // a category/listing page, not a product
				$hubs[] = $u;
			}
		}
		if (empty($hubs)) {
			return array();
		}
		$found = array();
		foreach ($hubs as $hub) {
			$rendered = $this->fetch_rendered($hub);
			if ($rendered === '') {
				continue;
			}
			foreach (competitor_filter_candidate_product_urls(competitor_extract_links($rendered, $hub), parse_url($hub, PHP_URL_HOST)) as $p) {
				$found[$p] = true;
			}
		}
		$out = array_keys($found);
		$this->log_crawl('keyword_hub_discovery', array('keyword' => $keyword, 'hubs' => count($hubs), 'products' => count($out)));
		return $out;
	}

	/**
	 * Headless discovery for JS sites with no usable sitemap (chanbrothers,
	 * applevacations, sedunia): render the homepage, take the product links AND the
	 * same-host CATEGORY/listing links it draws, then render a BOUNDED number of those
	 * category pages to reach the tours JS only injects there. Bounded by
	 * COMPETITOR_HEADLESS_CATEGORIES (default 12) and the per-crawl headless budget, so
	 * it can't run away; the log records what was covered (no silent truncation).
	 */
	protected function discover_via_headless($base_url, $limit)
	{
		$rendered = $this->fetch_rendered($base_url);
		if ($rendered === '') {
			return array();
		}
		$products = array();
		foreach (competitor_filter_candidate_product_urls(competitor_extract_links($rendered, $base_url), parse_url($base_url, PHP_URL_HOST)) as $p) {
			$products[$p] = true;
		}
		// Opaque SPA: product links live only in the captured JSON APIs, not the DOM.
		foreach ($this->urls_from_apis($base_url) as $p) {
			$products[$p] = true;
		}
		// …or in the hydration blob embedded in the rendered HTML (__NEXT_DATA__/__NUXT__).
		foreach (competitor_urls_from_embedded_json($rendered, $base_url) as $p) {
			$products[$p] = true;
		}

		$host = preg_replace('/^www\./i', '', strtolower((string) parse_url($base_url, PHP_URL_HOST)));
		$cats = array();
		foreach (competitor_extract_links($rendered, $base_url) as $u) {
			if (competitor_is_product_url($u)) {
				continue;   // already a product, not a category to drill into
			}
			$h = preg_replace('/^www\./i', '', strtolower((string) parse_url($u, PHP_URL_HOST)));
			if ($h !== $host) {
				continue;   // same host only
			}
			if (preg_match('#/(about|contact|blog|news|faq|login|signin|register|cart|account|career|job|privacy|policy|term)#i', $u)) {
				continue;   // obvious non-category chrome
			}
			$path  = (string) parse_url($u, PHP_URL_PATH);
			$depth = count(array_filter(explode('/', $path), 'strlen'));
			if ($path === '' || $path === '/' || $depth < 1 || $depth > 3) {
				continue;   // homepage or too-deep
			}
			$cats[$u] = true;
		}

		$cat_cap = (int) get_env('COMPETITOR_HEADLESS_CATEGORIES');
		$cat_cap = $cat_cap > 0 ? $cat_cap : 12;
		$done = 0;
		foreach (array_keys($cats) as $cat) {
			if ($done >= $cat_cap) {
				break;
			}
			if ($this->headless_cap > 0 && $this->headless_count >= $this->headless_cap) {
				break;
			}
			$r = $this->fetch_rendered($cat);
			$done++;
			if ($r === '') {
				continue;
			}
			foreach (competitor_filter_candidate_product_urls(competitor_extract_links($r, $cat), parse_url($cat, PHP_URL_HOST)) as $p) {
				$products[$p] = true;
			}
		}

		$out = array_keys($products);
		$this->log_crawl('headless_discovery', array('base_url' => $base_url,
			'category_candidates' => count($cats), 'categories_rendered' => $done,
			'products' => count($out)));
		return ((int) $limit > 0) ? array_slice($out, 0, (int) $limit) : $out;
	}

	/**
	 * ICE listing/search discovery: when the base URL is a /web/listing?<query>
	 * page, read /api/v1/series?<same query> and turn its filtered itineraries into
	 * series-detail pick items. Returns [{url,label}] (possibly empty) for a listing
	 * URL — authoritative, so the caller never falls back to the whole-catalogue
	 * enumeration — or null when it's not a listing URL.
	 */
	protected function discover_ice_listing($base_url, $limit)
	{
		$api = competitor_ice_listing_api_url($base_url);
		if ($api === '') {
			return null;
		}
		$parts  = parse_url($base_url);
		$origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
		$items  = competitor_ice_series_items($this->fetch_url($api), $origin, $limit);
		$this->log_crawl('ice_listing', array('base_url' => $base_url, 'api' => $api, 'count' => count($items)));
		return $items;
	}

	/**
	 * ICE Holidays JSON-API discovery. Detects the platform via its
	 * /api/v1/series/country_list endpoint, then walks countries pulling their
	 * series (tour products) into pick-list items until $limit is reached.
	 * Returns [{url,label}] on an ICE site (possibly empty), or null when the site
	 * is not ICE (so the caller falls back to the HTML crawl).
	 */
	protected function discover_ice($base_url, $limit)
	{
		$parts = parse_url($base_url);
		if (empty($parts['scheme']) || empty($parts['host'])) {
			return null;
		}
		$origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

		$cl = json_decode($this->fetch_url($origin . '/api/v1/series/country_list'), true);
		if ( ! is_array($cl) || empty($cl['countries']) || ! is_array($cl['countries'])) {
			return null;   // not an ICE site
		}
		$this->log_crawl('ice_detected', array('origin' => $origin, 'countries' => count($cl['countries'])));

		// Enumerate the AUTHORITATIVE flat catalogues — no 25-per-keyword-query cap and
		// no reliance on the (incomplete) country_list. Series + cruise share the
		// /api/v1/series/<id> reader (cruise via ?type=cruise); land tours are a separate
		// /b2c2b catalogue read per id; posts are promo bundles outside the series list.
		$series = competitor_ice_code_list_items($this->fetch_url($origin . '/api/v1/series/itinerary_list'), $origin, 'series');
		$cruise = competitor_ice_code_list_items($this->fetch_url($origin . '/api/v1/series/cruise_itinerary_list'), $origin, 'cruise');
		$land   = $this->discover_ice_land_tours($origin);
		$posts  = $this->discover_ice_posts_auto($base_url, '');

		// Interleave the four sources so a BOUNDED pick stays varied (rather than filling
		// the whole cap from series alone); dedupe by url.
		$items = competitor_interleave_unique(array($series, $cruise, $land, $posts));
		// The interleaved set IS the full ICE catalogue — record it as the authoritative
		// total (before any limit slice) so the coverage check knows the crawl is complete.
		$this->last_authoritative_total = count($items);
		if ($limit > 0) {
			$items = array_slice($items, 0, $limit);
		}
		$this->log_crawl('ice_discovery', array('origin' => $origin,
			'series' => count($series), 'cruise' => count($cruise),
			'land' => count($land), 'posts' => count($posts), 'count' => count($items)));
		return $items;
	}

	/**
	 * Enumerate the ICE /b2c2b land-tour catalogue: page 1 exposes meta.total_pages,
	 * then we walk the rest (20/page). Bounded by COMPETITOR_ICE_MAX_LIST_PAGES
	 * (<= 0 = all pages; total_pages caps it either way). Each row becomes a
	 * /b2c2b/api/v1/land_tours/<id> detail-URL pick. Returns [{url,label}].
	 */
	protected function discover_ice_land_tours($origin)
	{
		$first = $this->fetch_url($origin . '/b2c2b/api/v1/land_tours');
		$items = competitor_ice_land_tour_items($first, $origin);
		if (empty($items)) {
			return array();
		}
		$total = competitor_ice_list_total_pages($first);
		$cap   = (int) get_env('COMPETITOR_ICE_MAX_LIST_PAGES');   // <= 0 = all pages
		$last  = ($cap > 0) ? min($total, $cap) : $total;
		for ($page = 2; $page <= $last; $page++) {
			$resp = $this->fetch_url($origin . '/b2c2b/api/v1/land_tours?page=' . $page);
			$items = array_merge($items, competitor_ice_land_tour_items($resp, $origin));
		}
		if ($cap > 0 && $total > $cap) {
			$this->log_crawl('ice_land_tours_capped', array('total_pages' => $total, 'read_pages' => $last));
		}
		$this->log_crawl('ice_land_tours', array('total_pages' => $total, 'read_pages' => $last, 'count' => count($items)));
		return array_values($items);
	}

	/** ICE post categories to probe (brand-prefixed, e.g. gd_domestic) — there's no
	 *  category-list API, so we sweep the common ones. */
	protected static $ICE_POST_CATEGORIES = array(
		'domestic', 'international', 'cruise', 'cruises', 'promotion', 'promotions',
		'group', 'groups', 'muslim', 'theme_park', 'free_easy', 'fly_free_easy',
		'honeymoon', 'education', 'package', 'packages', 'tour', 'tours', 'holiday',
		'umrah', 'hajj', 'fit', 'series',
	);

	/**
	 * Auto-discover ICE "posts" (promo bundles that live OUTSIDE the series catalogue —
	 * gd.my's Sabah packages are here) from the base URL. There's no post category-list
	 * API, so we sweep the common brand-prefixed categories (gd_domestic, …); each post
	 * found becomes /api/v1/posts/<id> (later split into packages). $keyword keeps only
	 * posts whose listing JSON matches it. Returns [{url,label}] (possibly empty).
	 */
	protected function discover_ice_posts_auto($base_url, $keyword = '')
	{
		$parts  = parse_url($base_url);
		if (empty($parts['scheme']) || empty($parts['host'])) {
			return array();
		}
		$origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
		$prefix = explode('.', preg_replace('/^www\./', '', strtolower($parts['host'])))[0];   // gd.my → gd
		$keyword = trim((string) $keyword);

		$items = array();
		$cats  = 0;
		foreach (self::$ICE_POST_CATEGORIES as $c) {
			$cat = $prefix . '_' . $c;
			$page = 1;
			$pages = 1;
			$got = false;
			do {
				$url = $origin . '/api/v1/posts?filter%5Bcategory%5D=' . rawurlencode($cat) . '&page=' . $page . '&limit=50';
				$d = json_decode($this->fetch_url($url), true);
				if ( ! is_array($d) || empty($d['data'])) { break; }
				$got = true;
				$pages = isset($d['meta']['total_pages']) ? (int) $d['meta']['total_pages'] : 1;
				foreach ($d['data'] as $p) {
					$id = isset($p['id']) ? (string) $p['id'] : '';
					if ($id === '') { continue; }
					// Keyword: match the whole post JSON (title + nested package text).
					if ($keyword !== '' && mb_stripos(json_encode($p), $keyword, 0, 'UTF-8') === false) {
						continue;
					}
					$attr  = isset($p['attributes']) && is_array($p['attributes']) ? $p['attributes'] : $p;
					$title = trim(preg_replace('/\s+/', ' ', (string) (isset($attr['title']) ? $attr['title'] : '')));
					$u = $origin . '/api/v1/posts/' . $id;
					$items[$u] = array('url' => $u, 'label' => $title);
				}
				$page++;
			} while ($page <= $pages && $page <= 10);
			if ($got) { $cats++; }
		}
		$this->log_crawl('ice_posts_auto', array('categories' => $cats, 'keyword' => $keyword, 'count' => count($items)));
		return array_values($items);
	}

	/**
	 * ICE keyword search: on an ICE site, run its NATIVE search API
	 * (/api/v1/series?keyword=…) so a keyword crawl uses ICE's own server-side match
	 * (e.g. "japan" → the Osaka/Kyoto series whose captions don't contain the word
	 * "japan"). Returns [{url,label}] (possibly empty) on an ICE site, or null when the
	 * site is not ICE (caller falls back to normal keyword discovery).
	 */
	protected function discover_ice_keyword($base_url, $keyword)
	{
		$parts = parse_url($base_url);
		if (empty($parts['scheme']) || empty($parts['host'])) {
			return null;
		}
		$origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
		$cl = json_decode($this->fetch_url($origin . '/api/v1/series/country_list'), true);
		if ( ! is_array($cl) || empty($cl['countries'])) {
			return null;   // not an ICE site
		}
		$resp  = $this->fetch_url($origin . '/api/v1/series?keyword=' . rawurlencode($keyword));
		$items = array_values(competitor_ice_series_items($resp, $origin, 0));
		// ICE series search does NOT cover "posts" — enumerate + keyword-match those too
		// (so gd.my + "sabah" finds the domestic Sabah post the series search misses).
		$seen = array();
		foreach ($items as $it) { $seen[$it['url']] = true; }
		foreach ($this->discover_ice_posts_auto($base_url, $keyword) as $p) {
			if ( ! isset($seen[$p['url']])) { $items[] = $p; }
		}
		$this->log_crawl('ice_keyword', array('origin' => $origin, 'keyword' => $keyword, 'count' => count($items)));
		return $items;
	}

	/**
	 * AI-assisted product discovery — the fallback when the plain HTML crawl
	 * finds nothing (a JavaScript-rendered site whose links aren't in the served
	 * HTML). One web_search call browses the site and returns individual product
	 * page URLs; we validate/dedupe them against the site host. Costs one small
	 * OpenAI call (discovery only — no per-product analysis yet).
	 */
	public function discover_product_urls_ai($base_url, $limit = 10, $keyword = '')
	{
		$base_url = trim((string) $base_url);
		if ( ! preg_match('#^https?://#i', $base_url)) {
			throw new Exception('Please enter a valid http(s) URL.');
		}
		$limit = (int) $limit > 0 ? (int) $limit : 10;

		$spec = competitor_build_discovery_agent($base_url, $limit, $keyword);
		$raw  = $this->request($spec['instructions'], $spec['input'], array(array('type' => $this->web_search_tool())), 'discovery ' . $base_url);
		$host = parse_url($base_url, PHP_URL_HOST);
		$found = competitor_parse_url_list($raw, $host ?: '', $limit);
		$this->log_crawl('discovery_result', array('base_url' => $base_url, 'keyword' => (string) $keyword, 'count' => count($found)));
		return $found;
	}

	/**
	 * Analyse an explicit list of product URLs (e.g. the ones the Owner ticked)
	 * into one combined report. Same shape as analyze_site().
	 */
	public function analyze_urls($urls, $our_products, $progress = null)
	{
		$clean = array();
		foreach ((array) $urls as $u) {
			$u = trim((string) $u);
			if ($u !== '' && preg_match('#^https?://#i', $u) && ! in_array($u, $clean, true)) {
				$clean[] = $u;
			}
		}
		if (empty($clean)) {
			throw new Exception('No valid product URLs to analyse.');
		}
		return $this->analyze_product_list($clean, $our_products, $progress);
	}

	/**
	 * Run the single-page analysis over each URL and fold the results into one
	 * combined report: ['products' => [record,…], 'input_tokens', 'output_tokens',
	 * 'cost_usd', 'model']. A page that fails is skipped; throws only if every
	 * page failed.
	 */
	protected function analyze_product_list($urls, $our_products, $progress = null)
	{
		// Reset the per-crawl web_search + headless budgets for this run.
		$this->reset_render_budget();
		$tick  = is_callable($progress) ? $progress : function () {};
		$total = count($urls);

		$products = array();
		$in = 0; $out = 0; $cost = 0.0;
		$first_error = null;
		$done = 0;
		foreach ($urls as $purl) {
			$tick('analysing', $done, $total, $purl);
			try {
				$record = $this->analyze_url($purl, $our_products);
			} catch (Exception $e) {
				if ($first_error === null) { $first_error = $e->getMessage(); }
				$this->log_crawl('product_failed', array('url' => $purl, 'message' => $e->getMessage()));
				$tick('analysing', ++$done, $total, $purl);
				continue;
			}
			$record['url'] = $purl;
			$products[]    = $record;
			$in   += (int) $record['input_tokens'];
			$out  += (int) $record['output_tokens'];
			$cost += (float) $record['cost_usd'];
			$tick('analysing', ++$done, $total, $purl);
		}

		$this->log_crawl('websearch_total', array('count' => $this->websearch_count, 'cap' => $this->websearch_cap));

		if (empty($products)) {
			throw new Exception($first_error ?: 'No competitor products could be analysed.');
		}

		return array(
			'products'         => $products,
			'input_tokens'     => $in,
			'output_tokens'    => $out,
			'cost_usd'         => round($cost, 6),
			'model'            => $this->model(),
			'websearch_calls'  => $this->websearch_count,
		);
	}

	/**
	 * Analyse products from ALREADY-CRAWLED text — no re-fetch, no web_search. Each
	 * $items entry is ['url' => …, 'text' => …] (the text a prior crawl_to_text()
	 * produced, PDFs and all). We just hand each text to OpenAI with the scraped
	 * agent, so "Analyse" reuses exactly what was crawled/inspected — cheapest path.
	 * Same combined-report shape as analyze_urls(). $progress($phase,$done,$total,
	 * $label) is optional. Items with empty text are skipped.
	 */
	public function analyze_texts($items, $our_products, $progress = null)
	{
		$tick  = is_callable($progress) ? $progress : function () {};
		$items = is_array($items) ? $items : array();
		$total = count($items);

		$products = array();
		$in = 0; $out = 0; $cost = 0.0;
		$first_error = null;
		$done = 0;
		foreach ($items as $it) {
			$url  = isset($it['url']) ? (string) $it['url'] : '';
			$text = isset($it['text']) ? (string) $it['text'] : '';
			$tick('analysing', $done, $total, $url);
			try {
				if (trim($text) === '') {
					throw new Exception('No crawled text for ' . ($url !== '' ? $url : 'product'));
				}
				$spec   = competitor_build_scraped_agent($url, $text, $our_products);
				$record = $this->to_record($this->request($spec['instructions'], $spec['input'], array(), 'reuse ' . $url));
			} catch (Exception $e) {
				if ($first_error === null) { $first_error = $e->getMessage(); }
				$this->log_crawl('product_failed', array('url' => $url, 'message' => $e->getMessage()));
				$tick('analysing', ++$done, $total, $url);
				continue;
			}
			$record['url'] = $url;
			$products[]    = $record;
			$in   += (int) $record['input_tokens'];
			$out  += (int) $record['output_tokens'];
			$cost += (float) $record['cost_usd'];
			$tick('analysing', ++$done, $total, $url);
		}

		if (empty($products)) {
			throw new Exception($first_error ?: 'No products to analyse.');
		}
		return array(
			'products'        => $products,
			'input_tokens'    => $in,
			'output_tokens'   => $out,
			'cost_usd'        => round($cost, 6),
			'model'           => $this->model(),
			'websearch_calls' => 0,
		);
	}

	/**
	 * Crawl same-host pages starting at $base_url and return up to $limit product
	 * URLs (heuristic: competitor_is_product_url). Bounded by CRAWL_MAX_FETCHES.
	 * To stay on the tours section we only follow links that carry a product
	 * keyword (product pages plus their listing/category pages), so we don't burn
	 * fetches on about/blog/contact.
	 */
	protected function crawl_product_urls($base_url, $limit)
	{
		$unbounded = (int) $limit <= 0;
		$visited  = array();
		$queue    = array($base_url);
		$products = array();
		$fetches  = 0;
		// Unbounded: crawl the whole same-host tours section (the visited-set makes
		// it terminate). Bounded: give a bigger cap room to reach that many products.
		$max_fetches = $unbounded ? PHP_INT_MAX : max(self::CRAWL_MAX_FETCHES, $limit * 3);

		while ( ! empty($queue) && ($unbounded || count($products) < $limit) && $fetches < $max_fetches) {
			// Take a batch of unvisited URLs and fetch them CONCURRENTLY — a wide,
			// sitemap-less crawl is the slow part, so parallelism helps a lot.
			$batch = array();
			while ( ! empty($queue) && count($batch) < 8) {
				$u = array_shift($queue);
				if (isset($visited[$u])) {
					continue;
				}
				$visited[$u] = true;
				$batch[] = $u;
			}
			if (empty($batch)) {
				break;
			}
			$bodies = $this->fetch_urls_multi($batch);
			$fetches += count($batch);

			foreach ($batch as $url) {
				$html = isset($bodies[$url]) ? $bodies[$url] : '';
				if ($html === '') {
					continue;
				}
				// Resolve links against the CURRENT page so directory-relative hrefs
				// on deeper listing pages resolve correctly (same host throughout).
				foreach (competitor_extract_links($html, $url) as $link) {
					if (isset($visited[$link])) {
						continue;
					}
					if (competitor_is_product_url($link)) {
						if ( ! in_array($link, $products, true)) {
							$products[] = $link;
						}
					} elseif (preg_match('#/(tour|package|holiday|trip|itinerar|vacation|getaway|cruise|product)#i', (string) parse_url($link, PHP_URL_PATH))) {
						// A listing/category page in the tours section — worth crawling
						// deeper to reach its product links.
						$queue[] = $link;
					}
				}
				if ( ! $unbounded && count($products) >= $limit) {
					break;
				}
			}
		}
		return $unbounded ? $products : array_slice($products, 0, $limit);
	}

	/**
	 * Whole-site sweep: spider EVERY same-host candidate page (competitor_is_
	 * candidate_url) with a broad link-following crawl, UNIONed with the sitemap's
	 * same-host <loc> URLs. The BFS is the primary source (works even with no/stale
	 * sitemap), but a JS/SPA site (e.g. a Next.js listing that client-paginates its
	 * tours) exposes only the first page of links in raw HTML — the BFS then reaches a
	 * fraction of the catalogue while the sitemap lists them all. Merging both is safe:
	 * every candidate is still fetched and passed through the itinerary gate, so a stale
	 * sitemap URL just 404s / reads thin and is dropped. Strict product-URL matches are
	 * ordered FIRST so the real tours are read (and rendered, if JS) before the per-crawl
	 * render budget / page cap is spent on maybes; the rest follow and the itinerary gate
	 * keeps only genuine tours. NO page cap by default — it sweeps the WHOLE site (the
	 * visited-set guarantees it terminates); an optional COMPETITOR_MAX_SITE_PAGES caps
	 * it only for a runaway/huge site. Returns a URL list.
	 */
	/**
	 * The recrawl trigger tolerance (0.1 = escalate when discovery got < 90% of the
	 * site's declared total). COMPETITOR_COVERAGE_TOLERANCE overrides; kept in [0,1).
	 */
	protected function coverage_tolerance()
	{
		$t = get_env('COMPETITOR_COVERAGE_TOLERANCE');
		$t = ($t === '' || $t === null) ? 0.1 : (float) $t;
		return ($t < 0 || $t >= 1) ? 0.1 : $t;
	}

	/**
	 * The site's AUTHORITATIVE product total, or 0 when it can't be known reliably.
	 * ICE sites expose it exactly (enumerated catalogue, stashed during discover_ice).
	 * Otherwise read the base listing's own declared count — its "X of Y results" prose
	 * and/or its JSON listing API's meta.total. Only trustworthy product-level signals
	 * are used, so a 0 leaves the crawl behaving exactly as before.
	 */
	protected function authoritative_total($base_url, $source)
	{
		if ($source === 'ice') {
			return (int) $this->last_authoritative_total;
		}
		$total = 0;
		$html = $this->fetch_url($base_url);
		if ($html !== '') {
			$total = competitor_result_count_hint(
				competitor_html_to_text($html, competitor_page_char_cap(get_env('COMPETITOR_MAX_PAGE_CHARS')))
			);
			$api = competitor_spa_api_url($base_url);
			if ($api !== '') {
				$api_total = competitor_paginator_total($this->fetch_url($api));
				if ($api_total > $total) { $total = $api_total; }
			}
		}
		// Sitemap dominant-cluster estimate: the largest same-shaped URL family in the
		// sitemap is almost always the product namespace — a language-free product count
		// for the many sites (SPA / 中文 / BM) that declare none in prose. Only trusted
		// when the cluster's URLs look product-ish (keyword or product-URL shape), so a
		// big blog/news family can't set a false-high target and trigger a wasted recrawl.
		$host = parse_url($base_url, PHP_URL_HOST);
		$locs = array();
		foreach ($this->collect_sitemap_locs($base_url) as $u) {
			if (competitor_is_candidate_url($u, $host) && ! competitor_is_guide_url($u)) {
				$locs[] = $u;
			}
		}
		$cluster = competitor_dominant_path_cluster($locs);
		if ( ! empty($cluster)
			&& (competitor_is_product_url($cluster[0]) || competitor_path_has_product_keyword($cluster[0]))
			&& count($cluster) > $total) {
			$this->log_crawl('sitemap_cluster_total', array('template_sample' => $cluster[0],
				'cluster' => count($cluster), 'prev_total' => $total));
			$total = count($cluster);
		}
		return (int) $total;
	}

	/**
	 * Self-healing coverage: when discovery came up short of the site's authoritative
	 * total ($target), escalate — each strategy applied at most ONCE, capped by
	 * COMPETITOR_MAX_RECRAWL_ROUNDS — unioning whatever new product URLs each finds.
	 * Two strategies: the whole-site SWEEP (BFS + sitemap union) and HTML PAGINATION
	 * (?page=2..N) — the one gap the sweep can't close on its own. Headless is NOT a
	 * recrawl strategy: it's already the discovery fallback, so re-running it here just
	 * repeats work. Guaranteed to terminate (per-strategy-once + round cap). Full
	 * unbounded crawls only (the caller gates on limit/keyword). Returns widened URLs.
	 */
	protected function close_coverage_gap($base_url, array $urls, $target)
	{
		$max = (int) get_env('COMPETITOR_MAX_RECRAWL_ROUNDS');
		if ($max <= 0) { $max = 2; }
		$tol = $this->coverage_tolerance();
		$applied   = array();
		$rounds    = 0;
		$base_html = null;
		while ($rounds < $max && competitor_coverage_short(count($urls), $target, $tol)) {
			$strategy = '';
			$found    = array();
			if ( ! isset($applied['sweep']) && ! in_array($this->last_discovery_source, array('sweep', 'sitemap', 'thorough'), true)) {
				$strategy = 'sweep';                          // whole-site BFS + sitemap union
				$found = $this->discover_all_urls($base_url, 0);
			} elseif ( ! isset($applied['html_pagination'])) {
				$strategy = 'html_pagination';                // follow ?page=2..N from the root listing
				if ($base_html === null) { $base_html = $this->fetch_url($base_url); }
				$found = $this->html_paginated_child_urls($base_url, $base_html);
			} else {
				break;                                         // no strategies left to try
			}
			$applied[$strategy] = true;
			$before = count($urls);
			$urls = array_values(array_unique(array_merge($urls, $this->discovered_urls($found))));
			$rounds++;
			$this->log_crawl('coverage_recrawl', array('round' => $rounds, 'strategy' => $strategy,
				'before' => $before, 'after' => count($urls), 'target' => $target));
		}
		return $urls;
	}

	protected function discover_all_urls($base_url, $limit)
	{
		$page_cap = (int) get_env('COMPETITOR_MAX_SITE_PAGES');
		// Default the BFS breadth to the discovery cap (memory guard) so a mega-site can't
		// balloon the visited-set; a normal site finishes far below it (unaffected).
		$page_cap = $page_cap > 0 ? $page_cap : $this->discovery_cap();

		// SITEMAP FIRST (cold): fetch the sitemap BEFORE the broad BFS burst. A throttling host
		// (e.g. lovelyvacation.com.my, Crawl-delay set) returns empty bodies to a burst — so if
		// the BFS ran first it would throttle the site and the later sitemap fetch would come back
		// empty (losing the authoritative product list). Reading it cold, as the first request,
		// gets the full list; the result is cached so the union below is free. Guides filtered out.
		$sitemap = array();
		foreach ($this->collect_sitemap_locs($base_url) as $u) {
			if (competitor_is_guide_url($u)) { continue; }
			$sitemap[] = $u;
		}

		// Then the broad same-host BFS (raw-HTML crawl) for anything the sitemap misses.
		$crawled = $this->crawl_all_urls($base_url, $page_cap);

		// Union: sitemap ∪ BFS (order can only ADD coverage — nothing is dropped).
		$sitemap_new = array_values(array_diff($sitemap, $crawled));
		$cands = array_values(array_unique(array_merge($crawled, $sitemap_new)));
		if ( ! empty($sitemap_new)) {
			$this->log_crawl('sweep_sitemap_union', array('sitemap_locs' => count($sitemap),
				'added' => count($sitemap_new)));
		}

		// Order: strict product URLs first, then the rest (gate decides the maybes).
		$products = array();
		$rest     = array();
		foreach ($cands as $u) {
			if (competitor_is_product_url($u)) { $products[] = $u; } else { $rest[] = $u; }
		}
		$ordered = array_merge($products, $rest);

		if ($page_cap > 0 && count($ordered) > $page_cap) {
			$this->log_crawl('sweep_truncated', array('found' => count($ordered), 'cap' => $page_cap));
			$ordered = array_slice($ordered, 0, $page_cap);
		}
		$this->log_crawl('sweep_discovery', array('base_url' => $base_url,
			'total_candidates' => count($cands), 'strict_products_first' => count($products),
			'kept' => count($ordered)));
		return $ordered;
	}

	/**
	 * Broad same-host BFS from $base_url: follow EVERY same-host candidate link
	 * (competitor_is_candidate_url — no product-keyword requirement) and return every
	 * candidate page URL reached. Breadth-first, so each page's links are one level
	 * deeper than the page; depth is UNLIMITED by default (it keeps descending until
	 * the whole same-host site is covered), optionally capped by COMPETITOR_MAX_CRAWL_
	 * DEPTH (levels of links from the base). $cap <= 0 = NO fetch cap (the visited-set
	 * still terminates it on a finite site); a positive $cap bounds a runaway/huge
	 * site. Batches fetch concurrently (curl_multi, COMPETITOR_CRAWL_CONCURRENCY wide).
	 *
	 * SPEED: a strict product URL (competitor_is_product_url) is treated as a LEAF —
	 * collected but NOT fetched-to-expand, because tours are reached from listing/
	 * category pages, not from each other. So discovery only downloads the (few) hub
	 * pages instead of every product page, roughly halving total fetches (the reading
	 * phase downloads the products once). Never throws.
	 */
	protected function crawl_all_urls($base_url, $cap)
	{
		$host      = parse_url($base_url, PHP_URL_HOST);
		$max_depth = (int) get_env('COMPETITOR_MAX_CRAWL_DEPTH');   // 0 / blank = unlimited
		$unbounded = ((int) $cap <= 0);   // no page cap — sweep the whole site
		$width     = (int) get_env('COMPETITOR_CRAWL_CONCURRENCY');
		$width     = $width > 0 ? $width : 16;   // pages fetched per parallel batch
		$visited   = array();
		$queue     = array(array($base_url, 0));   // [url, depth]
		$found     = array();
		$fetches   = 0;
		$deepest   = 0;
		while ( ! empty($queue) && ($unbounded || $fetches < $cap)) {
			$batch = array();   // url => depth
			while ( ! empty($queue) && count($batch) < $width) {
				list($u, $d) = array_shift($queue);
				if (isset($visited[$u])) { continue; }
				$visited[$u] = true;
				$batch[$u] = $d;
			}
			if (empty($batch)) { break; }
			$bodies = $this->fetch_urls_multi(array_keys($batch));
			$fetches += count($batch);
			foreach ($batch as $url => $d) {
				$html = isset($bodies[$url]) ? $bodies[$url] : '';
				if ($html === '') { continue; }
				foreach (competitor_extract_links($html, $url) as $link) {
					if (isset($visited[$link]) || isset($found[$link])) { continue; }
					if ( ! competitor_is_candidate_url($link, $host)) { continue; }
					if (competitor_is_guide_url($link)) { continue; }   // travel guide/article, not a tour
					$found[$link] = true;
					if ($d + 1 > $deepest) { $deepest = $d + 1; }
					// Descend unless we've hit the depth cap (0 = unlimited) — and never
					// spider OUT of a product page (a leaf): tours are found on hub pages,
					// so fetching every product page here is wasted work.
					if (($max_depth === 0 || $d + 1 < $max_depth) && ! competitor_is_product_url($link)) {
						$queue[] = array($link, $d + 1);
					}
				}
			}
		}
		$this->log_crawl('broad_crawl', array('base_url' => $base_url, 'fetched' => $fetches,
			'found' => count($found), 'deepest_level' => $deepest,
			'max_depth' => $max_depth > 0 ? $max_depth : 'unlimited'));
		return array_keys($found);
	}

	/**
	 * Analyse a single competitor product URL. We scrape the page OURSELVES first
	 * (fetch_url + competitor_html_to_text) and hand the extracted text to OpenAI
	 * with no web_search tool — cheaper, no per-call browse fee. Only when our
	 * scrape comes back too thin (a JS-rendered page a plain fetch can't read) do
	 * we fall back to the browsing agent that opens the page itself. Returns the
	 * parsed record.
	 */
	public function analyze_url($url, $our_products)
	{
		$url = trim((string) $url);
		if ( ! preg_match('#^https?://#i', $url)) {
			throw new Exception('Please enter a valid http(s) URL.');
		}

		$text = $this->extract_source_text($url);

		// Full page content (static site / rich API / embedded JSON): hand the TEXT
		// to OpenAI with NO web_search — cheaper, no browse fee. But NOT when it's a
		// blank-shell SPA that only gave partial metadata (last_spa_partial) — that
		// needs browsing to get the itinerary/prices.
		if ( ! competitor_scrape_is_thin($text) && ! $this->last_spa_partial) {
			$spec = competitor_build_scraped_agent($url, $text, $our_products);
			$raw  = $this->request($spec['instructions'], $spec['input'], array(), 'scrape ' . $url);
			return $this->to_record($raw);
		}

		// 2) Fallback: a JS SPA our scraper couldn't fully read — the browsing agent
		// opens the page. This carries a per-call fee, so it's bounded by the
		// per-crawl web_search budget (COMPETITOR_MAX_WEBSEARCH).
		if ($this->websearch_cap > 0 && $this->websearch_count >= $this->websearch_cap) {
			$this->log_crawl('websearch_skipped_cap', array('url' => $url, 'cap' => $this->websearch_cap));
			if (competitor_scrape_is_thin($text)) {
				// Nothing usable and browsing is capped — skip this product.
				throw new Exception('Skipped: page needs web browsing but the per-crawl web-search limit (' . $this->websearch_cap . ') was reached.');
			}
			// We at least have partial metadata — analyse that, no browse fee.
			$spec = competitor_build_scraped_agent($url, $text, $our_products);
			$raw  = $this->request($spec['instructions'], $spec['input'], array(), 'scrape(capped) ' . $url);
			return $this->to_record($raw);
		}

		$this->websearch_count++;
		$this->log_crawl('scrape_thin', array('url' => $url, 'text_len' => strlen($text),
			'spa_partial' => $this->last_spa_partial, 'websearch_n' => $this->websearch_count));
		$spec = competitor_build_agent_input($url, $our_products, $text);
		$raw  = $this->request($spec['instructions'], $spec['input'], array(array('type' => $this->web_search_tool())), 'websearch ' . $url);
		return $this->to_record($raw);
	}

	/**
	 * Extract a single product page's SOURCE TEXT with NO OpenAI call — the shared
	 * scraping pipeline behind both analyze_url() and crawl_to_text():
	 *   0) ICE Holidays JSON API URLs -> read the JSON directly
	 *   1) our own HTML scraper
	 *   1b) known JS SPA platform -> its JSON API
	 *   1c) generic JS SPA -> the JSON embedded in the HTML (JSON-LD/__NEXT_DATA__)
	 *   2) linked brochure/itinerary PDFs -> fetched + read and appended
	 * Returns the best text we could get ('' / thin when the page is unreadable
	 * without a real browser).
	 */
	/**
	 * Turn one product URL into one OR MORE items [{url,text}]. An ICE "posts" page
	 * that lists several packages (each a name+code+PDF) expands into one item per
	 * package (so a 5-package promo = 5 products, not 1). Everything else is a
	 * single item via extract_source_text().
	 */
	protected function expand_source_items($url, $prefetched = null)
	{
		$this->last_expand_was_listing = false;
		$api = (competitor_ice_api_kind($url) === 'posts') ? $url : competitor_spa_api_url($url);
		if ($api !== '' && competitor_ice_api_kind($api) === 'posts') {
			$body = ($api === $url && $prefetched !== null) ? $prefetched : $this->fetch_url($api);
			$packages = competitor_ice_post_packages($body);
			if (count($packages) > 1) {
				$this->log_crawl('post_split', array('url' => $url, 'packages' => count($packages)));
				return $this->items_from_packages($url, $packages);
			}
		}
		$text = $this->extract_source_text($url, $prefetched);
		// A page that links to >=3 of its OWN sub-pages is an index/listing of them
		// (e.g. a JS section hub /tour-package linking /tour-package/1077, …/1090, …
		// exposed after render) — drill the children instead of keeping the hub.
		$child_links = competitor_count_child_links($url, $this->last_page_links_raw);
		if ($this->last_is_listing || competitor_is_category_url($url) || competitor_text_looks_like_listing($text)
			|| competitor_is_destination_listing($this->last_page_title, $text) || $child_links >= 3) {
			// A category / listing page (by JSON-LD, a plural "…-tours" URL, content, a
			// bare-destination catalogue of tour cards, or a hub of its own sub-pages) —
			// not a product itself; drop it and let
			// crawl_to_text drill its individual products (last_page_links). If it's a
			// paginated SPA listing, walk its API for the products on page 2..N too (the
			// render only exposes page 1 into the DOM).
			$this->last_expand_was_listing = true;
			if ( ! empty($this->last_render_apis)) {
				$paged = $this->paginated_child_urls($url);
				if ( ! empty($paged)) {
					$this->last_page_links = array_values(array_unique(array_merge($this->last_page_links, $paged)));
				}
			}
			// Classic HTML pagination (?page=2 / rel="next") — no JSON API needed. Cheap
			// when the listing isn't paginated (next-page lookup returns '' at once).
			$paged_html = $this->html_paginated_child_urls($url, $this->last_page_html);
			if ( ! empty($paged_html)) {
				$this->last_page_links = array_values(array_unique(array_merge($this->last_page_links, $paged_html)));
			}
			$this->log_crawl('dropped_listing', array('url' => $url,
				'links' => count($this->last_page_links), 'child_links' => $child_links));
			return array();
		}
		if (competitor_looks_like_article($text)) {
			// Rich prose with NO product signal (price/duration/itinerary) — a blog
			// article / info page that carried a product keyword in its URL. Drop it.
			$this->log_crawl('dropped_article', array('url' => $url, 'text_len' => strlen($text)));
			return array();
		}
		if (competitor_is_guide_url($url, $this->last_page_title)) {
			// A travel GUIDE / planning article ("How to plan a trip…", "Best time…")
			// — its day-by-day plan looks like an itinerary, so the gate below would
			// keep it. Drop it here (backstop for guides the discovery filter's URL
			// check missed, e.g. reached via a listing drill or category seed).
			$this->log_crawl('dropped_guide', array('url' => $url, 'title' => $this->last_page_title));
			return array();
		}
		// Tour-page gate (always on): a crawl keeps ONLY real bookable tour pages
		// (competitor_is_tour_page — a day-by-day itinerary, OR a duration+price for
		// sites that hide the itinerary behind a tab), and not thin. Landing/overview/
		// category pages, blog posts and under-scraped shells fail this, so they're
		// dropped here rather than analysed. Logged so the crawl log shows exactly what
		// was skipped (no silent truncation).
		if (competitor_scrape_is_thin($text)
			|| ! competitor_is_tour_page($text, $this->last_is_product, competitor_is_product_url($url))) {
			$this->log_crawl('dropped_not_tour', array('url' => $url, 'text_len' => strlen($text),
				'jsonld_product' => $this->last_is_product ? 1 : 0,
				'product_url' => competitor_is_product_url($url) ? 1 : 0));
			return array();
		}
		// Store the customer-facing web URL for an ICE series (set while extracting),
		// not the raw /api/v1/series/<id> URL the crawler read.
		$display_url = ($this->last_ice_web_url !== '') ? $this->last_ice_web_url : $url;
		$item = array('url' => $display_url, 'text' => $text);
		if ($this->last_page_title !== '') {
			$item['title'] = $this->last_page_title;   // real <h1> name — see crawl_to_text
		}
		return array($item);
	}

	/**
	 * Build one item per package: heading (post title — name (code)) plus the
	 * package's own PDF text. The package PDFs are fetched CONCURRENTLY.
	 */
	protected function items_from_packages($post_url, $packages)
	{
		$files = array();
		foreach ($packages as $p) {
			if ( ! empty($p['file'])) { $files[] = $p['file']; }
		}
		$bodies = $this->fetch_urls_multi($files);

		$items = array();
		foreach ($packages as $p) {
			$head = trim(($p['title'] !== '' ? $p['title'] . ' — ' : '') . $p['name']
				. ($p['code'] !== '' ? ' (Code: ' . $p['code'] . ')' : ''));
			$text = 'Product: ' . $head . "\n";
			if ( ! empty($p['file'])) {
				$doc = $this->extract_text_from_body(isset($bodies[$p['file']]) ? $bodies[$p['file']] : '', $p['file']);
				if ($doc !== '') {
					$text .= "\n" . $doc;
				}
			}
			$items[] = array(
				'url'  => ! empty($p['file']) ? $p['file'] : ($post_url . '#' . $p['code']),
				'text' => $text,
			);
		}
		return $items;
	}

	/**
	 * Product-page + PDF-brochure links found in a page's HTML — the drill targets
	 * for a category/listing page. Product URLs via competitor_is_product_url; PDFs via
	 * the file-link scraper (junk/legal PDFs excluded). Pure-ish (no fetch).
	 */
	/**
	 * Same-host CATEGORY/listing URLs found on a page (competitor_is_category_url) —
	 * seeds for the drill step so products hidden behind category pages get reached.
	 * Keyword-filtered when a keyword is set; capped by COMPETITOR_MAX_CATEGORY_SEEDS
	 * (default 40) so a site with hundreds of near-duplicate filter pages can't explode.
	 */
	protected function category_seeds_from_html($html, $base, $keyword = '')
	{
		$host = preg_replace('/^www\./i', '', strtolower((string) parse_url($base, PHP_URL_HOST)));
		$cats = array();
		foreach (competitor_extract_links($html, $base) as $u) {
			$h = preg_replace('/^www\./i', '', strtolower((string) parse_url($u, PHP_URL_HOST)));
			if ($h === $host && competitor_is_category_url($u)) {
				$cats[$u] = true;
			}
		}
		$cats = array_keys($cats);
		if (trim((string) $keyword) !== '') {
			$cats = array_values(competitor_filter_urls_by_keyword($cats, $keyword));
		}
		$cap = (int) get_env('COMPETITOR_MAX_CATEGORY_SEEDS');
		$cap = $cap > 0 ? $cap : 40;
		return array_slice($cats, 0, $cap);
	}

	/**
	 * Product URLs mined from the JSON APIs captured on the last render (last_render_apis)
	 * — the way to discover products on an OPAQUE SPA whose links never reach the DOM.
	 * Pulls URL-ish strings from the API bodies, resolves them, keeps product URLs.
	 */
	protected function urls_from_apis($base)
	{
		$urls = array();
		foreach ($this->last_render_apis as $a) {
			$body = isset($a['body']) ? $a['body'] : '';
			if ($body === '' || strpos($body, '/') === false) {
				continue;
			}
			if (preg_match_all('#["\'](https?://[^"\']+|/[A-Za-z0-9][A-Za-z0-9\-_/]{2,})["\']#', $body, $m)) {
				foreach ($m[1] as $u) {
					$abs = competitor_resolve_url($base, html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
					if ($abs !== '' && competitor_is_product_url($abs)) {
						$urls[$abs] = true;
					}
				}
			}
		}
		return array_keys($urls);
	}

	/**
	 * Readable text from the JSON APIs captured on the last render — folded into an
	 * opaque SPA product page's source text so the AI sees the itinerary/price the
	 * page loaded via XHR. Bounded so one huge feed can't dominate the input.
	 */
	protected function render_apis_to_text()
	{
		$parts = array();
		$budget = 60000;
		foreach ($this->last_render_apis as $a) {
			$body = isset($a['body']) ? $a['body'] : '';
			$t = competitor_json_api_to_text($body);
			if ($t === '' || competitor_scrape_is_thin($t)) {
				continue;
			}
			$parts[] = $t;
			$budget -= strlen($t);
			if ($budget <= 0) {
				break;
			}
		}
		return $parts ? ("CAPTURED API DATA:\n" . implode("\n\n", $parts)) : '';
	}

	/**
	 * Fetch a JSON API endpoint as an XHR would (X-Requested-With + Accept: json +
	 * Referer) — some SPA APIs (e.g. tio.asia) return empty to a plain browser
	 * request. Returns the raw body, or '' on failure. Never throws.
	 */
	protected function fetch_api($url, $referer = '')
	{
		$ch = curl_init();
		$opts = $this->curl_opts($url);
		$headers = array('Accept: application/json', 'X-Requested-With: XMLHttpRequest');
		if ($referer !== '') { $headers[] = 'Referer: ' . $referer; }
		$opts[CURLOPT_HTTPHEADER] = $headers;
		curl_setopt_array($ch, $opts);
		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		return ($code >= 400 || ! is_string($body)) ? '' : $body;
	}

	/**
	 * PER-SITE adapters: a few competitors are opaque SPAs whose products are only in a
	 * clean public JSON API — read it directly (one call, no per-page render) and return
	 * ready crawl items [{url,title,text}]. These WIN over any headless-scraped duplicate
	 * of the same URL. Isolated per host by design (the general path is discovery + the
	 * gate/coverage helpers); returns [] for every other site so nothing else is affected.
	 */
	protected function site_adapter_items($base_url)
	{
		$host   = preg_replace('/^www\./i', '', strtolower((string) parse_url($base_url, PHP_URL_HOST)));
		$parts  = parse_url($base_url);
		$origin = ( ! empty($parts['scheme']) && ! empty($parts['host']))
			? $parts['scheme'] . '://' . $parts['host'] : (string) $base_url;

		if ($host === 'tripfez.com') {
			// tripfez React SPA: its cruise catalogue is one public JSON call (~44 items).
			$body  = $this->fetch_api('https://api.cruisemalaysia.com.my/api/v1/cruises?limit=1000', $base_url);
			$items = competitor_tripfez_cruise_items($body, $origin);
			$this->log_crawl('site_adapter', array('host' => $host, 'source' => 'cruise_api', 'items' => count($items)));
			return $items;
		}
		return array();
	}

	/**
	 * Enumerate ALL product URLs behind a paginated SPA listing (e.g. tio.asia's
	 * /tour-package, whose /api/tour-package returns {data,links,meta} 16 at a time).
	 * Finds the paginated API among the listing's captured render XHRs, walks every
	 * page via links.next (bounded), and builds a product URL per record — so the
	 * crawl reaches page 2..N, not just the ~16 rendered into the DOM. Returns []
	 * when there's no paginated API. Never throws.
	 */
	protected function paginated_child_urls($listing_url)
	{
		$host = parse_url($listing_url, PHP_URL_HOST);
		foreach ((array) $this->last_render_apis as $a) {
			$api = is_array($a) ? (string) (isset($a['url']) ? $a['url'] : '') : (string) $a;
			if ($api === '' || parse_url($api, PHP_URL_HOST) !== $host) {
				continue;
			}
			// Prefer the body captured at render; fall back to a fresh XHR fetch.
			$body = (is_array($a) && ! empty($a['body'])) ? (string) $a['body'] : $this->fetch_api($api, $listing_url);
			$json = json_decode($body, true);
			if ( ! competitor_paginator_items($json)) {
				continue;   // not the listing paginator
			}
			$out    = array();
			$seen   = array();
			$pages  = 0;
			$cap    = 200;   // page guard (200 pages × ~16 = plenty; the seen-set also stops loops)
			while (is_array($json) && $pages < $cap) {
				foreach (competitor_paginator_items($json) as $it) {
					$u = competitor_listing_item_url($listing_url, $it);
					if ($u !== '') { $out[$u] = true; }
				}
				$pages++;
				$next = competitor_paginator_next($json);
				if ($next === '' || isset($seen[$next])) { break; }
				$seen[$next] = true;
				$json = json_decode($this->fetch_api($next, $listing_url), true);
			}
			if ( ! empty($out)) {
				$this->log_crawl('paginated_listing', array('listing' => $listing_url,
					'api' => $api, 'pages' => $pages, 'urls' => count($out)));
				return array_keys($out);
			}
		}
		return array();
	}

	/**
	 * Enumerate product URLs behind a listing that paginates with CLASSIC HTML links
	 * (?page=2, ?paged=2, /page/2/, or <link rel="next">) rather than a JSON API — the
	 * companion to paginated_child_urls() (which needs a captured render XHR). Walks
	 * next → next from the listing's HTML, fetching each page and collecting its
	 * candidate product links, bounded by a page guard + a seen-set. Returns [] when
	 * the listing isn't paginated (0 extra fetches — competitor_html_next_page returns
	 * '' immediately). Never throws.
	 */
	protected function html_paginated_child_urls($listing_url, $html)
	{
		$host = parse_url($listing_url, PHP_URL_HOST);
		$out  = array();
		$seen = array($listing_url => true);
		$cur_url  = $listing_url;
		$cur_html = (string) $html;
		$pages = 0;
		$cap   = 30;   // page guard; the seen-set also stops loops
		while ($cur_html !== '' && $pages < $cap) {
			$next = competitor_html_next_page($cur_html, $cur_url);
			if ($next === '' || isset($seen[$next]) || parse_url($next, PHP_URL_HOST) !== $host) {
				break;
			}
			$seen[$next] = true;
			$pages++;
			$body = $this->fetch_url($next);
			if ($body === '') {
				break;
			}
			foreach (competitor_filter_candidate_product_urls(competitor_extract_links($body, $next), $host) as $p) {
				$out[$p] = true;
			}
			$cur_url  = $next;
			$cur_html = $body;
		}
		if ( ! empty($out)) {
			$this->log_crawl('paginated_listing_html', array('listing' => $listing_url,
				'pages' => $pages, 'urls' => count($out)));
		}
		return array_keys($out);
	}

	protected function page_links_from_html($html, $base)
	{
		$links = competitor_filter_candidate_product_urls(competitor_extract_links($html, $base), parse_url($base, PHP_URL_HOST));
		foreach (competitor_extract_file_links($html, $base) as $f) {
			if (preg_match('#\.pdf(\?|$)#i', $f) && ! competitor_is_junk_file_url($f)) {
				$links[] = $f;
			}
		}
		return array_values(array_unique($links));
	}

	protected function extract_source_text($url, $prefetched = null)
	{
		$this->last_spa_partial = false;
		$this->last_is_listing  = false;
		$this->last_is_product  = false;
		$this->last_page_title  = '';
		$this->last_page_links  = array();
		$this->last_page_links_raw = array();
		$this->last_page_html   = '';   // don't carry a prior page's HTML into pagination
		$this->last_render_apis = array();   // don't carry a prior page's captured APIs
		$this->last_ice_web_url = '';
		$html_thin = false;   // was the raw HTML a blank-shell SPA?

		// $prefetched (when given) IS the body of $url fetched in parallel upstream —
		// use it wherever we'd otherwise fetch $url, to avoid a serial re-fetch.
		$fetch_self = function () use ($url, $prefetched) {
			return ($prefetched !== null) ? $prefetched : $this->fetch_url($url);
		};

		$kind = competitor_ice_api_kind($url);
		if ($kind === 'series') {
			$body = $fetch_self();
			$text = competitor_ice_series_to_text($body);
			// Remember the customer-facing /web/itinerary/<code> URL so the stored
			// product shows the real website page, not the /api/v1 URL we crawled.
			$parts = parse_url($url);
			if ( ! empty($parts['scheme']) && ! empty($parts['host'])) {
				$origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
				$this->last_ice_web_url = competitor_ice_series_web_url($body, $origin);
			}
		} elseif ($kind === 'land_tour') {
			$body = $fetch_self();
			$text = competitor_ice_land_tour_to_text($body);
			// Show the customer-facing /web/land-tour/<code> page, not the /b2c2b API URL.
			$d = json_decode($body, true);
			$code = (is_array($d) && isset($d['code'])) ? trim((string) $d['code']) : '';
			$parts = parse_url($url);
			if ($code !== '' && ! empty($parts['scheme']) && ! empty($parts['host'])) {
				$origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
				$this->last_ice_web_url = $origin . '/web/land-tour/' . rawurlencode($code);
			}
		} elseif ($kind === 'posts') {
			$text = competitor_json_api_to_text($fetch_self());
		} else {
			// 1) Our own scraper.
			$html = $fetch_self();

			// 0b) A direct DOCUMENT URL (a PDF/image brochure linked as a product,
			// e.g. cit.travel's /PDF/packages/….pdf) — read it with pdftotext/OCR.
			$bkind = competitor_file_binary_kind($html);
			if ($bkind !== '') {
				return $this->enrich_with_linked_files($this->extract_text_from_body($html, $url));
			}

			// Category/listing/search page (per its JSON-LD) — not a single product;
			// flag so expand_source_items() drops it instead of analysing a catalogue.
			// The same JSON-LD also gives the positive keep-signal (Product/Trip type)
			// so a page the site DECLARES a tour survives even if the (multilingual) text
			// regex misses — e.g. an SPA shell whose itinerary loads later.
			$ld_types = competitor_jsonld_types($html);
			$this->last_is_listing = competitor_looks_like_listing($ld_types);
			$this->last_is_product = competitor_jsonld_is_product($ld_types);

			// The real product name (<h1>/og:title) — reliable title for review + AI,
			// vs the first body line which is usually menu/CTA/inquiry-form chrome.
			$this->last_page_title = competitor_page_title($html);

			$text = competitor_html_to_text($html, competitor_page_char_cap(get_env('COMPETITOR_MAX_PAGE_CHARS')));
			$html_thin = competitor_scrape_is_thin($text);   // blank-shell SPA?

			// 1a) Lead with schema.org PRODUCT JSON-LD when present, even if the HTML
			// isn't thin — a noisy SPA shell otherwise buries the real structured
			// facts (name/price/itinerary) under nav/widget text.
			$ld = competitor_jsonld_product_text($html);
			if ($ld !== '') {
				$this->log_crawl('jsonld_product_used', array('url' => $url, 'text_len' => strlen($ld)));
				$text = "STRUCTURED PRODUCT DATA (schema.org):\n" . $ld . "\n\n" . $text;
			}

			// 1b) Known JS SPA platform (e.g. ICE Holidays / gd.my): the HTML is an
			// empty shell (or boilerplate with no real tour sections), so read the JSON
			// API that actually holds the content.
			if (competitor_needs_more_content($text)) {
				$api = competitor_spa_api_url($url);
				if ($api !== '') {
					$api_text = competitor_json_api_to_text($this->fetch_url($api));
					if ( ! competitor_scrape_is_thin($api_text)) {
						$this->log_crawl('spa_api_used', array('url' => $url, 'api' => $api, 'text_len' => strlen($api_text)));
						$text = $api_text;
					}
				}
			}

			// 1c) Generic JS SPA: no known API, but most SPAs ship their data as JSON
			// inside the HTML (JSON-LD / __NEXT_DATA__ / og-tags) — read that ourselves
			// before paying for the browsing agent.
			if (competitor_needs_more_content($text)) {
				$embedded = competitor_extract_embedded_json($html);
				if ( ! competitor_scrape_is_thin($embedded)) {
					$this->log_crawl('embedded_json_used', array('url' => $url, 'text_len' => strlen($embedded)));
					$text = $embedded;
				}
			}

			// 1d) Still short of a full tour page: follow a <link rel="alternate"
			// type="application/json"> feed if the page advertises one (its own
			// machine-readable version).
			if (competitor_needs_more_content($text)) {
				$alt = competitor_json_alternate_url($html, $url);
				if ($alt !== '') {
					$alt_text = competitor_json_api_to_text($this->fetch_url($alt));
					if ( ! competitor_scrape_is_thin($alt_text)) {
						$this->log_crawl('json_alternate_used', array('url' => $url, 'alt' => $alt, 'text_len' => strlen($alt_text)));
						$text = $alt_text;
					}
				}
			}

			// 1d1) A body PREFETCHED by the wide concurrent batch (curl_multi, HTTP/2
			// multiplexed) can come back empty or TRUNCATED for a large SSR page — shared
			// bandwidth + a per-request timeout — so it reads as thin here even though the
			// page is fine when fetched on its own (verified: 300KB+ tour pages that failed
			// the batch read in full sequentially). That thin body would otherwise trigger
			// a slow, flaky headless render (or get dropped). Before paying for a render,
			// retry ONE clean standalone fetch (fetch_url, with its own transient retry).
			// Only for product-keyword pages that still need content — bounded and cheap,
			// and typically FASTER than the render it replaces.
			if ($prefetched !== null && competitor_needs_more_content($text)
					&& competitor_path_has_product_keyword($url)
					&& ! competitor_is_guide_url($url, $this->last_page_title)) {
				$refetched = $this->fetch_url($url);
				if ($refetched !== '') {
					$rf_text = competitor_html_to_text($refetched, competitor_page_char_cap(get_env('COMPETITOR_MAX_PAGE_CHARS')));
					$rf_ld   = competitor_jsonld_product_text($refetched);
					if ($rf_ld !== '') {
						$rf_text = "STRUCTURED PRODUCT DATA (schema.org):\n" . $rf_ld . "\n\n" . $rf_text;
					}
					if ( ! competitor_scrape_is_thin($rf_text) && mb_strlen($rf_text, 'UTF-8') > mb_strlen($text, 'UTF-8')) {
						$this->log_crawl('clean_refetch_used', array('url' => $url, 'text_len' => strlen($rf_text)));
						$text = $rf_text;
						$html = $refetched;
						$html_thin = false;
						if ($this->last_page_title === '') {
							$this->last_page_title = competitor_page_title($refetched);
						}
						$rf_types = competitor_jsonld_types($refetched);
						$this->last_is_listing = competitor_looks_like_listing($rf_types);
						$this->last_is_product = competitor_jsonld_is_product($rf_types);
					}
				}
			}

			// 1d2) We still don't have a full tour page — on a full JS SPA (e.g.
			// chanbrothers) a product page is served as a shell whose itinerary loads via
			// JS, so it MUST be browser-rendered to be kept. The render budget is precious
			// (each render is a full page load), so we spend it ONLY on pages whose path
			// carries a tour/product keyword (a product OR a tour-section hub/listing,
			// any depth incl. numeric-id URLs like /tour-package/1077), that aren't a
			// guide, and still lack an itinerary (competitor_needs_more_content). A hub
			// renders so its child products can be drilled; a product renders to be read.
			// Info/blog/account pages carry no keyword, so they're never rendered
			// here — they'd be dropped anyway — so the budget isn't wasted on them (the
			// old signal-based trigger burned it on /inspirations pages, starving the real
			// products and returning zero). Listing pages get their own render in the drill
			// step. Skipped on structured-catalogue sites (reading_allow_headless).
			$render_worthy = competitor_path_has_product_keyword($url)
				&& ! competitor_is_guide_url($url, $this->last_page_title)
				&& competitor_needs_more_content($text);
			if ($this->reading_allow_headless && $render_worthy) {
				$rendered = $this->fetch_rendered($url);
				if ($rendered !== '') {
					$rendered_text = competitor_html_to_text($rendered, competitor_page_char_cap(get_env('COMPETITOR_MAX_PAGE_CHARS')));
					if ( ! competitor_scrape_is_thin($rendered_text) && mb_strlen($rendered_text, 'UTF-8') > mb_strlen($text, 'UTF-8')) {
						$this->log_crawl('headless_content_used', array('url' => $url, 'text_len' => strlen($rendered_text)));
						$text = $rendered_text;
						$html = $rendered;
						$html_thin = false;   // headless got the real content; not an unreadable SPA
						if ($this->last_page_title === '') {
							$this->last_page_title = competitor_page_title($rendered);
						}
					}
				}
			}

			// Fold any captured render-service APIs into the text — the itinerary/price
			// an opaque SPA loads via XHR (captured by the 1d2 render above).
			if ( ! empty($this->last_render_apis)) {
				$api_text = $this->render_apis_to_text();
				if ($api_text !== '') {
					$text = trim($text . "\n\n" . $api_text);
					if ($this->last_page_title === '') {
						$this->last_page_title = competitor_page_title($html);
					}
				}
			}

			// 1e) html_to_text drops <a href>, so a plain HTML page's PDF/image
			// brochure links would be lost — recover them from the raw HTML so
			// step 2 can fetch + read them.
			$files = competitor_extract_file_links($html, $url);
			if ($files) {
				$text .= "\n\nFiles: " . implode(' , ', $files);
			}

			// Outbound product + PDF links on this page — used by the listing drill-down
			// to reach the individual products of a category/listing page.
			$this->last_page_links = $this->page_links_from_html($html, $url);
			$this->last_page_links_raw = competitor_extract_links($html, $url);
			// Keep the (possibly rendered) HTML so a listing can be walked for classic
			// ?page=2 / rel="next" pagination in expand_source_items().
			$this->last_page_html = $html;
		}

		// 2) Read any linked brochure/itinerary PDFs (the "View File" links) and
		// fold their text in — that's where the real prices/itinerary live.
		$text = $this->enrich_with_linked_files($text);

		// A blank-shell SPA where all we recovered is short metadata (title/cities,
		// but not the itinerary/prices behind its private API): flag it so
		// analyze_url() lets the web_search agent fill the gaps.
		$this->last_spa_partial = $html_thin
			&& ! competitor_scrape_is_thin($text)
			&& mb_strlen($text, 'UTF-8') < self::SPA_PARTIAL_MAX;

		return $text;
	}

	/**
	 * Fetch the file URLs found in $text (brochure/itinerary "View File" links),
	 * read each and append its extracted text under a labelled separator. PDFs are
	 * read via pdftotext, images via tesseract OCR; other types / any failure are
	 * skipped silently. Bounded to the first few files. Returns the enriched text
	 * (unchanged when there is nothing to add).
	 */
	protected function enrich_with_linked_files($text)
	{
		$urls = competitor_extract_urls($text, 10);
		// Drop legal/policy junk (privacy, terms & conditions, PDPA…) — these are linked
		// site-wide (footer / chat widget), so their payment-schedule tables ("60 days
		// prior", "RM35,000") would otherwise be folded into EVERY page's text, giving a
		// non-product page (why-us, gallery, promotions) a spurious duration+price and
		// slipping it past the tour-page keep gate. competitor_extract_file_links already
		// applies this filter; the free-text URL path here must too.
		$urls = array_values(array_filter($urls, function ($u) {
			return ! competitor_is_junk_file_url($u);
		}));
		if (empty($urls)) {
			return $text;
		}
		// Download all linked files CONCURRENTLY (they're the slow part), then read
		// each from its bytes. Preserves discovery order.
		$bodies = $this->fetch_urls_multi($urls);
		$sections = array();
		foreach ($urls as $u) {
			$doc = $this->extract_text_from_body(isset($bodies[$u]) ? $bodies[$u] : '', $u);
			if ($doc !== '') {
				$this->log_crawl('linked_file_used', array('url' => $u, 'text_len' => strlen($doc)));
				$sections[] = "----- LINKED FILE: " . $u . " -----\n" . $doc;
			}
		}
		return empty($sections) ? $text : $text . "\n\n" . implode("\n\n", $sections);
	}

	/**
	 * Read already-fetched file bytes into text: PDFs via pdftotext, images
	 * (JPEG/PNG/GIF/WEBP) via tesseract OCR. Type is sniffed from the bytes (these
	 * CDN links carry no extension). Returns '' for other types or when the tool /
	 * shell_exec is unavailable — the caller then just keeps the link. Never throws.
	 */
	protected function extract_text_from_body($body, $url = '')
	{
		if ($body === '' || ! function_exists('shell_exec')) {
			return '';
		}
		$kind = competitor_file_binary_kind($body);
		$text = '';
		if ($kind === 'pdf') {
			$text = $this->pdf_to_text($body, $url);
		} elseif ($kind === 'image') {
			$text = $this->image_to_text($body, $url);
		}
		// Cap one file's extracted text — a big brochure can yield hundreds of KB,
		// which bloats both memory (all items held at once) and the AI token cost.
		$cap = competitor_page_char_cap(get_env('COMPETITOR_MAX_PAGE_CHARS'));
		if ($cap > 0 && mb_strlen($text, 'UTF-8') > $cap) {
			$text = mb_substr($text, 0, $cap, 'UTF-8');
		}
		return $text;
	}

	/** PDF bytes -> text via pdftotext (-layout keeps price columns aligned). */
	protected function pdf_to_text($body, $url = '')
	{
		$bin = $this->resolve_bin(get_env('PDFTOTEXT_BIN'), 'pdftotext');
		return $this->run_extractor(
			escapeshellarg($bin) . ' -q -layout {IN} - 2>/dev/null', $body, 'cmp_pdf_', 'pdf', $url
		);
	}

	/**
	 * Image bytes -> text via tesseract OCR. Language(s) from TESSERACT_LANG
	 * (default 'eng'; set e.g. 'eng+chi_sim' for the bilingual EN/CN brochures once
	 * that language pack is installed).
	 */
	protected function image_to_text($body, $url = '')
	{
		$bin = $this->resolve_bin(get_env('TESSERACT_BIN'), 'tesseract');
		$lang = get_env('TESSERACT_LANG');
		$lang = $lang ? $lang : 'eng';
		return $this->run_extractor(
			escapeshellarg($bin) . ' {IN} stdout -l ' . escapeshellarg($lang) . ' 2>/dev/null', $body, 'cmp_img_', 'ocr', $url
		);
	}

	/**
	 * Resolve a CLI tool to an ABSOLUTE path. php-fpm runs with a minimal PATH that
	 * usually excludes Homebrew (/opt/homebrew/bin), so a bare "pdftotext" shells out
	 * to nothing. An explicit env value wins; otherwise probe the common bin dirs;
	 * last resort return the bare name (hope it's on PATH). Cached per process.
	 */
	protected function resolve_bin($configured, $name)
	{
		static $cache = array();
		$configured = trim((string) $configured);
		if ($configured !== '') {
			return $configured;
		}
		if (isset($cache[$name])) {
			return $cache[$name];
		}
		$found = $name;
		foreach (array('/opt/homebrew/bin/', '/usr/local/bin/', '/usr/bin/', '/bin/') as $dir) {
			if (is_executable($dir . $name)) {
				$found = $dir . $name;
				break;
			}
		}
		return $cache[$name] = $found;
	}

	/** Reset the per-crawl web_search + headless budgets and the running AI cost. */
	protected function reset_render_budget()
	{
		$this->websearch_cap   = competitor_websearch_cap(get_env('COMPETITOR_MAX_WEBSEARCH'));
		$this->websearch_count = 0;
		// Single headless budget for all crawls (COMPETITOR_MAX_HEADLESS; set it to 0 for
		// uncapped). A thorough first-crawl gets its extra reach from the headless UNION in
		// discovery, not a separate budget.
		$this->headless_cap    = competitor_headless_cap(get_env('COMPETITOR_MAX_HEADLESS'));
		$this->headless_count  = 0;
		$this->run_cost        = 0.0;
	}

	/** USD cost of all OpenAI calls since the last reset (e.g. the AI-crawl discovery). */
	public function last_run_cost()
	{
		return round((float) $this->run_cost, 6);
	}

	/**
	 * The shell command for the Playwright render service ('node render.js'), or '' when
	 * it isn't installed (no node, no script, no node_modules). COMPETITOR_RENDER_CMD
	 * overrides it wholesale. Cached per process.
	 */
	protected function render_service_cmd()
	{
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		$override = trim((string) get_env('COMPETITOR_RENDER_CMD'));
		if ($override !== '') {
			return $cache = $override;
		}
		$script = FCPATH . 'tools/competitor_render/render.js';
		$deps   = FCPATH . 'tools/competitor_render/node_modules/playwright';
		if ( ! is_file($script) || ! is_dir($deps)) {
			return $cache = '';   // not installed → caller falls back to Chrome
		}
		$node = $this->resolve_bin(get_env('NODE_BIN'), 'node');
		return $cache = escapeshellarg($node) . ' ' . escapeshellarg($script);
	}

	/**
	 * Render a URL via the Playwright service. Returns ['html'=>, 'apis'=>[{url,body}]]
	 * or null when the service isn't installed / failed (caller then uses Chrome).
	 */
	protected function render_via_service($url)
	{
		$cmd = $this->render_service_cmd();
		if ($cmd === '') {
			return null;
		}
		$secs = (int) get_env('COMPETITOR_HEADLESS_TIMEOUT');
		$secs = $secs > 0 ? $secs : 35;
		// Pass the proxy to the headless renderer (render.js reads these env vars → Playwright
		// launches Chromium through the proxy), so rendered pages route through the same IP.
		$env = '';
		$proxy = $this->proxy_config();
		if ($proxy !== null) {
			$env = 'COMPETITOR_PROXY=' . escapeshellarg($proxy['url']) . ' ';
			if ($proxy['auth'] !== '') {
				$env .= 'COMPETITOR_PROXY_AUTH=' . escapeshellarg($proxy['auth']) . ' ';
			}
		}
		// No done-marker: node prints its JSON then exits, so wait for exit (salvage off).
		$raw = $this->run_with_timeout($env . $cmd . ' ' . escapeshellarg($url), $secs + 10, 'render.js', '');
		$data = json_decode((string) $raw, true);
		if ( ! is_array($data) || ! empty($data['error'])) {
			if (is_array($data) && ! empty($data['error'])) {
				$this->log_crawl('playwright_error', array('url' => $url, 'error' => $data['error']));
			}
			return null;
		}
		$apis = array();
		if ( ! empty($data['apis']) && is_array($data['apis'])) {
			foreach ($data['apis'] as $a) {
				if (isset($a['body']) && is_string($a['body'])) {
					$apis[] = array('url' => isset($a['url']) ? (string) $a['url'] : '', 'body' => $a['body']);
				}
			}
		}
		return array('html' => isset($data['html']) ? (string) $data['html'] : '', 'apis' => $apis);
	}

	/**
	 * Render a JavaScript page with headless Chrome and return the resulting DOM
	 * HTML — the fallback for JS SPAs our plain fetch can't read (a listing whose
	 * product links, or a product page whose content, are injected by JS). Bounded
	 * by a virtual-time budget so XHR can populate, and by the per-crawl headless
	 * budget. Returns '' when Chrome/shell_exec is unavailable, the budget is spent,
	 * or on failure. Never throws.
	 */
	protected function fetch_rendered($url)
	{
		if ( ! function_exists('proc_open')) {
			return '';
		}
		if ($this->headless_cap > 0 && $this->headless_count >= $this->headless_cap) {
			$this->log_crawl('headless_skipped_cap', array('url' => $url, 'cap' => $this->headless_cap));
			return '';
		}
		$this->last_render_apis = array();

		// Prefer the Playwright render service when installed — it executes JS, scrolls
		// for lazy-load, and CAPTURES the JSON APIs the SPA calls (the data plain
		// --dump-dom can't see). Falls through to Chrome --dump-dom when unavailable.
		$svc = $this->render_via_service($url);
		if ($svc !== null) {
			$this->last_render_apis = isset($svc['apis']) ? $svc['apis'] : array();
			if ($svc['html'] !== '') {
				$this->headless_count++;
				$this->log_crawl('playwright_render', array('url' => $url,
					'bytes' => strlen($svc['html']), 'apis' => count($this->last_render_apis), 'n' => $this->headless_count));
				return $svc['html'];
			}
		}

		$bin = $this->headless_bin();
		if ($bin === '') {
			return '';
		}
		$ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
			. '(KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
		// --user-data-dir to a writable temp dir: under php-fpm (www-data) $HOME is
		// often not writable, and Chrome otherwise fails to launch on Linux. Reused
		// per-process (our renders are sequential, so no profile-lock clash).
		$profile = sys_get_temp_dir() . '/cmp_chrome_' . getmypid();
		// Chrome fallback: route through the proxy too (credentials aren't supported inline on
		// the Chrome CLI — an auth'd proxy works via the curl + Playwright paths).
		$proxy = $this->proxy_config();
		$proxy_arg = ($proxy !== null) ? ' --proxy-server=' . escapeshellarg($proxy['url']) : '';
		$cmd = escapeshellarg($bin)
			. ' --headless --disable-gpu --no-sandbox --disable-dev-shm-usage'
			. ' --user-data-dir=' . escapeshellarg($profile) . $proxy_arg
			. ' --virtual-time-budget=9000 --timeout=9000 --user-agent=' . escapeshellarg($ua)
			. ' --dump-dom ' . escapeshellarg($url);

		// HARD wall-clock timeout: --virtual-time-budget alone does NOT reliably make
		// Chrome exit (a page with live timers/XHR can hang it forever), which would
		// freeze the whole crawl. Run under a deadline and kill a hung Chrome tree.
		$secs = (int) get_env('COMPETITOR_HEADLESS_TIMEOUT');
		$secs = $secs > 0 ? $secs : 35;   // heavy JS sites (chanbrothers) render in ~20-30s
		$html = $this->run_with_timeout($cmd, $secs, $profile);
		if ($html !== '') {
			$this->headless_count++;
			$this->log_crawl('headless_render', array('url' => $url, 'bytes' => strlen($html), 'n' => $this->headless_count));
		}
		return $html;
	}

	/**
	 * Run a shell command, capturing stdout, but KILL it (and its process tree) if it
	 * runs past $secs — so a hung child (e.g. headless Chrome that never exits) can't
	 * block the crawl indefinitely. $kill_match, when set, is a pattern passed to
	 * `pkill -f` to reap orphaned descendants the signal to our direct child misses
	 * (Chrome's helper processes). $done_marker (e.g. "</html>") lets us stop the moment
	 * the child has produced a complete result — headless Chrome with --dump-dom writes
	 * the full DOM then often HANGS on exit, so waiting for exit would waste the whole
	 * timeout and then discard a good render. Returns the captured output (even if we
	 * had to kill a hung-on-exit child that already produced it). '' on real failure.
	 */
	protected function run_with_timeout($cmd, $secs, $kill_match = '', $done_marker = '</html>')
	{
		$desc = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w'));
		$pipes = array();
		$proc = @proc_open($cmd, $desc, $pipes);
		if ( ! is_resource($proc)) {
			return '';
		}
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);

		$out      = '';
		$deadline = microtime(true) + $secs;
		$timedout = false;
		$complete = false;
		$kill = function () use ($proc, $kill_match) {
			@proc_terminate($proc, 9);
			if ($kill_match !== '' && function_exists('shell_exec')) {
				@shell_exec('pkill -9 -f ' . escapeshellarg($kill_match) . ' 2>/dev/null');
			}
		};
		while (true) {
			$chunk = stream_get_contents($pipes[1]);
			if (is_string($chunk) && $chunk !== '') {
				$out .= $chunk;
			}
			// The child already emitted a complete result (full DOM) — take it now and
			// kill the process rather than waiting for it to (maybe never) exit.
			if ($done_marker !== '' && stripos($out, $done_marker) !== false) {
				$complete = true;
				$kill();
				break;
			}
			$st = proc_get_status($proc);
			if ( ! $st['running']) {
				$chunk = stream_get_contents($pipes[1]);   // final drain
				if (is_string($chunk)) { $out .= $chunk; }
				break;
			}
			if (microtime(true) >= $deadline) {
				$timedout = true;
				$kill();
				break;
			}
			usleep(100000);   // 100ms
		}
		fclose($pipes[1]);
		@proc_close($proc);
		if ($timedout && ! $complete) {
			// No complete result before the deadline — but if the child DID dump a
			// substantial body before hanging, keep it rather than throwing it away.
			$salvage = (strlen($out) > 20000) ? $out : '';
			$this->log_crawl('headless_timeout', array('secs' => $secs, 'kill' => $kill_match, 'salvaged_bytes' => strlen($salvage)));
			return $salvage;
		}
		return $out;
	}

	/** Resolve the headless Chrome/Chromium binary (HEADLESS_BROWSER_BIN or common paths). '' when none. */
	protected function headless_bin()
	{
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		$env = get_env('HEADLESS_BROWSER_BIN');
		if ($env) {
			return $cache = $env;
		}
		$cands = array(
			'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
			'/Applications/Chromium.app/Contents/MacOS/Chromium',
			'/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
			'/opt/homebrew/bin/chromium',
			'/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
		);
		foreach ($cands as $c) {
			if (@is_executable($c)) {
				return $cache = $c;
			}
		}
		return $cache = '';
	}

	/**
	 * Shared: write $body to a temp file, run $cmd (with {IN} replaced by the shell-
	 * quoted temp path) and return its trimmed stdout with blank-line runs collapsed.
	 * '' on any failure (logged as a crawl event, not an AI call).
	 */
	protected function run_extractor($cmd, $body, $prefix, $label, $url)
	{
		$tmp = tempnam(sys_get_temp_dir(), $prefix);
		if ($tmp === false) {
			return '';
		}
		file_put_contents($tmp, $body);
		$out = @shell_exec(str_replace('{IN}', escapeshellarg($tmp), $cmd));
		@unlink($tmp);

		$out = is_string($out) ? trim($out) : '';
		if ($out === '') {
			$this->log_crawl($label . '_empty', array('url' => $url, 'bytes' => strlen($body)));
			return '';
		}
		return preg_replace('/\n{3,}/', "\n\n", $out);
	}

	/**
	 * Crawl a site to TEXT ONLY — discover its product URLs (AI-free: ICE API +
	 * HTML crawl, no web_search) and extract each page's source text with NO
	 * OpenAI call. Returns [['url'=>, 'text'=>], …] for the dump/debug mode so we
	 * can inspect exactly what the crawler would feed the AI, without paying for
	 * analysis. $limit <= 0 means UNCAPPED — dump every product URL discovered
	 * (bounded only by the crawler's own fetch ceiling).
	 *
	 * $progress, when given, is called as $progress($phase, $done, $total, $label)
	 * so a background job can report live progress ('discovering' then 'reading').
	 */
	public function crawl_to_text($base_url, $limit = 0, $progress = null, $keyword = '', $ai_discover = false)
	{
		$base_url = trim((string) $base_url);
		if ( ! preg_match('#^https?://#i', $base_url)) {
			throw new Exception('Please enter a valid http(s) URL.');
		}
		$tick = is_callable($progress) ? $progress : function () {};
		$keyword = trim((string) $keyword);
		// ALWAYS run the thorough (slowest, most complete) discovery for a full site crawl:
		// union headless rendering with the normal sweep so JS-injected tour links are caught.
		// Slower, but maximises coverage every time. A keyword/limited crawl stays scoped.
		$this->thorough_discovery = ($keyword === '' && (int) $limit <= 0);
		$this->reset_render_budget();
		$this->last_authoritative_total = 0;   // recomputed per crawl (set by discover_ice)

		// A base URL is always discovered into its full product list — UNCAPPED
		// (bounded only by the same-host crawl's visited-set + fetch guard).
		$tick('discovering', 0, 0, $base_url);
		if ($this->thorough_discovery) {
			$this->log_crawl('thorough_discovery_mode', array('base_url' => $base_url));
		}

		// DISCOVER-ONCE-THEN-REUSE: on a continuation run (the queue file AND a non-empty
		// done-file both already exist), reuse the saved URL queue instead of re-discovering
		// the whole site — so discovery runs ONCE on the first run, not again on every
		// auto-continue chunk. A fresh crawl (new job → no done-file) discovers normally.
		$resume = ($this->discovery_url_file !== '' && is_file($this->discovery_url_file) && filesize($this->discovery_url_file) > 0
			&& $this->done_file !== '' && is_file($this->done_file) && filesize($this->done_file) > 0);
		if ($resume) {
			$urls = array_values(array_filter(array_map('trim',
				file($this->discovery_url_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))));
			// Restore the ORIGINAL discovery source (persisted by the worker) so headless gating
			// stays correct across chunks — e.g. an ICE/sitemap site must not headless-render on
			// resume. Fall back to 'resume' (headless allowed) only if none was persisted.
			$this->last_discovery_source = ($this->forced_source !== '') ? $this->forced_source : 'resume';
			$this->log_crawl('resume_reuse_queue', array('urls' => count($urls), 'source' => $this->last_discovery_source));
		} else {
		// ICE site + keyword → use ICE's native search API (server-side match), not a
		// full-catalogue enumeration + text filter. Returns null when it isn't ICE.
		$ice_kw  = ($keyword !== '') ? $this->discover_ice_keyword($base_url, $keyword) : null;
		$ai_used = false;   // AI discovery already applied any keyword — skip the slug filter below
		if ($ice_kw !== null) {
			$found = $ice_kw;
			$this->last_discovery_source = 'ice';
		} elseif ($ai_discover && get_env('OPENAI_API_KEY')) {
			// "Crawl with AI": let the web_search agent browse the site and hand back the
			// individual tour URLs (best for JS sites the cheap cascade can't read). Fall
			// back to the free HTML/sitemap/headless discovery when it returns nothing.
			$found = $this->discover_product_urls_ai($base_url, ($limit > 0 ? $limit : 10), $keyword);
			if ( ! empty($found)) {
				$ai_used = true;
				$this->last_discovery_source = 'ai';
			} else {
				$this->log_crawl('ai_discovery_empty', array('base_url' => $base_url));
				$found = $this->discover_product_urls($base_url, $limit);
			}
		} else {
			$found = $this->discover_product_urls($base_url, $limit);
		}
		$urls = $this->discovered_urls($found);
		// url → discovery label so a keyword can match the tour NAME when the URL is a
		// numeric API id (/api/v1/series/6618).
		$labels = array();
		foreach ((array) $found as $it) {
			if (is_array($it) && isset($it['url'])) {
				$labels[(string) $it['url']] = isset($it['label']) ? (string) $it['label'] : '';
			}
		}

		if ($keyword !== '') {
			// Keyword crawl reads ONLY the matching products. ICE search already matched
			// server-side; otherwise match the URL slug OR the discovery label, PLUS a
			// JS-category drill-down for SPAs whose sitemap lists only category pages.
			if ($ice_kw !== null || $ai_used) {
				$slug = $urls;   // ICE API / AI discovery already applied the keyword
			} else {
				$slug = array();
				foreach ($urls as $u) {
					if (competitor_matches_keyword($u, isset($labels[$u]) ? $labels[$u] : '', $keyword)) {
						$slug[] = $u;
					}
				}
			}
			// JS-category drill-down only when the slug match came up short (and not an
			// ICE / AI-discovery run — those already returned the matching products).
			// Renders category pages with headless (slow), so it's skipped when discovery
			// already answered.
			$hub  = ($ice_kw === null && ! $ai_used && count($slug) < 3) ? $this->discover_keyword_hub_products($base_url, $keyword) : array();
			$urls = array_values(array_unique(array_merge($slug, $hub)));
			if ( ! empty($hub)) {
				$this->last_discovery_source = 'headless';   // JS site → allow headless reads
			}
			$this->log_crawl('keyword_filter', array('keyword' => $keyword,
				'slug' => count($slug), 'hub' => count($hub), 'kept' => count($urls)));
			// No base-URL fallback for a keyword crawl: 0 matches = 0 products.
		} elseif (empty($urls)) {
			$urls = array($base_url);
		}

		// Self-healing coverage (full unbounded, non-keyword crawls only): if the site
		// declares more products than discovery found, escalate discovery to close the
		// gap automatically — no manual per-site deep-dive. A 0/unknown target or an
		// already-complete crawl is a no-op (behaves exactly as before).
		if ($keyword === '' && (int) $limit <= 0) {
			$target = $this->authoritative_total($base_url, $this->last_discovery_source);
			if (competitor_coverage_short(count($urls), $target, $this->coverage_tolerance())) {
				$urls = $this->close_coverage_gap($base_url, $urls, $target);
			}
			$this->log_crawl('coverage_result', array('source' => $this->last_discovery_source,
				'target' => $target, 'discovered' => count($urls)));
		}

		if ((int) $limit > 0) {
			$urls = array_slice($urls, 0, (int) $limit);
		} else {
			// Full crawl: cap the READING to a bounded sample so a mega-site's discovered
			// list doesn't turn into hours of page reads. Real competitors sit under the cap
			// (unaffected); only aggregators get sampled. Logged so the truncation is visible.
			$read_cap = $this->read_cap();
			if ($read_cap > 0 && count($urls) > $read_cap) {
				$this->log_crawl('read_cap_truncated', array('discovered' => count($urls), 'cap' => $read_cap));
				$urls = array_slice($urls, 0, $read_cap);
			}
		}

		// Persist the final discovered URL list (the exact set about to be read) to the
		// per-crawl queue file — written ONCE on the first run; continuation runs reuse it
		// (see discover-once-then-reuse above). Bounded by the caps, so it's cheap.
		if ($this->discovery_url_file !== '' && ! empty($urls)) {
			@file_put_contents($this->discovery_url_file, implode("\n", $urls) . "\n");
		}
		}   // end discover-once (the !$resume branch)

		// CHECKPOINT-STYLE READING FOR EVERY CRAWL: read the discovered URLs one CHUNK per run,
		// carrying forward prior chunks' products, and re-spawn to continue until done. A small
		// site finishes in one chunk; a huge site (aggregator) auto-continues over many short,
		// paced runs that survive blocks/kills. Same flow for all — no special one-shot path.
		$resume_existing_items = array();
		$this->last_has_more   = false;
		$this->last_run_urls   = array();
		$chunk = $this->read_chunk();   // one uniform chunk size, used for every crawl
		if ($this->done_file !== '' && $chunk > 0) {
			$done = array();
			if (is_file($this->done_file)) {
				foreach (file($this->done_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $d) {
					$d = trim($d);
					if ($d !== '') { $done[$d] = true; }
				}
			}
			if ( ! empty($done) && $this->items_checkpoint_file !== '' && is_file($this->items_checkpoint_file)) {
				$prev = json_decode((string) @file_get_contents($this->items_checkpoint_file), true);
				if (is_array($prev)) { $resume_existing_items = $prev; }
			}
			$next = competitor_next_chunk($urls, $done, $chunk);   // pure, unit-tested
			$urls = $next['chunk'];
			$this->last_has_more = $next['has_more'];
			// Remember this run's chunk — marked done AFTER reading (below), so a mid-chunk crash
			// loses nothing (the chunk is simply re-read next run and deduped).
			$this->last_run_urls = $urls;
			$this->log_crawl('resume_chunk', array('already_done' => count($done),
				'this_run' => count($urls), 'chunk' => $chunk, 'has_more' => $this->last_has_more,
				'remaining' => $next['remaining'], 'carried_forward' => count($resume_existing_items)));
		}

		// #4 Headless gating: a structured-catalogue site (sitemap / ICE API) has
		// readable HTML for every product, so reading never needs a browser — don't
		// risk one hanging. Allow headless during reading ONLY when discovery itself
		// needed JS (html/headless/pdf/sweep or the single-URL fallback).
		$this->reading_allow_headless =
			! in_array($this->last_discovery_source, array('sitemap', 'ice'), true);
		$this->log_crawl('reading_config', array('source' => $this->last_discovery_source,
			'allow_headless' => $this->reading_allow_headless, 'products' => count($urls)));

		// Pace the read to the host's advertised robots.txt Crawl-delay. A throttling
		// host (lovelyvacation.com.my sets Crawl-delay: 1) returns empty/429 bodies to a
		// wide burst; those pages then read as thin, fall to a slow headless render that
		// ALSO fails under the load, and the product is dropped. Pacing (a narrower batch
		// + a delay between batches) keeps plain fetches succeeding. 0 = no hint → full speed.
		$parts  = parse_url($base_url);
		$origin = ( ! empty($parts['scheme']) && ! empty($parts['host']))
			? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
			: $base_url;
		$crawl_delay = competitor_robots_crawl_delay($this->fetch_url($origin . '/robots.txt'));

		// Site-wide nav/menu links (from the homepage) — excluded when drilling a
		// listing so its destination menu (e.g. wtstravel's 57 "…-tours" categories)
		// isn't mistaken for the listing's own products.
		$home_html = $this->fetch_url($base_url);
		$this->nav_links = $this->page_links_from_html($home_html, $base_url);

		// Category seeding: some sites hide every product behind category/listing pages
		// (applevacations' /en/listing.php?…) that aren't product URLs, so normal
		// discovery finds almost nothing. Seed those category pages here — the drill
		// step then reaches the products behind them. Only when product discovery came
		// up thin (or a keyword crawl), so big-product sites aren't blown up.
		if ($keyword !== '' || count($urls) < 20) {
			$seeds = $this->category_seeds_from_html($home_html, $base_url, $keyword);
			if ( ! empty($seeds)) {
				$urls = array_values(array_unique(array_merge($urls, $seeds)));
				$this->log_crawl('category_seeds', array('count' => count($seeds)));
			}
		}

		// Per-site adapter: an opaque SPA may expose a clean public product API. Read it
		// directly (one call) and treat those products as ALREADY read — seed the output
		// with them and drop their URLs from the render queue, so we don't waste the
		// headless budget re-rendering pages the API already gave us cleanly. Full crawls
		// only (a keyword crawl stays targeted). Never throws.
		$adapter_items = ($keyword === '') ? $this->site_adapter_items($base_url) : array();
		$adapter_urls  = array();
		foreach ($adapter_items as $it) { $adapter_urls[$it['url']] = true; }
		if ( ! empty($adapter_urls)) {
			$urls = array_values(array_filter($urls, function ($u) use ($adapter_urls) {
				return ! isset($adapter_urls[$u]);
			}));
		}

		$out = $adapter_items;   // adapter products count as read (they carry their text)
		$i = 0;
		// Read in parallel batches, fetching each batch's page bodies concurrently
		// (curl_multi) then extracting from the prefetched body. Queue-based so a
		// category/listing page can DRILL into its individual products (added to the
		// queue), bounded by the drill cap + a visited-set.
		$queue   = array_values($urls);
		$visited = array();
		foreach ($queue as $u) { $visited[$u] = true; }
		foreach ($adapter_urls as $u => $_) { $visited[$u] = true; }   // never re-queue an adapter URL
		// Carry forward prior chunks' products (resume): seed the output + mark their URLs
		// visited so they're never re-read, and so checkpoints keep the full accumulated set.
		if ( ! empty($resume_existing_items)) {
			$have = array();
			foreach ($out as $it) { if (isset($it['url'])) { $have[$it['url']] = true; } }
			foreach ($resume_existing_items as $it) {
				$u = isset($it['url']) ? $it['url'] : null;
				if ($u !== null && ! isset($have[$u])) { $out[] = $it; $have[$u] = true; $visited[$u] = true; }
			}
		}
		$drill_cap = (int) get_env('COMPETITOR_DRILL_MAX');
		$drill_cap = $drill_cap > 0 ? $drill_cap : 800;   // generous — pull more products out of listing pages
		$drilled = 0;
		$drill_skipped = 0;   // fresh child products dropped because the cap was hit
		// Narrow the batch on a self-throttling host so the burst doesn't trip its limit.
		$batch_size = $crawl_delay > 0 ? 3 : 8;
		// Chunked checkpointing: flush progress to the items file every N products read, so a
		// crash / kill / block mid-read never loses everything already read (and the read is
		// naturally paced across chunks). 0 = single final write only.
		$chunk       = $this->read_chunk();
		$last_ckpt   = 0;
		$this->log_crawl('reading_pace', array('crawl_delay' => $crawl_delay, 'batch_size' => $batch_size, 'chunk' => $chunk));
		while ( ! empty($queue)) {
			$batch  = array_splice($queue, 0, $batch_size);
			$tick('reading', $i, count($visited), $batch[0]);
			$bodies = $this->fetch_urls_multi($batch);
			foreach ($batch as $purl) {
				// '' = failed/empty prefetch → pass null so extract can retry the fetch.
				$prefetched = ( ! empty($bodies[$purl])) ? $bodies[$purl] : null;
				// A post that lists several packages expands into one item per package.
				foreach ($this->expand_source_items($purl, $prefetched) as $it) {
					$out[] = $it;
				}
				// Listing drill-down: queue the page's individual products (minus nav).
				if ($this->last_expand_was_listing) {
					$fresh = array_diff($this->last_page_links, $this->nav_links);
					if (count($fresh) < 3 && $drilled < $drill_cap) {
						// Products are JS-injected — render the listing to expose them.
						// Skip the (costly) render once the cap is hit — we can't queue more.
						$r = $this->fetch_rendered($purl);
						if ($r !== '') {
							$fresh = array_diff($this->page_links_from_html($r, $purl), $this->nav_links);
						}
					}
					foreach ($fresh as $link) {
						if (isset($visited[$link])) {
							continue;
						}
						if ($drilled >= $drill_cap) {
							$drill_skipped++;   // count what the cap dropped — no silent truncation
							continue;
						}
						$visited[$link] = true;
						$queue[] = $link;
						$drilled++;
					}
				}
				$tick('reading', ++$i, count($visited), $purl);
			}
			// Checkpoint a full chunk's worth of progress to disk (crash-resilience).
			if ($chunk > 0 && (count($out) - $last_ckpt) >= $chunk) {
				$this->checkpoint_items($out);
				$this->log_crawl('read_checkpoint', array('read_so_far' => count($out), 'remaining_queue' => count($queue)));
				$last_ckpt = count($out);
			}
			// Respect the host's Crawl-delay between batches so we don't get throttled.
			if ($crawl_delay > 0 && ! empty($queue)) {
				usleep((int) ($crawl_delay * 1000000));
			}
		}
		if ($drilled > 0 || $drill_skipped > 0) {
			$entry = array('drilled' => $drilled, 'total_read' => count($visited));
			if ($drill_skipped > 0) {
				// Coverage was bounded — surface it (raise COMPETITOR_DRILL_MAX to go deeper).
				$entry['cap'] = $drill_cap;
				$entry['skipped_at_cap'] = $drill_skipped;
			}
			$this->log_crawl('listing_drill', $entry);
		}

		// RECOVERY PASS: a real product can be dropped by a purely transient blip during
		// the wide read — an empty/throttled body that then reads as thin and fails the
		// gate — even though the page is perfectly readable on its own. Re-read every
		// DISCOVERED product-looking URL that's missing from the result ONE AT A TIME (no
		// concurrency, paced to Crawl-delay, headless OFF so a catalogue/hub re-drops fast
		// instead of paying for a render). Makes coverage robust to network noise.
		$kept_urls = array();
		foreach ($out as $it) { $kept_urls[$it['url']] = true; }
		$recover = array();
		foreach ($urls as $u) {
			if (isset($kept_urls[$u])) { continue; }
			if (competitor_path_has_product_keyword($u) && ! competitor_is_guide_url($u)) {
				$recover[$u] = true;
			}
		}
		if ( ! empty($recover)) {
			$prev_allow_headless = $this->reading_allow_headless;
			$this->reading_allow_headless = false;   // clean plain re-read only — no render load
			$recovered = 0;
			foreach (array_keys($recover) as $ru) {
				if ($crawl_delay > 0) { usleep((int) ($crawl_delay * 1000000)); }
				foreach ($this->expand_source_items($ru, null) as $it) {
					if ( ! isset($kept_urls[$it['url']])) {
						$out[] = $it;
						$kept_urls[$it['url']] = true;
						$recovered++;
					}
				}
			}
			$this->reading_allow_headless = $prev_allow_headless;
			$this->log_crawl('recovery_pass', array('attempted' => count($recover), 'recovered' => $recovered));
		}
		// Mark this run's chunk as ATTEMPTED now that reading (incl. recovery) is complete — so
		// the next run skips them. Done AFTER reading: a mid-chunk crash leaves them un-marked,
		// so they're safely re-read next run rather than lost. Failures count as done (attempted),
		// so a persistently-failing URL isn't retried forever.
		if ($this->done_file !== '' && ! empty($this->last_run_urls)) {
			@file_put_contents($this->done_file, implode("\n", $this->last_run_urls) . "\n", FILE_APPEND);
		}
		// Strip site chrome (mega-menu / header / footer) that repeats verbatim across
		// every product — our tag scraper misses it when it's plain <div>/<ul>. Fixes
		// titles reading "MENUMENU" and stops the menu bloating every AI input.
		$before = array_sum(array_map(function ($it) { return isset($it['text']) ? strlen($it['text']) : 0; }, $out));
		$out = competitor_strip_shared_chrome($out);
		$after = array_sum(array_map(function ($it) { return isset($it['text']) ? strlen($it['text']) : 0; }, $out));
		if ($after < $before) {
			$this->log_crawl('stripped_shared_chrome', array('items' => count($out), 'bytes_removed' => $before - $after));
		}
		// Per-item boilerplate strip (cookie/subscribe/social/breadcrumb/CTA/footer +
		// "related tours" headings). Catches the noise the shared-chrome pass can't —
		// single-product crawls (< 3 items), mid-page widgets, and non-HTML sources
		// (JSON API / PDF-OCR text that never went through html_to_text's line filter).
		$bp_before = $after;
		foreach ($out as &$bp_it) {
			if (isset($bp_it['text'])) { $bp_it['text'] = competitor_strip_boilerplate($bp_it['text']); }
		}
		unset($bp_it);
		$bp_after = array_sum(array_map(function ($it) { return isset($it['text']) ? strlen($it['text']) : 0; }, $out));
		if ($bp_after < $bp_before) {
			$this->log_crawl('stripped_boilerplate', array('items' => count($out), 'bytes_removed' => $bp_before - $bp_after));
		}
		// Lead each product's text with its real page title (<h1>) so the review list
		// and the AI both see the product NAME first — the post-chrome first body line
		// is often a shared inquiry-form/CTA message, not the tour name. Done AFTER
		// chrome-strip so the prepended (unique) title doesn't defeat its shared-run
		// detection. Guarded so a title already leading the body isn't duplicated.
		foreach ($out as &$it) {
			$title = isset($it['title']) ? trim($it['title']) : '';
			if ($title === '') { continue; }
			$body = isset($it['text']) ? $it['text'] : '';
			if (mb_stripos($body, $title, 0, 'UTF-8') !== 0) {
				$it['text'] = 'Product: ' . $title . "\n" . $body;
			}
		}
		unset($it);
		return $out;
	}

	/**
	 * Deep-read ONE tour/product detail page directly — the "Our Product" crawler.
	 * The user pastes the exact detail-page URL, so there is NO site discovery,
	 * listing-drill or tour-page gate: the pasted page is trusted and always kept.
	 * Reuses extract_source_text (leads with schema.org Product JSON-LD, then falls
	 * back through SPA-API / embedded-JSON / headless render and folds in linked
	 * brochure PDFs), then applies the same boilerplate-strip + title-lead cleanup
	 * as crawl_to_text. Returns a single {url, text, title} item — the exact shape
	 * crawl_to_text yields — so the Review → Analyse flow is unchanged.
	 */
	public function read_single_product($url, $progress = null)
	{
		$url = trim((string) $url);
		if ( ! preg_match('#^https?://#i', $url)) {
			throw new Exception('Please enter a valid http(s) URL.');
		}
		$tick = is_callable($progress) ? $progress : function () {};
		$this->reset_render_budget();
		$this->reading_allow_headless = true;   // a JS detail page may still need a browser

		$tick('reading', 0, 1, $url);
		$text  = $this->extract_source_text($url);
		$title = $this->last_page_title;
		if (trim($text) === '') {
			throw new Exception('Could not read the product page (no readable content).');
		}
		$display_url = ($this->last_ice_web_url !== '') ? $this->last_ice_web_url : $url;

		// Same per-item cleanup crawl_to_text applies: strip cookie/nav/footer/CTA
		// boilerplate, then lead the body with the real page title (<h1>) so the AI
		// sees the product NAME first.
		$text = competitor_strip_boilerplate($text);
		if ($title !== '' && mb_stripos($text, $title, 0, 'UTF-8') !== 0) {
			$text = 'Product: ' . $title . "\n" . $text;
		}

		$item = array('url' => $display_url, 'text' => $text);
		if ($title !== '') { $item['title'] = $title; }
		$tick('reading', 1, 1, $url);
		$this->log_crawl('single_product_read', array('url' => $url, 'text_len' => strlen($text)));
		return array($item);
	}

	/**
	 * Shared curl handle. A whole-site crawl talks to ONE host, so reusing its DNS
	 * lookup, TLS session and live connections across every fetch means roughly one
	 * TLS handshake for the whole site instead of one per page — the dominant
	 * per-request cost on HTTPS. Created once, lazily, and reused for the run.
	 */
	protected $curl_share = null;

	protected function curl_share()
	{
		if ($this->curl_share === null) {
			$sh = curl_share_init();
			curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
			curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
			if (defined('CURL_LOCK_DATA_CONNECT')) {   // reuse live connections (PHP 8.0+ / libcurl 7.57+)
				curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
			}
			$this->curl_share = $sh;
		}
		return $this->curl_share;
	}

	/** Browser-like curl options for a single URL, shared by fetch_url + multi. */
	/**
	 * Optional outbound proxy for ALL crawl traffic (curl + headless), so you can route
	 * through a proxy / rotating residential IP to get past hard IP blocks (e.g. tourradar
	 * 403). Returns ['url' => 'scheme://host:port', 'auth' => 'user:pass'] or null when
	 * COMPETITOR_PROXY isn't set (default: no proxy, zero effect on normal crawls).
	 */
	protected function proxy_config()
	{
		$url = trim((string) get_env('COMPETITOR_PROXY'));
		if ($url === '') {
			return null;
		}
		return array('url' => $url, 'auth' => trim((string) get_env('COMPETITOR_PROXY_AUTH')));
	}

	protected function curl_opts($url)
	{
		// Shorter timeout so one slow/hanging page can't stall a wide crawl.
		// Override with COMPETITOR_FETCH_TIMEOUT (seconds).
		$timeout = (int) get_env('COMPETITOR_FETCH_TIMEOUT');
		$timeout = $timeout > 0 ? $timeout : 15;
		$opts = array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 5,
			CURLOPT_CONNECTTIMEOUT => 8,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_ENCODING       => '',   // accept gzip/deflate, curl inflates it
			// Cap the download size (bytes) so a giant multi-tour brochure PDF (sedunia
			// ships 16 MB ones) can't be pulled into memory and OOM the crawl — a page
			// that big isn't a single product anyway. Aborts via Content-Length.
			CURLOPT_MAXFILESIZE    => (int) (get_env('COMPETITOR_MAX_FILE_BYTES') ?: 12582912),  // 12 MB
			// Reuse DNS + TLS session + connection pool across the whole crawl.
			CURLOPT_SHARE          => $this->curl_share(),
			// Prefer HTTP/2 (falls back to 1.1 if unsupported) so many same-host
			// requests can MULTIPLEX over a single connection, not one connection each.
			CURLOPT_HTTP_VERSION   => defined('CURL_HTTP_VERSION_2TLS') ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_1_1,
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
				. '(KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
			CURLOPT_HTTPHEADER     => array(
				'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language: en-US,en;q=0.9',
			),
		);
		// Route through the configured proxy / rotating IP, if any (gets past hard IP blocks).
		$proxy = $this->proxy_config();
		if ($proxy !== null) {
			$opts[CURLOPT_PROXY] = $proxy['url'];
			if ($proxy['auth'] !== '') {
				$opts[CURLOPT_PROXYUSERPWD] = $proxy['auth'];
			}
		}
		return $opts;
	}

	/**
	 * Fetch a URL's raw body with a browser-like request. Follows redirects, short
	 * timeouts, TLS verification left ON (a cert/anti-bot failure simply returns ''
	 * so the caller falls back). Never throws — '' means "couldn't read it".
	 */
	protected function fetch_url($url)
	{
		$host = (string) parse_url($url, PHP_URL_HOST);
		// Cross-crawl cache: a still-fresh page is served straight from disk with NO
		// network request, so a re-crawl (or our own testing) doesn't re-hit — and
		// rate-limit — the host. A stale entry revalidates conditionally (304 = cheap).
		$cache = $this->http_cache_read($url);
		if ($cache !== null && competitor_http_cache_is_fresh($cache['meta'], time(), $this->http_cache_ttl())) {
			return $cache['body'];
		}
		$this->await_host_cooldown($host);
		// Retry on a TRANSIENT failure: timeout / connection reset / 5xx / 429, AND — the
		// subtle one — a 200 with an EMPTY body. Across a crawl the shared, HTTP/2-
		// multiplexed connection gets closed by the server (a GOAWAY after N streams) or
		// otherwise poisoned, so a request on it comes back "200 + 0 bytes". Treating that
		// as success (the old code did) means the page reads as thin → a slow headless
		// render fires, also fails under the load, and a perfectly readable product is
		// dropped. So an empty 200 is retried too — and the RETRY uses a FRESH, NON-
		// multiplexed connection (CURLOPT_FRESH_CONNECT, no CURLOPT_SHARE, forced HTTP/1.1)
		// so it can't reuse the poisoned pooled connection. Adds NO load to healthy
		// fetches (only failures retry). 4xx (except 429) is a hard "no page", not retried.
		$attempts = 3;
		for ($i = 0; $i < $attempts; $i++) {
			$ch   = curl_init();
			$opts = $this->curl_opts($url);
			if ($i > 0) {
				$opts[CURLOPT_FRESH_CONNECT] = true;
				$opts[CURLOPT_FORBID_REUSE]  = true;
				unset($opts[CURLOPT_SHARE]);   // don't reuse the poisoned shared pool
				$opts[CURLOPT_HTTP_VERSION]  = defined('CURL_HTTP_VERSION_1_1')
					? CURL_HTTP_VERSION_1_1 : $opts[CURLOPT_HTTP_VERSION];   // avoid h2 multiplexing
			}
			// Conditional revalidation of a stale cache entry — the server can answer 304.
			if ($cache !== null) {
				$cond = competitor_http_cache_conditional($cache['meta']);
				if ( ! empty($cond) && isset($opts[CURLOPT_HTTPHEADER])) {
					$opts[CURLOPT_HTTPHEADER] = array_merge($opts[CURLOPT_HTTPHEADER], $cond);
				}
			}
			// Capture Retry-After (politeness) + ETag/Last-Modified (cache validators).
			$retry_after = ''; $etag = ''; $last_mod = '';
			$opts[CURLOPT_HEADERFUNCTION] = function ($c, $line) use (&$retry_after, &$etag, &$last_mod) {
				if (stripos($line, 'retry-after:') === 0)        { $retry_after = trim(substr($line, 12)); }
				elseif (stripos($line, 'etag:') === 0)           { $etag = trim(substr($line, 5)); }
				elseif (stripos($line, 'last-modified:') === 0)  { $last_mod = trim(substr($line, 14)); }
				return strlen($line);
			};
			curl_setopt_array($ch, $opts);
			$body  = curl_exec($ch);
			$errno = curl_errno($ch);
			$code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			// 304 Not Modified → our cached copy is still valid; refresh its fresh-window.
			if ( ! $errno && $code === 304 && $cache !== null) {
				$this->http_cache_touch($url);
				return $cache['body'];
			}
			if ( ! $errno && $code >= 200 && $code < 400 && is_string($body) && $body !== '') {
				$this->http_cache_write($url, $body, $etag, $last_mod);
				return $body;   // real, non-empty success
			}
			// Retry transient failures + an empty-body 2xx/3xx; give up on a hard 4xx.
			$transient = ($errno || $code >= 500 || $code === 429 || ($code >= 200 && $code < 400));
			if ( ! $transient || $i === $attempts - 1) {
				break;
			}
			// A throttling response (429/503): back off for real — honour Retry-After, else
			// exponential backoff — AND set a host cooldown so every later request to this
			// host waits too, instead of hammering it into more 429s.
			if ($code === 429 || $code === 503) {
				$wait = competitor_retry_after_seconds($retry_after);
				if ($wait <= 0) { $wait = competitor_backoff_seconds($i); }
				$this->note_host_cooldown($host, $wait);
				usleep((int) (min($wait, 30) * 1000000));
			} else {
				usleep(500000);   // 0.5s back-off, also eases the host's crawl-delay
			}
		}
		return '';
	}

	/**
	 * Record that $host asked us to back off for $secs (429/503) — extends its cooldown
	 * window so both fetch paths hold off. Bounded by the Retry-After cap already applied.
	 */
	protected function note_host_cooldown($host, $secs)
	{
		if ($host === '' || $secs <= 0) {
			return;
		}
		$until = time() + (int) ceil($secs);
		if (empty($this->host_cooldown[$host]) || $until > $this->host_cooldown[$host]) {
			$this->host_cooldown[$host] = $until;
			$this->log_crawl('rate_limited', array('host' => $host, 'cooldown_s' => (int) ceil($secs)));
		}
	}

	/**
	 * Block until $host's 429/503 cooldown has elapsed (capped per wait so a hostile
	 * value can't stall the crawl). No-op when the host isn't cooling down.
	 */
	protected function await_host_cooldown($host)
	{
		if ($host === '' || empty($this->host_cooldown[$host])) {
			return;
		}
		$wait = $this->host_cooldown[$host] - time();
		if ($wait > 0) {
			usleep((int) (min($wait, 30) * 1000000));
		}
	}

	/**
	 * Fresh-window (seconds) for the cross-crawl HTTP cache. >0 = serve from disk without
	 * a network hit for that long; 0 = always revalidate conditionally; <0 = cache OFF.
	 * DEFAULT OFF: the cache stores full page bodies with no size cap, so left on across
	 * many big crawls it can fill the disk (it did). Opt in with COMPETITOR_HTTP_CACHE_TTL
	 * (e.g. 3600 for a 1h window) only when you're watching disk usage. Politeness/backoff,
	 * not this cache, is the primary throttle protection.
	 */
	protected function http_cache_ttl()
	{
		$t = get_env('COMPETITOR_HTTP_CACHE_TTL');
		return ($t === '' || $t === null) ? -1 : (int) $t;
	}

	protected function http_cache_dir()
	{
		$d = APPPATH . 'logs/competitor_crawl/httpcache/';
		if ( ! is_dir($d)) { @mkdir($d, 0755, true); }
		return $d;
	}

	/** Read a URL's cache entry {meta, body}, or null when absent/disabled/unreadable. */
	protected function http_cache_read($url)
	{
		if ($this->http_cache_ttl() < 0) {
			return null;   // caching disabled
		}
		$key = competitor_http_cache_key($url);
		if ($key === '') {
			return null;
		}
		$meta_f = $this->http_cache_dir() . $key . '.json';
		$body_f = $this->http_cache_dir() . $key . '.body';
		if ( ! is_file($meta_f) || ! is_file($body_f)) {
			return null;
		}
		$meta = json_decode((string) @file_get_contents($meta_f), true);
		$body = (string) @file_get_contents($body_f);
		if ( ! is_array($meta) || $body === '') {
			return null;
		}
		return array('meta' => $meta, 'body' => $body);
	}

	/** Store a fresh 200 body + its ETag/Last-Modified validators. No-op when disabled. */
	protected function http_cache_write($url, $body, $etag, $last_modified)
	{
		if ($this->http_cache_ttl() < 0 || ! is_string($body) || $body === '') {
			return;
		}
		$key = competitor_http_cache_key($url);
		if ($key === '') {
			return;
		}
		$dir = $this->http_cache_dir();
		@file_put_contents($dir . $key . '.body', $body);
		@file_put_contents($dir . $key . '.json', json_encode(
			array('url' => $url, 'ts' => time(), 'etag' => (string) $etag, 'last_modified' => (string) $last_modified),
			JSON_UNESCAPED_SLASHES));
	}

	/** Refresh a cache entry's fresh-window after a 304 (content unchanged). */
	protected function http_cache_touch($url)
	{
		$key = competitor_http_cache_key($url);
		if ($key === '') {
			return;
		}
		$meta_f = $this->http_cache_dir() . $key . '.json';
		$meta   = is_file($meta_f) ? json_decode((string) @file_get_contents($meta_f), true) : null;
		if ( ! is_array($meta)) {
			$meta = array('url' => $url);
		}
		$meta['ts'] = time();
		@file_put_contents($meta_f, json_encode($meta, JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Fetch several URLs CONCURRENTLY (curl_multi) and return a map url => body
	 * ('' for any that failed). Used for the linked brochure files — the slow part
	 * of a crawl — so N PDFs download in parallel instead of one-by-one. Order of
	 * the input is irrelevant; the caller keys back by URL. Never throws.
	 */
	protected function fetch_urls_multi($urls)
	{
		$urls = array_values(array_unique(array_filter(array_map('strval', (array) $urls), 'strlen')));
		$out  = array();
		if (empty($urls)) {
			return $out;
		}
		// Cross-crawl cache: serve still-fresh URLs from disk (no network) and only fetch
		// the rest — so a re-crawl doesn't re-hit the host with the whole batch.
		$cached = array();   // url => cache entry (for conditional revalidation / 304 reuse)
		$fetch  = array();
		foreach ($urls as $u) {
			$c = $this->http_cache_read($u);
			if ($c !== null && competitor_http_cache_is_fresh($c['meta'], time(), $this->http_cache_ttl())) {
				$out[$u] = $c['body'];
				continue;
			}
			if ($c !== null) { $cached[$u] = $c; }
			$fetch[] = $u;
		}
		if (empty($fetch)) {
			return $out;
		}
		// Politeness: if any host in this batch is on a 429/503 cooldown, wait it out
		// before firing the concurrent burst (a burst into a throttled host = more 429s).
		foreach (array_unique(array_map(function ($u) { return (string) parse_url($u, PHP_URL_HOST); }, $fetch)) as $h) {
			$this->await_host_cooldown($h);
		}
		$mh = curl_multi_init();
		// Let same-host requests share one connection via HTTP/2 multiplexing instead
		// of opening a socket per URL (with CURLOPT_SHARE this reuses TLS too).
		if (defined('CURLMOPT_PIPELINING') && defined('CURLPIPE_MULTIPLEX')) {
			curl_multi_setopt($mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
		}
		$handles = array();
		$hdr     = array();   // url => ['etag'=>, 'lm'=>] captured from response headers
		foreach ($fetch as $u) {
			$ch   = curl_init();
			$opts = $this->curl_opts($u);
			if (isset($cached[$u])) {
				$cond = competitor_http_cache_conditional($cached[$u]['meta']);
				if ( ! empty($cond) && isset($opts[CURLOPT_HTTPHEADER])) {
					$opts[CURLOPT_HTTPHEADER] = array_merge($opts[CURLOPT_HTTPHEADER], $cond);
				}
			}
			$hdr[$u] = array('etag' => '', 'lm' => '');
			$opts[CURLOPT_HEADERFUNCTION] = function ($c, $line) use (&$hdr, $u) {
				if (stripos($line, 'etag:') === 0)              { $hdr[$u]['etag'] = trim(substr($line, 5)); }
				elseif (stripos($line, 'last-modified:') === 0) { $hdr[$u]['lm'] = trim(substr($line, 14)); }
				return strlen($line);
			};
			curl_setopt_array($ch, $opts);
			curl_multi_add_handle($mh, $ch);
			$handles[$u] = $ch;
		}
		do {
			$status = curl_multi_exec($mh, $running);
			if ($running) {
				curl_multi_select($mh, 1.0);
			}
		} while ($running && $status === CURLM_OK);

		foreach ($handles as $u => $ch) {
			$body = curl_multi_getcontent($ch);
			$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			// A throttled response in the burst → put that host on cooldown so the NEXT
			// batch backs off (curl_multi can't read Retry-After cheaply here, so use a
			// fixed exponential-ish backoff).
			if ($code === 429 || $code === 503) {
				$this->note_host_cooldown((string) parse_url($u, PHP_URL_HOST), competitor_backoff_seconds(1));
			}
			if ($code === 304 && isset($cached[$u])) {
				$out[$u] = $cached[$u]['body'];   // unchanged — reuse cached copy
				$this->http_cache_touch($u);
			} elseif ($code >= 200 && $code < 400 && is_string($body) && $body !== '') {
				$out[$u] = $body;
				$this->http_cache_write($u, $body, $hdr[$u]['etag'], $hdr[$u]['lm']);
			} else {
				$out[$u] = '';
			}
			curl_multi_remove_handle($mh, $ch);
			curl_close($ch);
		}
		curl_multi_close($mh);
		return $out;
	}

	/**
	 * Analyse an uploaded competitor product file (PDF or image). $file_path is
	 * a readable local path, $ext its extension. The file is sent to OpenAI as
	 * an input_file/input_image content part — no web_search, the document is
	 * the source. Returns the parsed record ready for the model layer.
	 */
	public function analyze_file($file_path, $ext, $our_products)
	{
		if ( ! is_readable($file_path)) {
			throw new Exception('The uploaded file could not be read.');
		}
		$data = file_get_contents($file_path);
		if ($data === false || $data === '') {
			throw new Exception('The uploaded file is empty.');
		}
		$part = competitor_file_input_part($ext, base64_encode($data));
		if ($part === null) {
			throw new Exception('Unsupported file type. Upload a PDF or an image (JPG, PNG, GIF, WEBP).');
		}

		$spec  = competitor_build_file_agent($our_products);
		$input = array(array(
			'role'    => 'user',
			'content' => array(
				array('type' => 'input_text', 'text' => $spec['text']),
				$part,
			),
		));
		$raw = $this->request($spec['instructions'], $input, array(), 'file ' . $ext);
		return $this->to_record($raw);
	}

	/**
	 * Analyse PASTED TEXT (notes the user typed, with competitor links dropped in).
	 * We pull the http(s) URLs out of the text and scrape each page OURSELVES
	 * (extract_source_text — the same pipeline as a crawl, PDFs and SPAs included),
	 * then hand the pasted notes PLUS every link's scraped content to OpenAI. Links
	 * a plain fetch couldn't read (JS SPAs) are handed to the browsing agent with
	 * web_search so the link content still reaches the model. Returns one record.
	 */
	public function analyze_paste($text, $our_products)
	{
		$text = trim((string) $text);
		if ($text === '') {
			throw new Exception('Paste some text or a competitor link to analyse.');
		}

		$urls  = competitor_extract_text_urls($text);
		$links = array();
		$any_thin = false;
		foreach ($urls as $u) {
			try {
				$body = $this->extract_source_text($u);
			} catch (Exception $e) {
				$body = '';
			}
			$thin = competitor_scrape_is_thin($body);
			if ($thin) { $any_thin = true; }
			$links[] = array('url' => $u, 'text' => $thin ? '' : $body);
		}

		// Only let the model browse the unreadable links when we actually have a
		// web_search tool for this model — otherwise just analyse the notes + text.
		$allow_browse = $any_thin && $this->web_search_tool() !== '';
		$spec  = competitor_build_paste_agent($text, $links, $our_products, $allow_browse);
		$tools = $allow_browse ? array(array('type' => $this->web_search_tool())) : array();
		$raw   = $this->request($spec['instructions'], $spec['input'], $tools, 'paste');
		return $this->to_record($raw);
	}

	/** Parse the assistant text into a stored record, stamping model + raw. */
	protected function to_record($raw)
	{
		$record = competitor_parse_ai_response($raw);
		if ($record === null) {
			throw new Exception('The AI response could not be parsed. Please try again.');
		}
		$record['raw_json']      = $raw;
		$record['model']         = $this->model();
		$record['input_tokens']  = $this->last_usage['input_tokens'];
		$record['output_tokens'] = $this->last_usage['output_tokens'];
		$record['cost_usd']      = competitor_estimate_cost(
			$this->model(), $record['input_tokens'], $record['output_tokens'], $this->price_rates()
		);
		return $record;
	}

	/**
	 * Translate a whole analysis (its list of display products) into $lang with a
	 * single OpenAI call. Returns a cacheable overlay structure
	 *   { products: [ {scalars,lists,meals,itinerary}, ... ],
	 *     lang, model, input_tokens, output_tokens, cost_usd }
	 * that competitor_apply_translation_to_row() overlays onto the row at render
	 * time. Throws Exception (surfaced to the user) on any failure.
	 */
	public function translate_analysis($products, $lang)
	{
		$this->CI->load->helper('competitor_analysis');
		$payload = array('products' => array());
		foreach ((array) $products as $p) {
			$payload['products'][] = competitor_extract_translatable((array) $p);
		}

		$spec = competitor_build_translation_agent($payload, $lang);
		// json_object mode guarantees a syntactically valid reply (the model can
		// otherwise emit an unbalanced brace on long Chinese output).
		$raw  = $this->request($spec['instructions'], $spec['input'], array(), 'translate ' . $lang, true);
		$data = competitor_json_object_from_text($raw);
		if ( ! is_array($data) || ! isset($data['products']) || ! is_array($data['products'])) {
			throw new Exception('The translation response could not be parsed. Please try again.');
		}

		return array(
			'products'      => array_values($data['products']),
			'lang'          => competitor_normalize_lang($lang),
			'model'         => $this->model(),
			'input_tokens'  => $this->last_usage['input_tokens'],
			'output_tokens' => $this->last_usage['output_tokens'],
			'cost_usd'      => competitor_estimate_cost(
				$this->model(), $this->last_usage['input_tokens'], $this->last_usage['output_tokens'], $this->price_rates()
			),
		);
	}

	protected function model()
	{
		$m = get_env('OPENAI_MODEL');
		return $m ? $m : 'gpt-4o-mini';
	}

	/**
	 * Optional per-1M-token price override from .env (OPENAI_PRICE_INPUT /
	 * OPENAI_PRICE_OUTPUT, USD). Returns null when unset so the helper falls back
	 * to its built-in per-model price table.
	 */
	protected function price_rates()
	{
		// get_env() returns null (not false) when a key is unset, so test for a
		// real numeric value — otherwise unset overrides become (float) null = 0
		// and every cost is stored as 0.
		$in  = get_env('OPENAI_PRICE_INPUT');
		$out = get_env('OPENAI_PRICE_OUTPUT');
		if (is_numeric($in) && is_numeric($out)) {
			return array('input' => (float) $in, 'output' => (float) $out);
		}
		return null;
	}

	protected function web_search_tool()
	{
		return competitor_web_search_tool_for_model($this->model(), (string) get_env('OPENAI_WEB_SEARCH_TOOL'));
	}

	/**
	 * Call the OpenAI Responses API and return the assistant's final text
	 * (expected to be a JSON object). $input is either a string or a structured
	 * message/content array; $tools is the tools list (empty = none).
	 */
	protected function request($instructions, $input, $tools = array(), $label = '', $json_object = false)
	{
		$key = get_env('OPENAI_API_KEY');
		if (empty($key)) {
			$this->log_ai('config_error', array('label' => $label, 'message' => 'OPENAI_API_KEY missing'));
			throw new Exception('OpenAI is not configured. Add OPENAI_API_KEY to the .env file.');
		}
		$base = get_env('OPENAI_BASE_URL');
		$base = $base ? rtrim($base, '/') : 'https://api.openai.com/v1';

		$tool_types = array();
		foreach ((array) $tools as $t) { $tool_types[] = isset($t['type']) ? $t['type'] : '?'; }

		$payload = array(
			'model'        => $this->model(),
			'instructions' => $instructions,
			'input'        => $input,
		);
		// Reasoning models (gpt-5+, o-series) reject a custom temperature — only send
		// it to models that accept one (gpt-4o etc.).
		if (competitor_model_supports_temperature($this->model())) {
			$payload['temperature'] = 0.2;
		}
		if ( ! empty($tools)) {
			$payload['tools'] = $tools;
		}
		// Force a syntactically valid JSON object reply (Responses API "json mode").
		// Requires the word "json" in the input, which the caller supplies.
		if ($json_object) {
			$payload['text'] = array('format' => array('type' => 'json_object'));
		}

		$this->log_ai('request', array(
			'label'            => $label,
			'model'            => $this->model(),
			'tools'            => $tool_types,
			'instructions_len' => strlen((string) $instructions),
			'input_len'        => is_string($input) ? strlen($input) : strlen((string) json_encode($input)),
		));

		$started = microtime(true);
		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL            => $base . '/responses',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			CURLOPT_CONNECTTIMEOUT => 15,
			// Browsing / document reading + reasoning can take a while — GPT-5 reasoning
			// models especially, so allow a longer ceiling.
			CURLOPT_TIMEOUT        => 300,
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . $key,
			),
		));
		$resp  = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		$code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$ms = (int) round((microtime(true) - $started) * 1000);

		if ($errno) {
			$this->log_ai('curl_error', array('label' => $label, 'errno' => $errno, 'error' => $error, 'ms' => $ms));
			throw new Exception('Could not reach OpenAI: ' . $error);
		}
		$json = json_decode($resp, true);
		// Capture token usage for costing before we unwrap the text.
		$this->last_usage = competitor_extract_usage($json);
		// Accumulate this call's cost so a crawl's non-product AI spend (the AI-crawl
		// discovery web_search) can be billed onto the crawl row.
		$this->run_cost += competitor_estimate_cost(
			$this->model(), $this->last_usage['input_tokens'], $this->last_usage['output_tokens'], $this->price_rates());
		if ($code >= 400) {
			$msg = isset($json['error']['message']) ? $json['error']['message'] : ('HTTP ' . $code);
			$this->log_ai('http_error', array('label' => $label, 'code' => $code, 'message' => $msg, 'ms' => $ms, 'body' => mb_substr((string) $resp, 0, 3000)));
			throw new Exception('OpenAI error: ' . $msg);
		}

		$text = competitor_extract_responses_text($json);
		if ($text === '') {
			$this->log_ai('empty_response', array('label' => $label, 'code' => $code, 'usage' => $this->last_usage, 'ms' => $ms, 'body' => mb_substr((string) $resp, 0, 3000)));
			throw new Exception('OpenAI returned no readable analysis. Try a different source or model.');
		}

		$this->log_ai('response', array('label' => $label, 'code' => $code, 'usage' => $this->last_usage, 'ms' => $ms, 'text_snippet' => mb_substr($text, 0, 800)));
		$this->log_usage($this->usage_feature);
		return $text;
	}

	/**
	 * Set the ai_usage_log feature label for calls made through this instance, so
	 * Our Product / Product Extraction spend isn't misattributed to Competitor
	 * Analysis. Blank input is ignored (keeps the default). Public — called by the
	 * controller right after loading the library.
	 */
	public function set_usage_feature($label)
	{
		$label = trim((string) $label);
		if ($label !== '') {
			$this->usage_feature = mb_substr($label, 0, 64);
		}
	}

	/**
	 * Record this call's tokens + cost to the central ai_usage_log so the owner's
	 * "AI Cost & Usage" page can report it. Fires once per actual OpenAI call
	 * (including web_search discovery). Best-effort: never throws so usage logging
	 * can never break the paid AI flow.
	 */
	protected function log_usage($feature)
	{
		try {
			$in   = (int) $this->last_usage['input_tokens'];
			$out  = (int) $this->last_usage['output_tokens'];
			$cost = competitor_estimate_cost($this->model(), $in, $out, $this->price_rates());
			$by   = (isset($this->CI->session) && ! empty($this->CI->session->admin_id)) ? (int) $this->CI->session->admin_id : null;
			$this->CI->load->model('Ai_Usage_Model');
			$this->CI->Ai_Usage_Model->Log(array(
				'feature'       => $feature,
				'model'         => $this->model(),
				'input_tokens'  => $in,
				'output_tokens' => $out,
				'cost_usd'      => $cost,
				'created_by'    => $by,
			));
		} catch (Exception $e) {
			// never break the AI flow because usage logging failed
		}
	}

	/**
	 * Append one JSON line per actual OpenAI call to competitor_ai.log — ONLY the
	 * request()/response events, so a dump-mode (AI-disabled) run writes nothing
	 * here. Captures request shape, HTTP/curl outcome, token usage, timing, errors.
	 */
	protected function log_ai($event, array $data = array())
	{
		$this->write_log('competitor_ai.log', 'CompetitorAI', $event, $data);
	}

	/**
	 * Append one JSON line per crawl/scrape step (discovery, spa/embedded/pdf/ocr
	 * reads, skips) to competitor_crawl.log — the AI-free side, so competitor_ai.log
	 * stays reserved for real AI calls.
	 */
	protected function log_crawl($event, array $data = array())
	{
		$this->write_log('competitor_crawl.log', 'CompetitorCrawl', $event, $data);
	}

	/** Shared JSON-line writer for the two logs. Never throws — logging must not break analysis. */
	protected function write_log($file, $tag, $event, array $data)
	{
		$entry = array_merge(array('ts' => date('Y-m-d H:i:s'), 'event' => $event), $data);
		$json  = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		@file_put_contents(APPPATH . 'logs/' . $file, $json . "\n", FILE_APPEND | LOCK_EX);
		if (function_exists('log_message')) {
			log_message('error', $tag . ' ' . $json);
		}
	}
}
