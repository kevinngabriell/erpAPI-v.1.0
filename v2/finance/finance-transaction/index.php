<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/audit_log.php';
require_once __DIR__ . '/../../helpers/dual_approval.php';
require_once __DIR__ . '/../../helpers/notification.php';

function getAllFinanceTransactions($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "ft.company_id = '$company_id' AND ft.deleted_at IS NULL";
    if ($search) {
        $where .= " AND (ft.voucher_number LIKE '%$search%' OR ft.payee LIKE '%$search%')";
    }
    if (isset($params['bank_account_id']) && trim($params['bank_account_id']) !== '') {
        $bank_account_id = mysqli_real_escape_string($conn, $params['bank_account_id']);
        $where .= " AND ft.bank_account_id = '$bank_account_id'";
    }
    if (isset($params['finance_category_id']) && trim($params['finance_category_id']) !== '') {
        $finance_category_id = mysqli_real_escape_string($conn, $params['finance_category_id']);
        $where .= " AND ft.finance_category_id = '$finance_category_id'";
    }
    if (isset($params['transaction_status']) && trim($params['transaction_status']) !== '') {
        $transaction_status = mysqli_real_escape_string($conn, $params['transaction_status']);
        $where .= " AND ft.transaction_status = '$transaction_status'";
    }

    $from = APP_SCHEMA . ".finance_transaction ft
            LEFT JOIN " . APP_SCHEMA . ".bank_account ba ON ba.id = ft.bank_account_id
            LEFT JOIN " . APP_SCHEMA . ".finance_category fc ON fc.id = ft.finance_category_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = ft.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = ft.updated_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user ow ON ow.user_id COLLATE utf8mb4_general_ci = ft.approved_by_owner_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user tr ON tr.user_id COLLATE utf8mb4_general_ci = ft.approved_by_treasury_id";

    $result       = mysqli_query($conn, "SELECT ft.*, ba.bank_name, ba.bank_number, fc.category_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
            CONCAT(ow.first_name, ' ', ow.last_name) AS approved_by_owner,
            CONCAT(tr.first_name, ' ', tr.last_name) AS approved_by_treasury
            FROM $from WHERE $where ORDER BY ft.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".finance_transaction ft WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Finance transactions found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No finance transactions found');
    }
}

