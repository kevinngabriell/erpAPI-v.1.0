# Finance Payment API

> **Last updated:** 2026-07-26 19:15:00 WIB
> **Base URL:** `/api/v2/finance-payment`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/finance-payment` | List all finance payments (paginated) |
| GET    | `/api/v2/finance-payment/outstanding-invoices` | List a single supplier's or customer's unpaid invoices |
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

### GET `/api/v2/finance-payment/outstanding-invoices`

List the unpaid invoices for a single supplier (A/P) or customer (A/R) — the v2 replacement for the legacy `showspurchaseinvoice.php`/`showssalesinvoice.php` endpoints. Used to populate the invoice-selection table on the A/P and A/R payment-entry screens after a vendor/customer is chosen.

Not paginated — a partner's open invoice count is small enough to return in full.

#### Query parameters

Exactly one of `supplier_id` or `customer_id` must be provided.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| supplier_id | string | One of `supplier_id`/`customer_id` | Look up A/P invoices for this supplier |
| customer_id | string | One of `supplier_id`/`customer_id` | Look up A/R invoices for this customer |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Outstanding invoices found",
  "data": {
    "data": [
      {
        "invoice_number": "001/ABC-INV/VII/2026",
        "invoice_date": "2026-07-10",
        "invoice_value": 5000000,
        "paid_amount": 3500000,
        "outstanding": 1500000,
        "purchase_order_id": "po_64a1b2c3",
        "kurs": 15500,
        "currency_code": "USD",
        "currency_name": "US Dollar",
        "status_name": "Approved"
      }
    ]
  }
}
```

`invoice_value` is the invoice's total amount; `paid_amount` is the sum of everything paid against it so far; `outstanding` = `invoice_value - paid_amount`. Only invoices where `outstanding > 0` are returned — a fully paid invoice never appears here. `invoice_date` is joined from `purchase_invoice`/`sales_invoice` and can be `null` if that invoice record was hard-deleted independently of its `finance_payment` rows.

`purchase_order_id`, `kurs` (the exchange rate the invoice was created at), and `currency_code`/`currency_name` (resolved from `purchase_order.currency_id`, same resolution as `purchase-order.md`) are only present on the A/P side (`supplier_id` query) — these four keys are absent entirely from A/R (`customer_id` query) results, since `sales_invoice`/`sales_order` carries currency/kurs at the item level rather than the header and isn't resolved here. On the A/P side, if the underlying `purchase_invoice`/`purchase_order`/`currency` row can't be matched (e.g. hard-deleted, or no currency set on the PO), the corresponding field(s) are `null` rather than omitted.

**`status_name`** is new — resolved from `purchase_status`/`sales_status` (matching whichever side the query is on) and reflects the invoice's *current* status, not its status at the time the baseline `finance_payment` row was seeded. Because a `finance_payment` baseline row is only ever created once (on first approval) and is never deleted if the invoice is later rejected, an invoice can appear here with `status_name` other than `"Approved"` — e.g. it was approved, then rejected and sent back for revision. **The frontend must check `status_name === "Approved"` before allowing the row to be selected for payment** — `POST /api/v2/finance-payment` will reject the attempt with `400` otherwise (see below).

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "supplier_id or customer_id is required",
  "data": []
}
```

Also returned as `Provide only one of supplier_id or customer_id` if both are sent.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Supplier not found",
  "data": []
}
```

