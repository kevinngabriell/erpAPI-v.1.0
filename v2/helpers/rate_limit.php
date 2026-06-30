<?php

function checkRateLimitByEmail($conn, string $email, string $action, int $max, int $window_minutes): bool {
    $pattern = $action . '%';
    $stmt    = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM " . CORE_SCHEMA . ".log
         WHERE username = ? AND module = 'auth' AND action LIKE ?
           AND `timestamp` > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    if (!$stmt) return false;
    $stmt->bind_param('ssi', $email, $pattern, $window_minutes);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['cnt'] ?? 0) >= $max;
}

function checkRateLimitByIP($conn, string $ip, string $action_prefix, int $max, int $window_minutes): bool {
    $pattern = $action_prefix . '%';
    $stmt    = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM " . CORE_SCHEMA . ".log
         WHERE ip_address = ? AND module = 'auth' AND action LIKE ?
           AND `timestamp` > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    if (!$stmt) return false;
    $stmt->bind_param('ssi', $ip, $pattern, $window_minutes);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['cnt'] ?? 0) >= $max;
}
