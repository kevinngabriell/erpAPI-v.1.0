# API Changelog

All notable API changes are documented here in reverse-chronological order.
Dates and times are in **WIB (UTC+7)**.

Intended audience: frontend developers.

---

## [2026-07-11 19:44:37 WIB] — Accounting report endpoints added (Laporan Keuangan)

### Added
- `GET /api/v2/sales-report` — Omset/Penjualan report: total omset, monthly revenue trend, top 10 products, top 10 customers for a `date_from`/`date_to` range.
- `GET /api/v2/ar-ap-report` — Piutang & Hutang report: paginated, searchable, filterable (`type`, `bucket`) list of outstanding AR/AP invoices with aging buckets, plus bucket-total summaries.
- `GET /api/v2/profit-loss` — Laporan Laba Rugi (P&L) for a `date_from`/`date_to` range, with revenue/expense broken down per account and net profit.
- `GET /api/v2/balance-sheet` — Neraca (balance sheet) as of a given `as_of_date`, with asset/liability/equity broken down per account.
- `GET /api/v2/general-ledger` — Buku Besar: paginated per-account summary (opening balance, period movement, closing balance) for a date range.
- `GET /api/v2/general-ledger/{account_code_id}` — transaction-level ledger detail for one account, with running balance.
- `GET /api/v2/cogs-report` — HPP (COGS) & gross profit per product for a date range, paginated, with summary totals.

### Notes for frontend
- These are new, dedicated report endpoints behind the "Laporan Keuangan / Accounting" dashboard cards (Omset, Piutang & Hutang, Laba Rugi, Neraca, Buku Besar, HPP). They are separate from the existing lightweight, non-filterable dashboard widgets (`pnl_snapshot`, `neraca_snapshot`, `buku_besar_summary`, `ar_aging`/`ap_aging` on `GET /api/v2/dashboard`), which are unchanged and still power the dashboard home screen.
- All six are `GET`-only reporting endpoints — no create/update/delete.
- "Fixed Assets & Penyusutan" and "FX Revaluation" (still "Coming Soon" in the UI) are not part of this change — no backing schema exists for them yet.

---

## [2026-07-11 18:52:00 WIB] — Sales module list/detail responses now include resolved customer & sales-order names

