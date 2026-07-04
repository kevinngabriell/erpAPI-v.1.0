# CORS Architecture & Troubleshooting

## TL;DR

**CORS is owned entirely by nginx, not PHP.** `general.php` does not set any
`Access-Control-Allow-*` headers. If you see a CORS error in production, the
bug is almost always one of:

1. The endpoint isn't routed through the `/venken-api/` nginx location (so the
   CORS `add_header` block never applies), or
2. Something in PHP is *also* setting `Access-Control-Allow-Origin`, causing a
   duplicate header (browser CORS error: `MultipleAllowOriginValues`), or
3. The endpoint's own PHP file short-circuits `OPTIONS` in a way that's
   inconsistent with the rest (harmless today only because nginx intercepts
   `OPTIONS` before PHP ever runs — see "Known inconsistency" below).

---

## Where CORS actually lives

**nginx** (`/etc/nginx/sites-available/getmovira.com.conf` on the VPS),
inside the `/venken-api/` location block:

```nginx
# Venken API
location ~ ^/venken-api/(?<php_path>.+\.php)$ {
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /home/ubuntu/apps/erpAPI-v.1.0/$php_path;
    fastcgi_param APP_ENV production;
    include fastcgi_params;
    fastcgi_param Authorization $http_authorization;

    #CORS Setting
    add_header Access-Control-Allow-Origin $http_origin always;
    add_header Access-Control-Allow-Credentials "true" always;
    add_header Access-Control-Allow-Methods "GET,POST,PUT,PATCH,DELETE,OPTIONS" always;
    add_header Access-Control-Allow-Headers "Authorization,Content-Type,Accept,Origin,X-Requested-With" always;
    add_header Access-Control-Max-Age 86400 always;

    # Preflight
    if ($request_method = OPTIONS) {
        return 204;
    }
}
```

Key points:

- `add_header ... always` applies the header to **every** response nginx
  proxies through this location, including error responses and the `OPTIONS`
  preflight — this is the single source of truth for CORS in production.
- The `if ($request_method = OPTIONS) { return 204; }` block means **PHP
  never even runs for preflight requests** in production — nginx answers the
  preflight itself. Any `OPTIONS` handling inside the PHP files is dead code
  in production; it only matters if you run the app locally without this
  nginx layer in front (e.g. `php -S localhost:8000`).
- `fastcgi_param APP_ENV production;` tells PHP it's running in production.
  This matters for anything else in the codebase that branches on `APP_ENV`
  (error display, logging, etc.) — CORS itself no longer depends on it.

**PHP (`general.php`)** does not set any CORS headers. It only handles a
bare `OPTIONS` → `200` short-circuit as a local-dev safety net.

**Local development**, without nginx in front, has no CORS layer at all.
If you need to hit the API from a local frontend dev server, add a local
nginx config mirroring the block above, or a dev-only proxy in the frontend
tooling (e.g. Vite/CRA proxy) — don't re-add `header()` calls to `general.php`
for this; see the RCA below for why that broke things.

---

## Root Cause Analysis — 2026-07-04 CORS outage

**Symptom:** New/refactored master-data endpoints (`currency.php`, `uom.php`,
`customer.php`, `ppn.php`, `bank-account.php`, `account-code.php`) failed in
production with browser CORS errors on the actual GET/POST response, even
though the `OPTIONS` preflight for the same request succeeded (204). Older,
pre-refactor endpoints (`gettopsalesorder.php` and similar) were unaffected.

**Root causes (two independent bugs):**

