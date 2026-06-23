# Backend Documentation — Venken ERP API v1.0

> **Purpose:** Migration reference. This document captures every endpoint, all critical business logic, known bugs, and the full SQL schema so the new system can replicate correct behavior and avoid inherited problems.

---

## 1. System Overview

| Property | Value |
|---|---|
| Language | PHP 8.x (procedural + OOP mixed) |
| Database | MySQL — database `migration_temp_venken` |
| Host | `127.0.0.1:3306`, user `movira_dev` |
| Auth | Firebase JWT — HS256, 10-hour expiry |
| Timezone | All datetime stored/returned in `Asia/Jakarta` (WIB, UTC+7) |
| Response envelope | `{"StatusCode": int, "Status": string, "Data": ...}` |

**No routing framework.** Each `.php` file is both the route and the handler. The URL path maps directly to the file path on disk.

---

## 2. Global Utilities (`general.php`)

| Function | Purpose |
|---|---|
| `logApiError(int $httpStatus, string $message, ...)` | Writes to `api_error_log`. Level: 500+ → `critical`, 404 → `warning`, else → `error`. |
| `jsonResponse(int $code, string $message, mixed $data)` | Canonical JSON responder. Calls `logApiError` automatically for 4xx/5xx. |
| `getCurrentDateTimeJakarta()` | Returns `Y-m-d H:i:s` in Jakarta timezone. |
| `normalizeDate(string $dateStr)` | Parses any date string → `Y-m-d` in Jakarta TZ. Falls back to `substr($str, 0, 10)`. |
| `cleanInput($conn, $value, ...)` | Trims + `mysqli_real_escape_string`. Returns `'-'` default for blank/zero. |
| `generateUUID()` | RFC 4122 v4 UUID using `random_bytes`. |

---

## 3. Auth & JWT (`auth/`)

### JWT Config
- **Secret:** `b9bbcf490c72f9ba65d34713d9052cb5134e3beb77dd4c8d1f287b47945bf50f`
- **Algorithm:** HS256
- **Expiry:** 36000 seconds (10 hours)
- **JWT payload fields:** `iat`, `exp`, `sub` (username), `firstName`, `lastName`, `permissionAccess`, `companyName`, `companyId`

### Middleware (`auth/middleware.php`)
```
verifyToken() → reads Authorization: Bearer <token> header → returns decoded payload object
```
Exits with 401 on missing token, expired token, or invalid signature.

> **CRITICAL ISSUE:** Most endpoint files do NOT call `verifyToken()`. Only a subset of newer endpoints use it. The majority of the API is effectively unauthenticated — any caller with the URL can access and mutate data.

---

## 4. Endpoints by Module

### 4.1 Authentication — `/user/`

| File | Method | Description |
|---|---|---|
| `login.php` | POST | Authenticate user, return JWT |
| `register.php` | POST | Register user using one-time verification code |
| `forgot-password.php` | POST | Initiate password reset |
| `reset-account.php` | POST | Complete password reset |
| `profile.php` | GET/POST | View/update user profile |
| `referral.php` | GET | Get referral/company info |

**`POST /user/login.php`**

Payload (`application/x-www-form-urlencoded`):
```
username, password
```
Logic: JOINs `user → permission → refferal → company`. Verifies password with `password_verify()`. Returns JWT + user info.

> **BUG:** The login query uses string interpolation (`"WHERE A1.username = '$username'"`) — **SQL injection vulnerability**. Must use prepared statements in the new system.

Response codes:
- `200` — success + token
- `203` — username not found (non-standard; should be 401)
- `204` — wrong password (non-standard; should be 401)

**`POST /user/register.php`**

Payload:
```
first_name, last_name, username, password, verification_code
```
Logic:
1. Validates verification code from `verification` table (checks `isUsed` and `expiredDt`)
2. Checks username uniqueness
3. `password_hash($password, PASSWORD_DEFAULT)`
4. Inserts into `user` with hardcoded `permission_id = 'a9b8390e-bfd8-11ee-9'` and `unique_id = 'FGr9km'`
5. Marks verification code as used

> **ISSUE:** `unique_id` is hardcoded to `'FGr9km'` (referral/company binding). New system must derive this dynamically from invitation context.

---

### 4.2 Company — `/company/`

| File | Method | Description |
|---|---|---|
| `company.php` | GET/POST/PUT | Get/create/update company details |
| `users.php` | GET | List users for a company |
| `permission.php` | GET/POST | Get/update user permission access |
| `targeting.php` | GET/POST/PUT | Annual sales targets per company |

---

### 4.3 Master Data — `/master/`

| Path | Method | Description |
|---|---|---|
| `customer/getallcustomer.php` | GET | All customers for a company |
| `customer/getdetailcustomer.php` | GET | Customer detail by `company_id` |
| `customer/insertcustomer.php` | POST | Create customer |
| `customer/updatecustomer.php` | POST | Update customer |
| `supplier/getallsupplier.php` | GET | All suppliers |
| `supplier/getallimportsupplier.php` | GET | Import-type suppliers |
| `supplier/getalllocalsupplier.php` | GET | Local suppliers |
| `supplier/getsupplierdetail.php` | GET | Supplier detail |
| `supplier/insertsupplier.php` | POST | Create supplier |
| `supplier/updatesupplier.php` | POST | Update supplier |
| `supplier/getcurrencybasedonsupplier.php` | GET | Currency for a supplier |
| `supplier/gettermbasedonsupplier.php` | GET | Payment term for a supplier |
| `finance/account-code.php` | GET/POST/PUT/DELETE | Chart of accounts CRUD |
| `finance/bank-account.php` | GET/POST/PUT | Bank account CRUD |
| `finance/currency.php` | GET/POST/PUT | Currency CRUD |
| `product/getallproduct.php` | GET | All products |
| `product/insertproduct.php` | POST | Create product |
| `product/updateproduct.php` | POST | Update product |
| `product/deleteproduct.php` | POST | Delete product |
| `uom/getalluom.php` | GET | Units of measure |
| `uom/insertuom.php` | POST | Create UOM |
| `origin/getorigin.php` | GET | Countries/origins |
| `term/getallterm.php` | GET | Payment terms |
| `ppn/getallppn.php` | GET | PPN (VAT) types |
| `shipVia/getallshipvia.php` | GET | Ship-via options |
| `shipping/getallshipping.php` | GET | Shipment methods |
| `settings/Setting.php` | GET | App menu settings |
| `getoveralldashboard.php` | GET | Dashboard summary |
| `getmonth.php` / `getyear.php` | GET | Month/year lookup lists |

