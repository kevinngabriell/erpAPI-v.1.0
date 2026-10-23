# Shared Auth Guide — Integrating with `movira_core_dev`

This document explains how **this project (Aluria / v2)** implements login,
register, forgot-password, and logout against `movira_core_dev`, so another
Movira project that connects to the **same database server / same
`movira_core_dev` schema** can implement a compatible auth flow.

It is derived directly from `v2/auth/*.php`, `v2/general.php`,
`v2/helpers/jwt.php`, and `v2/connection/db.php` — if this project's auth
code changes, re-derive this doc from source rather than hand-editing it out
of sync.

---

## 1. Architecture — what's shared vs. what's per-app

`movira_core_dev` is the **core identity database** shared across Movira
products. Every product connects to the same MySQL schema, but data is
partitioned two different ways — this distinction matters a lot when you
integrate:

| Table | Scoped by `app_id`? | In practice |
|---|---|---|
| `app_user` | Yes | Each app has its **own** user rows, even for the same person/email. Registering in App A does not create an account in App B. |
| `app_company` | Yes | Each app has its **own** companies. `company_code` is only unique within an `app_id`, not globally. |
| `app_role` | Yes | Roles/permissions are defined per app. |
| `app_position` | No (global lookup) | Shared position list, joined in for display only. |
| `app_subscription` | Yes (`app_id` + `app_company_id`) | Billing status per app, per company. |
| `otp_codes` | **No `app_id` column at all** | Global table, keyed by `identifier` (email/phone) + `purpose`. See §7 — this is a real cross-app collision risk. |
| `log` | **No `app_id` / `company_id` column at all** | Global audit trail *and* the table this project's rate-limiter queries against. See §7. |

**Takeaway:** sharing the schema does **not** mean shared accounts or SSO
across apps. Each app is its own tenant, identified by a unique `app_id`
issued once per app. What *is* shared: table shapes, the JWT signing
mechanism (optionally), the OTP delivery pipeline, and the audit log.

## 2. Prerequisites before you write any code

1. **Get an `app_id`** — there is no self-service endpoint for this; it's a
   row provisioned directly in `movira_core_dev` by whoever administers the
   core schema. Get this before anything else, you'll hardcode it as a
   constant (see `APP_ID` in `v2/config.php` for how this project does it).
2. **Seed at least one `app_role`** row for your `app_id` — registration
   falls back to `SELECT app_role_id FROM app_role WHERE app_id = ? ORDER BY
   created_at ASC LIMIT 1` when no role is explicitly chosen, and to a role
   named exactly `Business Owner` for the first user of a company (see §5.2).
   Without these seeded, `register` will 500.
3. **Decide on `JWT_SECRET` sharing** (see §6.3) — this determines whether
   your tokens are portable across apps.
4. Connect with the same low-level conventions this project uses (adapt to
   your stack, but keep the schema-qualification rule — it's the one
   non-negotiable):
   - Never select a default DB on the connection. Always qualify tables as
     `movira_core_dev.app_user`, never bare `app_user`.
   - `utf8mb4` charset, `Asia/Jakarta` (`+07:00`) session timezone.

## 3. Required core tables

Columns below are only what this project's auth code actually reads/writes —
treat as a floor, not a full schema dump.

**`app_user`**: `user_id` (PK, `usr_` + uniqid), `username`, `email`,
`password` (bcrypt), `first_name`, `last_name`, `phone_number`,
`account_status` (`pending` \| `verified` \| `waiting_payment` \|
`suspended` \| `disabled`), `app_id`, `app_role_id`, `company_id`,
`position_id` (nullable), `language` (`id` \| `en`), `created_at`,
`updated_at`.

**`app_company`**: `company_id`, `company_name`, `company_code`, `status`
(`active` \| `inactive`), `app_id`.

**`app_role`**: `app_role_id`, `app_id`, `role_name`, `created_at`.

**`app_position`**: `position_id`, `position_name` (global, no `app_id`).

