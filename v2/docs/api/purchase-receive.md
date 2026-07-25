# Purchase Receive API

> **Last updated:** 2026-07-25 17:58:51 WIB
> **Base URL:** `/api/v2/purchase-receive`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/purchase-receive` | List all purchase receives (paginated) |
| POST   | `/api/v2/purchase-receive` | Create a new purchase receive (with items) |
| GET    | `/api/v2/purchase-receive/{id}` | Get purchase receive detail (with items) |
| PUT    | `/api/v2/purchase-receive/{id}` | Update a purchase receive |
| DELETE | `/api/v2/purchase-receive/{id}` | Delete a purchase receive |
| PATCH  | `/api/v2/purchase-receive/{id}/approve` | Approve a purchase receive |
| PATCH  | `/api/v2/purchase-receive/{id}/reject` | Reject a purchase receive |
| PATCH  | `/api/v2/purchase-receive/{id}/revise` | Revise a rejected purchase receive back to Draft |
| GET    | `/api/v2/purchase-receive/{id}/export` | Download the purchase receive as a `.docx` file |
| GET    | `/api/v2/purchase-receive/{id}/items` | List items of a purchase receive |
| GET    | `/api/v2/purchase-receive/{id}/items/{item_id}` | Get a single purchase receive item |
| PUT    | `/api/v2/purchase-receive/{id}/items/{item_id}` | Update a purchase receive item |

---

### GET `/api/v2/purchase-receive`

List all purchase receives belonging to the authenticated company.

#### Query parameters

| Parameter          | Type   | Required | Default | Description |
|--------------------|--------|----------|---------|-------------|
| page               | int    | No       | 1       | Page number |
| limit              | int    | No       | 10      | Items per page (max 100) |
| search             | string | No       | —       | Search on the linked purchase order's `po_display_number` (left-joined) |
| purchase_order_id  | string | No       | —       | Filter by `purchase_order_id` |
| supplier_id        | string | No       | —       | Filter by `supplier_id` |
| status_id          | string | No       | —       | Filter by `status_id` |
| date_from          | string (date) | No | —    | Filter `receiving_date >=` this date (`YYYY-MM-DD`) |
| date_to            | string (date) | No | —    | Filter `receiving_date <=` this date (`YYYY-MM-DD`) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase receives found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "purchase_order_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
        "po_display_number": "PO-2026-0001",
        "supplier_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
        "supplier_name": "PT Sumber Baja",
        "receiving_date": "2026-07-05",
        "ship_date": "2026-07-01",
        "ship_via_id": "e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b",
        "ship_name": "Sea Freight",
        "status_id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
        "status_name": "Draft",
        "approved_by": null,
        "approved_at": null,
        "created_by": "Budi Santoso",
        "created_at": "2026-07-05 09:00:00",
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
  "status_message": "No purchase receives found",
  "data": []
}
```

---

### POST `/api/v2/purchase-receive`

Create a new purchase receive together with its items. Requires the referenced purchase order to exist for the authenticated company. Server-side sets `status_id` to the `Draft` purchase status — the client does not send `status_id`.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| purchase_order_id | string | Yes | Must reference an existing, non-deleted purchase order for the company |
| supplier_id | string | Yes | Supplier ID |
| receiving_date | string (date) | Yes | — |
| ship_date | string (date) | Yes | — |
| ship_via_id | string | Yes | — |
| items | array | Yes | Non-empty array of receive items — see below |

**`items[]` object:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| product_name | string | Yes | — |
| quantity | number | Yes | — |
| packaging_size | number | Yes | — |
| unit_price | number | Yes | — |
| vat | number | No | Defaults to `0` |
| total | number | No | Defaults to `(quantity * unit_price) + vat` if not provided |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Purchase receive created successfully",
  "data": {
    "purchase_receive_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "purchase_order_id is required",
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
  "status_message": "items.product_name is required",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Purchase order not found",
  "data": []
}
```

---

### GET `/api/v2/purchase-receive/{id}`

Get detail of a single purchase receive, including its nested `items` array.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase receive ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase receive found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "purchase_order_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
    "po_display_number": "PO-2026-0001",
    "supplier_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
    "supplier_name": "PT Sumber Baja",
    "receiving_date": "2026-07-05",
    "ship_date": "2026-07-01",
    "ship_via_id": "e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b",
    "ship_name": "Sea Freight",
    "status_id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
    "status_name": "Draft",
    "approved_by": null,
    "approved_at": null,
    "created_by": "Budi Santoso",
    "created_at": "2026-07-05 09:00:00",
    "updated_by": null,
    "updated_at": null,
    "deleted_at": null,
    "items": [
      {
        "id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
        "purchase_receive_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "product_name": "Steel Rod 12mm",
        "quantity": 100,
        "packaging_size": 10,
        "unit_price": 50000,
        "vat": 5000,
        "total": 5005000,
        "created_by": "Budi Santoso",
        "created_at": "2026-07-05 09:00:00",
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
  "status_message": "Purchase receive not found",
  "data": []
}
```