---

### 4.4 Purchase — `/purchase/`

#### Purchase Order (PO) Lifecycle

```
Draft → Approved → On Delivery → Arrival at Port → Received → Invoiced
```

Status IDs are hardcoded UUIDs in the database (`purchaseStatus` table). The code sets these by string literal.

| File | Method | Description |
|---|---|---|
| `insertpurchaseimport.php` | POST | Create Import PO + items |
| `insertpurchaselocal.php` | POST | Create Local PO + items |
| `approvepurchase.php` | POST | Approve PO (status update + history) |
| `rejectpurchase.php` | POST | Reject PO |
| `revisiedpurchaseimport.php` | POST | Revise Import PO |
| `revisiedpurchaselocal.php` | POST | Revise Local PO |
| `ondeliverypurchase.php` | POST | Mark PO as "On Delivery" |
| `arrivalonportpurchase.php` | POST | Mark PO as "Arrived at Port" |
| `deliverwarehouse.php` | POST | Deliver to warehouse |
| `delivertocustomer.php` | POST | Deliver to customer |
| `invoice/insertinvoice.php` | POST | Create Purchase Invoice + items + financeItem |
| `receivingitems/insertreceivingitems.php` | POST | Record goods receipt |
| `getallpurchaseimport.php` | GET | List all Import POs |
| `getallpurchaselocal.php` | GET | List all Local POs |
| `getdetailpurchaseimport.php` | GET | Full detail of Import PO + items |
| `getdetailpurchaselocal.php` | GET | Full detail of Local PO + items |
| `getallpurchasereceive.php` | GET | All receiving records |
| `getallpurchaseinvoice.php` | GET | All purchase invoices |
| `findponumber.php` | GET | Search PO by number |
| `getpoblumiinvoice.php` | GET | POs not yet invoiced |
| `updatepurchaseimport.php` | POST | Update Import PO |
| `updatepurchaselocal.php` | POST | Update Local PO |
| `exportPDF.php` | GET | Export PO as PDF (TCPDF) |

**`POST /purchase/insertpurchaseimport.php`** — Create Import PO

Payload (form-data):
```
purchase_order_number, purchase_order_date, purchase_order_supplier,
purchase_order_shipment, purchase_order_term, purchase_order_payment,
purchase_order_origin, purchase_order_shippingmarks, purchase_order_remarks,
purchase_order_status, purchase_order_type, purchase_order_currency,
insert_by, product_length,
purchase_order_product_name_1..N, purchase_order_product_quantity_1..N,
purchase_order_product_packaging_size_1..N, purchase_order_product_unit_price_1..N
```

> **CRITICAL:** Date input format must be `"Www Mmm DD YYYY"` (e.g. `"Thu Jan 01 2026"`) — parsed by splitting on space and taking parts [1],[2],[3] then `DateTime::createFromFormat('M d Y', ...)`. This is a JavaScript `.toString()` output. **The new system should accept ISO 8601 instead.**

> **BUG:** Items loop is **not transactional**. The header `purchaseOrder` row is committed first. If any item insert fails, the header remains with no items and no error is returned. No `http_response_code(200)` or success echo after the loop completes.

> **BUG (local):** `insertpurchaselocal.php` has a `echo json_encode($purchase_order_product_name)` debug statement inside the item loop — this corrupts the JSON response.

**`POST /purchase/invoice/insertinvoice.php`** — Create Purchase Invoice

Payload:
```
purchase_order_number, purchase_order_supplier, invoice_number, invoice_date,
ship_date, kurs, term, username, insert_by, product_length, total_amount,
purchase_order_product_name_1..N, ...
```
Logic:
1. Checks for duplicate `invoice_number`
2. Inserts `purchaseInvoice`
3. Updates `purchaseOrder.POStatus = 'e4376c01-1438-11ef-9'` (Invoiced)
4. Inserts `PurchaseInvoiceItem` rows
5. Inserts `financeItem` with `paid_amount = 0`, `due_amount = total_amount` (creates the AP record)

> **NOTE:** VAT and Total are always stored as `0` in item tables. These are decorative — totals must be computed by the client.

**`POST /purchase/receivingitems/insertreceivingitems.php`** — Record Goods Receipt

Payload:
```
purchase_order_supplier, purchase_order_number, receiving_date, ship_date,
ship_via, insert_by, product_length, purchase_order_product_name_1..N, ...
```
Updates `purchaseOrder.POStatus = 'e73d9d9c-1438-11ef-9'` (Received).

> **BUG:** Same debug echo of product name inside loop. Same non-transactional issue.

---

### 4.5 Sales — `/sales/`

#### Sales Order (SO) Lifecycle

```
Draft → SPPB → Delivery Order → Sales Invoice → Profit
```

Status IDs (hardcoded in code):

| UUID | Stage |
|---|---|
| `6d352c3a-1efc-11ef-a` | Draft SO |
| `76b86e17-1efc-11ef-a` | SPPB (Surat Perintah Pengeluaran Barang) |
| `8096b9e4-1efc-11ef-a` | Delivery Order |
| `7c44858e-1efc-11ef-a` | Sales Invoice / Profit |

