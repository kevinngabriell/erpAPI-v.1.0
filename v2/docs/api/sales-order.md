# Sales Order API

> **Last updated:** 2026-07-11 15:00:00 WIB
> **Base URL:** `/api/v2/sales-order`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/sales-order` | List all sales orders (paginated) |
| POST   | `/api/v2/sales-order` | Create a new sales order (with items) |
| GET    | `/api/v2/sales-order/{id}` | Get sales order detail (with items) |
| PUT    | `/api/v2/sales-order/{id}` | Update a sales order |
| DELETE | `/api/v2/sales-order/{id}` | Delete a sales order |
| PATCH  | `/api/v2/sales-order/{id}/approve` | Approve a sales order |
| PATCH  | `/api/v2/sales-order/{id}/reject` | Reject a sales order |
| GET    | `/api/v2/sales-order/{id}/items` | List items of a sales order |
| POST   | `/api/v2/sales-order/{id}/items` | Add an item to a sales order |
| GET    | `/api/v2/sales-order/{id}/items/{item_id}` | Get a single sales order item |
| PUT    | `/api/v2/sales-order/{id}/items/{item_id}` | Update a sales order item |
| DELETE | `/api/v2/sales-order/{id}/items/{item_id}` | Delete a sales order item |

---

### GET `/api/v2/sales-order`

List all sales orders belonging to the authenticated company.

#### Query parameters

| Parameter   | Type   | Required | Default | Description |
|-------------|--------|----------|---------|-------------|
| page        | int    | No       | 1       | Page number |
| limit       | int    | No       | 10      | Items per page (max 100) |
| search      | string | No       | —       | Search on `so_display_number` |
| status_id   | string | No       | —       | Filter by `status_id` |
| customer_id | string | No       | —       | Filter by `customer_id` |
| date_from   | string (date) | No | —    | Filter `so_date >=` this date (`YYYY-MM-DD`) |
| date_to     | string (date) | No | —    | Filter `so_date <=` this date (`YYYY-MM-DD`) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales orders found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "so_display_number": "SO-2026-0001",
        "so_date": "2026-07-01",
        "ppn_type_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
        "customer_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
        "send_to_address": "Jl. Sudirman No. 1, Jakarta",
        "send_date": "2026-07-05",
        "status_id": "e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b",
        "approved_by": null,
        "approved_at": null,
        "created_by": "budi.santoso",
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
  "status_message": "No sales orders found",
  "data": []
}
```

---

### POST `/api/v2/sales-order`

Create a new sales order together with its items.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| so_display_number | string | Yes | Unique display number for the SO |
| so_date | string (date) | Yes | SO date |
| ppn_type_id | string | Yes | PPN type ID |
| customer_id | string | Yes | Customer ID |
| send_date | string (date) | Yes | — |
| status_id | string | Yes | Initial status ID |
| items | array | Yes | Non-empty array of order items — see below |
| send_to_address | string | No | — |

**`items[]` object:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| product_name | string | Yes | — |
| quantity | number | Yes | — |
| uom_id | string | Yes | — |
| currency_id | string | Yes | — |
| unit_price | number | Yes | — |
| kurs | number | Yes | Exchange rate |
| purchase_order_id | string | No | Links the item to a purchase order |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Sales order created successfully",
  "data": {
    "sales_order_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "so_display_number is required",
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

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Sales order already exists",
  "data": []
}
```

---

### GET `/api/v2/sales-order/{id}`

Get detail of a single sales order, including its nested `items` array.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales order ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales order found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "so_display_number": "SO-2026-0001",
    "so_date": "2026-07-01",
    "ppn_type_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
    "customer_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
    "send_to_address": "Jl. Sudirman No. 1, Jakarta",
    "send_date": "2026-07-05",
    "status_id": "e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b",
    "approved_by": null,
    "approved_at": null,
    "created_by": "budi.santoso",
    "created_at": "2026-07-01 10:00:00",
    "updated_by": null,
    "updated_at": null,
    "deleted_at": null,
    "items": [
      {
        "id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
        "sales_order_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "purchase_order_id": null,
        "product_name": "Steel Rod 12mm",
        "quantity": 100,
        "uom_id": "a7b8c9d0-e1f2-4a5b-4c5d-6e7f8a9b0c1d",
        "currency_id": "b8c9d0e1-f2a3-4b5c-5d6e-7f8a9b0c1d2e",
        "unit_price": 50000,
        "kurs": 1,
        "created_by": "budi.santoso",
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
  "status_message": "Sales order not found",
  "data": []
}
```

---

### PUT `/api/v2/sales-order/{id}`

