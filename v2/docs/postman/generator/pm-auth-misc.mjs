import { req, folder, ok, created, badReq, notFound, forbidden, conflict, serverErr, paginated } from './pm-helpers.mjs';

// ============================= AUTH ========================================
const loginItem = req({
  name: 'Login',
  method: 'POST',
  path: 'account/login',
  noAuth: true,
  description: 'Authenticates an existing Aluria user. Returns a signed JWT (8-hour expiry) and minimal user/company metadata. On success, this request\'s Tests script stores the token into {{access_token}} automatically.',
  body: { email: '{{email}}', password: '{{password}}' },
  examples: [
    {
      code: 200, body: ok('Login successful', {
        token: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...',
        user: { user_id: 'usr_64a1b2c3d4e5f', first_name: 'Budi', last_name: 'Santoso', email: 'budi@example.com', position_id: 'pos_admin_sales', position_name: 'Admin Sales', language: 'id' },
        company: { company_id: 'cmp_xyz789', company_name: 'PT Maju Bersama', days_remaining: 23 },
      }),
    },
    { code: 400, body: badReq('The email field is required.') },
    { code: 400, name: '400 Bad Request (missing password)', body: badReq('The password field is required.') },
    { code: 401, body: { status_code: 401, status_message: 'Invalid email or password', error_code: 'AUTH_001', data: null } },
    { code: 401, name: '401 Unauthorized (not verified)', body: { status_code: 401, status_message: 'Account is not verified yet', error_code: 'AUTH_002', data: null } },
    { code: 401, name: '401 Unauthorized (pending payment)', body: { status_code: 401, status_message: 'Account is pending payment verification', error_code: 'AUTH_003', data: null } },
    { code: 403, body: { status_code: 403, status_message: 'Account has been suspended. Contact your administrator.', error_code: 'AUTH_004', data: null } },
    { code: 403, name: '403 Forbidden (disabled)', body: { status_code: 403, status_message: 'Account has been disabled.', error_code: 'AUTH_005', data: null } },
    { code: 403, name: '403 Forbidden (company inactive)', body: { status_code: 403, status_message: 'Your company account is not active.', error_code: 'AUTH_006', data: null } },
    { code: 429, body: { status_code: 429, status_message: 'Too many login attempts. Please try again in 15 minutes.', error_code: 'RATE_001', data: null } },
    { code: 429, name: '429 Too Many Requests (IP limit)', body: { status_code: 429, status_message: 'Too many login attempts. Please try again later.', error_code: 'RATE_002', data: null } },
    { code: 500, body: badReq('An unexpected error occurred. Please try again.') },
  ],
});
loginItem.event = [
  {
    listen: 'test',
    script: {
      type: 'text/javascript',
      exec: [
        'if (pm.response.code === 200) {',
        '    const json = pm.response.json();',
        '    pm.environment.set("access_token", json.data.token);',
        '    pm.environment.set("company_id", json.data.company.company_id);',
        '    pm.environment.set("user_id", json.data.user.user_id);',
        '    console.log("Saved access_token to environment");',
        '} else {',
        '    console.warn("Login failed — access_token was not updated");',
        '}',
      ],
    },
  },
];

