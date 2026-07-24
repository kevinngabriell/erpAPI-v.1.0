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

function calculateSalesProfitTotal($conn, $sales_profit_id) {
    $sales_profit_id = mysqli_real_escape_string($conn, $sales_profit_id);
    $result = mysqli_query($conn, "SELECT SUM(quantity * (price - landed_cost)) AS total_profit
            FROM " . APP_SCHEMA . ".sales_profit_item WHERE sales_profit_id = '$sales_profit_id' AND deleted_at IS NULL");
    return $result ? (float)(mysqli_fetch_assoc($result)['total_profit'] ?? 0) : 0;
}

function getAllSalesProfits($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "sp.company_id = '$company_id' AND sp.deleted_at IS NULL";
    if ($search) {
        $where .= " AND so.so_display_number LIKE '%$search%'";
    }
    if (isset($params['customer_id']) && trim($params['customer_id']) !== '') {
        $customer_id = mysqli_real_escape_string($conn, $params['customer_id']);
        $where .= " AND sp.customer_id = '$customer_id'";
    }
    if (isset($params['sales_order_id']) && trim($params['sales_order_id']) !== '') {
        $sales_order_id = mysqli_real_escape_string($conn, $params['sales_order_id']);
        $where .= " AND sp.sales_order_id = '$sales_order_id'";
    }
    if (isset($params['date_from']) && trim($params['date_from']) !== '') {
        $date_from = mysqli_real_escape_string($conn, $params['date_from']);
        $where .= " AND sp.created_at >= '$date_from 00:00:00'";
    }
    if (isset($params['date_to']) && trim($params['date_to']) !== '') {
        $date_to = mysqli_real_escape_string($conn, $params['date_to']);
        $where .= " AND sp.created_at <= '$date_to 23:59:59'";
    }

    $from = APP_SCHEMA . ".sales_profit sp
            LEFT JOIN " . APP_SCHEMA . ".sales_order so ON so.id = sp.sales_order_id
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = sp.customer_id
            LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = sp.status_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = sp.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = sp.updated_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = sp.approved_by";

    $result       = mysqli_query($conn, "SELECT sp.*, so.so_display_number, c.customer_name, ss.status_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by
            FROM $from WHERE $where ORDER BY sp.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM $from WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Sales profits found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No sales profits found');
    }
}

function createSalesProfit($conn, $input, $username, $company_id) {
    $required = ['sales_order_id', 'customer_id', 'items'];
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
        $item_required = ['product_name', 'quantity', 'price', 'landed_cost'];
        foreach ($item_required as $field) {
            if (!isset($item[$field]) || (is_string($item[$field]) && trim($item[$field]) === '')) {
                jsonResponse(400, "items.$field is required");
                return;
            }
        }
    }

    $sales_order_id = mysqli_real_escape_string($conn, $input['sales_order_id']);
    $customer_id    = mysqli_real_escape_string($conn, $input['customer_id']);

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

    $sales_profit_id = generateUUID();
    $now             = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO " . APP_SCHEMA . ".sales_profit
                (id, company_id, sales_order_id, customer_id, status_id, created_by, created_at)
                VALUES
                ('$sales_profit_id', '$company_id', '$sales_order_id', '$customer_id', '$status_id', '$username', '$now')";

        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }

        foreach ($input['items'] as $item) {
            $item_id      = generateUUID();
            $product_name = mysqli_real_escape_string($conn, $item['product_name']);
            $quantity     = (float)$item['quantity'];
            $price        = (float)$item['price'];
            $landed_cost  = (float)$item['landed_cost'];
            $purchase_order_id_sql = isset($item['purchase_order_id']) && trim($item['purchase_order_id']) !== '' ? "'" . mysqli_real_escape_string($conn, $item['purchase_order_id']) . "'" : 'NULL';

            $item_sql = "INSERT INTO " . APP_SCHEMA . ".sales_profit_item
                         (id, sales_profit_id, purchase_order_id, product_name, quantity, price, landed_cost, created_by, created_at)
                         VALUES ('$item_id', '$sales_profit_id', $purchase_order_id_sql, '$product_name', $quantity, $price, $landed_cost, '$username', '$now')";

            if (!mysqli_query($conn, $item_sql)) {
                throw new Exception(mysqli_error($conn));
            }
        }

        insertAuditLog($conn, $company_id, 'sales_profit', $sales_profit_id, 'created', $username);

        $conn->commit();
        jsonResponse(201, 'Sales profit created successfully', ['sales_profit_id' => $sales_profit_id]);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(500, 'Failed to create sales profit', ['error' => $e->getMessage()]);
    }
}