---

### PUT `/api/v2/purchase-receive/{id}`

Update a purchase receive. Only send the fields you want to change. Does not update `purchase_order_id`, items, or `status_id` — use the items sub-resource for item changes; status transitions only happen via `approve`/`reject`/`revise`.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase receive ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| supplier_id | string | No | Cannot be empty if provided |
| ship_via_id | string | No | Cannot be empty if provided |
| receiving_date | string (date) | No | — |
| ship_date | string (date) | No | — |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase receive updated successfully",
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
  "status_message": "supplier_id cannot be empty",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Purchase receive not found",
  "data": []
}
```

---

### DELETE `/api/v2/purchase-receive/{id}`

Soft-deletes the purchase receive (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase receive ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase receive deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Purchase receive not found",
  "data": []
}
```

---

### PATCH `/api/v2/purchase-receive/{id}/approve`

Approve a purchase receive. Server-side sets `status_id` to the `Approved` purchase status, plus `approved_by`, `approved_at`. The client does not send `status_id`.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase receive ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase receive approved successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Purchase receive not found",
  "data": []
}
```

---

### PATCH `/api/v2/purchase-receive/{id}/reject`

Reject a purchase receive. Server-side sets `status_id` to the `Rejected` purchase status (does not set `approved_by`/`approved_at`). The client does not send `status_id`.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase receive ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase receive rejected successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Purchase receive not found",
  "data": []
}
```

---

### PATCH `/api/v2/purchase-receive/{id}/revise`

Move a rejected purchase receive back to `Draft` so it can be edited and resubmitted. Only allowed when the current status is `Rejected`.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase receive ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase receive revised successfully",
  "data": []
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "Only rejected purchase receives can be revised",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Purchase receive not found",
  "data": []
}
```

---

### GET `/api/v2/purchase-receive/{id}/export`

Download the purchase receive as a formatted `.docx` file (built with `PhpOffice\PhpWord`, no legacy template — v1 had no equivalent export for this module). Header info (linked PO number, supplier, receiving/ship dates, ship-via), an item table (`QTY`/`PACKING`/`HARGA @`/`VAT`/`TOTAL`), grand total, and a signature block (`created_by`/`approved_by`).

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase receive ID |

#### Response `200 OK`

Binary `.docx` file. Headers:

```
Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document
Content-Disposition: attachment; filename="PurchaseReceive-{po_display_number}-{receiving_date}.docx"
```

The filename's `po_display_number` has any character outside `[A-Za-z0-9_-]` replaced with `-`; `{receiving_date}` is formatted `YYYYMMDD`.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Purchase receive not found",
  "data": []
}
```

---

### Purchase receive items (`/api/v2/purchase-receive/{id}/items`)

Items are also a sub-resource in their own right, separate from the nested `items` array returned on the purchase receive itself. `{id}` below is the parent purchase receive ID; the item's own ID is `{item_id}`. Unlike purchase-order items, receive items cannot be added or deleted through this sub-resource — only updated — since receive items are created together with the parent record (`POST /api/v2/purchase-receive`).

#### GET `/api/v2/purchase-receive/{id}/items`

List all items belonging to the purchase receive.

##### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase receive items found",
  "data": {
    "data": [
      {
        "id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
        "purchase_receive_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "product_name": "Steel Rod 12mm",
        "quantity": 100,
        "packaging_size": 10,
        "unit_price": 50000,
        "vat": 5000,
        "total": 5005000,
        "created_by": "Budi Santoso",
        "created_at": "2026-07-05 09:00:00",
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
  "status_message": "No purchase receive items found",
  "data": []
}
```

If the parent purchase receive does not belong to the company or does not exist, all item endpoints respond:

```json
{
  "status_code": 404,
  "status_message": "Purchase receive not found",
  "data": []
}
```

#### GET `/api/v2/purchase-receive/{id}/items/{item_id}`

Get a single purchase receive item.

##### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase receive item found",
  "data": {
    "id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
    "purchase_receive_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "product_name": "Steel Rod 12mm",
    "quantity": 100,
    "packaging_size": 10,
    "unit_price": 50000,
    "vat": 5000,
    "total": 5005000,
    "created_by": "Budi Santoso",
    "created_at": "2026-07-05 09:00:00",
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
  "status_message": "Purchase receive item not found",
  "data": []
}
```

#### PUT `/api/v2/purchase-receive/{id}/items/{item_id}`

Update a purchase receive item — this is how `quantity` and `unit_price` get corrected on a rejected receive before resubmitting via `PATCH .../revise`. Only send the fields you want to change.

##### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| product_name | string | No | Cannot be empty if provided |
| quantity | number | No | — |
| packaging_size | number | No | — |
| unit_price | number | No | — |
| vat | number | No | — |
| total | number | No | — |

##### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase receive item updated successfully",
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
  "status_message": "Purchase receive item not found",
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

- Header + items creation is wrapped in a single database transaction — either the purchase receive and all its items are created together, or nothing is saved.
- Approve/reject/revise write an `audit_log` row with action `approved`/`rejected`/`revised`; create writes a `created` audit_log row (update/delete do not call `insertAuditLog` — pre-existing gap, unrelated to the approval stage added here). Query this history via `GET /api/v2/audit-log?module=purchase_receive&reference_id={id}` — see the `audit-log` module doc.
- **`status_id`, `approved_by`, and `approved_at` are new as of this addendum** — added specifically to bring purchase-receive's approval workflow in line with purchase-order/sales. `status_id` is resolved server-side by matching `purchase_status.status_name` (the same shared lookup table `purchase_order` uses): `POST` sets it to `"Draft"`, `PATCH .../approve` sets it to `"Approved"`, `PATCH .../reject` sets it to `"Rejected"`, `PATCH .../revise` sets it back to `"Draft"`. `PUT` cannot change `status_id` — status transitions only happen via `approve`/`reject`/`revise`. `approve` sets `approved_by`/`approved_at`; `reject` and `revise` do not.
- `revise` only succeeds when the purchase receive's current status is `Rejected`; any other status returns `400`.
- Purchase receives created before this addendum were backfilled to `Approved` with `approved_by`/`approved_at` left `NULL` (no real approver was ever recorded for them) — see `v2/docs/migrations/v22_purchase_invoice_receive_approval_schema.md`.
- The list endpoint (`GET /api/v2/purchase-receive`) does not include the nested `items` array. Only the detail endpoint (`GET /api/v2/purchase-receive/{id}`) returns `items`. The create response only returns `purchase_receive_id`.
- `search` is matched against the linked purchase order's `po_display_number` via a `LEFT JOIN`, not against any field on the purchase receive record itself — purchase receive rows have no display number of their own. Records whose `purchase_order_id` no longer resolves to a purchase order are excluded from `search` results (but still returned when `search` is omitted).
- **`created_by`, `updated_by`, and `approved_by` are resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a purchase receive (list, detail, and any nested items). There is no separate `*_id` field for them, the resolved name **is** the value. `updated_by`/`approved_by` are `null` until the record has actually been updated/approved; `created_by` can be `null` only if the creating user has since been deleted.
- **List and detail responses also resolve reference IDs to their display names**, alongside the existing `*_id` field (both are returned): `purchase_order_id` → `po_display_number`, `supplier_id` → `supplier_name`, `ship_via_id` → `ship_name`, `status_id` → `status_name`. All are `LEFT JOIN`ed, so the resolved field is `null` if the referenced record is missing or was deleted; the `*_id` field is unaffected either way.
- **`POST` (create) and `PATCH .../approve`/`.../reject` now trigger notifications** — in-app + WhatsApp to the users holding `notification.purchase_receive.approver` on create, and to the receive's creator on approve/reject. This is a side effect only; it does not change this endpoint's own request/response shape. See `notification.md`.
- **`GET .../export` has no v1 precedent** — v1 never had a purchase receive export. It is built fresh with `PhpOffice\PhpWord` (no Word template file), styled to match the purchase-order export's header/table/signature layout.
- **The items sub-resource (`/api/v2/purchase-receive/{id}/items`) is new** — added specifically so `quantity`/`unit_price`/`packaging_size` can be corrected on a rejected receive before resubmitting, which was previously impossible (`PUT /api/v2/purchase-receive/{id}` never touched items). Unlike the equivalent purchase-order sub-resource, there is no `POST`/`DELETE` here — receive items can only be updated in place, not added or removed, since they're always created together with the parent record.
