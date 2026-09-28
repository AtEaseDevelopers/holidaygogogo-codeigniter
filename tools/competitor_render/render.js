#!/usr/bin/env node
/**
 * Competitor-Analysis render service (Playwright).
 *
 * Renders one URL in a real headless Chromium — executes JS, waits for network to
 * settle, scrolls to trigger lazy-loading, and CAPTURES the JSON XHR/fetch responses
 * the SPA makes (the product data that never lands in the DOM). Prints a single JSON
 * object to stdout so the PHP crawler can read the rendered DOM AND the raw APIs:
 *
 *   { "url": "...", "status": 200, "html": "<rendered DOM>", "apis": [ { "url": "...", "body": "..." } ] }
 *
 * STEALTH: applies the standard evasions (no AutomationControlled flag, webdriver/
 * languages/plugins/chrome patched, real UA + locale + viewport) and WAITS OUT a
 * Cloudflare "Just a moment…" JS challenge — the non-interactive kind auto-solves in
 * a few seconds once the browser looks real. Hard CAPTCHAs still won't pass. Toggle
 * with RENDER_STEALTH=0.
 *
 * Usage:  node render.js "<url>"
 * Env:    RENDER_TIMEOUT_MS (25000), RENDER_UA, RENDER_MAX_APIS (40),
 *         RENDER_STEALTH (1), RENDER_CF_WAIT_MS (15000)
 *
 * Setup:  cd tools/competitor_render && npm install && npx playwright install chromium
 * Degrades gracefully: on any failure it prints {error:...} and the PHP side falls
 * back to the plain Chrome --dump-dom render.
 */
'use strict';

