# Company Setting Menu API

> **Last updated:** 2026-07-11 18:49:02 WIB
> **Base URL:** `/api/v2/company-setting-menu`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/company-setting-menu` | List all company setting menus (paginated) |
| POST   | `/api/v2/company-setting-menu` | Create a new company setting menu |
| GET    | `/api/v2/company-setting-menu/{id}` | Get company setting menu detail |
| PUT    | `/api/v2/company-setting-menu/{id}` | Update a company setting menu |
| DELETE | `/api/v2/company-setting-menu/{id}` | Delete a company setting menu |
| GET    | `/api/v2/company-setting-menu/{id}/details` | List all details of a setting menu (paginated) |
| POST   | `/api/v2/company-setting-menu/{id}/details` | Create a new detail under a setting menu |
| GET    | `/api/v2/company-setting-menu/{id}/details/{detail_id}` | Get detail-record detail |
| PUT    | `/api/v2/company-setting-menu/{id}/details/{detail_id}` | Update a detail record |
| DELETE | `/api/v2/company-setting-menu/{id}/details/{detail_id}` | Delete a detail record |

---

### GET `/api/v2/company-setting-menu`

List all company setting menus belonging to the authenticated company.

#### Query parameters

| Parameter | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| page      | int    | No       | 1       | Page number |
| limit     | int    | No       | 10      | Items per page (max 100) |
| search    | string | No       | —       | Full-text search on `setting_name` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Company setting menus found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "setting_image": "iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB...",
        "setting_name": "General",
        "setting_caption": "General company settings",
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

`setting_image` is `null` when no image was uploaded for that record.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "No company setting menus found",
  "data": []
}
```

---

### POST `/api/v2/company-setting-menu`

Create a new company setting menu.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| setting_name | string | Yes | — |
| setting_caption | null \| string | No | — |
| setting_image | null \| string (base64) | No | Binary image data, transmitted as a base64-encoded string. Rejected with 400 if not valid base64. |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Company setting menu created successfully",
  "data": {
    "company_setting_menu_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "setting_name is required",
  "data": []
}
```

`setting_image must be a valid base64 encoded string` is returned when `setting_image` fails base64 decoding.

---

### GET `/api/v2/company-setting-menu/{id}`

Get detail of a single company setting menu.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| company_setting_menu_id | string | The company setting menu ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Company setting menu found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "setting_image": "iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB...",
    "setting_name": "General",
    "setting_caption": "General company settings",
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
  "status_message": "Company setting menu not found",
  "data": []
}
```

---

### PUT `/api/v2/company-setting-menu/{id}`

Update a company setting menu. Only send the fields you want to change.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| company_setting_menu_id | string | The company setting menu ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| setting_name | string | No | Cannot be empty if provided |
| setting_caption | null \| string | No | Send `null` to clear |
| setting_image | null \| string (base64) | No | Binary image data, base64-encoded. Send `null` to clear the image. |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Company setting menu updated successfully",
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

Also returned as `setting_name cannot be empty` or `setting_image must be a valid base64 encoded string` for the respective invalid fields.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Company setting menu not found",
  "data": []
}
```

---

### DELETE `/api/v2/company-setting-menu/{id}`

Soft-deletes the company setting menu (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| company_setting_menu_id | string | The company setting menu ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Company setting menu deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Company setting menu not found",
  "data": []
}
```

---

## Company Setting Detail (sub-resource)

The following endpoints are nested under a parent setting menu: `/api/v2/company-setting-menu/{setting_menu_id}/details[/{detail_id}]`. The parent `setting_menu_id` must exist, belong to the authenticated company, and not be soft-deleted, or every endpoint below returns `404 Setting menu not found`.

### GET `/api/v2/company-setting-menu/{setting_menu_id}/details`

List all detail records under a setting menu.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| setting_menu_id | string | The parent company setting menu ID |

#### Query parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| page      | int  | No       | 1       | Page number |
| limit     | int  | No       | 10      | Items per page (max 100) |

