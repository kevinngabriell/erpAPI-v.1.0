# Finance Requirements — Full API Documentation

> **Stack:** PHP + MySQL (MySQLi) · Excel via PhpSpreadsheet (already in `/vendor`)
> **Base URL:** `https://yourdomain.com/` (adjust to your server)

---

## Quick Reference

| # | Kebutuhan | Method | Endpoint |
|---|---|---|---|
| 1 | Omset / Penjualan per bulan | GET | `/finance/getomsetperbulan.php` |
| 2 | Piutang & Hutang belum dibayar | GET | `/finance/getoutstandingpayments.php` |
| 3 | PO yang belum di-invoice | GET | `/purchase/getpoblumiinvoice.php` |
| 4 | SO yang belum di-invoice | GET | `/sales/getsoblumiinvoice.php` |
| 5 | Stok / Persediaan | GET | `/warehouse/getstokpersediaan.php` |
| 6a | Laporan Laba Rugi | GET | `/finance/getlaporanlabarugi.php` |
| 6b | Neraca (Balance Sheet) | GET | `/finance/getneraca.php` |
| 6c | Buku Besar (General Ledger) | GET | `/finance/getbukubesar.php` |
| 6d | HPP (Cost of Goods Sold) | GET | `/finance/gethpp.php` |
| 6e | Penyusutan Aset | GET | `/finance/getpenyusutan.php` |
| 7a | Export Omset ke Excel | GET | `/finance/exportomset.php` |
| 7b | Export Laba Rugi ke Excel | GET | `/finance/exportlaborugi.php` |
| 7c | Export Neraca ke Excel | GET | `/finance/exportneraca.php` |
| 7d | Export Buku Besar ke Excel | GET | `/finance/exportbukubesar.php` |
| 7e | Export Buku Kas ke Excel | GET | `/finance/exportbukukas.php` |
| 8 | Buku Kas (Mutasi Harian) | GET | `/finance/getbukukas.php` |
| 9 | Set / Get Beginning Balance (Bank) | POST / GET | `/finance/setbeginningbalance.php` |
| 10 | Set / Get Beginning Balance (Akun COA) | POST / GET | `/finance/setaccountbeginningbalance.php` |

---

## 1. Omset / Penjualan per Bulan

**Endpoint:** `GET /finance/getomsetperbulan.php`

### Query Params
| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `year` | `int` | No | current year | Tahun laporan |
| `month` | `int` | No | 0 (all months) | Filter bulan tertentu (1–12) |
| `customer_id` | `string` | No | — | Filter per customer |

### Example
```
GET /finance/getomsetperbulan.php?year=2025
GET /finance/getomsetperbulan.php?year=2025&month=5
```

### Response `200`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "filter": { "year": 2025, "month": "all", "customer_id": "all" },
  "summary": {
    "grand_total_sebelum_ppn": 135000000,
    "grand_total_termasuk_ppn": 148500000
  },
  "omset_bulanan": [
    { "tahun": 2025, "bulan": 1, "nama_bulan": "January", "total_invoice": 12, "omset_sebelum_ppn": 25000000, "omset_termasuk_ppn": 27500000 }
  ],
  "top_produk": [
    { "productName": "Produk A", "total_qty": 150, "total_nilai": 45000000 }
  ],
  "top_customer": [
    { "company_name": "PT ABC", "total_invoice": 8, "total_nilai": 32000000 }
  ]
}
```

### Excel Export
```
GET /finance/exportomset.php?year=2025
```
Downloads: `Omset_Penjualan_2025.xlsx`

---

## 2. Piutang & Hutang yang Belum Dibayar

**Endpoint:** `GET /finance/getoutstandingpayments.php`

### Query Params
| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `type` | `string` | No | `all` | `piutang` / `hutang` / `all` |

### Example
```
GET /finance/getoutstandingpayments.php?type=all
GET /finance/getoutstandingpayments.php?type=piutang
GET /finance/getoutstandingpayments.php?type=hutang
```

### Response `200`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "type": "all",
  "piutang_usaha": {
    "total_piutang": 18500000,
    "total_invoice": 5,
    "data": [
      {
        "invoice_number": "INV-2025-001",
        "nama_pelanggan": "PT ABC",
        "customer_id": "abc-123",
        "tanggal_invoice": "2025-04-01",
        "term_of_payment": 30,
        "jatuh_tempo": "2025-05-01",
        "hari_overdue": 20,
        "sisa_tagihan": 5000000,
        "nilai_invoice": 5000000,
        "sudah_dibayar": 0,
        "status": "Overdue"
      }
    ]
  },
  "hutang_usaha": {
    "total_hutang": 9200000,
    "total_invoice": 3,
    "data": [
      {
        "invoice_number": "PO-INV-001",
        "nama_supplier": "CV XYZ",
        "supplier_id": "xyz-456",
        "tanggal_invoice": "2025-04-10",
        "sisa_hutang": 3200000,
        "nilai_invoice": 3200000,
        "sudah_dibayar": 0,
        "hari_sejak_invoice": 41
      }
    ]
  }
}
```

