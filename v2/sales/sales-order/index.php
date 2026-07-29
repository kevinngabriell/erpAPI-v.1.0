<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/audit_log.php';
require_once __DIR__ . '/../../helpers/excel_export.php';
require_once __DIR__ . '/../../helpers/notification.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

function getSalesStatusIdByName($conn, $status_name) {
    $status_name = mysqli_real_escape_string($conn, $status_name);
    $result = mysqli_query($conn, "SELECT id FROM " . APP_SCHEMA . ".sales_status WHERE status_name = '$status_name' AND deleted_at IS NULL LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? $row['id'] : null;
}

function calculateSalesOrderTotal($conn, $sales_order_id, $ppn_percentage, $ppn_name) {
    $sales_order_id = mysqli_real_escape_string($conn, $sales_order_id);
    $result = mysqli_query($conn, "SELECT SUM(quantity * unit_price * kurs) AS subtotal
            FROM " . APP_SCHEMA . ".sales_order_item WHERE sales_order_id = '$sales_order_id' AND deleted_at IS NULL");
    $subtotal = $result ? (float)(mysqli_fetch_assoc($result)['subtotal'] ?? 0) : 0;

    $ppn_percentage = (float)$ppn_percentage;
    $is_11_12_method = $ppn_percentage === 12.0 && str_contains($ppn_name ?? '', '11/12');
    $tax_rate        = $is_11_12_method ? (11 / 12 * 0.12) : (in_array($ppn_percentage, [11.0, 12.0], true) ? $ppn_percentage / 100 : 0);

    return $subtotal * (1 + $tax_rate);
}

function getCompanyCode($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT company_code FROM " . CORE_SCHEMA . ".app_company WHERE company_id = '$company_id' LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? strtoupper($row['company_code']) : null;
}

function generateSalesOrderNumber($conn, $company_id) {
    $company_code = getCompanyCode($conn, $company_id);
    if (!$company_code) {
        jsonResponse(404, 'Company not found');
        return;
    }

    $roman_months = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
    $roman_month  = $roman_months[(int)date('n') - 1];
    $year         = date('Y');

    $pattern      = mysqli_real_escape_string($conn, "%/$company_code-SO/$roman_month/$year");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".sales_order WHERE company_id = '$company_id' AND so_display_number LIKE '$pattern'");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    $sequence          = str_pad((string)($total + 1), 3, '0', STR_PAD_LEFT);
    $so_display_number = "$sequence/$company_code-SO/$roman_month/$year";

    jsonResponse(200, 'Sales order number generated successfully', ['so_display_number' => $so_display_number]);
}

function getAllSalesOrders($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "so.company_id = '$company_id' AND so.deleted_at IS NULL";
    if ($search) {
        $where .= " AND so.so_display_number LIKE '%$search%'";
    }
    if (isset($params['status_id']) && trim($params['status_id']) !== '') {
        $status_id = mysqli_real_escape_string($conn, $params['status_id']);
        $where .= " AND so.status_id = '$status_id'";
    }
    if (isset($params['customer_id']) && trim($params['customer_id']) !== '') {
        $customer_id = mysqli_real_escape_string($conn, $params['customer_id']);
        $where .= " AND so.customer_id = '$customer_id'";
    }
    if (isset($params['date_from']) && trim($params['date_from']) !== '') {
        $date_from = mysqli_real_escape_string($conn, $params['date_from']);
        $where .= " AND so.so_date >= '$date_from'";
    }
    if (isset($params['date_to']) && trim($params['date_to']) !== '') {
        $date_to = mysqli_real_escape_string($conn, $params['date_to']);
        $where .= " AND so.so_date <= '$date_to'";
    }

    $from = APP_SCHEMA . ".sales_order so
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = so.customer_id
            LEFT JOIN " . APP_SCHEMA . ".ppn_type pt ON pt.id = so.ppn_type_id
            LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = so.status_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = so.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = so.updated_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = so.approved_by";

    $result       = mysqli_query($conn, "SELECT so.*, c.customer_name, pt.ppn_name, ss.status_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by
            FROM $from WHERE $where ORDER BY so.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM $from WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Sales orders found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No sales orders found');
    }
}

function createSalesOrder($conn, $input, $username, $company_id) {
    $required = ['so_display_number', 'so_date', 'ppn_type_id', 'customer_id', 'send_date', 'items'];
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
        $item_required = ['product_name', 'quantity', 'uom_id', 'currency_id', 'unit_price', 'kurs'];
        foreach ($item_required as $field) {
            if (!isset($item[$field]) || (is_string($item[$field]) && trim($item[$field]) === '')) {
                jsonResponse(400, "items.$field is required");
                return;
            }
        }
    }

    $so_display_number = trim(mysqli_real_escape_string($conn, $input['so_display_number']));
    $so_date            = mysqli_real_escape_string($conn, $input['so_date']);
    $ppn_type_id        = mysqli_real_escape_string($conn, $input['ppn_type_id']);
    $customer_id        = mysqli_real_escape_string($conn, $input['customer_id']);
    $send_date          = mysqli_real_escape_string($conn, $input['send_date']);

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_order WHERE company_id = '$company_id' AND so_display_number = '$so_display_number' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Sales order already exists');
        return;
    }

    $status_id = getSalesStatusIdByName($conn, 'Draft');
    if (!$status_id) {
        jsonResponse(500, 'Default sales status "Draft" is not configured');
        return;
    }

    $send_to_address_sql = isset($input['send_to_address']) && trim($input['send_to_address']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['send_to_address']) . "'" : 'NULL';

    $sales_order_id = generateUUID();
    $now            = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO " . APP_SCHEMA . ".sales_order
                (id, company_id, so_display_number, so_date, ppn_type_id, customer_id, send_to_address, send_date, status_id,
                 created_by, created_at)
                VALUES
                ('$sales_order_id', '$company_id', '$so_display_number', '$so_date', '$ppn_type_id', '$customer_id', $send_to_address_sql, '$send_date', '$status_id',
                 '$username', '$now')";

        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }

        foreach ($input['items'] as $item) {
            $item_id            = generateUUID();
            $product_name        = mysqli_real_escape_string($conn, $item['product_name']);
            $quantity            = (float)$item['quantity'];
            $uom_id              = mysqli_real_escape_string($conn, $item['uom_id']);
            $currency_id         = mysqli_real_escape_string($conn, $item['currency_id']);
            $unit_price          = (float)$item['unit_price'];
            $kurs                = (float)$item['kurs'];
            $purchase_order_id_sql = isset($item['purchase_order_id']) && trim($item['purchase_order_id']) !== '' ? "'" . mysqli_real_escape_string($conn, $item['purchase_order_id']) . "'" : 'NULL';

            $item_sql = "INSERT INTO " . APP_SCHEMA . ".sales_order_item
                         (id, sales_order_id, purchase_order_id, product_name, quantity, uom_id, currency_id, unit_price, kurs, created_by, created_at)
                         VALUES ('$item_id', '$sales_order_id', $purchase_order_id_sql, '$product_name', $quantity, '$uom_id', '$currency_id', $unit_price, $kurs, '$username', '$now')";

            if (!mysqli_query($conn, $item_sql)) {
                throw new Exception(mysqli_error($conn));
            }
        }

        insertAuditLog($conn, $company_id, 'sales_order', $sales_order_id, 'created', $username);

        $conn->commit();

        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_pending',
            'source_module'      => 'sales_order',
            'source_document_id' => $sales_order_id,
            'title'              => 'Sales Order Menunggu Approval',
            'body'               => "Dokumen Sales Order *$so_display_number* membutuhkan persetujuan Bapak/Ibu. Silakan klik link di bawah untuk meninjau dan menyetujui:",
            'created_by'         => $username,
            'recipients'         => resolveApprovalRecipients($conn, $company_id, 'sales_order'),
        ]);

        jsonResponse(201, 'Sales order created successfully', ['sales_order_id' => $sales_order_id]);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(500, 'Failed to create sales order', ['error' => $e->getMessage()]);
    }
}

