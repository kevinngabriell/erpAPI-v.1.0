<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/audit_log.php';
require_once __DIR__ . '/../../helpers/excel_export.php';
require_once __DIR__ . '/../../helpers/notification.php';
require_once __DIR__ . '/../../helpers/finance_payment.php';
require_once __DIR__ . '/../../helpers/general_journal.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

function getSalesStatusIdByName($conn, $status_name) {
    $status_name = mysqli_real_escape_string($conn, $status_name);
    $result = mysqli_query($conn, "SELECT id FROM " . APP_SCHEMA . ".sales_status WHERE status_name = '$status_name' AND deleted_at IS NULL LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? $row['id'] : null;
}

function getCompanyCode($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT company_code FROM " . CORE_SCHEMA . ".app_company WHERE company_id = '$company_id' LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? strtoupper($row['company_code']) : null;
}

function generateSalesInvoiceNumber($conn, $company_id) {
    $company_code = getCompanyCode($conn, $company_id);
    if (!$company_code) {
        jsonResponse(404, 'Company not found');
        return;
    }

    $roman_months = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
    $roman_month  = $roman_months[(int)date('n') - 1];
    $year         = date('Y');

    $pattern      = mysqli_real_escape_string($conn, "%/$company_code-INV/$roman_month/$year");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".sales_invoice WHERE company_id = '$company_id' AND invoice_display_number LIKE '$pattern'");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    $sequence               = str_pad((string)($total + 1), 3, '0', STR_PAD_LEFT);
    $invoice_display_number = "$sequence/$company_code-INV/$roman_month/$year";

    jsonResponse(200, 'Sales invoice number generated successfully', ['invoice_display_number' => $invoice_display_number]);
}

