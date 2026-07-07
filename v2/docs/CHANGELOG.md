# API Changelog

All notable API changes are documented here in reverse-chronological order.
Dates and times are in **WIB (UTC+7)**.

Intended audience: frontend developers.

---

## [2026-07-07 08:11:31 WIB] — Account (Auth)

### Added
- `GET /api/v2/account/my-permissions` — New endpoint. Requires `Authorization: Bearer <token>`. Returns the permission list granted to the caller's role: a flat `permissions` array of `permission_key` strings, plus a `modules`-grouped shape (module → list of `{ permission_key, label, description }`) for building an access-control/permissions screen.

### Changed
- `POST /api/v2/account/login` — The `user` object in the response now includes `position_name` (resolved via `app_position`), alongside the existing `position_id`. `position_name` is `null` for users who don't have a position assigned yet.

### Notes for frontend
- `my-permissions` reflects the **role**, not the individual user — all users sharing an `app_role_id` get the same permission list.
- If a user has no `app_role_id` (shouldn't normally happen post-login), `my-permissions` returns `400`.
- The JWT itself is unchanged — permissions are still fetched separately, never embedded in the token.

---

## [2026-07-03] — Account (Auth)

### Changed
- `POST /api/v2/account/register` — Removed the `position_id` field from the registration request. A new registrant no longer self-selects an internal position at signup (they have no way to look up valid IDs); every non-first user now gets the same fallback role and lands in `pending` status. A super admin assigns `position_id` (and role, if needed) as part of the existing approval step.

### Notes for frontend
- Registration forms should drop the position picker. The response payload for register no longer includes `position_id` / `position_name`.

---

## [2026-06-27] — Account (Auth)

### Added
- `POST /api/v2/account/login` — Authenticate with email and password; returns a signed JWT (8-hour expiry) plus user and company metadata.
- `POST /api/v2/account/register` — Register a new user into an existing company using a company code and OTP. First user of a company receives the Business Owner role and is immediately active; all subsequent users start as `pending` and require admin approval.

### Notes for frontend
- The JWT payload contains `user_id`, `email`, `first_name`, `last_name`, `app_role_id`, `company_id`, `position_id`, `days_remaining`, and `language`. The permission map is **not** embedded — fetch it separately after login.
- All error responses include an optional `error_code` field (e.g. `AUTH_001`, `REG_004`) to drive localised error messages without string-matching `status_message`.
- Login and registration are rate-limited per email and per IP. Rate limit details are in `docs/api/account.md`.

---
