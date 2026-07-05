<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../helpers/auth_response.php';
require_once __DIR__ . '/../helpers/permissions.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

function getMyPermissions($conn, string $app_role_id): void {
    $permissions = getRolePermissions($conn, $app_role_id);

    authResponse(200, 'Permissions retrieved successfully', null, [
        'app_role_id' => $app_role_id,
        'permissions' => $permissions,
    ]);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn   = getConn();

    switch ($method) {
        case 'GET':
            $authUser    = requireAuth();
            $app_role_id = $authUser['app_role_id'] ?? null;

            if (!$app_role_id) {
                authResponse(403, 'No role assigned to this account.', 'AUTH_007');
                break;
            }

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