function calculateSalesInvoiceTotal($conn, $sales_invoice_id) {
    $sales_invoice_id = mysqli_real_escape_string($conn, $sales_invoice_id);
    $result = mysqli_query($conn, "SELECT SUM(quantity * unit_price * (1 + tax / 100)) AS total_invoice
            FROM " . APP_SCHEMA . ".sales_invoice_item WHERE sales_invoice_id = '$sales_invoice_id' AND deleted_at IS NULL");
    return $result ? (float)(mysqli_fetch_assoc($result)['total_invoice'] ?? 0) : 0;
}

// Recognizes Piutang Usaha + Revenue in the General Journal when a sales invoice is
// approved. Skips (never blocks the approval, never guesses an account) if the
// company hasn't configured its default receivable/sales-revenue accounts yet.
function postSalesInvoiceRecognition($conn, $company_id, $sales_invoice_id, $total_invoice, $transaction_date, $username) {
    if ($total_invoice <= 0) return;

    $receivable_account = getDefaultAccountCode($conn, $company_id, 'is_default_receivable');
    $revenue_account     = getDefaultAccountCode($conn, $company_id, 'is_default_sales_revenue');

    if (!$receivable_account || !$revenue_account) {
        $missing = !$receivable_account ? 'is_default_receivable' : 'is_default_sales_revenue';
        insertAuditLog($conn, $company_id, 'general_journal', $sales_invoice_id, 'gl_posting_skipped', $username, "missing default account: $missing");
        return;
    }

    postGeneralJournalEntry($conn, $company_id, substr($transaction_date, 0, 10), "Pengakuan piutang - sales_invoice $sales_invoice_id",
        'sales_invoice', $sales_invoice_id, [
            ['account_code_id' => $receivable_account['id'], 'amount' => $total_invoice],
            ['account_code_id' => $revenue_account['id'], 'amount' => $total_invoice],
        ], $username);
}

function getAllSalesInvoices($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "si.company_id = '$company_id' AND si.deleted_at IS NULL";
    if ($search) {
        $where .= " AND si.invoice_display_number LIKE '%$search%'";
    }
    if (isset($params['status_id']) && trim($params['status_id']) !== '') {
        $status_id = mysqli_real_escape_string($conn, $params['status_id']);
        $where .= " AND si.status_id = '$status_id'";
    }
    if (isset($params['customer_id']) && trim($params['customer_id']) !== '') {
        $customer_id = mysqli_real_escape_string($conn, $params['customer_id']);
        $where .= " AND si.customer_id = '$customer_id'";
    }
    if (isset($params['sales_order_id']) && trim($params['sales_order_id']) !== '') {
        $sales_order_id = mysqli_real_escape_string($conn, $params['sales_order_id']);
        $where .= " AND si.sales_order_id = '$sales_order_id'";
    }
    if (isset($params['date_from']) && trim($params['date_from']) !== '') {
        $date_from = mysqli_real_escape_string($conn, $params['date_from']);
        $where .= " AND si.invoice_date >= '$date_from'";
    }
    if (isset($params['date_to']) && trim($params['date_to']) !== '') {
        $date_to = mysqli_real_escape_string($conn, $params['date_to']);
        $where .= " AND si.invoice_date <= '$date_to'";
    }

    $from = APP_SCHEMA . ".sales_invoice si
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = si.customer_id
            LEFT JOIN " . APP_SCHEMA . ".sales_order so ON so.id = si.sales_order_id
            LEFT JOIN " . APP_SCHEMA . ".sales_delivery sdel ON sdel.id = si.sales_delivery_id
            LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = si.status_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = si.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = si.updated_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = si.approved_by";

    $result       = mysqli_query($conn, "SELECT si.*, c.customer_name, so.so_display_number, sdel.do_display_number, ss.status_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by
            FROM $from WHERE $where ORDER BY si.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM $from WHERE $where");
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

    $status_id = getSalesStatusIdByName($conn, 'Draft');
    if (!$status_id) {
        jsonResponse(500, 'Default sales status "Draft" is not configured');
        return;
    }

    $sales_invoice_id = generateUUID();
    $now              = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO " . APP_SCHEMA . ".sales_invoice
                (id, company_id, invoice_display_number, customer_id, sales_order_id, sales_delivery_id, invoice_date, tax_invoice_number,
                 ship_to_address, bill_to_address, status_id, created_by, created_at)
                VALUES
                ('$sales_invoice_id', '$company_id', '$invoice_display_number', '$customer_id', '$sales_order_id', $sales_delivery_id_sql, '$invoice_date', $tax_invoice_number_sql,
                 '$ship_to_address', '$bill_to_address', '$status_id', '$username', '$now')";

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

    $from   = APP_SCHEMA . ".sales_invoice si
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = si.customer_id
            LEFT JOIN " . APP_SCHEMA . ".sales_order so ON so.id = si.sales_order_id
            LEFT JOIN " . APP_SCHEMA . ".sales_delivery sdel ON sdel.id = si.sales_delivery_id
            LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = si.status_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = si.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = si.updated_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = si.approved_by";
    $result = mysqli_query($conn, "SELECT si.*, c.customer_name, so.so_display_number, sdel.do_display_number, ss.status_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by
            FROM $from WHERE si.id = '$sales_invoice_id' AND si.company_id = '$company_id' AND si.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Sales invoice not found');
        return;
    }

    $sales_invoice = mysqli_fetch_assoc($result);

    $items_from   = APP_SCHEMA . ".sales_invoice_item sii
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = sii.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = sii.updated_by";
    $items_result = mysqli_query($conn, "SELECT sii.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $items_from WHERE sii.sales_invoice_id = '$sales_invoice_id' AND sii.deleted_at IS NULL ORDER BY sii.created_at ASC");
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

function approveSalesInvoice($conn, $sales_invoice_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT si.created_by, si.invoice_display_number, si.customer_id, c.customer_name, ss.status_name
            FROM " . APP_SCHEMA . ".sales_invoice si
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = si.customer_id
            LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = si.status_id
            WHERE si.id = '$sales_invoice_id' AND si.company_id = '$company_id' AND si.deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales invoice not found');
        return;
    }
    $sales_invoice = mysqli_fetch_assoc($check);
    if ($sales_invoice['status_name'] !== 'Draft') {
        jsonResponse(400, 'Only draft sales invoices can be approved');
        return;
    }

    $status_id = getSalesStatusIdByName($conn, 'Approved');
    if (!$status_id) {
        jsonResponse(500, 'Sales status "Approved" is not configured');
        return;
    }

    $now   = date('Y-m-d H:i:s');
    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_invoice
            SET status_id = '$status_id', approved_by = '$username', approved_at = '$now', updated_by = '$username', updated_at = '$now'
            WHERE id = '$sales_invoice_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_invoice', $sales_invoice_id, 'approved', $username, $notes);

        $approver_name = resolveDisplayName($conn, $username);
        $total_invoice = calculateSalesInvoiceTotal($conn, $sales_invoice_id);
        seedFinancePaymentBaseline($conn, $company_id, $sales_invoice['invoice_display_number'], $total_invoice, $sales_invoice['customer_id'], null, $username);
        postSalesInvoiceRecognition($conn, $company_id, $sales_invoice_id, $total_invoice, $now, $username);
        $detail_link   = rtrim(APPROVAL_BASE_URL, '/') . '/sales/sales-invoice/' . $sales_invoice_id;

        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_approved',
            'source_module'      => 'sales_invoice',
            'source_document_id' => $sales_invoice_id,
            'title'              => 'Sales Invoice Disetujui',
            'body'               => "Dokumen Sales Invoice *{$sales_invoice['invoice_display_number']}* telah *disetujui* oleh $approver_name pada " . formatIndonesianDate($now) . ', ' . date('H:i', strtotime($now)) . " WIB.\n\n" .
                                     "Customer: {$sales_invoice['customer_name']}\n" .
                                     'Total: Rp ' . number_format($total_invoice, 0, ',', '.') . "\n\n" .
                                     "Lihat detail dokumen pada link berikut:\n$detail_link",
            'created_by'         => $username,
            'recipients'         => [$sales_invoice['created_by']],
        ]);

        jsonResponse(200, 'Sales invoice approved successfully');
    } else {
        jsonResponse(500, 'Failed to approve sales invoice', ['error' => mysqli_error($conn)]);
    }
}

