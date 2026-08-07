# Reorder Point API

> **Last updated:** 2026-08-07 10:15:00 WIB
> **Base URL:** `/api/v2/reorder-point`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/reorder-point` | List all reorder points (paginated) |
| POST   | `/api/v2/reorder-point` | Create a new reorder point |
| GET    | `/api/v2/reorder-point/{id}` | Get reorder point detail |
| PUT    | `/api/v2/reorder-point/{id}` | Update a reorder point |
| DELETE | `/api/v2/reorder-point/{id}` | Delete a reorder point |

---

### GET `/api/v2/reorder-point`

List all reorder points belonging to the authenticated company.

#### Query parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| page | int | No | 1 | Page number |
| limit | int | No | 10 | Items per page (max 100) |
| product_id | string | No | — | Filter by `product_id` |
| location_id | string | No | — | Filter by `location_id` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Reorder points found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "product_id": "c9d0e1f2-a3b4-4c5d-6e7f-8a9b0c1d2e3f",
        "location_id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
        "min_stock": 100,
        "alerted_at": null,
        "created_by": "Budi Santoso",
        "created_at": "2026-08-07 10:00:00",
        "updated_by": null,
        "updated_at": null,
        "deleted_at": null
      }
    ],
    "pagination": {
      "total": 1,
      "page": 1,
      "limit": 10,
      "total_pages": 1
    }
  }
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "No reorder points found",
  "data": []
}
```

---

### POST `/api/v2/reorder-point`

Create a new reorder point — the minimum-stock threshold for one `product_id` at one `location_id`.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| product_id | string | Yes | Must reference an existing, non-deleted product in the same company |
| location_id | string | Yes | Must reference an existing, non-deleted warehouse location in the same company |
| min_stock | number | Yes | The threshold — an alert fires when summed stock for this product+location drops below it |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Reorder point created successfully",
  "data": {
    "reorder_point_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "product_id is required",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Product not found",
  "data": []
}
```

```json
{
  "status_code": 404,
  "status_message": "Warehouse location not found",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Reorder point already exists for this product and location",
  "data": []
}
```

---

### GET `/api/v2/reorder-point/{id}`

Get detail of a single reorder point.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The reorder point ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Reorder point found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "product_id": "c9d0e1f2-a3b4-4c5d-6e7f-8a9b0c1d2e3f",
    "location_id": "f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c",
    "min_stock": 100,
    "alerted_at": "2026-08-07 10:05:00",
    "created_by": "Budi Santoso",
    "created_at": "2026-08-07 10:00:00",
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
  "status_message": "Reorder point not found",
  "data": []
}
```

---

### PUT `/api/v2/reorder-point/{id}`

Update a reorder point. Only `min_stock` can be changed — `product_id`/`location_id` are fixed at creation (delete and recreate to change either).

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The reorder point ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| min_stock | number | No | — |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Reorder point updated successfully",
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
  "status_message": "Reorder point not found",
  "data": []
}
```

---

### DELETE `/api/v2/reorder-point/{id}`

Delete a reorder point (soft delete). Stops future low-stock checks for that product+location — does not affect any `warehouse_lot` balances or past notifications.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The reorder point ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Reorder point deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Reorder point not found",
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
| 409  | Duplicate — a reorder point already exists for this product+location |
| 500  | Internal server error |

---

## Notes

- A reorder point does nothing on its own — it's read by `POST /api/v2/warehouse-transaction` (see `warehouse-transaction.md`) after each transaction commits, which compares summed `warehouse_lot.end_balance` for the same product+location against `min_stock` and fires a `low_stock_alert` notification (see `notification.md`) when it's below threshold.
- `alerted_at` is set the moment a low-stock alert fires and cleared once stock recovers back to/above `min_stock` — it's what stops the same dip from re-notifying on every subsequent transaction. It's returned in every response but not writable through this API; only the warehouse-transaction check sets or clears it.
- Threshold is per `product_id` + `location_id` — the same product can have a different `min_stock` at each warehouse location, or no threshold configured at all at a given location (in which case nothing is ever checked there).
- No endpoint here returns current on-hand stock directly — that's `warehouse-lot.md`'s list endpoint (filter by `product_id`/`location_id` and sum `end_balance` client-side), or the `dashboard.low_stock.view` widget (`dashboard.md`) for products already below threshold.
