# Product API

> **Last updated:** 2026-07-26 00:00:00 WIB
> **Base URL:** `/api/v2/product`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/product` | List all products (paginated) |
| POST   | `/api/v2/product` | Create a new product |
| GET    | `/api/v2/product/{id}` | Get product detail |
| PUT    | `/api/v2/product/{id}` | Update a product |
| DELETE | `/api/v2/product/{id}` | Delete a product |

---

### GET `/api/v2/product`

List all products belonging to the authenticated company.

#### Query parameters

| Parameter | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| page      | int    | No       | 1       | Page number |
| limit     | int    | No       | 10      | Items per page (max 100) |
| search    | string | No       | —       | Full-text search on `product_name`, `product_code`, and `hs_code` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Products found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "product_name": "Kertas HVS A4 80gsm",
        "product_code": "C-029",
        "product_desc": "Rim, 500 lembar per rim",
        "hs_code": "4802.56.00",
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
  "status_message": "No products found",
  "data": []
}
```

---

### POST `/api/v2/product`

Create a new product.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| product_name | string | Yes | Unique within the company |
| product_code | null \| string | No | Product/SKU code. Unique within the company when provided |
| product_desc | null \| string | No | — |
| hs_code | null \| string | No | Harmonized System tariff code |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Product created successfully",
  "data": {
    "product_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "product_name is required",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Product already exists",
  "data": []
}
```

Also returned as `Product code already exists` when `product_code` is provided and already in use by another product in the same company.

---

### GET `/api/v2/product/{id}`

Get detail of a single product.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| product_id | string | The product ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Product found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "product_name": "Kertas HVS A4 80gsm",
    "product_code": "C-029",
    "product_desc": "Rim, 500 lembar per rim",
    "hs_code": "4802.56.00",
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
  "status_message": "Product not found",
  "data": []
}
```

---

### PUT `/api/v2/product/{id}`

Update a product. Only send the fields you want to change.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| product_id | string | The product ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| product_name | string | No | Cannot be empty if provided. Checked for duplicates. |
| product_code | null \| string | No | Send `null` (or omit) to leave unchanged, send an empty string to clear. Checked for duplicates against other products in the company when non-empty. |
| product_desc | null \| string | No | Send `null` to clear |
| hs_code | null \| string | No | Send `null` to clear |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Product updated successfully",
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

Also returned as `product_name cannot be empty` when `product_name` is provided but blank.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Product not found",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Product already exists",
  "data": []
}
```

Also returned as `Product code already exists` when `product_code` is provided and already in use by another product in the same company.

---

### DELETE `/api/v2/product/{id}`

Soft-deletes the product (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| product_id | string | The product ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Product deleted successfully",
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
- **`product_code`** is the product's business/SKU code (e.g. `C-029`, `F-051`). It is optional and nullable — not every product has one. When provided on create or update, it must be unique within the company (`409 Conflict` otherwise). Products migrated from the legacy system had their old `skuID` carried over into this field automatically; products with no legacy code have `product_code: null`.
- No fields other than `product_name`/`product_code` reference other tables — `hs_code` is a free-text field, not validated against an external tariff list.
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a product (list, detail, and any nested items). Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