### Added
- `GET /api/v2/sales-delivery` and `GET /api/v2/sales-delivery/{id}` — responses now include `customer_name` and `so_display_number` alongside `customer_id` and `sales_order_id`.
- `GET /api/v2/sales-invoice` and `GET /api/v2/sales-invoice/{id}` — responses now include `customer_name`, `so_display_number`, and `do_display_number` alongside `customer_id`, `sales_order_id`, and `sales_delivery_id`. `do_display_number` is `null` whenever `sales_delivery_id` is `null` (it's an optional link).
- `GET /api/v2/sales-profit` and `GET /api/v2/sales-profit/{id}` — responses now include `so_display_number` and `customer_name` alongside `sales_order_id` and `customer_id`.
- `GET /api/v2/sales-sppb` and `GET /api/v2/sales-sppb/{id}` — responses now include `customer_name` and `so_display_number` alongside `customer_id` and `sales_order_id`.

### Notes for frontend
- Same pattern already shipped for `sales-order` (`customer_name`, `ppn_name`, `status_name`) — this extends it to the rest of the sales module so none of these list/detail views need a follow-up lookup call just to show the customer or linked sales order's display number.
- The `_id` fields are unchanged and still required for `PUT` payloads and existing filters (`customer_id`, `sales_order_id`).
- Any of the new name fields can be `null` if the referenced record was soft-deleted after the row was created — the `_id` will still be present, just unresolved.
- These joins are `LEFT JOIN`s reused from the existing pagination-count query, so there's no added query cost per row.

---

## [2026-07-11 18:49:02 WIB] — `created_by`/`updated_by`/`approved_by` now resolve to the acting user's full name (all v2 modules)

### Changed
Every v2 module's list, detail, and nested-item endpoints now return `created_by`, `updated_by` (and `approved_by`, where it exists) as the acting user's resolved full name (`"First Last"`) instead of the raw user-id UUID. There is no new field — the existing keys just carry a different value now. This applies to every `GET` endpoint across:
- **Sales:** `sales-order` (+ its `approved_by`), `sales-order` items, `sales-delivery`, `sales-invoice`, `sales-profit`, `sales-sppb`
- **Purchase:** `purchase-order` (+ its `approved_by`), `purchase-order` items, `purchase-invoice`, `purchase-receive`
- **Warehouse:** `warehouse-lot`, `warehouse-transaction`
- **Finance:** `finance-payment`, `finance-transaction`
- **HR:** `salary-transaction`
- **Master data:** `account-code`, `bank-account`, `company-setting-menu` (+ its `details` sub-resource), `currency`, `customer`, `document-center`, `document-watermark`, `finance-category`, `origin`, `payment-method`, `payment-term`, `ppn-type`, `product`, `purchase-status`, `purchase-type`, `region`, `salary-category`, `sales-status`, `sales-target`, `ship-via`, `supplier`, `unit-of-measure`, `warehouse-location`

### Notes for frontend
- If you were previously showing the raw `created_by`/`updated_by` UUID in an audit trail or "last edited by" label, you can now render the value directly — no separate user lookup needed.
- `updated_by`/`approved_by` are `null` until the record has actually been updated/approved, same as before.
- `created_by` can be `null` in the rare case the creating user has since been deleted from the user directory — previously it would have shown their (still-valid) UUID even after deletion.
- This is resolved via a `LEFT JOIN` against the core user directory, reusing the existing query — no added round trip.

---

## [2026-07-11 18:06:15 WIB] — Sales Order list/detail now include resolved names

### Added
- `GET /api/v2/sales-order` — response rows now include `customer_name`, `ppn_name`, and `status_name` alongside their existing `customer_id`, `ppn_type_id`, and `status_id` fields.
- `GET /api/v2/sales-order/{id}` — same three fields (`customer_name`, `ppn_name`, `status_name`) added to the detail response.

### Notes for frontend
- You no longer need a separate `GET /api/v2/customers/{id}`, `GET /api/v2/ppn-type/{id}`, or `GET /api/v2/sales-status/{id}` call just to display the customer, PPN type, or status name in a sales order list or detail view — they're now returned inline.
- The `_id` fields are unchanged and still required for `PUT` payloads and for the `status_id`/`customer_id` list filters.
- Any of the three name fields can be `null` if the referenced master-data row was soft-deleted after the sales order was created — the `_id` will still be present, just unresolved.

---

## [2026-07-11 18:00:00 WIB] — Sales Order `status_id` is now server-managed

### Changed
- `POST /api/v2/sales-order` — **`status_id` removed from the request body.** The backend now assigns the `"Draft"` sales status automatically. Sending `status_id` in the payload has no effect (it's ignored).
- `PUT /api/v2/sales-order/{id}` — **`status_id` removed from the update payload.** Status can no longer be changed through general update.
- `PATCH /api/v2/sales-order/{id}/approve` — **`status_id` removed from the request body.** The backend now assigns the `"Approved"` sales status automatically. Body only needs the optional `notes` field.
- `PATCH /api/v2/sales-order/{id}/reject` — **`status_id` removed from the request body.** The backend now assigns the `"Rejected"` sales status automatically. Body only needs the optional `notes` field.

### Added
- `PATCH /api/v2/sales-order/{id}/revise` — new endpoint. Moves a `Rejected` sales order back to `Draft` so it can be edited (via `PUT`) and resubmitted for approval. Returns `400 Only rejected sales orders can be revised` if the order isn't currently `Rejected`. This replaces the old pattern of sending `status_id: <draft id>` on `PUT` to "un-reject" an order — `PUT` can no longer do that at all (see Breaking changes).

### Breaking changes
- `status_id` is no longer read from the request body on any sales-order endpoint. If your frontend currently sends `status_id` on create/update/approve/reject, it's now a no-op field — safe to leave in during transition, but should be removed.
- `PUT /api/v2/sales-order/{id}` can no longer change status at all (previously it silently accepted whatever `status_id` UUID was sent, without validating it existed). Use `PATCH .../approve`, `PATCH .../reject`, or `PATCH .../revise` instead.
- **If your revision flow does `PUT .../sales-order/{id}` with `status_id: <draft id>` to move a rejected order back to Draft (e.g. `SalesOrderDetail.js`'s `handleRevision`), that call will no longer change the status** — the order will keep showing as `Rejected` after the edit is saved. Split it into two calls: `PUT` to save the edited fields (unchanged, just drop `status_id`), then `PATCH .../revise` to move the order back to `Draft`. See "Notes for frontend" below.
- `POST /api/v2/sales-order` no longer requires `status_id` as a required field — it's simply not part of the request contract anymore.

### Notes for frontend
- **Root cause of this change:** `sales_status.id` is a `generateUUID()` value generated independently per environment — "Draft" in dev and "Draft" in production do **not** share the same `id`. The old design required the frontend to source and send that UUID (unlike `ppn_type_id`, which is a genuine user-facing dropdown choice), which meant any hardcoded/cached `status_id` broke or silently corrupted data across environments. Status on a sales order is a workflow state driven by which action the user takes (create → Draft, approve → Approved, reject → Rejected, revise → Draft), not a free-form user choice, so it now lives entirely server-side.
- **Required change:** stop sending `status_id` on `POST /api/v2/sales-order`, `PUT /api/v2/sales-order/{id}`, `PATCH .../approve`, and `PATCH .../reject`. Remove any status dropdown/picker used for these actions — there is nothing for the user to choose anymore.
- **Revision flow specifically:** update the code that currently does `PUT .../sales-order/{id}` with `status_id: draftStatusId` to (1) call `PUT .../sales-order/{id}` with the edited fields only (no `status_id`), then (2) call `PATCH .../sales-order/{id}/revise` to move the status back to `Draft`. If the UI only allows revision when the order is already `Rejected`, no extra guard is needed — the endpoint enforces that server-side and returns `400` otherwise.
- There is no "un-approve" action — an `Approved` order cannot be reverted to `Draft` through the API. If that's a real product need, raise it with the backend team; it wasn't part of this change.
- `GET /api/v2/sales-order` still supports filtering the list by `status_id` (a read-only query param) — for that, resolve the `id` for a given `status_name` via `GET /api/v2/sales-status` at request time. Don't hardcode that value either.
- If a request to create/approve/reject/revise ever returns `500` with a message like `Sales status "Draft" is not configured`, that means the `sales_status` master table is missing that row in that environment — this is a backend data-seeding issue to raise with the backend team, not something the frontend can fix.

---

## [2026-07-11 15:00:00 WIB] — Sales & Purchase list endpoints (date filtering + join search)

### Added
- `GET /api/v2/sales-order` — new `date_from`/`date_to` params filter by `so_date`.
- `GET /api/v2/sales-invoice` — new `date_from`/`date_to` params filter by `invoice_date`.
- `GET /api/v2/sales-sppb` — new `date_from`/`date_to` params filter by `sppb_date`.
- `GET /api/v2/sales-delivery` — new `date_from`/`date_to` params filter by `delivery_date`.
- `GET /api/v2/sales-profit` — new `date_from`/`date_to` params filter by `created_at`; new `search` param now matches the linked sales order's `so_display_number` (previously this endpoint had no `search` support at all).
- `GET /api/v2/purchase-order` — new `date_from`/`date_to` params filter by `po_date`.
- `GET /api/v2/purchase-receive` — new `date_from`/`date_to` params filter by `receiving_date`; new `search` param now matches the linked purchase order's `po_display_number` (previously this endpoint had no `search` support at all).
- `GET /api/v2/purchase-invoice` — new `date_from`/`date_to` params filter by `invoice_date`.

### Notes for frontend
- All new params are optional query-string additions — existing requests without them behave exactly as before.
- `date_from`/`date_to` take `YYYY-MM-DD` and are inclusive on both ends. For `sales-profit`'s `created_at` (a datetime column, not a plain date), `date_from` is treated as the start of that day and `date_to` as the end of that day.
- `search` on `sales-profit` and `purchase-receive` resolves against the joined order's display number, not any field on the record itself — a `search` term matching nothing on the linked order returns an empty page even if the sales-profit/purchase-receive row exists.
- List pagination (`page`/`limit`, `pagination.total` in the response) was already accurate on all 8 endpoints before this change — no fix was needed there.

---

## [2026-07-11 00:00:00 WIB] — Permissions (new module)

### Added
- `GET /api/v2/permissions/{permission_key}/roles` — New endpoint. Returns the role(s) that currently grant a given permission key, e.g. `{ "permission_key": "sales.so.create", "label": "Create Sales Order", "roles": ["Business Owner", "Admin Sales"] }`.
- `GET /api/v2/permissions/roles?keys=a,b,c` — Batch variant of the above; resolves up to 50 permission keys in one request. Unknown keys are dropped from the response rather than erroring.

### Notes for frontend
- Built for `usePermissionGate`'s denial dialog: fetch the role list for the `permission_key` a click-time gate just blocked, and interpolate it into the message instead of the generic "contact your administrator" text.
- Any authenticated user can call this — role names aren't treated as sensitive, and the data isn't company-scoped (it reads the global `app_permission`/`app_role_permission` catalog, same as `GET /account/my-permissions`).
- Responses are always live — no caching layer, so role/permission edits show up immediately.

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
