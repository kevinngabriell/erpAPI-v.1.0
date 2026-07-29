# Running the API locally (no CORS errors, no 404s)

This is the one correct way to run this API on your machine. Skipping the
nginx layer, or hitting the PHP port directly, is what causes the CORS
errors and 404s people keep re-discovering — see "Why this exists" at the
bottom.

## The two moving pieces

| Piece | Command | Port | Role |
|---|---|---|---|
| nginx | already running as a brew service | `8888` | Owns CORS, rewrites `/venken-api/v2/...` → `/v2/...`, answers `OPTIONS` preflight |
| PHP dev server | `php -S 127.0.0.1:8300 -t . router.php` | `8300` | Runs the actual app code. **Internal only** — never call this port from a browser/frontend |

**Your frontend's API base URL must always be `http://localhost:8888/venken-api`.**
Never `:8300`.

## Starting everything

```bash
# 1. nginx (only if not already running)
brew services start nginx

# 2. PHP dev server — from the repo root, must include router.php
php -S 127.0.0.1:8300 -t . router.php
```

Run step 2 in its own terminal tab (or with `&` / `nohup` if you want it
backgrounded). Leave it running for the whole dev session.

## Stopping it

```bash
# find it
ps aux | grep "php -S" | grep -v grep

# kill by PID
kill <pid>
```

nginx can stay running — it's a shared system service, not something you
start/stop per session.

## Why `router.php` is required

`php -S` with no router script can only serve literal files that exist on
disk. Pretty URLs like `/v2/account/login` aren't real files — only
`v2/index.php` exists, and it does its own internal parsing of
`$_SERVER['REQUEST_URI']`. Without a router script, PHP's built-in server
404s on every `v2` route before your code ever runs (you'll see PHP's own
plain-HTML "Not Found" page, not this app's JSON 404).

[`router.php`](../router.php) (repo root) fixes this: it serves real files
as-is (legacy `/{module}/index.php` v1 endpoints, static assets) and falls
back to `v2/index.php` for everything else, mirroring what nginx does in
production/local for the two different route shapes.

## Why nginx is required too

CORS is owned entirely by nginx, not PHP — see
[`CORS_TROUBLESHOOTING.md`](../CORS_TROUBLESHOOTING.md). The local nginx
config for this is at
`/opt/homebrew/etc/nginx/servers/venken-api-local.conf` (loaded
automatically via `include servers/*;` in `nginx.conf`). It:

- Proxies `/venken-api/v2/{module}/...` → `127.0.0.1:8300/v2/{module}/...`
- Proxies `/venken-api/{module}/index.php` → `127.0.0.1:8300/{module}/index.php` (legacy v1)
- Strips any CORS headers PHP itself adds (`proxy_hide_header`) and adds its
  own single, consistent set — avoiding the "duplicate
  `Access-Control-Allow-*`" bug that breaks CORS in real browsers (curl
  doesn't care about duplicate headers, browsers do)
- Answers `OPTIONS` preflight directly with `204` — PHP never runs for
  preflight

If you hit `:8300` directly, none of this applies: no CORS headers at all,
and no router-driven rewriting unless you're using `router.php` (which only
solves the routing half, not CORS).

## Verifying it's working

```bash
# Preflight should be 204 with CORS headers, not 404
curl -i -X OPTIONS 'http://localhost:8888/venken-api/v2/account/login' \
  -H 'Origin: http://localhost:3000' -H 'Access-Control-Request-Method: POST'

# Real request should return this app's JSON shape, not PHP's HTML 404 page
curl -i 'http://localhost:8888/venken-api/v2/account/login' \
  -H 'Content-Type: application/json' \
  -H 'Origin: http://localhost:3000' \
  --data-raw '{"email":"you@example.com","password":"..."}'
```

## Common mistakes

- **Frontend pointed at `:8300` instead of `:8888`** → CORS error in browser,
  curl looks fine. This is the #1 recurring cause.
- **PHP dev server started without `router.php`** → every `/v2/...` route
  404s with PHP's own HTML error page (not this app's JSON 404).
- **Wrong module name in the URL** — the URL segment is the `case` label in
  [`v2/index.php`](../v2/index.php)'s switch statement, not the directory
  name. E.g. `my-permissions` lives in the `auth/` directory on disk but is
  routed under the `account` module: `/v2/account/my-permissions`, not
  `/v2/auth/my-permissions`.

## Why this exists

Written after repeatedly diagnosing the same two failures locally: a
frontend calling `:8300` directly (CORS error, no preflight headers), and
the PHP dev server running without `router.php` (404 on every pretty `v2`
URL). Both were being re-discovered from scratch each session — this doc
is the fix for that.
