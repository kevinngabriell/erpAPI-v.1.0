<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/notification.php';

// module → [display table, id column doesn't vary, endpoint URL segment]
const APPROVAL_MODULE_ENDPOINTS = [
    'sales_order'         => 'sales-order',
    'purchase_order'      => 'purchase-order',
    'finance_transaction' => 'finance-transaction',
    'finance_payment'     => 'finance-payment',
];

function getApprovalToken($conn, $token, $company_id, $username) {
    $token = mysqli_real_escape_string($conn, $token);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".approval_action_token
            WHERE id = '$token' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Approval link not found');
        return null;
    }

    $row = mysqli_fetch_assoc($result);

    if ($row['target_user_id'] !== $username) {
        jsonResponse(403, 'This approval link was not issued to your account');
        return null;
    }
    if ($row['used_at'] !== null) {
        jsonResponse(409, 'This approval link has already been used');
        return null;
    }
    if (strtotime($row['expires_at']) < time()) {
        jsonResponse(410, 'This approval link has expired');
        return null;
    }

    return $row;
}

function getApprovalDocumentSummary($conn, $source_module, $source_document_id, $company_id) {
    $source_document_id = mysqli_real_escape_string($conn, $source_document_id);

    switch ($source_module) {
        case 'sales_order':
            $result = mysqli_query($conn, "SELECT so.id, so.so_display_number AS document_number, so.so_date AS document_date,
                    ss.status_name, so.created_by, so.created_at
                    FROM " . APP_SCHEMA . ".sales_order so
                    LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = so.status_id
                    WHERE so.id = '$source_document_id' AND so.company_id = '$company_id' AND so.deleted_at IS NULL LIMIT 1");
            break;
        case 'purchase_order':
            $result = mysqli_query($conn, "SELECT po.id, po.po_display_number AS document_number, po.po_date AS document_date,
                    ps.status_name, po.created_by, po.created_at
                    FROM " . APP_SCHEMA . ".purchase_order po
                    LEFT JOIN " . APP_SCHEMA . ".purchase_status ps ON ps.id = po.status_id
                    WHERE po.id = '$source_document_id' AND po.company_id = '$company_id' AND po.deleted_at IS NULL LIMIT 1");
            break;
        case 'finance_transaction':
            $result = mysqli_query($conn, "SELECT ft.id, ft.voucher_number AS document_number, ft.transaction_date AS document_date,
                    ft.transaction_status AS status_name, ft.created_by, ft.created_at,
                    ft.approved_by_owner_id, ft.approved_by_owner_at, ft.approved_by_treasury_id, ft.approved_by_treasury_at
                    FROM " . APP_SCHEMA . ".finance_transaction ft
                    WHERE ft.id = '$source_document_id' AND ft.company_id = '$company_id' AND ft.deleted_at IS NULL LIMIT 1");
            break;
        case 'finance_payment':
            $result = mysqli_query($conn, "SELECT fp.id, fp.invoice_number AS document_number, fp.payment_date AS document_date,
                    fp.transaction_status AS status_name, fp.created_by, fp.created_at,
                    fp.approved_by_owner_id, fp.approved_by_owner_at, fp.approved_by_treasury_id, fp.approved_by_treasury_at
                    FROM " . APP_SCHEMA . ".finance_payment fp
                    WHERE fp.id = '$source_document_id' AND fp.company_id = '$company_id' AND fp.deleted_at IS NULL LIMIT 1");
            break;
        default:
            return null;
    }

    if (!$result || mysqli_num_rows($result) === 0) return null;

    $document = mysqli_fetch_assoc($result);
    $document['created_by_name'] = resolveDisplayName($conn, $document['created_by']);

    if (in_array($source_module, ['finance_transaction', 'finance_payment'], true)) {
        $owner_signed    = $document['approved_by_owner_id'] !== null;
        $treasury_signed = $document['approved_by_treasury_id'] !== null;
        $document['dual_approval_progress'] = [
            'owner_signed'      => $owner_signed,
            'owner_name'        => $owner_signed ? resolveDisplayName($conn, $document['approved_by_owner_id']) : null,
            'treasury_signed'   => $treasury_signed,
            'treasury_name'     => $treasury_signed ? resolveDisplayName($conn, $document['approved_by_treasury_id']) : null,
            'summary'           => (($owner_signed ? 1 : 0) + ($treasury_signed ? 1 : 0)) . '/2 approvers signed',
        ];
    }

    return $document;
}

function viewApproval($conn, $token, $company_id, $username) {
    $token_row = getApprovalToken($conn, $token, $company_id, $username);
    if (!$token_row) return;

    $document = getApprovalDocumentSummary($conn, $token_row['source_module'], $token_row['source_document_id'], $company_id);
    if (!$document) {
        jsonResponse(404, 'The document this link points to no longer exists');
        return;
    }

    $terminal_statuses = ['Approved', 'Rejected', 'posted', 'rejected'];
    if (in_array($document['status_name'], $terminal_statuses, true)) {
        jsonResponse(409, "This document has already been {$document['status_name']} — nothing left to do here", [
            'source_module'      => $token_row['source_module'],
            'source_document_id' => $token_row['source_document_id'],
            'document'           => $document,
        ]);
        return;
    }

    jsonResponse(200, 'Approval link is valid', [
        'source_module'      => $token_row['source_module'],
        'source_document_id' => $token_row['source_document_id'],
        'document'           => $document,
    ]);
}

// Approve/reject re-uses the exact same endpoint the normal UI calls
// (Aluria-Notification-Module-Spec.md §4.4 point 6) via an internal HTTP
// call carrying the caller's own bearer token — so audit trail/history stays
// identical to approving through the form, with zero duplicated logic.
function executeApproval($conn, $token, $sub_action, $input, $company_id, $username) {
    $token_row = getApprovalToken($conn, $token, $company_id, $username);
    if (!$token_row) return;

    $endpoint_segment = APPROVAL_MODULE_ENDPOINTS[$token_row['source_module']] ?? null;
    if (!$endpoint_segment) {
        jsonResponse(500, 'Unsupported source_module on this token');
        return;
    }

    $scheme = (($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
    $url    = "$scheme://{$_SERVER['HTTP_HOST']}/api/v2/$endpoint_segment/{$token_row['source_document_id']}/$sub_action";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
        ],
        CURLOPT_POSTFIELDS => json_encode(['notes' => $input['notes'] ?? $input['reason'] ?? null]),
        CURLOPT_TIMEOUT    => 15,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);

    if ($http_code >= 200 && $http_code < 300) {
        $now = date('Y-m-d H:i:s');
        mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".approval_action_token SET used_at = '$now'
                WHERE id = '" . mysqli_real_escape_string($conn, $token) . "'");
        invalidateApprovalTokens($conn, $token_row['source_module'], $token_row['source_document_id'], $token);
    }

    jsonResponse($http_code ?: 500, $decoded['status_message'] ?? 'Failed to reach approval endpoint', $decoded['data'] ?? []);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;
$username   = $authUser['user_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

$token      = !empty($action) ? $action : null;
$sub_action = $parts[4] ?? '';

if (!$token) {
    jsonResponse(404, 'Route not found');
    exit;
}

try {
    $conn = getConn();

    if ($sub_action === '') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        viewApproval($conn, $token, $company_id, $username);

    } elseif (in_array($sub_action, ['approve', 'reject'], true)) {
        if ($method !== 'POST') { jsonResponse(405, 'Method Not Allowed'); }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        executeApproval($conn, $token, $sub_action, $input, $company_id, $username);

    } else {
        jsonResponse(404, 'Route not found');
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
