# /standards

Review a PHP file against the project's code standards and — when all checks pass — create or update the API documentation for that module.

---

## Step 1 — Read the standards

Read `.claude/CODE_STANDARDS.md` in full before doing anything else.

---

## Step 2 — Identify the target file

- If an argument was given (e.g. `/standards customers/index.php`), use that path.
- Otherwise use the most recently created or edited PHP module file.
- Derive the **module name** from the file path:
  - `customers/index.php` → module = `customers`
  - `policies/coverages.php` → module = `policies`, sub-resource = `coverages`

---

## Step 3 — Run the standards checklist

Check the file against every item below. Mark each **PASS**, **FAIL**, or **WARN**.

### Structure & auth
- [ ] `require_once __DIR__ . '/../general.php'` at top
- [ ] Functions defined before the dispatch block
- [ ] `requireAuth()` called once in dispatch, never inside functions
- [ ] `$company_id` sourced from JWT (`$authUser['company_id']`), never from `$input`
- [ ] `$username` sourced from `$authUser['sub']` with fallback to `$authUser['user_id']`
- [ ] Dispatch wrapped in `try/catch (Exception $e)`

### SQL
- [ ] All tables schema-qualified (`APP_SCHEMA .` or `CORE_SCHEMA .`)
- [ ] No default DB — no bare table names anywhere
- [ ] Dynamic UPDATE uses `$updates[]` array + `implode(', ', $updates)`
- [ ] Dynamic WHERE starts with `"company_id = '$company_id'"` as the mandatory first condition
- [ ] Single-record lookups have `LIMIT 1`

### Response
- [ ] Every `jsonResponse()` uses the exact shape: `status_code`, `status_message`, `data`
- [ ] `return;` follows every `jsonResponse()` call inside a function
- [ ] Paginated list response includes `pagination` key with `total`, `page`, `limit`, `total_pages`

### IDs & timestamps
- [ ] App resource IDs: `'prefix_' . uniqid()` (e.g. `'pol_' . uniqid()`)
- [ ] User/company IDs: `generateUUID()`
- [ ] Timestamps: `$now = date('Y-m-d H:i:s')` assigned once, reused

### Validation order (inside each function)
- [ ] Required field check → 400 first
- [ ] Enum / format check → 400
- [ ] Existence check (before update/delete) → 404
- [ ] Duplicate check (before insert) → 409

### Variable naming
- [ ] All variables are `snake_case` — no camelCase, no abbreviations
- [ ] ID variables include entity prefix (`$policy_id`, not `$id` or `$polId`)
- [ ] Auth/dispatch names are exactly: `$authUser`, `$company_id`, `$username`, `$method`, `$action`, `$sub_action`
- [ ] Request body assigned to `$input`, query string to `$params` — not re-read from raw source
- [ ] SQL pipeline names used: `$where`, `$updates`, `$result`, `$count_result`, `$row`, `$data`, `$total`
- [ ] Nullable SQL values use `_sql` suffix (`$notes_sql = ... ? "'...'" : 'NULL'`)
- [ ] Booleans prefixed `is_`, `has_`, `can_` — DB booleans double-cast `(bool)(int)$row['col']`
- [ ] Arrays are plural (`$policies`), single records are singular (`$policy` or `$row`)
- [ ] DB column names and PHP variable names match exactly — no aliases
- [ ] `$val` used only within a single `isset()` block — never crosses block boundaries

### Function signatures
- [ ] `getAll*($conn, $company_id, $params)`
- [ ] `create*($conn, $input, $username, $company_id)`
- [ ] `getDetail*($conn, $entity_id, $company_id)`
- [ ] `update*($conn, $entity_id, $input, $username, $company_id)`
- [ ] `delete*($conn, $entity_id, $company_id)`
- [ ] `$conn` is always the first parameter in every function

### Code quality
- [ ] No unnecessary comments or docblocks
- [ ] No `$query` / `$res` / `$cnt` / `$rows` / `$flag` variable names

### Database schema changes
- [ ] If this task altered the DB schema (new columns/tables/enum values) or inserted rows into a shared lookup/permission table, a migration doc exists under `v2/docs/migrations/` covering every environment (dev + prod), per `.claude/CODE_STANDARDS.md` §17 — **FAIL** if the schema was changed but no doc was written
- [ ] Any historical-row backfill on a new column either derives from real source data or is explicitly left `NULL` with a stated reason — never a guessed default
- [ ] Any new permission key is not paired with a guessed role assignment — role/permission assignment is left as a checklist item, not silently decided

---

## Step 4 — Report findings

Group by severity:
- **FAIL** — violates the standard (must fix before proceeding)
- **WARN** — likely violation, review needed
- **PASS** — compliant

For every FAIL: show the offending line number and the correct pattern from the standard.

**If there are any FAILs, stop here.** Do not generate docs until the file is clean.

---

## Step 5 — Generate or update API documentation (all checks PASS only)

### 5a — Module doc: `docs/api/{module}.md`

Create the file if it doesn't exist. If it exists, replace the entire content (do not append — the file is always a full snapshot of the current module).

Use this exact template — fill every section from the actual code, never fabricate fields or responses:

```markdown
# {Module} API

> **Last updated:** YYYY-MM-DD HH:MM:SS WIB
> **Base URL:** `/api/v1/{module}`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v1/{module}` | List all {modules} (paginated) |
| POST   | `/api/v1/{module}` | Create a new {module} |
| GET    | `/api/v1/{module}/{id}` | Get {module} detail |
| PUT    | `/api/v1/{module}/{id}` | Update a {module} |
| DELETE | `/api/v1/{module}/{id}` | Delete a {module} |