function rejectSalesInvoice($conn, $sales_invoice_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT si.created_by, si.invoice_display_number, ss.status_name
            FROM " . APP_SCHEMA . ".sales_invoice si
            LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = si.status_id
            WHERE si.id = '$sales_invoice_id' AND si.company_id = '$company_id' AND si.deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales invoice not found');
        return;
    }
    $sales_invoice = mysqli_fetch_assoc($check);
    if ($sales_invoice['status_name'] !== 'Draft') {
        jsonResponse(400, 'Only draft sales invoices can be rejected');
        return;
    }

    $status_id = getSalesStatusIdByName($conn, 'Rejected');
    if (!$status_id) {
        jsonResponse(500, 'Sales status "Rejected" is not configured');
        return;
    }

    $now   = date('Y-m-d H:i:s');
    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_invoice
            SET status_id = '$status_id', updated_by = '$username', updated_at = '$now'
            WHERE id = '$sales_invoice_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_invoice', $sales_invoice_id, 'rejected', $username, $notes);

        $rejector_name = resolveDisplayName($conn, $username);
        $reason_text   = $notes ? " Alasan: $notes" : '';

        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_rejected',
            'source_module'      => 'sales_invoice',
            'source_document_id' => $sales_invoice_id,
            'title'              => 'Sales Invoice Ditolak',
            'body'               => "Dokumen Sales Invoice *{$sales_invoice['invoice_display_number']}* *ditolak* oleh $rejector_name.$reason_text",
            'created_by'         => $username,
            'recipients'         => [$sales_invoice['created_by']],
        ]);

        jsonResponse(200, 'Sales invoice rejected successfully');
    } else {
        jsonResponse(500, 'Failed to reject sales invoice', ['error' => mysqli_error($conn)]);
    }
}

function reviseSalesInvoice($conn, $sales_invoice_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT ss.status_name FROM " . APP_SCHEMA . ".sales_invoice si
            LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = si.status_id
            WHERE si.id = '$sales_invoice_id' AND si.company_id = '$company_id' AND si.deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales invoice not found');
        return;
    }

    $sales_invoice = mysqli_fetch_assoc($check);
    if ($sales_invoice['status_name'] !== 'Rejected') {
        jsonResponse(400, 'Only rejected sales invoices can be revised');
        return;
    }

    $status_id = getSalesStatusIdByName($conn, 'Draft');
    if (!$status_id) {
        jsonResponse(500, 'Default sales status "Draft" is not configured');
        return;
    }

    $now   = date('Y-m-d H:i:s');
    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_invoice
            SET status_id = '$status_id', updated_by = '$username', updated_at = '$now'
            WHERE id = '$sales_invoice_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_invoice', $sales_invoice_id, 'revised', $username, $notes);
        jsonResponse(200, 'Sales invoice revised successfully');
    } else {
        jsonResponse(500, 'Failed to revise sales invoice', ['error' => mysqli_error($conn)]);
    }
}

