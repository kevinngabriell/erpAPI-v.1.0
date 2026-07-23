# FE To-Do — Warehouse Stock Reports, HPP Export, Buku Besar Export

> For the frontend team. Covers 3 requests from the 2026-07-23 WhatsApp thread (Warehouse team + Finance/HPP page). Backend is done — this file lists exactly what needs to be added on the FE side.

---

## 1. Warehouse — Laporan Stok Bulanan & Tahunan (for Laporan SPT)

**Where:** Warehouse page, next to the existing "Laporan Stock Mingguan" card.

**What to add:** Two new cards/tabs — **"Laporan Stock Bulanan"** and **"Laporan Stock Tahunan"** — each with a period picker and an **Export Excel** button, same visual pattern as the existing weekly card.

### Bulanan (Monthly)
- Period picker: month + year selectors (default: current month/year)
- On-screen table (optional): call `GET /warehouse/monthlyreport.php?year={year}&month={month}` → returns per-product `BB` (saldo awal), `In`, `Out`, `ending_balance`, plus a `daily_transactions` array if you want a day-by-day drill-down
- Export Excel button: `GET /warehouse/exportmonthlyreport.php?year={year}&month={month}` → downloads `Laporan_Stok_Bulanan_YYYY_MM.xlsx` directly (trigger via `window.location` or an `<a download>`, same as other export buttons in this app — it's a file stream, not JSON)

### Tahunan (Yearly)
- Period picker: year selector only (default: current year)
- On-screen table (optional): call `GET /warehouse/yearlyreport.php?year={year}` → returns per-product `BB`, `In`, `Out`, `ending_balance`, plus a `monthly_transactions` array (12 entries, Jan–Des) if you want a month-by-month drill-down
- Export Excel button: `GET /warehouse/exportyearlyreport.php?year={year}` → downloads `Laporan_Stok_Tahunan_YYYY.xlsx`

**No auth header required** — these endpoints follow the same pattern as the rest of the warehouse module (no `Authorization` header today).

Full request/response reference: [`warehouse/WAREHOUSE_REPORTS_API.md`](warehouse/WAREHOUSE_REPORTS_API.md)

---

## 2. Finance — Buku Besar Excel Export now 1 sheet (no FE action needed unless you parse the file)

**What changed:** `GET /finance/exportbukubesar.php` used to generate **one Excel sheet per account code** (so a full year could mean 30+ tabs). It now generates **a single sheet** with every account as a titled block, one after another.

**FE impact:**
- If your button just triggers a file download (`window.location = '.../exportbukubesar.php?...'`), **nothing to change**.
- If any FE code reads sheet names/tabs out of the downloaded workbook (e.g. a preview feature), that code needs to be updated — there's now only one sheet named `Buku Besar`, and account boundaries are marked by grey `AKUN: {code} - {name}` header rows instead of separate tabs.
- Query params (`start_date`, `end_date`, `account_code`) are unchanged.

Full reference: [`finance/FINANCIAL_REPORTS_API.md`](finance/FINANCIAL_REPORTS_API.md) — section 10.

---

## 3. Finance — HPP (Harga Pokok Penjualan) page: add Export Excel

**Where:** Finance → HPP page (the one with Total Pembelian / Nilai Stok Akhir / Total HPP / Nilai Penjualan / Gross Profit cards).

**What to add:** An **Export Excel** button next to the existing date range picker (`start_date` / `end_date` — same params already used to load the page).

- Button calls: `GET /finance/exporthpp.php?start_date={start_date}&end_date={end_date}` → downloads `HPP_{start_date}_sd_{end_date}.xlsx`
- The file has 2 sheets: **HPP per Produk** (the summary cards + the per-product HPP table already shown on screen) and **Pembelian Periode** (the purchases table)
- No new params needed — reuse whatever `start_date`/`end_date` are currently selected on the page

Full reference: [`finance/FINANCIAL_REPORTS_API.md`](finance/FINANCIAL_REPORTS_API.md) — sections 11–12.

---

## Quick checklist

- [ ] Warehouse: add "Laporan Stock Bulanan" card (month+year picker, Export Excel button)
- [ ] Warehouse: add "Laporan Stock Tahunan" card (year picker, Export Excel button)
- [ ] Finance / HPP page: add Export Excel button using current `start_date`/`end_date`
- [ ] Finance / Buku Besar: verify nothing parses individual sheet tabs from the export; if it does, update to the single-sheet format