const ssoCallbackItem = req({
  name: 'SSO Login (Movira)',
  method: 'POST',
  path: 'account/sso-callback',
  noAuth: true,
  description: 'Exchanges a Movira SSO code for an Aluria JWT. The frontend receives a `code` (and `state`) after the user authenticates on Movira, then calls this endpoint. The server exchanges the code with Movira\'s `/api/v2/sso/exchange` using X-Client-Id/X-Client-Secret credentials, verifies the user exists in app_user, and issues a JWT — same response shape as Login. Also accepts GET with `?code=...&state=...` for browser redirect callbacks. On success, the Tests script saves the token into {{access_token}}.',
  body: { code: 'ss0_xxxxxxxxxxxxxxxxxxxxxxxx', state: 'random-state-string-12345678' },
  examples: [
    {
      code: 200, body: ok('SSO login successful', {
        token: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...',
        user: { user_id: 'usr_64a1b2c3d4e5f', first_name: 'Budi', last_name: 'Santoso', email: 'budi@example.com', position_id: 'pos_admin_sales', position_name: 'Admin Sales', language: 'id' },
        company: { company_id: 'cmp_xyz789', company_name: 'PT Maju Bersama', days_remaining: 23 },
        permissions: ['dashboard.view_full', 'view.purchase'],
        sso: true,
      }),
    },
    { code: 400, body: badReq('The code field is required.') },
    { code: 401, body: { status_code: 401, status_message: 'The SSO code is invalid. Please try logging in again.', error_code: 'SSO_ERR', data: [], error_id: '6710a1b2-3c4d-7e5f-8a90-123456789abc' } },
    { code: 401, name: '401 Unauthorized (code expired)', body: { status_code: 401, status_message: 'The SSO code has expired. Please try logging in again.', error_code: 'SSO_ERR', data: [], error_id: '6710a1b2-3c4d-7e5f-8a90-123456789abc' } },
    { code: 401, name: '401 Unauthorized (no access)', body: { status_code: 401, status_message: 'Your account does not exist in this application. Contact your administrator.', error_code: 'SSO_ERR', data: [], error_id: '6710a1b2-3c4d-7e5f-8a90-123456789abc' } },
    { code: 403, body: { status_code: 403, status_message: 'You do not have access to this application.', error_code: 'SSO_ERR', data: [], error_id: '6710a1b2-3c4d-7e5f-8a90-123456789abc' } },
    { code: 403, name: '403 Forbidden (suspended)', body: { status_code: 403, status_message: 'Account has been suspended. Contact your administrator.', error_code: 'SSO_ERR', data: [], error_id: '6710a1b2-3c4d-7e5f-8a90-123456789abc' } },
    { code: 403, name: '403 Forbidden (subscription expired)', body: { status_code: 403, status_message: 'Your company subscription is not active or has expired.', error_code: 'SSO_ERR', data: [], error_id: '6710a1b2-3c4d-7e5f-8a90-123456789abc' } },
    { code: 500, body: { status_code: 500, status_message: 'SSO is not configured. Contact your administrator.', data: [], error_id: '6710a1b2-3c4d-7e5f-8a90-123456789abc' } },
    { code: 502, body: { status_code: 502, status_message: 'Unable to reach the identity provider (Movira). Please try again.', data: [], error_id: '6710a1b2-3c4d-7e5f-8a90-123456789abc' } },
  ],
});
ssoCallbackItem.event = [
  {
    listen: 'test',
    script: {
      type: 'text/javascript',
      exec: [
        'if (pm.response.code === 200) {',
        '    const json = pm.response.json();',
        '    pm.environment.set("access_token", json.data.token);',
        '    pm.environment.set("company_id", json.data.company.company_id);',
        '    pm.environment.set("user_id", json.data.user.user_id);',
        '    console.log("Saved access_token from SSO to environment");',
        '} else {',
        '    console.warn("SSO login failed — access_token was not updated");',
        '}',
      ],
    },
  },
];

