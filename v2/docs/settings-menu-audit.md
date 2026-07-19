# Settings Menu Audit — v2 API Coverage

> Business analysis comparing the current Settings UI (Pengaturan) tiles against actual v2 API availability.
> Basis: `v2/master/*`, `v2/finance/*`, `v2/auth/*` module directories and `v2/docs/api/*.md`.

---

## Current tiles vs. v2 API

| Tile | v2 endpoint | Status |
|---|---|---|
| Produk | `master/product` | Keep |
| Customer | `master/customer` | Keep |
| Finance | `finance/*`, `master/account-code`, `bank-account`, `finance-category`, `ppn-type` | Keep |
| Supplier | `master/supplier` | Keep |
| Term | `master/payment-term` | Keep |
| Asal (Origin) | `master/origin` | Keep |
| Pembayaran | `master/payment-method` | Keep |
| Mata Uang | `master/currency` | Keep |
| Pengguna | none | No backing API |
| Perusahaan | none | No backing API |

---

## Remove or gate — tiles with no v2 endpoint

**Pengguna**
v1 had only a referral-based user lookup (`company/users.php`), not real user management. v2 has no list/invite/edit-role/deactivate-user endpoint at all; `auth/permission-roles` and `auth/my-permissions` are read-only lookups, not CRUD. Clicking this tile today has nothing to call.

**Perusahaan**
v1's `company/company.php` (name, address, email, phone, web, industry — GET/POST/PUT) was never migrated. `company-setting-menu` in v2 is a different thing: a generic, configurable "setting category" table (image/name/caption), not company-profile data. There is no way to edit company profile fields in v2 today.

Both are true gaps, not UI-only issues — recommend flagging to backend rather than just hiding, since "manage users" and "edit company profile" are baseline ERP settings expectations.

---

## Add — v2 master data with no tile yet

Full CRUD exists but isn't surfaced anywhere in Settings:

| Missing tile | Endpoint | Likely home |
|---|---|---|
| Chart of Accounts | `master/account-code` | under Finance |
| Bank Account | `master/bank-account` | under Finance |
| Finance Category | `master/finance-category` | under Finance |
| PPN/Tax Type | `master/ppn-type` | under Finance |
| Unit of Measure | `master/unit-of-measure` | under Produk |
| Ship Via (courier) | `master/ship-via` | new tile |
| Purchase Status / Type | `master/purchase-status`, `purchase-type` | new tile (Pembelian config) |
| Sales Status | `master/sales-status` | new tile (Penjualan config) |
| Sales Target | `master/sales-target` | new tile or under Analitik |

---

## Not orphaned — belongs elsewhere in the UI, not Settings

- `document-center`, `document-watermark` → likely under the **Dokumen** sidebar item, not Settings
- `warehouse-location` → likely under **Gudang**, not Settings
- `region` → reference/lookup data, probably a picker inside Customer/Supplier forms, not its own settings tile
- `company-setting-menu` → looks like an internal/admin config for building the Settings page itself, not a user-facing tile
- `salary-category`, HR `salary-transaction` → HR module has no sidebar entry at all yet; backend is ahead of the UI here, worth a heads-up but not a Settings gap specifically

---

## Bottom line

8 of 10 current tiles are solid. **Pengguna** and **Perusahaan** are UI without a backend — that's the priority conversation to have with backend before deciding whether to hide or wait. Separately, Finance and Produk are undersized relative to what the API already supports (COA, bank accounts, tax types, UOM sitting unused), and Pembelian/Penjualan are missing status/type config tiles entirely.
