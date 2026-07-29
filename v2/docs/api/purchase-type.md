# Purchase Type API

> **Last updated:** 2026-07-25 00:40:00 WIB
> **Base URL:** `/api/v2/purchase-type`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/purchase-type` | List all purchase types (paginated) |
| POST   | `/api/v2/purchase-type` | Create a new purchase type |
| GET    | `/api/v2/purchase-type/{id}` | Get purchase type detail |
| PUT    | `/api/v2/purchase-type/{id}` | Update a purchase type |
| DELETE | `/api/v2/purchase-type/{id}` | Delete a purchase type |

---

### GET `/api/v2/purchase-type`

List all purchase types.

#### Query parameters

| Parameter | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| page      | int    | No       | 1       | Page number |
| limit     | int    | No       | 10      | Items per page (max 100) |
| search    | string | No       | —       | Search on `type_name` (partial match) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase types found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "type_name": "Local Purchase",
        "vat_applicable": true,
        "number_format": "{company_code}/L/{month}/{yyyy}/{seq}",
        "sequence_digits": "3",
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

`vat_applicable` is returned as a boolean (`true`/`false`). `number_format` is `null` if not configured for this type — see `POST`/`PUT` below. `sequence_digits` is returned as a numeric string (not cast to int), consistent with how mysqli returns non-boolean numeric columns elsewhere in this API.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "No purchase types found",
  "data": []
}
```

---

### POST `/api/v2/purchase-type`

Create a new purchase type.

#### Request body (`application/json`)

| Field            | Type    | Required | Description |
|------------------|---------|----------|-------------|
| type_name        | string  | Yes      | Purchase type name. Must be unique among non-deleted purchase types. |
| vat_applicable   | boolean | No       | Whether VAT applies to this purchase type. Defaults to `true` if omitted. |
| number_format    | string  | No       | Template for `purchase_order.po_display_number`, used by `GET /api/v2/purchase-order/generate-number`. Must contain a literal `{seq}` placeholder — `400` otherwise. Other supported tokens: `{company_code}`, `{month}` (Roman numeral), `{yyyy}`, `{yy}`. `null`/omitted if not provided. |
| sequence_digits  | int     | No       | Zero-padded width of the `{seq}` token. Must be between 1 and 10. Defaults to `4` if omitted. |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Purchase type created successfully",
  "data": {
    "purchase_type_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "type_name is required",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "Purchase type already exists",
  "data": []
}
```

`number_format` validation also returns `400`:

```json
{
  "status_code": 400,
  "status_message": "number_format must include a {seq} placeholder",
  "data": []
}
```

```json
{
  "status_code": 400,
  "status_message": "sequence_digits must be between 1 and 10",
  "data": []
}
```

---

### GET `/api/v2/purchase-type/{id}`

Get detail of a single purchase type.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id        | string | The purchase type ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase type found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "type_name": "Local Purchase",
    "vat_applicable": true,
    "number_format": "{company_code}/L/{month}/{yyyy}/{seq}",
    "sequence_digits": "3",
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
  "status_message": "Purchase type not found",
  "data": []
}
```

---

### PUT `/api/v2/purchase-type/{id}`

Update a purchase type. Only send the fields you want to change.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id        | string | The purchase type ID |

#### Request body (`application/json`)

| Field            | Type    | Required | Description |
|------------------|---------|----------|-------------|
| type_name        | string  | No       | Cannot be an empty string if provided. |
| vat_applicable   | boolean | No       | Whether VAT applies to this purchase type. |
| number_format    | string  | No       | Cannot be an empty string if provided; must contain a literal `{seq}` placeholder — `400` otherwise. See tokens list under `POST` above. |
| sequence_digits  | int     | No       | Must be between 1 and 10. |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase type updated successfully",
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
  "status_message": "number_format must include a {seq} placeholder",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Purchase type not found",
  "data": []
}
```

---

### DELETE `/api/v2/purchase-type/{id}`

Soft-deletes the purchase type (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| id        | string | The purchase type ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase type deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Purchase type not found",
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

- **Global lookup table.** `purchase_type` has no `company_id` column and the queries in this module do not filter by company — every authenticated user across every company sees and shares the same set of purchase types. `Authorization` is still required, but there is no tenant scoping on this data.
- IDs are UUIDs generated with `generateUUID()`, not prefixed strings (e.g. `a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d`).
- `vat_applicable` defaults to `true` when not supplied on create; any falsy value (`false`, `0`, omitted-then-explicit-false) is stored as `false`.
- Delete is a soft delete (`deleted_at` timestamp) and is reversible at the database layer, even though there is currently no undelete endpoint.
- **`number_format`/`sequence_digits` drive `GET /api/v2/purchase-order/generate-number`** for purchase orders of this type — see `purchase-order.md`. `Local` and `Import` (the two rows that existed before this feature) are pre-configured to match the numbering already in use: `Import` → `{company_code}/{yy}/{month}/{seq}` (4-digit sequence, e.g. `VKN/26/VII/0030`), `Local` → `{company_code}/L/{month}/{yyyy}/{seq}` (3-digit sequence, e.g. `VKN/L/VI/2026/015`). A newly created purchase type has `number_format = null` until an admin sets one via `PUT` — `generate-number` returns `500` for that type until then, by design (no guessed default format).
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a purchase type (list, detail, and any nested items). Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
