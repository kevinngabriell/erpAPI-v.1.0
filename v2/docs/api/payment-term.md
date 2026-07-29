# Payment Term API

> **Last updated:** 2026-07-11 18:49:02 WIB
> **Base URL:** `/api/v2/payment-term`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/payment-term` | List all payment terms (paginated) |
| POST   | `/api/v2/payment-term` | Create a new payment term |
| GET    | `/api/v2/payment-term/{id}` | Get payment term detail |
| PUT    | `/api/v2/payment-term/{id}` | Update a payment term |
| DELETE | `/api/v2/payment-term/{id}` | Delete a payment term |

---

### GET `/api/v2/payment-term`

List all payment terms.

#### Query parameters

| Parameter | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| page      | int    | No       | 1       | Page number |
| limit     | int    | No       | 10      | Items per page (max 100) |
| search    | string | No       | —       | Search on `term_name` (partial match) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Payment terms found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "term_name": "Net 30",
        "days": 30,
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
  "status_message": "No payment terms found",
  "data": []
}
```

---

### POST `/api/v2/payment-term`

Create a new payment term.

#### Request body (`application/json`)

| Field      | Type      | Required | Description |
|------------|-----------|----------|-------------|
| term_name  | string    | Yes      | Payment term name. Must be unique among non-deleted payment terms. |
| days       | null\|int | No       | Number of days for the term. `null` if omitted or sent as an empty string. |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Payment term created successfully",
  "data": {
    "payment_term_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "term_name is required",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Payment term already exists",
  "data": []
}
```

---

### GET `/api/v2/payment-term/{id}`

Get detail of a single payment term.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id        | string | The payment term ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Payment term found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "term_name": "Net 30",
    "days": 30,
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
  "status_message": "Payment term not found",
  "data": []
}
```

---

### PUT `/api/v2/payment-term/{id}`

Update a payment term. Only send the fields you want to change.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id        | string | The payment term ID |

#### Request body (`application/json`)

| Field      | Type      | Required | Description |
|------------|-----------|----------|-------------|
| term_name  | string    | No       | Cannot be an empty string if provided. |
| days       | null\|int | No       | Sending an empty string clears the value to `NULL`. |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Payment term updated successfully",
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
  "status_message": "Payment term not found",
  "data": []
}
```

---

### DELETE `/api/v2/payment-term/{id}`

Soft-deletes the payment term (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id        | string | The payment term ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Payment term deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Payment term not found",
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

- **Global lookup table.** `payment_term` has no `company_id` column and the queries in this module do not filter by company — every authenticated user across every company sees and shares the same set of payment terms. `Authorization` is still required, but there is no tenant scoping on this data.
- IDs are UUIDs generated with `generateUUID()`, not prefixed strings (e.g. `a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d`).
- Delete is a soft delete (`deleted_at` timestamp) and is reversible at the database layer, even though there is currently no undelete endpoint.
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a payment term (list, detail, and any nested items). Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
