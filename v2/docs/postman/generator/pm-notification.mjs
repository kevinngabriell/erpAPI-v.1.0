import { req, folder, ok, created, badReq, notFound, forbidden, conflict, paginated } from './pm-helpers.mjs';

const NOTIF_ROW = {
  id: 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d', company_id: 'b2c3d4e5-f6a7-4b5c-9d0e-1f2a3b4c5d6e',
  type: 'approval_pending', source_module: 'sales_order', source_document_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a',
  title: 'Sales Order Menunggu Approval', body: 'SO-2026-0001 butuh approval Anda. Silahkan klik link dibawah untuk menyetujui:',
  target_user_id: 'usr_1a2b3c4d', read_at: null, created_by: 'usr_5e6f7a8b', created_at: '2026-07-21 08:00:00',
  updated_by: null, updated_at: null, deleted_at: null,
};

const notificationCore = folder('Notifications', [
  req({
    name: 'List Notifications',
    method: 'GET',
    path: 'notification',
    query: [
      { key: 'page', value: '1', description: 'Page number' },
      { key: 'limit', value: '10', description: 'Items per page (max 100)' },
      { key: 'unread', value: '1', description: 'Pass 1 to return only unread notifications', disabled: true },
      { key: 'category', value: 'sales', description: 'sales | purchase | finance | warehouse | system', disabled: true },
    ],
    description: 'List in-app notifications belonging to the authenticated user.',
    examples: [
      { code: 200, body: ok('Notifications found', paginated([NOTIF_ROW], 12)) },
      { code: 400, body: badReq('category must be one of: sales, purchase, finance, warehouse, system') },
    ],
  }),
  req({
    name: 'Mark Notification Read',
    method: 'PUT',
    path: 'notification/:id/read',
    pathVars: { id: NOTIF_ROW.id },
    description: 'Mark a single notification as read. Only affects notifications owned by the caller.',
    examples: [
      { code: 200, body: ok('Notification marked as read', []) },
      { code: 404, body: notFound('Notification not found') },
    ],
  }),
  req({
    name: 'Mark All Notifications Read',
    method: 'PUT',
    path: 'notification/read-all',
    description: 'Mark every unread notification belonging to the caller as read.',
    examples: [{ code: 200, body: ok('All notifications marked as read', []) }],
  }),
  req({
    name: 'Get Unread Count',
    method: 'GET',
    path: 'notification/unread-count',
    description: 'Call on app load and after WebSocket reconnect to stay in sync.',
    examples: [{ code: 200, body: ok('Unread count retrieved', { unread_count: 3 }) }],
  }),
], 'GET /api/v2/notification. Real-time push is via WebSocket (ws(s)://.../notification/stream), not polling — see docs/api/notification.md.');

const approvalsFolder = folder('Approvals (one-click links)', [
  req({
    name: 'Validate Approval Token',
    method: 'GET',
    path: 'approvals/:token',
    pathVars: { token: 'apr_9f8e7d6c5b4a' },
    description: 'Validate a one-click approval token (from WhatsApp/digest) and return a document summary. Requires the caller to be logged in as the token\'s intended recipient.',
    examples: [
      {
        code: 200, body: ok('Approval link is valid', {
          source_module: 'finance_transaction', source_document_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a',
          document: {
            id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', document_number: 'FIN-2026-0021', document_date: '2026-07-20',
            status_name: 'partially_approved', created_by: 'usr_5e6f7a8b', created_at: '2026-07-20 09:00:00', created_by_name: 'Budi Santoso',
            dual_approval_progress: { owner_signed: true, owner_name: 'Siti Aminah', treasury_signed: false, treasury_name: null, summary: '1/2 approvers signed' },
          },
        }),
      },
      { code: 403, body: forbidden('This approval link was not issued to your account') },
      { code: 409, body: conflict('This approval link has already been used') },
      { code: 409, name: '409 Conflict (already actioned)', body: { status_code: 409, status_message: 'This document has already been Approved — nothing left to do here', data: { source_module: 'sales_order', source_document_id: 'd4e5f6a7-b8c9-4d5e-1f2a-3b4c5d6e7f8a', document: { '...': '...' } } } },
      { code: 410, body: { status_code: 410, status_message: 'This approval link has expired', data: [] } },
      { code: 404, body: notFound('Approval link not found') },
    ],
  }),
  req({
    name: 'Approve Via Token',
    method: 'POST',
    path: 'approvals/:token/approve',
    pathVars: { token: 'apr_9f8e7d6c5b4a' },
    body: { notes: 'Approved via WhatsApp link' },
    description: 'Approve the document a token points to — internally calls the same PATCH /{module}/{id}/approve endpoint the normal approve button uses, so the response shape matches that module\'s own approve response.',
    examples: [
      { code: 200, body: ok('Finance transaction approved successfully', { approved_slot: 'owner', transaction_status: 'partially_approved' }) },
      { code: 403, body: forbidden('This approval link was not issued to your account') },
      { code: 404, body: notFound('Approval link not found') },
      { code: 409, body: conflict('This approval link has already been used') },
      { code: 410, body: { status_code: 410, status_message: 'This approval link has expired', data: [] } },
    ],
  }),
  req({
    name: 'Reject Via Token',
    method: 'POST',
    path: 'approvals/:token/reject',
    pathVars: { token: 'apr_9f8e7d6c5b4a' },
    body: { notes: 'Rejected — please recheck the quantities' },
    description: 'Reject the document a token points to. Same mechanics as approve, calling PATCH /{module}/{id}/reject internally. reason is accepted as an alias for notes.',
    examples: [
      { code: 200, body: ok('Sales order rejected successfully', []) },
      { code: 403, body: forbidden('This approval link was not issued to your account') },
      { code: 404, body: notFound('Approval link not found') },
      { code: 409, body: conflict('This approval link has already been used') },
      { code: 410, body: { status_code: 410, status_message: 'This approval link has expired', data: [] } },
    ],
  }),
], 'One-click WhatsApp/digest approval links. Scope: Sales Order, Purchase Order, Purchase Invoice, Purchase Receive, Finance Transaction, Finance Payment. Tokens are single-use, per-user, 72h TTL.');

