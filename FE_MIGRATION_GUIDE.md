# Frontend Migration Guide — Master Data Endpoints

This document is for the **frontend team**. It covers every endpoint that changed,
what the old call looked like, what the new call looks like, and exactly what you
need to update in your code.

---

## Summary of Breaking Changes

There are **4 things that changed on every single endpoint**. Fix these first.

### 1. Add Authorization Header (NEW — required everywhere)

Old endpoints had **no auth**. Every new endpoint requires a JWT token.

```js
const token = localStorage.getItem('token');

// BEFORE — no auth header
const response = await fetch(`${API_BASE_URL}master/payment/getallpayment.php`);

// AFTER — must send token
const response = await fetch(`${API_BASE_URL}master/payment/payment.php`, {
    headers: { 'Authorization': `Bearer ${token}` },
});
```

If the header is missing or the token is expired you get `401 Unauthorized`.

---

### 2. Change Request Body Format: FormData → JSON

Old POST/PUT endpoints read `$_POST` (form data).
New endpoints read a JSON body.

```js
const token = localStorage.getItem('token');

// BEFORE — FormData
const form = new FormData();
form.append('supplier_name', 'PT ABC');
const response = await fetch(`${API_BASE_URL}master/supplier/insertsupplier.php`, {
    method: 'POST',
    body: form,
});

// AFTER — JSON body
const response = await fetch(`${API_BASE_URL}master/supplier/supplier.php`, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
    },
    body: JSON.stringify({
        supplier_name: 'PT ABC',
        // ... other fields
    }),
});
```

---

### 3. Update How You Read List Responses (pagination wrapper)

Old list responses returned the array directly inside `Data`.
New list responses wrap rows inside `Data.data` and add pagination.

```js
const token = localStorage.getItem('token');

// BEFORE — data is a flat array
const response = await fetch(`${API_BASE_URL}master/payment/getallpayment.php`);
const json = await response.json();
const items = json.Data;  // array

// AFTER — data is wrapped
const response = await fetch(`${API_BASE_URL}master/payment/payment.php`, {
    headers: { 'Authorization': `Bearer ${token}` },
});
const json = await response.json();
const items    = json.Data.data;                    // array
const total    = json.Data.pagination.total;
const totalPages = json.Data.pagination.total_pages;
```

---

### 4. `company_id` Removed from Request Body (customer & supplier only)

Old endpoints required you to pass `company_id` in the POST body to identify which company the record belongs to.
New endpoints read it directly from your JWT token — **do not send it in the body**.

```js
// BEFORE — had to pass company_id manually
body: JSON.stringify({ company_id: '...', supplier_name: 'PT ABC', ... })

// AFTER — company_id is taken from token automatically
body: JSON.stringify({ supplier_name: 'PT ABC', ... })
```

---

## Backward Compatibility

**Old files are still online.** You can migrate one feature at a time.
The old files (e.g. `getallpayment.php`) will stay working until they are removed.
Only the **new** files require auth and return the new response shape.

---

## Fetch Helper (recommended)

Set up a thin helper once so you don't repeat headers on every call:

```js
const API_BASE_URL = 'https://<your-domain>/erpAPI-v.1.0/';

function authHeaders(json = false) {
    const token = localStorage.getItem('token');
    const headers = { 'Authorization': `Bearer ${token}` };
    if (json) headers['Content-Type'] = 'application/json';
    return headers;
}

// GET
async function apiGet(path, params = {}) {
    const url = new URL(API_BASE_URL + path);
    Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
    const response = await fetch(url.toString(), { headers: authHeaders() });
    return response.json();
}

// POST / PUT / DELETE with body
async function apiSend(method, path, body = null) {
    const response = await fetch(API_BASE_URL + path, {
        method,
        headers: authHeaders(true),
        body: body ? JSON.stringify(body) : undefined,
    });
    return response.json();
}
```

Usage:

```js
// GET list with search + pagination
const json = await apiGet('master/payment/payment.php', { params: keyword, page: 1, limit: 10 });
const rows       = json.Data.data;
const totalPages = json.Data.pagination.total_pages;

// POST create
const json = await apiSend('POST', 'master/payment/payment.php', { payment_name: 'Transfer Bank' });

// PUT update
const json = await apiSend('PUT', 'master/supplier/supplier.php', { supplier_id: id, supplier_name: 'New Name' });

// DELETE
const json = await apiGet('master/payment/payment.php');  // then DELETE with query param:
const response = await fetch(`${API_BASE_URL}master/payment/payment.php?payment_id=${id}`, {
    method: 'DELETE',
    headers: authHeaders(),
});
```

