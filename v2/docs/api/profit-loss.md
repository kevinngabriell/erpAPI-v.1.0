# Profit & Loss Report API

> **Last updated:** 2026-07-23 22:30:00 WIB
> **Base URL:** `/api/v2/profit-loss`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/profit-loss` | Laporan Laba Rugi (P&L) for a date range, broken down by account |

---

### GET `/api/v2/profit-loss`

Revenue, expense, and net profit for the authenticated company over a date range, broken down per `account_code`.

#### Query parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| date_from | string (date) | No | First day of the current month | Filters `finance_transaction.transaction_date >=` this date (`YYYY-MM-DD`) |
| date_to   | string (date) | No | Today | Filters `finance_transaction.transaction_date <=` this date (`YYYY-MM-DD`) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Profit and loss report found",
  "data": {
    "date_from": "2026-07-01",
    "date_to": "2026-07-11",
    "revenue": {
      "total": 125000000,
      "by_account": [
        { "account_code_id": "acc_001", "account_code": "4-1000", "account_code_name": "Sales Revenue", "total": 125000000 }
      ]
    },
    "expense": {
      "total": 45000000,
      "by_account": [
        { "account_code_id": "acc_050", "account_code": "6-1000", "account_code_name": "Operating Expense", "total": 45000000 }
      ]
    },
    "net_profit": 80000000
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

- Figures are `SUM(finance_transaction_detail.amount)` grouped by `account_code`, scoped to `account_code.account_type IN ('revenue', 'expense')` and the owning `finance_transaction.deleted_at IS NULL`. Each `finance_transaction` can now split across multiple `account_code`s via its `details` — see the `finance-transaction` module doc — so a single transaction can contribute to more than one row here.
- `by_account` only lists accounts with a non-zero total in the selected date range (`HAVING total != 0`) — accounts with no activity in the period are omitted.
- `net_profit` is `revenue.total - expense.total`.
- This is a read-only reporting endpoint — no `POST`/`PUT`/`DELETE`. It generalizes the dashboard's fixed current-month-only `pnl_snapshot` widget (`GET /api/v2/dashboard`) into a date-range report with a per-account breakdown.