function createFinanceTransaction($conn, $input, $username, $company_id) {
    $required = ['bank_account_id', 'transaction_date', 'amount', 'details', 'finance_category_id'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    if (!is_array($input['details']) || count($input['details']) === 0) {
        jsonResponse(400, 'details must be a non-empty array');
        return;
    }

    $detail_sum = 0;
    foreach ($input['details'] as $detail) {
        $detail_required = ['account_code_id', 'amount'];
        foreach ($detail_required as $field) {
            if (!isset($detail[$field]) || (is_string($detail[$field]) && trim($detail[$field]) === '')) {
                jsonResponse(400, "details.$field is required");
                return;
            }
        }
        $detail_sum += (float)$detail['amount'];
    }

    $bank_account_id     = mysqli_real_escape_string($conn, $input['bank_account_id']);
    $transaction_date    = mysqli_real_escape_string($conn, $input['transaction_date']);
    $amount              = (float)$input['amount'];
    $finance_category_id = mysqli_real_escape_string($conn, $input['finance_category_id']);

    if (round($detail_sum, 2) !== round($amount, 2)) {
        jsonResponse(400, 'Sum of details.amount must equal amount');
        return;
    }

    $bank_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".bank_account WHERE id = '$bank_account_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($bank_check) === 0) {
        jsonResponse(404, 'Bank account not found');
        return;
    }

    foreach ($input['details'] as $detail) {
        $account_code_id = mysqli_real_escape_string($conn, $detail['account_code_id']);
        $account_check   = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".account_code WHERE id = '$account_code_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($account_check) === 0) {
            jsonResponse(404, 'Account code not found');
            return;
        }
    }

    $voucher_number_sql = isset($input['voucher_number']) && trim($input['voucher_number']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['voucher_number']) . "'" : 'NULL';
    $memo_sql           = isset($input['memo']) && trim($input['memo']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['memo']) . "'" : 'NULL';
    $cheque_number_sql  = isset($input['cheque_number']) && trim($input['cheque_number']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['cheque_number']) . "'" : 'NULL';
    $payee_sql          = isset($input['payee']) && trim($input['payee']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['payee']) . "'" : 'NULL';

    $finance_transaction_id = generateUUID();
    $now                    = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO " . APP_SCHEMA . ".finance_transaction
                (id, company_id, voucher_number, bank_account_id, transaction_date, memo, amount, finance_category_id,
                 created_by, created_at)
                VALUES ('$finance_transaction_id', '$company_id', $voucher_number_sql, '$bank_account_id', '$transaction_date', $memo_sql, $amount, '$finance_category_id',
                        '$username', '$now')";

        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }

        foreach ($input['details'] as $detail) {
            $detail_id       = generateUUID();
            $account_code_id = mysqli_real_escape_string($conn, $detail['account_code_id']);
            $detail_amount   = (float)$detail['amount'];
            $detail_memo_sql = isset($detail['memo']) && trim($detail['memo']) !== '' ? "'" . mysqli_real_escape_string($conn, $detail['memo']) . "'" : 'NULL';

            $detail_sql = "INSERT INTO " . APP_SCHEMA . ".finance_transaction_detail
                    (id, finance_transaction_id, account_code_id, amount, memo, created_by, created_at)
                    VALUES ('$detail_id', '$finance_transaction_id', '$account_code_id', $detail_amount, $detail_memo_sql, '$username', '$now')";

            if (!mysqli_query($conn, $detail_sql)) {
                throw new Exception(mysqli_error($conn));
            }
        }

        insertAuditLog($conn, $company_id, 'finance_transaction', $finance_transaction_id, 'created', $username);
        $conn->commit();

        $voucher_display = isset($input['voucher_number']) && trim($input['voucher_number']) !== '' ? trim($input['voucher_number']) : $finance_transaction_id;
        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_pending',
            'source_module'      => 'finance_transaction',
            'source_document_id' => $finance_transaction_id,
            'title'              => 'Finance Transaction Menunggu Approval',
            'body'               => "Dokumen Finance Transaction *$voucher_display* membutuhkan persetujuan Bapak/Ibu (memerlukan 2 tahap persetujuan). Silakan klik link di bawah untuk meninjau dan menyetujui:",
            'created_by'         => $username,
            'recipients'         => resolveApprovalRecipients($conn, $company_id, 'finance_transaction'),
        ]);

        jsonResponse(201, 'Finance transaction created successfully', ['finance_transaction_id' => $finance_transaction_id]);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(500, 'Failed to create finance transaction', ['error' => $e->getMessage()]);
    }
}

function getDetailFinanceTransaction($conn, $finance_transaction_id, $company_id) {
    $finance_transaction_id = mysqli_real_escape_string($conn, $finance_transaction_id);

    $from   = APP_SCHEMA . ".finance_transaction ft
            LEFT JOIN " . APP_SCHEMA . ".bank_account ba ON ba.id = ft.bank_account_id
            LEFT JOIN " . APP_SCHEMA . ".finance_category fc ON fc.id = ft.finance_category_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = ft.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = ft.updated_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user ow ON ow.user_id COLLATE utf8mb4_general_ci = ft.approved_by_owner_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user tr ON tr.user_id COLLATE utf8mb4_general_ci = ft.approved_by_treasury_id";
    $result = mysqli_query($conn, "SELECT ft.*, ba.bank_name, ba.bank_number, fc.category_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
            CONCAT(ow.first_name, ' ', ow.last_name) AS approved_by_owner,
            CONCAT(tr.first_name, ' ', tr.last_name) AS approved_by_treasury
            FROM $from WHERE ft.id = '$finance_transaction_id' AND ft.company_id = '$company_id' AND ft.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Finance transaction not found');
        return;
    }

    $finance_transaction = mysqli_fetch_assoc($result);

    $details_from   = APP_SCHEMA . ".finance_transaction_detail ftd
            LEFT JOIN " . APP_SCHEMA . ".account_code ac ON ac.id = ftd.account_code_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = ftd.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = ftd.updated_by";
    $details_result = mysqli_query($conn, "SELECT ftd.*, ac.account_code, ac.account_code_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $details_from WHERE ftd.finance_transaction_id = '$finance_transaction_id' AND ftd.deleted_at IS NULL ORDER BY ftd.created_at ASC");
    $finance_transaction['details'] = $details_result ? mysqli_fetch_all($details_result, MYSQLI_ASSOC) : [];

    jsonResponse(200, 'Finance transaction found', $finance_transaction);
}

