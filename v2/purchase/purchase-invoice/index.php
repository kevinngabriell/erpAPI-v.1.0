<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/audit_log.php';
require_once __DIR__ . '/../../helpers/notification.php';
require_once __DIR__ . '/../../helpers/excel_export.php';
require_once __DIR__ . '/../../helpers/word_export.php';
require_once __DIR__ . '/../../helpers/finance_payment.php';

use PhpOffice\PhpWord\PhpWord;

function getPurchaseStatusIdByName($conn, $status_name) {
    $status_name = mysqli_real_escape_string($conn, $status_name);
    $result = mysqli_query($conn, "SELECT id FROM " . APP_SCHEMA . ".purchase_status WHERE status_name = '$status_name' AND deleted_at IS NULL LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? $row['id'] : null;
}

function calculatePurchaseInvoiceTotal($conn, $purchase_invoice_id) {
    $purchase_invoice_id = mysqli_real_escape_string($conn, $purchase_invoice_id);
    $result = mysqli_query($conn, "SELECT SUM(total) AS total_invoice
            FROM " . APP_SCHEMA . ".purchase_invoice_item WHERE purchase_invoice_id = '$purchase_invoice_id' AND deleted_at IS NULL");
    return $result ? (float)(mysqli_fetch_assoc($result)['total_invoice'] ?? 0) : 0;
}

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
    if (isset($params['status_id']) && trim($params['status_id']) !== '') {
        $status_id = mysqli_real_escape_string($conn, $params['status_id']);
        $where .= " AND pi.status_id = '$status_id'";
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
            LEFT JOIN " . APP_SCHEMA . ".purchase_status ps ON ps.id = pi.status_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = pi.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = pi.updated_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = pi.approved_by";

    $result       = mysqli_query($conn, "SELECT pi.*, po.po_display_number, s.supplier_name, pt.term_name, ps.status_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by
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

    $po_check = mysqli_query($conn, "SELECT po_display_number FROM " . APP_SCHEMA . ".purchase_order WHERE id = '$purchase_order_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($po_check) === 0) {
        jsonResponse(404, 'Purchase order not found');
        return;
    }
    $po_display_number = mysqli_fetch_assoc($po_check)['po_display_number'];

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_invoice WHERE company_id = '$company_id' AND invoice_display_number = '$invoice_display_number' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Purchase invoice already exists');
        return;
    }

    $status_id = getPurchaseStatusIdByName($conn, 'Draft');
    if (!$status_id) {
        jsonResponse(500, 'Default purchase status "Draft" is not configured');
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
                 tax_invoice_number, kurs, term_id, status_id, created_by, created_at)
                VALUES ('$purchase_invoice_id', '$company_id', '$invoice_display_number', '$purchase_order_id', '$supplier_id', '$invoice_date', '$ship_date',
                        $tax_invoice_number_sql, $kurs_sql, $term_id_sql, '$status_id', '$username', '$now')";

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

        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_pending',
            'source_module'      => 'purchase_invoice',
            'source_document_id' => $purchase_invoice_id,
            'title'              => 'Purchase Invoice Menunggu Approval',
            'body'               => "Dokumen Purchase Invoice *$invoice_display_number* (PO *$po_display_number*) membutuhkan persetujuan Bapak/Ibu. Silakan klik link di bawah untuk meninjau dan menyetujui:",
            'created_by'         => $username,
            'recipients'         => resolveApprovalRecipients($conn, $company_id, 'purchase_invoice'),
        ]);

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
            LEFT JOIN " . APP_SCHEMA . ".purchase_status ps ON ps.id = pi.status_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = pi.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = pi.updated_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = pi.approved_by";
    $result = mysqli_query($conn, "SELECT pi.*, po.po_display_number, s.supplier_name, pt.term_name, ps.status_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by
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

function approvePurchaseInvoice($conn, $purchase_invoice_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT invoice_display_number, supplier_id, created_by FROM " . APP_SCHEMA . ".purchase_invoice WHERE id = '$purchase_invoice_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase invoice not found');
        return;
    }
    $purchase_invoice = mysqli_fetch_assoc($check);

    $status_id = getPurchaseStatusIdByName($conn, 'Approved');
    if (!$status_id) {
        jsonResponse(500, 'Purchase status "Approved" is not configured');
        return;
    }

    $now   = date('Y-m-d H:i:s');
    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_invoice
            SET status_id = '$status_id', approved_by = '$username', approved_at = '$now', updated_by = '$username', updated_at = '$now'
            WHERE id = '$purchase_invoice_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'purchase_invoice', $purchase_invoice_id, 'approved', $username, $notes);
        invalidateApprovalTokens($conn, 'purchase_invoice', $purchase_invoice_id);

        $total_invoice = calculatePurchaseInvoiceTotal($conn, $purchase_invoice_id);
        seedFinancePaymentBaseline($conn, $company_id, $purchase_invoice['invoice_display_number'], $total_invoice, null, $purchase_invoice['supplier_id'], $username);

        $approver_name = resolveDisplayName($conn, $username);
        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_approved',
            'source_module'      => 'purchase_invoice',
            'source_document_id' => $purchase_invoice_id,
            'title'              => 'Purchase Invoice Disetujui',
            'body'               => "Dokumen Purchase Invoice *{$purchase_invoice['invoice_display_number']}* telah *disetujui* oleh $approver_name.",
            'created_by'         => $username,
            'recipients'         => [$purchase_invoice['created_by']],
        ]);

        jsonResponse(200, 'Purchase invoice approved successfully');
    } else {
        jsonResponse(500, 'Failed to approve purchase invoice', ['error' => mysqli_error($conn)]);
    }
}

