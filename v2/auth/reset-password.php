<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../helpers/auth_response.php';
require_once __DIR__ . '/../helpers/rate_limit.php';
require_once __DIR__ . '/../helpers/password_policy.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

function writeResetPasswordLog($conn, string $action, ?string $user_id, string $username): void {
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

function resetPassword($conn, $input): void {
    // 1. Required field validation
    $required = ['email', 'otp_code', 'new_password', 'new_password_confirmation'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            authResponse(400, "The {$field} field is required.");
            return;
        }
    }

    $email                     = strtolower(strip_tags(trim($input['email'])));
    $otp_code                  = strip_tags(trim($input['otp_code']));
    $new_password              = $input['new_password'];
    $new_password_confirmation = $input['new_password_confirmation'];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 50) {
        authResponse(400, 'The email field must be a valid email address (max 50 characters).');
        return;
    }
    if (!preg_match('/^[0-9]{6}$/', $otp_code)) {
        authResponse(400, 'The otp_code field must be a 6-digit numeric code.');
        return;
    }

    // 2. IP rate limit — 10 per hour
    $ip = getUserIP();
    if (checkRateLimitByIP($conn, $ip, 'reset_password', 10, 60)) {
        authResponse(429, 'Too many attempts. Please try again later.', 'RATE_003');
        return;
    }

    // 3. Look up user
    $stmt = $conn->prepare(
        "SELECT user_id, first_name, last_name
         FROM " . CORE_SCHEMA . ".app_user
         WHERE email = ? AND app_id = '" . APP_ID . "'
         LIMIT 1"
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user_row) {
        writeResetPasswordLog($conn, 'reset_password_failed', null, $email);
        authResponse(400, 'Invalid or expired OTP code.', 'PWD_002');
        return;
    }

    // 4. Validate OTP
    $stmt = $conn->prepare(
        "SELECT otp_id, otp_code, attempt_count
         FROM " . CORE_SCHEMA . ".otp_codes
         WHERE identifier = ? AND purpose = 'forgot_password' AND is_used = 0 AND expire_at > NOW()
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $otp_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$otp_row) {
        writeResetPasswordLog($conn, 'reset_password_failed', $user_row['user_id'], $email);
        authResponse(400, 'Invalid or expired OTP code.', 'PWD_002');
        return;
    }
    if ((int)$otp_row['attempt_count'] >= 5) {
        authResponse(429, 'Too many OTP attempts. Request a new code.', 'PWD_003');
        return;
    }
    if ($otp_row['otp_code'] !== $otp_code) {
        $stmt = $conn->prepare(
            "UPDATE " . CORE_SCHEMA . ".otp_codes SET attempt_count = attempt_count + 1 WHERE otp_id = ?"
        );
        $stmt->bind_param('s', $otp_row['otp_id']);
        $stmt->execute();
        $stmt->close();

        writeResetPasswordLog($conn, 'reset_password_failed', $user_row['user_id'], $email);
        authResponse(400, 'Invalid or expired OTP code.', 'PWD_002');
        return;
    }

    $otp_id = $otp_row['otp_id'];

    // 5. Password policy
    $policy_error = validatePasswordPolicy($new_password, $user_row['first_name'] ?? '', $user_row['last_name'] ?? '', $email);
    if ($policy_error !== null) {
        authResponse(400, $policy_error, 'PWD_004');
        return;
    }

    // 6. Confirmation match
    if ($new_password !== $new_password_confirmation) {
        authResponse(400, 'Password and confirmation do not match.', 'PWD_005');
        return;
    }

    $password_hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
    $now = date('Y-m-d H:i:s');

    // 7. Transaction
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare(
            "UPDATE " . CORE_SCHEMA . ".app_user SET password = ?, updated_at = ? WHERE user_id = ?"
        );
        $stmt->bind_param('sss', $password_hash, $now, $user_row['user_id']);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare(
            "UPDATE " . CORE_SCHEMA . ".otp_codes SET is_used = 1, used_at = ? WHERE otp_id = ?"
        );
        $stmt->bind_param('ss', $now, $otp_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();

    } catch (Exception $e) {
        $conn->rollback();
        writeResetPasswordLog($conn, 'reset_password_failed', $user_row['user_id'], $email);
        authResponse(500, 'An unexpected error occurred. Please try again.');
        return;
    }

    // 8. Audit log
    writeResetPasswordLog($conn, 'reset_password_success', $user_row['user_id'], $email);

    authResponse(200, 'Password reset successful. Please log in with your new password.');
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn   = getConn();

    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            resetPassword($conn, $input);
            break;
        default:
            authResponse(405, 'Method not allowed');
    }

} catch (Throwable $e) {
    authResponse(500, 'An unexpected error occurred. Please try again.', null,
        APP_ENV === 'development' ? ['debug' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()] : null
    );
}
