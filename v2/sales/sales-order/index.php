<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/audit_log.php';

function getAllSalesOrders($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "company_id = '$company_id' AND deleted_at IS NULL";
    if ($search) {
        $where .= " AND so_display_number LIKE '%$search%'";
    }
    if (isset($params['status_id']) && trim($params['status_id']) !== '') {
        $status_id = mysqli_real_escape_string($conn, $params['status_id']);
        $where .= " AND status_id = '$status_id'";
    }
    if (isset($params['customer_id']) && trim($params['customer_id']) !== '') {
        $customer_id = mysqli_real_escape_string($conn, $params['customer_id']);
        $where .= " AND customer_id = '$customer_id'";
    }
    if (isset($params['date_from']) && trim($params['date_from']) !== '') {
        $date_from = mysqli_real_escape_string($conn, $params['date_from']);
        $where .= " AND so_date >= '$date_from'";
    }
    if (isset($params['date_to']) && trim($params['date_to']) !== '') {
        $date_to = mysqli_real_escape_string($conn, $params['date_to']);
        $where .= " AND so_date <= '$date_to'";
    }

    $result       = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".sales_order WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".sales_order WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Sales orders found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No sales orders found');
    }
}

function createSalesOrder($conn, $input, $username, $company_id) {
    $required = ['so_display_number', 'so_date', 'ppn_type_id', 'customer_id', 'send_date', 'status_id', 'items'];
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
        $item_required = ['product_name', 'quantity', 'uom_id', 'currency_id', 'unit_price', 'kurs'];
        foreach ($item_required as $field) {
            if (!isset($item[$field]) || (is_string($item[$field]) && trim($item[$field]) === '')) {
                jsonResponse(400, "items.$field is required");
                return;
            }
        }
    }

    $so_display_number = trim(mysqli_real_escape_string($conn, $input['so_display_number']));
    $so_date            = mysqli_real_escape_string($conn, $input['so_date']);
    $ppn_type_id        = mysqli_real_escape_string($conn, $input['ppn_type_id']);
    $customer_id        = mysqli_real_escape_string($conn, $input['customer_id']);
    $send_date          = mysqli_real_escape_string($conn, $input['send_date']);
    $status_id          = mysqli_real_escape_string($conn, $input['status_id']);

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_order WHERE company_id = '$company_id' AND so_display_number = '$so_display_number' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Sales order already exists');
        return;
    }

    $send_to_address_sql = isset($input['send_to_address']) && trim($input['send_to_address']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['send_to_address']) . "'" : 'NULL';

    $sales_order_id = generateUUID();
    $now            = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO " . APP_SCHEMA . ".sales_order
                (id, company_id, so_display_number, so_date, ppn_type_id, customer_id, send_to_address, send_date, status_id,
                 created_by, created_at)
                VALUES
                ('$sales_order_id', '$company_id', '$so_display_number', '$so_date', '$ppn_type_id', '$customer_id', $send_to_address_sql, '$send_date', '$status_id',
                 '$username', '$now')";

        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }

        foreach ($input['items'] as $item) {
            $item_id            = generateUUID();
            $product_name        = mysqli_real_escape_string($conn, $item['product_name']);
            $quantity            = (float)$item['quantity'];
            $uom_id              = mysqli_real_escape_string($conn, $item['uom_id']);
            $currency_id         = mysqli_real_escape_string($conn, $item['currency_id']);
            $unit_price          = (float)$item['unit_price'];
            $kurs                = (float)$item['kurs'];
            $purchase_order_id_sql = isset($item['purchase_order_id']) && trim($item['purchase_order_id']) !== '' ? "'" . mysqli_real_escape_string($conn, $item['purchase_order_id']) . "'" : 'NULL';

            $item_sql = "INSERT INTO " . APP_SCHEMA . ".sales_order_item
                         (id, sales_order_id, purchase_order_id, product_name, quantity, uom_id, currency_id, unit_price, kurs, created_by, created_at)
                         VALUES ('$item_id', '$sales_order_id', $purchase_order_id_sql, '$product_name', $quantity, '$uom_id', '$currency_id', $unit_price, $kurs, '$username', '$now')";

            if (!mysqli_query($conn, $item_sql)) {
                throw new Exception(mysqli_error($conn));
            }
        }

        insertAuditLog($conn, $company_id, 'sales_order', $sales_order_id, 'created', $username);

        $conn->commit();
        jsonResponse(201, 'Sales order created successfully', ['sales_order_id' => $sales_order_id]);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(500, 'Failed to create sales order', ['error' => $e->getMessage()]);
    }
}