---

## 3. PO yang Belum Di-Invoice

**Endpoint:** `GET /purchase/getpoblumiinvoice.php`

### Query Params
| Param | Type | Required | Description |
|---|---|---|---|
| `start_date` | `YYYY-MM-DD` | No | Filter tanggal PO |
| `end_date` | `YYYY-MM-DD` | No | Filter tanggal PO |
| `supplier_id` | `string` | No | Filter per supplier |
| `page` | `int` | No | Default: 1 |
| `limit` | `int` | No | Default: 25 |

### Example
```
GET /purchase/getpoblumiinvoice.php?start_date=2025-01-01&end_date=2025-05-31
```

### Response `200`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "totalItems": 12,
  "page": 1,
  "limit": 25,
  "total_nilai_po_belum_invoice": 45000000,
  "Data": [
    {
      "PONumber": "PO-2025-001",
      "PODate": "2025-03-15",
      "supplier_name": "CV Supplier A",
      "PO_Status_Name": "Approved",
      "PO_Type_Name": "Local",
      "total_item": 3,
      "nilai_po": 12000000,
      "hari_sejak_po": 67
    }
  ]
}
```

---

## 4. SO yang Belum Di-Invoice

**Endpoint:** `GET /sales/getsoblumiinvoice.php`

### Query Params
| Param | Type | Required | Description |
|---|---|---|---|
| `start_date` | `YYYY-MM-DD` | No | Filter tanggal SO |
| `end_date` | `YYYY-MM-DD` | No | Filter tanggal SO |
| `customer_id` | `string` | No | Filter per customer |
| `page` | `int` | No | Default: 1 |
| `limit` | `int` | No | Default: 25 |

### Example
```
GET /sales/getsoblumiinvoice.php?start_date=2025-01-01&end_date=2025-05-31
```

### Response `200`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "totalItems": 8,
  "page": 1,
  "limit": 25,
  "total_nilai_so_belum_invoice": 32000000,
  "Data": [
    {
      "SONumber": "SO-2025-045",
      "SODate": "2025-05-01",
      "nama_pelanggan": "PT Pelanggan B",
      "customer_id": "cust-789",
      "status_so": "Approved",
      "total_item": 5,
      "nilai_so": 8500000,
      "hari_sejak_so": 20
    }
  ]
}
```

---

## 5. Stok / Persediaan

**Endpoint:** `GET /warehouse/getstokpersediaan.php`

### Query Params
| Param | Type | Required | Description |
|---|---|---|---|
| `product_name` | `string` | No | Filter nama produk (LIKE search) |
| `sku_id` | `string` | No | Filter kode produk |
| `page` | `int` | No | Default: 1 |
| `limit` | `int` | No | Default: 50 |

### Example
```
GET /warehouse/getstokpersediaan.php
GET /warehouse/getstokpersediaan.php?product_name=besi
```

