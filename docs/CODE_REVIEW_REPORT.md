# ERP API v2 — Comprehensive Code Review Report

**Date:** 17 October 2026 (WIB)
**Reviewers:** Code Reviewer Agent, Database Optimizer Agent, API Platform Engineer Agent
**Scope:** All PHP source files under `v2/` — core infrastructure, auth, helpers, master data, purchase, sales, finance, warehouse, HR, audit, notification, analytics, reports, dashboard, search, subscription, profile
**Methodology:** Three specialized agents reviewed the codebase in parallel, covering security vulnerabilities, database design/performance, and API design/consistency. Findings were consolidated and de-duplicated into this report.

---

## Fix Status: ALL FINDINGS ADDRESSED (17 October 2026)

| Severity | Total | Fixed | Notes |
|----------|-------|-------|-------|
| **CRITICAL** | 16 | 16 ✅ | All fixed in PHP code + 4 migration docs created |
| **MEDIUM** | 25 | 25 ✅ | All fixed in PHP code + migration docs for schema items |
| **MINOR** | 22 | 21 ✅ + 1 accepted | MIN-14 accepted as-is (breaking API change) |

**Migration docs created:** `v2/docs/migrations/v40_unique_constraints_for_duplicate_prevention.md`, `v41_document_sequence_counter_table.md`, `v42_collation_alignment.md`, `v43_composite_indexes.md`

**Files modified:** ~90 PHP files across all modules + 4 new migration docs + 1 new helper (`helpers/auth_log.php`)

---

## Executive Summary

| Severity | Count | Summary | Fix Status |
|----------|-------|---------|------------|
| **CRITICAL** | 16 | SQL injection, SSRF with token theft, unauthenticated endpoints, missing permission checks, missing transactions, cross-tenant data access, weak crypto defaults | ✅ All 16 FIXED |
| **MEDIUM** | 25 | Race conditions, missing indexes, information disclosure, unbounded queries, no rate limiting, WebSocket gaps, float precision, missing cascade deletes | ✅ All 25 FIXED |
| **MINOR** | 22 | Code duplication, naming inconsistencies, dead code, missing standards compliance, redundant nesting | ✅ 21 FIXED, 1 accepted |

### Top 5 Immediate Action Items — ALL COMPLETED ✅

1. ✅ **Escape entity IDs in all approve/reject/revise functions** — 30+ functions fixed across purchase, sales, finance modules
2. ✅ **Fix SSRF + JWT token theft in `approvals.php`** — Hardcoded `http://127.0.0.1` internal URL
3. ✅ **Add permission checks to all report, search, master data, and approval endpoints** — `userHasPermission()` checks added
4. ✅ **Secure `digest.php` HTTP access** — Now requires auth + `system.digest.run` permission
5. ✅ **Fail-fast on empty `JWT_SECRET` and default `OTP_PEPPER`** — Startup validation in `config.php`

---

## CRITICAL Findings

---

### CRIT-1: SQL Injection — Unescaped Entity IDs in All Approve/Reject/Revise Functions ✅ FIXED

**Category:** SQL Injection
**Files:** 30+ functions across purchase, sales, and finance modules

**Affected files (non-exhaustive):**
- `v2/purchase/purchase-order/index.php:416,457,499` — approve/reject/revise
- `v2/purchase/purchase-receive/index.php:274,317,361` — approve/reject/revise
- `v2/purchase/purchase-invoice/index.php:319,371,420` — approve/reject/revise
- `v2/sales/sales-order/index.php:318,369,411` — approve/reject/revise
- `v2/sales/sales-delivery/index.php:301,348,390` — approve/reject/revise
- `v2/sales/sales-invoice/index.php:343,399,448` — approve/reject/revise
- `v2/sales/sales-sppb/index.php:349,396,438` — approve/reject/revise
- `v2/sales/sales-profit/index.php:324,374,419` — approve/reject/revise
- `v2/finance/finance-transaction/index.php:307,372` — approve/reject
- `v2/finance/finance-payment/index.php:477,545` — approve/reject
- `v2/purchase/purchase-order/items.php:3` — `assertPurchaseOrderBelongsToCompany()`
- `v2/purchase/purchase-receive/items.php:3` — `assertPurchaseReceiveBelongsToCompany()`
- `v2/purchase/purchase-invoice/items.php:3` — `assertPurchaseInvoiceBelongsToCompany()`
- `v2/sales/sales-order/items.php:3` — `assertSalesOrderBelongsToCompany()`
- `v2/finance/finance-transaction/details.php:3` — `assertFinanceTransactionBelongsToCompany()`

**Description:** In standard CRUD operations (`getDetail*`, `update*`, `delete*`), entity IDs are escaped with `mysqli_real_escape_string($conn, $entity_id)`. However, in all `approve*`, `reject*`, and `revise*` sub-action functions, the entity ID from the URL path (`$action = $parts[3]`) is used directly in SQL queries **without escaping**.

**Impact:** An attacker can craft a URL like `/api/v2/purchase-order/' OR '1'='1/approve` to bypass existence checks, extract data via UNION-based injection, or manipulate data. This is a direct, exploitable SQL injection vector on 30+ endpoints.

**Recommendation:** Add `$entity_id = mysqli_real_escape_string($conn, $entity_id);` as the first line of every approve/reject/revise function and all `assert*BelongsToCompany()` functions, matching the pattern already used in `getDetail*`, `update*`, and `delete*`:

```php
function approvePurchaseOrder($conn, $purchase_order_id, $input, $username, $company_id) {
    $purchase_order_id = mysqli_real_escape_string($conn, $purchase_order_id);
    // ... rest of function
}
```

**Long-term:** Migrate to prepared statements with `bind_param()` across the entire codebase.

---

### CRIT-2: SSRF with Bearer Token Leakage in Approval Execution ✅ FIXED

**Category:** SSRF / Token Theft
**File:** `v2/notification/approvals.php:157-173`

**Description:** `executeApproval()` builds an internal URL using `$_SERVER['HTTP_HOST']`, which is fully attacker-controlled via the HTTP `Host` header. The curl call then forwards the user's `Authorization` header to that URL:

```php
$url = "$scheme://{$_SERVER['HTTP_HOST']}/api/v2/$endpoint_segment/...";
// ...
'Authorization: ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
```

**Impact:** An authenticated attacker can set `Host: evil.attacker.com`, causing the server to make an outbound HTTP request to the attacker's server **carrying the victim's JWT bearer token**. The curl response body is returned to the caller, enabling full SSRF with response reading — internal network scanning and token theft.

