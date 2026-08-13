# Aluria Schema Addendum — v37: `employee_profile` table + self-service profile API

**Adds `aluria_dev.employee_profile`** — a one-row-per-user extension table holding the HR-facing profile fields `CORE_SCHEMA.app_user` has no columns for: gender, birth date, address, emergency contact, employee code, department, employment type, join date, direct supervisor, and work location. `CORE_SCHEMA.app_user` stays untouched — it's shared across every app on `movira_core`, and these fields are Aluria/ERP-specific, so they live in `APP_SCHEMA` instead, keyed by `app_user_id`.

This backs the new self-service `GET /api/v2/profile` / `PUT /api/v2/profile` endpoints (`v2/profile/index.php`) — a user viewing/editing their own profile. `employee_profile` is deliberately designed to be reused as-is by a future HR-admin "employee" module (list/manage all employees in a company) rather than being profile-endpoint-specific — that module is not part of this addendum and does not exist yet.

## Code changes (this session)

- `v2/profile/index.php` (new): `GET /api/v2/profile` returns the caller's own profile — `app_user` fields (`first_name`, `last_name`, `email`, `phone_number`, `position_id`/`position_name`, `account_status`) LEFT JOINed with `employee_profile` (all extension fields, `NULL` until first set) and the resolved supervisor's name. `PUT`/`PATCH /api/v2/profile` lets the caller edit their own **personal** fields only: `first_name`, `last_name`, `phone_number` (on `app_user`) and `gender`, `birth_date`, `address`, `emergency_contact_name`, `emergency_contact_phone` (on `employee_profile`, upserted — the row is created on first write). Employment fields (`employee_code`, `department`, `employment_type`, `join_date`, `supervisor_id`, `work_location`) are read-only through this endpoint by design — silently ignored if sent, intended to be HR-admin-managed by the future employee module, not self-edited.
- `v2/index.php`: routes `profile` → the new module.

## DEV — already applied

Run against `aluria_dev` on **2026-08-13**, via direct `mysqli` through `v2/connection/db.php` (same tool used for `v32`–`v36`). Idempotent via the `information_schema` guard. `COLLATE utf8mb4_general_ci` is pinned explicitly per [[project_schema_collation_mismatch]] — `aluria_dev`'s connection/schema default is `utf8mb4_0900_ai_ci` and would otherwise silently mismatch every sibling `APP_SCHEMA` table.

```sql
-- ============================================================================
-- v37 DEV — employee_profile table
-- ============================================================================

SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE table_schema = 'aluria_dev' AND table_name = 'employee_profile'
);
SET @create_sql := IF(@schema_check > 0,
  'SELECT ''employee_profile already exists, skipping'' AS note',
  'CREATE TABLE aluria_dev.employee_profile (
     id                       VARCHAR(50) NOT NULL PRIMARY KEY,
     company_id               VARCHAR(50) NOT NULL,
     app_user_id              VARCHAR(50) NOT NULL,
     gender                   ENUM(''male'',''female'') NULL,
     birth_date               DATE NULL,
     address                  TEXT NULL,
     emergency_contact_name   VARCHAR(200) NULL,
     emergency_contact_phone  VARCHAR(50) NULL,
     employee_code            VARCHAR(50) NULL,
     department               VARCHAR(200) NULL,
     employment_type          ENUM(''full_time'',''part_time'',''contract'',''intern'') NULL,
     join_date                DATE NULL,
     supervisor_id             VARCHAR(50) NULL,
     work_location             VARCHAR(200) NULL,
     created_by                VARCHAR(50) NOT NULL,
     created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     updated_by                VARCHAR(50) NULL,
     updated_at                DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
     deleted_at                DATETIME NULL,
     UNIQUE KEY uq_employee_profile_app_user (app_user_id),
     UNIQUE KEY uq_employee_profile_company_code (company_id, employee_code),
     KEY idx_employee_profile_company (company_id),
     KEY idx_employee_profile_supervisor (supervisor_id)
   ) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
);
PREPARE create_employee_profile FROM @create_sql;
EXECUTE create_employee_profile;
DEALLOCATE PREPARE create_employee_profile;

-- Verification
DESCRIBE aluria_dev.employee_profile;
SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'aluria_dev' AND TABLE_NAME = 'employee_profile';
```

