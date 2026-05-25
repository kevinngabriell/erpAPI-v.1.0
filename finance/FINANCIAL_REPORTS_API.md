# Financial Reports API

> **Last updated: 2026-05-21**
> Changes in this update: added search params to Penerimaan/Pembayaran list endpoints; added new Omset detail & export endpoints.

---

## Table of Contents

1. [Omset / Penjualan — Summary per Bulan](#1-omset--penjualan--summary-per-bulan)
2. [Omset / Penjualan — Detail Invoice per Bulan ⭐ NEW](#2-omset--penjualan--detail-invoice-per-bulan-new)
3. [Export Omset Summary (Excel)](#3-export-omset-summary-excel)
4. [Export Omset Detail Invoice (Excel) ⭐ NEW](#4-export-omset-detail-invoice-excel-new)
5. [Penerimaan (Jurnal)](#5-penerimaan-jurnal)
6. [Pembayaran (Jurnal)](#6-pembayaran-jurnal)
7. [Penerimaan Penjualan (Invoice Pelanggan)](#7-penerimaan-penjualan-invoice-pelanggan)
8. [Penerimaan Pembelian (Invoice Supplier)](#8-penerimaan-pembelian-invoice-supplier)
9. [Buku Besar](#9-buku-besar)
10. [Laporan Laba Rugi](#10-laporan-laba-rugi)
11. [Neraca](#11-neraca)
12. [Outstanding Hutang & Piutang](#12-outstanding-hutang--piutang)
13. [Error Responses](#error-responses)

---

## 1. Omset / Penjualan — Summary per Bulan

**File:** `finance/getomsetperbulan.php`

| Property | Value |
|---|---|
| **Method** | `GET` |
| **Endpoint** | `/finance/getomsetperbulan.php` |

### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `year` | `integer` | No | current year | Filter by year |
| `month` | `integer` (1–12) | No | `0` (all months) | Filter by specific month |
| `customer_id` | `string` | No | — | Filter by customer |

### Example Request
```
GET /finance/getomsetperbulan.php?year=2025
GET /finance/getomsetperbulan.php?year=2025&month=1
```

### Response `200 OK`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "filter": { "year": 2025, "month": "all", "customer_id": "all" },
  "summary": {
    "grand_total_sebelum_ppn": 31639821050,
    "grand_total_termasuk_ppn": 85071184352639.76
  },
  "omset_bulanan": [
    {
      "tahun": "2025",
      "bulan": "1",
      "nama_bulan": "January",
      "total_invoice": 34,
      "omset_sebelum_ppn": 31639821050,
      "omset_termasuk_ppn": 85071184352639.76
    }
  ],
  "top_produk": [
    { "productName": "Produk A", "total_qty": 120, "total_nilai": 5000000 }
  ],
  "top_customer": [
    { "company_name": "PT ABC", "total_invoice": 5, "total_nilai": 12000000 }
  ]
}
```

### Notes
- `omset_sebelum_ppn` = SUM(productQuantity × unitPrice)
- `omset_termasuk_ppn` = SUM(productQuantity × unitPrice × (1 + tax/100))
- When `month=0` (default), returns all months grouped per month for that year

---

## 2. Omset / Penjualan — Detail Invoice per Bulan ⭐ NEW

**File:** `finance/getdetailomset.php`

Use this endpoint when the user **clicks a month row** in the Omset Bulanan table to see all invoices within that month.

| Property | Value |
|---|---|
| **Method** | `GET` |
| **Endpoint** | `/finance/getdetailomset.php` |

### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `year` | `integer` | No | current year | Filter by year |
| `month` | `integer` (1–12) | No | `0` (all months) | Filter by specific month |
| `customer_id` | `string` | No | — | Filter by customer |
| `search` | `string` | No | — | Search by invoice number or customer name |
| `page` | `integer` | No | `1` | Page number |
| `limit` | `integer` | No | `25` | Items per page |

### Example Request
```
GET /finance/getdetailomset.php?year=2025&month=1
GET /finance/getdetailomset.php?year=2025&month=1&search=INV-2025&page=1&limit=25
```

### Response `200 OK`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "filter": {
    "year": 2025,
    "month": 1,
    "customer_id": "all",
    "search": ""
  },
  "totalItems": 34,
  "page": 1,
  "limit": 25,
  "Data": [
    {
      "invoiceNumber": "INV-2025-001",
      "invoiceDate": "2025-01-15",
      "customerID": "CUST-001",
      "company_name": "PT ABC",
      "total_items": 3,
      "total_qty": 10,
      "omset_sebelum_ppn": 5000000,
      "omset_termasuk_ppn": 5500000
    }
  ]
}
```

### Notes
- One row = one invoice (aggregated across all its line items)
- `total_items` = number of product lines in that invoice
- `total_qty` = sum of all product quantities in that invoice
- Sorted by `invoiceDate DESC`

---

## 3. Export Omset Summary (Excel)

**File:** `finance/exportomset.php`

Exports the **monthly summary** table (same as section 1) to Excel. Use this for the main Export Excel button on the Omset page.

| Property | Value |
|---|---|
| **Method** | `GET` |
| **Endpoint** | `/finance/exportomset.php` |
| **Response** | `.xlsx` file download |

### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `year` | `integer` | No | current year | Filter by year |
| `month` | `integer` (1–12) | No | `0` (all months) | Filter by specific month |
| `customer_id` | `string` | No | — | Filter by customer |

### Example Request
```
GET /finance/exportomset.php?year=2025
GET /finance/exportomset.php?year=2025&month=1
```

### Excel Output Columns
`Tahun` | `Bulan` | `Nama Bulan` | `Total Invoice` | `Omset (Before PPN)` | `Omset (Incl. PPN)`

---

## 4. Export Omset Detail Invoice (Excel) ⭐ NEW

**File:** `finance/exportdetailomset.php`

Exports the **full invoice list** for a given period to Excel. Use this for the Export Excel button on the detail/drill-down view.

| Property | Value |
|---|---|
| **Method** | `GET` |
| **Endpoint** | `/finance/exportdetailomset.php` |
| **Response** | `.xlsx` file download |

### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `year` | `integer` | No | current year | Filter by year |
| `month` | `integer` (1–12) | No | `0` (all months) | Filter by specific month |
| `customer_id` | `string` | No | — | Filter by customer |
| `search` | `string` | No | — | Search by invoice number or customer name |

### Example Request
```
GET /finance/exportdetailomset.php?year=2025&month=1
GET /finance/exportdetailomset.php?year=2025
```

### Excel Output Columns
`No` | `No. Invoice` | `Tanggal` | `Customer ID` | `Nama Customer` | `Total Item` | `Total Qty` | `Omset (Before PPN)` | `Omset (Incl. PPN)`

### Notes
- Includes a **TOTAL row** at the bottom
- Filename format: `Detail_Omset_2025_01.xlsx` (with month) or `Detail_Omset_2025.xlsx` (without month)

---

## 5. Penerimaan (Jurnal)

**File:** `finance/getallpenerimaan.php`

| Property | Value |
|---|---|
| **Method** | `GET` |
| **Endpoint** | `/finance/getallpenerimaan.php` |

### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `page` | `integer` | No | `1` | Page number |
| `limit` | `integer` | No | `25` | Items per page |
| `search` | `string` | No | — | Search in memo, bank_account, account_name ⭐ NEW |
| `start_date` | `string` (YYYY-MM-DD) | No | — | Filter date >= start_date ⭐ NEW |
| `end_date` | `string` (YYYY-MM-DD) | No | — | Filter date <= end_date ⭐ NEW |

### Example Request
```
GET /finance/getallpenerimaan.php?page=1&limit=25
GET /finance/getallpenerimaan.php?start_date=2025-01-01&end_date=2025-01-31&search=kas
```

### Response `200 OK`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "totalItems": 80,
  "Data": [
    {
      "amount": 5000000,
      "account_name": "Kas Utama",
      "bank_account": "BCA-001",
      "id_transaction": "TXN-001",
      "date": "2025-01-15",
      "memo": "Penerimaan dari pelanggan"
    }
  ]
}
```

---

## 6. Pembayaran (Jurnal)

**File:** `finance/getallpembayaran.php`

| Property | Value |
|---|---|
| **Method** | `GET` |
| **Endpoint** | `/finance/getallpembayaran.php` |

### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `page` | `integer` | No | `1` | Page number |
| `limit` | `integer` | No | `25` | Items per page |
| `search` | `string` | No | — | Search in memo, bank_account, account_name ⭐ NEW |
| `start_date` | `string` (YYYY-MM-DD) | No | — | Filter date >= start_date ⭐ NEW |
| `end_date` | `string` (YYYY-MM-DD) | No | — | Filter date <= end_date ⭐ NEW |

### Example Request
```
GET /finance/getallpembayaran.php?page=1&limit=25
GET /finance/getallpembayaran.php?start_date=2025-01-01&end_date=2025-01-31&search=supplier
```

### Response `200 OK`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "totalItems": 42,
  "Data": [
    {
      "amount": 3000000,
      "account_name": "Biaya Operasional",
      "bank_account": "BCA-001",
      "id_transaction": "TXN-002",
      "date": "2025-01-10",
      "memo": "Pembayaran supplier"
    }
  ]
}
```

---

## 7. Penerimaan Penjualan (Invoice Pelanggan)

**File:** `finance/getallpenerimaanpenjualan.php`

| Property | Value |
|---|---|
| **Method** | `GET` |
| **Endpoint** | `/finance/getallpenerimaanpenjualan.php` |

### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `page` | `integer` | No | `1` | Page number |
| `limit` | `integer` | No | `25` | Items per page |
| `search` | `string` | No | — | Search in company_name, invoice_number ⭐ NEW |
| `start_date` | `string` (YYYY-MM-DD) | No | — | Filter invoiceDate >= start_date ⭐ NEW |
| `end_date` | `string` (YYYY-MM-DD) | No | — | Filter invoiceDate <= end_date ⭐ NEW |

### Example Request
```
GET /finance/getallpenerimaanpenjualan.php?page=1&limit=25
GET /finance/getallpenerimaanpenjualan.php?start_date=2025-01-01&end_date=2025-01-31&search=PT+ABC
```

### Response `200 OK`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "totalItems": 34,
  "Data": [
    {
      "due_amount": 10000000,
      "id_transaction": "TXN-003",
      "paid_amount": 5000000,
      "customerID": "CUST-001",
      "invoice_number": "INV-2025-001",
      "invoiceDate": "2025-01-15",
      "company_name": "PT ABC"
    }
  ]
}
```

---

## 8. Penerimaan Pembelian (Invoice Supplier)

**File:** `finance/getallpenerimaanpembelian.php`

| Property | Value |
|---|---|
| **Method** | `GET` |
| **Endpoint** | `/finance/getallpenerimaanpembelian.php` |

### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `page` | `integer` | No | `1` | Page number |
| `limit` | `integer` | No | `25` | Items per page |
| `search` | `string` | No | — | Search in supplier_name, invoice_number ⭐ NEW |
| `start_date` | `string` (YYYY-MM-DD) | No | — | Filter invoiceDate >= start_date ⭐ NEW |
| `end_date` | `string` (YYYY-MM-DD) | No | — | Filter invoiceDate <= end_date ⭐ NEW |

### Example Request
```
GET /finance/getallpenerimaanpembelian.php?page=1&limit=25
GET /finance/getallpenerimaanpembelian.php?start_date=2025-01-01&end_date=2025-01-31&search=CV+XYZ
```

### Response `200 OK`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "totalItems": 20,
  "Data": [
    {
      "due_amount": 8000000,
      "id_transaction": "TXN-004",
      "paid_amount": 0,
      "supplier": "SUP-001",
      "invoice_number": "PI-2025-001",
      "invoiceDate": "2025-01-05",
      "supplier_name": "CV XYZ",
      "currency_name": "IDR"
    }
  ]
}
```

---

## 9. Buku Besar

**File:** `finance/getbukubesar.php`

| Property | Value |
|---|---|
| **Method** | `GET` |
| **Endpoint** | `/finance/getbukubesar.php` |

### Query Parameters

| Parameter | Type | Required | Description |
|---|---|---|---|
| `start_date` | `string` (YYYY-MM-DD) | Yes | Start of reporting period |
| `end_date` | `string` (YYYY-MM-DD) | Yes | End of reporting period |
| `account_code` | `string` | No | Filter by a specific COA account code |

### Example Request
```
GET /finance/getbukubesar.php?start_date=2025-05-01&end_date=2025-05-31
GET /finance/getbukubesar.php?start_date=2025-05-01&end_date=2025-05-31&account_code=1001
```

### Response `200 OK`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "period": { "start_date": "2025-05-01", "end_date": "2025-05-31" },
  "total_accounts": 5,
  "Data": [
    {
      "account_code": "1001",
      "account_name": "Cash",
      "account_name_alias": "Kas",
      "opening_balance": 5000000,
      "total_debit": 3000000,
      "total_credit": 1500000,
      "closing_balance": 6500000,
      "transactions": [
        {
          "date": "2025-05-03",
          "voucher_no": "VCH-001",
          "memo": "Penerimaan penjualan",
          "debit": 3000000,
          "credit": 0
        }
      ]
    }
  ]
}
```

### Notes
- `opening_balance` = net balance of the account **before** `start_date`
- `closing_balance` = `opening_balance` + `total_debit` - `total_credit`
- Debit = Pembayaran transactions (finance_category: `1d604104-226d-11ef-a`)
- Credit = Penerimaan transactions (finance_category: `174c61e8-226d-11ef-a`)

---

## 10. Laporan Laba Rugi

**File:** `finance/getlaporanlabarugi.php`

| Property | Value |
|---|---|
| **Method** | `GET` |
| **Endpoint** | `/finance/getlaporanlabarugi.php` |

### Query Parameters

| Parameter | Type | Required | Description |
|---|---|---|---|
| `start_date` | `string` (YYYY-MM-DD) | Yes | Start of reporting period |
| `end_date` | `string` (YYYY-MM-DD) | Yes | End of reporting period |

### Example Request
```
GET /finance/getlaporanlabarugi.php?start_date=2025-05-01&end_date=2025-05-31
```

### Response `200 OK`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "period": { "start_date": "2025-05-01", "end_date": "2025-05-31" },
  "Data": {
    "pendapatan": {
      "jurnal": [
        { "code": "4001", "account_name": "Pendapatan Lain-Lain", "total": 15000000 }
      ],
      "penerimaan_invoice": 8000000,
      "total_pendapatan": 23000000
    },
    "beban": {
      "jurnal": [
        { "code": "5001", "account_name": "Biaya Operasional", "total": 4000000 }
      ],
      "pembayaran_invoice": 6000000,
      "total_beban": 10000000
    },
    "laba_bersih": 13000000
  }
}
```

### Notes
- `pendapatan.jurnal` = penerimaan journal entries filtered to **account code prefix `4xx`** only
- `beban.jurnal` = pembayaran journal entries filtered to **account code prefix `5xx` / `6xx`** only
- `laba_bersih` = `total_pendapatan` - `total_beban` (positive = profit, negative = loss)

---

## 11. Neraca

**File:** `finance/getneraca.php`

| Property | Value |
|---|---|
| **Method** | `GET` |
| **Endpoint** | `/finance/getneraca.php` |

### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `as_of_date` | `string` (YYYY-MM-DD) | No | today | Snapshot date |

### Example Request
```
GET /finance/getneraca.php?as_of_date=2025-05-31
```

### Response `200 OK`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "as_of_date": "2025-05-31",
  "Data": {
    "aset": {
      "aset_lancar": {
        "kas_bank": { "details": [{ "akun": "BCA-001", "saldo": 12000000 }], "total": 12000000 },
        "piutang_usaha": { "details": [{ "nama_pelanggan": "PT ABC", "outstanding": 3000000 }], "total": 3000000 },
        "total_aset_lancar": 15000000
      },
      "total_aset": 15000000
    },
    "kewajiban": {
      "kewajiban_lancar": {
        "hutang_usaha": { "details": [{ "nama_supplier": "CV XYZ", "outstanding": 2000000 }], "total": 2000000 },
        "total_kewajiban_lancar": 2000000
      },
      "total_kewajiban": 2000000
    },
    "modal": { "laba_ditahan": 13000000, "total_modal": 13000000 },
    "total_kewajiban_dan_modal": 15000000
  }
}
```

### Notes
- `total_kewajiban_dan_modal` should equal `total_aset` (Assets = Liabilities + Equity)

---

## 12. Outstanding Hutang & Piutang

**File:** `finance/getoutstandingpayments.php`

| Property | Value |
|---|---|
| **Method** | `GET` |
| **Endpoint** | `/finance/getoutstandingpayments.php` |

### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `type` | `string` | No | `all` | `piutang` \| `hutang` \| `all` |
| `year` | `integer` | No | — | Filter by invoice year |
| `month` | `integer` (1–12) | No | — | Filter by invoice month (requires `year`) |

### Example Request
```
GET /finance/getoutstandingpayments.php
GET /finance/getoutstandingpayments.php?type=piutang
GET /finance/getoutstandingpayments.php?type=all&year=2025&month=5
```

### Response `200 OK`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "type": "all",
  "filter": { "year": 2025, "month": 5 },
  "piutang_usaha": {
    "total_piutang": 25000000,
    "total_invoice": 3,
    "data": [
      {
        "invoice_number": "INV-2025-001",
        "nama_pelanggan": "PT ABC",
        "customer_id": "CUST-001",
        "tanggal_invoice": "2025-05-10",
        "term_of_payment": 30,
        "jatuh_tempo": "2025-06-09",
        "hari_overdue": -19,
        "sisa_tagihan": 10000000,
        "nilai_invoice": 10000000,
        "sudah_dibayar": 0,
        "status": "Belum Jatuh Tempo"
      }
    ]
  },
  "hutang_usaha": {
    "total_hutang": 8000000,
    "total_invoice": 1,
    "data": [
      {
        "invoice_number": "PI-2025-001",
        "nama_supplier": "CV XYZ",
        "supplier_id": "SUP-001",
        "tanggal_invoice": "2025-05-05",
        "sisa_hutang": 8000000,
        "nilai_invoice": 8000000,
        "sudah_dibayar": 0,
        "hari_sejak_invoice": 16
      }
    ]
  }
}
```

### Notes
- `hari_overdue` on piutang: **positive** = overdue by N days, **negative** = N days until due
- `status` on piutang: `"Overdue"` or `"Belum Jatuh Tempo"`

---

## Error Responses

| StatusCode | Status | Description |
|---|---|---|
| `400` | No Data Found | Query returned no results |
| `405` | Method Not Allowed | Wrong HTTP method used |
| `500` | Error | Database query error (message included) |

---

## Changelog

### 2026-05-21
- **NEW** `GET /finance/getdetailomset.php` — invoice-level drill-down for Omset per month (supports pagination + search)
- **NEW** `GET /finance/exportdetailomset.php` — Excel export of full invoice list (replaces summary-only export for detail view)
- **UPDATED** `getallpenerimaan.php` — added `search`, `start_date`, `end_date` params
- **UPDATED** `getallpembayaran.php` — added `search`, `start_date`, `end_date` params
- **UPDATED** `getallpenerimaanpenjualan.php` — added `search`, `start_date`, `end_date` params
- **UPDATED** `getallpenerimaanpembelian.php` — added `search`, `start_date`, `end_date` params
