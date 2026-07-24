# Sales Invoice API

> **Last updated:** 2026-07-24 21:51:35 WIB
> **Base URL:** `/api/v2/sales-invoice`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/sales-invoice/generate-number` | Generate the next `invoice_display_number` for the authenticated company |
| GET    | `/api/v2/sales-invoice` | List all sales invoices (paginated) |
| POST   | `/api/v2/sales-invoice` | Create a new sales invoice (with items) |
| GET    | `/api/v2/sales-invoice/{id}` | Get sales invoice detail (with items) |
| PUT    | `/api/v2/sales-invoice/{id}` | Update a sales invoice |
| DELETE | `/api/v2/sales-invoice/{id}` | Delete a sales invoice |
| PATCH  | `/api/v2/sales-invoice/{id}/approve` | Approve a sales invoice |
| PATCH  | `/api/v2/sales-invoice/{id}/reject` | Reject a sales invoice |
| PATCH  | `/api/v2/sales-invoice/{id}/revise` | Move a rejected sales invoice back to Draft |
| GET    | `/api/v2/sales-invoice/{id}/export` | Download the sales invoice as an `.xlsx` file |

---

### GET `/api/v2/sales-invoice/generate-number`

Generate the next `invoice_display_number` for the authenticated company. This is a read-only preview — it does not reserve or persist the number; it is not guaranteed to remain the next number if another sales invoice is created in the meantime. Call it right before submitting the `POST` request.

Format: `{seq}/{company_code}-INV/{roman_month}/{year}` — e.g. `001/VIK-INV/VII/2026`. `seq` is a zero-padded 3-digit counter that resets to `001` at the start of each calendar month and is scoped per company; `company_code` is the authenticated company's code (`app_company.company_code`, uppercased); the month is a Roman numeral (`I`–`XII`).

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales invoice number generated successfully",
  "data": {
    "invoice_display_number": "001/VIK-INV/VII/2026"
  }
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Company not found",
  "data": []
}
```

---

### GET `/api/v2/sales-invoice`

List all sales invoices belonging to the authenticated company.

#### Query parameters

| Parameter      | Type   | Required | Default | Description |
|----------------|--------|----------|---------|-------------|
| page           | int    | No       | 1       | Page number |
| limit          | int    | No       | 10      | Items per page (max 100) |
| search         | string | No       | —       | Search on `invoice_display_number` |
| status_id      | string | No       | —       | Filter by `status_id`. Resolve this from `GET /api/v2/sales-status` (match on `status_name`) — do not hardcode it |
| customer_id    | string | No       | —       | Filter by `customer_id` |
| sales_order_id | string | No       | —       | Filter by `sales_order_id` |
| date_from      | string (date) | No | —    | Filter `invoice_date >=` this date (`YYYY-MM-DD`) |
| date_to        | string (date) | No | —    | Filter `invoice_date <=` this date (`YYYY-MM-DD`) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales invoices found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "invoice_display_number": "INV-2026-0001",
        "customer_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
        "customer_name": "PT Sumber Makmur",
        "sales_order_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
        "so_display_number": "SO-2026-0001",
        "sales_delivery_id": "e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b",
        "do_display_number": "DO-2026-0001",
        "invoice_date": "2026-07-05",
        "tax_invoice_number": "010.000-26.00000001",
        "ship_to_address": "Jl. Gatot Subroto No. 2, Jakarta",
        "bill_to_address": "Jl. Sudirman No. 1, Jakarta",
        "status_id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
        "status_name": "Draft",
        "approved_by": null,
        "approved_at": null,
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
  "status_message": "No sales invoices found",
  "data": []
}
```

---

### POST `/api/v2/sales-invoice`

Create a new sales invoice together with its items. Server-side sets `status_id` to the `Draft` sales status — the client does not send `status_id`.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| invoice_display_number | string | Yes | Unique display number for the invoice |
| customer_id | string | Yes | Customer ID |
| sales_order_id | string | Yes | Must reference an existing, non-deleted sales order in the same company |
| invoice_date | string (date) | Yes | — |
| ship_to_address | string | Yes | — |
| bill_to_address | string | Yes | — |
| items | array | Yes | Non-empty array of invoice items — see below |
| sales_delivery_id | string | No | If provided, must reference an existing, non-deleted sales delivery in the same company |
| tax_invoice_number | string | No | — |

**`items[]` object:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| product_name | string | Yes | — |
| quantity | number | Yes | — |
| unit_price | number | Yes | — |
| tax | number | No | Defaults to `0` |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Sales invoice created successfully",
  "data": {
    "sales_invoice_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "invoice_display_number is required",
  "data": []
}
```

