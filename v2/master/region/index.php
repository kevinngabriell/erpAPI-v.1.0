<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllRegions($conn, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "r.deleted_at IS NULL";
    if ($search) {
        $where .= " AND r.region_name LIKE '%$search%'";
    }

    $from = APP_SCHEMA . ".region r
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = r.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = r.updated_by";

    $result       = mysqli_query($conn, "SELECT r.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY r.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".region r WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Regions found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No regions found');
    }
}

function createRegion($conn, $input, $username) {
    $required = ['region_name'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $region_name = trim(mysqli_real_escape_string($conn, $input['region_name']));

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".region WHERE region_name = '$region_name' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Region already exists');
        return;
    }

    $region_id = generateUUID();
    $now       = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".region (id, region_name, created_by, created_at)
            VALUES ('$region_id', '$region_name', '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Region created successfully', ['region_id' => $region_id]);
    } else {
        jsonResponse(500, 'Failed to create region', ['error' => mysqli_error($conn)]);
    }
}

function getDetailRegion($conn, $region_id, $company_id) {
    $region_id = mysqli_real_escape_string($conn, $region_id);

    $from   = APP_SCHEMA . ".region r
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = r.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = r.updated_by";
    $result = mysqli_query($conn, "SELECT r.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE r.id = '$region_id' AND r.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Region not found');
        return;
    }

    jsonResponse(200, 'Region found', mysqli_fetch_assoc($result));
}

function updateRegion($conn, $region_id, $input, $username) {
    $region_id = mysqli_real_escape_string($conn, $region_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".region WHERE id = '$region_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Region not found');
        return;
    }

    $updates = [];

    if (isset($input['region_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['region_name']));
        if ($val === '') { jsonResponse(400, 'region_name cannot be empty'); return; }
        $updates[] = "region_name = '$val'";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".region SET " . implode(', ', $updates) . " WHERE id = '$region_id'")) {
        jsonResponse(200, 'Region updated successfully');
    } else {
        jsonResponse(500, 'Failed to update region', ['error' => mysqli_error($conn)]);
    }
}

function deleteRegion($conn, $region_id, $username) {
    $region_id = mysqli_real_escape_string($conn, $region_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".region WHERE id = '$region_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Region not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".region SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$region_id'")) {
        jsonResponse(200, 'Region deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete region', ['error' => mysqli_error($conn)]);
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

$region_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($region_id) {
        switch ($method) {
            case 'GET':
                getDetailRegion($conn, $region_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateRegion($conn, $region_id, $input, $username);
                break;
            case 'DELETE':
                deleteRegion($conn, $region_id, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllRegions($conn, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createRegion($conn, $input, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
