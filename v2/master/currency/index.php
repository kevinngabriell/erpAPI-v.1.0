<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllCurrencies($conn, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "cur.deleted_at IS NULL";
    if ($search) {
        $where .= " AND (cur.currency_code LIKE '%$search%' OR cur.currency_name LIKE '%$search%')";
    }

    $from = APP_SCHEMA . ".currency cur
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = cur.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = cur.updated_by";

    $result       = mysqli_query($conn, "SELECT cur.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY cur.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".currency cur WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Currencies found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No currencies found');
    }
}

function createCurrency($conn, $input, $username) {
    $required = ['currency_code', 'currency_symbol'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $currency_code   = strtoupper(trim(mysqli_real_escape_string($conn, $input['currency_code'])));
    $currency_symbol = trim(mysqli_real_escape_string($conn, $input['currency_symbol']));

    $currency_name_sql = isset($input['currency_name']) && trim($input['currency_name']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['currency_name'])) . "'"
        : 'NULL';

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".currency WHERE currency_code = '$currency_code' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Currency already exists');
        return;
    }

    $currency_id = generateUUID();
    $now         = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".currency (id, currency_code, currency_symbol, currency_name, created_by, created_at)
            VALUES ('$currency_id', '$currency_code', '$currency_symbol', $currency_name_sql, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Currency created successfully', ['currency_id' => $currency_id]);
    } else {
        jsonResponse(500, 'Failed to create currency', ['error' => mysqli_error($conn)]);
    }
}

function getDetailCurrency($conn, $currency_id) {
    $currency_id = mysqli_real_escape_string($conn, $currency_id);

    $from   = APP_SCHEMA . ".currency cur
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = cur.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = cur.updated_by";
    $result = mysqli_query($conn, "SELECT cur.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE cur.id = '$currency_id' AND cur.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Currency not found');
        return;
    }

    jsonResponse(200, 'Currency found', mysqli_fetch_assoc($result));
}

function updateCurrency($conn, $currency_id, $input, $username) {
    $currency_id = mysqli_real_escape_string($conn, $currency_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".currency WHERE id = '$currency_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Currency not found');
        return;
    }

    $updates = [];

    if (isset($input['currency_code'])) {
        $val = strtoupper(trim(mysqli_real_escape_string($conn, $input['currency_code'])));
        if ($val === '') { jsonResponse(400, 'currency_code cannot be empty'); return; }

        $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".currency WHERE currency_code = '$val' AND id != '$currency_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($dup) > 0) {
            jsonResponse(409, 'Currency already exists');
            return;
        }
        $updates[] = "currency_code = '$val'";
    }

    if (isset($input['currency_symbol'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['currency_symbol']));
        if ($val === '') { jsonResponse(400, 'currency_symbol cannot be empty'); return; }
        $updates[] = "currency_symbol = '$val'";
    }

    if (isset($input['currency_name'])) {
        $currency_name_sql = trim($input['currency_name']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['currency_name'])) . "'"
            : 'NULL';
        $updates[] = "currency_name = $currency_name_sql";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".currency SET " . implode(', ', $updates) . " WHERE id = '$currency_id'")) {
        jsonResponse(200, 'Currency updated successfully');
    } else {
        jsonResponse(500, 'Failed to update currency', ['error' => mysqli_error($conn)]);
    }
}

function deleteCurrency($conn, $currency_id, $username) {
    $currency_id = mysqli_real_escape_string($conn, $currency_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".currency WHERE id = '$currency_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Currency not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".currency SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$currency_id'")) {
        jsonResponse(200, 'Currency deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete currency', ['error' => mysqli_error($conn)]);
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

$currency_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($currency_id) {
        switch ($method) {
            case 'GET':
                getDetailCurrency($conn, $currency_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateCurrency($conn, $currency_id, $input, $username);
                break;
            case 'DELETE':
                deleteCurrency($conn, $currency_id, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllCurrencies($conn, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createCurrency($conn, $input, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
