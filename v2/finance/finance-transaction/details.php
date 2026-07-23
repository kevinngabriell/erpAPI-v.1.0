<?php

function assertFinanceTransactionBelongsToCompany($conn, $finance_transaction_id, $company_id) {
    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".finance_transaction WHERE id = '$finance_transaction_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Finance transaction not found');
    }
}

function getAllFinanceTransactionDetails($conn, $finance_transaction_id, $company_id) {
    assertFinanceTransactionBelongsToCompany($conn, $finance_transaction_id, $company_id);

    $from   = APP_SCHEMA . ".finance_transaction_detail ftd
            LEFT JOIN " . APP_SCHEMA . ".account_code ac ON ac.id = ftd.account_code_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = ftd.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = ftd.updated_by";
    $result = mysqli_query($conn, "SELECT ftd.*, ac.account_code, ac.account_code_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE ftd.finance_transaction_id = '$finance_transaction_id' AND ftd.deleted_at IS NULL ORDER BY ftd.created_at ASC");

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Finance transaction details found', ['data' => mysqli_fetch_all($result, MYSQLI_ASSOC)]);
    } else {
        jsonResponse(404, 'No finance transaction details found');
    }
}

function createFinanceTransactionDetail($conn, $finance_transaction_id, $input, $username, $company_id) {
    assertFinanceTransactionBelongsToCompany($conn, $finance_transaction_id, $company_id);

    $required = ['account_code_id', 'amount'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $account_code_id = mysqli_real_escape_string($conn, $input['account_code_id']);
    $amount          = (float)$input['amount'];

    $account_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".account_code WHERE id = '$account_code_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($account_check) === 0) {
        jsonResponse(404, 'Account code not found');
        return;
    }

    $memo_sql = isset($input['memo']) && trim($input['memo']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['memo']) . "'" : 'NULL';

    $finance_transaction_detail_id = generateUUID();
    $now                           = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".finance_transaction_detail
            (id, finance_transaction_id, account_code_id, amount, memo, created_by, created_at)
            VALUES ('$finance_transaction_detail_id', '$finance_transaction_id', '$account_code_id', $amount, $memo_sql, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Finance transaction detail created successfully', ['finance_transaction_detail_id' => $finance_transaction_detail_id]);
    } else {
        jsonResponse(500, 'Failed to create finance transaction detail', ['error' => mysqli_error($conn)]);
    }
}

function getDetailFinanceTransactionDetail($conn, $finance_transaction_id, $finance_transaction_detail_id, $company_id) {
    assertFinanceTransactionBelongsToCompany($conn, $finance_transaction_id, $company_id);

    $finance_transaction_detail_id = mysqli_real_escape_string($conn, $finance_transaction_detail_id);

    $from   = APP_SCHEMA . ".finance_transaction_detail ftd
            LEFT JOIN " . APP_SCHEMA . ".account_code ac ON ac.id = ftd.account_code_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = ftd.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = ftd.updated_by";
    $result = mysqli_query($conn, "SELECT ftd.*, ac.account_code, ac.account_code_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE ftd.id = '$finance_transaction_detail_id' AND ftd.finance_transaction_id = '$finance_transaction_id' AND ftd.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Finance transaction detail not found');
        return;
    }

    jsonResponse(200, 'Finance transaction detail found', mysqli_fetch_assoc($result));
}

function updateFinanceTransactionDetail($conn, $finance_transaction_id, $finance_transaction_detail_id, $input, $username, $company_id) {
    assertFinanceTransactionBelongsToCompany($conn, $finance_transaction_id, $company_id);

    $finance_transaction_detail_id = mysqli_real_escape_string($conn, $finance_transaction_detail_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".finance_transaction_detail WHERE id = '$finance_transaction_detail_id' AND finance_transaction_id = '$finance_transaction_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Finance transaction detail not found');
        return;
    }

    $updates = [];

    if (isset($input['account_code_id'])) {
        $account_code_id = trim(mysqli_real_escape_string($conn, $input['account_code_id']));
        if ($account_code_id === '') { jsonResponse(400, 'account_code_id cannot be empty'); return; }

        $account_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".account_code WHERE id = '$account_code_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($account_check) === 0) {
            jsonResponse(404, 'Account code not found');
            return;
        }
        $updates[] = "account_code_id = '$account_code_id'";
    }

    if (isset($input['amount']) && $input['amount'] !== '') {
        $updates[] = "amount = " . (float)$input['amount'];
    }

    if (array_key_exists('memo', $input)) {
        $val = trim(mysqli_real_escape_string($conn, $input['memo'] ?? ''));
        $updates[] = "memo = " . ($val === '' ? 'NULL' : "'$val'");
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".finance_transaction_detail SET " . implode(', ', $updates) . " WHERE id = '$finance_transaction_detail_id' AND finance_transaction_id = '$finance_transaction_id'")) {
        jsonResponse(200, 'Finance transaction detail updated successfully');
    } else {
        jsonResponse(500, 'Failed to update finance transaction detail', ['error' => mysqli_error($conn)]);
    }
}

function deleteFinanceTransactionDetail($conn, $finance_transaction_id, $finance_transaction_detail_id, $username, $company_id) {
    assertFinanceTransactionBelongsToCompany($conn, $finance_transaction_id, $company_id);

    $finance_transaction_detail_id = mysqli_real_escape_string($conn, $finance_transaction_detail_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".finance_transaction_detail WHERE id = '$finance_transaction_detail_id' AND finance_transaction_id = '$finance_transaction_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Finance transaction detail not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".finance_transaction_detail SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$finance_transaction_detail_id' AND finance_transaction_id = '$finance_transaction_id'")) {
        jsonResponse(200, 'Finance transaction detail deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete finance transaction detail', ['error' => mysqli_error($conn)]);
    }
}

$finance_transaction_detail_id = $parts[5] ?? null;

if ($finance_transaction_detail_id) {
    switch ($method) {
        case 'GET':
            getDetailFinanceTransactionDetail($conn, $finance_transaction_id, $finance_transaction_detail_id, $company_id);
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            updateFinanceTransactionDetail($conn, $finance_transaction_id, $finance_transaction_detail_id, $input, $username, $company_id);
            break;
        case 'DELETE':
            deleteFinanceTransactionDetail($conn, $finance_transaction_id, $finance_transaction_detail_id, $username, $company_id);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} else {
    switch ($method) {
        case 'GET':
            getAllFinanceTransactionDetails($conn, $finance_transaction_id, $company_id);
            break;
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createFinanceTransactionDetail($conn, $finance_transaction_id, $input, $username, $company_id);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
    }
}
