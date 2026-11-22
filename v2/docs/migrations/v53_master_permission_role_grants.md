# Aluria Schema Addendum — v53: grant `master.*` write keys to every role that holds the matching `settings.*` keys

The v2 master endpoints gate writes on `master.*` keys (`userHasPermission(..., 'master.customer.create')` etc.), but the role
grants from v24 are on the older `settings.*` keys. v47 seeded most missing `master.*` keys but granted them to **Business Owner
only**, and never seeded `master.customer.*`. Result: every non-owner role (e.g. Admin Sales) gets
`403 You do not have permission to manage this master data` on create/update/delete, even though it holds `settings.customer.*`.
Seen on prod: `POST /v2/customer` by an Admin Sales user, `ERR_FORBIDDEN`.

No code change — data only. For each mapping below, every role that holds the `settings.*` source key receives the `master.*`
target key. Where one `master.*.manage` key covers create/update/delete, a role holding **any** of the three source keys gets it.
Missing `master.*` rows are inserted into `app_permission` first (anti-join, so existing rows are untouched). Purely additive,
idempotent, scoped to Aluria's `app_id`; nothing is revoked and the old `settings.*` grants stay.

| master.* key (target) | settings.* keys (source) |
|---|---|
| `master.customer.create` / `.update` / `.delete` | `settings.customer.create` / `.update` / `.delete` |
| `master.supplier.create` / `.update` / `.delete` | `settings.supplier.create` / `.update` / `.delete` |
| `master.product.create` / `.update` / `.delete` | `settings.items.create` / `.update` / `.delete` |
| `master.shipment_period.manage` | `settings.shipment_period.create` / `.update` / `.delete` |
| `master.ship_via.manage` | `settings.shipvia.create` / `.update` / `.delete` |
| `master.warehouse_location.manage` | `settings.warehouse_location.create` / `.update` / `.delete` |
| `master.account_code.manage` | `settings.account_code.create` / `.update` / `.delete` |
| `master.bank_account.manage` | `settings.bank_account.create` / `.update` / `.delete` |
| `master.unit_of_measure.manage` | `settings.uom.create` / `.update` / `.delete` |

**Deliberately not mapped** (no unambiguous `settings.*` source — decide per role, see checklist): `master.payment_method.manage`,
`master.payment_term.manage`, `master.ppn_type.manage`, `master.company_setting.manage`, `master.currency.manage`,
`master.origin.manage`, `master.finance_category.manage`, `master.sales_target.manage`, `master.document_center.manage`,
`master.document_signature.manage`, `master.document_watermark.manage`, `master.salary_category.manage`,
`master.purchase_status.manage`, `master.purchase_type.manage`, `master.sales_status.manage`, `master.region.manage`.
These stay Business Owner only (or whatever they already have).

## DEV (`movira_core_dev`) — NOT yet applied

Same SQL as PROD with `movira_core` → `movira_core_dev`.

## PROD (`movira_core`) — NOT yet applied

Run top to bottom in one session (the temp table is session-scoped). The temp table is pinned to
`utf8mb4_general_ci`, and the app id is inlined as a literal (a `SET @var` gets the connection collation and raises error 1267 against the `utf8mb4_0900_ai_ci` columns).

Preview (read-only) — roles that will gain each target key:

