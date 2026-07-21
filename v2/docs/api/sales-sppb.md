# Sales SPPB API

> **Last updated:** 2026-07-20 18:58:14 WIB
> **Base URL:** `/api/v2/sales-sppb`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/sales-sppb` | List all sales SPPBs (paginated) |
| GET    | `/api/v2/sales-sppb/generate-number` | Generate the next `sppb_display_number` for the authenticated company |
| POST   | `/api/v2/sales-sppb` | Create a new sales SPPB (with items) |
| GET    | `/api/v2/sales-sppb/{id}` | Get sales SPPB detail (with items) |
| PUT    | `/api/v2/sales-sppb/{id}` | Update a sales SPPB |
| DELETE | `/api/v2/sales-sppb/{id}` | Delete a sales SPPB |
| PATCH  | `/api/v2/sales-sppb/{id}/approve` | Approve a sales SPPB |
| PATCH  | `/api/v2/sales-sppb/{id}/reject` | Reject a sales SPPB |
| PATCH  | `/api/v2/sales-sppb/{id}/revise` | Move a rejected sales SPPB back to Draft |
| GET    | `/api/v2/sales-sppb/{id}/export` | Download the sales SPPB as an `.xlsx` file |

---

### GET `/api/v2/sales-sppb/generate-number`

Generate the next `sppb_display_number` for the authenticated company. This is a read-only preview — it does not reserve or persist the number; it is not guaranteed to remain the next number if another sales SPPB is created in the meantime. Call it right before submitting the `POST` request.

Format: `{seq}/{company_code}-SPPB/{roman_month}/{year}` — e.g. `001/VIK-SPPB/VII/2026`. `seq` is a zero-padded 3-digit counter that resets to `001` at the start of each calendar month and is scoped per company; `company_code` is the authenticated company's code (`app_company.company_code`, uppercased); the month is a Roman numeral (`I`–`XII`).

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales SPPB number generated successfully",
  "data": {
    "sppb_display_number": "001/VIK-SPPB/VII/2026"
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

### GET `/api/v2/sales-sppb`

List all sales SPPBs belonging to the authenticated company.

#### Query parameters

| Parameter      | Type   | Required | Default | Description |
|----------------|--------|----------|---------|-------------|
| page           | int    | No       | 1       | Page number |
| limit          | int    | No       | 10      | Items per page (max 100) |
| search         | string | No       | —       | Search on `sppb_display_number` |
| customer_id    | string | No       | —       | Filter by `customer_id` |
| sales_order_id | string | No       | —       | Filter by `sales_order_id` |
| date_from      | string (date) | No | —    | Filter `sppb_date >=` this date (`YYYY-MM-DD`) |
| date_to        | string (date) | No | —    | Filter `sppb_date <=` this date (`YYYY-MM-DD`) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales SPPBs found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "sppb_display_number": "SPPB-2026-0001",
        "sales_order_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
        "so_display_number": "SO-2026-0001",
        "sppb_date": "2026-07-01",
        "customer_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
        "customer_name": "PT Sumber Makmur",
        "status_id": "e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b",
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
  "status_message": "No sales SPPBs found",
  "data": []
}
```

---

### POST `/api/v2/sales-sppb`

Create a new sales SPPB together with its items. Server-side sets `status_id` to the `Draft` sales status — the client does not send `status_id`.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| sppb_display_number | string | Yes | Unique display number for the SPPB |
| sales_order_id | string | Yes | Sales order ID — must exist and belong to the company |
| sppb_date | string (date) | Yes | SPPB date |
| customer_id | string | Yes | Customer ID |
| items | array | Yes | Non-empty array of SPPB items — see below |

**`items[]` object:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| send_to_address | string | Yes | — |
| send_date | string (date) | Yes | — |
| product_name | string | Yes | — |
| quantity | number | Yes | — |
| uom_id | string | Yes | Unit of measure ID |
| description | string | No | — |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Sales SPPB created successfully",
  "data": {
    "sales_sppb_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "sppb_display_number is required",
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
  "status_message": "items.send_to_address is required",
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

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Sales SPPB already exists",
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

### GET `/api/v2/sales-sppb/{id}`

Get detail of a single sales SPPB, including its nested `items` array.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales SPPB ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales SPPB found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "sppb_display_number": "SPPB-2026-0001",
    "sales_order_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
    "so_display_number": "SO-2026-0001",
    "sppb_date": "2026-07-01",
    "customer_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
    "customer_name": "PT Sumber Makmur",
    "status_id": "e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b",
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
        "id": "e1f2a3b4-c5d6-4e5f-8a9b-0c1d2e3f4a5b",
        "sales_sppb_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "send_to_address": "Jl. Sudirman No. 1, Jakarta",
        "send_date": "2026-07-02",
        "product_name": "Steel Rod 12mm",
        "quantity": 100,
        "uom_id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
        "description": "Handle with care",
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
  "status_message": "Sales SPPB not found",
  "data": []
}
```

---

### PUT `/api/v2/sales-sppb/{id}`

Update a sales SPPB. Only send the fields you want to change. Does not update items. `status_id` is not updatable here — use `PATCH .../approve` or `PATCH .../reject` to change status.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales SPPB ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| sppb_display_number | string | No | Cannot be empty if provided |
| customer_id | string | No | Cannot be empty if provided |
| sppb_date | string (date) | No | — |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales SPPB updated successfully",
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
  "status_message": "Sales SPPB not found",
  "data": []
}
```

