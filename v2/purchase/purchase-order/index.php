<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/audit_log.php';
require_once __DIR__ . '/../../helpers/notification.php';
require_once __DIR__ . '/../../helpers/excel_export.php';
require_once __DIR__ . '/../../helpers/word_export.php';

use PhpOffice\PhpWord\TemplateProcessor;

const PURCHASE_ORDER_SHIPMENT_METHODS = ['FOB', 'CIF', 'EXW', 'CFR', 'CIP', 'DAP', 'DDP', 'FCA'];
const PURCHASE_ORDER_SHIPPING_MARKS_KEY = 'purchase_order.shipping_marks_default';

function getPurchaseStatusIdByName($conn, $status_name) {
    $status_name = mysqli_real_escape_string($conn, $status_name);
    $result = mysqli_query($conn, "SELECT id FROM " . APP_SCHEMA . ".purchase_status WHERE status_name = '$status_name' AND deleted_at IS NULL LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? $row['id'] : null;
}

function getCompanyCode($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT company_code FROM " . CORE_SCHEMA . ".app_company WHERE company_id = '$company_id' LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? strtoupper($row['company_code']) : null;
}

function generatePurchaseOrderNumber($conn, $company_id, $params) {
    $type_id = trim($params['type_id'] ?? '');
    if ($type_id === '') {
        jsonResponse(400, 'type_id is required');
        return;
    }

    $type_id_escaped = mysqli_real_escape_string($conn, $type_id);
    $result = mysqli_query($conn, "SELECT type_name, number_format, sequence_digits FROM " . APP_SCHEMA . ".purchase_type WHERE id = '$type_id_escaped' AND deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Purchase type not found');
        return;
    }
    $purchase_type = mysqli_fetch_assoc($result);

    $number_format = $purchase_type['number_format'];
    if (!$number_format || !str_contains($number_format, '{seq}')) {
        jsonResponse(500, "Purchase type \"{$purchase_type['type_name']}\" has no number_format configured");
        return;
    }

    $company_code = getCompanyCode($conn, $company_id);
    if (!$company_code) {
        jsonResponse(404, 'Company not found');
        return;
    }

    $roman_months = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
    $roman_month  = $roman_months[(int)date('n') - 1];

    $tokens = [
        '{company_code}' => $company_code,
        '{month}'        => $roman_month,
        '{yyyy}'         => date('Y'),
        '{yy}'           => date('y'),
    ];

    $sequence_digits = (int)($purchase_type['sequence_digits'] ?? 4);
    $pattern          = mysqli_real_escape_string($conn, strtr($number_format, $tokens + ['{seq}' => '%']));

    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".purchase_order WHERE company_id = '$company_id' AND type_id = '$type_id_escaped' AND po_display_number LIKE '$pattern'");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    $sequence           = str_pad((string)($total + 1), $sequence_digits, '0', STR_PAD_LEFT);
    $po_display_number = strtr($number_format, $tokens + ['{seq}' => $sequence]);

    jsonResponse(200, 'Purchase order number generated successfully', ['po_display_number' => $po_display_number]);
}

function getShippingMarksDefault($conn, $company_id) {
    $shipping_marks_default = getCompanySetting($conn, $company_id, PURCHASE_ORDER_SHIPPING_MARKS_KEY, '');
    jsonResponse(200, 'Shipping marks default retrieved', ['shipping_marks_default' => $shipping_marks_default]);
}

function updateShippingMarksDefault($conn, $input, $username, $company_id) {
    if (!isset($input['shipping_marks_default'])) {
        jsonResponse(400, 'shipping_marks_default is required');
        return;
    }

    $shipping_marks_default = trim($input['shipping_marks_default']);

    setCompanySetting($conn, $company_id, PURCHASE_ORDER_SHIPPING_MARKS_KEY, $shipping_marks_default, $username);
    insertAuditLog($conn, $company_id, 'company_setting', PURCHASE_ORDER_SHIPPING_MARKS_KEY, 'updated', $username);
    jsonResponse(200, 'Shipping marks default updated successfully', ['shipping_marks_default' => $shipping_marks_default]);
}

