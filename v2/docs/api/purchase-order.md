# Purchase Order API

> **Last updated:** 2026-07-20 00:00:00 WIB
> **Base URL:** `/api/v2/purchase-order`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/purchase-order` | List all purchase orders (paginated) |
| POST   | `/api/v2/purchase-order` | Create a new purchase order (with items) |
| GET    | `/api/v2/purchase-order/{id}` | Get purchase order detail (with items) |
| PUT    | `/api/v2/purchase-order/{id}` | Update a purchase order |
| DELETE | `/api/v2/purchase-order/{id}` | Delete a purchase order |
| PATCH  | `/api/v2/purchase-order/{id}/approve` | Approve a purchase order |
| PATCH  | `/api/v2/purchase-order/{id}/reject` | Reject a purchase order |
| PATCH  | `/api/v2/purchase-order/{id}/revise` | Revise a rejected purchase order back to Draft |
| GET    | `/api/v2/purchase-order/{id}/items` | List items of a purchase order |
| POST   | `/api/v2/purchase-order/{id}/items` | Add an item to a purchase order |
| GET    | `/api/v2/purchase-order/{id}/items/{item_id}` | Get a single purchase order item |
| PUT    | `/api/v2/purchase-order/{id}/items/{item_id}` | Update a purchase order item |
| DELETE | `/api/v2/purchase-order/{id}/items/{item_id}` | Delete a purchase order item |

---

### GET `/api/v2/purchase-order`

List all purchase orders belonging to the authenticated company.

#### Query parameters

| Parameter   | Type   | Required | Default | Description |
|-------------|--------|----------|---------|-------------|
| page        | int    | No       | 1       | Page number |
| limit       | int    | No       | 10      | Items per page (max 100) |
| search      | string | No       | —       | Search on `po_display_number` |
| status_id   | string | No       | —       | Filter by `status_id` |
| supplier_id | string | No       | —       | Filter by `supplier_id` |
| date_from   | string (date) | No | —    | Filter `po_date >=` this date (`YYYY-MM-DD`) |
| date_to     | string (date) | No | —    | Filter `po_date <=` this date (`YYYY-MM-DD`) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase orders found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "po_display_number": "PO-2026-0001",
        "po_date": "2026-07-01",
        "supplier_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
        "supplier_name": "PT Sumber Baja",
        "shipment_method": "FOB",
        "shipment_date": "2026-07-05",
        "term_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
        "term_name": "Net 30",
        "payment_method_id": "e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b",
        "method_name": "Bank Transfer",
        "origin_id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
        "origin_name": "China",
        "shipping_marks": "N/A",
        "remarks": "Urgent order",
        "status_id": "a7b8c9d0-e1f2-4a5b-4c5d-6e7f8a9b0c1d",
        "status_name": "Approved",
        "type_id": "b8c9d0e1-f2a3-4b5c-5d6e-7f8a9b0c1d2e",
        "type_name": "Import",
        "currency_id": "c9d0e1f2-a3b4-4c5d-6e7f-8a9b0c1d2e3f",
        "currency_code": "USD",
        "currency_name": "US Dollar",
        "ppn_type_id": "d0e1f2a3-b4c5-4d5e-7f8a-9b0c1d2e3f4a",
        "ppn_name": "PPN 11%",
        "container_number": "CONT1234567",
        "bl_number": "BL-998877",
        "vessel_name": "MV Nusantara",
        "etd_date": "2026-07-10",
        "eta_date": "2026-07-20",
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
  "status_message": "No purchase orders found",
  "data": []
}
```

---

### POST `/api/v2/purchase-order`