const authFolder = folder('Auth', [
  loginItem,
  ssoCallbackItem,
  req({
    name: 'Send Registration OTP',
    method: 'POST',
    path: 'account/send-otp',
    noAuth: true,
    description: 'Generates a 6-digit OTP for the "register" purpose and sends it via WhatsApp (WAHA) to the phone number provided. Must be called before POST /account/register.',
    body: { email: 'sari@example.com', phone_number: '081234567890' },
    examples: [
      { code: 200, body: ok('A WhatsApp message with your registration code has been sent to the phone number provided.', null) },
      { code: 400, body: badReq('The email field is required.') },
      { code: 400, name: '400 Bad Request (invalid email)', body: badReq('The email field must be a valid email address (max 50 characters).') },
      { code: 400, name: '400 Bad Request (invalid phone)', body: badReq('The phone_number field must be a valid Indonesian number...') },
      { code: 409, body: { status_code: 409, status_message: 'An account with this email already exists.', error_code: 'REG_003', data: [] } },
      { code: 429, body: { status_code: 429, status_message: 'Too many requests. Please try again later.', error_code: 'RATE_003', data: [] } },
      { code: 500, body: badReq('An unexpected error occurred. Please try again.') },
    ],
  }),
  req({
    name: 'Register',
    method: 'POST',
    path: 'account/register',
    noAuth: true,
    description: 'Registers a new user into an existing company. Requires a valid OTP (sent separately via POST /account/send-otp). First user of a company is auto-verified as Business Owner; subsequent users are created pending approval.',
    body: {
      first_name: 'Sari', last_name: 'Dewi', email: 'sari@example.com', phone_number: '081234567890',
      password: 'S3cur3P@ssw0rd', password_confirmation: 'S3cur3P@ssw0rd', company_code: 'VKN', otp_code: '123456', language: 'id',
    },
    examples: [
      { code: 201, body: created('Registration successful. Your account is pending approval by your company administrator.', { user_id: 'usr_64a1b2c3d4e5f', email: 'sari@example.com', first_name: 'Sari', last_name: 'Dewi', account_status: 'pending', company_name: 'PT Maju Bersama' }) },
      { code: 400, body: badReq('The first_name field is required.') },
      { code: 400, name: '400 Bad Request (password complexity)', body: { status_code: 400, status_message: 'Password must be at least 8 characters and contain uppercase, lowercase, number, and special character.', error_code: 'REG_007', data: [] } },
      { code: 400, name: '400 Bad Request (password mismatch)', body: { status_code: 400, status_message: 'Password and confirmation do not match.', error_code: 'REG_008', data: [] } },
      { code: 400, name: '400 Bad Request (invalid OTP)', body: { status_code: 400, status_message: 'Invalid or expired OTP code.', error_code: 'REG_004', data: [] } },
      { code: 404, body: { status_code: 404, status_message: 'Company not found. Please check your company code.', error_code: 'REG_001', data: [] } },
      { code: 409, body: { status_code: 409, status_message: 'An account with this email already exists.', error_code: 'REG_003', data: [] } },
      { code: 422, body: { status_code: 422, status_message: 'This company account is not active.', error_code: 'REG_002', data: [] } },
      { code: 429, body: { status_code: 429, status_message: 'Too many OTP attempts. Request a new code.', error_code: 'REG_005', data: [] } },
      { code: 500, body: badReq('An unexpected error occurred. Please try again.') },
    ],
  }),
  req({
    name: 'Forgot Password',
    method: 'POST',
    path: 'account/forgot-password',
    noAuth: true,
    description: 'Requests a password-reset OTP via WhatsApp. Always returns the same generic 200 message whether or not the email is registered (anti-enumeration).',
    body: { email: 'budi@example.com' },
    examples: [
      { code: 200, body: ok('If this email is registered, a WhatsApp message with your reset code has been sent to the phone number on file.', null) },
      { code: 400, body: badReq('The email field is required.') },
      { code: 429, body: { status_code: 429, status_message: 'Too many requests. Please try again later.', error_code: 'RATE_003', data: null } },
      { code: 500, body: badReq('An unexpected error occurred. Please try again.') },
    ],
  }),
  req({
    name: 'Reset Password',
    method: 'POST',
    path: 'account/reset-password',
    noAuth: true,
    description: 'Resets the password using the OTP sent by POST /account/forgot-password.',
    body: { email: 'budi@example.com', otp_code: '123456', new_password: 'N3wS3cur3P@ss', new_password_confirmation: 'N3wS3cur3P@ss' },
    examples: [
      { code: 200, body: ok('Password reset successful. Please log in with your new password.', null) },
      { code: 400, body: badReq('The email field is required.') },
      { code: 400, name: '400 Bad Request (invalid/expired OTP)', body: { status_code: 400, status_message: 'Invalid or expired OTP code.', error_code: 'PWD_002', data: [] } },
      { code: 400, name: '400 Bad Request (password policy)', body: { status_code: 400, status_message: 'Password does not meet the required policy.', error_code: 'PWD_004', data: [] } },
      { code: 400, name: '400 Bad Request (mismatch)', body: { status_code: 400, status_message: 'Password and confirmation do not match.', error_code: 'PWD_005', data: [] } },
      { code: 429, body: { status_code: 429, status_message: 'Too many OTP attempts. Request a new code.', error_code: 'PWD_003', data: [] } },
      { code: 500, body: badReq('An unexpected error occurred. Please try again.') },
    ],
  }),
  req({
    name: 'Get My Permissions',
    method: 'GET',
    path: 'account/my-permissions',
    description: 'Returns the flat and grouped permission list granted to the caller\'s role (app_role_id from the JWT).',
    examples: [
      {
        code: 200, body: ok('Permissions retrieved successfully', {
          app_role_id: 'role0a1152b01417134d', role_name: 'Admin Purchase',
          permissions: ['dashboard.view_full', 'view.purchase'],
          modules: {
            Dashboard: [{ permission_key: 'dashboard.view_full', label: 'View Full Dashboard', description: null }],
            Purchase: [{ permission_key: 'view.purchase', label: 'View Purchase', description: null }],
          },
        }),
      },
      { code: 400, body: badReq('No role assigned to this account yet.') },
      { code: 401, body: badReq('Authorization header missing or malformed') },
      { code: 404, body: notFound('Role not found') },
      { code: 405, body: badReq('Method not allowed') },
      { code: 500, body: badReq('An unexpected error occurred. Please try again.') },
    ],
  }),
  req({
    name: 'Get Roles For Permission Key',
    method: 'GET',
    path: 'permissions/:permission_key/roles',
    pathVars: { permission_key: 'sales.so.create' },
    description: 'Returns the role(s) that currently grant a given permission_key. Intended for denial dialogs.',
    examples: [
      { code: 200, body: ok('Roles retrieved successfully', { permission_key: 'sales.so.create', label: 'Create Sales Order', roles: ['Business Owner', 'Admin Sales'] }) },
      { code: 401, body: badReq('Authorization header missing or malformed') },
      { code: 404, body: notFound('Permission not found') },
      { code: 405, body: badReq('Method not allowed') },
      { code: 500, body: badReq('An unexpected error occurred. Please try again.') },
    ],
  }),
  req({
    name: 'Get Roles For Permission Keys (Batch)',
    method: 'GET',
    path: 'permissions/roles',
    query: [{ key: 'keys', value: 'sales.so.create,sales.so.approve', description: 'Comma-separated permission_keys, deduplicated, max 50' }],
    description: 'Batch variant — resolves several permission_keys in a single round-trip. Unknown keys are silently dropped rather than failing the whole request.',
    examples: [
      {
        code: 200, body: ok('Roles retrieved successfully', {
          permissions: [
            { permission_key: 'sales.so.create', label: 'Create Sales Order', roles: ['Business Owner', 'Admin Sales'] },
            { permission_key: 'sales.so.approve', label: 'Approve Sales Order', roles: ['Business Owner'] },
          ],
        }),
      },
      { code: 400, body: badReq('The keys query parameter is required.') },
      { code: 400, name: '400 Bad Request (too many keys)', body: badReq('A maximum of 50 keys can be requested at once.') },
      { code: 401, body: badReq('Authorization header missing or malformed') },
      { code: 405, body: badReq('Method not allowed') },
      { code: 500, body: badReq('An unexpected error occurred. Please try again.') },
    ],
  }),
], 'Authentication, SSO, registration, password recovery, and permission lookups. Base URL for the first 8 requests is /api/v2/account or /api/v2/permissions — no Authorization header required except my-permissions. Run "Login" or "SSO Login" first: both have Tests scripts that auto-save the JWT into {{access_token}} for every other request in this collection.');