function getAllPurchaseOrders($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "po.company_id = '$company_id' AND po.deleted_at IS NULL";
    if ($search) {
        $where .= " AND po.po_display_number LIKE '%$search%'";
    }
    if (isset($params['status_id']) && trim($params['status_id']) !== '') {
        $status_id = mysqli_real_escape_string($conn, $params['status_id']);
        $where .= " AND po.status_id = '$status_id'";
    }
    if (isset($params['supplier_id']) && trim($params['supplier_id']) !== '') {
        $supplier_id = mysqli_real_escape_string($conn, $params['supplier_id']);
        $where .= " AND po.supplier_id = '$supplier_id'";
    }
    if (isset($params['type_id']) && trim($params['type_id']) !== '') {
        $type_id = mysqli_real_escape_string($conn, $params['type_id']);
        $where .= " AND po.type_id = '$type_id'";
    }
    if (isset($params['date_from']) && trim($params['date_from']) !== '') {
        $date_from = mysqli_real_escape_string($conn, $params['date_from']);
        $where .= " AND po.po_date >= '$date_from'";
    }
    if (isset($params['date_to']) && trim($params['date_to']) !== '') {
        $date_to = mysqli_real_escape_string($conn, $params['date_to']);
        $where .= " AND po.po_date <= '$date_to'";
    }

    $from = APP_SCHEMA . ".purchase_order po
            LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = po.supplier_id
            LEFT JOIN " . APP_SCHEMA . ".purchase_status ps ON ps.id = po.status_id
            LEFT JOIN " . APP_SCHEMA . ".payment_term pt ON pt.id = po.term_id
            LEFT JOIN " . APP_SCHEMA . ".payment_method pm ON pm.id = po.payment_method_id
            LEFT JOIN " . APP_SCHEMA . ".origin o ON o.id = po.origin_id
            LEFT JOIN " . APP_SCHEMA . ".purchase_type pty ON pty.id = po.type_id
            LEFT JOIN " . APP_SCHEMA . ".currency cur ON cur.id = po.currency_id
            LEFT JOIN " . APP_SCHEMA . ".ppn_type ppn ON ppn.id = po.ppn_type_id
            LEFT JOIN " . APP_SCHEMA . ".shipment_period sp ON sp.id = po.shipment_period_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = po.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = po.updated_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = po.approved_by";

    $result       = mysqli_query($conn, "SELECT po.*, s.supplier_name, ps.status_name, pt.term_name, pm.method_name,
            o.origin_name, pty.type_name, cur.currency_code, cur.currency_name, ppn.ppn_name, sp.period_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by
            FROM $from WHERE $where ORDER BY po.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM $from WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Purchase orders found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No purchase orders found');
    }
}

