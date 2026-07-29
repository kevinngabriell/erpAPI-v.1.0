<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/audit_log.php';
require_once __DIR__ . '/../../helpers/excel_export.php';
require_once __DIR__ . '/../../helpers/notification.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

function getCompanyCode($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT company_code FROM " . CORE_SCHEMA . ".app_company WHERE company_id = '$company_id' LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? strtoupper($row['company_code']) : null;
}

function getSalesStatusIdByName($conn, $status_name) {
    $status_name = mysqli_real_escape_string($conn, $status_name);
    $result = mysqli_query($conn, "SELECT id FROM " . APP_SCHEMA . ".sales_status WHERE status_name = '$status_name' AND deleted_at IS NULL LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? $row['id'] : null;
}

function generateSalesSppbNumber($conn, $company_id) {
    $company_code = getCompanyCode($conn, $company_id);
    if (!$company_code) {
        jsonResponse(404, 'Company not found');
        return;
    }

    $roman_months = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
    $roman_month  = $roman_months[(int)date('n') - 1];
    $year         = date('Y');

    $pattern      = mysqli_real_escape_string($conn, "%/$company_code-SPPB/$roman_month/$year");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".sales_sppb WHERE company_id = '$company_id' AND sppb_display_number LIKE '$pattern'");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    $sequence            = str_pad((string)($total + 1), 3, '0', STR_PAD_LEFT);
    $sppb_display_number = "$sequence/$company_code-SPPB/$roman_month/$year";

    jsonResponse(200, 'Sales SPPB number generated successfully', ['sppb_display_number' => $sppb_display_number]);
}

