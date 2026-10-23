# Document Signature API

Per-company signer names printed under the signature block of the `.xlsx` exports of
Sales Order, SPPB and Sales Profit (`GET /sales-order|sales-sppb|sales-profit/{id}/export`).
Schema: [v49](../migrations/v49_document_signature.md).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v2/document-signature` | One entry per document type (`sales_order`, `sales_sppb`, `sales_profit`); unconfigured values are `null` |
| PUT | `/api/v2/document-signature/{document_type}` | Upsert the three slots. Needs `master.document_signature.manage` |

PUT body: `created_by_names`, `acknowledged_by_names`, `approved_by_names` — each up to 255 chars,
several names separated by commas. Rendered as `( NAME )`, joined by spaces. Empty/unset prints a
blank `(      )` box to sign by hand.

## List `limit=all`

`GET /sales-order`, `/sales-sppb`, `/sales-delivery`, `/sales-invoice`, `/sales-profit` accept
`limit=all` to return every matching row (used by the frontend Excel exports). Any other value is
still capped at 15 per page.
