# Sales Report API

> **Last updated:** 2026-08-01 12:24:45 WIB
> **Base URL:** `/api/v2/sales-report`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/sales-report` | Omset/sales report — monthly trend, top products, top customers |
| GET    | `/api/v2/sales-report/export` | Download the same report as an `.xlsx` file |

---

### GET `/api/v2/sales-report`

Sales/omset report for the authenticated company over a date range: total omset, a month-by-month revenue trend, the top 10 products by revenue, and the top 10 customers by revenue.

#### Query parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| date_from | string (date) | No | First day of the current month | Filters `sales_invoice.invoice_date >=` this date (`YYYY-MM-DD`) |
| date_to   | string (date) | No | Today | Filters `sales_invoice.invoice_date <=` this date (`YYYY-MM-DD`) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales report found",
  "data": {
    "date_from": "2026-07-01",
    "date_to": "2026-07-11",
    "summary": {
      "total_omset": 125000000,
      "total_invoices": 18
    },
    "monthly_trend": [
      { "month": "2026-07", "revenue": 125000000 }
    ],
    "top_products": [
      { "product_name": "Steel Rod 12mm", "quantity": 500, "revenue": 27500000 }
    ],
    "top_customers": [
      { "customer_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a", "customer_name": "PT Sumber Makmur", "total": 40000000 }
    ]
  }
}
```

---

### GET `/api/v2/sales-report/export`

Same data as `GET /api/v2/sales-report`, streamed as an `.xlsx` file with a summary, monthly trend table, top-10-products table, and top-10-customers table. Filename: `penjualan_{date_from}_{date_to}.xlsx`.

#### Query parameters

Same as `GET /api/v2/sales-report` — `date_from`, `date_to`.

---

## Error responses (all endpoints)

| Code | When |
|------|------|
| 400  | `company_id` missing from the authenticated token |
| 401  | Missing or expired Bearer token |
| 405  | HTTP method other than `GET` |
| 500  | Internal server error |

---

## Notes

- Figures are computed from `sales_invoice` + `sales_invoice_item` (`quantity * unit_price`), scoped to `si.deleted_at IS NULL` and `sii.deleted_at IS NULL`.
- `top_products` groups by `sales_invoice_item.product_name` — items have no `product_id` foreign key in this schema, so products are matched by name text.
- `monthly_trend` covers every calendar month touched by the `date_from`–`date_to` range (not a fixed trailing window) and is ordered oldest to newest.
- `top_products` and `top_customers` are capped at the top 10 by revenue; there is no pagination on this endpoint.
- This is a read-only reporting endpoint — no `POST`/`PUT`/`DELETE`. It is separate from the lightweight `revenue_trend`/`top_partners` widgets on `GET /api/v2/dashboard`, which are fixed to the trailing 6 months / current month and are not date-filterable.
