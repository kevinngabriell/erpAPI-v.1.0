<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../helpers/auth_response.php';
require_once __DIR__ . '/../helpers/rate_limit.php';
require_once __DIR__ . '/../helpers/password_policy.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

function writeRegisterLog($conn, string $action, ?string $user_id, string $username): void {
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

function registerUser($conn, $input): void {
    // 1. Required field validation
    $required = ['first_name', 'last_name', 'email', 'phone_number', 'password', 'password_confirmation', 'position_id', 'company_code', 'otp_code'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            authResponse(400, "The {$field} field is required.");
            return;
        }
    }

    // 2. Format validation
    $first_name   = strip_tags(trim($input['first_name']));
    $last_name    = strip_tags(trim($input['last_name']));
    $email        = strtolower(strip_tags(trim($input['email'])));
    $phone_number = strip_tags(trim($input['phone_number']));
    $password     = $input['password'];
    $password_confirmation = $input['password_confirmation'];
    $position_id  = strip_tags(trim($input['position_id']));
    $company_code = strtoupper(strip_tags(trim($input['company_code'])));
    $otp_code     = strip_tags(trim($input['otp_code']));
    $language     = isset($input['language']) && in_array($input['language'], ['id', 'en'], true)
                    ? $input['language']
                    : 'id';

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

    // 3. Password policy
    $policy_error = validatePasswordPolicy($password, $first_name, $last_name, $email);
    if ($policy_error !== null) {
        authResponse(400, $policy_error, 'REG_007');
        return;
    }

    // 4. Password confirmation
    if ($password !== $password_confirmation) {
        authResponse(400, 'Password and confirmation do not match.', 'REG_008');
        return;
    }

    // 5. IP rate limit — 10 per hour
    $ip = getUserIP();
    if (checkRateLimitByIP($conn, $ip, 'register', 10, 60)) {
        writeRegisterLog($conn, 'register_failed', null, $email);
        authResponse(429, 'Too many registration attempts. Please try again later.', 'RATE_003');
        return;
    }

    // 6. Email rate limit — 3 attempts per hour
    if (checkRateLimitByEmail($conn, $email, 'register', 3, 60)) {
        authResponse(429, 'Too many registration attempts. Please try again later.', 'RATE_003');
        return;
    }

    // 7. Validate company_code
    $stmt = $conn->prepare(
        "SELECT company_id, company_name, status
         FROM " . CORE_SCHEMA . ".app_company
         WHERE UPPER(company_code) = ? AND app_id = 'aluria'
         LIMIT 1"
    );
    if (!$stmt) {
        authResponse(500, 'An unexpected error occurred. Please try again.');
        return;
    }
    $stmt->bind_param('s', $company_code);
    $stmt->execute();
    $company_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$company_row) {
        writeRegisterLog($conn, 'register_failed', null, $email);
        authResponse(404, 'Company not found. Please check your company code.', 'REG_001');
        return;
    }
    if ($company_row['status'] !== 'active') {
        writeRegisterLog($conn, 'register_failed', null, $email);
        authResponse(422, 'This company account is not active.', 'REG_002');
        return;
    }

    $company_id   = $company_row['company_id'];
    $company_name = $company_row['company_name'];

    // 8. Email uniqueness within this app
    $stmt = $conn->prepare(
        "SELECT user_id FROM " . CORE_SCHEMA . ".app_user
         WHERE email = ? AND app_id = 'aluria'
         LIMIT 1"
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $dup = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($dup) {
        writeRegisterLog($conn, 'register_failed', null, $email);
        authResponse(409, 'An account with this email already exists.', 'REG_003');
        return;
    }

    // 9. Validate OTP
    $stmt = $conn->prepare(
        "SELECT otp_id, otp_code, attempt_count
         FROM " . CORE_SCHEMA . ".otp_codes
         WHERE identifier = ? AND purpose = 'register' AND is_used = 0 AND expire_at > NOW()
         LIMIT 1"
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $otp_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$otp_row) {
        writeRegisterLog($conn, 'register_failed', null, $email);
        authResponse(400, 'Invalid or expired OTP code.', 'REG_004');
        return;
    }
    if ((int)$otp_row['attempt_count'] >= 5) {
        authResponse(429, 'Too many OTP attempts. Request a new code.', 'REG_005');
        return;
    }
    if ($otp_row['otp_code'] !== $otp_code) {
        $stmt = $conn->prepare(
            "UPDATE " . CORE_SCHEMA . ".otp_codes SET attempt_count = attempt_count + 1 WHERE otp_id = ?"
        );
        if ($stmt) {
            $stmt->bind_param('s', $otp_row['otp_id']);
            $stmt->execute();
            $stmt->close();
        }
        writeRegisterLog($conn, 'register_failed', null, $email);
        authResponse(400, 'Invalid or expired OTP code.', 'REG_004');
        return;
    }

    $otp_id = $otp_row['otp_id'];

    // 10. Validate position_id and get position_name
    $stmt = $conn->prepare(
        "SELECT position_id, position_name
         FROM " . APP_SCHEMA . ".aluria_positions
         WHERE position_id = ? AND is_active = 1
         LIMIT 1"
    );
    $stmt->bind_param('s', $position_id);
    $stmt->execute();
    $position_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$position_row) {
        writeRegisterLog($conn, 'register_failed', null, $email);
        authResponse(400, 'Invalid position selected.', 'REG_006');
        return;
    }

    $position_name = $position_row['position_name'];

    // 11. Resolve default role from position
    $stmt = $conn->prepare(
        "SELECT app_role_id FROM " . APP_SCHEMA . ".aluria_position_role_defaults
         WHERE position_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $position_id);
    $stmt->execute();
    $role_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $default_role_id = $role_row['app_role_id'] ?? null;

    if (!$default_role_id) {
        $stmt = $conn->prepare(
            "SELECT app_role_id FROM " . CORE_SCHEMA . ".app_role
             WHERE app_id = 'aluria'
             ORDER BY created_at ASC
             LIMIT 1"
        );
        $stmt->execute();
        $fallback_row    = $stmt->get_result()->fetch_assoc();
        $default_role_id = $fallback_row['app_role_id'] ?? null;
        $stmt->close();
    }

    if (!$default_role_id) {
        authResponse(500, 'An unexpected error occurred. Please try again.');
        return;
    }

    // 12. Hash password
    $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // 13. Transaction
    $conn->begin_transaction();

    try {
        // Detect first user in this company for Business Owner logic
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS existing_users FROM " . CORE_SCHEMA . ".app_user
             WHERE company_id = ? AND app_id = 'aluria'"
        );
        $stmt->bind_param('s', $company_id);
        $stmt->execute();
        $count_row      = $stmt->get_result()->fetch_assoc();
        $existing_users = (int)$count_row['existing_users'];
        $stmt->close();

        if ($existing_users === 0) {
            // First user — assign Business Owner role, set immediately active
            $stmt = $conn->prepare(
                "SELECT app_role_id FROM " . CORE_SCHEMA . ".app_role
                 WHERE app_id = 'aluria' AND role_name = 'Business Owner'
                 LIMIT 1"
            );
            $stmt->execute();
            $biz_row        = $stmt->get_result()->fetch_assoc();
            $app_role_id    = $biz_row['app_role_id'] ?? $default_role_id;
            $account_status = 'verified';
            $stmt->close();
        } else {
            $app_role_id    = $default_role_id;
            $account_status = 'pending';
        }

        // Insert user
        $user_id  = 'usr_' . uniqid();
        $username = $email;
        $now      = date('Y-m-d H:i:s');

        $stmt = $conn->prepare(
            "INSERT INTO " . CORE_SCHEMA . ".app_user
             (user_id, username, password, account_status, app_id, app_role_id, company_id,
              first_name, last_name, phone_number, language, email, position_id, created_at)
             VALUES (?, ?, ?, ?, 'aluria', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'sssssssssssss',
            $user_id, $username, $password_hash, $account_status, $app_role_id, $company_id,
            $first_name, $last_name, $phone_number, $language, $email, $position_id, $now
        );
        $stmt->execute();
        $stmt->close();

        // Mark OTP as used
        $stmt = $conn->prepare(
            "UPDATE " . CORE_SCHEMA . ".otp_codes SET is_used = 1, used_at = ? WHERE otp_id = ?"
        );
        $stmt->bind_param('ss', $now, $otp_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();

    } catch (Exception $e) {
        $conn->rollback();
        writeRegisterLog($conn, 'register_failed', null, $email);
        authResponse(500, 'An unexpected error occurred. Please try again.');
        return;
    }

    // 14. Audit log
    writeRegisterLog($conn, 'register_success', $user_id, $email);

    authResponse(201, 'Registration successful. Your account is pending approval by your company administrator.', null, [
        'user_id'        => $user_id,
        'email'          => $email,
        'first_name'     => $first_name,
        'last_name'      => $last_name,
        'position_id'    => $position_id,
        'position_name'  => $position_name,
        'account_status' => $account_status,
        'company_name'   => $company_name,
    ]);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn   = getConn();

    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            registerUser($conn, $input);
            break;
        default:
            authResponse(405, 'Method not allowed');
    }

} catch (Exception $e) {
    authResponse(500, 'An unexpected error occurred. Please try again.');
}
