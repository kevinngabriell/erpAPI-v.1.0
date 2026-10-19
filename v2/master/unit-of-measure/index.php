<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/permission.php';

function getAllUnitOfMeasures($conn, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(15, max(1, (int)($params['limit'] ?? 15)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';
    $search = str_replace(['%', '_'], ['\%', '\_'], $search);

    $where = "uom.deleted_at IS NULL";
    if ($search) {
        $where .= " AND uom.uom_name LIKE '%$search%'";
    }

    $from = APP_SCHEMA . ".unit_of_measure uom
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = uom.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = uom.updated_by";

    $result       = mysqli_query($conn, "SELECT uom.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY uom.uom_name ASC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".unit_of_measure uom WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
    } else {
        $data = [];
    }

    jsonResponse(200, 'Unit of measures found', [
        'data'       => $data,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

function createUnitOfMeasure($conn, $input, $username) {
    $required = ['uom_name'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $uom_name = trim(mysqli_real_escape_string($conn, $input['uom_name']));

    $conversion_factor_sql = isset($input['conversion_factor']) && $input['conversion_factor'] !== ''
        ? (float)$input['conversion_factor']
        : 'NULL';

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".unit_of_measure WHERE uom_name = '$uom_name' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Unit of measure already exists');
        return;
    }

    $unit_of_measure_id = generateUUID();
    $now                = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".unit_of_measure (id, uom_name, conversion_factor, created_by, created_at)
            VALUES ('$unit_of_measure_id', '$uom_name', $conversion_factor_sql, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Unit of measure created successfully', ['unit_of_measure_id' => $unit_of_measure_id]);
    } else {
        jsonResponse(500, 'Failed to create unit of measure', safeDbError($conn));
    }
}

function getDetailUnitOfMeasure($conn, $unit_of_measure_id) {
    $unit_of_measure_id = mysqli_real_escape_string($conn, $unit_of_measure_id);

    $from   = APP_SCHEMA . ".unit_of_measure uom
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = uom.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = uom.updated_by";
    $result = mysqli_query($conn, "SELECT uom.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE uom.id = '$unit_of_measure_id' AND uom.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Unit of measure not found');
        return;
    }

    jsonResponse(200, 'Unit of measure found', mysqli_fetch_assoc($result));
}

function updateUnitOfMeasure($conn, $unit_of_measure_id, $input, $username) {
    $unit_of_measure_id = mysqli_real_escape_string($conn, $unit_of_measure_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".unit_of_measure WHERE id = '$unit_of_measure_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Unit of measure not found');
        return;
    }

    $updates = [];

    if (isset($input['uom_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['uom_name']));
        if ($val === '') { jsonResponse(400, 'uom_name cannot be empty'); return; }
        $updates[] = "uom_name = '$val'";
    }

    if (isset($input['conversion_factor'])) {
        $conversion_factor_sql = $input['conversion_factor'] !== '' ? (float)$input['conversion_factor'] : 'NULL';
        $updates[] = "conversion_factor = $conversion_factor_sql";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".unit_of_measure SET " . implode(', ', $updates) . " WHERE id = '$unit_of_measure_id'")) {
        jsonResponse(200, 'Unit of measure updated successfully');
    } else {
        jsonResponse(500, 'Failed to update unit of measure', safeDbError($conn));
    }
}

function deleteUnitOfMeasure($conn, $unit_of_measure_id, $username) {
    $unit_of_measure_id = mysqli_real_escape_string($conn, $unit_of_measure_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".unit_of_measure WHERE id = '$unit_of_measure_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Unit of measure not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".unit_of_measure SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$unit_of_measure_id'")) {
        jsonResponse(200, 'Unit of measure deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete unit of measure', safeDbError($conn));
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

$unit_of_measure_id = !empty($action) ? $action : null;

try {
    $conn = getConn();
    $company_id = mysqli_real_escape_string($conn, $company_id);
    $username = mysqli_real_escape_string($conn, $username);

    if ($unit_of_measure_id) {
        switch ($method) {
            case 'GET':
                getDetailUnitOfMeasure($conn, $unit_of_measure_id);
                break;
            case 'PUT':
                if (!userHasPermission($conn, $app_role_id, 'master.unit_of_measure.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateUnitOfMeasure($conn, $unit_of_measure_id, $input, $username);
                break;
            case 'DELETE':
                if (!userHasPermission($conn, $app_role_id, 'master.unit_of_measure.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                deleteUnitOfMeasure($conn, $unit_of_measure_id, $username);
                break;
            default:
                methodNotAllowed(['GET', 'PUT', 'DELETE']);
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllUnitOfMeasures($conn, $_GET);
                break;
            case 'POST':
                if (!userHasPermission($conn, $app_role_id, 'master.unit_of_measure.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createUnitOfMeasure($conn, $input, $username);
                break;
            default:
                methodNotAllowed(['GET', 'POST']);
        }
    }

} catch (Exception $e) {
    handleModuleException($e);
}