function updateFinanceTransaction($conn, $finance_transaction_id, $input, $username, $company_id) {
    $finance_transaction_id = mysqli_real_escape_string($conn, $finance_transaction_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".finance_transaction WHERE id = '$finance_transaction_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Finance transaction not found');
        return;
    }

    $updates = [];

    $string_fields = ['voucher_number', 'memo', 'cheque_number', 'payee'];
    foreach ($string_fields as $field) {
        if (isset($input[$field])) {
            $val = trim(mysqli_real_escape_string($conn, $input[$field]));
            $updates[] = "$field = " . ($val === '' ? 'NULL' : "'$val'");
        }
    }

    $fk_fields = ['bank_account_id', 'finance_category_id'];
    foreach ($fk_fields as $field) {
        if (isset($input[$field])) {
            $val = trim(mysqli_real_escape_string($conn, $input[$field]));
            if ($val === '') { jsonResponse(400, "$field cannot be empty"); return; }
            $updates[] = "$field = '$val'";
        }
    }

    if (isset($input['transaction_date'])) {
        $updates[] = "transaction_date = '" . mysqli_real_escape_string($conn, $input['transaction_date']) . "'";
    }
    if (isset($input['amount']) && $input['amount'] !== '') {
        $updates[] = "amount = " . (float)$input['amount'];
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".finance_transaction SET " . implode(', ', $updates) . " WHERE id = '$finance_transaction_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'finance_transaction', $finance_transaction_id, 'updated', $username);
        jsonResponse(200, 'Finance transaction updated successfully');
    } else {
        jsonResponse(500, 'Failed to update finance transaction', ['error' => mysqli_error($conn)]);
    }
}

function deleteFinanceTransaction($conn, $finance_transaction_id, $username, $company_id) {
    $finance_transaction_id = mysqli_real_escape_string($conn, $finance_transaction_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".finance_transaction WHERE id = '$finance_transaction_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Finance transaction not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".finance_transaction SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$finance_transaction_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'finance_transaction', $finance_transaction_id, 'deleted', $username);
        jsonResponse(200, 'Finance transaction deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete finance transaction', ['error' => mysqli_error($conn)]);
    }
}

function approveFinanceTransaction($conn, $finance_transaction_id, $input, $username, $app_role_id, $company_id) {
    $doc_check = mysqli_query($conn, "SELECT voucher_number, created_by FROM " . APP_SCHEMA . ".finance_transaction WHERE id = '$finance_transaction_id' AND company_id = '$company_id' LIMIT 1");
    $doc       = $doc_check ? mysqli_fetch_assoc($doc_check) : null;
    $voucher_display = ($doc && $doc['voucher_number']) ? $doc['voucher_number'] : $finance_transaction_id;

    $result = applyDualApproval($conn, APP_SCHEMA . '.finance_transaction', $finance_transaction_id, $company_id, $username, $app_role_id);

    if ($result['code'] !== 200) {
        jsonResponse($result['code'], $result['message'], isset($result['error']) ? ['error' => $result['error']] : []);
        return;
    }

    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;
    insertAuditLog($conn, $company_id, 'finance_transaction', $finance_transaction_id, "approved_{$result['slot']}", $username, $notes);

    $approver_name = resolveDisplayName($conn, $username);

    if ($result['transaction_status'] === 'posted') {
        invalidateApprovalTokens($conn, 'finance_transaction', $finance_transaction_id);
        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_approved',
            'source_module'      => 'finance_transaction',
            'source_document_id' => $finance_transaction_id,
            'title'              => 'Finance Transaction Fully Approved',
            'body'               => "Dokumen Finance Transaction *$voucher_display* telah *disetujui sepenuhnya* (fully approved).",
            'created_by'         => $username,
            'recipients'         => $doc ? [$doc['created_by']] : [],
        ]);
    } else {
        // partially_approved: remind whoever hasn't signed yet, and tell the
        // creator progress so far — no token invalidation, the other signer's
        // link is still valid.
        $other_recipients = array_values(array_diff(resolveApprovalRecipients($conn, $company_id, 'finance_transaction'), [$username]));
        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_pending',
            'source_module'      => 'finance_transaction',
            'source_document_id' => $finance_transaction_id,
            'title'              => 'Finance Transaction — Menunggu Approval Ke-2',
            'body'               => "Dokumen Finance Transaction *$voucher_display* telah disetujui oleh $approver_name. Diperlukan persetujuan Bapak/Ibu untuk menyelesaikan proses ini. Silakan klik link di bawah untuk meninjau dan menyetujui:",
            'created_by'         => $username,
            'recipients'         => $other_recipients,
        ]);

        if ($doc) {
            notify($conn, [
                'company_id'         => $company_id,
                'type'               => 'approval_approved',
                'source_module'      => 'finance_transaction',
                'source_document_id' => $finance_transaction_id,
                'title'              => 'Finance Transaction — Progress Approval',
                'body'               => "Dokumen Finance Transaction *$voucher_display* telah disetujui oleh $approver_name. Menunggu persetujuan tahap ke-2.",
                'created_by'         => $username,
                'recipients'         => [$doc['created_by']],
            ]);
        }
    }

    jsonResponse(200, 'Finance transaction approved successfully', [
        'approved_slot'      => $result['slot'],
        'transaction_status' => $result['transaction_status'],
    ]);
}

