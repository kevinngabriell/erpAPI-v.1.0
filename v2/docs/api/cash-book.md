# Cash Book Report API

> **Last updated:** 2026-07-23 22:30:00 WIB
> **Base URL:** `/api/v2/cash-book`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/cash-book` | Buku Kas — per-bank-account summary for a date range (paginated) |
| GET    | `/api/v2/cash-book/{bank_account_id}` | Transaction-level cash book detail for one bank account, with running balance |

---

### GET `/api/v2/cash-book`

Per-bank-account opening balance, period movement, and closing balance for the authenticated company over a date range.

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
  "status_message": "Cash books found",
  "data": {
    "date_from": "2026-07-01",
    "date_to": "2026-07-19",
    "data": [
      {
        "bank_account_id": "8dac152c-7ba0-11f1-93b5-525400d7fdd0",
        "bank_name": "KAS KECIL",
        "bank_number": "000-1234-5678",
        "opening_balance": -18544221.80,
        "period_movement": 2500000,
        "closing_balance": -16044221.80
      }
    ],
    "pagination": {
      "total": 6,
      "page": 1,
      "limit": 10,
      "total_pages": 1
    }
  }
}
```

---

### GET `/api/v2/cash-book/{bank_account_id}`

Transaction-level detail for a single bank account within a date range, including a running balance that starts from the account's opening balance. Combines two sources, ordered together by date: `finance_transaction` rows for that bank account, and `finance_payment` rows where that bank account was used to settle a customer or supplier invoice.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| bank_account_id | string | The `bank_account` ID |

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
  "status_message": "Cash book found",
  "data": {
    "bank_account_id": "8dac152c-7ba0-11f1-93b5-525400d7fdd0",
    "bank_name": "KAS KECIL",
    "bank_number": "000-1234-5678",
    "date_from": "2026-07-01",
    "date_to": "2026-07-19",
    "opening_balance": -18544221.80,
    "transactions": [
      {
        "transaction_date": "2026-07-03",
        "reference_number": "V-2026-0301",
        "cheque_number": null,
        "party_name": "PT Sinar Jaya",
        "memo": "Petty cash top-up",
        "description": "Cash",
        "signed_amount": 2500000,
        "running_balance": -16044221.80
      }
    ],
    "closing_balance": -16044221.80,
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
  "status_message": "Bank account not found",
  "data": []
}
```

---

## Error responses (all endpoints)

| Code | When |
|------|------|
| 400  | `company_id` missing from the authenticated token |
| 401  | Missing or expired Bearer token |
| 404  | `bank_account_id` not found for this company |
| 405  | HTTP method other than `GET` |
| 500  | Internal server error |

---

## Notes

- `signed_amount` is positive for cash in (debit) and negative for cash out (credit). For `finance_transaction` rows, direction comes from the linked [`finance_category.category_type`](finance-category.md) (`debit` or `credit`). For `finance_payment` rows, direction comes from which party settled: `customer_id` set → debit (cash received), `supplier_id` set → credit (cash paid out). Only `finance_payment` rows with a `bank_account_id` are included.
- `reference_number` is `voucher_number` for `finance_transaction` rows and `invoice_number` for `finance_payment` rows. `description` is only populated for `finance_transaction` rows — always `null` for `finance_payment` rows. Since a `finance_transaction` can now split across multiple `account_code`s via its `details` (see the `finance-transaction` module doc), `description` is the comma-separated list of every account code name on that transaction's detail lines, e.g. `"Office Supplies Expense, Utilities Expense"` — not a single account name.
- `opening_balance` is the sum of `signed_amount` for all transactions strictly before `date_from`; `closing_balance` = `opening_balance + period_movement` (list endpoint) or the final `running_balance` after the last listed transaction (detail endpoint) — both represent the same figure.
- The detail endpoint's `transactions` are ordered oldest to newest (`transaction_date ASC, created_at ASC`) so `running_balance` accumulates correctly; pagination applies to this transaction list, not to the opening/closing balance calculation (those are computed from the full unpaginated history/period).
- This is a read-only reporting endpoint — no `POST`/`PUT`/`DELETE`. It is the v2 equivalent of v1's `getbukukas.php` / `BukuKasdocument.php` / `exportbukukas.php`, minus the Excel export (JSON only, per the current API standard).
