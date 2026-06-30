<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../helpers/auth_response.php';
require_once __DIR__ . '/../helpers/rate_limit.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

function writeLoginLog($conn, string $action, ?string $user_id, string $username): void {
    $log_id = generateUUID();
    $ip     = substr(getUserIP(), 0, 45);
    $ua     = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $module = 'auth';
    $now    = date('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        "INSERT INTO " . CORE_SCHEMA . ".log (id, action, ip_address, module, resource_id, `timestamp`, user_agent, username)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if ($stmt) {
        $stmt->bind_param('ssssssss', $log_id, $action, $ip, $module, $user_id, $now, $ua, $username);
        $stmt->execute();
        $stmt->close();
    }
}

function loginUser($conn, $input): void {
    // 1. Required field validation
    if (!isset($input['email']) || trim((string)$input['email']) === '') {
        authResponse(400, 'The email field is required.');
        return;
    }
    if (!isset($input['password']) || trim((string)$input['password']) === '') {
        authResponse(400, 'The password field is required.');
        return;
    }

    // 2. Sanitize
    $email    = strtolower(strip_tags(trim($input['email'])));
    $password = $input['password'];

    // 3. IP rate limit — 20 requests per minute
    $ip = getUserIP();
    if (checkRateLimitByIP($conn, $ip, 'login', 20, 1)) {
        writeLoginLog($conn, 'login_failed', null, $email);
        authResponse(429, 'Too many login attempts. Please try again later.', 'RATE_002');
        return;
    }

    // 4. Email rate limit — 5 failed per 15 minutes
    if (checkRateLimitByEmail($conn, $email, 'login_failed', 5, 15)) {
        authResponse(429, 'Too many login attempts. Please try again in 15 minutes.', 'RATE_001');
        return;
    }

    // 5. Look up user with company and subscription data
    $stmt = $conn->prepare(
        "SELECT u.user_id, u.username, u.email, u.first_name, u.last_name, u.password,
                u.account_status, u.app_role_id, u.company_id, u.position_id, u.language,
                c.company_name, c.status AS company_status,
                ap.position_name,
                GREATEST(COALESCE(DATEDIFF(s.next_billing_date, NOW()), 0), 0) AS days_remaining
         FROM " . CORE_SCHEMA . ".app_user u
         JOIN " . CORE_SCHEMA . ".app_company c ON c.company_id = u.company_id
         LEFT JOIN " . APP_SCHEMA . ".aluria_positions ap ON ap.position_id = u.position_id
         LEFT JOIN " . CORE_SCHEMA . ".app_subscription s
               ON s.app_company_id = u.company_id
              AND s.app_id = u.app_id
              AND s.subscription_status = 'active'
         WHERE u.email = ? AND u.app_id = 'aluria'
         LIMIT 1"
    );

    if (!$stmt) {
        authResponse(500, 'An unexpected error occurred. Please try again.');
        return;
    }

    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // 6. User not found — same message as wrong password (prevent enumeration)
    if (!$row) {
        writeLoginLog($conn, 'login_failed', null, $email);
        authResponse(401, 'Invalid email or password', 'AUTH_001');
        return;
    }

    // 7. Verify password
    if (!password_verify($password, $row['password'])) {
        writeLoginLog($conn, 'login_failed', $row['user_id'], $email);
        authResponse(401, 'Invalid email or password', 'AUTH_001');
        return;
    }

    // 8. Account status check
    $account_status = $row['account_status'];
    if ($account_status === 'pending') {
        authResponse(401, 'Account is not verified yet', 'AUTH_002');
        return;
    }
    if ($account_status === 'waiting_payment') {
        authResponse(401, 'Account is pending payment verification', 'AUTH_003');
        return;
    }
    if ($account_status === 'suspended') {
        authResponse(403, 'Account has been suspended. Contact your administrator.', 'AUTH_004');
        return;
    }
    if ($account_status === 'disabled') {
        authResponse(403, 'Account has been disabled.', 'AUTH_005');
        return;
    }
    if ($account_status !== 'verified') {
        authResponse(403, 'Account is not active.', null);
        return;
    }

    // 9. Company status check
    if ($row['company_status'] === 'inactive') {
        authResponse(403, 'Your company account is not active.', 'AUTH_006');
        return;
    }

    // 10. Build JWT payload — 8 hours TTL per Aluria spec
    $days_remaining = (int)$row['days_remaining'];
    $claims         = [
        'user_id'        => $row['user_id'],
        'username'       => $row['username'],
        'email'          => $row['email'],
        'first_name'     => $row['first_name'],
        'last_name'      => $row['last_name'],
        'app_id'         => 'aluria',
        'app_role_id'    => $row['app_role_id'],
        'company_id'     => $row['company_id'],
        'position_id'    => $row['position_id'],
        'days_remaining' => $days_remaining,
        'language'       => $row['language'] ?? 'id',
    ];

    $token = JWT::encode($claims, 28800);

    // 11. Update last-login timestamp
    $now  = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE " . CORE_SCHEMA . ".app_user SET updated_at = ? WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param('ss', $now, $row['user_id']);
        $stmt->execute();
        $stmt->close();
    }

    // 12. Audit log
    writeLoginLog($conn, 'login_success', $row['user_id'], $email);

    authResponse(200, 'Login successful', null, [
        'token'   => $token,
        'user'    => [
            'user_id'       => $row['user_id'],
            'first_name'    => $row['first_name'],
            'last_name'     => $row['last_name'],
            'email'         => $row['email'],
            'position_id'   => $row['position_id'],
            'position_name' => $row['position_name'] ?? '',
            'language'      => $row['language'] ?? 'id',
        ],
        'company' => [
            'company_id'     => $row['company_id'],
            'company_name'   => $row['company_name'],
            'days_remaining' => $days_remaining,
        ],
    ]);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn   = getConn();

    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            loginUser($conn, $input);
            break;
        default:
            authResponse(405, 'Method not allowed');
    }

} catch (Throwable $e) {
    authResponse(500, 'An unexpected error occurred. Please try again.', null,
        APP_ENV === 'development' ? ['debug' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()] : null
    );
}
