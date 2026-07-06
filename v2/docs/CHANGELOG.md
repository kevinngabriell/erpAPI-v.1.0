# API Changelog

All notable API changes are documented here in reverse-chronological order.
Dates and times are in **WIB (UTC+7)**.

Intended audience: frontend developers.

---

## [2026-07-06 14:00:00 WIB] — Aluria schema migration (new v2 modules)

### Added

All endpoints below require `Authorization: Bearer <access_token>`. Every ID is a UUID (not a prefixed string). List/detail/update queries on tenant-scoped resources are filtered by the company from your token automatically — you never send `company_id` yourself. Deletes are soft (`deleted_at`), not permanent.

**Global lookups** (shared across all companies, no `company_id` filtering):
- `region`, `origin`, `unit-of-measure`, `currency`, `ppn-type`, `purchase-status`, `purchase-type`, `sales-status`, `ship-via`, `payment-method`, `payment-term`, `finance-category`, `salary-category` — full CRUD at `/api/v2/{module}`.

**Tenant master data**:
- `account-code`, `bank-account`, `customer`, `supplier`, `product`, `document-center`, `document-watermark`, `company-setting-menu` (+ nested `details` sub-resource), `sales-target`, `warehouse-location` — full CRUD at `/api/v2/{module}`.

**Purchase flow**:
- `purchase-order` — full CRUD, nested `items` sub-resource CRUD, plus `PATCH /api/v2/purchase-order/{id}/approve` and `PATCH .../reject`.
- `purchase-receive`, `purchase-invoice` — full CRUD with items created atomically alongside the header.

**Sales flow**:
- `sales-order` — full CRUD, nested `items` sub-resource CRUD, plus `PATCH /api/v2/sales-order/{id}/approve` and `PATCH .../reject`.
- `sales-delivery`, `sales-invoice`, `sales-sppb`, `sales-profit` — full CRUD with items created atomically alongside the header.

**Finance**:
- `finance-transaction`, `finance-payment` — full CRUD.

**Warehouse**:
- `warehouse-lot` — full CRUD.
- `warehouse-transaction` — full CRUD, nested items created atomically alongside the header, `transaction_type` enum (`stock_in`, `stock_out`, `adjustment`, `transfer`).

**HR**:
- `salary-transaction` — full CRUD.

**Audit**:
- `audit-log` — read-only (`GET` list + `GET` detail). Query by `module` + `reference_id` to see the approval/reject/create/update/delete history for a given record, e.g. `GET /api/v2/audit-log?module=purchase_order&reference_id={id}`. This replaces the old separate `purchaseOrderHistory` / `salesOrderHistory` / `financeLog` tables — everything now lands in one place.

### Notes for frontend
- Full request/response shapes, field lists, and enum values for every module above are documented in their own `docs/api/{module}.md` file.
- Not every module writes to `audit_log` yet — currently only `purchase_order`, `sales_order`, `finance_transaction`, and `finance_payment` do. Check each module's own doc's Notes section for its current audit coverage.
- Header+items endpoints (purchase/sales orders, receives, invoices, deliveries, sppb, profit, warehouse transactions) create the header and all items atomically — if any item fails validation, nothing is saved.

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
