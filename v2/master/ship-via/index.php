<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/permission.php';

function getAllShipVias($conn, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(15, max(1, (int)($params['limit'] ?? 15)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';
    $search = str_replace(['%', '_'], ['\%', '\_'], $search);

    $where = "sv.deleted_at IS NULL";
    if ($search) {
        $where .= " AND sv.ship_name LIKE '%$search%'";
    }

    $from = APP_SCHEMA . ".ship_via sv
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = sv.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = sv.updated_by";

    $result       = mysqli_query($conn, "SELECT sv.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY sv.ship_name ASC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".ship_via sv WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
    } else {
        $data = [];
    }

    jsonResponse(200, 'Ship vias found', [
        'data'       => $data,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

function createShipVia($conn, $input, $username) {
    $required = ['ship_name'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $ship_name = trim(mysqli_real_escape_string($conn, $input['ship_name']));

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".ship_via WHERE ship_name = '$ship_name' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Ship via already exists');
        return;
    }

    $ship_via_id = generateUUID();
    $now         = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".ship_via (id, ship_name, created_by, created_at)
            VALUES ('$ship_via_id', '$ship_name', '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Ship via created successfully', ['ship_via_id' => $ship_via_id]);
    } else {
        jsonResponse(500, 'Failed to create ship via', safeDbError($conn));
    }
}

function getDetailShipVia($conn, $ship_via_id) {
    $ship_via_id = mysqli_real_escape_string($conn, $ship_via_id);

    $from   = APP_SCHEMA . ".ship_via sv
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = sv.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = sv.updated_by";
    $result = mysqli_query($conn, "SELECT sv.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE sv.id = '$ship_via_id' AND sv.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Ship via not found');
        return;
    }

    jsonResponse(200, 'Ship via found', mysqli_fetch_assoc($result));
}

function updateShipVia($conn, $ship_via_id, $input, $username) {
    $ship_via_id = mysqli_real_escape_string($conn, $ship_via_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".ship_via WHERE id = '$ship_via_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Ship via not found');
        return;
    }

    $updates = [];

    if (isset($input['ship_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['ship_name']));
        if ($val === '') { jsonResponse(400, 'ship_name cannot be empty'); return; }
        $updates[] = "ship_name = '$val'";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".ship_via SET " . implode(', ', $updates) . " WHERE id = '$ship_via_id'")) {
        jsonResponse(200, 'Ship via updated successfully');
    } else {
        jsonResponse(500, 'Failed to update ship via', safeDbError($conn));
    }
}

function deleteShipVia($conn, $ship_via_id, $username) {
    $ship_via_id = mysqli_real_escape_string($conn, $ship_via_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".ship_via WHERE id = '$ship_via_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Ship via not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".ship_via SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$ship_via_id'")) {
        jsonResponse(200, 'Ship via deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete ship via', safeDbError($conn));
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

$ship_via_id = !empty($action) ? $action : null;

try {
    $conn = getConn();
    $company_id = mysqli_real_escape_string($conn, $company_id);
    $username = mysqli_real_escape_string($conn, $username);

    if ($ship_via_id) {
        switch ($method) {
            case 'GET':
                getDetailShipVia($conn, $ship_via_id);
                break;
            case 'PUT':
                if (!userHasPermission($conn, $app_role_id, 'master.ship_via.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateShipVia($conn, $ship_via_id, $input, $username);
                break;
            case 'DELETE':
                if (!userHasPermission($conn, $app_role_id, 'master.ship_via.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                deleteShipVia($conn, $ship_via_id, $username);
                break;
            default:
                methodNotAllowed(['GET', 'PUT', 'DELETE']);
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllShipVias($conn, $_GET);
                break;
            case 'POST':
                if (!userHasPermission($conn, $app_role_id, 'master.ship_via.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createShipVia($conn, $input, $username);
                break;
            default:
                methodNotAllowed(['GET', 'POST']);
        }
    }

} catch (Exception $e) {
    handleModuleException($e);
}