Create a new purchase order together with its items. Server-side sets `status_id` to the `Draft` purchase status — the client does not send `status_id`.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| po_display_number | string | Yes | Unique display number for the PO |
| po_date | string (date) | Yes | PO date |
| supplier_id | string | Yes | Supplier ID |
| items | array | Yes | Non-empty array of order items — see below |
| shipment_method | string | No | One of `FOB`, `CIF`, `EXW`, `CFR`, `CIP`, `DAP`, `DDP`, `FCA` |
| shipment_date | string (date) | No | — |
| term_id | string | No | — |
| payment_method_id | string | No | — |
| origin_id | string | No | — |
| shipping_marks | string | No | — |
| remarks | string | No | — |
| type_id | string | No | — |
| currency_id | string | No | — |
| ppn_type_id | string | No | — |
| container_number | string | No | — |
| bl_number | string | No | — |
| vessel_name | string | No | — |
| etd_date | string (date) | No | Estimated time of departure |
| eta_date | string (date) | No | Estimated time of arrival |

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
  "status_message": "Purchase order created successfully",
  "data": {
    "purchase_order_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "po_display_number is required",
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

```json
{
  "status_code": 400,
  "status_message": "shipment_method must be one of FOB, CIF, EXW, CFR, CIP, DAP, DDP, FCA",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Purchase order already exists",
  "data": []
}
```

---

### GET `/api/v2/purchase-order/{id}`

Get detail of a single purchase order, including its nested `items` array.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase order ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase order found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "po_display_number": "PO-2026-0001",
    "po_date": "2026-07-01",
    "supplier_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
    "supplier_name": "PT Sumber Baja",
    "shipment_method": "FOB",
    "shipment_date": "2026-07-05",
    "term_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
    "term_name": "Net 30",
    "payment_method_id": "e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b",
    "method_name": "Bank Transfer",
    "origin_id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
    "origin_name": "China",
    "shipping_marks": "N/A",
    "remarks": "Urgent order",
    "status_id": "a7b8c9d0-e1f2-4a5b-4c5d-6e7f8a9b0c1d",
    "status_name": "Approved",
    "type_id": "b8c9d0e1-f2a3-4b5c-5d6e-7f8a9b0c1d2e",
    "type_name": "Import",
    "currency_id": "c9d0e1f2-a3b4-4c5d-6e7f-8a9b0c1d2e3f",
    "currency_code": "USD",
    "currency_name": "US Dollar",
    "ppn_type_id": "d0e1f2a3-b4c5-4d5e-7f8a-9b0c1d2e3f4a",
    "ppn_name": "PPN 11%",
    "container_number": "CONT1234567",
    "bl_number": "BL-998877",
    "vessel_name": "MV Nusantara",
    "etd_date": "2026-07-10",
    "eta_date": "2026-07-20",
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
        "purchase_order_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "product_name": "Steel Rod 12mm",
        "quantity": 100,
        "packaging_size": 10,
        "unit_price": 50000,
        "vat": 5000,
        "total": 5005000,
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
  "status_message": "Purchase order not found",
  "data": []
}
```

---

### PUT `/api/v2/purchase-order/{id}`

Update a purchase order. Only send the fields you want to change. Does not update items — use the items sub-resource for that. `status_id` cannot be changed here — status transitions only happen via `approve`/`reject`/`revise`.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase order ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| po_display_number | string | No | Cannot be empty if provided |
| supplier_id | string | No | Cannot be empty if provided |
| term_id | string | No | Cannot be empty if provided |
| payment_method_id | string | No | Cannot be empty if provided |
| origin_id | string | No | Cannot be empty if provided |
| shipping_marks | string | No | Cannot be empty if provided |
| remarks | string | No | Cannot be empty if provided |
| type_id | string | No | Cannot be empty if provided |
| currency_id | string | No | Cannot be empty if provided |
| ppn_type_id | string | No | Cannot be empty if provided |
| container_number | string | No | Cannot be empty if provided |
| bl_number | string | No | Cannot be empty if provided |
| vessel_name | string | No | Cannot be empty if provided |
| po_date | string (date) | No | — |
| shipment_date | string (date) | No | — |
| etd_date | string (date) | No | — |
| eta_date | string (date) | No | — |
| shipment_method | string | No | One of `FOB`, `CIF`, `EXW`, `CFR`, `CIP`, `DAP`, `DDP`, `FCA` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase order updated successfully",
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
  "status_message": "Purchase order not found",
  "data": []
}
```

---

### DELETE `/api/v2/purchase-order/{id}`

