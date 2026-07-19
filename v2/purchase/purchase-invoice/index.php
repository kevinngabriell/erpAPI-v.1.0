<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/audit_log.php';

function getAllPurchaseInvoices($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "pi.company_id = '$company_id' AND pi.deleted_at IS NULL";
    if ($search) {
        $where .= " AND pi.invoice_display_number LIKE '%$search%'";
    }
    if (isset($params['supplier_id']) && trim($params['supplier_id']) !== '') {
        $supplier_id = mysqli_real_escape_string($conn, $params['supplier_id']);
        $where .= " AND pi.supplier_id = '$supplier_id'";
    }
    if (isset($params['date_from']) && trim($params['date_from']) !== '') {
        $date_from = mysqli_real_escape_string($conn, $params['date_from']);
        $where .= " AND pi.invoice_date >= '$date_from'";
    }
    if (isset($params['date_to']) && trim($params['date_to']) !== '') {
        $date_to = mysqli_real_escape_string($conn, $params['date_to']);
        $where .= " AND pi.invoice_date <= '$date_to'";
    }

    $from = APP_SCHEMA . ".purchase_invoice pi
            LEFT JOIN " . APP_SCHEMA . ".purchase_order po ON po.id = pi.purchase_order_id
            LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = pi.supplier_id
            LEFT JOIN " . APP_SCHEMA . ".payment_term pt ON pt.id = pi.term_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = pi.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = pi.updated_by";

    $result       = mysqli_query($conn, "SELECT pi.*, po.po_display_number, s.supplier_name, pt.term_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY pi.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM $from WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Purchase invoices found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No purchase invoices found');
    }
}

function createPurchaseInvoice($conn, $input, $username, $company_id) {
    $required = ['invoice_display_number', 'purchase_order_id', 'supplier_id', 'invoice_date', 'ship_date', 'items'];
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
        $item_required = ['product_name', 'quantity', 'packaging_size', 'unit_price'];
        foreach ($item_required as $field) {
            if (!isset($item[$field]) || (is_string($item[$field]) && trim($item[$field]) === '')) {
                jsonResponse(400, "items.$field is required");
                return;
            }
        }
    }

    $invoice_display_number = trim(mysqli_real_escape_string($conn, $input['invoice_display_number']));
    $purchase_order_id       = mysqli_real_escape_string($conn, $input['purchase_order_id']);
    $supplier_id             = mysqli_real_escape_string($conn, $input['supplier_id']);
    $invoice_date            = mysqli_real_escape_string($conn, $input['invoice_date']);
    $ship_date               = mysqli_real_escape_string($conn, $input['ship_date']);

    $po_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_order WHERE id = '$purchase_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($po_check) === 0) {
        jsonResponse(404, 'Purchase order not found');
        return;
    }

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_invoice WHERE company_id = '$company_id' AND invoice_display_number = '$invoice_display_number' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Purchase invoice already exists');
        return;
    }

    $tax_invoice_number_sql = isset($input['tax_invoice_number']) && trim($input['tax_invoice_number']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['tax_invoice_number']) . "'" : 'NULL';
    $kurs_sql               = isset($input['kurs']) && $input['kurs'] !== '' ? (float)$input['kurs'] : 'NULL';
    $term_id_sql            = isset($input['term_id']) && trim($input['term_id']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['term_id']) . "'" : 'NULL';

    $purchase_invoice_id = generateUUID();
    $now                 = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO " . APP_SCHEMA . ".purchase_invoice
                (id, company_id, invoice_display_number, purchase_order_id, supplier_id, invoice_date, ship_date,
                 tax_invoice_number, kurs, term_id, created_by, created_at)
                VALUES ('$purchase_invoice_id', '$company_id', '$invoice_display_number', '$purchase_order_id', '$supplier_id', '$invoice_date', '$ship_date',
                        $tax_invoice_number_sql, $kurs_sql, $term_id_sql, '$username', '$now')";

        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }

        foreach ($input['items'] as $item) {
            $item_id        = generateUUID();
            $product_name   = mysqli_real_escape_string($conn, $item['product_name']);
            $quantity       = (float)$item['quantity'];
            $packaging_size = (float)$item['packaging_size'];
            $unit_price     = (float)$item['unit_price'];
            $vat            = isset($item['vat']) && $item['vat'] !== '' ? (float)$item['vat'] : 0;
            $total          = isset($item['total']) && $item['total'] !== '' ? (float)$item['total'] : ($quantity * $unit_price) + $vat;

            $item_sql = "INSERT INTO " . APP_SCHEMA . ".purchase_invoice_item
                         (id, purchase_invoice_id, product_name, quantity, packaging_size, unit_price, vat, total, created_by, created_at)
                         VALUES ('$item_id', '$purchase_invoice_id', '$product_name', $quantity, $packaging_size, $unit_price, $vat, $total, '$username', '$now')";

            if (!mysqli_query($conn, $item_sql)) {
                throw new Exception(mysqli_error($conn));
            }
        }

        insertAuditLog($conn, $company_id, 'purchase_invoice', $purchase_invoice_id, 'created', $username);

        $conn->commit();
        jsonResponse(201, 'Purchase invoice created successfully', ['purchase_invoice_id' => $purchase_invoice_id]);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(500, 'Failed to create purchase invoice', ['error' => $e->getMessage()]);
    }
}