### Response `200`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "totalItems": 45,
  "page": 1,
  "limit": 50,
  "Data": [
    {
      "kode_produk": "SKU-001",
      "nama_produk": "Besi Hollow 40x40",
      "jumlah_lot": 3,
      "total_stok": 250.0,
      "stok_min_lot": 50.0,
      "stok_max_lot": 100.0,
      "lots": [
        { "lot": "LOT-2025-001", "stok": 100.0, "exp_date": "2026-01-01" },
        { "lot": "LOT-2025-002", "stok": 80.0, "exp_date": "2026-03-01" },
        { "lot": "LOT-2025-003", "stok": 70.0, "exp_date": null }
      ]
    }
  ]
}
```

> **Note:** Untuk update stok (masuk/keluar), gunakan endpoint yang sudah ada:
> - `POST /warehouse/productin.php` — Barang masuk
> - `POST /warehouse/productout.php` — Barang keluar

---

## 6a. Laporan Laba Rugi

**Endpoint:** `GET /finance/getlaporanlabarugi.php`

### Query Params
| Param | Required | Description |
|---|---|---|
| `start_date` | Yes | `YYYY-MM-DD` |
| `end_date` | Yes | `YYYY-MM-DD` |

### Excel Export
```
GET /finance/exportlaborugi.php?start_date=2025-05-01&end_date=2025-05-31
```
Downloads: `Laporan_Laba_Rugi_2025-05-01_sd_2025-05-31.xlsx`

---

## 6b. Neraca (Balance Sheet)

**Endpoint:** `GET /finance/getneraca.php`

### Query Params
| Param | Required | Default | Description |
|---|---|---|---|
| `as_of_date` | No | today | `YYYY-MM-DD` — snapshot date |

### Excel Export
```
GET /finance/exportneraca.php?as_of_date=2025-05-31
```
Downloads: `Neraca_2025-05-31.xlsx`

---

## 6c. Buku Besar (General Ledger)

**Endpoint:** `GET /finance/getbukubesar.php`

### Query Params
| Param | Required | Description |
|---|---|---|
| `start_date` | Yes | `YYYY-MM-DD` |
| `end_date` | Yes | `YYYY-MM-DD` |
| `account_code` | No | Filter by specific COA code |

### Excel Export
```
GET /finance/exportbukubesar.php?start_date=2025-05-01&end_date=2025-05-31
```
Downloads: `Buku_Besar_2025-05-01_sd_2025-05-31.xlsx`
Each account code gets its own Excel sheet with running balance.

---

## 6d. HPP (Harga Pokok Penjualan / COGS)

**Endpoint:** `GET /finance/gethpp.php`

### Query Params
| Param | Required | Description |
|---|---|---|
| `start_date` | Yes | `YYYY-MM-DD` |
| `end_date` | Yes | `YYYY-MM-DD` |

### Response `200`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "period": { "start_date": "2025-05-01", "end_date": "2025-05-31" },
  "summary": {
    "total_pembelian_periode": 40000000,
    "total_nilai_stok_akhir": 15000000,
    "total_hpp": 28500000,
    "total_nilai_penjualan": 55000000,
    "gross_profit": 26500000
  },
  "pembelian_periode": [
    { "nama_produk": "Produk A", "qty_beli": 100, "nilai_pembelian": 12000000 }
  ],
  "hpp_per_produk": [
    {
      "nama_produk": "Produk A",
      "qty_terjual": 80,
      "harga_pokok_rata": 120000,
      "hpp": 9600000,
      "nilai_penjualan": 14400000,
      "gross_profit": 4800000,
      "stok_akhir": 20
    }
  ]
}
```

> **HPP Formula:** `HPP = qty_terjual × rata-rata harga beli per produk`

---

## 6e. Penyusutan Aset Tetap (Depreciation)

**Endpoint:** `GET /finance/getpenyusutan.php`

### ⚠️ Prerequisite: Create `fixed_assets` Table

This endpoint requires a new table. Run this SQL first:

```sql
CREATE TABLE fixed_assets (
  asset_id       VARCHAR(36)    PRIMARY KEY,
  asset_code     VARCHAR(50)    NOT NULL UNIQUE,
  asset_name     VARCHAR(255)   NOT NULL,
  category       VARCHAR(100),
  purchase_date  DATE           NOT NULL,
  purchase_price DECIMAL(18,2)  NOT NULL,
  residual_value DECIMAL(18,2)  NOT NULL DEFAULT 0,
  useful_life    INT            NOT NULL COMMENT 'in months',
  method         ENUM('straight-line','declining-balance') NOT NULL DEFAULT 'straight-line',
  is_active      TINYINT(1)     NOT NULL DEFAULT 1,
  insert_by      VARCHAR(100),
  insert_dt      DATETIME
);
```

### Query Params
| Param | Required | Default | Description |
|---|---|---|---|
| `as_of_date` | No | today | `YYYY-MM-DD` — hitung nilai buku per tanggal ini |
| `category` | No | — | Filter kategori aset (mis: Kendaraan, Mesin, Bangunan) |

### Example
```
GET /finance/getpenyusutan.php?as_of_date=2025-05-31
GET /finance/getpenyusutan.php?as_of_date=2025-05-31&category=Kendaraan
```

