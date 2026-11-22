# Aluria Schema Addendum — v51: correct warehouse lots double-counted by the Barang Masuk form

`AddNewInhouse.js` created each new lot with `end_balance = qty` and then posted a `stock_in` of the same `qty`,
so the API added it a second time (lot showed 2× the received quantity). Frontend fixed (VenkenReact `d879dd6`:
new lot now starts at `end_balance = 0`). This corrects lots already created that way.

Deliberately narrow: only lots with `beginning_balance = 0`, exactly **one** transaction item (a `stock_in`),
created within 5 s of that transaction, and `end_balance = 2 × stock_in`. Imported/migrated lots whose balance
differs from their transaction history are NOT touched.

## DEV (`aluria_dev`) — applied 2026-10-21 (2 lots corrected: 4→2, 800→400)

## PROD (`aluria`) — NOT yet applied

Preview first (read-only), then run the UPDATE.

```sql
SELECT l.id, l.company_id, l.end_balance, m.stock_in_total AS correct_end_balance
FROM `aluria`.`warehouse_lot` l
JOIN (SELECT i.warehouse_lot_id, SUM(ABS(i.quantity)) AS stock_in_total, COUNT(*) AS n_items, COUNT(DISTINCT t.transaction_type) AS n_types, MIN(t.created_at) AS first_tx_at
      FROM `aluria`.`warehouse_transaction_item` i
      JOIN `aluria`.`warehouse_transaction` t ON t.id = i.warehouse_transaction_id AND t.deleted_at IS NULL
      WHERE i.deleted_at IS NULL GROUP BY i.warehouse_lot_id) m ON m.warehouse_lot_id = l.id
WHERE l.deleted_at IS NULL AND l.beginning_balance = 0 AND m.n_types = 1 AND m.n_items = 1
  AND l.end_balance = 2 * m.stock_in_total AND TIMESTAMPDIFF(SECOND, l.created_at, m.first_tx_at) BETWEEN 0 AND 5;
```

```sql
UPDATE `aluria`.`warehouse_lot` l
JOIN (SELECT i.warehouse_lot_id, SUM(ABS(i.quantity)) AS stock_in_total, COUNT(*) AS n_items, COUNT(DISTINCT t.transaction_type) AS n_types, MIN(t.created_at) AS first_tx_at
      FROM `aluria`.`warehouse_transaction_item` i
      JOIN `aluria`.`warehouse_transaction` t ON t.id = i.warehouse_transaction_id AND t.deleted_at IS NULL
      WHERE i.deleted_at IS NULL GROUP BY i.warehouse_lot_id) m ON m.warehouse_lot_id = l.id
SET l.end_balance = l.beginning_balance + m.stock_in_total
WHERE l.deleted_at IS NULL AND l.beginning_balance = 0
  AND m.n_types = 1 AND m.n_items = 1
  AND l.end_balance = 2 * m.stock_in_total
  AND TIMESTAMPDIFF(SECOND, l.created_at, m.first_tx_at) BETWEEN 0 AND 5
  AND EXISTS (SELECT 1 FROM `aluria`.`warehouse_transaction_item` i2 JOIN `aluria`.`warehouse_transaction` t2 ON t2.id = i2.warehouse_transaction_id
              WHERE i2.warehouse_lot_id = l.id AND t2.transaction_type = 'stock_in');
```
