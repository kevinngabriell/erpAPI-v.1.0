import { crudModule, folder, req, ok, created, badReq, notFound, conflict, paginated } from './pm-helpers.mjs';

const ID = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';
const COMPANY_ID = 'b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e';
const BY = { created_by: 'Budi Santoso', created_at: '2026-06-27 10:00:00', updated_by: null, updated_at: null, deleted_at: null };

export const masterTenantFolders = [];

// account-code ---------------------------------------------------------------
{
  const row = {
    id: ID, company_id: COMPANY_ID, account_code: '1000', account_code_name: 'Cash',
    account_code_name_alias: 'Kas', account_type: 'asset', parent_account_code_id: null, is_active: true, ...BY,
  };
  masterTenantFolders.push(crudModule({
    path: 'account-code', displayName: 'Account Code', idParam: 'account_code_id', idExample: ID,
    extraListQuery: [{ key: 'account_type', value: 'asset', description: 'asset | liability | equity | revenue | expense' }],
    listRow: row,
    createBody: { account_code: '1000', account_code_name: 'Cash', account_type: 'asset', account_code_name_alias: 'Kas', parent_account_code_id: null, is_active: true },
    createdField: 'account_code_id', createdIdExample: ID,
    extraCreate400: ['account_type must be asset, liability, equity, revenue, or expense'],
    extraCreate404: ['Parent account code not found'],
    extraUpdate400: ['account_code cannot be empty', 'account_code_name cannot be empty', 'account_type must be asset, liability, equity, revenue, or expense'],
    extraUpdate404: ['Parent account code not found'],
    messages: {
      listFound: 'Account codes found', listEmpty: 'No account codes found', itemFound: 'Account code found',
      itemNotFound: 'Account code not found', created: 'Account code created successfully',
      requiredMessage: 'account_code is required', duplicate: 'Account code already exists',
      updated: 'Account code updated successfully', noFields: 'No fields provided for update',
      deleted: 'Account code deleted successfully',
    },
    notes: 'Company-scoped. account_type is a fixed enum. parent_account_code_id supports a self-referencing hierarchy within the same company.',
  }));
}

// bank-account -----------------------------------------------------------
{
  const row = {
    id: ID, company_id: COMPANY_ID, bank_number: '1234567890', bank_name: 'Bank Central Asia',
    bank_branch: 'Jakarta Sudirman', currency_id: 'c3d4e5f6-a7b8-4c5d-9e0f-1a2b3c4d5e6f', is_primary: true, ...BY,
  };
  masterTenantFolders.push(crudModule({
    path: 'bank-account', displayName: 'Bank Account', idParam: 'bank_account_id', idExample: ID,
    extraListQuery: [{ key: 'currency_id', value: '', description: 'Filter by currency ID' }],
    listRow: row,
    createBody: { bank_number: '1234567890', bank_name: 'Bank Central Asia', currency_id: 'c3d4e5f6-a7b8-4c5d-9e0f-1a2b3c4d5e6f', bank_branch: 'Jakarta Sudirman', is_primary: true },
    createdField: 'bank_account_id', createdIdExample: ID,
    extraCreate404: ['Currency not found'],
    extraUpdate400: ['bank_number cannot be empty', 'bank_name cannot be empty'],
    extraUpdate404: ['Currency not found'],
    messages: {
      listFound: 'Bank accounts found', listEmpty: 'No bank accounts found', itemFound: 'Bank account found',
      itemNotFound: 'Bank account not found', created: 'Bank account created successfully',
      requiredMessage: 'bank_number is required', duplicate: 'Bank account already exists',
      updated: 'Bank account updated successfully', noFields: 'No fields provided for update',
      deleted: 'Bank account deleted successfully',
    },
    notes: 'Company-scoped. currency_id must reference an existing currency.',
  }));
}