function getDetailSalesProfit($conn, $sales_profit_id, $company_id) {
    $sales_profit_id = mysqli_real_escape_string($conn, $sales_profit_id);

    $from   = APP_SCHEMA . ".sales_profit sp
            LEFT JOIN " . APP_SCHEMA . ".sales_order so ON so.id = sp.sales_order_id
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = sp.customer_id
            LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = sp.status_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = sp.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = sp.updated_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = sp.approved_by";
    $result = mysqli_query($conn, "SELECT sp.*, so.so_display_number, c.customer_name, ss.status_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by
            FROM $from WHERE sp.id = '$sales_profit_id' AND sp.company_id = '$company_id' AND sp.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Sales profit not found');
        return;
    }

    $sales_profit = mysqli_fetch_assoc($result);

    $items_from   = APP_SCHEMA . ".sales_profit_item spi
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = spi.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = spi.updated_by";
    $items_result = mysqli_query($conn, "SELECT spi.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $items_from WHERE spi.sales_profit_id = '$sales_profit_id' AND spi.deleted_at IS NULL ORDER BY spi.created_at ASC");
    $sales_profit['items'] = $items_result ? mysqli_fetch_all($items_result, MYSQLI_ASSOC) : [];

    jsonResponse(200, 'Sales profit found', $sales_profit);
}

function updateSalesProfit($conn, $sales_profit_id, $input, $username, $company_id) {
    $sales_profit_id = mysqli_real_escape_string($conn, $sales_profit_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_profit WHERE id = '$sales_profit_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales profit not found');
        return;
    }

    $updates = [];

    if (isset($input['customer_id'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['customer_id']));
        if ($val === '') { jsonResponse(400, 'customer_id cannot be empty'); return; }
        $updates[] = "customer_id = '$val'";
    }

    if (isset($input['sales_order_id'])) {
        $sales_order_id = trim(mysqli_real_escape_string($conn, $input['sales_order_id']));
        if ($sales_order_id === '') { jsonResponse(400, 'sales_order_id cannot be empty'); return; }

        $so_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_order WHERE id = '$sales_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($so_check) === 0) {
            jsonResponse(404, 'Sales order not found');
            return;
        }

        $updates[] = "sales_order_id = '$sales_order_id'";
    }

    // items is optional: when present, it fully replaces the profit's existing items
    // (old rows soft-deleted, new rows inserted) — same shape/validation as create,
    // so a rejected profit can correct item-level fields (landed_cost, price, quantity)
    // before resubmitting.
    $items_provided = array_key_exists('items', $input);
    if ($items_provided) {
        if (!is_array($input['items']) || count($input['items']) === 0) {
            jsonResponse(400, 'items must be a non-empty array');
            return;
        }
        foreach ($input['items'] as $item) {
            $item_required = ['product_name', 'quantity', 'price', 'landed_cost'];
            foreach ($item_required as $field) {
                if (!isset($item[$field]) || (is_string($item[$field]) && trim($item[$field]) === '')) {
                    jsonResponse(400, "items.$field is required");
                    return;
                }
            }
        }
    }

    if (empty($updates) && !$items_provided) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        if (!empty($updates)) {
            $header_updates   = $updates;
            $header_updates[] = "updated_by = '$username'";
            $header_updates[] = "updated_at = '$now'";
            if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_profit SET " . implode(', ', $header_updates) . " WHERE id = '$sales_profit_id' AND company_id = '$company_id'")) {
                throw new Exception(mysqli_error($conn));
            }
        }

        if ($items_provided) {
            if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_profit_item SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE sales_profit_id = '$sales_profit_id' AND deleted_at IS NULL")) {
                throw new Exception(mysqli_error($conn));
            }

            foreach ($input['items'] as $item) {
                $item_id      = generateUUID();
                $product_name = mysqli_real_escape_string($conn, $item['product_name']);
                $quantity     = (float)$item['quantity'];
                $price        = (float)$item['price'];
                $landed_cost  = (float)$item['landed_cost'];
                $purchase_order_id_sql = isset($item['purchase_order_id']) && trim($item['purchase_order_id']) !== '' ? "'" . mysqli_real_escape_string($conn, $item['purchase_order_id']) . "'" : 'NULL';

                $item_sql = "INSERT INTO " . APP_SCHEMA . ".sales_profit_item
                             (id, sales_profit_id, purchase_order_id, product_name, quantity, price, landed_cost, created_by, created_at)
                             VALUES ('$item_id', '$sales_profit_id', $purchase_order_id_sql, '$product_name', $quantity, $price, $landed_cost, '$username', '$now')";

                if (!mysqli_query($conn, $item_sql)) {
                    throw new Exception(mysqli_error($conn));
                }
            }
        }

        insertAuditLog($conn, $company_id, 'sales_profit', $sales_profit_id, 'updated', $username);

        $conn->commit();
        jsonResponse(200, 'Sales profit updated successfully');
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(500, 'Failed to update sales profit', ['error' => $e->getMessage()]);
    }
}