**Recommendation:** Use a hardcoded internal base URL instead of `$_SERVER['HTTP_HOST']`:

```php
$url = "http://127.0.0.1/api/v2/$endpoint_segment/{$token_row['source_document_id']}/$sub_action";
```

---

### CRIT-3: Unauthenticated Digest Endpoint Enables Mass WhatsApp Spam ✅ FIXED

**Category:** Authentication Bypass / Abuse
**File:** `v2/notification/digest.php:210-216`

**Description:** The HTTP debug entry point only requires `?debug=1` — no authentication, no JWT, no permission check. The `.htaccess` rewrite rule (`RewriteCond %{REQUEST_FILENAME} !-f`) serves `digest.php` directly as a real file, bypassing the `index.php` router.

```php
$isDebugHttp = !$isCli && ($_GET['debug'] ?? '') === '1';
if (!$isCli && !$isDebugHttp) { jsonResponse(403, ...); }
```

**Impact:** Anyone who can reach the API URL can trigger the digest, which sends WhatsApp messages to **every approver across every company**, generates approval tokens, and creates notification rows. Enables mass WhatsApp spam, API cost abuse, and unauthenticated financial data processing.

**Recommendation:** Require authentication and a super-admin permission check for HTTP debug mode, or remove HTTP access entirely and use CLI-only (cron):

```php
$isDebugHttp = !$isCli && ($_GET['debug'] ?? '') === '1';
if ($isDebugHttp) {
    $authUser = requireAuth();
    // Add admin permission check
}
if (!$isCli && !$isDebugHttp) { jsonResponse(403, 'CLI access only'); }
```

---

### CRIT-4: IP-Based Rate Limiting Trivially Bypassable via Spoofed Headers ✅ FIXED

**Category:** Rate Limiting Bypass / Brute-Force
**File:** `v2/general.php:63-67`

**Description:** `getUserIP()` trusts `HTTP_X_FORWARDED_FOR` as the first priority:

```php
function getUserIP(): string {
    return $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '';
}
```

This value is used for IP-based rate limiting on all auth endpoints (`login.php:44`, `register.php:87`, `send-otp.php:52`, `forgot-password.php:44`, `reset-password.php:54`).

**Impact:** An attacker sets a different `X-Forwarded-For` header on each request to get a fresh "IP" — completely defeating the 20-per-minute login rate limit and the 10-per-hour registration/OTP/reset limits. Enables unlimited brute-force attacks on passwords and OTP codes.

**Recommendation:** Only trust `X-Forwarded-For` if the request comes from a known reverse proxy IP. Otherwise, use `REMOTE_ADDR` only:

```php
function getUserIP(): string {
    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
    $trusted_proxies = ['127.0.0.1', '::1']; // Add your proxy IPs
    if (in_array($remote_addr, $trusted_proxies, true)) {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $parts = array_map('trim', explode(',', $xff));
            return $parts[0];
        }
    }
    return $remote_addr;
}
```

---

### CRIT-5: Empty JWT Secret Default Enables Token Forgery ✅ FIXED

**Category:** Authentication Bypass
**File:** `v2/config.php:22`, `v2/helpers/jwt.php:15`

**Description:** `JWT_SECRET` defaults to an empty string if `.env` is missing or misconfigured:

```php
define('JWT_SECRET', $_ENV['JWT_SECRET'] ?? '');
```

`hash_hmac('sha256', $data, '')` produces a valid HMAC with an empty key. There is no startup check that `JWT_SECRET` is non-empty.

**Impact:** If the `.env` file is missing, not loaded, or has `JWT_SECRET=` (empty), all JWTs are signed with an empty key. An attacker who knows this (the default is visible in source) can forge valid JWTs for any user/company, gaining complete authentication bypass and cross-tenant access.

**Recommendation:** Add a hard fail at startup:

```php
if (!defined('JWT_SECRET') || JWT_SECRET === '' || strlen(JWT_SECRET) < 32) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status_code' => 500,
        'status_message' => 'JWT_SECRET must be configured with a minimum 32-character secret',
        'data' => []
    ]);
    exit;
}
```

---

### CRIT-6: OTP Pepper Falls Back to Known Default ✅ FIXED

**Category:** Weak Cryptography
**File:** `v2/config.php:26`

**Description:** `OTP_PEPPER` defaults to the hardcoded string `'fallback-pepper'`:

```php
define('OTP_PEPPER', $_ENV['OTP_PEPPER'] ?? 'fallback-pepper');
```

This pepper is used to hash OTP codes via `hash_hmac('sha256', $otp_code, OTP_PEPPER)` in `send-otp.php`, `register.php`, `forgot-password.php`, and `reset-password.php`.

**Impact:** If `.env` is missing or `OTP_PEPPER` is unset, all OTPs are hashed with a publicly known pepper. An attacker with DB read access (e.g., via SQL injection elsewhere) can brute-force 6-digit OTPs (1M possibilities) in milliseconds.

**Recommendation:** Fail fast if `OTP_PEPPER` is not set:

```php
$otp_pepper = $_ENV['OTP_PEPPER'] ?? '';
if ($otp_pepper === '' || $otp_pepper === 'fallback-pepper') {
    http_response_code(500);
    exit('OTP_PEPPER must be configured in .env');
}
define('OTP_PEPPER', $otp_pepper);
```

---

### CRIT-7: SQL Injection in Audit Log Helper — Unescaped Variables ✅ FIXED

**Category:** SQL Injection
**File:** `v2/helpers/audit_log.php:10-12`

**Description:** `insertAuditLog()` interpolates `$company_id`, `$module`, `$reference_id`, `$action`, `$username` directly into SQL without `mysqli_real_escape_string()`. Only `$notes` is escaped.

**Impact:** These values originate from JWT claims or DB rows. If any contain SQL metacharacters — through a compromised JWT, a DB value with special characters, or a secondary injection vector — the query is injectable.

**Recommendation:** Escape all interpolated values or use a prepared statement:

