import { crudModule, folder, req, ok, created, badReq, notFound, paginated } from './pm-helpers.mjs';

const ID = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';
const COMPANY_ID = 'b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e';
const BY = { created_by: 'Budi Santoso', created_at: '2026-07-01 10:00:00', updated_by: null, updated_at: null, deleted_at: null };

// ---- Warehouse Lot ----------------------------------------------------
const warehouseLotFolder = crudModule({
  path: 'warehouse-lot', displayName: 'Warehouse Lot', idParam: 'id', idExample: ID,
  searchParam: null,
  extraListQuery: [
    { key: 'product_id', value: '', description: 'Filter by product_id' },
    { key: 'location_id', value: '', description: 'Filter by location_id' },
  ],
  listRow: { id: ID, company_id: COMPANY_ID, location_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f', product_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', lot_date: '2026-07-01', beginning_balance: 100, end_balance: 80, ...BY },
  createBody: { product_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', lot_date: '2026-07-01', beginning_balance: 100, end_balance: 80, location_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f' },
  createdField: 'warehouse_lot_id', createdIdExample: ID,
  extraCreate404: ['Product not found', 'Warehouse location not found'],
  messages: {
    listFound: 'Warehouse lots found', listEmpty: 'No warehouse lots found', itemFound: 'Warehouse lot found',
    itemNotFound: 'Warehouse lot not found', created: 'Warehouse lot created successfully',
    requiredMessage: 'product_id is required', duplicate: null,
    updated: 'Warehouse lot updated successfully', noFields: 'No fields provided for update',
    deleted: 'Warehouse lot deleted successfully',
  },
  notes: 'Company-scoped. No audit_log entries written by this module. On update, location_id is only re-checked for emptiness, not re-validated for existence.',
});

