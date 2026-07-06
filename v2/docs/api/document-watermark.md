# Document Watermark API

> **Last updated:** 2026-07-06 14:30:00 WIB
> **Base URL:** `/api/v2/document-watermark`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/document-watermark` | List all document watermarks (paginated) |
| POST   | `/api/v2/document-watermark` | Create a new document watermark |
| GET    | `/api/v2/document-watermark/{id}` | Get document watermark detail |
| PUT    | `/api/v2/document-watermark/{id}` | Update a document watermark |
| DELETE | `/api/v2/document-watermark/{id}` | Delete a document watermark |

---

### GET `/api/v2/document-watermark`

List all document watermarks belonging to the authenticated company.

#### Query parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| page      | int  | No       | 1       | Page number |
| limit     | int  | No       | 10      | Items per page (max 100) |

_(No `search` parameter exists for this module.)_

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Document watermarks found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "watermark": "iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB...",
        "created_by": "usr_64a1b2c3",
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
  "status_message": "No document watermarks found",
  "data": []
}
```

---

### POST `/api/v2/document-watermark`

Create a new document watermark.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| watermark | string (base64) | Yes | Binary image data, transmitted as a base64-encoded string. Rejected with 400 if not valid base64. |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Document watermark created successfully",
  "data": {
    "document_watermark_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "watermark is required",
  "data": []
}
```

`watermark must be a valid base64 encoded string` is returned when `watermark` fails base64 decoding.

---

### GET `/api/v2/document-watermark/{id}`

Get detail of a single document watermark.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| document_watermark_id | string | The document watermark ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Document watermark found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "watermark": "iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB...",
    "created_by": "usr_64a1b2c3",
    "created_at": "2026-06-27 10:00:00",
    "updated_by": null,
    "updated_at": null,
    "deleted_at": null
  }
}
```

`watermark` is the binary image data stored for this record, base64-encoded in the response (see Notes below).

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Document watermark not found",
  "data": []
}
```

---

### PUT `/api/v2/document-watermark/{id}`

Update a document watermark.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| document_watermark_id | string | The document watermark ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| watermark | string (base64) | Yes | Binary image data, transmitted as a base64-encoded string. This is the only updatable field, and it must be provided (non-empty) to update the record. |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Document watermark updated successfully",
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

`watermark must be a valid base64 encoded string` is returned when `watermark` fails base64 decoding.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Document watermark not found",
  "data": []
}
```

---

### DELETE `/api/v2/document-watermark/{id}`

Soft-deletes the document watermark (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| document_watermark_id | string | The document watermark ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Document watermark deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Document watermark not found",
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

- Resource is scoped to the authenticated company (`company_id` from the JWT) — records from other companies are never returned or modifiable.
- **`watermark` is stored as raw binary (BLOB) in the database.** In every request and every response body it is transmitted as a **base64-encoded string** — encode before sending, decode after receiving. The API never returns raw binary in the JSON payload.
- This module has no duplicate check on create or update — a company may have multiple watermark records.
- `PUT` requires `watermark` to be present and non-empty; there is no partial-update path for this module (unlike other modules where PUT accepts any subset of fields).