| File | Method | Description |
|---|---|---|
| `insertsalesorder.php` | POST | Create SO + items + history |
| `approvesalesorder.php` | POST | Approve SO |
| `rejectsalesorder.php` | POST | Reject SO |
| `revisedsalesorder.php` | POST | Revise SO |
| `deletesalesorder.php` | POST | Delete SO |
| `insertsppb.php` | POST | Create SPPB + items |
| `approvesppb.php` | POST | Approve SPPB |
| `rejectsppb.php` | POST | Reject SPPB |
| `revisedsppb.php` | POST | Revise SPPB |
| `insertdeliveryorder.php` | POST | Create Delivery Order + items |
| `approvedelivery.php` | POST | Approve DO |
| `rejectdelivery.php` | POST | Reject DO |
| `reviseddeliveryorder.php` | POST | Revise DO |
| `insertsalesinvoice.php` | POST | Create Sales Invoice + items + financeItem |
| `approvesalesinvoice.php` | POST | Approve Sales Invoice |
| `rejectsalesinvoice.php` | POST | Reject Sales Invoice |
| `insertsalesprofit.php` | POST | Create Profit record + items |
| `approveprofit.php` | POST | Approve Profit |
| `rejectprofit.php` | POST | Reject Profit |
| `revisedprofit.php` | POST | Revise Profit |
| `getallsalesorder.php` | GET | List SOs |
| `getdetailsalesorder.php` | GET | SO detail + items |
| `getallsppb.php` | GET | List SPPBs |
| `getdetailsppb.php` | GET | SPPB detail + items |
| `getalldeliveryorder.php` | GET | List DOs |
| `getdetaildelivery.php` | GET | DO detail + items |
| `getallsalesinvoice.php` | GET | List Sales Invoices |
| `getdetailsalesinvoice.php` | GET | Sales Invoice detail |
| `getallprofit.php` | GET | List Profit records |
| `getdetailprofit.php` | GET | Profit detail + items |
| `findsonumber.php` | GET | Search SO |
| `getsoblumiinvoice.php` | GET | SOs not yet invoiced |
| `SOExport.php` | GET | Export SO PDF |
| `SPPBExport.php` | GET | Export SPPB PDF |
| `SuratJalanExport.php` | GET | Export Surat Jalan (delivery note) PDF |
| `SalesInvoice.php` | GET | Export Sales Invoice PDF |
| `ProfitExport.php` | GET | Export Profit PDF |

**`POST /sales/insertsalesorder.php`** — Create Sales Order

Payload:
```
sales_order_number, sales_order_date (dd/MM/yyyy), sales_order_ppn,
sales_order_customer, sales_order_send_to (dd/MM/yyyy),
sales_order_send_date (dd/MM/yyyy), insert_by, product_length,
sales_order_PO_1..N, sales_order_product_name_1..N,
sales_order_product_quantity_1..N, sales_order_satuan_1..N,
sales_order_matauang_1..N, sales_order_hargasatuan_1..N, sales_order_kurs_1..N
```
> **NOTE:** Date format here is `dd/MM/yyyy` (`DateTime::createFromFormat('d/m/Y', ...)`). Inconsistent with purchase dates which use `'Www Mmm DD YYYY'`.

**`POST /sales/insertsalesinvoice.php`** — Create Sales Invoice

Payload:
```
customer_id, invoice_id, sales_order, invoice_date, ship_to, bill_to,
insert_by, product_length, total_amount,
SO_1..N, product_name_1..N, quantity_1..N, price_1..N, tax_1..N, do_number_1..N
```
Logic:
1. Inserts `salesInvoice`
2. Inserts `salesOrderHistory`
3. Inserts `financeItem` with `paid_amount=0`, `due_amount=total_amount` (creates AR record)
4. Updates `salesOrder.SOStatus`
5. Inserts `salesInvoiceItem` rows

> **BUG:** `salesInvoiceItem.DONumber` is set to `$invoice_id` (the invoice number), **not** `$do_number`. The column is mislabeled/misused.

> **BUG:** `insertsppb.php` calls `echo json_encode(...)` **inside** the item loop, producing multiple JSON objects concatenated — invalid JSON for any iteration > 1 item.

**`POST /sales/insertsalesprofit.php`** — Create Profit

Payload:
```
sales_number, customer_id, insert_by, product_length,
PO_1..N, SO_1..N, product_name_1..N, quantity_1..N, price_1..N, landed_cost_1..N
```
Records landed cost vs. sale price per product for margin analysis.

---

### 4.6 Finance — `/finance/`

| File | Method | Description |
|---|---|---|
| `insertpenerimaan.php` | POST | Record journal receipt (income) |
| `insertpembayaran.php` | POST | Record journal payment (expense) |
| `insertpenerimaanpenjualan.php` | POST | Record customer invoice payment (AR settlement) |
| `insertpenerimaanpembelian.php` | POST | Record supplier invoice payment (AP settlement) |
| `getbukukas.php` | GET | Cash book (bank statement view) |
| `getbukubesar.php` | GET | General ledger |
| `getlaporanlabarugi.php` | GET | Profit & Loss report |
| `getneraca.php` | GET | Balance sheet (Neraca) |
| `getomsetperbulan.php` | GET | Revenue per month |
| `getdetailomset.php` | GET | Revenue breakdown detail |
| `getoutstandingpayments.php` | GET | Outstanding invoices |
| `alloutstandingcustomer.php` | GET | All customer outstanding |
| `allaccountcode.php` | GET | All account codes |
| `allbankaccount.php` | GET | All bank accounts |
| `findaccountcodename.php` | GET | Search account code by name |
| `ARdocument.php` | GET | AR document list |
| `BukuKasdocument.php` | GET | Buku Kas document |
| `deletetransaction.php` | POST | Delete finance transaction |
| `setbeginningbalance.php` | POST | Set cash beginning balance |
| `setaccountbeginningbalance.php` | POST | Set account beginning balance |
| `showspurchaseinvoice.php` | GET | Purchase invoices for finance view |
| `showssalesinvoice.php` | GET | Sales invoices for finance view |
| `financestastics.php` | GET | Finance statistics dashboard |
| `gethpp.php` | GET | Cost of goods sold (HPP) |
| `getpenyusutan.php` | GET | Depreciation |
| `getallpembayaran.php` | GET | All payments |
| `getallpenerimaan.php` | GET | All receipts |
| `getallpenerimaanpembelian.php` | GET | All AP payments |
| `getallpenerimaanpenjualan.php` | GET | All AR collections |
| `getdetailpembayaran.php` | GET | Payment detail |
| `getdetailpenerimaan.php` | GET | Receipt detail |
| `exportbukukas.php` | GET | Export Cash Book (Excel/CSV) |
| `exportbukubesar.php` | GET | Export General Ledger |
| `exportlaborugi.php` | GET | Export P&L |
| `exportneraca.php` | GET | Export Balance Sheet |
| `exportomset.php` | GET | Export revenue report |
| `exportdetailomset.php` | GET | Export revenue detail |
| `exportoutstandingpayments.php` | GET | Export outstanding payments |

#### Finance Category UUIDs (hardcoded)

| UUID | Meaning |
|---|---|
| `174c61e8-226d-11ef-a` | Penerimaan (Receipt / Income) |
| `1d604104-226d-11ef-a` | Pembayaran (Payment / Expense) |

