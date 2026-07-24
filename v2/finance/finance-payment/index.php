<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/audit_log.php';
require_once __DIR__ . '/../../helpers/dual_approval.php';
require_once __DIR__ . '/../../helpers/notification.php';

function getAllFinancePayments($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "fp.company_id = '$company_id' AND fp.deleted_at IS NULL";
    if ($search) {
        $where .= " AND fp.invoice_number LIKE '%$search%'";
    }
    if (isset($params['customer_id']) && trim($params['customer_id']) !== '') {
        $customer_id = mysqli_real_escape_string($conn, $params['customer_id']);
        $where .= " AND fp.customer_id = '$customer_id'";
    }
    if (isset($params['supplier_id']) && trim($params['supplier_id']) !== '') {
        $supplier_id = mysqli_real_escape_string($conn, $params['supplier_id']);
        $where .= " AND fp.supplier_id = '$supplier_id'";
    }
    if (isset($params['transaction_status']) && trim($params['transaction_status']) !== '') {
        $transaction_status = mysqli_real_escape_string($conn, $params['transaction_status']);
        $where .= " AND fp.transaction_status = '$transaction_status'";
    }

    $from = APP_SCHEMA . ".finance_payment fp
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = fp.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = fp.updated_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user ow ON ow.user_id COLLATE utf8mb4_general_ci = fp.approved_by_owner_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user tr ON tr.user_id COLLATE utf8mb4_general_ci = fp.approved_by_treasury_id";

    $result       = mysqli_query($conn, "SELECT fp.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
            CONCAT(ow.first_name, ' ', ow.last_name) AS approved_by_owner,
            CONCAT(tr.first_name, ' ', tr.last_name) AS approved_by_treasury
            FROM $from WHERE $where ORDER BY fp.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".finance_payment fp WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Finance payments found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No finance payments found');
    }
}

