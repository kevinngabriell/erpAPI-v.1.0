# Dashboard API

> **Last updated:** 2026-07-05 WIB
> **Base URL:** `/api/v2/dashboard`
> **Auth:** All endpoints require `Authorization: Bearer <token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v2/dashboard` | Get the authenticated user's dashboard: profile, company, permission map, and role-gated widgets |

---

### GET `/api/v2/dashboard`

Returns everything the dashboard screen needs in one call: who's logged in, their company, their full permission map, and a `widgets` object containing only the sections this role is permitted to see.

**This is a skeleton.** No business-data widgets are wired up yet (sales, purchase, warehouse, finance haven't been migrated to the multi-tenant v2 schema). Each widget key currently returns `null` as a placeholder — it appears in the response only if the caller's role has the matching permission, and is otherwise omitted entirely so an unauthorized role can't tell the section exists.

#### Widget → permission key

| Widget key | Required `permission_key` | Status |
|---|---|---|
| `overview` | `dashboard.overview.view` | Not implemented — `null` placeholder |
| `sales` | `dashboard.sales.view` | Not implemented — `null` placeholder |
| `purchase` | `dashboard.purchase.view` | Not implemented — `null` placeholder |
| `warehouse` | `dashboard.warehouse.view` | Not implemented — `null` placeholder |
| `finance` | `dashboard.finance.view` | Not implemented — `null` placeholder |

These `permission_key` values must exist in `app_permission` and be assigned to the relevant roles in the Movira admin panel before a role will see that widget key at all.

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Dashboard loaded successfully",
  "data": {
    "user": {
      "first_name": "Budi",
      "last_name": "Santoso",
      "position_id": "pos_admin_sales",
      "position_name": "Admin Sales",
      "role_name": "Admin Sales"
    },
    "company": {
      "company_id": "cmp_xyz789",
      "company_name": "PT Maju Bersama",
      "days_remaining": 23
    },
    "permissions": {
      "dashboard.sales.view": true
    },
    "widgets": {
      "sales": null
    }
  }
}
```

A role with none of the `dashboard.*` permissions gets `"widgets": {}`.

#### Failure responses

| Code | `error_code` | `status_message` | When |
|---|---|---|---|
| 401 | — | `No token provided` / `Invalid token` / `Session expired...` | Missing, malformed, or expired Bearer token |
| 401 | — | `Invalid session. Please log in again.` | JWT is missing `user_id`, `app_role_id`, or `company_id` |
| 404 | — | `User not found.` | User was deleted after the token was issued |
| 405 | — | `Method not allowed` | Non-GET request |
| 500 | — | `An unexpected error occurred. Please try again.` | Server error |

---

## Notes

- **This endpoint will be extended, not replaced**, as each business domain migrates from the legacy single-tenant schema to `APP_SCHEMA`. When a domain migrates, its widget key starts returning real data instead of `null` — the shape of `widgets.{key}` for a given domain will be documented here at that time.
- **Frontend should treat an absent widget key as "not visible to this role"**, not as an error — do not assume every widget key is always present in `data.widgets`.
- `permissions` here is the same flat map returned by `GET /api/v2/account/my-permissions` — this endpoint saves a second round trip by including it inline.