---

## Per-Endpoint Migration Reference

---

### Customer

#### GET — List all customers

```
BEFORE: GET /master/customer/getallcustomer.php?company=<id>
AFTER:  GET /master/customer/customer.php?params=&page=1&limit=10
```

> `company` is no longer a query param — it is read from your JWT token automatically.

```js
// BEFORE
const response = await fetch(`${API_BASE_URL}master/customer/getallcustomer.php?company=${companyId}`);
const json = await response.json();
const customers = json.Data;

// AFTER
const token = localStorage.getItem('token');
const response = await fetch(
    `${API_BASE_URL}master/customer/customer.php?params=${search}&page=${page}&limit=10`,
    { headers: { 'Authorization': `Bearer ${token}` } }
);
const json = await response.json();
const customers  = json.Data.data;
const totalPages = json.Data.pagination.total_pages;
```

Response shape per row:
```json
{ "company_id": "...", "Company Name": "...", "Company Address": "...", "Company Phone": "..." }
```

#### GET — Customer detail

```
BEFORE: GET /master/customer/getdetailcustomer.php?company_id=<id>
AFTER:  GET /master/customer/customer.php?company_id=<id>
```

#### GET — Customer address only

```
BEFORE: GET /master/customer/getcustomeraddress.php?company_id=<id>
AFTER:  GET /master/customer/customer.php?company_id=<id>&type=address
```

#### POST — Create customer

```
BEFORE: POST /master/customer/insertcustomer.php
        Body: FormData { company_id, company_name, company_address, company_phone,
                         company_pic_name, company_pic_contact, company_top }

AFTER:  POST /master/customer/customer.php
        Body: JSON { company_name, company_address, company_phone,
                     company_pic_name, company_pic_contact, company_top }
```

> `company_id` removed from body — taken from JWT.

```js
const token = localStorage.getItem('token');
const response = await fetch(`${API_BASE_URL}master/customer/customer.php`, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
    },
    body: JSON.stringify({
        company_name:        txtCompanyName,
        company_address:     txtAddress,
        company_phone:       txtPhone,
        company_pic_name:    txtPicName,
        company_pic_contact: txtPicContact,
        company_top:         txtTop,
    }),
});
const json = await response.json();
```

#### PUT — Update customer

```
BEFORE: POST /master/customer/updatecustomer.php
        Body: FormData { company_id, ...fields }

AFTER:  PUT /master/customer/customer.php
        Body: JSON { company_id, ...fields }
```

> Here `company_id` is the customer record's ID (primary key), not the tenant — keep it.

---

### Product

#### GET — List all products

```
BEFORE: GET /master/product/getallproduct.php
AFTER:  GET /master/product/product.php?params=&page=1&limit=10
```

```js
const token = localStorage.getItem('token');
const response = await fetch(
    `${API_BASE_URL}master/product/product.php?params=${search}&page=${page}&limit=10`,
    { headers: { 'Authorization': `Bearer ${token}` } }
);
const json = await response.json();
const products   = json.Data.data;
const totalPages = json.Data.pagination.total_pages;
```

Response shape per row:
```json
{ "skuID": "...", "Code": "...", "Product Name": "...", "Product Description": "..." }
```

#### GET — Product detail

```
BEFORE: GET /master/product/getdetailproduct.php?product_code=<sku>
AFTER:  GET /master/product/product.php?product_code=<sku>
```

#### POST — Create product

```
BEFORE: POST /master/product/insertproduct.php
        Body: FormData { product_code, product_name, product_desc, username }

AFTER:  POST /master/product/product.php
        Body: JSON { product_code, product_name, product_desc }
```

> `username` removed from body — taken from JWT automatically.

```js
const token = localStorage.getItem('token');
const response = await fetch(`${API_BASE_URL}master/product/product.php`, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
    },
    body: JSON.stringify({
        product_code: txtProductCode,
        product_name: txtProductName,
        product_desc: txtProductDesc,
    }),
});
const json = await response.json();
```

#### PUT — Update product

