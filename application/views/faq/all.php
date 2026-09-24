<?php
	// Grouped FAQ page (/Faq/All). Every active internal FAQ on one screen, each
	// rendered as its own section of sub-Q&As, with a single search box and tag
	// filter spanning the whole set. $faqs is the internal-only list from
	// Read_Faqs() (carries Title, Slug, Description, Destinations); $tag_names
	// maps FAQTagID => Name for the per-item tag chips. Mirrors the per-FAQ page
	// (faq/page.php) design so the two read as one product.

	// Decode every FAQ's sub-Q&As up front so we can both render them and build
	// the union of tags actually used across the whole library (id => name). The
	// tag bar only offers tags that appear on some item, so it never lists one
	// that would match nothing. Sorted by name.
	$decoded_faqs = array();
	$present_tags = array();
	foreach($faqs as $faq) {
		$items = Faq_Model::Decode_Items($faq->Description);
		$decoded_faqs[] = array('faq' => $faq, 'items' => $items);
		if(isset($tag_names)) {
			foreach($items as $it) {
				if(isset($it['tags']) && is_array($it['tags'])) {
					foreach($it['tags'] as $tid) {
						$tid = (int)$tid;
						if($tid > 0 && isset($tag_names[$tid])) {
							$present_tags[$tid] = $tag_names[$tid];
						}
					}
				}
			}
		}
	}
	asort($present_tags, SORT_NATURAL | SORT_FLAG_CASE);

	// Split the present tags into curated "default" chips (shown up-front) and
	// the rest (revealed only when the tag search bar matches them). The set of
	// default ids comes from faq_tag.IsDefault via the controller.
	$default_ids = isset($default_tag_ids) ? $default_tag_ids : array();
	$tag_chips   = Faq_Model::Tag_Chips($present_tags, $default_ids);
