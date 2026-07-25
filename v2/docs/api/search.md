# Global Search API

> **Last updated:** 2026-07-25 00:00:00 WIB
> **Base URL:** `/api/v2/search`
> **Auth:** All endpoints require `Authorization: Bearer <access_token>`

A single `GET /api/v2/search` endpoint that fans a query string out across every major transactional and master-data table and returns a compact, grouped preview — built for a command-palette style dropdown, not a full results page. Results are always scoped to the caller's `company_id` from the JWT, exactly like every other list endpoint. There is no permission-based filtering of which groups appear — any authenticated user in the company can see a match in any group, the same as if they called that resource's own `GET` list endpoint directly.

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/v2/search` | Search across sales, purchase, master data, and finance records |

---

### GET `/api/v2/search`

#### Query parameters

| Parameter | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| q         | string | Yes      | —       | The search text. Matched with `LIKE '%q%'` against each resource's display/name field(s). |
| limit     | int    | No       | 5       | Max items returned per group (max 10). |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Search results found",
  "data": {
    "groups": [
      {
        "type": "sales-order",
        "label": "Sales Orders",
        "items": [
          { "id": "a1b2c3d4-...", "title": "SO-2026-0001", "subtitle": "PT Sumber Baja" }
        ]
      },
      {
        "type": "purchase-order",
        "label": "Purchase Orders",
        "items": [
          { "id": "b2c3d4e5-...", "title": "PO-2026-0001", "subtitle": "PT Sumber Baja", "type_name": "Local" }
        ]
      }
    ]
  }
}
```

`data.groups` only contains entries for resource types that had at least one match — a group with zero matches is omitted entirely rather than returned with an empty `items` array. If nothing matches anywhere, `data.groups` is `[]` (this is a normal `200`, not a `404`).

Every item has `id`, `title`, `subtitle`. The `purchase-order` group additionally returns `type_name` (`Local` / `Import`, from `purchase_type`) since a purchase order's detail route differs by type.

#### Group catalog

| `type` | `label` | Table | Matched against | `title` | `subtitle` |
|---|---|---|---|---|---|
| `sales-order` | Sales Orders | `sales_order` | `so_display_number` | `so_display_number` | `customer_name` |
| `sales-invoice` | Sales Invoices | `sales_invoice` | `invoice_display_number` | `invoice_display_number` | `customer_name` |
| `sales-delivery` | Sales Deliveries | `sales_delivery` | `do_display_number` | `do_display_number` | `customer_name` |
| `sales-sppb` | Sales SPPBs | `sales_sppb` | `sppb_display_number` | `sppb_display_number` | `customer_name` |
| `purchase-order` | Purchase Orders | `purchase_order` | `po_display_number` | `po_display_number` | `supplier_name` |
| `purchase-receive` | Purchase Receives | `purchase_receive` | linked `purchase_order.po_display_number` | `po_display_number` (of the linked PO) | `supplier_name` |
| `purchase-invoice` | Purchase Invoices | `purchase_invoice` | `invoice_display_number` | `invoice_display_number` | `supplier_name` |
| `customer` | Customers | `customer` | `customer_name`, `customer_pic_name` | `customer_name` | `customer_pic_name` |
| `supplier` | Suppliers | `supplier` | `supplier_name`, `supplier_pic_name` | `supplier_name` | `supplier_pic_name` |
| `product` | Products | `product` | `product_name`, `hs_code` | `product_name` | `hs_code` |
| `finance-transaction` | Finance Transactions | `finance_transaction` | `voucher_number`, `payee` | `voucher_number` | `payee` |
| `finance-payment` | Finance Payments | `finance_payment` | `invoice_number` | `invoice_number` | `customer_name` or `supplier_name`, whichever the payment is linked to |

All matches exclude soft-deleted rows (`deleted_at IS NULL`) on the primary table of each group.

#### Response `400 Bad Request`

```json
{
  "status_code": 400,
  "status_message": "q is required",
  "data": []
}
```

---

## Error responses (all endpoints)

| Code | When |
|------|------|
| 400  | `q` missing or empty, or `company_id` missing from JWT |
| 401  | Missing or expired Bearer token |
| 405  | HTTP method other than `GET` |
| 500  | Internal server error |

---

## Notes

- This endpoint is a preview/lookup surface only — it does not paginate and caps results per group (`limit`, default 5, max 10). For full paginated results within one resource type, call that resource's own `GET` list endpoint (e.g. `GET /api/v2/sales-order?search=...`).
- `employee`/HR and `company`/`user` entity types are intentionally out of scope — there is no v2 `company`/`user` resource yet (companies still come from a legacy v1 endpoint), and HR has no dedicated employee master table to search against (`salary_transaction` links to `app_user` in `movira_core_dev`, not a company-scoped resource table).
- No schema changes were required for this endpoint — it only reads existing tables.
