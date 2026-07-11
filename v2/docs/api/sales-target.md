# Sales Target API

> **Last updated:** 2026-07-11 18:49:02 WIB
> **Base URL:** `/api/v2/sales-target`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/sales-target` | List all sales targets (paginated) |
| POST   | `/api/v2/sales-target` | Create a new sales target |
| GET    | `/api/v2/sales-target/{id}` | Get sales target detail |
| PUT    | `/api/v2/sales-target/{id}` | Update a sales target |
| DELETE | `/api/v2/sales-target/{id}` | Delete a sales target |

---

### GET `/api/v2/sales-target`

List all sales targets belonging to the authenticated company.

#### Query parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| page      | int  | No       | 1       | Page number |
| limit     | int  | No       | 10      | Items per page (max 100) |
| target_year | int | No      | —       | Filter by target year |

_(No `search` parameter exists for this module.)_

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales targets found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "target_year": 2026,
        "target_value": 5000000000,
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
  "status_message": "No sales targets found",
  "data": []
}
```

---

### POST `/api/v2/sales-target`

Create a new sales target.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| target_year | int | Yes | Must be a 4-digit year. Unique per company. |
| target_value | number | Yes | — |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Sales target created successfully",
  "data": {
    "sales_target_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "target_year is required",
  "data": []
}
```

`target_year must be a 4-digit year` is returned when `target_year` does not match a 4-digit numeric format.

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Sales target already exists for this year",
  "data": []
}
```

---

### GET `/api/v2/sales-target/{id}`

Get detail of a single sales target.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| sales_target_id | string | The sales target ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales target found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "target_year": 2026,
    "target_value": 5000000000,
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
  "status_message": "Sales target not found",
  "data": []
}
```

---

### PUT `/api/v2/sales-target/{id}`

Update a sales target. Only send the fields you want to change.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| sales_target_id | string | The sales target ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| target_year | int | No | Must be a 4-digit year. Checked for duplicates. |
| target_value | number | No | — |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales target updated successfully",
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

Also returned as `target_year must be a 4-digit year` when `target_year` is provided but not 4 digits.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales target not found",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Sales target already exists for this year",
  "data": []
}
```

---

### DELETE `/api/v2/sales-target/{id}`

Soft-deletes the sales target (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| sales_target_id | string | The sales target ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales target deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales target not found",
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
- Uniqueness is enforced per `(company_id, target_year)` — a company can have at most one sales target per year.
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a sales target (list, detail, and any nested items). Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
