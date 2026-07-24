# Finance Payment API

> **Last updated:** 2026-07-24 21:05:00 WIB
> **Base URL:** `/api/v2/finance-payment`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/finance-payment` | List all finance payments (paginated) |
| POST   | `/api/v2/finance-payment` | Create a new finance payment |
| GET    | `/api/v2/finance-payment/{id}` | Get finance payment detail |
| PUT    | `/api/v2/finance-payment/{id}` | Update a finance payment |
| DELETE | `/api/v2/finance-payment/{id}` | Delete a finance payment |
| PATCH  | `/api/v2/finance-payment/{id}/approve` | Sign the A/P or A/R payment as Business Owner or Treasury/Controller |
| PATCH  | `/api/v2/finance-payment/{id}/reject` | Reject the payment |

---

### GET `/api/v2/finance-payment`

List all finance payments belonging to the authenticated company.

#### Query parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| page | int | No | 1 | Page number |
| limit | int | No | 10 | Items per page (max 100) |
| search | string | No | — | Search on `invoice_number` |
| customer_id | string | No | — | Filter by `customer_id` |
| supplier_id | string | No | — | Filter by `supplier_id` |
| transaction_status | string | No | — | Filter by `transaction_status`: `draft` \| `submitted` \| `partially_approved` \| `posted` \| `rejected` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance payments found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "invoice_number": "INV-2026-0001",
        "paid_amount": 2500000,
        "due_amount": 500000,
        "customer_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
        "supplier_id": null,
        "payment_date": "2026-07-01",
        "form_number": "FRM-0011",
        "bank_account_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
        "exchange_rate": 1,
        "cheque_number": "CHQ-0021",
        "cheque_date": "2026-07-01",
        "cheque_amount": 2500000,
        "memo": "Partial invoice settlement",
        "recipient": "PT Sumber Makmur",
        "discount_amount": 0,
        "transaction_status": "posted",
        "approved_by_owner_id": "614442bc-476a-4bbb-81f1-b3ccc2523d50",
        "approved_by_owner_at": "2026-07-02 09:15:00",
        "approved_by_treasury_id": "b7d39398-4f4d-4c87-be7c-95b5a9baf56e",
        "approved_by_treasury_at": "2026-07-02 14:30:00",
        "created_by": "Budi Santoso",
        "created_at": "2026-07-01 10:00:00",
        "updated_by": "Kevin 2",
        "updated_at": "2026-07-02 14:30:00",
        "approved_by_owner": "Kevin Gabriel",
        "approved_by_treasury": "Kevin 2",
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
  "status_message": "No finance payments found",
  "data": []
}
```

---

### POST `/api/v2/finance-payment`

Create a new finance payment. `invoice_number` must match an existing, non-deleted `sales_invoice.invoice_display_number` in the same company, and that sales invoice's `status_name` must be `Approved` — creation is rejected otherwise.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| invoice_number | string | Yes | Must match an existing sales invoice's `invoice_display_number` in the same company, and that invoice must currently be `Approved` |
| paid_amount | number | Yes | — |
| customer_id | string | No | If provided, must reference an existing, non-deleted customer in the company |
| supplier_id | string | No | If provided, must reference an existing, non-deleted supplier in the company |
| due_amount | number | No | — |
| payment_date | string (date) | No | — |
| form_number | string | No | — |
| bank_account_id | string | No | — |
| exchange_rate | number | No | — |
| cheque_number | string | No | — |
| cheque_date | string (date) | No | — |
| cheque_amount | number | No | — |
| memo | string | No | — |
| recipient | string | No | — |
| discount_amount | number | No | Defaults to `0` |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Finance payment created successfully",
  "data": {
    "finance_payment_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "invoice_number is required",
  "data": []
}
```

```json
{
  "status_code": 400,
  "status_message": "Sales invoice must be approved before a payment can be recorded",
  "data": []
}
```

Returned when `invoice_number` matches a real sales invoice, but that invoice's current status is not `Approved` (e.g. still `Draft` or `Rejected`).

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales invoice not found",
  "data": []
}
```

Returned when `invoice_number` does not match any non-deleted `sales_invoice.invoice_display_number` in the company.

```json
{
  "status_code": 404,
  "status_message": "Customer not found",
  "data": []
}
```

```json
{
  "status_code": 404,
  "status_message": "Supplier not found",
  "data": []
}
```

---

### GET `/api/v2/finance-payment/{id}`

