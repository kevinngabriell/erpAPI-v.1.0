import { crudModule, folder } from './pm-helpers.mjs';

const UID = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';
const BY = { created_by: 'Budi Santoso', created_at: '2026-06-27 10:00:00', updated_by: null, updated_at: null, deleted_at: null };

export const globalLookupFolders = [];

// 1. Region ------------------------------------------------------------
globalLookupFolders.push(crudModule({
  path: 'region', displayName: 'Region', idParam: 'id', idExample: UID,
  listRow: { id: UID, region_name: 'Jawa Barat', ...BY },
  createBody: { region_name: 'Jawa Barat' }, createdField: 'region_id', createdIdExample: UID,
  messages: {
    listFound: 'Regions found', listEmpty: 'No regions found', itemFound: 'Region found',
    itemNotFound: 'Region not found', created: 'Region created successfully',
    requiredMessage: 'region_name is required', duplicate: 'Region already exists',
    updated: 'Region updated successfully', noFields: 'No fields provided for update',
    deleted: 'Region deleted successfully',
  },
  notes: 'Global lookup table (not company-scoped) — every company shares the same regions.',
}));

// 2. Origin --------------------------------------------------------------
const ORIGIN_ROW = { id: UID, origin_name: 'Pelabuhan Tanjung Priok', region_name: 'Jawa Barat', is_free_trade: false, ...BY };
globalLookupFolders.push(crudModule({
  path: 'origin', displayName: 'Origin', idParam: 'id', idExample: UID,
  extraListQuery: [{ key: 'region_id', value: '', description: 'Filter by region ID (UUID)' }],
  listRow: ORIGIN_ROW,
  createBody: { origin_name: 'Pelabuhan Tanjung Priok', region_id: 'b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e', is_free_trade: false },
  createdField: 'origin_id', createdIdExample: UID,
  extraCreate404: ['Region not found'],
  extraUpdate404: ['Region not found'],
  messages: {
    listFound: 'Origins found', listEmpty: 'No origins found', itemFound: 'Origin found',
    itemNotFound: 'Origin not found', created: 'Origin created successfully',
    requiredMessage: 'origin_name is required', duplicate: 'Origin already exists',
    updated: 'Origin updated successfully', noFields: 'No fields provided for update',
    deleted: 'Origin deleted successfully',
  },
  notes: 'Global lookup table. region_id must reference an existing, non-deleted region. region_name is resolved via LEFT JOIN and replaces region_id in responses.',
}));

// 3. Unit of Measure -------------------------------------------------------
globalLookupFolders.push(crudModule({
  path: 'unit-of-measure', displayName: 'Unit Of Measure', idParam: 'id', idExample: UID,
  listRow: { id: UID, uom_name: 'Kilogram', conversion_factor: 1.0, ...BY },
  createBody: { uom_name: 'Kilogram', conversion_factor: 1.0 }, createdField: 'unit_of_measure_id', createdIdExample: UID,
  messages: {
    listFound: 'Unit of measures found', listEmpty: 'No unit of measures found', itemFound: 'Unit of measure found',
    itemNotFound: 'Unit of measure not found', created: 'Unit of measure created successfully',
    requiredMessage: 'uom_name is required', duplicate: 'Unit of measure already exists',
    updated: 'Unit of measure updated successfully', noFields: 'No fields provided for update',
    deleted: 'Unit of measure deleted successfully',
  },
  notes: 'Global lookup table (not company-scoped).',
}));

// 4. Currency ---------------------------------------------------------------
globalLookupFolders.push(crudModule({
  path: 'currency', displayName: 'Currency', idParam: 'id', idExample: UID,
  listRow: { id: UID, currency_code: 'IDR', currency_symbol: 'Rp', currency_name: 'Indonesian Rupiah', ...BY },
  createBody: { currency_code: 'USD', currency_symbol: '$', currency_name: 'US Dollar' }, createdField: 'currency_id', createdIdExample: UID,
  messages: {
    listFound: 'Currencies found', listEmpty: 'No currencies found', itemFound: 'Currency found',
    itemNotFound: 'Currency not found', created: 'Currency created successfully',
    requiredMessage: 'currency_code is required', duplicate: 'Currency already exists',
    updated: 'Currency updated successfully', noFields: 'No fields provided for update',
    deleted: 'Currency deleted successfully',
  },
  notes: 'Global lookup table. currency_code is always upper-cased server-side before storage/comparison.',
}));

// 5. PPN Type -----------------------------------------------------------
globalLookupFolders.push(crudModule({
  path: 'ppn-type', displayName: 'Ppn Type', idParam: 'id', idExample: UID,
  listRow: { id: UID, ppn_name: 'PPN 11%', ppn_percentage: 11.0, ...BY },
  createBody: { ppn_name: 'PPN 11%', ppn_percentage: 11.0 }, createdField: 'ppn_type_id', createdIdExample: UID,
  messages: {
    listFound: 'Ppn types found', listEmpty: 'No ppn types found', itemFound: 'Ppn type found',
    itemNotFound: 'Ppn type not found', created: 'Ppn type created successfully',
    requiredMessage: 'ppn_name is required', duplicate: 'Ppn type already exists',
    updated: 'Ppn type updated successfully', noFields: 'No fields provided for update',
    deleted: 'Ppn type deleted successfully',
  },
  notes: 'Global lookup table (not company-scoped).',
}));