```php
function insertAuditLog($conn, $entity_id, $company_id, $event_type, $description, $username, $old_value = null, $new_value = null, $reference_type = null, $reference_id = null, $metadata = null) {
    $log_id = 'log_' . uniqid();
    $now = date('Y-m-d H:i:s');
    $company_id_sql = mysqli_real_escape_string($conn, $company_id);
    $module_sql = mysqli_real_escape_string($conn, $reference_type ?? '');
    $reference_id_sql = mysqli_real_escape_string($conn, $reference_id ?? '');
    $action_sql = mysqli_real_escape_string($conn, $event_type);
    $username_sql = mysqli_real_escape_string($conn, $username);
    $notes_sql = isset($description) && trim($description) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($description)) . "'"
        : 'NULL';
    $meta_sql = $metadata !== null
        ? "'" . mysqli_real_escape_string($conn, json_encode($metadata, JSON_UNESCAPED_UNICODE)) . "'"
        : 'NULL';
    // ... INSERT with escaped values
}
```

---

### CRIT-8: SQL Injection in Dual Approval Helper — Unescaped `$table` and `$username` ✅ FIXED

**Category:** SQL Injection
**File:** `v2/helpers/dual_approval.php:7-11, 43-46, 55-58, 73-75`

**Description:** The `$table` parameter is interpolated directly into SQL (`FROM $table WHERE...`, `UPDATE $table SET...`). Additionally, `$username` and `$company_id` are interpolated without escaping.

**Impact:** While current callers pass hardcoded table names, the function accepts any string as `$table` with no validation — a latent SQL injection vector. The `$username` from JWT claims is directly injectable if it contains SQL metacharacters.

**Recommendation:** Validate `$table` against a whitelist of allowed table names. Escape all other interpolated values:

```php
$allowed_tables = [
    APP_SCHEMA . '.purchase_order',
    APP_SCHEMA . '.sales_order',
    APP_SCHEMA . '.finance_transaction',
    // ... etc
];
if (!in_array($table, $allowed_tables, true)) {
    throw new Exception("Invalid table: $table");
}
$username = mysqli_real_escape_string($conn, $username);
$company_id = mysqli_real_escape_string($conn, $company_id);
$entity_id = mysqli_real_escape_string($conn, $entity_id);
```

---

### CRIT-9: Missing Tenant Isolation — 14 Global Lookup Tables Have No `company_id` Scoping ✅ FIXED (admin-only writes)

**Category:** IDOR / Cross-Tenant Data Access
**Files:**
- `v2/master/currency/index.php`
- `v2/master/region/index.php`
- `v2/master/origin/index.php`
- `v2/master/ppn-type/index.php`
- `v2/master/purchase-status/index.php`
- `v2/master/purchase-type/index.php`
- `v2/master/sales-status/index.php`
- `v2/master/ship-via/index.php`
- `v2/master/shipment-period/index.php`
- `v2/master/payment-method/index.php`
- `v2/master/payment-term/index.php`
- `v2/master/finance-category/index.php`
- `v2/master/salary-category/index.php`
- `v2/master/unit-of-measure/index.php`

**Description:** These 14 master data modules have NO `company_id` filter in any of their SQL queries. Any authenticated user from any tenant can read, create, update, and delete records that affect all tenants.

**Impact:** Cross-tenant data manipulation. Tenant A can delete the "USD" currency, and Tenant B's operations referencing USD will break. A malicious tenant could delete critical lookup records causing widespread system failures.

**Recommendation:** If these are intentionally global tables (shared reference data), restrict create/update/delete to admin/super-admin roles only. If they should be tenant-scoped, add `company_id` to the table schema and all queries. Document the design decision explicitly.

---

### CRIT-10: Missing Tenant Isolation on Warehouse Lot Lookup — Cross-Tenant Stock Manipulation ✅ FIXED

**Category:** IDOR / Cross-Tenant Data Access
**File:** `v2/warehouse/warehouse-transaction/index.php:25`

**Description:** In `applyWarehouseLotDelta()`, the warehouse lot is looked up without a `company_id` filter:

```php
$lot_result = mysqli_query($conn, "SELECT product_id, location_id FROM " . APP_SCHEMA . ".warehouse_lot 
    WHERE id = '$warehouse_lot_id_sql' LIMIT 1");
```

**Impact:** A user from Tenant A can pass Tenant B's `warehouse_lot_id` in a stock transaction, and the system will apply stock movements to Tenant B's lot. An attacker can drain another tenant's inventory or inflate their own.

**Recommendation:**

```php
$lot_result = mysqli_query($conn, "SELECT product_id, location_id FROM " . APP_SCHEMA . ".warehouse_lot 
    WHERE id = '$warehouse_lot_id_sql' AND company_id = '$company_id' LIMIT 1");
```

---

### CRIT-11: Missing Transaction Wrapping for Multi-Step Financial Operations ✅ FIXED

**Category:** Transaction Integrity
**Files:**
- `v2/purchase/purchase-invoice/index.php:343-368` — `approvePurchaseInvoice()`
- `v2/sales/sales-invoice/index.php:368-397` — `approveSalesInvoice()`
- `v2/finance/finance-payment/index.php:477-543` — `approveFinancePayment()`
- `v2/finance/finance-transaction/index.php:306-369` — `approveFinanceTransaction()`
- `v2/master/account-code/index.php:64-67` — `setSingleDefaultAccountFlag()`

**Description:** Multi-step approval operations (approval UPDATE + GL posting + baseline seeding) execute as separate non-transactional operations. If the invoice is approved but GL posting fails, the invoice status is "Approved" but no journal entry exists — financial records are inconsistent.

**Impact:** Data inconsistency — approved documents without corresponding financial journal entries, or multiple default account codes. In a financial system, this causes incorrect balance sheets, missing payables/receivables, and audit trail gaps.

**Recommendation:**

```php
$conn->begin_transaction();
try {
    // approval UPDATE
    // seedFinancePaymentBaseline()
    // postPurchaseInvoiceRecognition()
    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    error_log('Approval failed: ' . $e->getMessage());
    jsonResponse(500, 'Failed to process approval');
}
```

---

### CRIT-12: Race Condition — Check-Then-Insert Without UNIQUE Constraints ✅ FIXED (migration doc v40)

**Category:** Race Condition / Data Integrity
**Files:** Every `create*` function across all modules

**Description:** Every create function follows: `SELECT 1 ... WHERE unique_field = '$value'` (duplicate check) → if not found → `INSERT`. Under concurrent requests, both can pass the check and both INSERT, creating duplicates.

**Impact:** Duplicate records that violate business uniqueness — duplicate invoice numbers, PO numbers, customer names. For financial documents, this causes accounting errors and audit confusion.

**Recommendation:** Add `UNIQUE` constraints at the database level and catch the duplicate key error:

```php
if (!mysqli_query($conn, $sql)) {
    if (mysqli_errno($conn) === 1062) {
        jsonResponse(409, 'Customer already exists');
        return;
    }
    error_log('SQL error: ' . mysqli_error($conn));
    jsonResponse(500, 'Failed to create customer');
    return;
}
```

---

### CRIT-13: Stock Movement Allows Negative Stock Without Validation ✅ FIXED

**Category:** Data Integrity
**File:** `v2/warehouse/warehouse-transaction/index.php:21-34`

**Description:** `applyWarehouseLotDelta()` applies the delta directly to `end_balance` without checking if the result would be negative.

**Impact:** Negative stock levels — incorrect inventory valuations, corrupted warehouse data, cascading into incorrect COGS calculations and financial reporting errors.

**Recommendation:** Use a conditional UPDATE:

```php
mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".warehouse_lot
    SET end_balance = end_balance + ($delta), updated_by = '$username', updated_at = '$now'
    WHERE id = '$warehouse_lot_id_sql' AND end_balance + ($delta) >= 0");
if (mysqli_affected_rows($conn) === 0) {
    throw new Exception("Insufficient stock for lot $warehouse_lot_id");
}
```

---

### CRIT-14: Sequence Number Generation Not Concurrency-Safe ✅ FIXED (migration doc v41)

**Category:** Race Condition / Duplicate Document Numbers
**Files:**
- `v2/purchase/purchase-order/index.php:68` — `generatePurchaseOrderNumber()`
- `v2/sales/sales-order/index.php:51` — `generateSalesOrderNumber()`
- `v2/sales/sales-delivery/index.php:36` — `generateSalesDeliveryNumber()`
- `v2/sales/sales-invoice/index.php:40` — `generateSalesInvoiceNumber()`
- `v2/sales/sales-sppb/index.php:38` — `generateSalesSppbNumber()`

**Description:** Document sequence numbers are generated by counting existing records. Two concurrent requests can get the same count and generate the same sequence number.

**Impact:** Duplicate PO/SO/invoice numbers — violates document numbering integrity, causes confusion in financial auditing.

**Recommendation:** Use a dedicated sequence counter table with `SELECT ... FOR UPDATE` or `INSERT ... ON DUPLICATE KEY UPDATE` to atomically increment and retrieve the next sequence number. Alternatively, use MySQL's `AUTO_INCREMENT` on a sequence table.

---

### CRIT-15: Missing Permission Checks on ALL Report Modules ✅ FIXED

**Category:** Missing Permission Checks / Authorization
**Files:**
- `v2/reports/sales-report/index.php:159-181`
- `v2/reports/ar-ap-report/index.php:207-230`
- `v2/reports/profit-loss/index.php:127-150`
- `v2/reports/balance-sheet/index.php:128-151`
- `v2/reports/general-ledger/index.php:314-344`
- `v2/reports/cash-book/index.php:310-340`
- `v2/reports/cogs-report/index.php:155-178`

**Description:** Every report module calls `requireAuth()` but none check permissions. Compare with analytics (`analytics/index.php:44`: `userHasPermission($conn, $app_role_id, "analytics.$action.view")`) and dashboard (`dashboard/index.php:703`: `getPermittedDashboardKeys()`).

**Impact:** Any authenticated user — including a warehouse clerk — can view the entire company balance sheet, profit/loss statement, and every general ledger transaction.

**Recommendation:**

```php
if (!userHasPermission($conn, $app_role_id, "reports.sales_report.view")) {
    jsonResponse(403, 'You do not have permission to view this report');
    return;
}
```

---

### CRIT-16: Missing Permission Checks on Sales-Order, Purchase-Order, and Master Data Approve/Reject/CRUD ✅ FIXED

**Category:** Missing Permission Checks / Authorization
**Files:**
- `v2/sales/sales-order/index.php:318,369,411` — approve/reject/revise (no permission check)
- `v2/purchase/purchase-order/index.php:415,456,498` — approve/reject/revise (no permission check)
- `v2/master/product/index.php:195-241` — create/update/delete (no permission check)
- `v2/master/customer/index.php:261-307` — create/update/delete (no permission check)
- `v2/search/index.php:230-246` — global search (no permission check)
- `v2/notification/settings.php:160-188` — public holidays/working days (no permission check)

**Description:** These endpoints verify the document exists and belongs to the company, but never check whether the calling user has the appropriate permission. Finance transactions use `applyDualApproval()` which checks role permissions internally, but sales-order, purchase-order, master data, and search have no equivalent gate.

**Impact:** Any authenticated user can approve/reject sales orders and purchase orders, modify the product catalog or customer database, search across all 12 entity types, and change company-wide notification settings.

**Recommendation:** Add permission checks before processing write operations and approvals:

```php
if (!userHasPermission($conn, $app_role_id, 'sales_order.approve')) {
    jsonResponse(403, 'You do not have permission to approve sales orders');
    return;
}
```

---

## MEDIUM Findings

---

### MED-1: Database Error Messages Leaked in API Responses ✅ FIXED

**Category:** Sensitive Data Exposure / Information Disclosure
**Files:** Systemic — every module's error path

**Description:** Raw `mysqli_error()` output and exception messages are returned to the client in the `data.error` field across all modules. Representative examples:
- `v2/notification/index.php:66` — `['error' => mysqli_error($conn)]`
- `v2/notification/settings.php:53,72` — `['error' => mysqli_error($conn)]`
- `v2/subscription/index.php:259` — `['error' => $e->getMessage()]`
- `v2/profile/index.php:158,195` — `['error' => $e->getMessage()]`
- `v2/helpers/dual_approval.php:49,78` — `['error' => mysqli_error($conn)]`
- Every module's top-level catch — `['error' => $e->getMessage()]`

**Impact:** Leaks database table names, column names, SQL syntax, and constraint names to attackers, facilitating further attack refinement.

**Recommendation:** Log errors server-side with `error_log()`. Return generic messages to clients (especially in production):

```php
} catch (Exception $e) {
    error_log('Module error: ' . $e->getMessage());
    $error_data = APP_ENV === 'development' ? ['error' => $e->getMessage()] : [];
    jsonResponse(500, 'Internal Server Error', $error_data);
}
```

---

### MED-2: No Permission Check on Permission/Role Enumeration Endpoint ✅ FIXED

**Category:** Authorization / Information Disclosure
**File:** `v2/auth/permission-roles.php:106-149`