### Response `200`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "as_of_date": "2025-05-31",
  "summary": {
    "total_aset": 5,
    "total_harga_perolehan": 850000000,
    "total_akumulasi_penyusutan": 212500000,
    "total_penyusutan_per_tahun": 85000000,
    "total_nilai_buku": 637500000
  },
  "Data": [
    {
      "asset_code": "AST-001",
      "asset_name": "Kendaraan Operasional",
      "category": "Kendaraan",
      "purchase_date": "2022-01-01",
      "purchase_price": 300000000,
      "residual_value": 30000000,
      "useful_life": 60,
      "method": "straight-line",
      "depreciable_amount": 270000000,
      "months_elapsed": 40,
      "akumulasi_penyusutan": 180000000,
      "penyusutan_per_tahun": 54000000,
      "penyusutan_per_bulan": 4500000,
      "nilai_buku": 120000000
    }
  ]
}
```

---

## 7. Excel Exports Summary

| Laporan | Endpoint | Params | Filename |
|---|---|---|---|
| Omset per Bulan | `/finance/exportomset.php` | `year`, `month?`, `customer_id?` | `Omset_Penjualan_{year}.xlsx` |
| Laba Rugi | `/finance/exportlaborugi.php` | `start_date`, `end_date` | `Laporan_Laba_Rugi_{start}_sd_{end}.xlsx` |
| Neraca | `/finance/exportneraca.php` | `as_of_date?` | `Neraca_{date}.xlsx` |
| Buku Besar | `/finance/exportbukubesar.php` | `start_date`, `end_date`, `account_code?` | `Buku_Besar_{start}_sd_{end}.xlsx` |
| Buku Kas | `/finance/exportbukukas.php` | `bank_account`, `start_date`, `end_date` | `Buku_Kas_{bank}_{start}_sd_{end}.xlsx` |

> All exports stream the `.xlsx` file directly as a download (no file saved on server).

---

## Error Codes

| Code | Status | Meaning |
|---|---|---|
| 200 | Success | OK |
| 400 | Bad Request | Missing required params |
| 404 | Not Found | Table not found (penyusutan) |
| 405 | Method Not Allowed | Wrong HTTP method |
| 500 | Error | DB query error — `message` field has detail |

---

## Files Created

### Finance
| File | Description |
|---|---|
| `finance/getomsetperbulan.php` | Omset per bulan |
| `finance/getoutstandingpayments.php` | Piutang & hutang belum dibayar |
| `finance/gethpp.php` | HPP / COGS |
| `finance/getpenyusutan.php` | Penyusutan aset tetap |
| `finance/getlaporanlabarugi.php` | Laporan laba rugi |
| `finance/getneraca.php` | Neraca |
| `finance/getbukubesar.php` | Buku besar / GL |
| `finance/exportomset.php` | Excel: omset |
| `finance/exportlaborugi.php` | Excel: laba rugi |
| `finance/exportneraca.php` | Excel: neraca |
| `finance/exportbukubesar.php` | Excel: buku besar (per sheet per akun) |
| `finance/exportbukukas.php` | Excel: buku kas (mutasi harian) |
| `finance/getbukukas.php` | Buku kas — mutasi harian dengan running saldo |
| `finance/setbeginningbalance.php` | Set / get beginning balance per bank account |
| `finance/setaccountbeginningbalance.php` | Set / get beginning balance per akun COA (single & bulk) |

### Purchase
| File | Description |
|---|---|
| `purchase/getpoblumiinvoice.php` | PO belum di-invoice |

### Sales
| File | Description |
|---|---|
| `sales/getsoblumiinvoice.php` | SO belum di-invoice |

### Warehouse
| File | Description |
|---|---|
| `warehouse/getstokpersediaan.php` | Stok persediaan per produk + lot |

---

## 8. Buku Kas — Mutasi Kas Harian

**Endpoint:** `GET /finance/getbukukas.php`

### Query Params
| Param | Type | Required | Description |
|---|---|---|---|
| `bank_account` | `string` | Yes | ID rekening bank |
| `start_date` | `YYYY-MM-DD` | Yes | Awal periode |
| `end_date` | `YYYY-MM-DD` | Yes | Akhir periode |

### Example
```
GET /finance/getbukukas.php?bank_account=KAS-001&start_date=2025-02-01&end_date=2025-02-28
```

### Response `200`
```json
{
  "beginning_balance": 6982458,
  "end_balance": 6275458,
  "total_transactions": 10,
  "transactions": [
    {
      "transaction_date": "2025-02-03 07:00:00",
      "chequeno": null,
      "account_name_alias": "BIAYA TRANSPORT KARYAWAN",
      "amount": "255000",
      "category_name": "Pengeluaran"
    }
  ]
}
```

> **Note:** `beginning_balance` automatically includes any adjustment set via endpoint #9. No extra param needed — it just works.

### Bug Fix (2025-05-25)
Transaksi tanggal diluar range (contoh: 31 Jan muncul saat search 1–28 Feb) disebabkan oleh perbandingan DATETIME tanpa strip time. Fixed dengan `DATE()` wrapper di semua query filter.

### Excel Export
```
GET /finance/exportbukukas.php?bank_account=KAS-001&start_date=2025-02-01&end_date=2025-02-28
```
Downloads: `Buku_Kas_KAS-001_2025-02-01_sd_2025-02-28.xlsx`

---

## 9. Set / Get Beginning Balance — Bank Account

**Endpoint:** `POST /finance/setbeginningbalance.php` · `GET /finance/setbeginningbalance.php`

> **No new table required.** Stored as a special entry in `financeTransaction` with `memo = '__SALDO_AWAL__'`.
> The API computes the **adjustment** (desired balance − current computed balance) and stores that,
> so all existing balance SUM queries produce the correct result automatically.

### POST — Set Beginning Balance

**Request Body (form-data or x-www-form-urlencoded)**

| Field | Type | Required | Description |
|---|---|---|---|
| `bank_account` | `string` | Yes | ID rekening bank |
| `as_of_date` | `YYYY-MM-DD` | Yes | Tanggal saldo awal |
| `amount` | `decimal` | Yes | Nominal saldo awal yang diinginkan |
| `insert_by` | `string` | No | Username yang input |

**Example**
```
POST /finance/setbeginningbalance.php
bank_account=KAS-001 & as_of_date=2025-01-01 & amount=6982458 & insert_by=admin
```

**Response `200` — Created**
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "action": "created",
  "bank_account": "KAS-001",
  "as_of_date": "2025-01-01",
  "desired_amount": 6982458,
  "adjustment": 6982458
}
```

