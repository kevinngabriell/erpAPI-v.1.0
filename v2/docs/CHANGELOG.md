# API Changelog

All notable API changes are documented here in reverse-chronological order.
Dates and times are in **WIB (UTC+7)**.

Intended audience: frontend developers.

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