function createPurchaseOrder($conn, $input, $username, $company_id) {
    $required = ['po_display_number', 'po_date', 'supplier_id', 'items'];
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

    $shipment_method = null;
    if (isset($input['shipment_method']) && trim($input['shipment_method']) !== '') {
        $shipment_method = strtoupper(trim($input['shipment_method']));
        if (!in_array($shipment_method, PURCHASE_ORDER_SHIPMENT_METHODS, true)) {
            jsonResponse(400, 'shipment_method must be one of ' . implode(', ', PURCHASE_ORDER_SHIPMENT_METHODS));
            return;
        }
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

    $po_display_number = trim(mysqli_real_escape_string($conn, $input['po_display_number']));
    $po_date            = mysqli_real_escape_string($conn, $input['po_date']);
    $supplier_id        = mysqli_real_escape_string($conn, $input['supplier_id']);

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_order WHERE company_id = '$company_id' AND po_display_number = '$po_display_number' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Purchase order already exists');
        return;
    }

    $status_id = getPurchaseStatusIdByName($conn, 'Draft');
    if (!$status_id) {
        jsonResponse(500, 'Default purchase status "Draft" is not configured');
        return;
    }

    $shipment_method_sql     = $shipment_method !== null ? "'$shipment_method'" : 'NULL';
    $shipment_period_id_sql  = isset($input['shipment_period_id']) && trim($input['shipment_period_id']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['shipment_period_id']) . "'" : 'NULL';
    $term_id_sql          = isset($input['term_id']) && trim($input['term_id']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['term_id']) . "'" : 'NULL';
    $payment_method_id_sql = isset($input['payment_method_id']) && trim($input['payment_method_id']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['payment_method_id']) . "'" : 'NULL';
    $origin_id_sql        = isset($input['origin_id']) && trim($input['origin_id']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['origin_id']) . "'" : 'NULL';
    $shipping_marks_sql   = isset($input['shipping_marks']) && trim($input['shipping_marks']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['shipping_marks']) . "'" : 'NULL';
    $remarks_sql          = isset($input['remarks']) && trim($input['remarks']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['remarks']) . "'" : 'NULL';
    $type_id_sql          = isset($input['type_id']) && trim($input['type_id']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['type_id']) . "'" : 'NULL';
    $currency_id_sql      = isset($input['currency_id']) && trim($input['currency_id']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['currency_id']) . "'" : 'NULL';
    $ppn_type_id_sql      = isset($input['ppn_type_id']) && trim($input['ppn_type_id']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['ppn_type_id']) . "'" : 'NULL';
    $container_number_sql = isset($input['container_number']) && trim($input['container_number']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['container_number']) . "'" : 'NULL';
    $bl_number_sql        = isset($input['bl_number']) && trim($input['bl_number']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['bl_number']) . "'" : 'NULL';
    $vessel_name_sql      = isset($input['vessel_name']) && trim($input['vessel_name']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['vessel_name']) . "'" : 'NULL';
    $etd_date_sql         = isset($input['etd_date']) && trim($input['etd_date']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['etd_date']) . "'" : 'NULL';
    $eta_date_sql         = isset($input['eta_date']) && trim($input['eta_date']) !== '' ? "'" . mysqli_real_escape_string($conn, $input['eta_date']) . "'" : 'NULL';

    $po_id = generateUUID();
    $now   = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO " . APP_SCHEMA . ".purchase_order
                (id, company_id, po_display_number, po_date, supplier_id, shipment_method, shipment_period_id,
                 term_id, payment_method_id, origin_id, shipping_marks, remarks, status_id, type_id,
                 currency_id, ppn_type_id, container_number, bl_number, vessel_name, etd_date, eta_date,
                 created_by, created_at)
                VALUES
                ('$po_id', '$company_id', '$po_display_number', '$po_date', '$supplier_id', $shipment_method_sql, $shipment_period_id_sql,
                 $term_id_sql, $payment_method_id_sql, $origin_id_sql, $shipping_marks_sql, $remarks_sql, '$status_id', $type_id_sql,
                 $currency_id_sql, $ppn_type_id_sql, $container_number_sql, $bl_number_sql, $vessel_name_sql, $etd_date_sql, $eta_date_sql,
                 '$username', '$now')";

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

            $item_sql = "INSERT INTO " . APP_SCHEMA . ".purchase_order_item
                         (id, purchase_order_id, product_name, quantity, packaging_size, unit_price, vat, total, created_by, created_at)
                         VALUES ('$item_id', '$po_id', '$product_name', $quantity, $packaging_size, $unit_price, $vat, $total, '$username', '$now')";

            if (!mysqli_query($conn, $item_sql)) {
                throw new Exception(mysqli_error($conn));
            }
        }

        insertAuditLog($conn, $company_id, 'purchase_order', $po_id, 'created', $username);

        $conn->commit();

        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_pending',
            'source_module'      => 'purchase_order',
            'source_document_id' => $po_id,
            'title'              => 'Purchase Order Menunggu Approval',
            'body'               => "Dokumen Purchase Order *$po_display_number* membutuhkan persetujuan Bapak/Ibu. Silakan klik link di bawah untuk meninjau dan menyetujui:",
            'created_by'         => $username,
            'recipients'         => resolveApprovalRecipients($conn, $company_id, 'purchase_order'),
        ]);

        jsonResponse(201, 'Purchase order created successfully', ['purchase_order_id' => $po_id]);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(500, 'Failed to create purchase order', ['error' => $e->getMessage()]);
    }
}