```
BEFORE: POST /master/product/updateproduct.php
        Body: FormData { product_code_before, product_name_before, product_desc_before,
                         product_code_new, product_name_new, product_desc_new }

AFTER:  PUT /master/product/product.php
        Body: JSON    { product_code_before, product_name_before, product_desc_before,
                        product_code_new, product_name_new, product_desc_new }
```

#### DELETE — Delete product

```
BEFORE: POST /master/product/deleteproduct.php
        Body: FormData { product_code }

AFTER:  DELETE /master/product/product.php?product_code=<sku>
```

```js
const token = localStorage.getItem('token');
const response = await fetch(`${API_BASE_URL}master/product/product.php?product_code=${sku}`, {
    method: 'DELETE',
    headers: { 'Authorization': `Bearer ${token}` },
});
const json = await response.json();
```

---

### Supplier

#### GET — List all suppliers

```
BEFORE: GET /master/supplier/getallsupplier.php?company=<id>
AFTER:  GET /master/supplier/supplier.php?params=&page=1&limit=10
```

> `company` is no longer a query param — read from JWT.

#### GET — Import suppliers only

```
BEFORE: GET /master/supplier/getallimportsupplier.php?company=<id>
AFTER:  GET /master/supplier/supplier.php?type=import&params=&page=1&limit=10
```

#### GET — Local suppliers only

```
BEFORE: GET /master/supplier/getalllocalsupplier.php?company=<id>
AFTER:  GET /master/supplier/supplier.php?type=local&params=&page=1&limit=10
```

#### GET — Supplier detail

```
BEFORE: GET /master/supplier/getsupplierdetail.php?supplier_id=<id>
AFTER:  GET /master/supplier/supplier.php?supplier_id=<id>
```

#### GET — Supplier purchase history

```
BEFORE: GET /master/supplier/getsupplierhistory.php?supplier_id=<id>
AFTER:  GET /master/supplier/supplier.php?supplier_id=<id>&type=history
```

#### GET — Supplier currency

```
BEFORE: GET /master/supplier/getcurrencybasedonsupplier.php?supplier_id=<id>
AFTER:  GET /master/supplier/supplier.php?supplier_id=<id>&type=currency
```

#### GET — Supplier origin

```
BEFORE: GET /master/supplier/getoriginbasedonsupplier.php?supplier_id=<id>
AFTER:  GET /master/supplier/supplier.php?supplier_id=<id>&type=origin
```

#### GET — Supplier PIC name

```
BEFORE: GET /master/supplier/getpicnamebasedonsupplier.php?supplier_id=<id>
AFTER:  GET /master/supplier/supplier.php?supplier_id=<id>&type=pic
```

#### GET — Supplier payment term

```
BEFORE: GET /master/supplier/gettermbasedonsupplier.php?supplier_id=<id>
AFTER:  GET /master/supplier/supplier.php?supplier_id=<id>&type=term
```

#### POST — Create supplier

```
BEFORE: POST /master/supplier/insertsupplier.php
        Body: FormData { company_id, supplier_name, supplier_origin, supplier_address,
                         supplier_phone, supplier_pic_name, supplier_pic_contact,
                         supplier_currency, supplier_term, supplier_bank }

AFTER:  POST /master/supplier/supplier.php
        Body: JSON    { supplier_name, supplier_origin, supplier_address,
                        supplier_phone, supplier_pic_name, supplier_pic_contact,
                        supplier_currency, supplier_term, supplier_bank }
```

> `company_id` removed from body — taken from JWT.

```js
const token = localStorage.getItem('token');
const response = await fetch(`${API_BASE_URL}master/supplier/supplier.php`, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
    },
    body: JSON.stringify({
        supplier_name:        txtSupplierName,
        supplier_origin:      selectedOrigin,
        supplier_address:     txtAddress,
        supplier_phone:       txtPhone,
        supplier_pic_name:    txtPicName,
        supplier_pic_contact: txtPicContact,
        supplier_currency:    selectedCurrency,
        supplier_term:        selectedTerm,
        supplier_bank:        txtBankInfo,
    }),
});
const json = await response.json();
```

#### PUT — Update supplier

```
BEFORE: POST /master/supplier/updatesupplier.php
        Body: FormData { supplier_id, ...fields }

AFTER:  PUT /master/supplier/supplier.php
        Body: JSON { supplier_id, ...fields }
```

---

### Payment Method

