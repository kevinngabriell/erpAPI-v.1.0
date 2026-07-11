<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

const WAREHOUSE_TRANSACTION_TYPES = ['stock_in', 'stock_out', 'adjustment', 'transfer'];

function getAllWarehouseTransactions($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $where = "wt.company_id = '$company_id' AND wt.deleted_at IS NULL";
    if (isset($params['transaction_type']) && in_array($params['transaction_type'], WAREHOUSE_TRANSACTION_TYPES, true)) {
        $transaction_type = mysqli_real_escape_string($conn, $params['transaction_type']);
        $where .= " AND wt.transaction_type = '$transaction_type'";
    }
    if (isset($params['customer_id']) && trim($params['customer_id']) !== '') {
        $customer_id = mysqli_real_escape_string($conn, $params['customer_id']);
        $where .= " AND wt.customer_id = '$customer_id'";
    }

    $from = APP_SCHEMA . ".warehouse_transaction wt
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = wt.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = wt.updated_by";

    $result       = mysqli_query($conn, "SELECT wt.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY wt.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM $from WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Warehouse transactions found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No warehouse transactions found');
    }
}

function createWarehouseTransaction($conn, $input, $username, $company_id) {
    $required = ['transaction_date', 'transaction_type', 'items'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    if (!in_array($input['transaction_type'], WAREHOUSE_TRANSACTION_TYPES, true)) {
        jsonResponse(400, 'transaction_type must be one of ' . implode(', ', WAREHOUSE_TRANSACTION_TYPES));
        return;
    }

    if (!is_array($input['items']) || count($input['items']) === 0) {
        jsonResponse(400, 'items must be a non-empty array');
        return;
    }

    foreach ($input['items'] as $item) {
        $item_required = ['warehouse_lot_id', 'product_id', 'quantity'];
        foreach ($item_required as $field) {
            if (!isset($item[$field]) || (is_string($item[$field]) && trim($item[$field]) === '')) {
                jsonResponse(400, "items.$field is required");
                return;
            }
        }
    }

    $transaction_date = mysqli_real_escape_string($conn, $input['transaction_date']);
    $transaction_type = mysqli_real_escape_string($conn, $input['transaction_type']);

    $customer_id_sql = 'NULL';
    if (isset($input['customer_id']) && trim($input['customer_id']) !== '') {
        $customer_id = mysqli_real_escape_string($conn, $input['customer_id']);
        $customer_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".customer WHERE id = '$customer_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($customer_check) === 0) {
            jsonResponse(404, 'Customer not found');
            return;
        }
        $customer_id_sql = "'$customer_id'";
    }

    $notes_sql = isset($input['notes']) && trim($input['notes']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['notes']) . "'" : 'NULL';

    $warehouse_transaction_id = generateUUID();
    $now                     = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO " . APP_SCHEMA . ".warehouse_transaction
                (id, company_id, transaction_date, transaction_type, customer_id, notes, created_by, created_at)
                VALUES ('$warehouse_transaction_id', '$company_id', '$transaction_date', '$transaction_type', $customer_id_sql, $notes_sql, '$username', '$now')";

        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }

        foreach ($input['items'] as $item) {
            $item_id           = generateUUID();
            $warehouse_lot_id  = mysqli_real_escape_string($conn, $item['warehouse_lot_id']);
            $product_id        = mysqli_real_escape_string($conn, $item['product_id']);
            $quantity          = (float)$item['quantity'];
            $uom_id_sql        = isset($item['uom_id']) && trim($item['uom_id']) !== '' ? "'" . mysqli_real_escape_string($conn, $item['uom_id']) . "'" : 'NULL';
            $conversion_factor_sql = isset($item['conversion_factor']) && $item['conversion_factor'] !== '' ? (float)$item['conversion_factor'] : 'NULL';
            $expired_at_sql    = isset($item['expired_at']) && trim($item['expired_at']) !== '' ? "'" . mysqli_real_escape_string($conn, $item['expired_at']) . "'" : 'NULL';
            $item_notes_sql    = isset($item['notes']) && trim($item['notes']) !== '' ? "'" . mysqli_real_escape_string($conn, $item['notes']) . "'" : 'NULL';

            $item_sql = "INSERT INTO " . APP_SCHEMA . ".warehouse_transaction_item
                         (id, warehouse_transaction_id, warehouse_lot_id, product_id, quantity, uom_id, conversion_factor, expired_at, notes, created_by, created_at)
                         VALUES ('$item_id', '$warehouse_transaction_id', '$warehouse_lot_id', '$product_id', $quantity, $uom_id_sql, $conversion_factor_sql, $expired_at_sql, $item_notes_sql, '$username', '$now')";

            if (!mysqli_query($conn, $item_sql)) {
                throw new Exception(mysqli_error($conn));
            }
        }

        $conn->commit();
        jsonResponse(201, 'Warehouse transaction created successfully', ['warehouse_transaction_id' => $warehouse_transaction_id]);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(500, 'Failed to create warehouse transaction', ['error' => $e->getMessage()]);
    }
}

