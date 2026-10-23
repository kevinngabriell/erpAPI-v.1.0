import { req, folder, ok, created, badReq, notFound, conflict, paginated } from './pm-helpers.mjs';

const COMPANY_ID = 'b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e';

// ============================= SALES ORDER ===================================
const SO_ID = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';
const SO_ITEM_ID = 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c';
const soListRow = {
  id: SO_ID, company_id: COMPANY_ID, so_display_number: 'SO-2026-0001', so_date: '2026-07-01',
  ppn_type_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f', ppn_name: 'PPN 11%', customer_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a',
  customer_name: 'PT Sumber Makmur', send_to_address: 'Jl. Sudirman No. 1, Jakarta', send_date: '2026-07-05',
  status_id: 'e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b', status_name: 'Draft', approved_by: null, approved_at: null,
  created_by: 'Budi Santoso', created_at: '2026-07-01 10:00:00', updated_by: null, updated_at: null, deleted_at: null,
};
const soItemRow = { id: SO_ITEM_ID, sales_order_id: SO_ID, purchase_order_id: null, product_name: 'Steel Rod 12mm', quantity: 100, uom_id: 'a7b8c9d0-e1f2-4a5b-4c5d-6e7f8a9b0c1d', currency_id: 'b8c9d0e1-f2a3-4b5c-5d6e-7f8a9b0c1d2e', unit_price: 50000, kurs: 1, created_by: 'Budi Santoso', created_at: '2026-07-01 10:00:00', updated_by: null, updated_at: null, deleted_at: null };
const soFull = { ...soListRow, items: [soItemRow] };