// customer -----------------------------------------------------------------
{
  const baseFields = {
    id: ID, company_id: COMPANY_ID, customer_name: 'PT Maju Bersama', customer_address: 'Jl. Sudirman No. 1, Jakarta',
    customer_phone: '021-5551234', customer_pic_name: 'Budi Santoso', customer_pic_contact: '0812-3456-7890',
    customer_top_days: 30, npwp: '01.234.567.8-901.000', tax_invoice_name: 'PT Maju Bersama',
    tax_invoice_address: 'Jl. Sudirman No. 1, Jakarta', credit_limit: 50000000, ...BY,
  };
  const listRow = { ...baseFields, outstanding_amount: 2500000, has_outstanding: true };
  masterTenantFolders.push(crudModule({
    path: 'customer', displayName: 'Customer', idParam: 'customer_id', idExample: ID,
    listRow, detailRow: baseFields,
    createBody: {
      customer_name: 'PT Maju Bersama', customer_address: 'Jl. Sudirman No. 1, Jakarta', customer_phone: '021-5551234',
      customer_pic_name: 'Budi Santoso', customer_pic_contact: '0812-3456-7890', customer_top_days: 30,
      npwp: '01.234.567.8-901.000', tax_invoice_name: 'PT Maju Bersama', tax_invoice_address: 'Jl. Sudirman No. 1, Jakarta',
      credit_limit: 50000000,
    },
    createdField: 'customer_id', createdIdExample: ID,
    extraUpdate400: ['customer_name cannot be empty'],
    messages: {
      listFound: 'Customers found', listEmpty: 'No customers found', itemFound: 'Customer found',
      itemNotFound: 'Customer not found', created: 'Customer created successfully',
      requiredMessage: 'customer_name is required', duplicate: 'Customer already exists',
      updated: 'Customer updated successfully', noFields: 'No fields provided for update',
      deleted: 'Customer deleted successfully',
    },
    notes: 'Company-scoped. created_by/updated_by resolved to full name. outstanding_amount/has_outstanding appear only on the LIST endpoint, never on detail — computed from MAX(due_amount)-SUM(paid_amount) per invoice across finance_payment.',
  }));
}

// supplier -------------------------------------------------------------------
{
  const baseFields = {
    id: ID, company_id: COMPANY_ID, supplier_name: 'CV Sumber Rejeki', supplier_origin_id: 'c3d4e5f6-a7b8-4c5d-9e0f-1a2b3c4d5e6f',
    supplier_address: 'Jl. Gatot Subroto No. 5, Jakarta', supplier_phone: '021-5559876', supplier_pic_name: 'Andi Wijaya',
    supplier_pic_contact: '0813-4567-8901', supplier_currency_id: 'd4e5f6a7-b8c9-4d5e-9f0a-1b2c3d4e5f6a',
    supplier_term_id: 'e5f6a7b8-c9d0-4e5f-9a0b-1c2d3e4f5a6b', supplier_bank_information: 'BCA 1234567890 a/n CV Sumber Rejeki',
    npwp: '02.345.678.9-012.000', tax_invoice_name: 'CV Sumber Rejeki', tax_invoice_address: 'Jl. Gatot Subroto No. 5, Jakarta', ...BY,
  };
  const listRow = { ...baseFields, outstanding_amount: 1500000, has_outstanding: true };
  masterTenantFolders.push(crudModule({
    path: 'supplier', displayName: 'Supplier', idParam: 'supplier_id', idExample: ID,
    extraListQuery: [{ key: 'supplier_origin_id', value: '', description: 'Filter by origin ID' }],
    listRow, detailRow: baseFields,
    createBody: {
      supplier_name: 'CV Sumber Rejeki', supplier_origin_id: 'c3d4e5f6-a7b8-4c5d-9e0f-1a2b3c4d5e6f',
      supplier_address: 'Jl. Gatot Subroto No. 5, Jakarta', supplier_phone: '021-5559876', supplier_pic_name: 'Andi Wijaya',
      supplier_pic_contact: '0813-4567-8901', supplier_currency_id: 'd4e5f6a7-b8c9-4d5e-9f0a-1b2c3d4e5f6a',
      supplier_term_id: 'e5f6a7b8-c9d0-4e5f-9a0b-1c2d3e4f5a6b', supplier_bank_information: 'BCA 1234567890 a/n CV Sumber Rejeki',
      npwp: '02.345.678.9-012.000', tax_invoice_name: 'CV Sumber Rejeki', tax_invoice_address: 'Jl. Gatot Subroto No. 5, Jakarta',
    },
    createdField: 'supplier_id', createdIdExample: ID,
    extraCreate404: ['Origin not found', 'Currency not found', 'Payment term not found'],
    extraUpdate400: ['supplier_name cannot be empty', 'supplier_origin_id cannot be empty'],
    extraUpdate404: ['Origin not found', 'Currency not found', 'Payment term not found'],
    messages: {
      listFound: 'Suppliers found', listEmpty: 'No suppliers found', itemFound: 'Supplier found',
      itemNotFound: 'Supplier not found', created: 'Supplier created successfully',
      requiredMessage: 'supplier_name is required', duplicate: 'Supplier already exists',
      updated: 'Supplier updated successfully', noFields: 'No fields provided for update',
      deleted: 'Supplier deleted successfully',
    },
    notes: 'Company-scoped. supplier_origin_id/supplier_currency_id/supplier_term_id reference global lookup tables. outstanding_amount/has_outstanding appear only on LIST, not detail.',
  }));
}

