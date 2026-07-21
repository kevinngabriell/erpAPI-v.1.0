# API Changelog

All notable API changes are documented here in reverse-chronological order.
Dates and times are in **WIB (UTC+7)**.

Intended audience: frontend developers.

---

## [2026-07-20 22:30:00 WIB] — Purchase order/invoice/receive gain sales-style single-approval workflow; sales approve bug fixed

### Added
- `PATCH /api/v2/purchase-invoice/{id}/approve`, `.../reject`, `.../revise` — purchase invoice now has the same single-approval workflow as purchase-order/sales (it previously had no status/approval concept at all). New columns `status_id`, `approved_by`, `approved_at` added to `purchase_invoice`.
- `PATCH /api/v2/purchase-receive/{id}/approve`, `.../reject`, `.../revise` — purchase receive gains the same workflow. New columns `status_id`, `approved_by`, `approved_at` added to `purchase_receive`.
- `PATCH /api/v2/purchase-order/{id}/revise` — purchase order previously only had `approve`/`reject`; it now also supports moving a rejected order back to `Draft`, matching sales-order/sales-sppb/sales-delivery/sales-profit.
- `POST /api/v2/purchase-invoice`, `POST /api/v2/purchase-receive` — now set `status_id` to `Draft` server-side on create.
- `GET /api/v2/purchase-invoice`, `GET /api/v2/purchase-invoice/{id}`, `GET /api/v2/purchase-receive`, `GET /api/v2/purchase-receive/{id}` — responses now include `status_id`, `status_name`, `approved_by`, `approved_at`. Both endpoints also accept a new `status_id` filter on the list route.
- This is a **single-approval** workflow (one `approved_by`/`approved_at` pair) — unrelated to and not replacing the 2-signer Owner+Treasury dual-approval flow that Finance uses (`finance_transaction`/`finance_payment`); that pattern stays scoped to Finance only.