function createFinancePayment($conn, $input, $username, $company_id) {
    $required = ['invoice_number', 'paid_amount'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $invoice_number = trim(mysqli_real_escape_string($conn, $input['invoice_number']));
    $paid_amount    = (float)$input['paid_amount'];

    $invoice_check = mysqli_query($conn, "SELECT ss.status_name FROM " . APP_SCHEMA . ".sales_invoice si
            LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = si.status_id
            WHERE si.invoice_display_number = '$invoice_number' AND si.company_id = '$company_id' AND si.deleted_at IS NULL LIMIT 1");
    if (!$invoice_check || mysqli_num_rows($invoice_check) === 0) {
        jsonResponse(404, 'Sales invoice not found');
        return;
    }
    $sales_invoice = mysqli_fetch_assoc($invoice_check);
    if ($sales_invoice['status_name'] !== 'Approved') {
        jsonResponse(400, 'Sales invoice must be approved before a payment can be recorded');
        return;
    }

    if (isset($input['customer_id']) && trim($input['customer_id']) !== '') {
        $customer_id = mysqli_real_escape_string($conn, $input['customer_id']);
        $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".customer WHERE id = '$customer_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($check) === 0) {
            jsonResponse(404, 'Customer not found');
            return;
        }
    }

    if (isset($input['supplier_id']) && trim($input['supplier_id']) !== '') {
        $supplier_id = mysqli_real_escape_string($conn, $input['supplier_id']);
        $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".supplier WHERE id = '$supplier_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($check) === 0) {
            jsonResponse(404, 'Supplier not found');
            return;
        }
    }

    $customer_id_sql       = isset($input['customer_id']) && trim($input['customer_id']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['customer_id']) . "'" : 'NULL';
    $supplier_id_sql        = isset($input['supplier_id']) && trim($input['supplier_id']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['supplier_id']) . "'" : 'NULL';
    $due_amount_sql         = isset($input['due_amount']) && $input['due_amount'] !== '' ? (float)$input['due_amount'] : 'NULL';
    $payment_date_sql       = isset($input['payment_date']) && trim($input['payment_date']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['payment_date']) . "'" : 'NULL';
    $form_number_sql        = isset($input['form_number']) && trim($input['form_number']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['form_number']) . "'" : 'NULL';
    $bank_account_id_sql    = isset($input['bank_account_id']) && trim($input['bank_account_id']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['bank_account_id']) . "'" : 'NULL';
    $exchange_rate_sql      = isset($input['exchange_rate']) && $input['exchange_rate'] !== '' ? (float)$input['exchange_rate'] : 'NULL';
    $cheque_number_sql      = isset($input['cheque_number']) && trim($input['cheque_number']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['cheque_number']) . "'" : 'NULL';
    $cheque_date_sql        = isset($input['cheque_date']) && trim($input['cheque_date']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['cheque_date']) . "'" : 'NULL';
    $cheque_amount_sql      = isset($input['cheque_amount']) && $input['cheque_amount'] !== '' ? (float)$input['cheque_amount'] : 'NULL';
    $memo_sql               = isset($input['memo']) && trim($input['memo']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['memo']) . "'" : 'NULL';
    $recipient_sql          = isset($input['recipient']) && trim($input['recipient']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['recipient']) . "'" : 'NULL';
    $discount_amount        = isset($input['discount_amount']) && $input['discount_amount'] !== '' ? (float)$input['discount_amount'] : 0;

    $finance_payment_id = generateUUID();
    $now                = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".finance_payment
            (id, company_id, invoice_number, paid_amount, due_amount, customer_id, supplier_id,
             payment_date, form_number, bank_account_id, exchange_rate, cheque_number, cheque_date,
             cheque_amount, memo, recipient, discount_amount, created_by, created_at)
            VALUES ('$finance_payment_id', '$company_id', '$invoice_number', $paid_amount, $due_amount_sql, $customer_id_sql, $supplier_id_sql,
                    $payment_date_sql, $form_number_sql, $bank_account_id_sql, $exchange_rate_sql, $cheque_number_sql, $cheque_date_sql,
                    $cheque_amount_sql, $memo_sql, $recipient_sql, $discount_amount, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        insertAuditLog($conn, $company_id, 'finance_payment', $finance_payment_id, 'created', $username);

        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_pending',
            'source_module'      => 'finance_payment',
            'source_document_id' => $finance_payment_id,
            'title'              => 'Finance Payment Menunggu Approval',
            'body'               => "Dokumen Finance Payment *$invoice_number* membutuhkan persetujuan Bapak/Ibu (memerlukan 2 tahap persetujuan). Silakan klik link di bawah untuk meninjau dan menyetujui:",
            'created_by'         => $username,
            'recipients'         => resolveApprovalRecipients($conn, $company_id, 'finance_payment'),
        ]);

        jsonResponse(201, 'Finance payment created successfully', ['finance_payment_id' => $finance_payment_id]);
    } else {
        jsonResponse(500, 'Failed to create finance payment', ['error' => mysqli_error($conn)]);
    }
}

function getDetailFinancePayment($conn, $finance_payment_id, $company_id) {
    $finance_payment_id = mysqli_real_escape_string($conn, $finance_payment_id);

    $from   = APP_SCHEMA . ".finance_payment fp
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = fp.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = fp.updated_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user ow ON ow.user_id COLLATE utf8mb4_general_ci = fp.approved_by_owner_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user tr ON tr.user_id COLLATE utf8mb4_general_ci = fp.approved_by_treasury_id";
    $result = mysqli_query($conn, "SELECT fp.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
            CONCAT(ow.first_name, ' ', ow.last_name) AS approved_by_owner,
            CONCAT(tr.first_name, ' ', tr.last_name) AS approved_by_treasury
            FROM $from WHERE fp.id = '$finance_payment_id' AND fp.company_id = '$company_id' AND fp.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Finance payment not found');
        return;
    }

    jsonResponse(200, 'Finance payment found', mysqli_fetch_assoc($result));
}

