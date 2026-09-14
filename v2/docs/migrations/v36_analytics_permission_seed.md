# Aluria Schema Addendum — v36: analytics module permission keys

**Purpose.** Adds the five permission keys the new Analytics module's endpoints
gate on (`GET /analytics/overview|sales|purchase|finance|warehouse`, added in
this same change — `v2/analytics/index.php` + `v2/analytics/{overview,sales,
purchase,finance,warehouse}.php`). Each endpoint calls `userHasPermission($conn,
$app_role_id, "analytics.$action.view")` and returns `403` when the caller's
role doesn't hold the matching key — same per-widget gating shape `v24`'s
`dashboard.*.view` catalog already established, just one key per endpoint
instead of one per widget (Analytics has no widget-level composition; each
tab is a single request).

No `aluria_dev`/`aluria_prod` (APP_SCHEMA) changes — `app_permission` lives in
`CORE_SCHEMA` only, same as `v24`/`v29`.

---

## DEV — already applied

Run against `movira_core_dev` on **2026-08-13**, by Claude (this session),
against the Tailscale dev host (`100.98.160.119`). Idempotent
(`INSERT ... ON DUPLICATE KEY UPDATE`), safe to re-run.

```sql
-- ============================================================================
-- v36 DEV — analytics module permission keys
-- ============================================================================

USE `movira_core_dev`;

-- app_id 'appe16592dc7b88eab9' is Aluria's app row (verified against an
-- existing dashboard.* row before running this, same check v29 did).
-- permission_id format matches existing rows: 'perm' + 16 hex chars.

INSERT INTO movira_core_dev.app_permission (permission_id, app_id, permission_key, module, label, description)
VALUES
  ('perma783ffae85fdc3e9', 'appe16592dc7b88eab9', 'analytics.overview.view', 'Analytics', 'Analytics Overview', 'View the Analytics module Overview tab (KPI strip + revenue vs purchase spend trend)'),
  ('perm9207cd1e309a5076', 'appe16592dc7b88eab9', 'analytics.sales.view',    'Analytics', 'Analytics Sales',    'View the Analytics module Sales tab (YTD/target KPIs, monthly trend, top products, funnel)'),
  ('perm6e0fa3e8fb2512c7', 'appe16592dc7b88eab9', 'analytics.purchase.view', 'Analytics', 'Analytics Purchase', 'View the Analytics module Purchase tab (YTD/cycle-time KPIs, import vs local trend, rankings)'),
  ('perm54960f2313d35e38', 'appe16592dc7b88eab9', 'analytics.finance.view',  'Analytics', 'Analytics Finance',  'View the Analytics module Finance tab (revenue/gross profit KPIs, P&L trend, debtor/creditor rankings)'),
  ('permd10d52f6a90ad2e9', 'appe16592dc7b88eab9', 'analytics.warehouse.view','Analytics', 'Analytics Warehouse','View the Analytics module Warehouse tab (inbound vs outbound trend, fastest-moving products)')
ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description);

-- Verification
SELECT permission_id, permission_key, module, label FROM movira_core_dev.app_permission WHERE permission_key LIKE 'analytics.%' ORDER BY permission_key;
```

**DEV verification result:** all five keys inserted — confirmed via the
`SELECT` above returning `analytics.finance.view`, `analytics.overview.view`,
`analytics.purchase.view`, `analytics.sales.view`, `analytics.warehouse.view`,
each with `module = 'Analytics'`. `SELECT COUNT(*) ... WHERE permission_key
LIKE 'analytics%'` was `0` immediately before this run, confirming these are
genuinely new rows, not a no-op re-run.

---

## PROD — NOT yet applied

**Do not run this against production without sign-off first** — same review
gate as `v21`–`v29`. Mirrors the DEV block above exactly, scoped to prod
schema names. Confirm prod's `app_permission` `app_id` for Aluria matches
`appe16592dc7b88eab9` first (verify, don't assume — same caveat every prior
addendum in this series has left open).

```sql
-- Replace movira_core_prod below with the real prod schema name from prod's
-- .env (CORE_SCHEMA) before running.

USE `movira_core_prod`;

INSERT INTO movira_core_prod.app_permission (permission_id, app_id, permission_key, module, label, description)
VALUES
  ('perma783ffae85fdc3e9', 'appe16592dc7b88eab9', 'analytics.overview.view', 'Analytics', 'Analytics Overview', 'View the Analytics module Overview tab (KPI strip + revenue vs purchase spend trend)'),
  ('perm9207cd1e309a5076', 'appe16592dc7b88eab9', 'analytics.sales.view',    'Analytics', 'Analytics Sales',    'View the Analytics module Sales tab (YTD/target KPIs, monthly trend, top products, funnel)'),
  ('perm6e0fa3e8fb2512c7', 'appe16592dc7b88eab9', 'analytics.purchase.view', 'Analytics', 'Analytics Purchase', 'View the Analytics module Purchase tab (YTD/cycle-time KPIs, import vs local trend, rankings)'),
  ('perm54960f2313d35e38', 'appe16592dc7b88eab9', 'analytics.finance.view',  'Analytics', 'Analytics Finance',  'View the Analytics module Finance tab (revenue/gross profit KPIs, P&L trend, debtor/creditor rankings)'),
  ('permd10d52f6a90ad2e9', 'appe16592dc7b88eab9', 'analytics.warehouse.view','Analytics', 'Analytics Warehouse','View the Analytics module Warehouse tab (inbound vs outbound trend, fastest-moving products)')
ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description);

-- Verification
SELECT permission_id, permission_key, module, label FROM movira_core_prod.app_permission WHERE permission_key LIKE 'analytics.%' ORDER BY permission_key;
```

---

## What happens if schema and code deploy land out of sync

- **Old code (pre-`v36`) against new schema:** harmless. The five rows are
  inert until something calls `userHasPermission()` with an `analytics.*.view`
  key, which only the new `v2/analytics/*.php` files do.
- **New code against old schema (this migration not yet run):** every
  `GET /analytics/*` request 500s — `userHasPermission()` runs a normal
  `SELECT` against `app_role_permission`/`app_permission`; with no matching
  `permission_key` row at all, the join returns zero rows for every role,
  which the code currently treats as "no permission" → `403`, not a crash.
  So this direction is also safe, just fully locked out (`403` for every
  caller) until the migration runs — not a 500.

---

## Post-migration checklist

- [ ] **Role assignment is unassigned**, same open item every `.view`-key
  addendum in this series (`v24`, `v29`) has left for the caller to decide.
  Until a role is granted `analytics.overview.view` / `analytics.sales.view` /
  `analytics.purchase.view` / `analytics.finance.view` /
  `analytics.warehouse.view`, every caller in that role gets `403` on the
  matching tab — expected behavior per the frontend's contract (`403` renders
  "Access Denied", not a crash), but no one can actually see any Analytics
  tab until at least one role holds these keys. Decide which roles (Business
  Owner and Manager are the obvious first candidates, mirroring their broad
  `dashboard.*.view` grants from `v24`) should get which of the five keys —
  not inferred here.
- [ ] API code shipping alongside this migration (`v2/analytics/index.php`,
  `v2/analytics/overview.php`, `v2/analytics/sales.php`,
  `v2/analytics/purchase.php`, `v2/analytics/finance.php`,
  `v2/analytics/warehouse.php`, `v2/helpers/analytics_dates.php`,
  the `case 'analytics'` route added to `v2/index.php`) must deploy in the
  same release as this migration, per the sync note above.
- [ ] PROD block above still needs to be run — not done as part of this
  session.
