<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/audit_log.php';

function getAllFinanceTransactions($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "company_id = '$company_id' AND deleted_at IS NULL";
    if ($search) {
        $where .= " AND (voucher_number LIKE '%$search%' OR payee LIKE '%$search%')";
    }
    if (isset($params['bank_account_id']) && trim($params['bank_account_id']) !== '') {
        $bank_account_id = mysqli_real_escape_string($conn, $params['bank_account_id']);
        $where .= " AND bank_account_id = '$bank_account_id'";
    }
    if (isset($params['finance_category_id']) && trim($params['finance_category_id']) !== '') {
        $finance_category_id = mysqli_real_escape_string($conn, $params['finance_category_id']);
        $where .= " AND finance_category_id = '$finance_category_id'";
    }

    $result       = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".finance_transaction WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".finance_transaction WHERE $where");
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
    $required = ['bank_account_id', 'transaction_date', 'amount', 'account_code_id', 'account_amount', 'finance_category_id'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $bank_account_id     = mysqli_real_escape_string($conn, $input['bank_account_id']);
    $transaction_date    = mysqli_real_escape_string($conn, $input['transaction_date']);
    $amount              = (float)$input['amount'];
    $account_code_id     = mysqli_real_escape_string($conn, $input['account_code_id']);
    $account_amount      = (float)$input['account_amount'];
    $finance_category_id = mysqli_real_escape_string($conn, $input['finance_category_id']);

    $bank_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".bank_account WHERE id = '$bank_account_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($bank_check) === 0) {
        jsonResponse(404, 'Bank account not found');
        return;
    }

    $account_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".account_code WHERE id = '$account_code_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($account_check) === 0) {
        jsonResponse(404, 'Account code not found');
        return;
    }

    $voucher_number_sql = isset($input['voucher_number']) && trim($input['voucher_number']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['voucher_number']) . "'" : 'NULL';
    $memo_sql           = isset($input['memo']) && trim($input['memo']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['memo']) . "'" : 'NULL';
    $account_memo_sql   = isset($input['account_memo']) && trim($input['account_memo']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['account_memo']) . "'" : 'NULL';
    $cheque_number_sql  = isset($input['cheque_number']) && trim($input['cheque_number']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['cheque_number']) . "'" : 'NULL';
    $payee_sql          = isset($input['payee']) && trim($input['payee']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['payee']) . "'" : 'NULL';

    $finance_transaction_id = generateUUID();
    $now                    = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".finance_transaction
            (id, company_id, voucher_number, bank_account_id, transaction_date, memo, amount,
             account_code_id, account_amount, account_memo, cheque_number, payee, finance_category_id,
             created_by, created_at)
            VALUES ('$finance_transaction_id', '$company_id', $voucher_number_sql, '$bank_account_id', '$transaction_date', $memo_sql, $amount,
                    '$account_code_id', $account_amount, $account_memo_sql, $cheque_number_sql, $payee_sql, '$finance_category_id',
                    '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        insertAuditLog($conn, $company_id, 'finance_transaction', $finance_transaction_id, 'created', $username);
        jsonResponse(201, 'Finance transaction created successfully', ['finance_transaction_id' => $finance_transaction_id]);
    } else {
        jsonResponse(500, 'Failed to create finance transaction', ['error' => mysqli_error($conn)]);
    }
}

function getDetailFinanceTransaction($conn, $finance_transaction_id, $company_id) {
    $finance_transaction_id = mysqli_real_escape_string($conn, $finance_transaction_id);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".finance_transaction WHERE id = '$finance_transaction_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Finance transaction not found');
        return;
    }

    jsonResponse(200, 'Finance transaction found', mysqli_fetch_assoc($result));
}

function updateFinanceTransaction($conn, $finance_transaction_id, $input, $username, $company_id) {
    $finance_transaction_id = mysqli_real_escape_string($conn, $finance_transaction_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".finance_transaction WHERE id = '$finance_transaction_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Finance transaction not found');
        return;
    }

    $updates = [];

    $string_fields = ['voucher_number', 'memo', 'account_memo', 'cheque_number', 'payee'];
    foreach ($string_fields as $field) {
        if (isset($input[$field])) {
            $val = trim(mysqli_real_escape_string($conn, $input[$field]));
            $updates[] = "$field = " . ($val === '' ? 'NULL' : "'$val'");
        }
    }

    $fk_fields = ['bank_account_id', 'account_code_id', 'finance_category_id'];
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
    if (isset($input['account_amount']) && $input['account_amount'] !== '') {
        $updates[] = "account_amount = " . (float)$input['account_amount'];
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

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;
$username   = $authUser['user_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

$finance_transaction_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($finance_transaction_id) {
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