const salesOrderRoot = [
  req({
    name: 'List Sales Orders', method: 'GET', path: 'sales-order',
    query: [
      { key: 'page', value: '1' }, { key: 'limit', value: '10', description: 'max 100' },
      { key: 'search', value: '', description: 'Search so_display_number', disabled: true },
      { key: 'status_id', value: '', description: 'Resolve from GET /sales-status', disabled: true },
      { key: 'customer_id', value: '', disabled: true }, { key: 'date_from', value: '', disabled: true }, { key: 'date_to', value: '', disabled: true },
    ],
    examples: [
      { code: 200, body: ok('Sales orders found', paginated([soListRow])) },
      { code: 404, body: notFound('No sales orders found') },
    ],
  }),
  req({
    name: 'Generate SO Number', method: 'GET', path: 'sales-order/generate-number',
    description: 'Read-only preview. Format: {seq}/{company_code}-SO/{roman_month}/{year}, e.g. 001/VIK-SO/VII/2026.',
    examples: [
      { code: 200, body: ok('Sales order number generated successfully', { so_display_number: '001/VIK-SO/VII/2026' }) },
      { code: 404, body: notFound('Company not found') },
    ],
  }),
  req({
    name: 'Create Sales Order', method: 'POST', path: 'sales-order',
    body: {
      so_display_number: 'SO-2026-0001', so_date: '2026-07-01', ppn_type_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f',
      customer_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', send_date: '2026-07-05', send_to_address: 'Jl. Sudirman No. 1, Jakarta',
      items: [{ product_name: 'Steel Rod 12mm', quantity: 100, uom_id: 'a7b8c9d0-e1f2-4a5b-4c5d-6e7f8a9b0c1d', currency_id: 'b8c9d0e1-f2a3-4b5c-5d6e-7f8a9b0c1d2e', unit_price: 50000, kurs: 1 }],
    },
    description: 'Server sets status_id to Draft. Header + items is one DB transaction. Triggers notifications to notification.sales_order.approver holders.',
    examples: [
      { code: 201, body: created('Sales order created successfully', { sales_order_id: SO_ID }) },
      { code: 400, body: badReq('so_display_number is required') },
      { code: 400, name: '400 Bad Request (no items)', body: badReq('items must be a non-empty array') },
      { code: 400, name: '400 Bad Request (item field)', body: badReq('items.product_name is required') },
      { code: 409, body: conflict('Sales order already exists') },
    ],
  }),
  req({
    name: 'Get Sales Order Detail', method: 'GET', path: 'sales-order/:id', pathVars: { id: SO_ID },
    examples: [
      { code: 200, body: ok('Sales order found', soFull) },
      { code: 404, body: notFound('Sales order not found') },
    ],
  }),
  req({
    name: 'Update Sales Order', method: 'PUT', path: 'sales-order/:id', pathVars: { id: SO_ID },
    body: { send_to_address: 'Jl. Sudirman No. 1B, Jakarta' },
    description: 'Does not update items. status_id is not updatable here — use approve/reject/revise.',
    examples: [
      { code: 200, body: ok('Sales order updated successfully', []) },
      { code: 400, body: badReq('No fields provided for update') },
      { code: 404, body: notFound('Sales order not found') },
    ],
  }),
  req({
    name: 'Delete Sales Order', method: 'DELETE', path: 'sales-order/:id', pathVars: { id: SO_ID },
    examples: [
      { code: 200, body: ok('Sales order deleted successfully', []) },
      { code: 404, body: notFound('Sales order not found') },
    ],
  }),
  req({
    name: 'Approve Sales Order', method: 'PATCH', path: 'sales-order/:id/approve', pathVars: { id: SO_ID },
    body: { notes: 'Approved by sales manager' },
    examples: [
      { code: 200, body: ok('Sales order approved successfully', []) },
      { code: 404, body: notFound('Sales order not found') },
    ],
  }),
  req({
    name: 'Reject Sales Order', method: 'PATCH', path: 'sales-order/:id/reject', pathVars: { id: SO_ID },
    body: { notes: 'Credit limit exceeded' },
    examples: [
      { code: 200, body: ok('Sales order rejected successfully', []) },
      { code: 404, body: notFound('Sales order not found') },
    ],
  }),
  req({
    name: 'Revise Sales Order', method: 'PATCH', path: 'sales-order/:id/revise', pathVars: { id: SO_ID },
    body: { notes: 'Resubmitting with revised pricing' },
    description: 'Only allowed when current status is Rejected. There is no "un-approve" — approved orders cannot revert to Draft.',
    examples: [
      { code: 200, body: ok('Sales order revised successfully', []) },
      { code: 400, body: badReq('Only rejected sales orders can be revised') },
      { code: 404, body: notFound('Sales order not found') },
    ],
  }),
  req({
    name: 'Export Sales Order (.xlsx)', method: 'GET', path: 'sales-order/:id/export', pathVars: { id: SO_ID },
    description: 'DPP/PPN columns computed server-side from ppn_type.ppn_percentage.',
    examples: [
      { code: 200, name: '200 OK (binary .xlsx)', binary: true, contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', body: '<binary .xlsx file — Content-Disposition: attachment; filename="sales_order_{so_display_number}.xlsx">' },
      { code: 404, body: notFound('Sales order not found') },
    ],
  }),
];
const salesOrderItems = folder('Sales Order Items (sub-resource)', [
  req({
    name: 'List SO Items', method: 'GET', path: 'sales-order/:id/items', pathVars: { id: SO_ID },
    examples: [
      { code: 200, body: ok('Sales order items found', { data: [soItemRow] }) },
      { code: 404, body: notFound('No sales order items found') },
      { code: 404, name: '404 Not Found (parent missing)', body: notFound('Sales order not found') },
    ],
  }),
  req({
    name: 'Add SO Item', method: 'POST', path: 'sales-order/:id/items', pathVars: { id: SO_ID },
    body: { product_name: 'Steel Rod 12mm', quantity: 100, uom_id: 'a7b8c9d0-e1f2-4a5b-4c5d-6e7f8a9b0c1d', currency_id: 'b8c9d0e1-f2a3-4b5c-5d6e-7f8a9b0c1d2e', unit_price: 50000, kurs: 1 },
    examples: [
      { code: 201, body: created('Sales order item created successfully', { sales_order_item_id: SO_ITEM_ID }) },
      { code: 400, body: badReq('product_name is required') },
    ],
  }),
  req({
    name: 'Get SO Item', method: 'GET', path: 'sales-order/:id/items/:item_id', pathVars: { id: SO_ID, item_id: SO_ITEM_ID },
    examples: [
      { code: 200, body: ok('Sales order item found', soItemRow) },
      { code: 404, body: notFound('Sales order item not found') },
    ],
  }),
  req({
    name: 'Update SO Item', method: 'PUT', path: 'sales-order/:id/items/:item_id', pathVars: { id: SO_ID, item_id: SO_ITEM_ID },
    body: { quantity: 120, unit_price: 49000 },
    examples: [
      { code: 200, body: ok('Sales order item updated successfully', []) },
      { code: 400, body: badReq('No fields provided for update') },
      { code: 404, body: notFound('Sales order item not found') },
    ],
  }),
  req({
    name: 'Delete SO Item', method: 'DELETE', path: 'sales-order/:id/items/:item_id', pathVars: { id: SO_ID, item_id: SO_ITEM_ID },
    examples: [
      { code: 200, body: ok('Sales order item deleted successfully', []) },
      { code: 404, body: notFound('Sales order item not found') },
    ],
  }),
]);
const salesOrderFolder = folder('Sales Order (/sales-order)', [...salesOrderRoot, salesOrderItems],
  'status_id is server-resolved (Draft/Approved/Rejected via sales_status.status_name) — never client-supplied.');

// ============================= SALES DELIVERY =================================
const SD_ID = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';
const sdListRow = {
  id: SD_ID, company_id: COMPANY_ID, do_display_number: 'DO-2026-0001', customer_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f',
  customer_name: 'PT Sumber Makmur', sales_order_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', so_display_number: 'SO-2026-0001',
  delivery_date: '2026-07-05', bill_to_address: 'Jl. Sudirman No. 1, Jakarta', ship_to_address: 'Jl. Gatot Subroto No. 2, Jakarta',
  container_number: 'CONT1234567', bl_number: 'BL-998877', vessel_name: 'MV Nusantara', etd_date: '2026-07-10', eta_date: '2026-07-20',
  status_id: 'e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b', status_name: 'Draft', approved_by: null, approved_at: null,
  created_by: 'Budi Santoso', created_at: '2026-07-01 10:00:00', updated_by: null, updated_at: null, deleted_at: null,
};
const sdItemRow = { id: 'e1f2a3b4-c5d6-4e5f-8a9b-0c1d2e3f4a5b', sales_delivery_id: SD_ID, product_name: 'Steel Rod 12mm', quantity: 100, notes: null, created_by: 'Budi Santoso', created_at: '2026-07-01 10:00:00', updated_by: null, updated_at: null, deleted_at: null };
const sdFull = { ...sdListRow, items: [sdItemRow] };

const salesDeliveryFolder = folder('Sales Delivery (/sales-delivery)', [
  req({
    name: 'Generate DO Number', method: 'GET', path: 'sales-delivery/generate-number',
    description: 'Format: {seq}/{company_code}-DO/{roman_month}/{year}.',
    examples: [
      { code: 200, body: ok('Sales delivery number generated successfully', { do_display_number: '001/VIK-DO/VII/2026' }) },
      { code: 404, body: notFound('Company not found') },
    ],
  }),
  req({
    name: 'List Sales Deliveries', method: 'GET', path: 'sales-delivery',
    query: [
      { key: 'page', value: '1' }, { key: 'limit', value: '10', description: 'max 100' },
      { key: 'search', value: '', description: 'Search do_display_number', disabled: true },
      { key: 'status_id', value: '', disabled: true }, { key: 'customer_id', value: '', disabled: true },
      { key: 'sales_order_id', value: '', disabled: true }, { key: 'date_from', value: '', disabled: true }, { key: 'date_to', value: '', disabled: true },
    ],
    examples: [
      { code: 200, body: ok('Sales deliveries found', paginated([sdListRow])) },
      { code: 404, body: notFound('No sales deliveries found') },
    ],
  }),
  req({
    name: 'Create Sales Delivery', method: 'POST', path: 'sales-delivery',
    body: {
      do_display_number: 'DO-2026-0001', customer_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f', sales_order_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a',
      delivery_date: '2026-07-05', bill_to_address: 'Jl. Sudirman No. 1, Jakarta', ship_to_address: 'Jl. Gatot Subroto No. 2, Jakarta',
      container_number: 'CONT1234567', bl_number: 'BL-998877', vessel_name: 'MV Nusantara', etd_date: '2026-07-10', eta_date: '2026-07-20',
      items: [{ product_name: 'Steel Rod 12mm', quantity: 100, notes: null }],
    },
    description: 'sales_order_id must reference an existing, non-deleted sales order. Server sets status_id to Draft.',
    examples: [
      { code: 201, body: created('Sales delivery created successfully', { sales_delivery_id: SD_ID }) },
      { code: 400, body: badReq('do_display_number is required') },
      { code: 400, name: '400 Bad Request (no items)', body: badReq('items must be a non-empty array') },
      { code: 400, name: '400 Bad Request (item field)', body: badReq('items.product_name is required') },
      { code: 404, body: notFound('Sales order not found') },
      { code: 409, body: conflict('Sales delivery already exists') },
      { code: 500, body: badReq('Default sales status "Draft" is not configured') },
    ],
  }),
  req({
    name: 'Get Sales Delivery Detail', method: 'GET', path: 'sales-delivery/:id', pathVars: { id: SD_ID },
    examples: [
      { code: 200, body: ok('Sales delivery found', sdFull) },
      { code: 404, body: notFound('Sales delivery not found') },
    ],
  }),
  req({
    name: 'Update Sales Delivery', method: 'PUT', path: 'sales-delivery/:id', pathVars: { id: SD_ID },
    body: { vessel_name: 'MV Nusantara Jaya', eta_date: '2026-07-22' },
    description: 'Does not update items. status_id is not updatable here.',
    examples: [
      { code: 200, body: ok('Sales delivery updated successfully', []) },
      { code: 400, body: badReq('No fields provided for update') },
      { code: 404, body: notFound('Sales delivery not found') },
    ],
  }),
  req({
    name: 'Delete Sales Delivery', method: 'DELETE', path: 'sales-delivery/:id', pathVars: { id: SD_ID },
    examples: [
      { code: 200, body: ok('Sales delivery deleted successfully', []) },
      { code: 404, body: notFound('Sales delivery not found') },
    ],
  }),
  req({
    name: 'Approve Sales Delivery', method: 'PATCH', path: 'sales-delivery/:id/approve', pathVars: { id: SD_ID },
    body: { notes: 'Confirmed loaded onto vessel' },
    examples: [
      { code: 200, body: ok('Sales delivery approved successfully', []) },
      { code: 404, body: notFound('Sales delivery not found') },
    ],
  }),
  req({
    name: 'Reject Sales Delivery', method: 'PATCH', path: 'sales-delivery/:id/reject', pathVars: { id: SD_ID },
    body: { notes: 'Container not ready' },
    examples: [
      { code: 200, body: ok('Sales delivery rejected successfully', []) },
      { code: 404, body: notFound('Sales delivery not found') },
    ],
  }),
  req({
    name: 'Revise Sales Delivery', method: 'PATCH', path: 'sales-delivery/:id/revise', pathVars: { id: SD_ID },
    body: { notes: 'Resubmitting with new container number' },
    examples: [
      { code: 200, body: ok('Sales delivery revised successfully', []) },
      { code: 400, body: badReq('Only rejected sales deliveries can be revised') },
      { code: 404, body: notFound('Sales delivery not found') },
    ],
  }),
  req({
    name: 'Export Sales Delivery / Surat Jalan (.xlsx)', method: 'GET', path: 'sales-delivery/:id/export', pathVars: { id: SD_ID },
    description: 'Letterhead-positioned layout matching legacy v1 SuratJalanExport.php.',
    examples: [
      { code: 200, name: '200 OK (binary .xlsx)', binary: true, contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', body: '<binary .xlsx file — Content-Disposition: attachment; filename="surat_jalan_{do_display_number}.xlsx">' },
      { code: 404, body: notFound('Sales delivery not found') },
    ],
  }),
], 'status_id is server-resolved (Draft/Approve/Rejected via sales_status.status_name).');

// ============================= SALES INVOICE ==================================
const SI_ID = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';
const siListRow = {
  id: SI_ID, company_id: COMPANY_ID, invoice_display_number: 'INV-2026-0001', customer_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f',
  customer_name: 'PT Sumber Makmur', sales_order_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', so_display_number: 'SO-2026-0001',
  sales_delivery_id: 'e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b', do_display_number: 'DO-2026-0001', invoice_date: '2026-07-05',
  tax_invoice_number: '010.000-26.00000001', ship_to_address: 'Jl. Gatot Subroto No. 2, Jakarta', bill_to_address: 'Jl. Sudirman No. 1, Jakarta',
  status_id: 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c', status_name: 'Draft', approved_by: null, approved_at: null,
  created_by: 'Budi Santoso', created_at: '2026-07-01 10:00:00', updated_by: null, updated_at: null, deleted_at: null,
};
const siItemRow = { id: 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c', sales_invoice_id: SI_ID, product_name: 'Steel Rod 12mm', quantity: 100, unit_price: 50000, tax: 5000, created_by: 'Budi Santoso', created_at: '2026-07-01 10:00:00', updated_by: null, updated_at: null, deleted_at: null };
const siFull = { ...siListRow, items: [siItemRow] };

const salesInvoiceFolder = folder('Sales Invoice (/sales-invoice)', [
  req({
    name: 'Generate Invoice Number', method: 'GET', path: 'sales-invoice/generate-number',
    description: 'Format: {seq}/{company_code}-INV/{roman_month}/{year}.',
    examples: [
      { code: 200, body: ok('Sales invoice number generated successfully', { invoice_display_number: '001/VIK-INV/VII/2026' }) },
      { code: 404, body: notFound('Company not found') },
    ],
  }),
  req({
    name: 'List Sales Invoices', method: 'GET', path: 'sales-invoice',
    query: [
      { key: 'page', value: '1' }, { key: 'limit', value: '10', description: 'max 100' },
      { key: 'search', value: '', description: 'Search invoice_display_number', disabled: true },
      { key: 'status_id', value: '', disabled: true }, { key: 'customer_id', value: '', disabled: true },
      { key: 'sales_order_id', value: '', disabled: true }, { key: 'date_from', value: '', disabled: true }, { key: 'date_to', value: '', disabled: true },
    ],
    examples: [
      { code: 200, body: ok('Sales invoices found', paginated([siListRow])) },
      { code: 404, body: notFound('No sales invoices found') },
    ],
  }),
  req({
    name: 'Create Sales Invoice', method: 'POST', path: 'sales-invoice',
    body: {
      invoice_display_number: 'INV-2026-0001', customer_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f', sales_order_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a',
      invoice_date: '2026-07-05', ship_to_address: 'Jl. Gatot Subroto No. 2, Jakarta', bill_to_address: 'Jl. Sudirman No. 1, Jakarta',
      sales_delivery_id: 'e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b', tax_invoice_number: '010.000-26.00000001',
      items: [{ product_name: 'Steel Rod 12mm', quantity: 100, unit_price: 50000, tax: 5000 }],
    },
    description: 'Server sets status_id to Draft.',
    examples: [
      { code: 201, body: created('Sales invoice created successfully', { sales_invoice_id: SI_ID }) },
      { code: 400, body: badReq('invoice_display_number is required') },
      { code: 400, name: '400 Bad Request (no items)', body: badReq('items must be a non-empty array') },
      { code: 400, name: '400 Bad Request (item field)', body: badReq('items.unit_price is required') },
      { code: 404, body: notFound('Sales order not found') },
      { code: 404, name: '404 Not Found (delivery)', body: notFound('Sales delivery not found') },
      { code: 409, body: conflict('Sales invoice already exists') },
      { code: 500, body: badReq('Default sales status "Draft" is not configured') },
    ],
  }),
  req({
    name: 'Get Sales Invoice Detail', method: 'GET', path: 'sales-invoice/:id', pathVars: { id: SI_ID },
    examples: [
      { code: 200, body: ok('Sales invoice found', siFull) },
      { code: 404, body: notFound('Sales invoice not found') },
    ],
  }),
  req({
    name: 'Update Sales Invoice', method: 'PUT', path: 'sales-invoice/:id', pathVars: { id: SI_ID },
    body: { tax_invoice_number: '010.000-26.00000002' },
    description: 'Does not update items. status_id is not updatable here.',
    examples: [
      { code: 200, body: ok('Sales invoice updated successfully', []) },
      { code: 400, body: badReq('No fields provided for update') },
      { code: 404, body: notFound('Sales invoice not found') },
    ],
  }),
  req({
    name: 'Delete Sales Invoice', method: 'DELETE', path: 'sales-invoice/:id', pathVars: { id: SI_ID },
    examples: [
      { code: 200, body: ok('Sales invoice deleted successfully', []) },
      { code: 404, body: notFound('Sales invoice not found') },
    ],
  }),
  req({
    name: 'Approve Sales Invoice', method: 'PATCH', path: 'sales-invoice/:id/approve', pathVars: { id: SI_ID },
    body: { notes: 'Verified against delivery order' },
    description: 'Only allowed when status is Draft. Also seeds a baseline finance_payment row if one doesn\'t already exist.',
    examples: [
      { code: 200, body: ok('Sales invoice approved successfully', []) },
      { code: 400, body: badReq('Only draft sales invoices can be approved') },
      { code: 404, body: notFound('Sales invoice not found') },
    ],
  }),
  req({
    name: 'Reject Sales Invoice', method: 'PATCH', path: 'sales-invoice/:id/reject', pathVars: { id: SI_ID },
    body: { notes: 'Wrong tax invoice number' },
    description: 'Only allowed when status is Draft — an already-Approved invoice (possibly with payments) can no longer be rejected.',
    examples: [
      { code: 200, body: ok('Sales invoice rejected successfully', []) },
      { code: 400, body: badReq('Only draft sales invoices can be rejected') },
      { code: 404, body: notFound('Sales invoice not found') },
    ],
  }),
  req({
    name: 'Revise Sales Invoice', method: 'PATCH', path: 'sales-invoice/:id/revise', pathVars: { id: SI_ID },
    body: { notes: 'Corrected tax invoice number' },
    examples: [
      { code: 200, body: ok('Sales invoice revised successfully', []) },
      { code: 400, body: badReq('Only rejected sales invoices can be revised') },
      { code: 404, body: notFound('Sales invoice not found') },
    ],
  }),
  req({
    name: 'Export Sales Invoice (.xlsx)', method: 'GET', path: 'sales-invoice/:id/export', pathVars: { id: SI_ID },
    examples: [
      { code: 200, name: '200 OK (binary .xlsx)', binary: true, contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', body: '<binary .xlsx file — Content-Disposition: attachment; filename="sales_invoice_{invoice_display_number}.xlsx">' },
      { code: 404, body: notFound('Sales invoice not found') },
    ],
  }),
], 'status_id is server-resolved. Every pre-existing sales invoice was backfilled to Draft when this field was introduced. Frontend should gate Approve/Reject on status_name === "Draft" and Revise on "Rejected".');

// ============================= SALES SPPB ======================================
const SPPB_ID = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';
const sppbListRow = {
  id: SPPB_ID, company_id: COMPANY_ID, sppb_display_number: 'SPPB-2026-0001', sales_order_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f',
  so_display_number: 'SO-2026-0001', sppb_date: '2026-07-01', customer_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', customer_name: 'PT Sumber Makmur',
  status_id: 'e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b', status_name: 'Draft', approved_by: null, approved_at: null,
  created_by: 'Budi Santoso', created_at: '2026-07-01 10:00:00', updated_by: null, updated_at: null, deleted_at: null,
};
const sppbItemRow = { id: 'e1f2a3b4-c5d6-4e5f-8a9b-0c1d2e3f4a5b', sales_sppb_id: SPPB_ID, send_to_address: 'Jl. Sudirman No. 1, Jakarta', send_date: '2026-07-02', product_name: 'Steel Rod 12mm', quantity: 100, uom_id: 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c', description: 'Handle with care', created_by: 'Budi Santoso', created_at: '2026-07-01 10:00:00', updated_by: null, updated_at: null, deleted_at: null };
const sppbFull = { ...sppbListRow, items: [sppbItemRow] };

const salesSppbFolder = folder('Sales SPPB (/sales-sppb)', [
  req({
    name: 'List Sales SPPBs', method: 'GET', path: 'sales-sppb',
    query: [
      { key: 'page', value: '1' }, { key: 'limit', value: '10', description: 'max 100' },
      { key: 'search', value: '', description: 'Search sppb_display_number', disabled: true },
      { key: 'status_id', value: '', disabled: true }, { key: 'customer_id', value: '', disabled: true },
      { key: 'sales_order_id', value: '', disabled: true }, { key: 'date_from', value: '', disabled: true }, { key: 'date_to', value: '', disabled: true },
    ],
    examples: [
      { code: 200, body: ok('Sales SPPBs found', paginated([sppbListRow])) },
      { code: 404, body: notFound('No sales SPPBs found') },
    ],
  }),
  req({
    name: 'Generate SPPB Number', method: 'GET', path: 'sales-sppb/generate-number',
    description: 'Format: {seq}/{company_code}-SPPB/{roman_month}/{year}.',
    examples: [
      { code: 200, body: ok('Sales SPPB number generated successfully', { sppb_display_number: '001/VIK-SPPB/VII/2026' }) },
      { code: 404, body: notFound('Company not found') },
    ],
  }),
  req({
    name: 'Create Sales SPPB', method: 'POST', path: 'sales-sppb',
    body: {
      sppb_display_number: 'SPPB-2026-0001', sales_order_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f', sppb_date: '2026-07-01',
      customer_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a',
      items: [{ send_to_address: 'Jl. Sudirman No. 1, Jakarta', send_date: '2026-07-02', product_name: 'Steel Rod 12mm', quantity: 100, uom_id: 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c', description: 'Handle with care' }],
    },
    description: 'Server sets status_id to Draft.',
    examples: [
      { code: 201, body: created('Sales SPPB created successfully', { sales_sppb_id: SPPB_ID }) },
      { code: 400, body: badReq('sppb_display_number is required') },
      { code: 400, name: '400 Bad Request (no items)', body: badReq('items must be a non-empty array') },
      { code: 400, name: '400 Bad Request (item field)', body: badReq('items.send_to_address is required') },
      { code: 404, body: notFound('Sales order not found') },
      { code: 409, body: conflict('Sales SPPB already exists') },
      { code: 500, body: badReq('Default sales status "Draft" is not configured') },
    ],
  }),
  req({
    name: 'Get Sales SPPB Detail', method: 'GET', path: 'sales-sppb/:id', pathVars: { id: SPPB_ID },
    examples: [
      { code: 200, body: ok('Sales SPPB found', sppbFull) },
      { code: 404, body: notFound('Sales SPPB not found') },
    ],
  }),
  req({
    name: 'Update Sales SPPB', method: 'PUT', path: 'sales-sppb/:id', pathVars: { id: SPPB_ID },
    body: { sppb_date: '2026-07-02' },
    description: 'If items is included, it FULLY REPLACES all existing items (soft-deletes old rows, inserts the new array) in one transaction. Omit items entirely to leave them untouched. status_id is not updatable here.',
    examples: [
      { code: 200, body: ok('Sales SPPB updated successfully', []) },
      { code: 400, body: badReq('No fields provided for update') },
      { code: 400, name: '400 Bad Request (items replace validation)', body: badReq('items must be a non-empty array') },
      { code: 404, body: notFound('Sales SPPB not found') },
    ],
  }),
  req({
    name: 'Delete Sales SPPB', method: 'DELETE', path: 'sales-sppb/:id', pathVars: { id: SPPB_ID },
    examples: [
      { code: 200, body: ok('Sales SPPB deleted successfully', []) },
      { code: 404, body: notFound('Sales SPPB not found') },
    ],
  }),
  req({
    name: 'Approve Sales SPPB', method: 'PATCH', path: 'sales-sppb/:id/approve', pathVars: { id: SPPB_ID },
    body: { notes: 'Approved for release' },
    examples: [
      { code: 200, body: ok('Sales SPPB approved successfully', []) },
      { code: 404, body: notFound('Sales SPPB not found') },
    ],
  }),
  req({
    name: 'Reject Sales SPPB', method: 'PATCH', path: 'sales-sppb/:id/reject', pathVars: { id: SPPB_ID },
    body: { notes: 'Quantity mismatch with SO' },
    examples: [
      { code: 200, body: ok('Sales SPPB rejected successfully', []) },
      { code: 404, body: notFound('Sales SPPB not found') },
    ],
  }),
  req({
    name: 'Revise Sales SPPB', method: 'PATCH', path: 'sales-sppb/:id/revise', pathVars: { id: SPPB_ID },
    body: { notes: 'Resubmitting with corrected quantity' },
    examples: [
      { code: 200, body: ok('Sales SPPB revised successfully', []) },
      { code: 400, body: badReq('Only rejected sales SPPBs can be revised') },
      { code: 404, body: notFound('Sales SPPB not found') },
    ],
  }),
  req({
    name: 'Export Sales SPPB (.xlsx)', method: 'GET', path: 'sales-sppb/:id/export', pathVars: { id: SPPB_ID },
    examples: [
      { code: 200, name: '200 OK (binary .xlsx)', binary: true, contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', body: '<binary .xlsx file — Content-Disposition: attachment; filename="sales_sppb_{sppb_display_number}.xlsx">' },
      { code: 404, body: notFound('Sales SPPB not found') },
    ],
  }),
], 'status_id is server-resolved. GET generate-number counts existing rows including soft-deleted ones, so the sequence never repeats within a month.');

// ============================= SALES PROFIT ====================================
const SP_ID = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';
const spListRow = {
  id: SP_ID, company_id: COMPANY_ID, sales_order_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f', so_display_number: 'SO-2026-0001',
  customer_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', customer_name: 'PT Sumber Makmur', status_id: 'e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b',
  status_name: 'Draft', approved_by: null, approved_at: null, created_by: 'Budi Santoso', created_at: '2026-07-01 10:00:00',
  updated_by: null, updated_at: null, deleted_at: null,
};
const spItemRow = { id: 'e1f2a3b4-c5d6-4e5f-8a9b-0c1d2e3f4a5b', sales_profit_id: SP_ID, purchase_order_id: 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c', product_name: 'Steel Rod 12mm', quantity: 100, price: 55000, landed_cost: 50000, created_by: 'Budi Santoso', created_at: '2026-07-01 10:00:00', updated_by: null, updated_at: null, deleted_at: null };
const spFull = { ...spListRow, items: [spItemRow] };

const salesProfitFolder = folder('Sales Profit (/sales-profit)', [
  req({
    name: 'List Sales Profits', method: 'GET', path: 'sales-profit',
    query: [
      { key: 'page', value: '1' }, { key: 'limit', value: '10', description: 'max 100' },
      { key: 'search', value: '', description: "Search linked sales order's so_display_number", disabled: true },
      { key: 'status_id', value: '', disabled: true }, { key: 'customer_id', value: '', disabled: true },
      { key: 'sales_order_id', value: '', disabled: true }, { key: 'date_from', value: '', disabled: true }, { key: 'date_to', value: '', disabled: true },
    ],
    examples: [
      { code: 200, body: ok('Sales profits found', paginated([spListRow])) },
      { code: 404, body: notFound('No sales profits found') },
    ],
  }),
  req({
    name: 'Create Sales Profit', method: 'POST', path: 'sales-profit',
    body: {
      sales_order_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f', customer_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a',
      items: [{ product_name: 'Steel Rod 12mm', quantity: 100, price: 55000, landed_cost: 50000, purchase_order_id: 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c' }],
    },
    description: 'Server sets status_id to Draft. price/landed_cost are stored as-is — no profit/margin field is computed in the JSON response.',
    examples: [
      { code: 201, body: created('Sales profit created successfully', { sales_profit_id: SP_ID }) },
      { code: 400, body: badReq('sales_order_id is required') },
      { code: 400, name: '400 Bad Request (no items)', body: badReq('items must be a non-empty array') },
      { code: 400, name: '400 Bad Request (item field)', body: badReq('items.price is required') },
      { code: 404, body: notFound('Sales order not found') },
      { code: 500, body: badReq('Default sales status "Draft" is not configured') },
    ],
  }),
  req({
    name: 'Get Sales Profit Detail', method: 'GET', path: 'sales-profit/:id', pathVars: { id: SP_ID },
    examples: [
      { code: 200, body: ok('Sales profit found', spFull) },
      { code: 404, body: notFound('Sales profit not found') },
    ],
  }),
  req({
    name: 'Update Sales Profit', method: 'PUT', path: 'sales-profit/:id', pathVars: { id: SP_ID },
    body: { items: [{ product_name: 'Steel Rod 12mm', quantity: 100, price: 56000, landed_cost: 50500 }] },
    description: 'When items is provided, it fully replaces the record\'s existing items (destructive replace, new IDs) in the same transaction as any header changes. status_id is not updatable here.',
    examples: [
      { code: 200, body: ok('Sales profit updated successfully', []) },
      { code: 400, body: badReq('No fields provided for update') },
      { code: 400, name: '400 Bad Request (items validation)', body: badReq('items.landed_cost is required') },
      { code: 404, body: notFound('Sales profit not found') },
      { code: 404, name: '404 Not Found (sales order)', body: notFound('Sales order not found') },
    ],
  }),
  req({
    name: 'Delete Sales Profit', method: 'DELETE', path: 'sales-profit/:id', pathVars: { id: SP_ID },
    examples: [
      { code: 200, body: ok('Sales profit deleted successfully', []) },
      { code: 404, body: notFound('Sales profit not found') },
    ],
  }),
  req({
    name: 'Approve Sales Profit', method: 'PATCH', path: 'sales-profit/:id/approve', pathVars: { id: SP_ID },
    body: { notes: 'Margins verified' },
    examples: [
      { code: 200, body: ok('Sales profit approved successfully', []) },
      { code: 404, body: notFound('Sales profit not found') },
    ],
  }),
  req({
    name: 'Reject Sales Profit', method: 'PATCH', path: 'sales-profit/:id/reject', pathVars: { id: SP_ID },
    body: { notes: 'Landed cost looks wrong' },
    examples: [
      { code: 200, body: ok('Sales profit rejected successfully', []) },
      { code: 404, body: notFound('Sales profit not found') },
    ],
  }),
  req({
    name: 'Revise Sales Profit', method: 'PATCH', path: 'sales-profit/:id/revise', pathVars: { id: SP_ID },
    body: { notes: 'Landed cost corrected' },
    examples: [
      { code: 200, body: ok('Sales profit revised successfully', []) },
      { code: 400, body: badReq('Only rejected sales profit records can be revised') },
      { code: 404, body: notFound('Sales profit not found') },
    ],
  }),
  req({
    name: 'Export Sales Profit (.xlsx)', method: 'GET', path: 'sales-profit/:id/export', pathVars: { id: SP_ID },
    description: 'PROFIT and Profit % columns computed server-side from price/landed_cost, for display only — not persisted.',
    examples: [
      { code: 200, name: '200 OK (binary .xlsx)', binary: true, contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', body: '<binary .xlsx file — Content-Disposition: attachment; filename="sales_profit_{so_display_number}.xlsx">' },
      { code: 404, body: notFound('Sales profit not found') },
    ],
  }),
], 'Unlike sales-sppb, this module has no unique display-number and no duplicate (409) check.');

export const salesFolder = folder('Sales', [salesOrderFolder, salesDeliveryFolder, salesInvoiceFolder, salesSppbFolder, salesProfitFolder]);
