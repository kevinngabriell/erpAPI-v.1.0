# Finance Payment API

> **Last updated:** 2026-07-11 18:49:02 WIB
> **Base URL:** `/api/v2/finance-payment`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/finance-payment` | List all finance payments (paginated) |
| POST   | `/api/v2/finance-payment` | Create a new finance payment |
| GET    | `/api/v2/finance-payment/{id}` | Get finance payment detail |
| PUT    | `/api/v2/finance-payment/{id}` | Update a finance payment |
| DELETE | `/api/v2/finance-payment/{id}` | Delete a finance payment |

---

### GET `/api/v2/finance-payment`

List all finance payments belonging to the authenticated company.

#### Query parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| page | int | No | 1 | Page number |
| limit | int | No | 10 | Items per page (max 100) |
| search | string | No | — | Search on `invoice_number` |
| customer_id | string | No | — | Filter by `customer_id` |
| supplier_id | string | No | — | Filter by `supplier_id` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance payments found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "invoice_number": "INV-2026-0001",
        "paid_amount": 2500000,
        "due_amount": 500000,
        "customer_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
        "supplier_id": null,
        "payment_date": "2026-07-01",
        "form_number": "FRM-0011",
        "bank_account_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
        "exchange_rate": 1,
        "cheque_number": "CHQ-0021",
        "cheque_date": "2026-07-01",
        "cheque_amount": 2500000,
        "memo": "Partial invoice settlement",
        "recipient": "PT Sumber Makmur",
        "discount_amount": 0,
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
  "status_message": "No finance payments found",
  "data": []
}
```

---

### POST `/api/v2/finance-payment`

Create a new finance payment.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| invoice_number | string | Yes | — |
| paid_amount | number | Yes | — |
| customer_id | string | No | If provided, must reference an existing, non-deleted customer in the company |
| supplier_id | string | No | If provided, must reference an existing, non-deleted supplier in the company |
| due_amount | number | No | — |
| payment_date | string (date) | No | — |
| form_number | string | No | — |
| bank_account_id | string | No | — |
| exchange_rate | number | No | — |
| cheque_number | string | No | — |
| cheque_date | string (date) | No | — |
| cheque_amount | number | No | — |
| memo | string | No | — |
| recipient | string | No | — |
| discount_amount | number | No | Defaults to `0` |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Finance payment created successfully",
  "data": {
    "finance_payment_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "invoice_number is required",
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

```json
{
  "status_code": 404,
  "status_message": "Supplier not found",
  "data": []
}
```

---

### GET `/api/v2/finance-payment/{id}`

Get detail of a single finance payment.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The finance payment ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance payment found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "invoice_number": "INV-2026-0001",
    "paid_amount": 2500000,
    "due_amount": 500000,
    "customer_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
    "supplier_id": null,
    "payment_date": "2026-07-01",
    "form_number": "FRM-0011",
    "bank_account_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
    "exchange_rate": 1,
    "cheque_number": "CHQ-0021",
    "cheque_date": "2026-07-01",
    "cheque_amount": 2500000,
    "memo": "Partial invoice settlement",
    "recipient": "PT Sumber Makmur",
    "discount_amount": 0,
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
  "status_message": "Finance payment not found",
  "data": []
}
```

---

### PUT `/api/v2/finance-payment/{id}`

Update a finance payment. Only send the fields you want to change.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The finance payment ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| invoice_number | string | No | Set to `null` if provided empty |
| form_number | string | No | Set to `null` if provided empty |
| cheque_number | string | No | Set to `null` if provided empty |
| memo | string | No | Set to `null` if provided empty |
| recipient | string | No | Set to `null` if provided empty |
| customer_id | string | No | Set to `null` if provided empty |
| supplier_id | string | No | Set to `null` if provided empty |
| bank_account_id | string | No | Set to `null` if provided empty |
| payment_date | string (date) | No | Set to `null` if provided empty |
| cheque_date | string (date) | No | Set to `null` if provided empty |
| paid_amount | number | No | — |
| due_amount | number | No | — |
| exchange_rate | number | No | — |
| cheque_amount | number | No | — |
| discount_amount | number | No | — |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance payment updated successfully",
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
  "status_message": "Finance payment not found",
  "data": []
}
```

---

### DELETE `/api/v2/finance-payment/{id}`

Soft-deletes the finance payment (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The finance payment ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Finance payment deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Finance payment not found",
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

- No enum-constrained fields exist on this module. `customer_id` and `supplier_id` are optional and, only when provided, are validated for existence against non-deleted records in the company; neither is required to be mutually exclusive.
- On update, `customer_id`, `supplier_id`, and `bank_account_id` are not re-validated for existence — only re-checked for emptiness (cleared to `null` if sent empty).
- create/update/delete write `audit_log` rows with action `created`/`updated`/`deleted`. Query this history via `GET /api/v2/audit-log?module=finance_payment&reference_id={id}` — see the `audit-log` module doc.
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a finance payment (list, detail, and any nested items). Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
