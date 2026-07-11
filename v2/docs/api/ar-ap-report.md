# AR/AP Report API

> **Last updated:** 2026-07-11 19:44:37 WIB
> **Base URL:** `/api/v2/ar-ap-report`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/ar-ap-report` | Piutang & Hutang — paginated outstanding AR/AP list with aging buckets |

---

### GET `/api/v2/ar-ap-report`

Outstanding receivables (AR) and payables (AP), aged into buckets, with a searchable/filterable paginated list plus bucket-total summaries.

#### Query parameters

| Parameter | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| type      | string | No       | `all`   | `ar` \| `ap` \| `all` |
| bucket    | string | No       | —       | Filter to one aging bucket: `current` \| `30` \| `60` \| `90` \| `90+` |
| search    | string | No       | —       | Case-insensitive match on partner name (`customer_name`/`supplier_name`) or `invoice_number` |
| page      | int    | No       | 1       | Page number |
| limit     | int    | No       | 10      | Items per page (max 100) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "AR/AP report found",
  "data": {
    "data": [
      {
        "invoice_number": "INV-2026-0042",
        "partner_name": "PT Sumber Makmur",
        "partner_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
        "invoice_date": "2026-05-01",
        "outstanding": 15000000,
        "days_overdue": 45,
        "bucket": "60",
        "type": "ar"
      }
    ],
    "pagination": {
      "total": 23,
      "page": 1,
      "limit": 10,
      "total_pages": 3
    },
    "summary": {
      "receivables": { "current": 5000000, "30": 3000000, "60": 15000000, "90": 0, "90+": 2000000 },
      "payables":    { "current": 1000000, "30": 0, "60": 0, "90": 0, "90+": 0 }
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

- Outstanding balance per invoice is `MAX(finance_payment.due_amount) - SUM(finance_payment.paid_amount)`, aggregated from `finance_payment` grouped by `invoice_number`; only rows with `outstanding > 0` are included.
- `days_overdue` is `DATEDIFF(CURDATE(), invoice_date + payment term days)` — AR uses `customer.customer_top_days`, AP uses `supplier.supplier_term_id → payment_term.days`. Aging buckets: `current` (≤0 days), `30` (1–30), `60` (31–60), `90` (61–90), `90+` (>90).
- `summary` bucket totals always reflect the full unfiltered AR/AP data — they are not affected by `bucket` or `search`, so the totals stay stable while a user filters/searches the list.
- `partner_id`/`partner_name` is `customer_id`/`customer_name` for AR rows and `supplier_id`/`supplier_name` for AP rows — check the `type` field to know which.
- This is a read-only reporting endpoint — no `POST`/`PUT`/`DELETE`. It generalizes the dashboard's fixed `ar_aging`/`ap_aging`/`ar_ap_summary` widgets (`GET /api/v2/dashboard`) into a searchable, filterable, paginated report.