1. **Duplicate `Access-Control-Allow-Origin` header.**
   `general.php` (added in commit `6d11484`) called `header('Access-Control-Allow-Origin: *')`
   etc. whenever `APP_ENV !== 'production'`. Nothing in the deploy pipeline
   ([.github/workflows/deploy.yml](.github/workflows/deploy.yml)) or the nginx
   config (at the time) ever set `APP_ENV` for PHP-FPM, so it silently
   defaulted to `'development'` in production too — meaning **PHP was always
   adding its own CORS headers**, on top of the ones nginx's `add_header ...
   always` was already adding at the reverse-proxy layer. Two
   `Access-Control-Allow-Origin` header lines on one response is invalid per
   the Fetch/CORS spec, and browsers reject it outright
   (`MultipleAllowOriginValues`). This is why the preflight (answered
   entirely by nginx, PHP never ran) looked fine, while the actual response
   (which did run PHP) failed.

2. **Inconsistent OPTIONS handling in 3 files.** `currency.php`,
   `bank-account.php`, and `account-code.php` still had a leftover
   `if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }`
   block *before* `require_once '../../general.php'` — a pattern the commit
   message of `6d11484` claimed to have removed from "all 15 master/* files"
   but missed these three. Harmless once nginx intercepts `OPTIONS` before
   PHP runs, but it left the codebase inconsistent with the other 12
   master-data files, and would have masked/duplicated CORS logic again if
   `general.php` ever regained header-setting responsibility.

**Why "the VPS handles CORS" seemed wrong at first:** it was actually
correct — nginx was doing its job the whole time. The bug was that PHP
*also* thought it was responsible, and two owners of the same header broke
the contract. The fix was to make nginx the sole owner and strip all CORS
header logic out of PHP, rather than trying to gate PHP's headers around
nginx's.

**Fix applied:**
- Removed all `header('Access-Control-Allow-*', ...)` calls from
  `general.php`. PHP no longer sets CORS headers under any environment.
- Added `fastcgi_param APP_ENV production;` to the nginx `/venken-api/`
  location block so PHP correctly knows its environment for non-CORS
  purposes.
- Left the bare `OPTIONS → 200 exit` in `general.php` as a no-op fallback for
  local dev without nginx in front.

**Known inconsistency (not a bug, just noted for future cleanup):**
`currency.php`, `bank-account.php`, and `account-code.php` still check for
`OPTIONS` and exit *before* requiring `general.php`, while the other 12
master-data files require `general.php` first and let it own the `OPTIONS`
short-circuit. Functionally identical today (nginx answers `OPTIONS` before
either code path runs in production), but if this project ever drops the
nginx-level preflight handling, these 3 files should be reconciled with the
other 12.

---

## Diagnosing a future CORS error

1. **Look at the Network tab error text**, not just "CORS error":
   - `MultipleAllowOriginValues` / "contains multiple values" → something is
     double-adding `Access-Control-Allow-Origin`. Check both nginx
     (`add_header` in the matching `location` block) and PHP
     (`grep -rn "Access-Control-Allow-Origin" .` in this repo) — there should
     be exactly **one** place setting it.
   - No CORS header at all / "No Access-Control-Allow-Origin header is
     present" → the request isn't hitting a location that sets it. Confirm
     the request URL actually matches the nginx `location ~
     ^/venken-api/(?<php_path>.+\.php)$` regex — a typo'd path, a missing
     `.php` extension, or a route added outside `/venken-api/` will bypass it
     entirely.
   - Preflight (`OPTIONS`) succeeds but the actual request fails → the actual
     request reaches a code path the preflight didn't (e.g. PHP adding
     headers only on non-OPTIONS requests). Compare what runs for `OPTIONS`
     vs. the real method.

2. **Confirm which layer is actually responding.** `curl -i -X OPTIONS
   https://venken.getmovira.com/venken-api/master/finance/currency.php` and
   `curl -i https://.../currency.php` from the VPS itself — if nginx is
   answering the `OPTIONS` call (204, no PHP-generated body), PHP is not in
   the picture for preflight at all.

3. **Never re-add CORS `header()` calls to PHP** while nginx's `/venken-api/`
   block still has its `#CORS Setting` section — that's exactly how this
   incident happened. If PHP-level CORS is ever needed again (e.g. running
   without nginx), remove the nginx `add_header` lines first, don't run both.

4. **New master-data/endpoint files:** just `require_once
   '../../general.php'` first, with no CORS or `OPTIONS` handling of your own
   — consistent with 12 of the 15 master files. Don't add a bespoke
   `OPTIONS` early-exit block; it doesn't help in production and drifts the
   codebase out of sync (see "Known inconsistency" above).
