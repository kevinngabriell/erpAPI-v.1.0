# Aluria Schema Addendum — v39: subscription checkout via SumoPod

Adds two new `aluria_dev` (`APP_SCHEMA`) tables, one new `movira_core_dev` (`CORE_SCHEMA`) permission key, and seeds Aluria's first two public packages into `app_services`. No existing table is altered.

## Design

- Packages are **not** a new table — they are `CORE_SCHEMA.app_services` rows for `APP_ID` (public plan = `customer_id IS NULL`). Aluria had none before this migration.
- **`subscription_payment`** (APP_SCHEMA) — one row per SumoPod checkout attempt. Holds gateway state (`gateway_payment_id`, `payment_link_url`, `fee`, `net_amount`, `status`). `pending_company_key` is a generated column with a UNIQUE index so a company can have at most one `pending` payment (same trick as `app_payment.pending_invoice_key` in core).
- **`payment_webhook_event`** (APP_SCHEMA) — `id` is the Svix `svix-id` header; its PRIMARY KEY is the webhook idempotency guard (Svix retries reuse the id).
- Core `app_subscription` is written **only on `payment.completed`** (insert, or update the company's latest non-cancelled row, extending from `current_period_end` when still active) and `app_company.expired_at` is synced to the new period end. No pending/placeholder subscription rows are created at checkout.
- Core `app_invoice` / `app_payment` are deliberately **not** written: the core portal owns invoice numbering (`INV-YYYY-NNNN`) and the `unique_code` / `open_amount_key` matching constraints, and `app_payment.payment_status` has no gateway-compatible states. See checklist.
- `subscription.manage` gates `POST /subscription/checkout`. Assigning it to a role is left to the user.

## DEV — already applied

Run against `aluria_dev` / `movira_core_dev` on **2026-10-17**, by Claude (this session). All statements are idempotent.

```sql
CREATE TABLE IF NOT EXISTS aluria_dev.subscription_payment (
  id                   VARCHAR(50)  NOT NULL,
  company_id           VARCHAR(50)  NOT NULL,
  service_id           VARCHAR(50)  NOT NULL,
  subscription_id      VARCHAR(50)  NULL,
  order_id             VARCHAR(100) NOT NULL,
  gateway_payment_id   VARCHAR(100) NULL,
  payment_link_url     VARCHAR(500) NULL,
  amount               BIGINT       NOT NULL,
  fee                  BIGINT       NULL,
  net_amount           BIGINT       NULL,
  billing_cycle        ENUM('monthly','yearly') NOT NULL,
  status               ENUM('pending','completed','failed','expired') NOT NULL DEFAULT 'pending',
  pending_company_key  VARCHAR(50) GENERATED ALWAYS AS (IF(status = 'pending', company_id, NULL)) STORED,
  expires_at           DATETIME     NULL,
  paid_at              DATETIME     NULL,
  activated_at         DATETIME     NULL,
  created_by           VARCHAR(50)  NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscription_payment_order (order_id),
  UNIQUE KEY uq_subscription_payment_pending (pending_company_key),
  KEY idx_company_created (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS aluria_dev.payment_webhook_event (
  id           VARCHAR(100) NOT NULL,
  event_type   VARCHAR(50)  NOT NULL,
  order_id     VARCHAR(100) NULL,
  received_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO movira_core_dev.app_permission (permission_id, app_id, permission_key, module, label, description)
SELECT 'perm3b7e91c4a05d28f6', 'appe16592dc7b88eab9', 'subscription.manage', 'Subscription', 'Manage Subscription', 'Can purchase or renew the company subscription package via the payment gateway'
WHERE NOT EXISTS (
  SELECT 1 FROM movira_core_dev.app_permission WHERE permission_key = 'subscription.manage' AND app_id = 'appe16592dc7b88eab9'
);

-- WHY: Starter/Business are the discounted prices from the pricing page (list prices 1.299.000 / 2.999.000 are display-only, no column for them).
-- Enterprise (Rp 5.999.000) is 'Hubungi sales' (contact sales) and intentionally NOT seeded — it must not be purchasable online.
INSERT INTO movira_core_dev.app_services
  (service_id, app_id, customer_id, service_name, service_description, service_price, billing_cycle, is_active, auto_invoice, pricing_model, sort_order)
SELECT 'appservice_alu_starter', 'appe16592dc7b88eab9', NULL, 'Starter', 'UKM & perusahaan rintisan. Hingga 5 user aktif.', 1000000, 'monthly', 1, 0, 'fixed', 1
WHERE NOT EXISTS (SELECT 1 FROM movira_core_dev.app_services WHERE app_id = 'appe16592dc7b88eab9' AND service_name = 'Starter' AND customer_id IS NULL);

INSERT INTO movira_core_dev.app_services
  (service_id, app_id, customer_id, service_name, service_description, service_price, billing_cycle, is_active, auto_invoice, pricing_model, sort_order)
SELECT 'appservice_alu_business', 'appe16592dc7b88eab9', NULL, 'Business', 'Trading & forwarding aktif. Hingga 15 user aktif.', 2500000, 'monthly', 1, 0, 'fixed', 2
WHERE NOT EXISTS (SELECT 1 FROM movira_core_dev.app_services WHERE app_id = 'appe16592dc7b88eab9' AND service_name = 'Business' AND customer_id IS NULL);

-- Verification
SHOW TABLES FROM aluria_dev LIKE 'subscription_payment';
SHOW TABLES FROM aluria_dev LIKE 'payment_webhook_event';
SELECT permission_id, permission_key FROM movira_core_dev.app_permission WHERE permission_key = 'subscription.manage';
SELECT service_id, service_name, service_price, billing_cycle, is_active FROM movira_core_dev.app_services WHERE app_id = 'appe16592dc7b88eab9';
```

**DEV verification result:** both tables and the permission row exist; 2 services seeded. Smoke test (local `php -S` against dev DB, temp payment on company `cmp0fcd1b1c010aa508`, signed with a throwaway `whsec_` secret): bad/missing signature → 401; `payment.test` → 200; second concurrent pending payment blocked by `uq_subscription_payment_pending` (errno 1062); amount mismatch → 409 and payment stays `pending`; `payment.completed` → subscription inserted `active`/`paid`, period 1 month, `app_company.expired_at` synced; replayed `svix-id` → "already processed"; new `svix-id` for same order → "already completed"; `/packages`, `/current`, `/payments/{id}` correct; checkout without permission → 403. All temp rows (subscription, payment, webhook events, audit rows) deleted and `expired_at` reset to `NULL`.

---

## PROD — NOT yet applied

Prod shares `CORE_SCHEMA = movira_core_dev` with dev, so the **permission key and the two `app_services` rows already exist for prod**. Only the two `APP_SCHEMA` tables are needed — run the two `CREATE TABLE IF NOT EXISTS` statements above with `aluria_dev.` replaced by `aluria.` (prod `APP_SCHEMA`). Requires explicit go-ahead; not run.

## Deploy order / out-of-sync behaviour

- Schema first, then code. New code against the old schema errors on every `/subscription/*` request; old code against the new schema is harmless (nothing reads these tables).
- Env must be set before the webhook works: `SUMOPOD_API_KEY`, `SUMOPOD_WEBHOOK_SECRET` (`whsec_...`); optional `SUMOPOD_SUCCESS_URL` / `SUMOPOD_CANCEL_URL`. Without the secret, the webhook returns 500 (never accepts unsigned events).

## Post-migration checklist

- [ ] **Assign `subscription.manage` to a role** (e.g. the company-owner role) — business decision, not done. Until then every `POST /subscription/checkout` returns 403.
- [ ] In SumoPod → Settings → Webhook, set the URL to `https://<host>/api/v2/subscription/webhook`, save, then copy the generated signing secret into `SUMOPOD_WEBHOOK_SECRET`. Use "Save & Test" (`payment.test` → expect 200).
- [ ] Live and Sandbox have separate API keys/secrets in SumoPod — use the matching pair per environment.
- [ ] Enterprise plan is sales-assisted: create its `app_services` row / subscription via the core admin portal, not this flow.
- [ ] Decide whether paid subscriptions should also create core `app_invoice` + `app_payment` rows (needs the portal's numbering/`unique_code` rules). Not done.
- [ ] `login` embeds `days_remaining` in the JWT, so a user sees the new period only after re-login/refresh.
- [ ] Companies/users in `account_status = 'waiting_payment'` are **not** auto-promoted to `verified` on payment — signup-flow decision pending.