function rejectFinanceTransaction($conn, $finance_transaction_id, $input, $username, $app_role_id, $company_id) {
    $doc_check = mysqli_query($conn, "SELECT voucher_number, created_by FROM " . APP_SCHEMA . ".finance_transaction WHERE id = '$finance_transaction_id' AND company_id = '$company_id' LIMIT 1");
    $doc       = $doc_check ? mysqli_fetch_assoc($doc_check) : null;
    $voucher_display = ($doc && $doc['voucher_number']) ? $doc['voucher_number'] : $finance_transaction_id;

    $result = rejectDualApproval($conn, APP_SCHEMA . '.finance_transaction', $finance_transaction_id, $company_id, $username, $app_role_id);

    if ($result['code'] !== 200) {
        jsonResponse($result['code'], $result['message'], isset($result['error']) ? ['error' => $result['error']] : []);
        return;
    }

    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;
    insertAuditLog($conn, $company_id, 'finance_transaction', $finance_transaction_id, 'rejected', $username, $notes);
    invalidateApprovalTokens($conn, 'finance_transaction', $finance_transaction_id);

    $rejector_name = resolveDisplayName($conn, $username);
    $reason_text   = $notes ? " Alasan: $notes." : '';
    notify($conn, [
        'company_id'         => $company_id,
        'type'               => 'approval_rejected',
        'source_module'      => 'finance_transaction',
        'source_document_id' => $finance_transaction_id,
        'title'              => 'Finance Transaction Ditolak',
        'body'               => "Dokumen Finance Transaction *$voucher_display* *ditolak* oleh $rejector_name.$reason_text",
        'created_by'         => $username,
        'recipients'         => $doc ? [$doc['created_by']] : [],
    ]);

    jsonResponse(200, 'Finance transaction rejected successfully');
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser    = requireAuth();
$method      = $_SERVER['REQUEST_METHOD'];
$company_id  = $authUser['company_id'] ?? null;
$username    = $authUser['user_id'] ?? null;
$app_role_id = $authUser['app_role_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

$finance_transaction_id = !empty($action) ? $action : null;
$sub_action             = $parts[4] ?? '';

try {
    $conn = getConn();

    if ($finance_transaction_id && $sub_action === 'details') {
        require __DIR__ . '/details.php';

    } elseif ($finance_transaction_id && $sub_action !== '') {
        $input = in_array($method, ['POST', 'PUT', 'PATCH'])
            ? (json_decode(file_get_contents('php://input'), true) ?? [])
            : [];

        switch ($sub_action) {
            case 'approve':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                approveFinanceTransaction($conn, $finance_transaction_id, $input, $username, $app_role_id, $company_id);
                break;
            case 'reject':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                rejectFinanceTransaction($conn, $finance_transaction_id, $input, $username, $app_role_id, $company_id);
                break;
            default:
                jsonResponse(404, 'Route not found');
        }

    } elseif ($finance_transaction_id) {
        switch ($method) {
            case 'GET':
                getDetailFinanceTransaction($conn, $finance_transaction_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateFinanceTransaction($conn, $finance_transaction_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteFinanceTransaction($conn, $finance_transaction_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllFinanceTransactions($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createFinanceTransaction($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
