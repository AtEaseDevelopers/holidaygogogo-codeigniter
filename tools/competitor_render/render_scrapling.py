#!/usr/bin/env python3
"""Scrapling renderer with the same stdout JSON contract as render.js."""
import contextlib
import json
import os
import sys
from urllib.parse import urlsplit


class RenderConfigError(ValueError):
    pass


def env_int(name, default, minimum=0):
    try:
        value = int(os.environ.get(name, str(default)))
    except ValueError:
        raise RenderConfigError("%s must be an integer" % name)
    if value < minimum:
        raise RenderConfigError("%s must be >= %s" % (name, minimum))
    return value


def render(url, fetcher=None):
    out = {"url": url, "status": 0, "html": "", "apis": []}
    try:
        parsed = urlsplit(url)
        if parsed.scheme not in ("http", "https") or not parsed.hostname:
            raise RenderConfigError("invalid url")
        if fetcher is None:
            if sys.version_info < (3, 10):
                raise RenderConfigError("Scrapling requires Python 3.10+; configure PYTHON_BIN")
            from scrapling.fetchers import StealthyFetcher
            fetcher = StealthyFetcher

        max_apis = env_int("RENDER_MAX_APIS", 40)
        seen = set()

        def capture(response):
            try:
                if len(out["apis"]) >= max_apis:
                    return
                if "json" not in response.headers.get("content-type", "").lower():
                    return
                if response.url in seen:
                    return
                seen.add(response.url)
                body = response.text()
                if 40 < len(body) < 2000000:
                    out["apis"].append({"url": response.url, "body": body})
            except Exception:
                pass  # One unreadable/aborted API must not fail the page.

        def setup(page):
            page.on("response", capture)
            if os.environ.get("RENDER_BLOCK_ASSETS", "1") != "0":
                blocked = set(filter(None, (
                    part.strip() for part in os.environ.get(
                        "RENDER_BLOCK_TYPES", "image,media,font").split(","))))

                def route_request(route):
                    if route.request.resource_type in blocked:
                        route.abort()
                    else:
                        route.continue_()

                page.route("**/*", route_request)

        def settle(page):
            try:
                page.wait_for_load_state("networkidle", timeout=8000)
            except Exception:
                pass
            for _ in range(4):
                try:
                    page.evaluate("window.scrollTo(0, document.body.scrollHeight)")
                    page.wait_for_timeout(700)
                except Exception:
                    break
            try:
                page.wait_for_load_state("networkidle", timeout=4000)
            except Exception:
                pass

        options = dict(
            headless=True,
            timeout=env_int("RENDER_TIMEOUT_MS", 25000, 1),
            retries=1,  # Match the current renderer: one navigation per attempt.
            solve_cloudflare=os.environ.get("RENDER_STEALTH", "1") != "0",
            disable_resources=False,  # Use the same asset filter as render.js.
            google_search=False,
            locale="en-US",
            timezone_id="Asia/Kuala_Lumpur",
            page_setup=setup,
            page_action=settle,
        )
        if os.environ.get("RENDER_UA"):
            options["useragent"] = os.environ["RENDER_UA"]
        proxy = os.environ.get("COMPETITOR_PROXY", "")
        if proxy:
            options["proxy"] = {"server": proxy}
            auth = os.environ.get("COMPETITOR_PROXY_AUTH", "")
            if auth:
                username, _, password = auth.partition(":")
                options["proxy"].update(username=username, password=password)

        response = fetcher.fetch(url, **options)
        out["status"] = int(response.status)
        body = response.body
        out["html"] = body.decode("utf-8", errors="replace") if isinstance(body, bytes) else str(body)
    except Exception as exc:
        # Do not leak proxy credentials or browser diagnostics into the JSON/logs.
        out["error"] = str(exc) if isinstance(exc, RenderConfigError) else type(exc).__name__
    return out


def main():
    # Dependencies may log to stdout; reserve stdout exclusively for the contract.
    with contextlib.redirect_stdout(sys.stderr):
        result = render(sys.argv[1] if len(sys.argv) == 2 else "")
    sys.stdout.write(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
