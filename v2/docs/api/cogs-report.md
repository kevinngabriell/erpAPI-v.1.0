# COGS Report API

> **Last updated:** 2026-07-11 19:44:37 WIB
> **Base URL:** `/api/v2/cogs-report`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/cogs-report` | HPP (COGS) & gross profit per product, for a date range (paginated) |

---

### GET `/api/v2/cogs-report`

Cost of goods sold and gross profit per product for the authenticated company over a date range.

#### Query parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| date_from | string (date) | No | First day of the current month | Filters `sales_profit.created_at >=` this date, start of day (`YYYY-MM-DD`) |
| date_to   | string (date) | No | Today | Filters `sales_profit.created_at <=` this date, end of day (`YYYY-MM-DD`) |
| page      | int | No | 1  | Page number |
| limit     | int | No | 10 | Items per page (max 100) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "COGS report found",
  "data": {
    "date_from": "2026-07-01",
    "date_to": "2026-07-11",
    "data": [
      {
        "product_name": "Steel Rod 12mm",
        "quantity_sold": 500,
        "revenue": 27500000,
        "cogs": 25000000,
        "gross_profit": 2500000,
        "margin_percent": 9.09
      }
    ],
    "pagination": {
      "total": 14,
      "page": 1,
      "limit": 10,
      "total_pages": 2
    },
    "summary": {
      "total_revenue": 125000000,
      "total_cogs": 100000000,
      "total_gross_profit": 25000000,
      "overall_margin_percent": 20.0
    }
  }
}
```

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

- Sourced from `sales_profit` + `sales_profit_item`, grouped by `sales_profit_item.product_name` (items have no `product_id` foreign key in this schema, so products are matched by name text).
- `revenue = SUM(price * quantity)`, `cogs = SUM(landed_cost * quantity)`, `gross_profit = revenue - cogs`, `margin_percent = round(gross_profit / revenue * 100, 2)` (`0` if revenue is `0`).
- `summary` totals reflect the full unpaginated result set for the date range, not just the current page.
- Filtered on `sales_profit.created_at` (record creation time) — `sales_profit` has no separate transaction/invoice date field, so this is the same field the dashboard's `profit_summary` widget already filters on.
- This is a read-only reporting endpoint — no `POST`/`PUT`/`DELETE`.