**`app_subscription`**: `app_company_id`, `app_id`, `subscription_status`
(`active` when live), `next_billing_date`.

**`otp_codes`**: `otp_id`, `identifier` (email or phone — this project always
uses email), `otp_code` (6-digit zero-padded string), `purpose` (free-text,
e.g. `register` / `forgot_password`), `expire_at`, `is_used`,
`attempt_count`, `created_at`, `used_at`.

**`log`**: `id`, `action`, `ip_address`, `module`, `resource_id`,
`timestamp`, `user_agent`, `username`. This project always writes
`module = 'auth'` and uses it both as an audit trail and as the source table
for rate limiting (see §6.4).

## 4. Endpoint contract

This project exposes these under `POST /api/v2/account/{action}`, routed by
[`v2/index.php`](../index.php) to `v2/auth/{action}.php`. Your project's URL
scheme doesn't need to match — what matters if you want interop (shared FE,
shared rate-limit/audit visibility) is the **request/response shapes**
below.

Every response — success or failure — uses this envelope:

```json
{ "status_code": 200, "status_message": "...", "data": {}, "error_code": "AUTH_001" }
```

`error_code` is omitted on success. `data` is `null` for actions with no
payload to return.

### 4.1 Register — two-step, OTP-gated

**Step A — `POST send-otp`** — `{ email, phone_number }`. Rejects if the
email already has an `app_user` row for your `app_id` (`409 REG_003`).
Generates a 6-digit OTP, `purpose = 'register'`, 10-minute expiry, and sends
it via WhatsApp (see [`WHATSAPP_WAHA_GUIDE.md`](WHATSAPP_WAHA_GUIDE.md)).
WhatsApp delivery failure is logged but never fails the request — the OTP
still exists and is still valid. Invalidates any prior unused `register` OTP
for that email first.

**Step B — `POST register`** — `{ first_name, last_name, email,
phone_number, password, password_confirmation, company_code, otp_code,
language? }`.

- `company_code` must match an **active** company under your `app_id`
  (case-insensitive) — `404 REG_001` if not found, `422 REG_002` if inactive.
- Email must not already exist for your `app_id` — `409 REG_003`.
- OTP must match, be unused, unexpired, `purpose = 'register'`, and have
  `attempt_count < 5` — wrong code increments `attempt_count` and returns
  `400 REG_004`; 5+ attempts returns `429 REG_005` without checking the code.
- Password policy (see §5.1) — `400 REG_007` / `400 REG_008` for mismatch.
- **First user of the company** → role resolved by `role_name = 'Business
  Owner'`, `account_status` set straight to `verified`. **Every subsequent
  user** → falls back to the oldest `app_role` row for your `app_id`,
  `account_status = 'pending'`. Position and any non-default role are
  assigned later by an admin — there's no field for either at registration.
- On success: OTP is marked used in the same transaction as the insert, and
  **no JWT is issued** — the caller must log in separately (and can't, until
  an admin flips `pending` → `verified`).

### 4.2 Login — `POST login`

`{ email, password }` → on success, a JWT (see §6) plus minimal `user` and
`company` objects (see [`docs/api/account.md`](api/account.md) for the exact
response shape this project returns).

Checks in order: required fields → IP rate limit (20/min) → email rate limit
(5 failed/15min) → user lookup → password verify → `account_status` (five
distinct non-verified states, each its own error code) → `company.status`.
User-not-found and wrong-password return the **identical** `401 AUTH_001`
message — don't let your version leak which one it was, that's an
enumeration vector.

`days_remaining` in the response is computed as
`GREATEST(DATEDIFF(next_billing_date, NOW()), 0)` from the company's active
`app_subscription` row for your `app_id`, `0` if there isn't one.

### 4.3 Forgot / reset password — also two-step, also OTP-gated

