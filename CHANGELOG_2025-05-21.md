# BE Changelog — 2025-05-21

> Changes made based on user requests from Intan Dariss and Rizka Venken.
> All endpoints below are ready to consume by FE.

---

## Summary of Changes

| # | User Request | Affected Endpoint | Change Type |
|---|---|---|---|
| 1 | Duplikat data di Sales Invoice list | `GET /finance/showssalesinvoice.php` | Bug Fix |
| 2 | Status tidak berubah setelah create invoice | `POST /sales/insertsalesinvoice.php` | Bug Fix |
| 3 | Duplikat data di Piutang & Hutang | `GET /finance/getoutstandingpayments.php` | Bug Fix |
| 4 | Tidak bisa tahu invoice supplier sudah lunas | `GET /finance/getallpenerimaanpembelian.php` | New Field |
| 5 | Bayar supplier dengan diskon | `POST /finance/insertpenerimaanpembelian.php` | New Parameter |

---

## 1. Bug Fix — Duplikat Data Sales Invoice per Customer

**File:** `GET /finance/showssalesinvoice.php`

**User Request (Intan Dariss):**
Data invoice yang tampil di list customer berulang-ulang padahal seharusnya hanya muncul sekali per invoice.

**Root Cause:**
JOIN ke tabel `salesOrder` dan `salesPPNType` menghasilkan lebih dari satu baris untuk invoice yang sama (JOIN fan-out).

**Solution:**
Ditambahkan `SELECT DISTINCT` agar baris yang identik dihapus dari hasil query.

**FE Impact:**
Tidak ada perubahan struktur response. Data yang dikembalikan sama, hanya duplikatnya yang sudah dihilangkan. FE tidak perlu mengubah apapun.

---

## 2. Bug Fix — Status SO Tidak Berubah Setelah Create Sales Invoice

**File:** `POST /sales/insertsalesinvoice.php`

**User Request (Intan Dariss):**
Setelah admin membuat invoice dari SO, status di halaman admin masih sama seperti sebelum invoice dibuat. Tidak ada bedanya.

**Root Cause:**
Variable `$sales_order_status` sudah didefinisikan di kode tapi tidak pernah dipakai — query `UPDATE salesOrder SET SOStatus = ...` tidak ada sama sekali.

**Solution:**
Ditambahkan query UPDATE untuk mengubah `SOStatus` pada tabel `salesOrder` setelah invoice berhasil dibuat:

```sql
UPDATE salesOrder
SET SOStatus = '7c44858e-1efc-11ef-a', UpdateBy = ?, UpdateDt = ?
WHERE SONumber = ?
```

**FE Impact:**
Setelah call `POST /sales/insertsalesinvoice.php` berhasil, status SO di list akan otomatis berubah. FE cukup refresh/reload data SO setelah response `200 Success`. Tidak ada perubahan pada request body maupun response structure.

---

## 3. Bug Fix — Duplikat Data di Piutang & Hutang

**File:** `GET /finance/getoutstandingpayments.php`

**User Request:**
Halaman Piutang & Hutang menampilkan invoice yang sama berkali-kali dengan nilai `sudah_dibayar` yang berbeda-beda.

**Root Cause:**
Berbeda dengan bug #1. Tabel `financeItem` menyimpan **satu baris per transaksi pembayaran** (bukan satu baris per invoice). Jadi satu invoice yang sudah dibayar 3x akan punya 4 baris (1 baris awal + 3 baris pembayaran). Query sebelumnya mengembalikan semua baris itu secara terpisah.

**Solution:**
Query diubah menggunakan `GROUP BY invoice_number` dengan agregasi:
- `MAX(due_amount)` → nilai invoice asli
- `SUM(paid_amount)` → total yang sudah dibayar
- Filter dipindah dari `WHERE` ke `HAVING` karena menggunakan hasil agregasi

Berlaku untuk kedua bagian: **Piutang Usaha** dan **Hutang Usaha**.

**FE Impact:**
Response structure tidak berubah. Setiap invoice sekarang hanya muncul **satu kali** dengan nilai yang sudah diagregasi. Nilai `sudah_dibayar` sekarang adalah **total** semua pembayaran untuk invoice tersebut, bukan nilai pembayaran satu transaksi.

---

## 4. New Field — Status LUNAS / BELUM LUNAS di Penerimaan Pembelian

