# Finance Transaction API

> **Last updated:** 2026-07-25 07:12:00 WIB
> **Base URL:** `/api/v2/finance-transaction`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/finance-transaction` | List all finance transactions (paginated) |
| POST   | `/api/v2/finance-transaction` | Create a new finance transaction (with details) |
| GET    | `/api/v2/finance-transaction/{id}` | Get finance transaction detail (with details) |
| PUT    | `/api/v2/finance-transaction/{id}` | Update a finance transaction |
| DELETE | `/api/v2/finance-transaction/{id}` | Delete a finance transaction |
| PATCH  | `/api/v2/finance-transaction/{id}/approve` | Sign the transaction as Business Owner or Treasury/Controller |
| PATCH  | `/api/v2/finance-transaction/{id}/reject` | Reject the transaction |
| GET    | `/api/v2/finance-transaction/{id}/details` | List detail lines of a finance transaction |
| POST   | `/api/v2/finance-transaction/{id}/details` | Add a detail line to a finance transaction |
| GET    | `/api/v2/finance-transaction/{id}/details/{detail_id}` | Get a single detail line |
| PUT    | `/api/v2/finance-transaction/{id}/details/{detail_id}` | Update a detail line |
| DELETE | `/api/v2/finance-transaction/{id}/details/{detail_id}` | Delete a detail line |

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
| transaction_status | string | No | — | Filter by `transaction_status`: `draft` \| `submitted` \| `partially_approved` \| `posted` \| `rejected` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance transactions found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "voucher_number": "VC-2026-0001",
        "transaction_date": "2026-07-01",
        "memo": "Office supplies payment",
        "amount": 1500000,
        "cheque_number": "CHQ-0011",
        "payee": "PT Sumber Makmur",
        "transaction_status": "partially_approved",
        "approved_by_owner_at": "2026-07-02 09:15:00",
        "approved_by_treasury_at": null,
        "bank_name": "Bank Central Asia",
        "bank_number": "1234567890",
        "category_name": "Operating Expense",
        "created_by": "Budi Santoso",
        "created_at": "2026-07-01 10:00:00",
        "updated_by": "Kevin Gabriel",
        "updated_at": "2026-07-02 09:15:00",
        "deleted_at": null,
        "approved_by_owner": "Kevin Gabriel",
        "approved_by_treasury": null,
        "accounts": [
          {
            "account_code": "5100",
            "account_code_name": "Office Supplies Expense",
            "amount": 1500000
          }
        ]
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

The list response does **not** include the nested `details` array — only the detail endpoint does. As of this version, the list row also drops `company_id`, `bank_account_id`, `finance_category_id`, `approved_by_owner_id`, and `approved_by_treasury_id` — the resolved display fields (`bank_name`/`bank_number`, `category_name`, `approved_by_owner`/`approved_by_treasury`) are returned instead, and the raw IDs are only available from the detail endpoint (`GET /{id}`). Each row now also carries an `accounts` summary — one entry per GL account split (from `details[]`), each with just `account_code`, `account_code_name`, and `amount` (no `id`, `memo`, or timestamps — use the detail endpoint or the `/details` sub-resource for that). See "Breaking change" note at the bottom.

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

Create a new finance transaction together with its detail lines (one or more GL account splits).

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| bank_account_id | string | Yes | Must reference an existing, non-deleted bank account in the company |
| transaction_date | string (date) | Yes | — |
| amount | number | Yes | Total transaction amount. Must equal the sum of `details[].amount` |
| details | array | Yes | Non-empty array of detail lines — see below |
| finance_category_id | string | Yes | — |
| voucher_number | string | No | — |
| memo | string | No | — |
| cheque_number | string | No | — |
| payee | string | No | — |

**`details[]` object:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| account_code_id | string | Yes | Must reference an existing, non-deleted account code in the company |
| amount | number | Yes | — |
| memo | string | No | Per-line note |

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

```json
{
  "status_code": 400,
  "status_message": "details must be a non-empty array",
  "data": []
}
```

```json
{
  "status_code": 400,
  "status_message": "details.account_code_id is required",
  "data": []
}
```

```json
{
  "status_code": 400,
  "status_message": "Sum of details.amount must equal amount",
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

Get detail of a single finance transaction, including its nested `details` array.

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
    "cheque_number": "CHQ-0011",
    "payee": "PT Sumber Makmur",
    "finance_category_id": "e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b",
    "transaction_status": "partially_approved",
    "approved_by_owner_id": "614442bc-476a-4bbb-81f1-b3ccc2523d50",
    "approved_by_owner_at": "2026-07-02 09:15:00",
    "approved_by_treasury_id": null,
    "approved_by_treasury_at": null,
    "bank_name": "Bank Central Asia",
    "bank_number": "1234567890",
    "category_name": "Operating Expense",
    "created_by": "Budi Santoso",
    "created_at": "2026-07-01 10:00:00",
    "updated_by": "Kevin Gabriel",
    "updated_at": "2026-07-02 09:15:00",
    "approved_by_owner": "Kevin Gabriel",
    "approved_by_treasury": null,
    "deleted_at": null,
    "details": [
      {
        "id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
        "finance_transaction_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "account_code_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
        "amount": 1500000,
        "memo": "Supplies expense",
        "account_code": "5100",
        "account_code_name": "Office Supplies Expense",
        "created_by": "Budi Santoso",
        "created_at": "2026-07-01 10:00:00",
        "updated_by": null,
        "updated_at": null,
        "deleted_at": null
      }
    ]
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

Update a finance transaction. Only send the fields you want to change. Does not update `details` — use the details sub-resource for that.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The finance transaction ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| voucher_number | string | No | Set to `null` if provided empty |
| memo | string | No | Set to `null` if provided empty |
| cheque_number | string | No | Set to `null` if provided empty |
| payee | string | No | Set to `null` if provided empty |
| bank_account_id | string | No | Cannot be empty if provided |
| finance_category_id | string | No | Cannot be empty if provided |
| transaction_date | string (date) | No | — |
| amount | number | No | Not cross-checked against `details` on update — the client is responsible for keeping detail lines reconciled if it changes the header amount |

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

Soft-deletes the finance transaction (sets `deleted_at`) — it will no longer appear in list/detail responses. Does not cascade to `details` — its detail lines are left as-is (they simply stop surfacing anywhere, since every report/dashboard query joins detail lines through a non-deleted header).

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

### PATCH `/api/v2/finance-transaction/{id}/approve`

Signs the transaction as either **Business Owner** or **Treasury/Controller** — whichever slot the caller's role is permitted to fill. Every `finance_transaction` (Pembayaran and Penerimaan alike) requires both signatures before it reaches `posted`.

Which slot is filled is determined entirely server-side from the caller's permissions — the request does not choose a role:
- Caller holds `keuangan.approve_owner` and the owner slot is open → fills `approved_by_owner_id`/`approved_by_owner_at`.
- Caller holds `keuangan.approve_treasury` and the treasury slot is open → fills `approved_by_treasury_id`/`approved_by_treasury_at`.
- The same user can never fill both slots on the same transaction, even if their role holds both permissions.
- After the first signature: `transaction_status` → `partially_approved`. After the second (different) signature: `transaction_status` → `posted`.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The finance transaction ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance transaction approved successfully",
  "data": {
    "approved_slot": "owner",
    "transaction_status": "partially_approved"
  }
}
```

#### Response `403 Forbidden`

```json
{
  "status_code": 403,
  "status_message": "You do not have permission to approve this record",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Record not found",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "You have already signed this record",
  "data": []
}
```

```json
{
  "status_code": 409,
  "status_message": "Record is already posted",
  "data": []
}
```

---

### PATCH `/api/v2/finance-transaction/{id}/reject`

Rejects the transaction. Either signer (owner or treasury permission holder) can reject at any point before it reaches `posted`; rejection is final and does not require both slots to have been filled.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The finance transaction ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance transaction rejected successfully",
  "data": []
}
```