function getDetailSalesOrder($conn, $sales_order_id, $company_id) {
    $sales_order_id = mysqli_real_escape_string($conn, $sales_order_id);

    $from = APP_SCHEMA . ".sales_order so
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = so.customer_id
            LEFT JOIN " . APP_SCHEMA . ".ppn_type pt ON pt.id = so.ppn_type_id
            LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = so.status_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = so.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = so.updated_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = so.approved_by";

    $result = mysqli_query($conn, "SELECT so.*, c.customer_name, pt.ppn_name, ss.status_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by
            FROM $from WHERE so.id = '$sales_order_id' AND so.company_id = '$company_id' AND so.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Sales order not found');
        return;
    }

    $sales_order = mysqli_fetch_assoc($result);

    $items_from   = APP_SCHEMA . ".sales_order_item soi
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = soi.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = soi.updated_by";
    $items_result = mysqli_query($conn, "SELECT soi.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $items_from WHERE soi.sales_order_id = '$sales_order_id' AND soi.deleted_at IS NULL ORDER BY soi.created_at ASC");
    $sales_order['items'] = $items_result ? mysqli_fetch_all($items_result, MYSQLI_ASSOC) : [];

    jsonResponse(200, 'Sales order found', $sales_order);
}

function updateSalesOrder($conn, $sales_order_id, $input, $username, $company_id) {
    $sales_order_id = mysqli_real_escape_string($conn, $sales_order_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_order WHERE id = '$sales_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales order not found');
        return;
    }

    $updates = [];

    $string_fields = ['so_display_number', 'ppn_type_id', 'customer_id', 'send_to_address'];
    foreach ($string_fields as $field) {
        if (isset($input[$field])) {
            $val = trim(mysqli_real_escape_string($conn, $input[$field]));
            if ($val === '') { jsonResponse(400, "$field cannot be empty"); return; }
            $updates[] = "$field = '$val'";
        }
    }

    $date_fields = ['so_date', 'send_date'];
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

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_order SET " . implode(', ', $updates) . " WHERE id = '$sales_order_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_order', $sales_order_id, 'updated', $username);
        jsonResponse(200, 'Sales order updated successfully');
    } else {
        jsonResponse(500, 'Failed to update sales order', ['error' => mysqli_error($conn)]);
    }
}

