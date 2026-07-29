# General Ledger Report API

> **Last updated:** 2026-07-23 22:30:00 WIB
> **Base URL:** `/api/v2/general-ledger`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/general-ledger` | Buku Besar — per-account summary for a date range (paginated) |
| GET    | `/api/v2/general-ledger/{account_code_id}` | Transaction-level ledger detail for one account, with running balance |

---

### GET `/api/v2/general-ledger`

Per-account opening balance, period movement, and closing balance for the authenticated company over a date range.

#### Query parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| date_from | string (date) | No | First day of the current month | Period start (`YYYY-MM-DD`) |
| date_to   | string (date) | No | Today | Period end (`YYYY-MM-DD`) |
| page      | int | No | 1  | Page number |
| limit     | int | No | 10 | Items per page (max 100) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "General ledger found",
  "data": {
    "date_from": "2026-07-01",
    "date_to": "2026-07-11",
    "data": [
      {
        "account_code_id": "acc_010",
        "account_code": "1-1000",
        "account_code_name": "Cash",
        "account_type": "asset",
        "opening_balance": 480000000,
        "period_movement": 20000000,
        "closing_balance": 500000000
      }
    ],
    "pagination": {
      "total": 32,
      "page": 1,
      "limit": 10,
      "total_pages": 4
    }
  }
}
```

---

### GET `/api/v2/general-ledger/{account_code_id}`

Transaction-level detail for a single account within a date range, including a running balance that starts from the account's opening balance.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| account_code_id | string | The `account_code` ID |

#### Query parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| date_from | string (date) | No | First day of the current month | Period start (`YYYY-MM-DD`) |
| date_to   | string (date) | No | Today | Period end (`YYYY-MM-DD`) |
| page      | int | No | 1  | Page number |
| limit     | int | No | 20 | Items per page (max 100) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "General ledger account found",
  "data": {
    "account_code_id": "acc_010",
    "account_code": "1-1000",
    "account_code_name": "Cash",
    "account_type": "asset",
    "date_from": "2026-07-01",
    "date_to": "2026-07-11",
    "opening_balance": 480000000,
    "transactions": [
      {
        "id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
        "transaction_date": "2026-07-03",
        "voucher_number": "V-2026-0301",
        "memo": "Customer payment received",
        "account_amount": 20000000,
        "running_balance": 500000000
      }
    ],
    "closing_balance": 500000000,
    "pagination": {
      "total": 1,
      "page": 1,
      "limit": 20,
      "total_pages": 1
    }
  }
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Account code not found",
  "data": []
}
```

---

## Error responses (all endpoints)

| Code | When |
|------|------|
| 400  | `company_id` missing from the authenticated token |
| 401  | Missing or expired Bearer token |
| 404  | `account_code_id` not found for this company |
| 405  | HTTP method other than `GET` |
| 500  | Internal server error |

---

## Notes

- `opening_balance` is `SUM(finance_transaction_detail.amount)` for all detail lines posted against this account strictly before `date_from`; `closing_balance` = `opening_balance + period_movement` (list endpoint) or the final `running_balance` after the last listed transaction (detail endpoint) — both represent the same figure.
- **Each row in `transactions` is a `finance_transaction_detail` line, not a `finance_transaction` header** — a single finance transaction with multiple `account_code`s in its `details` (see the `finance-transaction` module doc) now contributes one row per matching detail line here, not one row per transaction. `id` is the detail line's own ID; `transaction_date`, `voucher_number`, and `memo` are still resolved from the parent header.
- The detail endpoint's `transactions` are ordered oldest to newest (`transaction_date ASC, created_at ASC`) so `running_balance` accumulates correctly; pagination applies to this transaction list, not to the opening/closing balance calculation (those are computed from the full unpaginated history/period).
- This is a read-only reporting endpoint — no `POST`/`PUT`/`DELETE`. It generalizes the dashboard's fixed all-time `buku_besar_summary` widget (`GET /api/v2/dashboard`) into a date-range report with pagination and a per-account transaction drill-down.