#### GET — List all payment methods

```
BEFORE: GET /master/payment/getallpayment.php
AFTER:  GET /master/payment/payment.php?params=&page=1&limit=10
```

```js
const token = localStorage.getItem('token');
const response = await fetch(
    `${API_BASE_URL}master/payment/payment.php?params=${search}&page=${page}&limit=10`,
    { headers: { 'Authorization': `Bearer ${token}` } }
);
const json = await response.json();
const items      = json.Data.data;
const totalPages = json.Data.pagination.total_pages;
```

Response shape per row:
```json
{ "Id": "...", "Payment Method": "..." }
```

#### POST — Create payment method

```
BEFORE: POST /master/payment/insertpayment.php
        Body: FormData { payment_name }

AFTER:  POST /master/payment/payment.php
        Body: JSON { payment_name }
```

#### DELETE — Delete payment method

```
BEFORE: POST /master/payment/deletepayment.php
        Body: FormData { payment_id }

AFTER:  DELETE /master/payment/payment.php?payment_id=<id>
```

```js
const token = localStorage.getItem('token');
const response = await fetch(`${API_BASE_URL}master/payment/payment.php?payment_id=${id}`, {
    method: 'DELETE',
    headers: { 'Authorization': `Bearer ${token}` },
});
const json = await response.json();
```

---

### Payment Term

#### GET — List all terms

```
BEFORE: GET /master/term/getallterm.php
AFTER:  GET /master/term/term.php?params=&page=1&limit=10
```

Response shape per row:
```json
{ "term_id": "...", "Term": "..." }
```

#### POST — Create term

```
BEFORE: POST /master/term/insertterm.php
        Body: FormData { term_name }

AFTER:  POST /master/term/term.php
        Body: JSON { term_name }
```

#### DELETE — Delete term

```
BEFORE: POST /master/term/deleteterm.php
        Body: FormData { term_id }

AFTER:  DELETE /master/term/term.php?term_id=<id>
```

---

### PPN / Tax

#### GET — List all PPN types

```
BEFORE: GET /master/ppn/getallppn.php
AFTER:  GET /master/ppn/ppn.php?params=&page=1&limit=10
```

#### GET — PPN percentage detail

```
BEFORE: GET /master/ppn/getdetailppnpercantage.php?PPNType_id=<id>
AFTER:  GET /master/ppn/ppn.php?PPNType_id=<id>
```

#### POST — Create PPN type

```
BEFORE: POST /master/ppn/insertppn.php
        Body: FormData { ppn_name }

AFTER:  POST /master/ppn/ppn.php
        Body: JSON { ppn_name }
```

---

### Unit of Measure (UOM)

#### GET — List all UOM

```
BEFORE: GET /master/uom/getalluom.php
AFTER:  GET /master/uom/uom.php?params=&page=1&limit=10
```

#### POST — Create UOM

```
BEFORE: POST /master/uom/insertuom.php
        Body: FormData { uom_name, conversion_factor }

AFTER:  POST /master/uom/uom.php
        Body: JSON { uom_name, conversion_factor }
```

---

### Origin / Country

#### GET — List all origins

```
BEFORE: GET /master/origin/getorigin.php
AFTER:  GET /master/origin/origin.php?params=&page=1&limit=10
```

Response shape per row:
```json
{ "origin_id": "...", "Country Name": "...", "Is Free Trade": "...", "Region": "..." }
```

#### GET — Origin detail

```
BEFORE: GET /master/origin/getdetailorigin.php?origin_id=<id>
AFTER:  GET /master/origin/origin.php?origin_id=<id>
```

#### GET — Origin by supplier

```
BEFORE: GET /master/origin/getoriginbasedonsupplier.php?supplier=<id>
AFTER:  GET /master/origin/origin.php?supplier=<id>
```

#### POST — Create origin

```
BEFORE: POST /master/origin/insertorigin.php
        Body: FormData { origin_name, origin_region, origin_is_free_trade }

AFTER:  POST /master/origin/origin.php
        Body: JSON { origin_name, origin_region, origin_is_free_trade }
```

#### PUT — Update origin

```
BEFORE: POST /master/origin/updateorigin.php
        Body: FormData { origin_id, origin_name, origin_is_free_trade }

AFTER:  PUT /master/origin/origin.php
        Body: JSON { origin_id, origin_name, origin_is_free_trade }
```