function getDetailPurchaseOrder($conn, $purchase_order_id, $company_id) {
    $purchase_order_id = mysqli_real_escape_string($conn, $purchase_order_id);

    $from   = APP_SCHEMA . ".purchase_order po
            LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = po.supplier_id
            LEFT JOIN " . APP_SCHEMA . ".purchase_status ps ON ps.id = po.status_id
            LEFT JOIN " . APP_SCHEMA . ".payment_term pt ON pt.id = po.term_id
            LEFT JOIN " . APP_SCHEMA . ".payment_method pm ON pm.id = po.payment_method_id
            LEFT JOIN " . APP_SCHEMA . ".origin o ON o.id = po.origin_id
            LEFT JOIN " . APP_SCHEMA . ".purchase_type pty ON pty.id = po.type_id
            LEFT JOIN " . APP_SCHEMA . ".currency cur ON cur.id = po.currency_id
            LEFT JOIN " . APP_SCHEMA . ".ppn_type ppn ON ppn.id = po.ppn_type_id
            LEFT JOIN " . APP_SCHEMA . ".shipment_period sp ON sp.id = po.shipment_period_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = po.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = po.updated_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = po.approved_by";
    $result = mysqli_query($conn, "SELECT po.*, s.supplier_name, ps.status_name, pt.term_name, pm.method_name,
            o.origin_name, pty.type_name, cur.currency_code, cur.currency_name, ppn.ppn_name, sp.period_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by
            FROM $from WHERE po.id = '$purchase_order_id' AND po.company_id = '$company_id' AND po.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Purchase order not found');
        return;
    }

    $purchase_order = mysqli_fetch_assoc($result);

    $items_from   = APP_SCHEMA . ".purchase_order_item poi
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = poi.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = poi.updated_by";
    $items_result = mysqli_query($conn, "SELECT poi.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $items_from WHERE poi.purchase_order_id = '$purchase_order_id' AND poi.deleted_at IS NULL ORDER BY poi.created_at ASC");
    $purchase_order['items'] = $items_result ? mysqli_fetch_all($items_result, MYSQLI_ASSOC) : [];

    jsonResponse(200, 'Purchase order found', $purchase_order);
}

function updatePurchaseOrder($conn, $purchase_order_id, $input, $username, $company_id) {
    $purchase_order_id = mysqli_real_escape_string($conn, $purchase_order_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_order WHERE id = '$purchase_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase order not found');
        return;
    }

    $updates = [];

    $string_fields = [
        'po_display_number', 'supplier_id', 'term_id', 'payment_method_id', 'origin_id',
        'type_id', 'currency_id', 'ppn_type_id',
        'container_number', 'bl_number', 'vessel_name', 'shipment_period_id',
    ];
    foreach ($string_fields as $field) {
        if (isset($input[$field])) {
            $val = trim(mysqli_real_escape_string($conn, $input[$field]));
            if ($val === '') { jsonResponse(400, "$field cannot be empty"); return; }
            $updates[] = "$field = '$val'";
        }
    }

    $nullable_fields = ['shipping_marks', 'remarks'];
    foreach ($nullable_fields as $field) {
        if (isset($input[$field])) {
            $val = trim(mysqli_real_escape_string($conn, $input[$field]));
            $updates[] = $val === '' ? "$field = NULL" : "$field = '$val'";
        }
    }

    $date_fields = ['po_date', 'etd_date', 'eta_date'];
    foreach ($date_fields as $field) {
        if (isset($input[$field])) {
            $val = mysqli_real_escape_string($conn, $input[$field]);
            $updates[] = "$field = '$val'";
        }
    }

    if (isset($input['shipment_method'])) {
        $shipment_method = strtoupper(trim($input['shipment_method']));
        if (!in_array($shipment_method, PURCHASE_ORDER_SHIPMENT_METHODS, true)) {
            jsonResponse(400, 'shipment_method must be one of ' . implode(', ', PURCHASE_ORDER_SHIPMENT_METHODS));
            return;
        }
        $updates[] = "shipment_method = '$shipment_method'";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_order SET " . implode(', ', $updates) . " WHERE id = '$purchase_order_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'purchase_order', $purchase_order_id, 'updated', $username);
        jsonResponse(200, 'Purchase order updated successfully');
    } else {
        jsonResponse(500, 'Failed to update purchase order', ['error' => mysqli_error($conn)]);
    }
}

