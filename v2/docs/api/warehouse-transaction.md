# Warehouse Transaction API

> **Last updated:** 2026-07-11 18:49:02 WIB
> **Base URL:** `/api/v2/warehouse-transaction`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/warehouse-transaction` | List all warehouse transactions (paginated) |
| POST   | `/api/v2/warehouse-transaction` | Create a new warehouse transaction (with items) |
| GET    | `/api/v2/warehouse-transaction/{id}` | Get warehouse transaction detail (with items) |
| PUT    | `/api/v2/warehouse-transaction/{id}` | Update a warehouse transaction |
| DELETE | `/api/v2/warehouse-transaction/{id}` | Delete a warehouse transaction |

---

### GET `/api/v2/warehouse-transaction`

List all warehouse transactions belonging to the authenticated company.

#### Query parameters

| Parameter        | Type   | Required | Default | Description |
|------------------|--------|----------|---------|-------------|
| page             | int    | No       | 1       | Page number |
| limit            | int    | No       | 10      | Items per page (max 100) |
| transaction_type | string | No       | —       | Filter by `transaction_type`. Must be one of: `stock_in`, `stock_out`, `adjustment`, `transfer` — invalid values are silently ignored (no filter applied) |
| customer_id      | string | No       | —       | Filter by `customer_id` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Warehouse transactions found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "transaction_date": "2026-07-01",
        "transaction_type": "stock_in",
        "customer_id": null,
        "notes": null,
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
  "status_message": "No warehouse transactions found",
  "data": []
}
```

---

### POST `/api/v2/warehouse-transaction`

Create a new warehouse transaction together with its items.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| transaction_date | string (date) | Yes | — |
| transaction_type | string | Yes | Must be one of: `stock_in`, `stock_out`, `adjustment`, `transfer` |
| items | array | Yes | Non-empty array of transaction items — see below |
| customer_id | string | No | Must reference an existing, non-deleted customer in the same company |
| notes | string | No | — |

**`items[]` object:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| warehouse_lot_id | string | Yes | — |
| product_id | string | Yes | — |
| quantity | number | Yes | — |
| uom_id | string | No | — |
| conversion_factor | number | No | — |
| expired_at | string (date) | No | — |
| notes | string | No | — |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Warehouse transaction created successfully",
  "data": {
    "warehouse_transaction_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "transaction_date is required",
  "data": []
}
```

```json
{
  "status_code": 400,
  "status_message": "transaction_type must be one of stock_in, stock_out, adjustment, transfer",
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
  "status_message": "items.warehouse_lot_id is required",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Customer not found",
  "data": []
}
```

#### Response `500 Internal Server Error`

```json
{
  "status_code": 500,
  "status_message": "Failed to create warehouse transaction",
  "data": { "error": "..." }
}
```

---

### GET `/api/v2/warehouse-transaction/{id}`

Get detail of a single warehouse transaction, including its nested `items` array.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The warehouse transaction ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Warehouse transaction found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "transaction_date": "2026-07-01",
    "transaction_type": "stock_in",
    "customer_id": null,
    "notes": null,
    "created_by": "Budi Santoso",
    "created_at": "2026-07-01 10:00:00",
    "updated_by": null,
    "updated_at": null,
    "deleted_at": null,
    "items": [
      {
        "id": "e1f2a3b4-c5d6-4e5f-8a9b-0c1d2e3f4a5b",
        "warehouse_transaction_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "warehouse_lot_id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
        "product_id": "c9d0e1f2-a3b4-4c5d-6e7f-8a9b0c1d2e3f",
        "quantity": 100,
        "uom_id": null,
        "conversion_factor": null,
        "expired_at": null,
        "notes": null,
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
  "status_message": "Warehouse transaction not found",
  "data": []
}
```

---

### PUT `/api/v2/warehouse-transaction/{id}`

Update a warehouse transaction. Only send the fields you want to change. Does not update `items`.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The warehouse transaction ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| transaction_date | string (date) | No | — |
| transaction_type | string | No | Must be one of: `stock_in`, `stock_out`, `adjustment`, `transfer` |
| customer_id | string | No | Sending an empty value clears it to `NULL` |
| notes | string | No | Sending an empty value clears it to `NULL` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Warehouse transaction updated successfully",
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
  "status_message": "transaction_type must be one of stock_in, stock_out, adjustment, transfer",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Warehouse transaction not found",
  "data": []
}
```

---

### DELETE `/api/v2/warehouse-transaction/{id}`

Soft-deletes the warehouse transaction (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The warehouse transaction ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Warehouse transaction deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Warehouse transaction not found",
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
| 500  | Internal server error |

---

## Notes

- `transaction_type` is an enum defined in code as `WAREHOUSE_TRANSACTION_TYPES` and accepts exactly 4 values: `stock_in`, `stock_out`, `adjustment`, `transfer`. This applies to both create and update.
- Header + items creation is wrapped in a single database transaction (`begin_transaction()` / `commit()` / `rollback()`) — either the warehouse transaction and all its items are created together, or nothing is saved.
- The list endpoint (`GET /api/v2/warehouse-transaction`) does not include the nested `items` array; only the detail endpoint (`GET /api/v2/warehouse-transaction/{id}`) does. The create response only returns `warehouse_transaction_id`.
- Unlike some other modules (e.g. `purchase-order`), this module does not currently write any `audit_log` entries — no calls to `insertAuditLog()` exist in its source. There is no corresponding `GET /api/v2/audit-log?module=warehouse_transaction...` history available at this time.
- There is no duplicate/uniqueness check on create, so no `409 Conflict` response exists for this module.
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a warehouse transaction (list, detail, and any nested items). Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
