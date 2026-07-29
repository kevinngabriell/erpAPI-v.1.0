<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllSalesStatuses($conn, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "ss.deleted_at IS NULL";
    if ($search) {
        $where .= " AND ss.status_name LIKE '%$search%'";
    }

    $from = APP_SCHEMA . ".sales_status ss
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = ss.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = ss.updated_by";

    $result       = mysqli_query($conn, "SELECT ss.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY ss.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".sales_status ss WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Sales statuses found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No sales statuses found');
    }
}

function createSalesStatus($conn, $input, $username) {
    $required = ['status_name'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $status_name = trim(mysqli_real_escape_string($conn, $input['status_name']));

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_status WHERE status_name = '$status_name' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Sales status already exists');
        return;
    }

    $sales_status_id = generateUUID();
    $now              = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".sales_status (id, status_name, created_by, created_at)
            VALUES ('$sales_status_id', '$status_name', '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Sales status created successfully', ['sales_status_id' => $sales_status_id]);
    } else {
        jsonResponse(500, 'Failed to create sales status', ['error' => mysqli_error($conn)]);
    }
}

function getDetailSalesStatus($conn, $sales_status_id) {
    $sales_status_id = mysqli_real_escape_string($conn, $sales_status_id);

    $from   = APP_SCHEMA . ".sales_status ss
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = ss.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = ss.updated_by";
    $result = mysqli_query($conn, "SELECT ss.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE ss.id = '$sales_status_id' AND ss.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Sales status not found');
        return;
    }

    jsonResponse(200, 'Sales status found', mysqli_fetch_assoc($result));
}

function updateSalesStatus($conn, $sales_status_id, $input, $username) {
    $sales_status_id = mysqli_real_escape_string($conn, $sales_status_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_status WHERE id = '$sales_status_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales status not found');
        return;
    }

    $updates = [];

    if (isset($input['status_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['status_name']));
        if ($val === '') { jsonResponse(400, 'status_name cannot be empty'); return; }
        $updates[] = "status_name = '$val'";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_status SET " . implode(', ', $updates) . " WHERE id = '$sales_status_id'")) {
        jsonResponse(200, 'Sales status updated successfully');
    } else {
        jsonResponse(500, 'Failed to update sales status', ['error' => mysqli_error($conn)]);
    }
}

function deleteSalesStatus($conn, $sales_status_id, $username) {
    $sales_status_id = mysqli_real_escape_string($conn, $sales_status_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_status WHERE id = '$sales_status_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales status not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_status SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$sales_status_id'")) {
        jsonResponse(200, 'Sales status deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete sales status', ['error' => mysqli_error($conn)]);
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

$sales_status_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($sales_status_id) {
        switch ($method) {
            case 'GET':
                getDetailSalesStatus($conn, $sales_status_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateSalesStatus($conn, $sales_status_id, $input, $username);
                break;
            case 'DELETE':
                deleteSalesStatus($conn, $sales_status_id, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllSalesStatuses($conn, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createSalesStatus($conn, $input, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
