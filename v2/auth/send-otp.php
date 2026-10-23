<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../helpers/auth_response.php';
require_once __DIR__ . '/../helpers/rate_limit.php';
require_once __DIR__ . '/../helpers/whatsapp.php';
require_once __DIR__ . '/../helpers/auth_log.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

function requestRegisterOtp($conn, $input): void {
    // 1. Required field validation
    foreach (['email', 'phone_number'] as $field) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            authResponse(400, "The {$field} field is required.");
            return;
        }
    }

    $email        = strtolower(strip_tags(trim($input['email'])));
    $phone_number = strip_tags(trim($input['phone_number']));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 50) {
        authResponse(400, 'The email field must be a valid email address (max 50 characters).');
        return;
    }
    if (!preg_match('/^(\+62|08)[0-9]{8,13}$/', $phone_number)) {
        authResponse(400, 'The phone_number field must be a valid Indonesian number starting with 08 or +62.');
        return;
    }

    // 2. IP rate limit — 10 per hour
    $ip = getUserIP();
    if (checkRateLimitByIP($conn, $ip, 'send_otp', 10, 60)) {
        authResponse(429, 'Too many requests. Please try again later.', 'RATE_003');
        return;
    }

    // 3. Email rate limit — 3 per hour
    if (checkRateLimitByEmail($conn, $email, 'send_otp', 3, 60)) {
        authResponse(429, 'Too many requests. Please try again later.', 'RATE_003');
        return;
    }

    // 4. Reject if this email is already a registered account
    $stmt = $conn->prepare(
        "SELECT user_id FROM " . CORE_SCHEMA . ".app_user
         WHERE email = ? AND app_id = '" . APP_ID . "'
         LIMIT 1"
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $dup = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($dup) {
        writeAuthLog($conn, 'send_otp_failed', null, $email);
        authResponse(409, 'An account with this email already exists.', 'REG_003');
        return;
    }

    $now = date('Y-m-d H:i:s');

    // 5. Invalidate any previously issued, still-unused register codes for this email
    $stmt = $conn->prepare(
        "UPDATE " . CORE_SCHEMA . ".otp_codes SET used_at = ?
         WHERE identifier = ? AND purpose = 'register' AND used_at IS NULL"
    );
    $stmt->bind_param('ss', $now, $email);
    $stmt->execute();
    $stmt->close();

    // 6. Generate and store a new OTP
    $otp_id    = 'otp_' . uniqid();
    $otp_code  = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otp_hash  = hash_hmac('sha256', $otp_code, OTP_PEPPER);
    $expire_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $stmt = $conn->prepare(
        "INSERT INTO " . CORE_SCHEMA . ".otp_codes (otp_id, identifier, otp_hash, purpose, expire_at, ip_address, created_at)
         VALUES (?, ?, ?, 'register', ?, ?, ?)"
    );
    $stmt->bind_param('ssssss', $otp_id, $email, $otp_hash, $expire_at, $ip, $now);
    $stmt->execute();
    $stmt->close();

    // 7. Send the OTP via WhatsApp — delivery failure never fails the request
    $chat_id   = buildWhatsAppChatId($phone_number);
    $wa_result = sendWhatsAppText(
        $chat_id,
        "Your Aluria registration code is: {$otp_code}\n\nThis code expires in 10 minutes. If you didn't request this, you can ignore this message."
    );
    if (!($wa_result['success'] ?? false)) {
        error_log('Send-otp WhatsApp send failed for ' . $email . ': ' . json_encode($wa_result));
    }

    // 8. Audit log
    writeAuthLog($conn, 'send_otp_requested', null, $email);

    authResponse(200, 'A WhatsApp message with your registration code has been sent to the phone number provided.');
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn   = getConn();

    validateJsonContentType();

    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            requestRegisterOtp($conn, $input);
            break;
        default:
            methodNotAllowed(['POST']);
    }

} catch (Exception $e) {
    authResponse(500, 'An unexpected error occurred. Please try again. Reference ID: ' . requestId(), null,
        safeErrorResponse($e)
    );
}