// ============================= PROFILE =====================================
const PROFILE_ROW = {
  app_user_id: 'usr_6a4a366caf628', first_name: 'kevin', last_name: 'florentino', email: 'kevingabriel786@gmail.com',
  phone_number: '081144113071', position_id: null, position_name: null, account_status: 'verified',
  employee_profile_id: 'cd5b4d4b-9dff-40ea-b536-47712e20cc66', gender: 'male', birth_date: '1990-05-12',
  address: 'Jl. Mawar No. 5, Jakarta', emergency_contact_name: 'Siti Aminah', emergency_contact_phone: '081234567890',
  employee_code: null, department: null, employment_type: null, join_date: null, supervisor_id: null,
  supervisor_name: null, work_location: null, created_at: '2026-08-13 12:48:39', updated_at: '2026-08-13 12:48:39', is_active: true,
};
const profileFolder = folder('Profile', [
  req({
    name: 'Get My Profile',
    method: 'GET',
    path: 'profile',
    description: 'Self-service only — always acts on the logged-in user\'s own record (from the JWT). Returns combined identity + employment profile.',
    examples: [
      { code: 200, body: ok('Profile found', PROFILE_ROW) },
      { code: 404, body: notFound('Profile not found') },
    ],
  }),
  req({
    name: 'Update My Profile (PUT)',
    method: 'PUT',
    path: 'profile',
    description: 'Update the caller\'s own profile. Only send fields you want to change. Employment fields (employee_code, department, employment_type, join_date, supervisor_id, work_location) are silently ignored — HR-admin managed, not editable here.',
    body: { first_name: 'Kevin', last_name: 'Florentino', phone_number: '081144113071', gender: 'male', birth_date: '1990-05-12', address: 'Jl. Mawar No. 5, Jakarta', emergency_contact_name: 'Siti Aminah', emergency_contact_phone: '081234567890' },
    examples: [
      { code: 200, body: ok('Profile updated successfully', []) },
      { code: 400, body: badReq('gender must be male or female') },
      { code: 400, name: '400 Bad Request (nothing to update)', body: badReq('No fields provided for update') },
      { code: 404, body: notFound('Profile not found') },
    ],
  }),
  req({
    name: 'Update My Profile (PATCH)',
    method: 'PATCH',
    path: 'profile',
    description: 'Identical behavior to PUT /profile — alias method.',
    body: { address: 'Jl. Melati No. 12, Jakarta' },
    examples: [
      { code: 200, body: ok('Profile updated successfully', []) },
      { code: 400, body: badReq('No fields provided for update') },
      { code: 404, body: notFound('Profile not found') },
    ],
  }),
], 'Self-service profile — no {id} path parameter; every endpoint acts on the caller\'s own record resolved from the JWT.');

