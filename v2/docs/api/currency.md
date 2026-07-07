# Currency API

> **Last updated:** 2026-07-06 14:00:00 WIB
> **Base URL:** `/api/v2/currency`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/currency` | List all currencies (paginated) |
| POST   | `/api/v2/currency` | Create a new currency |
| GET    | `/api/v2/currency/{id}` | Get currency detail |
| PUT    | `/api/v2/currency/{id}` | Update a currency |
| DELETE | `/api/v2/currency/{id}` | Delete a currency |

---

### GET `/api/v2/currency`

List all currencies.

#### Query parameters

| Parameter | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| page      | int    | No       | 1       | Page number |
| limit     | int    | No       | 10      | Items per page (max 100) |
| search    | string | No       | —       | Search on `currency_code` OR `currency_name` (partial match) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Currencies found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "currency_code": "IDR",
        "currency_symbol": "Rp",
        "currency_name": "Indonesian Rupiah",
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
  "status_message": "No currencies found",
  "data": []
}
```

---

### POST `/api/v2/currency`

Create a new currency.

#### Request body (`application/json`)

| Field            | Type        | Required | Description |
|------------------|-------------|----------|-------------|
| currency_code    | string      | Yes      | Automatically upper-cased on save. Must be unique among non-deleted currencies. |
| currency_symbol  | string      | Yes      | Display symbol, e.g. `Rp`, `$`. |
| currency_name    | null\|string| No       | `null` if omitted or sent as an empty string. |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Currency created successfully",
  "data": {
    "currency_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "currency_code is required",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Currency already exists",
  "data": []
}
```

---

### GET `/api/v2/currency/{id}`

Get detail of a single currency.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id        | string | The currency ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Currency found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "currency_code": "IDR",
    "currency_symbol": "Rp",
    "currency_name": "Indonesian Rupiah",
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
  "status_message": "Currency not found",
  "data": []
}
```

---

### PUT `/api/v2/currency/{id}`

Update a currency. Only send the fields you want to change.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id        | string | The currency ID |

#### Request body (`application/json`)

| Field            | Type        | Required | Description |
|------------------|-------------|----------|-------------|
| currency_code    | string      | No       | Automatically upper-cased on save. Cannot be empty. Checked for uniqueness against other non-deleted currencies. |
| currency_symbol  | string      | No       | Cannot be an empty string if provided. |
| currency_name    | null\|string| No       | Sending an empty string clears the value to `NULL`. |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Currency updated successfully",
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
  "status_message": "Currency not found",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Currency already exists",
  "data": []
}
```

Returned when the updated `currency_code` matches another non-deleted currency (excluding the record being updated).

---

### DELETE `/api/v2/currency/{id}`

Soft-deletes the currency (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id        | string | The currency ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Currency deleted successfully",
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

- **Global lookup table.** `currency` has no `company_id` column and the queries in this module do not filter by company — every authenticated user across every company sees and shares the same set of currencies. `Authorization` is still required, but there is no tenant scoping on this data.
- IDs are UUIDs generated with `generateUUID()`, not prefixed strings (e.g. `a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d`).
- `currency_code` is always upper-cased server-side before it is stored or compared, on both create and update.
- Delete is a soft delete (`deleted_at` timestamp) and is reversible at the database layer, even though there is currently no undelete endpoint.