**Response `200` — Updated** (jika sudah ada entry untuk bank + tanggal yang sama, akan di-replace)
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "action": "updated",
  "bank_account": "KAS-001",
  "as_of_date": "2025-01-01",
  "desired_amount": 7000000,
  "adjustment": 17542
}
```

**Response `200` — No Change** (jika balance sudah benar, tidak perlu adjustment)
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "action": "no_change",
  "bank_account": "KAS-001",
  "as_of_date": "2025-01-01",
  "desired_amount": 6982458,
  "adjustment": 0
}
```

> **`adjustment`** adalah selisih yang disimpan ke DB, bukan `desired_amount`-nya langsung.
> FE cukup kirim `amount` = nilai saldo awal yang diinginkan — backend hitung sisanya.

### GET — Retrieve Beginning Balance Entries

**Query Params**

| Param | Type | Required | Description |
|---|---|---|---|
| `bank_account` | `string` | Yes | ID rekening bank |
| `as_of_date` | `YYYY-MM-DD` | No | Filter tanggal tertentu; kosong = semua |

**Example**
```
GET /finance/setbeginningbalance.php?bank_account=KAS-001
GET /finance/setbeginningbalance.php?bank_account=KAS-001&as_of_date=2025-01-01
```

**Response `200`**
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "bank_account": "KAS-001",
  "total_records": 1,
  "data": [
    {
      "id_transaction": "abc123...",
      "bank_account": "KAS-001",
      "as_of_date": "2025-01-01",
      "amount": 6982458.00,
      "insertby": "admin",
      "insertdt": "2025-01-01 09:00:00"
    }
  ]
}
```

> `amount` di response GET adalah nilai **setelah sign correction** (positif = saldo DR, negatif = saldo CR).
> Ini adalah nilai adjustment yang tersimpan, bukan necessarily sama dengan `desired_amount` yang dikirim.

### How it affects Buku Kas & Export

The `__SALDO_AWAL__` adjustment is automatically included in all balance SUM queries.
- `getbukukas.php` / `exportbukukas.php` → beginning balance and end balance correct automatically
- Transaction **list** → `__SALDO_AWAL__` entries are filtered out and never shown as a row to the user

---

## 10. Set / Get Beginning Balance — Akun COA (Chart of Accounts)

**Endpoint:** `POST /finance/setaccountbeginningbalance.php` · `GET /finance/setaccountbeginningbalance.php`

Digunakan untuk input saldo awal per kode akun (seperti data "Beg Balance 2025 - VIK" dari spreadsheet).
Setelah disimpan, digunakan otomatis oleh `getbukubesar.php` dan `exportbukubesar.php`.

> **No new table required.** Stored as a special entry in `financeTransaction` with `memo = '__SALDO_AWAL__'`.
> Backend hitung adjustment otomatis; FE cukup kirim nilai saldo awal yang diinginkan.

> **Amount convention:** Positive (+) = Debit balance (Aset, Biaya). Negative (−) = Credit balance (Liabilitas, Ekuitas, Akumulasi Penyusutan).

---

### POST — Single Entry (form-data)

| Field | Type | Required | Description |
|---|---|---|---|
| `account_code` | `string` | Yes | Kode akun (e.g. `1000.01`) |
| `as_of_date` | `YYYY-MM-DD` | Yes | Tanggal saldo awal |
| `amount` | `decimal` | Yes | Saldo awal yang diinginkan (positif = debit, negatif = kredit) |
| `insert_by` | `string` | No | Username |

**Example**
```
POST /finance/setaccountbeginningbalance.php
account_code=1000.01 & as_of_date=2025-01-01 & amount=6116785 & insert_by=admin
```

**Response `200`**
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "action": "created",
  "account_code": "1000.01",
  "as_of_date": "2025-01-01",
  "desired_amount": 6116785,
  "adjustment": 6116785
}
```

