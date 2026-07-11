# Purchase Invoice API

> **Last updated:** 2026-07-11 18:49:02 WIB
> **Base URL:** `/api/v2/purchase-invoice`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/purchase-invoice` | List all purchase invoices (paginated) |
| POST   | `/api/v2/purchase-invoice` | Create a new purchase invoice (with items) |
| GET    | `/api/v2/purchase-invoice/{id}` | Get purchase invoice detail (with items) |
| PUT    | `/api/v2/purchase-invoice/{id}` | Update a purchase invoice |
| DELETE | `/api/v2/purchase-invoice/{id}` | Delete a purchase invoice |

---

### GET `/api/v2/purchase-invoice`

List all purchase invoices belonging to the authenticated company.

#### Query parameters

| Parameter   | Type   | Required | Default | Description |
|-------------|--------|----------|---------|-------------|
| page        | int    | No       | 1       | Page number |
| limit       | int    | No       | 10      | Items per page (max 100) |
| search      | string | No       | —       | Search on `invoice_display_number` |
| supplier_id | string | No       | —       | Filter by `supplier_id` |
| date_from   | string (date) | No | —    | Filter `invoice_date >=` this date (`YYYY-MM-DD`) |
| date_to     | string (date) | No | —    | Filter `invoice_date <=` this date (`YYYY-MM-DD`) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase invoices found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "invoice_display_number": "INV-2026-0001",
        "purchase_order_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
        "supplier_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
        "invoice_date": "2026-07-05",
        "ship_date": "2026-07-01",
        "tax_invoice_number": "PPN-998877",
        "kurs": 15500,
        "term_id": "e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b",
        "created_by": "Budi Santoso",
        "created_at": "2026-07-05 09:00:00",
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
  "status_message": "No purchase invoices found",
  "data": []
}
```

---

### POST `/api/v2/purchase-invoice`

Create a new purchase invoice together with its items. Requires the referenced purchase order to exist for the authenticated company, and `invoice_display_number` to be unique within the company.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| invoice_display_number | string | Yes | Unique display number for the invoice within the company |
| purchase_order_id | string | Yes | Must reference an existing, non-deleted purchase order for the company |
| supplier_id | string | Yes | Supplier ID |
| invoice_date | string (date) | Yes | — |
| ship_date | string (date) | Yes | — |
| items | array | Yes | Non-empty array of invoice items — see below |
| tax_invoice_number | string | No | — |
| kurs | number | No | Exchange rate |
| term_id | string | No | — |

**`items[]` object:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| product_name | string | Yes | — |
| quantity | number | Yes | — |
| packaging_size | number | Yes | — |
| unit_price | number | Yes | — |
| vat | number | No | Defaults to `0` |
| total | number | No | Defaults to `(quantity * unit_price) + vat` if not provided |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Purchase invoice created successfully",
  "data": {
    "purchase_invoice_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "invoice_display_number is required",
  "data": []
}
```

```json
{
  "status_code": 400,
  "status_message": "items must be a non-empty array",
  "data": []
}
```

```json
{
  "status_code": 400,
  "status_message": "items.product_name is required",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Purchase order not found",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Purchase invoice already exists",
  "data": []
}
```

---

### GET `/api/v2/purchase-invoice/{id}`

Get detail of a single purchase invoice, including its nested `items` array.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase invoice ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase invoice found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "invoice_display_number": "INV-2026-0001",
    "purchase_order_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
    "supplier_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
    "invoice_date": "2026-07-05",
    "ship_date": "2026-07-01",
    "tax_invoice_number": "PPN-998877",
    "kurs": 15500,
    "term_id": "e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b",
    "created_by": "Budi Santoso",
    "created_at": "2026-07-05 09:00:00",
    "updated_by": null,
    "updated_at": null,
    "deleted_at": null,
    "items": [
      {
        "id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
        "purchase_invoice_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "product_name": "Steel Rod 12mm",
        "quantity": 100,
        "packaging_size": 10,
        "unit_price": 50000,
        "vat": 5000,
        "total": 5005000,
        "created_by": "Budi Santoso",
        "created_at": "2026-07-05 09:00:00",
        "updated_by": null,
        "updated_at": null,
        "deleted_at": null
      }
    ]
  }
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Purchase invoice not found",
  "data": []
}
```

---

### PUT `/api/v2/purchase-invoice/{id}`

Update a purchase invoice. Only send the fields you want to change. Does not update `purchase_order_id` or items.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase invoice ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| invoice_display_number | string | No | Cannot be empty if provided |
| supplier_id | string | No | Cannot be empty if provided |
| tax_invoice_number | string | No | Cannot be empty if provided |
| term_id | string | No | Cannot be empty if provided |
| invoice_date | string (date) | No | — |
| ship_date | string (date) | No | — |
| kurs | number | No | — |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase invoice updated successfully",
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

```json
{
  "status_code": 400,
  "status_message": "supplier_id cannot be empty",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Purchase invoice not found",
  "data": []
}
```

---

### DELETE `/api/v2/purchase-invoice/{id}`

Soft-deletes the purchase invoice (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase invoice ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase invoice deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Purchase invoice not found",
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

- Header + items creation is wrapped in a single database transaction — either the purchase invoice and all its items are created together, or nothing is saved.
- Create/update/delete write `audit_log` rows with action `created`/`updated`/`deleted` (only `created` is currently wired up in the source — update/delete do not call `insertAuditLog`). Query this history via `GET /api/v2/audit-log?module=purchase_invoice&reference_id={id}` — see the `audit-log` module doc.
- No enum-constrained fields were found in this module's source.
- `invoice_display_number` must be unique per company among non-deleted purchase invoices; violating this returns `409 Conflict`.
- The create response only returns `purchase_invoice_id`. Only the detail endpoint (`GET /api/v2/purchase-invoice/{id}`) returns the nested `items` array.
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a purchase invoice (list, detail, and any nested items). Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