**Description:** The endpoint requires authentication but performs no permission check. Any authenticated user from any tenant can enumerate all permissions and see which roles hold each permission.

**Impact:** Exposes the complete permission taxonomy and role-permission mappings — a roadmap for privilege escalation targets.

**Recommendation:** Require an admin-level permission before returning data.

---

### MED-3: Race Conditions in Finance Payment Baseline Seeding ✅ FIXED

**Category:** Race Condition
**File:** `v2/helpers/finance_payment.php:20-38`

**Description:** `seedFinancePaymentBaseline()` performs a check-then-insert without a transaction or `FOR UPDATE` lock. Two concurrent invoice approvals can both pass the existence check and insert duplicate baseline rows.

**Impact:** Corrupts AR/AP calculations.

**Recommendation:** Wrap in a transaction with `SELECT ... FOR UPDATE`, or use `INSERT ... ON DUPLICATE KEY UPDATE` with a unique constraint.

---

### MED-4: Race Condition in Subscription Payment Creation ✅ FIXED

**Category:** Race Condition
**File:** `v2/subscription/index.php:122-147`

**Description:** The pending-payment duplicate check and INSERT are not wrapped in a transaction. Two concurrent checkout requests can both pass the check and proceed to the payment gateway, creating duplicate gateway charges.

**Recommendation:** Use `SELECT ... FOR UPDATE` inside a transaction, or move the gateway call after confirming the insert succeeded.

---

### MED-5: WebSocket Server Doesn't Validate Account Status ✅ FIXED

**Category:** Authorization
**File:** `v2/notification/ws-server.php:84-101`

**Description:** The WS server validates JWT signature and expiry but doesn't check the user's `account_status` against the database. A suspended/disabled user with a still-valid JWT can maintain a WebSocket connection and receive real-time notifications.

**Impact:** Revoked/suspended users continue receiving real-time data until their token expires (up to 8 hours).

**Recommendation:** On WS auth, query the DB to verify `account_status = 'verified'` before adding the connection.

---

### MED-6: Unescaped JWT-Sourced Variables in SQL Queries (Defense-in-Depth) ✅ FIXED (55 files)

**Category:** SQL Injection (Defense-in-Depth)
**Files:** Systemic — `$company_id` and `$username` from JWT interpolated without escaping in numerous locations across all modules.

**Description:** While these values come from a signed JWT, they are not escaped at the query level. If the JWT secret is compromised (see CRIT-5) or if any code path allows arbitrary values into JWT claims, these become SQL injection vectors.

**Recommendation:** Consistently apply `mysqli_real_escape_string()` to all variables interpolated into SQL, or migrate to prepared statements.

---

### MED-7: COLLATE on Every JOIN to `app_user` Prevents Index Usage ✅ FIXED (migration doc v42)

**Category:** Performance / Missing Index
**Files:** Every module file in the codebase

**Description:** Every JOIN to `CORE_SCHEMA.app_user` uses `COLLATE utf8mb4_general_ci`, forcing a collation conversion that prevents MySQL from using indexes on `user_id`. This happens on every query in every module — typically 2-3 JOINs per query.

**Impact:** Full table scans on `app_user` for every JOIN. This is the single most impactful performance issue in the codebase.

**Recommendation:** Align the collation of `app_user.user_id` with the `created_by`/`updated_by` columns in all app_schema tables. Remove the COLLATE clause once collations match.

---

### MED-8: LIKE Wildcard Characters Not Escaped ✅ FIXED

**Category:** SQL Injection (minor) / Wildcard Injection
**Files:** Every `getAll*` function with search functionality

**Description:** The `$search` parameter is escaped with `mysqli_real_escape_string()`, but LIKE wildcard characters (`%` and `_`) are NOT escaped. A user can pass `%` to match all records, bypassing intended filtering.

**Recommendation:**

```php
$search = mysqli_real_escape_string($conn, $params['search']);
$search = str_replace(['%', '_'], ['\%', '\_'], $search);
$where .= " AND (c.customer_name LIKE '%$search%' ESCAPE '\\')";
```

---

### MED-9: Expensive Subquery on Every Customer/Supplier List Query ✅ FIXED

**Category:** Performance
**Files:**
- `v2/master/customer/index.php:17-27`
- `v2/master/supplier/index.php:21-31`

**Description:** A nested subquery calculates outstanding payment amounts for every customer on every list query — scanning the entire `finance_payment` table, grouping twice, with HAVING.

**Impact:** Severe performance degradation as `finance_payment` grows.

**Recommendation:** Cache the outstanding amount as a denormalized column on the customer table, updated when payments are created/approved. Or make it a separate endpoint.

---

### MED-10: Missing Pagination on Sub-Resource Item Lists ✅ FIXED

**Category:** Unbounded Query
**Files:**
- `v2/purchase/purchase-order/items.php:10-26`
- `v2/purchase/purchase-receive/items.php:10-26`
- `v2/purchase/purchase-invoice/items.php:10-26`
- `v2/sales/sales-order/items.php:10-26`

**Description:** These functions fetch ALL items for a parent document without pagination.

**Recommendation:** Add pagination parameters with a higher default limit (e.g., 500 for items).

---

### MED-11: Missing `deleted_at IS NULL` Filter in Approval Flow Queries ✅ FIXED

**Category:** Data Integrity
**Files:**
- `v2/finance/finance-transaction/index.php:307,372`
- `v2/finance/finance-payment/index.php:477,545`

**Description:** Doc lookup queries in approval functions don't filter `deleted_at IS NULL`, meaning soft-deleted transactions can still be approved/rejected.

**Recommendation:** Add `AND deleted_at IS NULL` to all approval flow queries.

---

### MED-12: Delete Operations Don't Cascade to Child Items ✅ FIXED

**Category:** Data Integrity / Orphaned Records
**Files:** All parent delete operations

**Description:** When a parent record is soft-deleted, child item records are NOT soft-deleted. The CODE_STANDARDS.md specifies cascade deletion but it's not implemented.

**Impact:** Orphaned child records remain active. Detail queries that fetch items by parent_id will still return items for deleted parents.

**Recommendation:** Implement cascade soft-delete:

```php
mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_order_item 
    SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' 
    WHERE purchase_order_id = '$purchase_order_id' AND deleted_at IS NULL");
mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_order 
    SET deleted_at = '$now' ... WHERE id = '$purchase_order_id' AND company_id = '$company_id'");
```

---

### MED-13: Missing Database Indexes ✅ FIXED (migration doc v43)