// product ----------------------------------------------------------------
{
  const row = {
    id: ID, company_id: COMPANY_ID, product_name: 'Kertas HVS A4 80gsm', product_code: 'C-029',
    product_desc: 'Rim, 500 lembar per rim', hs_code: '4802.56.00', ...BY,
  };
  masterTenantFolders.push(crudModule({
    path: 'product', displayName: 'Product', idParam: 'product_id', idExample: ID,
    listRow: row,
    createBody: { product_name: 'Kertas HVS A4 80gsm', product_code: 'C-029', product_desc: 'Rim, 500 lembar per rim', hs_code: '4802.56.00' },
    createdField: 'product_id', createdIdExample: ID,
    extraCreate409: null,
    extraUpdate400: ['product_name cannot be empty'],
    messages: {
      listFound: 'Products found', listEmpty: 'No products found', itemFound: 'Product found',
      itemNotFound: 'Product not found', created: 'Product created successfully',
      requiredMessage: 'product_name is required', duplicate: 'Product already exists',
      updated: 'Product updated successfully', noFields: 'No fields provided for update',
      deleted: 'Product deleted successfully',
    },
    notes: 'Company-scoped. product_code (SKU) is optional/nullable but unique within the company when provided — also returns 409 "Product code already exists" as a distinct duplicate case from product_name.',
  }));
}

// document-center (no duplicate check) --------------------------------------
{
  const row = { id: ID, company_id: COMPANY_ID, document_name: 'Invoice Template 2026', document_file_size: 204800, ...BY };
  masterTenantFolders.push(crudModule({
    path: 'document-center', displayName: 'Document Center', idParam: 'document_center_id', idExample: ID,
    listRow: row,
    createBody: { document_name: 'Invoice Template 2026', document_file_size: 204800 },
    createdField: 'document_center_id', createdIdExample: ID,
    extraUpdate400: ['document_name cannot be empty'],
    messages: {
      listFound: 'Documents found', listEmpty: 'No documents found', itemFound: 'Document found',
      itemNotFound: 'Document not found', created: 'Document created successfully',
      requiredMessage: 'document_name is required', duplicate: null,
      updated: 'Document updated successfully', noFields: 'No fields provided for update',
      deleted: 'Document deleted successfully',
    },
    notes: 'Company-scoped. No duplicate check on create/update — document_name may repeat. Stores metadata only (document_name, document_file_size); does not accept/return file content.',
  }));
}