// ============================= DASHBOARD ====================================
const dashboardFolder = folder('Dashboard', [
  req({
    name: 'Get Dashboard',
    method: 'GET',
    path: 'dashboard',
    description: 'Returns only the widgets the caller\'s role is permitted to see, driven entirely by dashboard.* permission keys. A role with none of these permissions gets "widgets": {} (a normal 200, not an error).',
    examples: [
      {
        code: 200, body: ok('Dashboard found', {
          widgets: {
            revenue_trend: [{ month: '2026-02', revenue: 120000000 }],
            pending_approvals: { purchase_orders: 3, sales_orders: 5 },
          },
        }),
      },
      { code: 400, body: badReq('company_id is required') },
      { code: 400, name: '400 Bad Request (no role)', body: badReq('No role assigned to this account yet.') },
      { code: 405, body: badReq('Method Not Allowed') },
      { code: 500, body: badReq('Internal Server Error') },
    ],
  }),
], 'Single GET /api/v2/dashboard endpoint. Widget visibility is entirely dashboard.* permission-key driven — never by role name. See docs/api/dashboard.md for the full widget catalog per role (Business Owner, Manager, Finance, Accounting, Admin Purchase, Admin Sales, Logistics, Gudang, Kepala Gudang).');

// ============================= ANALYTICS ====================================
const analyticsWindowQuery = [
  { key: 'from', value: '2026-01-01', description: 'YYYY-MM-DD — start of window', disabled: true },
  { key: 'to', value: '2026-07-31', description: 'YYYY-MM-DD — end of window', disabled: true },
];
const analyticsFailureExamples = (permKey) => [
  { code: 400, body: badReq('company_id is required') },
  { code: 400, name: '400 Bad Request (no role)', body: badReq('No role assigned to this account yet.') },
  { code: 400, name: '400 Bad Request (bad date)', body: badReq('from must be YYYY-MM-DD format') },
  { code: 403, body: forbidden('You do not have permission to view this analytics module') },
  { code: 404, body: notFound('Route not found') },
  { code: 405, body: badReq('Method Not Allowed') },
  { code: 500, body: badReq('Internal Server Error') },
];
const analyticsFolder = folder('Analytics', [
  req({
    name: 'Overview Analytics',
    method: 'GET', path: 'analytics/overview', query: analyticsWindowQuery,
    description: 'Requires analytics.overview.view permission.',
    examples: [
      { code: 200, body: ok('Overview analytics found', { summary: { revenue_ytd: 23500479305.75, gross_margin_percent: 16.55, cash_position: 102623591904.07, total_ar: 5434643371.86, total_ap: 12630303.72 }, monthly: [{ month: '2025-09', revenue: 0, purchase_spend: 475624000 }] }) },
      ...analyticsFailureExamples(),
    ],
  }),
  req({
    name: 'Sales Analytics',
    method: 'GET', path: 'analytics/sales', query: analyticsWindowQuery,
    description: 'Requires analytics.sales.view permission.',
    examples: [
      { code: 200, body: ok('Sales analytics found', { summary: { total_sales_ytd: 23500479305.75, target_sales: 5000000000, avg_order_value: 131287593.89 }, monthly: [{ month: '2025-09', total_sales: 0 }], top_products: [{ product_name: 'Steel Coil', total_sales: 8918117250 }], funnel: { so: 100, sppb: 0, delivery: 0, invoice: 0 } }) },
      ...analyticsFailureExamples(),
    ],
  }),
  req({
    name: 'Purchase Analytics',
    method: 'GET', path: 'analytics/purchase', query: analyticsWindowQuery,
    description: 'Requires analytics.purchase.view permission.',
    examples: [
      { code: 200, body: ok('Purchase analytics found', { summary: { total_purchase_ytd: 769427083.38, avg_cycle_time_days: 12.4, import_share_percent: 3.2 }, monthly: [{ month: '2025-09', total_import: 240000, total_local: 475384000 }], supplier_origin: [{ country: 'China', total: 45000000 }], top_products: [{ product_name: 'Aluminum Sheet', total_purchase: 25130344 }], top_suppliers: [{ supplier_name: 'PT Sumber Jaya', total: 93500000 }] }) },
      ...analyticsFailureExamples(),
    ],
  }),
  req({
    name: 'Finance Analytics',
    method: 'GET', path: 'analytics/finance', query: analyticsWindowQuery,
    description: 'Requires analytics.finance.view permission.',
    examples: [
      { code: 200, body: ok('Finance analytics found', { summary: { revenue: 76269670129.39, cogs: 63644661477.32, gross_profit: 12625008652.06, gross_margin_percent: 16.55 }, monthly: [{ month: '2025-09', revenue: 0, cogs: 0, net_profit: 0 }], top_debtors: [{ partner_name: 'PT Contoh Sejahtera', outstanding: 5017761469.08 }], top_creditors: [{ partner_name: 'CV Mitra Abadi', outstanding: 12564922.5 }] }) },
      ...analyticsFailureExamples(),
    ],
  }),
  req({
    name: 'Warehouse Analytics',
    method: 'GET', path: 'analytics/warehouse', query: analyticsWindowQuery,
    description: 'Requires analytics.warehouse.view permission.',
    examples: [
      { code: 200, body: ok('Warehouse analytics found', { monthly: [{ month: '2025-09', total_in: 0, total_out: 0 }], top_movers: [{ product_name: 'Steel Coil', total_qty: 4200 }] }) },
      ...analyticsFailureExamples(),
    ],
  }),
], 'Five read-only, permission-gated endpoints (analytics.{action}.view). Optional from/to (YYYY-MM-DD) windowing params — see docs/api/analytics.md for per-field default-window rules.');