#### Response `403 Forbidden`

```json
{
  "status_code": 403,
  "status_message": "You do not have permission to reject this record",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Record not found",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Record is already rejected",
  "data": []
}
```

---

### Finance transaction details (`/api/v2/finance-transaction/{id}/details`)

Details are also a sub-resource in their own right, separate from the nested `details` array returned on the finance transaction itself. `{id}` below is the parent finance transaction ID; the detail line's own ID is `{detail_id}`. Each detail line represents one GL account split of the transaction (`account_code_id` + `amount`), and every transaction has at least one.

#### GET `/api/v2/finance-transaction/{id}/details`

List all detail lines belonging to the finance transaction.

##### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance transaction details found",
  "data": {
    "data": [
      {
        "id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
        "finance_transaction_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "account_code_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
        "amount": 1500000,
        "memo": "Supplies expense",
        "account_code": "5100",
        "account_code_name": "Office Supplies Expense",
        "created_by": "Budi Santoso",
        "created_at": "2026-07-01 10:00:00",
        "updated_by": null,
        "updated_at": null,
        "deleted_at": null
      }
    ]
  }
}
```

##### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "No finance transaction details found",
  "data": []
}
```

If the parent finance transaction does not belong to the company or does not exist, all detail endpoints respond:

```json
{
  "status_code": 404,
  "status_message": "Finance transaction not found",
  "data": []
}
```

