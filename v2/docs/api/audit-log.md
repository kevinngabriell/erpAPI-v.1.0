# Audit Log API

> **Last updated:** 2026-07-17 20:00:00 WIB
> **Base URL:** `/api/v2/audit-log`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

This module is **read-only**. There is no create, update, or delete endpoint — entries are written internally by other modules, never through this API.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/audit-log` | List all audit log entries (paginated) |
| GET    | `/api/v2/audit-log/{id}` | Get audit log entry detail |

---

### GET `/api/v2/audit-log`

List all audit log entries belonging to the authenticated company.

#### Query parameters

| Parameter    | Type   | Required | Default | Description |
|--------------|--------|----------|---------|-------------|
| page         | int    | No       | 1       | Page number |
| limit        | int    | No       | 10      | Items per page (max 100) |
| module       | string | No       | —       | Filter by `module` (e.g. `purchase_order`) |
| reference_id | string | No       | —       | Filter by `reference_id` — the ID of the record the log entry refers to |
| action       | string | No       | —       | Filter by `action` (e.g. `created`, `updated`, `deleted`, `approved`, `rejected`) |

Combine `module` and `reference_id` to see the full history of a single resource, e.g.:

```
GET /api/v2/audit-log?module=purchase_order&reference_id=a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d
```

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Audit logs found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "module": "purchase_order",
        "reference_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
        "action": "created",
        "action_by": "Budi Santoso",
        "position_name": "Purchasing Staff",
        "action_at": "2026-07-01 10:00:00",
        "notes": null
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
  "status_message": "No audit logs found",
  "data": []
}
```

---

### GET `/api/v2/audit-log/{id}`

Get detail of a single audit log entry.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The audit log entry ID (UUID) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Audit log found",
  "data": {
    "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
    "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
    "module": "purchase_order",
    "reference_id": "c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f",
    "action": "created",
    "action_by": "Budi Santoso",
    "position_name": "Purchasing Staff",
    "action_at": "2026-07-01 10:00:00",
    "notes": null
  }
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Audit log not found",
  "data": []
}
```

---

## Error responses (all endpoints)

| Code | When |
|------|------|
| 401  | Missing or expired Bearer token |
| 404  | Resource not found |
| 405  | HTTP method not allowed on this path |
| 500  | Internal server error |

---

## Notes

- This module is **read-only**: only `GET` (list) and `GET /{id}` (detail) exist. There is no `POST`, `PUT`, or `DELETE` endpoint, so no `400 Bad Request` or `409 Conflict` responses are applicable.
- Rows in `audit_log` are **not** created directly through this API. They are written internally by other modules via the shared `insertAuditLog($conn, $company_id, $module, $reference_id, $action, $username, $notes = null)` helper in `v2/helpers/audit_log.php`, which inserts the columns `id`, `company_id`, `module`, `reference_id`, `action`, `action_by`, `action_at`, `notes`.
- Modules such as `purchase-order`, `purchase-receive`, `purchase-invoice`, `finance-transaction`, `finance-payment`, `sales-order`, `sales-delivery`, `sales-invoice`, `sales-sppb`, and `sales-profit` call this helper on their own create/update/delete/approve/reject actions.
- To see the approval/creation history of a given resource, filter by both `module` and `reference_id`, e.g. `GET /api/v2/audit-log?module=purchase_order&reference_id={id}`.
- **`action_by` is resolved to the acting user's full name** (`"First Last"`, joined from the core user directory), on both the list and detail endpoints. Previously this field held the raw user ID. There is no separate `action_by_id` field — the resolved name **is** the value; the raw user ID is no longer returned anywhere in the response. `action_by` can be `null` only if the user who performed the action has since been deleted.
- **`position_name` is a new field** — the acting user's job position at the time of the request (joined from `app_position` via the user's `position_id`), e.g. `"Purchasing Staff"`. It is `null` if the user has no position assigned or has since been deleted.