### Breaking changes
- **`purchase-order`: `status_id` is no longer a client-supplied field.** `POST` no longer accepts/requires `status_id` (it's auto-set to `Draft`); `PUT` no longer accepts `status_id` at all (status changes only via `approve`/`reject`/`revise`); `PATCH .../approve` and `.../reject` no longer accept/require `status_id` in the body (previously required — the endpoint resolved the status purely from whatever UUID the client sent). Any frontend code currently sending `status_id` on these three requests should stop — it's now ignored on `PUT`/silently unnecessary elsewhere.
- **Sales approve endpoints were 500ing and are now fixed.** `sales-order`, `sales-delivery`, `sales-sppb`, and `sales-profit`'s `PATCH .../approve` looked up a `sales_status` row named `"Approve"`, but the seeded row is actually named `"Approved"` — every call to these four `approve` endpoints returned `500 Sales status "Approve" is not configured` in every environment. This is now fixed to look up `"Approved"`, so these endpoints work for the first time. No request/response shape changed — this is a bug fix, not a new behavior, but flagging it here since any frontend code that was silently swallowing/retrying the 500 should be revisited.

### Notes for frontend
- Purchase invoices/receives that existed before this change were backfilled to `status_name = "Approved"` with `approved_by`/`approved_at` left `null` — there's no real approver identity to backfill for records that predate the approval gate.
- `revise` only succeeds when the current status is `Rejected` on all three purchase modules; any other status returns `400`.
- See `v2/docs/migrations/v22_purchase_invoice_receive_approval_schema.md` for the schema migration — applied to dev, **prod not yet applied** (pending sign-off, same gate as every other schema change in this project).

---

## [2026-07-20 21:10:00 WIB] — Owner + Treasury dual approval added to finance-transaction and finance-payment

### Added
- `PATCH /api/v2/finance-transaction/{id}/approve`, `.../reject` — every finance transaction (Pembayaran/cash-out **and** Penerimaan/cash-in alike) now requires two signatures — Business Owner and Treasury/Controller — before it reaches `posted`. Which slot a caller fills is resolved server-side from their permissions, not chosen in the request.
- `PATCH /api/v2/finance-payment/{id}/approve`, `.../reject` — A/P and A/R settlements (`finance_payment`) now go through the same Owner + Treasury 2-signer workflow.
- `finance_transaction` and `finance_payment` list/detail responses now include `transaction_status`, `approved_by_owner_id`/`approved_by_owner_at`, `approved_by_treasury_id`/`approved_by_treasury_at`, and resolved display names `approved_by_owner`/`approved_by_treasury`.
- `GET /api/v2/finance-transaction` and `GET /api/v2/finance-payment` accept a new `transaction_status` filter (`draft` \| `submitted` \| `partially_approved` \| `posted` \| `rejected`).
- Two new permission keys: `keuangan.approve_owner` and `keuangan.approve_treasury`. Shared across both modules — one 2-signer control, not four separate ones.

### Breaking changes
None — all changes are additive (new fields, new endpoints, new optional filter). Existing `POST`/`PUT` request bodies are unchanged.

### Notes for frontend
- New records are created with `transaction_status = 'draft'` by default; nothing changes about the `POST` request shape.
- The same user can never fill both the owner and treasury slot on one record, even if their role happens to hold both permissions — the second attempt returns `409 You have already signed this record`.
- **No role currently holds `keuangan.approve_owner` or `keuangan.approve_treasury`** — an admin needs to assign these via the roles/permissions screen before anyone can actually approve anything through these new endpoints. A "Treasury/Controller" role does not exist yet in `movira_core`; it needs to be created (or an existing role repurposed) first.
- Pre-existing rows were backfilled to `transaction_status = 'posted'` with `NULL` approvers on both tables (4,443 `finance_transaction` rows already had this from an earlier migration; 582 `finance_payment` rows were backfilled as part of this change) — they predate the approval workflow, so there's no real approver identity to backfill.
- Edits and soft-deletes are **not** blocked by `transaction_status` — a `posted` record can still be updated/deleted via the existing `PUT`/`DELETE` endpoints, matching existing behavior on `purchase_order`/`sales_order`.

---

## [2026-07-20 18:58:14 WIB] — Sales SPPB/delivery/profit gain approve/reject workflow; Excel export added across sales

### Added
- `GET /api/v2/sales-order/{id}/export`, `GET /api/v2/sales-sppb/{id}/export`, `GET /api/v2/sales-delivery/{id}/export`, `GET /api/v2/sales-profit/{id}/export` — each downloads the record as a formatted `.xlsx` file, replicating the layout of the legacy v1 Excel exports (`sales/SOExport.php`, `sales/SPPBExport.php`, `sales/SuratJalanExport.php`, `sales/ProfitExport.php`). Auth-gated like every other endpoint — requires the same `Authorization: Bearer` header, so a plain `<a href>`/browser navigation won't work; fetch as a blob client-side.
- `PATCH /api/v2/sales-sppb/{id}/approve`, `.../reject`, `.../revise` — sales SPPB now has the same approval workflow as sales-order: server-resolved `status_id` via `sales_status`, `approved_by`/`approved_at` set on approve.
- `PATCH /api/v2/sales-delivery/{id}/approve`, `.../reject`, `.../revise` — sales delivery now has the same approval workflow (previously had no status/approval concept at all, unlike v1).
- `PATCH /api/v2/sales-profit/{id}/approve`, `.../reject`, `.../revise` — sales profit now has the same approval workflow.
- `POST /api/v2/sales-sppb`, `POST /api/v2/sales-delivery`, `POST /api/v2/sales-profit` — now set `status_id` to `Draft` server-side on create.
- `GET /api/v2/sales-sppb`, `GET /api/v2/sales-sppb/{id}`, `GET /api/v2/sales-delivery`, `GET /api/v2/sales-delivery/{id}`, `GET /api/v2/sales-profit`, `GET /api/v2/sales-profit/{id}` — responses now include `status_id`, `status_name`, `approved_by`, `approved_at`, matching the fields already returned by sales-order.

### Breaking changes
None — all changes are additive (new fields, new endpoints). Existing `POST`/`PUT` request bodies for SPPB, delivery, and profit are unchanged.

### Notes for frontend
- `status_id` is never client-supplied on any of the three modules — same pattern as sales-order. Resolve `sales_status.id` from `GET /api/v2/sales-status` only if you need to filter or display it; transitions happen exclusively via `approve`/`reject`/`revise`.
- Sales delivery previously had no status concept in the v2 API at all — existing sales-delivery records were implicitly "no status" until this change; after deploying the underlying schema change, treat any pre-existing row's `status_id` as whatever the migration backfilled (check with whoever ran it) before assuming it's `Draft`.
- The delivery export fixes two bugs present in the v1 script rather than reproducing them: cell `G5` now shows `ship_to_address` instead of duplicating the billing address, and the hardcoded signer name `'Intan'` is replaced with the record's resolved `approved_by` name.
- The SPPB export's "PO CUSTOMER" column is relabeled "NO SO" and now shows the linked sales order's `so_display_number` — v2's `sales_sppb_item` has no per-item PO number field like v1 did.
- The sales-order export's header "PO NO" cell shows the first item's linked `po_display_number`, since v2 links purchase orders per-item rather than one PO number per header like v1.
- None of the export endpoints persist anything — they are pure read/format operations, same transactional guarantees as any other `GET`.

---

## [2026-07-19 22:54:57 WIB] — Cash book report added

### Added
- `GET /api/v2/cash-book` — new report endpoint. Per-bank-account opening balance, period movement, and closing balance for a date range (paginated). This is the v2 equivalent of v1's Buku Kas.
- `GET /api/v2/cash-book/{bank_account_id}` — transaction-level cash book detail for one bank account, with running balance. Combines `finance_transaction` rows for that bank account with `finance_payment` rows settled through it (customer/supplier payments), ordered together by date.
- `POST /api/v2/finance-category` and `PUT /api/v2/finance-category/{id}` — now require/accept a new `category_type` field (`debit` or `credit`). List and detail responses for finance categories now include `category_type`.

### Breaking changes
- `POST /api/v2/finance-category` now requires `category_type` in the request body — existing integrations creating finance categories without this field will get a `400`.

### Notes for frontend
- The cash book's `signed_amount` is positive for cash in, negative for cash out. Direction for `finance_transaction` rows comes from the linked finance category's new `category_type`; direction for `finance_payment` rows comes from whether it was a customer or supplier settlement.
- Existing finance categories were backfilled: `Penerimaan` and `Penerimaan Penjualan` → `debit`; `Pembayaran` and `Pembayaran Pembelian` → `credit`. Any new category must be tagged correctly or cash book totals will be wrong.
- No Excel export endpoint yet (v1 had `BukuKasdocument.php`/`exportbukukas.php`) — this is JSON-only, matching the rest of the v2 reports.

---

## [2026-07-19 WIB] — Finance transaction list & detail now resolve reference names

### Added
- `GET /api/v2/finance-transaction` and `GET /api/v2/finance-transaction/{id}` — responses now also include `bank_name`, `bank_number` (from `bank_account_id`), `account_code`, `account_code_name` (from `account_code_id`), and `category_name` (from `finance_category_id`), resolved via `LEFT JOIN` alongside their existing `*_id` fields.

### Notes for frontend
- All `*_id` fields are unchanged and still returned — this is additive, not a rename. Display the new resolved fields directly instead of calling `bank-account`, `account-code`, and `finance-category` separately and mapping the results client-side.
- Every resolved field is `null` if the referenced master record no longer exists, same as the existing `created_by`/`updated_by` behavior.

---

## [2026-07-19 15:25:59 WIB] — Purchase order/receive/invoice list & detail now resolve reference names

### Added
- `GET /api/v2/purchase-order` and `GET /api/v2/purchase-order/{id}` — responses now also include `supplier_name`, `status_name`, `term_name`, `method_name`, `origin_name`, `type_name`, `currency_code`, `currency_name`, `ppn_name`, resolved via `LEFT JOIN` alongside their existing `*_id` fields.
- `GET /api/v2/purchase-receive` and `GET /api/v2/purchase-receive/{id}` — responses now also include `po_display_number`, `supplier_name`, `ship_name`, resolved via `LEFT JOIN` alongside their existing `*_id` fields.
- `GET /api/v2/purchase-invoice` and `GET /api/v2/purchase-invoice/{id}` — responses now also include `po_display_number`, `supplier_name`, `term_name`, resolved via `LEFT JOIN` alongside their existing `*_id` fields.

### Notes for frontend
- All `*_id` fields are unchanged and still returned — this is additive, not a rename. Display the new `*_name`/`*_display_number` fields directly instead of doing a client-side lookup against master-data endpoints.
- Every resolved field is `null` if the referenced master record no longer exists (or the source `*_id` was never set), same as the existing `created_by`/`updated_by`/`approved_by` behavior.
- This brings purchase-order, purchase-receive, and purchase-invoice list/detail responses in line with the sales-order module, which already returned resolved names.

---

## [2026-07-19 15:04:59 WIB] — Origin list/detail now show resolved region name instead of region ID

### Changed
- `GET /api/v2/origin` and `GET /api/v2/origin/{id}` — responses now include `region_name` (resolved via `LEFT JOIN` on `region`) in place of `region_id`.

### Breaking changes
- `region_id` is no longer present in `origin` list/detail responses. Any frontend code reading `region_id` off these responses (e.g. to display it, or to re-send it unchanged on a `PUT`) must switch to reading `region_id` from its own local state instead, or omit that field from the `PUT` payload if unchanged.

### Notes for frontend
- `region_id` is still required on `POST` and still accepted on `PUT`, and the `?region_id=` filter on the list endpoint is unchanged — only the response shape changed.
- `region_name` is `null` only if the referenced region row no longer exists; it still resolves normally if the region was merely soft-deleted after the origin was created.

---

## [2026-07-17 12:04:11 WIB] — Sales SPPB number generator added

### Added
- `GET /api/v2/sales-sppb/generate-number` — returns the next `sppb_display_number` for the authenticated company, in the format `{seq}/{company_code}-SPPB/{roman_month}/{year}` (e.g. `001/VIK-SPPB/VII/2026`). This is a read-only preview, following the same pattern already shipped for sales orders (`GET /api/v2/sales-order/generate-number`).

### Notes for frontend
- Call this endpoint to pre-fill `sppb_display_number` right before submitting `POST /api/v2/sales-sppb` — the number is not reserved, so a race with another concurrent creation is possible (same caveat as the sales-order generator).

---

## [2026-07-17 20:00:00 WIB] — Audit log entries now include resolved user name and position

### Changed
- `GET /api/v2/audit-log` and `GET /api/v2/audit-log/{id}` — `action_by` now returns the acting user's resolved full name (`"First Last"`) instead of the raw user ID. There is no new field for this — the existing key just carries a different value now, following the same pattern already shipped for `created_by`/`updated_by`/`approved_by` on every other v2 module.

### Added
- `GET /api/v2/audit-log` and `GET /api/v2/audit-log/{id}` — responses now include `position_name`, the acting user's job position at the time of the request (e.g. `"Purchasing Staff"`).

### Breaking changes
- `action_by` in `audit_log` responses is no longer the raw user ID. If the frontend was using it as a lookup key (e.g. to fetch the user's name separately), that call is no longer needed — render the value directly. Any code parsing `action_by` as a UUID will break.

### Notes for frontend
- `action_by` can be `null` only if the acting user has since been deleted from the user directory.
- `position_name` is `null` if the user has no position assigned, or has since been deleted.
- Both are resolved via `LEFT JOIN`s reused from the existing pagination-count query — no added round trip.

---

## [2026-07-14 05:56:38 WIB] — Sales order number generator added

### Added
- `GET /api/v2/sales-order/generate-number` — returns the next `so_display_number` for the authenticated company as a preview (`{seq}/{company_code}-SO/{roman_month}/{year}`, e.g. `001/VIK-SO/VII/2026`). The frontend no longer needs to construct or guess this value before calling `POST /api/v2/sales-order`.

### Notes for frontend
- This is a preview, not a reservation — the number is not locked until the sales order is actually created. Call it immediately before submitting the create form to minimize the (small) chance of a collision if two users create a sales order in the same company at the same time, in which case `POST` still returns `409 Conflict` on a duplicate `so_display_number`.
- The sequence resets to `001` at the start of each calendar month, per company.

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
