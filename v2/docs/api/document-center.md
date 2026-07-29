# Document Center API

> **Last updated:** 2026-07-11 18:49:02 WIB
> **Base URL:** `/api/v2/document-center`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/document-center` | List all documents (paginated) |
| POST   | `/api/v2/document-center` | Create a new document |
| GET    | `/api/v2/document-center/{id}` | Get document detail |
| PUT    | `/api/v2/document-center/{id}` | Update a document |
| DELETE | `/api/v2/document-center/{id}` | Delete a document |

---

### GET `/api/v2/document-center`

List all documents belonging to the authenticated company.

#### Query parameters

| Parameter | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| page      | int    | No       | 1       | Page number |
| limit     | int    | No       | 10      | Items per page (max 100) |
| search    | string | No       | —       | Full-text search on `document_name` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Documents found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "document_name": "Invoice Template 2026",
        "document_file_size": 204800,
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
  "status_message": "No documents found",
  "data": []
}
```

---

### POST `/api/v2/document-center`

Create a new document.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| document_name | string | Yes | — |
| document_file_size | number | Yes | File size, in bytes |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Document created successfully",
  "data": {
    "document_center_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "document_name is required",
  "data": []
}
```

---

### GET `/api/v2/document-center/{id}`

Get detail of a single document.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| document_center_id | string | The document ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Document found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "document_name": "Invoice Template 2026",
    "document_file_size": 204800,
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
  "status_message": "Document not found",
  "data": []
}
```

---

### PUT `/api/v2/document-center/{id}`

Update a document. Only send the fields you want to change.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| document_center_id | string | The document ID |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| document_name | string | No | Cannot be empty if provided |
| document_file_size | number | No | File size, in bytes |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Document updated successfully",
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

Also returned as `document_name cannot be empty` when `document_name` is provided but blank.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Document not found",
  "data": []
}
```

---

### DELETE `/api/v2/document-center/{id}`

Soft-deletes the document (sets `deleted_at`) — it will no longer appear in list/detail responses.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| document_center_id | string | The document ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Document deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Document not found",
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
- Unlike most other master-data modules in this set, this module has **no duplicate check** on create or update — `document_name` may repeat within a company.
- This endpoint only stores document metadata (`document_name`, `document_file_size`); it does not accept or return file content.
- **`created_by` and `updated_by` are now resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on every endpoint that returns a document (list, detail, and any nested items). Previously these fields held the raw user ID; there is no separate `*_id` field for them, the resolved name **is** the value. `updated_by` is `null` until the record has actually been updated; `created_by` can be `null` only if the creating user has since been deleted.
