# ERP API Documentation

## Overview

Base URL: `https://<your-domain>/erpAPI-v.1.0`

All endpoints require a JWT Bearer token in the `Authorization` header.

```
Authorization: Bearer <token>
```

### Standard Response Format

**Success (list)**
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "Data": {
    "rows": [...],
    "pagination": {
      "total": 100,
      "page": 1,
      "limit": 10,
      "total_pages": 10
    }
  }
}
```

**Success (single / action)**
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "Data": { ... }
}
```

**Error**
```json
{
  "StatusCode": 400,
  "Status": "Bad Request",
  "Data": null
}
```

### Common HTTP Status Codes

| Code | Meaning |
|------|---------|
| 200 | OK |
| 201 | Created |
| 400 | Bad Request — missing or invalid field |
| 401 | Unauthorized — missing or expired token |
| 404 | Not Found |
| 405 | Method Not Allowed |
| 409 | Conflict — duplicate record |
| 500 | Internal Server Error |

### Pagination Query Params

All list endpoints support:

| Param | Default | Max | Description |
|-------|---------|-----|-------------|
| `params` | `""` | — | Search keyword (LIKE match) |
| `page` | `1` | — | Page number |
| `limit` | `10` | `100` | Records per page |

---

## Authentication

### Login
`POST /user/login.php`

**Body**
```json
{
  "email": "user@example.com",
  "password": "secret"
}
```

### Register
`POST /user/register.php`

### Forgot Password
`POST /user/forgot-password.php`

### Reset Account
`POST /user/reset-account.php`

### User Profile
`GET /user/profile.php`

---

## Master Data

All master data endpoints live under `/master/`.

---

### Account Code
`/master/finance/account-code.php`

**Search fields:** `account_code`, `account_code_name`

#### GET — List
```
GET /master/finance/account-code.php?params=&page=1&limit=10
```

#### GET — Detail
```
GET /master/finance/account-code.php?account_code_id=<id>
```

#### POST — Create
```json
{
  "account_code": "1-1001",
  "account_code_name": "Cash on Hand",
  "account_type": "asset"
}
```
`account_type` must be one of: `asset`, `liability`, `equity`, `revenue`, `expense`

#### PUT — Update
```json
{
  "account_code_id": "<id>",
  "account_code_name": "Petty Cash",
  "account_type": "asset"
}
```

#### DELETE
```
DELETE /master/finance/account-code.php?account_code_id=<id>
```

---

### Bank Account
`/master/finance/bank-account.php`

**Search fields:** `bank_number`, `bank_name`, `bank_branch`

#### GET — List
```
GET /master/finance/bank-account.php?company=<company_id>&params=&page=1&limit=10
```

#### GET — Detail
```
GET /master/finance/bank-account.php?bank_account_id=<id>
```

#### POST — Create
```json
{
  "company_id": "<uuid>",
  "bank_number": "1234567890",
  "bank_name": "BCA",
  "bank_branch": "Jakarta Pusat",
  "currency_id": "<uuid>",
  "is_primary": true
}
```

#### PUT — Update
```json
{
  "bank_account_id": "<id>",
  "bank_name": "Mandiri",
  "bank_branch": "Sudirman",
  "is_primary": false
}
```

#### DELETE
```
DELETE /master/finance/bank-account.php?bank_account_id=<id>
```

---

### Currency
`/master/finance/currency.php`

**Search fields:** `currency_code`, `currency_name`, `currency_symbol`

#### GET — List
```
GET /master/finance/currency.php?params=&page=1&limit=10
```

#### GET — Detail
```
GET /master/finance/currency.php?currency_id=<id>
```

#### POST — Create
```json
{
  "currency_code": "USD",
  "currency_symbol": "$",
  "currency_name": "US Dollar"
}
```
`currency_code` must be exactly 3 characters.

#### PUT — Update
```json
{
  "currency_id": "<id>",
  "currency_code": "USD",
  "currency_symbol": "$",
  "currency_name": "US Dollar"
}
```

#### DELETE
```
DELETE /master/finance/currency.php?currency_id=<id>
```

---

### Finance Category
`/master/finance/finance-category.php`

**Search fields:** `category_name`

#### GET — List
```
GET /master/finance/finance-category.php?params=&page=1&limit=10
```

#### GET — Detail
```
GET /master/finance/finance-category.php?category_id=<id>
```

#### POST — Create
```json
{
  "category_name": "Operational Expense"
}
```

---

### Customer
`/master/customer/customer.php`

**Search fields:** `company_name`, `company_address`, `company_phone`

