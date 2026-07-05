<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../helpers/auth_response.php';
require_once __DIR__ . '/../helpers/permissions.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// Widget key => permission_key required to see it. Add an entry here the
// same day a domain's dashboard query is migrated to v2 — until then the
// widget stays absent from the response for everyone, migrated or not.
const DASHBOARD_WIDGETS = [
    'overview'  => 'dashboard.overview.view',
    'sales'     => 'dashboard.sales.view',
    'purchase'  => 'dashboard.purchase.view',
    'warehouse' => 'dashboard.warehouse.view',
    'finance'   => 'dashboard.finance.view',
];

function getDashboard($conn, string $user_id, string $app_role_id, string $company_id): void {
    $stmt = $conn->prepare(
        "SELECT u.first_name, u.last_name, u.position_id,
                ap.position_name,
                r.role_name,
                c.company_name,
                GREATEST(COALESCE(DATEDIFF(s.next_billing_date, NOW()), 0), 0) AS days_remaining
         FROM " . CORE_SCHEMA . ".app_user u
         JOIN " . CORE_SCHEMA . ".app_company c ON c.company_id = u.company_id
         JOIN " . CORE_SCHEMA . ".app_role r ON r.app_role_id = u.app_role_id
         LEFT JOIN " . APP_SCHEMA . ".aluria_positions ap ON ap.position_id = u.position_id
         LEFT JOIN " . CORE_SCHEMA . ".app_subscription s
               ON s.app_company_id = u.company_id
              AND s.app_id = u.app_id
              AND s.subscription_status = 'active'
         WHERE u.user_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        authResponse(500, 'An unexpected error occurred. Please try again.');
        return;
    }
    $stmt->bind_param('s', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        authResponse(404, 'User not found.');
        return;
    }

    $permissions = getRolePermissions($conn, $app_role_id);

    $widgets = [];
    foreach (DASHBOARD_WIDGETS as $widget_key => $permission_key) {
        if (isset($permissions[$permission_key])) {
            $widgets[$widget_key] = null;
        }
    }

    authResponse(200, 'Dashboard loaded successfully', null, [
        'user' => [
            'first_name'    => $row['first_name'],
            'last_name'     => $row['last_name'],
            'position_id'   => $row['position_id'],
            'position_name' => $row['position_name'] ?? '',
            'role_name'     => $row['role_name'],
        ],
        'company' => [
            'company_id'     => $company_id,
            'company_name'   => $row['company_name'],
            'days_remaining' => (int)$row['days_remaining'],
        ],
        'permissions' => $permissions,
        'widgets'     => $widgets,
    ]);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn   = getConn();

    switch ($method) {
        case 'GET':
            $authUser    = requireAuth();
            $user_id     = $authUser['user_id'] ?? null;
            $app_role_id = $authUser['app_role_id'] ?? null;
            $company_id  = $authUser['company_id'] ?? null;

            if (!$user_id || !$app_role_id || !$company_id) {
                authResponse(401, 'Invalid session. Please log in again.');
                break;
            }

            getDashboard($conn, $user_id, $app_role_id, $company_id);
            break;
        default:
            authResponse(405, 'Method not allowed');
    }

} catch (Throwable $e) {
    authResponse(500, 'An unexpected error occurred. Please try again.', null,
        APP_ENV === 'development' ? ['debug' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()] : null
    );
}