function updateFinancePayment($conn, $finance_payment_id, $input, $username, $company_id) {
    $finance_payment_id = mysqli_real_escape_string($conn, $finance_payment_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".finance_payment WHERE id = '$finance_payment_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Finance payment not found');
        return;
    }

    $updates = [];

    $string_fields = ['invoice_number', 'form_number', 'cheque_number', 'memo', 'recipient'];
    foreach ($string_fields as $field) {
        if (isset($input[$field])) {
            $val = trim(mysqli_real_escape_string($conn, $input[$field]));
            $updates[] = "$field = " . ($val === '' ? 'NULL' : "'$val'");
        }
    }

    $fk_fields = ['customer_id', 'supplier_id', 'bank_account_id'];
    foreach ($fk_fields as $field) {
        if (isset($input[$field])) {
            $val = trim(mysqli_real_escape_string($conn, $input[$field]));
            $updates[] = "$field = " . ($val === '' ? 'NULL' : "'$val'");
        }
    }

    $date_fields = ['payment_date', 'cheque_date'];
    foreach ($date_fields as $field) {
        if (isset($input[$field])) {
            $val = trim(mysqli_real_escape_string($conn, $input[$field]));
            $updates[] = "$field = " . ($val === '' ? 'NULL' : "'$val'");
        }
    }

    $numeric_fields = ['paid_amount', 'due_amount', 'exchange_rate', 'cheque_amount', 'discount_amount'];
    foreach ($numeric_fields as $field) {
        if (isset($input[$field]) && $input[$field] !== '') {
            $updates[] = "$field = " . (float)$input[$field];
        }
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".finance_payment SET " . implode(', ', $updates) . " WHERE id = '$finance_payment_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'finance_payment', $finance_payment_id, 'updated', $username);
        jsonResponse(200, 'Finance payment updated successfully');
    } else {
        jsonResponse(500, 'Failed to update finance payment', ['error' => mysqli_error($conn)]);
    }
}

function deleteFinancePayment($conn, $finance_payment_id, $username, $company_id) {
    $finance_payment_id = mysqli_real_escape_string($conn, $finance_payment_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".finance_payment WHERE id = '$finance_payment_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Finance payment not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".finance_payment SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$finance_payment_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'finance_payment', $finance_payment_id, 'deleted', $username);
        jsonResponse(200, 'Finance payment deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete finance payment', ['error' => mysqli_error($conn)]);
    }
}

function approveFinancePayment($conn, $finance_payment_id, $input, $username, $app_role_id, $company_id) {
    $doc_check = mysqli_query($conn, "SELECT invoice_number, created_by FROM " . APP_SCHEMA . ".finance_payment WHERE id = '$finance_payment_id' AND company_id = '$company_id' LIMIT 1");
    $doc       = $doc_check ? mysqli_fetch_assoc($doc_check) : null;

    $result = applyDualApproval($conn, APP_SCHEMA . '.finance_payment', $finance_payment_id, $company_id, $username, $app_role_id);

    if ($result['code'] !== 200) {
        jsonResponse($result['code'], $result['message'], isset($result['error']) ? ['error' => $result['error']] : []);
        return;
    }

    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;
    insertAuditLog($conn, $company_id, 'finance_payment', $finance_payment_id, "approved_{$result['slot']}", $username, $notes);

    $approver_name    = resolveDisplayName($conn, $username);
    $invoice_display  = $doc['invoice_number'] ?? $finance_payment_id;

    if ($result['transaction_status'] === 'posted') {
        invalidateApprovalTokens($conn, 'finance_payment', $finance_payment_id);
        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_approved',
            'source_module'      => 'finance_payment',
            'source_document_id' => $finance_payment_id,
            'title'              => 'Finance Payment Fully Approved',
            'body'               => "Dokumen Finance Payment *$invoice_display* telah *disetujui sepenuhnya* (fully approved).",
            'created_by'         => $username,
            'recipients'         => $doc ? [$doc['created_by']] : [],
        ]);
    } else {
        $other_recipients = array_values(array_diff(resolveApprovalRecipients($conn, $company_id, 'finance_payment'), [$username]));
        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_pending',
            'source_module'      => 'finance_payment',
            'source_document_id' => $finance_payment_id,
            'title'              => 'Finance Payment — Menunggu Approval Ke-2',
            'body'               => "Dokumen Finance Payment *$invoice_display* telah disetujui oleh $approver_name. Diperlukan persetujuan Bapak/Ibu untuk menyelesaikan proses ini. Silakan klik link di bawah untuk meninjau dan menyetujui:",
            'created_by'         => $username,
            'recipients'         => $other_recipients,
        ]);

        if ($doc) {
            notify($conn, [
                'company_id'         => $company_id,
                'type'               => 'approval_approved',
                'source_module'      => 'finance_payment',
                'source_document_id' => $finance_payment_id,
                'title'              => 'Finance Payment — Progress Approval',
                'body'               => "Dokumen Finance Payment *$invoice_display* telah disetujui oleh $approver_name. Menunggu persetujuan tahap ke-2.",
                'created_by'         => $username,
                'recipients'         => [$doc['created_by']],
            ]);
        }
    }

    jsonResponse(200, 'Finance payment approved successfully', [
        'approved_slot'      => $result['slot'],
        'transaction_status' => $result['transaction_status'],
    ]);
}

