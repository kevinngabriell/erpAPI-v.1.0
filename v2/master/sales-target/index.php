<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/permission.php';

function getAllSalesTargets($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(15, max(1, (int)($params['limit'] ?? 15)));
    $offset = ($page - 1) * $limit;

    $where = "st.company_id = '$company_id' AND st.deleted_at IS NULL";
    if (isset($params['target_year']) && trim($params['target_year']) !== '') {
        $target_year = (int)$params['target_year'];
        $where .= " AND st.target_year = $target_year";
    }

    $from = APP_SCHEMA . ".sales_target st
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = st.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = st.updated_by";

    $result       = mysqli_query($conn, "SELECT st.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY st.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".sales_target st WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
    } else {
        $data = [];
    }

    jsonResponse(200, 'Sales targets found', [
        'data'       => $data,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

function createSalesTarget($conn, $input, $username, $company_id) {
    $required = ['target_year', 'target_value'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    if (!preg_match('/^\d{4}$/', (string)$input['target_year'])) {
        jsonResponse(400, 'target_year must be a 4-digit year');
        return;
    }

    $target_year  = (int)$input['target_year'];
    $target_value = (float)$input['target_value'];

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_target WHERE company_id = '$company_id' AND target_year = $target_year AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Sales target already exists for this year');
        return;
    }

    $sales_target_id = generateUUID();
    $now              = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".sales_target (id, company_id, target_year, target_value, created_by, created_at)
            VALUES ('$sales_target_id', '$company_id', $target_year, $target_value, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Sales target created successfully', ['sales_target_id' => $sales_target_id]);
    } else {
        jsonResponse(500, 'Failed to create sales target', safeDbError($conn));
    }
}

function getDetailSalesTarget($conn, $sales_target_id, $company_id) {
    $sales_target_id = mysqli_real_escape_string($conn, $sales_target_id);

    $from   = APP_SCHEMA . ".sales_target st
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = st.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = st.updated_by";
    $result = mysqli_query($conn, "SELECT st.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE st.id = '$sales_target_id' AND st.company_id = '$company_id' AND st.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Sales target not found');
        return;
    }

    jsonResponse(200, 'Sales target found', mysqli_fetch_assoc($result));
}

function updateSalesTarget($conn, $sales_target_id, $input, $username, $company_id) {
    $sales_target_id = mysqli_real_escape_string($conn, $sales_target_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_target WHERE id = '$sales_target_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales target not found');
        return;
    }

    $updates = [];

    if (isset($input['target_year'])) {
        if (!preg_match('/^\d{4}$/', (string)$input['target_year'])) {
            jsonResponse(400, 'target_year must be a 4-digit year');
            return;
        }
        $target_year = (int)$input['target_year'];
        $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_target WHERE company_id = '$company_id' AND target_year = $target_year AND id != '$sales_target_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($dup) > 0) {
            jsonResponse(409, 'Sales target already exists for this year');
            return;
        }
        $updates[] = "target_year = $target_year";
    }

    if (isset($input['target_value'])) {
        $target_value = (float)$input['target_value'];
        $updates[] = "target_value = $target_value";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_target SET " . implode(', ', $updates) . " WHERE id = '$sales_target_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Sales target updated successfully');
    } else {
        jsonResponse(500, 'Failed to update sales target', safeDbError($conn));
    }
}

function deleteSalesTarget($conn, $sales_target_id, $username, $company_id) {
    $sales_target_id = mysqli_real_escape_string($conn, $sales_target_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_target WHERE id = '$sales_target_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales target not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_target SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$sales_target_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Sales target deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete sales target', safeDbError($conn));
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

$sales_target_id = !empty($action) ? $action : null;

try {
    $conn = getConn();
    $company_id = mysqli_real_escape_string($conn, $company_id);
    $username = mysqli_real_escape_string($conn, $username);

    if ($sales_target_id) {
        switch ($method) {
            case 'GET':
                getDetailSalesTarget($conn, $sales_target_id, $company_id);
                break;
            case 'PUT':
                if (!userHasPermission($conn, $app_role_id, 'master.sales_target.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateSalesTarget($conn, $sales_target_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                if (!userHasPermission($conn, $app_role_id, 'master.sales_target.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                deleteSalesTarget($conn, $sales_target_id, $username, $company_id);
                break;
            default:
                methodNotAllowed(['GET', 'PUT', 'DELETE']);
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllSalesTargets($conn, $company_id, $_GET);
                break;
            case 'POST':
                if (!userHasPermission($conn, $app_role_id, 'master.sales_target.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createSalesTarget($conn, $input, $username, $company_id);
                break;
            default:
                methodNotAllowed(['GET', 'POST']);
        }
    }

} catch (Exception $e) {
    handleModuleException($e);
}
