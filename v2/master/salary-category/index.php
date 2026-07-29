<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllSalaryCategories($conn, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "sc.deleted_at IS NULL";
    if ($search) {
        $where .= " AND sc.category_name LIKE '%$search%'";
    }
    if (isset($params['category_type']) && in_array($params['category_type'], ['allowance', 'deduction'], true)) {
        $category_type = mysqli_real_escape_string($conn, $params['category_type']);
        $where .= " AND sc.category_type = '$category_type'";
    }

    $from = APP_SCHEMA . ".salary_category sc
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = sc.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = sc.updated_by";

    $result       = mysqli_query($conn, "SELECT sc.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY sc.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".salary_category sc WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Salary categories found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No salary categories found');
    }
}

function createSalaryCategory($conn, $input, $username) {
    $required = ['category_name', 'category_type'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    if (!in_array($input['category_type'], ['allowance', 'deduction'], true)) {
        jsonResponse(400, 'category_type must be allowance or deduction');
        return;
    }

    $category_name = trim(mysqli_real_escape_string($conn, $input['category_name']));
    $category_type = mysqli_real_escape_string($conn, $input['category_type']);

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".salary_category WHERE category_name = '$category_name' AND category_type = '$category_type' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Salary category already exists');
        return;
    }

    $salary_category_id = generateUUID();
    $now                 = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".salary_category (id, category_name, category_type, created_by, created_at)
            VALUES ('$salary_category_id', '$category_name', '$category_type', '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Salary category created successfully', ['salary_category_id' => $salary_category_id]);
    } else {
        jsonResponse(500, 'Failed to create salary category', ['error' => mysqli_error($conn)]);
    }
}

function getDetailSalaryCategory($conn, $salary_category_id) {
    $salary_category_id = mysqli_real_escape_string($conn, $salary_category_id);

    $from   = APP_SCHEMA . ".salary_category sc
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = sc.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = sc.updated_by";
    $result = mysqli_query($conn, "SELECT sc.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE sc.id = '$salary_category_id' AND sc.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Salary category not found');
        return;
    }

    jsonResponse(200, 'Salary category found', mysqli_fetch_assoc($result));
}

function updateSalaryCategory($conn, $salary_category_id, $input, $username) {
    $salary_category_id = mysqli_real_escape_string($conn, $salary_category_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".salary_category WHERE id = '$salary_category_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Salary category not found');
        return;
    }

    $updates = [];

    if (isset($input['category_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['category_name']));
        if ($val === '') { jsonResponse(400, 'category_name cannot be empty'); return; }
        $updates[] = "category_name = '$val'";
    }

    if (isset($input['category_type'])) {
        if (!in_array($input['category_type'], ['allowance', 'deduction'], true)) {
            jsonResponse(400, 'category_type must be allowance or deduction');
            return;
        }
        $val = mysqli_real_escape_string($conn, $input['category_type']);
        $updates[] = "category_type = '$val'";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".salary_category SET " . implode(', ', $updates) . " WHERE id = '$salary_category_id'")) {
        jsonResponse(200, 'Salary category updated successfully');
    } else {
        jsonResponse(500, 'Failed to update salary category', ['error' => mysqli_error($conn)]);
    }
}

function deleteSalaryCategory($conn, $salary_category_id, $username) {
    $salary_category_id = mysqli_real_escape_string($conn, $salary_category_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".salary_category WHERE id = '$salary_category_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Salary category not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".salary_category SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$salary_category_id'")) {
        jsonResponse(200, 'Salary category deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete salary category', ['error' => mysqli_error($conn)]);
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

$salary_category_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($salary_category_id) {
        switch ($method) {
            case 'GET':
                getDetailSalaryCategory($conn, $salary_category_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateSalaryCategory($conn, $salary_category_id, $input, $username);
                break;
            case 'DELETE':
                deleteSalaryCategory($conn, $salary_category_id, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllSalaryCategories($conn, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createSalaryCategory($conn, $input, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