**`POST forgot-password`** — `{ email }`. Always returns the same `200`
generic message ("If this email is registered, a WhatsApp message...")
**whether or not the account exists** — this is deliberate anti-enumeration,
don't special-case the "not found" path to return something different.
Internally: looks up the user's `phone_number`, invalidates prior unused
`forgot_password` OTPs for that email, issues a new one, sends it over
WhatsApp. If the user has no phone number on file, it silently no-ops but
still returns the generic `200`.

**`POST reset-password`** — `{ email, otp_code, new_password,
new_password_confirmation }`. Same OTP validation shape as register
(`purpose = 'forgot_password'`, 5-attempt cap, 10-minute expiry). On success,
updates `app_user.password` and marks the OTP used in one transaction, and
tells the caller to log in again — **does not** issue a new JWT and does
**not** invalidate any JWTs already issued before the reset (see §6.2, there
is nothing to invalidate).

### 4.4 Logout — there is no server-side endpoint

This project does not have a `logout` route, and there is nothing to
call. Auth is a stateless, self-expiring JWT (§6) with **no session table,
no token blacklist, and no revocation mechanism of any kind**. "Logging out"
is entirely a client responsibility: discard the stored token. A token
that's been logged out of client-side is still cryptographically valid and
will still pass `requireAuth()` until it hits its own `exp`.

If your project needs actual server-enforced revocation (e.g. "log out this
user everywhere," forced session kill on password change, admin-initiated
kick), **you have to build that yourself** — e.g. a denylist table keyed by
JTI or `user_id` + `iat`, checked in your token-verification step. Don't
assume the core schema gives you this; it doesn't.

## 5. Shared validation rules

### 5.1 Password policy (`v2/helpers/password_policy.php`)

All of: 8–128 chars, ≥1 uppercase, ≥1 lowercase, ≥1 digit, ≥1 of
`` ! @ # $ % ^ & * ( ) - _ = + [ ] { } ; ' : " , . < > ? / \ | ``, not in a
common-password list, and must not contain the user's `first_name`,
`last_name`, or the local part of their email as a case-insensitive
substring.

### 5.2 IDs

`user_id` = `'usr_' . uniqid()`, `otp_id` = `'otp_' . uniqid()`. Company/role
IDs follow whatever convention was used when the core schema was seeded —
don't invent a new prefix scheme for rows in shared tables.

## 6. JWT contract

`v2/helpers/jwt.php` — plain HS256, no external library.

- **Header/payload**: base64url JSON, `.`-joined, signature =
  base64url(`HMAC-SHA256(header.payload, JWT_SECRET)`), compared with
  `hash_equals` (constant-time).
- **Claims this project puts in the token**: `user_id`, `username`, `email`,
  `first_name`, `last_name`, `app_id`, `app_role_id`, `company_id`,
  `position_id`, `days_remaining`, `language`, plus standard `iat`/`exp`.
- **TTL**: login tokens are minted with an explicit 8-hour TTL
  (`JWT::encode($claims, 28800)`), even though the class default
  (`ACCESS_TTL`) is 10 hours — the 8-hour figure is the actual contract for
  `/account/login`. There's a `type: 'refresh'`/`encodeRefresh()` path with a
  7-day TTL defined in the helper, but **no endpoint in this project actually
  issues or accepts one** — treat it as unused, not as a documented feature.
- **Verification**: `Authorization: Bearer <token>`, reject anything not
  3 dot-separated base64url segments, reject bad signature, reject
  `exp < now()`. No `iss`/`aud` checking is done — if your app is going to
  accept another app's tokens, you're trusting them solely on signature +
  expiry.

### 6.1 Should your project share `JWT_SECRET`?

This project shares one `JWT_SECRET` between its own legacy (v1) stack and
v2 stack specifically so tokens are portable *within the same product*
(see the comment in `v2/.env.example` above `JWT_SECRET`). That is **not**
automatically a reason for an unrelated Movira product to reuse the same
secret — since users/companies are `app_id`-scoped anyway (§1), a shared
secret alone doesn't give you cross-app SSO, it just means anyone holding
the secret can forge tokens that *look* valid to every app that trusts it.
Only share it if you're deliberately building cross-app trust and have a
plan for `app_id` checking on the receiving side. Otherwise, mint your own
secret — you lose nothing, since sessions aren't portable across apps in
this design regardless.