function getAllSalesSppbs($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "ssp.company_id = '$company_id' AND ssp.deleted_at IS NULL";
    if ($search) {
        $where .= " AND ssp.sppb_display_number LIKE '%$search%'";
    }
    if (isset($params['status_id']) && trim($params['status_id']) !== '') {
        $status_id = mysqli_real_escape_string($conn, $params['status_id']);
        $where .= " AND ssp.status_id = '$status_id'";
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
            LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = ssp.status_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = ssp.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = ssp.updated_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = ssp.approved_by";

    $result       = mysqli_query($conn, "SELECT ssp.*, c.customer_name, so.so_display_number, ss.status_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by
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

    $status_id = getSalesStatusIdByName($conn, 'Draft');
    if (!$status_id) {
        jsonResponse(500, 'Default sales status "Draft" is not configured');
        return;
    }

    $sales_sppb_id = generateUUID();
    $now           = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO " . APP_SCHEMA . ".sales_sppb
                (id, company_id, sppb_display_number, sales_order_id, sppb_date, customer_id, status_id, created_by, created_at)
                VALUES
                ('$sales_sppb_id', '$company_id', '$sppb_display_number', '$sales_order_id', '$sppb_date', '$customer_id', '$status_id', '$username', '$now')";

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
            LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = ssp.status_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = ssp.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = ssp.updated_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = ssp.approved_by";
    $result = mysqli_query($conn, "SELECT ssp.*, c.customer_name, so.so_display_number, ss.status_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by
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

    // items is optional: when present, it fully replaces the SPPB's existing items
    // (old rows soft-deleted, new rows inserted) — same shape/validation as create,
    // so a rejected SPPB can correct item-level fields (send_to_address, send_date,
    // quantity, product_name, uom_id, description) before resubmitting.
    $items_provided = array_key_exists('items', $input);
    if ($items_provided) {
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
            if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_sppb SET " . implode(', ', $header_updates) . " WHERE id = '$sales_sppb_id' AND company_id = '$company_id'")) {
                throw new Exception(mysqli_error($conn));
            }
        }

        if ($items_provided) {
            if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_sppb_item SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE sales_sppb_id = '$sales_sppb_id' AND deleted_at IS NULL")) {
                throw new Exception(mysqli_error($conn));
            }

            foreach ($input['items'] as $item) {
                $item_id          = generateUUID();
                $send_to_address  = mysqli_real_escape_string($conn, $item['send_to_address']);
                $send_date        = mysqli_real_escape_string($conn, $item['send_date']);
                $product_name     = mysqli_real_escape_string($conn, $item['product_name']);
                $quantity         = (float)$item['quantity'];
                $uom_id           = mysqli_real_escape_string($conn, $item['uom_id']);
                $description_sql  = isset($item['description']) && trim($item['description']) !== '' ? "'" . mysqli_real_escape_string($conn, $item['description']) . "'" : 'NULL';

                $item_sql = "INSERT INTO " . APP_SCHEMA . ".sales_sppb_item
                             (id, sales_sppb_id, send_to_address, send_date, product_name, quantity, uom_id, description, created_by, created_at)
                             VALUES ('$item_id', '$sales_sppb_id', '$send_to_address', '$send_date', '$product_name', $quantity, '$uom_id', $description_sql, '$username', '$now')";

                if (!mysqli_query($conn, $item_sql)) {
                    throw new Exception(mysqli_error($conn));
                }
            }
        }

        insertAuditLog($conn, $company_id, 'sales_sppb', $sales_sppb_id, 'updated', $username);

        $conn->commit();
        jsonResponse(200, 'Sales SPPB updated successfully');
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(500, 'Failed to update sales SPPB', ['error' => $e->getMessage()]);
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

function approveSalesSppb($conn, $sales_sppb_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT ssp.sppb_display_number, ssp.created_by, c.customer_name
            FROM " . APP_SCHEMA . ".sales_sppb ssp
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = ssp.customer_id
            WHERE ssp.id = '$sales_sppb_id' AND ssp.company_id = '$company_id' AND ssp.deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales SPPB not found');
        return;
    }
    $sales_sppb = mysqli_fetch_assoc($check);

    $status_id = getSalesStatusIdByName($conn, 'Approved');
    if (!$status_id) {
        jsonResponse(500, 'Sales status "Approved" is not configured');
        return;
    }

    $now   = date('Y-m-d H:i:s');
    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_sppb
            SET status_id = '$status_id', approved_by = '$username', approved_at = '$now', updated_by = '$username', updated_at = '$now'
            WHERE id = '$sales_sppb_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_sppb', $sales_sppb_id, 'approved', $username, $notes);

        $approver_name = resolveDisplayName($conn, $username);
        $detail_link   = rtrim(APPROVAL_BASE_URL, '/') . '/sales/sales-sppb/' . $sales_sppb_id;

        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_approved',
            'source_module'      => 'sales_sppb',
            'source_document_id' => $sales_sppb_id,
            'title'              => 'Sales SPPB Disetujui',
            'body'               => "Dokumen Sales SPPB *{$sales_sppb['sppb_display_number']}* telah *disetujui* oleh $approver_name pada " . formatIndonesianDate($now) . ', ' . date('H:i', strtotime($now)) . " WIB.\n\n" .
                                     "Customer: {$sales_sppb['customer_name']}\n\n" .
                                     "Lihat detail dokumen pada link berikut:\n$detail_link",
            'created_by'         => $username,
            'recipients'         => [$sales_sppb['created_by']],
        ]);

        jsonResponse(200, 'Sales SPPB approved successfully');
    } else {
        jsonResponse(500, 'Failed to approve sales SPPB', ['error' => mysqli_error($conn)]);
    }
}

