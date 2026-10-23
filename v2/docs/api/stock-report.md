# Stock Report API

> **Last updated:** 2026-10-21 21:40:00 WIB
> **Base URL:** `/api/v2/stock-report`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`
> **Permission:** any one of `warehouse.stock.view`, `warehouse.report_weekly.view`, `warehouse.report_movement.view` (otherwise `403`)

Per-product stock movement for a week, month or year: opening balance, total in, total out, closing balance,
plus in/out per day (weekly, monthly) or per month (yearly).

Balances are derived backwards from the current lot balances (`SUM(warehouse_lot.end_balance)`):
`balance at D = current − movements dated after D`. Products are those that have at least one non-deleted lot.
`stock_in` counts as in, `stock_out` as out, `adjustment` by the sign of its quantity. `transfer` moves stock
between lots of the same product, so it is not reported as in/out (it still nets into the balances).

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v2/stock-report` | Stock report as JSON |
| GET | `/api/v2/stock-report/export` | Same report as `.xlsx` (binary download) |

### Query parameters (both endpoints)

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| period | string | No | `weekly` | `weekly`, `monthly` or `yearly` |
| year | int | No | current year | `monthly` / `yearly` only. 2000–2100 |
| month | int | No | current month | `monthly` only. 1–12 |
| date_from | string (date) | No | `date_to` − 6 days | `weekly` only. `YYYY-MM-DD` |
| date_to | string (date) | No | today | `weekly` only. `YYYY-MM-DD`; at most 31 days after `date_from` |

---

### GET `/api/v2/stock-report`

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Stock report found",
  "data": {
    "period": "monthly",
    "period_label": "Agustus 2025",
    "date_from": "2025-08-01",
    "date_to": "2025-08-31",
    "granularity": "day",
    "data": [
      {
        "product_id": "0bc90524-8b27-11f1-93b5-525400d7fdd0",
        "product_code": "F-223",
        "product_name": "Milk Permeate Powder (MPP)",
        "beginning_balance": 5500,
        "total_in": 0,
        "total_out": 2250,
        "ending_balance": 3250,
        "movements": [
          { "bucket": "2025-08-01", "label": "1 Agustus 2025", "in": 0, "out": 0 }
        ]
      }
    ]
  }
}
```

`granularity` is `day` for weekly/monthly (`bucket` = `YYYY-MM-DD`) and `month` for yearly (`bucket` = `YYYY-MM`,
`label` = Indonesian month name). Every bucket in the period is present, including empty ones.
Quantities are numbers (up to 3 decimals).

#### Response `400 Bad Request`

```json
{ "status_code": 400, "status_message": "period must be one of weekly, monthly, yearly", "data": [] }
```

Other `400` messages: `year must be between 2000 and 2100`, `month must be between 1 and 12`,
`date_from must be YYYY-MM-DD format`, `date_to must be YYYY-MM-DD format`,
`date_from must be before or equal to date_to`, `weekly period cannot span more than 31 days`.

#### Response `403 Forbidden`

```json
{ "status_code": 403, "status_message": "You do not have permission to view the stock report", "data": [] }
```

---

### GET `/api/v2/stock-report/export`

Returns `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` with
`Content-Disposition: attachment; filename="laporan_stok_{period}_{date_from}_{date_to}.xlsx"`.

Sheet layout: title (`LAPORAN STOK MINGGUAN / BULANAN / TAHUNAN`), `Periode: {period_label}`, a two-row header
(`No, Kode Produk, Nama Produk, Saldo Awal, Masuk, Keluar, Saldo Akhir`, then one `Masuk / Keluar` pair per bucket),
one row per product and a `TOTAL` row. Errors are returned as JSON with the same shapes as above.
