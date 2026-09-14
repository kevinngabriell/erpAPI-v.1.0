# Analytics API

> **Last updated:** 2026-08-13 12:15:00 WIB
> **Base URL:** `/api/v2/analytics`
> **Auth:** Requires `Authorization: Bearer <access_token>`

Five read-only, permission-gated endpoints powering the Analytics module's
six tabs (Overview, Sales, Purchase, Finance, Warehouse — Workforce is
mock-only client-side, no backend). Each endpoint requires its own
`analytics.{action}.view` permission key; a caller whose role doesn't hold
that key gets a `403` for that tab specifically, not for the whole module.

All five share the same optional windowing params:

| Parameter | Type | Required | Description |
|---|---|---|---|
| `from` | string (`YYYY-MM-DD`) | No | Start of the window. |
| `to` | string (`YYYY-MM-DD`) | No | End of the window. |

If `from`/`to` are omitted, each field falls back to a default scoped to what
it represents:

- **`monthly` series** → trailing 12 months ending this month.
- **`*_ytd` summary fields** → calendar year to date.
- **Everything else** (rankings, funnel, cycle time, import share) → current
  month.
- **`cash_position`, `total_ar`, `total_ap`, `top_debtors`, `top_creditors`**
  → not window-scoped at all — these are point-in-time balances (same
  convention as `/dashboard`'s `cash_position`/`ar_ap_summary` widgets and
  `/ar-ap-report`), always "as of now" regardless of `from`/`to`.

If `from`/`to` **are** supplied, that exact range is used for every field
that isn't a point-in-time balance — it replaces whichever default that
field would otherwise have used, it does not narrow within it.

`month` fields in `monthly` arrays are `"YYYY-MM"` strings, oldest → newest,
zero-filled for months with no data — same convention as the dashboard's
`revenue_trend` widget.

---

## `GET /api/v2/analytics/overview`

Requires `analytics.overview.view`.

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Overview analytics found",
  "data": {
    "summary": {
      "revenue_ytd": 23500479305.75,
      "gross_margin_percent": 16.55,
      "cash_position": 102623591904.07,
      "total_ar": 5434643371.86,
      "total_ap": 12630303.72
    },
    "monthly": [
      { "month": "2025-09", "revenue": 0, "purchase_spend": 475624000 }
    ]
  }
}
```

- `revenue_ytd` — `SUM(sales_invoice_item.quantity * unit_price)`, `sales_invoice.invoice_date` in the YTD window.
- `gross_margin_percent` — from `sales_profit`/`sales_profit_item` (`price` vs `landed_cost`) in the YTD window, same method as `gross_margin_percent` on `/analytics/finance` and the dashboard's `profit_summary` widget.
- `cash_position` — `SUM(finance_transaction.amount)` across all bank accounts. Point-in-time.
- `total_ar` / `total_ap` — sum of all outstanding `finance_payment` balances (customer-linked / supplier-linked). Point-in-time.
- `monthly[].purchase_spend` — `SUM(purchase_invoice_item.quantity * unit_price)`, `purchase_invoice.invoice_date` in that month.

---

## `GET /api/v2/analytics/sales`

Requires `analytics.sales.view`.

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales analytics found",
  "data": {
    "summary": {
      "total_sales_ytd": 23500479305.75,
      "target_sales": 5000000000,
      "avg_order_value": 131287593.89
    },
    "monthly": [
      { "month": "2025-09", "total_sales": 0 }
    ],
    "top_products": [
      { "product_name": "Steel Coil", "total_sales": 8918117250 }
    ],
    "funnel": { "so": 100, "sppb": 0, "delivery": 0, "invoice": 0 }
  }
}
```

- `target_sales` — `sales_target.target_value` for the YTD window's year (`YEAR(to)`). `0` if no target row exists for that year.
- `avg_order_value` — `total_sales_ytd / COUNT(DISTINCT sales_order_id invoiced in the window)`. `0` if no orders were invoiced.
- `top_products` — top 10 by `SUM(quantity * unit_price)` from `sales_invoice_item`, grouped by `product_name` (denormalized text on the item row — there's no `product_id` FK on sales items).
- `funnel` — of sales orders **placed** (`so_date`) in the window, `so` is always `100`; `sppb`/`delivery`/`invoice` are the percentage of those orders that have at least one linked `sales_sppb`/`sales_delivery`/`sales_invoice` row (ever, not restricted to the window). Stops at invoice — does not include a `profit` stage (see Notes).

---

## `GET /api/v2/analytics/purchase`

Requires `analytics.purchase.view`.

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase analytics found",
  "data": {
    "summary": {
      "total_purchase_ytd": 769427083.38,
      "avg_cycle_time_days": 12.4,
      "import_share_percent": 3.2
    },
    "monthly": [
      { "month": "2025-09", "total_import": 240000, "total_local": 475384000 }
    ],
    "supplier_origin": [
      { "country": "China", "total": 45000000 }
    ],
    "top_products": [
      { "product_name": "Aluminum Sheet", "total_purchase": 25130344 }
    ],
    "top_suppliers": [
      { "supplier_name": "PT Sumber Jaya", "total": 93500000 }
    ]
  }
}
```

- `avg_cycle_time_days` — mean `DATEDIFF(first purchase_receive.receiving_date, purchase_order.po_date)`, over POs whose **first** receipt fell in the window. `0` if no PO received its first receipt in the window.
- `import_share_percent` / `monthly[].total_import` / `monthly[].total_local` — classified via `purchase_invoice.purchase_order_id → purchase_order.type_id → purchase_type.type_name`, matching `import`/`local` case-insensitively (same `str_contains` convention already used by the purchase-order export template picker). A `purchase_type` name containing neither word contributes to neither bucket.
- `supplier_origin.country` — `supplier.supplier_origin_id → origin.origin_name` (the supplier's registered origin, not the PO's shipment `origin_id`), summed by invoiced purchase value in the window.
- `top_products` / `top_suppliers` — top 10 by invoiced purchase value in the window.

---

## `GET /api/v2/analytics/finance`

Requires `analytics.finance.view`.

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance analytics found",
  "data": {
    "summary": {
      "revenue": 76269670129.39,
      "cogs": 63644661477.32,
      "gross_profit": 12625008652.06,
      "gross_margin_percent": 16.55
    },
    "monthly": [
      { "month": "2025-09", "revenue": 0, "cogs": 0, "net_profit": 0 }
    ],
    "top_debtors": [
      { "partner_name": "PT Contoh Sejahtera", "outstanding": 5017761469.08 }
    ],
    "top_creditors": [
      { "partner_name": "CV Mitra Abadi", "outstanding": 12564922.5 }
    ]
  }
}
```

