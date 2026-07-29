<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllBankAccounts($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "ba.company_id = '$company_id' AND ba.deleted_at IS NULL";
    if ($search) {
        $where .= " AND (ba.bank_number LIKE '%$search%' OR ba.bank_name LIKE '%$search%')";
    }
    if (isset($params['currency_id']) && trim($params['currency_id']) !== '') {
        $currency_id = mysqli_real_escape_string($conn, $params['currency_id']);
        $where .= " AND ba.currency_id = '$currency_id'";
    }

    $from = APP_SCHEMA . ".bank_account ba
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = ba.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = ba.updated_by";

    $result       = mysqli_query($conn, "SELECT ba.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY ba.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".bank_account ba WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        $bank_accounts = mysqli_fetch_all($result, MYSQLI_ASSOC);
        foreach ($bank_accounts as &$bank_account) {
            $bank_account['is_primary'] = (bool)(int)$bank_account['is_primary'];
        }
        jsonResponse(200, 'Bank accounts found', [
            'data'       => $bank_accounts,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No bank accounts found');
    }
}

function createBankAccount($conn, $input, $username, $company_id) {
    $required = ['bank_number', 'bank_name', 'currency_id'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $bank_number = trim(mysqli_real_escape_string($conn, $input['bank_number']));
    $bank_name   = trim(mysqli_real_escape_string($conn, $input['bank_name']));
    $currency_id = mysqli_real_escape_string($conn, $input['currency_id']);

    $bank_branch_sql = isset($input['bank_branch']) && trim($input['bank_branch']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['bank_branch'])) . "'"
        : 'NULL';

    $currency_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".currency WHERE id = '$currency_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($currency_check) === 0) {
        jsonResponse(404, 'Currency not found');
        return;
    }

    $account_code_id_sql = 'NULL';
    if (isset($input['account_code_id']) && trim($input['account_code_id']) !== '') {
        $account_code_id = mysqli_real_escape_string($conn, $input['account_code_id']);
        $account_check   = mysqli_query($conn, "SELECT account_type FROM " . APP_SCHEMA . ".account_code WHERE id = '$account_code_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($account_check) === 0) {
            jsonResponse(404, 'Account code not found');
            return;
        }
        if (mysqli_fetch_assoc($account_check)['account_type'] !== 'asset') {
            jsonResponse(400, 'account_code_id must reference an account_type=asset account');
            return;
        }
        $account_code_id_sql = "'$account_code_id'";
    }

    $is_primary = !empty($input['is_primary']) ? 1 : 0;

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".bank_account WHERE company_id = '$company_id' AND bank_number = '$bank_number' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Bank account already exists');
        return;
    }

    $bank_account_id = generateUUID();
    $now             = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".bank_account
            (id, company_id, bank_number, bank_name, bank_branch, currency_id, account_code_id, is_primary, created_by, created_at)
            VALUES ('$bank_account_id', '$company_id', '$bank_number', '$bank_name', $bank_branch_sql, '$currency_id', $account_code_id_sql, $is_primary, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Bank account created successfully', ['bank_account_id' => $bank_account_id]);
    } else {
        jsonResponse(500, 'Failed to create bank account', ['error' => mysqli_error($conn)]);
    }
}

function getDetailBankAccount($conn, $bank_account_id, $company_id) {
    $bank_account_id = mysqli_real_escape_string($conn, $bank_account_id);

    $from   = APP_SCHEMA . ".bank_account ba
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = ba.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = ba.updated_by";
    $result = mysqli_query($conn, "SELECT ba.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE ba.id = '$bank_account_id' AND ba.company_id = '$company_id' AND ba.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Bank account not found');
        return;
    }

    $bank_account = mysqli_fetch_assoc($result);
    $bank_account['is_primary'] = (bool)(int)$bank_account['is_primary'];

    jsonResponse(200, 'Bank account found', $bank_account);
}

function updateBankAccount($conn, $bank_account_id, $input, $username, $company_id) {
    $bank_account_id = mysqli_real_escape_string($conn, $bank_account_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".bank_account WHERE id = '$bank_account_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Bank account not found');
        return;
    }

    $updates = [];

    if (isset($input['bank_number'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['bank_number']));
        if ($val === '') { jsonResponse(400, 'bank_number cannot be empty'); return; }
        $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".bank_account WHERE company_id = '$company_id' AND bank_number = '$val' AND id != '$bank_account_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($dup) > 0) {
            jsonResponse(409, 'Bank account already exists');
            return;
        }
        $updates[] = "bank_number = '$val'";
    }

    if (isset($input['bank_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['bank_name']));
        if ($val === '') { jsonResponse(400, 'bank_name cannot be empty'); return; }
        $updates[] = "bank_name = '$val'";
    }

    if (array_key_exists('bank_branch', $input)) {
        $val = isset($input['bank_branch']) && trim($input['bank_branch']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['bank_branch'])) . "'"
            : 'NULL';
        $updates[] = "bank_branch = $val";
    }

    if (isset($input['currency_id'])) {
        $currency_id = mysqli_real_escape_string($conn, $input['currency_id']);
        $currency_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".currency WHERE id = '$currency_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($currency_check) === 0) {
            jsonResponse(404, 'Currency not found');
            return;
        }
        $updates[] = "currency_id = '$currency_id'";
    }

    if (isset($input['is_primary'])) {
        $is_primary = !empty($input['is_primary']) ? 1 : 0;
        $updates[] = "is_primary = $is_primary";
    }

    if (array_key_exists('account_code_id', $input)) {
        if ($input['account_code_id'] === null || trim((string)$input['account_code_id']) === '') {
            $updates[] = "account_code_id = NULL";
        } else {
            $new_account_code_id = mysqli_real_escape_string($conn, $input['account_code_id']);
            $account_check = mysqli_query($conn, "SELECT account_type FROM " . APP_SCHEMA . ".account_code WHERE id = '$new_account_code_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
            if (mysqli_num_rows($account_check) === 0) {
                jsonResponse(404, 'Account code not found');
                return;
            }
            if (mysqli_fetch_assoc($account_check)['account_type'] !== 'asset') {
                jsonResponse(400, 'account_code_id must reference an account_type=asset account');
                return;
            }
            $updates[] = "account_code_id = '$new_account_code_id'";
        }
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".bank_account SET " . implode(', ', $updates) . " WHERE id = '$bank_account_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Bank account updated successfully');
    } else {
        jsonResponse(500, 'Failed to update bank account', ['error' => mysqli_error($conn)]);
    }
}

function deleteBankAccount($conn, $bank_account_id, $username, $company_id) {
    $bank_account_id = mysqli_real_escape_string($conn, $bank_account_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".bank_account WHERE id = '$bank_account_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Bank account not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".bank_account SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$bank_account_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Bank account deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete bank account', ['error' => mysqli_error($conn)]);
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

$bank_account_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($bank_account_id) {
        switch ($method) {
            case 'GET':
                getDetailBankAccount($conn, $bank_account_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateBankAccount($conn, $bank_account_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteBankAccount($conn, $bank_account_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllBankAccounts($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createBankAccount($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
