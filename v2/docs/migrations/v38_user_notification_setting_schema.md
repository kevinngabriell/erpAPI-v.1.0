# Aluria Schema Addendum — v38: per-user WhatsApp notification opt-out

Adds one new `aluria_dev` (`APP_SCHEMA`) table and one new `movira_core_dev` (`CORE_SCHEMA`) permission key, so a user can turn off WhatsApp delivery for themselves (in-app notifications are unaffected), and an admin holding a new permission key can do it on another user's behalf. No existing table is altered.

This follows the same generic key/value precedent `company_setting` set in [v23_notification_schema.md](v23_notification_schema.md) — no `notification_settings`-style single-purpose table, and no new column on `CORE_SCHEMA.app_user` (that table is shared across apps via `movira_core`, so app-specific preference columns don't belong there).

## Design

- **`user_setting`** — generic, per-user, company-scoped key/value store, structurally identical to `company_setting` except keyed by `user_id` instead of `company_id`. First (and only, for now) key: `notification.whatsapp.enabled`, value `'1'` or `'0'`. Missing row = enabled (default-on, matches today's unconditional-send behavior so existing users see no change until they opt out).
- **`notification.user_setting.manage`** permission key — lets a caller view/update this setting for a `user_id` other than themselves (own setting is always self-service, no permission needed).
- `notify()` in `v2/helpers/notification.php` checks this setting right before the WhatsApp send and writes a `notification_delivery` row with `status = 'skipped'`, `error_message = 'Disabled by user'` when off — same pattern already used for "no phone number on file". In-app delivery and the WebSocket push are untouched; this only gates the WhatsApp channel.

---

## DEV — already applied

Run against `aluria_dev` / `movira_core_dev` on **2026-09-14**, by Claude (this session), against the Tailscale dev host (`100.98.160.119`). All statements are idempotent (`CREATE TABLE IF NOT EXISTS`, `INSERT ... ON DUPLICATE KEY UPDATE`), safe to re-run.

```sql
-- ============================================================================
-- v38 DEV — user_setting table (aluria_dev)
-- ============================================================================

USE `aluria_dev`;

CREATE TABLE IF NOT EXISTS aluria_dev.user_setting (
  id             VARCHAR(50)  NOT NULL,
  company_id     VARCHAR(50)  NOT NULL,
  user_id        VARCHAR(50)  NOT NULL,
  setting_key    VARCHAR(100) NOT NULL,
  setting_value  TEXT         NOT NULL,
  created_by     VARCHAR(50)  NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by     VARCHAR(50)  NULL,
  updated_at     DATETIME     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_setting_key (user_id, setting_key),
  KEY idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Verification
SHOW TABLES FROM aluria_dev LIKE 'user_setting';
DESCRIBE aluria_dev.user_setting;
```

```sql
-- ============================================================================
-- v38 DEV — notification.user_setting.manage permission key (movira_core_dev)
-- ============================================================================

USE `movira_core_dev`;

-- app_id 'appe16592dc7b88eab9' is Aluria's app row (matches APP_ID in v2/.env,
-- same value used in v23/v29/v36).
INSERT INTO movira_core_dev.app_permission (permission_id, app_id, permission_key, module, label, description)
SELECT 'perm9d24f6b81ce307a5', 'appe16592dc7b88eab9', 'notification.user_setting.manage', 'Notification', 'Manage User Notification Settings', 'Can view and update another user''s WhatsApp notification opt-out setting'
WHERE NOT EXISTS (
  SELECT 1 FROM movira_core_dev.app_permission WHERE permission_key = 'notification.user_setting.manage'
);

-- Verification
SELECT permission_id, permission_key, module, label FROM movira_core_dev.app_permission WHERE permission_key = 'notification.user_setting.manage';
```

**DEV verification result:** table created (confirmed via `SHOW TABLES`/`DESCRIBE`), permission key inserted (confirmed via the `SELECT`). Smoke test: inserted and deleted a throwaway `user_setting` row for a real dev `user_id` via `PUT notification-settings/whatsapp-opt-out` and confirmed `isWhatsAppEnabledForUser()` toggled correctly before/after (see task notes — no leftover test data).

---

## PROD — applied

Confirmed against prod's actual `.env` on **2026-09-14** (sign-off obtained): prod's real schema names are **not** `aluria_prod`/`movira_core_prod` as guessed below — prod shares `CORE_SCHEMA=movira_core_dev` with dev (same DB server, `100.98.160.119`), and `APP_SCHEMA=aluria` (no suffix).

Read-only checks before writing anything:
- `aluria.user_setting` **already existed**, structurally identical to the DEV table above (`DESCRIBE` matched column-for-column) and `utf8mb4_general_ci` throughout — 0 rows. No `CREATE TABLE` needed.
- `movira_core_dev.app_permission` already had the `notification.user_setting.manage` key (`perm9d24f6b81ce307a5`, `app_id = appe16592dc7b88eab9`) — because `CORE_SCHEMA` is shared with dev, the DEV permission INSERT above already covered prod. No second INSERT needed.

Only action taken: applied the WhatsApp opt-out for `usr_6aa0da60b8249` (company `cmp7738323d94503bbc`) via `setUserSetting()` + `insertAuditLog()` directly against `aluria.user_setting` / `aluria.audit_log`, mirroring exactly what `PUT notification-settings/whatsapp-opt-out/{user_id}` does. Verified `isWhatsAppEnabledForUser()` returns `false` post-write.

```sql
-- Original placeholder template (kept for reference — not what was run,
-- since the table/permission already existed under prod's real schema names):
-- Re-run the DEV SQL above with `aluria_dev` → `aluria_prod` and
-- `movira_core_dev` → `movira_core_prod`, after confirming prod's real
-- schema names from its own .env (do not assume — verify).
```

---

## Post-migration checklist

- [ ] **No role currently holds `notification.user_setting.manage`.** Until a role is granted it, only self-service (a user toggling their own setting) works — nobody can toggle it on another user's behalf. Decide which roles (HR? Business Owner?) should hold this, same open-decision pattern `v23` left for the two `notification.*.approver` keys.
- [ ] `user_setting` starts empty — every existing user defaults to WhatsApp-enabled (`isWhatsAppEnabledForUser()` treats a missing row as enabled), so this migration changes no one's current notification behavior until someone explicitly opts out.
- [ ] This setting only gates the WhatsApp channel. In-app notifications and the WebSocket push are unaffected — a user who disables WhatsApp still sees the notification in-app.
