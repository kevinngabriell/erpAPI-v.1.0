# Balance Sheet Report API

> **Last updated:** 2026-07-11 19:44:37 WIB
> **Base URL:** `/api/v2/balance-sheet`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/balance-sheet` | Neraca (balance sheet) as of a given date, broken down by account |

---

### GET `/api/v2/balance-sheet`

Asset, liability, and equity balances for the authenticated company as of a given date, broken down per `account_code`.

#### Query parameters

| Parameter  | Type | Required | Default | Description |
|------------|------|----------|---------|-------------|
| as_of_date | string (date) | No | Today | Cumulative balance as of this date (`YYYY-MM-DD`), inclusive |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Balance sheet report found",
  "data": {
    "as_of_date": "2026-07-11",
    "asset": {
      "total": 500000000,
      "by_account": [
        { "account_code_id": "acc_010", "account_code": "1-1000", "account_code_name": "Cash", "total": 500000000 }
      ]
    },
    "liability": {
      "total": 120000000,
      "by_account": [
        { "account_code_id": "acc_020", "account_code": "2-1000", "account_code_name": "Accounts Payable", "total": 120000000 }
      ]
    },
    "equity": {
      "total": 380000000,
      "by_account": [
        { "account_code_id": "acc_030", "account_code": "3-1000", "account_code_name": "Owner's Capital", "total": 380000000 }
      ]
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

- Each balance is `SUM(finance_transaction.account_amount)` cumulative from all history through `as_of_date` (`transaction_date <= as_of_date`), grouped by `account_code`, scoped to `account_code.account_type IN ('asset', 'liability', 'equity')` and `finance_transaction.deleted_at IS NULL`.
- `by_account` only lists accounts with a non-zero cumulative balance as of the given date (`HAVING total != 0`).
- This is a read-only reporting endpoint — no `POST`/`PUT`/`DELETE`. It generalizes the dashboard's fixed all-time `neraca_snapshot` widget (`GET /api/v2/dashboard`) into an as-of-date report with a per-account breakdown.
