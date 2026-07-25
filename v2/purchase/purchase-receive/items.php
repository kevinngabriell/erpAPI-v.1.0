<?php

function assertPurchaseReceiveBelongsToCompany($conn, $purchase_receive_id, $company_id) {
    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_receive WHERE id = '$purchase_receive_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase receive not found');
    }
}

function getAllPurchaseReceiveItems($conn, $purchase_receive_id, $company_id) {
    assertPurchaseReceiveBelongsToCompany($conn, $purchase_receive_id, $company_id);

    $from   = APP_SCHEMA . ".purchase_receive_item pri
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = pri.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = pri.updated_by";
    $result = mysqli_query($conn, "SELECT pri.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE pri.purchase_receive_id = '$purchase_receive_id' AND pri.deleted_at IS NULL ORDER BY pri.created_at ASC");

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Purchase receive items found', ['data' => mysqli_fetch_all($result, MYSQLI_ASSOC)]);
    } else {
        jsonResponse(404, 'No purchase receive items found');
    }
}

function getDetailPurchaseReceiveItem($conn, $purchase_receive_id, $purchase_receive_item_id, $company_id) {
    assertPurchaseReceiveBelongsToCompany($conn, $purchase_receive_id, $company_id);

    $purchase_receive_item_id = mysqli_real_escape_string($conn, $purchase_receive_item_id);

    $from   = APP_SCHEMA . ".purchase_receive_item pri
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = pri.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = pri.updated_by";
    $result = mysqli_query($conn, "SELECT pri.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE pri.id = '$purchase_receive_item_id' AND pri.purchase_receive_id = '$purchase_receive_id' AND pri.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Purchase receive item not found');
        return;
    }

    jsonResponse(200, 'Purchase receive item found', mysqli_fetch_assoc($result));
}

function updatePurchaseReceiveItem($conn, $purchase_receive_id, $purchase_receive_item_id, $input, $username, $company_id) {
    assertPurchaseReceiveBelongsToCompany($conn, $purchase_receive_id, $company_id);

    $purchase_receive_item_id = mysqli_real_escape_string($conn, $purchase_receive_item_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_receive_item WHERE id = '$purchase_receive_item_id' AND purchase_receive_id = '$purchase_receive_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase receive item not found');
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

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_receive_item SET " . implode(', ', $updates) . " WHERE id = '$purchase_receive_item_id' AND purchase_receive_id = '$purchase_receive_id'")) {
        jsonResponse(200, 'Purchase receive item updated successfully');
    } else {
        jsonResponse(500, 'Failed to update purchase receive item', ['error' => mysqli_error($conn)]);
    }
}

$purchase_receive_item_id = $parts[5] ?? null;

if ($purchase_receive_item_id) {
    switch ($method) {
        case 'GET':
            getDetailPurchaseReceiveItem($conn, $purchase_receive_id, $purchase_receive_item_id, $company_id);
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            updatePurchaseReceiveItem($conn, $purchase_receive_id, $purchase_receive_item_id, $input, $username, $company_id);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} else {
    switch ($method) {
        case 'GET':
            getAllPurchaseReceiveItems($conn, $purchase_receive_id, $company_id);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
    }
}
