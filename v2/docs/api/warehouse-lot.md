# Warehouse Lot API

> **Last updated:** 2026-07-11 18:49:02 WIB
> **Base URL:** `/api/v2/warehouse-lot`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/warehouse-lot` | List all warehouse lots (paginated) |
| POST   | `/api/v2/warehouse-lot` | Create a new warehouse lot |
| GET    | `/api/v2/warehouse-lot/{id}` | Get warehouse lot detail |
| PUT    | `/api/v2/warehouse-lot/{id}` | Update a warehouse lot |
| DELETE | `/api/v2/warehouse-lot/{id}` | Delete a warehouse lot |

---

### GET `/api/v2/warehouse-lot`

List all warehouse lots belonging to the authenticated company.

#### Query parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| page | int | No | 1 | Page number |
| limit | int | No | 10 | Items per page (max 100) |
| product_id | string | No | — | Filter by `product_id` |
| location_id | string | No | — | Filter by `location_id` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Warehouse lots found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "location_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
        "product_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
        "lot_date": "2026-07-01",
        "beginning_balance": 100,
        "end_balance": 80,
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
  "status_message": "No warehouse lots found",
  "data": []
}
```

---

### POST `/api/v2/warehouse-lot`

Create a new warehouse lot.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| product_id | string | Yes | Must reference an existing, non-deleted product in the company |
| lot_date | string (date) | Yes | — |
| beginning_balance | number | Yes | — |
| end_balance | number | Yes | — |
| location_id | string | No | If provided, must reference an existing, non-deleted warehouse location in the company |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Warehouse lot created successfully",
  "data": {
    "warehouse_lot_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "product_id is required",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Product not found",
  "data": []
}
```

```json
{
  "status_code": 404,
  "status_message": "Warehouse location not found",
  "data": []
}
```

---

### GET `/api/v2/warehouse-lot/{id}`

Get detail of a single warehouse lot.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The warehouse lot ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Warehouse lot found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "location_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
    "product_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
    "lot_date": "2026-07-01",
    "beginning_balance": 100,
    "end_balance": 80,
    "created_by": "Budi Santoso",
    "created_at": "2026-07-01 10:00:00",
    "updated_by": null,
    "updated_at": null,
    "deleted_at": null
  }
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Warehouse lot not found",
  "data": []
}
```

---

### PUT `/api/v2/warehouse-lot/{id}`

Update a warehouse lot. Only send the fields you want to change.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The warehouse lot ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| location_id | string | No | Set to `null` if provided empty |
| lot_date | string (date) | No | — |
| beginning_balance | number | No | — |
| end_balance | number | No | — |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Warehouse lot updated successfully",
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
  "status_message": "Warehouse lot not found",
  "data": []
}
```

---

### DELETE `/api/v2/warehouse-lot/{id}`

Soft-deletes the warehouse lot (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The warehouse lot ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Warehouse lot deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Warehouse lot not found",
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

- No enum-constrained fields exist on this module. `product_id` is required and validated for existence; `location_id` is optional and, only when provided, validated for existence against non-deleted records in the company.
- On update, `location_id` is not re-validated for existence — it is only re-checked for emptiness (cleared to `null` if sent empty).
- Unlike `finance-transaction` and `finance-payment`, this module does **not** write `audit_log` rows — the source file does not include the audit-log helper or call `insertAuditLog()` on create, update, or delete. There is no audit history to query for this module.
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a warehouse lot (list, detail, and any nested items). Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
