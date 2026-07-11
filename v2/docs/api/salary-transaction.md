# Salary Transaction API

> **Last updated:** 2026-07-11 18:49:02 WIB
> **Base URL:** `/api/v2/salary-transaction`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/salary-transaction` | List all salary transactions (paginated) |
| POST   | `/api/v2/salary-transaction` | Create a new salary transaction |
| GET    | `/api/v2/salary-transaction/{id}` | Get salary transaction detail |
| PUT    | `/api/v2/salary-transaction/{id}` | Update a salary transaction |
| DELETE | `/api/v2/salary-transaction/{id}` | Delete a salary transaction |

---

### GET `/api/v2/salary-transaction`

List all salary transactions belonging to the authenticated company.

#### Query parameters

| Parameter           | Type   | Required | Default | Description |
|---------------------|--------|----------|---------|-------------|
| page                | int    | No       | 1       | Page number |
| limit               | int    | No       | 10      | Items per page (max 100) |
| app_user_id         | string | No       | —       | Filter by `app_user_id` |
| salary_category_id  | string | No       | —       | Filter by `salary_category_id` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Salary transactions found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "app_user_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
        "salary_category_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
        "salary_amount": 5000000,
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
  "status_message": "No salary transactions found",
  "data": []
}
```

---

### POST `/api/v2/salary-transaction`

Create a new salary transaction.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| app_user_id | string | Yes | — |
| salary_category_id | string | Yes | Must reference an existing, non-deleted salary category |
| salary_amount | number | Yes | — |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Salary transaction created successfully",
  "data": {
    "salary_transaction_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "app_user_id is required",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Salary category not found",
  "data": []
}
```

#### Response `500 Internal Server Error`

```json
{
  "status_code": 500,
  "status_message": "Failed to create salary transaction",
  "data": { "error": "..." }
}
```

---

### GET `/api/v2/salary-transaction/{id}`

Get detail of a single salary transaction.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The salary transaction ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Salary transaction found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "app_user_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
    "salary_category_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
    "salary_amount": 5000000,
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
  "status_message": "Salary transaction not found",
  "data": []
}
```

---

### PUT `/api/v2/salary-transaction/{id}`

Update a salary transaction. Only send the fields you want to change.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The salary transaction ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| salary_category_id | string | No | Cannot be empty if provided; must reference an existing, non-deleted salary category |
| salary_amount | number | No | Ignored if sent as an empty string |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Salary transaction updated successfully",
  "data": []
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "salary_category_id cannot be empty",
  "data": []
}
```

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
  "status_message": "Salary transaction not found",
  "data": []
}
```

```json
{
  "status_code": 404,
  "status_message": "Salary category not found",
  "data": []
}
```

---

### DELETE `/api/v2/salary-transaction/{id}`

Soft-deletes the salary transaction (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The salary transaction ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Salary transaction deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Salary transaction not found",
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

- This module has no nested items and no items sub-resource.
- Create is a single `INSERT` statement — it is not wrapped in a database transaction (no `begin_transaction()`/`commit()`/`rollback()` calls in the source).
- This module does not currently write any `audit_log` entries — no calls to `insertAuditLog()` exist in its source. There is no corresponding `GET /api/v2/audit-log?module=salary_transaction...` history available at this time.
- There is no duplicate/uniqueness check on create, so no `409 Conflict` response exists for this module.
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a salary transaction (list, detail, and any nested items). Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
