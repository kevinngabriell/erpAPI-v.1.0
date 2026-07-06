# Dashboard API

> **Last updated:** 2026-07-06 20:00:00 WIB
> **Base URL:** `/api/v2/dashboard`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

Read-only reporting/dashboard widgets. Every endpoint is `GET`, scoped to the authenticated company (`company_id` from the JWT), and returns live aggregates — nothing here is cached or precomputed.

This module replaces the old single-tenant v1 dashboard/reporting endpoints (`master/getoveralldashboard.php`, `purchase/getoverallpurchase.php`, `finance/alloutstandingcustomer.php`, `finance/getoutstandingpayments.php`, and the various `sales/gettop*.php` / `purchase/gettop*.php` files). Every widget below is now company-scoped, which the old endpoints never were.

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v2/dashboard/overview` | Full-year overview: totals, monthly charts, top products, outstanding AR/AP |
| GET | `/api/v2/dashboard/purchase-overview` | Current month's PO counts by status (Draft/Approved/Received/Invoice) |
| GET | `/api/v2/dashboard/top-sales-orders` | Latest 3 sales orders |
| GET | `/api/v2/dashboard/top-sppb` | Latest 3 SPPB documents |
| GET | `/api/v2/dashboard/top-sales-invoices` | Latest 3 sales invoices |
| GET | `/api/v2/dashboard/top-delivery-orders` | Latest 3 delivery orders |
| GET | `/api/v2/dashboard/top-profit` | Latest 3 sales profit records |
| GET | `/api/v2/dashboard/top-purchase-receives` | Latest 4 purchase receives |
| GET | `/api/v2/dashboard/top-purchase-invoices` | Latest 4 purchase invoices |
| GET | `/api/v2/dashboard/top-purchase-import` | Latest 4 Import-type purchase orders, with items |
| GET | `/api/v2/dashboard/top-purchase-local` | Latest 4 Local-type purchase orders, with items |
| GET | `/api/v2/dashboard/outstanding` | AR (piutang) / AP (hutang) outstanding report |

If no widget segment is given (`GET /api/v2/dashboard`), it defaults to `overview`.

---

### GET `/api/v2/dashboard/overview`

#### Query parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| year | int | No | current year | Year to aggregate |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Dashboard overview found",
  "data": {
    "year": 2026,
    "total_target": 5000000000,
    "total_sales": 1200000000,
    "total_purchase": 42,
    "total_invoice": 10,
    "total_outstand_supplier": 85000000,
    "total_outstand_customer": 120000000,
    "sales_chart": [
      { "month": 1, "total_sales": 100000000 }
    ],
    "purchase_chart": [
      { "month": 1, "total_import": 50000000, "total_local": 20000000 }
    ],
    "order_count_by_country": [
      { "country": "China", "order_count": 12 }
    ],
    "top_purchase_products": [
      { "product_name": "Steel Coil", "total_purchase": 300000000 }
    ],
    "top_sales_products": [
      { "product_name": "Steel Coil", "total_sales": 450000000 }
    ]
  }
}
```

`sales_chart` and `purchase_chart` always contain exactly 12 entries (months 1–12), zero-filled for months with no data.

---

### GET `/api/v2/dashboard/purchase-overview`

#### Query parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| month | int | No | current month | 1–12 |
| year | int | No | current year | e.g. 2026 |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Purchase overview found",
  "data": {
    "month": 7,
    "year": 2026,
    "total_draft": 3,
    "total_approved": 5,
    "total_received": 2,
    "total_invoice": 1
  }
}
```

Counts match `purchase_status.status_name` = `Draft`, `Approved`, `Received`, `Invoice` respectively.

---

### GET `/api/v2/dashboard/top-sales-orders`

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Top sales orders found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "so_display_number": "SO/2026/001",
        "so_date": "2026-07-01",
        "customer_name": "PT Contoh Jaya"
      }
    ]
  }
}
```

---

### GET `/api/v2/dashboard/top-sppb`

```json
{
  "status_code": 200,
  "status_message": "Top SPPB found",
  "data": { "data": [ { "id": "...", "sppb_display_number": "SPPB/2026/001", "sppb_date": "2026-07-01", "customer_name": "PT Contoh Jaya" } ] }
}
```

---

### GET `/api/v2/dashboard/top-sales-invoices`

```json
{
  "status_code": 200,
  "status_message": "Top sales invoices found",
  "data": { "data": [ { "id": "...", "invoice_display_number": "INV/2026/001", "invoice_date": "2026-07-01", "sales_order_id": "...", "customer_name": "PT Contoh Jaya" } ] }
}
```

---

### GET `/api/v2/dashboard/top-delivery-orders`

```json
{
  "status_code": 200,
  "status_message": "Top delivery orders found",
  "data": { "data": [ { "id": "...", "do_display_number": "DO/2026/001", "delivery_date": "2026-07-01", "sales_order_id": "...", "customer_name": "PT Contoh Jaya" } ] }
}
```

---

### GET `/api/v2/dashboard/top-profit`

