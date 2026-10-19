<?php
require_once __DIR__ . '/../config.php';

function writeAuthLog($conn, string $action, ?string $user_id, string $username): void {
    $ip     = substr(getUserIP(), 0, 45);
    $ua     = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $module = 'auth';
    $now    = date('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        "INSERT INTO " . CORE_SCHEMA . ".log
            (action, module, description, actor_type, actor_username, resource_id, ip_address, user_agent, occurred_at, app_id)
         VALUES (?, ?, ?, 'app', ?, ?, ?, ?, ?, '" . APP_ID . "')"
    );
    if ($stmt) {
        $stmt->bind_param('ssssssss', $action, $module, $action, $username, $user_id, $ip, $ua, $now);
        $stmt->execute();
        $stmt->close();
    }
}
