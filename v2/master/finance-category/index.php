<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/permission.php';

function getAllFinanceCategories($conn, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(15, max(1, (int)($params['limit'] ?? 15)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';
    $search = str_replace(['%', '_'], ['\%', '\_'], $search);

    $where = "fc.deleted_at IS NULL";
    if ($search) {
        $where .= " AND fc.category_name LIKE '%$search%'";
    }

    $from = APP_SCHEMA . ".finance_category fc
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = fc.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = fc.updated_by";

    $result       = mysqli_query($conn, "SELECT fc.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY fc.category_name ASC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".finance_category fc WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
    } else {
        $data = [];
    }

    jsonResponse(200, 'Finance categories found', [
        'data'       => $data,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

function createFinanceCategory($conn, $input, $username) {
    $required = ['category_name', 'category_type'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    if (!in_array($input['category_type'], ['debit', 'credit'], true)) {
        jsonResponse(400, 'category_type must be debit or credit');
        return;
    }

    $category_name = trim(mysqli_real_escape_string($conn, $input['category_name']));
    $category_type = $input['category_type'];

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".finance_category WHERE category_name = '$category_name' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Finance category already exists');
        return;
    }

    $finance_category_id = generateUUID();
    $now                  = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".finance_category (id, category_name, category_type, created_by, created_at)
            VALUES ('$finance_category_id', '$category_name', '$category_type', '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Finance category created successfully', ['finance_category_id' => $finance_category_id]);
    } else {
        jsonResponse(500, 'Failed to create finance category', safeDbError($conn));
    }
}

function getDetailFinanceCategory($conn, $finance_category_id) {
    $finance_category_id = mysqli_real_escape_string($conn, $finance_category_id);

    $from   = APP_SCHEMA . ".finance_category fc
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = fc.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = fc.updated_by";
    $result = mysqli_query($conn, "SELECT fc.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE fc.id = '$finance_category_id' AND fc.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Finance category not found');
        return;
    }

    jsonResponse(200, 'Finance category found', mysqli_fetch_assoc($result));
}

function updateFinanceCategory($conn, $finance_category_id, $input, $username) {
    $finance_category_id = mysqli_real_escape_string($conn, $finance_category_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".finance_category WHERE id = '$finance_category_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Finance category not found');
        return;
    }

    $updates = [];

    if (isset($input['category_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['category_name']));
        if ($val === '') { jsonResponse(400, 'category_name cannot be empty'); return; }
        $updates[] = "category_name = '$val'";
    }

    if (isset($input['category_type'])) {
        if (!in_array($input['category_type'], ['debit', 'credit'], true)) {
            jsonResponse(400, 'category_type must be debit or credit');
            return;
        }
        $updates[] = "category_type = '{$input['category_type']}'";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".finance_category SET " . implode(', ', $updates) . " WHERE id = '$finance_category_id'")) {
        jsonResponse(200, 'Finance category updated successfully');
    } else {
        jsonResponse(500, 'Failed to update finance category', safeDbError($conn));
    }
}

function deleteFinanceCategory($conn, $finance_category_id, $username) {
    $finance_category_id = mysqli_real_escape_string($conn, $finance_category_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".finance_category WHERE id = '$finance_category_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Finance category not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".finance_category SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$finance_category_id'")) {
        jsonResponse(200, 'Finance category deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete finance category', safeDbError($conn));
    }
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser    = requireAuth();
$method      = $_SERVER['REQUEST_METHOD'];
$company_id  = $authUser['company_id'] ?? null;
$username    = $authUser['sub'] ?? $authUser['user_id'] ?? null;
$app_role_id = $authUser['app_role_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

$finance_category_id = !empty($action) ? $action : null;

try {
    $conn = getConn();
    $company_id = mysqli_real_escape_string($conn, $company_id);
    $username = mysqli_real_escape_string($conn, $username);

    if ($finance_category_id) {
        switch ($method) {
            case 'GET':
                getDetailFinanceCategory($conn, $finance_category_id);
                break;
            case 'PUT':
                if (!userHasPermission($conn, $app_role_id, 'master.finance_category.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateFinanceCategory($conn, $finance_category_id, $input, $username);
                break;
            case 'DELETE':
                if (!userHasPermission($conn, $app_role_id, 'master.finance_category.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                deleteFinanceCategory($conn, $finance_category_id, $username);
                break;
            default:
                methodNotAllowed(['GET', 'PUT', 'DELETE']);
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllFinanceCategories($conn, $_GET);
                break;
            case 'POST':
                if (!userHasPermission($conn, $app_role_id, 'master.finance_category.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createFinanceCategory($conn, $input, $username);
                break;
            default:
                methodNotAllowed(['GET', 'POST']);
        }
    }

} catch (Exception $e) {
    handleModuleException($e);
}
