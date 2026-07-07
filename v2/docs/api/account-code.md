# Account Code API

> **Last updated:** 2026-07-06 14:00:00 WIB
> **Base URL:** `/api/v2/account-code`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/account-code` | List all account codes (paginated) |
| POST   | `/api/v2/account-code` | Create a new account code |
| GET    | `/api/v2/account-code/{id}` | Get account code detail |
| PUT    | `/api/v2/account-code/{id}` | Update an account code |
| DELETE | `/api/v2/account-code/{id}` | Delete an account code |

---

### GET `/api/v2/account-code`

List all account codes belonging to the authenticated company.

#### Query parameters

| Parameter    | Type   | Required | Default | Description |
|--------------|--------|----------|---------|-------------|
| page         | int    | No       | 1       | Page number |
| limit        | int    | No       | 10      | Items per page (max 100) |
| search       | string | No       | —       | Full-text search on `account_code` and `account_code_name` |
| account_type | string | No       | —       | Filter by account type: `asset` \| `liability` \| `equity` \| `revenue` \| `expense` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Account codes found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "account_code": "1000",
        "account_code_name": "Cash",
        "account_code_name_alias": "Kas",
        "account_type": "asset",
        "parent_account_code_id": null,
        "is_active": true,
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
  "status_message": "No account codes found",
  "data": []
}
```

---

### POST `/api/v2/account-code`

Create a new account code.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| account_code | string | Yes | Unique within the company |
| account_code_name | string | Yes | Display name |
| account_type | string | Yes | Must be one of: `asset`, `liability`, `equity`, `revenue`, `expense` |
| account_code_name_alias | null \| string | No | Alternate/local-language name |
| parent_account_code_id | null \| string | No | Must reference an existing account code (in the same company) |
| is_active | boolean | No | Defaults to `true` |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Account code created successfully",
  "data": {
    "account_code_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "account_code is required",
  "data": []
}
```

`account_type must be asset, liability, equity, revenue, or expense` is returned when `account_type` is not one of the allowed values.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Parent account code not found",
  "data": []
}
```

Returned when `parent_account_code_id` is provided but does not match an existing, non-deleted account code in the company.

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Account code already exists",
  "data": []
}
```

---

### GET `/api/v2/account-code/{id}`

Get detail of a single account code.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| account_code_id | string | The account code ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Account code found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "account_code": "1000",
    "account_code_name": "Cash",
    "account_code_name_alias": "Kas",
    "account_type": "asset",
    "parent_account_code_id": null,
    "is_active": true,
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
  "status_message": "Account code not found",
  "data": []
}
```

---

### PUT `/api/v2/account-code/{id}`

Update an account code. Only send the fields you want to change.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| account_code_id | string | The account code ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| account_code | string | No | Cannot be empty if provided. Checked for duplicates. |
| account_code_name | string | No | Cannot be empty if provided |
| account_code_name_alias | null \| string | No | Send `null` to clear |
| account_type | string | No | Must be one of: `asset`, `liability`, `equity`, `revenue`, `expense` |
| parent_account_code_id | null \| string | No | Must reference an existing account code. Send `null` to clear. |
| is_active | boolean | No | — |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Account code updated successfully",
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

Also returned as `account_code cannot be empty`, `account_code_name cannot be empty`, or `account_type must be asset, liability, equity, revenue, or expense` for the respective invalid fields.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Account code not found",
  "data": []
}
```

`Parent account code not found` is returned when `parent_account_code_id` is provided but does not reference an existing account code.

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Account code already exists",
  "data": []
}
```

---

### DELETE `/api/v2/account-code/{id}`

Soft-deletes the account code (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| account_code_id | string | The account code ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Account code deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Account code not found",
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
- `account_type` is a fixed enum: `asset`, `liability`, `equity`, `revenue`, `expense`.
- `parent_account_code_id` supports a self-referencing hierarchy; the parent must belong to the same company and not be soft-deleted.
- `is_active` is returned as `true`/`false`.
