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
// BEFORE — no auth header
axios.get('/master/payment/getallpayment.php')

// AFTER — must send token
axios.get('/master/payment/payment.php', {
  headers: { Authorization: `Bearer ${token}` }
})
```

If this header is missing or the token is expired you get `401 Unauthorized`.

---

### 2. Change Request Body Format: FormData → JSON

Old POST/PUT endpoints read `$_POST` (form data).
New endpoints read `php://input` (JSON body).

```js
// BEFORE — FormData
const form = new FormData()
form.append('supplier_name', 'PT ABC')
axios.post('/master/supplier/insertsupplier.php', form)

// AFTER — JSON body
axios.post('/master/supplier/supplier.php', {
  supplier_name: 'PT ABC',
  // ... other fields
}, {
  headers: {
    'Content-Type': 'application/json',
    Authorization: `Bearer ${token}`
  }
})
```

---

### 3. Update How You Read List Responses (pagination wrapper)

Old list responses returned the array directly inside `Data`.
New list responses wrap rows inside `Data.rows` and add pagination.

```js
// BEFORE — data is a flat array
const response = await axios.get('/master/payment/getallpayment.php')
const items = response.data.Data  // array

// AFTER — data is wrapped
const response = await axios.get('/master/payment/payment.php', { headers })
const items = response.data.Data.rows       // array
const total = response.data.Data.pagination.total
const totalPages = response.data.Data.pagination.total_pages
```

---

### 4. Response Field Names Changed on Some Endpoints

Some old endpoints returned display-style keys. New ones use consistent snake_case DB column names.

| Endpoint | Old field | New field |
|----------|-----------|-----------|
| Customer list | `'Company Name'` | `company_name` |
| Customer list | `'Company Address'` | `company_address` |
| Customer list | `'Company Phone'` | `company_phone` |
| Payment list | `'Id'` | `payment_id` |
| Payment list | `'Payment Method'` | `payment_name` |
| Origin list | `'Country Name'` | `origin_name` |
| Origin list | `'Is Free Trade'` | `origin_is_free_trade` |
| Origin list | `'Region'` | `region_name` |

---

## Backward Compatibility

**Old files are still online.** You can migrate one feature at a time.
The old files (e.g. `getallpayment.php`) will stay working until they are removed.
Only the **new** files require auth and return the new response shape.

---

## Per-Endpoint Migration Reference

---

### Customer

#### GET — List all customers

```
BEFORE: GET /master/customer/getallcustomer.php?company=<id>
AFTER:  GET /master/customer/customer.php?company=<id>&params=&page=1&limit=10
```

Response change:
```js
// BEFORE
{ StatusCode: 200, Status: 'Success', Data: [
  { company_id, 'Company Name', 'Company Address', 'Company Phone' }
]}

// AFTER
{ StatusCode: 200, Status: 'Success', Data: {
  rows: [{ company_id, company_name, company_address, company_phone }],
  pagination: { total, page, limit, total_pages }
}}
```

#### GET — Customer detail

```
BEFORE: GET /master/customer/getdetailcustomer.php?company_id=<id>
AFTER:  GET /master/customer/customer.php?company_id=<id>
```

#### GET — Customer address

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
        Body: JSON { company_id, company_name, company_address, company_phone,
                     company_pic_name, company_pic_contact, company_top }
```

#### PUT — Update customer

```
BEFORE: POST /master/customer/updatecustomer.php
        Body: FormData { company_id, ...fields }

AFTER:  PUT /master/customer/customer.php
        Body: JSON { company_id, ...fields }
```

---

### Product

#### GET — List all products

```
BEFORE: GET /master/product/getallproduct.php
AFTER:  GET /master/product/product.php?params=&page=1&limit=10
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
              ↑ had to pass username manually

AFTER:  POST /master/product/product.php
        Body: JSON { product_code, product_name, product_desc }
              ↑ username/userId is taken from the JWT token automatically
```

#### PUT — Update product

```
BEFORE: POST /master/product/updateproduct.php
        Body: FormData { product_code, ...fields }

