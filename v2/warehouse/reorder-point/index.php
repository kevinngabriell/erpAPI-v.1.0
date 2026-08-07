<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllReorderPoints($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $where = "rp.company_id = '$company_id' AND rp.deleted_at IS NULL";
    if (isset($params['product_id']) && trim($params['product_id']) !== '') {
        $product_id = mysqli_real_escape_string($conn, $params['product_id']);
        $where .= " AND rp.product_id = '$product_id'";
    }
    if (isset($params['location_id']) && trim($params['location_id']) !== '') {
        $location_id = mysqli_real_escape_string($conn, $params['location_id']);
        $where .= " AND rp.location_id = '$location_id'";
    }

    $from = APP_SCHEMA . ".reorder_point rp
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = rp.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = rp.updated_by";

    $result       = mysqli_query($conn, "SELECT rp.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY rp.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".reorder_point rp WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Reorder points found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No reorder points found');
    }
}

function createReorderPoint($conn, $input, $username, $company_id) {
    $required = ['product_id', 'location_id', 'min_stock'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $product_id  = mysqli_real_escape_string($conn, $input['product_id']);
    $location_id = mysqli_real_escape_string($conn, $input['location_id']);
    $min_stock   = (float)$input['min_stock'];

    $product_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".product WHERE id = '$product_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($product_check) === 0) {
        jsonResponse(404, 'Product not found');
        return;
    }

    $location_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".warehouse_location WHERE id = '$location_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($location_check) === 0) {
        jsonResponse(404, 'Warehouse location not found');
        return;
    }

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".reorder_point WHERE company_id = '$company_id' AND product_id = '$product_id' AND location_id = '$location_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Reorder point already exists for this product and location');
        return;
    }

    $reorder_point_id = generateUUID();
    $now              = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".reorder_point
            (id, company_id, product_id, location_id, min_stock, created_by, created_at)
            VALUES ('$reorder_point_id', '$company_id', '$product_id', '$location_id', $min_stock, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Reorder point created successfully', ['reorder_point_id' => $reorder_point_id]);
    } else {
        jsonResponse(500, 'Failed to create reorder point', ['error' => mysqli_error($conn)]);
    }
}

function getDetailReorderPoint($conn, $reorder_point_id, $company_id) {
    $reorder_point_id = mysqli_real_escape_string($conn, $reorder_point_id);

    $from   = APP_SCHEMA . ".reorder_point rp
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = rp.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = rp.updated_by";
    $result = mysqli_query($conn, "SELECT rp.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE rp.id = '$reorder_point_id' AND rp.company_id = '$company_id' AND rp.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Reorder point not found');
        return;
    }

    jsonResponse(200, 'Reorder point found', mysqli_fetch_assoc($result));
}

function updateReorderPoint($conn, $reorder_point_id, $input, $username, $company_id) {
    $reorder_point_id = mysqli_real_escape_string($conn, $reorder_point_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".reorder_point WHERE id = '$reorder_point_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Reorder point not found');
        return;
    }

    $updates = [];

    if (isset($input['min_stock']) && $input['min_stock'] !== '') {
        $updates[] = "min_stock = " . (float)$input['min_stock'];
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".reorder_point SET " . implode(', ', $updates) . " WHERE id = '$reorder_point_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Reorder point updated successfully');
    } else {
        jsonResponse(500, 'Failed to update reorder point', ['error' => mysqli_error($conn)]);
    }
}

function deleteReorderPoint($conn, $reorder_point_id, $username, $company_id) {
    $reorder_point_id = mysqli_real_escape_string($conn, $reorder_point_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".reorder_point WHERE id = '$reorder_point_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Reorder point not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".reorder_point SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$reorder_point_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Reorder point deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete reorder point', ['error' => mysqli_error($conn)]);
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

$reorder_point_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($reorder_point_id) {
        switch ($method) {
            case 'GET':
                getDetailReorderPoint($conn, $reorder_point_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateReorderPoint($conn, $reorder_point_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteReorderPoint($conn, $reorder_point_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllReorderPoints($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createReorderPoint($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
