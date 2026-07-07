<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/audit_log.php';

function getAllSalesProfits($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $where = "company_id = '$company_id' AND deleted_at IS NULL";
    if (isset($params['customer_id']) && trim($params['customer_id']) !== '') {
        $customer_id = mysqli_real_escape_string($conn, $params['customer_id']);
        $where .= " AND customer_id = '$customer_id'";
    }
    if (isset($params['sales_order_id']) && trim($params['sales_order_id']) !== '') {
        $sales_order_id = mysqli_real_escape_string($conn, $params['sales_order_id']);
        $where .= " AND sales_order_id = '$sales_order_id'";
    }

    $result       = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".sales_profit WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".sales_profit WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Sales profits found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No sales profits found');
    }
}

function createSalesProfit($conn, $input, $username, $company_id) {
    $required = ['sales_order_id', 'customer_id', 'items'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    if (!is_array($input['items']) || count($input['items']) === 0) {
        jsonResponse(400, 'items must be a non-empty array');
        return;
    }

    foreach ($input['items'] as $item) {
        $item_required = ['product_name', 'quantity', 'price', 'landed_cost'];
        foreach ($item_required as $field) {
            if (!isset($item[$field]) || (is_string($item[$field]) && trim($item[$field]) === '')) {
                jsonResponse(400, "items.$field is required");
                return;
            }
        }
    }

    $sales_order_id = mysqli_real_escape_string($conn, $input['sales_order_id']);
    $customer_id    = mysqli_real_escape_string($conn, $input['customer_id']);

    $so_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_order WHERE id = '$sales_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($so_check) === 0) {
        jsonResponse(404, 'Sales order not found');
        return;
    }

    $sales_profit_id = generateUUID();
    $now             = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO " . APP_SCHEMA . ".sales_profit
                (id, company_id, sales_order_id, customer_id, created_by, created_at)
                VALUES
                ('$sales_profit_id', '$company_id', '$sales_order_id', '$customer_id', '$username', '$now')";

        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }

        foreach ($input['items'] as $item) {
            $item_id      = generateUUID();
            $product_name = mysqli_real_escape_string($conn, $item['product_name']);
            $quantity     = (float)$item['quantity'];
            $price        = (float)$item['price'];
            $landed_cost  = (float)$item['landed_cost'];
            $purchase_order_id_sql = isset($item['purchase_order_id']) && trim($item['purchase_order_id']) !== '' ? "'" . mysqli_real_escape_string($conn, $item['purchase_order_id']) . "'" : 'NULL';

            $item_sql = "INSERT INTO " . APP_SCHEMA . ".sales_profit_item
                         (id, sales_profit_id, purchase_order_id, product_name, quantity, price, landed_cost, created_by, created_at)
                         VALUES ('$item_id', '$sales_profit_id', $purchase_order_id_sql, '$product_name', $quantity, $price, $landed_cost, '$username', '$now')";

            if (!mysqli_query($conn, $item_sql)) {
                throw new Exception(mysqli_error($conn));
            }
        }

        insertAuditLog($conn, $company_id, 'sales_profit', $sales_profit_id, 'created', $username);

        $conn->commit();
        jsonResponse(201, 'Sales profit created successfully', ['sales_profit_id' => $sales_profit_id]);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(500, 'Failed to create sales profit', ['error' => $e->getMessage()]);
    }
}

function getDetailSalesProfit($conn, $sales_profit_id, $company_id) {
    $sales_profit_id = mysqli_real_escape_string($conn, $sales_profit_id);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".sales_profit WHERE id = '$sales_profit_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Sales profit not found');
        return;
    }

    $sales_profit = mysqli_fetch_assoc($result);

    $items_result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".sales_profit_item WHERE sales_profit_id = '$sales_profit_id' AND deleted_at IS NULL ORDER BY created_at ASC");
    $sales_profit['items'] = $items_result ? mysqli_fetch_all($items_result, MYSQLI_ASSOC) : [];

    jsonResponse(200, 'Sales profit found', $sales_profit);
}

function updateSalesProfit($conn, $sales_profit_id, $input, $username, $company_id) {
    $sales_profit_id = mysqli_real_escape_string($conn, $sales_profit_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_profit WHERE id = '$sales_profit_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales profit not found');
        return;
    }

    $updates = [];

    if (isset($input['customer_id'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['customer_id']));
        if ($val === '') { jsonResponse(400, 'customer_id cannot be empty'); return; }
        $updates[] = "customer_id = '$val'";
    }

    if (isset($input['sales_order_id'])) {
        $sales_order_id = trim(mysqli_real_escape_string($conn, $input['sales_order_id']));
        if ($sales_order_id === '') { jsonResponse(400, 'sales_order_id cannot be empty'); return; }

        $so_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_order WHERE id = '$sales_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($so_check) === 0) {
            jsonResponse(404, 'Sales order not found');
            return;
        }

        $updates[] = "sales_order_id = '$sales_order_id'";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_profit SET " . implode(', ', $updates) . " WHERE id = '$sales_profit_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_profit', $sales_profit_id, 'updated', $username);
        jsonResponse(200, 'Sales profit updated successfully');
    } else {
        jsonResponse(500, 'Failed to update sales profit', ['error' => mysqli_error($conn)]);
    }
}

function deleteSalesProfit($conn, $sales_profit_id, $username, $company_id) {
    $sales_profit_id = mysqli_real_escape_string($conn, $sales_profit_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_profit WHERE id = '$sales_profit_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales profit not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_profit SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$sales_profit_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_profit', $sales_profit_id, 'deleted', $username);
        jsonResponse(200, 'Sales profit deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete sales profit', ['error' => mysqli_error($conn)]);
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

$sales_profit_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($sales_profit_id) {
        switch ($method) {
            case 'GET':
                getDetailSalesProfit($conn, $sales_profit_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateSalesProfit($conn, $sales_profit_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteSalesProfit($conn, $sales_profit_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllSalesProfits($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createSalesProfit($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
