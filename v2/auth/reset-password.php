<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../helpers/auth_response.php';
require_once __DIR__ . '/../helpers/rate_limit.php';
require_once __DIR__ . '/../helpers/password_policy.php';
require_once __DIR__ . '/../helpers/auth_log.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

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
        writeAuthLog($conn, 'reset_password_failed', null, $email);
        authResponse(400, 'Invalid or expired OTP code.', 'PWD_002');
        return;
    }

    // 4. Validate OTP
    $stmt = $conn->prepare(
        "SELECT otp_id, otp_hash, attempt_count
         FROM " . CORE_SCHEMA . ".otp_codes
         WHERE identifier = ? AND purpose = 'forgot_password' AND used_at IS NULL AND expire_at > NOW()
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $otp_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$otp_row) {
        writeAuthLog($conn, 'reset_password_failed', $user_row['user_id'], $email);
        authResponse(400, 'Invalid or expired OTP code.', 'PWD_002');
        return;
    }
    if ((int)$otp_row['attempt_count'] >= 5) {
        authResponse(429, 'Too many OTP attempts. Request a new code.', 'PWD_003');
        return;
    }
    if ($otp_row['otp_hash'] !== hash_hmac('sha256', $otp_code, OTP_PEPPER)) {
        $stmt = $conn->prepare(
            "UPDATE " . CORE_SCHEMA . ".otp_codes SET attempt_count = attempt_count + 1 WHERE otp_id = ?"
        );
        $stmt->bind_param('s', $otp_row['otp_id']);
        $stmt->execute();
        $stmt->close();

        writeAuthLog($conn, 'reset_password_failed', $user_row['user_id'], $email);
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
            "UPDATE " . CORE_SCHEMA . ".otp_codes SET used_at = ? WHERE otp_id = ?"
        );
        $stmt->bind_param('ss', $now, $otp_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();

    } catch (Exception $e) {
        $conn->rollback();
        writeAuthLog($conn, 'reset_password_failed', $user_row['user_id'], $email);
        authResponse(500, 'An unexpected error occurred. Please try again.');
        return;
    }

    // 8. Audit log
    writeAuthLog($conn, 'reset_password_success', $user_row['user_id'], $email);

    authResponse(200, 'Password reset successful. Please log in with your new password.');
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn   = getConn();

    validateJsonContentType();

    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            resetPassword($conn, $input);
            break;
        default:
            methodNotAllowed(['POST']);
    }

} catch (Exception $e) {
    authResponse(500, 'An unexpected error occurred. Please try again. Reference ID: ' . requestId(), null,
        safeErrorResponse($e)
    );
}
