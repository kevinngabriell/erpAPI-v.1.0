# ERP API — Claude Instructions

**Every PHP file you generate in this project MUST follow the code standards in [.claude/CODE_STANDARDS.md](.claude/CODE_STANDARDS.md) exactly.** Read it before writing any PHP code.

## Non-negotiable rules (summary — full spec in CODE_STANDARDS.md)

### Structure
- One directory per module (`customers/`, `policies/`). Module logic lives in `{module}/index.php`.
- Sub-resources: separate files inside the parent dir (`policies/coverages.php`).
- No `src/`, no `app/`, no framework directories.

### Every module file must
1. Start with `require_once __DIR__ . '/../general.php';`
2. Define all functions first, dispatch block at the bottom
3. Call `requireAuth()` once at the top of dispatch — never inside functions
4. Extract `$company_id` from JWT (`$authUser['company_id']`) — never from request body
5. Wrap the entire dispatch in `try { ... } catch (Exception $e) { jsonResponse(500, ...) }`

### Response — always this exact shape, no exceptions
```json
{ "status_code": 200, "status_message": "...", "data": {} }
```
Use `jsonResponse($code, $message, $data)` — it calls `exit`, so add `return;` after calls inside functions.

### SQL
- Always qualify tables: `APP_SCHEMA . ".table_name"` or `CORE_SCHEMA . ".table_name"`
- Never select a default DB in `new mysqli()` — no bare table names
- Dynamic UPDATE: build `$updates[]` array, `implode(', ', $updates)` into SET clause
- Dynamic WHERE: start with `"company_id = '$company_id'"`, append filters with `AND`
- Always `LIMIT 1` on single-record lookups

### IDs
- App resources: `'prefix_' . uniqid()` (e.g. `'pol_' . uniqid()`)
- Users / companies: `generateUUID()`

### Naming — variables
- All variables: `snake_case` — no camelCase, no abbreviations
- IDs: always include entity prefix — `$policy_id`, `$customer_id`, never `$id` or `$polId`
- Auth/dispatch fixed names — never rename: `$authUser`, `$company_id`, `$username`, `$method`, `$action`, `$sub_action`
- Input: `$input` (JSON body), `$params` ($_GET), never re-read raw input
- SQL pipeline fixed names: `$where`, `$updates`, `$query`, `$result`, `$count_result`, `$row`, `$data`, `$total`, `$now`
- Nullable SQL values: use `_sql` suffix — `$notes_sql = isset(...) ? "'...'" : 'NULL'`
- Booleans: prefix `is_`, `has_`, `can_` — always double-cast DB booleans: `(bool)(int)$row['col']`
- Collections: plural (`$policies`), single records: singular (`$policy`) or `$row`
- Validation temps: `$required`, `$updates`, `$check`, `$dup`, `$val` (short scope only)
- DB column → PHP variable must match exactly — no aliases

### Naming — functions
- `getAll{Things}($conn, $company_id, $params)` — paginated list
- `create{Thing}($conn, $input, $username, $company_id)` — insert
- `getDetail{Thing}($conn, $entity_id, $company_id)` — single record
- `update{Thing}($conn, $entity_id, $input, $username, $company_id)` — update
- `delete{Thing}($conn, $entity_id, $company_id)` — delete
- `$conn` is always the first parameter in every function

### Naming — timestamps
- Always `$now = date('Y-m-d H:i:s');` — assign once, reuse

### Validation order (inside each function)
1. Required field check → 400
2. Enum / format check → 400
3. Existence check (before update/delete) → 404
4. Duplicate check (before insert) → 409

### No comments unless the WHY is non-obvious. No docblocks. No trailing summaries.

---

## API Documentation

- Per-module docs live in `docs/api/{module}.md` — one file per module, always a full snapshot (never append-only).
- Changelog lives in `docs/CHANGELOG.md` — entries prepended in reverse-chronological order, dates in WIB (UTC+7).
- Never write docs manually. Run `/standards {file}` — docs are generated automatically only when the file passes all standards checks.
- Do not fabricate fields, response shapes, or parameters in the docs — derive everything from the actual PHP source code.
