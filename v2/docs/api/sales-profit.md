# Sales Profit API

> **Last updated:** 2026-07-11 15:00:00 WIB
> **Base URL:** `/api/v2/sales-profit`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/sales-profit` | List all sales profits (paginated) |
| POST   | `/api/v2/sales-profit` | Create a new sales profit record (with items) |
| GET    | `/api/v2/sales-profit/{id}` | Get sales profit detail (with items) |
| PUT    | `/api/v2/sales-profit/{id}` | Update a sales profit record |
| DELETE | `/api/v2/sales-profit/{id}` | Delete a sales profit record |

---

### GET `/api/v2/sales-profit`

List all sales profit records belonging to the authenticated company.

#### Query parameters

| Parameter      | Type   | Required | Default | Description |
|----------------|--------|----------|---------|-------------|
| page           | int    | No       | 1       | Page number |
| limit          | int    | No       | 10      | Items per page (max 100) |
| search         | string | No       | —       | Search on the linked sales order's `so_display_number` (left-joined) |
| customer_id    | string | No       | —       | Filter by `customer_id` |
| sales_order_id | string | No       | —       | Filter by `sales_order_id` |
| date_from      | string (date) | No | —    | Filter `created_at >=` this date (`YYYY-MM-DD`, start of day) |
| date_to        | string (date) | No | —    | Filter `created_at <=` this date (`YYYY-MM-DD`, end of day) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales profits found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "sales_order_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
        "customer_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
        "created_by": "budi.santoso",
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
  "status_message": "No sales profits found",
  "data": []
}
```

---

### POST `/api/v2/sales-profit`

Create a new sales profit record together with its items.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| sales_order_id | string | Yes | Sales order ID — must exist and belong to the company |
| customer_id | string | Yes | Customer ID |
| items | array | Yes | Non-empty array of sales profit items — see below |

**`items[]` object:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| product_name | string | Yes | — |
| quantity | number | Yes | — |
| price | number | Yes | Sale price |
| landed_cost | number | Yes | Landed cost of the item |
| purchase_order_id | string | No | Related purchase order ID |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Sales profit created successfully",
  "data": {
    "sales_profit_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "sales_order_id is required",
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
  "status_message": "items.price is required",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales order not found",
  "data": []
}
```

---

### GET `/api/v2/sales-profit/{id}`

Get detail of a single sales profit record, including its nested `items` array.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales profit ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales profit found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "sales_order_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
    "customer_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
    "created_by": "budi.santoso",
    "created_at": "2026-07-01 10:00:00",
    "updated_by": null,
    "updated_at": null,
    "deleted_at": null,
    "items": [
      {
        "id": "e1f2a3b4-c5d6-4e5f-8a9b-0c1d2e3f4a5b",
        "sales_profit_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "purchase_order_id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
        "product_name": "Steel Rod 12mm",
        "quantity": 100,
        "price": 55000,
        "landed_cost": 50000,
        "created_by": "budi.santoso",
        "created_at": "2026-07-01 10:00:00",
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
  "status_message": "Sales profit not found",
  "data": []
}
```

---

### PUT `/api/v2/sales-profit/{id}`

Update a sales profit record. Only send the fields you want to change. Does not update items.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales profit ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| customer_id | string | No | Cannot be empty if provided |
| sales_order_id | string | No | Cannot be empty if provided; must exist and belong to the company |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales profit updated successfully",
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
  "status_message": "Sales profit not found",
  "data": []
}
```

```json
{
  "status_code": 404,
  "status_message": "Sales order not found",
  "data": []
}
```

---

### DELETE `/api/v2/sales-profit/{id}`

Soft-deletes the sales profit (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales profit ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales profit deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales profit not found",
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

- Header + items creation is wrapped in a single database transaction — either the sales profit record and all its items are created together, or nothing is saved.
- Create/update/delete write `audit_log` rows with action `created`/`updated`/`deleted` and module string `sales_profit`. Query this history via `GET /api/v2/audit-log?module=sales_profit&reference_id={id}` — see the `audit-log` module doc.
- The list endpoint (`GET /api/v2/sales-profit`) does not include the nested `items` array; only the detail endpoint (`GET /api/v2/sales-profit/{id}`) does. The create response only returns `sales_profit_id`.
- Unlike sales SPPB, this module has no unique display-number field and no duplicate (409) check on create.
- `price` and `landed_cost` are stored exactly as submitted by the client — the API does not calculate a profit or margin field anywhere in this module; any profit/margin figure must be derived by the consumer from `price` and `landed_cost`.
- No enum-constrained fields were found in this module's source code.
- `search` is matched against the linked sales order's `so_display_number` via a `LEFT JOIN`, not against any field on the sales profit record itself — sales profit rows have no display number of their own. Records whose `sales_order_id` no longer resolves to a sales order are excluded from `search` results (but still returned when `search` is omitted).