#### GET — List
```
GET /master/customer/customer.php?company=<company_id>&params=&page=1&limit=10
```
`company` (company_id) is required.

#### GET — Detail
```
GET /master/customer/customer.php?company_id=<id>
```

#### GET — Address only
```
GET /master/customer/customer.php?company_id=<id>&type=address
```

#### POST — Create
```json
{
  "company_id": "<uuid>",
  "company_name": "PT Example",
  "company_address": "Jl. Sudirman No. 1",
  "company_phone": "021-1234567",
  "company_email": "info@example.com",
  "company_npwp": "12.345.678.9-012.000"
}
```

#### PUT — Update
```json
{
  "company_id": "<id>",
  "company_name": "PT Example Updated",
  "company_address": "Jl. Thamrin No. 2",
  "company_phone": "021-9876543"
}
```

---

### Product
`/master/product/product.php`

**Search fields:** `skuID`, `productName`, `productDesc`

#### GET — List
```
GET /master/product/product.php?params=&page=1&limit=10
```

#### GET — Detail
```
GET /master/product/product.php?product_code=<sku>
```

#### POST — Create
```json
{
  "product_name": "Widget A",
  "product_desc": "Standard widget",
  "product_uom": "<uom_id>",
  "product_category": "<category_id>"
}
```

#### PUT — Update
```json
{
  "product_code": "<sku>",
  "product_name": "Widget A v2",
  "product_desc": "Updated description"
}
```

#### DELETE
```
DELETE /master/product/product.php?product_code=<sku>
```

---

### Supplier
`/master/supplier/supplier.php`

**Search fields:** `supplier_name`, `supplier_phone`, `supplier_pic_name`

#### GET — List (all)
```
GET /master/supplier/supplier.php?company=<company_id>&params=&page=1&limit=10
```

#### GET — List (import only)
```
GET /master/supplier/supplier.php?company=<company_id>&type=import&params=&page=1&limit=10
```

#### GET — List (local only)
```
GET /master/supplier/supplier.php?company=<company_id>&type=local&params=&page=1&limit=10
```

#### GET — Detail
```
GET /master/supplier/supplier.php?supplier_id=<id>
```

#### GET — Detail sub-types
```
GET /master/supplier/supplier.php?supplier_id=<id>&type=history
GET /master/supplier/supplier.php?supplier_id=<id>&type=currency
GET /master/supplier/supplier.php?supplier_id=<id>&type=origin
GET /master/supplier/supplier.php?supplier_id=<id>&type=term
GET /master/supplier/supplier.php?supplier_id=<id>&type=pic
```

#### POST — Create
```json
{
  "company_id": "<uuid>",
  "supplier_name": "PT Supplier ABC",
  "supplier_origin": "<origin_id>",
  "supplier_address": "Jl. Industri No. 5",
  "supplier_phone": "021-5551234",
  "supplier_pic_name": "Budi Santoso",
  "supplier_pic_contact": "081234567890",
  "supplier_currency": "<currency_id>",
  "supplier_term": "<term_id>",
  "supplier_bank": "BCA 1234567890 a/n PT Supplier ABC"
}
```

#### PUT — Update
```json
{
  "supplier_id": "<id>",
  "supplier_name": "PT Supplier XYZ",
  "supplier_phone": "021-9998888",
  "supplier_currency": "<currency_id>",
  "supplier_term": "<term_id>",
  "supplier_bank": "Mandiri 0987654321 a/n PT Supplier XYZ"
}
```

---

### Payment Method
`/master/payment/payment.php`

**Search fields:** `payment_name`

#### GET — List
```
GET /master/payment/payment.php?params=&page=1&limit=10
```

#### POST — Create
```json
{
  "payment_name": "Transfer Bank"
}
```

#### DELETE
```
DELETE /master/payment/payment.php?payment_id=<id>
```

---

### Payment Term
`/master/term/term.php`

**Search fields:** `term_name`

#### GET — List
```
GET /master/term/term.php?params=&page=1&limit=10
```

#### POST — Create
```json
{
  "term_name": "Net 30"
}
```

#### DELETE
```
DELETE /master/term/term.php?term_id=<id>
```

---

### PPN / Tax
`/master/ppn/ppn.php`

**Search fields:** `PPNType_name`

#### GET — List
```
GET /master/ppn/ppn.php?params=&page=1&limit=10
```

#### GET — Percentage Detail
```
GET /master/ppn/ppn.php?PPNType_id=<id>
```

