<?php

function assertSalesOrderBelongsToCompany($conn, $sales_order_id, $company_id) {
    $sales_order_id = mysqli_real_escape_string($conn, $sales_order_id);
    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_order WHERE id = '$sales_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales order not found');
        return;
    }
}

function getAllSalesOrderItems($conn, $sales_order_id, $company_id, $params = []) {
    assertSalesOrderBelongsToCompany($conn, $sales_order_id, $company_id);

    $sales_order_id = mysqli_real_escape_string($conn, $sales_order_id);

    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(15, max(1, (int)($params['limit'] ?? 15)));
    $offset = ($page - 1) * $limit;

    $from   = APP_SCHEMA . ".sales_order_item soi
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = soi.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = soi.updated_by";
    $result = mysqli_query($conn, "SELECT soi.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE soi.sales_order_id = '$sales_order_id' AND soi.deleted_at IS NULL ORDER BY soi.created_at ASC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM $from WHERE soi.sales_order_id = '$sales_order_id' AND soi.deleted_at IS NULL");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    jsonResponse(200, 'Sales order items found', [
        'data'       => $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [],
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

function createSalesOrderItem($conn, $sales_order_id, $input, $username, $company_id) {
    assertSalesOrderBelongsToCompany($conn, $sales_order_id, $company_id);

    $required = ['product_name', 'quantity', 'uom_id', 'currency_id', 'unit_price', 'kurs'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $product_name = trim(mysqli_real_escape_string($conn, $input['product_name']));
    $quantity     = mysqli_real_escape_string($conn, (string)$input['quantity']);
    $uom_id       = mysqli_real_escape_string($conn, $input['uom_id']);
    $currency_id  = mysqli_real_escape_string($conn, $input['currency_id']);
    $unit_price   = mysqli_real_escape_string($conn, (string)$input['unit_price']);
    $kurs         = mysqli_real_escape_string($conn, (string)$input['kurs']);
    $purchase_order_id_sql = isset($input['purchase_order_id']) && trim($input['purchase_order_id']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['purchase_order_id']) . "'" : 'NULL';

    $item_id = generateUUID();
    $now     = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".sales_order_item
            (id, sales_order_id, purchase_order_id, product_name, quantity, uom_id, currency_id, unit_price, kurs, created_by, created_at)
            VALUES ('$item_id', '$sales_order_id', $purchase_order_id_sql, '$product_name', $quantity, '$uom_id', '$currency_id', $unit_price, $kurs, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Sales order item created successfully', ['sales_order_item_id' => $item_id]);
    } else {
        jsonResponse(500, 'Failed to create sales order item', safeDbError($conn));
    }
}

function getDetailSalesOrderItem($conn, $sales_order_id, $sales_order_item_id, $company_id) {
    assertSalesOrderBelongsToCompany($conn, $sales_order_id, $company_id);

    $sales_order_item_id = mysqli_real_escape_string($conn, $sales_order_item_id);

    $from   = APP_SCHEMA . ".sales_order_item soi
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = soi.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = soi.updated_by";
    $result = mysqli_query($conn, "SELECT soi.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE soi.id = '$sales_order_item_id' AND soi.sales_order_id = '$sales_order_id' AND soi.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Sales order item not found');
        return;
    }

    jsonResponse(200, 'Sales order item found', mysqli_fetch_assoc($result));
}

function updateSalesOrderItem($conn, $sales_order_id, $sales_order_item_id, $input, $username, $company_id) {
    assertSalesOrderBelongsToCompany($conn, $sales_order_id, $company_id);

    $sales_order_item_id = mysqli_real_escape_string($conn, $sales_order_item_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_order_item WHERE id = '$sales_order_item_id' AND sales_order_id = '$sales_order_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales order item not found');
        return;
    }

    $updates = [];

    if (isset($input['product_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['product_name']));
        if ($val === '') { jsonResponse(400, 'product_name cannot be empty'); return; }
        $updates[] = "product_name = '$val'";
    }

    $string_fields = ['uom_id', 'currency_id'];
    foreach ($string_fields as $field) {
        if (isset($input[$field])) {
            $val = trim(mysqli_real_escape_string($conn, $input[$field]));
            if ($val === '') { jsonResponse(400, "$field cannot be empty"); return; }
            $updates[] = "$field = '$val'";
        }
    }

    if (array_key_exists('purchase_order_id', $input)) {
        $updates[] = ($input['purchase_order_id'] !== null && trim($input['purchase_order_id']) !== '')
            ? "purchase_order_id = '" . mysqli_real_escape_string($conn, $input['purchase_order_id']) . "'"
            : "purchase_order_id = NULL";
    }

    $numeric_fields = ['quantity', 'unit_price', 'kurs'];
    foreach ($numeric_fields as $field) {
        if (isset($input[$field]) && $input[$field] !== '') {
            $updates[] = "$field = '" . mysqli_real_escape_string($conn, (string)$input[$field]) . "'";
        }
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_order_item SET " . implode(', ', $updates) . " WHERE id = '$sales_order_item_id' AND sales_order_id = '$sales_order_id'")) {
        jsonResponse(200, 'Sales order item updated successfully');
    } else {
        jsonResponse(500, 'Failed to update sales order item', safeDbError($conn));
    }
}

function deleteSalesOrderItem($conn, $sales_order_id, $sales_order_item_id, $username, $company_id) {
    assertSalesOrderBelongsToCompany($conn, $sales_order_id, $company_id);

    $sales_order_item_id = mysqli_real_escape_string($conn, $sales_order_item_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_order_item WHERE id = '$sales_order_item_id' AND sales_order_id = '$sales_order_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales order item not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_order_item SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$sales_order_item_id' AND sales_order_id = '$sales_order_id'")) {
        jsonResponse(200, 'Sales order item deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete sales order item', safeDbError($conn));
    }
}

$sales_order_item_id = $parts[5] ?? null;

if ($sales_order_item_id) {
    switch ($method) {
        case 'GET':
            getDetailSalesOrderItem($conn, $sales_order_id, $sales_order_item_id, $company_id);
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            updateSalesOrderItem($conn, $sales_order_id, $sales_order_item_id, $input, $username, $company_id);
            break;
        case 'DELETE':
            deleteSalesOrderItem($conn, $sales_order_id, $sales_order_item_id, $username, $company_id);
            break;
        default:
            methodNotAllowed(['GET', 'PUT', 'DELETE']);
    }
} else {
    switch ($method) {
        case 'GET':
            getAllSalesOrderItems($conn, $sales_order_id, $company_id, $_GET);
            break;
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createSalesOrderItem($conn, $sales_order_id, $input, $username, $company_id);
            break;
        default:
            methodNotAllowed(['GET', 'POST']);
    }
}