function deleteSalesOrder($conn, $sales_order_id, $username, $company_id) {
    $sales_order_id = mysqli_real_escape_string($conn, $sales_order_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_order WHERE id = '$sales_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales order not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_order SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$sales_order_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_order', $sales_order_id, 'deleted', $username);
        jsonResponse(200, 'Sales order deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete sales order', ['error' => mysqli_error($conn)]);
    }
}

function approveSalesOrder($conn, $sales_order_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT so.so_display_number, so.created_by, c.customer_name, pt.ppn_percentage, pt.ppn_name
            FROM " . APP_SCHEMA . ".sales_order so
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = so.customer_id
            LEFT JOIN " . APP_SCHEMA . ".ppn_type pt ON pt.id = so.ppn_type_id
            WHERE so.id = '$sales_order_id' AND so.company_id = '$company_id' AND so.deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales order not found');
        return;
    }
    $sales_order = mysqli_fetch_assoc($check);

    $status_id = getSalesStatusIdByName($conn, 'Approved');
    if (!$status_id) {
        jsonResponse(500, 'Sales status "Approved" is not configured');
        return;
    }

    $now       = date('Y-m-d H:i:s');
    $notes     = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_order
            SET status_id = '$status_id', approved_by = '$username', approved_at = '$now', updated_by = '$username', updated_at = '$now'
            WHERE id = '$sales_order_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_order', $sales_order_id, 'approved', $username, $notes);
        invalidateApprovalTokens($conn, 'sales_order', $sales_order_id);

        $approver_name = resolveDisplayName($conn, $username);
        $total         = calculateSalesOrderTotal($conn, $sales_order_id, $sales_order['ppn_percentage'] ?? 0, $sales_order['ppn_name'] ?? '');
        $detail_link   = rtrim(APPROVAL_BASE_URL, '/') . '/sales/sales-order/' . $sales_order_id;

        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_approved',
            'source_module'      => 'sales_order',
            'source_document_id' => $sales_order_id,
            'title'              => 'Sales Order Disetujui',
            'body'               => "Dokumen Sales Order *{$sales_order['so_display_number']}* telah *disetujui* oleh $approver_name pada " . formatIndonesianDate($now) . ', ' . date('H:i', strtotime($now)) . " WIB.\n\n" .
                                     "Customer: {$sales_order['customer_name']}\n" .
                                     'Total: Rp ' . number_format($total, 0, ',', '.') . "\n\n" .
                                     "Lihat detail dokumen pada link berikut:\n$detail_link",
            'created_by'         => $username,
            'recipients'         => [$sales_order['created_by']],
        ]);

        jsonResponse(200, 'Sales order approved successfully');
    } else {
        jsonResponse(500, 'Failed to approve sales order', ['error' => mysqli_error($conn)]);
    }
}

