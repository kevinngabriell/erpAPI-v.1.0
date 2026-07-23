# Sales Profit API

> **Last updated:** 2026-07-23 23:15:00 WIB
> **Base URL:** `/api/v2/sales-profit`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/sales-profit` | List all sales profits (paginated) |
| POST   | `/api/v2/sales-profit` | Create a new sales profit record (with items) |
| GET    | `/api/v2/sales-profit/{id}` | Get sales profit detail (with items) |
| PUT    | `/api/v2/sales-profit/{id}` | Update a sales profit record |
| DELETE | `/api/v2/sales-profit/{id}` | Delete a sales profit record |
| PATCH  | `/api/v2/sales-profit/{id}/approve` | Approve a sales profit record |
| PATCH  | `/api/v2/sales-profit/{id}/reject` | Reject a sales profit record |
| PATCH  | `/api/v2/sales-profit/{id}/revise` | Move a rejected sales profit record back to Draft |
| GET    | `/api/v2/sales-profit/{id}/export` | Download the sales profit record as an `.xlsx` file |

---

### GET `/api/v2/sales-profit`

List all sales profit records belonging to the authenticated company.

#### Query parameters

| Parameter      | Type   | Required | Default | Description |
|----------------|--------|----------|---------|-------------|
| page           | int    | No       | 1       | Page number |
| limit          | int    | No       | 10      | Items per page (max 100) |
| search         | string | No       | —       | Search on the linked sales order's `so_display_number` (left-joined) |
| customer_id    | string | No       | —       | Filter by `customer_id` |
| sales_order_id | string | No       | —       | Filter by `sales_order_id` |
| date_from      | string (date) | No | —    | Filter `created_at >=` this date (`YYYY-MM-DD`, start of day) |
| date_to        | string (date) | No | —    | Filter `created_at <=` this date (`YYYY-MM-DD`, end of day) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales profits found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "sales_order_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
        "so_display_number": "SO-2026-0001",
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
  "status_message": "No sales profits found",
  "data": []
}
```

---

### POST `/api/v2/sales-profit`

Create a new sales profit record together with its items. Server-side sets `status_id` to the `Draft` sales status — the client does not send `status_id`.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| sales_order_id | string | Yes | Sales order ID — must exist and belong to the company |
| customer_id | string | Yes | Customer ID |
| items | array | Yes | Non-empty array of sales profit items — see below |

**`items[]` object:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| product_name | string | Yes | — |
| quantity | number | Yes | — |
| price | number | Yes | Sale price |
| landed_cost | number | Yes | Landed cost of the item |
| purchase_order_id | string | No | Related purchase order ID |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Sales profit created successfully",
  "data": {
    "sales_profit_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "sales_order_id is required",
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
  "status_message": "items.price is required",
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

### GET `/api/v2/sales-profit/{id}`

Get detail of a single sales profit record, including its nested `items` array.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales profit ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales profit found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "sales_order_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
    "so_display_number": "SO-2026-0001",
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
        "sales_profit_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "purchase_order_id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
        "product_name": "Steel Rod 12mm",
        "quantity": 100,
        "price": 55000,
        "landed_cost": 50000,
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
  "status_message": "Sales profit not found",
  "data": []
}
```

---

### PUT `/api/v2/sales-profit/{id}`

Update a sales profit record. Only send the fields you want to change. `status_id` is not updatable here — use `PATCH .../approve` or `PATCH .../reject` to change status.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales profit ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| customer_id | string | No | Cannot be empty if provided |
| sales_order_id | string | No | Cannot be empty if provided; must exist and belong to the company |
| items | array | No | When provided, must be a non-empty array and **fully replaces** the record's existing items — see below |

**`items[]` object (required per item when `items` is provided):**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| product_name | string | Yes | — |
| quantity | number | Yes | — |
| price | number | Yes | Sale price |
| landed_cost | number | Yes | Landed cost of the item |
| purchase_order_id | string | No | Related purchase order ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales profit updated successfully",
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
  "status_message": "items must be a non-empty array",
  "data": []
}
```

```json
{
  "status_code": 400,
  "status_message": "items.landed_cost is required",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales profit not found",
  "data": []
}
```

```json
{
  "status_code": 404,
  "status_message": "Sales order not found",
  "data": []
}
```

---

### DELETE `/api/v2/sales-profit/{id}`

Soft-deletes the sales profit (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales profit ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales profit deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales profit not found",
  "data": []
}
```

---

### PATCH `/api/v2/sales-profit/{id}/approve`

Approve a sales profit record. Server-side sets `status_id` to the `Approved` sales status, plus `approved_by`, `approved_at`. The client does not send `status_id`.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales profit ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales profit approved successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales profit not found",
  "data": []
}
```

---

### PATCH `/api/v2/sales-profit/{id}/reject`

