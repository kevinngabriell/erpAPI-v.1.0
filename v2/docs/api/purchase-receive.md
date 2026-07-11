# Purchase Receive API

> **Last updated:** 2026-07-11 15:00:00 WIB
> **Base URL:** `/api/v2/purchase-receive`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/purchase-receive` | List all purchase receives (paginated) |
| POST   | `/api/v2/purchase-receive` | Create a new purchase receive (with items) |
| GET    | `/api/v2/purchase-receive/{id}` | Get purchase receive detail (with items) |
| PUT    | `/api/v2/purchase-receive/{id}` | Update a purchase receive |
| DELETE | `/api/v2/purchase-receive/{id}` | Delete a purchase receive |

---

### GET `/api/v2/purchase-receive`

List all purchase receives belonging to the authenticated company.

#### Query parameters

| Parameter          | Type   | Required | Default | Description |
|--------------------|--------|----------|---------|-------------|
| page               | int    | No       | 1       | Page number |
| limit              | int    | No       | 10      | Items per page (max 100) |
| search             | string | No       | —       | Search on the linked purchase order's `po_display_number` (left-joined) |
| purchase_order_id  | string | No       | —       | Filter by `purchase_order_id` |
| supplier_id        | string | No       | —       | Filter by `supplier_id` |
| date_from          | string (date) | No | —    | Filter `receiving_date >=` this date (`YYYY-MM-DD`) |
| date_to            | string (date) | No | —    | Filter `receiving_date <=` this date (`YYYY-MM-DD`) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase receives found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "purchase_order_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
        "supplier_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
        "receiving_date": "2026-07-05",
        "ship_date": "2026-07-01",
        "ship_via_id": "e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b",
        "created_by": "budi.santoso",
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
  "status_message": "No purchase receives found",
  "data": []
}
```

---

### POST `/api/v2/purchase-receive`

Create a new purchase receive together with its items. Requires the referenced purchase order to exist for the authenticated company.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| purchase_order_id | string | Yes | Must reference an existing, non-deleted purchase order for the company |
| supplier_id | string | Yes | Supplier ID |
| receiving_date | string (date) | Yes | — |
| ship_date | string (date) | Yes | — |
| ship_via_id | string | Yes | — |
| items | array | Yes | Non-empty array of receive items — see below |

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
  "status_message": "Purchase receive created successfully",
  "data": {
    "purchase_receive_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "purchase_order_id is required",
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

---

### GET `/api/v2/purchase-receive/{id}`

Get detail of a single purchase receive, including its nested `items` array.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase receive ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase receive found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "purchase_order_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
    "supplier_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
    "receiving_date": "2026-07-05",
    "ship_date": "2026-07-01",
    "ship_via_id": "e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b",
    "created_by": "budi.santoso",
    "created_at": "2026-07-05 09:00:00",
    "updated_by": null,
    "updated_at": null,
    "deleted_at": null,
    "items": [
      {
        "id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
        "purchase_receive_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "product_name": "Steel Rod 12mm",
        "quantity": 100,
        "packaging_size": 10,
        "unit_price": 50000,
        "vat": 5000,
        "total": 5005000,
        "created_by": "budi.santoso",
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
  "status_message": "Purchase receive not found",
  "data": []
}
```

---

### PUT `/api/v2/purchase-receive/{id}`

Update a purchase receive. Only send the fields you want to change. Does not update `purchase_order_id` or items.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase receive ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| supplier_id | string | No | Cannot be empty if provided |
| ship_via_id | string | No | Cannot be empty if provided |
| receiving_date | string (date) | No | — |
| ship_date | string (date) | No | — |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase receive updated successfully",
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
  "status_message": "Purchase receive not found",
  "data": []
}
```

---

### DELETE `/api/v2/purchase-receive/{id}`

Soft-deletes the purchase receive (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The purchase receive ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase receive deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Purchase receive not found",
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

- Header + items creation is wrapped in a single database transaction — either the purchase receive and all its items are created together, or nothing is saved.
- Create/update/delete write `audit_log` rows with action `created`/`updated`/`deleted` (only `created` is currently wired up in the source — update/delete do not call `insertAuditLog`). Query this history via `GET /api/v2/audit-log?module=purchase_receive&reference_id={id}` — see the `audit-log` module doc.
- No enum-constrained fields were found in this module's source.
- The list endpoint (`GET /api/v2/purchase-receive`) does not include the nested `items` array. Only the detail endpoint (`GET /api/v2/purchase-receive/{id}`) returns `items`. The create response only returns `purchase_receive_id`.
- `search` is matched against the linked purchase order's `po_display_number` via a `LEFT JOIN`, not against any field on the purchase receive record itself — purchase receive rows have no display number of their own. Records whose `purchase_order_id` no longer resolves to a purchase order are excluded from `search` results (but still returned when `search` is omitted).
