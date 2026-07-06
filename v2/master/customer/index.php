<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllCustomers($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "company_id = '$company_id' AND deleted_at IS NULL";
    if ($search) {
        $where .= " AND (customer_name LIKE '%$search%' OR customer_pic_name LIKE '%$search%')";
    }

    $result       = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".customer WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".customer WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Customers found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No customers found');
    }
}

function createCustomer($conn, $input, $username, $company_id) {
    $required = ['customer_name'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $customer_name = trim(mysqli_real_escape_string($conn, $input['customer_name']));

    $customer_address_sql = isset($input['customer_address']) && trim($input['customer_address']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['customer_address'])) . "'"
        : 'NULL';
    $customer_phone_sql = isset($input['customer_phone']) && trim($input['customer_phone']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['customer_phone'])) . "'"
        : 'NULL';
    $customer_pic_name_sql = isset($input['customer_pic_name']) && trim($input['customer_pic_name']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['customer_pic_name'])) . "'"
        : 'NULL';
    $customer_pic_contact_sql = isset($input['customer_pic_contact']) && trim($input['customer_pic_contact']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['customer_pic_contact'])) . "'"
        : 'NULL';
    $customer_top_days_sql = isset($input['customer_top_days']) && $input['customer_top_days'] !== ''
        ? (int)$input['customer_top_days']
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
    $credit_limit_sql = isset($input['credit_limit']) && $input['credit_limit'] !== ''
        ? (float)$input['credit_limit']
        : 'NULL';

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".customer WHERE company_id = '$company_id' AND customer_name = '$customer_name' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Customer already exists');
        return;
    }

    $customer_id = generateUUID();
    $now         = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".customer
            (id, company_id, customer_name, customer_address, customer_phone, customer_pic_name, customer_pic_contact, customer_top_days, npwp, tax_invoice_name, tax_invoice_address, credit_limit, created_by, created_at)
            VALUES ('$customer_id', '$company_id', '$customer_name', $customer_address_sql, $customer_phone_sql, $customer_pic_name_sql, $customer_pic_contact_sql, $customer_top_days_sql, $npwp_sql, $tax_invoice_name_sql, $tax_invoice_address_sql, $credit_limit_sql, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Customer created successfully', ['customer_id' => $customer_id]);
    } else {
        jsonResponse(500, 'Failed to create customer', ['error' => mysqli_error($conn)]);
    }
}

function getDetailCustomer($conn, $customer_id, $company_id) {
    $customer_id = mysqli_real_escape_string($conn, $customer_id);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".customer WHERE id = '$customer_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Customer not found');
        return;
    }

    jsonResponse(200, 'Customer found', mysqli_fetch_assoc($result));
}

function updateCustomer($conn, $customer_id, $input, $username, $company_id) {
    $customer_id = mysqli_real_escape_string($conn, $customer_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".customer WHERE id = '$customer_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Customer not found');
        return;
    }

    $updates = [];

    if (isset($input['customer_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['customer_name']));
        if ($val === '') { jsonResponse(400, 'customer_name cannot be empty'); return; }
        $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".customer WHERE company_id = '$company_id' AND customer_name = '$val' AND id != '$customer_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($dup) > 0) {
            jsonResponse(409, 'Customer already exists');
            return;
        }
        $updates[] = "customer_name = '$val'";
    }

    if (array_key_exists('customer_address', $input)) {
        $val = isset($input['customer_address']) && trim($input['customer_address']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['customer_address'])) . "'"
            : 'NULL';
        $updates[] = "customer_address = $val";
    }

    if (array_key_exists('customer_phone', $input)) {
        $val = isset($input['customer_phone']) && trim($input['customer_phone']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['customer_phone'])) . "'"
            : 'NULL';
        $updates[] = "customer_phone = $val";
    }

    if (array_key_exists('customer_pic_name', $input)) {
        $val = isset($input['customer_pic_name']) && trim($input['customer_pic_name']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['customer_pic_name'])) . "'"
            : 'NULL';
        $updates[] = "customer_pic_name = $val";
    }

    if (array_key_exists('customer_pic_contact', $input)) {
        $val = isset($input['customer_pic_contact']) && trim($input['customer_pic_contact']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['customer_pic_contact'])) . "'"
            : 'NULL';
        $updates[] = "customer_pic_contact = $val";
    }

    if (array_key_exists('customer_top_days', $input)) {
        $val = $input['customer_top_days'] !== null && $input['customer_top_days'] !== ''
            ? (int)$input['customer_top_days']
            : 'NULL';
        $updates[] = "customer_top_days = $val";
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

    if (array_key_exists('credit_limit', $input)) {
        $val = $input['credit_limit'] !== null && $input['credit_limit'] !== ''
            ? (float)$input['credit_limit']
            : 'NULL';
        $updates[] = "credit_limit = $val";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".customer SET " . implode(', ', $updates) . " WHERE id = '$customer_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Customer updated successfully');
    } else {
        jsonResponse(500, 'Failed to update customer', ['error' => mysqli_error($conn)]);
    }
}

function deleteCustomer($conn, $customer_id, $username, $company_id) {
    $customer_id = mysqli_real_escape_string($conn, $customer_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".customer WHERE id = '$customer_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Customer not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".customer SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$customer_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Customer deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete customer', ['error' => mysqli_error($conn)]);
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

$customer_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($customer_id) {
        switch ($method) {
            case 'GET':
                getDetailCustomer($conn, $customer_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateCustomer($conn, $customer_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteCustomer($conn, $customer_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllCustomers($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createCustomer($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
