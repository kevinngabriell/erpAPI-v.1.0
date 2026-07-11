<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllSuppliers($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "s.company_id = '$company_id' AND s.deleted_at IS NULL";
    if ($search) {
        $where .= " AND (s.supplier_name LIKE '%$search%' OR s.supplier_pic_name LIKE '%$search%')";
    }
    if (isset($params['supplier_origin_id']) && trim($params['supplier_origin_id']) !== '') {
        $supplier_origin_id = mysqli_real_escape_string($conn, $params['supplier_origin_id']);
        $where .= " AND s.supplier_origin_id = '$supplier_origin_id'";
    }

    $from = APP_SCHEMA . ".supplier s
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = s.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = s.updated_by";

    $result       = mysqli_query($conn, "SELECT s.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY s.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".supplier s WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Suppliers found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No suppliers found');
    }
}

function createSupplier($conn, $input, $username, $company_id) {
    $required = ['supplier_name', 'supplier_origin_id'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $supplier_name      = trim(mysqli_real_escape_string($conn, $input['supplier_name']));
    $supplier_origin_id = mysqli_real_escape_string($conn, $input['supplier_origin_id']);

    $origin_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".origin WHERE id = '$supplier_origin_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($origin_check) === 0) {
        jsonResponse(404, 'Origin not found');
        return;
    }

    $supplier_currency_id_sql = 'NULL';
    if (isset($input['supplier_currency_id']) && trim($input['supplier_currency_id']) !== '') {
        $supplier_currency_id = mysqli_real_escape_string($conn, $input['supplier_currency_id']);
        $currency_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".currency WHERE id = '$supplier_currency_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($currency_check) === 0) {
            jsonResponse(404, 'Currency not found');
            return;
        }
        $supplier_currency_id_sql = "'$supplier_currency_id'";
    }

    $supplier_term_id_sql = 'NULL';
    if (isset($input['supplier_term_id']) && trim($input['supplier_term_id']) !== '') {
        $supplier_term_id = mysqli_real_escape_string($conn, $input['supplier_term_id']);
        $term_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".payment_term WHERE id = '$supplier_term_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($term_check) === 0) {
            jsonResponse(404, 'Payment term not found');
            return;
        }
        $supplier_term_id_sql = "'$supplier_term_id'";
    }

    $supplier_address_sql = isset($input['supplier_address']) && trim($input['supplier_address']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['supplier_address'])) . "'"
        : 'NULL';
    $supplier_phone_sql = isset($input['supplier_phone']) && trim($input['supplier_phone']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['supplier_phone'])) . "'"
        : 'NULL';
    $supplier_pic_name_sql = isset($input['supplier_pic_name']) && trim($input['supplier_pic_name']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['supplier_pic_name'])) . "'"
        : 'NULL';
    $supplier_pic_contact_sql = isset($input['supplier_pic_contact']) && trim($input['supplier_pic_contact']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['supplier_pic_contact'])) . "'"
        : 'NULL';
    $supplier_bank_information_sql = isset($input['supplier_bank_information']) && trim($input['supplier_bank_information']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['supplier_bank_information'])) . "'"
        : 'NULL';
    $npwp_sql = isset($input['npwp']) && trim($input['npwp']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['npwp'])) . "'"
        : 'NULL';
    $tax_invoice_name_sql = isset($input['tax_invoice_name']) && trim($input['tax_invoice_name']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['tax_invoice_name'])) . "'"
        : 'NULL';
    $tax_invoice_address_sql = isset($input['tax_invoice_address']) && trim($input['tax_invoice_address']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['tax_invoice_address'])) . "'"
        : 'NULL';

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".supplier WHERE company_id = '$company_id' AND supplier_name = '$supplier_name' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Supplier already exists');
        return;
    }

    $supplier_id = generateUUID();
    $now         = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".supplier
            (id, company_id, supplier_name, supplier_origin_id, supplier_address, supplier_phone, supplier_pic_name, supplier_pic_contact, supplier_currency_id, supplier_term_id, supplier_bank_information, npwp, tax_invoice_name, tax_invoice_address, created_by, created_at)
            VALUES ('$supplier_id', '$company_id', '$supplier_name', '$supplier_origin_id', $supplier_address_sql, $supplier_phone_sql, $supplier_pic_name_sql, $supplier_pic_contact_sql, $supplier_currency_id_sql, $supplier_term_id_sql, $supplier_bank_information_sql, $npwp_sql, $tax_invoice_name_sql, $tax_invoice_address_sql, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Supplier created successfully', ['supplier_id' => $supplier_id]);
    } else {
        jsonResponse(500, 'Failed to create supplier', ['error' => mysqli_error($conn)]);
    }
}

function getDetailSupplier($conn, $supplier_id, $company_id) {
    $supplier_id = mysqli_real_escape_string($conn, $supplier_id);

    $from   = APP_SCHEMA . ".supplier s
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = s.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = s.updated_by";
    $result = mysqli_query($conn, "SELECT s.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE s.id = '$supplier_id' AND s.company_id = '$company_id' AND s.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Supplier not found');
        return;
    }

    jsonResponse(200, 'Supplier found', mysqli_fetch_assoc($result));
}