```sql
USE movira_core;

CREATE TEMPORARY TABLE tmp_master_map (
  master_key   VARCHAR(100) NOT NULL,
  settings_key VARCHAR(100) NOT NULL,
  label        VARCHAR(150) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_master_map (master_key, settings_key, label) VALUES
 ('master.customer.create','settings.customer.create','Create Customer'),
 ('master.customer.update','settings.customer.update','Update Customer'),
 ('master.customer.delete','settings.customer.delete','Delete Customer'),
 ('master.supplier.create','settings.supplier.create','Create Supplier'),
 ('master.supplier.update','settings.supplier.update','Update Supplier'),
 ('master.supplier.delete','settings.supplier.delete','Delete Supplier'),
 ('master.product.create','settings.items.create','Create Product'),
 ('master.product.update','settings.items.update','Update Product'),
 ('master.product.delete','settings.items.delete','Delete Product'),
 ('master.shipment_period.manage','settings.shipment_period.create','Manage Shipment Period Master Data'),
 ('master.shipment_period.manage','settings.shipment_period.update','Manage Shipment Period Master Data'),
 ('master.shipment_period.manage','settings.shipment_period.delete','Manage Shipment Period Master Data'),
 ('master.ship_via.manage','settings.shipvia.create','Manage Ship Via Master Data'),
 ('master.ship_via.manage','settings.shipvia.update','Manage Ship Via Master Data'),
 ('master.ship_via.manage','settings.shipvia.delete','Manage Ship Via Master Data'),
 ('master.warehouse_location.manage','settings.warehouse_location.create','Manage Warehouse Location Master Data'),
 ('master.warehouse_location.manage','settings.warehouse_location.update','Manage Warehouse Location Master Data'),
 ('master.warehouse_location.manage','settings.warehouse_location.delete','Manage Warehouse Location Master Data'),
 ('master.account_code.manage','settings.account_code.create','Manage Account Code Master Data'),
 ('master.account_code.manage','settings.account_code.update','Manage Account Code Master Data'),
 ('master.account_code.manage','settings.account_code.delete','Manage Account Code Master Data'),
 ('master.bank_account.manage','settings.bank_account.create','Manage Bank Account Master Data'),
 ('master.bank_account.manage','settings.bank_account.update','Manage Bank Account Master Data'),
 ('master.bank_account.manage','settings.bank_account.delete','Manage Bank Account Master Data'),
 ('master.unit_of_measure.manage','settings.uom.create','Manage Unit of Measure Master Data'),
 ('master.unit_of_measure.manage','settings.uom.update','Manage Unit of Measure Master Data'),
 ('master.unit_of_measure.manage','settings.uom.delete','Manage Unit of Measure Master Data');

SELECT r.role_name, m.master_key
FROM tmp_master_map m
JOIN app_permission sp ON sp.app_id = 'appe16592dc7b88eab9' AND sp.permission_key COLLATE utf8mb4_general_ci = m.settings_key
JOIN app_role_permission rp ON rp.permission_id = sp.permission_id
JOIN app_role r ON r.app_role_id = rp.app_role_id AND r.app_id = 'appe16592dc7b88eab9'
LEFT JOIN app_permission mp ON mp.app_id = 'appe16592dc7b88eab9' AND mp.permission_key COLLATE utf8mb4_general_ci = m.master_key
LEFT JOIN app_role_permission ex ON ex.app_role_id = rp.app_role_id AND ex.permission_id = mp.permission_id
WHERE ex.app_role_id IS NULL
GROUP BY r.role_name, m.master_key
ORDER BY r.role_name, m.master_key;
```

Apply (same session, temp table still in place):

```sql
INSERT INTO app_permission (permission_id, app_id, permission_key, module, label, description)
SELECT UUID(), 'appe16592dc7b88eab9', m.master_key, 'Master', MIN(m.label), ''
FROM tmp_master_map m
WHERE NOT EXISTS (
  SELECT 1 FROM app_permission p
  WHERE p.app_id = 'appe16592dc7b88eab9' AND p.permission_key COLLATE utf8mb4_general_ci = m.master_key
)
GROUP BY m.master_key;

INSERT INTO app_role_permission (app_role_id, permission_id)
SELECT DISTINCT rp.app_role_id, mp.permission_id
FROM tmp_master_map m
JOIN app_permission sp ON sp.app_id = 'appe16592dc7b88eab9' AND sp.permission_key COLLATE utf8mb4_general_ci = m.settings_key
JOIN app_role_permission rp ON rp.permission_id = sp.permission_id
JOIN app_role r ON r.app_role_id = rp.app_role_id AND r.app_id = 'appe16592dc7b88eab9'
JOIN app_permission mp ON mp.app_id = 'appe16592dc7b88eab9' AND mp.permission_key COLLATE utf8mb4_general_ci = m.master_key
WHERE NOT EXISTS (
  SELECT 1 FROM app_role_permission ex
  WHERE ex.app_role_id = rp.app_role_id AND ex.permission_id = mp.permission_id
);

DROP TEMPORARY TABLE tmp_master_map;
```

Verify — Admin Sales should now hold the three `master.customer.*` keys:

```sql
SELECT r.role_name, p.permission_key
FROM app_role r
JOIN app_role_permission rp ON rp.app_role_id = r.app_role_id
JOIN app_permission p ON p.permission_id = rp.permission_id
WHERE r.app_id = 'appe16592dc7b88eab9' AND p.permission_key LIKE 'master.customer.%'
ORDER BY r.role_name, p.permission_key;
```

## Post-migration checklist

- [ ] Run the preview first and sanity-check which roles gain which keys (e.g. no read-only role should appear).
- [ ] Users must re-login (or refresh their token) only if `app_role_id` is cached in the JWT and permissions are cached client-side; the backend reads grants from the DB on every request, so no re-login is needed for API access.
- [ ] Retest `POST /v2/customer` as the Admin Sales user.
- [ ] Decide which roles (if any) should get the unmapped `master.*.manage` keys listed above.