---

### Ship Via

#### GET — List

```
BEFORE: GET /master/shipVia/getallshipvia.php
AFTER:  GET /master/shipVia/shipvia.php?params=&page=1&limit=10
```

#### POST — Create

```
BEFORE: POST /master/shipVia/insertshipvia.php
        Body: FormData { shipvia_name }

AFTER:  POST /master/shipVia/shipvia.php
        Body: JSON { shipvia_name }
```

---

### Shipping Schedule

#### GET — List

```
BEFORE: GET /master/shipping/getallshipping.php
AFTER:  GET /master/shipping/shipping.php?params=&page=1&limit=10
```

#### POST — Create

```
BEFORE: POST /master/shipping/insertshipping.php
        Body: FormData { shipping_name }

AFTER:  POST /master/shipping/shipping.php
        Body: JSON { shipping_name }
```

---

### Gender

#### GET — List

```
BEFORE: GET /master/gender/getallgender.php
AFTER:  GET /master/gender/gender.php?params=&page=1&limit=10
```

#### POST — Create

```
BEFORE: POST /master/gender/insertgender.php
        Body: FormData { gender_name }

AFTER:  POST /master/gender/gender.php
        Body: JSON { gender_name }
```

---

### Finance Category

#### GET — List

```
BEFORE: GET /master/finance/getfinancecategory.php?page=1&limit=25
AFTER:  GET /master/finance/finance-category.php?params=&page=1&limit=10
```

```js
// BEFORE — no pagination wrapper
const json = await response.json();
const items = json.Data;  // flat array, totalItems was a separate field

// AFTER — standard wrapper
const json = await response.json();
const items      = json.Data.data;
const totalPages = json.Data.pagination.total_pages;
```

#### GET — Detail

```
BEFORE: GET /master/finance/getdetailfinancecategory.php?category_id=<id>
AFTER:  GET /master/finance/finance-category.php?category_id=<id>
```

#### POST — Create

```
BEFORE: POST /master/finance/insertfinancecategory.php
        Body: FormData { category_name }

AFTER:  POST /master/finance/finance-category.php
        Body: JSON { category_name }
```

> Note: Old endpoint returned `203` for duplicate names. New endpoint returns `409 Conflict`.

---

### Purchase Status

#### GET — List

```
BEFORE: GET /master/purchaseStatus/getallpurchasestatus.php
AFTER:  GET /master/purchaseStatus/purchase-status.php?params=&page=1&limit=10
```

#### POST — Create

```
BEFORE: POST /master/purchaseStatus/insertpurchasestatus.php
        Body: FormData { purchase_status_name }

AFTER:  POST /master/purchaseStatus/purchase-status.php
        Body: JSON { purchase_status_name }
```

---

### Purchase Type

#### GET — List

```
BEFORE: GET /master/purchaseType/getallpurchasetype.php
AFTER:  GET /master/purchaseType/purchase-type.php?params=&page=1&limit=10
```

#### POST — Create

```
BEFORE: POST /master/purchaseType/insertpurchasetype.php
        Body: FormData { purchase_type_name }

AFTER:  POST /master/purchaseType/purchase-type.php
        Body: JSON { purchase_type_name }
```

---

### Sales Status

#### GET — List *(endpoint is new — there was no GET before)*

```
NEW:    GET /master/salesStatus/sales-status.php?params=&page=1&limit=10
```

#### POST — Create

```
BEFORE: POST /master/salesStatus/insertsalesstatus.php
        Body: FormData { sales_status_name }

AFTER:  POST /master/salesStatus/sales-status.php
        Body: JSON { sales_status_name }
```

---

## Quick Checklist for Each Feature You Migrate

- [ ] Update the URL to the new consolidated file
- [ ] Add `Authorization: Bearer <token>` header on every request (`localStorage.getItem('token')`)
- [ ] Change POST/PUT body from `FormData` to `JSON` with `Content-Type: application/json`
- [ ] For lists: access `json.Data.data` instead of `json.Data`
- [ ] For lists: read `json.Data.pagination` for total count / page info
- [ ] For customer & supplier **create**: remove `company_id` from body (now from token)
- [ ] For product **create**: remove `username` from body (now from token)
- [ ] For DELETE: change from POST with body to `DELETE` with query param
- [ ] For UPDATE: change method from POST to `PUT`
