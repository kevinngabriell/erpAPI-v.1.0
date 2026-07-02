<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../helpers/auth_response.php';
require_once __DIR__ . '/../helpers/rate_limit.php';
require_once __DIR__ . '/../helpers/whatsapp.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

function writeForgotPasswordLog($conn, string $action, ?string $user_id, string $username): void {
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

function requestPasswordReset($conn, $input): void {
    // 1. Required field validation
    if (!isset($input['email']) || trim((string)$input['email']) === '') {
        authResponse(400, 'The email field is required.');
        return;
    }

    $email = strtolower(strip_tags(trim($input['email'])));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 50) {
        authResponse(400, 'The email field must be a valid email address (max 50 characters).');
        return;
    }

    // 2. IP rate limit — 10 per hour
    $ip = getUserIP();
    if (checkRateLimitByIP($conn, $ip, 'forgot_password', 10, 60)) {
        authResponse(429, 'Too many requests. Please try again later.', 'RATE_003');
        return;
    }

    // 3. Email rate limit — 3 per hour
    if (checkRateLimitByEmail($conn, $email, 'forgot_password', 3, 60)) {
        authResponse(429, 'Too many requests. Please try again later.', 'RATE_003');
        return;
    }

    // Anti-enumeration: this response is returned whether or not the email exists
    $generic_message = 'If this email is registered, a WhatsApp message with your reset code has been sent to the phone number on file.';

    // 4. Look up user
    $stmt = $conn->prepare(
        "SELECT user_id, phone_number
         FROM " . CORE_SCHEMA . ".app_user
         WHERE email = ? AND app_id = 'aluria'
         LIMIT 1"
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user_row || empty($user_row['phone_number'])) {
        writeForgotPasswordLog($conn, 'forgot_password_requested', null, $email);
        authResponse(200, $generic_message);
        return;
    }

    $now = date('Y-m-d H:i:s');

    // 5. Invalidate any previously issued, still-unused codes for this email
    $stmt = $conn->prepare(
        "UPDATE " . CORE_SCHEMA . ".otp_codes SET is_used = 1, used_at = ?
         WHERE identifier = ? AND purpose = 'forgot_password' AND is_used = 0"
    );
    $stmt->bind_param('ss', $now, $email);
    $stmt->execute();
    $stmt->close();

    // 6. Generate and store a new OTP
    $otp_id    = 'otp_' . uniqid();
    $otp_code  = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expire_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $stmt = $conn->prepare(
        "INSERT INTO " . CORE_SCHEMA . ".otp_codes (otp_id, identifier, otp_code, purpose, expire_at, is_used, attempt_count, created_at)
         VALUES (?, ?, ?, 'forgot_password', ?, 0, 0, ?)"
    );
    $stmt->bind_param('sssss', $otp_id, $email, $otp_code, $expire_at, $now);
    $stmt->execute();
    $stmt->close();

    // 7. Send the OTP via WhatsApp — delivery failure never fails the request or leaks status to the caller
    $chat_id   = buildWhatsAppChatId($user_row['phone_number']);
    $wa_result = sendWhatsAppText(
        $chat_id,
        "Your Aluria password reset code is: {$otp_code}\n\nThis code expires in 10 minutes. If you didn't request this, you can ignore this message."
    );
    if (!($wa_result['success'] ?? false)) {
        error_log('Forgot-password WhatsApp send failed for ' . $email . ': ' . json_encode($wa_result));
    }

    // 8. Audit log
    writeForgotPasswordLog($conn, 'forgot_password_requested', $user_row['user_id'], $email);

    authResponse(200, $generic_message);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn   = getConn();

    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            requestPasswordReset($conn, $input);
            break;
        default:
            authResponse(405, 'Method not allowed');
    }

} catch (Throwable $e) {
    authResponse(500, 'An unexpected error occurred. Please try again.', null,
        APP_ENV === 'development' ? ['debug' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()] : null
    );
}
