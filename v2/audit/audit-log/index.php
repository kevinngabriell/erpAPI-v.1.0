<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllAuditLogs($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $where = "al.company_id = '$company_id'";
    if (isset($params['module']) && trim($params['module']) !== '') {
        $module = mysqli_real_escape_string($conn, $params['module']);
        $where .= " AND al.module = '$module'";
    }
    if (isset($params['reference_id']) && trim($params['reference_id']) !== '') {
        $reference_id = mysqli_real_escape_string($conn, $params['reference_id']);
        $where .= " AND al.reference_id = '$reference_id'";
    }
    if (isset($params['action']) && trim($params['action']) !== '') {
        $action_filter = mysqli_real_escape_string($conn, $params['action']);
        $where .= " AND al.action = '$action_filter'";
    }

    $from = APP_SCHEMA . ".audit_log al
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = al.action_by
            LEFT JOIN " . CORE_SCHEMA . ".app_position ap ON ap.position_id = au.position_id";

    $result       = mysqli_query($conn, "SELECT al.*,
            CONCAT(au.first_name, ' ', au.last_name) AS action_by,
            ap.position_name
            FROM $from WHERE $where ORDER BY al.action_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM $from WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Audit logs found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No audit logs found');
    }
}

function getDetailAuditLog($conn, $audit_log_id, $company_id) {
    $audit_log_id = mysqli_real_escape_string($conn, $audit_log_id);

    $from = APP_SCHEMA . ".audit_log al
            LEFT JOIN " . CORE_SCHEMA . ".app_user au ON au.user_id COLLATE utf8mb4_general_ci = al.action_by
            LEFT JOIN " . CORE_SCHEMA . ".app_position ap ON ap.position_id = au.position_id";

    $result = mysqli_query($conn, "SELECT al.*,
            CONCAT(au.first_name, ' ', au.last_name) AS action_by,
            ap.position_name
            FROM $from WHERE al.id = '$audit_log_id' AND al.company_id = '$company_id' LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Audit log not found');
        return;
    }

    jsonResponse(200, 'Audit log found', mysqli_fetch_assoc($result));
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

$audit_log_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($audit_log_id) {
        switch ($method) {
            case 'GET':
                getDetailAuditLog($conn, $audit_log_id, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllAuditLogs($conn, $company_id, $_GET);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
