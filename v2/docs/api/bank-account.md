# Bank Account API

> **Last updated:** 2026-07-06 14:05:00 WIB
> **Base URL:** `/api/v2/bank-account`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/bank-account` | List all bank accounts (paginated) |
| POST   | `/api/v2/bank-account` | Create a new bank account |
| GET    | `/api/v2/bank-account/{id}` | Get bank account detail |
| PUT    | `/api/v2/bank-account/{id}` | Update a bank account |
| DELETE | `/api/v2/bank-account/{id}` | Delete a bank account |

---

### GET `/api/v2/bank-account`

List all bank accounts belonging to the authenticated company.

#### Query parameters

| Parameter   | Type   | Required | Default | Description |
|-------------|--------|----------|---------|-------------|
| page        | int    | No       | 1       | Page number |
| limit       | int    | No       | 10      | Items per page (max 100) |
| search      | string | No       | —       | Full-text search on `bank_number` and `bank_name` |
| currency_id | string | No       | —       | Filter by currency ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Bank accounts found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "bank_number": "1234567890",
        "bank_name": "Bank Central Asia",
        "bank_branch": "Jakarta Sudirman",
        "currency_id": "c3d4e5f6-a7b8-4c5d-9e0f-1a2b3c4d5e6f",
        "is_primary": true,
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
  "status_message": "No bank accounts found",
  "data": []
}
```

---

### POST `/api/v2/bank-account`

Create a new bank account.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| bank_number | string | Yes | Unique within the company |
| bank_name | string | Yes | — |
| currency_id | string | Yes | Must reference an existing currency |
| bank_branch | null \| string | No | — |
| is_primary | boolean | No | Defaults to `false` |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Bank account created successfully",
  "data": {
    "bank_account_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "bank_number is required",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Currency not found",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Bank account already exists",
  "data": []
}
```

---

### GET `/api/v2/bank-account/{id}`

Get detail of a single bank account.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| bank_account_id | string | The bank account ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Bank account found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "bank_number": "1234567890",
    "bank_name": "Bank Central Asia",
    "bank_branch": "Jakarta Sudirman",
    "currency_id": "c3d4e5f6-a7b8-4c5d-9e0f-1a2b3c4d5e6f",
    "is_primary": true,
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
  "status_message": "Bank account not found",
  "data": []
}
```

---

### PUT `/api/v2/bank-account/{id}`

Update a bank account. Only send the fields you want to change.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| bank_account_id | string | The bank account ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| bank_number | string | No | Cannot be empty if provided. Checked for duplicates. |
| bank_name | string | No | Cannot be empty if provided |
| bank_branch | null \| string | No | Send `null` to clear |
| currency_id | string | No | Must reference an existing currency |
| is_primary | boolean | No | — |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Bank account updated successfully",
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

Also returned as `bank_number cannot be empty` or `bank_name cannot be empty` for the respective invalid fields.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Bank account not found",
  "data": []
}
```

`Currency not found` is returned when `currency_id` is provided but does not reference an existing currency.

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Bank account already exists",
  "data": []
}
```

---

### DELETE `/api/v2/bank-account/{id}`

Soft-deletes the bank account (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| bank_account_id | string | The bank account ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Bank account deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Bank account not found",
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
- `is_primary` is returned as `true`/`false`.
- `currency_id` must reference an existing, non-deleted row in the `currency` table.