#### POST `/api/v2/finance-transaction/{id}/details`

Add a new detail line to an existing finance transaction. Does not re-validate that the transaction's total `amount` still reconciles with the sum of its detail lines — the client is responsible for keeping them in sync when adding/editing/removing lines after creation.

##### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| account_code_id | string | Yes | Must reference an existing, non-deleted account code in the company |
| amount | number | Yes | — |
| memo | string | No | — |

##### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Finance transaction detail created successfully",
  "data": {
    "finance_transaction_detail_id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c"
  }
}
```

##### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "account_code_id is required",
  "data": []
}
```

##### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Account code not found",
  "data": []
}
```

#### GET `/api/v2/finance-transaction/{id}/details/{detail_id}`

Get a single finance transaction detail line.

##### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance transaction detail found",
  "data": {
    "id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
    "finance_transaction_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "account_code_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
    "amount": 1500000,
    "memo": "Supplies expense",
    "account_code": "5100",
    "account_code_name": "Office Supplies Expense",
    "created_by": "Budi Santoso",
    "created_at": "2026-07-01 10:00:00",
    "updated_by": null,
    "updated_at": null,
    "deleted_at": null
  }
}
```

##### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Finance transaction detail not found",
  "data": []
}
```

#### PUT `/api/v2/finance-transaction/{id}/details/{detail_id}`

Update a finance transaction detail line. Only send the fields you want to change.

##### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| account_code_id | string | No | Cannot be empty if provided; must reference an existing, non-deleted account code in the company |
| amount | number | No | — |
| memo | string | No | Pass `null` or an empty string to clear it |

##### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance transaction detail updated successfully",
  "data": []
}
```

##### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "No fields provided for update",
  "data": []
}
```

##### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Finance transaction detail not found",
  "data": []
}
```

#### DELETE `/api/v2/finance-transaction/{id}/details/{detail_id}`

Soft-deletes the detail line (sets `deleted_at`) — it will no longer appear in list/detail responses or in any report/dashboard aggregation.

##### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance transaction detail deleted successfully",
  "data": []
}
```

##### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Finance transaction detail not found",
  "data": []
}
```

---

## Error responses (all endpoints)

| Code | When |
|------|------|
| 400  | Validation failed — missing or invalid field |
| 401  | Missing or expired Bearer token |
| 403  | Caller's role does not hold `keuangan.approve_owner`/`keuangan.approve_treasury`, or has already signed the other slot |
| 404  | Resource not found |
| 405  | HTTP method not allowed on this path |
| 409  | Duplicate — resource already exists, or already signed/finalized |
| 500  | Internal server error |

---

## Notes

