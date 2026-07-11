<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllOrigins($conn, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "o.deleted_at IS NULL";
    if ($search) {
        $where .= " AND o.origin_name LIKE '%$search%'";
    }
    if (isset($params['region_id']) && trim($params['region_id']) !== '') {
        $region_id = mysqli_real_escape_string($conn, $params['region_id']);
        $where .= " AND o.region_id = '$region_id'";
    }

    $from = APP_SCHEMA . ".origin o
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = o.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = o.updated_by";

    $result       = mysqli_query($conn, "SELECT o.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY o.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".origin o WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        $origins = mysqli_fetch_all($result, MYSQLI_ASSOC);
        foreach ($origins as &$origin) {
            $origin['is_free_trade'] = (bool)(int)$origin['is_free_trade'];
        }
        jsonResponse(200, 'Origins found', [
            'data'       => $origins,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No origins found');
    }
}

function createOrigin($conn, $input, $username) {
    $required = ['origin_name', 'region_id'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $origin_name   = trim(mysqli_real_escape_string($conn, $input['origin_name']));
    $region_id     = mysqli_real_escape_string($conn, $input['region_id']);
    $is_free_trade = !empty($input['is_free_trade']) ? 1 : 0;

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".region WHERE id = '$region_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Region not found');
        return;
    }

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".origin WHERE origin_name = '$origin_name' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Origin already exists');
        return;
    }

    $origin_id = generateUUID();
    $now       = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".origin (id, origin_name, region_id, is_free_trade, created_by, created_at)
            VALUES ('$origin_id', '$origin_name', '$region_id', $is_free_trade, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Origin created successfully', ['origin_id' => $origin_id]);
    } else {
        jsonResponse(500, 'Failed to create origin', ['error' => mysqli_error($conn)]);
    }
}

function getDetailOrigin($conn, $origin_id) {
    $origin_id = mysqli_real_escape_string($conn, $origin_id);

    $from   = APP_SCHEMA . ".origin o
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = o.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = o.updated_by";
    $result = mysqli_query($conn, "SELECT o.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE o.id = '$origin_id' AND o.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Origin not found');
        return;
    }

    $origin = mysqli_fetch_assoc($result);
    $origin['is_free_trade'] = (bool)(int)$origin['is_free_trade'];

    jsonResponse(200, 'Origin found', $origin);
}

function updateOrigin($conn, $origin_id, $input, $username) {
    $origin_id = mysqli_real_escape_string($conn, $origin_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".origin WHERE id = '$origin_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Origin not found');
        return;
    }

    $updates = [];

    if (isset($input['origin_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['origin_name']));
        if ($val === '') { jsonResponse(400, 'origin_name cannot be empty'); return; }
        $updates[] = "origin_name = '$val'";
    }

    if (isset($input['region_id'])) {
        $region_id = mysqli_real_escape_string($conn, $input['region_id']);
        $region_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".region WHERE id = '$region_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($region_check) === 0) {
            jsonResponse(404, 'Region not found');
            return;
        }
        $updates[] = "region_id = '$region_id'";
    }

    if (isset($input['is_free_trade'])) {
        $is_free_trade = !empty($input['is_free_trade']) ? 1 : 0;
        $updates[] = "is_free_trade = $is_free_trade";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".origin SET " . implode(', ', $updates) . " WHERE id = '$origin_id'")) {
        jsonResponse(200, 'Origin updated successfully');
    } else {
        jsonResponse(500, 'Failed to update origin', ['error' => mysqli_error($conn)]);
    }
}

function deleteOrigin($conn, $origin_id, $username) {
    $origin_id = mysqli_real_escape_string($conn, $origin_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".origin WHERE id = '$origin_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Origin not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".origin SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$origin_id'")) {
        jsonResponse(200, 'Origin deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete origin', ['error' => mysqli_error($conn)]);
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

$origin_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($origin_id) {
        switch ($method) {
            case 'GET':
                getDetailOrigin($conn, $origin_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateOrigin($conn, $origin_id, $input, $username);
                break;
            case 'DELETE':
                deleteOrigin($conn, $origin_id, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllOrigins($conn, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createOrigin($conn, $input, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
