# Customer API

> **Last updated:** 2026-07-25 20:02:24 WIB
> **Base URL:** `/api/v2/customer`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/customer` | List all customers (paginated) |
| POST   | `/api/v2/customer` | Create a new customer |
| GET    | `/api/v2/customer/{id}` | Get customer detail |
| PUT    | `/api/v2/customer/{id}` | Update a customer |
| DELETE | `/api/v2/customer/{id}` | Delete a customer |

---

### GET `/api/v2/customer`

List all customers belonging to the authenticated company.

#### Query parameters

| Parameter | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| page      | int    | No       | 1       | Page number |
| limit     | int    | No       | 10      | Items per page (max 100) |
| search    | string | No       | —       | Full-text search on `customer_name` and `customer_pic_name` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Customers found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "customer_name": "PT Maju Bersama",
        "customer_address": "Jl. Sudirman No. 1, Jakarta",
        "customer_phone": "021-5551234",
        "customer_pic_name": "Budi Santoso",
        "customer_pic_contact": "0812-3456-7890",
        "customer_top_days": 30,
        "npwp": "01.234.567.8-901.000",
        "tax_invoice_name": "PT Maju Bersama",
        "tax_invoice_address": "Jl. Sudirman No. 1, Jakarta",
        "credit_limit": 50000000,
        "created_by": "Budi Santoso",
        "created_at": "2026-06-27 10:00:00",
        "updated_by": null,
        "updated_at": null,
        "deleted_at": null,
        "outstanding_amount": 2500000,
        "has_outstanding": true
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
  "status_message": "No customers found",
  "data": []
}
```

---

### POST `/api/v2/customer`

Create a new customer.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| customer_name | string | Yes | Unique within the company |
| customer_address | null \| string | No | — |
| customer_phone | null \| string | No | — |
| customer_pic_name | null \| string | No | Person-in-charge name |
| customer_pic_contact | null \| string | No | Person-in-charge contact |
| customer_top_days | null \| int | No | Terms of payment, in days |
| npwp | null \| string | No | Tax ID number |
| tax_invoice_name | null \| string | No | — |
| tax_invoice_address | null \| string | No | — |
| credit_limit | null \| number | No | — |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Customer created successfully",
  "data": {
    "customer_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "customer_name is required",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Customer already exists",
  "data": []
}
```

---

### GET `/api/v2/customer/{id}`

Get detail of a single customer.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| customer_id | string | The customer ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Customer found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "customer_name": "PT Maju Bersama",
    "customer_address": "Jl. Sudirman No. 1, Jakarta",
    "customer_phone": "021-5551234",
    "customer_pic_name": "Budi Santoso",
    "customer_pic_contact": "0812-3456-7890",
    "customer_top_days": 30,
    "npwp": "01.234.567.8-901.000",
    "tax_invoice_name": "PT Maju Bersama",
    "tax_invoice_address": "Jl. Sudirman No. 1, Jakarta",
    "credit_limit": 50000000,
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
  "status_message": "Customer not found",
  "data": []
}
```

---

### PUT `/api/v2/customer/{id}`

Update a customer. Only send the fields you want to change.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| customer_id | string | The customer ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| customer_name | string | No | Cannot be empty if provided. Checked for duplicates. |
| customer_address | null \| string | No | Send `null` to clear |
| customer_phone | null \| string | No | Send `null` to clear |
| customer_pic_name | null \| string | No | Send `null` to clear |
| customer_pic_contact | null \| string | No | Send `null` to clear |
| customer_top_days | null \| int | No | Send `null` to clear |
| npwp | null \| string | No | Send `null` to clear |
| tax_invoice_name | null \| string | No | Send `null` to clear |
| tax_invoice_address | null \| string | No | Send `null` to clear |
| credit_limit | null \| number | No | Send `null` to clear |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Customer updated successfully",
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

Also returned as `customer_name cannot be empty` when `customer_name` is provided but blank.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Customer not found",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Customer already exists",
  "data": []
}
```

---

### DELETE `/api/v2/customer/{id}`

Soft-deletes the customer (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| customer_id | string | The customer ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Customer deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Customer not found",
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
- No fields other than `customer_name` reference other tables — none of the optional fields are validated against other master data.
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a customer (list, detail, and any nested items). Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
- **`outstanding_amount` and `has_outstanding` are only present on `GET /api/v2/customer` (list), not on `GET /api/v2/customer/{id}` (detail).** `outstanding_amount` is the sum, across every invoice tied to this customer, of `MAX(due_amount) - SUM(paid_amount)` per invoice (only invoices where that difference is greater than `0` count) — the same "outstanding" definition already used by the AR/AP report and dashboard widgets. `has_outstanding` is `true` when `outstanding_amount > 0`.
