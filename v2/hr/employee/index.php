<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

const EMPLOYEE_ACCOUNT_STATUSES = ['pending', 'waiting_payment', 'verified', 'suspended', 'disabled', 'rejected'];

// The employee directory is CORE_SCHEMA.app_user for this app + company. Role
// ("peran") comes from app_role, position ("jabatan") from app_position, and
// the ERP-only HR fields from APP_SCHEMA.employee_profile (see v37).
function employeeFrom() {
    return CORE_SCHEMA . ".app_user u
        LEFT JOIN " . CORE_SCHEMA . ".app_role r ON r.app_role_id = u.app_role_id
        LEFT JOIN " . CORE_SCHEMA . ".app_position p ON p.position_id = u.position_id
        LEFT JOIN " . APP_SCHEMA . ".employee_profile ep ON u.user_id COLLATE utf8mb4_general_ci = ep.app_user_id AND ep.deleted_at IS NULL";
}

function employeeColumns() {
    return "u.user_id, u.username, u.first_name, u.last_name,
        TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS full_name,
        u.email, u.phone_number, u.account_status, u.app_role_id, r.role_name,
        u.position_id, p.position_name, ep.employee_code, ep.department, ep.employment_type, ep.join_date,
        u.last_login_at, u.created_at";
}

function getAllEmployees($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(15, max(1, (int)($params['limit'] ?? 15)));
    $offset = ($page - 1) * $limit;
    $app_id = mysqli_real_escape_string($conn, APP_ID);

    $base_where = "u.company_id = '$company_id' AND u.app_id = '$app_id'";
    $where      = $base_where;

    if (isset($params['search']) && trim($params['search']) !== '') {
        $search = mysqli_real_escape_string($conn, trim($params['search']));
        $where .= " AND (u.first_name LIKE '%$search%' OR u.last_name LIKE '%$search%'
            OR CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) LIKE '%$search%'
            OR u.email LIKE '%$search%' OR u.username LIKE '%$search%'
            OR r.role_name LIKE '%$search%' OR p.position_name LIKE '%$search%'
            OR ep.employee_code LIKE '%$search%' OR ep.department LIKE '%$search%')";
    }
    if (isset($params['app_role_id']) && trim($params['app_role_id']) !== '') {
        $app_role_id = mysqli_real_escape_string($conn, $params['app_role_id']);
        $where .= " AND u.app_role_id = '$app_role_id'";
    }
    if (isset($params['account_status']) && in_array($params['account_status'], EMPLOYEE_ACCOUNT_STATUSES, true)) {
        $account_status = mysqli_real_escape_string($conn, $params['account_status']);
        $where .= " AND u.account_status = '$account_status'";
    }

    $from = employeeFrom();

    $result = mysqli_query($conn, "SELECT " . employeeColumns() . " FROM $from WHERE $where
        ORDER BY u.first_name ASC, u.last_name ASC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM $from WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    $status_result = mysqli_query($conn, "SELECT u.account_status, COUNT(*) AS total FROM " . CORE_SCHEMA . ".app_user u
        WHERE $base_where GROUP BY u.account_status");
    $by_status = array_fill_keys(EMPLOYEE_ACCOUNT_STATUSES, 0);
    $employee_total = 0;
    while ($row = mysqli_fetch_assoc($status_result)) {
        $by_status[$row['account_status']] = (int)$row['total'];
        $employee_total += (int)$row['total'];
    }

    $role_result = mysqli_query($conn, "SELECT u.app_role_id, r.role_name, COUNT(*) AS total
        FROM " . CORE_SCHEMA . ".app_user u
        LEFT JOIN " . CORE_SCHEMA . ".app_role r ON r.app_role_id = u.app_role_id
        WHERE $base_where GROUP BY u.app_role_id, r.role_name ORDER BY r.role_name ASC");
    $by_role = [];
    while ($row = mysqli_fetch_assoc($role_result)) {
        $by_role[] = ['app_role_id' => $row['app_role_id'], 'role_name' => $row['role_name'], 'total' => (int)$row['total']];
    }

    jsonResponse(200, 'Employees found', [
        'data'       => $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [],
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
        'summary'    => [
            'total'     => $employee_total,
            'by_status' => $by_status,
            'by_role'   => $by_role,
        ],
    ]);
}

function getDetailEmployee($conn, $user_id, $company_id) {
    $user_id = mysqli_real_escape_string($conn, $user_id);
    $app_id  = mysqli_real_escape_string($conn, APP_ID);

    $result = mysqli_query($conn, "SELECT " . employeeColumns() . ",
            ep.gender, ep.work_location, ep.supervisor_id,
            TRIM(CONCAT(COALESCE(su.first_name, ''), ' ', COALESCE(su.last_name, ''))) AS supervisor_name
        FROM " . employeeFrom() . "
        LEFT JOIN " . CORE_SCHEMA . ".app_user su ON su.user_id COLLATE utf8mb4_general_ci = ep.supervisor_id
        WHERE u.user_id = '$user_id' AND u.company_id = '$company_id' AND u.app_id = '$app_id' LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Employee not found');
        return;
    }

    $employee = mysqli_fetch_assoc($result);
    if ($employee['supervisor_name'] === '') $employee['supervisor_name'] = null;

    jsonResponse(200, 'Employee found', $employee);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

$user_id = !empty($action) ? $action : null;

try {
    $conn = getConn();
    $company_id = mysqli_real_escape_string($conn, $company_id);

    if ($method !== 'GET') {
        methodNotAllowed(['GET']);
        return;
    }

    if ($user_id) {
        getDetailEmployee($conn, $user_id, $company_id);
    } else {
        getAllEmployees($conn, $company_id, $_GET);
    }
} catch (Exception $e) {
    handleModuleException($e);
}