function rejectSalesOrder($conn, $sales_order_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT so_display_number, created_by FROM " . APP_SCHEMA . ".sales_order WHERE id = '$sales_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales order not found');
        return;
    }
    $sales_order = mysqli_fetch_assoc($check);

    $status_id = getSalesStatusIdByName($conn, 'Rejected');
    if (!$status_id) {
        jsonResponse(500, 'Sales status "Rejected" is not configured');
        return;
    }

    $now       = date('Y-m-d H:i:s');
    $notes     = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_order
            SET status_id = '$status_id', updated_by = '$username', updated_at = '$now'
            WHERE id = '$sales_order_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_order', $sales_order_id, 'rejected', $username, $notes);
        invalidateApprovalTokens($conn, 'sales_order', $sales_order_id);

        $rejector_name = resolveDisplayName($conn, $username);
        $reason_text   = $notes ? " Alasan: $notes." : '';
        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_rejected',
            'source_module'      => 'sales_order',
            'source_document_id' => $sales_order_id,
            'title'              => 'Sales Order Ditolak',
            'body'               => "Dokumen Sales Order *{$sales_order['so_display_number']}* *ditolak* oleh $rejector_name.$reason_text",
            'created_by'         => $username,
            'recipients'         => [$sales_order['created_by']],
        ]);

        jsonResponse(200, 'Sales order rejected successfully');
    } else {
        jsonResponse(500, 'Failed to reject sales order', ['error' => mysqli_error($conn)]);
    }
}

function reviseSalesOrder($conn, $sales_order_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT ss.status_name FROM " . APP_SCHEMA . ".sales_order so
            LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = so.status_id
            WHERE so.id = '$sales_order_id' AND so.company_id = '$company_id' AND so.deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales order not found');
        return;
    }

    $sales_order = mysqli_fetch_assoc($check);
    if ($sales_order['status_name'] !== 'Rejected') {
        jsonResponse(400, 'Only rejected sales orders can be revised');
        return;
    }

    $status_id = getSalesStatusIdByName($conn, 'Draft');
    if (!$status_id) {
        jsonResponse(500, 'Default sales status "Draft" is not configured');
        return;
    }

    $now       = date('Y-m-d H:i:s');
    $notes     = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_order
            SET status_id = '$status_id', updated_by = '$username', updated_at = '$now'
            WHERE id = '$sales_order_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_order', $sales_order_id, 'revised', $username, $notes);
        jsonResponse(200, 'Sales order revised successfully');
    } else {
        jsonResponse(500, 'Failed to revise sales order', ['error' => mysqli_error($conn)]);
    }
}

