<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/audit_log.php';

function getAllSalesDeliveries($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "company_id = '$company_id' AND deleted_at IS NULL";
    if ($search) {
        $where .= " AND do_display_number LIKE '%$search%'";
    }
    if (isset($params['customer_id']) && trim($params['customer_id']) !== '') {
        $customer_id = mysqli_real_escape_string($conn, $params['customer_id']);
        $where .= " AND customer_id = '$customer_id'";
    }
    if (isset($params['sales_order_id']) && trim($params['sales_order_id']) !== '') {
        $sales_order_id = mysqli_real_escape_string($conn, $params['sales_order_id']);
        $where .= " AND sales_order_id = '$sales_order_id'";
    }

    $result       = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".sales_delivery WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".sales_delivery WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Sales deliveries found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No sales deliveries found');
    }
}

function createSalesDelivery($conn, $input, $username, $company_id) {
    $required = ['do_display_number', 'customer_id', 'sales_order_id', 'delivery_date', 'bill_to_address', 'ship_to_address', 'items'];
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
        $item_required = ['product_name', 'quantity'];
        foreach ($item_required as $field) {
            if (!isset($item[$field]) || (is_string($item[$field]) && trim($item[$field]) === '')) {
                jsonResponse(400, "items.$field is required");
                return;
            }
        }
    }

    $do_display_number = trim(mysqli_real_escape_string($conn, $input['do_display_number']));
    $customer_id        = mysqli_real_escape_string($conn, $input['customer_id']);
    $sales_order_id     = mysqli_real_escape_string($conn, $input['sales_order_id']);
    $delivery_date      = mysqli_real_escape_string($conn, $input['delivery_date']);
    $bill_to_address    = mysqli_real_escape_string($conn, $input['bill_to_address']);
    $ship_to_address    = mysqli_real_escape_string($conn, $input['ship_to_address']);

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_delivery WHERE company_id = '$company_id' AND do_display_number = '$do_display_number' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Sales delivery already exists');
        return;
    }

    $so_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_order WHERE id = '$sales_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($so_check) === 0) {
        jsonResponse(404, 'Sales order not found');
        return;
    }

    $container_number_sql = isset($input['container_number']) && trim($input['container_number']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['container_number']) . "'" : 'NULL';
    $bl_number_sql        = isset($input['bl_number']) && trim($input['bl_number']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['bl_number']) . "'" : 'NULL';
    $vessel_name_sql      = isset($input['vessel_name']) && trim($input['vessel_name']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['vessel_name']) . "'" : 'NULL';
    $etd_date_sql         = isset($input['etd_date']) && trim($input['etd_date']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['etd_date']) . "'" : 'NULL';
    $eta_date_sql         = isset($input['eta_date']) && trim($input['eta_date']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['eta_date']) . "'" : 'NULL';

    $sales_delivery_id = generateUUID();
    $now               = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO " . APP_SCHEMA . ".sales_delivery
                (id, company_id, do_display_number, customer_id, sales_order_id, delivery_date, bill_to_address, ship_to_address,
                 container_number, bl_number, vessel_name, etd_date, eta_date, created_by, created_at)
                VALUES
                ('$sales_delivery_id', '$company_id', '$do_display_number', '$customer_id', '$sales_order_id', '$delivery_date', '$bill_to_address', '$ship_to_address',
                 $container_number_sql, $bl_number_sql, $vessel_name_sql, $etd_date_sql, $eta_date_sql, '$username', '$now')";

        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }

        foreach ($input['items'] as $item) {
            $item_id      = generateUUID();
            $product_name = mysqli_real_escape_string($conn, $item['product_name']);
            $quantity     = (float)$item['quantity'];
            $notes_sql    = isset($item['notes']) && trim($item['notes']) !== '' ? "'" . mysqli_real_escape_string($conn, $item['notes']) . "'" : 'NULL';

            $item_sql = "INSERT INTO " . APP_SCHEMA . ".sales_delivery_item
                         (id, sales_delivery_id, product_name, quantity, notes, created_by, created_at)
                         VALUES ('$item_id', '$sales_delivery_id', '$product_name', $quantity, $notes_sql, '$username', '$now')";

            if (!mysqli_query($conn, $item_sql)) {
                throw new Exception(mysqli_error($conn));
            }
        }

        insertAuditLog($conn, $company_id, 'sales_delivery', $sales_delivery_id, 'created', $username);

        $conn->commit();
        jsonResponse(201, 'Sales delivery created successfully', ['sales_delivery_id' => $sales_delivery_id]);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(500, 'Failed to create sales delivery', ['error' => $e->getMessage()]);
    }
}

function getDetailSalesDelivery($conn, $sales_delivery_id, $company_id) {
    $sales_delivery_id = mysqli_real_escape_string($conn, $sales_delivery_id);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".sales_delivery WHERE id = '$sales_delivery_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Sales delivery not found');
        return;
    }

    $sales_delivery = mysqli_fetch_assoc($result);

    $items_result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".sales_delivery_item WHERE sales_delivery_id = '$sales_delivery_id' AND deleted_at IS NULL ORDER BY created_at ASC");
    $sales_delivery['items'] = $items_result ? mysqli_fetch_all($items_result, MYSQLI_ASSOC) : [];

    jsonResponse(200, 'Sales delivery found', $sales_delivery);
}

function updateSalesDelivery($conn, $sales_delivery_id, $input, $username, $company_id) {
    $sales_delivery_id = mysqli_real_escape_string($conn, $sales_delivery_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_delivery WHERE id = '$sales_delivery_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales delivery not found');
        return;
    }

    $updates = [];

    $string_fields = ['do_display_number', 'customer_id', 'bill_to_address', 'ship_to_address', 'container_number', 'bl_number', 'vessel_name'];
    foreach ($string_fields as $field) {
        if (isset($input[$field])) {
            $val = trim(mysqli_real_escape_string($conn, $input[$field]));
            if ($val === '') { jsonResponse(400, "$field cannot be empty"); return; }
            $updates[] = "$field = '$val'";
        }
    }

    $date_fields = ['delivery_date', 'etd_date', 'eta_date'];
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

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_delivery SET " . implode(', ', $updates) . " WHERE id = '$sales_delivery_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_delivery', $sales_delivery_id, 'updated', $username);
        jsonResponse(200, 'Sales delivery updated successfully');
    } else {
        jsonResponse(500, 'Failed to update sales delivery', ['error' => mysqli_error($conn)]);
    }
}

function deleteSalesDelivery($conn, $sales_delivery_id, $username, $company_id) {
    $sales_delivery_id = mysqli_real_escape_string($conn, $sales_delivery_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_delivery WHERE id = '$sales_delivery_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales delivery not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_delivery SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$sales_delivery_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_delivery', $sales_delivery_id, 'deleted', $username);
        jsonResponse(200, 'Sales delivery deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete sales delivery', ['error' => mysqli_error($conn)]);
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

$sales_delivery_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($sales_delivery_id) {
        switch ($method) {
            case 'GET':
                getDetailSalesDelivery($conn, $sales_delivery_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateSalesDelivery($conn, $sales_delivery_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteSalesDelivery($conn, $sales_delivery_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllSalesDeliveries($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createSalesDelivery($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