Reject a sales profit record. Server-side sets `status_id` to the `Rejected` sales status (does not set `approved_by`/`approved_at`). The client does not send `status_id`.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales profit ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales profit rejected successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales profit not found",
  "data": []
}
```

---

### PATCH `/api/v2/sales-profit/{id}/revise`

Move a rejected sales profit record back to `Draft` status so it can be edited and resubmitted for approval. Only allowed when the record's current status is `Rejected`.

Use `PUT /api/v2/sales-profit/{id}` to edit the record's fields — including item-level corrections like `landed_cost` — before or after calling this endpoint. `revise` only changes status, it does not accept or update any other field.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales profit ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales profit revised successfully",
  "data": []
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "Only rejected sales profit records can be revised",
  "data": []
}
```

Returned when the record's current status is not `Rejected` (e.g. it's `Draft` or `Approve`).

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales profit not found",
  "data": []
}
```

---

### GET `/api/v2/sales-profit/{id}/export`

Download the sales profit record as a formatted `.xlsx` file (via PhpSpreadsheet), matching the layout of the legacy v1 export (`sales/ProfitExport.php`): title block, item table (`PROFIT` and `Profit %` computed server-side from `price`/`landed_cost`), totals row, and signature block.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales profit ID |

#### Response `200 OK`

Binary `.xlsx` file. Headers:

```
Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
Content-Disposition: attachment; filename="sales_profit_{so_display_number}.xlsx"
```

The filename's `so_display_number` has any character outside `[A-Za-z0-9_-]` replaced with `-`. Falls back to the sales profit's own `id` if the linked sales order no longer resolves.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales profit not found",
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

- Header + items creation is wrapped in a single database transaction — either the sales profit record and all its items are created together, or nothing is saved.
- **`PUT` item replacement is also transactional and destructive-by-replace**: when `items` is provided, all of the record's existing (non-deleted) items are soft-deleted and the submitted array is inserted as entirely new rows (new `id`s) in the same transaction as any header field changes. There is no per-item partial update — omitting an existing item from the array removes it; the whole set must be resent even to change a single item's `landed_cost`. This mirrors the `sales-sppb` module's `PUT` behavior and is the mechanism for correcting item data on a `Rejected` record before resubmitting via `revise`.
- Create/update/delete write `audit_log` rows with action `created`/`updated`/`deleted` and module string `sales_profit`; approve/reject/revise write `approved`/`rejected`/`revised`. Query this history via `GET /api/v2/audit-log?module=sales_profit&reference_id={id}` — see the `audit-log` module doc.
- The list endpoint (`GET /api/v2/sales-profit`) does not include the nested `items` array; only the detail endpoint (`GET /api/v2/sales-profit/{id}`) does. The create response only returns `sales_profit_id`.
- Unlike sales SPPB, this module has no unique display-number field and no duplicate (409) check on create.
- `price` and `landed_cost` are stored exactly as submitted by the client — the API does not calculate a profit or margin field anywhere in the JSON response; any profit/margin figure must be derived by the consumer from `price` and `landed_cost`. The `.xlsx` export is the one place profit/margin is computed server-side, for display purposes only — it does not persist those computed values back to the database.
- **`status_id` is a server-resolved field, not client-supplied**, matching the pattern already used by sales-order: `POST` sets it to `"Draft"`, `PATCH .../approve` sets it to `"Approve"`, `PATCH .../reject` sets it to `"Rejected"`, `PATCH .../revise` sets it back to `"Draft"`. `PUT` cannot change `status_id` at all. Resolved by matching `sales_status.status_name` — the frontend never needs to know or send a `sales_status.id` UUID. `GET /api/v2/sales-profit` does not currently expose `status_id` as a list filter (unlike sales-order/sales-sppb).
- `approve` sets `approved_by` and `approved_at`; `reject` and `revise` do not. If `sales_status` is ever missing a `Draft`/`Approve`/`Rejected` row (non-deleted), the corresponding endpoint returns `500` naming the missing status — a master-data configuration problem, not a client error.
- `revise` only works when the current status is `Rejected` — there is no "un-approve" action; approved records cannot be reverted to `Draft` through the API.
- **The `.xlsx` export's "Kurs" value is not stored on the sales profit record itself** — `sales_profit_item` has no `kurs` field. It is looked up from the earliest (by `created_at`) non-deleted item on the linked `sales_order_item`, mirroring how the v1 script joined `salesOrderItem.Kurs`. Shows `N/A` if no such item exists.
- `search` is matched against the linked sales order's `so_display_number` via a `LEFT JOIN`, not against any field on the sales profit record itself — sales profit rows have no display number of their own. Records whose `sales_order_id` no longer resolves to a sales order are excluded from `search` results (but still returned when `search` is omitted).
- **List and detail responses now include resolved names alongside their IDs** — `so_display_number` (joined from `sales_order`), `customer_name` (joined from `customer`), and `status_name` (joined from `sales_status`) are returned next to `sales_order_id`, `customer_id`, and `status_id` respectively. The frontend no longer needs a separate lookup call just to display these values in a list or detail view; the IDs are still returned and still required for `PUT`/filter requests. Any may be `null` if the referenced record was deleted.
- **`created_by`, `updated_by`, and `approved_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on both the sales profit record itself and its items. Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by`/`approved_by` are `null` until the record has actually been updated/approved; `created_by` can be `null` only if the creating user has since been deleted.