**`POST /finance/insertpenerimaan.php`** — Journal Receipt

Payload:
```
deposit_to (bank_account_id), voucher_no, date, memo, amount,
account_code, account_amount, account_memo, username
```
Inserts into `financeTransaction` with `finance_category = '174c61e8-226d-11ef-a'`.

**`POST /finance/insertpembayaran.php`** — Journal Payment

Payload:
```
paid_from (bank_account_id), voucher_no, cheque_no, date, memo, payee,
amount, account_code, account_amount, account_memo, username
```
Inserts into `financeTransaction` with `finance_category = '1d604104-226d-11ef-a'`.

**`POST /finance/insertpenerimaanpenjualan.php`** — Settle Customer Invoice (AR)

Payload:
```
customer_id, payment_date, form_no, bank_number, rate, cheque_no,
cheque_date, cheque_amount, memo, invoice_length, username,
invoice_number_1..N, invoice_date_1..N, invoice_amount_1..N, paymount_amount_1..N
```
> **NOTE:** `payment_date` may include timezone text like `"Thu Jan 01 2026 (WIB)"` — stripped with `preg_replace('/\s*\(.*\)$/', '', ...)` before `strtotime()`.

Per-invoice logic: `due_amount = invoice_amount - paymount_amount`. Updates `financeItem.due_amount` on the original row.

**`POST /finance/insertpenerimaanpembelian.php`** — Settle Supplier Invoice (AP)

Payload: same as above + `supplier_id, penerima, discount_amount_1..N`

> **CRITICAL LOGIC:** Due amount uses a **subquery-based source-of-truth calculation** (not a simple subtraction):
> ```sql
> due_amount = original_due_from_first_row - SUM(all_payments + all_discounts)
> ```
> This is intentional to handle partial payments correctly. The new system must replicate this.

**`GET /finance/getbukukas.php`** — Cash Book

Query params: `bank_account` (optional), `start_date`, `end_date`, `accountcode` (optional)

Logic:
1. Computes `beginning_balance` = all transactions before `start_date` + all `financeItem` payments before `start_date`
2. Fetches 3 data sets for the period and merges them in PHP: journal transactions, supplier payments from `financeItem`, customer receipts from `financeItem`
3. Sorts merged array by date in PHP (not SQL)
4. Computes `end_balance` by iterating transactions — income adds, expense subtracts, with `rate` applied to `financeItem` amounts
5. Special memo `__SALDO_AWAL__` = beginning balance sentinel, excluded from displayed rows

**`GET /finance/getlaporanlabarugi.php`** — Profit & Loss

Query params: `start_date`, `end_date`

Logic:
- Revenue = `financeTransaction` WHERE `finance_category = '174c61e8-...'` AND `account_code LIKE '4%'`
- Expense = `financeTransaction` WHERE `finance_category = '1d604104-...'` AND `account_code LIKE '5%' OR '6%'`
- Also sums customer `financeItem.paid_amount` for the period (sales receipt)
- Also sums supplier `financeItem.paid_amount` for the period (purchase payment)
- `laba_bersih = (revenue_journal + sales_receipt) - (expense_journal + purchase_payment)`

**`GET /finance/getneraca.php`** — Balance Sheet

Query param: `as_of_date` (defaults to today)

Assets:
- Cash/Bank: union of journal transactions (penerimaan + - pembayaran) and financeItem cash movements, grouped by bank_account
- Receivables (Piutang): `financeItem.due_amount > 0` linked to `salesInvoice → customer`

Liabilities:
- Payables (Hutang): `financeItem.due_amount > 0` linked to `purchaseInvoice → supplier`

Equity:
- Retained earnings = cumulative net of all journal entries

> **NOTE:** Balance sheet excludes fixed assets — only current assets and current liabilities exist in this system.

---

### 4.7 Warehouse — `/warehouse/`

| File | Method | Description |
|---|---|---|
| `productin.php` | POST | Record goods receipt (IN transaction) |
| `productout.php` | POST | Record goods dispatch (OUT transaction) |
| `getalltransaction.php` | GET | All warehouse transactions |
| `gettransactionbylot.php` | GET | Transactions for a specific lot |
| `getstokpersediaan.php` | GET | Current stock levels |
| `warehousestock.php` | GET | Warehouse stock summary |
| `summary.php` | GET | Warehouse summary dashboard |
| `weeklyreport.php` | GET | Weekly transaction report |
| `detaildeliveryorder.php` | GET | DO details for warehouse |
| `finddo.php` | GET | Find DO number |
| `productquery.php` | GET | Product lookup |
| `lotquery.php` | GET | Lot lookup |

**`POST /warehouse/productin.php`** — Product In

Payload:
```
lot, kodeProduk, jumlahBarang, unitOfMeasureID, keteranganBarang,
expDate, date, username
```
Logic:
1. Fetches `conversionFactor` from `unitOfMeasure`
2. `endBalance = jumlahBarang × conversionFactor`
3. Rejects if `lot` already exists in `warehouse`
4. Inserts `warehouse` with `beginningBalance = 0`, `endBalance`
5. Inserts `warehouseTransaction` with type `452c5015-e80f-4e8a-8` (IN)

**`POST /warehouse/productout.php`** — Product Out

Payload:
```
lot, kodeProduk, jumlahBarang, unitOfMeasureID, keteranganBarang, date, username
```
Logic:
1. Rejects if `lot` does NOT exist
2. Fetches `conversionFactor`
3. `quantityInStandardUnit = jumlahBarang × conversionFactor`
4. Inserts `warehouseTransaction` with type `9cafab5b-d975-41e8-8` (OUT)
5. `UPDATE warehouse SET endBalance = endBalance - quantityInStandardUnit`

> **NOTE:** There is no negative balance guard — stock can go negative. New system should enforce this.

#### Warehouse Transaction Type UUIDs

| UUID | Type |
|---|---|
| `452c5015-e80f-4e8a-8` | IN (product receipt) |
| `9cafab5b-d975-41e8-8` | OUT (product dispatch) |

---

### 4.8 HR — `/hr/HR.php`

Handles employee CRUD, salary categories, and salary transactions.

---

### 4.9 Verification — `/verification/`

| File | Method | Description |
|---|---|---|
| `verification.php` | POST | Generate verification code (stored in `verification` table) |
| `verificationaction.php` | POST | Validate/consume verification code |

---

