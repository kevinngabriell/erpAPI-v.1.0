<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllAccountCodes($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "ac.company_id = '$company_id' AND ac.deleted_at IS NULL";
    if ($search) {
        $where .= " AND (ac.account_code LIKE '%$search%' OR ac.account_code_name LIKE '%$search%')";
    }
    if (isset($params['account_type']) && in_array($params['account_type'], ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) {
        $account_type = mysqli_real_escape_string($conn, $params['account_type']);
        $where .= " AND ac.account_type = '$account_type'";
    }

    $from = APP_SCHEMA . ".account_code ac
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = ac.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = ac.updated_by";

    $result       = mysqli_query($conn, "SELECT ac.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY ac.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".account_code ac WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        $account_codes = mysqli_fetch_all($result, MYSQLI_ASSOC);
        foreach ($account_codes as &$account_code) {
            $account_code['is_active'] = (bool)(int)$account_code['is_active'];
        }
        jsonResponse(200, 'Account codes found', [
            'data'       => $account_codes,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No account codes found');
    }
}

function createAccountCode($conn, $input, $username, $company_id) {
    $required = ['account_code', 'account_code_name', 'account_type'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    if (!in_array($input['account_type'], ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) {
        jsonResponse(400, 'account_type must be asset, liability, equity, revenue, or expense');
        return;
    }

    $account_code      = trim(mysqli_real_escape_string($conn, $input['account_code']));
    $account_code_name = trim(mysqli_real_escape_string($conn, $input['account_code_name']));
    $account_type      = mysqli_real_escape_string($conn, $input['account_type']);

    $account_code_name_alias_sql = isset($input['account_code_name_alias']) && trim($input['account_code_name_alias']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['account_code_name_alias'])) . "'"
        : 'NULL';

    $parent_account_code_id_sql = 'NULL';
    if (isset($input['parent_account_code_id']) && trim($input['parent_account_code_id']) !== '') {
        $parent_account_code_id = mysqli_real_escape_string($conn, $input['parent_account_code_id']);
        $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".account_code WHERE id = '$parent_account_code_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($check) === 0) {
            jsonResponse(404, 'Parent account code not found');
            return;
        }
        $parent_account_code_id_sql = "'$parent_account_code_id'";
    }

    $is_active = isset($input['is_active']) ? (!empty($input['is_active']) ? 1 : 0) : 1;

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".account_code WHERE company_id = '$company_id' AND account_code = '$account_code' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Account code already exists');
        return;
    }

    $account_code_id = generateUUID();
    $now             = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".account_code
            (id, company_id, account_code, account_code_name, account_code_name_alias, account_type, parent_account_code_id, is_active, created_by, created_at)
            VALUES ('$account_code_id', '$company_id', '$account_code', '$account_code_name', $account_code_name_alias_sql, '$account_type', $parent_account_code_id_sql, $is_active, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Account code created successfully', ['account_code_id' => $account_code_id]);
    } else {
        jsonResponse(500, 'Failed to create account code', ['error' => mysqli_error($conn)]);
    }
}

function getDetailAccountCode($conn, $account_code_id, $company_id) {
    $account_code_id = mysqli_real_escape_string($conn, $account_code_id);

    $from   = APP_SCHEMA . ".account_code ac
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = ac.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = ac.updated_by";
    $result = mysqli_query($conn, "SELECT ac.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE ac.id = '$account_code_id' AND ac.company_id = '$company_id' AND ac.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Account code not found');
        return;
    }

    $account_code = mysqli_fetch_assoc($result);
    $account_code['is_active'] = (bool)(int)$account_code['is_active'];

    jsonResponse(200, 'Account code found', $account_code);
}

function updateAccountCode($conn, $account_code_id, $input, $username, $company_id) {
    $account_code_id = mysqli_real_escape_string($conn, $account_code_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".account_code WHERE id = '$account_code_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Account code not found');
        return;
    }

    $updates = [];

    if (isset($input['account_code'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['account_code']));
        if ($val === '') { jsonResponse(400, 'account_code cannot be empty'); return; }
        $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".account_code WHERE company_id = '$company_id' AND account_code = '$val' AND id != '$account_code_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($dup) > 0) {
            jsonResponse(409, 'Account code already exists');
            return;
        }
        $updates[] = "account_code = '$val'";
    }

    if (isset($input['account_code_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['account_code_name']));
        if ($val === '') { jsonResponse(400, 'account_code_name cannot be empty'); return; }
        $updates[] = "account_code_name = '$val'";
    }

    if (array_key_exists('account_code_name_alias', $input)) {
        $val = isset($input['account_code_name_alias']) && trim($input['account_code_name_alias']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['account_code_name_alias'])) . "'"
            : 'NULL';
        $updates[] = "account_code_name_alias = $val";
    }

    if (isset($input['account_type'])) {
        if (!in_array($input['account_type'], ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) {
            jsonResponse(400, 'account_type must be asset, liability, equity, revenue, or expense');
            return;
        }
        $account_type = mysqli_real_escape_string($conn, $input['account_type']);
        $updates[] = "account_type = '$account_type'";
    }

    if (array_key_exists('parent_account_code_id', $input)) {
        if ($input['parent_account_code_id'] === null || trim((string)$input['parent_account_code_id']) === '') {
            $updates[] = "parent_account_code_id = NULL";
        } else {
            $parent_account_code_id = mysqli_real_escape_string($conn, $input['parent_account_code_id']);
            $parent_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".account_code WHERE id = '$parent_account_code_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
            if (mysqli_num_rows($parent_check) === 0) {
                jsonResponse(404, 'Parent account code not found');
                return;
            }
            $updates[] = "parent_account_code_id = '$parent_account_code_id'";
        }
    }

    if (isset($input['is_active'])) {
        $is_active = !empty($input['is_active']) ? 1 : 0;
        $updates[] = "is_active = $is_active";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".account_code SET " . implode(', ', $updates) . " WHERE id = '$account_code_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Account code updated successfully');
    } else {
        jsonResponse(500, 'Failed to update account code', ['error' => mysqli_error($conn)]);
    }
}

function deleteAccountCode($conn, $account_code_id, $username, $company_id) {
    $account_code_id = mysqli_real_escape_string($conn, $account_code_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".account_code WHERE id = '$account_code_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Account code not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".account_code SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$account_code_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Account code deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete account code', ['error' => mysqli_error($conn)]);
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

$account_code_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($account_code_id) {
        switch ($method) {
            case 'GET':
                getDetailAccountCode($conn, $account_code_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateAccountCode($conn, $account_code_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteAccountCode($conn, $account_code_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllAccountCodes($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createAccountCode($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
