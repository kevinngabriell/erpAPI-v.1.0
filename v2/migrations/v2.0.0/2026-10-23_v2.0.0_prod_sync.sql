-- =====================================================================================
-- Aluria v2.0.0 production sync  (aluria -> schema/data expected by branch dev/release/v.2.0.0)
-- File : v2/migrations/v2.0.0/2026-10-23_v2.0.0_prod_sync.sql
-- Built: 2026-10-23. DDL below is generated from SHOW CREATE TABLE on aluria_dev, never hand-typed.
--
-- HOW TO RUN (in this order)
--   1. Full backup:  mysqldump --single-transaction --routines --events aluria > aluria_pre_v2.0.0.sql
--   2. Put the app in maintenance mode (stop PHP-FPM / the notification ws-server and cron).
--   3. In your SQL client choose the database  aluria  as the DEFAULT schema, then run THIS WHOLE FILE
--      in ONE session (user variables hold the before-counts used by the verification at the end).
--      The client MUST stop on the first error (do not tick ignore errors).
--      Step 0 stops by selecting from a missing table named PREFLIGHT_FAILED__<check> BEFORE any change.
--   4. Read the three verification result sets at the very end: every problems/lost_rows value must be 0.
--   5. Deploy the release (git pull, composer install --no-dev), then leave maintenance mode and re-check.
--
-- SAFE TO RE-RUN: every step is guarded (information_schema / IS NULL / NOT EXISTS). DDL cannot roll back.
-- Nothing is dropped, renamed or re-keyed. No helper tables, procedures or temporary tables are created.
--
-- NOT IN THIS FILE (see NOTES at the bottom): movira_core permission seeds (docs v46, v47, v49),
-- v50 default GL accounts, v51 lot double count fix, and every dev-only row.
-- =====================================================================================