// ---- Warehouse Transaction (has items[]) -----------------------------
const WT_ID = ID;
const WT_ITEM_ROW = { id: 'e1f2a3b4-c5d6-4e5f-8a9b-0c1d2e3f4a5b', warehouse_transaction_id: WT_ID, warehouse_lot_id: 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c', product_id: 'c9d0e1f2-a3b4-4c5d-6e7f-8a9b0c1d2e3f', quantity: 100, uom_id: null, conversion_factor: null, expired_at: null, notes: null, ...BY };
const WT_ROW = { id: WT_ID, company_id: COMPANY_ID, transaction_date: '2026-07-01', transaction_type: 'stock_in', customer_id: null, notes: null, ...BY };
const warehouseTransactionFolder = folder('Warehouse Transaction (/warehouse-transaction)', [
  req({
    name: 'List Warehouse Transactions', method: 'GET', path: 'warehouse-transaction',
    query: [
      { key: 'page', value: '1' }, { key: 'limit', value: '10', description: 'max 100' },
      { key: 'transaction_type', value: 'stock_in', description: 'stock_in | stock_out | adjustment | transfer', disabled: true },
      { key: 'customer_id', value: '', disabled: true },
    ],
    description: 'List all warehouse transactions belonging to the authenticated company. List does not include the nested items array.',
    examples: [
      { code: 200, body: ok('Warehouse transactions found', paginated([WT_ROW])) },
      { code: 404, body: notFound('No warehouse transactions found') },
    ],
  }),
  req({
    name: 'Create Warehouse Transaction', method: 'POST', path: 'warehouse-transaction',
    body: {
      transaction_date: '2026-07-01', transaction_type: 'stock_in', customer_id: null, notes: null,
      items: [{ warehouse_lot_id: 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c', product_id: 'c9d0e1f2-a3b4-4c5d-6e7f-8a9b0c1d2e3f', quantity: 100, uom_id: null, conversion_factor: null, expired_at: null, notes: null }],
    },
    description: 'Create a warehouse transaction with its items. Keeps warehouse_lot.end_balance in sync as a side effect (stock_in adds, stock_out subtracts, adjustment/transfer apply quantity as given). May trigger a low_stock_alert notification if a reorder-point is breached.',
    examples: [
      { code: 201, body: created('Warehouse transaction created successfully', { warehouse_transaction_id: WT_ID }) },
      { code: 400, body: badReq('transaction_date is required') },
      { code: 400, name: '400 Bad Request (bad type)', body: badReq('transaction_type must be one of stock_in, stock_out, adjustment, transfer') },
      { code: 400, name: '400 Bad Request (no items)', body: badReq('items must be a non-empty array') },
      { code: 400, name: '400 Bad Request (item field)', body: badReq('items.warehouse_lot_id is required') },
      { code: 404, body: notFound('Customer not found') },
      { code: 500, body: { status_code: 500, status_message: 'Failed to create warehouse transaction', data: { error: '...' } } },
    ],
  }),
  req({
    name: 'Get Warehouse Transaction Detail', method: 'GET', path: 'warehouse-transaction/:id', pathVars: { id: WT_ID },
    description: 'Get detail of a single warehouse transaction, including its nested items array.',
    examples: [
      { code: 200, body: ok('Warehouse transaction found', { ...WT_ROW, items: [WT_ITEM_ROW] }) },
      { code: 404, body: notFound('Warehouse transaction not found') },
    ],
  }),
  req({
    name: 'Update Warehouse Transaction', method: 'PUT', path: 'warehouse-transaction/:id', pathVars: { id: WT_ID },
    body: { transaction_date: '2026-07-02', transaction_type: 'stock_in', customer_id: null, notes: 'Corrected date' },
    description: 'Update a warehouse transaction. Does not update items and does not adjust warehouse_lot balances.',
    examples: [
      { code: 200, body: ok('Warehouse transaction updated successfully', []) },
      { code: 400, body: badReq('No fields provided for update') },
      { code: 400, name: '400 Bad Request (bad type)', body: badReq('transaction_type must be one of stock_in, stock_out, adjustment, transfer') },
      { code: 404, body: notFound('Warehouse transaction not found') },
    ],
  }),
  req({
    name: 'Delete Warehouse Transaction', method: 'DELETE', path: 'warehouse-transaction/:id', pathVars: { id: WT_ID },
    description: 'Soft-delete. Does not reverse or adjust any lot balance — only POST touches balances.',
    examples: [
      { code: 200, body: ok('Warehouse transaction deleted successfully', []) },
      { code: 404, body: notFound('Warehouse transaction not found') },
    ],
  }),
], 'Company-scoped. No audit_log entries; no duplicate check (no 409).');

// ---- Reorder Point ------------------------------------------------------
const reorderPointFolder = crudModule({
  path: 'reorder-point', displayName: 'Reorder Point', idParam: 'id', idExample: ID,
  searchParam: null,
  extraListQuery: [
    { key: 'product_id', value: '', description: 'Filter by product_id' },
    { key: 'location_id', value: '', description: 'Filter by location_id' },
  ],
  listRow: { id: ID, company_id: COMPANY_ID, product_id: 'c9d0e1f2-a3b4-4c5d-6e7f-8a9b0c1d2e3f', location_id: 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c', min_stock: 100, alerted_at: null, ...BY },
  detailRow: { id: ID, company_id: COMPANY_ID, product_id: 'c9d0e1f2-a3b4-4c5d-6e7f-8a9b0c1d2e3f', location_id: 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c', min_stock: 100, alerted_at: '2026-08-07 10:05:00', ...BY },
  createBody: { product_id: 'c9d0e1f2-a3b4-4c5d-6e7f-8a9b0c1d2e3f', location_id: 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c', min_stock: 100 },
  updateBody: { min_stock: 150 },
  createdField: 'reorder_point_id', createdIdExample: ID,
  extraCreate404: ['Product not found', 'Warehouse location not found'],
  extraCreate409: 'Reorder point already exists for this product and location',
  messages: {
    listFound: 'Reorder points found', listEmpty: 'No reorder points found', itemFound: 'Reorder point found',
    itemNotFound: 'Reorder point not found', created: 'Reorder point created successfully',
    requiredMessage: 'product_id is required', duplicate: null,
    updated: 'Reorder point updated successfully', noFields: 'No fields provided for update',
    deleted: 'Reorder point deleted successfully',
  },
  notes: 'Company-scoped. Only min_stock can be changed via PUT — product_id/location_id are fixed at creation. Read by POST /warehouse-transaction after each transaction commits to fire low_stock_alert notifications.',
});

export const warehouseFolder = folder('Warehouse', [warehouseLotFolder, warehouseTransactionFolder, reorderPointFolder]);

// ============================= HR ===========================================
const salaryTransactionFolder = crudModule({
  path: 'salary-transaction', displayName: 'Salary Transaction', idParam: 'id', idExample: ID,
  searchParam: null,
  extraListQuery: [
    { key: 'app_user_id', value: '', description: 'Filter by app_user_id' },
    { key: 'salary_category_id', value: '', description: 'Filter by salary_category_id' },
  ],
  listRow: { id: ID, company_id: COMPANY_ID, app_user_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f', salary_category_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', salary_amount: 5000000, ...BY },
  createBody: { app_user_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f', salary_category_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', salary_amount: 5000000 },
  updateBody: { salary_category_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', salary_amount: 5500000 },
  createdField: 'salary_transaction_id', createdIdExample: ID,
  extraCreate404: ['Salary category not found'],
  extraUpdate400: ['salary_category_id cannot be empty'],
  extraUpdate404: ['Salary category not found'],
  messages: {
    listFound: 'Salary transactions found', listEmpty: 'No salary transactions found', itemFound: 'Salary transaction found',
    itemNotFound: 'Salary transaction not found', created: 'Salary transaction created successfully',
    requiredMessage: 'app_user_id is required', duplicate: null,
    updated: 'Salary transaction updated successfully', noFields: 'No fields provided for update',
    deleted: 'Salary transaction deleted successfully',
  },
  notes: 'Company-scoped. No audit_log entries; no duplicate check; create is a single INSERT, not wrapped in a DB transaction.',
});
// Add the documented 500 example to create response set
salaryTransactionFolder.item[1].response.push({
  name: '500 Internal Server Error', originalRequest: salaryTransactionFolder.item[1].response[0].originalRequest,
  status: 'Internal Server Error', code: 500, _postman_previewlanguage: 'json',
  header: [{ key: 'Content-Type', value: 'application/json' }], cookie: [],
  body: JSON.stringify({ status_code: 500, status_message: 'Failed to create salary transaction', data: { error: '...' } }, null, 2),
});

export const hrFolder = folder('HR', [salaryTransactionFolder]);