Soft-deletes the purchase order (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase order ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase order deleted successfully",
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

### PATCH `/api/v2/purchase-order/{id}/approve`

Approve a purchase order. Server-side sets `status_id` to the `Approved` purchase status, plus `approved_by`, `approved_at`. The client does not send `status_id`.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase order ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase order approved successfully",
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

### PATCH `/api/v2/purchase-order/{id}/reject`

Reject a purchase order. Server-side sets `status_id` to the `Rejected` purchase status (does not set `approved_by`/`approved_at`). The client does not send `status_id`.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase order ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase order rejected successfully",
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

### PATCH `/api/v2/purchase-order/{id}/revise`

Move a rejected purchase order back to `Draft` so it can be edited and resubmitted. Only allowed when the current status is `Rejected`.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase order ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase order revised successfully",
  "data": []
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "Only rejected purchase orders can be revised",
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

### Purchase order items (`/api/v2/purchase-order/{id}/items`)

Items are also a sub-resource in their own right, separate from the nested `items` array returned on the purchase order itself. `{id}` below is the parent purchase order ID; the item's own ID is `{item_id}`.

#### GET `/api/v2/purchase-order/{id}/items`

List all items belonging to the purchase order.

##### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase order items found",
  "data": {
    "data": [
      {
        "id": "e1f2a3b4-c5d6-4e5f-8a9b-0c1d2e3f4a5b",
        "purchase_order_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "product_name": "Steel Rod 12mm",
        "quantity": 100,
        "packaging_size": 10,
        "unit_price": 50000,
        "vat": 5000,
        "total": 5005000,
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
  "status_message": "No purchase order items found",
  "data": []
}
```

If the parent purchase order does not belong to the company or does not exist, all item endpoints respond:

```json
{
  "status_code": 404,
  "status_message": "Purchase order not found",
  "data": []
}
```

#### POST `/api/v2/purchase-order/{id}/items`

Add a new item to an existing purchase order.

##### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| product_name | string | Yes | — |
| quantity | number | Yes | — |
| packaging_size | number | Yes | — |
| unit_price | number | Yes | — |
| vat | number | No | Defaults to `0` |
| total | number | No | Defaults to `(quantity * unit_price) + vat` if not provided |

##### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Purchase order item created successfully",
  "data": {
    "purchase_order_item_id": "e1f2a3b4-c5d6-4e5f-8a9b-0c1d2e3f4a5b"
  }
}
```

##### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "product_name is required",
  "data": []
}
```

#### GET `/api/v2/purchase-order/{id}/items/{item_id}`

Get a single purchase order item.

##### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase order item found",
  "data": {
    "id": "e1f2a3b4-c5d6-4e5f-8a9b-0c1d2e3f4a5b",
    "purchase_order_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "product_name": "Steel Rod 12mm",
    "quantity": 100,
    "packaging_size": 10,
    "unit_price": 50000,
    "vat": 5000,
    "total": 5005000,
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
  "status_message": "Purchase order item not found",
  "data": []
}
```

#### PUT `/api/v2/purchase-order/{id}/items/{item_id}`

Update a purchase order item. Only send the fields you want to change.

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
  "status_message": "Purchase order item updated successfully",
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
  "status_message": "Purchase order item not found",
  "data": []
}
```

#### DELETE `/api/v2/purchase-order/{id}/items/{item_id}`

Soft-deletes the purchase order item (sets `deleted_at`) — it will no longer appear in list/detail responses.

##### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase order item deleted successfully",
  "data": []
}
```

##### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Purchase order item not found",
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

- `shipment_method`, when provided, must be one of: `FOB`, `CIF`, `EXW`, `CFR`, `CIP`, `DAP`, `DDP`, `FCA`.
- Header + items creation is wrapped in a single database transaction — either the purchase order and all its items are created together, or nothing is saved.
- The list endpoint (`GET /api/v2/purchase-order`) does not include the nested `items` array; only the detail endpoint (`GET /api/v2/purchase-order/{id}`) does. The create response only returns `purchase_order_id`.
- Approve/reject/revise write an `audit_log` row with action `approved`/`rejected`/`revised`; create/update/delete write `created`/`updated`/`deleted` audit_log rows. Query this history via `GET /api/v2/audit-log?module=purchase_order&reference_id={id}` — see the `audit-log` module doc.
- `approve` sets `approved_by` and `approved_at`; `reject` and `revise` do not.
- **`status_id` is no longer a client-supplied field on any endpoint.** It is resolved server-side by matching `purchase_status.status_name`: `POST` sets it to `"Draft"`, `PATCH .../approve` sets it to `"Approved"`, `PATCH .../reject` sets it to `"Rejected"`, `PATCH .../revise` sets it back to `"Draft"`. `PUT` (general update) cannot change `status_id` at all — status transitions only happen via `approve`/`reject`/`revise`. This removes the previous requirement for the frontend to know/send a `status_id` UUID, and the correctness risk that came with it (`purchase_status.id` is a UUID generated independently per environment — the same status name has a different `id` in dev vs. production).
- `revise` only succeeds when the purchase order's current status is `Rejected`; any other status returns `400`.
- **`created_by`, `updated_by`, and `approved_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a purchase order or a purchase order item — list, detail, and items. Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by`/`approved_by` are `null` until the record has actually been updated/approved; `created_by` can be `null` only if the creating user has since been deleted.
- **List and detail responses now also resolve reference IDs to their display names**, alongside the existing `*_id` field (both are returned): `supplier_id` → `supplier_name`, `status_id` → `status_name`, `term_id` → `term_name`, `payment_method_id` → `method_name`, `origin_id` → `origin_name`, `type_id` → `type_name`, `currency_id` → `currency_code` + `currency_name`, `ppn_type_id` → `ppn_name`. All are `LEFT JOIN`ed, so the resolved field is `null` if the referenced master record is missing or was deleted; the `*_id` field is unaffected either way.
