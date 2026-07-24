# API Changelog

All notable API changes are documented here in reverse-chronological order.
Dates and times are in **WIB (UTC+7)**.

Intended audience: frontend developers.

---

## [2026-07-25 07:15:00 WIB] — Purchase order shipment field now an ETA period, not a raw date

### Added
- `GET /api/v2/shipment-period` — new global master endpoint listing the 36 fixed ETA shipment periods (`Early`/`Mid`/`End` × each month, e.g. "Early July"), ordered by `sort_order`.
- `POST /api/v2/shipment-period`, `GET /api/v2/shipment-period/{id}`, `PUT /api/v2/shipment-period/{id}`, `DELETE /api/v2/shipment-period/{id}` — standard CRUD for the above, same shape as `ship-via`/`payment-term`.
- `purchase_order` gains `shipment_period_id`, returned alongside its resolved `period_name` on list/detail responses (`GET /api/v2/purchase-order`, `GET /api/v2/purchase-order/{id}`), and accepted on `POST`/`PUT`.

### Removed
- `purchase_order.shipment_date` (a free date) no longer exists — dropped from the database and from every request/response shape.

### Breaking changes
- `shipment_date` is gone from `POST /api/v2/purchase-order` and `PUT /api/v2/purchase-order/{id}` request bodies, and from every response that used to include it (list, detail). Sending `shipment_date` no longer does anything — the field is silently ignored, not rejected.
- Use `shipment_period_id` instead, referencing `GET /api/v2/shipment-period` for valid values. This is a coarser ETA estimate (e.g. "Early July") rather than an exact date — matches how the pre-Aluria legacy system's PO form already represents this field. Exact dates are still available separately via `etd_date`/`eta_date`.

### Notes for frontend
- Existing purchase orders that had a `shipment_date` were migrated automatically: mapped to the enclosing period by day-of-month (1–10 → Early, 11–20 → Mid, 21–31 → End, same month). No data was silently dropped — see `v2/docs/migrations/v30_purchase_order_shipment_period_schema.md` for the exact mapping and per-environment status (**dev applied, prod not yet applied**).
- `shipment_method` (the Incoterm enum — `FOB`/`CIF`/etc.) is unrelated and unaffected by this change.
- Fetch the dropdown options from `GET /api/v2/shipment-period` rather than hardcoding the 36 period names — `sort_order` gives the correct chronological display order.
- The permission keys `settings.shipment_period.view|create|update|delete` already existed (seeded ahead of time in a prior migration) but no role currently holds any of them; that's a separate admin action, not something this release changes.

---

## [2026-07-25 06:24:59 WIB] — Purchase invoice and purchase receive gain the same notification wiring as purchase order

### Added
- `POST /api/v2/purchase-invoice` — now triggers an `approval_pending` notification (in-app + WebSocket push + WhatsApp with a one-click approve/reject link) to users holding the new `notification.purchase_invoice.approver` permission key.
- `PATCH /api/v2/purchase-invoice/{id}/approve`/`.../reject` — now notify the invoice's creator (`approval_approved`/`approval_rejected`) and invalidate any outstanding approval links for that document.
- `POST /api/v2/purchase-receive` — now triggers an `approval_pending` notification to users holding the new `notification.purchase_receive.approver` permission key.
- `PATCH /api/v2/purchase-receive/{id}/approve`/`.../reject` — now notify the receive's creator and invalidate any outstanding approval links.
- One-click WhatsApp approval links (`GET/POST /api/v2/approvals/{token}...`) now support `purchase_invoice` and `purchase_receive` as `source_module` values, same as `purchase_order`.
- Two new permission keys: `notification.purchase_invoice.approver`, `notification.purchase_receive.approver`. No request/response shape changed on any of these endpoints — this is a side effect only, same pattern as `purchase-order` got in the original notification module rollout.

### Notes for frontend
- **No role currently holds either new permission key** — until an admin grants one via the roles/permissions UI, Purchase Invoice/Purchase Receive `approval_pending` notifications have zero recipients (the in-app row is still created, just with nobody to deliver it to). Approve/reject notifications to the creator work immediately regardless, since those don't depend on the new keys.
- Purchase receive has no document number of its own — its notification text and the `approvals` one-click summary reference the linked purchase order's `po_display_number` instead.
- See `v2/docs/migrations/v29_purchase_invoice_receive_notification_permissions.md` for the permission-key migration — applied to dev, **prod not yet applied** (pending sign-off, same gate as every other schema change in this project).

---

## [2026-07-25 00:40:00 WIB] — Auto-generated document numbers for purchase order

