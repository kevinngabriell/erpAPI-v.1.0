# Aluria Schema Addendum — v23: notification module (in-app + WhatsApp + approval links)

Adds the schema for the new notification module: five new `aluria_dev` (`APP_SCHEMA`) tables and two new `movira_core_dev` (`CORE_SCHEMA`) permission keys. No existing table is altered.

This ships **Phase 1** scope only — Sales Order, Purchase Order, and Finance (finance-transaction, finance-payment). Warehouse Adjustment has no approval workflow to hook into yet (confirmed: `warehouse_transaction` has no status column, no approve/reject actions — see `docs/api/dashboard.md`'s "Unavailable widgets" note) and is out of scope here.

Two deliberate design choices, different from the notification spec's original assumptions:

1. **`notification` is one row per (event, recipient)**, not a shared row with a nullable `target_role_id`. Each recipient gets their own `read_at`. This is what makes the list/unread-count endpoints a plain `WHERE target_user_id = ?` query.
2. **No `document_dual_approval` table and no new `Treasury` role.** Finance recipient resolution reuses the *existing* `keuangan.approve_owner` / `keuangan.approve_treasury` permission keys (`v2/helpers/permission.php`, `v2/helpers/dual_approval.php`) to find who to notify, instead of a separately-configured pair of named approvers. This keeps "who can approve Finance" as a single source of truth instead of two.

`company_setting` is a new **generic** company-scoped key/value table — no `feature.*`/settings pattern existed anywhere in this codebase to reuse (confirmed by grep), so this is new infrastructure, intentionally generic so it isn't a single-purpose `notification_settings` table.

---

## DEV — already applied

Run against `aluria_dev` / `movira_core_dev` on **2026-07-21**, by Claude (this session), against the Tailscale dev host (`100.98.160.119`). All statements are idempotent (`CREATE TABLE IF NOT EXISTS`, `INSERT ... ON DUPLICATE KEY UPDATE`), safe to re-run.

```sql
-- ============================================================================
-- v23 DEV — notification module tables (aluria_dev)
-- ============================================================================

USE `aluria_dev`;

-- ----------------------------------------------------------------------------
-- notification — one row per (event, recipient). read_at is per-recipient.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS aluria_dev.notification (
  id                  VARCHAR(50)  NOT NULL,
  company_id          VARCHAR(50)  NOT NULL,
  type                VARCHAR(50)  NOT NULL COMMENT 'approval_pending | approval_approved | approval_rejected | digest_daily',
  source_module       VARCHAR(50)  NOT NULL COMMENT 'sales_order | purchase_order | finance_transaction | finance_payment',
  source_document_id  VARCHAR(50)  NOT NULL,
  title               VARCHAR(255) NOT NULL,
  body                TEXT         NOT NULL,
  target_user_id      VARCHAR(50)  NOT NULL,
  read_at             DATETIME     NULL,
  created_by          VARCHAR(50)  NOT NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by          VARCHAR(50)  NULL,
  updated_at          DATETIME     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at          DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_company_target_unread (company_id, target_user_id, read_at),
  KEY idx_source (source_module, source_document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- notification_delivery — one row per (notification, channel).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS aluria_dev.notification_delivery (
  id                 VARCHAR(50)  NOT NULL,
  notification_id    VARCHAR(50)  NOT NULL,
  company_id         VARCHAR(50)  NOT NULL,
  channel            ENUM('in_app','whatsapp') NOT NULL,
  recipient_user_id  VARCHAR(50)  NOT NULL,
  recipient_phone    VARCHAR(20)  NULL,
  status             ENUM('queued','sent','delivered','failed','skipped') NOT NULL DEFAULT 'queued',
  error_message      TEXT         NULL,
  sent_at            DATETIME     NULL,
  created_by         VARCHAR(50)  NOT NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by         VARCHAR(50)  NULL,
  updated_at         DATETIME     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at         DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_notification (notification_id),
  KEY idx_company_channel_status (company_id, channel, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- approval_action_token — one-click approve/reject link, single-use,
-- scoped to 1 user + 1 document.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS aluria_dev.approval_action_token (
  id                  VARCHAR(50) NOT NULL COMMENT 'also the URL token',
  company_id          VARCHAR(50) NOT NULL,
  notification_id     VARCHAR(50) NULL,
  source_module       VARCHAR(50) NOT NULL,
  source_document_id  VARCHAR(50) NOT NULL,
  target_user_id      VARCHAR(50) NOT NULL,
  expires_at          DATETIME    NOT NULL,
  used_at             DATETIME    NULL,
  created_by          VARCHAR(50) NOT NULL,
  created_at          DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at          DATETIME    NULL,
  PRIMARY KEY (id),
  KEY idx_source_doc (source_module, source_document_id),
  KEY idx_target_user (target_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- public_holiday — company_id NULL = national holiday (applies to all
-- tenants); seeded manually, not from a third-party API (see notification
-- spec §3.5/§4.6a for why: gov't cuti-bersama dates get revised close to the
-- date, an external API can be stale or wrong).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS aluria_dev.public_holiday (
  id            VARCHAR(50)  NOT NULL,
  company_id    VARCHAR(50)  NULL,
  holiday_date  DATE         NOT NULL,
  label         VARCHAR(255) NOT NULL,
  created_by    VARCHAR(50)  NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at    DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_company_date (company_id, holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- company_setting — generic company-scoped key/value store. No equivalent
-- existed anywhere in this codebase before this migration (confirmed by
-- grep for "feature." / "reorder" — zero hits). Used now for
-- notification.working_days; deliberately generic for future reuse.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS aluria_dev.company_setting (
  id             VARCHAR(50) NOT NULL,
  company_id     VARCHAR(50) NOT NULL,
  setting_key    VARCHAR(100) NOT NULL,
  setting_value  TEXT NOT NULL,
  created_by     VARCHAR(50) NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by     VARCHAR(50) NULL,
  updated_at     DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_company_setting_key (company_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Verification
SHOW TABLES FROM aluria_dev LIKE 'notification%';
SHOW TABLES FROM aluria_dev LIKE 'approval_action_token';
SHOW TABLES FROM aluria_dev LIKE 'public_holiday';
SHOW TABLES FROM aluria_dev LIKE 'company_setting';
```

```sql
-- ============================================================================
-- v23 DEV — notification permission keys (movira_core_dev)
-- ============================================================================

USE `movira_core_dev`;

-- app_id 'appe16592dc7b88eab9' is Aluria's app row (matches APP_ID in v2/.env).
-- permission_id format matches existing rows: 'perm' + 16 hex chars.

INSERT INTO movira_core_dev.app_permission (permission_id, app_id, permission_key, module, label, description)
VALUES
  ('perm7a1e4d6c9f302b18', 'appe16592dc7b88eab9', 'notification.sales_order.approver',    'Notification', 'Sales Order Approver',    'Receives notifications for Sales Orders pending approval'),
  ('perm3c58b0a127de6f94', 'appe16592dc7b88eab9', 'notification.purchase_order.approver', 'Notification', 'Purchase Order Approver', 'Receives notifications for Purchase Orders pending approval')
ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description);

-- Verification
SELECT permission_id, permission_key, module, label FROM movira_core_dev.app_permission WHERE permission_key LIKE 'notification.%';
```

**DEV verification result:** all 5 tables created, both permission keys inserted (confirmed via the `SELECT`/`SHOW TABLES` statements above).

---

## PROD — NOT yet applied

**Do not run this against production without Kage's sign-off first** — same review gate as `v21`/`v22`. Mirrors the DEV block above exactly, scoped to prod schema names.

```sql
-- Replace aluria_prod / movira_core_prod below with the real prod schema
-- names from prod's .env (APP_SCHEMA / CORE_SCHEMA) before running. Re-run
-- the exact DEV SQL above with `aluria_dev` → `aluria_prod` and
-- `movira_core_dev` → `movira_core_prod`. Confirm prod's `app_permission`
-- APP_ID matches 'appe16592dc7b88eab9' before running the permission INSERT
-- (Aluria's app row id should be constant across environments, but verify —
-- do not assume).
```

---

## Post-migration checklist

- [ ] **Role assignment for the two new `notification.*.approver` permission keys is unassigned.** No role currently holds `notification.sales_order.approver` or `notification.purchase_order.approver` — until a role is granted one of these, `resolveApprovalRecipients()` returns an empty recipient list for that module and submitted documents generate no notifications (in-app rows are still written with zero recipients skipped silently, not an error). Decide which roles (Business Owner? Manager?) should hold these, same open-decision pattern as `v21` left Treasury unassigned.
- [ ] **WebSocket daemon (`v2/notification/ws-server.php`) needs new server infra**, unlike everything else in this repo: a systemd unit (`Restart=always`) to keep it running across restarts/deploys, and an Nginx `location` block proxying `wss://.../notification/stream` to the daemon's local port with `Upgrade`/`Connection` headers set. Neither exists yet — `.github/workflows/deploy.yml` has no service-restart step today, so a `git reset --hard` deploy does not need to restart this daemon (it isn't part of the checked-out PHP-FPM code path), but the systemd unit itself has to be created once, manually, on the target host.
- [ ] **Daily digest cron is not installed.** `v2/notification/digest.php` is CLI-runnable and self-gates on working-days/public-holiday, but the actual crontab line (`0 8 * * * php /home/ubuntu/apps/erpAPI-dev/v2/notification/digest.php >> /var/log/notification_digest.log 2>&1`) needs to be added to the host's crontab manually — no crontab is tracked in this repo.
- [ ] Confirm `pcntl`/`posix` PHP extensions are present on the deploy host (`php -m | grep -E 'pcntl|posix'`) before starting `ws-server.php` there — required by `workerman/workerman` for process management. Both are normally bundled in Ubuntu's default `php8.3-cli` package, but this hasn't been verified against the actual deploy host.
- [ ] `public_holiday` starts empty — seed national holidays for the current year before relying on the digest's holiday-skip logic.
- [ ] `company_setting` starts empty — companies default to `["MON","TUE","WED","THU","FRI"]` for `notification.working_days` when no row exists (handled in code, not backfilled here).
