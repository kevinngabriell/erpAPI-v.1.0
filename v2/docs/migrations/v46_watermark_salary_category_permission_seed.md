# Aluria Seed Migration — v46: watermark & salary-category manage permissions

## Summary

`master/document-watermark` and `master/salary-category` gate every write
(`POST`/`PUT`/`DELETE`) on `master.document_watermark.manage` and
`master.salary_category.manage`, but neither key existed in
`app_permission` (verified on 2026-10-19 in both `movira_core_dev` and
`movira_core`). `userHasPermission()` can never pass for a key that isn't in
the catalog, so every write on both modules returned `403` for every role.

This migration only creates the two catalog rows. It does **not** assign them
to any role — which roles may manage watermarks and salary categories is a
business decision, left as a checklist item below. Until a role is granted
the key, writes keep returning `403` (now with an assignable key behind it).

Row shape follows the existing `master.*.manage` rows
(`module = 'Master'`, `label = 'Manage … Master Data'`, empty description,
`app_id` = Aluria's app).

## DEV (`movira_core_dev`) — applied 2026-10-19

```sql
-- WHY NOT EXISTS: re-runnable; permission_key has no unique index to lean on.
INSERT INTO movira_core_dev.app_permission (permission_id, app_id, permission_key, module, label, description)
SELECT UUID(), 'appe16592dc7b88eab9', 'master.document_watermark.manage', 'Master', 'Manage Document Watermark Master Data', ''
WHERE NOT EXISTS (SELECT 1 FROM movira_core_dev.app_permission WHERE app_id = 'appe16592dc7b88eab9' AND permission_key = 'master.document_watermark.manage');

INSERT INTO movira_core_dev.app_permission (permission_id, app_id, permission_key, module, label, description)
SELECT UUID(), 'appe16592dc7b88eab9', 'master.salary_category.manage', 'Master', 'Manage Salary Category Master Data', ''
WHERE NOT EXISTS (SELECT 1 FROM movira_core_dev.app_permission WHERE app_id = 'appe16592dc7b88eab9' AND permission_key = 'master.salary_category.manage');
```

## PROD (`movira_core`) — NOT yet applied

Confirm Aluria's production `app_id` first — the value below is the dev one
from `v2/.env`; use whatever `APP_ID` the production deployment runs with.

```sql
SET @aluria_app_id = 'appe16592dc7b88eab9'; -- WHY: replace with prod APP_ID if it differs

INSERT INTO movira_core.app_permission (permission_id, app_id, permission_key, module, label, description)
SELECT UUID(), @aluria_app_id, 'master.document_watermark.manage', 'Master', 'Manage Document Watermark Master Data', ''
WHERE NOT EXISTS (SELECT 1 FROM movira_core.app_permission WHERE app_id = @aluria_app_id AND permission_key = 'master.document_watermark.manage');

INSERT INTO movira_core.app_permission (permission_id, app_id, permission_key, module, label, description)
SELECT UUID(), @aluria_app_id, 'master.salary_category.manage', 'Master', 'Manage Salary Category Master Data', ''
WHERE NOT EXISTS (SELECT 1 FROM movira_core.app_permission WHERE app_id = @aluria_app_id AND permission_key = 'master.salary_category.manage');
```

## Verification

```sql
SELECT permission_key, module, label,
       (SELECT COUNT(*) FROM movira_core_dev.app_role_permission rp WHERE rp.permission_id = p.permission_id) AS roles_granted
FROM movira_core_dev.app_permission p
WHERE permission_key IN ('master.document_watermark.manage', 'master.salary_category.manage');
-- expect 2 rows, roles_granted = 0 (assignment is a separate decision)
```

## Post-migration checklist

- [ ] Decide which role(s) get `master.document_watermark.manage` and grant it.
- [ ] Decide which role(s) get `master.salary_category.manage` and grant it.
- [ ] Apply the PROD block (with the correct prod `APP_ID`).
