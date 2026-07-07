<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllSalaryTransactions($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $where = "company_id = '$company_id' AND deleted_at IS NULL";
    if (isset($params['app_user_id']) && trim($params['app_user_id']) !== '') {
        $app_user_id = mysqli_real_escape_string($conn, $params['app_user_id']);
        $where .= " AND app_user_id = '$app_user_id'";
    }
    if (isset($params['salary_category_id']) && trim($params['salary_category_id']) !== '') {
        $salary_category_id = mysqli_real_escape_string($conn, $params['salary_category_id']);
        $where .= " AND salary_category_id = '$salary_category_id'";
    }

    $result       = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".salary_transaction WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".salary_transaction WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Salary transactions found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No salary transactions found');
    }
}

function createSalaryTransaction($conn, $input, $username, $company_id) {
    $required = ['app_user_id', 'salary_category_id', 'salary_amount'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $app_user_id        = mysqli_real_escape_string($conn, $input['app_user_id']);
    $salary_category_id = mysqli_real_escape_string($conn, $input['salary_category_id']);
    $salary_amount       = (float)$input['salary_amount'];

    $category_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".salary_category WHERE id = '$salary_category_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($category_check) === 0) {
        jsonResponse(404, 'Salary category not found');
        return;
    }

    $salary_transaction_id = generateUUID();
    $now                   = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".salary_transaction
            (id, company_id, app_user_id, salary_category_id, salary_amount, created_by, created_at)
            VALUES ('$salary_transaction_id', '$company_id', '$app_user_id', '$salary_category_id', $salary_amount, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Salary transaction created successfully', ['salary_transaction_id' => $salary_transaction_id]);
    } else {
        jsonResponse(500, 'Failed to create salary transaction', ['error' => mysqli_error($conn)]);
    }
}

function getDetailSalaryTransaction($conn, $salary_transaction_id, $company_id) {
    $salary_transaction_id = mysqli_real_escape_string($conn, $salary_transaction_id);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".salary_transaction WHERE id = '$salary_transaction_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Salary transaction not found');
        return;
    }

    jsonResponse(200, 'Salary transaction found', mysqli_fetch_assoc($result));
}

function updateSalaryTransaction($conn, $salary_transaction_id, $input, $username, $company_id) {
    $salary_transaction_id = mysqli_real_escape_string($conn, $salary_transaction_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".salary_transaction WHERE id = '$salary_transaction_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Salary transaction not found');
        return;
    }

    $updates = [];

    if (isset($input['salary_category_id'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['salary_category_id']));
        if ($val === '') { jsonResponse(400, 'salary_category_id cannot be empty'); return; }
        $category_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".salary_category WHERE id = '$val' AND deleted_at IS NULL LIMIT 1");
        if (mysqli_num_rows($category_check) === 0) {
            jsonResponse(404, 'Salary category not found');
            return;
        }
        $updates[] = "salary_category_id = '$val'";
    }

    if (isset($input['salary_amount']) && $input['salary_amount'] !== '') {
        $updates[] = "salary_amount = " . (float)$input['salary_amount'];
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".salary_transaction SET " . implode(', ', $updates) . " WHERE id = '$salary_transaction_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Salary transaction updated successfully');
    } else {
        jsonResponse(500, 'Failed to update salary transaction', ['error' => mysqli_error($conn)]);
    }
}

function deleteSalaryTransaction($conn, $salary_transaction_id, $username, $company_id) {
    $salary_transaction_id = mysqli_real_escape_string($conn, $salary_transaction_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".salary_transaction WHERE id = '$salary_transaction_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Salary transaction not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".salary_transaction SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$salary_transaction_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Salary transaction deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete salary transaction', ['error' => mysqli_error($conn)]);
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

$salary_transaction_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($salary_transaction_id) {
        switch ($method) {
            case 'GET':
                getDetailSalaryTransaction($conn, $salary_transaction_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateSalaryTransaction($conn, $salary_transaction_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteSalaryTransaction($conn, $salary_transaction_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllSalaryTransactions($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createSalaryTransaction($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