### Added
- `GET /api/v2/purchase-order/generate-number?type_id={purchase_type_id}` — returns the next `po_display_number` for the authenticated company and the given purchase type. Read-only preview, does not reserve the number. Unlike `sales-order`'s single fixed format, the format is per purchase type: `type_id` is required, and the pattern is read from the type's `number_format`/`sequence_digits` (see below).
- `POST /api/v2/purchase-type` and `PUT /api/v2/purchase-type/{id}` — new optional `number_format` and `sequence_digits` fields. `number_format` is a template string (tokens: `{company_code}`, `{month}`, `{yyyy}`, `{yy}`, `{seq}`) used by the new `generate-number` endpoint above; must contain `{seq}` or the request is rejected with `400`. `sequence_digits` controls zero-padding width (1–10, default 4).
- `GET /api/v2/purchase-type` and `GET /api/v2/purchase-type/{id}` — responses now include `number_format` and `sequence_digits` for every purchase type.

### Notes for frontend
- Call `generate-number` with the `type_id` the user has selected for the purchase order (from `GET /api/v2/purchase-type`), right before submitting `POST /api/v2/purchase-order` — same pattern as `sales-order`'s `generate-number`.
- The two purchase types that already existed (`Local`, `Import`) are pre-configured to match the numbering already in production use: `Import` → e.g. `VKN/26/VII/0030`, `Local` → e.g. `VKN/L/VI/2026/015`. Any purchase type created going forward needs its `number_format` set via `PUT /api/v2/purchase-type/{id}` before `generate-number` will work for it — it responds `500` with a clear message otherwise (no guessed default).
- `sequence_digits` is returned as a numeric string, not cast to an int, like other non-boolean numeric columns in this API.

---

## [2026-07-24 21:51:35 WIB] — Status filter added to sales-invoice, sales-delivery, and sales-profit lists

### Added
- `GET /api/v2/sales-invoice` — new `status_id` query parameter, filters on `sales_invoice.status_id`.
- `GET /api/v2/sales-delivery` — new `status_id` query parameter, filters on `sales_delivery.status_id`.
- `GET /api/v2/sales-profit` — new `status_id` query parameter, filters on `sales_profit.status_id`.

### Notes for frontend
- Resolve the `id` for a given `status_name` via `GET /api/v2/sales-status` at request time — do not hardcode it (the same status name has a different `id` per environment).
- This brings all five sales list endpoints (`sales-order`, `sales-delivery`, `sales-invoice`, `sales-sppb`, `sales-profit`) to parity on `status_id` filtering — `sales-order` and `sales-sppb` already supported it.
- `status_name` was already present on all five list responses (joined from `sales_status`) — no change there, confirming what was already live.

---

## [2026-07-24 21:30:00 WIB] — Auto-generated document numbers for sales delivery and sales invoice

### Added
- `GET /api/v2/sales-delivery/generate-number` — returns the next `do_display_number` for the authenticated company, in the same format used by sales order/SPPB: `{seq}/{company_code}-DO/{roman_month}/{year}` (e.g. `001/VIK-DO/VII/2026`). Read-only preview, does not reserve the number.
- `GET /api/v2/sales-invoice/generate-number` — returns the next `invoice_display_number` for the authenticated company, format `{seq}/{company_code}-INV/{roman_month}/{year}` (e.g. `001/VIK-INV/VII/2026`). Read-only preview, does not reserve the number.

### Notes for frontend
- Both endpoints work exactly like the existing `GET /api/v2/sales-order/generate-number` and `GET /api/v2/sales-sppb/generate-number` — call them right before showing the create form (or right before submit) and pre-fill `do_display_number` / `invoice_display_number`. The `POST` endpoints still require the field and still enforce uniqueness (`409` on duplicate), so treat the generated value as a suggestion, not a reservation.
- No existing endpoint, field, or response shape changed — this only adds two new `GET` routes.

---

## [2026-07-24 21:06:53 WIB] — Finance payment creation now requires an approved sales invoice

### Updated
- `POST /api/v2/finance-payment` — `invoice_number` is now validated against `sales_invoice.invoice_display_number` (same company, not deleted). Returns `404 Sales invoice not found` if there's no match, or `400 Sales invoice must be approved before a payment can be recorded` if the matching invoice's status isn't `Approved` yet. Previously `invoice_number` was accepted as any free-text string with no existence or status check.

### Notes for frontend
- This closes a gap where a payment could be recorded against an invoice that was still `Draft` or had been `Rejected`. Make sure any "record payment" UI only offers invoices whose `status_name` is `Approved` (see the `sales-invoice` doc), so users don't hit the new `400` after filling out the whole form.
- `invoice_number` is still a plain string field on `finance_payment` — there is no `sales_invoice_id` FK — this is a create-time check only, not a stored relationship.