-- ======================= STEP 0: PREFLIGHT (changes nothing) =======================
SET @expected_db = IFNULL(@expected_db, 'aluria');
SET @s = IF(DATABASE() IS NULL OR CONVERT(DATABASE() USING utf8mb4) COLLATE utf8mb4_general_ci <> CONVERT(@expected_db USING utf8mb4) COLLATE utf8mb4_general_ci, 'SELECT * FROM PREFLIGHT_FAILED__wrong_database', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(CAST(SUBSTRING_INDEX(VERSION(), '.', 1) AS UNSIGNED) < 8, 'SELECT * FROM PREFLIGHT_FAILED__mysql_version_below_8', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('account_code','document_watermark','finance_category','general_journal','legacy_id_map','sales_sppb','sales_status','warehouse_lot','warehouse_transaction','warehouse_transaction_item')) <> 10, 'SELECT * FROM PREFLIGHT_FAILED__required_tables_missing', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM finance_category WHERE category_type IS NULL) > 0, 'SELECT * FROM PREFLIGHT_FAILED__finance_category_null_category_type', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM sales_sppb WHERE status_id IS NULL) > 0, 'SELECT * FROM PREFLIGHT_FAILED__sales_sppb_null_status_id', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'document_signature') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'document_signature' AND column_name = 'acknowledged_by_names') = 0, 'SELECT * FROM PREFLIGHT_FAILED__document_signature_unexpected_shape', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'subscription_payment') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'subscription_payment' AND column_name = 'pending_company_key') = 0, 'SELECT * FROM PREFLIGHT_FAILED__subscription_payment_unexpected_shape', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'payment_webhook_event') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payment_webhook_event' AND column_name = 'event_type') = 0, 'SELECT * FROM PREFLIGHT_FAILED__payment_webhook_event_unexpected_shape', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ======================= STEP 1: BEFORE-COUNTS (read only) =======================
SET @pre_account_code = (SELECT COUNT(*) FROM `account_code`);
SET @pre_api_error_log = (SELECT COUNT(*) FROM `api_error_log`);
SET @pre_approval_action_token = (SELECT COUNT(*) FROM `approval_action_token`);
SET @pre_audit_log = (SELECT COUNT(*) FROM `audit_log`);
SET @pre_bank_account = (SELECT COUNT(*) FROM `bank_account`);
SET @pre_company_setting = (SELECT COUNT(*) FROM `company_setting`);
SET @pre_company_setting_detail = (SELECT COUNT(*) FROM `company_setting_detail`);
SET @pre_company_setting_menu = (SELECT COUNT(*) FROM `company_setting_menu`);
SET @pre_currency = (SELECT COUNT(*) FROM `currency`);
SET @pre_customer = (SELECT COUNT(*) FROM `customer`);
SET @pre_document_center = (SELECT COUNT(*) FROM `document_center`);
SET @pre_document_watermark = (SELECT COUNT(*) FROM `document_watermark`);
SET @pre_employee_profile = (SELECT COUNT(*) FROM `employee_profile`);
SET @pre_finance_category = (SELECT COUNT(*) FROM `finance_category`);
SET @pre_finance_payment = (SELECT COUNT(*) FROM `finance_payment`);
SET @pre_finance_transaction = (SELECT COUNT(*) FROM `finance_transaction`);
SET @pre_finance_transaction_detail = (SELECT COUNT(*) FROM `finance_transaction_detail`);
SET @pre_general_journal = (SELECT COUNT(*) FROM `general_journal`);
SET @pre_general_journal_detail = (SELECT COUNT(*) FROM `general_journal_detail`);
SET @pre_legacy_id_map = (SELECT COUNT(*) FROM `legacy_id_map`);
SET @pre_notification = (SELECT COUNT(*) FROM `notification`);
SET @pre_notification_delivery = (SELECT COUNT(*) FROM `notification_delivery`);
SET @pre_origin = (SELECT COUNT(*) FROM `origin`);
SET @pre_payment_method = (SELECT COUNT(*) FROM `payment_method`);
SET @pre_payment_term = (SELECT COUNT(*) FROM `payment_term`);
SET @pre_ppn_type = (SELECT COUNT(*) FROM `ppn_type`);
SET @pre_product = (SELECT COUNT(*) FROM `product`);
SET @pre_public_holiday = (SELECT COUNT(*) FROM `public_holiday`);
SET @pre_purchase_invoice = (SELECT COUNT(*) FROM `purchase_invoice`);
SET @pre_purchase_invoice_item = (SELECT COUNT(*) FROM `purchase_invoice_item`);
SET @pre_purchase_order = (SELECT COUNT(*) FROM `purchase_order`);
SET @pre_purchase_order_item = (SELECT COUNT(*) FROM `purchase_order_item`);
SET @pre_purchase_receive = (SELECT COUNT(*) FROM `purchase_receive`);
SET @pre_purchase_receive_item = (SELECT COUNT(*) FROM `purchase_receive_item`);
SET @pre_purchase_status = (SELECT COUNT(*) FROM `purchase_status`);
SET @pre_purchase_type = (SELECT COUNT(*) FROM `purchase_type`);
SET @pre_region = (SELECT COUNT(*) FROM `region`);
SET @pre_reorder_point = (SELECT COUNT(*) FROM `reorder_point`);
SET @pre_salary_category = (SELECT COUNT(*) FROM `salary_category`);
SET @pre_salary_transaction = (SELECT COUNT(*) FROM `salary_transaction`);
SET @pre_sales_delivery = (SELECT COUNT(*) FROM `sales_delivery`);
SET @pre_sales_delivery_item = (SELECT COUNT(*) FROM `sales_delivery_item`);
SET @pre_sales_invoice = (SELECT COUNT(*) FROM `sales_invoice`);
SET @pre_sales_invoice_item = (SELECT COUNT(*) FROM `sales_invoice_item`);
SET @pre_sales_order = (SELECT COUNT(*) FROM `sales_order`);
SET @pre_sales_order_item = (SELECT COUNT(*) FROM `sales_order_item`);
SET @pre_sales_profit = (SELECT COUNT(*) FROM `sales_profit`);
SET @pre_sales_profit_item = (SELECT COUNT(*) FROM `sales_profit_item`);
SET @pre_sales_sppb = (SELECT COUNT(*) FROM `sales_sppb`);
SET @pre_sales_sppb_item = (SELECT COUNT(*) FROM `sales_sppb_item`);
SET @pre_sales_status = (SELECT COUNT(*) FROM `sales_status`);
SET @pre_sales_target = (SELECT COUNT(*) FROM `sales_target`);
SET @pre_ship_via = (SELECT COUNT(*) FROM `ship_via`);
SET @pre_shipment_period = (SELECT COUNT(*) FROM `shipment_period`);
SET @pre_supplier = (SELECT COUNT(*) FROM `supplier`);
SET @pre_unit_of_measure = (SELECT COUNT(*) FROM `unit_of_measure`);
SET @pre_user_setting = (SELECT COUNT(*) FROM `user_setting`);
SET @pre_warehouse_location = (SELECT COUNT(*) FROM `warehouse_location`);
SET @pre_warehouse_lot = (SELECT COUNT(*) FROM `warehouse_lot`);
SET @pre_warehouse_transaction = (SELECT COUNT(*) FROM `warehouse_transaction`);
SET @pre_warehouse_transaction_item = (SELECT COUNT(*) FROM `warehouse_transaction_item`);

