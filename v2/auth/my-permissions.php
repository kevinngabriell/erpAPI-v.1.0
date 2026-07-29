<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../helpers/auth_response.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

function getMyPermissions($conn, $app_role_id): void {
    $stmt = $conn->prepare(
        "SELECT role_name FROM " . CORE_SCHEMA . ".app_role WHERE app_role_id = ? LIMIT 1"
    );
    $stmt->bind_param('s', $app_role_id);
    $stmt->execute();
    $role = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$role) {
        authResponse(404, 'Role not found');
        return;
    }

    $stmt = $conn->prepare(
        "SELECT p.permission_key, p.module, p.label, p.description
         FROM " . CORE_SCHEMA . ".app_role_permission rp
         JOIN " . CORE_SCHEMA . ".app_permission p ON p.permission_id = rp.permission_id
         WHERE rp.app_role_id = ?
         ORDER BY p.module, p.label"
    );
    $stmt->bind_param('s', $app_role_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $permissions = [];
    $modules     = [];
    while ($row = $result->fetch_assoc()) {
        $permissions[] = $row['permission_key'];
        $modules[$row['module']][] = [
            'permission_key' => $row['permission_key'],
            'label'          => $row['label'],
            'description'    => $row['description'],
        ];
    }
    $stmt->close();

    authResponse(200, 'Permissions retrieved successfully', null, [
        'app_role_id' => $app_role_id,
        'role_name'   => $role['role_name'],
        'permissions' => $permissions,
        'modules'     => $modules,
    ]);
}

try {
    $authUser    = requireAuth();
    $method      = $_SERVER['REQUEST_METHOD'];
    $app_role_id = $authUser['app_role_id'] ?? null;

    if (!$app_role_id) {
        authResponse(400, 'No role assigned to this account yet.');
        exit;
    }

    switch ($method) {
        case 'GET':
            $conn = getConn();
            getMyPermissions($conn, $app_role_id);
            break;
        default:
            authResponse(405, 'Method not allowed');
    }

} catch (Throwable $e) {
    authResponse(500, 'An unexpected error occurred. Please try again.', null,
        APP_ENV === 'development' ? ['debug' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()] : null
    );
}
