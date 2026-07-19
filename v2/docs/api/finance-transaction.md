# Finance Transaction API

> **Last updated:** 2026-07-19 WIB
> **Base URL:** `/api/v2/finance-transaction`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/finance-transaction` | List all finance transactions (paginated) |
| POST   | `/api/v2/finance-transaction` | Create a new finance transaction |
| GET    | `/api/v2/finance-transaction/{id}` | Get finance transaction detail |
| PUT    | `/api/v2/finance-transaction/{id}` | Update a finance transaction |
| DELETE | `/api/v2/finance-transaction/{id}` | Delete a finance transaction |

---

### GET `/api/v2/finance-transaction`

List all finance transactions belonging to the authenticated company.

#### Query parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| page | int | No | 1 | Page number |
| limit | int | No | 10 | Items per page (max 100) |
| search | string | No | — | Search on `voucher_number` or `payee` |
| bank_account_id | string | No | — | Filter by `bank_account_id` |
| finance_category_id | string | No | — | Filter by `finance_category_id` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance transactions found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "voucher_number": "VC-2026-0001",
        "bank_account_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
        "transaction_date": "2026-07-01",
        "memo": "Office supplies payment",
        "amount": 1500000,
        "account_code_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
        "account_amount": 1500000,
        "account_memo": "Supplies expense",
        "cheque_number": "CHQ-0011",
        "payee": "PT Sumber Makmur",
        "finance_category_id": "e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b",
        "bank_name": "Bank Central Asia",
        "bank_number": "1234567890",
        "account_code": "5100",
        "account_code_name": "Office Supplies Expense",
        "category_name": "Operating Expense",
        "created_by": "Budi Santoso",
        "created_at": "2026-07-01 10:00:00",
        "updated_by": null,
        "updated_at": null,
        "deleted_at": null
      }
    ],
    "pagination": {
      "total": 42,
      "page": 1,
      "limit": 10,
      "total_pages": 5
    }
  }
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "No finance transactions found",
  "data": []
}
```

---

### POST `/api/v2/finance-transaction`

Create a new finance transaction.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| bank_account_id | string | Yes | Must reference an existing, non-deleted bank account in the company |
| transaction_date | string (date) | Yes | — |
| amount | number | Yes | — |
| account_code_id | string | Yes | Must reference an existing, non-deleted account code in the company |
| account_amount | number | Yes | — |
| finance_category_id | string | Yes | — |
| voucher_number | string | No | — |
| memo | string | No | — |
| account_memo | string | No | — |
| cheque_number | string | No | — |
| payee | string | No | — |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Finance transaction created successfully",
  "data": {
    "finance_transaction_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "bank_account_id is required",
  "data": []
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

```json
{
  "status_code": 404,
  "status_message": "Account code not found",
  "data": []
}
```

---

### GET `/api/v2/finance-transaction/{id}`

Get detail of a single finance transaction.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The finance transaction ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance transaction found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "voucher_number": "VC-2026-0001",
    "bank_account_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
    "transaction_date": "2026-07-01",
    "memo": "Office supplies payment",
    "amount": 1500000,
    "account_code_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
    "account_amount": 1500000,
    "account_memo": "Supplies expense",
    "cheque_number": "CHQ-0011",
    "payee": "PT Sumber Makmur",
    "finance_category_id": "e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b",
    "bank_name": "Bank Central Asia",
    "bank_number": "1234567890",
    "account_code": "5100",
    "account_code_name": "Office Supplies Expense",
    "category_name": "Operating Expense",
    "created_by": "Budi Santoso",
    "created_at": "2026-07-01 10:00:00",
    "updated_by": null,
    "updated_at": null,
    "deleted_at": null
  }
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Finance transaction not found",
  "data": []
}
```

---

### PUT `/api/v2/finance-transaction/{id}`

Update a finance transaction. Only send the fields you want to change.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The finance transaction ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| voucher_number | string | No | Set to `null` if provided empty |
| memo | string | No | Set to `null` if provided empty |
| account_memo | string | No | Set to `null` if provided empty |
| cheque_number | string | No | Set to `null` if provided empty |
| payee | string | No | Set to `null` if provided empty |
| bank_account_id | string | No | Cannot be empty if provided |
| account_code_id | string | No | Cannot be empty if provided |
| finance_category_id | string | No | Cannot be empty if provided |
| transaction_date | string (date) | No | — |
| amount | number | No | — |
| account_amount | number | No | — |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance transaction updated successfully",
  "data": []
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "No fields provided for update",
  "data": []
}
```

```json
{
  "status_code": 400,
  "status_message": "bank_account_id cannot be empty",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Finance transaction not found",
  "data": []
}
```

---

### DELETE `/api/v2/finance-transaction/{id}`

Soft-deletes the finance transaction (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The finance transaction ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance transaction deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Finance transaction not found",
  "data": []
}
```

---

## Error responses (all endpoints)

| Code | When |
|------|------|
| 400  | Validation failed — missing or invalid field |
| 401  | Missing or expired Bearer token |
| 404  | Resource not found |
| 405  | HTTP method not allowed on this path |
| 409  | Duplicate — resource already exists |
| 500  | Internal server error |

---

## Notes

- No enum-constrained fields exist on this module — `bank_account_id`, `account_code_id`, and `finance_category_id` are validated only for existence (bank account and account code) or presence (finance category), not against a fixed value list.
- `bank_account_id` and `account_code_id` must reference an existing, non-deleted record in the same company; `finance_category_id` is only checked for presence, not existence.
- create/update/delete write `audit_log` rows with action `created`/`updated`/`deleted`. Query this history via `GET /api/v2/audit-log?module=finance_transaction&reference_id={id}` — see the `audit-log` module doc.
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a finance transaction (list, detail, and any nested items). Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
- **List and detail responses now include LEFT JOIN-resolved display fields** alongside the existing `*_id` foreign keys, so the frontend no longer needs to call `bank-account`, `account-code`, and `finance-category` separately and map the results client-side: `bank_name` / `bank_number` (from `bank_account_id`), `account_code` / `account_code_name` (from `account_code_id`), and `category_name` (from `finance_category_id`). The `*_id` fields are still returned unchanged. Any of these can be `null` if the referenced record has been deleted.
