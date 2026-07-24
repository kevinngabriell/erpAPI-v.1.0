<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllShipmentPeriods($conn, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "sp.deleted_at IS NULL";
    if ($search) {
        $where .= " AND sp.period_name LIKE '%$search%'";
    }

    $from = APP_SCHEMA . ".shipment_period sp
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = sp.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = sp.updated_by";

    $result       = mysqli_query($conn, "SELECT sp.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY sp.sort_order ASC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".shipment_period sp WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Shipment periods found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No shipment periods found');
    }
}

function createShipmentPeriod($conn, $input, $username) {
    $required = ['period_name', 'sort_order'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $period_name = trim(mysqli_real_escape_string($conn, $input['period_name']));
    $sort_order  = (int)$input['sort_order'];

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".shipment_period WHERE period_name = '$period_name' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Shipment period already exists');
        return;
    }

    $shipment_period_id = 'shp_' . uniqid();
    $now                = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".shipment_period (id, period_name, sort_order, created_by, created_at)
            VALUES ('$shipment_period_id', '$period_name', $sort_order, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Shipment period created successfully', ['shipment_period_id' => $shipment_period_id]);
    } else {
        jsonResponse(500, 'Failed to create shipment period', ['error' => mysqli_error($conn)]);
    }
}

function getDetailShipmentPeriod($conn, $shipment_period_id) {
    $shipment_period_id = mysqli_real_escape_string($conn, $shipment_period_id);

    $from   = APP_SCHEMA . ".shipment_period sp
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = sp.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = sp.updated_by";
    $result = mysqli_query($conn, "SELECT sp.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE sp.id = '$shipment_period_id' AND sp.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Shipment period not found');
        return;
    }

    jsonResponse(200, 'Shipment period found', mysqli_fetch_assoc($result));
}

function updateShipmentPeriod($conn, $shipment_period_id, $input, $username) {
    $shipment_period_id = mysqli_real_escape_string($conn, $shipment_period_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".shipment_period WHERE id = '$shipment_period_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Shipment period not found');
        return;
    }

    $updates = [];

    if (isset($input['period_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['period_name']));
        if ($val === '') { jsonResponse(400, 'period_name cannot be empty'); return; }
        $updates[] = "period_name = '$val'";
    }

    if (isset($input['sort_order'])) {
        $updates[] = "sort_order = " . (int)$input['sort_order'];
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".shipment_period SET " . implode(', ', $updates) . " WHERE id = '$shipment_period_id'")) {
        jsonResponse(200, 'Shipment period updated successfully');
    } else {
        jsonResponse(500, 'Failed to update shipment period', ['error' => mysqli_error($conn)]);
    }
}

function deleteShipmentPeriod($conn, $shipment_period_id, $username) {
    $shipment_period_id = mysqli_real_escape_string($conn, $shipment_period_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".shipment_period WHERE id = '$shipment_period_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Shipment period not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".shipment_period SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$shipment_period_id'")) {
        jsonResponse(200, 'Shipment period deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete shipment period', ['error' => mysqli_error($conn)]);
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

$shipment_period_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($shipment_period_id) {
        switch ($method) {
            case 'GET':
                getDetailShipmentPeriod($conn, $shipment_period_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateShipmentPeriod($conn, $shipment_period_id, $input, $username);
                break;
            case 'DELETE':
                deleteShipmentPeriod($conn, $shipment_period_id, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllShipmentPeriods($conn, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createShipmentPeriod($conn, $input, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