function deleteSalesProfit($conn, $sales_profit_id, $username, $company_id) {
    $sales_profit_id = mysqli_real_escape_string($conn, $sales_profit_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sales_profit WHERE id = '$sales_profit_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales profit not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_profit SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$sales_profit_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_profit', $sales_profit_id, 'deleted', $username);
        jsonResponse(200, 'Sales profit deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete sales profit', ['error' => mysqli_error($conn)]);
    }
}

function approveSalesProfit($conn, $sales_profit_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT sp.created_by, so.so_display_number, c.customer_name
            FROM " . APP_SCHEMA . ".sales_profit sp
            LEFT JOIN " . APP_SCHEMA . ".sales_order so ON so.id = sp.sales_order_id
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = sp.customer_id
            WHERE sp.id = '$sales_profit_id' AND sp.company_id = '$company_id' AND sp.deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales profit not found');
        return;
    }
    $sales_profit = mysqli_fetch_assoc($check);

    $status_id = getSalesStatusIdByName($conn, 'Approved');
    if (!$status_id) {
        jsonResponse(500, 'Sales status "Approved" is not configured');
        return;
    }

    $now   = date('Y-m-d H:i:s');
    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_profit
            SET status_id = '$status_id', approved_by = '$username', approved_at = '$now', updated_by = '$username', updated_at = '$now'
            WHERE id = '$sales_profit_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_profit', $sales_profit_id, 'approved', $username, $notes);

        $approver_name = resolveDisplayName($conn, $username);
        $total_profit  = calculateSalesProfitTotal($conn, $sales_profit_id);
        $detail_link   = rtrim(APPROVAL_BASE_URL, '/') . '/sales/sales-profit/' . $sales_profit_id;

        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_approved',
            'source_module'      => 'sales_profit',
            'source_document_id' => $sales_profit_id,
            'title'              => 'Sales Profit Disetujui',
            'body'               => "Dokumen Sales Profit *{$sales_profit['so_display_number']}* telah *disetujui* oleh $approver_name pada " . formatIndonesianDate($now) . ', ' . date('H:i', strtotime($now)) . " WIB.\n\n" .
                                     "Customer: {$sales_profit['customer_name']}\n" .
                                     'Total Profit: Rp ' . number_format($total_profit, 0, ',', '.') . "\n\n" .
                                     "Lihat detail dokumen pada link berikut:\n$detail_link",
            'created_by'         => $username,
            'recipients'         => [$sales_profit['created_by']],
        ]);

        jsonResponse(200, 'Sales profit approved successfully');
    } else {
        jsonResponse(500, 'Failed to approve sales profit', ['error' => mysqli_error($conn)]);
    }
}

function rejectSalesProfit($conn, $sales_profit_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT sp.created_by, so.so_display_number
            FROM " . APP_SCHEMA . ".sales_profit sp
            LEFT JOIN " . APP_SCHEMA . ".sales_order so ON so.id = sp.sales_order_id
            WHERE sp.id = '$sales_profit_id' AND sp.company_id = '$company_id' AND sp.deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales profit not found');
        return;
    }
    $sales_profit = mysqli_fetch_assoc($check);

    $status_id = getSalesStatusIdByName($conn, 'Rejected');
    if (!$status_id) {
        jsonResponse(500, 'Sales status "Rejected" is not configured');
        return;
    }

    $now   = date('Y-m-d H:i:s');
    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_profit
            SET status_id = '$status_id', updated_by = '$username', updated_at = '$now'
            WHERE id = '$sales_profit_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_profit', $sales_profit_id, 'rejected', $username, $notes);

        $rejector_name = resolveDisplayName($conn, $username);
        $reason_text   = $notes ? " Alasan: $notes" : '';

        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_rejected',
            'source_module'      => 'sales_profit',
            'source_document_id' => $sales_profit_id,
            'title'              => 'Sales Profit Ditolak',
            'body'               => "Dokumen Sales Profit *{$sales_profit['so_display_number']}* *ditolak* oleh $rejector_name.$reason_text",
            'created_by'         => $username,
            'recipients'         => [$sales_profit['created_by']],
        ]);

        jsonResponse(200, 'Sales profit rejected successfully');
    } else {
        jsonResponse(500, 'Failed to reject sales profit', ['error' => mysqli_error($conn)]);
    }
}