Also returned as `Customer not found` (when `customer_id` doesn't resolve), or `No outstanding invoices found` (when the partner exists but has nothing outstanding).

---

### POST `/api/v2/finance-payment`

Create a new finance payment. If `supplier_id` is provided (A/P), `invoice_number` must match an existing, non-deleted `purchase_invoice.invoice_display_number` in the same company with `status_name = 'Approved'`. Otherwise (A/R), it must match an existing, non-deleted `sales_invoice.invoice_display_number` with `status_name = 'Approved'`. Creation is rejected otherwise. Which table is checked is decided purely by whether `supplier_id` is present in the request body — it does not look at `customer_id` or the invoice number's format.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| invoice_number | string | Yes | Must match an existing invoice's `invoice_display_number` in the same company (`purchase_invoice` if `supplier_id` is set, otherwise `sales_invoice`), and that invoice must currently be `Approved` |
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

Returned when `supplier_id` is **not** set and `invoice_number` matches a real sales invoice, but that invoice's current status is not `Approved` (e.g. still `Draft`, or `Rejected` after having been approved and then rejected).

```json
{
  "status_code": 400,
  "status_message": "Purchase invoice must be approved before a payment can be recorded",
  "data": []
}
```

Returned when `supplier_id` **is** set and `invoice_number` matches a real purchase invoice, but that invoice's current status is not `Approved`.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales invoice not found",
  "data": []
}
```

Returned when `supplier_id` is not set and `invoice_number` does not match any non-deleted `sales_invoice.invoice_display_number` in the company.

```json
{
  "status_code": 404,
  "status_message": "Purchase invoice not found",
  "data": []
}
```

Returned when `supplier_id` is set and `invoice_number` does not match any non-deleted `purchase_invoice.invoice_display_number` in the company.

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

- **`POST` (create) now requires `invoice_number` to resolve to an `Approved` invoice.** `invoice_number` is still a free-text field, not a foreign key — there is no `sales_invoice_id`/`purchase_invoice_id` column on `finance_payment` — but on create it is matched against `sales_invoice.invoice_display_number` (A/R) or `purchase_invoice.invoice_display_number` (A/P) (both scoped to the company) purely for this validation, decided by whether `supplier_id` is present in the request body. `404` if no matching invoice exists, `400` if it exists but isn't `Approved`. This check runs on create only; `PUT` does not re-validate `invoice_number` against the invoice table if it's changed (see the existing note below on update not re-validating FK-like fields). **Previously this gate only ever checked `sales_invoice`, regardless of whether the payment was A/P or A/R** — A/P payments (`supplier_id` set) were not gated on the purchase invoice's approval status at all; this has been fixed.
- No enum-constrained fields exist on this module. `customer_id` and `supplier_id` are optional and, only when provided, are validated for existence against non-deleted records in the company; neither is required to be mutually exclusive.
- On update, `customer_id`, `supplier_id`, and `bank_account_id` are not re-validated for existence — only re-checked for emptiness (cleared to `null` if sent empty).
- create/update/delete write `audit_log` rows with action `created`/`updated`/`deleted`; approve writes `approved_owner` or `approved_treasury` depending on which slot was filled; reject writes `rejected`. Query this history via `GET /api/v2/audit-log?module=finance_payment&reference_id={id}` — see the `audit-log` module doc.
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a finance payment (list, detail, and any nested items). Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
- **New: dual approval.** `transaction_status` (`draft` \| `submitted` \| `partially_approved` \| `posted` \| `rejected`), `approved_by_owner_id`/`approved_by_owner_at`, and `approved_by_treasury_id`/`approved_by_treasury_at` are new columns — added specifically for this feature, `finance_payment` had no approval tracking before. New payments are created with `transaction_status = 'draft'`. The 582 payments that existed before this change were backfilled to `transaction_status = 'posted'` with `NULL` approvers (they predate the approval workflow — no real approver identity to backfill, same policy used for historical `finance_transaction` rows). `approved_by_owner`/`approved_by_treasury` (resolved display names) are returned alongside the raw `*_id` fields.
- **Permission model:** shared with `finance-transaction` — `keuangan.approve_owner` and `keuangan.approve_treasury` gate both modules' approve/reject endpoints identically; this is one 2-signer workflow reused across Pembayaran, Penerimaan, A/P, and A/R, not four separate ones. The existing `keuangan.ap.approve`/`keuangan.ar.approve` permission keys are unaffected by this change and continue to mean whatever they meant before (this endpoint does not check them). **As of this writing, no role has `keuangan.approve_owner`/`keuangan.approve_treasury` assigned** — see the `finance-transaction` doc's note on assigning these via the roles/permissions admin UI before go-live.
- Update/delete are **not** blocked by `transaction_status`, matching this codebase's existing precedent elsewhere (see `finance-transaction` doc).
- **`POST` (create) and `PATCH .../approve`/`.../reject` now trigger notifications**, identical mechanics to `finance-transaction` (see that doc's note) — create notifies `keuangan.approve_owner`/`keuangan.approve_treasury` holders, partial approval reminds the other slot and updates the creator, full approval and rejection notify the creator. This is a side effect only; it does not change this endpoint's own request/response shape. See `notification.md`.
- **New: baseline rows are seeded automatically.** `PATCH /api/v2/purchase-invoice/{id}/approve` and `PATCH /api/v2/sales-invoice/{id}/approve` now insert a `finance_payment` row for that invoice (`paid_amount: 0`, `due_amount: <invoice total>`, `transaction_status: "posted"`) if one doesn't already exist — this is what makes `outstanding-invoices` (and `has_outstanding` on `GET /api/v2/supplier`/`GET /api/v2/customer`) able to see an invoice that has never had a manual payment recorded. These rows are indistinguishable from a real payment in `GET /api/v2/finance-payment` history other than having `payment_date: null` and no bank/cheque info — this is expected, not a bug.
