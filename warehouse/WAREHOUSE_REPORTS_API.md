# Warehouse Reports API

> **Last updated: 2026-07-23**
> Changes in this update: added Monthly and Yearly stock reports (JSON + Excel export), for SPT (tax) reporting needs.

---

## Table of Contents

1. [Stock Report — Mingguan (Weekly)](#1-stock-report--mingguan-weekly)
2. [Stock Report — Bulanan (Monthly) ⭐ NEW](#2-stock-report--bulanan-monthly-new)
3. [Stock Report — Tahunan (Yearly) ⭐ NEW](#3-stock-report--tahunan-yearly-new)
4. [Export Stock Report Bulanan (Excel) ⭐ NEW](#4-export-stock-report-bulanan-excel-new)
5. [Export Stock Report Tahunan (Excel) ⭐ NEW](#5-export-stock-report-tahunan-excel-new)
6. [Error Responses](#error-responses)

---

## 1. Stock Report — Mingguan (Weekly)

**File:** `warehouse/weeklyreport.php`

_(Existing endpoint, documented here for context — no changes in this update.)_

| Property | Value |
|---|---|
| **Method** | `GET` |
| **Endpoint** | `/warehouse/weeklyreport.php` |

Returns beginning balance, in/out, ending balance and a **daily** breakdown for the current week (Monday–Sunday) for every product. No query parameters — always reports the current week.

---

## 2. Stock Report — Bulanan (Monthly) ⭐ NEW

**File:** `warehouse/monthlyreport.php`

Same shape as the weekly report, but for a full calendar month with a daily breakdown. Use this to power a "Laporan Stok Bulanan" screen.

| Property | Value |
|---|---|
| **Method** | `GET` |
| **Endpoint** | `/warehouse/monthlyreport.php` |

### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `year` | `integer` | No | current year | Report year |
| `month` | `integer` (1–12) | No | current month | Report month |

### Example Request
```
GET /warehouse/monthlyreport.php?year=2026&month=7
```

### Response `200 OK`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "message": "Monthly stock report generated successfully.",
  "period": { "year": 2026, "month": 7, "start_date": "2026-07-01", "end_date": "2026-07-31" },
  "data": [
    {
      "kodeProduk": "SKU-001",
      "namaProduk": "ARA POWDER 20%",
      "BB": "1500",
      "In": "500",
      "Out": "300",
      "ending_balance": "1700",
      "daily_transactions": [
        { "date": "2026-07-01", "In": "0", "Out": "0" },
        { "date": "2026-07-02", "In": "500", "Out": "0" }
      ]
    }
  ]
}
```

### Response `400 Bad Request`
```json
{ "StatusCode": 400, "Status": "Bad Request", "message": "month must be between 1 and 12" }
```

### Notes
- `BB` (Beginning Balance) = stock as of the first day of the requested month
- `ending_balance` = `BB + In - Out`
- `daily_transactions` has one entry per calendar day in the month (28–31 entries depending on the month)
- Quantities are formatted as trimmed decimal strings (trailing zeros removed), matching `weeklyreport.php`

---

## 3. Stock Report — Tahunan (Yearly) ⭐ NEW

**File:** `warehouse/yearlyreport.php`

Same shape as the monthly report, but for a full calendar year with a **monthly** breakdown (12 entries instead of daily). Use this to power a "Laporan Stok Tahunan" screen.

| Property | Value |
|---|---|
| **Method** | `GET` |
| **Endpoint** | `/warehouse/yearlyreport.php` |

### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `year` | `integer` | No | current year | Report year |

### Example Request
```
GET /warehouse/yearlyreport.php?year=2026
```

### Response `200 OK`
```json
{
  "StatusCode": 200,
  "Status": "Success",
  "message": "Yearly stock report generated successfully.",
  "period": { "year": 2026, "start_date": "2026-01-01", "end_date": "2026-12-31" },
  "data": [
    {
      "kodeProduk": "SKU-001",
      "namaProduk": "ARA POWDER 20%",
      "BB": "1000",
      "In": "5000",
      "Out": "4300",
      "ending_balance": "1700",
      "monthly_transactions": [
        { "month": 1, "month_name": "Januari", "In": "500", "Out": "300" },
        { "month": 2, "month_name": "Februari", "In": "0", "Out": "100" }
      ]
    }
  ]
}
```

### Notes
- `BB` (Beginning Balance) = stock as of January 1st of the requested year
- `ending_balance` = `BB + In - Out`
- `monthly_transactions` always has exactly 12 entries (Januari–Desember), even for months with no activity

---

## 4. Export Stock Report Bulanan (Excel) ⭐ NEW

**File:** `warehouse/exportmonthlyreport.php`

Exports a **summary** table (one row per product) for the requested month to Excel. Built for SPT (tax) reporting, so it's a flat recap rather than the full daily breakdown.

| Property | Value |
|---|---|
| **Method** | `GET` |
| **Endpoint** | `/warehouse/exportmonthlyreport.php` |
| **Response** | `.xlsx` file download |

### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `year` | `integer` | No | current year | Report year |
| `month` | `integer` (1–12) | No | current month | Report month |

### Example Request
```
GET /warehouse/exportmonthlyreport.php?year=2026&month=7
```

### Excel Output Columns
`Kode Produk` | `Nama Produk` | `Saldo Awal` | `Barang Masuk` | `Barang Keluar` | `Saldo Akhir`

A `TOTAL` row is appended at the bottom, summing all columns.

### Notes
- Filename format: `Laporan_Stok_Bulanan_2026_07.xlsx`

---

## 5. Export Stock Report Tahunan (Excel) ⭐ NEW

**File:** `warehouse/exportyearlyreport.php`

Exports a **summary** table (one row per product) for the requested year to Excel.

| Property | Value |
|---|---|
| **Method** | `GET` |
| **Endpoint** | `/warehouse/exportyearlyreport.php` |
| **Response** | `.xlsx` file download |

### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `year` | `integer` | No | current year | Report year |

### Example Request
```
GET /warehouse/exportyearlyreport.php?year=2026
```

### Excel Output Columns
`Kode Produk` | `Nama Produk` | `Saldo Awal` | `Barang Masuk` | `Barang Keluar` | `Saldo Akhir`

A `TOTAL` row is appended at the bottom, summing all columns.

### Notes
- Filename format: `Laporan_Stok_Tahunan_2026.xlsx`

---

## Error Responses

| StatusCode | Status | Description |
|---|---|---|
| `400` | Bad Request | `month` outside 1–12 |
| `405` | Method Not Allowed | Wrong HTTP method used |
| `500` | Error | Database query error (message included) |

---

## Changelog

### 2026-07-23
- **NEW** `GET /warehouse/monthlyreport.php` — monthly stock report (all products), daily breakdown, for SPT reporting
- **NEW** `GET /warehouse/yearlyreport.php` — yearly stock report (all products), monthly breakdown, for SPT reporting
- **NEW** `GET /warehouse/exportmonthlyreport.php` — Excel export of the monthly stock summary
- **NEW** `GET /warehouse/exportyearlyreport.php` — Excel export of the yearly stock summary