### 4.10 Permission — `/permission/newpermission.php`

Creates new permission entries.

---

## 5. Critical Issues to Fix in New System

### Security
| # | Issue | Location |
|---|---|---|
| 1 | **SQL Injection** — string interpolation in queries | Most files: `login.php`, all `insertpurchase*.php`, `insertreceivingitems.php`, etc. |
| 2 | **No auth on most endpoints** — `verifyToken()` not called | ~80% of endpoint files |
| 3 | **JWT secret hardcoded** in `JWTConfig.php` | `auth/JWTConfig.php` |
| 4 | **DB credentials hardcoded** | `connection/connection.php` |
| 5 | **`display_errors = 1`** in production code | Every file header |

### Correctness / Bugs
| # | Issue | Location |
|---|---|---|
| 6 | **Debug echo inside item loop** corrupts JSON | `insertpurchaselocal.php`, `insertreceivingitems.php` |
| 7 | **Multiple JSON outputs** inside item loop | `insertsppb.php` |
| 8 | **`salesInvoiceItem.DONumber` stores invoice_id**, not DO number | `insertsalesinvoice.php:63` |
| 9 | **No DB transaction** — header committed before items loop | All insert endpoints with `product_length` loop |
| 10 | **No 200 success response** returned after item loop | `insertpurchaseimport.php`, `insertpurchaselocal.php`, `insertreceivingitems.php`, `insertsalesprofit.php` |
| 11 | **Warehouse stock can go negative** | `productout.php` |
| 12 | **Non-standard HTTP codes** for auth errors | `login.php` (203, 204 instead of 401) |
| 13 | **`register.php` hardcodes** `permission_id` and `unique_id` | `register.php:33,72` |
| 14 | **VAT and Total always stored as 0** in all item tables | All item inserts |

### Design / Migration Concerns
| # | Issue | Recommendation |
|---|---|---|
| 15 | **Inconsistent date input formats** — some files expect `"Www Mmm DD YYYY"` (JS `.toString()`), others `"dd/MM/yyyy"` | Standardize on ISO 8601 (`YYYY-MM-DD`) |
| 16 | **Item payload via indexed fields** (`product_name_1`, `product_name_2`) | Use JSON array body instead |
| 17 | **Hardcoded status/type/category UUIDs** in PHP code | Store in lookup table + reference by semantic constant |
| 18 | **Mixed procedural/OOP mysqli** — some use `mysqli_query($connect, ...)`, others `$connect->prepare(...)` | Standardize on prepared statements only |
| 19 | **Finance merges data in PHP** (3 queries + PHP sort) | Move to a single SQL UNION with ORDER BY |
| 20 | **`salesOrderHistory` used for non-SO events** (SPPB, DO, Invoice logs) | New system needs per-document audit tables |
| 21 | **Due amount on AP is simple subtraction** in customer endpoint vs. **subquery source-of-truth** in supplier endpoint | Unify approach; supplier subquery logic is more correct |

---

## 6. Hardcoded UUID Reference

All UUIDs below are stored as PKs in lookup tables but referenced as string literals throughout the PHP code.

### Purchase Order Status (`purchaseStatus.PO_Status_ID`)
| UUID (truncated) | Meaning |
|---|---|
| *(from `purchaseStatus` table)* | Draft, Approved, On Delivery, Arrived, Received |
| `e4376c01-1438-11ef-9` | Invoiced (set by `insertinvoice.php`) |
| `e73d9d9c-1438-11ef-9` | Received (set by `insertreceivingitems.php`) |

### Sales Order Status (`salesStatus.SO_Status_ID`)
| UUID (truncated) | Meaning |
|---|---|
| `6d352c3a-1efc-11ef-a` | Draft |
| `76b86e17-1efc-11ef-a` | SPPB stage |
| `8096b9e4-1efc-11ef-a` | Delivery Order stage |
| `7c44858e-1efc-11ef-a` | Sales Invoice / Profit stage |

### Finance Category (`finance_category.category_id`)
| UUID (truncated) | Meaning |
|---|---|
| `174c61e8-226d-11ef-a` | Penerimaan (Receipt/Income) |
| `1d604104-226d-11ef-a` | Pembayaran (Payment/Expense) |

### Warehouse Transaction Type (`warehouseTransaction.transactionType`)
| UUID (truncated) | Meaning |
|---|---|
| `452c5015-e80f-4e8a-8` | IN |
| `9cafab5b-d975-41e8-8` | OUT |

---

## 7. Standard Response Envelope

```json
{
  "StatusCode": 200,
  "Status": "Success",
  "Data": { ... }
}
```

Error:
```json
{
  "StatusCode": 400,
  "Status": "Error",
  "message": "Human-readable error"
}
```

> **NOTE:** Response key casing is inconsistent. Some files use `StatusCode`/`Status`, others use `statusCode`/`status`, and `message` vs `message`. New system must standardize.

---

## 8. Database Schema

### `user`
| Column | Type | Notes |
|---|---|---|
| `username` | varchar(500) | PK |
| `first_name` | varchar(500) | |
| `last_name` | varchar(500) | |
| `password` | varchar(500) | bcrypt |
| `permission_id` | char(20) | FK → `permission` |
| `unique_id` | varchar(100) | FK → `refferal.refferal_id` |

### `permission`
| Column | Type | Notes |
|---|---|---|
| `permission_id` | char(20) | PK |
| `permission_access` | varchar(500) | Stored in JWT on login |

### `refferal`
| Column | Type | Notes |
|---|---|---|
| `refferal_id` | varchar(100) | PK |
| `company` | varchar(500) | UNIQUE; FK → `company.company_id` |
| `limit_user` | int | Max users for this company |

### `company`
| Column | Type | Notes |
|---|---|---|
| `company_id` | varchar(500) | PK |
| `company_name` | varchar(500) | |
| `company_address` | varchar(500) | |
| `company_email` | varchar(255) | |
| `company_phone` | varchar(20) | |
| `company_web` | varchar(500) | |
| `company_industry` | varchar(300) | |

### `verification`
| Column | Type | Notes |
|---|---|---|
| `code` | int | Numeric code |
| `createdBy` | varchar(500) | FK → `user` |
| `createdDt` | datetime | |
| `expiredDt` | datetime | Checked on register |
| `isUsed` | tinyint(1) | 0=fresh, 1=consumed |
| `usedDt` | datetime | |

