# Permissions API

> **Last updated:** 2026-07-11 00:00:00 WIB
> **Base URL:** `/api/v2/permissions`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v2/permissions/roles?keys=a,b,c` | Get the roles that grant each of a batch of permission keys |
| GET | `/api/v2/permissions/{permission_key}/roles` | Get the roles that grant a single permission key |
 
---

### GET `/api/v2/permissions/{permission_key}/roles`

Returns the role(s) that currently grant a given `permission_key`. Intended for denial dialogs — e.g. when a click-time permission gate blocks an action, the frontend can say *"Requires the Admin Sales or Business Owner role"* instead of a generic "contact your administrator."

#### Path parameters

| Parameter | Type | Description |
|---|---|---|
| `permission_key` | string | The permission key to look up, e.g. `sales.so.create` |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Roles retrieved successfully",
  "data": {
    "permission_key": "sales.so.create",
    "label": "Create Sales Order",
    "roles": ["Business Owner", "Admin Sales"]
  }
}
```

`roles` is an empty array if the permission exists but no role currently grants it.

#### Failure responses

| Code | `status_message` | When |
|---|---|---|
| 401 | `Authorization header missing or malformed` \| `Invalid token signature` \| `Token expired` | Missing, malformed, or expired Bearer token |
| 404 | `Permission not found` | `permission_key` does not exist in `app_permission` |
| 405 | `Method not allowed` | Any method other than `GET` |
| 500 | `An unexpected error occurred. Please try again.` | Server error |

---

### GET `/api/v2/permissions/roles?keys=a,b,c`

Batch variant — resolves several `permission_key`s in a single round-trip. Unknown keys are silently dropped from the response rather than failing the whole request.

#### Query parameters

| Parameter | Type | Required | Description |
|---|---|---|---|
| `keys` | string | Yes | Comma-separated list of `permission_key`s. Deduplicated automatically. Max 50 keys per request. |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Roles retrieved successfully",
  "data": {
    "permissions": [
      {
        "permission_key": "sales.so.create",
        "label": "Create Sales Order",
        "roles": ["Business Owner", "Admin Sales"]
      },
      {
        "permission_key": "sales.so.approve",
        "label": "Approve Sales Order",
        "roles": ["Business Owner"]
      }
    ]
  }
}
```

If none of the requested keys exist, `data.permissions` is an empty array (still `200`, not `404`).

#### Failure responses

| Code | `status_message` | When |
|---|---|---|
| 400 | `The keys query parameter is required.` | `keys` missing or empty after trimming |
| 400 | `A maximum of 50 keys can be requested at once.` | More than 50 keys supplied |
| 401 | `Authorization header missing or malformed` \| `Invalid token signature` \| `Token expired` | Missing, malformed, or expired Bearer token |
| 405 | `Method not allowed` | Any method other than `GET` |
| 500 | `An unexpected error occurred. Please try again.` | Server error |

---

## Notes

- **Auth scope is deliberately broad.** Any authenticated user can resolve role-permission mappings — role *names* aren't sensitive, and this mirrors `GET /account/my-permissions`, which is also open to any authenticated caller regardless of their own role.
- **Not company-scoped.** `app_permission`, `app_role`, and `app_role_permission` are global catalog tables (`CORE_SCHEMA`), so results are identical across every company/tenant.
- **Always live, never cached.** Every call reads `app_role_permission` directly, so role/permission assignment changes are reflected immediately.
- **Typical frontend usage:** `usePermissionGate` fetches the role list for the denied `permission_key` on click and interpolates it into the denial dialog message.