// 6. Purchase Status -------------------------------------------------------
globalLookupFolders.push(crudModule({
  path: 'purchase-status', displayName: 'Purchase Status', idParam: 'id', idExample: UID,
  listRow: { id: UID, status_name: 'Pending Approval', ...BY },
  createBody: { status_name: 'Pending Approval' }, createdField: 'purchase_status_id', createdIdExample: UID,
  messages: {
    listFound: 'Purchase statuses found', listEmpty: 'No purchase statuses found', itemFound: 'Purchase status found',
    itemNotFound: 'Purchase status not found', created: 'Purchase status created successfully',
    requiredMessage: 'status_name is required', duplicate: 'Purchase status already exists',
    updated: 'Purchase status updated successfully', noFields: 'No fields provided for update',
    deleted: 'Purchase status deleted successfully',
  },
  notes: 'Global lookup table. Seeded values (Draft/Approved/Rejected/...) are relied upon by purchase-order/invoice/receive status transitions.',
}));

// 7. Purchase Type (custom - has number_format/sequence_digits) -----------
{
  const row = {
    id: UID, type_name: 'Local Purchase', vat_applicable: true,
    number_format: '{company_code}/L/{month}/{yyyy}/{seq}', sequence_digits: '3', ...BY,
  };
  globalLookupFolders.push(crudModule({
    path: 'purchase-type', displayName: 'Purchase Type', idParam: 'id', idExample: UID,
    listRow: row,
    createBody: {
      type_name: 'Local Purchase', vat_applicable: true,
      number_format: '{company_code}/L/{month}/{yyyy}/{seq}', sequence_digits: 3,
    },
    createdField: 'purchase_type_id', createdIdExample: UID,
    extraCreate400: [
      'number_format must include a {seq} placeholder',
      'sequence_digits must be between 1 and 10',
    ],
    extraUpdate400: ['number_format must include a {seq} placeholder'],
    messages: {
      listFound: 'Purchase types found', listEmpty: 'No purchase types found', itemFound: 'Purchase type found',
      itemNotFound: 'Purchase type not found', created: 'Purchase type created successfully',
      requiredMessage: 'type_name is required', duplicate: 'Purchase type already exists',
      updated: 'Purchase type updated successfully', noFields: 'No fields provided for update',
      deleted: 'Purchase type deleted successfully',
    },
    notes: 'Global lookup table. number_format/sequence_digits drive GET /purchase-order/generate-number — must contain a literal {seq} token. Supported tokens: {company_code}, {month} (Roman numeral), {yyyy}, {yy}.',
  }));
}

// 8. Sales Status -----------------------------------------------------------
globalLookupFolders.push(crudModule({
  path: 'sales-status', displayName: 'Sales Status', idParam: 'id', idExample: UID,
  listRow: { id: UID, status_name: 'Quotation', ...BY },
  createBody: { status_name: 'Quotation' }, createdField: 'sales_status_id', createdIdExample: UID,
  messages: {
    listFound: 'Sales statuses found', listEmpty: 'No sales statuses found', itemFound: 'Sales status found',
    itemNotFound: 'Sales status not found', created: 'Sales status created successfully',
    requiredMessage: 'status_name is required', duplicate: 'Sales status already exists',
    updated: 'Sales status updated successfully', noFields: 'No fields provided for update',
    deleted: 'Sales status deleted successfully',
  },
  notes: 'Global lookup table. Seeded Draft/Approved/Rejected rows drive sales-order/delivery/invoice/sppb/profit status transitions — do not rename/delete them.',
}));

// 9. Ship Via -----------------------------------------------------------
globalLookupFolders.push(crudModule({
  path: 'ship-via', displayName: 'Ship Via', idParam: 'id', idExample: UID,
  listRow: { id: UID, ship_name: 'JNE Regular', ...BY },
  createBody: { ship_name: 'JNE Regular' }, createdField: 'ship_via_id', createdIdExample: UID,
  messages: {
    listFound: 'Ship vias found', listEmpty: 'No ship vias found', itemFound: 'Ship via found',
    itemNotFound: 'Ship via not found', created: 'Ship via created successfully',
    requiredMessage: 'ship_name is required', duplicate: 'Ship via already exists',
    updated: 'Ship via updated successfully', noFields: 'No fields provided for update',
    deleted: 'Ship via deleted successfully',
  },
  notes: 'Global lookup table.',
}));

