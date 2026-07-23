# Permission Keys Reference

> **Last updated:** 2026-07-21 WIB
> **Audience:** Frontend — every `permission_key` the API checks or exposes, and which role currently grants it.
> **Scope:** v2 only. v1 (root `/user`, `/company`) has no per-key permission model — see [v1 legacy model](#v1-legacy-model-no-permission-keys) at the bottom.

## How this works

- Permission keys, roles, and the role→permission mapping live in three tables in `CORE_SCHEMA` (`app_permission`, `app_role`, `app_role_permission`) — global catalog, not company-scoped, not cached.
- No endpoint checks a role by **name**. Every gate checks whether the caller's `app_role_id` holds a specific `permission_key` (`userHasPermission()` in [v2/helpers/permission.php](../helpers/permission.php)). Role names below reflect what's *currently assigned* in the dev/prod database as of this writing, not something hardcoded — an admin can reassign any key to any role at any time via the roles/permissions admin UI.
- **Do not hardcode role names in the frontend.** Two endpoints exist specifically so the FE never has to:
  - `GET /api/v2/account/my-permissions` — the caller's own permission keys, to drive what UI to show/hide.
  - `GET /api/v2/permissions/{permission_key}/roles` and `GET /api/v2/permissions/roles?keys=a,b,c` — resolves which role(s) currently hold a key, for building "Requires role X" denial messages.
  See [permissions.md](api/permissions.md) and [account.md](api/account.md) for request/response shapes.
- This document is a snapshot of what's in code + migration docs in this repo. The **live** `app_role_permission` table is the actual source of truth — use the two endpoints above at runtime rather than hardcoding anything from this table.

---

## Dashboard widget keys (`dashboard.*`)

Gate: `getPermittedDashboardKeys()` in [v2/dashboard/index.php](../dashboard/index.php). Each key controls whether one widget key appears in `GET /api/v2/dashboard`'s `data.widgets`. Full widget shapes are in [dashboard.md](api/dashboard.md).

| permission_key | Expected role (per original spec grouping) |
|---|---|
| `dashboard.revenue_trend.view` | Business Owner |
| `dashboard.profit_summary.view` | Business Owner |
| `dashboard.cash_position.view` | Business Owner |
| `dashboard.ar_ap_summary.view` | Business Owner |
| `dashboard.pending_approvals.view` | Business Owner |
| `dashboard.top_partners.view` | Business Owner |
| `dashboard.subscription_status.view` | Business Owner |
| `dashboard.pending_users.view` | Business Owner |
| `dashboard.exceptions.view` | Manager |
| `dashboard.approval_queue.view` | Manager |
| `dashboard.activity_log.view` | Manager |
| `dashboard.buku_kas_snapshot.view` | Finance |
| `dashboard.bank_balances.view` | Finance |
| `dashboard.finance_entries_today.view` | Finance |
| `dashboard.pnl_snapshot.view` | Accounting |
| `dashboard.neraca_snapshot.view` | Accounting |
| `dashboard.buku_besar_summary.view` | Accounting |
| `dashboard.ar_aging.view` | Accounting |
| `dashboard.ap_aging.view` | Accounting |
| `dashboard.tax_due.view` | Accounting |
| `dashboard.open_pos.view` | Admin Purchase |
| `dashboard.po_pending_approval.view` | Admin Purchase |
| `dashboard.incoming_eta.view` | Admin Purchase |
| `dashboard.invoice_matching.view` | Admin Purchase |
| `dashboard.open_sos.view` | Admin Sales |
| `dashboard.so_pending_approval.view` | Admin Sales |
| `dashboard.delivery_status.view` | Admin Sales |
| `dashboard.sppb_pending.view` | Admin Sales |
| `dashboard.order_backlog.view` | Admin Sales |
| `dashboard.top_customers_mtd.view` | Admin Sales |
| `dashboard.shipments_in_transit.view` | Logistics / Import Coordinator |
| `dashboard.sppb_tracker.view` | Logistics / Import Coordinator |
| `dashboard.container_lookup.view` | Logistics / Import Coordinator |
| `dashboard.at_risk_shipments.view` | Logistics / Import Coordinator |
| `dashboard.stock_movements_today.view` | Gudang (Warehouse Staff) |
| `dashboard.pending_receive.view` | Gudang (Warehouse Staff) |
| `dashboard.pending_outbound.view` | Gudang (Warehouse Staff) |
| `dashboard.stock_by_location.view` | Kepala Gudang (Warehouse Supervisor) |
| `dashboard.warehouse_monthly_summary.view` | Kepala Gudang (Warehouse Supervisor) |

**Not implemented — granting these to a role has no effect** (schema doesn't support them yet, see [dashboard.md](api/dashboard.md#unavailable-widgets-schema-gaps) for why each is blocked):

`dashboard.dept_comparison.view`, `dashboard.payment_verification.view`, `dashboard.supplier_scorecard.view`, `dashboard.clearance_mode.view`, `dashboard.low_stock.view`, `dashboard.adjustment_approvals.view`, `dashboard.discrepancy_flags.view`

---

## Finance dual-approval keys (`keuangan.*`)

Gate: `applyDualApproval()` / `rejectDualApproval()` in [v2/helpers/dual_approval.php](../helpers/dual_approval.php), called from the `approve`/`reject` actions on `finance-transaction` and `finance-payment`. One shared 2-signer workflow covers Pembayaran, Penerimaan, A/P, and A/R — not four separate ones.

| permission_key | What it gates | Expected role | Status |
|---|---|---|---|
| `keuangan.approve_owner` | Signs the "Business Owner" slot on a finance transaction/payment | Business Owner (obvious mapping by name, **not yet assigned in dev or prod**) | ⚠️ Unassigned — approve/reject on both modules is unusable for anyone until an admin grants this |
| `keuangan.approve_treasury` | Signs the "Treasury/Controller" slot | No existing role is an obvious fit — no "Treasury" or "Controller" role exists yet in `movira_core` (dev or prod) | ⚠️ Unassigned — needs a new role created or an existing Finance-adjacent role repurposed |

Do not assume either key is granted to anyone — check `GET /api/v2/permissions/roles?keys=keuangan.approve_owner,keuangan.approve_treasury` before wiring up an approve button, and surface "no one can approve yet" states rather than a generic 403.

**Legacy, unrelated keys** (referenced in docs, not checked by any current code path — do not use for gating the dual-approval UI):
- `keuangan.ap.approve`, `keuangan.ar.approve` — pre-existing keys mentioned only in [finance-payment.md](api/finance-payment.md); mean whatever they meant before this feature, unaffected by dual-approval.
- `keuangan.ledger.view` — pre-existing key, used only as a lookup anchor in the migration script to find `app_id`; no endpoint checks it.

---

## Notification routing keys (`notification.*`)

Gate: `resolveApprovalRecipients()` in [v2/helpers/notification.php](../helpers/notification.php) — determines who gets notified (in-app + WhatsApp) when a document enters `approval_pending`.

| permission_key | What it gates | Expected role | Status |
|---|---|---|---|
| `notification.sales_order.approver` | Who receives "Sales Order pending approval" notifications | Not yet decided — candidates: Business Owner or Manager | ⚠️ Unassigned in dev/prod |
| `notification.purchase_order.approver` | Who receives "Purchase Order pending approval" notifications | Not yet decided — candidates: Business Owner or Manager | ⚠️ Unassigned in dev/prod |

Until a role holds these, submitted sales/purchase orders write their notification row(s) but resolve to zero recipients — not an error, just silent. Finance approval notifications reuse `keuangan.approve_owner`/`keuangan.approve_treasury` (no separate notification keys) and inherit the same unassigned status above.

---

## All roles seen in code/docs

| Role name | Where it appears |
|---|---|
| Business Owner | Auto-assigned to the first user of a new company at registration ([v2/auth/register.php](../auth/register.php)); primary candidate for both unassigned `keuangan.*` and `notification.*` keys above |
| Manager | Dashboard `exceptions`/`approval_queue`/`activity_log` group; candidate for `notification.*` keys |
| Finance | Dashboard `buku_kas_snapshot`/`bank_balances`/`finance_entries_today` group |
| Accounting | Dashboard `pnl_snapshot`/`neraca_snapshot`/`buku_besar_summary`/`ar_aging`/`ap_aging`/`tax_due` group |
| Admin Purchase | Dashboard `open_pos`/`po_pending_approval`/`incoming_eta`/`invoice_matching` group |
| Admin Sales | Dashboard `open_sos`/`so_pending_approval`/`delivery_status`/`sppb_pending`/`order_backlog`/`top_customers_mtd` group |
| Logistics / Import Coordinator | Dashboard `shipments_in_transit`/`sppb_tracker`/`container_lookup`/`at_risk_shipments` group |
| Gudang (Warehouse Staff) | Dashboard `stock_movements_today`/`pending_receive`/`pending_outbound` group — company-wide, no location scoping exists yet |
| Kepala Gudang (Warehouse Supervisor) | Dashboard `stock_by_location`/`warehouse_monthly_summary` group |
| Treasury / Controller | **Does not exist yet** — needs to be created for `keuangan.approve_treasury` to be assignable to anyone |

Custom/generalist roles (e.g. a client's own "Admin Operasional" bundle) can also exist — the dashboard and all permission checks work off the union of whatever keys a role holds, regardless of its name.

---

## v1 legacy model (no permission keys)

v1 (`/user`, `/company`, root DB) has no `permission_key`/role system. `user` has a flat `permission_access` string column joined via a legacy `permission` table (just `permission_id` + `permission_access`) — no role table, no per-endpoint key checks anywhere in the root PHP. Do not try to map v1 `permission_access` values onto the v2 keys above; they're unrelated models.

---

## Frontend integration checklist

1. On login/session load, call `GET /api/v2/account/my-permissions` and cache `data.permissions` (flat array) and `data.modules` (grouped) for the session — use this to show/hide UI, never a role-name check.
2. When a permission-gated action is unavailable, call `GET /api/v2/permissions/{permission_key}/roles` (or the batch variant) to populate the denial message with the actual current role(s), since assignments change independently of this document.
3. Treat the Finance approve/reject buttons and the SO/PO "pending approval" notification recipients as **currently unassigned** — confirm with backend/admin before assuming any user can act on them.
