# Sales Delivery API

> **Last updated:** 2026-07-11 15:00:00 WIB
> **Base URL:** `/api/v2/sales-delivery`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/sales-delivery` | List all sales deliveries (paginated) |
| POST   | `/api/v2/sales-delivery` | Create a new sales delivery (with items) |
| GET    | `/api/v2/sales-delivery/{id}` | Get sales delivery detail (with items) |
| PUT    | `/api/v2/sales-delivery/{id}` | Update a sales delivery |
| DELETE | `/api/v2/sales-delivery/{id}` | Delete a sales delivery |

---

### GET `/api/v2/sales-delivery`

List all sales deliveries belonging to the authenticated company.

#### Query parameters

| Parameter      | Type   | Required | Default | Description |
|----------------|--------|----------|---------|-------------|
| page           | int    | No       | 1       | Page number |
| limit          | int    | No       | 10      | Items per page (max 100) |
| search         | string | No       | —       | Search on `do_display_number` |
| customer_id    | string | No       | —       | Filter by `customer_id` |
| sales_order_id | string | No       | —       | Filter by `sales_order_id` |
| date_from      | string (date) | No | —    | Filter `delivery_date >=` this date (`YYYY-MM-DD`) |
| date_to        | string (date) | No | —    | Filter `delivery_date <=` this date (`YYYY-MM-DD`) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales deliveries found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "do_display_number": "DO-2026-0001",
        "customer_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
        "sales_order_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
        "delivery_date": "2026-07-05",
        "bill_to_address": "Jl. Sudirman No. 1, Jakarta",
        "ship_to_address": "Jl. Gatot Subroto No. 2, Jakarta",
        "container_number": "CONT1234567",
        "bl_number": "BL-998877",
        "vessel_name": "MV Nusantara",
        "etd_date": "2026-07-10",
        "eta_date": "2026-07-20",
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
  "status_message": "No sales deliveries found",
  "data": []
}
```

---

### POST `/api/v2/sales-delivery`

Create a new sales delivery together with its items.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| do_display_number | string | Yes | Unique display number for the delivery order |
| customer_id | string | Yes | Customer ID |
| sales_order_id | string | Yes | Must reference an existing, non-deleted sales order in the same company |
| delivery_date | string (date) | Yes | — |
| bill_to_address | string | Yes | — |
| ship_to_address | string | Yes | — |
| items | array | Yes | Non-empty array of delivery items — see below |
| container_number | string | No | — |
| bl_number | string | No | — |
| vessel_name | string | No | — |
| etd_date | string (date) | No | Estimated time of departure |
| eta_date | string (date) | No | Estimated time of arrival |

**`items[]` object:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| product_name | string | Yes | — |
| quantity | number | Yes | — |
| notes | string | No | — |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Sales delivery created successfully",
  "data": {
    "sales_delivery_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "do_display_number is required",
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
  "status_message": "Sales order not found",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Sales delivery already exists",
  "data": []
}
```

---

### GET `/api/v2/sales-delivery/{id}`

Get detail of a single sales delivery, including its nested `items` array.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales delivery ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales delivery found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "do_display_number": "DO-2026-0001",
    "customer_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
    "sales_order_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
    "delivery_date": "2026-07-05",
    "bill_to_address": "Jl. Sudirman No. 1, Jakarta",
    "ship_to_address": "Jl. Gatot Subroto No. 2, Jakarta",
    "container_number": "CONT1234567",
    "bl_number": "BL-998877",
    "vessel_name": "MV Nusantara",
    "etd_date": "2026-07-10",
    "eta_date": "2026-07-20",
    "created_by": "budi.santoso",
    "created_at": "2026-07-01 10:00:00",
    "updated_by": null,
    "updated_at": null,
    "deleted_at": null,
    "items": [
      {
        "id": "e1f2a3b4-c5d6-4e5f-8a9b-0c1d2e3f4a5b",
        "sales_delivery_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "product_name": "Steel Rod 12mm",
        "quantity": 100,
        "notes": null,
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
  "status_message": "Sales delivery not found",
  "data": []
}
```

---

### PUT `/api/v2/sales-delivery/{id}`

Update a sales delivery. Only send the fields you want to change. Does not update items.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales delivery ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| do_display_number | string | No | Cannot be empty if provided |
| customer_id | string | No | Cannot be empty if provided |
| bill_to_address | string | No | Cannot be empty if provided |
| ship_to_address | string | No | Cannot be empty if provided |
| container_number | string | No | Cannot be empty if provided |
| bl_number | string | No | Cannot be empty if provided |
| vessel_name | string | No | Cannot be empty if provided |
| delivery_date | string (date) | No | — |
| etd_date | string (date) | No | — |
| eta_date | string (date) | No | — |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales delivery updated successfully",
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
  "status_message": "Sales delivery not found",
  "data": []
}
```

---

### DELETE `/api/v2/sales-delivery/{id}`

Soft-deletes the sales delivery (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The sales delivery ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Sales delivery deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Sales delivery not found",
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

- Header + items creation is wrapped in a single database transaction — either the sales delivery and all its items are created together, or nothing is saved.
- Create/update/delete write `audit_log` rows with action `created`/`updated`/`deleted`. Query this history via `GET /api/v2/audit-log?module=sales_delivery&reference_id={id}` — see the `audit-log` module doc.
- The list endpoint (`GET /api/v2/sales-delivery`) does not include the nested `items` array; only the detail endpoint (`GET /api/v2/sales-delivery/{id}`) does. The create response only returns `sales_delivery_id`.
- No enum constraints are enforced in this module's source code.
