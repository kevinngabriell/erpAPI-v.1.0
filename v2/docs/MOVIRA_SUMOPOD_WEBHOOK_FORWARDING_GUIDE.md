# SumoPod Webhook Forwarding Guide — Movira → Aluria

This document explains how **Movira** (the main application / company page, which owns the SumoPod webhook URL) must forward Aluria subscription payment events to **Aluria (v2)**, so Aluria can activate the company's subscription.

It is derived from `v2/subscription/webhook.php`, `v2/subscription/index.php`, `v2/helpers/sumopod.php` and `v2/helpers/subscription.php`. If that code changes, re-derive this doc from source rather than hand-editing it out of sync.

Related: [v39_subscription_payment_schema.md](migrations/v39_subscription_payment_schema.md) (tables, permission, packages), [SHARED_AUTH_MOVIRA_CORE_GUIDE.md](SHARED_AUTH_MOVIRA_CORE_GUIDE.md) (shared `movira_core_dev`).

---

## 1. Why forwarding is needed

- SumoPod allows **one webhook URL per project** (Settings → Webhook). That URL points at Movira.
- Aluria creates its payments through the same SumoPod project (`POST /api/v1/payments`, see `createSumoPodPayment()`), so Movira receives **every** event for that project — Aluria's and its own.
- Activation logic (mark payment completed, create/extend `app_subscription`, sync `app_company.expired_at`) lives in Aluria, next to the `subscription_payment` table. Movira should not duplicate it; it forwards.

## 2. Flow

```
SumoPod ──POST (svix-* headers + JSON)──▶ Movira webhook
                                           │ 1. verify svix signature (shared whsec_ secret)
                                           │ 2. parse JSON only to read data.order_id
                                           │ 3. order_id starts with "ALU-SUB-" ?
                                           │      no  → Movira's own handling
                                           │      yes ↓
                                           ▼
                        POST {ALURIA}/api/v2/subscription/webhook
                        (SAME raw body bytes + SAME 3 svix-* headers)
                                           │
                        Aluria re-verifies signature, dedupes by svix-id,
                        activates subscription, replies { status_code, ... }
                                           │
Movira returns Aluria's HTTP status ◀──────┘   (non-2xx ⇒ SumoPod retries)
```

## 3. Identifying Aluria events

Aluria generates `order_id` as `'ALU-SUB-' . strtoupper(uniqid())` (e.g. `ALU-SUB-6AD36A5AE5CAE`). That prefix is the **only** routing key — route on `data.order_id` starting with `ALU-SUB-`.

Events carrying no `data.order_id` (e.g. `payment.test`) cannot be attributed. Movira should answer those itself with `200`; Aluria also answers `payment.test` with `200` if it is forwarded.

Events Aluria acts on: `payment.completed`, `payment.failed`, `payment.expired`. `payment.test` is acknowledged; any other event type is acknowledged and ignored (`200`).

## 4. Forwarding contract (what Movira must do exactly)

| Requirement | Detail |
|---|---|
| **Method / URL** | `POST` to Aluria's `/api/v2/subscription/webhook`. Behind the nginx `/venken-api/` location the public path is `/venken-api/v2/subscription/webhook` (the router pads it back to `api/v2/...`). Use the per-environment Aluria base URL. |
| **Body** | The **raw request bytes, unmodified**. Never `json_decode` → `json_encode` before forwarding: a single changed whitespace character breaks the HMAC (SumoPod's docs say the same). Read the body once (`php://input`), keep the string, forward that string. |
| **Headers** | Forward `svix-id`, `svix-timestamp`, `svix-signature` **unchanged**. Also send `Content-Type: application/json`. Aluria reads them as `HTTP_SVIX_ID`, `HTTP_SVIX_TIMESTAMP`, `HTTP_SVIX_SIGNATURE`. |
| **No JWT** | The endpoint is intentionally public (no `Authorization` header). Authenticity is the Svix signature. |
| **Forward immediately** | Aluria rejects timestamps older/newer than **300 seconds** from now. Do not queue forwards for later; retry only within that window (SumoPod re-sends with a fresh signature anyway). |
| **Timeout** | SumoPod requires a `2xx` within **10 seconds**. Use a forward timeout of ~8s. |
| **Secret** | The same `whsec_...` secret must be configured in Movira (`SUMOPOD_WEBHOOK_SECRET` or equivalent) **and** Aluria (`SUMOPOD_WEBHOOK_SECRET` in `v2/.env`). Movira's own verification is recommended (don't forward unsigned junk), but Aluria always re-verifies independently. |

## 5. Return codes — what Movira should answer to SumoPod

Movira should **pass Aluria's HTTP status through** (and may pass the JSON body through too). Everything Aluria returns uses the standard shape `{ "status_code", "status_message", "data" }`.

| Aluria status | `status_message` | Meaning | Movira → SumoPod |
|---|---|---|---|
| `200` | `Test event received` | `payment.test` | `200` |
| `200` | `Event ignored` | Unsupported event type, or no `order_id` | `200` |
| `200` | `Event already processed` | Same `svix-id` seen before (retry) | `200` |
| `200` | `Unknown order, ignored` | `order_id` has no `subscription_payment` row | `200` |
| `200` | `Payment already completed` / `failed` / `expired` | Payment no longer pending | `200` |
| `200` | `Payment marked failed` / `expired` | `payment.failed` / `payment.expired` applied | `200` |
| `200` | `Payment completed and subscription activated` | Success | `200` |
| `401` | `Invalid signature` | Bad/missing signature, secret mismatch, or timestamp outside ±300s | `401` (SumoPod retries). Persistent 401s ⇒ secrets differ between Movira and Aluria. |
| `400` | `Invalid JSON body` | Body not valid JSON | `400` |
| `405` | `Method Not Allowed` | Not a `POST` | — (indicates a Movira bug) |
| `409` | `Amount mismatch` | `payment.completed` amount ≠ the amount Aluria requested. **Subscription is NOT activated.** | pass `409`. SumoPod will keep retrying and it will keep failing — **alert a human**, do not suppress. |
| `500` | `Webhook is not configured` | `SUMOPOD_WEBHOOK_SECRET` empty in Aluria's `.env` | `500` (retry after fix) |
| `500` | `Webhook processing failed` | DB/transaction error; everything rolled back | `500` (SumoPod retries; safe because nothing was committed) |

