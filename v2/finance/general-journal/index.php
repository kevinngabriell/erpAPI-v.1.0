<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

// Read-only: entries here are only ever system-generated (sales/purchase invoice
// approval, finance payment settlement — see v2/helpers/general_journal.php).
// No create/update/delete/approve — nothing today hand-keys a journal entry.

function getAllGeneralJournalEntries($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $where = "gj.company_id = '$company_id' AND gj.deleted_at IS NULL";
    if (isset($params['source_module']) && trim($params['source_module']) !== '') {
        $source_module = mysqli_real_escape_string($conn, $params['source_module']);
        $where .= " AND gj.source_module = '$source_module'";
    }
    if (isset($params['source_document_id']) && trim($params['source_document_id']) !== '') {
        $source_document_id = mysqli_real_escape_string($conn, $params['source_document_id']);
        $where .= " AND gj.source_document_id = '$source_document_id'";
    }

    $from = APP_SCHEMA . ".general_journal gj
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = gj.created_by";

    $result       = mysqli_query($conn, "SELECT gj.*, CONCAT(cu.first_name, ' ', cu.last_name) AS created_by
            FROM $from WHERE $where ORDER BY gj.transaction_date DESC, gj.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".general_journal gj WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'General journal entries found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No general journal entries found');
    }
}

function getDetailGeneralJournalEntry($conn, $general_journal_id, $company_id) {
    $general_journal_id = mysqli_real_escape_string($conn, $general_journal_id);

    $from   = APP_SCHEMA . ".general_journal gj
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = gj.created_by";
    $result = mysqli_query($conn, "SELECT gj.*, CONCAT(cu.first_name, ' ', cu.last_name) AS created_by
            FROM $from WHERE gj.id = '$general_journal_id' AND gj.company_id = '$company_id' AND gj.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'General journal entry not found');
        return;
    }

    $general_journal = mysqli_fetch_assoc($result);

    $details_from   = APP_SCHEMA . ".general_journal_detail gjd
            LEFT JOIN " . APP_SCHEMA . ".account_code ac ON ac.id = gjd.account_code_id";
    $details_result = mysqli_query($conn, "SELECT gjd.id, gjd.account_code_id, ac.account_code, ac.account_code_name, gjd.amount, gjd.memo
            FROM $details_from WHERE gjd.general_journal_id = '$general_journal_id' AND gjd.deleted_at IS NULL ORDER BY gjd.created_at ASC");
    $general_journal['details'] = $details_result ? mysqli_fetch_all($details_result, MYSQLI_ASSOC) : [];

    jsonResponse(200, 'General journal entry found', $general_journal);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}
if ($method !== 'GET') {
    jsonResponse(405, 'Method Not Allowed');
    exit;
}

$general_journal_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($general_journal_id) {
        getDetailGeneralJournalEntry($conn, $general_journal_id, $company_id);
    } else {
        getAllGeneralJournalEntries($conn, $company_id, $_GET);
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