Get detail of a single finance payment.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The finance payment ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance payment found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "invoice_number": "INV-2026-0001",
    "paid_amount": 2500000,
    "due_amount": 500000,
    "customer_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
    "supplier_id": null,
    "payment_date": "2026-07-01",
    "form_number": "FRM-0011",
    "bank_account_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
    "exchange_rate": 1,
    "cheque_number": "CHQ-0021",
    "cheque_date": "2026-07-01",
    "cheque_amount": 2500000,
    "memo": "Partial invoice settlement",
    "recipient": "PT Sumber Makmur",
    "discount_amount": 0,
    "transaction_status": "posted",
    "approved_by_owner_id": "614442bc-476a-4bbb-81f1-b3ccc2523d50",
    "approved_by_owner_at": "2026-07-02 09:15:00",
    "approved_by_treasury_id": "b7d39398-4f4d-4c87-be7c-95b5a9baf56e",
    "approved_by_treasury_at": "2026-07-02 14:30:00",
    "created_by": "Budi Santoso",
    "created_at": "2026-07-01 10:00:00",
    "updated_by": "Kevin 2",
    "updated_at": "2026-07-02 14:30:00",
    "approved_by_owner": "Kevin Gabriel",
    "approved_by_treasury": "Kevin 2",
    "deleted_at": null
  }
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Finance payment not found",
  "data": []
}
```

---

### PUT `/api/v2/finance-payment/{id}`

Update a finance payment. Only send the fields you want to change.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The finance payment ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| invoice_number | string | No | Set to `null` if provided empty |
| form_number | string | No | Set to `null` if provided empty |
| cheque_number | string | No | Set to `null` if provided empty |
| memo | string | No | Set to `null` if provided empty |
| recipient | string | No | Set to `null` if provided empty |
| customer_id | string | No | Set to `null` if provided empty |
| supplier_id | string | No | Set to `null` if provided empty |
| bank_account_id | string | No | Set to `null` if provided empty |
| payment_date | string (date) | No | Set to `null` if provided empty |
| cheque_date | string (date) | No | Set to `null` if provided empty |
| paid_amount | number | No | — |
| due_amount | number | No | — |
| exchange_rate | number | No | — |
| cheque_amount | number | No | — |
| discount_amount | number | No | — |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance payment updated successfully",
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

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Finance payment not found",
  "data": []
}
```

---

### DELETE `/api/v2/finance-payment/{id}`

Soft-deletes the finance payment (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The finance payment ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance payment deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Finance payment not found",
  "data": []
}
```

---

### PATCH `/api/v2/finance-payment/{id}/approve`

Signs the A/P (`supplier_id` set) or A/R (`customer_id` set) settlement as either **Business Owner** or **Treasury/Controller** — whichever slot the caller's role is permitted to fill. Same 2-signer gate as `finance-transaction` (see that module's doc for the full slot-assignment rules); both A/P and A/R go through this one endpoint.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The finance payment ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance payment approved successfully",
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

### PATCH `/api/v2/finance-payment/{id}/reject`

Rejects the payment. Either signer (owner or treasury permission holder) can reject at any point before it reaches `posted`.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The finance payment ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance payment rejected successfully",
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

- **`POST` (create) now requires `invoice_number` to resolve to an `Approved` sales invoice.** `invoice_number` is still a free-text field, not a foreign key — there is no `sales_invoice_id` column on `finance_payment` — but on create it is matched against `sales_invoice.invoice_display_number` (scoped to the company) purely for this validation. `404` if no such invoice exists, `400` if it exists but isn't `Approved`. This check runs on create only; `PUT` does not re-validate `invoice_number` against `sales_invoice` if it's changed (see the existing note below on update not re-validating FK-like fields).
- No enum-constrained fields exist on this module. `customer_id` and `supplier_id` are optional and, only when provided, are validated for existence against non-deleted records in the company; neither is required to be mutually exclusive.
- On update, `customer_id`, `supplier_id`, and `bank_account_id` are not re-validated for existence — only re-checked for emptiness (cleared to `null` if sent empty).
- create/update/delete write `audit_log` rows with action `created`/`updated`/`deleted`; approve writes `approved_owner` or `approved_treasury` depending on which slot was filled; reject writes `rejected`. Query this history via `GET /api/v2/audit-log?module=finance_payment&reference_id={id}` — see the `audit-log` module doc.
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a finance payment (list, detail, and any nested items). Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
- **New: dual approval.** `transaction_status` (`draft` \| `submitted` \| `partially_approved` \| `posted` \| `rejected`), `approved_by_owner_id`/`approved_by_owner_at`, and `approved_by_treasury_id`/`approved_by_treasury_at` are new columns — added specifically for this feature, `finance_payment` had no approval tracking before. New payments are created with `transaction_status = 'draft'`. The 582 payments that existed before this change were backfilled to `transaction_status = 'posted'` with `NULL` approvers (they predate the approval workflow — no real approver identity to backfill, same policy used for historical `finance_transaction` rows). `approved_by_owner`/`approved_by_treasury` (resolved display names) are returned alongside the raw `*_id` fields.
- **Permission model:** shared with `finance-transaction` — `keuangan.approve_owner` and `keuangan.approve_treasury` gate both modules' approve/reject endpoints identically; this is one 2-signer workflow reused across Pembayaran, Penerimaan, A/P, and A/R, not four separate ones. The existing `keuangan.ap.approve`/`keuangan.ar.approve` permission keys are unaffected by this change and continue to mean whatever they meant before (this endpoint does not check them). **As of this writing, no role has `keuangan.approve_owner`/`keuangan.approve_treasury` assigned** — see the `finance-transaction` doc's note on assigning these via the roles/permissions admin UI before go-live.
- Update/delete are **not** blocked by `transaction_status`, matching this codebase's existing precedent elsewhere (see `finance-transaction` doc).
- **`POST` (create) and `PATCH .../approve`/`.../reject` now trigger notifications**, identical mechanics to `finance-transaction` (see that doc's note) — create notifies `keuangan.approve_owner`/`keuangan.approve_treasury` holders, partial approval reminds the other slot and updates the creator, full approval and rejection notify the creator. This is a side effect only; it does not change this endpoint's own request/response shape. See `notification.md`.
