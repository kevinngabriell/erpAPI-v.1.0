import { req, folder, ok, badReq, notFound } from './pm-helpers.mjs';

const dateRangeQuery = (fromDefault = '', toDefault = '') => [
  { key: 'date_from', value: fromDefault || '2026-07-01', description: 'YYYY-MM-DD (default: first day of current month)' },
  { key: 'date_to', value: toDefault || '2026-07-11', description: 'YYYY-MM-DD (default: today)' },
];
const reportErrors = () => [
  { code: 400, body: badReq('company_id is required') },
  { code: 401, body: badReq('Missing or expired Bearer token') },
  { code: 405, body: badReq('Method Not Allowed') },
  { code: 500, body: badReq('Internal server error') },
];
const binaryXlsx = (filename) => ({
  code: 200, name: '200 OK (binary .xlsx)', binary: true,
  contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  body: `<binary .xlsx file — Content-Disposition: attachment; filename="${filename}">`,
});

// ---- Sales Report -----------------------------------------------------
const salesReportFolder = folder('Sales Report', [
  req({
    name: 'Get Sales Report', method: 'GET', path: 'sales-report', query: dateRangeQuery(),
    description: 'Omset/sales report for a date range: total omset, monthly trend, top 10 products, top 10 customers.',
    examples: [
      { code: 200, body: ok('Sales report found', { date_from: '2026-07-01', date_to: '2026-07-11', summary: { total_omset: 125000000, total_invoices: 18 }, monthly_trend: [{ month: '2026-07', revenue: 125000000 }], top_products: [{ product_name: 'Steel Rod 12mm', quantity: 500, revenue: 27500000 }], top_customers: [{ customer_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', customer_name: 'PT Sumber Makmur', total: 40000000 }] }) },
      ...reportErrors(),
    ],
  }),
  req({
    name: 'Export Sales Report (.xlsx)', method: 'GET', path: 'sales-report/export', query: dateRangeQuery(),
    description: 'Same data as GET /sales-report, streamed as .xlsx. Filename: penjualan_{date_from}_{date_to}.xlsx',
    examples: [binaryXlsx('penjualan_2026-07-01_2026-07-11.xlsx'), ...reportErrors()],
  }),
], 'Read-only. Base URL: /api/v2/sales-report.');

// ---- AR/AP Report -------------------------------------------------------
const arApFolder = folder('AR/AP Report', [
  req({
    name: 'Get AR/AP Report', method: 'GET', path: 'ar-ap-report',
    query: [
      { key: 'type', value: 'all', description: 'ar | ap | all' },
      { key: 'bucket', value: '', description: 'current | 30 | 60 | 90 | 90+', disabled: true },
      { key: 'search', value: '', description: 'Match partner name or invoice_number', disabled: true },
      { key: 'page', value: '1', description: 'Page number' },
      { key: 'limit', value: '10', description: 'Items per page (max 100)' },
    ],
    description: 'Outstanding receivables (AR) and payables (AP), aged into buckets, paginated, with bucket-total summaries.',
    examples: [
      { code: 200, body: ok('AR/AP report found', { data: [{ invoice_number: 'INV-2026-0042', partner_name: 'PT Sumber Makmur', partner_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', invoice_date: '2026-05-01', outstanding: 15000000, days_overdue: 45, bucket: '60', type: 'ar' }], pagination: { total: 23, page: 1, limit: 10, total_pages: 3 }, summary: { receivables: { current: 5000000, '30': 3000000, '60': 15000000, '90': 0, '90+': 2000000 }, payables: { current: 1000000, '30': 0, '60': 0, '90': 0, '90+': 0 } } }) },
      ...reportErrors(),
    ],
  }),
  req({
    name: 'Export AR/AP Report (.xlsx)', method: 'GET', path: 'ar-ap-report/export',
    query: [{ key: 'type', value: 'all' }, { key: 'bucket', value: '', disabled: true }, { key: 'search', value: '', disabled: true }],
    description: 'Same data, unpaginated, streamed as .xlsx. Filename: piutang_hutang_{YYYY-MM-DD}.xlsx',
    examples: [binaryXlsx('piutang_hutang_2026-07-11.xlsx'), ...reportErrors()],
  }),
], 'Read-only. Base URL: /api/v2/ar-ap-report.');

// ---- Profit & Loss -------------------------------------------------------
const profitLossFolder = folder('Profit & Loss Report', [
  req({
    name: 'Get Profit & Loss Report', method: 'GET', path: 'profit-loss', query: dateRangeQuery(),
    description: 'Revenue, expense, and net profit for a date range, broken down per account_code.',
    examples: [
      { code: 200, body: ok('Profit and loss report found', { date_from: '2026-07-01', date_to: '2026-07-11', revenue: { total: 125000000, by_account: [{ account_code_id: 'acc_001', account_code: '4-1000', account_code_name: 'Sales Revenue', total: 125000000 }] }, expense: { total: 45000000, by_account: [{ account_code_id: 'acc_050', account_code: '6-1000', account_code_name: 'Operating Expense', total: 45000000 }] }, net_profit: 80000000 }) },
      ...reportErrors(),
    ],
  }),
  req({
    name: 'Export Profit & Loss Report (.xlsx)', method: 'GET', path: 'profit-loss/export', query: dateRangeQuery(),
    description: 'Streamed as .xlsx. Filename: laba_rugi_{date_from}_{date_to}.xlsx',
    examples: [binaryXlsx('laba_rugi_2026-07-01_2026-07-11.xlsx'), ...reportErrors()],
  }),
], 'Read-only. Base URL: /api/v2/profit-loss.');

// ---- Balance Sheet --------------------------------------------------------
const balanceSheetFolder = folder('Balance Sheet Report', [
  req({
    name: 'Get Balance Sheet', method: 'GET', path: 'balance-sheet',
    query: [{ key: 'as_of_date', value: '2026-07-11', description: 'YYYY-MM-DD, inclusive (default: today)' }],
    description: 'Asset, liability, and equity balances as of a given date, broken down per account_code.',
    examples: [
      { code: 200, body: ok('Balance sheet report found', { as_of_date: '2026-07-11', asset: { total: 500000000, by_account: [{ account_code_id: 'acc_010', account_code: '1-1000', account_code_name: 'Cash', total: 500000000 }] }, liability: { total: 120000000, by_account: [{ account_code_id: 'acc_020', account_code: '2-1000', account_code_name: 'Accounts Payable', total: 120000000 }] }, equity: { total: 380000000, by_account: [{ account_code_id: 'acc_030', account_code: '3-1000', account_code_name: "Owner's Capital", total: 380000000 }] } }) },
      ...reportErrors(),
    ],
  }),
  req({
    name: 'Export Balance Sheet (.xlsx)', method: 'GET', path: 'balance-sheet/export',
    query: [{ key: 'as_of_date', value: '2026-07-11' }],
    description: 'Streamed as .xlsx. Filename: neraca_{as_of_date}.xlsx',
    examples: [binaryXlsx('neraca_2026-07-11.xlsx'), ...reportErrors()],
  }),
], 'Read-only. Base URL: /api/v2/balance-sheet.');

// ---- General Ledger -------------------------------------------------------
const glListQuery = [...dateRangeQuery(), { key: 'page', value: '1' }, { key: 'limit', value: '10', description: 'max 100' }];
const generalLedgerFolder = folder('General Ledger Report', [
  req({
    name: 'List General Ledger (per account)', method: 'GET', path: 'general-ledger', query: glListQuery,
    description: 'Per-account opening balance, period movement, and closing balance for a date range.',
    examples: [
      { code: 200, body: ok('General ledger found', { date_from: '2026-07-01', date_to: '2026-07-11', data: [{ account_code_id: 'acc_010', account_code: '1-1000', account_code_name: 'Cash', account_type: 'asset', opening_balance: 480000000, period_movement: 20000000, closing_balance: 500000000 }], pagination: { total: 32, page: 1, limit: 10, total_pages: 4 } }) },
      ...reportErrors(),
    ],
  }),
  req({
    name: 'Export General Ledger (.xlsx)', method: 'GET', path: 'general-ledger/export', query: dateRangeQuery(),
    description: 'Streamed as .xlsx. Filename: buku_besar_{date_from}_{date_to}.xlsx',
    examples: [binaryXlsx('buku_besar_2026-07-01_2026-07-11.xlsx'), ...reportErrors()],
  }),
  req({
    name: 'Get General Ledger Account Detail', method: 'GET', path: 'general-ledger/:account_code_id',
    pathVars: { account_code_id: 'acc_010' },
    query: [...dateRangeQuery(), { key: 'page', value: '1' }, { key: 'limit', value: '20', description: 'max 100' }],
    description: 'Transaction-level detail for a single account within a date range, with a running balance.',
    examples: [
      { code: 200, body: ok('General ledger account found', { account_code_id: 'acc_010', account_code: '1-1000', account_code_name: 'Cash', account_type: 'asset', date_from: '2026-07-01', date_to: '2026-07-11', opening_balance: 480000000, transactions: [{ id: 'f6a7b8c9-d0e1-4f5a-3b4c-5d6e7f8a9b0c', transaction_date: '2026-07-03', voucher_number: 'V-2026-0301', memo: 'Customer payment received', account_amount: 20000000, running_balance: 500000000 }], closing_balance: 500000000, pagination: { total: 1, page: 1, limit: 20, total_pages: 1 } }) },
      { code: 404, body: notFound('Account code not found') },
      ...reportErrors(),
    ],
  }),
  req({
    name: 'Export General Ledger Account Detail (.xlsx)', method: 'GET', path: 'general-ledger/:account_code_id/export',
    pathVars: { account_code_id: 'acc_010' }, query: dateRangeQuery(),
    description: 'Streamed as .xlsx. Filename: buku_besar_{account_code}_{date_from}_{date_to}.xlsx',
    examples: [binaryXlsx('buku_besar_1-1000_2026-07-01_2026-07-11.xlsx'), { code: 404, body: notFound('Account code not found') }, ...reportErrors()],
  }),
], 'Read-only. Base URL: /api/v2/general-ledger. Each row in the detail transactions[] is a finance_transaction_detail line, not a header row.');

// ---- Cash Book ----------------------------------------------------------
const cashBookFolder = folder('Cash Book Report', [
  req({
    name: 'List Cash Book (per bank account)', method: 'GET', path: 'cash-book', query: glListQuery,
    description: 'Per-bank-account opening balance, period movement, and closing balance for a date range.',
    examples: [
      { code: 200, body: ok('Cash books found', { date_from: '2026-07-01', date_to: '2026-07-19', data: [{ bank_account_id: '8dac152c-7ba0-11f1-93b5-525400d7fdd0', bank_name: 'KAS KECIL', bank_number: '000-1234-5678', opening_balance: -18544221.8, period_movement: 2500000, closing_balance: -16044221.8 }], pagination: { total: 6, page: 1, limit: 10, total_pages: 1 } }) },
      ...reportErrors(),
    ],
  }),
  req({
    name: 'Export Cash Book (.xlsx)', method: 'GET', path: 'cash-book/export', query: dateRangeQuery(),
    description: 'Streamed as .xlsx. Filename: buku_kas_{date_from}_{date_to}.xlsx',
    examples: [binaryXlsx('buku_kas_2026-07-01_2026-07-19.xlsx'), ...reportErrors()],
  }),
  req({
    name: 'Get Cash Book Detail (per bank account)', method: 'GET', path: 'cash-book/:bank_account_id',
    pathVars: { bank_account_id: '8dac152c-7ba0-11f1-93b5-525400d7fdd0' },
    query: [...dateRangeQuery(), { key: 'page', value: '1' }, { key: 'limit', value: '20', description: 'max 100' }],
    description: 'Transaction-level cash book detail for one bank account, with running balance. Combines finance_transaction and finance_payment rows for that account.',
    examples: [
      { code: 200, body: ok('Cash book found', { bank_account_id: '8dac152c-7ba0-11f1-93b5-525400d7fdd0', bank_name: 'KAS KECIL', bank_number: '000-1234-5678', date_from: '2026-07-01', date_to: '2026-07-19', opening_balance: -18544221.8, transactions: [{ transaction_date: '2026-07-03', reference_number: 'V-2026-0301', cheque_number: null, party_name: 'PT Sinar Jaya', memo: 'Petty cash top-up', description: 'Cash', signed_amount: 2500000, running_balance: -16044221.8 }], closing_balance: -16044221.8, pagination: { total: 1, page: 1, limit: 20, total_pages: 1 } }) },
      { code: 404, body: notFound('Bank account not found') },
      ...reportErrors(),
    ],
  }),
  req({
    name: 'Export Cash Book Detail (.xlsx)', method: 'GET', path: 'cash-book/:bank_account_id/export',
    pathVars: { bank_account_id: '8dac152c-7ba0-11f1-93b5-525400d7fdd0' }, query: dateRangeQuery(),
    description: 'Streamed as .xlsx. Filename: buku_kas_{bank_name}_{date_from}_{date_to}.xlsx',
    examples: [binaryXlsx('buku_kas_KAS_KECIL_2026-07-01_2026-07-19.xlsx'), { code: 404, body: notFound('Bank account not found') }, ...reportErrors()],
  }),
], 'Read-only. Base URL: /api/v2/cash-book.');

// ---- COGS Report -----------------------------------------------------
const cogsFolder = folder('COGS Report', [
  req({
    name: 'Get COGS Report', method: 'GET', path: 'cogs-report', query: [...dateRangeQuery(), { key: 'page', value: '1' }, { key: 'limit', value: '10', description: 'max 100' }],
    description: 'HPP (COGS) & gross profit per product for a date range, filtered on sales_profit.created_at.',
    examples: [
      { code: 200, body: ok('COGS report found', { date_from: '2026-07-01', date_to: '2026-07-11', data: [{ product_name: 'Steel Rod 12mm', quantity_sold: 500, revenue: 27500000, cogs: 25000000, gross_profit: 2500000, margin_percent: 9.09 }], pagination: { total: 14, page: 1, limit: 10, total_pages: 2 }, summary: { total_revenue: 125000000, total_cogs: 100000000, total_gross_profit: 25000000, overall_margin_percent: 20.0 } }) },
      ...reportErrors(),
    ],
  }),
  req({
    name: 'Export COGS Report (.xlsx)', method: 'GET', path: 'cogs-report/export', query: dateRangeQuery(),
    description: 'Streamed as .xlsx. Filename: hpp_{date_from}_{date_to}.xlsx',
    examples: [binaryXlsx('hpp_2026-07-01_2026-07-11.xlsx'), ...reportErrors()],
  }),
], 'Read-only. Base URL: /api/v2/cogs-report.');

export const reportsFolder = folder('Reports', [
  salesReportFolder, arApFolder, profitLossFolder, balanceSheetFolder, generalLedgerFolder, cashBookFolder, cogsFolder,
], 'All reporting endpoints are read-only (no POST/PUT/DELETE). Every /export variant streams a binary .xlsx — Postman will show it as a download when actually invoked; the saved examples here are placeholders describing the file.');
