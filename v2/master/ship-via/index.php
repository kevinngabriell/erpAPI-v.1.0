<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllShipVias($conn, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "deleted_at IS NULL";
    if ($search) {
        $where .= " AND ship_name LIKE '%$search%'";
    }

    $result       = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".ship_via WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".ship_via WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Ship vias found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No ship vias found');
    }
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
        jsonResponse(500, 'Failed to create ship via', ['error' => mysqli_error($conn)]);
    }
}

function getDetailShipVia($conn, $ship_via_id) {
    $ship_via_id = mysqli_real_escape_string($conn, $ship_via_id);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".ship_via WHERE id = '$ship_via_id' AND deleted_at IS NULL LIMIT 1");
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
        jsonResponse(500, 'Failed to update ship via', ['error' => mysqli_error($conn)]);
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
        jsonResponse(500, 'Failed to delete ship via', ['error' => mysqli_error($conn)]);
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

$ship_via_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($ship_via_id) {
        switch ($method) {
            case 'GET':
                getDetailShipVia($conn, $ship_via_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateShipVia($conn, $ship_via_id, $input, $username);
                break;
            case 'DELETE':
                deleteShipVia($conn, $ship_via_id, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllShipVias($conn, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createShipVia($conn, $input, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