Update a sales order. Only send the fields you want to change. Does not update items — use the items sub-resource for that.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales order ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| so_display_number | string | No | Cannot be empty if provided |
| ppn_type_id | string | No | Cannot be empty if provided |
| customer_id | string | No | Cannot be empty if provided |
| send_to_address | string | No | Cannot be empty if provided |
| status_id | string | No | Cannot be empty if provided |
| so_date | string (date) | No | — |
| send_date | string (date) | No | — |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales order updated successfully",
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
  "status_message": "Sales order not found",
  "data": []
}
```

---

### DELETE `/api/v2/sales-order/{id}`

Soft-deletes the sales order (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales order ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales order deleted successfully",
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

---

### PATCH `/api/v2/sales-order/{id}/approve`

Approve a sales order. Sets `status_id`, `approved_by`, `approved_at`.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales order ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| status_id | string | Yes | The status to set on approval |
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales order approved successfully",
  "data": []
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "status_id is required",
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

---

### PATCH `/api/v2/sales-order/{id}/reject`

Reject a sales order. Sets `status_id` only (does not set `approved_by`/`approved_at`).

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales order ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| status_id | string | Yes | The status to set on rejection |
| notes | string | No | Optional note recorded on the audit log entry |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales order rejected successfully",
  "data": []
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "status_id is required",
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

---

### Sales order items (`/api/v2/sales-order/{id}/items`)

Items are also a sub-resource in their own right, separate from the nested `items` array returned on the sales order itself. `{id}` below is the parent sales order ID; the item's own ID is `{item_id}`.

#### GET `/api/v2/sales-order/{id}/items`

List all items belonging to the sales order.

##### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales order items found",
  "data": {
    "data": [
      {
        "id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
        "sales_order_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "purchase_order_id": null,
        "product_name": "Steel Rod 12mm",
        "quantity": 100,
        "uom_id": "a7b8c9d0-e1f2-4a5b-4c5d-6e7f8a9b0c1d",
        "currency_id": "b8c9d0e1-f2a3-4b5c-5d6e-7f8a9b0c1d2e",
        "unit_price": 50000,
        "kurs": 1,
        "created_by": "budi.santoso",
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
  "status_message": "No sales order items found",
  "data": []
}
```

If the parent sales order does not belong to the company or does not exist, all item endpoints respond:

```json
{
  "status_code": 404,
  "status_message": "Sales order not found",
  "data": []
}
```

#### POST `/api/v2/sales-order/{id}/items`

Add a new item to an existing sales order.

##### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| product_name | string | Yes | — |
| quantity | number | Yes | — |
| uom_id | string | Yes | — |
| currency_id | string | Yes | — |
| unit_price | number | Yes | — |
| kurs | number | Yes | Exchange rate |
| purchase_order_id | string | No | Links the item to a purchase order |

##### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Sales order item created successfully",
  "data": {
    "sales_order_item_id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c"
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

#### GET `/api/v2/sales-order/{id}/items/{item_id}`

Get a single sales order item.

##### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales order item found",
  "data": {
    "id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
    "sales_order_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "purchase_order_id": null,
    "product_name": "Steel Rod 12mm",
    "quantity": 100,
    "uom_id": "a7b8c9d0-e1f2-4a5b-4c5d-6e7f8a9b0c1d",
    "currency_id": "b8c9d0e1-f2a3-4b5c-5d6e-7f8a9b0c1d2e",
    "unit_price": 50000,
    "kurs": 1,
    "created_by": "budi.santoso",
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
  "status_message": "Sales order item not found",
  "data": []
}
```

#### PUT `/api/v2/sales-order/{id}/items/{item_id}`

Update a sales order item. Only send the fields you want to change.

##### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| product_name | string | No | Cannot be empty if provided |
| uom_id | string | No | Cannot be empty if provided |
| currency_id | string | No | Cannot be empty if provided |
| purchase_order_id | string | No | Pass `null` or an empty string to clear it |
| quantity | number | No | — |
| unit_price | number | No | — |
| kurs | number | No | — |

##### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales order item updated successfully",
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
  "status_message": "Sales order item not found",
  "data": []
}
```

#### DELETE `/api/v2/sales-order/{id}/items/{item_id}`

Soft-deletes the sales order item (sets `deleted_at`) — it will no longer appear in list/detail responses.

##### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales order item deleted successfully",
  "data": []
}
```

##### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales order item not found",
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

- Header + items creation is wrapped in a single database transaction — either the sales order and all its items are created together, or nothing is saved.
- The list endpoint (`GET /api/v2/sales-order`) does not include the nested `items` array; only the detail endpoint (`GET /api/v2/sales-order/{id}`) does. The create response only returns `sales_order_id`.
- Approve/reject write an `audit_log` row with action `approved`/`rejected`; create/update/delete write `created`/`updated`/`deleted` audit_log rows. Query this history via `GET /api/v2/audit-log?module=sales_order&reference_id={id}` — see the `audit-log` module doc.
- `approve` sets `approved_by` and `approved_at`; `reject` does not.
- No enum-constrained fields were found in the sales-order source (unlike purchase-order's `shipment_method`).