_(Only include rows for endpoints that actually exist in the file.)_

---

### GET `/api/v1/{module}`

List all {modules} belonging to the authenticated company.

#### Query parameters

| Parameter | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| page      | int    | No       | 1       | Page number |
| limit     | int    | No       | 10      | Items per page (max 100) |
| search    | string | No       | —       | Full-text search on ... |
| {field}   | string | No       | —       | Filter by {field}: `value_a` \| `value_b` |

_(List only query params that actually exist in the code's WHERE-building logic.)_

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "{Modules} found",
  "data": {
    "data": [
      {
        "{module}_id": "prefix_64a1b2c3",
        "company_id": "uuid",
        "field_one": "value",
        "field_two": "value",
        "created_at": "2026-06-27 10:00:00",
        "updated_at": "2026-06-27 10:00:00"
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
  "status_message": "No {modules} found",
  "data": []
}
```

---

### POST `/api/v1/{module}`

Create a new {module}.

#### Request body (`application/json`)

| Field     | Type   | Required | Description |
|-----------|--------|----------|-------------|
| field_one | string | Yes      | ... |
| field_two | string | No       | ... |

_(Derive required/optional directly from the validation logic in `create*()`. List every field the function reads from `$input`.)_

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "{Module} created successfully",
  "data": {
    "{module}_id": "prefix_64a1b2c3"
  }
}
```

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "{field} is required",
  "data": []
}
```

#### Response `409 Conflict`

```json
{
  "status_code": 409,
  "status_message": "{Module} already exists",
  "data": []
}
```

---

### GET `/api/v1/{module}/{id}`

Get detail of a single {module}.

#### Path parameters

| Parameter   | Type   | Description |
|-------------|--------|-------------|
| {module}_id | string | The {module} ID (e.g. `prefix_64a1b2c3`) |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "{Module} found",
  "data": {
    "{module}_id": "prefix_64a1b2c3",
    "company_id": "uuid",
    "field_one": "value",
    "field_two": "value",
    "created_at": "2026-06-27 10:00:00",
    "updated_at": "2026-06-27 10:00:00"
  }
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "{Module} not found",
  "data": []
}
```

---

### PUT `/api/v1/{module}/{id}`

Update a {module}. Only send the fields you want to change.

#### Path parameters

| Parameter   | Type   | Description |
|-------------|--------|-------------|
| {module}_id | string | The {module} ID |

#### Request body (`application/json`)

| Field     | Type   | Required | Description |
|-----------|--------|----------|-------------|
| field_one | string | No       | ... |
| field_two | string | No       | ... |

_(At least one field must be provided. Derive updatable fields from the `$updates[]` logic in `update*()`. Mark any field with enum constraints.)_

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "{Module} updated successfully",
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

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "{Module} not found",
  "data": []
}
```

---

### DELETE `/api/v1/{module}/{id}`

Delete a {module}. This action is irreversible.

#### Path parameters

| Parameter   | Type   | Description |
|-------------|--------|-------------|
| {module}_id | string | The {module} ID |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "{Module} deleted successfully",
  "data": []
}
```

#### Response `404 Not Found`

```json
{
  "status_code": 404,
  "status_message": "{Module} not found",
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
| 409  | Duplicate — resource already exists |
| 500  | Internal server error |

---

## Notes

_(Add any business-logic notes here: field constraints, enum values, cascade rules, etc. Only include what a frontend developer needs to know to use the API correctly.)_
```

**Rules for filling the template:**
- Read the PHP source code for exact field names, validation rules, enum values, and response messages — never invent them.
- Use the actual ID prefix from the code (e.g. `pol_` not `prefix_`).
- Include only endpoints that exist in the dispatch block.
- For sub-actions (`PATCH /{id}/status`), add a new `###` section below the standard CRUD sections.
- Boolean fields: note they are returned as `true`/`false`.
- Nullable fields: note with `null \| string` in the Type column.
- If a field has enum constraints, list valid values explicitly in the Description column.

---

### 5b — Changelog: `docs/CHANGELOG.md`

If `docs/CHANGELOG.md` does not exist, create it with this header first:

```markdown
# API Changelog

All notable API changes are documented here in reverse-chronological order.
Dates and times are in **WIB (UTC+7)**.

Intended audience: frontend developers.

---
```

Then **prepend** a new entry at the top (below the header, above all previous entries) using this format:

```markdown
## [YYYY-MM-DD HH:MM:SS WIB] — {Module}

### {Added | Updated | Fixed | Removed}
- `{METHOD} /api/v1/{module}` — {one-sentence plain-English description of what changed or was added}
- `{METHOD} /api/v1/{module}/{id}` — ...

### Breaking changes
_(List only if a field was renamed, removed, or a response shape changed. Leave this section out if there are no breaking changes.)_

### Notes for frontend
_(Any migration steps, deprecated fields still in response, or things to watch for.)_

---
```

**Rules for the changelog entry:**
- Use the current date and time (today is shown in the system context).
- Write descriptions in plain English from the frontend's perspective — what they can now do, not what the backend changed internally.
- If updating an existing module (file already had a doc), list only what is **different** from the previous entry. Read the existing `docs/CHANGELOG.md` first to avoid repeating unchanged endpoints.
- If creating a module for the first time, use `### Added` and list all endpoints.
- "Breaking changes" means the frontend must update their code — renamed field, removed endpoint, changed status code, changed response shape.