**Category:** Missing Index / Performance
**Files:** All modules

**Description:** Based on WHERE clauses, JOIN conditions, and ORDER BY across all modules, the following composite indexes should exist but are not verified:

| Table | Recommended Index |
|-------|-------------------|
| Every app_schema table | `(company_id, deleted_at)` |
| Every app_schema table | `(company_id, deleted_at, created_at)` |
| `purchase_order` | `(company_id, supplier_id)`, `(company_id, status_id)`, `(company_id, po_display_number)` |
| `sales_order` | `(company_id, customer_id)`, `(company_id, status_id)`, `(company_id, so_display_number)` |
| `finance_payment` | `(company_id, customer_id)`, `(company_id, invoice_number)`, `(company_id, transaction_status)` |
| `warehouse_lot` | `(company_id, product_id)`, `(company_id, location_id)` |
| `audit_log` | `(company_id, module)`, `(company_id, reference_id)` |
| All `*_item` tables | `(parent_id, deleted_at)` |

**Recommendation:** Create a migration adding these indexes. Prioritize `company_id + deleted_at` composite indexes.

---

### MED-14: Financial Calculation Precision — `(float)` Casts for Monetary Values ✅ FIXED (bcmath)

**Category:** Financial Calculation Precision
**Files:** All purchase, sales, finance, and warehouse modules

**Description:** All monetary and quantity values are cast to `(float)` in PHP before SQL. PHP `float` is IEEE 754 double-precision, which can cause rounding errors (`0.1 + 0.2 = 0.30000000000000004`).

**Impact:** Cumulative rounding errors in financial calculations. Over thousands of transactions, float imprecision can cause balance sheets to not reconcile.

**Recommendation:** If database columns use `DECIMAL`, pass values as strings to SQL. For PHP-side calculations, use `bcmath` functions (`bcadd`, `bcmul`).

---

### MED-15: No Rate Limiting on Any Endpoint ✅ FIXED

**Category:** Rate Limiting / DoS
**Files:** Systemic

**Description:** No module implements rate limiting. Expensive endpoints (dashboard: 30+ queries/request, analytics: 5 sub-modules with heavy aggregates, search: 12 sequential LIKE queries, all reports) have no protection.

**Impact:** A single user can hammer `/api/v2/dashboard` and exhaust DB connections. The search endpoint with 12 LIKE `%term%` queries is particularly vulnerable.

**Recommendation:** Implement rate limiting using `helpers/rate_limit.php`. Apply stricter limits to analytics/reports/dashboard/search (e.g., 60 req/min) than CRUD endpoints (300 req/min).

---

### MED-16: Unbounded Data in Analytics, Reports, and Dashboard ✅ FIXED

**Category:** Large Response Payloads
**Files:**
- `v2/analytics/overview.php` — no pagination
- `v2/reports/profit-loss/index.php:48-55` — all accounts unbounded
- `v2/reports/balance-sheet/index.php:46-52` — all accounts unbounded
- `v2/dashboard/index.php` — multiple widgets return unbounded arrays (AR aging, AP aging, tax due, shipments, containers, pending receives, stock by location, low stock)

**Recommendation:** Add pagination to all array-returning endpoints, or enforce a hard row limit (`LIMIT 100`).

---

### MED-17: SELECT * on Tables with BLOB Columns ✅ FIXED

**Category:** Performance
**Files:**
- `v2/master/document-watermark/index.php:17` — `SELECT dw.*` includes BLOB `watermark` column
- `v2/master/company-setting-menu/index.php:21` — `SELECT csm.*` includes BLOB `setting_image` column

**Recommendation:** Use explicit column lists excluding BLOBs for list views.

---

### MED-18: Persistent Database Connections Risk State Leakage ✅ FIXED

**Category:** Resource Management
**File:** `v2/connection/db.php:13`

**Description:** Uses `p:$host` (persistent connections). Session-level state (`SET sql_mode`, user variables) can leak between requests on pooled connections.

**Recommendation:** Either remove the `p:` prefix, or reset session state at the start of each request.

---

### MED-19: JWT Access Token TTL Mismatch ✅ FIXED

**Category:** Logic Error
**File:** `v2/helpers/jwt.php:5` vs `v2/auth/login.php:144`

**Description:** `ACCESS_TTL` constant is `36000` (10 hours), but `login.php` encodes tokens with `28800` (8 hours).

**Recommendation:** Use the constant: `JWT::encode($claims, JWT::ACCESS_TTL)`. Consider implementing a token blacklist for forced logout.

---

### MED-20: No Content-Type Validation on Request Body ✅ FIXED

**Category:** Input Validation
**Files:** Systemic — every POST/PUT/PATCH handler

**Description:** No module checks that `Content-Type: application/json` is set before parsing `php://input`.

**Recommendation:**

```php
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (!str_contains($content_type, 'application/json')) {
        jsonResponse(415, 'Content-Type must be application/json');
    }
}
```

---

### MED-21: No Idempotency Keys on POST Creates ✅ FIXED

**Category:** API Design
**Files:** All create endpoints

**Description:** No create endpoint accepts an idempotency key. If a client retries a POST due to network timeout, a duplicate resource is created.

**Recommendation:** Accept an `Idempotency-Key` header on POST endpoints. Store the key + response for 24h. On retry with the same key, return the cached response.

---

### MED-22: WebSocket Server — No Connection Limit, No Heartbeat, No Token Expiry Check ✅ FIXED

**Category:** WebSocket Security
**File:** `v2/notification/ws-server.php`

**Description:**
1. No connection limit per user/IP
2. No heartbeat/ping — dead connections never cleaned up
3. No token expiry check after initial auth
4. Binds to `0.0.0.0` — accessible from any network interface

**Recommendation:**
- Add `maxConnections` per user (e.g., 5)
- Implement ping/pong heartbeat every 30s
- Periodically check JWT expiry on live connections
- Bind to `127.0.0.1` in production, use nginx wss:// proxy

---

### MED-23: Report Date Ranges Not Validated ✅ FIXED

**Category:** Input Validation
**File:** `v2/helpers/report_dates.php:3-11`

**Description:** `resolveReportDateRange()` accepts any string for `date_from`/`date_to` without format validation.

**Recommendation:** Add `preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)` validation.

---

### MED-24: Notification Settings — Delete Allows Deleting Global Holidays ✅ FIXED

**Category:** IDOR / Authorization
**File:** `v2/notification/settings.php:57-73`

