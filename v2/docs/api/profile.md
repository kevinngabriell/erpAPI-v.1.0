# Profile API

> **Last updated:** 2026-08-13 12:55:00 WIB
> **Base URL:** `/api/v2/profile`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

Self-service only — every endpoint always acts on the logged-in user's own record (resolved from the JWT). There is no `{id}` path parameter and no way to view or edit another user's profile through this module.

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/profile` | Get the logged-in user's own profile |
| PUT    | `/api/v2/profile` | Update the logged-in user's own profile (personal fields only) |
| PATCH  | `/api/v2/profile` | Same as `PUT` |

---

### GET `/api/v2/profile`

Returns the caller's combined identity + employment profile: core account fields (from `app_user`) merged with the extended HR profile fields (from `employee_profile`, which may not exist yet for this user — those fields are `null` until the first successful `PUT`).

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Profile found",
  "data": {
    "app_user_id": "usr_6a4a366caf628",
    "first_name": "kevin",
    "last_name": "florentino",
    "email": "kevingabriel786@gmail.com",
    "phone_number": "081144113071",
    "position_id": null,
    "position_name": null,
    "account_status": "verified",
    "employee_profile_id": "cd5b4d4b-9dff-40ea-b536-47712e20cc66",
    "gender": "male",
    "birth_date": "1990-05-12",
    "address": "Jl. Mawar No. 5, Jakarta",
    "emergency_contact_name": "Siti Aminah",
    "emergency_contact_phone": "081234567890",
    "employee_code": null,
    "department": null,
    "employment_type": null,
    "join_date": null,
    "supervisor_id": null,
    "supervisor_name": null,
    "work_location": null,
    "created_at": "2026-08-13 12:48:39",
    "updated_at": "2026-08-13 12:48:39",
    "is_active": true
  }
}
```

`employee_profile_id`, `gender`, `birth_date`, `address`, `emergency_contact_name`, `emergency_contact_phone`, `employee_code`, `department`, `employment_type`, `join_date`, `supervisor_id`, `supervisor_name`, `work_location`, `created_at`, `updated_at` are all `null` until the user's `employee_profile` row is created by a first `PUT`/`PATCH`. `created_at`/`updated_at` here refer to the `employee_profile` row, not the account itself. `is_active` is derived from `account_status === 'verified'` — not a stored column.

#### Response `404 Not Found`

Returned only if the JWT's `user_id`/`company_id` no longer resolves to a live `app_user` row (e.g. account moved companies or was removed after the token was issued).

```json
{
  "status_code": 404,
  "status_message": "Profile not found",
  "data": []
}
```

---

### PUT `/api/v2/profile`

Update the caller's own profile. Only send the fields you want to change. Sending an empty string for `address`, `emergency_contact_name`, `emergency_contact_phone`, `gender`, or `birth_date` clears that field back to `null` rather than rejecting it.

**Employment fields are not editable here** — `employee_code`, `department`, `employment_type`, `join_date`, `supervisor_id`, and `work_location` are silently ignored if sent (not written, not an error). They are HR-admin-managed and will be editable through a separate employee-management module once it exists.

The `employee_profile` row is created automatically on the first `PUT` that includes at least one `employee_profile` field — no separate "create" step.

#### Request body (`application/json`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| first_name | string | No | 2–100 characters, letters/spaces/hyphens only. Cannot be cleared to empty. |
| last_name | string | No | 2–100 characters, letters/spaces/hyphens only. Cannot be cleared to empty. |
| phone_number | string | No | Indonesian format: starts with `08` or `+62`, 8–13 digits after. Cannot be cleared to empty. |
| gender | string \| null | No | `male` \| `female`. Empty string clears to `null`. |
| birth_date | string \| null | No | `YYYY-MM-DD`. Empty string clears to `null`. |
| address | string \| null | No | Free text. Empty string clears to `null`. |
| emergency_contact_name | string \| null | No | 2–200 characters, letters/spaces/hyphens only. Empty string clears to `null`. |
| emergency_contact_phone | string \| null | No | Indonesian format: starts with `08` or `+62`, 8–13 digits after. Empty string clears to `null`. |

At least one recognized field (from the table above) must be provided.

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Profile updated successfully",
  "data": []
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "gender must be male or female",
  "data": []
}
```

```json
{
  "status_code": 400,
  "status_message": "No fields provided for update",
  "data": []
}
```

Also returned (with the corresponding message) for invalid `first_name`/`last_name` (format), invalid `phone_number`/`emergency_contact_phone` (format), invalid `birth_date` (format), and invalid `emergency_contact_name` (format). A body containing only unrecognized/employment fields (e.g. `employee_code`, `department`) also produces `No fields provided for update`, since those fields are never recognized by this endpoint.

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "Profile not found",
  "data": []
}
```

---

## Error responses (all endpoints)

| Code | When |
|------|------|
| 400  | Validation failed — invalid or empty-when-not-allowed field, or nothing recognized to update |
| 401  | Missing or expired Bearer token |
| 404  | Caller's own `app_user` record could not be resolved |
| 405  | HTTP method not allowed on this path |
| 500  | Internal server error |

---

## Notes

- This module reads and writes two tables: `CORE_SCHEMA.app_user` (`first_name`, `last_name`, `phone_number`) and `APP_SCHEMA.employee_profile` (everything else except `email`, `position_id`/`position_name`, `account_status`, which are read-only here). A `PUT` touching fields on both tables is wrapped in a single transaction — either both writes land or neither does.
- `email` and `position_id`/`position_name` are returned but not editable through this endpoint — no code path accepts them.
- `employee_code`, `department`, `employment_type`, `join_date`, `supervisor_id`, and `work_location` exist in the schema and are returned by `GET`, but there is currently no endpoint anywhere that writes them — they will stay `null` for every user until a future HR-admin employee module is built. See `v2/docs/migrations/v37_employee_profile_schema.md`.
- `supervisor_id` is a plain reference to `CORE_SCHEMA.app_user.user_id` — no foreign key enforced at the DB level (consistent with the rest of this codebase's cross-schema references).
- `employee_profile` is soft-deletable (`deleted_at`) at the schema level, but no endpoint in this module sets it — there is currently no way to delete a profile through the API.