### 6.2 No revocation, by design or by omission

Because verification is pure signature + `exp` check with no DB lookup,
there is **no way to invalidate a live token** short of rotating
`JWT_SECRET` (which kills every app sharing it). Password reset, account
suspension, and role changes all take effect only on **next login** — a
token issued before any of those stays valid for the rest of its 8-hour
window. If your app has a lower risk tolerance than that, you need your own
revocation layer on top of this pattern.

## 7. Known collision risk in the shared tables — read before you launch

`otp_codes` and `log` have **no `app_id` column**. This project's queries
scope OTP lookups by `identifier + purpose` only, and rate-limit /
audit-log queries by `username`/`ip_address` + `module = 'auth'` +
`action LIKE '{prefix}%'` only — nothing in either query excludes rows
written by a different app.

Concretely, if your project also writes `purpose = 'register'` or
`purpose = 'forgot_password'` OTPs, or `module = 'auth'` log rows with
generic `action` values like `login_failed` / `login_success`, and a user
happens to share the same email across both apps' registration attempts:

- OTP requests can invalidate each other's pending codes (§4.1/§4.3 both
  invalidate "any previously issued, still-unused" code for that
  `identifier + purpose` — that query doesn't know which app issued it).
- Rate-limit counters and failed-login counts become **cross-app** — a user
  hammering login on App B can trip App A's `RATE_001` lockout for the same
  email, and vice versa.

Mitigate by namespacing what you write into these two shared tables, e.g.
`purpose = '{your_app_slug}_register'` and `action = '{your_app_slug}_login_failed'`
instead of the bare values this project uses. Alternatively, if a
platform-wide shared audit trail and platform-wide rate limiting are
actually what you want (arguably reasonable for `log`, since it's the
security audit trail), leave it as-is — but that's a decision to make
explicitly, not stumble into.

## 8. Security headers this project sends on every auth response

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Strict-Transport-Security: max-age=31536000; includeSubDomains
```

Match these if your project sits behind the same frontend/domain
expectations.

## 9. Integration checklist

- [ ] `app_id` provisioned in `movira_core_dev` for your app
- [ ] At least one `app_role` seeded for that `app_id`, including one named
      exactly `Business Owner`
- [ ] At least one `app_company` row (`status = 'active'`) to register
      against, per company you expect to onboard
- [ ] Decided: share `JWT_SECRET` with another app, or mint your own (§6.1)
- [ ] Decided: namespace your `otp_codes.purpose` / `log.action` values, or
      knowingly share the pool (§7)
- [ ] Connection uses fully-qualified `movira_core_dev.{table}` — never a
      bare table name
- [ ] Accepted that "logout" is client-only — not built a call to an
      endpoint that doesn't exist
- [ ] WhatsApp/WAHA wired up if you're reusing the OTP-over-WhatsApp flow
      (see [`WHATSAPP_WAHA_GUIDE.md`](WHATSAPP_WAHA_GUIDE.md))

## 10. Source of truth

Read the code, not just this doc, before implementing:

- [`v2/auth/login.php`](../auth/login.php)
- [`v2/auth/register.php`](../auth/register.php)
- [`v2/auth/send-otp.php`](../auth/send-otp.php)
- [`v2/auth/forgot-password.php`](../auth/forgot-password.php)
- [`v2/auth/reset-password.php`](../auth/reset-password.php)
- [`v2/helpers/jwt.php`](../helpers/jwt.php)
- [`v2/helpers/rate_limit.php`](../helpers/rate_limit.php)
- [`v2/helpers/password_policy.php`](../helpers/password_policy.php)
- [`v2/connection/db.php`](../connection/db.php) / [`v2/config.php`](../config.php)
- [`docs/api/account.md`](api/account.md) — exact request/response JSON for
  this project's own endpoints