// 10. Shipment Period (id is a slug string, not a UUID) --------------------
{
  const SP_ID = 'shp_early_jul';
  globalLookupFolders.push(crudModule({
    path: 'shipment-period', displayName: 'Shipment Period', idParam: 'id', idExample: SP_ID,
    listRow: { id: SP_ID, period_name: 'Early July', sort_order: 19, created_by: 'Budi Santoso', created_at: '2026-07-25 06:39:53', updated_by: null, updated_at: '2026-07-25 06:39:53', deleted_at: null },
    createBody: { period_name: 'Early July', sort_order: 19 }, createdField: 'shipment_period_id', createdIdExample: 'shp_64a1b2c3',
    messages: {
      listFound: 'Shipment periods found', listEmpty: 'No shipment periods found', itemFound: 'Shipment period found',
      itemNotFound: 'Shipment period not found', created: 'Shipment period created successfully',
      requiredMessage: 'period_name is required', duplicate: 'Shipment period already exists',
      updated: 'Shipment period updated successfully', noFields: 'No fields provided for update',
      deleted: 'Shipment period deleted successfully',
    },
    notes: 'Global lookup, seeded with 36 fixed rows (Early/Mid/End x each month) used as purchase_order.shipment_period_id. List is ordered by sort_order, not created_at.',
  }));
}

// 11. Payment Method -----------------------------------------------------
globalLookupFolders.push(crudModule({
  path: 'payment-method', displayName: 'Payment Method', idParam: 'id', idExample: UID,
  listRow: { id: UID, method_name: 'Bank Transfer', ...BY },
  createBody: { method_name: 'Bank Transfer' }, createdField: 'payment_method_id', createdIdExample: UID,
  messages: {
    listFound: 'Payment methods found', listEmpty: 'No payment methods found', itemFound: 'Payment method found',
    itemNotFound: 'Payment method not found', created: 'Payment method created successfully',
    requiredMessage: 'method_name is required', duplicate: 'Payment method already exists',
    updated: 'Payment method updated successfully', noFields: 'No fields provided for update',
    deleted: 'Payment method deleted successfully',
  },
  notes: 'Global lookup table.',
}));

// 12. Payment Term --------------------------------------------------------
globalLookupFolders.push(crudModule({
  path: 'payment-term', displayName: 'Payment Term', idParam: 'id', idExample: UID,
  listRow: { id: UID, term_name: 'Net 30', days: 30, ...BY },
  createBody: { term_name: 'Net 30', days: 30 }, createdField: 'payment_term_id', createdIdExample: UID,
  messages: {
    listFound: 'Payment terms found', listEmpty: 'No payment terms found', itemFound: 'Payment term found',
    itemNotFound: 'Payment term not found', created: 'Payment term created successfully',
    requiredMessage: 'term_name is required', duplicate: 'Payment term already exists',
    updated: 'Payment term updated successfully', noFields: 'No fields provided for update',
    deleted: 'Payment term deleted successfully',
  },
  notes: 'Global lookup table.',
}));

// 13. Finance Category (enum category_type) --------------------------------
globalLookupFolders.push(crudModule({
  path: 'finance-category', displayName: 'Finance Category', idParam: 'id', idExample: UID,
  listRow: { id: UID, category_name: 'Office Supplies', category_type: 'debit', ...BY },
  createBody: { category_name: 'Office Supplies', category_type: 'debit' }, createdField: 'finance_category_id', createdIdExample: UID,
  messages: {
    listFound: 'Finance categories found', listEmpty: 'No finance categories found', itemFound: 'Finance category found',
    itemNotFound: 'Finance category not found', created: 'Finance category created successfully',
    requiredMessage: 'category_name is required', duplicate: 'Finance category already exists',
    updated: 'Finance category updated successfully', noFields: 'No fields provided for update',
    deleted: 'Finance category deleted successfully',
  },
  notes: 'Global lookup table. category_type is "debit" (cash in) or "credit" (cash out) — drives GET /cash-book direction classification.',
}));

// 14. Salary Category (enum category_type, unique name+type) --------------
globalLookupFolders.push(crudModule({
  path: 'salary-category', displayName: 'Salary Category', idParam: 'id', idExample: UID,
  extraListQuery: [{ key: 'category_type', value: 'allowance', description: 'allowance | deduction' }],
  listRow: { id: UID, category_name: 'Transport Allowance', category_type: 'allowance', ...BY },
  createBody: { category_name: 'Transport Allowance', category_type: 'allowance' }, createdField: 'salary_category_id', createdIdExample: UID,
  extraCreate400: ['category_type must be allowance or deduction'],
  extraUpdate400: ['category_type must be allowance or deduction'],
  messages: {
    listFound: 'Salary categories found', listEmpty: 'No salary categories found', itemFound: 'Salary category found',
    itemNotFound: 'Salary category not found', created: 'Salary category created successfully',
    requiredMessage: 'category_name is required', duplicate: 'Salary category already exists',
    updated: 'Salary category updated successfully', noFields: 'No fields provided for update',
    deleted: 'Salary category deleted successfully',
  },
  notes: 'Global lookup table. Uniqueness is on (category_name, category_type) together — the same name may exist once as allowance and once as deduction.',
}));

export const masterGlobalFolder = folder('Global Lookups', globalLookupFolders,
  'Global master tables — not scoped to company_id, shared across every tenant. Authorization is still required.');