// document-watermark (base64, no duplicate check) ---------------------------
{
  const row = { id: ID, company_id: COMPANY_ID, watermark: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB...', ...BY };
  masterTenantFolders.push(crudModule({
    path: 'document-watermark', displayName: 'Document Watermark', idParam: 'document_watermark_id', idExample: ID,
    searchParam: null,
    listRow: row,
    createBody: { watermark: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB...' },
    createdField: 'document_watermark_id', createdIdExample: ID,
    extraCreate400: ['watermark must be a valid base64 encoded string'],
    extraUpdate400: ['watermark must be a valid base64 encoded string'],
    messages: {
      listFound: 'Document watermarks found', listEmpty: 'No document watermarks found', itemFound: 'Document watermark found',
      itemNotFound: 'Document watermark not found', created: 'Document watermark created successfully',
      requiredMessage: 'watermark is required', duplicate: null,
      updated: 'Document watermark updated successfully', noFields: 'No fields provided for update',
      deleted: 'Document watermark deleted successfully',
    },
    notes: 'Company-scoped. watermark stored as BLOB, transmitted base64-encoded both ways. PUT requires watermark to be present and non-empty — no partial-update path (unlike most modules).',
  }));
}

// sales-target ---------------------------------------------------------------
{
  const row = { id: ID, company_id: COMPANY_ID, target_year: 2026, target_value: 5000000000, ...BY };
  masterTenantFolders.push(crudModule({
    path: 'sales-target', displayName: 'Sales Target', idParam: 'sales_target_id', idExample: ID,
    searchParam: null,
    extraListQuery: [{ key: 'target_year', value: '2026', description: 'Filter by target year' }],
    listRow: row,
    createBody: { target_year: 2026, target_value: 5000000000 },
    createdField: 'sales_target_id', createdIdExample: ID,
    extraCreate400: ['target_year must be a 4-digit year'],
    extraUpdate400: ['target_year must be a 4-digit year'],
    extraCreate409: 'Sales target already exists for this year',
    extraUpdate409: 'Sales target already exists for this year',
    messages: {
      listFound: 'Sales targets found', listEmpty: 'No sales targets found', itemFound: 'Sales target found',
      itemNotFound: 'Sales target not found', created: 'Sales target created successfully',
      requiredMessage: 'target_year is required', duplicate: null,
      updated: 'Sales target updated successfully', noFields: 'No fields provided for update',
      deleted: 'Sales target deleted successfully',
    },
    notes: 'Company-scoped. Uniqueness is per (company_id, target_year) — at most one target per year per company.',
  }));
}

// warehouse-location -----------------------------------------------------
{
  const row = { id: ID, company_id: COMPANY_ID, location_name: 'Gudang Cikarang', address: 'Jl. Industri Raya No. 10, Cikarang', ...BY };
  masterTenantFolders.push(crudModule({
    path: 'warehouse-location', displayName: 'Warehouse Location', idParam: 'warehouse_location_id', idExample: ID,
    listRow: row,
    createBody: { location_name: 'Gudang Cikarang', address: 'Jl. Industri Raya No. 10, Cikarang' },
    createdField: 'warehouse_location_id', createdIdExample: ID,
    extraUpdate400: ['location_name cannot be empty'],
    messages: {
      listFound: 'Warehouse locations found', listEmpty: 'No warehouse locations found', itemFound: 'Warehouse location found',
      itemNotFound: 'Warehouse location not found', created: 'Warehouse location created successfully',
      requiredMessage: 'location_name is required', duplicate: 'Warehouse location already exists',
      updated: 'Warehouse location updated successfully', noFields: 'No fields provided for update',
      deleted: 'Warehouse location deleted successfully',
    },
    notes: 'Company-scoped.',
  }));
}

// company-setting-menu (+ nested details sub-resource) ------------------------
{
  const menuRow = { id: ID, company_id: COMPANY_ID, setting_image: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB...', setting_name: 'General', setting_caption: 'General company settings', ...BY };
  const menuFolder = crudModule({
    path: 'company-setting-menu', displayName: 'Company Setting Menu', idParam: 'company_setting_menu_id', idExample: ID,
    listRow: menuRow,
    createBody: { setting_name: 'General', setting_caption: 'General company settings', setting_image: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB...' },
    createdField: 'company_setting_menu_id', createdIdExample: ID,
    extraCreate400: ['setting_image must be a valid base64 encoded string'],
    extraUpdate400: ['setting_name cannot be empty', 'setting_image must be a valid base64 encoded string'],
    messages: {
      listFound: 'Company setting menus found', listEmpty: 'No company setting menus found', itemFound: 'Company setting menu found',
      itemNotFound: 'Company setting menu not found', created: 'Company setting menu created successfully',
      requiredMessage: 'setting_name is required', duplicate: null,
      updated: 'Company setting menu updated successfully', noFields: 'No fields provided for update',
      deleted: 'Company setting menu deleted successfully',
    },
    notes: 'Company-scoped. setting_image is BLOB, base64 both ways. No duplicate check.',
  });

  // Nested details sub-resource
  const SETTING_MENU_ID = ID;
  const DETAIL_ID = 'f6a7b8c9-d0e1-4f5a-9b0c-1d2e3f4a5b6c';
  const detailRow = {
    id: DETAIL_ID, setting_menu_id: SETTING_MENU_ID, is_tab: true, tab_count: 3, is_data: true,
    data_url: '/settings/general/company-profile', is_can_new: false, ...BY,
  };
  const detailItems = [
    req({
      name: 'List Setting Details',
      method: 'GET',
      path: 'company-setting-menu/:setting_menu_id/details',
      pathVars: { setting_menu_id: SETTING_MENU_ID },
      query: [
        { key: 'page', value: '1', description: 'Page number' },
        { key: 'limit', value: '10', description: 'Items per page (max 100)' },
      ],
      description: 'List all detail records under a setting menu. No search parameter exists for this sub-resource.',
      examples: [
        { code: 200, body: ok('Company setting details found', paginated([detailRow], 8, 1, 10)) },
        { code: 404, body: notFound('No company setting details found') },
        { code: 404, name: '404 Not Found (parent missing)', body: notFound('Setting menu not found') },
      ],
    }),
    req({
      name: 'Create Setting Detail',
      method: 'POST',
      path: 'company-setting-menu/:setting_menu_id/details',
      pathVars: { setting_menu_id: SETTING_MENU_ID },
      body: { data_url: '/settings/general/company-profile', is_tab: true, tab_count: 3, is_data: true, is_can_new: false },
      description: 'Create a new detail record under a setting menu.',
      examples: [
        { code: 201, body: created('Company setting detail created successfully', { company_setting_detail_id: DETAIL_ID }) },
        { code: 400, body: badReq('data_url is required') },
        { code: 404, body: notFound('Setting menu not found') },
      ],
    }),
    req({
      name: 'Get Setting Detail',
      method: 'GET',
      path: 'company-setting-menu/:setting_menu_id/details/:company_setting_detail_id',
      pathVars: { setting_menu_id: SETTING_MENU_ID, company_setting_detail_id: DETAIL_ID },
      description: 'Get detail of a single company setting detail record.',
      examples: [
        { code: 200, body: ok('Company setting detail found', detailRow) },
        { code: 404, body: notFound('Company setting detail not found') },
        { code: 404, name: '404 Not Found (parent missing)', body: notFound('Setting menu not found') },
      ],
    }),
    req({
      name: 'Update Setting Detail',
      method: 'PUT',
      path: 'company-setting-menu/:setting_menu_id/details/:company_setting_detail_id',
      pathVars: { setting_menu_id: SETTING_MENU_ID, company_setting_detail_id: DETAIL_ID },
      body: { is_tab: true, tab_count: 4, is_data: true, data_url: '/settings/general/company-profile', is_can_new: false },
      description: 'Update a company setting detail record. Only send the fields you want to change.',
      examples: [
        { code: 200, body: ok('Company setting detail updated successfully', []) },
        { code: 400, body: badReq('No fields provided for update') },
        { code: 400, name: '400 Bad Request (empty data_url)', body: badReq('data_url cannot be empty') },
        { code: 404, body: notFound('Company setting detail not found') },
      ],
    }),
    req({
      name: 'Delete Setting Detail',
      method: 'DELETE',
      path: 'company-setting-menu/:setting_menu_id/details/:company_setting_detail_id',
      pathVars: { setting_menu_id: SETTING_MENU_ID, company_setting_detail_id: DETAIL_ID },
      description: 'Soft-deletes the company setting detail record.',
      examples: [
        { code: 200, body: ok('Company setting detail deleted successfully', []) },
        { code: 404, body: notFound('Company setting detail not found') },
      ],
    }),
  ];
  menuFolder.item.push(folder('Setting Details (sub-resource)', detailItems,
    'Nested under a parent setting menu: /company-setting-menu/{setting_menu_id}/details[/{detail_id}]. The parent must exist, belong to the company, and not be soft-deleted, or every endpoint returns 404 "Setting menu not found". company_setting_detail rows have no company_id of their own — isolation is enforced via the parent.'));
  masterTenantFolders.push(menuFolder);
}

export const masterTenantFolder = folder('Tenant Master Data', masterTenantFolders,
  'Master data scoped to the authenticated company (company_id from the JWT) — records from other companies are never returned or modifiable.');
