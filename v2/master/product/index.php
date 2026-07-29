<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllProducts($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "p.company_id = '$company_id' AND p.deleted_at IS NULL";
    if ($search) {
        $where .= " AND (p.product_name LIKE '%$search%' OR p.product_code LIKE '%$search%' OR p.hs_code LIKE '%$search%')";
    }

    $from = APP_SCHEMA . ".product p
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = p.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = p.updated_by";

    $result       = mysqli_query($conn, "SELECT p.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY p.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".product p WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Products found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No products found');
    }
}

function createProduct($conn, $input, $username, $company_id) {
    $required = ['product_name'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $product_name = trim(mysqli_real_escape_string($conn, $input['product_name']));

    $product_code_sql = isset($input['product_code']) && trim($input['product_code']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['product_code'])) . "'"
        : 'NULL';
    $product_desc_sql = isset($input['product_desc']) && trim($input['product_desc']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['product_desc'])) . "'"
        : 'NULL';
    $hs_code_sql = isset($input['hs_code']) && trim($input['hs_code']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['hs_code'])) . "'"
        : 'NULL';

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".product WHERE company_id = '$company_id' AND product_name = '$product_name' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Product already exists');
        return;
    }

    if ($product_code_sql !== 'NULL') {
        $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".product WHERE company_id = '$company_id' AND product_code = $product_code_sql AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($dup) > 0) {
            jsonResponse(409, 'Product code already exists');
            return;
        }
    }

    $product_id = generateUUID();
    $now        = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".product (id, company_id, product_name, product_code, product_desc, hs_code, created_by, created_at)
            VALUES ('$product_id', '$company_id', '$product_name', $product_code_sql, $product_desc_sql, $hs_code_sql, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Product created successfully', ['product_id' => $product_id]);
    } else {
        jsonResponse(500, 'Failed to create product', ['error' => mysqli_error($conn)]);
    }
}

function getDetailProduct($conn, $product_id, $company_id) {
    $product_id = mysqli_real_escape_string($conn, $product_id);

    $from   = APP_SCHEMA . ".product p
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = p.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = p.updated_by";
    $result = mysqli_query($conn, "SELECT p.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE p.id = '$product_id' AND p.company_id = '$company_id' AND p.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Product not found');
        return;
    }

    jsonResponse(200, 'Product found', mysqli_fetch_assoc($result));
}

function updateProduct($conn, $product_id, $input, $username, $company_id) {
    $product_id = mysqli_real_escape_string($conn, $product_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".product WHERE id = '$product_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Product not found');
        return;
    }

    $updates = [];

    if (isset($input['product_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['product_name']));
        if ($val === '') { jsonResponse(400, 'product_name cannot be empty'); return; }
        $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".product WHERE company_id = '$company_id' AND product_name = '$val' AND id != '$product_id' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($dup) > 0) {
            jsonResponse(409, 'Product already exists');
            return;
        }
        $updates[] = "product_name = '$val'";
    }

    if (array_key_exists('product_code', $input)) {
        $val = isset($input['product_code']) && trim($input['product_code']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['product_code'])) . "'"
            : 'NULL';
        if ($val !== 'NULL') {
            $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".product WHERE company_id = '$company_id' AND product_code = $val AND id != '$product_id' AND deleted_at IS NULL LIMIT 1");
            if (mysqli_num_rows($dup) > 0) {
                jsonResponse(409, 'Product code already exists');
                return;
            }
        }
        $updates[] = "product_code = $val";
    }

    if (array_key_exists('product_desc', $input)) {
        $val = isset($input['product_desc']) && trim($input['product_desc']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['product_desc'])) . "'"
            : 'NULL';
        $updates[] = "product_desc = $val";
    }

    if (array_key_exists('hs_code', $input)) {
        $val = isset($input['hs_code']) && trim($input['hs_code']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['hs_code'])) . "'"
            : 'NULL';
        $updates[] = "hs_code = $val";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".product SET " . implode(', ', $updates) . " WHERE id = '$product_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Product updated successfully');
    } else {
        jsonResponse(500, 'Failed to update product', ['error' => mysqli_error($conn)]);
    }
}

function deleteProduct($conn, $product_id, $username, $company_id) {
    $product_id = mysqli_real_escape_string($conn, $product_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".product WHERE id = '$product_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Product not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".product SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$product_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Product deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete product', ['error' => mysqli_error($conn)]);
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

$product_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($product_id) {
        switch ($method) {
            case 'GET':
                getDetailProduct($conn, $product_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateProduct($conn, $product_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteProduct($conn, $product_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllProducts($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createProduct($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