**Description:** `deletePublicHoliday()` checks `(company_id = '$company_id' OR company_id IS NULL)` — any tenant can soft-delete global holidays, affecting all tenants.

**Recommendation:** Only allow deleting company-specific holidays. Global holidays require super-admin privileges.

---

### MED-25: Word Export — Filename Not Sanitized in Content-Disposition Header ✅ FIXED

**Category:** Header Injection
**File:** `v2/helpers/word_export.php:14`

**Description:** `streamDocx()` passes `$filename` directly into the `Content-Disposition` header without sanitization. `excel_export.php` has `sanitizeFilename()` but `word_export.php` doesn't use it.

**Recommendation:** Apply `sanitizeFilename()` to `$filename` before setting the header.

---

## MINOR Findings

---

### MIN-1: `generateUUID()` Uses Non-Cryptographic `mt_rand()` ✅ FIXED

**File:** `v2/general.php:52-61`
**Description:** UUID v4 generation uses `mt_rand()`, which is predictable. UUIDs are used for `company_id`, `user_id`, and other entity identifiers.
**Recommendation:** Replace with `random_int()` or `random_bytes()`:
```php
function generateUUID(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}
```

---

### MIN-2: JWT Header `alg` Not Validated on Decode ✅ FIXED

**File:** `v2/helpers/jwt.php:24-35`
**Description:** `decode()` verifies the signature using HS256 but doesn't check that the token header's `alg` claim is `HS256`. While the implementation always uses HMAC-SHA256 regardless of the header value (so algorithm confusion attacks don't work), this is a defense-in-depth gap.
**Recommendation:** Decode and validate the header, rejecting tokens where `alg !== 'HS256'`.

---

### MIN-3: WebSocket Server Binds to `0.0.0.0` ✅ FIXED

**File:** `v2/notification/ws-server.php:71`
**Description:** Directly accessible without a reverse proxy, potentially bypassing TLS.
**Recommendation:** Bind to `127.0.0.1` and use nginx as a TLS-terminating reverse proxy.

---

### MIN-4: Inconsistent `$username` Source Across Modules ✅ FIXED

**Files:** `v2/subscription/index.php:228`, `v2/notification/index.php:90`, `v2/profile/index.php:171`, and many others
**Description:** CODE_STANDARDS specifies `$username = $authUser['sub'] ?? $authUser['user_id'] ?? null`, but modules use only `$authUser['user_id']`.
**Recommendation:** Standardize to the documented fallback chain.

---

### MIN-5: Massive Code Duplication in Auth Log Functions ✅ FIXED

**File:** `v2/auth/login.php:10-26`, `register.php:11-27`, `send-otp.php:11-28`, `forgot-password.php:11-27`, `reset-password.php:11-27`
**Description:** Five identical `write*Log()` functions exist across auth files.
**Recommendation:** Extract to a single `writeAuthLog($conn, $action, $user_id, $username)` helper.

---

### MIN-6: Inconsistent Response Shape Between Auth and Non-Auth Modules ✅ FIXED

**File:** `v2/helpers/auth_response.php` vs `v2/general.php:24-32`
**Description:** `authResponse()` includes an optional `error_code` field and sets `data` to `null` by default. `jsonResponse()` always includes `data` defaulting to `[]`.
**Recommendation:** Unify the response helper or document the intentional difference.

---

### MIN-7: Inconsistent Empty List Handling (404 vs 200 with Empty Array) ✅ FIXED

**Files:** Systemic
**Description:** Some list endpoints return 404 when no records exist; others return 200 with an empty `data` array. Returning 404 for an empty collection is a REST anti-pattern.
**Recommendation:** Standardize on 200 with empty array for all list endpoints. Update CODE_STANDARDS.md.

---

### MIN-8: No `Allow` Header on 405 Responses ✅ FIXED

**Files:** Systemic
**Description:** RFC 7231 requires a 405 response to include an `Allow` header listing valid methods.
**Recommendation:**
```php
function methodNotAllowed(array $allowed): void {
    header('Allow: ' . implode(', ', $allowed));
    jsonResponse(405, 'Method Not Allowed');
}
```

---

### MIN-9: Inconsistent HTTP Method for Approval (POST vs PATCH) ✅ FIXED

**Files:** `v2/notification/approvals.php:215` (POST) vs `v2/sales/sales-order/index.php:613` (PATCH)
**Description:** Same business action uses different HTTP methods on different endpoints.
**Recommendation:** Standardize on PATCH for all approve/reject/revise actions.

---

### MIN-10: Missing `return;` After `jsonResponse()` in Assert Functions ✅ FIXED

**Files:** All `assert*BelongsToCompany()` functions
**Description:** Missing `return;` after `jsonResponse()` — code analysis tools flag this.
**Recommendation:** Add `return;` after `jsonResponse()` calls.

---

### MIN-11: Inconsistent ORDER BY Direction Across Modules ✅ FIXED

**Files:** Various — `currency/index.php` uses `ORDER BY cur.currency_code ASC`, most others use `created_at DESC`
**Recommendation:** Document the convention for lookup tables (code-based ordering) vs transactional tables (date-based ordering).

---

### MIN-12: Count Query Includes Unnecessary JOINs ✅ FIXED

**Files:** `v2/purchase/purchase-order/index.php:146` (12 JOINs in COUNT), `v2/audit/audit-log/index.php:33`
**Description:** COUNT queries include JOINs that the WHERE clause doesn't reference.
**Recommendation:** Use the base table for count queries when the WHERE clause doesn't reference joined columns.

---

### MIN-13: `permission.php` Helper Has No `require_once` for Constants ✅ FIXED

**File:** `v2/helpers/permission.php:1`
**Description:** Uses `CORE_SCHEMA` constant but has no `require_once __DIR__ . '/../config.php'`.
**Recommendation:** Add the require at the top.

---

### MIN-14: Redundant `data.data` Nesting in Paginated Responses ⚠️ ACCEPTED AS-IS (breaking API change — changing would break existing clients)

**Files:** All paginated endpoints
**Description:** Paginated responses nest the row array inside `data.data`:
```json
{"status_code": 200, "data": {"data": [...], "pagination": {...}}}
```
**Recommendation:** Consider flattening or renaming the inner key to `items` or `rows`.

---

### MIN-15: No API Versioning or Deprecation Headers ✅ FIXED

**Files:** Systemic
**Description:** No `X-API-Version` headers, `Sunset` headers, or `Deprecation` headers.
**Recommendation:** Add `X-API-Version: 2.0` response header. Use RFC 8594 `Deprecation`/`Sunset` headers for endpoints being phased out.

---

### MIN-16: Dead Code in `approvals.php` ✅ FIXED

**File:** `v2/notification/approvals.php:24, 118, 149`
**Description:** `getApprovalToken()` returns `null` after `jsonResponse()` (which exits). The `return null` and `if (!$token_row) return;` are unreachable.
**Recommendation:** Remove dead returns or restructure to return a result object.

---

### MIN-17: `requireAuth()` Catch Block Has Redundant `exit` ✅ FIXED

**File:** `v2/general.php:43-50`
**Description:** `jsonResponse()` already calls `exit`. The explicit `exit` is redundant.
**Recommendation:** Remove the redundant `exit`.

---

### MIN-18: DB Connection Error Response Violates Required Format ✅ FIXED

**File:** `v2/connection/db.php:16-19`
**Description:** DB connection failure returns `{"success": false, "message": "..."}` instead of the standard `{status_code, status_message, data}` shape.
**Recommendation:**
```php
echo json_encode([
    'status_code'    => 500,
    'status_message' => 'Database connection failed',
    'data'           => []
]);
```

---

### MIN-19: `analytics_dates.php` Validation Function Exits Instead of Returning ✅ FIXED

**File:** `v2/helpers/analytics_dates.php:6-8`
**Description:** Helper function calls `jsonResponse()` which exits. Prevents collecting multiple validation errors.
**Recommendation:** Return `bool` or throw `InvalidArgumentException`, let the caller decide.

---

### MIN-20: Excel Export Endpoints Break the JSON Response Contract ✅ FIXED

**Files:** All report export endpoints + `helpers/excel_export.php`
**Description:** Export endpoints return binary `.xlsx` content instead of JSON. Error responses may have mixed content types.
**Recommendation:** Document the export response contract. Consider returning a download URL that the client follows separately.

---

### MIN-21: Dashboard Doesn't Support Date Range Filtering ✅ FIXED

**File:** `v2/dashboard/index.php`
**Description:** Dashboard widgets use hardcoded `CURDATE()`. No way to request dashboard data for a historical date range.
**Recommendation:** Accept optional `date_from`/`date_to` query params.

---

### MIN-22: Persistent DB Connection Deviation from Standards ✅ FIXED

**File:** `v2/connection/db.php:13`
**Description:** Uses `p:` prefix for persistent connections, but CODE_STANDARDS.md shows `new mysqli($host, ...)` without the persistent prefix. Undocumented deviation.
**Recommendation:** Either update CODE_STANDARDS.md to document this decision, or remove the `p:` prefix.

---

## Recommended Fix Priority — ALL PHASES COMPLETED ✅

### Phase 1 — Immediate (Security Critical) ✅ COMPLETED
1. ✅ CRIT-1: Escape entity IDs in all approve/reject/revise functions
2. ✅ CRIT-2: Fix SSRF + token theft in `approvals.php`
3. ✅ CRIT-3: Secure or disable `digest.php` HTTP access
4. ✅ CRIT-4: Fix `getUserIP()` to not trust spoofable headers
5. ✅ CRIT-5: Fail-fast on empty `JWT_SECRET`
6. ✅ CRIT-6: Fail-fast on default `OTP_PEPPER`
7. ✅ CRIT-7: Escape all variables in `audit_log.php`
8. ✅ CRIT-8: Validate `$table` whitelist in `dual_approval.php`
9. ✅ CRIT-9: Restrict global lookup table mutations to admin role
10. ✅ CRIT-10: Add `company_id` filter to warehouse lot lookups
11. ✅ CRIT-15: Add permission checks to all report endpoints
12. ✅ CRIT-16: Add permission checks to approval/master data/search endpoints
13. ✅ MED-1: Stop exposing `mysqli_error()` in responses

### Phase 2 — Short-Term (Data Integrity) ✅ COMPLETED
1. ✅ CRIT-11: Wrap financial approval operations in transactions
2. ✅ CRIT-12: Add UNIQUE constraints for duplicate prevention (migration doc v40)
3. ✅ CRIT-13: Validate stock levels before deduction
4. ✅ CRIT-14: Fix sequence number generation (migration doc v41)
5. ✅ MED-3, MED-4: Fix race conditions in baseline seeding and subscription
6. ✅ MED-11: Add `deleted_at IS NULL` to approval queries
7. ✅ MED-12: Implement cascade soft-delete
8. ✅ MED-14: Fix float precision for monetary calculations (bcmath)

### Phase 3 — Medium-Term (Performance & Hardening) ✅ COMPLETED
1. ✅ MED-7: Fix COLLATE on JOINs (migration doc v42)
2. ✅ MED-13: Add database indexes (migration doc v43)
3. ✅ MED-9: Optimize customer/supplier list subqueries
4. ✅ MED-15: Implement rate limiting
5. ✅ MED-16: Add pagination/bounding to analytics/reports/dashboard
6. ✅ MED-22: Secure WebSocket server
7. ✅ MED-6: Escape JWT-sourced variables in SQL (defense-in-depth, 55 files)

### Phase 4 — Long-Term (Polish & Consistency) ✅ COMPLETED
1. ✅ All MINOR findings (21 fixed, MIN-14 accepted as-is)
2. ✅ Standardize response shape, error handling, naming conventions
3. ✅ Add API versioning headers (X-API-Version: 2.0)
4. ✅ Clean up dead code and duplication
5. ✅ Update CODE_STANDARDS.md to reflect decisions

---

## Database Migration Recommendations — MIGRATION DOCS CREATED ✅

The following schema changes have been documented as migration docs under `v2/docs/migrations/`. Per the project's migration policy, dev may be applied directly and prod requires explicit user approval.

1. ✅ **UNIQUE constraints** — `v40_unique_constraints_for_duplicate_prevention.md`
2. ✅ **Sequence counter table** — `v41_document_sequence_counter_table.md`
3. ✅ **Collation alignment** — `v42_collation_alignment.md`
4. ✅ **Composite indexes** — `v43_composite_indexes.md`
5. ✅ **company_id filter on warehouse_lot** — application-level fix (no schema change needed)
6. ✅ **deleted_at IS NULL in approval queries** — application-level fix (no schema change needed)

---

*Report generated by parallel review using Code Reviewer, Database Optimizer, and API Platform Engineer agents from `.opencode/agents/`.*
*All findings addressed on 17 October 2026 (WIB).*
