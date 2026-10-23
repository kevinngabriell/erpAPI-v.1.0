<?php

require_once __DIR__ . '/../config.php';

function checkRateLimitByEmail($conn, string $email, string $action, int $max, int $window_minutes): bool {
    $pattern = $action . '%';
    $stmt    = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM " . CORE_SCHEMA . ".log
         WHERE actor_username = ? AND module = 'auth' AND action LIKE ?
           AND occurred_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
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
           AND occurred_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    if (!$stmt) return false;
    $stmt->bind_param('ssi', $ip, $pattern, $window_minutes);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['cnt'] ?? 0) >= $max;
}

function checkRateLimitByUser($conn, string $user_id, string $endpoint, int $max, int $window_seconds): bool {
    $cache_key = "rate:$user_id:$endpoint";
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM " . APP_SCHEMA . ".api_rate_limit
         WHERE user_id = ? AND endpoint = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
    );
    if (!$stmt) return false;
    $stmt->bind_param('ssi', $user_id, $endpoint, $window_seconds);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['cnt'] ?? 0) >= $max;
}

function recordApiRequest($conn, string $user_id, string $endpoint): void {
    $stmt = $conn->prepare(
        "INSERT INTO " . APP_SCHEMA . ".api_rate_limit (id, user_id, endpoint, created_at)
         VALUES (UUID(), ?, ?, NOW())"
    );
    if (!$stmt) return;
    $stmt->bind_param('ss', $user_id, $endpoint);
    $stmt->execute();
    $stmt->close();
}

function enforceRateLimit($conn, string $user_id, string $endpoint, int $max, int $window_seconds): void {
    if (checkRateLimitByUser($conn, $user_id, $endpoint, $max, $window_seconds)) {
        jsonResponse(429, 'Rate limit exceeded. Please try again later.');
    }
    recordApiRequest($conn, $user_id, $endpoint);
}

function validateJsonContentType(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) return;

    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (!str_contains($content_type, 'application/json')) {
        jsonResponse(415, 'Content-Type must be application/json');
    }
}

function checkIdempotencyKey($conn, string $user_id, string $key): ?array {
    if ($key === '') return null;
    $key_sql = mysqli_real_escape_string($conn, $key);
    $result = mysqli_query($conn, "SELECT response_code, response_message, response_data FROM " . APP_SCHEMA . ".idempotency_key
            WHERE idempotency_key = '$key_sql' AND user_id = '" . mysqli_real_escape_string($conn, $user_id) . "' LIMIT 1");
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return [
            'status_code' => (int)$row['response_code'],
            'status_message' => $row['response_message'],
            'data' => json_decode($row['response_data'], true) ?? [],
        ];
    }
    return null;
}

function storeIdempotencyKey($conn, string $user_id, string $key, int $code, string $message, array $data): void {
    if ($key === '') return;
    $key_sql     = mysqli_real_escape_string($conn, $key);
    $user_sql    = mysqli_real_escape_string($conn, $user_id);
    $msg_sql     = mysqli_real_escape_string($conn, $message);
    $data_sql    = mysqli_real_escape_string($conn, json_encode($data, JSON_UNESCAPED_UNICODE));
    $now         = date('Y-m-d H:i:s');

    mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".idempotency_key
            (idempotency_key, user_id, response_code, response_message, response_data, created_at)
            VALUES ('$key_sql', '$user_sql', $code, '$msg_sql', '$data_sql', '$now')");
}