(async () => {
  const url = process.argv[2];
  const out = { url: url || '', status: 0, html: '', apis: [] };
  if (!url || !/^https?:\/\//i.test(url)) {
    out.error = 'invalid url';
    process.stdout.write(JSON.stringify(out));
    return;
  }

  const TIMEOUT = parseInt(process.env.RENDER_TIMEOUT_MS || '25000', 10);
  const MAX_APIS = parseInt(process.env.RENDER_MAX_APIS || '40', 10);
  const STEALTH = process.env.RENDER_STEALTH !== '0';
  const CF_WAIT = parseInt(process.env.RENDER_CF_WAIT_MS || '15000', 10);
  const UA = process.env.RENDER_UA
    || 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
     + '(KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
  // Optional outbound proxy (rotating/residential IP to get past hard IP blocks). Set by the
  // PHP worker from COMPETITOR_PROXY / COMPETITOR_PROXY_AUTH. Off when unset.
  const PROXY = process.env.COMPETITOR_PROXY || '';
  const PROXY_AUTH = process.env.COMPETITOR_PROXY_AUTH || '';

  let chromium;
  try {
    ({ chromium } = require('playwright'));
  } catch (e) {
    out.error = 'playwright not installed';
    process.stdout.write(JSON.stringify(out));
    return;
  }

  // Does the current page still show a bot-challenge interstitial (Cloudflare "Just a
  // moment…", Turnstile) rather than real content? Used to wait it out.
  const isChallenge = async (page) => {
    try {
      const t = (await page.title()) || '';
      if (/just a moment|attention required|verifying you are human|checking your browser/i.test(t)) {
        return true;
      }
      const h = await page.content();
      return /challenges\.cloudflare\.com|cf-chl|_cf_chl_|cf-turnstile/i.test(h)
        && !/<(main|article)\b|product|itinerary|tour|package|pakej/i.test(h);
    } catch (e) { return false; }
  };

  let browser;
  try {
    const launchOpts = {
      headless: true,
      args: [
        '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu',
        // Drop the CDP automation banner CF/anti-bot fingerprints on.
        '--disable-blink-features=AutomationControlled',
      ],
    };
    if (PROXY) {
      launchOpts.proxy = { server: PROXY };
      if (PROXY_AUTH) {
        const i = PROXY_AUTH.indexOf(':');
        launchOpts.proxy.username = i >= 0 ? PROXY_AUTH.slice(0, i) : PROXY_AUTH;
        launchOpts.proxy.password = i >= 0 ? PROXY_AUTH.slice(i + 1) : '';
      }
    }
    browser = await chromium.launch(launchOpts);
    const ctx = await browser.newContext({
      userAgent: UA,
      ignoreHTTPSErrors: true,
      locale: 'en-US',
      timezoneId: 'Asia/Kuala_Lumpur',
      viewport: { width: 1366, height: 900 },
      extraHTTPHeaders: {
        'Accept-Language': 'en-US,en;q=0.9',
        'Upgrade-Insecure-Requests': '1',
      },
    });

    // Standard stealth shim — patch the properties headless Chromium leaks that
    // anti-bot scripts read (navigator.webdriver, languages, plugins, window.chrome).
    if (STEALTH) {
      await ctx.addInitScript(() => {
        Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
        Object.defineProperty(navigator, 'languages', { get: () => ['en-US', 'en'] });
        Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3, 4, 5] });
        window.chrome = { runtime: {} };
        const q = window.navigator.permissions && window.navigator.permissions.query;
        if (q) {
          window.navigator.permissions.query = (p) =>
            (p && p.name === 'notifications')
              ? Promise.resolve({ state: Notification.permission })
              : q(p);
        }
      });
    }

    const page = await ctx.newPage();

    // TRIM bandwidth: block heavy resources we never use (images, media, fonts) — the render
    // still executes JS and builds the DOM (which is what we extract), but downloads a small
    // fraction of the bytes (~5-10x less). Important behind a metered proxy. Scripts + XHR/
    // fetch are kept so JS runs and the SPA's data still loads. RENDER_BLOCK_ASSETS=0 disables;
    // RENDER_BLOCK_TYPES overrides the list (comma-separated Playwright resourceTypes).
    if (process.env.RENDER_BLOCK_ASSETS !== '0') {
      const blocked = new Set(
        (process.env.RENDER_BLOCK_TYPES || 'image,media,font').split(',').map((s) => s.trim()).filter(Boolean)
      );
      await page.route('**/*', (route) => {
        return blocked.has(route.request().resourceType()) ? route.abort() : route.continue();
      });
    }

    // Capture JSON API responses (the data behind the SPA) — deduped, size-bounded.
    const seen = new Set();
    page.on('response', async (resp) => {
      try {
        if (out.apis.length >= MAX_APIS) return;
        const ct = String(resp.headers()['content-type'] || '');
        if (!/json/i.test(ct)) return;
        const u = resp.url();
        if (seen.has(u)) return;
        seen.add(u);
        const body = await resp.text();
        if (body && body.length > 40 && body.length < 2000000) {
          out.apis.push({ url: u, body: body });
        }
      } catch (e) { /* ignore a single unreadable response */ }
    });

    const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: TIMEOUT });
    out.status = resp ? resp.status() : 0;
    try { await page.waitForLoadState('networkidle', { timeout: 8000 }); } catch (e) {}

    // Cloudflare/Turnstile non-interactive challenge: wait for it to clear (it redirects
    // to the real page once the JS check passes). Poll until the interstitial is gone.
    if (STEALTH && (await isChallenge(page))) {
      out.challenge = true;
      const deadline = Date.now() + CF_WAIT;
      while (Date.now() < deadline) {
        try { await page.waitForTimeout(1500); } catch (e) { break; }
        if (!(await isChallenge(page))) { out.challenge_cleared = true; break; }
      }
      try { await page.waitForLoadState('networkidle', { timeout: 5000 }); } catch (e) {}
    }

    // Bounded scroll: trigger lazy-loaded product cards / infinite scroll.
    for (let i = 0; i < 4; i++) {
      try {
        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await page.waitForTimeout(700);
      } catch (e) { break; }
    }
    try { await page.waitForLoadState('networkidle', { timeout: 4000 }); } catch (e) {}

    out.html = await page.content();
    out.status = out.status || 200;
  } catch (e) {
    out.error = String((e && e.message) || e);
  } finally {
    if (browser) { try { await browser.close(); } catch (e) {} }
  }

  process.stdout.write(JSON.stringify(out));
})();