---

### DELETE `/api/v2/sales-sppb/{id}`

Soft-deletes the sales SPPB (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales SPPB ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales SPPB deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales SPPB not found",
  "data": []
}
```

---

### PATCH `/api/v2/sales-sppb/{id}/approve`

Approve a sales SPPB. Server-side sets `status_id` to the `Approved` sales status, plus `approved_by`, `approved_at`. The client does not send `status_id`.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales SPPB ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales SPPB approved successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales SPPB not found",
  "data": []
}
```

---

### PATCH `/api/v2/sales-sppb/{id}/reject`

Reject a sales SPPB. Server-side sets `status_id` to the `Rejected` sales status (does not set `approved_by`/`approved_at`). The client does not send `status_id`.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales SPPB ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales SPPB rejected successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales SPPB not found",
  "data": []
}
```

---

### PATCH `/api/v2/sales-sppb/{id}/revise`

Move a rejected sales SPPB back to `Draft` status so it can be edited and resubmitted for approval. Only allowed when the sales SPPB's current status is `Rejected`.

Use `PUT /api/v2/sales-sppb/{id}` to edit the SPPB's fields (before or after calling this endpoint) — `revise` only changes status, it does not accept or update any other field.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales SPPB ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales SPPB revised successfully",
  "data": []
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "Only rejected sales SPPBs can be revised",
  "data": []
}
```

Returned when the sales SPPB's current status is not `Rejected` (e.g. it's `Draft` or `Approve`).

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales SPPB not found",
  "data": []
}
```

---

### GET `/api/v2/sales-sppb/{id}/export`

Download the sales SPPB as a formatted `.xlsx` file (via PhpSpreadsheet), matching the layout of the legacy v1 export (`sales/SPPBExport.php`): title block, header info, item table, and signature block.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales SPPB ID |

#### Response `200 OK`

Binary `.xlsx` file. Headers:

```
Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
Content-Disposition: attachment; filename="sales_sppb_{sppb_display_number}.xlsx"
```

The filename's `sppb_display_number` has any character outside `[A-Za-z0-9_-]` replaced with `-` (the generated display number contains `/`, which is not a valid filename character).

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales SPPB not found",
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

- Header + items creation is wrapped in a single database transaction — either the sales SPPB and all its items are created together, or nothing is saved.
- Create/update/delete write `audit_log` rows with action `created`/`updated`/`deleted` and module string `sales_sppb`; approve/reject/revise write `approved`/`rejected`/`revised`. Query this history via `GET /api/v2/audit-log?module=sales_sppb&reference_id={id}` — see the `audit-log` module doc.
- The list endpoint (`GET /api/v2/sales-sppb`) does not include the nested `items` array; only the detail endpoint (`GET /api/v2/sales-sppb/{id}`) does. The create response only returns `sales_sppb_id`.
- `sales_order_id` is validated to exist (and belong to the company) on create, but is not an updatable field via `PUT`.
- **`status_id` is a server-resolved field, not client-supplied**, matching the pattern already used by sales-order: `POST` sets it to `"Draft"`, `PATCH .../approve` sets it to `"Approve"`, `PATCH .../reject` sets it to `"Rejected"`, `PATCH .../revise` sets it back to `"Draft"`. `PUT` cannot change `status_id` at all. Resolved by matching `sales_status.status_name` — the frontend never needs to know or send a `sales_status.id` UUID. `GET /api/v2/sales-sppb` accepts `status_id` as a read-only filter; resolve it from `GET /api/v2/sales-status` at request time.
- `approve` sets `approved_by` and `approved_at`; `reject` and `revise` do not. If `sales_status` is ever missing a `Draft`/`Approve`/`Rejected` row (non-deleted), the corresponding endpoint returns `500` naming the missing status — a master-data configuration problem, not a client error.
- `revise` only works when the current status is `Rejected` — there is no "un-approve" action; approved SPPBs cannot be reverted to `Draft` through the API.
- **`GET /api/v2/sales-sppb/{id}/export` downloads a formatted `.xlsx`**, replicating the legacy v1 export layout. The "PO CUSTOMER" column from the v1 template is now labeled "NO SO" and shows the linked sales order's `so_display_number` — v2's `sales_sppb_item` has no per-item PO number field (unlike the v1 schema).
- No enum-constrained fields were found in this module's source code, other than `status_id` (see above).
- **List and detail responses now include resolved names alongside their IDs** — `so_display_number` (joined from `sales_order`), `customer_name` (joined from `customer`), and `status_name` (joined from `sales_status`) are returned next to `sales_order_id`, `customer_id`, and `status_id` respectively. The frontend no longer needs a separate lookup call just to display these values in a list or detail view; the IDs are still returned and still required for `PUT`/filter requests. Any may be `null` if the referenced record was deleted.
- `GET /api/v2/sales-sppb/generate-number` counts existing rows (including soft-deleted ones) whose `sppb_display_number` matches the current company/month/year pattern, so the sequence never repeats within a month even if a sales SPPB is later deleted.
- **`created_by`, `updated_by`, and `approved_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on both the sales SPPB itself and its items. Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by`/`approved_by` are `null` until the record has actually been updated/approved; `created_by` can be `null` only if the creating user has since been deleted.
