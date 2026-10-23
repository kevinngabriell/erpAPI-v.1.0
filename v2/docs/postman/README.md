# Aluria ERP API v2 — Postman Collection

Generated from `v2/docs/api/*.md`. Covers all 14 modules directories / 52 documented resources — 313 requests, 1000+ saved example responses (every documented success and failure case).

## Import

1. In Postman: **Import** → drag in both files:
   - `erpAPI-v2.postman_collection.json`
   - `erpAPI-v2.postman_environment.json`
2. Select the **Aluria ERP v2 - Local** environment (top-right environment picker).
3. Set `base_url` to your running instance. Default assumes the repo's own `router.php` served via `php -S localhost:8000 router.php` from the project root (`/api/v2/...` paths resolve correctly against that). Point it at your nginx/Apache vhost instead if you're not using the PHP built-in server (e.g. `https://your-host/api/v2` or, behind the `/venken-api/` nginx location, `https://your-host/venken-api/v2`).
4. Set `email` / `password` to a real account, then run **Auth → Login**. Its *Tests* script saves the returned JWT into `access_token` (plus `company_id`, `user_id`) automatically — every other request inherits `Authorization: Bearer {{access_token}}` from the collection level.

## Structure

- **Auth** — login, register, send-otp, forgot/reset-password (all `noauth`), my-permissions, permission→roles lookups.
- **Dashboard / Analytics / Search / Profile** — single-purpose and self-service endpoints.
- **Notification** — in-app notifications, one-click WhatsApp approval links, per-company notification settings.
- **Master Data** — Global Lookups (14 modules, not company-scoped: region, origin, currency, …) and Tenant Master Data (10 modules, company-scoped: customer, supplier, product, account-code, …, including the nested `company-setting-menu/{id}/details` sub-resource).
- **Purchase** — purchase-order (incl. number generation, shipping-marks setting, items sub-resource, `.docx` export), purchase-invoice, purchase-receive.
- **Sales** — sales-order, sales-delivery, sales-invoice, sales-sppb, sales-profit — each with generate-number, approve/reject/revise, and `.xlsx` export where documented.
- **Finance** — finance-transaction (multi-account GL splits + details sub-resource), finance-payment (dual approval), general-journal (read-only).
- **Warehouse** — warehouse-lot, warehouse-transaction, reorder-point.
- **HR** — salary-transaction.
- **Reports** — sales-report, ar-ap-report, profit-loss, balance-sheet, general-ledger, cash-book, cogs-report (all read-only, each with an `/export` `.xlsx` variant).
- **Audit Log** — read-only.

## Notes

- Path variables (`:customer_id`, `:id`, etc.) are pre-filled with the same example UUIDs used in the docs — edit them per-request (Postman's "Path Variables" tab) once you have real IDs.
- `/export` endpoints return binary `.xlsx`/`.docx` files; their saved "example" is a text placeholder describing the expected `Content-Disposition` filename, since Postman examples can't embed real binary payloads.
- Every response follows `{ status_code, status_message, data }`; auth endpoints add `error_code` on failures.
- To regenerate after the API changes: update the relevant `v2/docs/api/{module}.md` first (via `/standards`), then run `node v2/docs/postman/generator/pm-build.mjs v2/docs/postman` from the repo root. The generator (`generator/pm-*.mjs`) mirrors the doc structure module-by-module — find the matching file (e.g. `pm-sales.mjs` for sales-order/delivery/invoice/sppb/profit) and update the request/example data there.