_(No `search` parameter exists for this sub-resource.)_

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Company setting details found",
  "data": {
    "data": [
      {
        "id": "f6a7b8c9-d0e1-4f5a-9b0c-1d2e3f4a5b6c",
        "setting_menu_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "is_tab": true,
        "tab_count": 3,
        "is_data": true,
        "data_url": "/settings/general/company-profile",
        "is_can_new": false,
        "created_by": "Budi Santoso",
        "created_at": "2026-06-27 10:00:00",
        "updated_by": null,
        "updated_at": null,
        "deleted_at": null
      }
    ],
    "pagination": {
      "total": 8,
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
  "status_message": "No company setting details found",
  "data": []
}
```

---

### POST `/api/v2/company-setting-menu/{setting_menu_id}/details`

Create a new detail record under a setting menu.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| setting_menu_id | string | The parent company setting menu ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| data_url | string | Yes | — |
| is_tab | boolean | No | Defaults to `false` |
| tab_count | int | No | Defaults to `0` |
| is_data | boolean | No | Defaults to `false` |
| is_can_new | boolean | No | Defaults to `false` |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Company setting detail created successfully",
  "data": {
    "company_setting_detail_id": "f6a7b8c9-d0e1-4f5a-9b0c-1d2e3f4a5b6c"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "data_url is required",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Setting menu not found",
  "data": []
}
```

---

### GET `/api/v2/company-setting-menu/{setting_menu_id}/details/{detail_id}`

Get detail of a single company setting detail record.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| setting_menu_id | string | The parent company setting menu ID |
| company_setting_detail_id | string | The detail record ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Company setting detail found",
  "data": {
    "id": "f6a7b8c9-d0e1-4f5a-9b0c-1d2e3f4a5b6c",
    "setting_menu_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "is_tab": true,
    "tab_count": 3,
    "is_data": true,
    "data_url": "/settings/general/company-profile",
    "is_can_new": false,
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
  "status_message": "Company setting detail not found",
  "data": []
}
```

Also returned (as `Setting menu not found`) if the parent `setting_menu_id` does not exist or does not belong to the authenticated company.

---

### PUT `/api/v2/company-setting-menu/{setting_menu_id}/details/{detail_id}`

Update a company setting detail record. Only send the fields you want to change.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| setting_menu_id | string | The parent company setting menu ID |
| company_setting_detail_id | string | The detail record ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| is_tab | boolean | No | — |
| tab_count | int | No | — |
| is_data | boolean | No | — |
| data_url | string | No | Cannot be empty if provided |
| is_can_new | boolean | No | — |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Company setting detail updated successfully",
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

Also returned as `data_url cannot be empty` when `data_url` is provided but blank.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Company setting detail not found",
  "data": []
}
```

---

### DELETE `/api/v2/company-setting-menu/{setting_menu_id}/details/{detail_id}`

Soft-deletes the company setting detail record (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| setting_menu_id | string | The parent company setting menu ID |
| company_setting_detail_id | string | The detail record ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Company setting detail deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Company setting detail not found",
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
| 500  | Internal server error |

---

## Notes

- `company_setting_menu` is scoped to the authenticated company (`company_id` from the JWT) — records from other companies are never returned or modifiable.
- **`setting_image` is stored as raw binary (BLOB) in the database.** In every request and every response body it is transmitted as a **base64-encoded string**, or `null` if no image is set — encode before sending, decode after receiving. The API never returns raw binary in the JSON payload.
- Neither `company_setting_menu` nor `company_setting_detail` has a duplicate check on create or update.
- `company_setting_detail` rows have no `company_id` column of their own — tenant isolation is enforced by requiring the parent `setting_menu_id` to belong to the authenticated company on every sub-resource request.
- On `PUT /company-setting-menu/{id}`, if the `setting_image` key is included in the request body at all (including `null`, to clear it), the endpoint replaces the image together with whatever `setting_name`/`setting_caption` values are also supplied in the same request; fields omitted from that request keep their existing values.
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a company setting menu (list, detail, and any nested items). Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