function rejectSalesSppb($conn, $sales_sppb_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT sppb_display_number, created_by FROM " . APP_SCHEMA . ".sales_sppb WHERE id = '$sales_sppb_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales SPPB not found');
        return;
    }
    $sales_sppb = mysqli_fetch_assoc($check);

    $status_id = getSalesStatusIdByName($conn, 'Rejected');
    if (!$status_id) {
        jsonResponse(500, 'Sales status "Rejected" is not configured');
        return;
    }

    $now   = date('Y-m-d H:i:s');
    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_sppb
            SET status_id = '$status_id', updated_by = '$username', updated_at = '$now'
            WHERE id = '$sales_sppb_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_sppb', $sales_sppb_id, 'rejected', $username, $notes);

        $rejector_name = resolveDisplayName($conn, $username);
        $reason_text   = $notes ? " Alasan: $notes" : '';

        notify($conn, [
            'company_id'         => $company_id,
            'type'               => 'approval_rejected',
            'source_module'      => 'sales_sppb',
            'source_document_id' => $sales_sppb_id,
            'title'              => 'Sales SPPB Ditolak',
            'body'               => "Dokumen Sales SPPB *{$sales_sppb['sppb_display_number']}* *ditolak* oleh $rejector_name.$reason_text",
            'created_by'         => $username,
            'recipients'         => [$sales_sppb['created_by']],
        ]);

        jsonResponse(200, 'Sales SPPB rejected successfully');
    } else {
        jsonResponse(500, 'Failed to reject sales SPPB', ['error' => mysqli_error($conn)]);
    }
}

function reviseSalesSppb($conn, $sales_sppb_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT ss.status_name FROM " . APP_SCHEMA . ".sales_sppb ssp
            LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = ssp.status_id
            WHERE ssp.id = '$sales_sppb_id' AND ssp.company_id = '$company_id' AND ssp.deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Sales SPPB not found');
        return;
    }

    $sales_sppb = mysqli_fetch_assoc($check);
    if ($sales_sppb['status_name'] !== 'Rejected') {
        jsonResponse(400, 'Only rejected sales SPPBs can be revised');
        return;
    }

    $status_id = getSalesStatusIdByName($conn, 'Draft');
    if (!$status_id) {
        jsonResponse(500, 'Default sales status "Draft" is not configured');
        return;
    }

    $now   = date('Y-m-d H:i:s');
    $notes = isset($input['notes']) && trim($input['notes']) !== '' ? trim($input['notes']) : null;

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sales_sppb
            SET status_id = '$status_id', updated_by = '$username', updated_at = '$now'
            WHERE id = '$sales_sppb_id' AND company_id = '$company_id'")) {
        insertAuditLog($conn, $company_id, 'sales_sppb', $sales_sppb_id, 'revised', $username, $notes);
        jsonResponse(200, 'Sales SPPB revised successfully');
    } else {
        jsonResponse(500, 'Failed to revise sales SPPB', ['error' => mysqli_error($conn)]);
    }
}