-- ======================= STEP 2: SCHEMA (additive or loosening, guarded) =======================
SET @old_fk_checks = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

-- document_signature
CREATE TABLE IF NOT EXISTS `document_signature` (
  `id` varchar(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `company_id` varchar(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `document_type` enum('sales_order','sales_sppb','sales_profit') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_by_names` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `acknowledged_by_names` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `approved_by_names` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_by` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_by` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_signature` (`company_id`,`document_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- subscription_payment
CREATE TABLE IF NOT EXISTS `subscription_payment` (
  `id` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `company_id` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `service_id` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `subscription_id` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `order_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `gateway_payment_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `payment_link_url` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `amount` bigint NOT NULL,
  `fee` bigint DEFAULT NULL,
  `net_amount` bigint DEFAULT NULL,
  `billing_cycle` enum('monthly','yearly') COLLATE utf8mb4_general_ci NOT NULL,
  `status` enum('pending','completed','failed','expired') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `pending_company_key` varchar(50) COLLATE utf8mb4_general_ci GENERATED ALWAYS AS (if((`status` = _utf8mb4'pending'),`company_id`,NULL)) STORED,
  `expires_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `activated_at` datetime DEFAULT NULL,
  `created_by` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subscription_payment_order` (`order_id`),
  UNIQUE KEY `uq_subscription_payment_pending` (`pending_company_key`),
  KEY `idx_company_created` (`company_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- payment_webhook_event
CREATE TABLE IF NOT EXISTS `payment_webhook_event` (
  `id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `event_type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `order_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `received_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- document_watermark: name column, object-storage key, inline image becomes optional
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'document_watermark' AND column_name = 'watermark_name') = 0, 'ALTER TABLE document_watermark ADD COLUMN `watermark_name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL AFTER `company_id`', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'document_watermark' AND column_name = 'file_key') = 0, 'ALTER TABLE document_watermark ADD COLUMN `file_key` varchar(512) COLLATE utf8mb4_general_ci DEFAULT NULL AFTER `watermark`', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'document_watermark' AND column_name = 'watermark' AND is_nullable = "NO") = 1, 'ALTER TABLE document_watermark MODIFY COLUMN `watermark` longblob NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;

-- finance_category.category_type: NOT NULL DEFAULT debit (0 NULL rows verified in preflight)
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_category' AND column_name = 'category_type' AND is_nullable = "YES") = 1, 'ALTER TABLE finance_category MODIFY COLUMN `category_type` enum(''debit'',''credit'') COLLATE utf8mb4_general_ci NOT NULL DEFAULT ''debit''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;

-- sales_sppb.status_id: NOT NULL (0 NULL rows verified in preflight) plus company/status index
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_sppb' AND column_name = 'status_id' AND is_nullable = "YES") = 1, 'ALTER TABLE sales_sppb MODIFY COLUMN `status_id` varchar(50) COLLATE utf8mb4_general_ci NOT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sales_sppb' AND index_name = 'idx_company_status') = 0, 'ALTER TABLE sales_sppb ADD INDEX `idx_company_status` (`company_id`, `status_id`)', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;

SET FOREIGN_KEY_CHECKS = @old_fk_checks;

-- ======================= STEP 3: DATA BACKFILL (guarded) =======================
-- 3a. Export signers (v49). The only production company is cmp7738323d94503bbc (carries all sales/finance data).
--     Seeded with the values that were hard-coded in the old exports so the .xlsx output is unchanged.
INSERT INTO document_signature (id, company_id, document_type, created_by_names, acknowledged_by_names, approved_by_names, created_by, created_at)
SELECT CONCAT('dsg_', SUBSTRING(REPLACE(UUID(), '-', ''), 1, 13)), c.company_id, t.document_type, 'ADMIN SALES', 'TUKAR FAKTUR', 'SULANTO, IRENE', 'migration', NOW()
FROM (SELECT _utf8mb4'cmp7738323d94503bbc' COLLATE utf8mb4_general_ci AS company_id) c
CROSS JOIN (SELECT _utf8mb4'sales_order' COLLATE utf8mb4_general_ci AS document_type UNION ALL SELECT 'sales_sppb' UNION ALL SELECT 'sales_profit') t
WHERE EXISTS (SELECT 1 FROM sales_order so WHERE so.company_id = c.company_id)
  AND NOT EXISTS (SELECT 1 FROM document_signature d WHERE d.company_id = c.company_id AND d.document_type = t.document_type);

-- 3b. Restore stock in/out direction of migrated warehouse movements (v52).
--     Per lot the first movement is the receipt (stock_in) and every later one an issue (stock_out).
--     A lot is only touched when it has no non-adjustment movement, every adjustment is a single positive item,
--     and beginning_balance + first - sum(rest) = end_balance holds exactly. Lot balances are never changed.
WITH ranked AS (
  SELECT i.warehouse_lot_id, i.warehouse_transaction_id, i.quantity,
         ROW_NUMBER() OVER (PARTITION BY i.warehouse_lot_id ORDER BY t.transaction_date, t.created_at, t.id) AS rn
  FROM warehouse_transaction_item i
  JOIN warehouse_transaction t ON t.id = i.warehouse_transaction_id AND t.deleted_at IS NULL
  WHERE i.deleted_at IS NULL AND t.transaction_type = 'adjustment' AND i.quantity > 0
    AND (SELECT COUNT(*) FROM warehouse_transaction_item i2 WHERE i2.warehouse_transaction_id = t.id AND i2.deleted_at IS NULL) = 1
),
lots_ok AS (
  SELECT r.warehouse_lot_id
  FROM ranked r
  JOIN warehouse_lot l ON l.id = r.warehouse_lot_id AND l.deleted_at IS NULL
  WHERE NOT EXISTS (SELECT 1 FROM warehouse_transaction_item o
                    JOIN warehouse_transaction ot ON ot.id = o.warehouse_transaction_id AND ot.deleted_at IS NULL
                    WHERE o.warehouse_lot_id = l.id AND o.deleted_at IS NULL AND ot.transaction_type <> 'adjustment')
  GROUP BY r.warehouse_lot_id, l.beginning_balance, l.end_balance
  HAVING l.end_balance = l.beginning_balance + SUM(CASE WHEN r.rn = 1 THEN r.quantity ELSE -r.quantity END)
)
UPDATE warehouse_transaction t
JOIN (SELECT r.warehouse_transaction_id, CASE WHEN r.rn = 1 THEN 'stock_in' ELSE 'stock_out' END AS new_type
      FROM ranked r JOIN lots_ok USING (warehouse_lot_id)) m ON m.warehouse_transaction_id = t.id
SET t.transaction_type = m.new_type
WHERE t.transaction_type = 'adjustment';

-- ======================= STEP 4: VERIFICATION =======================
-- 4a. Schema and data checks. Every problems value must be 0.
SELECT 'missing_tables' AS check_name, 3 - (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('document_signature','subscription_payment','payment_webhook_event')) AS problems
UNION ALL SELECT 'document_watermark_missing_columns', 2 - (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'document_watermark' AND column_name IN ('watermark_name','file_key'))
UNION ALL SELECT 'document_watermark_watermark_not_nullable', (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'document_watermark' AND column_name = 'watermark' AND is_nullable = 'NO')
UNION ALL SELECT 'finance_category_category_type_wrong', 1 - (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_category' AND column_name = 'category_type' AND is_nullable = 'NO' AND column_default = 'debit')
UNION ALL SELECT 'sales_sppb_status_id_nullable', (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_sppb' AND column_name = 'status_id' AND is_nullable = 'YES')
UNION ALL SELECT 'sales_sppb_missing_index', 1 - (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sales_sppb' AND index_name = 'idx_company_status' AND seq_in_index = 2)
UNION ALL SELECT 'document_signature_rows_not_3_for_company', 3 - (SELECT COUNT(*) FROM document_signature WHERE company_id = _utf8mb4'cmp7738323d94503bbc' COLLATE utf8mb4_general_ci AND deleted_at IS NULL)
UNION ALL SELECT 'warehouse_lot_balance_mismatch_after_v52', (SELECT COUNT(*) FROM warehouse_lot l WHERE l.deleted_at IS NULL AND EXISTS (SELECT 1 FROM warehouse_transaction_item i JOIN warehouse_transaction t ON t.id = i.warehouse_transaction_id AND t.deleted_at IS NULL WHERE i.warehouse_lot_id = l.id AND i.deleted_at IS NULL) AND NOT EXISTS (SELECT 1 FROM warehouse_transaction_item i JOIN warehouse_transaction t ON t.id = i.warehouse_transaction_id AND t.deleted_at IS NULL WHERE i.warehouse_lot_id = l.id AND i.deleted_at IS NULL AND t.transaction_type NOT IN ('stock_in','stock_out')) AND l.end_balance <> l.beginning_balance + (SELECT SUM(CASE WHEN t.transaction_type = 'stock_in' THEN i.quantity ELSE -i.quantity END) FROM warehouse_transaction_item i JOIN warehouse_transaction t ON t.id = i.warehouse_transaction_id AND t.deleted_at IS NULL WHERE i.warehouse_lot_id = l.id AND i.deleted_at IS NULL));

-- 4b. No lost rows: before vs after for every table that existed before. lost_rows must be 0.
SELECT 'account_code' AS table_name, @pre_account_code AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_account_code - COUNT(*), 0) AS lost_rows FROM `account_code`
UNION ALL SELECT 'api_error_log' AS table_name, @pre_api_error_log AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_api_error_log - COUNT(*), 0) AS lost_rows FROM `api_error_log`
UNION ALL SELECT 'approval_action_token' AS table_name, @pre_approval_action_token AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_approval_action_token - COUNT(*), 0) AS lost_rows FROM `approval_action_token`
UNION ALL SELECT 'audit_log' AS table_name, @pre_audit_log AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_audit_log - COUNT(*), 0) AS lost_rows FROM `audit_log`
UNION ALL SELECT 'bank_account' AS table_name, @pre_bank_account AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_bank_account - COUNT(*), 0) AS lost_rows FROM `bank_account`
UNION ALL SELECT 'company_setting' AS table_name, @pre_company_setting AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_company_setting - COUNT(*), 0) AS lost_rows FROM `company_setting`
UNION ALL SELECT 'company_setting_detail' AS table_name, @pre_company_setting_detail AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_company_setting_detail - COUNT(*), 0) AS lost_rows FROM `company_setting_detail`
UNION ALL SELECT 'company_setting_menu' AS table_name, @pre_company_setting_menu AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_company_setting_menu - COUNT(*), 0) AS lost_rows FROM `company_setting_menu`
UNION ALL SELECT 'currency' AS table_name, @pre_currency AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_currency - COUNT(*), 0) AS lost_rows FROM `currency`
UNION ALL SELECT 'customer' AS table_name, @pre_customer AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_customer - COUNT(*), 0) AS lost_rows FROM `customer`
UNION ALL SELECT 'document_center' AS table_name, @pre_document_center AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_document_center - COUNT(*), 0) AS lost_rows FROM `document_center`
UNION ALL SELECT 'document_watermark' AS table_name, @pre_document_watermark AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_document_watermark - COUNT(*), 0) AS lost_rows FROM `document_watermark`
UNION ALL SELECT 'employee_profile' AS table_name, @pre_employee_profile AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_employee_profile - COUNT(*), 0) AS lost_rows FROM `employee_profile`
UNION ALL SELECT 'finance_category' AS table_name, @pre_finance_category AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_finance_category - COUNT(*), 0) AS lost_rows FROM `finance_category`
UNION ALL SELECT 'finance_payment' AS table_name, @pre_finance_payment AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_finance_payment - COUNT(*), 0) AS lost_rows FROM `finance_payment`
UNION ALL SELECT 'finance_transaction' AS table_name, @pre_finance_transaction AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_finance_transaction - COUNT(*), 0) AS lost_rows FROM `finance_transaction`
UNION ALL SELECT 'finance_transaction_detail' AS table_name, @pre_finance_transaction_detail AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_finance_transaction_detail - COUNT(*), 0) AS lost_rows FROM `finance_transaction_detail`
UNION ALL SELECT 'general_journal' AS table_name, @pre_general_journal AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_general_journal - COUNT(*), 0) AS lost_rows FROM `general_journal`
UNION ALL SELECT 'general_journal_detail' AS table_name, @pre_general_journal_detail AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_general_journal_detail - COUNT(*), 0) AS lost_rows FROM `general_journal_detail`
UNION ALL SELECT 'legacy_id_map' AS table_name, @pre_legacy_id_map AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_legacy_id_map - COUNT(*), 0) AS lost_rows FROM `legacy_id_map`
UNION ALL SELECT 'notification' AS table_name, @pre_notification AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_notification - COUNT(*), 0) AS lost_rows FROM `notification`
UNION ALL SELECT 'notification_delivery' AS table_name, @pre_notification_delivery AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_notification_delivery - COUNT(*), 0) AS lost_rows FROM `notification_delivery`
UNION ALL SELECT 'origin' AS table_name, @pre_origin AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_origin - COUNT(*), 0) AS lost_rows FROM `origin`
UNION ALL SELECT 'payment_method' AS table_name, @pre_payment_method AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_payment_method - COUNT(*), 0) AS lost_rows FROM `payment_method`
UNION ALL SELECT 'payment_term' AS table_name, @pre_payment_term AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_payment_term - COUNT(*), 0) AS lost_rows FROM `payment_term`
UNION ALL SELECT 'ppn_type' AS table_name, @pre_ppn_type AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_ppn_type - COUNT(*), 0) AS lost_rows FROM `ppn_type`
UNION ALL SELECT 'product' AS table_name, @pre_product AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_product - COUNT(*), 0) AS lost_rows FROM `product`
UNION ALL SELECT 'public_holiday' AS table_name, @pre_public_holiday AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_public_holiday - COUNT(*), 0) AS lost_rows FROM `public_holiday`
UNION ALL SELECT 'purchase_invoice' AS table_name, @pre_purchase_invoice AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_purchase_invoice - COUNT(*), 0) AS lost_rows FROM `purchase_invoice`
UNION ALL SELECT 'purchase_invoice_item' AS table_name, @pre_purchase_invoice_item AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_purchase_invoice_item - COUNT(*), 0) AS lost_rows FROM `purchase_invoice_item`
UNION ALL SELECT 'purchase_order' AS table_name, @pre_purchase_order AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_purchase_order - COUNT(*), 0) AS lost_rows FROM `purchase_order`
UNION ALL SELECT 'purchase_order_item' AS table_name, @pre_purchase_order_item AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_purchase_order_item - COUNT(*), 0) AS lost_rows FROM `purchase_order_item`
UNION ALL SELECT 'purchase_receive' AS table_name, @pre_purchase_receive AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_purchase_receive - COUNT(*), 0) AS lost_rows FROM `purchase_receive`
UNION ALL SELECT 'purchase_receive_item' AS table_name, @pre_purchase_receive_item AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_purchase_receive_item - COUNT(*), 0) AS lost_rows FROM `purchase_receive_item`
UNION ALL SELECT 'purchase_status' AS table_name, @pre_purchase_status AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_purchase_status - COUNT(*), 0) AS lost_rows FROM `purchase_status`
UNION ALL SELECT 'purchase_type' AS table_name, @pre_purchase_type AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_purchase_type - COUNT(*), 0) AS lost_rows FROM `purchase_type`
UNION ALL SELECT 'region' AS table_name, @pre_region AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_region - COUNT(*), 0) AS lost_rows FROM `region`
UNION ALL SELECT 'reorder_point' AS table_name, @pre_reorder_point AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_reorder_point - COUNT(*), 0) AS lost_rows FROM `reorder_point`
UNION ALL SELECT 'salary_category' AS table_name, @pre_salary_category AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_salary_category - COUNT(*), 0) AS lost_rows FROM `salary_category`
UNION ALL SELECT 'salary_transaction' AS table_name, @pre_salary_transaction AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_salary_transaction - COUNT(*), 0) AS lost_rows FROM `salary_transaction`
UNION ALL SELECT 'sales_delivery' AS table_name, @pre_sales_delivery AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_sales_delivery - COUNT(*), 0) AS lost_rows FROM `sales_delivery`
UNION ALL SELECT 'sales_delivery_item' AS table_name, @pre_sales_delivery_item AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_sales_delivery_item - COUNT(*), 0) AS lost_rows FROM `sales_delivery_item`
UNION ALL SELECT 'sales_invoice' AS table_name, @pre_sales_invoice AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_sales_invoice - COUNT(*), 0) AS lost_rows FROM `sales_invoice`
UNION ALL SELECT 'sales_invoice_item' AS table_name, @pre_sales_invoice_item AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_sales_invoice_item - COUNT(*), 0) AS lost_rows FROM `sales_invoice_item`
UNION ALL SELECT 'sales_order' AS table_name, @pre_sales_order AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_sales_order - COUNT(*), 0) AS lost_rows FROM `sales_order`
UNION ALL SELECT 'sales_order_item' AS table_name, @pre_sales_order_item AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_sales_order_item - COUNT(*), 0) AS lost_rows FROM `sales_order_item`
UNION ALL SELECT 'sales_profit' AS table_name, @pre_sales_profit AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_sales_profit - COUNT(*), 0) AS lost_rows FROM `sales_profit`
UNION ALL SELECT 'sales_profit_item' AS table_name, @pre_sales_profit_item AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_sales_profit_item - COUNT(*), 0) AS lost_rows FROM `sales_profit_item`
UNION ALL SELECT 'sales_sppb' AS table_name, @pre_sales_sppb AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_sales_sppb - COUNT(*), 0) AS lost_rows FROM `sales_sppb`
UNION ALL SELECT 'sales_sppb_item' AS table_name, @pre_sales_sppb_item AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_sales_sppb_item - COUNT(*), 0) AS lost_rows FROM `sales_sppb_item`
UNION ALL SELECT 'sales_status' AS table_name, @pre_sales_status AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_sales_status - COUNT(*), 0) AS lost_rows FROM `sales_status`
UNION ALL SELECT 'sales_target' AS table_name, @pre_sales_target AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_sales_target - COUNT(*), 0) AS lost_rows FROM `sales_target`
UNION ALL SELECT 'ship_via' AS table_name, @pre_ship_via AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_ship_via - COUNT(*), 0) AS lost_rows FROM `ship_via`
UNION ALL SELECT 'shipment_period' AS table_name, @pre_shipment_period AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_shipment_period - COUNT(*), 0) AS lost_rows FROM `shipment_period`
UNION ALL SELECT 'supplier' AS table_name, @pre_supplier AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_supplier - COUNT(*), 0) AS lost_rows FROM `supplier`
UNION ALL SELECT 'unit_of_measure' AS table_name, @pre_unit_of_measure AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_unit_of_measure - COUNT(*), 0) AS lost_rows FROM `unit_of_measure`
UNION ALL SELECT 'user_setting' AS table_name, @pre_user_setting AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_user_setting - COUNT(*), 0) AS lost_rows FROM `user_setting`
UNION ALL SELECT 'warehouse_location' AS table_name, @pre_warehouse_location AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_warehouse_location - COUNT(*), 0) AS lost_rows FROM `warehouse_location`
UNION ALL SELECT 'warehouse_lot' AS table_name, @pre_warehouse_lot AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_warehouse_lot - COUNT(*), 0) AS lost_rows FROM `warehouse_lot`
UNION ALL SELECT 'warehouse_transaction' AS table_name, @pre_warehouse_transaction AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_warehouse_transaction - COUNT(*), 0) AS lost_rows FROM `warehouse_transaction`
UNION ALL SELECT 'warehouse_transaction_item' AS table_name, @pre_warehouse_transaction_item AS rows_before, COUNT(*) AS rows_after, GREATEST(@pre_warehouse_transaction_item - COUNT(*), 0) AS lost_rows FROM `warehouse_transaction_item`;

