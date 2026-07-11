<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/audit_log.php';

function getAllSalesSppbs($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "ssp.company_id = '$company_id' AND ssp.deleted_at IS NULL";
    if ($search) {
        $where .= " AND ssp.sppb_display_number LIKE '%$search%'";
    }
    if (isset($params['customer_id']) && trim($params['customer_id']) !== '') {
        $customer_id = mysqli_real_escape_string($conn, $params['customer_id']);
        $where .= " AND ssp.customer_id = '$customer_id'";
    }
    if (isset($params['sales_order_id']) && trim($params['sales_order_id']) !== '') {
        $sales_order_id = mysqli_real_escape_string($conn, $params['sales_order_id']);
        $where .= " AND ssp.sales_order_id = '$sales_order_id'";
    }
    if (isset($params['date_from']) && trim($params['date_from']) !== '') {
        $date_from = mysqli_real_escape_string($conn, $params['date_from']);
        $where .= " AND ssp.sppb_date >= '$date_from'";
    }
    if (isset($params['date_to']) && trim($params['date_to']) !== '') {
        $date_to = mysqli_real_escape_string($conn, $params['date_to']);
        $where .= " AND ssp.sppb_date <= '$date_to'";
    }

    $from = APP_SCHEMA . ".sales_sppb ssp
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = ssp.customer_id
            LEFT JOIN " . APP_SCHEMA . ".sales_order so ON so.id = ssp.sales_order_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = ssp.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = ssp.updated_by";

    $result       = mysqli_query($conn, "SELECT ssp.*, c.customer_name, so.so_display_number,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY ssp.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM $from WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Sales SPPBs found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No sales SPPBs found');
    }
}

function createSalesSppb($conn, $input, $username, $company_id) {
    $required = ['sppb_display_number', 'sales_order_id', 'sppb_date', 'customer_id', 'items'];
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
        $item_required = ['send_to_address', 'send_date', 'product_name', 'quantity', 'uom_id'];
        foreach ($item_required as $field) {
            if (!isset($item[$field]) || (is_string($item[$field]) && trim($item[$field]) === '')) {
                jsonResponse(400, "items.$field is required");
                return;
            }
        }
    }

    $sppb_display_number = trim(mysqli_real_escape_string($conn, $input['sppb_display_number']));
    $sales_order_id        = mysqli_real_escape_string($conn, $input['sales_order_id']);
    $sppb_date             = mysqli_real_escape_string($conn, $input['sppb_date']);
    $customer_id           = mysqli_real_escape_string($conn, $input['customer_id']);

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_sppb WHERE company_id = '$company_id' AND sppb_display_number = '$sppb_display_number' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Sales SPPB already exists');
        return;
    }

    $so_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_order WHERE id = '$sales_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($so_check) === 0) {
        jsonResponse(404, 'Sales order not found');
        return;
    }

    $sales_sppb_id = generateUUID();
    $now           = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO " . APP_SCHEMA . ".sales_sppb
                (id, company_id, sppb_display_number, sales_order_id, sppb_date, customer_id, created_by, created_at)
                VALUES
                ('$sales_sppb_id', '$company_id', '$sppb_display_number', '$sales_order_id', '$sppb_date', '$customer_id', '$username', '$now')";

        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }

        foreach ($input['items'] as $item) {
            $item_id         = generateUUID();
            $send_to_address = mysqli_real_escape_string($conn, $item['send_to_address']);
            $send_date       = mysqli_real_escape_string($conn, $item['send_date']);
            $product_name    = mysqli_real_escape_string($conn, $item['product_name']);
            $quantity        = (float)$item['quantity'];
            $uom_id          = mysqli_real_escape_string($conn, $item['uom_id']);
            $description_sql = isset($item['description']) && trim($item['description']) !== '' ? "'" . mysqli_real_escape_string($conn, $item['description']) . "'" : 'NULL';

            $item_sql = "INSERT INTO " . APP_SCHEMA . ".sales_sppb_item
                         (id, sales_sppb_id, send_to_address, send_date, product_name, quantity, uom_id, description, created_by, created_at)
                         VALUES ('$item_id', '$sales_sppb_id', '$send_to_address', '$send_date', '$product_name', $quantity, '$uom_id', $description_sql, '$username', '$now')";

            if (!mysqli_query($conn, $item_sql)) {
                throw new Exception(mysqli_error($conn));
            }
        }

        insertAuditLog($conn, $company_id, 'sales_sppb', $sales_sppb_id, 'created', $username);

        $conn->commit();
        jsonResponse(201, 'Sales SPPB created successfully', ['sales_sppb_id' => $sales_sppb_id]);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(500, 'Failed to create sales SPPB', ['error' => $e->getMessage()]);
    }
}

