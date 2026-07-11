<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/audit_log.php';

function getAllPurchaseReceives($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "pr.company_id = '$company_id' AND pr.deleted_at IS NULL";
    if ($search) {
        $where .= " AND po.po_display_number LIKE '%$search%'";
    }
    if (isset($params['purchase_order_id']) && trim($params['purchase_order_id']) !== '') {
        $purchase_order_id = mysqli_real_escape_string($conn, $params['purchase_order_id']);
        $where .= " AND pr.purchase_order_id = '$purchase_order_id'";
    }
    if (isset($params['supplier_id']) && trim($params['supplier_id']) !== '') {
        $supplier_id = mysqli_real_escape_string($conn, $params['supplier_id']);
        $where .= " AND pr.supplier_id = '$supplier_id'";
    }
    if (isset($params['date_from']) && trim($params['date_from']) !== '') {
        $date_from = mysqli_real_escape_string($conn, $params['date_from']);
        $where .= " AND pr.receiving_date >= '$date_from'";
    }
    if (isset($params['date_to']) && trim($params['date_to']) !== '') {
        $date_to = mysqli_real_escape_string($conn, $params['date_to']);
        $where .= " AND pr.receiving_date <= '$date_to'";
    }

    $from = APP_SCHEMA . ".purchase_receive pr LEFT JOIN " . APP_SCHEMA . ".purchase_order po ON pr.purchase_order_id = po.id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = pr.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = pr.updated_by";

    $result       = mysqli_query($conn, "SELECT pr.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY pr.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM $from WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Purchase receives found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No purchase receives found');
    }
}

function createPurchaseReceive($conn, $input, $username, $company_id) {
    $required = ['purchase_order_id', 'supplier_id', 'receiving_date', 'ship_date', 'ship_via_id', 'items'];
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

    $purchase_order_id = mysqli_real_escape_string($conn, $input['purchase_order_id']);
    $supplier_id        = mysqli_real_escape_string($conn, $input['supplier_id']);
    $receiving_date     = mysqli_real_escape_string($conn, $input['receiving_date']);
    $ship_date          = mysqli_real_escape_string($conn, $input['ship_date']);
    $ship_via_id        = mysqli_real_escape_string($conn, $input['ship_via_id']);

    $po_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_order WHERE id = '$purchase_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($po_check) === 0) {
        jsonResponse(404, 'Purchase order not found');
        return;
    }

    $purchase_receive_id = generateUUID();
    $now                 = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO " . APP_SCHEMA . ".purchase_receive
                (id, company_id, purchase_order_id, supplier_id, receiving_date, ship_date, ship_via_id, created_by, created_at)
                VALUES ('$purchase_receive_id', '$company_id', '$purchase_order_id', '$supplier_id', '$receiving_date', '$ship_date', '$ship_via_id', '$username', '$now')";

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

            $item_sql = "INSERT INTO " . APP_SCHEMA . ".purchase_receive_item
                         (id, purchase_receive_id, product_name, quantity, packaging_size, unit_price, vat, total, created_by, created_at)
                         VALUES ('$item_id', '$purchase_receive_id', '$product_name', $quantity, $packaging_size, $unit_price, $vat, $total, '$username', '$now')";

            if (!mysqli_query($conn, $item_sql)) {
                throw new Exception(mysqli_error($conn));
            }
        }

        insertAuditLog($conn, $company_id, 'purchase_receive', $purchase_receive_id, 'created', $username);

        $conn->commit();
        jsonResponse(201, 'Purchase receive created successfully', ['purchase_receive_id' => $purchase_receive_id]);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(500, 'Failed to create purchase receive', ['error' => $e->getMessage()]);
    }
}

function getDetailPurchaseReceive($conn, $purchase_receive_id, $company_id) {
    $purchase_receive_id = mysqli_real_escape_string($conn, $purchase_receive_id);

    $from   = APP_SCHEMA . ".purchase_receive pr
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = pr.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = pr.updated_by";
    $result = mysqli_query($conn, "SELECT pr.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE pr.id = '$purchase_receive_id' AND pr.company_id = '$company_id' AND pr.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Purchase receive not found');
        return;
    }

    $purchase_receive = mysqli_fetch_assoc($result);

    $items_from   = APP_SCHEMA . ".purchase_receive_item pri
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = pri.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = pri.updated_by";
    $items_result = mysqli_query($conn, "SELECT pri.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $items_from WHERE pri.purchase_receive_id = '$purchase_receive_id' AND pri.deleted_at IS NULL ORDER BY pri.created_at ASC");
    $purchase_receive['items'] = $items_result ? mysqli_fetch_all($items_result, MYSQLI_ASSOC) : [];

    jsonResponse(200, 'Purchase receive found', $purchase_receive);
}

function updatePurchaseReceive($conn, $purchase_receive_id, $input, $username, $company_id) {
    $purchase_receive_id = mysqli_real_escape_string($conn, $purchase_receive_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_receive WHERE id = '$purchase_receive_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase receive not found');
        return;
    }

    $updates = [];

    $string_fields = ['supplier_id', 'ship_via_id'];
    foreach ($string_fields as $field) {
        if (isset($input[$field])) {
            $val = trim(mysqli_real_escape_string($conn, $input[$field]));
            if ($val === '') { jsonResponse(400, "$field cannot be empty"); return; }
            $updates[] = "$field = '$val'";
        }
    }

    $date_fields = ['receiving_date', 'ship_date'];
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

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_receive SET " . implode(', ', $updates) . " WHERE id = '$purchase_receive_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Purchase receive updated successfully');
    } else {
        jsonResponse(500, 'Failed to update purchase receive', ['error' => mysqli_error($conn)]);
    }
}

function deletePurchaseReceive($conn, $purchase_receive_id, $username, $company_id) {
    $purchase_receive_id = mysqli_real_escape_string($conn, $purchase_receive_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_receive WHERE id = '$purchase_receive_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase receive not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_receive SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$purchase_receive_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Purchase receive deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete purchase receive', ['error' => mysqli_error($conn)]);
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

$purchase_receive_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($purchase_receive_id) {
        switch ($method) {
            case 'GET':
                getDetailPurchaseReceive($conn, $purchase_receive_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updatePurchaseReceive($conn, $purchase_receive_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deletePurchaseReceive($conn, $purchase_receive_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllPurchaseReceives($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createPurchaseReceive($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