#### POST — Create
```json
{
  "ppn_type_name": "PPN 11%",
  "ppn_percentage": 11
}
```

---

### Unit of Measure (UOM)
`/master/uom/uom.php`

**Search fields:** `uomName`

#### GET — List
```
GET /master/uom/uom.php?params=&page=1&limit=10
```

#### POST — Create
```json
{
  "uom_name": "KG"
}
```

---

### Origin / Country
`/master/origin/origin.php`

**Search fields:** `origin_name`, `region_name`

#### GET — List
```
GET /master/origin/origin.php?params=&page=1&limit=10
```

#### GET — Detail
```
GET /master/origin/origin.php?origin_id=<id>
```

#### GET — By Supplier
```
GET /master/origin/origin.php?supplier=<supplier_id>
```

#### POST — Create
```json
{
  "origin_name": "Indonesia",
  "origin_region": "<region_id>",
  "origin_is_free_trade": "0"
}
```

#### PUT — Update
```json
{
  "origin_id": "<id>",
  "origin_name": "Indonesia",
  "origin_is_free_trade": "1"
}
```

---

### Ship Via
`/master/shipVia/shipvia.php`

**Search fields:** `shipName`

#### GET — List
```
GET /master/shipVia/shipvia.php?params=&page=1&limit=10
```

#### POST — Create
```json
{
  "shipvia_name": "Sea Freight"
}
```

---

### Shipping Schedule
`/master/shipping/shipping.php`

**Search fields:** `shipment_name`

Results are ordered chronologically by period (early/mid/end per month).

#### GET — List
```
GET /master/shipping/shipping.php?params=&page=1&limit=10
```

#### POST — Create
```json
{
  "shipping_name": "Early January 2025"
}
```

---

### Gender
`/master/gender/gender.php`

**Search fields:** `gender_name`

#### GET — List
```
GET /master/gender/gender.php?params=&page=1&limit=10
```

#### POST — Create
```json
{
  "gender_name": "Male"
}
```

---

### Purchase Status
`/master/purchaseStatus/purchase-status.php`

**Search fields:** `PO_Status_Name`

#### GET — List
```
GET /master/purchaseStatus/purchase-status.php?params=&page=1&limit=10
```

#### POST — Create
```json
{
  "purchase_status_name": "Pending Approval"
}
```

---

### Purchase Type
`/master/purchaseType/purchase-type.php`

**Search fields:** `PO_Type_Name`

#### GET — List
```
GET /master/purchaseType/purchase-type.php?params=&page=1&limit=10
```

#### POST — Create
```json
{
  "purchase_type_name": "Import"
}
```

---

### Sales Status
`/master/salesStatus/sales-status.php`

**Search fields:** `SO_Status_Name`

#### GET — List
```
GET /master/salesStatus/sales-status.php?params=&page=1&limit=10
```

#### POST — Create
```json
{
  "sales_status_name": "Pending Approval"
}
```

---

## Endpoint Index

| Resource | Path | Methods |
|----------|------|---------|
| Account Code | `/master/finance/account-code.php` | GET, POST, PUT, DELETE |
| Bank Account | `/master/finance/bank-account.php` | GET, POST, PUT, DELETE |
| Currency | `/master/finance/currency.php` | GET, POST, PUT, DELETE |
| Finance Category | `/master/finance/finance-category.php` | GET, POST |
| Customer | `/master/customer/customer.php` | GET, POST, PUT |
| Product | `/master/product/product.php` | GET, POST, PUT, DELETE |
| Supplier | `/master/supplier/supplier.php` | GET, POST, PUT |
| Payment Method | `/master/payment/payment.php` | GET, POST, DELETE |
| Payment Term | `/master/term/term.php` | GET, POST, DELETE |
| PPN / Tax | `/master/ppn/ppn.php` | GET, POST |
| Unit of Measure | `/master/uom/uom.php` | GET, POST |
| Origin / Country | `/master/origin/origin.php` | GET, POST, PUT |
| Ship Via | `/master/shipVia/shipvia.php` | GET, POST |
| Shipping Schedule | `/master/shipping/shipping.php` | GET, POST |
| Gender | `/master/gender/gender.php` | GET, POST |
| Purchase Status | `/master/purchaseStatus/purchase-status.php` | GET, POST |
| Purchase Type | `/master/purchaseType/purchase-type.php` | GET, POST |
| Sales Status | `/master/salesStatus/sales-status.php` | GET, POST |
| Login | `/user/login.php` | POST |
| Register | `/user/register.php` | POST |
| User Profile | `/user/profile.php` | GET |
