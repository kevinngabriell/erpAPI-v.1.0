<?php

function getRolePermissions($conn, string $app_role_id): array {
    $stmt = $conn->prepare(
        "SELECT p.permission_key
         FROM " . CORE_SCHEMA . ".app_role_permission rp
         JOIN " . CORE_SCHEMA . ".app_permission p ON p.permission_id = rp.permission_id
         WHERE rp.app_role_id = ?"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('s', $app_role_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $permissions = [];
    while ($row = $result->fetch_assoc()) {
        $permissions[$row['permission_key']] = true;
    }
    $stmt->close();

    return $permissions;
}
