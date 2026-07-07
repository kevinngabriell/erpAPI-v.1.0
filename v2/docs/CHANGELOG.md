# API Changelog

All notable API changes are documented here in reverse-chronological order.
Dates and times are in **WIB (UTC+7)**.

Intended audience: frontend developers.

---

## [2026-07-07 09:00:00 WIB] — Dashboard (role-based rebuild)

### Changed
- `GET /api/v2/dashboard` — Replaced entirely. Now a single endpoint that returns only the widgets the caller's role has `dashboard.*` permission for (see `docs/api/dashboard.md` for the full widget catalog). Widget visibility is driven by `app_role_permission`, never by role name — a custom/generalist role sees the union of whatever `dashboard.*` permissions it holds.

### Breaking changes
- `/dashboard/overview`, `/dashboard/purchase-overview`, `/dashboard/top-*`, and `/dashboard/outstanding` (added 2026-07-06) no longer exist. Everything is now under the single `GET /api/v2/dashboard`, response shape `data.widgets.{widget_key}`.
- Some widget names/shapes changed from the old endpoints — e.g. `outstanding?type=piutang` is now the `ar_aging` widget with an added `bucket` field.

### Notes for frontend
- Call `GET /api/v2/account/my-permissions` to know in advance which `dashboard.*` widgets a role will get back, so the UI can render the right layout without guessing from an empty response.
- A handful of `dashboard.*` permission keys from the original design have no widget yet — granting them does nothing. See "Unavailable widgets" in `docs/api/dashboard.md` for the list and why (missing department concept, no reorder-point field, no warehouse adjustment-approval workflow, etc.).
- `cash_position`/`bank_balances` assume `finance_transaction.amount` is signed (positive = inflow, negative = outflow) since there's no separate direction flag in the schema — flag it if that assumption is wrong.

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

## [2026-07-06 20:00:00 WIB] — Dashboard / reporting endpoints

### Added
- `GET /api/v2/dashboard/overview` — Yearly totals, monthly sales/purchase charts, top products, order count by country, outstanding AR/AP totals.
- `GET /api/v2/dashboard/purchase-overview` — Current month's PO counts by status.
- `GET /api/v2/dashboard/top-sales-orders`, `/top-sppb`, `/top-sales-invoices`, `/top-delivery-orders`, `/top-profit` — Latest records for each sales document type.
- `GET /api/v2/dashboard/top-purchase-receives`, `/top-purchase-invoices`, `/top-purchase-import`, `/top-purchase-local` — Latest purchase-side records; the import/local widgets now return every item on the order, not just the first.
- `GET /api/v2/dashboard/outstanding` — AR (piutang) / AP (hutang) report, filterable by `type`, `month`, `year`.

### Breaking changes
- These replace the old single-tenant v1 endpoints (`master/getoveralldashboard.php`, `purchase/getoverallpurchase.php`, `finance/alloutstandingcustomer.php`, `finance/getoutstandingpayments.php`, `sales/gettop*.php`, `purchase/gettop*.php`). URLs, response shape (`status_code`/`status_message`/`data` instead of `StatusCode`/`Status`/`Data`), and status-matching logic have all changed — see `docs/api/dashboard.md`.
- Outstanding-supplier/customer totals are now `due_amount - paid_amount` (previously summed `due_amount` alone, which double-counted paid invoices).

### Notes for frontend
- Every widget is now scoped to your company automatically — no `company_id` param needed or accepted.
- AR/AP amounts are shown in each invoice's original currency; no cross-currency conversion is applied.
- Full field lists, query params, and example responses are in `docs/api/dashboard.md`.

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