function reviseSalesProfit($conn, $sales_profit_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT ss.status_name FROM " . APP_SCHEMA . ".sales_profit sp
            LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = sp.status_id
            WHERE sp.id = '$sales_profit_id' AND sp.company_id = '$company_id' AND sp.deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales profit not found');
        return;
    }

    $sales_profit = mysqli_fetch_assoc($check);
    if ($sales_profit['status_name'] !== 'Rejected') {
        jsonResponse(400, 'Only rejected sales profit records can be revised');
        return;
    }

    $status_id = getSalesStatusIdByName($conn, 'Draft');
    if (!$status_id) {
        jsonResponse(500, 'Default sales status "Draft" is not configured');
        return;
    }

    $now   = date('Y-m-d H:i:s');
    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_profit
            SET status_id = '$status_id', updated_by = '$username', updated_at = '$now'
            WHERE id = '$sales_profit_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_profit', $sales_profit_id, 'revised', $username, $notes);
        jsonResponse(200, 'Sales profit revised successfully');
    } else {
        jsonResponse(500, 'Failed to revise sales profit', ['error' => mysqli_error($conn)]);
    }
}

function exportSalesProfit($conn, $sales_profit_id, $company_id) {
    $sales_profit_id = mysqli_real_escape_string($conn, $sales_profit_id);

    $from   = APP_SCHEMA . ".sales_profit sp
            LEFT JOIN " . APP_SCHEMA . ".sales_order so ON so.id = sp.sales_order_id
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = sp.customer_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = sp.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = sp.approved_by";
    $result = mysqli_query($conn, "SELECT sp.*, so.so_display_number, c.customer_name, c.customer_top_days,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by_name,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by_name
            FROM $from WHERE sp.id = '$sales_profit_id' AND sp.company_id = '$company_id' AND sp.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Sales profit not found');
        return;
    }

    $sales_profit = mysqli_fetch_assoc($result);

    $kurs_result = mysqli_query($conn, "SELECT kurs FROM " . APP_SCHEMA . ".sales_order_item
            WHERE sales_order_id = '" . $sales_profit['sales_order_id'] . "' AND deleted_at IS NULL ORDER BY created_at ASC LIMIT 1");
    $kurs_row    = $kurs_result ? mysqli_fetch_assoc($kurs_result) : null;
    $kurs        = $kurs_row ? $kurs_row['kurs'] : null;

    $items_result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".sales_profit_item
            WHERE sales_profit_id = '$sales_profit_id' AND deleted_at IS NULL ORDER BY created_at ASC");
    $items = $items_result ? mysqli_fetch_all($items_result, MYSQLI_ASSOC) : [];

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    $sheet->setCellValue('A1', 'PROFIT CALCULATION');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

    $sheet->getColumnDimension('A')->setWidth(10);
    $sheet->getColumnDimension('B')->setWidth(45);

    $sheet->mergeCells('A1:B1');
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A1')->getAlignment()->setWrapText(true);

    $sheet->setCellValue('A3', 'SO : ');
    $sheet->setCellValue('B3', $sales_profit['so_display_number'] ?? '-');
    $sheet->setCellValue('A4', 'Cust .');
    $sheet->setCellValue('B4', $sales_profit['customer_name']);

    $table_headers = ['No', 'Nama Barang', 'Qty', 'Harga Jual Satuan', 'Landed Cost', 'PROFIT', 'Profit %'];
    $sheet->fromArray($table_headers, null, 'A6');
    $sheet->getStyle('A6:G6')->getFont()->setBold(true);
    $sheet->getStyle('A6:G6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A6:G6')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $sheet->setCellValue('B7', 'Kurs = ' . ($kurs ?? 'N/A'));
    $sheet->getStyle('A7:G7')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle('A8:G8')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $row              = 9;
    $no               = 1;
    $total_qty        = 0;
    $total_price      = 0;
    $total_landed     = 0;
    $total_profit     = 0;
    $total_percentage = 0;

    foreach ($items as $item) {
        $quantity    = (float)$item['quantity'];
        $price       = (float)$item['price'];
        $landed_cost = (float)$item['landed_cost'];
        $profit      = $price - $landed_cost;
        $percentage  = $landed_cost != 0 ? ($profit / $landed_cost * 100) : 0;

        $sheet->setCellValue("A$row", $no);
        $sheet->setCellValue("B$row", $item['product_name']);
        $sheet->setCellValue("C$row", number_format($quantity, 0));
        $sheet->setCellValue("D$row", number_format($price, 2));
        $sheet->setCellValue("E$row", number_format($landed_cost, 2));
        $sheet->setCellValue("F$row", number_format($profit, 2));
        $sheet->setCellValue("G$row", round($percentage, 2));

        $sheet->getStyle("A$row:G$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $total_qty        += $quantity;
        $total_price       += $price;
        $total_landed      += $landed_cost;
        $total_profit      += $profit;
        $total_percentage  += $percentage;

        $row++;
        $no++;
    }

    $sheet->getStyle("A$row:G$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
    $sheet->getStyle("A$row:G$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
    $sheet->getStyle("A$row:G$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
    $sheet->getStyle("A$row:G$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->setCellValue("A$row", 'TOP :');
    $sheet->setCellValue("B$row", ($sales_profit['customer_top_days'] ?? '-') . ' hari');
    $row++;

    $sheet->setCellValue("B$row", 'TOTAL');
    $sheet->setCellValue("C$row", $total_qty);
    $sheet->setCellValue("D$row", $total_price);
    $sheet->setCellValue("E$row", $total_landed);
    $sheet->setCellValue("F$row", $total_profit);
    $sheet->setCellValue("G$row", round($total_percentage, 2));
    $sheet->getStyle("A$row:G$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;

    $row += 2;
    $sheet->setCellValue("A$row", 'DIBUAT OLEH,');
    $sheet->setCellValue("C$row", 'MENGETAHUI OLEH,');
    $sheet->setCellValue("F$row", 'DISETUJUI OLEH,');
    $row++;
    $sheet->setCellValue("A$row", ($sales_profit['created_by_name'] ?? '-') . ' pada ' . $sales_profit['created_at']);
    $sheet->setCellValue("C$row", ($sales_profit['created_by_name'] ?? '-') . ' pada ' . $sales_profit['created_at']);
    $sheet->setCellValue("F$row", ($sales_profit['approved_by_name'] ?? '-') . ' pada ' . ($sales_profit['approved_at'] ?? '-'));
    $row += 3;
    $sheet->setCellValue("A$row", '( ADMIN SALES )');
    $sheet->setCellValue("C$row", '( TUKAR FAKTUR )');
    $sheet->setCellValue("F$row", '( SULANTO )    ( IRENE )');

    foreach (range('A', 'J') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    streamXlsx($spreadsheet, 'sales_profit_' . sanitizeFilename($sales_profit['so_display_number'] ?? $sales_profit_id) . '.xlsx');
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

$sales_profit_id = !empty($action) ? $action : null;
$sub_action       = $parts[4] ?? '';

try {
    $conn = getConn();

    if ($sales_profit_id && $sub_action === 'export') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        exportSalesProfit($conn, $sales_profit_id, $company_id);

    } elseif ($sales_profit_id && $sub_action !== '') {
        $input = in_array($method, ['POST', 'PUT', 'PATCH'])
            ? (json_decode(file_get_contents('php://input'), true) ?? [])
            : [];

        switch ($sub_action) {
            case 'approve':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                approveSalesProfit($conn, $sales_profit_id, $input, $username, $company_id);
                break;
            case 'reject':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                rejectSalesProfit($conn, $sales_profit_id, $input, $username, $company_id);
                break;
            case 'revise':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                reviseSalesProfit($conn, $sales_profit_id, $input, $username, $company_id);
                break;
            default:
                jsonResponse(404, 'Route not found');
        }

    } elseif ($sales_profit_id) {
        switch ($method) {
            case 'GET':
                getDetailSalesProfit($conn, $sales_profit_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateSalesProfit($conn, $sales_profit_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteSalesProfit($conn, $sales_profit_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllSalesProfits($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createSalesProfit($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
