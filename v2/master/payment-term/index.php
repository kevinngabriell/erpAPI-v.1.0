<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllPaymentTerms($conn, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "pt.deleted_at IS NULL";
    if ($search) {
        $where .= " AND pt.term_name LIKE '%$search%'";
    }

    $from = APP_SCHEMA . ".payment_term pt
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = pt.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = pt.updated_by";

    $result       = mysqli_query($conn, "SELECT pt.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY pt.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".payment_term pt WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Payment terms found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No payment terms found');
    }
}

function createPaymentTerm($conn, $input, $username) {
    $required = ['term_name'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $term_name = trim(mysqli_real_escape_string($conn, $input['term_name']));

    $days_sql = isset($input['days']) && $input['days'] !== ''
        ? (int)$input['days']
        : 'NULL';

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".payment_term WHERE term_name = '$term_name' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Payment term already exists');
        return;
    }

    $payment_term_id = generateUUID();
    $now              = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".payment_term (id, term_name, days, created_by, created_at)
            VALUES ('$payment_term_id', '$term_name', $days_sql, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Payment term created successfully', ['payment_term_id' => $payment_term_id]);
    } else {
        jsonResponse(500, 'Failed to create payment term', ['error' => mysqli_error($conn)]);
    }
}

function getDetailPaymentTerm($conn, $payment_term_id) {
    $payment_term_id = mysqli_real_escape_string($conn, $payment_term_id);

    $from   = APP_SCHEMA . ".payment_term pt
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = pt.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = pt.updated_by";
    $result = mysqli_query($conn, "SELECT pt.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE pt.id = '$payment_term_id' AND pt.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Payment term not found');
        return;
    }

    jsonResponse(200, 'Payment term found', mysqli_fetch_assoc($result));
}

function updatePaymentTerm($conn, $payment_term_id, $input, $username) {
    $payment_term_id = mysqli_real_escape_string($conn, $payment_term_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".payment_term WHERE id = '$payment_term_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Payment term not found');
        return;
    }

    $updates = [];

    if (isset($input['term_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['term_name']));
        if ($val === '') { jsonResponse(400, 'term_name cannot be empty'); return; }
        $updates[] = "term_name = '$val'";
    }

    if (isset($input['days'])) {
        $days_sql = $input['days'] !== '' ? (int)$input['days'] : 'NULL';
        $updates[] = "days = $days_sql";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".payment_term SET " . implode(', ', $updates) . " WHERE id = '$payment_term_id'")) {
        jsonResponse(200, 'Payment term updated successfully');
    } else {
        jsonResponse(500, 'Failed to update payment term', ['error' => mysqli_error($conn)]);
    }
}

function deletePaymentTerm($conn, $payment_term_id, $username) {
    $payment_term_id = mysqli_real_escape_string($conn, $payment_term_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".payment_term WHERE id = '$payment_term_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Payment term not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".payment_term SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$payment_term_id'")) {
        jsonResponse(200, 'Payment term deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete payment term', ['error' => mysqli_error($conn)]);
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

$payment_term_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($payment_term_id) {
        switch ($method) {
            case 'GET':
                getDetailPaymentTerm($conn, $payment_term_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updatePaymentTerm($conn, $payment_term_id, $input, $username);
                break;
            case 'DELETE':
                deletePaymentTerm($conn, $payment_term_id, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllPaymentTerms($conn, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createPaymentTerm($conn, $input, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