### `customer`
| Column | Type | Notes |
|---|---|---|
| `company_id` | varchar(500) | PK |
| `company` | varchar(500) | FK → `refferal.company` / `company.company_id` |
| `company_name` | varchar(500) | |
| `company_address` | varchar(500) | |
| `company_phone` | varchar(20) | |
| `company_pic_name` | varchar(500) | PIC contact person |
| `company_pic_contact` | varchar(100) | |
| `company_top` | int | Terms of payment (days) |

### `supplier`
| Column | Type | Notes |
|---|---|---|
| `supplier_id` | varchar(500) | PK |
| `company` | varchar(500) | FK → company |
| `supplier_name` | varchar(500) | |
| `supplier_origin` | int | FK → `origin.origin_id` |
| `supplier_address` | varchar(500) | |
| `supplier_phone` | varchar(20) | |
| `supplier_pic_name` | varchar(500) | |
| `supplier_pic_contact` | varchar(100) | |
| `supplier_currency` | char(20) | FK → `currency` |
| `supplier_term` | char(20) | FK → `term` |
| `supplier_bank_information` | varchar(9000) | Freetext bank info |

### `purchaseOrder`
| Column | Type | Notes |
|---|---|---|
| `PONumber` | varchar(500) | PK |
| `PODate` | date | |
| `POSupplier` | varchar(500) | FK → `supplier.supplier_id` |
| `POShipment` | char(20) | FK → `shipment` |
| `POShipmentDate` | date | Local PO only |
| `POTerm` | char(20) | FK → `term` |
| `POPayment` | char(20) | FK → `payment` |
| `POOrigin` | int | FK → `origin` |
| `POShippingMarks` | varchar(500) | |
| `PORemarks` | varchar(500) | |
| `POStatus` | char(20) | FK → `purchaseStatus` |
| `POType` | char(20) | FK → `purchaseType` |
| `POCurrency` | char(20) | FK → `currency` |
| `POPPN` | char(20) | FK → `salesPPNType` (PPN type, local PO) |
| `InsertBy` | varchar(500) | |
| `InsertDt` | datetime | |
| `UpdateBy` | varchar(500) | |
| `UpdateDt` | datetime | |

### `purchaseOrderItem`
| Column | Type | Notes |
|---|---|---|
| `PONumber` | varchar(500) | FK → `purchaseOrder` |
| `POProductName` | varchar(500) | |
| `POQuantity` | double | |
| `POPackagingSize` | double | |
| `POUnitPrice` | double | |
| `POVAT` | double | Always 0 — client computes |
| `POTotal` | double | Always 0 — client computes |

### `purchaseOrderHistory`
| Column | Type | Notes |
|---|---|---|
| `PONumber` | varchar(500) | FK → `purchaseOrder` |
| `Action` | varchar(500) | Human-readable log string |
| `ActionBy` | varchar(500) | |
| `ActionDt` | datetime | |

### `purchaseInvoice`
| Column | Type | Notes |
|---|---|---|
| `invoiceNumber` | varchar(500) | PK |
| `PONumber` | varchar(500) | FK → `purchaseOrder` |
| `supplier` | varchar(500) | FK → `supplier` |
| `invoiceDate` | date | |
| `shipDate` | date | |
| `kurs` | int | Exchange rate |
| `term` | char(20) | FK → `term` |
| `insertBy` | varchar(500) | |
| `insertDt` | datetime | |
| `updateBy` | varchar(500) | |
| `updateDt` | datetime | |

### `PurchaseInvoiceItem`
| Column | Type | Notes |
|---|---|---|
| `PONumber` | varchar(500) | FK → `purchaseOrder` |
| `ProductName` | varchar(500) | |
| `Quantity` | double | |
| `PackagingSize` | double | |
| `UnitPrice` | double | |
| `VAT` | double | Always 0 |
| `Total` | double | Always 0 |

### `purchaseRecieve`
| Column | Type | Notes |
|---|---|---|
| `supplierID` | varchar(500) | FK → `supplier` |
| `PONumber` | varchar(500) | FK → `purchaseOrder` |
| `ReceivingDate` | date | |
| `ShipDate` | date | |
| `ShipVia` | char(20) | FK → `shipVia` |
| `InsertBy` / `InsertDt` | | |
| `UpdateBy` / `UpdateDt` | | |

### `purchaseReceiveItem`
Same structure as `PurchaseInvoiceItem` — `PONumber`, `ProductName`, `Quantity`, `PackagingSize`, `UnitPrice`, `VAT`, `Total`.

### `salesOrder`
| Column | Type | Notes |
|---|---|---|
| `SONumber` | varchar(500) | PK |
| `SODate` | date | |
| `SOPPN` | char(20) | FK → `salesPPNType` |
| `SOCustomer` | varchar(500) | FK → `customer` |
| `SOSendTo` | date | Delivery destination date (misnamed; stores date) |
| `SOSendDate` | date | |
| `SOStatus` | char(20) | FK → `salesStatus` |
| `InsertBy` / `InsertDt` | | |
| `UpdateBy` / `UpdateDt` | | |

### `salesOrderItem`
| Column | Type | Notes |
|---|---|---|
| `salesOrderNumber` | varchar(500) | FK → `salesOrder` |
| `purchaseOrderNumber` | varchar(500) | Linked PO |
| `ProductName` | varchar(500) | |
| `Quantity` | double | |
| `Satuan` | varchar(500) | FK → `unitOfMeasure` |
| `MataUang` | char(20) | FK → `currency` |
| `HargaSatuan` | double | Unit price |
| `Kurs` | double | Exchange rate |

### `salesOrderHistory`
| Column | Type | Notes |
|---|---|---|
| `SONumber` | varchar(500) | Used for SO, SPPB, DO, Invoice logs |
| `Action` | varchar(500) | Human-readable |
| `ActionBy` | varchar(500) | |
| `ActionDt` | datetime | |

### `salesSPPB` (Surat Perintah Pengeluaran Barang)
| Column | Type | Notes |
|---|---|---|
| `SPPBNumber` | varchar(500) | |
| `SPPBDate` | date | |
| `SPPBCustomer` | varchar(500) | FK → `customer` |

