import { req, folder, ok, created, badReq, notFound, conflict, paginated } from './pm-helpers.mjs';

const COMPANY_ID = 'b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e';

// ============================= PURCHASE ORDER ================================
const PO_ID = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';
const PO_ITEM_ID = 'e1f2a3b4-c5d6-4e5f-8a9b-0c1d2e3f4a5b';
const poListRow = {
  id: PO_ID, company_id: COMPANY_ID, po_display_number: 'PO-2026-0001', po_date: '2026-07-01',
  supplier_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f', supplier_name: 'PT Sumber Baja', shipment_method: 'FOB',
  shipment_period_id: 'shp_early_jul', period_name: 'Early July', term_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a',
  term_name: 'Net 30', payment_method_id: 'e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b', method_name: 'Bank Transfer',
  origin_id: 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c', origin_name: 'China', shipping_marks: 'N/A', remarks: 'Urgent order',
  status_id: 'a7b8c9d0-e1f2-4a5b-4c5d-6e7f8a9b0c1d', status_name: 'Approved', type_id: 'b8c9d0e1-f2a3-4b5c-5d6e-7f8a9b0c1d2e',
  type_name: 'Import', currency_id: 'c9d0e1f2-a3b4-4c5d-6e7f-8a9b0c1d2e3f', currency_code: 'USD', currency_name: 'US Dollar',
  ppn_type_id: 'd0e1f2a3-b4c5-4d5e-7f8a-9b0c1d2e3f4a', ppn_name: 'PPN 11%', container_number: 'CONT1234567',
  bl_number: 'BL-998877', vessel_name: 'MV Nusantara', etd_date: '2026-07-10', eta_date: '2026-07-20',
  approved_by: null, approved_at: null, created_by: 'Budi Santoso', created_at: '2026-07-01 10:00:00',
  updated_by: null, updated_at: null, deleted_at: null,
};
const poItemRow = { id: PO_ITEM_ID, purchase_order_id: PO_ID, product_name: 'Steel Rod 12mm', quantity: 100, packaging_size: 10, unit_price: 50000, vat: 5000, total: 5005000, created_by: 'Budi Santoso', created_at: '2026-07-01 10:00:00', updated_by: null, updated_at: null, deleted_at: null };
const poFull = { ...poListRow, items: [poItemRow] };