?>
<!DOCTYPE html>
<html lang="en" data-faq-theme="internal">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title>HolidayGoGoGo &middot; FAQ Library</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
	<style>
		:root {
			/* Internal = blue: shared with the per-FAQ page's design tokens. */
			--accent:        #2f6fb0;
			--accent-deep:   #1d4e80;
			--accent-soft:   #e8f1fa;
			--accent-tint:   #f4f8fc;
			--ring:          rgba(47, 111, 176, 0.18);
			--ink:           #16202b;
			--muted:         #5f6b78;
			--line:          #e4ebf2;
			--bg:            #f6f8fb;
			--card:          #ffffff;
		}

		* { box-sizing: border-box; margin: 0; padding: 0; }

		body {
			font-family: 'Outfit', -apple-system, sans-serif;
			color: var(--ink);
			background:
				radial-gradient(1200px 600px at 100% -10%, var(--accent-soft) 0%, transparent 55%),
				radial-gradient(900px 500px at -10% 0%, var(--accent-tint) 0%, transparent 50%),
				var(--bg);
			min-height: 100vh;
			-webkit-font-smoothing: antialiased;
			line-height: 1.5;
		}

		.wrap {
			max-width: 820px;
			margin: 0 auto;
			padding: clamp(32px, 6vw, 80px) clamp(20px, 5vw, 32px) 80px;
		}

		/* ---- Header ---- */
		.brand {
			display: flex;
			align-items: center;
			gap: 10px;
			margin-bottom: 30px;
			font-weight: 600;
			letter-spacing: -0.01em;
			color: var(--ink);
		}
		.brand .mark {
			width: 30px; height: 30px;
			border-radius: 9px;
			background: linear-gradient(135deg, var(--accent), var(--accent-deep));
			display: grid; place-items: center;
			color: #fff; font-family: 'Fraunces', serif; font-weight: 700;
			box-shadow: 0 4px 12px var(--ring);
		}

		.eyebrow {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			font-size: 12px;
			font-weight: 600;
			letter-spacing: 0.14em;
			text-transform: uppercase;
			color: var(--accent-deep);
			background: var(--card);
			border: 1px solid var(--line);
			padding: 7px 14px;
			border-radius: 100px;
			box-shadow: 0 1px 2px rgba(16, 32, 43, 0.04);
		}
		.eyebrow::before {
			content: "";
			width: 7px; height: 7px;
			border-radius: 50%;
			background: var(--accent);
			box-shadow: 0 0 0 4px var(--ring);
		}

		h1 {
			font-family: 'Fraunces', Georgia, serif;
			font-weight: 600;
			font-size: clamp(28px, 5vw, 44px);
			line-height: 1.1;
			letter-spacing: -0.02em;
			margin: 18px 0 0;
			color: var(--ink);
		}
		.lede { color: var(--muted); font-size: 15px; font-weight: 300; margin-top: 10px; }

		/* chips */
		.faq-meta { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 14px; }
		.chip {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			font-size: 12.5px;
			font-weight: 500;
			padding: 4px 12px;
			border-radius: 100px;
			line-height: 1.4;
		}
		.chip-tag  { background: var(--accent-soft); color: var(--accent-deep); }
		.chip-dest { background: var(--card); color: var(--muted); border: 1px solid var(--line); }
		.chip-dest::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: var(--accent); }

		/* ---- Search + tag filter (whole-library controls) ---- */
		.controls {
			margin-top: clamp(26px, 5vw, 40px);
			background: var(--card);
			border: 1px solid var(--line);
			border-radius: 18px;
			padding: clamp(14px, 3vw, 22px);
			box-shadow: 0 18px 44px -30px rgba(16, 32, 43, 0.45);
			position: sticky;
			top: 12px;
			z-index: 5;
		}
		.faq-search { position: relative; }
		.faq-search .search-icon {
			position: absolute;
			left: 16px; top: 50%;
			transform: translateY(-50%);
			width: 17px; height: 17px;
			stroke: var(--muted);
			pointer-events: none;
		}
		.faq-search input {
			width: 100%;
			font-family: 'Outfit', sans-serif;
			font-size: 15px;
			color: var(--ink);
			padding: 13px 44px;
			border: 1px solid var(--line);
			border-radius: 12px;
			background: var(--accent-tint);
			outline: none;
			transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
		}
		.faq-search input::placeholder { color: var(--muted); }
		.faq-search input:focus {
			border-color: var(--accent);
			background: var(--card);
			box-shadow: 0 0 0 4px var(--ring);
		}
		.faq-search .search-clear {
			position: absolute;
			right: 10px; top: 50%;
			transform: translateY(-50%);
			width: 26px; height: 26px;
			display: none;
			align-items: center;
			justify-content: center;
			border: none;
			background: transparent;
			border-radius: 50%;
			cursor: pointer;
			color: var(--muted);
		}
		.faq-search .search-clear:hover { background: var(--accent-soft); color: var(--accent-deep); }
		.faq-search .search-clear svg { width: 15px; height: 15px; stroke: currentColor; }
		.faq-search.has-value .search-clear { display: flex; }

		.tag-filter-wrap { margin-top: 14px; }
		.tag-filter-head {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 14px;
			margin-bottom: 10px;
		}
		/* Tag search: filters which chips are shown (default chips up-front, any
		   tag reachable by typing). Separate from the "Search all FAQs" box. */
		.tag-search { position: relative; display: inline-flex; align-items: center; }
		.tag-search .tag-search-icon {
			position: absolute;
			left: 12px; top: 50%;
			transform: translateY(-50%);
			width: 15px; height: 15px;
			stroke: var(--muted);
			pointer-events: none;
		}
		.tag-search input {
			font-family: 'Outfit', sans-serif;
			font-size: 13px;
			color: var(--ink);
			padding: 7px 14px 7px 34px;
			min-width: 220px;
			border: 1px solid var(--line);
			border-radius: 100px;
			background: var(--card);
			outline: none;
			transition: border-color .2s ease, box-shadow .2s ease;
		}
		.tag-search input::placeholder { color: var(--muted); }
		.tag-search input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
		.tag-filter {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 7px;
		}
		/* Hidden until the tag search reveals it (or it's an active filter). */
		.tag-filter-chip.is-tag-hidden { display: none; }
		.tag-filter-empty {
			font-family: 'Outfit', sans-serif;
			font-size: 12.5px;
			font-style: italic;
			color: var(--muted);
		}
		.tag-filter-empty.is-tag-hidden { display: none; }
		.tag-filter-label {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			font-size: 12px;
			font-weight: 600;
			letter-spacing: 0.04em;
			text-transform: uppercase;
			color: var(--muted);
			margin-right: 2px;
		}
		.tag-filter-label svg { width: 14px; height: 14px; stroke: var(--muted); }
		.tag-filter-chip {
			font-family: 'Outfit', sans-serif;
			font-size: 12.5px;
			font-weight: 500;
			line-height: 1.4;
			padding: 5px 13px;
			border-radius: 100px;
			border: 1px solid var(--line);
			background: var(--card);
			color: var(--muted);
			cursor: pointer;
			transition: border-color .2s ease, background .2s ease, color .2s ease, box-shadow .2s ease;
		}
		.tag-filter-chip:hover { border-color: var(--accent); color: var(--accent-deep); }
		.tag-filter-chip:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
		.tag-filter-chip.is-active {
			background: var(--accent);
			border-color: var(--accent);
			color: #fff;
			box-shadow: 0 6px 16px -10px var(--ring);
		}
		.search-count {
			font-size: 12.5px;
			color: var(--muted);
			margin-top: 12px;
			min-height: 16px;
		}
		.search-count strong { color: var(--accent-deep); font-weight: 600; }

		/* Search keyword highlight inside questions/answers */
		mark.faq-hl {
			background: #ffe58a;
			color: inherit;
			padding: 0 1px;
			border-radius: 3px;
		}

		/* ---- FAQ section ---- */
		.faq-section {
			margin-top: clamp(20px, 4vw, 30px);
			background: var(--card);
			border: 1px solid var(--line);
			border-radius: 18px;
			padding: clamp(14px, 3vw, 22px);
			box-shadow: 0 18px 44px -30px rgba(16, 32, 43, 0.45);
		}
		.faq-section.is-hidden { display: none; }
		.faq-section-head {
			display: flex;
			align-items: flex-start;
			gap: 12px;
			padding-bottom: 6px;
		}
		.faq-section-title {
			flex: 1;
			font-family: 'Fraunces', Georgia, serif;
			font-weight: 600;
			font-size: clamp(19px, 3vw, 24px);
			line-height: 1.2;
			letter-spacing: -0.01em;
			color: var(--ink);
		}
		.faq-open-link {
			flex-shrink: 0;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: 34px; height: 34px;
			border-radius: 10px;
			border: 1px solid var(--line);
			background: var(--card);
			color: var(--accent-deep);
			transition: border-color .2s ease, background .2s ease, box-shadow .2s ease;
		}
		.faq-open-link:hover { border-color: var(--accent); background: var(--accent-soft); box-shadow: 0 6px 18px -12px var(--ring); }
		.faq-open-link svg { width: 16px; height: 16px; stroke: currentColor; }

		/* ---- Accordion ---- */
		.a-list { margin-top: 6px; }
		.a-item { border-top: 1px solid var(--line); }
		.a-item:first-child { border-top: none; }
		.a-item.is-hidden { display: none; }

		.a-trigger {
			width: 100%;
			display: flex;
			align-items: center;
			gap: 14px;
			text-align: left;
			background: transparent;
			border: none;
			cursor: pointer;
			padding: 16px 4px;
		}
		.a-trigger:focus-visible {
			outline: 2px solid var(--accent);
			outline-offset: 2px;
			border-radius: 8px;
		}
		.a-q {
			flex: 1;
			font-family: 'Fraunces', Georgia, serif;
			font-weight: 600;
			font-size: clamp(15px, 2.2vw, 18px);
			line-height: 1.35;
			color: var(--ink);
			transition: color .2s ease;
		}
		.a-trigger:hover .a-q { color: var(--accent-deep); }
		.a-chevron {
			flex-shrink: 0;
			width: 20px; height: 20px;
			stroke: var(--accent);
			transition: transform .25s ease;
		}
		.a-item.is-open .a-chevron { transform: rotate(180deg); }
		.a-item.is-open .a-q { color: var(--accent-deep); }

		.a-panel {
			overflow: hidden;
			max-height: 0;
			transition: max-height .3s ease;
		}
		.a-panel-inner { padding: 0 4px 18px; }
		.a-a {
			color: var(--muted);
			font-size: 15.5px;
			font-weight: 300;
			line-height: 1.75;
		}
		.a-meta {
			display: flex;
			align-items: center;
			gap: 6px;
			margin-top: 12px;
			font-size: 12.5px;
			color: var(--accent-deep);
		}
		.a-meta svg { width: 13px; height: 13px; stroke: var(--accent-deep); flex-shrink: 0; }
		.item-tags { margin-top: 10px; }
		/* Reference links under an answer */
		.a-links { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
		.a-link {
			display: inline-flex;
			align-items: center;
			gap: 7px;
			font-size: 13.5px;
			font-weight: 500;
			line-height: 1.4;
			padding: 6px 13px;
			border-radius: 100px;
			border: 1px solid var(--line);
			background: var(--accent-tint);
			color: var(--accent-deep);
			text-decoration: none;
			transition: border-color .2s ease, background .2s ease, box-shadow .2s ease;
		}
		.a-link:hover { border-color: var(--accent); background: var(--accent-soft); box-shadow: 0 6px 16px -12px var(--ring); }
		.a-link svg { width: 14px; height: 14px; stroke: var(--accent-deep); flex-shrink: 0; }

		.empty-body { color: var(--muted); font-style: italic; font-weight: 300; padding: 6px 4px; }
		.no-results {
			margin-top: clamp(20px, 4vw, 30px);
			background: var(--card);
			border: 1px solid var(--line);
			border-radius: 18px;
			padding: 28px 22px;
			text-align: center;
			color: var(--muted);
			font-weight: 300;
			display: none;
		}
		.no-results.show { display: block; }

		footer {
			margin-top: 48px;
			padding-top: 22px;
			border-top: 1px solid var(--line);
			font-size: 13px;
			color: var(--muted);
			display: flex;
			justify-content: space-between;
			flex-wrap: wrap;
			gap: 8px;
		}
		/* ---- AI answer box ---- */
		.ai-ask {
			margin-top: clamp(22px, 4vw, 32px);
			background: linear-gradient(135deg, var(--accent), var(--accent-deep));
			border-radius: 18px;
			padding: clamp(16px, 3vw, 24px);
			box-shadow: 0 18px 44px -26px rgba(29, 78, 128, 0.7);
			color: #fff;
		}
		.ai-ask-head { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
		.ai-ask-head svg { width: 20px; height: 20px; stroke: #fff; }
		.ai-ask-title { font-family: 'Fraunces', Georgia, serif; font-weight: 600; font-size: 18px; }
		.ai-ask-sub { font-size: 13px; font-weight: 300; opacity: 0.9; margin-bottom: 14px; }
		.ai-ask-form { display: flex; gap: 10px; flex-wrap: wrap; }
		.ai-ask-input {
			flex: 1 1 260px;
			border: none;
			border-radius: 12px;
			padding: 13px 16px;
			font-family: inherit;
			font-size: 15px;
			color: var(--ink);
			background: #fff;
			outline: none;
			box-shadow: 0 0 0 3px transparent;
		}
		.ai-ask-input:focus { box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.5); }
		.ai-ask-count { margin-top: 6px; font-size: 12px; font-weight: 300; opacity: 0.85; text-align: right; }
		.ai-ask-count.is-max { opacity: 1; font-weight: 500; }
		.ai-ask-btn {
			border: none;
			border-radius: 12px;
			padding: 13px 22px;
			font-family: inherit;
			font-size: 14.5px;
			font-weight: 600;
			color: var(--accent-deep);
			background: #fff;
			cursor: pointer;
			display: inline-flex;
			align-items: center;
			gap: 8px;
			transition: transform 0.08s ease, opacity 0.2s ease;
		}
		.ai-ask-btn:hover { transform: translateY(-1px); }
		.ai-ask-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
		.ai-ask-btn svg { width: 16px; height: 16px; stroke: currentColor; }
		.ai-result {
			display: none;
			margin-top: 16px;
			background: #fff;
			border-radius: 14px;
			padding: 16px 18px;
			color: var(--ink);
		}
		.ai-result.show { display: block; }
		.ai-result-head {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 12px;
			margin-bottom: 10px;
		}
		.ai-result-label { font-size: 12px; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: var(--accent-deep); }
		.ai-copy-btn {
			border: 1px solid var(--line);
			background: var(--accent-tint);
			color: var(--accent-deep);
			border-radius: 10px;
			padding: 7px 14px;
			font-family: inherit;
			font-size: 13px;
			font-weight: 600;
			cursor: pointer;
			display: inline-flex;
			align-items: center;
			gap: 6px;
		}
		.ai-copy-btn svg { width: 14px; height: 14px; stroke: currentColor; }
		.ai-answer { font-size: 15px; line-height: 1.6; white-space: pre-wrap; color: var(--ink); }
		.ai-answer.is-muted { color: var(--muted); font-style: italic; }
		.ai-sources { margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--line); font-size: 12.5px; color: var(--muted); }
		.ai-sources strong { color: var(--ink); font-weight: 600; }
		.ai-error { margin-top: 14px; background: #fff3f3; color: #b42318; border-radius: 12px; padding: 12px 16px; font-size: 14px; display: none; }
		.ai-error.show { display: block; }
		.ai-spinner { width: 15px; height: 15px; border: 2px solid rgba(29,78,128,0.25); border-top-color: var(--accent-deep); border-radius: 50%; animation: ai-spin 0.7s linear infinite; }
		@keyframes ai-spin { to { transform: rotate(360deg); } }
	</style>
</head>
<body>
	<main class="wrap">
		<div class="brand">
			<span class="mark">H</span>
			<span>HolidayGoGoGo</span>
		</div>

		<div>
			<span class="eyebrow">For Our Team</span>
		</div>
		<h1>FAQ Library</h1>
		<p class="lede">Every internal FAQ in one place. Search across all of them, or filter by tag.</p>

		<!-- AI answer: type a customer question, get a ready-to-send reply grounded
		     in the FAQ library (Faq/Ai_Search). No data is stored.
		     Hidden per request — flip this condition to true to bring "Ask AI" back. -->
		<?php if(false) { ?>
		<section class="ai-ask">
			<div class="ai-ask-head">
				<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.6L18.5 9l-4.6 1.9L12 15.5 10.1 10.9 5.5 9l4.6-1.4L12 3z"></path><path d="M19 15l.7 1.8L21.5 17.5l-1.8.7L19 20l-.7-1.8L16.5 17.5l1.8-.7L19 15z"></path></svg>
				<span class="ai-ask-title">Ask AI</span>
			</div>
			<p class="ai-ask-sub">Type a customer's question — AI reads every FAQ and drafts a reply you can copy and send.</p>
			<form class="ai-ask-form" id="aiAskForm" autocomplete="off">
				<input type="text" class="ai-ask-input" id="aiAskInput" maxlength="250" placeholder="e.g. What time is check-in at Redang Bay Resort?" aria-label="Ask a question">
				<button type="submit" class="ai-ask-btn" id="aiAskBtn">
					<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
					<span id="aiAskBtnText">Ask</span>
				</button>
			</form>
			<div class="ai-ask-count" id="aiAskCount">0 / 250</div>
			<div class="ai-error" id="aiError"></div>
			<div class="ai-result" id="aiResult">
				<div class="ai-result-head">
					<span class="ai-result-label">Suggested reply</span>
					<button type="button" class="ai-copy-btn" id="aiCopyBtn">
						<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
						<span id="aiCopyBtnText">Copy</span>
					</button>
				</div>
				<div class="ai-answer" id="aiAnswer"></div>
				<div class="ai-sources" id="aiSources" style="display:none;"></div>
			</div>
		</section>
		<?php } ?>

		<?php if(empty($decoded_faqs)) { ?>
			<div class="no-results show">No FAQs yet.</div>
		<?php } else { ?>
			<section class="controls">
				<div class="faq-search" id="faqSearch">
					<svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
					<input type="text" id="faqSearchInput" placeholder="Search all FAQs&hellip;" autocomplete="off" aria-label="Search all FAQs">
					<button type="button" class="search-clear" id="faqSearchClear" aria-label="Clear search" title="Clear search">
						<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
					</button>
				</div>
				<?php if(!empty($tag_chips)) { ?>
					<div class="tag-filter-wrap">
						<div class="tag-filter-head">
							<span class="tag-filter-label">
								<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>
								Tags
							</span>
							<div class="tag-search" id="tagSearch">
								<svg class="tag-search-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
								<input type="text" id="tagSearchInput" placeholder="Search tags&hellip;" autocomplete="off" aria-label="Search tags">
							</div>
						</div>
						<div class="tag-filter" id="tagFilter" role="group" aria-label="Filter by tag">
							<button type="button" class="tag-filter-chip is-active" data-tag-all aria-pressed="true">All</button>
							<?php foreach($tag_chips as $chip) { ?>
								<button type="button" class="tag-filter-chip<?php echo $chip['is_default'] ? '' : ' is-tag-hidden'; ?>" data-tag="<?php echo (int)$chip['id']; ?>" data-tag-default="<?php echo $chip['is_default'] ? '1' : '0'; ?>" data-tag-name="<?php echo htmlspecialchars(strtolower($chip['name']), ENT_QUOTES); ?>" aria-pressed="false"><?php echo htmlspecialchars($chip['name']); ?></button>
							<?php } ?>
							<span class="tag-filter-empty is-tag-hidden" id="tagFilterEmpty">No tags match &ldquo;<span id="tagFilterEmptyTerm"></span>&rdquo;</span>
						</div>
					</div>
				<?php } ?>
				<div class="search-count" id="searchCount" aria-live="polite"></div>
			</section>

			<div id="faqLibrary">
			<?php foreach($decoded_faqs as $entry) {
				$faq        = $entry['faq'];
				$items      = $entry['items'];
				$dest_names = ($faq->Destinations === null || $faq->Destinations === '') ? array() : explode('||', $faq->Destinations);
				// Lowercased haystack for the FAQ title + destinations, so a search
				// can surface a whole section by its title even when no sub-item
				// text matches.
				$section_blob = strtolower(trim((string)$faq->Title) . ' ' . implode(' ', $dest_names));
			?>
				<section class="faq-section" data-search="<?php echo htmlspecialchars($section_blob, ENT_QUOTES); ?>">
					<div class="faq-section-head">
						<h2 class="faq-section-title"><?php echo htmlspecialchars($faq->Title); ?></h2>
						<?php if(!empty($faq->Slug)) { ?>
							<a href="<?php echo htmlspecialchars(base_url('faq/' . rawurlencode($faq->Slug)), ENT_QUOTES); ?>" target="_blank" rel="noopener" class="faq-open-link" title="Open this FAQ on its own page" aria-label="Open this FAQ on its own page">
								<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
							</a>
						<?php } ?>
					</div>
					<?php if(!empty($dest_names)) { ?>
						<div class="faq-meta">
							<?php foreach($dest_names as $dest_name) { ?>
								<span class="chip chip-dest"><?php echo htmlspecialchars($dest_name); ?></span>
							<?php } ?>
						</div>
					<?php } ?>

					<?php if(empty($items)) { ?>
						<p class="empty-body">No additional details for this FAQ yet.</p>
					<?php } else { ?>
						<div class="a-list">
						<?php foreach($items as $item) {
							// Resolve per-item tag chips through the $tag_names map.
							// $item_tag_ids feeds the data-tags attribute the tag filter reads.
							$item_tags    = array();
							$item_tag_ids = array();
							if(isset($tag_names) && isset($item['tags']) && is_array($item['tags'])) {
								foreach($item['tags'] as $tid) {
									$tid = (int)$tid;
									if(isset($tag_names[$tid])) {
										$item_tags[]    = $tag_names[$tid];
										$item_tag_ids[] = $tid;
									}
								}
							}
							$q_text = trim((string)$item['q']);
							$a_text = trim((string)$item['a']);
							$label  = $q_text !== '' ? $q_text : 'Details';
							// Per-item reference links ({l,u}); labels feed search too.
							$item_links = (isset($item['links']) && is_array($item['links'])) ? $item['links'] : array();
							$link_labels = array();
							foreach($item_links as $lnk) { if(isset($lnk['l']) && trim((string)$lnk['l']) !== '') { $link_labels[] = $lnk['l']; } }
							// Lowercased haystack the client search filters against:
							// FAQ title + sub-question + sub-answer + tag names + link
							// labels, so a match on the parent FAQ's title still surfaces
							// its items.
							$search_blob = strtolower((string)$faq->Title . ' ' . $q_text . ' ' . $a_text . ' ' . implode(' ', $item_tags) . ' ' . implode(' ', $link_labels));
							$ud = isset($item['ud']) ? trim((string)$item['ud']) : '';
						?>
							<div class="a-item" data-search="<?php echo htmlspecialchars($search_blob, ENT_QUOTES); ?>" data-tags="<?php echo htmlspecialchars(implode(' ', $item_tag_ids), ENT_QUOTES); ?>">
								<button type="button" class="a-trigger" aria-expanded="false">
									<span class="a-q"><?php echo htmlspecialchars($label); ?></span>
									<svg class="a-chevron" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
								</button>
								<div class="a-panel">
									<div class="a-panel-inner">
										<?php if($a_text !== '') { ?>
											<div class="a-a"><?php echo nl2br(htmlspecialchars($a_text)); ?></div>
										<?php } ?>
										<?php if(!empty($item_links)) { ?>
											<div class="a-links">
												<?php foreach($item_links as $lnk) {
													$u = trim((string)(isset($lnk['u']) ? $lnk['u'] : ''));
													if($u === '') { continue; }
													$l = trim((string)(isset($lnk['l']) ? $lnk['l'] : ''));
													if($l === '') { $l = $u; }
												?>
													<a class="a-link" href="<?php echo htmlspecialchars($u, ENT_QUOTES); ?>" target="_blank" rel="noopener">
														<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
														<span><?php echo htmlspecialchars($l); ?></span>
													</a>
												<?php } ?>
											</div>
										<?php } ?>
										<?php if(!empty($item_tags)) { ?>
											<div class="faq-meta item-tags">
												<?php foreach($item_tags as $tag_name) { ?>
													<span class="chip chip-tag"><?php echo htmlspecialchars($tag_name); ?></span>
												<?php } ?>
											</div>
										<?php } ?>
										<?php if($ud !== '') { $ud_ts = strtotime($ud); ?>
											<span class="a-meta">
												<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><polyline points="12 7 12 12 15.5 14"></polyline></svg>
												Updated <?php echo htmlspecialchars($ud_ts ? date('j M Y', $ud_ts) : $ud); ?>
											</span>
										<?php } ?>
									</div>
								</div>
							</div>
						<?php } ?>
						</div>
					<?php } ?>
				</section>
			<?php } ?>
			</div>
			<div class="no-results" id="noResults">No FAQs match your search.</div>
		<?php } ?>

		<footer>
			<span>&copy; <?php echo date('Y'); ?> HolidayGoGoGo</span>
			<span><?php echo count($decoded_faqs); ?> FAQ<?php echo count($decoded_faqs) === 1 ? '' : 's'; ?></span>
		</footer>
	</main>

	<script>
		// ---- AI answer box: post the question to Faq/Ai_Search, render the reply ----
		(function () {
			var form   = document.getElementById('aiAskForm');
			if (!form) return;
			var input     = document.getElementById('aiAskInput');
			var btn       = document.getElementById('aiAskBtn');
			var btnText   = document.getElementById('aiAskBtnText');
			var resultBox = document.getElementById('aiResult');
			var answerEl  = document.getElementById('aiAnswer');
			var sourcesEl = document.getElementById('aiSources');
			var errorEl   = document.getElementById('aiError');
			var copyBtn   = document.getElementById('aiCopyBtn');
			var copyText  = document.getElementById('aiCopyBtnText');
			var countEl   = document.getElementById('aiAskCount');
			var endpoint  = <?php echo json_encode(base_url('Faq/Ai_Search')); ?>;
			var MAX_LEN   = 250;

			function updateCount() {
				var n = (input.value || '').length;
				if (countEl) {
					countEl.textContent = n + ' / ' + MAX_LEN;
					countEl.classList.toggle('is-max', n >= MAX_LEN);
				}
			}
			input.addEventListener('input', updateCount);
			updateCount();

			function setLoading(on) {
				btn.disabled = on;
				btnText.textContent = on ? 'Thinking…' : 'Ask';
			}
			function showError(msg) {
				errorEl.textContent = msg;
				errorEl.classList.add('show');
				resultBox.classList.remove('show');
			}
			function escapeHtml(s) {
				return String(s).replace(/[&<>"']/g, function (c) {
					return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
				});
			}

			form.addEventListener('submit', function (e) {
				e.preventDefault();
				var q = (input.value || '').trim();
				errorEl.classList.remove('show');
				if (q.length < 3) {
					showError('Please type a question (at least 3 characters).');
					return;
				}
				setLoading(true);
				resultBox.classList.remove('show');

				var body = new URLSearchParams();
				body.append('question', q);

				fetch(endpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
					body: body.toString(),
					credentials: 'same-origin'
				}).then(function (r) {
					return r.json().catch(function () { throw new Error('Unexpected server response.'); });
				}).then(function (data) {
					setLoading(false);
					if (!data || !data.ok) {
						showError((data && data.error) ? data.error : 'Something went wrong. Please try again.');
						return;
					}
					if (!data.found || !data.answer) {
						answerEl.classList.add('is-muted');
						answerEl.textContent = (data.answer && data.answer.trim())
							? data.answer
							: 'No matching FAQ found. Please check with the team before replying.';
						sourcesEl.style.display = 'none';
						copyBtn.style.display = (data.answer && data.answer.trim()) ? '' : 'none';
						resultBox.classList.add('show');
						return;
					}
					answerEl.classList.remove('is-muted');
					answerEl.textContent = data.answer;
					copyBtn.style.display = '';
					if (data.sources && data.sources.length) {
						sourcesEl.innerHTML = '<strong>Based on:</strong> ' +
							data.sources.map(function (s) { return escapeHtml(s); }).join(' · ');
						sourcesEl.style.display = '';
					} else {
						sourcesEl.style.display = 'none';
					}
					resultBox.classList.add('show');
				}).catch(function (err) {
					setLoading(false);
					showError(err && err.message ? err.message : 'Could not reach the server.');
				});
			});

			copyBtn.addEventListener('click', function () {
				var text = answerEl.textContent || '';
				var done = function () {
					copyText.textContent = 'Copied!';
					setTimeout(function () { copyText.textContent = 'Copy'; }, 1400);
				};
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(done).catch(function () { fallback(text); done(); });
				} else {
					fallback(text); done();
				}
				function fallback(t) {
					var ta = document.createElement('textarea');
					ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0';
					document.body.appendChild(ta); ta.focus(); ta.select();
					try { document.execCommand('copy'); } catch (e) {}
					document.body.removeChild(ta);
				}
			});
		})();

		(function () {
			var library = document.getElementById('faqLibrary');
			if (!library) return;
			var sections = Array.prototype.slice.call(library.querySelectorAll('.faq-section'));
			var items = Array.prototype.slice.call(library.querySelectorAll('.a-item'));

			function sizePanel(item) {
				var panel = item.querySelector('.a-panel');
				panel.style.maxHeight = item.classList.contains('is-open') ? panel.scrollHeight + 'px' : '0px';
			}
			function openItem(item) {
				var t = item.querySelector('.a-trigger');
				item.classList.add('is-open');
				t.setAttribute('aria-expanded', 'true');
				sizePanel(item);
			}
			function closeItem(item) {
				var t = item.querySelector('.a-trigger');
				item.classList.remove('is-open');
				t.setAttribute('aria-expanded', 'false');
				item.querySelector('.a-panel').style.maxHeight = '0px';
			}

			items.forEach(function (item) {
				item.querySelector('.a-trigger').addEventListener('click', function () {
					if (item.classList.contains('is-open')) closeItem(item); else openItem(item);
				});
			});

			// Re-measure any open panel after a resize reflows its content.
			var resizeTimer;
			window.addEventListener('resize', function () {
				clearTimeout(resizeTimer);
				resizeTimer = setTimeout(function () {
					items.forEach(function (item) { if (item.classList.contains('is-open')) sizePanel(item); });
				}, 120);
			});

			var input = document.getElementById('faqSearchInput');
			var wrap = document.getElementById('faqSearch');
			var clearBtn = document.getElementById('faqSearchClear');
			var countEl = document.getElementById('searchCount');
			var noResults = document.getElementById('noResults');

			// Active tag ids (as strings). Empty = no tag filter. AND semantics:
			// an item shows only if it carries EVERY active tag.
			var activeTags = [];
			function matchesTags(item) {
				if (activeTags.length === 0) return true;
				var raw = (item.getAttribute('data-tags') || '').trim();
				if (raw === '') return false;
				var ids = raw.split(' ');
				for (var i = 0; i < activeTags.length; i++) {
					if (ids.indexOf(activeTags[i]) === -1) return false;
				}
				return true;
			}

			// ---- Keyword highlight ----
			// Wrap every occurrence of the query in <mark> across an element's text
			// nodes only, so we never break the answer's <br> markup. Clearing first
			// makes it idempotent as the user types.
			function escapeRx(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
			function clearHl(el) {
				var marks = el.querySelectorAll('mark.faq-hl');
				for (var i = 0; i < marks.length; i++) {
					var m = marks[i];
					m.parentNode.replaceChild(document.createTextNode(m.textContent), m);
				}
				el.normalize();
			}
			function highlight(el, q) {
				clearHl(el);
				if (!q) return;
				var rx = new RegExp(escapeRx(q), 'gi');
				var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null, false);
				var nodes = [], n;
				while ((n = walker.nextNode())) nodes.push(n);
				nodes.forEach(function (node) {
					var text = node.nodeValue;
					rx.lastIndex = 0;
					if (!rx.test(text)) return;
					rx.lastIndex = 0;
					var frag = document.createDocumentFragment(), last = 0, m;
					while ((m = rx.exec(text)) !== null) {
						if (m.index > last) frag.appendChild(document.createTextNode(text.slice(last, m.index)));
						var mark = document.createElement('mark');
						mark.className = 'faq-hl';
						mark.textContent = m[0];
						frag.appendChild(mark);
						last = m.index + m[0].length;
						if (m[0].length === 0) rx.lastIndex++;
					}
					if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
					node.parentNode.replaceChild(frag, node);
				});
			}
			function highlightFields(root, selector, q) {
				var fields = root.querySelectorAll(selector);
				for (var f = 0; f < fields.length; f++) {
					if (q !== '') highlight(fields[f], q); else clearHl(fields[f]);
				}
			}

			function runSearch() {
				var q = input.value.trim().toLowerCase();
				wrap.classList.toggle('has-value', q !== '');
				var filtering = q !== '' || activeTags.length > 0;
				var matches = 0;

				sections.forEach(function (section) {
					var sectionItems = Array.prototype.slice.call(section.querySelectorAll('.a-item'));
					var visibleItems = 0;
					sectionItems.forEach(function (item) {
						var hit = (q === '' || item.getAttribute('data-search').indexOf(q) !== -1) && matchesTags(item);
						item.classList.toggle('is-hidden', !hit);
						// Auto-expand on a text query so answers are visible; tag-only
						// filtering keeps items collapsed. Collapse when nothing applies.
						if (hit && q !== '') openItem(item); else closeItem(item);
						// Highlight the keyword in visible hits; strip highlights otherwise.
						highlightFields(item, '.a-q, .a-a', hit && q !== '' ? q : '');
						if (hit) visibleItems++;
					});

					var sectionHit;
					if (sectionItems.length > 0) {
						sectionHit = visibleItems > 0;
					} else {
						// A detail-less FAQ has no items to match; surface it only on a
						// title/destination text match, and never under a tag filter.
						sectionHit = activeTags.length === 0 && (q === '' || section.getAttribute('data-search').indexOf(q) !== -1);
					}
					section.classList.toggle('is-hidden', !sectionHit);
					// Highlight the section title on a visible text match too.
					highlightFields(section, '.faq-section-title', sectionHit && q !== '' ? q : '');
					if (sectionHit) matches += (sectionItems.length > 0 ? visibleItems : 1);
				});

				if (!filtering) {
					countEl.textContent = '';
					noResults.classList.remove('show');
				} else {
					noResults.classList.toggle('show', matches === 0);
					countEl.innerHTML = matches === 0 ? '' :
						'<strong>' + matches + '</strong> ' + (matches === 1 ? 'match' : 'matches');
				}
			}

			// ---- Tag filter chips ----
			var tagBar = document.getElementById('tagFilter');
			if (tagBar) {
				var tagChips = Array.prototype.slice.call(tagBar.querySelectorAll('.tag-filter-chip[data-tag]'));
				var allChip = tagBar.querySelector('.tag-filter-chip[data-tag-all]');
				function syncAll() {
					if (allChip) allChip.classList.toggle('is-active', activeTags.length === 0);
				}
				tagChips.forEach(function (chip) {
					chip.addEventListener('click', function () {
						var id = chip.getAttribute('data-tag');
						var idx = activeTags.indexOf(id);
						var nowActive = idx === -1;
						if (nowActive) activeTags.push(id); else activeTags.splice(idx, 1);
						chip.classList.toggle('is-active', nowActive);
						chip.setAttribute('aria-pressed', nowActive ? 'true' : 'false');
						syncAll();
						runSearch();
						filterTagChips();
					});
				});
				if (allChip) {
					allChip.addEventListener('click', function () {
						activeTags = [];
						tagChips.forEach(function (c) {
							c.classList.remove('is-active');
							c.setAttribute('aria-pressed', 'false');
						});
						syncAll();
						runSearch();
						filterTagChips();
					});
				}

				// ---- Tag search: reveal / hide chips by name ----
				// Only the curated (default) chips show up-front. Typing here reveals
				// ANY matching tag (default or not); clearing hides the non-defaults
				// again. Active chips always stay visible so they can be toggled off.
				// When NO tag is flagged default, the bar shows just "All" and every
				// tag is reached through the search box.
				var tagSearch    = document.getElementById('tagSearchInput');
				var tagEmpty     = document.getElementById('tagFilterEmpty');
				var tagEmptyTerm = document.getElementById('tagFilterEmptyTerm');
				function filterTagChips() {
					var q = (tagSearch ? tagSearch.value : '').trim().toLowerCase();
					var anyVisible = false;
					tagChips.forEach(function (chip) {
						var isDefault = chip.getAttribute('data-tag-default') === '1';
						var isActive  = chip.classList.contains('is-active');
						var name      = chip.getAttribute('data-tag-name') || '';
						var show = q === ''
							? (isDefault || isActive)
							: (name.indexOf(q) !== -1 || isActive);
						chip.classList.toggle('is-tag-hidden', !show);
						if (show) anyVisible = true;
					});
					if (tagEmpty) {
						var noHits = q !== '' && !anyVisible;
						tagEmpty.classList.toggle('is-tag-hidden', !noHits);
						if (noHits && tagEmptyTerm) tagEmptyTerm.textContent = tagSearch.value.trim();
					}
				}
				if (tagSearch) {
					tagSearch.addEventListener('input', filterTagChips);
					tagSearch.addEventListener('keydown', function (e) {
						if (e.key === 'Escape') { tagSearch.value = ''; filterTagChips(); }
					});
				}
				filterTagChips();
			}

			if (input) {
				input.addEventListener('input', runSearch);
				input.addEventListener('keydown', function (e) {
					if (e.key === 'Escape') { input.value = ''; runSearch(); }
				});
			}
			if (clearBtn) {
				clearBtn.addEventListener('click', function () {
					input.value = '';
					runSearch();
					input.focus();
				});
			}
		})();
	</script>
</body>
</html>