```json
{
  "status_code": 200,
  "status_message": "Top sales profit found",
  "data": { "data": [ { "id": "...", "sales_order_id": "...", "created_at": "2026-07-01 10:00:00", "customer_name": "PT Contoh Jaya" } ] }
}
```

---

### GET `/api/v2/dashboard/top-purchase-receives`

```json
{
  "status_code": 200,
  "status_message": "Top purchase receives found",
  "data": { "data": [ { "id": "...", "receiving_date": "2026-07-01", "purchase_order_id": "...", "po_display_number": "PO/2026/001", "supplier_name": "PT Supplier Makmur" } ] }
}
```

---

### GET `/api/v2/dashboard/top-purchase-invoices`

```json
{
  "status_code": 200,
  "status_message": "Top purchase invoices found",
  "data": { "data": [ { "id": "...", "invoice_display_number": "PINV/2026/001", "invoice_date": "2026-07-01", "purchase_order_id": "...", "po_display_number": "PO/2026/001", "supplier_name": "PT Supplier Makmur" } ] }
}
```

---

### GET `/api/v2/dashboard/top-purchase-import` / `/top-purchase-local`

Filters `purchase_order` by `purchase_type.type_name = 'Import'` or `'Local'` and returns the header plus its full nested `items` array (all items, not just the first one — unlike the old v1 pivot query which only ever surfaced `ProductName1` due to a bug in its column-pivot logic).

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Top import purchase orders found",
  "data": {
    "data": [
      {
        "id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
        "po_display_number": "PO/2026/001",
        "po_date": "2026-07-01",
        "supplier_name": "PT Supplier Makmur",
        "shipment_method": "FOB",
        "payment_method_name": "Bank Transfer",
        "status_name": "Approved",
        "type_name": "Import",
        "items": [
          {
            "id": "...",
            "purchase_order_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
            "product_name": "Steel Coil",
            "quantity": 100,
            "packaging_size": 1,
            "unit_price": 5000000,
            "vat": 0,
            "total": 500000000
          }
        ]
      }
    ]
  }
}
```

---

### GET `/api/v2/dashboard/outstanding`

AR (piutang usaha) and AP (hutang usaha) outstanding report. Replaces `finance/alloutstandingcustomer.php` and `finance/getoutstandingpayments.php`.

#### Query parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| type | string | No | `all` | `piutang` (AR only), `hutang` (AP only), or `all` |
| month | int | No | — (all months) | 1–12 |
| year | int | No | — (all years) | e.g. 2026 |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Outstanding report found",
  "data": {
    "type": "all",
    "filter": { "year": "all", "month": "all" },
    "piutang_usaha": {
      "total_piutang": 120000000,
      "total_invoice": 3,
      "data": [
        {
          "invoice_number": "INV/2026/001",
          "nama_pelanggan": "PT Contoh Jaya",
          "customer_id": "...",
          "tanggal_invoice": "2026-06-01",
          "term_of_payment": 30,
          "jatuh_tempo": "2026-07-01",
          "hari_overdue": 5,
          "nilai_invoice": 50000000,
          "sudah_dibayar": 10000000,
          "sisa_tagihan": 40000000,
          "status": "Overdue"
        }
      ]
    },
    "hutang_usaha": {
      "total_hutang": 85000000,
      "total_invoice": 2,
      "data": [
        {
          "invoice_number": "PINV/2026/001",
          "supplier_name": "PT Supplier Makmur",
          "supplier_id": "...",
          "tanggal_invoice": "2026-06-15",
          "kurs": 1,
          "nilai_invoice": 60000000,
          "sudah_dibayar": 20000000,
          "sisa_hutang": 40000000,
          "hari_sejak_invoice": 21
        }
      ]
    }
  }
}
```

#### Response `400 Bad Request`

```json
{ "status_code": 400, "status_message": "type must be piutang, hutang, or all", "data": [] }
```

---

## Error responses (all endpoints)

| Code | When |
|------|------|
| 400  | Invalid `type` on `/outstanding` |
| 401  | Missing or expired Bearer token |
| 404  | Unknown widget segment |
| 405  | Non-GET method used |
| 500  | Internal server error |

---

## Notes

- Every widget is scoped to the authenticated company via `company_id` from the JWT — the old v1 dashboard/reporting endpoints were single-tenant and had no such scoping at all.
- `outstanding`'s AR/AP figures are in each invoice's original transaction currency (no cross-currency conversion applied), a deliberate simplification versus the old v1 `getoutstandingpayments.php`, which attempted a `kurs`-based conversion through a fragile join chain. If you need converted totals, that's a follow-up.
- `top-purchase-import`/`top-purchase-local` return the *entire* nested `items` array per order rather than the old pivoted "first 5 items, but only the first item ever actually returned" shape — this is a fix, not a like-for-like port.
- `overview`'s outstanding-supplier/customer totals are computed as `SUM(due_amount - paid_amount)`, which is the correct "still owed" figure. The old v1 dashboard summed `due_amount` alone without subtracting payments — this is a bug fix, not a 1:1 port.
- `overview` no longer embeds a `sales_outstand_customers` list (the old dashboard did) — call `GET /api/v2/dashboard/outstanding?type=piutang` for that detail instead, to avoid computing the same heavy report twice per dashboard load.