function exportSalesSppb($conn, $sales_sppb_id, $company_id) {
    $sales_sppb_id = mysqli_real_escape_string($conn, $sales_sppb_id);

    $from   = APP_SCHEMA . ".sales_sppb ssp
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = ssp.customer_id
            LEFT JOIN " . APP_SCHEMA . ".sales_order so ON so.id = ssp.sales_order_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = ssp.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = ssp.approved_by";
    $result = mysqli_query($conn, "SELECT ssp.*, c.customer_name, so.so_display_number,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by_name,
            CONCAT(au.first_name, ' ', au.last_name) AS approved_by_name
            FROM $from WHERE ssp.id = '$sales_sppb_id' AND ssp.company_id = '$company_id' AND ssp.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Sales SPPB not found');
        return;
    }

    $sales_sppb = mysqli_fetch_assoc($result);

    $items_from   = APP_SCHEMA . ".sales_sppb_item sspi
            LEFT JOIN " . APP_SCHEMA . ".unit_of_measure uom ON uom.id = sspi.uom_id";
    $items_result = mysqli_query($conn, "SELECT sspi.*, uom.uom_name
            FROM $items_from WHERE sspi.sales_sppb_id = '$sales_sppb_id' AND sspi.deleted_at IS NULL ORDER BY sspi.created_at ASC");
    $items = $items_result ? mysqli_fetch_all($items_result, MYSQLI_ASSOC) : [];

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    $sheet->setCellValue('A1', 'SURAT PERMINTAAN PENGELUARAN BARANG (SPPB)');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

    $sheet->getColumnDimension('A')->setWidth(20);
    $sheet->getColumnDimension('B')->setWidth(25);
    $sheet->getColumnDimension('C')->setWidth(35);

    $sheet->mergeCells('A1:C1');
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A1')->getAlignment()->setWrapText(true);

    $sheet->mergeCells('A2:B2');
    $sheet->setCellValue('A2', 'No SPPB : ');
    $sheet->setCellValue('C2', $sales_sppb['sppb_display_number']);
    $sheet->mergeCells('A3:B3');
    $sheet->setCellValue('A3', 'Tanggal : ');
    $sheet->setCellValue('C3', formatIndonesianDate($sales_sppb['sppb_date']));
    $sheet->mergeCells('A4:B4');
    $sheet->setCellValue('A4', 'Customer : ');
    $sheet->setCellValue('C4', $sales_sppb['customer_name']);

    $table_headers = ['', 'NO SO', 'DIKIRIM KE', 'Tgl Kirim', 'Nama Barang', 'QTY', 'SAT', 'Keterangan'];
    $sheet->fromArray($table_headers, null, 'A5');
    $sheet->getStyle('A5:H5')->getFont()->setBold(true);
    $sheet->getStyle('A5:H5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A5:H5')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $row = 6;
    $no  = 1;

    foreach ($items as $item) {
        $sheet->setCellValue("A$row", $no);
        $sheet->setCellValue("B$row", $sales_sppb['so_display_number'] ?? '-');
        $sheet->setCellValue("C$row", $item['send_to_address']);
        $sheet->setCellValue("D$row", formatIndonesianDate($item['send_date']));
        $sheet->setCellValue("E$row", $item['product_name']);
        $sheet->setCellValue("F$row", number_format((float)$item['quantity'], 2));
        $sheet->setCellValue("G$row", $item['uom_name']);
        $sheet->setCellValue("H$row", $item['description']);

        $sheet->getStyle("A$row:H$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $row++;
        $no++;
    }

    $sheet->getStyle("A$row:H$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
    $sheet->getStyle("A$row:H$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;

    $sheet->setCellValue("A$row", 'DIBUAT OLEH,');
    $sheet->setCellValue("C$row", 'MENGETAHUI OLEH,');
    $sheet->setCellValue("E$row", 'DISETUJUI OLEH,');
    $row++;
    $sheet->setCellValue("A$row", ($sales_sppb['created_by_name'] ?? '-') . ' pada ' . $sales_sppb['created_at']);
    $sheet->setCellValue("C$row", ($sales_sppb['created_by_name'] ?? '-') . ' pada ' . $sales_sppb['created_at']);
    $sheet->setCellValue("E$row", ($sales_sppb['approved_by_name'] ?? '-') . ' pada ' . ($sales_sppb['approved_at'] ?? '-'));
    $row += 3;
    $sheet->setCellValue("A$row", '( ADMIN SALES )');
    $sheet->setCellValue("C$row", '( TUKAR FAKTUR )');
    $sheet->setCellValue("E$row", '( SULANTO )    ( IRENE )');

    foreach (range('A', 'J') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    streamXlsx($spreadsheet, 'sales_sppb_' . sanitizeFilename($sales_sppb['sppb_display_number']) . '.xlsx');
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
$sub_action    = $parts[4] ?? '';

try {
    $conn = getConn();

    if ($sales_sppb_id === 'generate-number' && $sub_action === '') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        generateSalesSppbNumber($conn, $company_id);

    } elseif ($sales_sppb_id && $sub_action === 'export') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        exportSalesSppb($conn, $sales_sppb_id, $company_id);

    } elseif ($sales_sppb_id && $sub_action !== '') {
        $input = in_array($method, ['POST', 'PUT', 'PATCH'])
            ? (json_decode(file_get_contents('php://input'), true) ?? [])
            : [];

        switch ($sub_action) {
            case 'approve':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                approveSalesSppb($conn, $sales_sppb_id, $input, $username, $company_id);
                break;
            case 'reject':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                rejectSalesSppb($conn, $sales_sppb_id, $input, $username, $company_id);
                break;
            case 'revise':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                reviseSalesSppb($conn, $sales_sppb_id, $input, $username, $company_id);
                break;
            default:
                jsonResponse(404, 'Route not found');
        }

    } elseif ($sales_sppb_id) {
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