### `salesSPPBItem`
| Column | Type | Notes |
|---|---|---|
| `SPPBNumber` | varchar(500) | |
| `PONumber` | varchar(500) | |
| `SendTo` | varchar(500) | Destination address |
| `SendDate` | date | |
| `ProductName` | varchar(500) | |
| `Quantity` | double | |
| `Measure` | varchar(500) | FK → UOM |
| `Description` | varchar(500) | |

### `salesDelivery`
| Column | Type | Notes |
|---|---|---|
| `DONumber` | varchar(500) | PK |
| `customerID` | varchar(500) | FK → `customer` |
| `PONumber` | varchar(500) | |
| `SONumber` | varchar(500) | FK → `salesOrder` |
| `DeliveryDate` | date | |
| `BillTo` | varchar(500) | |
| `ShipTo` | varchar(500) | |

### `salesDeliveryItem`
| Column | Type | Notes |
|---|---|---|
| `DeliveryOrder` | varchar(500) | Stores `SONumber`, not `DONumber` (see bug #8) |
| `productName` | varchar(500) | |
| `productQTY` | double | |
| `Keterangan` | varchar(500) | Notes |

### `salesInvoice`
| Column | Type | Notes |
|---|---|---|
| `invoiceNumber` | varchar(500) | |
| `customerID` | varchar(500) | FK → `customer` |
| `salesOrder` | varchar(500) | FK → `salesOrder` |
| `invoiceDate` | date | |
| `ShipTo` / `BillTo` | varchar(500) | |

### `salesInvoiceItem`
| Column | Type | Notes |
|---|---|---|
| `SONumber` | varchar(500) | |
| `productName` | varchar(500) | |
| `productQuantity` | double | |
| `unitPrice` | double | |
| `tax` | double | |
| `DONumber` | varchar(500) | **Stores invoice_id, not actual DO number (bug)** |

### `salesProfit`
| Column | Type | Notes |
|---|---|---|
| `SalesNumber` | varchar(500) | FK → `salesOrder` |
| `ProfitCustomer` | varchar(500) | FK → `customer` |

### `salesProfitItem`
| Column | Type | Notes |
|---|---|---|
| `PONumber` | varchar(500) | Source PO |
| `SalesOrderNumber` | varchar(500) | |
| `ProductName` | varchar(1000) | |
| `Quantity` | double | |
| `Price` | double | Sale price |
| `LandedCost` | double | Purchase/landed cost |

### `financeItem`
Central table linking all invoices to payment status.

| Column | Type | Notes |
|---|---|---|
| `id_transaction` | varchar(50) | UUID PK |
| `invoice_number` | varchar(500) | Links to `purchaseInvoice.invoiceNumber` or `salesInvoice.invoiceNumber` |
| `paid_amount` | bigint | Amount paid in this record |
| `due_amount` | bigint | Remaining due (updated on payment) |
| `customer` | varchar(500) | FK → `customer` (null for AP) |
| `supplier` | varchar(500) | FK → `supplier` (null for AR) |
| `paymentdate` | date | NULL on initial invoice creation |
| `formno` | varchar(500) | |
| `bank` | varchar(50) | FK → `bank_account` |
| `rate` | double | Exchange rate |
| `chequeno` | varchar(50) | |
| `chequedate` | date | |
| `chequeamount` | bigint | |
| `memo` | varchar(500) | |
| `penerima` | varchar(500) | Recipient name (AP only) |
| `discount_amount` | decimal(20,2) | AP discount |
| `insert_by` / `insert_dt` | | |
| `update_by` / `update_dt` | | |

> **KEY DESIGN:** The same table holds both the original invoice record (`paymentdate IS NULL`, `paid_amount = 0`) and subsequent payment records (`paymentdate IS NOT NULL`). Filtering `WHERE paymentdate IS NULL` gives the original invoice amount; filtering `WHERE paymentdate IS NOT NULL` gives payment history.

### `financeTransaction`
Journal entry (general ledger row).

| Column | Type | Notes |
|---|---|---|
| `id_transaction` | varchar(50) | UUID PK |
| `bank_account` | varchar(50) | FK → `bank_account` |
| `voucher_no` | varchar(500) | |
| `date` | date | |
| `memo` | varchar(500) | `'__SALDO_AWAL__'` = beginning balance sentinel |
| `amount` | decimal(20,2) | Positive; direction determined by `finance_category` |
| `accountcode` | varchar(500) | FK → `account_code.account_code` |
| `accountamount` | decimal(20,2) | Offsetting account amount |
| `accountmemo` | varchar(500) | |
| `chequeno` | varchar(500) | |
| `payee` | varchar(500) | |
| `finance_category` | char(20) | UUID — Penerimaan or Pembayaran |
| `insertby` / `insertdt` | | |
| `updateby` / `updatedt` | | |

### `financeLog`
| Column | Type | Notes |
|---|---|---|
| `id_transaction` | varchar(50) | FK → `financeTransaction` |
| `action` | varchar(500) | Bahasa Indonesia description |
| `actionBy` | varchar(500) | |
| `actionDt` | datetime | |

### `account_code`
| Column | Type | Notes |
|---|---|---|
| `account_code_id` | varchar(50) | PK (UUID) |
| `account_code` | varchar(50) | UNIQUE; used as FK everywhere |
| `account_code_name` | varchar(500) | |
| `account_code_name_alias` | varchar(500) | Display name |
| `account_type` | enum | `asset`, `liability`, `equity`, `revenue`, `expense` |
| `parent_account_code_id` | varchar(50) | Self-referential hierarchy |
| `is_active` | tinyint(1) | Default 1 |

> **IMPORTANT:** Account code prefix determines P&L treatment in reports:
> - `4xxx` = Revenue
> - `5xxx` / `6xxx` = Expense

### `bank_account`
| Column | Type | Notes |
|---|---|---|
| `bank_account_id` | varchar(50) | PK (UUID) |
| `bank_number` | varchar(50) | Account number |
| `bank_name` | varchar(500) | |
| `bank_branch` | varchar(500) | |
| `currency_id` | varchar(50) | FK → `currency` |
| `is_primary` | tinyint(1) | Default 0 |
| `company_id` | varchar(50) | FK → `company` |

### `currency`
| Column | Type | Notes |
|---|---|---|
| `currency_id` | varchar(50) | PK (UUID) |
| `currency_code` | char(3) | ISO 4217 (e.g. IDR, USD) |
| `currency_symbol` | varchar(5) | |
| `currency_name` | varchar(50) | |

### `warehouse`
| Column | Type | Notes |
|---|---|---|
| `lot` | varchar(20) | PK |
| `product` | varchar(500) | FK → `product.skuID` |
| `date` | date | Receipt date |
| `beginningbalance` | int | Always 0 for new lots |
| `endbalance` | int | Current stock in standard UOM unit |
| `insert_by` / `insert_dt` | | |
| `update_by` / `update_dt` | | |

### `warehouseTransaction`
| Column | Type | Notes |
|---|---|---|
| `id` | varchar(100) | PK (UUID via `UUID()` SQL function) |
| `lot` | varchar(20) | FK → `warehouse` |
| `product` | varchar(500) | |
| `transactionDate` | date | |
| `transactionType` | char(20) | UUID — IN or OUT |
| `quantity` | int | Raw quantity in input UOM |
| `unitOfMeasureID` | varchar(500) | FK → `unitOfMeasure` |
| `conversionFactor` | decimal(10,2) | Snapshot of factor at time of transaction |
| `expiredDt` | date | For IN transactions |
| `customer` | varchar(500) | For outbound (DO link) |
| `keterangan` | varchar(500) | |
| `insertBy` / `insertDt` | | |
| `updateBy` / `updateDt` | | |

### `unitOfMeasure`
| Column | Type | Notes |
|---|---|---|
| `uomID` | varchar(500) | PK |
| `uomName` | varchar(1000) | |
| `conversionFactor` | decimal(10,5) | Multiplier → base unit (e.g. 1 box = 12 pcs) |

### `product`
| Column | Type | Notes |
|---|---|---|
| `skuID` | varchar(500) | PK |
| `productName` | varchar(1000) | |
| `productDesc` | varchar(1000) | |
| `insertBy` / `insertDt` | | |
| `updateBy` / `updateDt` | | |

### `employee`
| Column | Type | Notes |
|---|---|---|
| `employee_id` | varchar(500) | PK |
| `username` | varchar(500) | FK → `user` |
| `employee_name` | varchar(500) | |
| `employee_pob` | varchar(500) | Place of birth |
| `employee_dob` | date | |
| `hire_date` | date | |
| `gender` | char(20) | FK → `gender` |
| `position` | varchar(500) | |

### `salary_transaction`
| Column | Type | Notes |
|---|---|---|
| `employee_id` | varchar(500) | FK → `employee` |
| `salary_category` | varchar(500) | FK → `salary_category` |
| `salary_amount` | int | |
| `insert_by` / `insert_dt` | | |

### `salary_category`
| Column | Type | Notes |
|---|---|---|
| `category_id` | varchar(500) | PK |
| `category_name` | varchar(500) | |
| `category_type` | varchar(500) | e.g. allowance, deduction |

### `api_error_log`
| Column | Type | Notes |
|---|---|---|
| `error_id` | varchar(50) | PK (random hex) |
| `error_level` | enum | `critical`, `error`, `warning` |
| `http_status` | int | |
| `endpoint` | varchar(255) | `REQUEST_URI` |
| `method` | varchar(10) | HTTP method |
| `error_message` | text | |
| `file` | varchar(255) | PHP file |
| `line` | int | |
| `user_identifier` | varchar(100) | From `$GLOBALS['_log_user']` |
| `company_id` | varchar(50) | |
| `request_id` | varchar(50) | Per-request UUID |
| `ip_address` | varchar(45) | |
| `user_agent` | varchar(255) | |
| `created_at` | datetime | |

### Other Lookup Tables

| Table | Key Columns | Purpose |
|---|---|---|
| `origin` | `origin_id`, `origin_name`, `origin_region`, `origin_is_free_trade` | Country/origin for imports |
| `region` | `region_id`, `region_name` | Geographic regions |
| `term` | `term_id`, `term_name` | Payment terms (e.g. NET30) |
| `payment` | `payment_id`, `payment_name` | Payment methods |
| `shipVia` | `shipID`, `shipName` | Ship via options |
| `shipment` | `shipment_id`, `shipment_name` | Shipment methods |
| `salesPPNType` | `PPNType_id`, `PPNType_name`, `PPNPercentage` | PPN (VAT) percentages |
| `purchaseStatus` | `PO_Status_ID`, `PO_Status_Name` | PO status lookup |
| `purchaseType` | `PO_Type_ID`, `PO_Type_Name` | Import vs Local |
| `salesStatus` | `SO_Status_ID`, `SO_Status_Name` | SO lifecycle statuses |
| `finance_category` | `category_id`, `category_name` | Penerimaan / Pembayaran |
| `warehouseCategory` | `id`, `category_name` | Warehouse categories |
| `gender` | `id`, `gender_name` | |
| `month` | `month_id`, `month_name` | |
| `year` | `year` | |
| `targeting` | `targeting_id`, `company`, `target_year`, `target_value` | Annual sales targets |
| `documentCenter` | `documentID`, `documentName`, `documentFileSize`, `insertBy`, `insertDt` | File attachments |
| `documentWatermark` | `id`, `watermark (longblob)` | Watermark image for PDF exports |
| `settingMenu` | `settingId`, `companyId`, `settingImage (longblob)`, `settingName`, `settingCaption` | Company app menus |
| `settingDetail` | FK → `settingMenu`, tabs/data config | Menu configuration detail |

---

## 9. Key Business Flow Summary

```
PURCHASE FLOW
Client creates PO → approve → on delivery → arrival at port → receive items
→ create purchase invoice → financeItem created (due_amount = invoice total)
→ supplier pays: insertpenerimaanpembelian → financeItem updated (due_amount reduced)

SALES FLOW
Create SO (draft) → approve → create SPPB → create DO → create Sales Invoice
→ financeItem created (due_amount = invoice total)
→ customer pays: insertpenerimaanpenjualan → financeItem updated (due_amount reduced)
→ create Profit record (price vs landed_cost per product)

WAREHOUSE FLOW
productin (new lot, beginningBalance=0, endBalance=qty×UOM factor)
productout (existing lot, endBalance -= qty×UOM factor)
Stock = SUM(endBalance) per product across lots

FINANCE REPORTING
Buku Kas = 3-query merge (journal + AP payments + AR collections), sorted by date in PHP
P&L = journal account_code prefix (4=revenue, 5/6=expense) + financeItem paid_amounts
Balance Sheet = kas_bank + piutang_usaha vs hutang_usaha + laba_ditahan
```
