<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/permission.php';

function getAllPaymentMethods($conn, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(15, max(1, (int)($params['limit'] ?? 15)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';
    $search = str_replace(['%', '_'], ['\%', '\_'], $search);

    $where = "pm.deleted_at IS NULL";
    if ($search) {
        $where .= " AND pm.method_name LIKE '%$search%'";
    }

    $from = APP_SCHEMA . ".payment_method pm
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = pm.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = pm.updated_by";

    $result       = mysqli_query($conn, "SELECT pm.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY pm.method_name ASC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".payment_method pm WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
    } else {
        $data = [];
    }

    jsonResponse(200, 'Payment methods found', [
        'data'       => $data,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

function createPaymentMethod($conn, $input, $username) {
    $required = ['method_name'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $method_name = trim(mysqli_real_escape_string($conn, $input['method_name']));

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".payment_method WHERE method_name = '$method_name' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Payment method already exists');
        return;
    }

    $payment_method_id = generateUUID();
    $now                = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".payment_method (id, method_name, created_by, created_at)
            VALUES ('$payment_method_id', '$method_name', '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Payment method created successfully', ['payment_method_id' => $payment_method_id]);
    } else {
        jsonResponse(500, 'Failed to create payment method', safeDbError($conn));
    }
}

function getDetailPaymentMethod($conn, $payment_method_id) {
    $payment_method_id = mysqli_real_escape_string($conn, $payment_method_id);

    $from   = APP_SCHEMA . ".payment_method pm
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = pm.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = pm.updated_by";
    $result = mysqli_query($conn, "SELECT pm.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE pm.id = '$payment_method_id' AND pm.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Payment method not found');
        return;
    }

    jsonResponse(200, 'Payment method found', mysqli_fetch_assoc($result));
}

function updatePaymentMethod($conn, $payment_method_id, $input, $username) {
    $payment_method_id = mysqli_real_escape_string($conn, $payment_method_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".payment_method WHERE id = '$payment_method_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Payment method not found');
        return;
    }

    $updates = [];

    if (isset($input['method_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['method_name']));
        if ($val === '') { jsonResponse(400, 'method_name cannot be empty'); return; }
        $updates[] = "method_name = '$val'";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".payment_method SET " . implode(', ', $updates) . " WHERE id = '$payment_method_id'")) {
        jsonResponse(200, 'Payment method updated successfully');
    } else {
        jsonResponse(500, 'Failed to update payment method', safeDbError($conn));
    }
}

function deletePaymentMethod($conn, $payment_method_id, $username) {
    $payment_method_id = mysqli_real_escape_string($conn, $payment_method_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".payment_method WHERE id = '$payment_method_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Payment method not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".payment_method SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$payment_method_id'")) {
        jsonResponse(200, 'Payment method deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete payment method', safeDbError($conn));
    }
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser    = requireAuth();
$method      = $_SERVER['REQUEST_METHOD'];
$company_id  = $authUser['company_id'] ?? null;
$username    = $authUser['sub'] ?? $authUser['user_id'] ?? null;
$app_role_id = $authUser['app_role_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

$payment_method_id = !empty($action) ? $action : null;

try {
    $conn = getConn();
    $company_id = mysqli_real_escape_string($conn, $company_id);
    $username = mysqli_real_escape_string($conn, $username);

    if ($payment_method_id) {
        switch ($method) {
            case 'GET':
                getDetailPaymentMethod($conn, $payment_method_id);
                break;
            case 'PUT':
                if (!userHasPermission($conn, $app_role_id, 'master.payment_method.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updatePaymentMethod($conn, $payment_method_id, $input, $username);
                break;
            case 'DELETE':
                if (!userHasPermission($conn, $app_role_id, 'master.payment_method.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                deletePaymentMethod($conn, $payment_method_id, $username);
                break;
            default:
                methodNotAllowed(['GET', 'PUT', 'DELETE']);
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllPaymentMethods($conn, $_GET);
                break;
            case 'POST':
                if (!userHasPermission($conn, $app_role_id, 'master.payment_method.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createPaymentMethod($conn, $input, $username);
                break;
            default:
                methodNotAllowed(['GET', 'POST']);
        }
    }

} catch (Exception $e) {
    handleModuleException($e);
}