function rejectPurchaseInvoice($conn, $purchase_invoice_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT invoice_display_number, created_by FROM " . APP_SCHEMA . ".purchase_invoice WHERE id = '$purchase_invoice_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase invoice not found');
        return;
    }
    $purchase_invoice = mysqli_fetch_assoc($check);

    $status_id = getPurchaseStatusIdByName($conn, 'Rejected');
    if (!$status_id) {
        jsonResponse(500, 'Purchase status "Rejected" is not configured');
        return;
    }

    $now   = date('Y-m-d H:i:s');
    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_invoice
            SET status_id = '$status_id', updated_by = '$username', updated_at = '$now'
            WHERE id = '$purchase_invoice_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'purchase_invoice', $purchase_invoice_id, 'rejected', $username, $notes);
        invalidateApprovalTokens($conn, 'purchase_invoice', $purchase_invoice_id);

        $rejector_name = resolveDisplayName($conn, $username);
        $reason_text   = $notes ? " Alasan: $notes." : '';
        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_rejected',
            'source_module'      => 'purchase_invoice',
            'source_document_id' => $purchase_invoice_id,
            'title'              => 'Purchase Invoice Ditolak',
            'body'               => "Dokumen Purchase Invoice *{$purchase_invoice['invoice_display_number']}* *ditolak* oleh $rejector_name.$reason_text",
            'created_by'         => $username,
            'recipients'         => [$purchase_invoice['created_by']],
        ]);

        jsonResponse(200, 'Purchase invoice rejected successfully');
    } else {
        jsonResponse(500, 'Failed to reject purchase invoice', ['error' => mysqli_error($conn)]);
    }
}

function revisePurchaseInvoice($conn, $purchase_invoice_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT ps.status_name FROM " . APP_SCHEMA . ".purchase_invoice pi
            LEFT JOIN " . APP_SCHEMA . ".purchase_status ps ON ps.id = pi.status_id
            WHERE pi.id = '$purchase_invoice_id' AND pi.company_id = '$company_id' AND pi.deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase invoice not found');
        return;
    }

    $purchase_invoice = mysqli_fetch_assoc($check);
    if ($purchase_invoice['status_name'] !== 'Rejected') {
        jsonResponse(400, 'Only rejected purchase invoices can be revised');
        return;
    }

    $status_id = getPurchaseStatusIdByName($conn, 'Draft');
    if (!$status_id) {
        jsonResponse(500, 'Default purchase status "Draft" is not configured');
        return;
    }

    $now   = date('Y-m-d H:i:s');
    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_invoice
            SET status_id = '$status_id', updated_by = '$username', updated_at = '$now'
            WHERE id = '$purchase_invoice_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'purchase_invoice', $purchase_invoice_id, 'revised', $username, $notes);
        jsonResponse(200, 'Purchase invoice revised successfully');
    } else {
        jsonResponse(500, 'Failed to revise purchase invoice', ['error' => mysqli_error($conn)]);
    }
}