const purchaseOrderRoot = [
  req({
    name: 'Generate PO Number', method: 'GET', path: 'purchase-order/generate-number',
    query: [{ key: 'type_id', value: 'b8c9d0e1-f2a3-4b5c-5d6e-7f8a9b0c1d2e', description: 'purchase_type ID to generate the number for' }],
    description: 'Read-only preview — does not reserve/persist the number. Format is read from purchase_type.number_format/sequence_digits (not hardcoded). Call right before POST.',
    examples: [
      { code: 200, body: ok('Purchase order number generated successfully', { po_display_number: 'VKN/26/VII/0030' }) },
      { code: 400, body: badReq('type_id is required') },
      { code: 404, body: notFound('Purchase type not found') },
      { code: 500, body: badReq('Purchase type "New Type" has no number_format configured') },
    ],
  }),
  req({
    name: 'Get Default Shipping Marks', method: 'GET', path: 'purchase-order/settings/shipping-marks-default',
    description: 'Per-company setting — the prefill text for the free-text shipping_marks field on POST /purchase-order. Not a purchase-order field.',
    examples: [{ code: 200, body: ok('Shipping marks default retrieved', { shipping_marks_default: 'VIK - PO - No....\nInv. No....DD......\nJakarta' }) }],
  }),
  req({
    name: 'Set Default Shipping Marks', method: 'PUT', path: 'purchase-order/settings/shipping-marks-default',
    body: { shipping_marks_default: 'VIK - PO - No....\nInv. No....DD......\nJakarta' },
    description: 'Upsert, not a partial update — shipping_marks_default must always be present (may be empty string to clear).',
    examples: [
      { code: 200, body: ok('Shipping marks default updated successfully', { shipping_marks_default: 'VIK - PO - No....\nInv. No....DD......\nJakarta' }) },
      { code: 400, body: badReq('shipping_marks_default is required') },
    ],
  }),
  req({
    name: 'List Purchase Orders', method: 'GET', path: 'purchase-order',
    query: [
      { key: 'page', value: '1' }, { key: 'limit', value: '10', description: 'max 100' },
      { key: 'search', value: '', description: 'Search po_display_number', disabled: true },
      { key: 'status_id', value: '', disabled: true }, { key: 'supplier_id', value: '', disabled: true },
      { key: 'type_id', value: '', disabled: true }, { key: 'date_from', value: '', disabled: true }, { key: 'date_to', value: '', disabled: true },
    ],
    description: 'List does not include the nested items[] array — only the detail endpoint does.',
    examples: [
      { code: 200, body: ok('Purchase orders found', paginated([poListRow])) },
      { code: 404, body: notFound('No purchase orders found') },
    ],
  }),
  req({
    name: 'Create Purchase Order', method: 'POST', path: 'purchase-order',
    body: {
      po_display_number: 'PO-2026-0001', po_date: '2026-07-01', supplier_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f',
      shipment_method: 'FOB', shipment_period_id: 'shp_early_jul', term_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a',
      payment_method_id: 'e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b', origin_id: 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c',
      shipping_marks: 'N/A', remarks: 'Urgent order', type_id: 'b8c9d0e1-f2a3-4b5c-5d6e-7f8a9b0c1d2e',
      currency_id: 'c9d0e1f2-a3b4-4c5d-6e7f-8a9b0c1d2e3f', ppn_type_id: 'd0e1f2a3-b4c5-4d5e-7f8a-9b0c1d2e3f4a',
      container_number: 'CONT1234567', bl_number: 'BL-998877', vessel_name: 'MV Nusantara', etd_date: '2026-07-10', eta_date: '2026-07-20',
      items: [{ product_name: 'Steel Rod 12mm', quantity: 100, packaging_size: 10, unit_price: 50000, vat: 5000 }],
    },
    description: 'Server-side sets status_id to Draft. Header + items creation is one DB transaction. Triggers notifications to notification.purchase_order.approver holders.',
    examples: [
      { code: 201, body: created('Purchase order created successfully', { purchase_order_id: PO_ID }) },
      { code: 400, body: badReq('po_display_number is required') },
      { code: 400, name: '400 Bad Request (no items)', body: badReq('items must be a non-empty array') },
      { code: 400, name: '400 Bad Request (item field)', body: badReq('items.product_name is required') },
      { code: 400, name: '400 Bad Request (bad Incoterm)', body: badReq('shipment_method must be one of FOB, CIF, EXW, CFR, CIP, DAP, DDP, FCA') },
      { code: 409, body: conflict('Purchase order already exists') },
    ],
  }),
  req({
    name: 'Get Purchase Order Detail', method: 'GET', path: 'purchase-order/:id', pathVars: { id: PO_ID },
    examples: [
      { code: 200, body: ok('Purchase order found', poFull) },
      { code: 404, body: notFound('Purchase order not found') },
    ],
  }),
  req({
    name: 'Update Purchase Order', method: 'PUT', path: 'purchase-order/:id', pathVars: { id: PO_ID },
    body: { remarks: 'Urgent order — expedite customs clearance', vessel_name: 'MV Nusantara Jaya' },
    description: 'Does not update items — use the items sub-resource. status_id cannot be changed here.',
    examples: [
      { code: 200, body: ok('Purchase order updated successfully', []) },
      { code: 400, body: badReq('No fields provided for update') },
      { code: 404, body: notFound('Purchase order not found') },
    ],
  }),
  req({
    name: 'Delete Purchase Order', method: 'DELETE', path: 'purchase-order/:id', pathVars: { id: PO_ID },
    examples: [
      { code: 200, body: ok('Purchase order deleted successfully', []) },
      { code: 404, body: notFound('Purchase order not found') },
    ],
  }),
  req({
    name: 'Approve Purchase Order', method: 'PATCH', path: 'purchase-order/:id/approve', pathVars: { id: PO_ID },
    body: { notes: 'Approved for procurement' },
    description: 'Server-side sets status_id to Approved plus approved_by/approved_at. Notifies the order\'s creator.',
    examples: [
      { code: 200, body: ok('Purchase order approved successfully', []) },
      { code: 404, body: notFound('Purchase order not found') },
    ],
  }),
  req({
    name: 'Reject Purchase Order', method: 'PATCH', path: 'purchase-order/:id/reject', pathVars: { id: PO_ID },
    body: { notes: 'Price exceeds budget' },
    examples: [
      { code: 200, body: ok('Purchase order rejected successfully', []) },
      { code: 404, body: notFound('Purchase order not found') },
    ],
  }),
  req({
    name: 'Revise Purchase Order', method: 'PATCH', path: 'purchase-order/:id/revise', pathVars: { id: PO_ID },
    body: { notes: 'Resubmitting with corrected pricing' },
    description: 'Moves a Rejected PO back to Draft. Only allowed when current status is Rejected.',
    examples: [
      { code: 200, body: ok('Purchase order revised successfully', []) },
      { code: 400, body: badReq('Only rejected purchase orders can be revised') },
      { code: 404, body: notFound('Purchase order not found') },
    ],
  }),
  req({
    name: 'Export Purchase Order (.docx)', method: 'GET', path: 'purchase-order/:id/export', pathVars: { id: PO_ID },
    description: 'Template chosen by type_name (case-insensitive "import"/"local" substring match). Fixed 5-row item table.',
    examples: [
      { code: 200, name: '200 OK (binary .docx)', binary: true, contentType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', body: '<binary .docx file — Content-Disposition: attachment; filename="POImport-{po_display_number}.docx">' },
      { code: 400, body: badReq('Export template is not configured for purchase type "New Type"') },
      { code: 404, body: notFound('Purchase order not found') },
    ],
  }),
];

const purchaseOrderItems = folder('Purchase Order Items (sub-resource)', [
  req({
    name: 'List PO Items', method: 'GET', path: 'purchase-order/:id/items', pathVars: { id: PO_ID },
    examples: [
      { code: 200, body: ok('Purchase order items found', { data: [poItemRow] }) },
      { code: 404, body: notFound('No purchase order items found') },
      { code: 404, name: '404 Not Found (parent missing)', body: notFound('Purchase order not found') },
    ],
  }),
  req({
    name: 'Add PO Item', method: 'POST', path: 'purchase-order/:id/items', pathVars: { id: PO_ID },
    body: { product_name: 'Steel Rod 12mm', quantity: 100, packaging_size: 10, unit_price: 50000, vat: 5000 },
    examples: [
      { code: 201, body: created('Purchase order item created successfully', { purchase_order_item_id: PO_ITEM_ID }) },
      { code: 400, body: badReq('product_name is required') },
    ],
  }),
  req({
    name: 'Get PO Item', method: 'GET', path: 'purchase-order/:id/items/:item_id', pathVars: { id: PO_ID, item_id: PO_ITEM_ID },
    examples: [
      { code: 200, body: ok('Purchase order item found', poItemRow) },
      { code: 404, body: notFound('Purchase order item not found') },
    ],
  }),
  req({
    name: 'Update PO Item', method: 'PUT', path: 'purchase-order/:id/items/:item_id', pathVars: { id: PO_ID, item_id: PO_ITEM_ID },
    body: { quantity: 120, unit_price: 49000 },
    examples: [
      { code: 200, body: ok('Purchase order item updated successfully', []) },
      { code: 400, body: badReq('No fields provided for update') },
      { code: 404, body: notFound('Purchase order item not found') },
    ],
  }),
  req({
    name: 'Delete PO Item', method: 'DELETE', path: 'purchase-order/:id/items/:item_id', pathVars: { id: PO_ID, item_id: PO_ITEM_ID },
    examples: [
      { code: 200, body: ok('Purchase order item deleted successfully', []) },
      { code: 404, body: notFound('Purchase order item not found') },
    ],
  }),
], 'Items are also addressable as their own sub-resource, separate from the nested items[] on the PO detail response.');

const purchaseOrderFolder = folder('Purchase Order (/purchase-order)', [...purchaseOrderRoot, purchaseOrderItems],
  'status_id is server-resolved (Draft/Approved/Rejected via purchase_status.status_name) — never client-supplied. shipment_method must be one of FOB, CIF, EXW, CFR, CIP, DAP, DDP, FCA.');

// ============================= PURCHASE INVOICE ==============================
const PI_ID = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';
const PI_ITEM_ID = 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c';
const piListRow = {
  id: PI_ID, company_id: COMPANY_ID, invoice_display_number: 'INV-2026-0001', purchase_order_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f',
  po_display_number: 'PO-2026-0001', supplier_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', supplier_name: 'PT Sumber Baja',
  invoice_date: '2026-07-05', ship_date: '2026-07-01', tax_invoice_number: 'PPN-998877', kurs: 15500,
  term_id: 'e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b', term_name: 'Net 30', status_id: 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c',
  status_name: 'Draft', approved_by: null, approved_at: null, created_by: 'Budi Santoso', created_at: '2026-07-05 09:00:00',
  updated_by: null, updated_at: null, deleted_at: null,
};
const piItemRow = { id: PI_ITEM_ID, purchase_invoice_id: PI_ID, product_name: 'Steel Rod 12mm', quantity: 100, packaging_size: 10, unit_price: 50000, vat: 5000, total: 5005000, created_by: 'Budi Santoso', created_at: '2026-07-05 09:00:00', updated_by: null, updated_at: null, deleted_at: null };
const piFull = { ...piListRow, items: [piItemRow] };

const purchaseInvoiceRoot = [
  req({
    name: 'List Purchase Invoices', method: 'GET', path: 'purchase-invoice',
    query: [
      { key: 'page', value: '1' }, { key: 'limit', value: '10', description: 'max 100' },
      { key: 'search', value: '', description: 'Search invoice_display_number', disabled: true },
      { key: 'supplier_id', value: '', disabled: true }, { key: 'status_id', value: '', disabled: true },
      { key: 'date_from', value: '', disabled: true }, { key: 'date_to', value: '', disabled: true },
    ],
    examples: [
      { code: 200, body: ok('Purchase invoices found', paginated([piListRow])) },
      { code: 404, body: notFound('No purchase invoices found') },
    ],
  }),
  req({
    name: 'Create Purchase Invoice', method: 'POST', path: 'purchase-invoice',
    body: {
      invoice_display_number: 'INV-2026-0001', purchase_order_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f',
      supplier_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', invoice_date: '2026-07-05', ship_date: '2026-07-01',
      tax_invoice_number: 'PPN-998877', kurs: 15500, term_id: 'e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b',
      items: [{ product_name: 'Steel Rod 12mm', quantity: 100, packaging_size: 10, unit_price: 50000, vat: 5000 }],
    },
    description: 'purchase_order_id must exist for the company; invoice_display_number must be unique within the company. Server sets status_id to Draft.',
    examples: [
      { code: 201, body: created('Purchase invoice created successfully', { purchase_invoice_id: PI_ID }) },
      { code: 400, body: badReq('invoice_display_number is required') },
      { code: 400, name: '400 Bad Request (no items)', body: badReq('items must be a non-empty array') },
      { code: 400, name: '400 Bad Request (item field)', body: badReq('items.product_name is required') },
      { code: 404, body: notFound('Purchase order not found') },
      { code: 409, body: conflict('Purchase invoice already exists') },
    ],
  }),
  req({
    name: 'Get Purchase Invoice Detail', method: 'GET', path: 'purchase-invoice/:id', pathVars: { id: PI_ID },
    examples: [
      { code: 200, body: ok('Purchase invoice found', piFull) },
      { code: 404, body: notFound('Purchase invoice not found') },
    ],
  }),
  req({
    name: 'Update Purchase Invoice', method: 'PUT', path: 'purchase-invoice/:id', pathVars: { id: PI_ID },
    body: { tax_invoice_number: 'PPN-998877-A', kurs: 15600 },
    description: 'Does not update purchase_order_id, items, or status_id.',
    examples: [
      { code: 200, body: ok('Purchase invoice updated successfully', []) },
      { code: 400, body: badReq('No fields provided for update') },
      { code: 400, name: '400 Bad Request (empty FK)', body: badReq('supplier_id cannot be empty') },
      { code: 404, body: notFound('Purchase invoice not found') },
    ],
  }),
  req({
    name: 'Delete Purchase Invoice', method: 'DELETE', path: 'purchase-invoice/:id', pathVars: { id: PI_ID },
    examples: [
      { code: 200, body: ok('Purchase invoice deleted successfully', []) },
      { code: 404, body: notFound('Purchase invoice not found') },
    ],
  }),
  req({
    name: 'Approve Purchase Invoice', method: 'PATCH', path: 'purchase-invoice/:id/approve', pathVars: { id: PI_ID },
    body: { notes: 'Matches PO and receiving report' },
    description: 'Only allowed when status is Draft. Also seeds a baseline finance_payment row (due_amount = sum of item totals, paid_amount 0) if one doesn\'t already exist.',
    examples: [
      { code: 200, body: ok('Purchase invoice approved successfully', []) },
      { code: 400, body: badReq('Only draft purchase invoices can be approved') },
      { code: 404, body: notFound('Purchase invoice not found') },
    ],
  }),
  req({
    name: 'Reject Purchase Invoice', method: 'PATCH', path: 'purchase-invoice/:id/reject', pathVars: { id: PI_ID },
    body: { notes: 'Quantity does not match receiving report' },
    description: 'Only allowed when status is Draft — an Approved invoice (may have payments recorded) can no longer be rejected.',
    examples: [
      { code: 200, body: ok('Purchase invoice rejected successfully', []) },
      { code: 400, body: badReq('Only draft purchase invoices can be rejected') },
      { code: 404, body: notFound('Purchase invoice not found') },
    ],
  }),
  req({
    name: 'Revise Purchase Invoice', method: 'PATCH', path: 'purchase-invoice/:id/revise', pathVars: { id: PI_ID },
    body: { notes: 'Corrected quantities per receiving report' },
    examples: [
      { code: 200, body: ok('Purchase invoice revised successfully', []) },
      { code: 400, body: badReq('Only rejected purchase invoices can be revised') },
      { code: 404, body: notFound('Purchase invoice not found') },
    ],
  }),
  req({
    name: 'Export Purchase Invoice (.docx)', method: 'GET', path: 'purchase-invoice/:id/export', pathVars: { id: PI_ID },
    examples: [
      { code: 200, name: '200 OK (binary .docx)', binary: true, contentType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', body: '<binary .docx file — Content-Disposition: attachment; filename="PurchaseInvoice-{invoice_display_number}.docx">' },
      { code: 404, body: notFound('Purchase invoice not found') },
    ],
  }),
];
const purchaseInvoiceItems = folder('Purchase Invoice Items (sub-resource)', [
  req({
    name: 'List PI Items', method: 'GET', path: 'purchase-invoice/:id/items', pathVars: { id: PI_ID },
    examples: [
      { code: 200, body: ok('Purchase invoice items found', { data: [piItemRow] }) },
      { code: 404, body: notFound('No purchase invoice items found') },
      { code: 404, name: '404 Not Found (parent missing)', body: notFound('Purchase invoice not found') },
    ],
  }),
  req({
    name: 'Get PI Item', method: 'GET', path: 'purchase-invoice/:id/items/:item_id', pathVars: { id: PI_ID, item_id: PI_ITEM_ID },
    examples: [
      { code: 200, body: ok('Purchase invoice item found', piItemRow) },
      { code: 404, body: notFound('Purchase invoice item not found') },
    ],
  }),
  req({
    name: 'Update PI Item', method: 'PUT', path: 'purchase-invoice/:id/items/:item_id', pathVars: { id: PI_ID, item_id: PI_ITEM_ID },
    body: { quantity: 95, unit_price: 51000 },
    description: 'This is how quantity/unit_price get corrected on a rejected invoice before resubmitting via revise. No POST/DELETE — invoice items can only be updated in place.',
    examples: [
      { code: 200, body: ok('Purchase invoice item updated successfully', []) },
      { code: 400, body: badReq('No fields provided for update') },
      { code: 404, body: notFound('Purchase invoice item not found') },
    ],
  }),
], 'Unlike purchase-order items, invoice items cannot be added or deleted here — only updated — since they are always created together with the parent record.');

const purchaseInvoiceFolder = folder('Purchase Invoice (/purchase-invoice)', [...purchaseInvoiceRoot, purchaseInvoiceItems]);

// ============================= PURCHASE RECEIVE ==============================
const PR_ID = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';
const PR_ITEM_ID = 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c';
const prListRow = {
  id: PR_ID, company_id: COMPANY_ID, purchase_order_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f', po_display_number: 'PO-2026-0001',
  supplier_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', supplier_name: 'PT Sumber Baja', receiving_date: '2026-07-05',
  ship_date: '2026-07-01', ship_via_id: 'e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b', ship_name: 'Sea Freight',
  status_id: 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c', status_name: 'Draft', approved_by: null, approved_at: null,
  created_by: 'Budi Santoso', created_at: '2026-07-05 09:00:00', updated_by: null, updated_at: null, deleted_at: null,
};
const prItemRow = { id: PR_ITEM_ID, purchase_receive_id: PR_ID, product_name: 'Steel Rod 12mm', quantity: 100, packaging_size: 10, unit_price: 50000, vat: 5000, total: 5005000, created_by: 'Budi Santoso', created_at: '2026-07-05 09:00:00', updated_by: null, updated_at: null, deleted_at: null };
const prFull = { ...prListRow, items: [prItemRow] };

const purchaseReceiveRoot = [
  req({
    name: 'List Purchase Receives', method: 'GET', path: 'purchase-receive',
    query: [
      { key: 'page', value: '1' }, { key: 'limit', value: '10', description: 'max 100' },
      { key: 'search', value: '', description: 'Search linked PO\'s po_display_number', disabled: true },
      { key: 'purchase_order_id', value: '', disabled: true }, { key: 'supplier_id', value: '', disabled: true },
      { key: 'status_id', value: '', disabled: true }, { key: 'date_from', value: '', disabled: true }, { key: 'date_to', value: '', disabled: true },
    ],
    examples: [
      { code: 200, body: ok('Purchase receives found', paginated([prListRow])) },
      { code: 404, body: notFound('No purchase receives found') },
    ],
  }),
  req({
    name: 'Create Purchase Receive', method: 'POST', path: 'purchase-receive',
    body: {
      purchase_order_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f', supplier_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a',
      receiving_date: '2026-07-05', ship_date: '2026-07-01', ship_via_id: 'e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b',
      items: [{ product_name: 'Steel Rod 12mm', quantity: 100, packaging_size: 10, unit_price: 50000, vat: 5000 }],
    },
    description: 'Requires the referenced purchase_order to exist for the company. Server sets status_id to Draft.',
    examples: [
      { code: 201, body: created('Purchase receive created successfully', { purchase_receive_id: PR_ID }) },
      { code: 400, body: badReq('purchase_order_id is required') },
      { code: 400, name: '400 Bad Request (no items)', body: badReq('items must be a non-empty array') },
      { code: 400, name: '400 Bad Request (item field)', body: badReq('items.product_name is required') },
      { code: 404, body: notFound('Purchase order not found') },
    ],
  }),
  req({
    name: 'Get Purchase Receive Detail', method: 'GET', path: 'purchase-receive/:id', pathVars: { id: PR_ID },
    examples: [
      { code: 200, body: ok('Purchase receive found', prFull) },
      { code: 404, body: notFound('Purchase receive not found') },
    ],
  }),
  req({
    name: 'Update Purchase Receive', method: 'PUT', path: 'purchase-receive/:id', pathVars: { id: PR_ID },
    body: { receiving_date: '2026-07-06', ship_via_id: 'e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b' },
    description: 'Does not update purchase_order_id, items, or status_id.',
    examples: [
      { code: 200, body: ok('Purchase receive updated successfully', []) },
      { code: 400, body: badReq('No fields provided for update') },
      { code: 400, name: '400 Bad Request (empty FK)', body: badReq('supplier_id cannot be empty') },
      { code: 404, body: notFound('Purchase receive not found') },
    ],
  }),
  req({
    name: 'Delete Purchase Receive', method: 'DELETE', path: 'purchase-receive/:id', pathVars: { id: PR_ID },
    examples: [
      { code: 200, body: ok('Purchase receive deleted successfully', []) },
      { code: 404, body: notFound('Purchase receive not found') },
    ],
  }),
  req({
    name: 'Approve Purchase Receive', method: 'PATCH', path: 'purchase-receive/:id/approve', pathVars: { id: PR_ID },
    body: { notes: 'Goods received in full' },
    examples: [
      { code: 200, body: ok('Purchase receive approved successfully', []) },
      { code: 404, body: notFound('Purchase receive not found') },
    ],
  }),
  req({
    name: 'Reject Purchase Receive', method: 'PATCH', path: 'purchase-receive/:id/reject', pathVars: { id: PR_ID },
    body: { notes: 'Damaged goods on arrival' },
    examples: [
      { code: 200, body: ok('Purchase receive rejected successfully', []) },
      { code: 404, body: notFound('Purchase receive not found') },
    ],
  }),
  req({
    name: 'Revise Purchase Receive', method: 'PATCH', path: 'purchase-receive/:id/revise', pathVars: { id: PR_ID },
    body: { notes: 'Resubmitting after quantity correction' },
    examples: [
      { code: 200, body: ok('Purchase receive revised successfully', []) },
      { code: 400, body: badReq('Only rejected purchase receives can be revised') },
      { code: 404, body: notFound('Purchase receive not found') },
    ],
  }),
  req({
    name: 'Export Purchase Receive (.docx)', method: 'GET', path: 'purchase-receive/:id/export', pathVars: { id: PR_ID },
    examples: [
      { code: 200, name: '200 OK (binary .docx)', binary: true, contentType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', body: '<binary .docx file — Content-Disposition: attachment; filename="PurchaseReceive-{po_display_number}-{receiving_date}.docx">' },
      { code: 404, body: notFound('Purchase receive not found') },
    ],
  }),
];
const purchaseReceiveItems = folder('Purchase Receive Items (sub-resource)', [
  req({
    name: 'List PR Items', method: 'GET', path: 'purchase-receive/:id/items', pathVars: { id: PR_ID },
    examples: [
      { code: 200, body: ok('Purchase receive items found', { data: [prItemRow] }) },
      { code: 404, body: notFound('No purchase receive items found') },
      { code: 404, name: '404 Not Found (parent missing)', body: notFound('Purchase receive not found') },
    ],
  }),
  req({
    name: 'Get PR Item', method: 'GET', path: 'purchase-receive/:id/items/:item_id', pathVars: { id: PR_ID, item_id: PR_ITEM_ID },
    examples: [
      { code: 200, body: ok('Purchase receive item found', prItemRow) },
      { code: 404, body: notFound('Purchase receive item not found') },
    ],
  }),
  req({
    name: 'Update PR Item', method: 'PUT', path: 'purchase-receive/:id/items/:item_id', pathVars: { id: PR_ID, item_id: PR_ITEM_ID },
    body: { quantity: 98 },
    description: 'This is how quantity/unit_price get corrected on a rejected receive before resubmitting via revise. No POST/DELETE.',
    examples: [
      { code: 200, body: ok('Purchase receive item updated successfully', []) },
      { code: 400, body: badReq('No fields provided for update') },
      { code: 404, body: notFound('Purchase receive item not found') },
    ],
  }),
], 'Unlike purchase-order items, receive items cannot be added or deleted here — only updated.');

const purchaseReceiveFolder = folder('Purchase Receive (/purchase-receive)', [...purchaseReceiveRoot, purchaseReceiveItems]);

export const purchaseFolder = folder('Purchase', [purchaseOrderFolder, purchaseInvoiceFolder, purchaseReceiveFolder]);
