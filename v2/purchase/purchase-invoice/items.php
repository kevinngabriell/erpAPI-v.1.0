<?php

function assertPurchaseInvoiceBelongsToCompany($conn, $purchase_invoice_id, $company_id) {
    $purchase_invoice_id = mysqli_real_escape_string($conn, $purchase_invoice_id);
    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_invoice WHERE id = '$purchase_invoice_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase invoice not found');
        return;
    }
}

function getAllPurchaseInvoiceItems($conn, $purchase_invoice_id, $company_id, $params = []) {
    assertPurchaseInvoiceBelongsToCompany($conn, $purchase_invoice_id, $company_id);

    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(15, max(1, (int)($params['limit'] ?? 15)));
    $offset = ($page - 1) * $limit;

    $from   = APP_SCHEMA . ".purchase_invoice_item pii
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = pii.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = pii.updated_by";
    $result = mysqli_query($conn, "SELECT pii.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE pii.purchase_invoice_id = '$purchase_invoice_id' AND pii.deleted_at IS NULL ORDER BY pii.created_at ASC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".purchase_invoice_item pii WHERE pii.purchase_invoice_id = '$purchase_invoice_id' AND pii.deleted_at IS NULL");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Purchase invoice items found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(200, 'Purchase invoice items found', [
            'data'       => [],
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    }
}

function getDetailPurchaseInvoiceItem($conn, $purchase_invoice_id, $purchase_invoice_item_id, $company_id) {
    assertPurchaseInvoiceBelongsToCompany($conn, $purchase_invoice_id, $company_id);

    $purchase_invoice_item_id = mysqli_real_escape_string($conn, $purchase_invoice_item_id);

    $from   = APP_SCHEMA . ".purchase_invoice_item pii
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = pii.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = pii.updated_by";
    $result = mysqli_query($conn, "SELECT pii.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE pii.id = '$purchase_invoice_item_id' AND pii.purchase_invoice_id = '$purchase_invoice_id' AND pii.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Purchase invoice item not found');
        return;
    }

    jsonResponse(200, 'Purchase invoice item found', mysqli_fetch_assoc($result));
}

function updatePurchaseInvoiceItem($conn, $purchase_invoice_id, $purchase_invoice_item_id, $input, $username, $company_id) {
    assertPurchaseInvoiceBelongsToCompany($conn, $purchase_invoice_id, $company_id);

    $purchase_invoice_item_id = mysqli_real_escape_string($conn, $purchase_invoice_item_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_invoice_item WHERE id = '$purchase_invoice_item_id' AND purchase_invoice_id = '$purchase_invoice_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase invoice item not found');
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
            $updates[] = "$field = " . mysqli_real_escape_string($conn, (string)$input[$field]);
        }
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_invoice_item SET " . implode(', ', $updates) . " WHERE id = '$purchase_invoice_item_id' AND purchase_invoice_id = '$purchase_invoice_id'")) {
        jsonResponse(200, 'Purchase invoice item updated successfully');
    } else {
        jsonResponse(500, 'Failed to update purchase invoice item', safeDbError($conn));
    }
}

$purchase_invoice_item_id = $parts[5] ?? null;

if ($purchase_invoice_item_id) {
    switch ($method) {
        case 'GET':
            getDetailPurchaseInvoiceItem($conn, $purchase_invoice_id, $purchase_invoice_item_id, $company_id);
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            updatePurchaseInvoiceItem($conn, $purchase_invoice_id, $purchase_invoice_item_id, $input, $username, $company_id);
            break;
        default:
            methodNotAllowed(['GET', 'PUT']);
    }
} else {
    switch ($method) {
        case 'GET':
            getAllPurchaseInvoiceItems($conn, $purchase_invoice_id, $company_id, $_GET);
            break;
        default:
            methodNotAllowed(['GET']);
    }
}
