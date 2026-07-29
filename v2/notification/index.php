<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/notification.php';

const NOTIFICATION_CATEGORY_MODULES = [
    'sales'    => ['sales_order', 'sales_invoice', 'sales_delivery', 'sales_sppb', 'sales_profit'],
    'purchase' => ['purchase_order', 'purchase_invoice', 'purchase_receive'],
    'finance'  => ['finance_transaction', 'finance_payment'],
    'system'   => ['notification'],
];

function getAllNotifications($conn, $company_id, $username, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $where = "n.company_id = '$company_id' AND n.target_user_id = '$username' AND n.deleted_at IS NULL";
    if (isset($params['unread']) && $params['unread'] === '1') {
        $where .= " AND n.read_at IS NULL";
    }
    if (isset($params['category']) && trim($params['category']) !== '') {
        $category = trim($params['category']);
        if (!isset(NOTIFICATION_CATEGORY_MODULES[$category])) {
            jsonResponse(400, 'category must be one of: ' . implode(', ', array_keys(NOTIFICATION_CATEGORY_MODULES)));
            return;
        }
        $modules_sql = implode(',', array_map(fn($module) => "'" . mysqli_real_escape_string($conn, $module) . "'", NOTIFICATION_CATEGORY_MODULES[$category]));
        $where .= " AND n.source_module IN ($modules_sql)";
    }

    $from = APP_SCHEMA . ".notification n";

    $result       = mysqli_query($conn, "SELECT n.* FROM $from WHERE $where ORDER BY n.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM $from WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    jsonResponse(200, 'Notifications found', [
        'data'       => $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [],
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

function markNotificationRead($conn, $notification_id, $username, $company_id) {
    $notification_id = mysqli_real_escape_string($conn, $notification_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".notification
            WHERE id = '$notification_id' AND target_user_id = '$username' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Notification not found');
        return;
    }

    $now = date('Y-m-d H:i:s');
    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".notification SET read_at = '$now', updated_by = '$username', updated_at = '$now'
            WHERE id = '$notification_id' AND target_user_id = '$username' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Notification marked as read');
    } else {
        jsonResponse(500, 'Failed to mark notification as read', ['error' => mysqli_error($conn)]);
    }
}

function markAllNotificationsRead($conn, $username, $company_id) {
    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".notification SET read_at = '$now', updated_by = '$username', updated_at = '$now'
            WHERE target_user_id = '$username' AND company_id = '$company_id' AND read_at IS NULL AND deleted_at IS NULL")) {
        jsonResponse(200, 'All notifications marked as read');
    } else {
        jsonResponse(500, 'Failed to mark notifications as read', ['error' => mysqli_error($conn)]);
    }
}

function getNotificationUnreadCount($conn, $company_id, $username) {
    jsonResponse(200, 'Unread count retrieved', ['unread_count' => getUnreadNotificationCount($conn, $company_id, $username)]);
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

$notification_id = !empty($action) ? $action : null;
$sub_action       = $parts[4] ?? '';

try {
    $conn = getConn();

    if ($notification_id === 'unread-count' && $sub_action === '') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        getNotificationUnreadCount($conn, $company_id, $username);

    } elseif ($notification_id === 'read-all' && $sub_action === '') {
        if ($method !== 'PUT') { jsonResponse(405, 'Method Not Allowed'); }
        markAllNotificationsRead($conn, $username, $company_id);

    } elseif ($notification_id && $sub_action === 'read') {
        if ($method !== 'PUT') { jsonResponse(405, 'Method Not Allowed'); }
        markNotificationRead($conn, $notification_id, $username, $company_id);

    } elseif ($notification_id) {
        jsonResponse(404, 'Route not found');

    } else {
        switch ($method) {
            case 'GET':
                getAllNotifications($conn, $company_id, $username, $_GET);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