-- 4c. Information only: stock movement types after v52, and rows seeded into the new tables.
SELECT transaction_type, COUNT(*) AS transactions FROM warehouse_transaction WHERE deleted_at IS NULL GROUP BY transaction_type;
SELECT 'document_signature' AS table_name, COUNT(*) AS total_rows FROM document_signature UNION ALL SELECT 'subscription_payment', COUNT(*) FROM subscription_payment UNION ALL SELECT 'payment_webhook_event', COUNT(*) FROM payment_webhook_event;

-- ======================= NOTES =======================
-- DECISIONS
--  * Prod was already the v2 schema (61 tables, identical foreign keys, no triggers or routines or partitions).
--    Nothing changed shape incompatibly, so no table is archived as _v1old and no history needs copying.
--  * finance_category.category_type, sales_sppb.status_id: prod holds 0 NULLs. The preflight stops if that changes.
--    Old code (main) always supplies both columns, so tightening them is safe for old and new code.
--  * document_watermark.watermark is relaxed to NULL. Old code still inserts the image, so it keeps working.
--  * Existing damage checked on prod before writing this file: 0 invalid enum values in all 12 enum columns,
--    0 NULLs against any new NOT NULL, 0 orphans (the foreign keys already exist and are identical).
--  * 3a signers: assumes the single prod company is the Venken company (it is the one dev seeded from
--    app_company name LIKE Venken%). Confirm, and edit the names afterwards through PUT /document-signature.
--  * 3b (v52) reclassified on the prod preview: 41 stock_in and 197 stock_out (all 238 legacy adjustments reconcile).
--    Only labels change. Quantities and lot balances are untouched.
-- NOT COPIED FROM DEV (set yourself if wanted)
--  * Dev-only test rows: purchase_type APA YA YUU, ppn_type PPN Test Uhuy, account_code AI and AI Agent,
--    currency US Dollar (prod uses USD), the second dev company and its chart, dev bank_account rows (3 more than prod),
--    dev company_setting, employee_profile, public_holiday, salary_category and subscription_payment rows,
--    dev audit_log and notification rows, and the dev scratch table clone_id_map.
--  * Secrets and hosts: S3_ACCESS_KEY_ID and S3_SECRET_ACCESS_KEY, SUMOPOD_API_KEY and SUMOPOD_WEBHOOK_SECRET,
--    SSO_CLIENT_ID and SSO_CLIENT_SECRET, WAHA_API_KEY, JWT_SECRET, OTP_PEPPER and every dev host must be set in the prod .env.
-- INTENTIONALLY SKIPPED SCHEMA DIFFERENCES
--  * sales_sppb column order (prod has status_id, approved_by, approved_at at the end) and the column comment
--    on sales_sppb.approved_by. Cosmetic. Reordering would rebuild the table for no benefit.
--  * legacy_id_map.new_id comment says aluria_dev in dev. Cosmetic.
--  * api_rate_limit and idempotency_key are referenced only by helper functions that nothing calls. No table needed.
-- NOT DONE ON PURPOSE
--  * v50 default GL accounts: prod already has all four defaults. HARGA POKOK PENJUALAN is the purchase expense
--    default with 137 posted purchase journals. v50 would move it to a new PEMBELIAN account. Business decision.
--    It also found 0 approved sales invoices without a journal. Run v50 only if finance asks for it.
--  * v51 lot double count fix: the preview matches 0 lots on prod.
--  * movira_core seeds (permission keys of docs v46, v47, v49) live in another database and were not inspected.
--    Run those PROD blocks from the docs with the production APP_ID before deploy.
-- CODE ISSUES FOUND
--  * v1 connection.php on main contained a hard-coded database password. It is deleted on this branch but stays in git history. Rotate it.