**File:** `GET /finance/getallpenerimaanpembelian.php`

**User Request (Rizka Venken):**
"Kalau saya mau cek di data supplier itu dimana kalau sudah ke paid?"

**Solution:**
Ditambahkan field `status` pada setiap item di response:

| Kondisi | Nilai `status` |
|---|---|
| `due_amount = 0` | `"LUNAS"` |
| `due_amount > 0` | `"BELUM LUNAS"` |

**Response Before:**
```json
{
  "due_amount": "0",
  "invoice_number": "PO:VIK/L/VI/2025/024",
  "supplier_name": "PT Supplier ABC",
  ...
}
```

**Response After:**
```json
{
  "due_amount": "0",
  "invoice_number": "PO:VIK/L/VI/2025/024",
  "supplier_name": "PT Supplier ABC",
  ...,
  "status": "LUNAS"
}
```

**FE Implementation Guide:**
- Tampilkan field `status` sebagai badge/chip di tabel list penerimaan pembelian
- Disarankan warna hijau untuk `"LUNAS"` dan merah/kuning untuk `"BELUM LUNAS"`
- Bisa dijadikan filter di FE untuk memisahkan invoice yang sudah lunas dan belum

---

## 5. New Parameter — Diskon Saat Pembayaran Supplier

**File:** `POST /finance/insertpenerimaanpembelian.php`

**User Request (Rizka Venken):**
"Ada 1 supplier yang kasih invoicenya full tapi pas kita mau bayar ada seperti diskon. Untuk diskonnya itu kita kurangin di ERP-nya gimana?"

**Root Cause / Gap:**
Sebelumnya tidak ada parameter diskon. Jika supplier memberikan invoice Rp 100.000.000 tapi dibayar Rp 95.000.000 (ada diskon Rp 5.000.000), maka `due_amount` akan dihitung `100.000.000 - 95.000.000 = 5.000.000` — invoice tidak pernah bisa tutup meskipun sudah dibayar sesuai kesepakatan.

**Solution:**
Ditambahkan parameter opsional `discount_amount_{i}` per invoice. Formula `due` diubah menjadi:

```
due = invoice_amount - paymount_amount - discount_amount
```

**Request Body — Before:**
```
supplier_id       = "abc-123"
invoice_length    = 1
invoice_number_1  = "PO:VIK/L/VI/2025/024"
invoice_amount_1  = 100000000
paymount_amount_1 = 95000000
...
```

**Request Body — After (with discount):**
```
supplier_id        = "abc-123"
invoice_length     = 1
invoice_number_1   = "PO:VIK/L/VI/2025/024"
invoice_amount_1   = 100000000
paymount_amount_1  = 95000000
discount_amount_1  = 5000000       ← NEW (opsional, default 0 jika tidak dikirim)
...
```

**DB Migration Required:**
Sebelum fitur ini bisa digunakan, tim DB/backend harus menjalankan migration berikut:

```sql
ALTER TABLE financeItem ADD COLUMN discount_amount DECIMAL(20,2) DEFAULT 0;
```

Tanpa kolom ini, nilai diskon tidak akan tersimpan dan angka di laporan tidak akan balance:
`paid_amount (95M) + due_amount (0) ≠ invoice_amount (100M)` → selisih Rp 5M hilang tanpa jejak.

**FE Implementation Guide:**
- Tambahkan input field "Diskon" per baris invoice di form pembayaran supplier
- Field ini **opsional** — jika tidak ada diskon, tidak perlu dikirim (atau kirim `0`)
- Validasi di FE: `paymount_amount + discount_amount` tidak boleh melebihi `invoice_amount`
- Tampilkan breakdown di UI: Nilai Invoice | Dibayar | Diskon | Sisa
- Tidak ada perubahan pada response structure — tetap `200 Success` atau `500 Error`

---

## Notes untuk FE

1. **Backward compatible** — semua perubahan tidak merusak request/response yang sudah ada. FE lama tetap berjalan normal.
2. **Parameter diskon bersifat opsional** — hanya kirim `discount_amount_{i}` jika memang ada diskon.
3. **Status `LUNAS`/`BELUM LUNAS`** hanya ada di endpoint `getallpenerimaanpembelian.php`, bukan di `showspurchaseinvoice.php`.
