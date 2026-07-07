<?php

function assertPurchaseOrderBelongsToCompany($conn, $purchase_order_id, $company_id) {
    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_order WHERE id = '$purchase_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase order not found');
    }
}

function getAllPurchaseOrderItems($conn, $purchase_order_id, $company_id) {
    assertPurchaseOrderBelongsToCompany($conn, $purchase_order_id, $company_id);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".purchase_order_item WHERE purchase_order_id = '$purchase_order_id' AND deleted_at IS NULL ORDER BY created_at ASC");

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Purchase order items found', ['data' => mysqli_fetch_all($result, MYSQLI_ASSOC)]);
    } else {
        jsonResponse(404, 'No purchase order items found');
    }
}

function createPurchaseOrderItem($conn, $purchase_order_id, $input, $username, $company_id) {
    assertPurchaseOrderBelongsToCompany($conn, $purchase_order_id, $company_id);

    $required = ['product_name', 'quantity', 'packaging_size', 'unit_price'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $product_name   = trim(mysqli_real_escape_string($conn, $input['product_name']));
    $quantity       = (float)$input['quantity'];
    $packaging_size = (float)$input['packaging_size'];
    $unit_price     = (float)$input['unit_price'];
    $vat            = isset($input['vat']) && $input['vat'] !== '' ? (float)$input['vat'] : 0;
    $total          = isset($input['total']) && $input['total'] !== '' ? (float)$input['total'] : ($quantity * $unit_price) + $vat;

    $item_id = generateUUID();
    $now     = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".purchase_order_item
            (id, purchase_order_id, product_name, quantity, packaging_size, unit_price, vat, total, created_by, created_at)
            VALUES ('$item_id', '$purchase_order_id', '$product_name', $quantity, $packaging_size, $unit_price, $vat, $total, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Purchase order item created successfully', ['purchase_order_item_id' => $item_id]);
    } else {
        jsonResponse(500, 'Failed to create purchase order item', ['error' => mysqli_error($conn)]);
    }
}

function getDetailPurchaseOrderItem($conn, $purchase_order_id, $purchase_order_item_id, $company_id) {
    assertPurchaseOrderBelongsToCompany($conn, $purchase_order_id, $company_id);

    $purchase_order_item_id = mysqli_real_escape_string($conn, $purchase_order_item_id);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".purchase_order_item WHERE id = '$purchase_order_item_id' AND purchase_order_id = '$purchase_order_id' AND deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Purchase order item not found');
        return;
    }

    jsonResponse(200, 'Purchase order item found', mysqli_fetch_assoc($result));
}

function updatePurchaseOrderItem($conn, $purchase_order_id, $purchase_order_item_id, $input, $username, $company_id) {
    assertPurchaseOrderBelongsToCompany($conn, $purchase_order_id, $company_id);

    $purchase_order_item_id = mysqli_real_escape_string($conn, $purchase_order_item_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_order_item WHERE id = '$purchase_order_item_id' AND purchase_order_id = '$purchase_order_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase order item not found');
        return;
    }

    $updates = [];

    if (isset($input['product_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['product_name']));
        if ($val === '') { jsonResponse(400, 'product_name cannot be empty'); return; }
        $updates[] = "product_name = '$val'";
    }

    $numeric_fields = ['quantity', 'packaging_size', 'unit_price', 'vat', 'total'];
    foreach ($numeric_fields as $field) {
        if (isset($input[$field]) && $input[$field] !== '') {
            $updates[] = "$field = " . (float)$input[$field];
        }
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_order_item SET " . implode(', ', $updates) . " WHERE id = '$purchase_order_item_id' AND purchase_order_id = '$purchase_order_id'")) {
        jsonResponse(200, 'Purchase order item updated successfully');
    } else {
        jsonResponse(500, 'Failed to update purchase order item', ['error' => mysqli_error($conn)]);
    }
}

function deletePurchaseOrderItem($conn, $purchase_order_id, $purchase_order_item_id, $username, $company_id) {
    assertPurchaseOrderBelongsToCompany($conn, $purchase_order_id, $company_id);

    $purchase_order_item_id = mysqli_real_escape_string($conn, $purchase_order_item_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_order_item WHERE id = '$purchase_order_item_id' AND purchase_order_id = '$purchase_order_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase order item not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_order_item SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$purchase_order_item_id' AND purchase_order_id = '$purchase_order_id'")) {
        jsonResponse(200, 'Purchase order item deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete purchase order item', ['error' => mysqli_error($conn)]);
    }
}

$purchase_order_item_id = $parts[5] ?? null;

if ($purchase_order_item_id) {
    switch ($method) {
        case 'GET':
            getDetailPurchaseOrderItem($conn, $purchase_order_id, $purchase_order_item_id, $company_id);
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            updatePurchaseOrderItem($conn, $purchase_order_id, $purchase_order_item_id, $input, $username, $company_id);
            break;
        case 'DELETE':
            deletePurchaseOrderItem($conn, $purchase_order_id, $purchase_order_item_id, $username, $company_id);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} else {
    switch ($method) {
        case 'GET':
            getAllPurchaseOrderItems($conn, $purchase_order_id, $company_id);
            break;
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createPurchaseOrderItem($conn, $purchase_order_id, $input, $username, $company_id);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
    }
}