```json
{
  "status_code": 400,
  "status_message": "items must be a non-empty array",
  "data": []
}
```

```json
{
  "status_code": 400,
  "status_message": "items.unit_price is required",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales order not found",
  "data": []
}
```

```json
{
  "status_code": 404,
  "status_message": "Sales delivery not found",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Sales invoice already exists",
  "data": []
}
```

#### Response `500 Internal Server Error`

```json
{
  "status_code": 500,
  "status_message": "Default sales status \"Draft\" is not configured",
  "data": []
}
```

Returned if `sales_status` has no non-deleted row with `status_name = 'Draft'` — a master-data configuration problem, not a client error.

---

### GET `/api/v2/sales-invoice/{id}`

Get detail of a single sales invoice, including its nested `items` array.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales invoice ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales invoice found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "invoice_display_number": "INV-2026-0001",
    "customer_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
    "customer_name": "PT Sumber Makmur",
    "sales_order_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
    "so_display_number": "SO-2026-0001",
    "sales_delivery_id": "e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b",
    "do_display_number": "DO-2026-0001",
    "invoice_date": "2026-07-05",
    "tax_invoice_number": "010.000-26.00000001",
    "ship_to_address": "Jl. Gatot Subroto No. 2, Jakarta",
    "bill_to_address": "Jl. Sudirman No. 1, Jakarta",
    "status_id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
    "status_name": "Draft",
    "approved_by": null,
    "approved_at": null,
    "created_by": "Budi Santoso",
    "created_at": "2026-07-01 10:00:00",
    "updated_by": null,
    "updated_at": null,
    "deleted_at": null,
    "items": [
      {
        "id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
        "sales_invoice_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "product_name": "Steel Rod 12mm",
        "quantity": 100,
        "unit_price": 50000,
        "tax": 5000,
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
  "status_message": "Sales invoice not found",
  "data": []
}
```

---

### PUT `/api/v2/sales-invoice/{id}`

Update a sales invoice. Only send the fields you want to change. Does not update items. `status_id` is not updatable here — use `PATCH .../approve` or `PATCH .../reject` to change status.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales invoice ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| invoice_display_number | string | No | Cannot be empty if provided |
| customer_id | string | No | Cannot be empty if provided |
| tax_invoice_number | string | No | Cannot be empty if provided |
| ship_to_address | string | No | Cannot be empty if provided |
| bill_to_address | string | No | Cannot be empty if provided |
| invoice_date | string (date) | No | — |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales invoice updated successfully",
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
  "status_message": "Sales invoice not found",
  "data": []
}
```

---

### DELETE `/api/v2/sales-invoice/{id}`

Soft-deletes the sales invoice (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales invoice ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales invoice deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales invoice not found",
  "data": []
}
```

---

### PATCH `/api/v2/sales-invoice/{id}/approve`

Approve a sales invoice. Server-side sets `status_id` to the `Approved` sales status, plus `approved_by`, `approved_at`. The client does not send `status_id`.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales invoice ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales invoice approved successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales invoice not found",
  "data": []
}
```

---

### PATCH `/api/v2/sales-invoice/{id}/reject`

Reject a sales invoice. Server-side sets `status_id` to the `Rejected` sales status (does not set `approved_by`/`approved_at`). The client does not send `status_id`.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales invoice ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales invoice rejected successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales invoice not found",
  "data": []
}
```

---

### PATCH `/api/v2/sales-invoice/{id}/revise`

Move a rejected sales invoice back to `Draft` status so it can be edited and resubmitted for approval. Only allowed when the invoice's current status is `Rejected`.

Use `PUT /api/v2/sales-invoice/{id}` to edit the invoice's fields before or after calling this endpoint. `revise` only changes status, it does not accept or update any other field.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales invoice ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales invoice revised successfully",
  "data": []
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "Only rejected sales invoices can be revised",
  "data": []
}
```