// ============================= SEARCH =======================================
const searchFolder = folder('Search', [
  req({
    name: 'Global Search',
    method: 'GET',
    path: 'search',
    query: [
      { key: 'q', value: 'SO-2026', description: 'Search text, LIKE %q% against each resource\'s name field(s)' },
      { key: 'limit', value: '15', description: 'Max items returned per group (max 15)', disabled: true },
    ],
    description: 'Fans a query out across sales, purchase, master data, and finance records — a compact, grouped preview for a command-palette dropdown. Not paginated; scoped to the caller\'s company.',
    examples: [
      {
        code: 200, body: ok('Search results found', {
          groups: [
            { type: 'sales-order', label: 'Sales Orders', items: [{ id: 'a1b2c3d4-...', title: 'SO-2026-0001', subtitle: 'PT Sumber Baja' }] },
            { type: 'purchase-order', label: 'Purchase Orders', items: [{ id: 'b2c3d4e5-...', title: 'PO-2026-0001', subtitle: 'PT Sumber Baja', type_name: 'Local' }] },
          ],
        }),
      },
      { code: 200, name: '200 OK (no matches)', body: ok('Search results found', { groups: [] }) },
      { code: 400, body: badReq('q is required') },
      { code: 401, body: badReq('Missing or expired Bearer token') },
      { code: 405, body: badReq('Method Not Allowed') },
      { code: 500, body: badReq('Internal server error') },
    ],
  }),
], 'A single GET /api/v2/search across sales-order, sales-invoice, sales-delivery, sales-sppb, purchase-order, purchase-receive, purchase-invoice, customer, supplier, product, finance-transaction, finance-payment. Groups with zero matches are omitted (not returned empty).');

