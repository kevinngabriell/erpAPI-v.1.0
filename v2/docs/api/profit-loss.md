# Profit & Loss Report API

> **Last updated:** 2026-08-01 12:24:45 WIB
> **Base URL:** `/api/v2/profit-loss`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/profit-loss` | Laporan Laba Rugi (P&L) for a date range, broken down by account |
| GET    | `/api/v2/profit-loss/export` | Download the same report as an `.xlsx` file |

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

### GET `/api/v2/profit-loss/export`

Same data as `GET /api/v2/profit-loss`, streamed as an Excel workbook (`.xlsx`) instead of JSON. Response headers set `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` and `Content-Disposition: attachment; filename="laba_rugi_{date_from}_{date_to}.xlsx"`.

#### Query parameters

Same as `GET /api/v2/profit-loss` — `date_from` and `date_to`, with the same defaults.

#### Response `200 OK`

Binary `.xlsx` file body (not JSON). The workbook has one sheet with:
- Title `LAPORAN LABA RUGI` and the resolved period (`Periode: {date_from} - {date_to}`) formatted as Indonesian long dates.
- A `PENDAPATAN` (revenue) section: a `Kode Akun` / `Nama Akun` / `Jumlah` table of `revenue.by_account`, followed by a `TOTAL PENDAPATAN` row.
- A `BEBAN` (expense) section in the same shape, followed by a `TOTAL BEBAN` row.
- A final `LABA/RUGI BERSIH` row with `net_profit`.

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
