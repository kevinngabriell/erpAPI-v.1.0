# Document Watermark API

> **Last updated:** 2026-10-19 21:30:15 WIB
> **Base URL:** `/api/v2/document-watermark`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/document-watermark` | List document watermarks (paginated, metadata only) |
| POST   | `/api/v2/document-watermark` | Create a new document watermark |
| GET    | `/api/v2/document-watermark/{id}` | Get document watermark detail (includes the image) |
| PUT    | `/api/v2/document-watermark/{id}` | Rename and/or replace the image of a document watermark |
| DELETE | `/api/v2/document-watermark/{id}` | Delete a document watermark |

`POST`, `PUT` and `DELETE` require the `master.document_watermark.manage` permission (`403` otherwise). `GET` is open to any authenticated user of the company.

---

### GET `/api/v2/document-watermark`

List the authenticated company's document watermarks, newest first. **The image itself is not included** — fetch `GET /{id}` for each watermark you need to preview.

#### Query parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| page      | int  | No       | 1       | Page number |
| limit     | int  | No       | 15      | Items per page (max 15) |

_(No `search` parameter exists for this module.)_

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Document watermarks found",
  "data": {
    "data": [
      {
        "id": "b2021da7-d673-45ba-9728-03f257534084",
        "company_id": "cmp0fcd1b1c010aa508",
        "watermark_name": "Company Logo",
        "file_key": null,
        "created_by": "Budi Santoso",
        "created_at": "2026-10-19 21:20:00",
        "updated_by": null,
        "updated_at": null
      }
    ],
    "pagination": {
      "total": 1,
      "page": 1,
      "limit": 15,
      "total_pages": 1
    }
  }
}
```

An empty company returns `200` with `data: []` and `total: 0`.

#### Response `500 Internal Server Error`

Returned (with `error_id`) if the list query fails.

---

### POST `/api/v2/document-watermark`

Create a new document watermark.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| watermark_name | string | Yes | Display name, trimmed, 1–255 characters. |
| watermark | string (base64) | One of `watermark` / `file_key` | Legacy inline image, base64-encoded, no `data:` prefix. Rejected with 400 if not valid base64. |
| file_key | string | One of `watermark` / `file_key` | Object-storage key from `POST /api/v2/files` (must start with the caller's `company_id/`). Preferred for new uploads. |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Document watermark created successfully",
  "data": {
    "document_watermark_id": "b2021da7-d673-45ba-9728-03f257534084"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "watermark_name is required",
  "data": [],
  "error_id": "006ad629-2f3e-7da3-8e8e-cdb036042f55"
}
```

Other `400` messages: `watermark is required`, `watermark_name must be 255 characters or fewer`, `watermark must be a valid base64 encoded string`.

---

### GET `/api/v2/document-watermark/{id}`

Get a single document watermark, including its image.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The document watermark ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Document watermark found",
  "data": {
    "id": "b2021da7-d673-45ba-9728-03f257534084",
    "company_id": "cmp0fcd1b1c010aa508",
    "watermark_name": "Company Logo",
    "watermark": "iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB...",
    "file_key": null,
    "created_by": "Budi Santoso",
    "created_at": "2026-10-19 21:20:00",
    "updated_by": "Budi Santoso",
    "updated_at": "2026-10-19 21:25:00",
    "deleted_at": null
  }
}
```

`watermark` is the stored inline image, base64-encoded (no MIME type is stored — detect it from the bytes if needed). It is `null` when the image lives in object storage; then `file_key` is set and `GET /api/v2/files?key=<file_key>` returns a pre-signed URL.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Document watermark not found",
  "data": [],
  "error_id": "…"
}
```

---

### PUT `/api/v2/document-watermark/{id}`

Rename a watermark, replace its image, or both. Send only what you want to change.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The document watermark ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| watermark_name | string | No | New name, trimmed, 1–255 characters. Cannot be cleared to empty. |
| watermark | string (base64) | No | New inline image, base64-encoded. An empty string is treated as "not sent". Clears `file_key`. |
| file_key | string | No | New object-storage key. Clears the inline image. |

At least one of the two must be provided.

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Document watermark updated successfully",
  "data": []
}
```

#### Response `400 Bad Request`

`No fields provided for update`, `watermark_name cannot be empty`, `watermark_name must be 255 characters or fewer`, `watermark must be a valid base64 encoded string` (each with `error_id`). Validation runs before the existence check.

#### Response `404 Not Found`

`Document watermark not found`.

---

### DELETE `/api/v2/document-watermark/{id}`

Soft-deletes the document watermark (sets `deleted_at`) — it no longer appears in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The document watermark ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Document watermark deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

`Document watermark not found`.

---

## Error responses (all endpoints)

Every error body carries `error_id` (also sent as the `X-Request-ID` header) for tracing in `log_request`.

| Code | When |
|------|------|
| 400  | Validation failed — missing or invalid field |
| 401  | Missing or expired Bearer token |
| 403  | Write without `master.document_watermark.manage` |
| 404  | Resource not found |
| 405  | HTTP method not allowed on this path |
| 500  | Internal server error |

---

## Notes

- Scoped to the authenticated company (`company_id` from the JWT) — other companies' records are never returned or modifiable.
- **`watermark` is stored as raw binary (`longblob`).** It is always transmitted as a base64 string in requests and responses.
- `watermark_name` was added in migration `v45_document_watermark_name.md`. Watermarks created before it have `watermark_name: null` until renamed.
- `master.document_watermark.manage` was added to the permission catalog in `v46_watermark_salary_category_permission_seed.md` and is **not assigned to any role yet** — every write returns `403` until a role is granted it.
- No duplicate check — a company may have several watermarks, including with the same name.
- `created_by` / `updated_by` are resolved to the acting user's full name (`"First Last"`); `updated_by` is `null` until the record is updated.
- Nothing in the API consumes watermarks yet (no document export stamps them); this module only stores and serves them.
