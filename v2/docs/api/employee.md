# Employee API

> **Last updated:** 2026-10-21 22:00:00 WIB
> **Base URL:** `/api/v2/employee`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

Read-only employee directory for the caller's company. Employees are the `CORE_SCHEMA.app_user` rows with
`app_id = APP_ID` (this app) and `company_id` = the caller's company. Role (`role_name`, "Peran") comes from
`app_role`, position (`position_name`, "Jabatan") from `app_position`, and the HR fields from
`APP_SCHEMA.employee_profile` (`NULL` until set).

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v2/employee` | List employees (paginated) with status/role summary |
| GET | `/api/v2/employee/{user_id}` | Employee detail |

---

### GET `/api/v2/employee`

#### Query parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| page | int | No | 1 | Page number |
| limit | int | No | 15 | Items per page (max 15) |
| search | string | No | — | Matches first/last/full name, email, username, role name, position name, employee code, department |
| app_role_id | string | No | — | Filter by role |
| account_status | string | No | — | One of `pending`, `waiting_payment`, `verified`, `suspended`, `disabled`, `rejected`. Invalid values are ignored |

Sorted by `first_name`, `last_name`. `summary` always covers the whole company (filters are not applied to it).

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Employees found",
  "data": {
    "data": [
      {
        "user_id": "usr_6a4a366caf628",
        "username": "kevin@example.com",
        "first_name": "Kevin",
        "last_name": "Florentino",
        "full_name": "Kevin Florentino",
        "email": "kevin@example.com",
        "phone_number": "081100000000",
        "account_status": "verified",
        "app_role_id": "role0a1152b01417134d",
        "role_name": "Business Owner",
        "position_id": null,
        "position_name": null,
        "employee_code": null,
        "department": null,
        "employment_type": null,
        "join_date": null,
        "last_login_at": null,
        "created_at": "2026-07-05 17:48:12"
      }
    ],
    "pagination": { "total": 2, "page": 1, "limit": 15, "total_pages": 1 },
    "summary": {
      "total": 2,
      "by_status": { "pending": 1, "waiting_payment": 0, "verified": 1, "suspended": 0, "disabled": 0, "rejected": 0 },
      "by_role": [ { "app_role_id": "role0a1152b01417134d", "role_name": "Business Owner", "total": 2 } ]
    }
  }
}
```

An empty result returns `200` with `data: []`.

---

### GET `/api/v2/employee/{user_id}`

Same fields as a list row plus `gender`, `work_location`, `supervisor_id` and `supervisor_name`.

#### Response `404 Not Found`

```json
{ "status_code": 404, "status_message": "Employee not found", "data": [] }
```

Returned when the user does not exist, belongs to another company, or to another app.

#### Response `405 Method Not Allowed`

Any method other than `GET`.