function getDetailSalesOrder($conn, $sales_order_id, $company_id) {
    $sales_order_id = mysqli_real_escape_string($conn, $sales_order_id);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".sales_order WHERE id = '$sales_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Sales order not found');
        return;
    }

    $sales_order = mysqli_fetch_assoc($result);

    $items_result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".sales_order_item WHERE sales_order_id = '$sales_order_id' AND deleted_at IS NULL ORDER BY created_at ASC");
    $sales_order['items'] = $items_result ? mysqli_fetch_all($items_result, MYSQLI_ASSOC) : [];

    jsonResponse(200, 'Sales order found', $sales_order);
}

function updateSalesOrder($conn, $sales_order_id, $input, $username, $company_id) {
    $sales_order_id = mysqli_real_escape_string($conn, $sales_order_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_order WHERE id = '$sales_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales order not found');
        return;
    }

    $updates = [];

    $string_fields = ['so_display_number', 'ppn_type_id', 'customer_id', 'send_to_address', 'status_id'];
    foreach ($string_fields as $field) {
        if (isset($input[$field])) {
            $val = trim(mysqli_real_escape_string($conn, $input[$field]));
            if ($val === '') { jsonResponse(400, "$field cannot be empty"); return; }
            $updates[] = "$field = '$val'";
        }
    }

    $date_fields = ['so_date', 'send_date'];
    foreach ($date_fields as $field) {
        if (isset($input[$field])) {
            $val = mysqli_real_escape_string($conn, $input[$field]);
            $updates[] = "$field = '$val'";
        }
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_order SET " . implode(', ', $updates) . " WHERE id = '$sales_order_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_order', $sales_order_id, 'updated', $username);
        jsonResponse(200, 'Sales order updated successfully');
    } else {
        jsonResponse(500, 'Failed to update sales order', ['error' => mysqli_error($conn)]);
    }
}

function deleteSalesOrder($conn, $sales_order_id, $username, $company_id) {
    $sales_order_id = mysqli_real_escape_string($conn, $sales_order_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_order WHERE id = '$sales_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales order not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_order SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$sales_order_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_order', $sales_order_id, 'deleted', $username);
        jsonResponse(200, 'Sales order deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete sales order', ['error' => mysqli_error($conn)]);
    }
}

function approveSalesOrder($conn, $sales_order_id, $input, $username, $company_id) {
    if (!isset($input['status_id']) || trim($input['status_id']) === '') {
        jsonResponse(400, 'status_id is required');
        return;
    }

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_order WHERE id = '$sales_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales order not found');
        return;
    }

    $status_id = mysqli_real_escape_string($conn, $input['status_id']);
    $now       = date('Y-m-d H:i:s');
    $notes     = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_order
            SET status_id = '$status_id', approved_by = '$username', approved_at = '$now', updated_by = '$username', updated_at = '$now'
            WHERE id = '$sales_order_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_order', $sales_order_id, 'approved', $username, $notes);
        jsonResponse(200, 'Sales order approved successfully');
    } else {
        jsonResponse(500, 'Failed to approve sales order', ['error' => mysqli_error($conn)]);
    }
}

function rejectSalesOrder($conn, $sales_order_id, $input, $username, $company_id) {
    if (!isset($input['status_id']) || trim($input['status_id']) === '') {
        jsonResponse(400, 'status_id is required');
        return;
    }

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_order WHERE id = '$sales_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales order not found');
        return;
    }

    $status_id = mysqli_real_escape_string($conn, $input['status_id']);
    $now       = date('Y-m-d H:i:s');
    $notes     = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_order
            SET status_id = '$status_id', updated_by = '$username', updated_at = '$now'
            WHERE id = '$sales_order_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_order', $sales_order_id, 'rejected', $username, $notes);
        jsonResponse(200, 'Sales order rejected successfully');
    } else {
        jsonResponse(500, 'Failed to reject sales order', ['error' => mysqli_error($conn)]);
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

$sales_order_id = !empty($action) ? $action : null;
$sub_action     = $parts[4] ?? '';

try {
    $conn = getConn();

    if ($sales_order_id && $sub_action === 'items') {
        require __DIR__ . '/items.php';

    } elseif ($sales_order_id && $sub_action !== '') {
        $input = in_array($method, ['POST', 'PUT', 'PATCH'])
            ? (json_decode(file_get_contents('php://input'), true) ?? [])
            : [];

        switch ($sub_action) {
            case 'approve':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                approveSalesOrder($conn, $sales_order_id, $input, $username, $company_id);
                break;
            case 'reject':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                rejectSalesOrder($conn, $sales_order_id, $input, $username, $company_id);
                break;
            default:
                jsonResponse(404, 'Route not found');
        }

    } elseif ($sales_order_id) {
        switch ($method) {
            case 'GET':
                getDetailSalesOrder($conn, $sales_order_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateSalesOrder($conn, $sales_order_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteSalesOrder($conn, $sales_order_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }

    } else {
        switch ($method) {
            case 'GET':
                getAllSalesOrders($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createSalesOrder($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