function deletePurchaseOrder($conn, $purchase_order_id, $username, $company_id) {
    $purchase_order_id = mysqli_real_escape_string($conn, $purchase_order_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_order WHERE id = '$purchase_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase order not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_order SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$purchase_order_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'purchase_order', $purchase_order_id, 'deleted', $username);
        jsonResponse(200, 'Purchase order deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete purchase order', ['error' => mysqli_error($conn)]);
    }
}

function approvePurchaseOrder($conn, $purchase_order_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT po_display_number, created_by FROM " . APP_SCHEMA . ".purchase_order WHERE id = '$purchase_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase order not found');
        return;
    }
    $purchase_order = mysqli_fetch_assoc($check);

    $status_id = getPurchaseStatusIdByName($conn, 'Approved');
    if (!$status_id) {
        jsonResponse(500, 'Purchase status "Approved" is not configured');
        return;
    }

    $now   = date('Y-m-d H:i:s');
    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_order
            SET status_id = '$status_id', approved_by = '$username', approved_at = '$now', updated_by = '$username', updated_at = '$now'
            WHERE id = '$purchase_order_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'purchase_order', $purchase_order_id, 'approved', $username, $notes);
        invalidateApprovalTokens($conn, 'purchase_order', $purchase_order_id);

        $approver_name = resolveDisplayName($conn, $username);
        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_approved',
            'source_module'      => 'purchase_order',
            'source_document_id' => $purchase_order_id,
            'title'              => 'Purchase Order Disetujui',
            'body'               => "Dokumen Purchase Order *{$purchase_order['po_display_number']}* telah *disetujui* oleh $approver_name.",
            'created_by'         => $username,
            'recipients'         => [$purchase_order['created_by']],
        ]);

        jsonResponse(200, 'Purchase order approved successfully');
    } else {
        jsonResponse(500, 'Failed to approve purchase order', ['error' => mysqli_error($conn)]);
    }
}

function rejectPurchaseOrder($conn, $purchase_order_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT po_display_number, created_by FROM " . APP_SCHEMA . ".purchase_order WHERE id = '$purchase_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase order not found');
        return;
    }
    $purchase_order = mysqli_fetch_assoc($check);

    $status_id = getPurchaseStatusIdByName($conn, 'Rejected');
    if (!$status_id) {
        jsonResponse(500, 'Purchase status "Rejected" is not configured');
        return;
    }

    $now   = date('Y-m-d H:i:s');
    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_order
            SET status_id = '$status_id', updated_by = '$username', updated_at = '$now'
            WHERE id = '$purchase_order_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'purchase_order', $purchase_order_id, 'rejected', $username, $notes);
        invalidateApprovalTokens($conn, 'purchase_order', $purchase_order_id);

        $rejector_name = resolveDisplayName($conn, $username);
        $reason_text   = $notes ? " Alasan: $notes." : '';
        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_rejected',
            'source_module'      => 'purchase_order',
            'source_document_id' => $purchase_order_id,
            'title'              => 'Purchase Order Ditolak',
            'body'               => "Dokumen Purchase Order *{$purchase_order['po_display_number']}* *ditolak* oleh $rejector_name.$reason_text",
            'created_by'         => $username,
            'recipients'         => [$purchase_order['created_by']],
        ]);

        jsonResponse(200, 'Purchase order rejected successfully');
    } else {
        jsonResponse(500, 'Failed to reject purchase order', ['error' => mysqli_error($conn)]);
    }
}