Returned when the invoice's current status is not `Rejected` (e.g. it's `Draft` or `Approved`).

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales invoice not found",
  "data": []
}
```

---

### GET `/api/v2/sales-invoice/{id}/export`

Download the sales invoice as a formatted `.xlsx` file (via PhpSpreadsheet): title block, item table (`Qty`, `Harga Satuan`, `Pajak (%)`, `Subtotal` computed server-side from `quantity`/`unit_price`/`tax`), totals row, and signature block.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales invoice ID |

#### Response `200 OK`

Binary `.xlsx` file. Headers:

```
Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
Content-Disposition: attachment; filename="sales_invoice_{invoice_display_number}.xlsx"
```

The filename's `invoice_display_number` has any character outside `[A-Za-z0-9_-]` replaced with `-`.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales invoice not found",
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

- Header + items creation is wrapped in a single database transaction — either the sales invoice and all its items are created together, or nothing is saved.
- Create/update/delete write `audit_log` rows with action `created`/`updated`/`deleted` and module string `sales_invoice`; approve/reject/revise write `approved`/`rejected`/`revised`. Query this history via `GET /api/v2/audit-log?module=sales_invoice&reference_id={id}` — see the `audit-log` module doc.
- The list endpoint (`GET /api/v2/sales-invoice`) does not include the nested `items` array; only the detail endpoint (`GET /api/v2/sales-invoice/{id}`) does. The create response only returns `sales_invoice_id`.
- No enum constraints are enforced on any field other than `status_id`, which is server-resolved (see below) — never client-supplied.
- **List and detail responses now include resolved names alongside their IDs** — `customer_name` (joined from `customer`), `so_display_number` (joined from `sales_order`), `do_display_number` (joined from `sales_delivery`), and `status_name` (joined from `sales_status`) are returned next to `customer_id`, `sales_order_id`, `sales_delivery_id`, and `status_id` respectively. The frontend no longer needs a separate lookup call just to display these values in a list or detail view; the IDs are still returned and still required for `PUT`/filter requests. `do_display_number` is `null` whenever `sales_delivery_id` is `null` (it's an optional link); the others may be `null` only if the referenced record was deleted.
- **`created_by`, `updated_by`, and `approved_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on both the sales invoice itself and its items. Previously `created_by`/`updated_by` held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by`/`approved_by` are `null` until the record has actually been updated/approved; `created_by` can be `null` only if the creating user has since been deleted.
- **`status_id` is a server-resolved field, not client-supplied**, matching the pattern already used by `sales-order`/`sales-sppb`/`sales-delivery`/`sales-profit`: `POST` sets it to `"Draft"`, `PATCH .../approve` sets it to `"Approved"`, `PATCH .../reject` sets it to `"Rejected"`, `PATCH .../revise` sets it back to `"Draft"`. `PUT` cannot change `status_id` at all. Resolved by matching `sales_status.status_name` — the frontend never needs to know or send a `sales_status.id` UUID. `GET /api/v2/sales-invoice` accepts `status_id` as a read-only filter; resolve it from `GET /api/v2/sales-status` at request time.
- `approve` sets `approved_by` and `approved_at`; `reject` and `revise` do not. If `sales_status` is ever missing a `Draft`/`Approved`/`Rejected` row (non-deleted), the corresponding endpoint returns `500` naming the missing status — a master-data configuration problem, not a client error.
- `revise` only works when the current status is `Rejected` — there is no "un-approve" action; approved invoices cannot be reverted to `Draft` through the API.
- **Every sales invoice that existed before `status_id` was added has been backfilled to `Draft`** (200 rows on dev, as of the migration that introduced this field) — this was a deliberate choice, not an inference: pre-existing invoices are treated as not-yet-approved rather than grandfathered in as already-approved. Expect them to appear in any "pending approval" view built around `status_name = 'Draft'`. See `v2/docs/migrations/v27_sales_invoice_approval_schema.md`.
- Frontend should gate the Approve/Reject buttons on `status_name === 'Draft'`, and a Revise action on `status_name === 'Rejected'`.
