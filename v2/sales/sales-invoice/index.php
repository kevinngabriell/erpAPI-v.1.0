<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/audit_log.php';

function getAllSalesInvoices($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "company_id = '$company_id' AND deleted_at IS NULL";
    if ($search) {
        $where .= " AND invoice_display_number LIKE '%$search%'";
    }
    if (isset($params['customer_id']) && trim($params['customer_id']) !== '') {
        $customer_id = mysqli_real_escape_string($conn, $params['customer_id']);
        $where .= " AND customer_id = '$customer_id'";
    }
    if (isset($params['sales_order_id']) && trim($params['sales_order_id']) !== '') {
        $sales_order_id = mysqli_real_escape_string($conn, $params['sales_order_id']);
        $where .= " AND sales_order_id = '$sales_order_id'";
    }

    $result       = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".sales_invoice WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".sales_invoice WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Sales invoices found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No sales invoices found');
    }
}

function createSalesInvoice($conn, $input, $username, $company_id) {
    $required = ['invoice_display_number', 'customer_id', 'sales_order_id', 'invoice_date', 'ship_to_address', 'bill_to_address', 'items'];
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
        $item_required = ['product_name', 'quantity', 'unit_price'];
        foreach ($item_required as $field) {
            if (!isset($item[$field]) || (is_string($item[$field]) && trim($item[$field]) === '')) {
                jsonResponse(400, "items.$field is required");
                return;
            }
        }
    }

    $invoice_display_number = trim(mysqli_real_escape_string($conn, $input['invoice_display_number']));
    $customer_id             = mysqli_real_escape_string($conn, $input['customer_id']);
    $sales_order_id          = mysqli_real_escape_string($conn, $input['sales_order_id']);
    $invoice_date            = mysqli_real_escape_string($conn, $input['invoice_date']);
    $ship_to_address         = mysqli_real_escape_string($conn, $input['ship_to_address']);
    $bill_to_address         = mysqli_real_escape_string($conn, $input['bill_to_address']);

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_invoice WHERE company_id = '$company_id' AND invoice_display_number = '$invoice_display_number' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Sales invoice already exists');
        return;
    }

    $so_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_order WHERE id = '$sales_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($so_check) === 0) {
        jsonResponse(404, 'Sales order not found');
        return;
    }

    $sales_delivery_id_sql = 'NULL';
    if (isset($input['sales_delivery_id']) && trim($input['sales_delivery_id']) !== '') {
        $sales_delivery_id = mysqli_real_escape_string($conn, $input['sales_delivery_id']);

        $do_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_delivery WHERE id = '$sales_delivery_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($do_check) === 0) {
            jsonResponse(404, 'Sales delivery not found');
            return;
        }

        $sales_delivery_id_sql = "'$sales_delivery_id'";
    }

    $tax_invoice_number_sql = isset($input['tax_invoice_number']) && trim($input['tax_invoice_number']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['tax_invoice_number']) . "'" : 'NULL';

    $sales_invoice_id = generateUUID();
    $now              = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO " . APP_SCHEMA . ".sales_invoice
                (id, company_id, invoice_display_number, customer_id, sales_order_id, sales_delivery_id, invoice_date, tax_invoice_number,
                 ship_to_address, bill_to_address, created_by, created_at)
                VALUES
                ('$sales_invoice_id', '$company_id', '$invoice_display_number', '$customer_id', '$sales_order_id', $sales_delivery_id_sql, '$invoice_date', $tax_invoice_number_sql,
                 '$ship_to_address', '$bill_to_address', '$username', '$now')";

        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }

        foreach ($input['items'] as $item) {
            $item_id      = generateUUID();
            $product_name = mysqli_real_escape_string($conn, $item['product_name']);
            $quantity     = (float)$item['quantity'];
            $unit_price   = (float)$item['unit_price'];
            $tax          = isset($item['tax']) && $item['tax'] !== '' ? (float)$item['tax'] : 0;

            $item_sql = "INSERT INTO " . APP_SCHEMA . ".sales_invoice_item
                         (id, sales_invoice_id, product_name, quantity, unit_price, tax, created_by, created_at)
                         VALUES ('$item_id', '$sales_invoice_id', '$product_name', $quantity, $unit_price, $tax, '$username', '$now')";

            if (!mysqli_query($conn, $item_sql)) {
                throw new Exception(mysqli_error($conn));
            }
        }

        insertAuditLog($conn, $company_id, 'sales_invoice', $sales_invoice_id, 'created', $username);

        $conn->commit();
        jsonResponse(201, 'Sales invoice created successfully', ['sales_invoice_id' => $sales_invoice_id]);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(500, 'Failed to create sales invoice', ['error' => $e->getMessage()]);
    }
}

function getDetailSalesInvoice($conn, $sales_invoice_id, $company_id) {
    $sales_invoice_id = mysqli_real_escape_string($conn, $sales_invoice_id);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".sales_invoice WHERE id = '$sales_invoice_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Sales invoice not found');
        return;
    }

    $sales_invoice = mysqli_fetch_assoc($result);

    $items_result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".sales_invoice_item WHERE sales_invoice_id = '$sales_invoice_id' AND deleted_at IS NULL ORDER BY created_at ASC");
    $sales_invoice['items'] = $items_result ? mysqli_fetch_all($items_result, MYSQLI_ASSOC) : [];

    jsonResponse(200, 'Sales invoice found', $sales_invoice);
}

function updateSalesInvoice($conn, $sales_invoice_id, $input, $username, $company_id) {
    $sales_invoice_id = mysqli_real_escape_string($conn, $sales_invoice_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_invoice WHERE id = '$sales_invoice_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales invoice not found');
        return;
    }

    $updates = [];

    $string_fields = ['invoice_display_number', 'customer_id', 'tax_invoice_number', 'ship_to_address', 'bill_to_address'];
    foreach ($string_fields as $field) {
        if (isset($input[$field])) {
            $val = trim(mysqli_real_escape_string($conn, $input[$field]));
            if ($val === '') { jsonResponse(400, "$field cannot be empty"); return; }
            $updates[] = "$field = '$val'";
        }
    }

    if (isset($input['invoice_date'])) {
        $val = mysqli_real_escape_string($conn, $input['invoice_date']);
        $updates[] = "invoice_date = '$val'";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_invoice SET " . implode(', ', $updates) . " WHERE id = '$sales_invoice_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_invoice', $sales_invoice_id, 'updated', $username);
        jsonResponse(200, 'Sales invoice updated successfully');
    } else {
        jsonResponse(500, 'Failed to update sales invoice', ['error' => mysqli_error($conn)]);
    }
}

function deleteSalesInvoice($conn, $sales_invoice_id, $username, $company_id) {
    $sales_invoice_id = mysqli_real_escape_string($conn, $sales_invoice_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_invoice WHERE id = '$sales_invoice_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales invoice not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_invoice SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$sales_invoice_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_invoice', $sales_invoice_id, 'deleted', $username);
        jsonResponse(200, 'Sales invoice deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete sales invoice', ['error' => mysqli_error($conn)]);
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

$sales_invoice_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($sales_invoice_id) {
        switch ($method) {
            case 'GET':
                getDetailSalesInvoice($conn, $sales_invoice_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateSalesInvoice($conn, $sales_invoice_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteSalesInvoice($conn, $sales_invoice_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllSalesInvoices($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createSalesInvoice($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