function revisePurchaseOrder($conn, $purchase_order_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT ps.status_name FROM " . APP_SCHEMA . ".purchase_order po
            LEFT JOIN " . APP_SCHEMA . ".purchase_status ps ON ps.id = po.status_id
            WHERE po.id = '$purchase_order_id' AND po.company_id = '$company_id' AND po.deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase order not found');
        return;
    }

    $purchase_order = mysqli_fetch_assoc($check);
    if ($purchase_order['status_name'] !== 'Rejected') {
        jsonResponse(400, 'Only rejected purchase orders can be revised');
        return;
    }

    $status_id = getPurchaseStatusIdByName($conn, 'Draft');
    if (!$status_id) {
        jsonResponse(500, 'Default purchase status "Draft" is not configured');
        return;
    }

    $now   = date('Y-m-d H:i:s');
    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_order
            SET status_id = '$status_id', updated_by = '$username', updated_at = '$now'
            WHERE id = '$purchase_order_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'purchase_order', $purchase_order_id, 'revised', $username, $notes);
        jsonResponse(200, 'Purchase order revised successfully');
    } else {
        jsonResponse(500, 'Failed to revise purchase order', ['error' => mysqli_error($conn)]);
    }
}

function exportPurchaseOrder($conn, $purchase_order_id, $company_id) {
    $purchase_order_id = mysqli_real_escape_string($conn, $purchase_order_id);

    $from   = APP_SCHEMA . ".purchase_order po
            LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = po.supplier_id
            LEFT JOIN " . APP_SCHEMA . ".payment_term pt ON pt.id = po.term_id
            LEFT JOIN " . APP_SCHEMA . ".payment_method pm ON pm.id = po.payment_method_id
            LEFT JOIN " . APP_SCHEMA . ".origin o ON o.id = po.origin_id
            LEFT JOIN " . APP_SCHEMA . ".purchase_type pty ON pty.id = po.type_id
            LEFT JOIN " . APP_SCHEMA . ".ppn_type ppn ON ppn.id = po.ppn_type_id";
    $result = mysqli_query($conn, "SELECT po.*, s.supplier_name, s.supplier_address, s.supplier_pic_name,
            pt.term_name, pm.method_name, o.origin_name, pty.type_name, ppn.ppn_percentage
            FROM $from WHERE po.id = '$purchase_order_id' AND po.company_id = '$company_id' AND po.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Purchase order not found');
        return;
    }
    $purchase_order = mysqli_fetch_assoc($result);

    $items_result = mysqli_query($conn, "SELECT product_name, quantity, packaging_size, unit_price
            FROM " . APP_SCHEMA . ".purchase_order_item
            WHERE purchase_order_id = '$purchase_order_id' AND deleted_at IS NULL ORDER BY created_at ASC LIMIT 5");
    $items = $items_result ? mysqli_fetch_all($items_result, MYSQLI_ASSOC) : [];

    $type_name = strtolower($purchase_order['type_name'] ?? '');
    if (str_contains($type_name, 'import')) {
        exportPurchaseOrderImport($purchase_order, $items);
    } elseif (str_contains($type_name, 'local')) {
        exportPurchaseOrderLocal($purchase_order, $items);
    } else {
        jsonResponse(400, 'Export template is not configured for purchase type "' . ($purchase_order['type_name'] ?? '-') . '"');
    }
}

function fillPurchaseOrderItemPlaceholders(TemplateProcessor $template, array $items): float {
    $grand_total = 0;

    for ($i = 0; $i < 5; $i++) {
        $item       = $items[$i] ?? null;
        $item_total = $item ? (float)$item['quantity'] * (float)$item['unit_price'] : 0;
        $grand_total += $item_total;

        $n = $i + 1;
        $template->setValue("no$n", $item ? (string)$n : '');
        $template->setValue("productname$n", $item['product_name'] ?? '');
        $template->setValue("quantity$n", $item ? number_format((float)$item['quantity'], 0) : '');
        $template->setValue("packing$n", $item ? (string)$item['packaging_size'] : '');
        $template->setValue("unitprice$n", $item ? number_format((float)$item['unit_price'], 2) : '');
        $template->setValue("total$n", $item ? number_format($item_total, 2) : '');
    }

    return $grand_total;
}

function exportPurchaseOrderImport($purchase_order, $items) {
    $template = new TemplateProcessor(__DIR__ . '/templates/template.docx');
    $template->setMacroChars('{', '}');

    $template->setValue('customername', $purchase_order['supplier_name'] ?? '');
    $template->setValue('customeraddress', $purchase_order['supplier_address'] ?? '');
    $template->setValue('picname', $purchase_order['supplier_pic_name'] ?? '');
    $template->setValue('ponumber', $purchase_order['po_display_number'] ?? '');
    $template->setValue('podate', formatIndonesianDate($purchase_order['po_date'] ?? null));
    $template->setValue('term', $purchase_order['term_name'] ?? '-');
    $template->setValue('origin', $purchase_order['origin_name'] ?? '-');
    $template->setValue('shipment', $purchase_order['shipment_method'] ?? '-');
    $template->setValue('payment', $purchase_order['method_name'] ?? '-');
    $template->setValue('shippingremarks', $purchase_order['shipping_marks'] ?? '-');
    $template->setValue('remarks', $purchase_order['remarks'] ?? '-');
    $template->setValue('documents', 'Documents');

    $grand_total = fillPurchaseOrderItemPlaceholders($template, $items);
    $template->setValue('grandtotal', number_format($grand_total, 2));

    streamTemplateDocx($template, 'POImport-' . sanitizeFilename($purchase_order['po_display_number']) . '.docx');
}

function exportPurchaseOrderLocal($purchase_order, $items) {
    $template = new TemplateProcessor(__DIR__ . '/templates/local_template.docx');
    $template->setMacroChars('{', '}');

    $template->setValue('suppliername', $purchase_order['supplier_name'] ?? '');
    $template->setValue('supplieraddress', $purchase_order['supplier_address'] ?? '');
    $template->setValue('ponumber', $purchase_order['po_display_number'] ?? '');
    $template->setValue('podate', formatIndonesianDate($purchase_order['po_date'] ?? null));
    $template->setValue('picname', $purchase_order['supplier_pic_name'] ?? '');
    $template->setValue('payment', $purchase_order['method_name'] ?? '-');
    $template->setValue('delivery', formatIndonesianDate($purchase_order['eta_date'] ?? $purchase_order['po_date'] ?? null));

    $grand_total = fillPurchaseOrderItemPlaceholders($template, $items);

    $ppn_percentage = (float)($purchase_order['ppn_percentage'] ?? 0);
    $tax            = $ppn_percentage != 0 ? $grand_total * ($ppn_percentage / 100) : 0;

    $template->setValue('vat', number_format($tax, 2));
    $template->setValue('total', number_format($grand_total + $tax, 2));

    streamTemplateDocx($template, 'POLocal-' . sanitizeFilename($purchase_order['po_display_number']) . '.docx');
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

$purchase_order_id = !empty($action) ? $action : null;
$sub_action         = $parts[4] ?? '';

try {
    $conn = getConn();

    if ($purchase_order_id === 'generate-number' && $sub_action === '') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        generatePurchaseOrderNumber($conn, $company_id, $_GET);

    } elseif ($purchase_order_id === 'settings' && $sub_action === 'shipping-marks-default') {
        switch ($method) {
            case 'GET':
                getShippingMarksDefault($conn, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateShippingMarksDefault($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }

    } elseif ($purchase_order_id && $sub_action === 'items') {
        require __DIR__ . '/items.php';

    } elseif ($purchase_order_id && $sub_action === 'export') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        exportPurchaseOrder($conn, $purchase_order_id, $company_id);

    } elseif ($purchase_order_id && $sub_action !== '') {
        $input = in_array($method, ['POST', 'PUT', 'PATCH'])
            ? (json_decode(file_get_contents('php://input'), true) ?? [])
            : [];

        switch ($sub_action) {
            case 'approve':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                approvePurchaseOrder($conn, $purchase_order_id, $input, $username, $company_id);
                break;
            case 'reject':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                rejectPurchaseOrder($conn, $purchase_order_id, $input, $username, $company_id);
                break;
            case 'revise':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                revisePurchaseOrder($conn, $purchase_order_id, $input, $username, $company_id);
                break;
            default:
                jsonResponse(404, 'Route not found');
        }

    } elseif ($purchase_order_id) {
        switch ($method) {
            case 'GET':
                getDetailPurchaseOrder($conn, $purchase_order_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updatePurchaseOrder($conn, $purchase_order_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deletePurchaseOrder($conn, $purchase_order_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }

    } else {
        switch ($method) {
            case 'GET':
                getAllPurchaseOrders($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createPurchaseOrder($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
