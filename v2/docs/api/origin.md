# Origin API

> **Last updated:** 2026-07-11 18:49:02 WIB
> **Base URL:** `/api/v2/origin`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/origin` | List all origins (paginated) |
| POST   | `/api/v2/origin` | Create a new origin |
| GET    | `/api/v2/origin/{id}` | Get origin detail |
| PUT    | `/api/v2/origin/{id}` | Update an origin |
| DELETE | `/api/v2/origin/{id}` | Delete an origin |

---

### GET `/api/v2/origin`

List all origins.

#### Query parameters

| Parameter | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| page      | int    | No       | 1       | Page number |
| limit     | int    | No       | 10      | Items per page (max 100) |
| search    | string | No       | —       | Search on `origin_name` (partial match) |
| region_id | string | No       | —       | Filter by region ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Origins found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "origin_name": "Pelabuhan Tanjung Priok",
        "region_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "is_free_trade": false,
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

`is_free_trade` is returned as a boolean (`true`/`false`).

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "No origins found",
  "data": []
}
```

---

### POST `/api/v2/origin`

Create a new origin.

#### Request body (`application/json`)

| Field         | Type    | Required | Description |
|---------------|---------|----------|-------------|
| origin_name   | string  | Yes      | Origin name. Must be unique among non-deleted origins. |
| region_id     | string  | Yes      | Must reference an existing, non-deleted region. |
| is_free_trade | boolean | No       | Defaults to `false` if omitted. |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Origin created successfully",
  "data": {
    "origin_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "origin_name is required",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Region not found",
  "data": []
}
```

Returned when `region_id` does not reference an existing, non-deleted region.

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Origin already exists",
  "data": []
}
```

---

### GET `/api/v2/origin/{id}`

Get detail of a single origin.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id        | string | The origin ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Origin found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "origin_name": "Pelabuhan Tanjung Priok",
    "region_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "is_free_trade": false,
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
  "status_message": "Origin not found",
  "data": []
}
```

---

### PUT `/api/v2/origin/{id}`

Update an origin. Only send the fields you want to change.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id        | string | The origin ID |

#### Request body (`application/json`)

| Field         | Type    | Required | Description |
|---------------|---------|----------|-------------|
| origin_name   | string  | No       | New origin name. Cannot be an empty string if provided. |
| region_id     | string  | No       | Must reference an existing, non-deleted region. |
| is_free_trade | boolean | No       | — |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Origin updated successfully",
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
  "status_message": "Origin not found",
  "data": []
}
```

Also returned as `"Region not found"` when `region_id` is provided but does not reference an existing, non-deleted region.

---

### DELETE `/api/v2/origin/{id}`

Soft-deletes the origin (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id        | string | The origin ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Origin deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Origin not found",
  "data": []
}
```

---

## Error responses (all endpoints)

| Code | When |
|------|------|
| 400  | Validation failed — missing or invalid field |
| 401  | Missing or expired Bearer token |
| 404  | Resource not found (origin, or referenced region) |
| 405  | HTTP method not allowed on this path |
| 409  | Duplicate — resource already exists |
| 500  | Internal server error |

---

## Notes

- **Global lookup table.** `origin` has no `company_id` column and the queries in this module do not filter by company — every authenticated user across every company sees and shares the same set of origins. `Authorization` is still required, but there is no tenant scoping on this data.
- IDs are UUIDs generated with `generateUUID()`, not prefixed strings (e.g. `a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d`).
- `region_id` must reference an existing, non-deleted row in the `region` table on both create and update; otherwise the request fails with `404 Region not found`.
- Delete is a soft delete (`deleted_at` timestamp) and is reversible at the database layer, even though there is currently no undelete endpoint.
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns an origin (list, detail, and any nested items). Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
