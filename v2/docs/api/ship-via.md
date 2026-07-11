# Ship Via API

> **Last updated:** 2026-07-11 18:49:02 WIB
> **Base URL:** `/api/v2/ship-via`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/ship-via` | List all ship-via methods (paginated) |
| POST   | `/api/v2/ship-via` | Create a new ship-via method |
| GET    | `/api/v2/ship-via/{id}` | Get ship-via detail |
| PUT    | `/api/v2/ship-via/{id}` | Update a ship-via method |
| DELETE | `/api/v2/ship-via/{id}` | Delete a ship-via method |

---

### GET `/api/v2/ship-via`

List all ship-via methods.

#### Query parameters

| Parameter | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| page      | int    | No       | 1       | Page number |
| limit     | int    | No       | 10      | Items per page (max 100) |
| search    | string | No       | —       | Search on `ship_name` (partial match) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Ship vias found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "ship_name": "JNE Regular",
        "created_by": "Budi Santoso",
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
  "status_message": "No ship vias found",
  "data": []
}
```

---

### POST `/api/v2/ship-via`

Create a new ship-via method.

#### Request body (`application/json`)

| Field      | Type   | Required | Description |
|------------|--------|----------|-------------|
| ship_name  | string | Yes      | Ship-via name. Must be unique among non-deleted ship-via methods. |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Ship via created successfully",
  "data": {
    "ship_via_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "ship_name is required",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Ship via already exists",
  "data": []
}
```

---

### GET `/api/v2/ship-via/{id}`

Get detail of a single ship-via method.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id        | string | The ship-via ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Ship via found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "ship_name": "JNE Regular",
    "created_by": "Budi Santoso",
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
  "status_message": "Ship via not found",
  "data": []
}
```

---

### PUT `/api/v2/ship-via/{id}`

Update a ship-via method. Only send the fields you want to change.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id        | string | The ship-via ID |

#### Request body (`application/json`)

| Field      | Type   | Required | Description |
|------------|--------|----------|-------------|
| ship_name  | string | No       | Cannot be an empty string if provided. |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Ship via updated successfully",
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
  "status_message": "Ship via not found",
  "data": []
}
```

---

### DELETE `/api/v2/ship-via/{id}`

Soft-deletes the ship-via method (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id        | string | The ship-via ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Ship via deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Ship via not found",
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

- **Global lookup table.** `ship_via` has no `company_id` column and the queries in this module do not filter by company — every authenticated user across every company sees and shares the same set of ship-via methods. `Authorization` is still required, but there is no tenant scoping on this data.
- IDs are UUIDs generated with `generateUUID()`, not prefixed strings (e.g. `a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d`).
- Delete is a soft delete (`deleted_at` timestamp) and is reversible at the database layer, even though there is currently no undelete endpoint.
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a ship-via method (list, detail, and any nested items). Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
