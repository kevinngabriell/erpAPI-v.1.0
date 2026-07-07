<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllWarehouseLots($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $where = "company_id = '$company_id' AND deleted_at IS NULL";
    if (isset($params['product_id']) && trim($params['product_id']) !== '') {
        $product_id = mysqli_real_escape_string($conn, $params['product_id']);
        $where .= " AND product_id = '$product_id'";
    }
    if (isset($params['location_id']) && trim($params['location_id']) !== '') {
        $location_id = mysqli_real_escape_string($conn, $params['location_id']);
        $where .= " AND location_id = '$location_id'";
    }

    $result       = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".warehouse_lot WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".warehouse_lot WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Warehouse lots found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No warehouse lots found');
    }
}

function createWarehouseLot($conn, $input, $username, $company_id) {
    $required = ['product_id', 'lot_date', 'beginning_balance', 'end_balance'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $product_id        = mysqli_real_escape_string($conn, $input['product_id']);
    $lot_date          = mysqli_real_escape_string($conn, $input['lot_date']);
    $beginning_balance = (float)$input['beginning_balance'];
    $end_balance       = (float)$input['end_balance'];

    $product_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".product WHERE id = '$product_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($product_check) === 0) {
        jsonResponse(404, 'Product not found');
        return;
    }

    $location_id_sql = 'NULL';
    if (isset($input['location_id']) && trim($input['location_id']) !== '') {
        $location_id = mysqli_real_escape_string($conn, $input['location_id']);
        $location_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".warehouse_location WHERE id = '$location_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($location_check) === 0) {
            jsonResponse(404, 'Warehouse location not found');
            return;
        }
        $location_id_sql = "'$location_id'";
    }

    $warehouse_lot_id = generateUUID();
    $now              = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".warehouse_lot
            (id, company_id, location_id, product_id, lot_date, beginning_balance, end_balance, created_by, created_at)
            VALUES ('$warehouse_lot_id', '$company_id', $location_id_sql, '$product_id', '$lot_date', $beginning_balance, $end_balance, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Warehouse lot created successfully', ['warehouse_lot_id' => $warehouse_lot_id]);
    } else {
        jsonResponse(500, 'Failed to create warehouse lot', ['error' => mysqli_error($conn)]);
    }
}

function getDetailWarehouseLot($conn, $warehouse_lot_id, $company_id) {
    $warehouse_lot_id = mysqli_real_escape_string($conn, $warehouse_lot_id);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".warehouse_lot WHERE id = '$warehouse_lot_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Warehouse lot not found');
        return;
    }

    jsonResponse(200, 'Warehouse lot found', mysqli_fetch_assoc($result));
}

function updateWarehouseLot($conn, $warehouse_lot_id, $input, $username, $company_id) {
    $warehouse_lot_id = mysqli_real_escape_string($conn, $warehouse_lot_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".warehouse_lot WHERE id = '$warehouse_lot_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Warehouse lot not found');
        return;
    }

    $updates = [];

    if (isset($input['location_id'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['location_id']));
        $updates[] = "location_id = " . ($val === '' ? 'NULL' : "'$val'");
    }

    if (isset($input['lot_date'])) {
        $updates[] = "lot_date = '" . mysqli_real_escape_string($conn, $input['lot_date']) . "'";
    }
    if (isset($input['beginning_balance']) && $input['beginning_balance'] !== '') {
        $updates[] = "beginning_balance = " . (float)$input['beginning_balance'];
    }
    if (isset($input['end_balance']) && $input['end_balance'] !== '') {
        $updates[] = "end_balance = " . (float)$input['end_balance'];
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".warehouse_lot SET " . implode(', ', $updates) . " WHERE id = '$warehouse_lot_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Warehouse lot updated successfully');
    } else {
        jsonResponse(500, 'Failed to update warehouse lot', ['error' => mysqli_error($conn)]);
    }
}

function deleteWarehouseLot($conn, $warehouse_lot_id, $username, $company_id) {
    $warehouse_lot_id = mysqli_real_escape_string($conn, $warehouse_lot_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".warehouse_lot WHERE id = '$warehouse_lot_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Warehouse lot not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".warehouse_lot SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$warehouse_lot_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Warehouse lot deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete warehouse lot', ['error' => mysqli_error($conn)]);
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

$warehouse_lot_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($warehouse_lot_id) {
        switch ($method) {
            case 'GET':
                getDetailWarehouseLot($conn, $warehouse_lot_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateWarehouseLot($conn, $warehouse_lot_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteWarehouseLot($conn, $warehouse_lot_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllWarehouseLots($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createWarehouseLot($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