function exportSalesOrder($conn, $sales_order_id, $company_id) {
    $sales_order_id = mysqli_real_escape_string($conn, $sales_order_id);

    $from   = APP_SCHEMA . ".sales_order so
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = so.customer_id
            LEFT JOIN " . APP_SCHEMA . ".ppn_type pt ON pt.id = so.ppn_type_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = so.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = so.approved_by";
    $result = mysqli_query($conn, "SELECT so.*, c.customer_name, c.customer_address, c.customer_top_days,
            pt.ppn_name, pt.ppn_percentage,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by_name,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by_name
            FROM $from WHERE so.id = '$sales_order_id' AND so.company_id = '$company_id' AND so.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Sales order not found');
        return;
    }

    $sales_order = mysqli_fetch_assoc($result);

    $items_from   = APP_SCHEMA . ".sales_order_item soi
            LEFT JOIN " . APP_SCHEMA . ".unit_of_measure uom ON uom.id = soi.uom_id
            LEFT JOIN " . APP_SCHEMA . ".currency cur ON cur.id = soi.currency_id
            LEFT JOIN " . APP_SCHEMA . ".purchase_order po ON po.id = soi.purchase_order_id";
    $items_result = mysqli_query($conn, "SELECT soi.*, uom.uom_name, cur.currency_name, po.po_display_number
            FROM $items_from WHERE soi.sales_order_id = '$sales_order_id' AND soi.deleted_at IS NULL ORDER BY soi.created_at ASC");
    $items = $items_result ? mysqli_fetch_all($items_result, MYSQLI_ASSOC) : [];

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    $sheet->mergeCells('A1:B1');
    $sheet->setCellValue('A1', 'SALES ORDER');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->setCellValue('A2', 'No SO :');
    $sheet->setCellValue('B2', $sales_order['so_display_number']);
    $sheet->setCellValue('F2', 'Customer :');
    $sheet->setCellValue('G2', $sales_order['customer_name']);
    $sheet->setCellValue('F3', 'ALAMAT :');
    $sheet->setCellValue('G3', $sales_order['customer_address']);
    $sheet->setCellValue('F4', 'PO NO :');
    $sheet->setCellValue('G4', $items[0]['po_display_number'] ?? '-');
    $sheet->setCellValue('A3', 'Tanggal :');
    $sheet->setCellValue('B3', formatIndonesianDate($sales_order['so_date']));
    $sheet->setCellValue('A4', 'PPN/NO PPN : ');
    $sheet->setCellValue('B4', $sales_order['ppn_name']);

    $table_headers = ['NO', 'BARANG', 'QTY', 'SAT', 'CURR', 'HARGA @', 'TOTAL', 'KURS', 'DPP', 'PPN'];
    $sheet->fromArray($table_headers, null, 'A5');
    $sheet->getStyle('A5:J5')->getFont()->setBold(true);
    $sheet->getStyle('A5:J5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A5:J5')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $row        = 6;
    $no         = 1;
    $total_dpp  = 0;
    $total_ppn  = 0;
    $ppn_percentage = (float)($sales_order['ppn_percentage'] ?? 0);

    foreach ($items as $item) {
        $dpp = (float)$item['quantity'] * (float)$item['unit_price'];
        $ppn = $ppn_percentage * $dpp / 100;

        $sheet->setCellValue("A$row", $no);
        $sheet->setCellValue("B$row", $item['product_name']);
        $sheet->setCellValue("C$row", $item['quantity']);
        $sheet->setCellValue("D$row", $item['uom_name']);
        $sheet->setCellValue("E$row", $item['currency_name']);
        $sheet->setCellValue("F$row", number_format((float)$item['unit_price'], 2));
        $sheet->setCellValue("G$row", number_format($dpp, 2));
        $sheet->setCellValue("H$row", number_format((float)$item['kurs'], 2));
        $sheet->setCellValue("I$row", number_format($dpp, 2));
        $sheet->setCellValue("J$row", number_format($ppn, 2));

        $sheet->getStyle("A$row:J$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $total_dpp += $dpp;
        $total_ppn += $ppn;
        $row++;
        $no++;
    }

    $sheet->getStyle("A$row:J$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
    $sheet->getStyle("A$row:J$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->setCellValue("A$row", 'TOP :');
    $sheet->setCellValue("B$row", ($sales_order['customer_top_days'] ?? '-') . ' hari');
    $row++;
    $sheet->getStyle("A$row:J$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;

    $sheet->setCellValue("F$row", 'Total :');
    $sheet->setCellValue("G$row", number_format($total_dpp, 2));
    $sheet->setCellValue("I$row", number_format($total_dpp, 2));
    $sheet->setCellValue("J$row", number_format($total_ppn, 2));
    $sheet->getStyle("A$row:J$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;

    $sheet->mergeCells("I$row:J$row");
    $sheet->setCellValue("I$row", number_format($total_dpp + $total_ppn, 2));
    $sheet->getStyle("A$row:J$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;

    $sheet->setCellValue("A$row", 'DIKIRIM Tgl : ');
    $sheet->setCellValue("B$row", formatIndonesianDate($sales_order['send_date']));
    $row++;
    $sheet->setCellValue("A$row", 'DIKIRIM KE : ');
    $sheet->setCellValue("B$row", $sales_order['send_to_address'] ?? '-');
    $row++;

    $row += 2;
    $sheet->setCellValue("A$row", 'DIBUAT OLEH,');
    $sheet->setCellValue("D$row", 'MENGETAHUI OLEH,');
    $sheet->setCellValue("I$row", 'DISETUJUI OLEH,');
    $row++;
    $sheet->setCellValue("A$row", ($sales_order['created_by_name'] ?? '-') . ' pada ' . $sales_order['created_at']);
    $sheet->setCellValue("D$row", ($sales_order['created_by_name'] ?? '-') . ' pada ' . $sales_order['created_at']);
    $sheet->setCellValue("I$row", ($sales_order['approved_by_name'] ?? '-') . ' pada ' . ($sales_order['approved_at'] ?? '-'));
    $row += 3;
    $sheet->setCellValue("A$row", '( ADMIN SALES )');
    $sheet->setCellValue("D$row", '( TUKAR FAKTUR )');
    $sheet->setCellValue("I$row", '( SULANTO )    ( IRENE )');

    foreach (range('A', 'J') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    streamXlsx($spreadsheet, 'sales_order_' . sanitizeFilename($sales_order['so_display_number']) . '.xlsx');
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

$sales_order_id = !empty($action) ? $action : null;
$sub_action     = $parts[4] ?? '';

try {
    $conn = getConn();

    if ($sales_order_id === 'generate-number' && $sub_action === '') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        generateSalesOrderNumber($conn, $company_id);

    } elseif ($sales_order_id && $sub_action === 'items') {
        require __DIR__ . '/items.php';

    } elseif ($sales_order_id && $sub_action === 'export') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        exportSalesOrder($conn, $sales_order_id, $company_id);

    } elseif ($sales_order_id && $sub_action !== '') {
        $input = in_array($method, ['POST', 'PUT', 'PATCH'])
            ? (json_decode(file_get_contents('php://input'), true) ?? [])
            : [];

        switch ($sub_action) {
            case 'approve':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                approveSalesOrder($conn, $sales_order_id, $input, $username, $company_id);
                break;
            case 'reject':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                rejectSalesOrder($conn, $sales_order_id, $input, $username, $company_id);
                break;
            case 'revise':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                reviseSalesOrder($conn, $sales_order_id, $input, $username, $company_id);
                break;
            default:
                jsonResponse(404, 'Route not found');
        }

    } elseif ($sales_order_id) {
        switch ($method) {
            case 'GET':
                getDetailSalesOrder($conn, $sales_order_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateSalesOrder($conn, $sales_order_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteSalesOrder($conn, $sales_order_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }

    } else {
        switch ($method) {
            case 'GET':
                getAllSalesOrders($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createSalesOrder($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