function exportPurchaseInvoice($conn, $purchase_invoice_id, $company_id) {
    $purchase_invoice_id = mysqli_real_escape_string($conn, $purchase_invoice_id);

    $from   = APP_SCHEMA . ".purchase_invoice pi
            LEFT JOIN " . APP_SCHEMA . ".purchase_order po ON po.id = pi.purchase_order_id
            LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = pi.supplier_id
            LEFT JOIN " . APP_SCHEMA . ".payment_term pt ON pt.id = pi.term_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = pi.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = pi.approved_by";
    $result = mysqli_query($conn, "SELECT pi.*, po.po_display_number, s.supplier_name, s.supplier_address, pt.term_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by_name,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by_name
            FROM $from WHERE pi.id = '$purchase_invoice_id' AND pi.company_id = '$company_id' AND pi.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Purchase invoice not found');
        return;
    }
    $purchase_invoice = mysqli_fetch_assoc($result);

    $items_result = mysqli_query($conn, "SELECT product_name, quantity, packaging_size, unit_price, vat, total
            FROM " . APP_SCHEMA . ".purchase_invoice_item
            WHERE purchase_invoice_id = '$purchase_invoice_id' AND deleted_at IS NULL ORDER BY created_at ASC");
    $items = $items_result ? mysqli_fetch_all($items_result, MYSQLI_ASSOC) : [];

    $document = new PhpWord();
    $section  = $document->addSection();

    $section->addText('PURCHASE INVOICE', ['bold' => true, 'size' => 14], ['alignment' => 'center']);
    $section->addTextBreak();

    $info_table = $section->addTable(['cellMargin' => 80]);
    $info_rows = [
        ['No Invoice :', $purchase_invoice['invoice_display_number'] ?? '-', 'PO No :', $purchase_invoice['po_display_number'] ?? '-'],
        ['Tanggal :', formatIndonesianDate($purchase_invoice['invoice_date'] ?? null), 'Supplier :', $purchase_invoice['supplier_name'] ?? '-'],
        ['Tgl Kirim :', formatIndonesianDate($purchase_invoice['ship_date'] ?? null), 'Alamat :', $purchase_invoice['supplier_address'] ?? '-'],
        ['No Faktur Pajak :', $purchase_invoice['tax_invoice_number'] ?? '-', 'Termin :', $purchase_invoice['term_name'] ?? '-'],
    ];
    foreach ($info_rows as $row) {
        $info_table->addRow();
        $info_table->addCell(2200)->addText($row[0]);
        $info_table->addCell(3300)->addText($row[1]);
        $info_table->addCell(1800)->addText($row[2]);
        $info_table->addCell(3300)->addText($row[3]);
    }

    $section->addTextBreak();

    $border_style = ['borderSize' => 6, 'borderColor' => '000000'];
    $item_table   = $section->addTable($border_style);

    $item_table->addRow();
    foreach (['NO', 'PRODUK', 'QTY', 'PACKING', 'HARGA @', 'VAT', 'TOTAL'] as $header) {
        $item_table->addCell(1300, $border_style)->addText($header, ['bold' => true], ['alignment' => 'center']);
    }

    $grand_total = 0;
    $no          = 1;
    foreach ($items as $item) {
        $item_table->addRow();
        $item_table->addCell(1300, $border_style)->addText((string)$no++, [], ['alignment' => 'center']);
        $item_table->addCell(1300, $border_style)->addText($item['product_name']);
        $item_table->addCell(1300, $border_style)->addText(number_format((float)$item['quantity'], 0), [], ['alignment' => 'center']);
        $item_table->addCell(1300, $border_style)->addText((string)$item['packaging_size'], [], ['alignment' => 'center']);
        $item_table->addCell(1300, $border_style)->addText(number_format((float)$item['unit_price'], 2), [], ['alignment' => 'right']);
        $item_table->addCell(1300, $border_style)->addText(number_format((float)$item['vat'], 2), [], ['alignment' => 'right']);
        $item_table->addCell(1300, $border_style)->addText(number_format((float)$item['total'], 2), [], ['alignment' => 'right']);
        $grand_total += (float)$item['total'];
    }

    $item_table->addRow();
    $item_table->addCell(6500, $border_style + ['gridSpan' => 6])->addText('TOTAL', ['bold' => true], ['alignment' => 'right']);
    $item_table->addCell(1300, $border_style)->addText(number_format($grand_total, 2), ['bold' => true], ['alignment' => 'right']);

    $section->addTextBreak(3);

    $footer_table = $section->addTable(['cellMargin' => 80]);
    $footer_table->addRow();
    $footer_table->addCell(4500)->addText('DIBUAT OLEH,');
    $footer_table->addCell(4500)->addText('DISETUJUI OLEH,');
    $footer_table->addRow();
    $footer_table->addCell(4500)->addTextBreak(2);
    $footer_table->addCell(4500)->addTextBreak(2);
    $footer_table->addRow();
    $footer_table->addCell(4500)->addText('(' . ($purchase_invoice['created_by_name'] ?? '-') . ')');
    $footer_table->addCell(4500)->addText('(' . ($purchase_invoice['approved_by_name'] ?? '-') . ')');

    streamDocx($document, 'PurchaseInvoice-' . sanitizeFilename($purchase_invoice['invoice_display_number']) . '.docx');
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
$sub_action           = $parts[4] ?? '';

try {
    $conn = getConn();

    if ($purchase_invoice_id && $sub_action === 'items') {
        require __DIR__ . '/items.php';

    } elseif ($purchase_invoice_id && $sub_action === 'export') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        exportPurchaseInvoice($conn, $purchase_invoice_id, $company_id);

    } elseif ($purchase_invoice_id && $sub_action !== '') {
        $input = in_array($method, ['POST', 'PUT', 'PATCH'])
            ? (json_decode(file_get_contents('php://input'), true) ?? [])
            : [];

        switch ($sub_action) {
            case 'approve':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                approvePurchaseInvoice($conn, $purchase_invoice_id, $input, $username, $company_id);
                break;
            case 'reject':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                rejectPurchaseInvoice($conn, $purchase_invoice_id, $input, $username, $company_id);
                break;
            case 'revise':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                revisePurchaseInvoice($conn, $purchase_invoice_id, $input, $username, $company_id);
                break;
            default:
                jsonResponse(404, 'Route not found');
        }

    } elseif ($purchase_invoice_id) {
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
