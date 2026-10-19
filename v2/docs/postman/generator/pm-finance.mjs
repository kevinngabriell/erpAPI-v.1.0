import { req, folder, ok, created, badReq, notFound, forbidden, conflict, paginated } from './pm-helpers.mjs';

const COMPANY_ID = 'b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e';
const FT_ID = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';
const FT_DETAIL_ID = 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c';

// ============================= FINANCE TRANSACTION ==========================
const ftListRow = {
  id: FT_ID, voucher_number: 'VC-2026-0001', transaction_date: '2026-07-01', memo: 'Office supplies payment',
  amount: 1500000, cheque_number: 'CHQ-0011', payee: 'PT Sumber Makmur', transaction_status: 'partially_approved',
  approved_by_owner_at: '2026-07-02 09:15:00', approved_by_treasury_at: null, bank_name: 'Bank Central Asia',
  bank_number: '1234567890', category_name: 'Operating Expense', created_by: 'Budi Santoso', created_at: '2026-07-01 10:00:00',
  updated_by: 'Kevin Gabriel', updated_at: '2026-07-02 09:15:00', deleted_at: null, approved_by_owner: 'Kevin Gabriel',
  approved_by_treasury: null, accounts: [{ account_code: '5100', account_code_name: 'Office Supplies Expense', amount: 1500000 }],
};
const ftDetailRow = {
  id: FT_DETAIL_ID, finance_transaction_id: FT_ID, account_code_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a',
  amount: 1500000, memo: 'Supplies expense', account_code: '5100', account_code_name: 'Office Supplies Expense',
  created_by: 'Budi Santoso', created_at: '2026-07-01 10:00:00', updated_by: null, updated_at: null, deleted_at: null,
};
const ftFull = {
  id: FT_ID, company_id: COMPANY_ID, voucher_number: 'VC-2026-0001', bank_account_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f',
  transaction_date: '2026-07-01', memo: 'Office supplies payment', amount: 1500000, cheque_number: 'CHQ-0011',
  payee: 'PT Sumber Makmur', finance_category_id: 'e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b', transaction_status: 'partially_approved',
  approved_by_owner_id: '614442bc-476a-4bbb-81f1-b3ccc2523d50', approved_by_owner_at: '2026-07-02 09:15:00',
  approved_by_treasury_id: null, approved_by_treasury_at: null, bank_name: 'Bank Central Asia', bank_number: '1234567890',
  category_name: 'Operating Expense', created_by: 'Budi Santoso', created_at: '2026-07-01 10:00:00', updated_by: 'Kevin Gabriel',
  updated_at: '2026-07-02 09:15:00', approved_by_owner: 'Kevin Gabriel', approved_by_treasury: null, deleted_at: null,
  details: [ftDetailRow],
};