function getDetailSalesSppb($conn, $sales_sppb_id, $company_id) {
    $sales_sppb_id = mysqli_real_escape_string($conn, $sales_sppb_id);

    $from   = APP_SCHEMA . ".sales_sppb ssp
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = ssp.customer_id
            LEFT JOIN " . APP_SCHEMA . ".sales_order so ON so.id = ssp.sales_order_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = ssp.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = ssp.updated_by";
    $result = mysqli_query($conn, "SELECT ssp.*, c.customer_name, so.so_display_number,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE ssp.id = '$sales_sppb_id' AND ssp.company_id = '$company_id' AND ssp.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Sales SPPB not found');
        return;
    }

    $sales_sppb = mysqli_fetch_assoc($result);

    $items_from   = APP_SCHEMA . ".sales_sppb_item sspi
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = sspi.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = sspi.updated_by";
    $items_result = mysqli_query($conn, "SELECT sspi.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $items_from WHERE sspi.sales_sppb_id = '$sales_sppb_id' AND sspi.deleted_at IS NULL ORDER BY sspi.created_at ASC");
    $sales_sppb['items'] = $items_result ? mysqli_fetch_all($items_result, MYSQLI_ASSOC) : [];

    jsonResponse(200, 'Sales SPPB found', $sales_sppb);
}

function updateSalesSppb($conn, $sales_sppb_id, $input, $username, $company_id) {
    $sales_sppb_id = mysqli_real_escape_string($conn, $sales_sppb_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_sppb WHERE id = '$sales_sppb_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales SPPB not found');
        return;
    }

    $updates = [];

    $string_fields = ['sppb_display_number', 'customer_id'];
    foreach ($string_fields as $field) {
        if (isset($input[$field])) {
            $val = trim(mysqli_real_escape_string($conn, $input[$field]));
            if ($val === '') { jsonResponse(400, "$field cannot be empty"); return; }
            $updates[] = "$field = '$val'";
        }
    }

    if (isset($input['sppb_date'])) {
        $val = mysqli_real_escape_string($conn, $input['sppb_date']);
        $updates[] = "sppb_date = '$val'";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_sppb SET " . implode(', ', $updates) . " WHERE id = '$sales_sppb_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_sppb', $sales_sppb_id, 'updated', $username);
        jsonResponse(200, 'Sales SPPB updated successfully');
    } else {
        jsonResponse(500, 'Failed to update sales SPPB', ['error' => mysqli_error($conn)]);
    }
}

function deleteSalesSppb($conn, $sales_sppb_id, $username, $company_id) {
    $sales_sppb_id = mysqli_real_escape_string($conn, $sales_sppb_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_sppb WHERE id = '$sales_sppb_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales SPPB not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_sppb SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$sales_sppb_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_sppb', $sales_sppb_id, 'deleted', $username);
        jsonResponse(200, 'Sales SPPB deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete sales SPPB', ['error' => mysqli_error($conn)]);
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

$sales_sppb_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($sales_sppb_id) {
        switch ($method) {
            case 'GET':
                getDetailSalesSppb($conn, $sales_sppb_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateSalesSppb($conn, $sales_sppb_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteSalesSppb($conn, $sales_sppb_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllSalesSppbs($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createSalesSppb($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
