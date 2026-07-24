# Notification API

> **Last updated:** 2026-07-25 06:24:59 WIB
> **Base URL:** `/api/v2/notification`, `/api/v2/approvals`, `/api/v2/notification-settings`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/notification` | List in-app notifications for the authenticated user (paginated) |
| PUT    | `/api/v2/notification/{id}/read` | Mark one notification as read |
| PUT    | `/api/v2/notification/read-all` | Mark all of the authenticated user's unread notifications as read |
| GET    | `/api/v2/notification/unread-count` | Unread notification count for the bell badge |
| GET    | `/api/v2/approvals/{token}` | Validate a one-click approval link and return a document summary |
| POST   | `/api/v2/approvals/{token}/approve` | Approve the document a token points to |
| POST   | `/api/v2/approvals/{token}/reject` | Reject the document a token points to |
| GET    | `/api/v2/notification-settings/public-holidays` | List public holidays (national + company-specific) |
| POST   | `/api/v2/notification-settings/public-holidays` | Add a public holiday |
| DELETE | `/api/v2/notification-settings/public-holidays/{id}` | Remove a public holiday |
| GET    | `/api/v2/notification-settings/working-days` | Get the company's working-days config |
| PUT    | `/api/v2/notification-settings/working-days` | Update the company's working-days config |

---

### GET `/api/v2/notification`

List in-app notifications belonging to the authenticated user.

#### Query parameters

| Parameter | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| page      | int    | No       | 1       | Page number |
| limit     | int    | No       | 20      | Items per page (max 100) |
| unread    | string | No       | —       | Pass `1` to return only unread notifications |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Notifications found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "company_id": "b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e",
        "type": "approval_pending",
        "source_module": "sales_order",
        "source_document_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
        "title": "Sales Order Menunggu Approval",
        "body": "SO-2026-0001 butuh approval Anda. Silahkan klik link dibawah untuk menyetujui:",
        "target_user_id": "usr_1a2b3c4d",
        "read_at": null,
        "created_by": "usr_5e6f7a8b",
        "created_at": "2026-07-21 08:00:00",
        "updated_by": null,
        "updated_at": null,
        "deleted_at": null
      }
    ],
    "pagination": {
      "total": 12,
      "page": 1,
      "limit": 20,
      "total_pages": 1
    }
  }
}
```

`type` is one of `approval_pending` \| `approval_approved` \| `approval_rejected` \| `digest_daily`. `source_module` is one of `sales_order` \| `purchase_order` \| `purchase_invoice` \| `purchase_receive` \| `finance_transaction` \| `finance_payment` (or `notification` for `digest_daily` rows, which don't point at one specific document). Construct the click-through link from `source_module` + `source_document_id` using the same per-module URL pattern already used elsewhere in the app.

---

### PUT `/api/v2/notification/{id}/read`

Mark a single notification as read. Only affects notifications owned by the caller.

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | The notification ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Notification marked as read",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Notification not found",
  "data": []
}
```

---

### PUT `/api/v2/notification/read-all`

Mark every unread notification belonging to the caller as read.

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "All notifications marked as read",
  "data": []
}
```

---

### GET `/api/v2/notification/unread-count`

Fetch the current unread count — call this on app load and after WebSocket reconnect to stay in sync (see Notes).

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Unread count retrieved",
  "data": {
    "unread_count": 3
  }
}
```

---

### GET `/api/v2/approvals/{token}`

Validate a one-click approval token (from a WhatsApp message or digest) and return a summary of the document it points to. Requires the caller to be logged in **as the token's intended recipient** — the token alone is not sufficient authorization (see Notes).

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| token | string | The approval token from the link |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Approval link is valid",
  "data": {
    "source_module": "finance_transaction",
    "source_document_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
    "document": {
      "id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
      "document_number": "FIN-2026-0021",
      "document_date": "2026-07-20",
      "status_name": "partially_approved",
      "created_by": "usr_5e6f7a8b",
      "created_at": "2026-07-20 09:00:00",
      "created_by_name": "Budi Santoso",
      "dual_approval_progress": {
        "owner_signed": true,
        "owner_name": "Siti Aminah",
        "treasury_signed": false,
        "treasury_name": null,
        "summary": "1/2 approvers signed"
      }
    }
  }
}
```

