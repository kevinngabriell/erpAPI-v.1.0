<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllWarehouseLocations($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "wloc.company_id = '$company_id' AND wloc.deleted_at IS NULL";
    if ($search) {
        $where .= " AND (wloc.location_name LIKE '%$search%' OR wloc.address LIKE '%$search%')";
    }

    $from = APP_SCHEMA . ".warehouse_location wloc
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = wloc.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = wloc.updated_by";

    $result       = mysqli_query($conn, "SELECT wloc.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY wloc.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".warehouse_location wloc WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Warehouse locations found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No warehouse locations found');
    }
}

function createWarehouseLocation($conn, $input, $username, $company_id) {
    $required = ['location_name'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $location_name = trim(mysqli_real_escape_string($conn, $input['location_name']));

    $address_sql = isset($input['address']) && trim($input['address']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['address'])) . "'"
        : 'NULL';

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".warehouse_location WHERE company_id = '$company_id' AND location_name = '$location_name' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Warehouse location already exists');
        return;
    }

    $warehouse_location_id = generateUUID();
    $now                   = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".warehouse_location (id, company_id, location_name, address, created_by, created_at)
            VALUES ('$warehouse_location_id', '$company_id', '$location_name', $address_sql, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Warehouse location created successfully', ['warehouse_location_id' => $warehouse_location_id]);
    } else {
        jsonResponse(500, 'Failed to create warehouse location', ['error' => mysqli_error($conn)]);
    }
}

function getDetailWarehouseLocation($conn, $warehouse_location_id, $company_id) {
    $warehouse_location_id = mysqli_real_escape_string($conn, $warehouse_location_id);

    $from   = APP_SCHEMA . ".warehouse_location wloc
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = wloc.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = wloc.updated_by";
    $result = mysqli_query($conn, "SELECT wloc.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE wloc.id = '$warehouse_location_id' AND wloc.company_id = '$company_id' AND wloc.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Warehouse location not found');
        return;
    }

    jsonResponse(200, 'Warehouse location found', mysqli_fetch_assoc($result));
}

function updateWarehouseLocation($conn, $warehouse_location_id, $input, $username, $company_id) {
    $warehouse_location_id = mysqli_real_escape_string($conn, $warehouse_location_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".warehouse_location WHERE id = '$warehouse_location_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Warehouse location not found');
        return;
    }

    $updates = [];

    if (isset($input['location_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['location_name']));
        if ($val === '') { jsonResponse(400, 'location_name cannot be empty'); return; }
        $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".warehouse_location WHERE company_id = '$company_id' AND location_name = '$val' AND id != '$warehouse_location_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($dup) > 0) {
            jsonResponse(409, 'Warehouse location already exists');
            return;
        }
        $updates[] = "location_name = '$val'";
    }

    if (array_key_exists('address', $input)) {
        $val = isset($input['address']) && trim($input['address']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['address'])) . "'"
            : 'NULL';
        $updates[] = "address = $val";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".warehouse_location SET " . implode(', ', $updates) . " WHERE id = '$warehouse_location_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Warehouse location updated successfully');
    } else {
        jsonResponse(500, 'Failed to update warehouse location', ['error' => mysqli_error($conn)]);
    }
}

function deleteWarehouseLocation($conn, $warehouse_location_id, $username, $company_id) {
    $warehouse_location_id = mysqli_real_escape_string($conn, $warehouse_location_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".warehouse_location WHERE id = '$warehouse_location_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Warehouse location not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".warehouse_location SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$warehouse_location_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Warehouse location deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete warehouse location', ['error' => mysqli_error($conn)]);
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

$warehouse_location_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($warehouse_location_id) {
        switch ($method) {
            case 'GET':
                getDetailWarehouseLocation($conn, $warehouse_location_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateWarehouseLocation($conn, $warehouse_location_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteWarehouseLocation($conn, $warehouse_location_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllWarehouseLocations($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createWarehouseLocation($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