-- ======================= ROLLBACK (commented, copy a line, remove the leading dashes, add a semicolon) =======================
-- Preferred rollback is restoring the pre-run mysqldump. Targeted undo, newest step first:
--  v52 labels : UPDATE warehouse_transaction t JOIN legacy_id_map m ON m.entity_type = 'warehouse_transaction' AND m.new_id = t.id SET t.transaction_type = 'adjustment' WHERE t.transaction_type IN ('stock_in','stock_out')
--  signers    : DELETE FROM document_signature WHERE created_by = 'migration'
--  index      : ALTER TABLE sales_sppb DROP INDEX idx_company_status
--  sppb status: ALTER TABLE sales_sppb MODIFY COLUMN status_id varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL
--  category   : ALTER TABLE finance_category MODIFY COLUMN category_type enum('debit','credit') COLLATE utf8mb4_general_ci DEFAULT NULL
--  watermark  : ALTER TABLE document_watermark DROP COLUMN file_key, DROP COLUMN watermark_name
--               then ALTER TABLE document_watermark MODIFY COLUMN watermark longblob NOT NULL (fails if rows with a NULL image were created after deploy)
--  new tables : DROP TABLE payment_webhook_event, DROP TABLE subscription_payment, DROP TABLE document_signature (only while unused)