**Network error / timeout reaching Aluria** → Movira must answer non-2xx (e.g. `502`/`504`) so SumoPod retries. Never answer `200` unless Aluria answered `2xx`.

### Idempotency & retries

- Aluria dedupes on `svix-id` (`payment_webhook_event` primary key) **inside the same DB transaction** as the activation. A processing failure rolls the dedupe row back, so a retry is processed normally; a successful delivery replayed is a harmless `200 Event already processed`.
- Therefore Movira may retry forwards freely (within the 300s timestamp window) and SumoPod's retries are safe. Movira does **not** need its own dedupe for Aluria events.

## 6. Reference sketch (PHP)

A sketch only — adapt to Movira's own conventions. The two things that matter are: keep `$raw_body` untouched, and map Aluria's status back to SumoPod.

```php
<?php
$raw_body  = file_get_contents('php://input');
$svix_id   = $_SERVER['HTTP_SVIX_ID']        ?? '';
$svix_ts   = $_SERVER['HTTP_SVIX_TIMESTAMP'] ?? '';
$svix_sig  = $_SERVER['HTTP_SVIX_SIGNATURE'] ?? '';

// 1. verify with the shared whsec_ secret (Svix algorithm, same as SumoPod's PHP sample)
// if (!verifyWebhookSignature(...)) { http_response_code(401); exit; }

// 2. parse ONLY to read the routing key — never forward this decoded copy
$event    = json_decode($raw_body, true);
$order_id = (string)($event['data']['order_id'] ?? '');

if (str_starts_with($order_id, 'ALU-SUB-')) {
    $ch = curl_init(ALURIA_BASE_URL . '/api/v2/subscription/webhook');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $raw_body,           // raw bytes, unchanged
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'svix-id: '        . $svix_id,
            'svix-timestamp: ' . $svix_ts,
            'svix-signature: ' . $svix_sig,
        ],
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    http_response_code($http_code >= 200 && $http_code < 300 ? $http_code : ($http_code ?: 502));
    header('Content-Type: application/json');
    echo $response ?: json_encode(['error' => 'Aluria unreachable']);
    exit;
}

// ...Movira's own event handling for every other order_id prefix...
```

## 7. Setup checklist

- [ ] SumoPod → Settings → Webhook: URL = Movira's webhook endpoint; save; copy the generated signing secret (`whsec_...`). Use **Live** and **Sandbox** pairs separately.
- [ ] Put the **same** `whsec_...` secret in Movira and in Aluria `v2/.env` (`SUMOPOD_WEBHOOK_SECRET`). Aluria currently has it **empty** → its endpoint returns `500 Webhook is not configured` until set.
- [ ] Aluria `v2/.env` also needs `SUMOPOD_API_KEY` (already set on dev). Use the key of the same SumoPod project/mode whose webhook points at Movira.
- [ ] Movira needs the Aluria base URL per environment, and Aluria must be reachable from Movira's server (dev Aluria sits on Tailscale `100.98.160.119`'s network — confirm reachability from wherever Movira runs).
- [ ] Prod: the two `aluria.*` tables from the v39 migration must exist first (see that doc's PROD section — **not yet applied**).
- [ ] Assign the `subscription.manage` permission to a role in Aluria (otherwise checkout returns 403 and no payments will ever be created).

## 8. Testing

1. **SumoPod "Save & Test"** sends `payment.test` to Movira → Movira should answer `200`.
2. **Forward path, signed manually** — send a signed `payment.completed` to Aluria with a real pending `order_id` (created via `POST /api/v2/subscription/checkout`). Signing (same as SumoPod's Svix scheme): `base64(HMAC_SHA256(base64decode(secret without "whsec_"), "{svix-id}.{svix-timestamp}.{raw_body}"))`, header value `v1,<signature>`.
3. **Expected Aluria results** for a correct signed `payment.completed` whose `data.amount` equals the requested amount:
   - `200 Payment completed and subscription activated`
   - `subscription_payment.status = 'completed'`, core `app_subscription` row `active`/`paid`, `current_period_end` = +1 month (or +1 year), `app_company.expired_at` = period end 23:59:59.
   - Replaying the same `svix-id` → `200 Event already processed`.
   - Wrong `data.amount` → `409 Amount mismatch` and the payment stays `pending`.
   - Altered body or secret → `401 Invalid signature`.
4. **Negative path through Movira**: stop Aluria (or block the route) and confirm Movira returns a non-2xx so SumoPod marks the delivery failed and can resend it from the Webhooks tab.

## 9. Things Movira should know

- A user's JWT carries `days_remaining` from login time. After activation the company page / Aluria shows the new period only after the user logs in again (or the token refreshes).
- Paid subscriptions are **not** written to core `app_invoice` / `app_payment` (the core portal owns invoice numbering and `unique_code` matching). If Movira's company page lists invoices, Aluria subscription payments will not appear there — they live in `aluria_dev.subscription_payment` (`aluria.subscription_payment` in prod). Open decision.
- Enterprise is sales-assisted ("Hubungi sales") and is not part of this flow.