function rejectFinancePayment($conn, $finance_payment_id, $input, $username, $app_role_id, $company_id) {
    $doc_check = mysqli_query($conn, "SELECT invoice_number, created_by FROM " . APP_SCHEMA . ".finance_payment WHERE id = '$finance_payment_id' AND company_id = '$company_id' LIMIT 1");
    $doc       = $doc_check ? mysqli_fetch_assoc($doc_check) : null;

    $result = rejectDualApproval($conn, APP_SCHEMA . '.finance_payment', $finance_payment_id, $company_id, $username, $app_role_id);

    if ($result['code'] !== 200) {
        jsonResponse($result['code'], $result['message'], isset($result['error']) ? ['error' => $result['error']] : []);
        return;
    }

    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;
    insertAuditLog($conn, $company_id, 'finance_payment', $finance_payment_id, 'rejected', $username, $notes);
    invalidateApprovalTokens($conn, 'finance_payment', $finance_payment_id);

    $rejector_name   = resolveDisplayName($conn, $username);
    $reason_text     = $notes ? " Alasan: $notes." : '';
    $invoice_display = $doc['invoice_number'] ?? $finance_payment_id;
    notify($conn, [
        'company_id'         => $company_id,
        'type'               => 'approval_rejected',
        'source_module'      => 'finance_payment',
        'source_document_id' => $finance_payment_id,
        'title'              => 'Finance Payment Ditolak',
        'body'               => "Dokumen Finance Payment *$invoice_display* *ditolak* oleh $rejector_name.$reason_text",
        'created_by'         => $username,
        'recipients'         => $doc ? [$doc['created_by']] : [],
    ]);

    jsonResponse(200, 'Finance payment rejected successfully');
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

$finance_payment_id = !empty($action) ? $action : null;
$sub_action          = $parts[4] ?? '';

try {
    $conn = getConn();

    if ($finance_payment_id && $sub_action !== '') {
        $input = in_array($method, ['POST', 'PUT', 'PATCH'])
            ? (json_decode(file_get_contents('php://input'), true) ?? [])
            : [];

        switch ($sub_action) {
            case 'approve':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                approveFinancePayment($conn, $finance_payment_id, $input, $username, $app_role_id, $company_id);
                break;
            case 'reject':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                rejectFinancePayment($conn, $finance_payment_id, $input, $username, $app_role_id, $company_id);
                break;
            default:
                jsonResponse(404, 'Route not found');
        }

    } elseif ($finance_payment_id) {
        switch ($method) {
            case 'GET':
                getDetailFinancePayment($conn, $finance_payment_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateFinancePayment($conn, $finance_payment_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteFinancePayment($conn, $finance_payment_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllFinancePayments($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createFinancePayment($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
