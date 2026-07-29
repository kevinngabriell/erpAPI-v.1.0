# Aluria Schema Addendum — v29: purchase_invoice + purchase_receive approval notifications

**Extends the notification module's Phase 1 scope (`v23`) to `purchase_invoice` and `purchase_receive`.** Both tables already have the single-approval columns (`status_id`/`approved_by`/`approved_at`, added in `v22`) and `approve`/`reject`/`revise` endpoints, but neither ever called `notify()` — no permission key existed to resolve "who should be told a document is pending", so wiring notify() in without this addendum would silently resolve to zero recipients (same failure mode `v23`'s checklist flagged for `sales_order`/`purchase_order`).

No table schema changes here — the five `notification*`/`approval_action_token`/`public_holiday`/`company_setting` tables from `v23` are reused as-is. This is purely two new `movira_core_dev.app_permission` rows, mirroring `notification.purchase_order.approver` exactly.

---

## DEV — already applied

Run against `movira_core_dev` on **2026-07-25**, by Claude (this session), against the Tailscale dev host (`100.98.160.119`). Idempotent (`INSERT ... ON DUPLICATE KEY UPDATE`), safe to re-run.

```sql
-- ============================================================================
-- v29 DEV — purchase_invoice / purchase_receive notification permission keys
-- ============================================================================

USE `movira_core_dev`;

-- app_id 'appe16592dc7b88eab9' is Aluria's app row (matches APP_ID in v2/.env,
-- confirmed against the existing v23 rows before running this).
-- permission_id format matches existing rows: 'perm' + 16 hex chars.

INSERT INTO movira_core_dev.app_permission (permission_id, app_id, permission_key, module, label, description)
VALUES
  ('perm00e6bb81c343a98d', 'appe16592dc7b88eab9', 'notification.purchase_invoice.approver', 'Notification', 'Purchase Invoice Approver', 'Receives notifications for Purchase Invoices pending approval'),
  ('perm6d05d1893e173ccd', 'appe16592dc7b88eab9', 'notification.purchase_receive.approver', 'Notification', 'Purchase Receive Approver', 'Receives notifications for Purchase Receives pending approval')
ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description);

-- Verification
SELECT permission_id, permission_key, module, label FROM movira_core_dev.app_permission WHERE permission_key LIKE 'notification.purchase_%';
```

**DEV verification result:** both permission keys inserted — confirmed via the `SELECT` above returning `notification.purchase_order.approver` (pre-existing, `v23`), `notification.purchase_invoice.approver`, and `notification.purchase_receive.approver`.

---

## PROD — NOT yet applied

**Do not run this against production without Kage's sign-off first** — same review gate as `v21`/`v22`/`v23`. Mirrors the DEV block above exactly, scoped to prod schema names.

```sql
-- Replace movira_core_prod below with the real prod schema name from prod's
-- .env (CORE_SCHEMA) before running. Confirm prod's app_permission APP_ID
-- matches 'appe16592dc7b88eab9' first (verify, don't assume — same caveat
-- v23's PROD block left open).

USE `movira_core_prod`;

INSERT INTO movira_core_prod.app_permission (permission_id, app_id, permission_key, module, label, description)
VALUES
  ('perm00e6bb81c343a98d', 'appe16592dc7b88eab9', 'notification.purchase_invoice.approver', 'Notification', 'Purchase Invoice Approver', 'Receives notifications for Purchase Invoices pending approval'),
  ('perm6d05d1893e173ccd', 'appe16592dc7b88eab9', 'notification.purchase_receive.approver', 'Notification', 'Purchase Receive Approver', 'Receives notifications for Purchase Receives pending approval')
ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description);

-- Verification
SELECT permission_id, permission_key, module, label FROM movira_core_prod.app_permission WHERE permission_key LIKE 'notification.purchase_%';
```

---

## Post-migration checklist

- [ ] **Role assignment is unassigned**, same open item `v23` left for `notification.sales_order.approver`/`notification.purchase_order.approver` — until a role is granted `notification.purchase_invoice.approver`/`notification.purchase_receive.approver`, `resolveApprovalRecipients()` returns an empty list for these two modules and submitted documents generate no pending-approval notification (not an error — `notify()` just skips writing any recipient rows). Decide which roles should hold these, same decision left open for the other three `notification.*.approver` keys.
- [ ] API code shipping alongside this migration (`v2/purchase/purchase-invoice/index.php`, `v2/purchase/purchase-receive/index.php`, `v2/helpers/notification.php`, `v2/notification/approvals.php`, `v2/notification/index.php`) must deploy in the same release — old code against this schema is harmless (the new permission rows are simply unused until code calls `resolveApprovalRecipients($conn, $company_id, 'purchase_invoice')`/`'purchase_receive'`).