**DEV RESULT:** ran 2026-08-13. Table created — confirmed `utf8mb4_general_ci` via `information_schema.TABLES.TABLE_COLLATION` (not the connection default `utf8mb4_0900_ai_ci`). No rows yet — net-new table, no backfill needed. Smoke-tested end-to-end this session via `php -S` + real JWTs against a real dev user (`usr_6a4a366caf628`, the account this session is running under):
- `GET /api/v2/profile` before any write — returns `app_user` fields (`first_name`/`last_name`/`email`/`phone_number`/`account_status`) with every `employee_profile` field `null`, `employee_profile_id: null`, `is_active: true`.
- `PUT /api/v2/profile` with `{"gender":"male","birth_date":"1990-05-12","address":"...","emergency_contact_name":"Siti Aminah","emergency_contact_phone":"081234567890","phone_number":"081144113071"}` — creates the `employee_profile` row and updates `app_user.phone_number` in the same request/transaction; a follow-up `GET` reflects all six fields.
- `PUT` with only `{"address":""}` — clears `address` back to `NULL` without touching `emergency_contact_name` or any other already-set field (confirms the per-field nullable-clear semantics, not a full-row overwrite).
- `PUT` with `{"gender":"other"}` → `400 gender must be male or female`; `{"phone_number":"12345"}` → `400 phone_number must be a valid Indonesian number...`; `{}` → `400 No fields provided for update`; `{"employee_code":"EMP-9999","department":"Sales"}` alone → `400 No fields provided for update` (confirms employment fields are silently unrecognized, not written, not erroring as "invalid field").
- No `Authorization` header → `401 Authorization header missing or malformed` (confirms `requireAuth()` gate).
Test data is the real dev account's own profile, not synthetic rows — no cleanup needed.

No `movira_core_dev` (CORE_SCHEMA) changes in this addendum — `app_user` is read and its `first_name`/`last_name`/`phone_number` columns are updated by the new endpoint, but no DDL change was made to it.

---

## PROD — NOT yet applied

**Do not run this against production without sign-off first** — same review gate as every other schema change in this project. Purely additive (new table, no existing data touched).

```sql
-- ============================================================================
-- v37 PROD — employee_profile table
-- ============================================================================

SET @app_schema := 'aluria'; -- replace with prod's real APP_SCHEMA (from prod's .env)

SET @sql := CONCAT(
  'SELECT COUNT(*) FROM information_schema.TABLES ',
  'WHERE table_schema = ''', @app_schema, ''' AND table_name = ''employee_profile'''
);
PREPARE schema_check FROM @sql; EXECUTE schema_check; DEALLOCATE PREPARE schema_check;
-- If the count above is > 0, skip Step 1 — table already exists.

-- ----------------------------------------------------------------------------
-- STEP 1 — create table
-- ----------------------------------------------------------------------------
SET @sql := CONCAT(
  'CREATE TABLE ', @app_schema, '.employee_profile (
     id                       VARCHAR(50) NOT NULL PRIMARY KEY,
     company_id               VARCHAR(50) NOT NULL,
     app_user_id              VARCHAR(50) NOT NULL,
     gender                   ENUM(''male'',''female'') NULL,
     birth_date               DATE NULL,
     address                  TEXT NULL,
     emergency_contact_name   VARCHAR(200) NULL,
     emergency_contact_phone  VARCHAR(50) NULL,
     employee_code            VARCHAR(50) NULL,
     department               VARCHAR(200) NULL,
     employment_type          ENUM(''full_time'',''part_time'',''contract'',''intern'') NULL,
     join_date                DATE NULL,
     supervisor_id             VARCHAR(50) NULL,
     work_location             VARCHAR(200) NULL,
     created_by                VARCHAR(50) NOT NULL,
     created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     updated_by                VARCHAR(50) NULL,
     updated_at                DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
     deleted_at                DATETIME NULL,
     UNIQUE KEY uq_employee_profile_app_user (app_user_id),
     UNIQUE KEY uq_employee_profile_company_code (company_id, employee_code),
     KEY idx_employee_profile_company (company_id),
     KEY idx_employee_profile_supervisor (supervisor_id)
   ) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
);
PREPARE create_employee_profile FROM @sql;
EXECUTE create_employee_profile;
DEALLOCATE PREPARE create_employee_profile;

-- ----------------------------------------------------------------------------
-- STEP 2 — verification
-- ----------------------------------------------------------------------------
SET @sql := CONCAT('DESCRIBE ', @app_schema, '.employee_profile');
PREPARE verify_describe FROM @sql; EXECUTE verify_describe; DEALLOCATE PREPARE verify_describe;
```

**Deploy order:** schema change (this table) must land before the code deploy — `GET`/`PUT /api/v2/profile` will error on every call against prod if the table doesn't exist yet, since `getMyProfile()` unconditionally LEFT JOINs it. Deploying the code first without the table is not a safe no-op.

---

## Post-migration checklist

- [ ] Get sign-off, then run the PROD block above.
- [ ] `docs/api/profile.md` generated this session for dev's shape.
- [ ] No permission keys involved — `/api/v2/profile` is self-service (any authenticated user acts on their own record), gated by `requireAuth()` only, no `permission_key` check. This mirrors `/api/v2/account/my-permissions`, not the tenant-resource modules.
- [ ] `employee_code`, `department`, `employment_type`, `join_date`, `supervisor_id`, `work_location` are left `NULL` for every existing user — there is no source data to backfill them from (no prior "employee" concept existed anywhere in this codebase before this addendum). Populating them is deliberately left for the future HR-admin employee module, not guessed here.
- [ ] The future "employee API" (HR admin listing/editing arbitrary employees, setting the employment fields) is not built by this addendum — only the shared table and the self-service endpoint exist so far.
