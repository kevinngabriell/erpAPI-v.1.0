# Shipment Period API

> **Last updated:** 2026-07-25 07:00:00 WIB
> **Base URL:** `/api/v2/shipment-period`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/shipment-period` | List all shipment periods (paginated) |
| POST   | `/api/v2/shipment-period` | Create a new shipment period |
| GET    | `/api/v2/shipment-period/{id}` | Get shipment period detail |
| PUT    | `/api/v2/shipment-period/{id}` | Update a shipment period |
| DELETE | `/api/v2/shipment-period/{id}` | Delete a shipment period |

---

### GET `/api/v2/shipment-period`

List all shipment periods. This is a **global** lookup, shared by every company (not scoped to `company_id`) — seeded with 36 fixed rows (`Early`/`Mid`/`End` × each month), used as the `shipment_period_id` reference on `purchase_order` (see `purchase-order.md`).

#### Query parameters

| Parameter | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| page      | int    | No       | 1       | Page number |
| limit     | int    | No       | 10      | Items per page (max 100) |
| search    | string | No       | —       | Search on `period_name` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Shipment periods found",
  "data": {
    "data": [
      {
        "id": "shp_early_jul",
        "period_name": "Early July",
        "sort_order": 19,
        "created_by": "Budi Santoso",
        "created_at": "2026-07-25 06:39:53",
        "updated_by": null,
        "updated_at": "2026-07-25 06:39:53",
        "deleted_at": null
      }
    ],
    "pagination": {
      "total": 36,
      "page": 1,
      "limit": 10,
      "total_pages": 4
    }
  }
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "No shipment periods found",
  "data": []
}
```

---

### POST `/api/v2/shipment-period`

Create a new shipment period.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| period_name | string | Yes | Must be unique |
| sort_order | int | Yes | Controls display order in the list endpoint |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Shipment period created successfully",
  "data": {
    "shipment_period_id": "shp_64a1b2c3"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "period_name is required",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Shipment period already exists",
  "data": []
}
```

---

### GET `/api/v2/shipment-period/{id}`

Get detail of a single shipment period.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id | string | The shipment period ID (e.g. `shp_early_jul`) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Shipment period found",
  "data": {
    "id": "shp_early_jul",
    "period_name": "Early July",
    "sort_order": 19,
    "created_by": "Budi Santoso",
    "created_at": "2026-07-25 06:39:53",
    "updated_by": null,
    "updated_at": "2026-07-25 06:39:53",
    "deleted_at": null
  }
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Shipment period not found",
  "data": []
}
```

---

### PUT `/api/v2/shipment-period/{id}`

Update a shipment period. Only send the fields you want to change.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id | string | The shipment period ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| period_name | string | No | Cannot be empty if provided |
| sort_order | int | No | — |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Shipment period updated successfully",
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
  "status_message": "Shipment period not found",
  "data": []
}
```

---

### DELETE `/api/v2/shipment-period/{id}`

Soft-deletes the shipment period (sets `deleted_at`) — it will no longer appear in list/detail responses. Note this does **not** clear `shipment_period_id` on any `purchase_order` rows still referencing it; those rows keep the ID but it will no longer resolve to a `period_name` in `purchase-order` responses.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id | string | The shipment period ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Shipment period deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Shipment period not found",
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

- This is a **global** master table, like `ship-via` and `payment-term` — there is no `company_id` scoping, and all companies see and share the same list.
- Seeded with 36 fixed rows on creation (`Early January` … `End December`, `sort_order` 1–36). New rows can be added via `POST`, but the list endpoint always orders by `sort_order`, not `created_at`, so a new entry needs a sensible `sort_order` value to appear in the right place chronologically.
- Primarily used as the FK target for `purchase_order.shipment_period_id` — see `purchase-order.md`.
