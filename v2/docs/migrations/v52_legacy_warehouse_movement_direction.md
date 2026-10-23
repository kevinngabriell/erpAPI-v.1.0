# Aluria Schema Addendum — v52: restore in/out direction of legacy warehouse movements

The v1 → v2 data migration copied every legacy warehouse movement into `warehouse_transaction` as a
positive-quantity `adjustment`, so the direction was lost. Receipts and issues both look like stock **in**
(issues carry the customer name in `notes`, e.g. `pt cdi`, `chemindo`). Lot balances
(`warehouse_lot.end_balance`) were migrated correctly. As a result the history no longer reconciles with
the lot balances, and the stock report (`GET /api/v2/stock-report`) shows negative opening balances.

Rule (verified read-only on `aluria_dev`: all 238 legacy adjustments across 41 lots, zero mismatches):
**per lot, the first movement is the receipt (`stock_in`) and every later one is an issue (`stock_out`)**.
Quantities stay positive because the API applies the sign from `transaction_type`. Lot balances are not touched.

The SQL verifies each lot before changing it. A lot is only reclassified when:
- it has no non-adjustment movements (nothing has been posted to it through v2 yet),
- every one of its adjustments is a single-item transaction with a positive quantity, and
- `beginning_balance + first − sum(rest) = end_balance` holds exactly.

Anything else (genuine adjustments, lots touched since the migration) stays `adjustment`.

## DEV (`aluria_dev`) — NOT yet applied

Expected preview: `stock_in` 41 transactions / 41 lots, `stock_out` 197 transactions / 39 lots.
For PROD, replace `aluria_dev` with `aluria` and run the preview first; the counts will differ.

Preview (read-only):

```sql
WITH ranked AS (
  SELECT i.warehouse_lot_id, i.warehouse_transaction_id, i.quantity,
         ROW_NUMBER() OVER (PARTITION BY i.warehouse_lot_id ORDER BY t.transaction_date, t.created_at, t.id) AS rn
  FROM `aluria_dev`.`warehouse_transaction_item` i
  JOIN `aluria_dev`.`warehouse_transaction` t ON t.id = i.warehouse_transaction_id AND t.deleted_at IS NULL
  WHERE i.deleted_at IS NULL AND t.transaction_type = 'adjustment' AND i.quantity > 0
    AND (SELECT COUNT(*) FROM `aluria_dev`.`warehouse_transaction_item` i2 WHERE i2.warehouse_transaction_id = t.id AND i2.deleted_at IS NULL) = 1
),
lots_ok AS (
  SELECT r.warehouse_lot_id
  FROM ranked r
  JOIN `aluria_dev`.`warehouse_lot` l ON l.id = r.warehouse_lot_id AND l.deleted_at IS NULL
  WHERE NOT EXISTS (SELECT 1 FROM `aluria_dev`.`warehouse_transaction_item` o
                    JOIN `aluria_dev`.`warehouse_transaction` ot ON ot.id = o.warehouse_transaction_id AND ot.deleted_at IS NULL
                    WHERE o.warehouse_lot_id = l.id AND o.deleted_at IS NULL AND ot.transaction_type <> 'adjustment')
  GROUP BY r.warehouse_lot_id, l.beginning_balance, l.end_balance
  HAVING l.end_balance = l.beginning_balance + SUM(CASE WHEN r.rn = 1 THEN r.quantity ELSE -r.quantity END)
)
SELECT CASE WHEN r.rn = 1 THEN 'stock_in' ELSE 'stock_out' END AS new_type,
       COUNT(*) AS transactions, COUNT(DISTINCT r.warehouse_lot_id) AS lots
FROM ranked r JOIN lots_ok USING (warehouse_lot_id)
GROUP BY new_type;
```

Apply (same `WITH` block, then):

```sql
WITH ranked AS ( /* identical to the preview */ ),
lots_ok AS ( /* identical to the preview */ )
UPDATE `aluria_dev`.`warehouse_transaction` t
JOIN (SELECT r.warehouse_transaction_id, CASE WHEN r.rn = 1 THEN 'stock_in' ELSE 'stock_out' END AS new_type
      FROM ranked r JOIN lots_ok USING (warehouse_lot_id)) m ON m.warehouse_transaction_id = t.id
SET t.transaction_type = m.new_type
WHERE t.transaction_type = 'adjustment';
```