`document` only includes `dual_approval_progress` for `finance_transaction`/`finance_payment`. For `sales_order`/`purchase_order`/`purchase_invoice`/`purchase_receive`, `status_name` is `Draft` \| `Approved` \| `Rejected` instead of the Finance `draft` \| `partially_approved` \| `posted` \| `rejected` enum.

#### Response `403 Forbidden`

```json
{
  "status_code": 403,
  "status_message": "This approval link was not issued to your account",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "This approval link has already been used",
  "data": []
}
```

Also returned (with `document` in `data`) when the underlying document was already approved/rejected by someone else before this link was opened:

```json
{
  "status_code": 409,
  "status_message": "This document has already been Approved — nothing left to do here",
  "data": {
    "source_module": "sales_order",
    "source_document_id": "d4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a",
    "document": { "...": "..." }
  }
}
```

#### Response `410 Gone`

```json
{
  "status_code": 410,
  "status_message": "This approval link has expired",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Approval link not found",
  "data": []
}
```

---

### POST `/api/v2/approvals/{token}/approve`

Approve the document a token points to. Internally calls the exact same endpoint the normal approve button uses (`PATCH /api/v2/{module}/{id}/approve`), so the response body, audit trail, and Finance dual-approval behavior are identical to approving through the app. On success, the token is marked used and any other still-open tokens for the same document are invalidated (Finance: the co-signer's own separate token stays valid until they act, or until the document reaches a terminal state).

#### Path parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| token | string | The approval token from the link |

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Passed through as the underlying approve endpoint's `notes` |

#### Response

Bubbled through from the underlying `PATCH /api/v2/{module}/{id}/approve` call — see that module's own doc (`sales-order.md`, `purchase-order.md`, `purchase-invoice.md`, `purchase-receive.md`, `finance-transaction.md`, `finance-payment.md`) for the exact response shape. Same status codes as validating the token (`403`/`404`/`409`/`410`) also apply here if the token itself is invalid.

---

### POST `/api/v2/approvals/{token}/reject`

Reject the document a token points to. Same mechanics as `approve` above, calling `PATCH /api/v2/{module}/{id}/reject` internally.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| notes | string | No | Rejection reason, passed through as the underlying endpoint's `notes` |
| reason | string | No | Alias for `notes` — either key works |

---

### GET `/api/v2/notification-settings/public-holidays`

List public holidays visible to the authenticated company (national holidays where `company_id` is `null`, plus any company-specific rows).

#### Query parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| year | int | No | Filter to holidays in this calendar year |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Public holidays found",
  "data": [
    {
      "id": "h1a2b3c4",
      "company_id": null,
      "holiday_date": "2026-08-17",
      "label": "Hari Kemerdekaan RI",
      "created_by": "usr_5e6f7a8b",
      "created_at": "2026-01-05 09:00:00",
      "deleted_at": null
    }
  ]
}
```

---

### POST `/api/v2/notification-settings/public-holidays`

Add a public holiday.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| holiday_date | string (date) | Yes | `YYYY-MM-DD` |
| label | string | Yes | — |
| company_specific | boolean | No | Default `false` (national holiday, visible to all companies). Pass `true` to scope the holiday to only the caller's company |

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Public holiday created successfully",
  "data": {
    "id": "h1a2b3c4"
  }
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "A holiday already exists for this date",
  "data": []
}
```

---

### DELETE `/api/v2/notification-settings/public-holidays/{id}`

