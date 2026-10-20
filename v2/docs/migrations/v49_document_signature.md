# Aluria Schema Addendum — v49: `document_signature` (per-company export signers)

The `.xlsx` exports of Sales Order, SPPB and Sales Profit printed hardcoded signer names
(`( ADMIN SALES )`, `( TUKAR FAKTUR )`, `( SULANTO )    ( IRENE )`) for every company. Those
names now come from `document_signature`, one row per `(company_id, document_type)`; a slot may
hold several names separated by commas. An unconfigured slot prints an empty `(      )` box.

Venken's existing values are seeded for every company named `Venken%` so its exports are unchanged.

Also adds permission key `master.document_signature.manage` (gates `PUT /document-signature/{type}`).
Assign it to the roles that may edit signers (business decision).

## DEV (`aluria_dev` / `movira_core_dev`) — applied 2026-10-20

```sql
CREATE TABLE IF NOT EXISTS `aluria_dev`.`document_signature` (
  `id` varchar(36) COLLATE utf8mb4_general_ci NOT NULL,
  `company_id` varchar(36) COLLATE utf8mb4_general_ci NOT NULL,
  `document_type` enum('sales_order','sales_sppb','sales_profit') COLLATE utf8mb4_general_ci NOT NULL,
  `created_by_names` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `acknowledged_by_names` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `approved_by_names` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_by` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_by` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_signature` (`company_id`,`document_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `aluria_dev`.`document_signature`
  (id, company_id, document_type, created_by_names, acknowledged_by_names, approved_by_names, created_by, created_at)
SELECT CONCAT('dsg_', SUBSTRING(REPLACE(UUID(),'-',''),1,13)), c.company_id, t.document_type,
       'ADMIN SALES', 'TUKAR FAKTUR', 'SULANTO, IRENE', 'migration', NOW()
FROM `movira_core_dev`.`app_company` c
CROSS JOIN (SELECT 'sales_order' AS document_type UNION SELECT 'sales_sppb' UNION SELECT 'sales_profit') t
WHERE c.company_name LIKE 'Venken%'
  AND NOT EXISTS (SELECT 1 FROM `aluria_dev`.`document_signature` d
                  WHERE d.company_id = c.company_id COLLATE utf8mb4_general_ci AND d.document_type = t.document_type);

INSERT INTO movira_core_dev.app_permission (permission_id, app_id, permission_key, module, label, description)
SELECT UUID(), 'appe16592dc7b88eab9', 'master.document_signature.manage', 'Master', 'Manage Document Signature Master Data', ''
WHERE NOT EXISTS (SELECT 1 FROM movira_core_dev.app_permission WHERE app_id = 'appe16592dc7b88eab9' AND permission_key = 'master.document_signature.manage');
```

## PROD (`aluria` / `movira_core`) — NOT yet applied

Apply before deploying this code. Same statements with `aluria` / `movira_core` schema names and
`@aluria_app_id` set to the production `APP_ID`.

## Verification

```sql
SELECT c.company_name, d.document_type, d.created_by_names, d.acknowledged_by_names, d.approved_by_names
FROM aluria_dev.document_signature d JOIN movira_core_dev.app_company c ON c.company_id = d.company_id COLLATE utf8mb4_general_ci;
-- expect 3 rows per Venken company
```