function exportSalesInvoice($conn, $sales_invoice_id, $company_id) {
    $sales_invoice_id = mysqli_real_escape_string($conn, $sales_invoice_id);

    $from   = APP_SCHEMA . ".sales_invoice si
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = si.customer_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = si.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = si.approved_by";
    $result = mysqli_query($conn, "SELECT si.*, c.customer_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by_name,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by_name
            FROM $from WHERE si.id = '$sales_invoice_id' AND si.company_id = '$company_id' AND si.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Sales invoice not found');
        return;
    }

    $sales_invoice = mysqli_fetch_assoc($result);

    $items_result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".sales_invoice_item
            WHERE sales_invoice_id = '$sales_invoice_id' AND deleted_at IS NULL ORDER BY created_at ASC");
    $items = $items_result ? mysqli_fetch_all($items_result, MYSQLI_ASSOC) : [];

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    $sheet->setCellValue('A1', 'SALES INVOICE');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->mergeCells('A1:B1');

    $sheet->setCellValue('A3', 'No. Invoice :');
    $sheet->setCellValue('B3', $sales_invoice['invoice_display_number']);
    $sheet->setCellValue('A4', 'Tanggal :');
    $sheet->setCellValue('B4', formatIndonesianDate($sales_invoice['invoice_date']));
    $sheet->setCellValue('A5', 'Customer :');
    $sheet->setCellValue('B5', $sales_invoice['customer_name'] ?? '-');
    $sheet->setCellValue('A6', 'Bill To :');
    $sheet->setCellValue('B6', $sales_invoice['bill_to_address'] ?? '-');
    $sheet->setCellValue('A7', 'Ship To :');
    $sheet->setCellValue('B7', $sales_invoice['ship_to_address'] ?? '-');

    $table_headers = ['No', 'Nama Barang', 'Qty', 'Harga Satuan', 'Pajak (%)', 'Subtotal'];
    $sheet->fromArray($table_headers, null, 'A9');
    $sheet->getStyle('A9:F9')->getFont()->setBold(true);
    $sheet->getStyle('A9:F9')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A9:F9')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $row          = 10;
    $no           = 1;
    $total_amount = 0;

    foreach ($items as $item) {
        $quantity   = (float)$item['quantity'];
        $unit_price = (float)$item['unit_price'];
        $tax        = (float)$item['tax'];
        $subtotal   = $quantity * $unit_price * (1 + $tax / 100);

        $sheet->setCellValue("A$row", $no);
        $sheet->setCellValue("B$row", $item['product_name']);
        $sheet->setCellValue("C$row", number_format($quantity, 0));
        $sheet->setCellValue("D$row", number_format($unit_price, 2));
        $sheet->setCellValue("E$row", $tax);
        $sheet->setCellValue("F$row", number_format($subtotal, 2));

        $sheet->getStyle("A$row:F$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $total_amount += $subtotal;
        $row++;
        $no++;
    }

    $sheet->setCellValue("E$row", 'TOTAL');
    $sheet->setCellValue("F$row", number_format($total_amount, 2));
    $sheet->getStyle("A$row:F$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row += 3;

    $sheet->setCellValue("A$row", 'DIBUAT OLEH,');
    $sheet->setCellValue("D$row", 'DISETUJUI OLEH,');
    $row++;
    $sheet->setCellValue("A$row", ($sales_invoice['created_by_name'] ?? '-') . ' pada ' . $sales_invoice['created_at']);
    $sheet->setCellValue("D$row", ($sales_invoice['approved_by_name'] ?? '-') . ' pada ' . ($sales_invoice['approved_at'] ?? '-'));

    foreach (range('A', 'F') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    streamXlsx($spreadsheet, 'sales_invoice_' . sanitizeFilename($sales_invoice['invoice_display_number']) . '.xlsx');
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
$sub_action        = $parts[4] ?? '';

try {
    $conn = getConn();

    if ($sales_invoice_id === 'generate-number' && $sub_action === '') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        generateSalesInvoiceNumber($conn, $company_id);

    } elseif ($sales_invoice_id && $sub_action === 'export') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        exportSalesInvoice($conn, $sales_invoice_id, $company_id);

    } elseif ($sales_invoice_id && $sub_action !== '') {
        $input = in_array($method, ['POST', 'PUT', 'PATCH'])
            ? (json_decode(file_get_contents('php://input'), true) ?? [])
            : [];

        switch ($sub_action) {
            case 'approve':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                approveSalesInvoice($conn, $sales_invoice_id, $input, $username, $company_id);
                break;
            case 'reject':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                rejectSalesInvoice($conn, $sales_invoice_id, $input, $username, $company_id);
                break;
            case 'revise':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                reviseSalesInvoice($conn, $sales_invoice_id, $input, $username, $company_id);
                break;
            default:
                jsonResponse(404, 'Route not found');
        }

    } elseif ($sales_invoice_id) {
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
