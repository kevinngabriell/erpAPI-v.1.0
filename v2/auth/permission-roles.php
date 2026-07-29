<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../helpers/auth_response.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

function getRolesForPermission($conn, string $permission_key): void {
    $stmt = $conn->prepare(
        "SELECT permission_id, permission_key, label
         FROM " . CORE_SCHEMA . ".app_permission
         WHERE permission_key = ? LIMIT 1"
    );
    $stmt->bind_param('s', $permission_key);
    $stmt->execute();
    $permission = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$permission) {
        authResponse(404, 'Permission not found');
        return;
    }

    $stmt = $conn->prepare(
        "SELECT r.role_name
         FROM " . CORE_SCHEMA . ".app_role_permission rp
         JOIN " . CORE_SCHEMA . ".app_role r ON r.app_role_id = rp.app_role_id
         WHERE rp.permission_id = ?
         ORDER BY r.role_name"
    );
    $stmt->bind_param('s', $permission['permission_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $role_names = [];
    while ($row = $result->fetch_assoc()) {
        $role_names[] = $row['role_name'];
    }
    $stmt->close();

    authResponse(200, 'Roles retrieved successfully', null, [
        'permission_key' => $permission['permission_key'],
        'label'          => $permission['label'],
        'roles'          => $role_names,
    ]);
}

function getRolesForPermissions($conn, array $permission_keys): void {
    $placeholders = implode(',', array_fill(0, count($permission_keys), '?'));
    $types        = str_repeat('s', count($permission_keys));

    $stmt = $conn->prepare(
        "SELECT permission_id, permission_key, label
         FROM " . CORE_SCHEMA . ".app_permission
         WHERE permission_key IN ($placeholders)"
    );
    $stmt->bind_param($types, ...$permission_keys);
    $stmt->execute();
    $result = $stmt->get_result();

    $permissions = [];
    while ($row = $result->fetch_assoc()) {
        $permissions[$row['permission_id']] = $row;
    }
    $stmt->close();

    if (empty($permissions)) {
        authResponse(200, 'Roles retrieved successfully', null, ['permissions' => []]);
        return;
    }

    $permission_ids = array_keys($permissions);
    $id_placeholders = implode(',', array_fill(0, count($permission_ids), '?'));
    $id_types        = str_repeat('s', count($permission_ids));

    $stmt = $conn->prepare(
        "SELECT rp.permission_id, r.role_name
         FROM " . CORE_SCHEMA . ".app_role_permission rp
         JOIN " . CORE_SCHEMA . ".app_role r ON r.app_role_id = rp.app_role_id
         WHERE rp.permission_id IN ($id_placeholders)
         ORDER BY r.role_name"
    );
    $stmt->bind_param($id_types, ...$permission_ids);
    $stmt->execute();
    $result = $stmt->get_result();

    $roles_by_permission = [];
    while ($row = $result->fetch_assoc()) {
        $roles_by_permission[$row['permission_id']][] = $row['role_name'];
    }
    $stmt->close();

    $data = [];
    foreach ($permissions as $permission_id => $permission) {
        $data[] = [
            'permission_key' => $permission['permission_key'],
            'label'          => $permission['label'],
            'roles'          => $roles_by_permission[$permission_id] ?? [],
        ];
    }

    authResponse(200, 'Roles retrieved successfully', null, ['permissions' => $data]);
}

try {
    $authUser = requireAuth();
    $method   = $_SERVER['REQUEST_METHOD'];

    if ($method !== 'GET') {
        authResponse(405, 'Method not allowed');
        exit;
    }

    $conn       = getConn();
    $params     = $_GET;
    $sub_action = $parts[4] ?? '';

    if ($action === 'roles') {
        $keys_param = trim($params['keys'] ?? '');
        if ($keys_param === '') {
            authResponse(400, 'The keys query parameter is required.');
            exit;
        }

        $permission_keys = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $keys_param))
        )));

        if (empty($permission_keys)) {
            authResponse(400, 'The keys query parameter is required.');
            exit;
        }

        if (count($permission_keys) > 50) {
            authResponse(400, 'A maximum of 50 keys can be requested at once.');
            exit;
        }

        getRolesForPermissions($conn, $permission_keys);
    } else {
        $permission_key = $action;
        if ($permission_key === '' || $sub_action !== 'roles') {
            authResponse(404, 'Route not found');
            exit;
        }

        getRolesForPermission($conn, $permission_key);
    }

} catch (Throwable $e) {
    authResponse(500, 'An unexpected error occurred. Please try again.', null,
        APP_ENV === 'development' ? ['debug' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()] : null
    );
}
