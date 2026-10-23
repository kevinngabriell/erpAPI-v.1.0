import fs from 'node:fs';
import { uid } from './pm-helpers.mjs';
import { masterGlobalFolder } from './pm-master-global.mjs';
import { masterTenantFolder } from './pm-master-tenant.mjs';
import { authMiscFolders } from './pm-auth-misc.mjs';
import { notificationFolder } from './pm-notification.mjs';
import { reportsFolder } from './pm-reports.mjs';
import { warehouseFolder, hrFolder } from './pm-warehouse-hr.mjs';
import { financeFolder } from './pm-finance.mjs';
import { purchaseFolder } from './pm-purchase.mjs';
import { salesFolder } from './pm-sales.mjs';

const [authFolder, profileFolder, dashboardFolder, analyticsFolder, searchFolder, auditFolder] = authMiscFolders;

const masterDataFolder = {
  name: 'Master Data',
  description: 'Master/reference data — 24 modules split into global lookups (shared across all companies) and tenant-scoped resources.',
  item: [masterGlobalFolder, masterTenantFolder],
};

const rootItems = [
  authFolder,
  dashboardFolder,
  analyticsFolder,
  searchFolder,
  profileFolder,
  notificationFolder,
  masterDataFolder,
  purchaseFolder,
  salesFolder,
  financeFolder,
  warehouseFolder,
  hrFolder,
  reportsFolder,
  auditFolder,
];

function countRequests(items) {
  let n = 0;
  for (const it of items) {
    if (it.item) n += countRequests(it.item);
    else if (it.request) n += 1;
  }
  return n;
}
const totalRequests = countRequests(rootItems);
console.log('Total requests:', totalRequests);

const collection = {
  info: {
    _postman_id: uid(),
    name: 'Aluria ERP API v2',
    description:
      'Complete Postman collection for the Aluria/Venken ERP v2 API (erpAPI-v.1.0/v2) — generated from docs/api/*.md. ' +
      'Every request includes at least one saved example response taken directly from the source documentation ' +
      '(200/201 success plus the documented 400/401/403/404/405/409/410/429/500 failure cases where applicable). ' +
      '\n\n## Getting started\n' +
      '1. Select (or duplicate) the **Aluria ERP v2 - Local** environment and set `base_url` to your running instance ' +
      '(default assumes `php -S localhost:8000 router.php` from the project root, which the repo\'s router.php supports directly — ' +
      'adjust to your actual host, e.g. an nginx vhost proxying `/api/v2/...` or `/venken-api/v2/...`).\n' +
      '2. Set `email` / `password` in the environment to a real account, then run **Auth → Login**. ' +
      'Its Tests script automatically saves the returned JWT into `access_token`, `company_id`, and `user_id`.\n' +
      '3. Every other request in this collection inherits Bearer auth from the collection level ' +
      '(`Authorization: Bearer {{access_token}}`) — no per-request auth setup needed. The 6 public account/permission ' +
      'endpoints (login, send-otp, register, forgot-password, reset-password) are explicitly marked "No Auth".\n' +
      '4. Path parameters (`:customer_id`, `:id`, etc.) are pre-filled with realistic example values taken from the docs — ' +
      'edit them per-request (Postman\'s "Path Variables" tab under each request\'s URL) once you have real IDs from your own data.\n\n' +
      '## Response shape\nEvery endpoint returns `{ status_code, status_message, data }` (see CODE_STANDARDS.md). ' +
      'Auth/account endpoints additionally return an `error_code` on failures, for frontend localisation.\n\n' +
      'Source: v2/docs/api/*.md (52 module docs) plus v2/auth/{forgot-password,reset-password}.php and v2/finance/general-journal/index.php read directly (undocumented in docs/api at generation time).',
    schema: 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
  },
  auth: {
    type: 'bearer',
    bearer: [{ key: 'token', value: '{{access_token}}', type: 'string' }],
  },
  event: [],
  variable: [
    { key: 'base_url', value: 'http://localhost:8000/api/v2', type: 'string' },
  ],
  item: rootItems,
};

const environment = {
  id: uid(),
  name: 'Aluria ERP v2 - Local',
  values: [
    { key: 'base_url', value: 'http://localhost:8000/api/v2', type: 'default', enabled: true,
      description: 'php -S localhost:8000 router.php from the project root; adjust to your nginx/Apache vhost (e.g. https://your-host/api/v2 or https://your-host/venken-api/v2) as needed.' },
    { key: 'email', value: 'budi@example.com', type: 'default', enabled: true, description: 'Login email used by Auth > Login' },
    { key: 'password', value: 'ChangeMe123!', type: 'secret', enabled: true, description: 'Login password used by Auth > Login' },
    { key: 'access_token', value: '', type: 'secret', enabled: true, description: 'Auto-populated by Auth > Login Tests script — do not set manually' },
    { key: 'company_id', value: '', type: 'default', enabled: true, description: 'Auto-populated by Auth > Login Tests script' },
    { key: 'user_id', value: '', type: 'default', enabled: true, description: 'Auto-populated by Auth > Login Tests script' },
  ],
  _postman_variable_scope: 'environment',
};

const outDir = process.argv[2] || '.';
fs.writeFileSync(`${outDir}/erpAPI-v2.postman_collection.json`, JSON.stringify(collection, null, 2));
fs.writeFileSync(`${outDir}/erpAPI-v2.postman_environment.json`, JSON.stringify(environment, null, 2));
console.log('Wrote collection and environment to', outDir);