- `revenue` / `cogs` / `gross_profit` / `gross_margin_percent` — from `sales_profit`/`sales_profit_item` (`price` vs `landed_cost`), bucketed by `sales_profit.created_at` (not `sales_invoice.invoice_date` — see Notes), YTD window for `summary`, monthly window for `monthly`.
- `monthly[].net_profit` — actually **gross profit** (`revenue - cogs`), not profit after operating expenses. See Notes.
- `top_debtors` / `top_creditors` — top 10 partners by outstanding balance, aggregated from the same `finance_payment` base data `/ar-ap-report` uses, grouped by partner instead of by invoice. Point-in-time, not window-scoped.

---

## `GET /api/v2/analytics/warehouse`

Requires `analytics.warehouse.view`.

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Warehouse analytics found",
  "data": {
    "monthly": [
      { "month": "2025-09", "total_in": 0, "total_out": 0 }
    ],
    "top_movers": [
      { "product_name": "Steel Coil", "total_qty": 4200 }
    ]
  }
}
```

- `total_in` / `total_out` — `SUM(warehouse_transaction_item.quantity)` where `warehouse_transaction.transaction_type` is `stock_in` / `stock_out` respectively. `adjustment`/`transfer` transactions are excluded from both this and `top_movers` — they're corrections/internal moves, not commercial throughput.
- `top_movers` — top 10 products by combined `stock_in` + `stock_out` quantity in the window (total movement, not net change).

---

## Failure responses (all five endpoints)

| Code | `status_message` | When |
|---|---|---|
| 400 | `company_id is required` | JWT has no `company_id` |
| 400 | `No role assigned to this account yet.` | JWT has no `app_role_id` |
| 400 | `from must be YYYY-MM-DD format` / `to must be YYYY-MM-DD format` | Malformed date param |
| 401 | — | Missing/invalid/expired Bearer token |
| 403 | `You do not have permission to view this analytics module` | Caller's role lacks the `analytics.{action}.view` key for this endpoint |
| 404 | `Route not found` | Action other than `overview`/`sales`/`purchase`/`finance`/`warehouse` |
| 405 | `Method Not Allowed` | Any method other than `GET` |
| 500 | `Internal Server Error` | Server error |

---

## Notes

- **Permission keys** — `analytics.overview.view`, `analytics.sales.view`, `analytics.purchase.view`, `analytics.finance.view`, `analytics.warehouse.view` (see `v2/docs/migrations/v36_analytics_permission_seed.md`). Seeded in dev; **no role holds any of them yet** — that assignment is a deliberate open decision, not done as part of this migration (same convention as every `.view`-key addendum before it). Every caller gets `403` on every tab until a role is granted.
- **`finance.monthly[].net_profit` is gross profit, not fully-loaded net profit.** `account_code` only distinguishes `revenue`/`expense`/`asset`/`liability`/`equity` — there's no COGS-specific classification to subtract from the account-code-based P&L (`/profit-loss`), so `/analytics/finance` mirrors the existing `/cogs-report` and dashboard `profit_summary` widget's method (`sales_profit_item.price - landed_cost`) instead of reusing `/profit-loss`. `/profit-loss` was **not** given a `granularity=monthly` param — its revenue/expense split doesn't decompose into COGS vs opex, so it wouldn't answer the same question this tab needs.
- **Finance's monthly revenue/cogs won't reconcile 1:1 against Sales's monthly `total_sales`** — Sales buckets by `sales_invoice.invoice_date`, Finance buckets by `sales_profit.created_at` (when the landed-cost record was created, which can lag or lead the invoice date). Same duality already exists between the dashboard's `revenue_trend` and `profit_summary` widgets; not new here.
- **Sales funnel stops at `invoice`**, matching the frontend's default. Adding a `profit` stage (`sales_profit` existence, matching `SalesIndex.js`'s `DOC_SECTIONS`) is straightforward if wanted — flagging back per the open question rather than guessing.
- **`top_debtors`/`top_creditors` are net-new, not a reuse of `/ar-ap-report`.** `/ar-ap-report` returns per-invoice rows; a per-partner ranking needs its own `GROUP BY` on top of the same `finance_payment` base query, which is what this endpoint does.
- **`supplier_origin.country`** reads the **supplier's** registered origin (`supplier.supplier_origin_id`), not the shipment `origin_id` stored on individual purchase orders — a supplier based in China can still ship a specific PO through a different origin; this field answers "which countries do our suppliers come from," not "where did this shipment originate."
