# Aluria Schema Addendum — v54: unit prices keep up to 3 decimals (not rounded to 2)

Finance asked that unit prices (purchase and sales) are no longer rounded: e.g. USD `0.783` must not be stored as
`0.78`, otherwise invoice totals differ from the finance system. Max **3** decimals for both purchase and sales.
Line totals / VAT / invoice totals stay at 2 decimals (rounded half-up in PHP via `roundMoneyHalfUp`).

Only widening `decimal(18,2)` → `decimal(18,3)` — lossless, existing values are unchanged.

## DEV (`aluria_dev`) — NOT yet applied (run the same statements with `aluria_dev` as schema)

## PROD (`aluria`) — NOT yet applied

```sql
ALTER TABLE `aluria`.`purchase_order_item`   MODIFY `unit_price` decimal(18,3) NOT NULL;
ALTER TABLE `aluria`.`purchase_receive_item` MODIFY `unit_price` decimal(18,3) NOT NULL;
ALTER TABLE `aluria`.`purchase_invoice_item` MODIFY `unit_price` decimal(18,3) NOT NULL;
ALTER TABLE `aluria`.`sales_order_item`      MODIFY `unit_price` decimal(18,3) NOT NULL;
ALTER TABLE `aluria`.`sales_invoice_item`    MODIFY `unit_price` decimal(18,3) NOT NULL;
ALTER TABLE `aluria`.`sales_profit_item`     MODIFY `price`      decimal(18,3) NOT NULL;
```

Check the real NULL/DEFAULT of each column first and mirror it (`SHOW CREATE TABLE`), the lines above assume NOT NULL.

## Code (same release)
- `general.php`: `roundMoneyHalfUp()` (bcmul/bcadd truncate; PHP 8.4 already owns `bcround`) and `formatUnitPrice()` (2–3 decimals for exports).
- Line totals computed server-side (`purchase-order|receive|invoice`, `sales-order` export, `sales-invoice` export) use half-up rounding.