const settingsFolder = folder('Notification Settings', [
  req({
    name: 'List Public Holidays',
    method: 'GET',
    path: 'notification-settings/public-holidays',
    query: [{ key: 'year', value: '2026', description: 'Filter to holidays in this calendar year', disabled: true }],
    description: 'Lists national holidays (company_id null) plus any company-specific rows.',
    examples: [
      { code: 200, body: ok('Public holidays found', [{ id: 'h1a2b3c4', company_id: null, holiday_date: '2026-08-17', label: 'Hari Kemerdekaan RI', created_by: 'usr_5e6f7a8b', created_at: '2026-01-05 09:00:00', deleted_at: null }]) },
    ],
  }),
  req({
    name: 'Add Public Holiday',
    method: 'POST',
    path: 'notification-settings/public-holidays',
    body: { holiday_date: '2026-12-25', label: 'Hari Natal', company_specific: false },
    description: 'Add a public holiday. company_specific true scopes it to only the caller\'s company (default false = national, visible to all companies).',
    examples: [
      { code: 201, body: created('Public holiday created successfully', { id: 'h1a2b3c4' }) },
      { code: 409, body: conflict('A holiday already exists for this date') },
    ],
  }),
  req({
    name: 'Delete Public Holiday',
    method: 'DELETE',
    path: 'notification-settings/public-holidays/:id',
    pathVars: { id: 'h1a2b3c4' },
    description: 'Remove a public holiday (soft delete via deleted_at).',
    examples: [
      { code: 200, body: ok('Public holiday deleted successfully', []) },
      { code: 404, body: notFound('Public holiday not found') },
    ],
  }),
  req({
    name: 'Get Working Days',
    method: 'GET',
    path: 'notification-settings/working-days',
    description: 'Get the company\'s working-days config used by the daily digest. Defaults to Mon-Fri when never configured.',
    examples: [{ code: 200, body: ok('Working days retrieved', { working_days: ['MON', 'TUE', 'WED', 'THU', 'FRI'] }) }],
  }),
  req({
    name: 'Update Working Days',
    method: 'PUT',
    path: 'notification-settings/working-days',
    body: { working_days: ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'] },
    description: 'working_days must be a non-empty array of MON|TUE|WED|THU|FRI|SAT|SUN.',
    examples: [
      { code: 200, body: ok('Working days updated successfully', { working_days: ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'] }) },
      { code: 400, body: badReq('working_days must be a non-empty array') },
    ],
  }),
], 'Per-company scheduling config for the notification digest/reminder system.');

export const notificationFolder = folder('Notification', [notificationCore, approvalsFolder, settingsFolder],
  'Base URLs: /api/v2/notification, /api/v2/approvals, /api/v2/notification-settings. All endpoints require Authorization: Bearer <access_token>.');
