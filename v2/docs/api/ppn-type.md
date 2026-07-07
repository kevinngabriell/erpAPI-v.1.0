# PPN Type API

> **Last updated:** 2026-07-06 14:00:00 WIB
> **Base URL:** `/api/v2/ppn-type`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/ppn-type` | List all PPN types (paginated) |
| POST   | `/api/v2/ppn-type` | Create a new PPN type |
| GET    | `/api/v2/ppn-type/{id}` | Get PPN type detail |
| PUT    | `/api/v2/ppn-type/{id}` | Update a PPN type |
| DELETE | `/api/v2/ppn-type/{id}` | Delete a PPN type |

---

### GET `/api/v2/ppn-type`

List all PPN types.

#### Query parameters

| Parameter | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| page      | int    | No       | 1       | Page number |
| limit     | int    | No       | 10      | Items per page (max 100) |
| search    | string | No       | —       | Search on `ppn_name` (partial match) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Ppn types found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "ppn_name": "PPN 11%",
        "ppn_percentage": 11.0,
        "created_by": "usr_64a1b2c3d4e5f",
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
  "status_message": "No ppn types found",
  "data": []
}
```

---

### POST `/api/v2/ppn-type`

Create a new PPN type.

#### Request body (`application/json`)

| Field           | Type        | Required | Description |
|-----------------|-------------|----------|-------------|
| ppn_name        | string      | Yes      | PPN type name. Must be unique among non-deleted PPN types. |
| ppn_percentage  | null\|float | No       | `null` if omitted or sent as an empty string. |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Ppn type created successfully",
  "data": {
    "ppn_type_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "ppn_name is required",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Ppn type already exists",
  "data": []
}
```

---

### GET `/api/v2/ppn-type/{id}`

Get detail of a single PPN type.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id        | string | The PPN type ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Ppn type found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "ppn_name": "PPN 11%",
    "ppn_percentage": 11.0,
    "created_by": "usr_64a1b2c3d4e5f",
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
  "status_message": "Ppn type not found",
  "data": []
}
```

---

### PUT `/api/v2/ppn-type/{id}`

Update a PPN type. Only send the fields you want to change.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id        | string | The PPN type ID |

#### Request body (`application/json`)

| Field           | Type        | Required | Description |
|-----------------|-------------|----------|-------------|
| ppn_name        | string      | No       | Cannot be an empty string if provided. |
| ppn_percentage  | null\|float | No       | Sending an empty string clears the value to `NULL`. |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Ppn type updated successfully",
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
  "status_message": "Ppn type not found",
  "data": []
}
```

---

### DELETE `/api/v2/ppn-type/{id}`

Soft-deletes the PPN type (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id        | string | The PPN type ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Ppn type deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Ppn type not found",
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

- **Global lookup table.** `ppn_type` has no `company_id` column and the queries in this module do not filter by company — every authenticated user across every company sees and shares the same set of PPN types. `Authorization` is still required, but there is no tenant scoping on this data.
- IDs are UUIDs generated with `generateUUID()`, not prefixed strings (e.g. `a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d`).
- Delete is a soft delete (`deleted_at` timestamp) and is reversible at the database layer, even though there is currently no undelete endpoint.
