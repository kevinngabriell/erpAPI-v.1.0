# Supplier API

> **Last updated:** 2026-07-11 18:49:02 WIB
> **Base URL:** `/api/v2/supplier`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/supplier` | List all suppliers (paginated) |
| POST   | `/api/v2/supplier` | Create a new supplier |
| GET    | `/api/v2/supplier/{id}` | Get supplier detail |
| PUT    | `/api/v2/supplier/{id}` | Update a supplier |
| DELETE | `/api/v2/supplier/{id}` | Delete a supplier |

---

### GET `/api/v2/supplier`

List all suppliers belonging to the authenticated company.

#### Query parameters

| Parameter | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| page      | int    | No       | 1       | Page number |
| limit     | int    | No       | 10      | Items per page (max 100) |
| search    | string | No       | —       | Full-text search on `supplier_name` and `supplier_pic_name` |
| supplier_origin_id | string | No | — | Filter by origin ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Suppliers found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "supplier_name": "CV Sumber Rejeki",
        "supplier_origin_id": "c3d4e5f6-a7b8-4c5d-9e0f-1a2b3c4d5e6f",
        "supplier_address": "Jl. Gatot Subroto No. 5, Jakarta",
        "supplier_phone": "021-5559876",
        "supplier_pic_name": "Andi Wijaya",
        "supplier_pic_contact": "0813-4567-8901",
        "supplier_currency_id": "d4e5f6a7-b8c9-4d5e-9f0a-1b2c3d4e5f6a",
        "supplier_term_id": "e5f6a7b8-c9d0-4e5f-9a0b-1c2d3e4f5a6b",
        "supplier_bank_information": "BCA 1234567890 a/n CV Sumber Rejeki",
        "npwp": "02.345.678.9-012.000",
        "tax_invoice_name": "CV Sumber Rejeki",
        "tax_invoice_address": "Jl. Gatot Subroto No. 5, Jakarta",
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
  "status_message": "No suppliers found",
  "data": []
}
```

---

### POST `/api/v2/supplier`

Create a new supplier.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| supplier_name | string | Yes | Unique within the company |
| supplier_origin_id | string | Yes | Must reference an existing origin |
| supplier_address | null \| string | No | — |
| supplier_phone | null \| string | No | — |
| supplier_pic_name | null \| string | No | Person-in-charge name |
| supplier_pic_contact | null \| string | No | Person-in-charge contact |
| supplier_currency_id | null \| string | No | Must reference an existing currency |
| supplier_term_id | null \| string | No | Must reference an existing payment term |
| supplier_bank_information | null \| string | No | Free-text bank details |
| npwp | null \| string | No | Tax ID number |
| tax_invoice_name | null \| string | No | — |
| tax_invoice_address | null \| string | No | — |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Supplier created successfully",
  "data": {
    "supplier_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "supplier_name is required",
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

Also returned as `Currency not found` or `Payment term not found` when `supplier_currency_id` or `supplier_term_id` is provided but does not reference an existing record.

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Supplier already exists",
  "data": []
}
```

---

### GET `/api/v2/supplier/{id}`

Get detail of a single supplier.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| supplier_id | string | The supplier ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Supplier found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "supplier_name": "CV Sumber Rejeki",
    "supplier_origin_id": "c3d4e5f6-a7b8-4c5d-9e0f-1a2b3c4d5e6f",
    "supplier_address": "Jl. Gatot Subroto No. 5, Jakarta",
    "supplier_phone": "021-5559876",
    "supplier_pic_name": "Andi Wijaya",
    "supplier_pic_contact": "0813-4567-8901",
    "supplier_currency_id": "d4e5f6a7-b8c9-4d5e-9f0a-1b2c3d4e5f6a",
    "supplier_term_id": "e5f6a7b8-c9d0-4e5f-9a0b-1c2d3e4f5a6b",
    "supplier_bank_information": "BCA 1234567890 a/n CV Sumber Rejeki",
    "npwp": "02.345.678.9-012.000",
    "tax_invoice_name": "CV Sumber Rejeki",
    "tax_invoice_address": "Jl. Gatot Subroto No. 5, Jakarta",
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
  "status_message": "Supplier not found",
  "data": []
}
```

---

### PUT `/api/v2/supplier/{id}`

Update a supplier. Only send the fields you want to change.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| supplier_id | string | The supplier ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| supplier_name | string | No | Cannot be empty if provided. Checked for duplicates. |
| supplier_origin_id | string | No | Cannot be empty if provided. Must reference an existing origin. |
| supplier_address | null \| string | No | Send `null` to clear |
| supplier_phone | null \| string | No | Send `null` to clear |
| supplier_pic_name | null \| string | No | Send `null` to clear |
| supplier_pic_contact | null \| string | No | Send `null` to clear |
| supplier_currency_id | null \| string | No | Must reference an existing currency. Send `null` to clear. |
| supplier_term_id | null \| string | No | Must reference an existing payment term. Send `null` to clear. |
| supplier_bank_information | null \| string | No | Send `null` to clear |
| npwp | null \| string | No | Send `null` to clear |
| tax_invoice_name | null \| string | No | Send `null` to clear |
| tax_invoice_address | null \| string | No | Send `null` to clear |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Supplier updated successfully",
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

Also returned as `supplier_name cannot be empty` or `supplier_origin_id cannot be empty` for the respective invalid fields.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Supplier not found",
  "data": []
}
```

Also returned as `Origin not found`, `Currency not found`, or `Payment term not found` when the respective FK field is provided but does not reference an existing record.

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Supplier already exists",
  "data": []
}
```

---

### DELETE `/api/v2/supplier/{id}`

Soft-deletes the supplier (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| supplier_id | string | The supplier ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Supplier deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Supplier not found",
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
- `supplier_origin_id` must reference an existing, non-deleted row in the `origin` table (global, not company-scoped).
- `supplier_currency_id` must reference an existing, non-deleted row in the `currency` table (global, not company-scoped).
- `supplier_term_id` must reference an existing, non-deleted row in the `payment_term` table (global, not company-scoped).
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a supplier (list, detail, and any nested items). Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