- No enum-constrained fields exist on this module — `bank_account_id`, `account_code_id` (on detail lines), and `finance_category_id` are validated only for existence (bank account and account code) or presence (finance category), not against a fixed value list.
- `bank_account_id` and `details[].account_code_id` must reference an existing, non-deleted record in the same company; `finance_category_id` is only checked for presence, not existence.
- Header + detail creation is wrapped in a single database transaction — either the finance transaction and all its detail lines are created together, or nothing is saved.
- **`SUM(details[].amount)` must equal `amount` on create**, or the request is rejected with `400 Sum of details.amount must equal amount`. This is only enforced at create time — `PUT` on the header and the details sub-resource endpoints do not re-check reconciliation after the fact.
- create/update/delete write `audit_log` rows with action `created`/`updated`/`deleted`; approve writes `approved_owner` or `approved_treasury` depending on which slot was filled; reject writes `rejected`. Detail line sub-resource endpoints do not write their own audit_log rows. Query history via `GET /api/v2/audit-log?module=finance_transaction&reference_id={id}` — see the `audit-log` module doc.
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a finance transaction or a finance transaction detail line — list, detail, and the nested `details` array. Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
- **List and detail responses include LEFT JOIN-resolved display fields**, so the frontend no longer needs to call `bank-account`, `account-code`, and `finance-category` separately and map the results client-side: `bank_name` / `bank_number` (from `bank_account_id`), `category_name` (from `finance_category_id`), and `account_code` / `account_code_name` on each detail line (from `account_code_id`). Any of these can be `null` if the referenced record has been deleted. The detail endpoint (`GET /{id}`) still returns the raw `*_id` fields alongside the resolved names; **the list endpoint (`GET /`) no longer returns `bank_account_id`, `finance_category_id`, `approved_by_owner_id`, `approved_by_treasury_id`, or `company_id`** — only the resolved display fields — since list rows are for display, not for driving edit/approval forms.
- **List rows carry an `accounts` summary array** (`account_code`, `account_code_name`, `amount` per GL split), built from the same `finance_transaction_detail` rows as the detail endpoint's `details` array but fetched in a single grouped query keyed by transaction ID — so a transaction with multiple account splits shows one `accounts` entry per split rather than duplicating the transaction row. It's a lighter summary than `details`: no `id`, `memo`, or timestamps — fetch `GET /{id}` or `GET /{id}/details` for the full record.
- **New: dual approval.** `transaction_status` (`draft` \| `submitted` \| `partially_approved` \| `posted` \| `rejected`), `approved_by_owner_id`/`approved_by_owner_at`, and `approved_by_treasury_id`/`approved_by_treasury_at` are new columns on every finance transaction — Pembayaran (cash-out) **and** Penerimaan (cash-in) alike, there is no debit/credit distinction in the approval requirement. New transactions are created with `transaction_status = 'draft'`. `approved_by_owner`/`approved_by_treasury` (resolved display names) are also returned alongside the raw `*_id` fields, same pattern as `created_by`/`updated_by`.
- **Permission model:** two new permission keys gate the 2-signer workflow — `keuangan.approve_owner` and `keuangan.approve_treasury`. They are shared across `finance-transaction` and `finance-payment` (see that module's doc) since it is the same control in both places. **As of this writing, no role has either permission assigned yet** — an admin must assign `keuangan.approve_owner` to whichever role represents the Business Owner and `keuangan.approve_treasury` to whichever role represents Treasury/Controller (a "Treasury" role does not yet exist in `movira_core` — create it or repurpose an existing role) via the roles/permissions admin UI before this endpoint is usable in production.
- Update/delete are **not** blocked by `transaction_status` — editing or soft-deleting an already-`posted` transaction is still allowed by the API today, matching this codebase's existing precedent on `purchase_order`/`sales_order` (neither locks edits post-approval either). Revisit if Finance wants posted transactions to be immutable.
- **`POST` (create) and `PATCH .../approve`/`.../reject` now trigger notifications.** Create notifies everyone holding `keuangan.approve_owner`/`keuangan.approve_treasury`. On approve: if the signature just applied leaves the transaction `partially_approved`, the still-open slot's holder(s) get a reminder (with a fresh approval link) and the creator gets a progress update; once both signatures are in (`posted`), the creator gets a final notice and any other open approval links for the document are invalidated. Reject notifies the creator. This is a side effect only; it does not change this endpoint's own request/response shape. See `notification.md`.

### Breaking change (2026-07-25)

`GET /api/v2/finance-transaction` (list) no longer returns `company_id`, `bank_account_id`, `finance_category_id`, `approved_by_owner_id`, or `approved_by_treasury_id` on each row — these raw foreign keys were redundant with the already-present resolved display fields (`bank_name`/`bank_number`, `category_name`, `approved_by_owner`/`approved_by_treasury`). If the frontend list view was reading any of these five `*_id` fields directly (e.g. to build an edit link), switch to `GET /{id}` instead, which is unchanged. Each list row also gains a new `accounts` array (see Notes) — additive, not breaking.

### Breaking change (2026-07-23)

`POST /api/v2/finance-transaction` no longer accepts the old flat `account_code_id`/`account_amount`/`account_memo` fields — sending them without `details` now fails with `400 details is required`. Every finance transaction now supports one or more GL account splits via `details[]` instead of exactly one account per transaction. `account_code_id`, `account_amount`, and the joined `account_code`/`account_code_name` fields no longer appear anywhere on the header row (list, detail, or update) — that information now lives exclusively on each entry in the `details` array (create/detail response) or via the new `/details` sub-resource. See `v2/docs/migrations/v26_finance_transaction_multi_account_detail_schema.md` for the schema change this shipped with.
