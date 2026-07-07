# Warehouse Location API

> **Last updated:** 2026-07-06 14:45:00 WIB
> **Base URL:** `/api/v2/warehouse-location`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/warehouse-location` | List all warehouse locations (paginated) |
| POST   | `/api/v2/warehouse-location` | Create a new warehouse location |
| GET    | `/api/v2/warehouse-location/{id}` | Get warehouse location detail |
| PUT    | `/api/v2/warehouse-location/{id}` | Update a warehouse location |
| DELETE | `/api/v2/warehouse-location/{id}` | Delete a warehouse location |

---

### GET `/api/v2/warehouse-location`

List all warehouse locations belonging to the authenticated company.

#### Query parameters

| Parameter | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| page      | int    | No       | 1       | Page number |
| limit     | int    | No       | 10      | Items per page (max 100) |
| search    | string | No       | —       | Full-text search on `location_name` and `address` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Warehouse locations found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "location_name": "Gudang Cikarang",
        "address": "Jl. Industri Raya No. 10, Cikarang",
        "created_by": "usr_64a1b2c3",
        "created_at": "2026-06-27 10:00:00",
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
  "status_message": "No warehouse locations found",
  "data": []
}
```

---

### POST `/api/v2/warehouse-location`

Create a new warehouse location.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| location_name | string | Yes | Unique within the company |
| address | null \| string | No | — |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Warehouse location created successfully",
  "data": {
    "warehouse_location_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "location_name is required",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Warehouse location already exists",
  "data": []
}
```

---

### GET `/api/v2/warehouse-location/{id}`

Get detail of a single warehouse location.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| warehouse_location_id | string | The warehouse location ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Warehouse location found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "location_name": "Gudang Cikarang",
    "address": "Jl. Industri Raya No. 10, Cikarang",
    "created_by": "usr_64a1b2c3",
    "created_at": "2026-06-27 10:00:00",
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
  "status_message": "Warehouse location not found",
  "data": []
}
```

---

### PUT `/api/v2/warehouse-location/{id}`

Update a warehouse location. Only send the fields you want to change.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| warehouse_location_id | string | The warehouse location ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| location_name | string | No | Cannot be empty if provided. Checked for duplicates. |
| address | null \| string | No | Send `null` to clear |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Warehouse location updated successfully",
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

Also returned as `location_name cannot be empty` when `location_name` is provided but blank.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Warehouse location not found",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Warehouse location already exists",
  "data": []
}
```

---

### DELETE `/api/v2/warehouse-location/{id}`

Soft-deletes the warehouse location (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| warehouse_location_id | string | The warehouse location ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Warehouse location deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Warehouse location not found",
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

- Resource is scoped to the authenticated company (`company_id` from the JWT) — records from other companies are never returned or modifiable.
- No fields other than `location_name` reference other tables.