---

### POST — Bulk Entry (JSON body)

Kirim seluruh data dari spreadsheet sekaligus dengan `Content-Type: application/json`.

**Request Body**
```json
{
  "as_of_date": "2025-01-01",
  "insert_by": "admin",
  "balances": [
    { "account_code": "1000.01", "amount":  6116785 },
    { "account_code": "1000.02", "amount":  0 },
    { "account_code": "1000.04", "amount": -2748401419 },
    { "account_code": "1700.06", "amount": -3519611236 },
    { "account_code": "2100",    "amount": -957229403 },
    { "account_code": "3001",    "amount": -18434029892 }
  ]
}
```

> `notes` field dihapus dari bulk body — tidak diperlukan lagi karena storage menggunakan financeTransaction.

**Response `200` (semua berhasil)**
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "as_of_date": "2025-01-01",
  "total_created": 5,
  "total_updated": 0,
  "total_skipped": 1,
  "total_failed": 0,
  "failed": []
}
```

> `total_skipped` = akun yang balance-nya sudah benar (adjustment = 0), tidak perlu entry baru.

**Response partial (sebagian gagal)**
```json
{
  "StatusCode": 207,
  "Status": "Partial Success",
  "total_created": 4,
  "total_updated": 1,
  "total_skipped": 0,
  "total_failed": 1,
  "failed": [{ "index": 2, "account_code": "1000.04", "reason": "..." }]
}
```

---

### GET — Retrieve Beginning Balance Entries

| Param | Type | Required | Description |
|---|---|---|---|
| `account_code` | `string` | No | Filter satu akun; kosong = semua |
| `as_of_date` | `YYYY-MM-DD` | No | Filter tanggal tertentu |

**Example**
```
GET /finance/setaccountbeginningbalance.php?as_of_date=2025-01-01
GET /finance/setaccountbeginningbalance.php?account_code=1000.01
```

**Response `200`**
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "total_records": 30,
  "data": [
    {
      "id_transaction": "abc123...",
      "account_code": "1000.01",
      "account_name": "Kas Kecil",
      "account_name_alias": "KAS KECIL",
      "as_of_date": "2025-01-01",
      "amount": 6116785.00,
      "insertby": "admin",
      "insertdt": "2025-01-01 09:00:00"
    }
  ]
}
```

> `amount` di response adalah nilai adjustment yang tersimpan (setelah sign correction), bukan saldo absolut yang dikirim FE.

---

### How it affects Buku Besar & Export

The `__SALDO_AWAL__` adjustment is automatically included in all opening balance SUM queries.
- `getbukubesar.php` / `exportbukubesar.php` → `opening_balance` per akun sudah benar otomatis
- Transaction **list** in Buku Besar → `__SALDO_AWAL__` entries are filtered out and never shown as a row
- `getneraca.php` → `__SALDO_AWAL__` filtered from kas_bank and laba_ditahan calculations to prevent contamination
