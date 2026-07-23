<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/audit_log.php';
require_once __DIR__ . '/../../helpers/excel_export.php';
require_once __DIR__ . '/../../helpers/notification.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;

function getSalesStatusIdByName($conn, $status_name) {
    $status_name = mysqli_real_escape_string($conn, $status_name);
    $result = mysqli_query($conn, "SELECT id FROM " . APP_SCHEMA . ".sales_status WHERE status_name = '$status_name' AND deleted_at IS NULL LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? $row['id'] : null;
}

function getAllSalesDeliveries($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "sd.company_id = '$company_id' AND sd.deleted_at IS NULL";
    if ($search) {
        $where .= " AND sd.do_display_number LIKE '%$search%'";
    }
    if (isset($params['customer_id']) && trim($params['customer_id']) !== '') {
        $customer_id = mysqli_real_escape_string($conn, $params['customer_id']);
        $where .= " AND sd.customer_id = '$customer_id'";
    }
    if (isset($params['sales_order_id']) && trim($params['sales_order_id']) !== '') {
        $sales_order_id = mysqli_real_escape_string($conn, $params['sales_order_id']);
        $where .= " AND sd.sales_order_id = '$sales_order_id'";
    }
    if (isset($params['date_from']) && trim($params['date_from']) !== '') {
        $date_from = mysqli_real_escape_string($conn, $params['date_from']);
        $where .= " AND sd.delivery_date >= '$date_from'";
    }
    if (isset($params['date_to']) && trim($params['date_to']) !== '') {
        $date_to = mysqli_real_escape_string($conn, $params['date_to']);
        $where .= " AND sd.delivery_date <= '$date_to'";
    }

    $from = APP_SCHEMA . ".sales_delivery sd
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = sd.customer_id
            LEFT JOIN " . APP_SCHEMA . ".sales_order so ON so.id = sd.sales_order_id
            LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = sd.status_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = sd.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = sd.updated_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = sd.approved_by";

    $result       = mysqli_query($conn, "SELECT sd.*, c.customer_name, so.so_display_number, ss.status_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by
            FROM $from WHERE $where ORDER BY sd.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM $from WHERE $where");
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

    $status_id = getSalesStatusIdByName($conn, 'Draft');
    if (!$status_id) {
        jsonResponse(500, 'Default sales status "Draft" is not configured');
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
                 container_number, bl_number, vessel_name, etd_date, eta_date, status_id, created_by, created_at)
                VALUES
                ('$sales_delivery_id', '$company_id', '$do_display_number', '$customer_id', '$sales_order_id', '$delivery_date', '$bill_to_address', '$ship_to_address',
                 $container_number_sql, $bl_number_sql, $vessel_name_sql, $etd_date_sql, $eta_date_sql, '$status_id', '$username', '$now')";

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

    $from   = APP_SCHEMA . ".sales_delivery sd
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = sd.customer_id
            LEFT JOIN " . APP_SCHEMA . ".sales_order so ON so.id = sd.sales_order_id
            LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = sd.status_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = sd.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = sd.updated_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = sd.approved_by";
    $result = mysqli_query($conn, "SELECT sd.*, c.customer_name, so.so_display_number, ss.status_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by
            FROM $from WHERE sd.id = '$sales_delivery_id' AND sd.company_id = '$company_id' AND sd.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Sales delivery not found');
        return;
    }

    $sales_delivery = mysqli_fetch_assoc($result);

    $items_from   = APP_SCHEMA . ".sales_delivery_item sdi
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = sdi.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = sdi.updated_by";
    $items_result = mysqli_query($conn, "SELECT sdi.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $items_from WHERE sdi.sales_delivery_id = '$sales_delivery_id' AND sdi.deleted_at IS NULL ORDER BY sdi.created_at ASC");
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

function approveSalesDelivery($conn, $sales_delivery_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT sd.do_display_number, sd.created_by, c.customer_name
            FROM " . APP_SCHEMA . ".sales_delivery sd
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = sd.customer_id
            WHERE sd.id = '$sales_delivery_id' AND sd.company_id = '$company_id' AND sd.deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales delivery not found');
        return;
    }
    $sales_delivery = mysqli_fetch_assoc($check);

    $status_id = getSalesStatusIdByName($conn, 'Approved');
    if (!$status_id) {
        jsonResponse(500, 'Sales status "Approved" is not configured');
        return;
    }

    $now   = date('Y-m-d H:i:s');
    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_delivery
            SET status_id = '$status_id', approved_by = '$username', approved_at = '$now', updated_by = '$username', updated_at = '$now'
            WHERE id = '$sales_delivery_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_delivery', $sales_delivery_id, 'approved', $username, $notes);

        $approver_name = resolveDisplayName($conn, $username);
        $detail_link   = rtrim(APPROVAL_BASE_URL, '/') . '/sales/sales-delivery/' . $sales_delivery_id;

        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_approved',
            'source_module'      => 'sales_delivery',
            'source_document_id' => $sales_delivery_id,
            'title'              => 'Sales Delivery Disetujui',
            'body'               => "{$sales_delivery['do_display_number']} telah disetujui oleh $approver_name pada " . formatIndonesianDate($now) . ', ' . date('H:i', strtotime($now)) . " WIB.\n\n" .
                                     "Customer: {$sales_delivery['customer_name']}\n\n" .
                                     "Lihat detail: $detail_link",
            'created_by'         => $username,
            'recipients'         => [$sales_delivery['created_by']],
        ]);

        jsonResponse(200, 'Sales delivery approved successfully');
    } else {
        jsonResponse(500, 'Failed to approve sales delivery', ['error' => mysqli_error($conn)]);
    }
}