const financeTransactionCore = [
  req({
    name: 'List Finance Transactions', method: 'GET', path: 'finance-transaction',
    query: [
      { key: 'page', value: '1' }, { key: 'limit', value: '10', description: 'max 100' },
      { key: 'search', value: '', description: 'Search voucher_number or payee', disabled: true },
      { key: 'bank_account_id', value: '', disabled: true }, { key: 'finance_category_id', value: '', disabled: true },
      { key: 'transaction_status', value: 'posted', description: 'draft|submitted|partially_approved|posted|rejected', disabled: true },
    ],
    description: 'List does not include nested details[]. As of the current version, raw FK ids (company_id, bank_account_id, finance_category_id, approved_by_owner_id, approved_by_treasury_id) are dropped from list rows in favor of resolved display fields; each row carries an accounts[] summary instead.',
    examples: [
      { code: 200, body: ok('Finance transactions found', paginated([ftListRow])) },
      { code: 404, body: notFound('No finance transactions found') },
    ],
  }),
  req({
    name: 'Create Finance Transaction', method: 'POST', path: 'finance-transaction',
    body: {
      bank_account_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f', transaction_date: '2026-07-01', amount: 1500000,
      finance_category_id: 'e5f6a7b8-c9d0-4e5f-2a3b-4c5d6e7f8a9b', voucher_number: 'VC-2026-0001', memo: 'Office supplies payment',
      cheque_number: 'CHQ-0011', payee: 'PT Sumber Makmur',
      details: [{ account_code_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', amount: 1500000, memo: 'Supplies expense' }],
    },
    description: 'Create with one or more GL account splits via details[]. SUM(details[].amount) must equal amount. Header + details insert is one DB transaction. Created with transaction_status = draft. Notifies keuangan.approve_owner/keuangan.approve_treasury holders.',
    examples: [
      { code: 201, body: created('Finance transaction created successfully', { finance_transaction_id: FT_ID }) },
      { code: 400, body: badReq('bank_account_id is required') },
      { code: 400, name: '400 Bad Request (details missing)', body: badReq('details must be a non-empty array') },
      { code: 400, name: '400 Bad Request (detail field)', body: badReq('details.account_code_id is required') },
      { code: 400, name: '400 Bad Request (sum mismatch)', body: badReq('Sum of details.amount must equal amount') },
      { code: 404, body: notFound('Bank account not found') },
      { code: 404, name: '404 Not Found (account code)', body: notFound('Account code not found') },
    ],
  }),
  req({
    name: 'Get Finance Transaction Detail', method: 'GET', path: 'finance-transaction/:id', pathVars: { id: FT_ID },
    description: 'Includes the nested details[] array and raw *_id FK fields (unlike list).',
    examples: [
      { code: 200, body: ok('Finance transaction found', ftFull) },
      { code: 404, body: notFound('Finance transaction not found') },
    ],
  }),
  req({
    name: 'Update Finance Transaction', method: 'PUT', path: 'finance-transaction/:id', pathVars: { id: FT_ID },
    body: { voucher_number: 'VC-2026-0001', memo: 'Office supplies payment (revised)', bank_account_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f', transaction_date: '2026-07-01' },
    description: 'Does not update details — use the details sub-resource. amount is not cross-checked against details on update.',
    examples: [
      { code: 200, body: ok('Finance transaction updated successfully', []) },
      { code: 400, body: badReq('No fields provided for update') },
      { code: 400, name: '400 Bad Request (empty FK)', body: badReq('bank_account_id cannot be empty') },
      { code: 404, body: notFound('Finance transaction not found') },
    ],
  }),
  req({
    name: 'Delete Finance Transaction', method: 'DELETE', path: 'finance-transaction/:id', pathVars: { id: FT_ID },
    description: 'Soft-delete. Not blocked by transaction_status (even a posted transaction can be deleted today). Does not cascade to details.',
    examples: [
      { code: 200, body: ok('Finance transaction deleted successfully', []) },
      { code: 404, body: notFound('Finance transaction not found') },
    ],
  }),
  req({
    name: 'Approve Finance Transaction', method: 'PATCH', path: 'finance-transaction/:id/approve', pathVars: { id: FT_ID },
    body: { notes: 'Confirmed against bank statement' },
    description: 'Signs as Business Owner or Treasury/Controller — whichever slot the caller\'s role is permitted to fill (keuangan.approve_owner / keuangan.approve_treasury). The same user cannot fill both slots. First signature -> partially_approved, second (different) signature -> posted.',
    examples: [
      { code: 200, body: ok('Finance transaction approved successfully', { approved_slot: 'owner', transaction_status: 'partially_approved' }) },
      { code: 403, body: forbidden('You do not have permission to approve this record') },
      { code: 404, body: notFound('Record not found') },
      { code: 409, body: conflict('You have already signed this record') },
      { code: 409, name: '409 Conflict (already posted)', body: conflict('Record is already posted') },
    ],
  }),
  req({
    name: 'Reject Finance Transaction', method: 'PATCH', path: 'finance-transaction/:id/reject', pathVars: { id: FT_ID },
    body: { notes: 'Missing supporting receipt' },
    description: 'Either signer can reject at any point before posted. Rejection is final and does not require both slots filled.',
    examples: [
      { code: 200, body: ok('Finance transaction rejected successfully', []) },
      { code: 403, body: forbidden('You do not have permission to reject this record') },
      { code: 404, body: notFound('Record not found') },
      { code: 409, body: conflict('Record is already rejected') },
    ],
  }),
];

const financeTransactionDetails = folder('Finance Transaction Details (sub-resource)', [
  req({
    name: 'List Detail Lines', method: 'GET', path: 'finance-transaction/:id/details', pathVars: { id: FT_ID },
    description: 'Every finance transaction has at least one detail line (a GL account split).',
    examples: [
      { code: 200, body: ok('Finance transaction details found', { data: [ftDetailRow] }) },
      { code: 404, body: notFound('No finance transaction details found') },
      { code: 404, name: '404 Not Found (parent missing)', body: notFound('Finance transaction not found') },
    ],
  }),
  req({
    name: 'Add Detail Line', method: 'POST', path: 'finance-transaction/:id/details', pathVars: { id: FT_ID },
    body: { account_code_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', amount: 1500000, memo: 'Supplies expense' },
    description: 'Does not re-validate that the transaction total amount still reconciles with the sum of detail lines.',
    examples: [
      { code: 201, body: created('Finance transaction detail created successfully', { finance_transaction_detail_id: FT_DETAIL_ID }) },
      { code: 400, body: badReq('account_code_id is required') },
      { code: 404, body: notFound('Account code not found') },
    ],
  }),
  req({
    name: 'Get Detail Line', method: 'GET', path: 'finance-transaction/:id/details/:detail_id', pathVars: { id: FT_ID, detail_id: FT_DETAIL_ID },
    examples: [
      { code: 200, body: ok('Finance transaction detail found', ftDetailRow) },
      { code: 404, body: notFound('Finance transaction detail not found') },
    ],
  }),
  req({
    name: 'Update Detail Line', method: 'PUT', path: 'finance-transaction/:id/details/:detail_id', pathVars: { id: FT_ID, detail_id: FT_DETAIL_ID },
    body: { amount: 1600000, memo: 'Supplies expense (corrected)' },
    examples: [
      { code: 200, body: ok('Finance transaction detail updated successfully', []) },
      { code: 400, body: badReq('No fields provided for update') },
      { code: 404, body: notFound('Finance transaction detail not found') },
    ],
  }),
  req({
    name: 'Delete Detail Line', method: 'DELETE', path: 'finance-transaction/:id/details/:detail_id', pathVars: { id: FT_ID, detail_id: FT_DETAIL_ID },
    description: 'Soft-delete — no longer appears in list/detail or any report/dashboard aggregation.',
    examples: [
      { code: 200, body: ok('Finance transaction detail deleted successfully', []) },
      { code: 404, body: notFound('Finance transaction detail not found') },
    ],
  }),
], 'Detail lines are also a sub-resource in their own right, separate from the nested details[] on the transaction itself.');

const financeTransactionFolder = folder('Finance Transaction (/finance-transaction)', [...financeTransactionCore, financeTransactionDetails],
  '2-signer dual-approval workflow (keuangan.approve_owner / keuangan.approve_treasury) shared with finance-payment. Multi-account GL splits via details[].');

// ============================= FINANCE PAYMENT ==============================
const FP_ID = FT_ID;
const fpFull = {
  id: FP_ID, company_id: COMPANY_ID, invoice_number: 'INV-2026-0001', paid_amount: 2500000, due_amount: 500000,
  customer_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f', supplier_id: null, payment_date: '2026-07-01', form_number: 'FRM-0011',
  bank_account_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', exchange_rate: 1, cheque_number: 'CHQ-0021', cheque_date: '2026-07-01',
  cheque_amount: 2500000, memo: 'Partial invoice settlement', recipient: 'PT Sumber Makmur', discount_amount: 0,
  transaction_status: 'posted', approved_by_owner_id: '614442bc-476a-4bbb-81f1-b3ccc2523d50', approved_by_owner_at: '2026-07-02 09:15:00',
  approved_by_treasury_id: 'b7d39398-4f4d-4c87-be7c-95b5a9baf56e', approved_by_treasury_at: '2026-07-02 14:30:00',
  created_by: 'Budi Santoso', created_at: '2026-07-01 10:00:00', updated_by: 'Kevin 2', updated_at: '2026-07-02 14:30:00',
  approved_by_owner: 'Kevin Gabriel', approved_by_treasury: 'Kevin 2', deleted_at: null,
};

const financePaymentFolder = folder('Finance Payment (/finance-payment)', [
  req({
    name: 'List Finance Payments', method: 'GET', path: 'finance-payment',
    query: [
      { key: 'page', value: '1' }, { key: 'limit', value: '10', description: 'max 100' },
      { key: 'search', value: '', description: 'Search invoice_number', disabled: true },
      { key: 'customer_id', value: '', disabled: true }, { key: 'supplier_id', value: '', disabled: true },
      { key: 'transaction_status', value: 'posted', description: 'draft|submitted|partially_approved|posted|rejected', disabled: true },
    ],
    examples: [
      { code: 200, body: ok('Finance payments found', paginated([fpFull])) },
      { code: 404, body: notFound('No finance payments found') },
    ],
  }),
  req({
    name: 'Get Outstanding Invoices', method: 'GET', path: 'finance-payment/outstanding-invoices',
    query: [
      { key: 'supplier_id', value: '', description: 'Look up A/P invoices for this supplier', disabled: true },
      { key: 'customer_id', value: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f', description: 'Look up A/R invoices for this customer' },
    ],
    description: 'Exactly one of supplier_id/customer_id must be provided. Not paginated. Populates the invoice-selection table on the A/P and A/R payment-entry screens. Frontend must check status_name === "Approved" before allowing selection.',
    examples: [
      { code: 200, body: ok('Outstanding invoices found', { data: [{ invoice_number: '001/ABC-INV/VII/2026', invoice_date: '2026-07-10', invoice_value: 5000000, paid_amount: 3500000, outstanding: 1500000, purchase_order_id: 'po_64a1b2c3', kurs: 15500, currency_code: 'USD', currency_name: 'US Dollar', status_name: 'Approved' }] }) },
      { code: 400, body: badReq('supplier_id or customer_id is required') },
      { code: 400, name: '400 Bad Request (both provided)', body: badReq('Provide only one of supplier_id or customer_id') },
      { code: 404, body: notFound('Supplier not found') },
      { code: 404, name: '404 Not Found (customer)', body: notFound('Customer not found') },
      { code: 404, name: '404 Not Found (nothing outstanding)', body: notFound('No outstanding invoices found') },
    ],
  }),
  req({
    name: 'Export Finance Payments (.xlsx)', method: 'GET', path: 'finance-payment/export',
    query: [
      { key: 'type', value: 'penerimaan', description: 'penerimaan (customer_id set) | pembayaran (supplier_id set)' },
      { key: 'date_from', value: '2026-07-01' }, { key: 'date_to', value: '2026-07-11' },
      { key: 'transaction_status', value: '', disabled: true },
    ],
    description: 'Streams Penerimaan/Pembayaran as .xlsx. Filename: penerimaan_{date_from}_{date_to}.xlsx or pembayaran_....',
    examples: [
      { code: 200, name: '200 OK (binary .xlsx)', binary: true, contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', body: '<binary .xlsx file>' },
      { code: 400, body: badReq('type must be penerimaan or pembayaran') },
    ],
  }),
  req({
    name: 'Create Finance Payment', method: 'POST', path: 'finance-payment',
    body: {
      invoice_number: 'INV-2026-0001', paid_amount: 2500000, customer_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f',
      due_amount: 500000, payment_date: '2026-07-01', form_number: 'FRM-0011', bank_account_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a',
      exchange_rate: 1, cheque_number: 'CHQ-0021', cheque_date: '2026-07-01', cheque_amount: 2500000,
      memo: 'Partial invoice settlement', recipient: 'PT Sumber Makmur', discount_amount: 0,
    },
    description: 'invoice_number must match an existing, Approved sales_invoice (A/R, no supplier_id) or purchase_invoice (A/P, supplier_id set). Created with transaction_status = draft.',
    examples: [
      { code: 201, body: created('Finance payment created successfully', { finance_payment_id: FP_ID }) },
      { code: 400, body: badReq('invoice_number is required') },
      { code: 400, name: '400 Bad Request (sales invoice not approved)', body: badReq('Sales invoice must be approved before a payment can be recorded') },
      { code: 400, name: '400 Bad Request (purchase invoice not approved)', body: badReq('Purchase invoice must be approved before a payment can be recorded') },
      { code: 404, body: notFound('Sales invoice not found') },
      { code: 404, name: '404 Not Found (purchase invoice)', body: notFound('Purchase invoice not found') },
      { code: 404, name: '404 Not Found (customer)', body: notFound('Customer not found') },
      { code: 404, name: '404 Not Found (supplier)', body: notFound('Supplier not found') },
    ],
  }),
  req({
    name: 'Get Finance Payment Detail', method: 'GET', path: 'finance-payment/:id', pathVars: { id: FP_ID },
    examples: [
      { code: 200, body: ok('Finance payment found', fpFull) },
      { code: 404, body: notFound('Finance payment not found') },
    ],
  }),
  req({
    name: 'Update Finance Payment', method: 'PUT', path: 'finance-payment/:id', pathVars: { id: FP_ID },
    body: { memo: 'Partial invoice settlement (revised)', paid_amount: 3000000 },
    description: 'On update, customer_id/supplier_id/bank_account_id are only re-checked for emptiness, not re-validated for existence. invoice_number is not re-validated against the invoice table on update.',
    examples: [
      { code: 200, body: ok('Finance payment updated successfully', []) },
      { code: 400, body: badReq('No fields provided for update') },
      { code: 404, body: notFound('Finance payment not found') },
    ],
  }),
  req({
    name: 'Delete Finance Payment', method: 'DELETE', path: 'finance-payment/:id', pathVars: { id: FP_ID },
    description: 'Soft-delete, not blocked by transaction_status.',
    examples: [
      { code: 200, body: ok('Finance payment deleted successfully', []) },
      { code: 404, body: notFound('Finance payment not found') },
    ],
  }),
  req({
    name: 'Approve Finance Payment', method: 'PATCH', path: 'finance-payment/:id/approve', pathVars: { id: FP_ID },
    body: { notes: 'Confirmed receipt' },
    description: 'Same 2-signer gate as finance-transaction (keuangan.approve_owner / keuangan.approve_treasury). Covers both A/P and A/R.',
    examples: [
      { code: 200, body: ok('Finance payment approved successfully', { approved_slot: 'owner', transaction_status: 'partially_approved' }) },
      { code: 403, body: forbidden('You do not have permission to approve this record') },
      { code: 404, body: notFound('Record not found') },
      { code: 409, body: conflict('You have already signed this record') },
      { code: 409, name: '409 Conflict (already posted)', body: conflict('Record is already posted') },
    ],
  }),
  req({
    name: 'Reject Finance Payment', method: 'PATCH', path: 'finance-payment/:id/reject', pathVars: { id: FP_ID },
    body: { notes: 'Amount does not match invoice' },
    examples: [
      { code: 200, body: ok('Finance payment rejected successfully', []) },
      { code: 403, body: forbidden('You do not have permission to reject this record') },
      { code: 404, body: notFound('Record not found') },
      { code: 409, body: conflict('Record is already rejected') },
    ],
  }),
], 'Baseline rows are auto-seeded by PATCH /purchase-invoice/{id}/approve and PATCH /sales-invoice/{id}/approve (paid_amount 0, due_amount = invoice total, transaction_status posted) so outstanding-invoices / has_outstanding work even with no manual payment ever recorded.');

// ============================= GENERAL JOURNAL (read-only) =================
const GJ_ID = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';
const gjRow = { id: GJ_ID, company_id: COMPANY_ID, source_module: 'sales_invoice', source_document_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', transaction_date: '2026-07-05', description: 'Sales invoice INV-2026-0001 posted', created_by: 'Budi Santoso', created_at: '2026-07-05 09:00:00', deleted_at: null };
const generalJournalFolder = folder('General Journal (/general-journal)', [
  req({
    name: 'List General Journal Entries', method: 'GET', path: 'general-journal',
    query: [
      { key: 'page', value: '1' }, { key: 'limit', value: '10', description: 'max 100' },
      { key: 'source_module', value: '', disabled: true }, { key: 'source_document_id', value: '', disabled: true },
    ],
    description: 'Read-only. Entries are only ever system-generated (sales/purchase invoice approval, finance payment settlement) — no create/update/delete/approve endpoint exists.',
    examples: [
      { code: 200, body: ok('General journal entries found', paginated([gjRow])) },
      { code: 404, body: notFound('No general journal entries found') },
    ],
  }),
  req({
    name: 'Get General Journal Entry Detail', method: 'GET', path: 'general-journal/:id', pathVars: { id: GJ_ID },
    description: 'Includes nested details[] (per-account-code GL splits) not present on the list row.',
    examples: [
      { code: 200, body: ok('General journal entry found', { ...gjRow, details: [{ id: 'gjd_001', account_code_id: 'acc_010', account_code: '1-1000', account_code_name: 'Cash', amount: 5000000, memo: null }] }) },
      { code: 404, body: notFound('General journal entry not found') },
    ],
  }),
], 'Read-only mirror of finance postings generated by other modules — see v2/helpers/general_journal.php.');

export const financeFolder = folder('Finance', [financeTransactionFolder, financePaymentFolder, generalJournalFolder]);