function getDetailWarehouseTransaction($conn, $warehouse_transaction_id, $company_id) {
    $warehouse_transaction_id = mysqli_real_escape_string($conn, $warehouse_transaction_id);

    $from   = APP_SCHEMA . ".warehouse_transaction wt
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = wt.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = wt.updated_by";
    $result = mysqli_query($conn, "SELECT wt.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE wt.id = '$warehouse_transaction_id' AND wt.company_id = '$company_id' AND wt.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Warehouse transaction not found');
        return;
    }

    $warehouse_transaction = mysqli_fetch_assoc($result);

    $items_from   = APP_SCHEMA . ".warehouse_transaction_item wti
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = wti.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = wti.updated_by";
    $items_result = mysqli_query($conn, "SELECT wti.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $items_from WHERE wti.warehouse_transaction_id = '$warehouse_transaction_id' AND wti.deleted_at IS NULL ORDER BY wti.created_at ASC");
    $warehouse_transaction['items'] = $items_result ? mysqli_fetch_all($items_result, MYSQLI_ASSOC) : [];

    jsonResponse(200, 'Warehouse transaction found', $warehouse_transaction);
}

function updateWarehouseTransaction($conn, $warehouse_transaction_id, $input, $username, $company_id) {
    $warehouse_transaction_id = mysqli_real_escape_string($conn, $warehouse_transaction_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".warehouse_transaction WHERE id = '$warehouse_transaction_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Warehouse transaction not found');
        return;
    }

    $updates = [];

    if (isset($input['transaction_date'])) {
        $updates[] = "transaction_date = '" . mysqli_real_escape_string($conn, $input['transaction_date']) . "'";
    }

    if (isset($input['transaction_type'])) {
        if (!in_array($input['transaction_type'], WAREHOUSE_TRANSACTION_TYPES, true)) {
            jsonResponse(400, 'transaction_type must be one of ' . implode(', ', WAREHOUSE_TRANSACTION_TYPES));
            return;
        }
        $updates[] = "transaction_type = '" . mysqli_real_escape_string($conn, $input['transaction_type']) . "'";
    }

    if (isset($input['customer_id'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['customer_id']));
        $updates[] = "customer_id = " . ($val === '' ? 'NULL' : "'$val'");
    }

    if (isset($input['notes'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['notes']));
        $updates[] = "notes = " . ($val === '' ? 'NULL' : "'$val'");
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".warehouse_transaction SET " . implode(', ', $updates) . " WHERE id = '$warehouse_transaction_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Warehouse transaction updated successfully');
    } else {
        jsonResponse(500, 'Failed to update warehouse transaction', ['error' => mysqli_error($conn)]);
    }
}

function deleteWarehouseTransaction($conn, $warehouse_transaction_id, $username, $company_id) {
    $warehouse_transaction_id = mysqli_real_escape_string($conn, $warehouse_transaction_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".warehouse_transaction WHERE id = '$warehouse_transaction_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Warehouse transaction not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".warehouse_transaction SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$warehouse_transaction_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Warehouse transaction deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete warehouse transaction', ['error' => mysqli_error($conn)]);
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

$warehouse_transaction_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($warehouse_transaction_id) {
        switch ($method) {
            case 'GET':
                getDetailWarehouseTransaction($conn, $warehouse_transaction_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateWarehouseTransaction($conn, $warehouse_transaction_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteWarehouseTransaction($conn, $warehouse_transaction_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllWarehouseTransactions($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createWarehouseTransaction($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