function rejectSalesDelivery($conn, $sales_delivery_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT do_display_number, created_by FROM " . APP_SCHEMA . ".sales_delivery WHERE id = '$sales_delivery_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales delivery not found');
        return;
    }
    $sales_delivery = mysqli_fetch_assoc($check);

    $status_id = getSalesStatusIdByName($conn, 'Rejected');
    if (!$status_id) {
        jsonResponse(500, 'Sales status "Rejected" is not configured');
        return;
    }

    $now   = date('Y-m-d H:i:s');
    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_delivery
            SET status_id = '$status_id', updated_by = '$username', updated_at = '$now'
            WHERE id = '$sales_delivery_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_delivery', $sales_delivery_id, 'rejected', $username, $notes);

        $rejector_name = resolveDisplayName($conn, $username);
        $reason_text   = $notes ? " Alasan: $notes" : '';

        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_rejected',
            'source_module'      => 'sales_delivery',
            'source_document_id' => $sales_delivery_id,
            'title'              => 'Sales Delivery Ditolak',
            'body'               => "{$sales_delivery['do_display_number']} ditolak oleh $rejector_name.$reason_text",
            'created_by'         => $username,
            'recipients'         => [$sales_delivery['created_by']],
        ]);

        jsonResponse(200, 'Sales delivery rejected successfully');
    } else {
        jsonResponse(500, 'Failed to reject sales delivery', ['error' => mysqli_error($conn)]);
    }
}

function reviseSalesDelivery($conn, $sales_delivery_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT ss.status_name FROM " . APP_SCHEMA . ".sales_delivery sd
            LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = sd.status_id
            WHERE sd.id = '$sales_delivery_id' AND sd.company_id = '$company_id' AND sd.deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales delivery not found');
        return;
    }

    $sales_delivery = mysqli_fetch_assoc($check);
    if ($sales_delivery['status_name'] !== 'Rejected') {
        jsonResponse(400, 'Only rejected sales deliveries can be revised');
        return;
    }

    $status_id = getSalesStatusIdByName($conn, 'Draft');
    if (!$status_id) {
        jsonResponse(500, 'Default sales status "Draft" is not configured');
        return;
    }

    $now   = date('Y-m-d H:i:s');
    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_delivery
            SET status_id = '$status_id', updated_by = '$username', updated_at = '$now'
            WHERE id = '$sales_delivery_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_delivery', $sales_delivery_id, 'revised', $username, $notes);
        jsonResponse(200, 'Sales delivery revised successfully');
    } else {
        jsonResponse(500, 'Failed to revise sales delivery', ['error' => mysqli_error($conn)]);
    }
}

function exportSalesDelivery($conn, $sales_delivery_id, $company_id) {
    $sales_delivery_id = mysqli_real_escape_string($conn, $sales_delivery_id);

    $from   = APP_SCHEMA . ".sales_delivery sd
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = sd.customer_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = sd.approved_by";
    $result = mysqli_query($conn, "SELECT sd.*, c.customer_name, c.customer_address, c.customer_phone,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by_name
            FROM $from WHERE sd.id = '$sales_delivery_id' AND sd.company_id = '$company_id' AND sd.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Sales delivery not found');
        return;
    }

    $sales_delivery = mysqli_fetch_assoc($result);

    $items_result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".sales_delivery_item
            WHERE sales_delivery_id = '$sales_delivery_id' AND deleted_at IS NULL ORDER BY created_at ASC");
    $items = $items_result ? mysqli_fetch_all($items_result, MYSQLI_ASSOC) : [];

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    $sheet->setCellValue('G1', formatIndonesianDate($sales_delivery['delivery_date']));
    $sheet->setCellValue('G3', $sales_delivery['customer_name']);
    $sheet->setCellValue('G4', $sales_delivery['customer_address']);
    $sheet->setCellValue('B5', $sales_delivery['do_display_number']);
    $sheet->setCellValue('G5', $sales_delivery['ship_to_address']);
    $sheet->setCellValue('G6', $sales_delivery['customer_phone']);
    $sheet->setCellValue('A8', 'Banyaknya');
    $sheet->setCellValue('C8', 'Nama Barang');

    $row = 9;
    foreach ($items as $item) {
        $sheet->setCellValue("A$row", $item['quantity']);
        $sheet->setCellValue("C$row", $item['product_name']);
        $row++;
        $sheet->setCellValue("C$row", $item['notes'] ?? '');
        $row++;
        $sheet->setCellValue("C$row", 'Lot No:');
        $sheet->setCellValue("D$row", '[Nomor Lot]');
        $row++;
    }

    $sheet->setCellValue('G24', $sales_delivery['approved_by_name'] ?? '-');

    foreach (range('A', 'J') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    streamXlsx($spreadsheet, 'surat_jalan_' . sanitizeFilename($sales_delivery['do_display_number']) . '.xlsx');
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
$sub_action         = $parts[4] ?? '';

try {
    $conn = getConn();

    if ($sales_delivery_id && $sub_action === 'export') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        exportSalesDelivery($conn, $sales_delivery_id, $company_id);

    } elseif ($sales_delivery_id && $sub_action !== '') {
        $input = in_array($method, ['POST', 'PUT', 'PATCH'])
            ? (json_decode(file_get_contents('php://input'), true) ?? [])
            : [];

        switch ($sub_action) {
            case 'approve':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                approveSalesDelivery($conn, $sales_delivery_id, $input, $username, $company_id);
                break;
            case 'reject':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                rejectSalesDelivery($conn, $sales_delivery_id, $input, $username, $company_id);
                break;
            case 'revise':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                reviseSalesDelivery($conn, $sales_delivery_id, $input, $username, $company_id);
                break;
            default:
                jsonResponse(404, 'Route not found');
        }

    } elseif ($sales_delivery_id) {
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
