# Files API (object storage)

> **Base URL:** `/api/v2/files`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

Files are stored in the S3-compatible Cloudeka object storage. Credentials come from
`v2/.env` (`S3_ENDPOINT`, `S3_REGION`, `S3_BUCKET`, `S3_ACCESS_KEY_ID`, `S3_SECRET_ACCESS_KEY`);
if unset, endpoints return `503 Object storage is not configured`.
Object keys are `{company_id}/{folder}/{uuid}.{ext}`; a company can only read/delete its own keys.

| Method | Path | Description |
|--------|------|-------------|
| POST   | `/api/v2/files` | Upload a file (`multipart/form-data`) |
| GET    | `/api/v2/files?key=` | Get a 5-minute pre-signed download URL |
| DELETE | `/api/v2/files?key=` | Delete a file |

### POST `/api/v2/files`

Form fields: `file` (required, max 10 MB; pdf, png, jpg, jpeg, gif, webp, doc, docx, xls, xlsx, csv, txt), `folder` (optional, `[a-z0-9_-]`, default `general`).

`201 Created`:

```json
{ "status_code": 201, "status_message": "File uploaded successfully",
  "data": { "key": "cmp.../general/<uuid>.pdf", "file_name": "invoice.pdf", "file_size": 204800, "content_type": "application/pdf" } }
```

### GET `/api/v2/files?key=...`

`200 OK` → `data: { "url": "<pre-signed url>", "expires_in": 300 }`. `400` if the key is missing or not owned by the company.

### DELETE `/api/v2/files?key=...`

`200 OK`. `400` if the key is missing or not owned by the company.