AFTER:  PUT /master/product/product.php
        Body: JSON { product_code, ...fields }
```

#### DELETE — Delete product

```
BEFORE: POST /master/product/deleteproduct.php
        Body: FormData { product_code }

AFTER:  DELETE /master/product/product.php?product_code=<sku>
```

---

### Supplier

#### GET — List all suppliers

```
BEFORE: GET /master/supplier/getallsupplier.php?company=<id>
AFTER:  GET /master/supplier/supplier.php?company=<id>&params=&page=1&limit=10
```

#### GET — List import suppliers only

```
BEFORE: GET /master/supplier/getallimportsupplier.php?company=<id>
AFTER:  GET /master/supplier/supplier.php?company=<id>&type=import
```

#### GET — List local suppliers only

```
BEFORE: GET /master/supplier/getalllocalsupplier.php?company=<id>
AFTER:  GET /master/supplier/supplier.php?company=<id>&type=local
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
        Body: JSON    { company_id, supplier_name, supplier_origin, supplier_address,
                        supplier_phone, supplier_pic_name, supplier_pic_contact,
                        supplier_currency, supplier_term, supplier_bank }
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

Response change:
```js
// BEFORE
{ Data: [{ 'Id': '...', 'Payment Method': '...' }] }

// AFTER
{ Data: { rows: [{ payment_id: '...', payment_name: '...' }], pagination: {...} } }
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

---

### Payment Term

#### GET — List all terms

```
BEFORE: GET /master/term/getallterm.php
AFTER:  GET /master/term/term.php?params=&page=1&limit=10
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
        Body: FormData { ppn_type_name, ppn_percentage }

AFTER:  POST /master/ppn/ppn.php
        Body: JSON { ppn_type_name, ppn_percentage }
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
        Body: FormData { uom_name }

AFTER:  POST /master/uom/uom.php
        Body: JSON { uom_name }
```

---

### Origin / Country

#### GET — List all origins

```
BEFORE: GET /master/origin/getorigin.php
AFTER:  GET /master/origin/origin.php?params=&page=1&limit=10
```

Response change:
```js
// BEFORE
{ Data: [{ origin_id, 'Country Name', 'Is Free Trade', 'Region' }] }

// AFTER
{ Data: { rows: [{ origin_id, origin_name, origin_is_free_trade, region_name }], pagination: {...} } }
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

Response change:
```js
// BEFORE — no pagination wrapper
{ StatusCode: 200, Status: 'Success', Data: [...], totalItems: 25 }

// AFTER — standard wrapper
{ StatusCode: 200, Status: 'Success', Data: { rows: [...], pagination: {...} } }
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

Note: Old endpoint returned `203` for duplicate names. New endpoint returns `409 Conflict`.

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
- [ ] Add `Authorization: Bearer <token>` header on every request
- [ ] Change POST/PUT body from `FormData` to `JSON` (`Content-Type: application/json`)
- [ ] For lists: access `response.data.Data.rows` instead of `response.data.Data`
- [ ] For lists: read `response.data.Data.pagination` for total count / page info
- [ ] Check response field names (customer, payment, origin — see table above)
- [ ] For DELETE: change from POST with body to `DELETE` with query param
- [ ] For UPDATE: change from POST to `PUT`
- [ ] Remove any manual `username` field from product create — it now comes from the token

---

## Axios Helper Example

To avoid repeating the auth header on every call, set it up once:

```js
import axios from 'axios'

const api = axios.create({
  baseURL: 'https://<your-domain>/erpAPI-v.1.0',
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

export default api
```

Then calls are clean:

```js
// GET list with search + pagination
const { data } = await api.get('/master/payment/payment.php', {
  params: { params: searchKeyword, page: currentPage, limit: 10 }
})
const rows = data.Data.rows
const totalPages = data.Data.pagination.total_pages

// POST create
await api.post('/master/payment/payment.php', { payment_name: 'Transfer Bank' })

// PUT update
await api.put('/master/supplier/supplier.php', { supplier_id: id, supplier_name: 'New Name' })

// DELETE
await api.delete('/master/payment/payment.php', { params: { payment_id: id } })
```