Remove a public holiday (hard status change via `deleted_at`, same soft-delete convention as the rest of the API).

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Public holiday deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Public holiday not found",
  "data": []
}
```

---

### GET `/api/v2/notification-settings/working-days`

Get the authenticated company's working-days config for the daily digest.

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Working days retrieved",
  "data": {
    "working_days": ["MON", "TUE", "WED", "THU", "FRI"]
  }
}
```

Defaults to `["MON","TUE","WED","THU","FRI"]` when the company has never set this — there is no separate "not configured" state.

---

### PUT `/api/v2/notification-settings/working-days`

Update the working-days config.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| working_days | array of string | Yes | Non-empty, each value one of `MON`\|`TUE`\|`WED`\|`THU`\|`FRI`\|`SAT`\|`SUN` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Working days updated successfully",
  "data": {
    "working_days": ["MON", "TUE", "WED", "THU", "FRI", "SAT"]
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "working_days must be a non-empty array",
  "data": []
}
```

---

## Error responses (all endpoints)

| Code | When |
|------|------|
| 400  | Validation failed — missing or invalid field |
| 401  | Missing or expired Bearer token |
| 403  | Approval token doesn't belong to the caller |
| 404  | Resource not found |
| 405  | HTTP method not allowed on this path |
| 409  | Duplicate, or approval token already used, or document already in a terminal state |
| 410  | Approval token expired (72h TTL) |
| 500  | Internal server error |

---

## Notes

- **Scope**: Sales Order, Purchase Order, Purchase Invoice, Purchase Receive, Finance Transaction, and Finance Payment (Purchase Invoice/Purchase Receive added after the original Phase 1 rollout — see `v2/docs/migrations/v29_purchase_invoice_receive_notification_permissions.md`). Warehouse Adjustment has no approval workflow in this codebase yet, so it has no notification hook either — see `v2/docs/migrations/v23_notification_schema.md`.
- **WhatsApp sends are synchronous**, not queued — same precedent as `auth/send-otp.php`/`auth/forgot-password.php`. A WhatsApp delivery failure never fails the triggering request (create/approve/reject); check `notification_delivery.status` for the actual outcome per channel.
- **Real-time push is via WebSocket**, not polling: connect to `ws(s)://.../notification/stream` (served by the standalone daemon `v2/notification/ws-server.php`, not through this HTTP router), send `{"type":"auth","token":"<the same bearer JWT>"}` as the first message, then listen for `notification:new` and `notification:unread_count` events. Call `GET /api/v2/notification/unread-count` once on initial load and again immediately after any reconnect, to cover events that happened while disconnected.
- **Recipient resolution is permission-key driven, not role-name driven** (same convention as the rest of v2, see `dashboard.md`). Sales Order/Purchase Order/Purchase Invoice/Purchase Receive approvers are whoever holds `notification.sales_order.approver`/`notification.purchase_order.approver`/`notification.purchase_invoice.approver`/`notification.purchase_receive.approver` respectively; Finance approvers are whoever holds the existing `keuangan.approve_owner`/`keuangan.approve_treasury` keys — there is no separate Finance-approver configuration endpoint. **No role currently holds any of the four `notification.*.approver` keys** — until one is granted, that module's `approval_pending` notifications have zero recipients (the in-app row still gets created for record-keeping purposes but nobody receives it).
- **The daily digest** (`v2/notification/digest.php`) is a CLI/cron script, not an HTTP endpoint under this router — it inserts its own `digest_daily` notification rows (visible via `GET /api/v2/notification`) and is documented in `v2/docs/migrations/v23_notification_schema.md`, not here.
- Approval tokens are single-use, scoped to one user + one document, and expire 72 hours after creation. A fresh token is minted for every `approval_pending` recipient at submit time (Sales/Purchase Order, Purchase Invoice, Purchase Receive) or at each new pending state (Finance's "menunggu approval ke-2" reminder); the daily digest always mints its own fresh tokens too, never reusing a real-time one.