function getDetailPurchaseInvoice($conn, $purchase_invoice_id, $company_id) {
    $purchase_invoice_id = mysqli_real_escape_string($conn, $purchase_invoice_id);

    $from   = APP_SCHEMA . ".purchase_invoice pi
            LEFT JOIN " . APP_SCHEMA . ".purchase_order po ON po.id = pi.purchase_order_id
            LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = pi.supplier_id
            LEFT JOIN " . APP_SCHEMA . ".payment_term pt ON pt.id = pi.term_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = pi.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = pi.updated_by";
    $result = mysqli_query($conn, "SELECT pi.*, po.po_display_number, s.supplier_name, pt.term_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE pi.id = '$purchase_invoice_id' AND pi.company_id = '$company_id' AND pi.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Purchase invoice not found');
        return;
    }

    $purchase_invoice = mysqli_fetch_assoc($result);

    $items_from   = APP_SCHEMA . ".purchase_invoice_item pii
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = pii.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = pii.updated_by";
    $items_result = mysqli_query($conn, "SELECT pii.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $items_from WHERE pii.purchase_invoice_id = '$purchase_invoice_id' AND pii.deleted_at IS NULL ORDER BY pii.created_at ASC");
    $purchase_invoice['items'] = $items_result ? mysqli_fetch_all($items_result, MYSQLI_ASSOC) : [];

    jsonResponse(200, 'Purchase invoice found', $purchase_invoice);
}

function updatePurchaseInvoice($conn, $purchase_invoice_id, $input, $username, $company_id) {
    $purchase_invoice_id = mysqli_real_escape_string($conn, $purchase_invoice_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_invoice WHERE id = '$purchase_invoice_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase invoice not found');
        return;
    }

    $updates = [];

    $string_fields = ['invoice_display_number', 'supplier_id', 'tax_invoice_number', 'term_id'];
    foreach ($string_fields as $field) {
        if (isset($input[$field])) {
            $val = trim(mysqli_real_escape_string($conn, $input[$field]));
            if ($val === '') { jsonResponse(400, "$field cannot be empty"); return; }
            $updates[] = "$field = '$val'";
        }
    }

    $date_fields = ['invoice_date', 'ship_date'];
    foreach ($date_fields as $field) {
        if (isset($input[$field])) {
            $val = mysqli_real_escape_string($conn, $input[$field]);
            $updates[] = "$field = '$val'";
        }
    }

    if (isset($input['kurs']) && $input['kurs'] !== '') {
        $updates[] = "kurs = " . (float)$input['kurs'];
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_invoice SET " . implode(', ', $updates) . " WHERE id = '$purchase_invoice_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Purchase invoice updated successfully');
    } else {
        jsonResponse(500, 'Failed to update purchase invoice', ['error' => mysqli_error($conn)]);
    }
}

function deletePurchaseInvoice($conn, $purchase_invoice_id, $username, $company_id) {
    $purchase_invoice_id = mysqli_real_escape_string($conn, $purchase_invoice_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_invoice WHERE id = '$purchase_invoice_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase invoice not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_invoice SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$purchase_invoice_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Purchase invoice deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete purchase invoice', ['error' => mysqli_error($conn)]);
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

$purchase_invoice_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($purchase_invoice_id) {
        switch ($method) {
            case 'GET':
                getDetailPurchaseInvoice($conn, $purchase_invoice_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updatePurchaseInvoice($conn, $purchase_invoice_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deletePurchaseInvoice($conn, $purchase_invoice_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllPurchaseInvoices($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createPurchaseInvoice($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
