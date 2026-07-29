<?php

function userHasPermission($conn, $app_role_id, $permission_key) {
    if (!$app_role_id) return false;

    $app_role_id    = mysqli_real_escape_string($conn, $app_role_id);
    $permission_key = mysqli_real_escape_string($conn, $permission_key);

    $result = mysqli_query($conn, "SELECT 1 FROM " . CORE_SCHEMA . ".app_role_permission rp
            JOIN " . CORE_SCHEMA . ".app_permission p ON p.permission_id = rp.permission_id
            WHERE rp.app_role_id = '$app_role_id' AND p.permission_key = '$permission_key' LIMIT 1");

    return $result && mysqli_num_rows($result) > 0;
}