// ============================= AUDIT LOG ====================================
const AUDIT_ROW = { id: 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d', company_id: 'b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e', module: 'purchase_order', reference_id: 'c3d4e5f6-a7b8-4c5d-0e1f-2a3b4c5d6e7f', action: 'created', action_by: 'Budi Santoso', position_name: 'Purchasing Staff', action_at: '2026-07-01 10:00:00', notes: null };
const auditFolder = folder('Audit Log', [
  req({
    name: 'List Audit Logs',
    method: 'GET',
    path: 'audit-log',
    query: [
      { key: 'page', value: '1', description: 'Page number' },
      { key: 'limit', value: '15', description: 'Items per page (max 15)' },
      { key: 'module', value: 'purchase_order', description: 'Filter by module', disabled: true },
      { key: 'reference_id', value: '', description: 'Filter by the ID of the record the entry refers to', disabled: true },
      { key: 'action', value: '', description: 'created | updated | deleted | approved | rejected', disabled: true },
    ],
    description: 'Read-only — this module has no POST/PUT/DELETE. Combine module + reference_id to see the full history of one resource.',
    examples: [
      { code: 200, body: ok('Audit logs found', paginated([AUDIT_ROW])) },
      { code: 404, body: notFound('No audit logs found') },
      { code: 401, body: badReq('Missing or expired Bearer token') },
    ],
  }),
  req({
    name: 'Get Audit Log Detail',
    method: 'GET',
    path: 'audit-log/:id',
    pathVars: { id: AUDIT_ROW.id },
    description: 'Get detail of a single audit log entry.',
    examples: [
      { code: 200, body: ok('Audit log found', AUDIT_ROW) },
      { code: 404, body: notFound('Audit log not found') },
    ],
  }),
], 'Read-only. Rows are written internally by other modules (purchase-order, purchase-receive, purchase-invoice, finance-transaction, finance-payment, sales-order, sales-delivery, sales-invoice, sales-sppb, sales-profit) via insertAuditLog() — never created directly through this API.');

export const authMiscFolders = [authFolder, profileFolder, dashboardFolder, analyticsFolder, searchFolder, auditFolder];