---

## [2026-07-24 20:56:15 WIB] — Purchase order list can now be filtered by type (Local/Import)

### Added
- `GET /api/v2/purchase-order` — new `type_id` query parameter, filters on `purchase_order.type_id`. Use `GET /api/v2/purchase-type` to look up the `id` for `"Local"` vs `"Import"` (or any other configured type).

### Notes for frontend
- This replaces the need for any legacy Local/Import purchase order screen to query a separate endpoint — `GET /api/v2/purchase-order?type_id={local_or_import_type_id}` now returns the same live data as the unfiltered list, just scoped to one type.

---

## [2026-07-24 20:54:35 WIB] — Sales invoice gets an approval workflow (approve/reject/revise/export, `status_name`)

### Added
- `PATCH /api/v2/sales-invoice/{id}/approve` — approves a sales invoice. Sets `status_id` to `Approved`, plus `approved_by`/`approved_at`. Optional `notes` field recorded on the audit log entry.
- `PATCH /api/v2/sales-invoice/{id}/reject` — rejects a sales invoice. Sets `status_id` to `Rejected`. Optional `notes` field.
- `PATCH /api/v2/sales-invoice/{id}/revise` — moves a `Rejected` invoice back to `Draft` so it can be resubmitted. Returns `400` if the invoice's current status isn't `Rejected`.
- `GET /api/v2/sales-invoice/{id}/export` — downloads the invoice as a formatted `.xlsx` file (header info, item table, totals, signature block).
- `GET /api/v2/sales-invoice` (list) and `GET /api/v2/sales-invoice/{id}` (detail) — responses now include `status_id`, `status_name` (joined from `sales_status`), `approved_by` (resolved to the approver's full name, `null` until approved), and `approved_at`.
- `POST /api/v2/sales-invoice` — now server-sets `status_id` to `Draft` on create; the client does not send `status_id`.

### Notes for frontend
- This is what your Approve/Reject buttons should gate on: show them only when `status_name === 'Draft'`; show a Revise action only when `status_name === 'Rejected'`.
- Every sales invoice that existed before this change (200 rows on dev) has been backfilled to `status_name: 'Draft'` — expect them to appear in any "pending approval" view built around that status. This was a deliberate choice (existing invoices are treated as not-yet-approved, not grandfathered in), not a bug.
- Same pattern as `sales-order`/`sales-sppb`/`sales-delivery`/`sales-profit` — if you've already wired up approve/reject/revise/export for those modules, `sales-invoice` behaves identically.
- See `v2/docs/migrations/v27_sales_invoice_approval_schema.md` for the schema change. Applied to dev; **prod not yet applied** — same sign-off gate as every other schema change in this project.

---

## [2026-07-23 23:15:00 WIB] — Sales profit `PUT` can now correct item data before resubmit

### Updated
- `PUT /api/v2/sales-profit/{id}` — now accepts an optional `items` array. When provided, it fully replaces the record's existing items (same required fields as `POST`: `product_name`, `quantity`, `price`, `landed_cost`, optional `purchase_order_id`), letting the frontend fix a `Rejected` record's item-level values (e.g. `landed_cost`) before calling `PATCH .../revise` and resubmitting. Previously the endpoint silently ignored any `items` sent in the body.

### Notes for frontend
- Sending `items` replaces the entire set — always resend the full array (including unchanged items), not just the one being corrected.

---

## [2026-07-23 22:30:00 WIB] — Finance transaction supports multiple GL accounts per voucher (`details`)

### Added
- `GET /api/v2/finance-transaction/{id}/details`, `POST /api/v2/finance-transaction/{id}/details`, `GET/PUT/DELETE /api/v2/finance-transaction/{id}/details/{detail_id}` — new sub-resource for managing individual GL account splits on a finance transaction, same pattern as `sales-order/{id}/items`.
- `GET /api/v2/finance-transaction` (list) and `GET /api/v2/finance-transaction/{id}` (detail) — detail response now includes a nested `details` array (list response does not).

### Breaking changes
- `POST /api/v2/finance-transaction` no longer accepts `account_code_id`/`account_amount`/`account_memo`. It now requires `details` — a non-empty array of `{account_code_id, amount, memo?}` — and rejects the request with `400 details is required` if it's missing, or `400 Sum of details.amount must equal amount` if the lines don't reconcile with the total `amount`.
- `account_code_id`, `account_amount`, `account_code`, and `account_code_name` no longer appear anywhere on the `finance_transaction` header row — list, detail, or `PUT` update. That information now lives only in each `details[]` entry (or via the new `/details` sub-resource).
- `PUT /api/v2/finance-transaction/{id}` no longer accepts `account_code_id`/`account_amount`.
- `GET /api/v2/general-ledger/{account_code_id}` — each row in `transactions` is now a `finance_transaction_detail` line rather than a `finance_transaction` header, so a transaction with multiple account splits now contributes one row per matching line instead of one row per transaction. `id` is the detail line's own ID.
- `GET /api/v2/cash-book/{bank_account_id}` — `description` on `finance_transaction` rows is now a comma-separated list of every account code name on that transaction (was a single account name).

### Notes for frontend
- This unblocks the "Detail Penerimaan"/"Detail Pembayaran" multi-line UI, which was already sending a `details` array that the API previously rejected with `400 account_code_id is required`.
- `dashboard` (`pnl_snapshot`, `neraca_snapshot`, `buku_besar_summary` widgets), `profit-loss`, `balance-sheet`, `general-ledger`, and `cash-book` all now aggregate through `finance_transaction_detail` instead of the old header columns — their request/response shapes are otherwise unchanged.
- See `v2/docs/migrations/v26_finance_transaction_multi_account_detail_schema.md` for the schema change. Applied to dev (4,443 existing rows backfilled 1:1, sums verified exact); **prod not yet applied** — same sign-off gate as every other schema change in this project, and prod's column-drop step must not run until this code is deployed and live.

---

## [2026-07-21 22:47:55 WIB] — Notification module added (in-app inbox, WebSocket push, WhatsApp approval pings, one-click approval links, daily digest)

### Added
- `GET /api/v2/notification` — paginated in-app notification list for the caller, `?unread=1` filter.
- `PUT /api/v2/notification/{id}/read`, `PUT /api/v2/notification/read-all`, `GET /api/v2/notification/unread-count` — bell/inbox read-state and badge count.
- `ws(s)://.../notification/stream` — new standalone WebSocket daemon (`v2/notification/ws-server.php`, not part of the HTTP router) pushing `notification:new`/`notification:unread_count` in real time. First category of long-running process in this repo — see the "New infra" note below.
- `GET /api/v2/approvals/{token}`, `POST /api/v2/approvals/{token}/approve`, `POST /api/v2/approvals/{token}/reject` — one-click WhatsApp approval links. Single-use, scoped to one user + one document, 72h TTL. Approve/reject call the exact same endpoint the app's own approve/reject button uses, so audit trail and Finance dual-approval behavior are unaffected.
- `GET/POST /api/v2/notification-settings/public-holidays`, `DELETE /api/v2/notification-settings/public-holidays/{id}` — public holiday calendar (national + per-company), used to skip the daily digest on holidays.
- `GET/PUT /api/v2/notification-settings/working-days` — per-company working-days config (default Mon–Fri), used to gate the daily digest.
- `POST` (create) and `PATCH .../approve`/`.../reject` on `sales-order`, `purchase-order`, `finance-transaction`, and `finance-payment` now trigger notifications (in-app + WebSocket push + WhatsApp) — see each module's own doc for the exact trigger points. No request/response shape changed on any of these four endpoints; this is a side effect only.
- New permission keys `notification.sales_order.approver` and `notification.purchase_order.approver` gate who receives Sales/Purchase Order approval notifications. Finance reuses the existing `keuangan.approve_owner`/`keuangan.approve_treasury` keys — no new Finance-specific approver configuration was added.
- Daily digest (`v2/notification/digest.php`, CLI/cron script, not an HTTP endpoint) — recaps everything still pending approval per recipient, gated on working-days + public holidays, sent once per working day at 08:00 WIB.

### Notes for frontend
- **Warehouse Adjustment is out of scope** — it has no approval workflow in this codebase yet, so there is nothing to notify on for that module.
- **No role currently holds `notification.sales_order.approver` or `notification.purchase_order.approver`.** Until an admin grants one of these via the roles/permissions UI, Sales/Purchase Order notifications have zero recipients (the in-app row is still created, just with nobody to deliver it to).
- **New infra, not yet installed on the server**: the WebSocket daemon needs a systemd unit + an Nginx `wss://` reverse-proxy block, and the digest needs a real crontab entry — neither exists yet. See `v2/docs/migrations/v23_notification_schema.md`'s post-migration checklist.
- WhatsApp sends are synchronous (same precedent as `send-otp`/`forgot-password`), not queued — a WA failure never fails the triggering create/approve/reject request.
- See `v2/docs/migrations/v23_notification_schema.md` for the full schema — applied to dev, **prod not yet applied** (pending sign-off, same gate as every other schema change in this project).

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