function updateSupplier($conn, $supplier_id, $input, $username, $company_id) {
    $supplier_id = mysqli_real_escape_string($conn, $supplier_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".supplier WHERE id = '$supplier_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Supplier not found');
        return;
    }

    $updates = [];

    if (isset($input['supplier_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['supplier_name']));
        if ($val === '') { jsonResponse(400, 'supplier_name cannot be empty'); return; }
        $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".supplier WHERE company_id = '$company_id' AND supplier_name = '$val' AND id != '$supplier_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($dup) > 0) {
            jsonResponse(409, 'Supplier already exists');
            return;
        }
        $updates[] = "supplier_name = '$val'";
    }

    if (isset($input['supplier_origin_id'])) {
        $val = mysqli_real_escape_string($conn, $input['supplier_origin_id']);
        if (trim($val) === '') { jsonResponse(400, 'supplier_origin_id cannot be empty'); return; }
        $origin_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".origin WHERE id = '$val' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($origin_check) === 0) {
            jsonResponse(404, 'Origin not found');
            return;
        }
        $updates[] = "supplier_origin_id = '$val'";
    }

    if (array_key_exists('supplier_address', $input)) {
        $val = isset($input['supplier_address']) && trim($input['supplier_address']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['supplier_address'])) . "'"
            : 'NULL';
        $updates[] = "supplier_address = $val";
    }

    if (array_key_exists('supplier_phone', $input)) {
        $val = isset($input['supplier_phone']) && trim($input['supplier_phone']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['supplier_phone'])) . "'"
            : 'NULL';
        $updates[] = "supplier_phone = $val";
    }

    if (array_key_exists('supplier_pic_name', $input)) {
        $val = isset($input['supplier_pic_name']) && trim($input['supplier_pic_name']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['supplier_pic_name'])) . "'"
            : 'NULL';
        $updates[] = "supplier_pic_name = $val";
    }

    if (array_key_exists('supplier_pic_contact', $input)) {
        $val = isset($input['supplier_pic_contact']) && trim($input['supplier_pic_contact']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['supplier_pic_contact'])) . "'"
            : 'NULL';
        $updates[] = "supplier_pic_contact = $val";
    }

    if (array_key_exists('supplier_currency_id', $input)) {
        if ($input['supplier_currency_id'] === null || trim((string)$input['supplier_currency_id']) === '') {
            $updates[] = "supplier_currency_id = NULL";
        } else {
            $val = mysqli_real_escape_string($conn, $input['supplier_currency_id']);
            $currency_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".currency WHERE id = '$val' AND deleted_at IS NULL LIMIT 1");
            if (mysqli_num_rows($currency_check) === 0) {
                jsonResponse(404, 'Currency not found');
                return;
            }
            $updates[] = "supplier_currency_id = '$val'";
        }
    }

    if (array_key_exists('supplier_term_id', $input)) {
        if ($input['supplier_term_id'] === null || trim((string)$input['supplier_term_id']) === '') {
            $updates[] = "supplier_term_id = NULL";
        } else {
            $val = mysqli_real_escape_string($conn, $input['supplier_term_id']);
            $term_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".payment_term WHERE id = '$val' AND deleted_at IS NULL LIMIT 1");
            if (mysqli_num_rows($term_check) === 0) {
                jsonResponse(404, 'Payment term not found');
                return;
            }
            $updates[] = "supplier_term_id = '$val'";
        }
    }

    if (array_key_exists('supplier_bank_information', $input)) {
        $val = isset($input['supplier_bank_information']) && trim($input['supplier_bank_information']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['supplier_bank_information'])) . "'"
            : 'NULL';
        $updates[] = "supplier_bank_information = $val";
    }

    if (array_key_exists('npwp', $input)) {
        $val = isset($input['npwp']) && trim($input['npwp']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['npwp'])) . "'"
            : 'NULL';
        $updates[] = "npwp = $val";
    }

    if (array_key_exists('tax_invoice_name', $input)) {
        $val = isset($input['tax_invoice_name']) && trim($input['tax_invoice_name']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['tax_invoice_name'])) . "'"
            : 'NULL';
        $updates[] = "tax_invoice_name = $val";
    }

    if (array_key_exists('tax_invoice_address', $input)) {
        $val = isset($input['tax_invoice_address']) && trim($input['tax_invoice_address']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['tax_invoice_address'])) . "'"
            : 'NULL';
        $updates[] = "tax_invoice_address = $val";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".supplier SET " . implode(', ', $updates) . " WHERE id = '$supplier_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Supplier updated successfully');
    } else {
        jsonResponse(500, 'Failed to update supplier', ['error' => mysqli_error($conn)]);
    }
}

function deleteSupplier($conn, $supplier_id, $username, $company_id) {
    $supplier_id = mysqli_real_escape_string($conn, $supplier_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".supplier WHERE id = '$supplier_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Supplier not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".supplier SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$supplier_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Supplier deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete supplier', ['error' => mysqli_error($conn)]);
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

$supplier_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($supplier_id) {
        switch ($method) {
            case 'GET':
                getDetailSupplier($conn, $supplier_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateSupplier($conn, $supplier_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteSupplier($conn, $supplier_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllSuppliers($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createSupplier($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
