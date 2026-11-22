<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../helpers/auth_response.php';
require_once __DIR__ . '/../helpers/rate_limit.php';
require_once __DIR__ . '/../helpers/password_policy.php';
require_once __DIR__ . '/../helpers/auth_log.php';
require_once __DIR__ . '/../helpers/subscription.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// Owner self-sign-up, pay first:
//   1. verify OTP (same /account/send-otp flow as employee registration)
//   2. create the company 'inactive' and its owner 'waiting_payment' (login answers AUTH_003 until paid)
//   3. start a SumoPod QRIS payment for the chosen Movira package
//   4. the payment.completed webhook (subscription/webhook.php) activates subscription + company + owner
// Nothing here grants access; only a verified payment does.

function generateCompanyCode($conn, string $business_name): string {
    $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $business_name));
    $base = str_pad(substr($base, 0, 6), 3, 'X');

    for ($i = 0; $i < 10; $i++) {
        $code = $base . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
        $stmt = $conn->prepare("SELECT 1 FROM " . CORE_SCHEMA . ".app_company WHERE company_code = ? LIMIT 1");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $taken = $stmt->get_result()->fetch_row();
        $stmt->close();
        if (!$taken) return $code;
    }
    throw new Exception('Could not generate a unique company code');
}

function registerOwner($conn, $input): void {
    $required = ['business_name', 'first_name', 'last_name', 'email', 'phone_number', 'password', 'password_confirmation', 'otp_code', 'service_id'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            authResponse(400, "The {$field} field is required.");
            return;
        }
    }

    $business_name = trim(strip_tags($input['business_name']));
    $first_name    = strip_tags(trim($input['first_name']));
    $last_name     = strip_tags(trim($input['last_name']));
    $email         = strtolower(strip_tags(trim($input['email'])));
    $phone_number  = strip_tags(trim($input['phone_number']));
    $password      = $input['password'];
    $otp_code      = strip_tags(trim($input['otp_code']));
    $service_id    = trim((string)$input['service_id']);
    $language      = isset($input['language']) && in_array($input['language'], ['id', 'en'], true) ? $input['language'] : 'id';

    if (mb_strlen($business_name) < 2 || mb_strlen($business_name) > 100) {
        authResponse(400, 'The business_name field must be 2-100 characters.');
        return;
    }
    if (!preg_match('/^[a-zA-Z\s\-]{2,100}$/', $first_name)) {
        authResponse(400, 'The first_name field must be 2-100 characters and contain letters, spaces, or hyphens only.');
        return;
    }
    if (!preg_match('/^[a-zA-Z\s\-]{2,100}$/', $last_name)) {
        authResponse(400, 'The last_name field must be 2-100 characters and contain letters, spaces, or hyphens only.');
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 50) {
        authResponse(400, 'The email field must be a valid email address (max 50 characters).');
        return;
    }
    if (!preg_match('/^(\+62|08)[0-9]{8,13}$/', $phone_number)) {
        authResponse(400, 'The phone_number field must be a valid Indonesian number starting with 08 or +62.');
        return;
    }
    if (!preg_match('/^[0-9]{6}$/', $otp_code)) {
        authResponse(400, 'The otp_code field must be a 6-digit numeric code.');
        return;
    }

    $policy_error = validatePasswordPolicy($password, $first_name, $last_name, $email);
    if ($policy_error !== null) {
        authResponse(400, $policy_error, 'REG_007');
        return;
    }
    if ($password !== $input['password_confirmation']) {
        authResponse(400, 'Password and confirmation do not match.', 'REG_008');
        return;
    }

    $ip = getUserIP();
    if (checkRateLimitByIP($conn, $ip, 'register', 10, 60) || checkRateLimitByEmail($conn, $email, 'register', 3, 60)) {
        writeAuthLog($conn, 'register_failed', null, $email);
        authResponse(429, 'Too many registration attempts. Please try again later.', 'RATE_003');
        return;
    }

    // Only public packages can be bought at sign-up (there is no customer to hold a private offer yet).
    $packages = fetchPurchasablePackages($conn, null, $service_id);
    if (!$packages) {
        authResponse(404, 'Package not found or cannot be purchased online.', 'REG_010');
        return;
    }
    $package = $packages[0];

    $stmt = $conn->prepare("SELECT user_id FROM " . CORE_SCHEMA . ".app_user WHERE email = ? AND app_id = '" . APP_ID . "' LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $dup = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($dup) {
        writeAuthLog($conn, 'register_failed', null, $email);
        authResponse(409, 'An account with this email already exists.', 'REG_003');
        return;
    }

    // OTP
    $stmt = $conn->prepare(
        "SELECT otp_id, otp_hash, attempt_count FROM " . CORE_SCHEMA . ".otp_codes
         WHERE identifier = ? AND purpose = 'register' AND used_at IS NULL AND expire_at > NOW() LIMIT 1"
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $otp_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$otp_row) {
        writeAuthLog($conn, 'register_failed', null, $email);
        authResponse(400, 'Invalid or expired OTP code.', 'REG_004');
        return;
    }
    if ((int)$otp_row['attempt_count'] >= 5) {
        authResponse(429, 'Too many OTP attempts. Request a new code.', 'REG_005');
        return;
    }
    if ($otp_row['otp_hash'] !== hash_hmac('sha256', $otp_code, OTP_PEPPER)) {
        $stmt = $conn->prepare("UPDATE " . CORE_SCHEMA . ".otp_codes SET attempt_count = attempt_count + 1 WHERE otp_id = ?");
        if ($stmt) {
            $stmt->bind_param('s', $otp_row['otp_id']);
            $stmt->execute();
            $stmt->close();
        }
        writeAuthLog($conn, 'register_failed', null, $email);
        authResponse(400, 'Invalid or expired OTP code.', 'REG_004');
        return;
    }
    $otp_id = $otp_row['otp_id'];

    $stmt = $conn->prepare(
        "SELECT app_role_id FROM " . CORE_SCHEMA . ".app_role WHERE app_id = '" . APP_ID . "' AND role_name = 'Business Owner' LIMIT 1"
    );
    $stmt->execute();
    $owner_role = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$owner_role) {
        error_log('register-owner: Business Owner role is missing for APP_ID ' . APP_ID);
        authResponse(500, 'An unexpected error occurred. Please try again.');
        return;
    }

    $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $now           = date('Y-m-d H:i:s');
    $company_id    = generateUUID();
    $user_id       = 'usr_' . uniqid();

    $conn->begin_transaction();
    try {
        $company_code = generateCompanyCode($conn, $business_name);

        $stmt = $conn->prepare(
            "INSERT INTO " . CORE_SCHEMA . ".app_company (company_id, company_code, company_name, app_id, status, created_at)
             VALUES (?, ?, ?, '" . APP_ID . "', 'inactive', ?)"
        );
        $stmt->bind_param('ssss', $company_id, $company_code, $business_name, $now);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare(
            "INSERT INTO " . CORE_SCHEMA . ".app_user
             (user_id, username, password, account_status, app_id, app_role_id, company_id,
              first_name, last_name, phone_number, language, email, created_at)
             VALUES (?, ?, ?, 'waiting_payment', '" . APP_ID . "', ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'sssssssssss',
            $user_id, $email, $password_hash, $owner_role['app_role_id'], $company_id,
            $first_name, $last_name, $phone_number, $language, $email, $now
        );
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE " . CORE_SCHEMA . ".otp_codes SET used_at = ? WHERE otp_id = ?");
        $stmt->bind_param('ss', $now, $otp_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log('register-owner failed: ' . $e->getMessage());
        writeAuthLog($conn, 'register_failed', null, $email);
        authResponse(500, 'An unexpected error occurred. Please try again.');
        return;
    }

    writeAuthLog($conn, 'register_success', $user_id, $email);

    // Payment is created after the account commits: if the gateway is down the owner keeps the
    // account and retries from the login screen (POST /subscription/owner-checkout).
    $payment = null;
    try {
        $result = startSubscriptionPayment($conn, $company_id, $package, $email);
        if ($result['code'] === 201) $payment = $result['data'];
    } catch (Exception $e) {
        error_log('register-owner payment start failed: ' . $e->getMessage());
    }

    authResponse(201, $payment
        ? 'Registration successful. Complete the payment to activate your account.'
        : 'Registration successful, but the payment link could not be created. Sign in to retry the payment.', null, [
        'user_id'        => $user_id,
        'email'          => $email,
        'company_name'   => $business_name,
        'company_code'   => $company_code,
        'account_status' => 'waiting_payment',
        'package'        => ['service_id' => $package['service_id'], 'service_name' => $package['service_name'], 'amount' => $package['service_price']],
        'payment'        => $payment,
    ]);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn   = getConn();

    validateJsonContentType();

    if ($method !== 'POST') {
        methodNotAllowed(['POST']);
    }
    registerOwner($conn, json_decode(file_get_contents('php://input'), true) ?? []);

} catch (Exception $e) {
    authResponse(500, 'An unexpected error occurred. Please try again. Reference ID: ' . requestId(), null,
        safeErrorResponse($e)
    );
}
