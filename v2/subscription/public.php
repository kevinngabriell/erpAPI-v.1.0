<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../helpers/auth_response.php';
require_once __DIR__ . '/../helpers/rate_limit.php';
require_once __DIR__ . '/../helpers/auth_log.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/subscription.php';

// Default: what can be bought online (sign-up). ?catalog=1 is the landing-page price list, which also
// shows one-time plans (billing_cycle = 'one_time') that are sold through sales.
function listPublicPlans($conn): void {
    $catalog = ($_GET['catalog'] ?? '') === '1';
    jsonResponse(200, 'Packages found', fetchPurchasablePackages($conn, null, null, $catalog));
}

function ownerCheckout($conn, array $input): void {
    $email    = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');
    if ($email === '' || $password === '') {
        authResponse(400, 'email and password are required.');
        return;
    }

    $ip = getUserIP();
    if (checkRateLimitByIP($conn, $ip, 'owner_checkout_failed', 10, 15) || checkRateLimitByEmail($conn, $email, 'owner_checkout_failed', 5, 15)) {
        authResponse(429, 'Too many attempts. Please try again in 15 minutes.', 'RATE_001');
        return;
    }

    $stmt = $conn->prepare(
        "SELECT u.user_id, u.password, u.account_status, u.company_id, r.role_name
         FROM " . CORE_SCHEMA . ".app_user u
         LEFT JOIN " . CORE_SCHEMA . ".app_role r ON r.app_role_id = u.app_role_id
         WHERE u.email = ? AND u.app_id = '" . APP_ID . "' LIMIT 1"
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Same answer for unknown email and wrong password (no account enumeration).
    if (!$user || !password_verify($password, $user['password'])) {
        writeAuthLog($conn, 'owner_checkout_failed', $user['user_id'] ?? null, $email);
        authResponse(401, 'Invalid email or password', 'AUTH_001');
        return;
    }
    // Two cases may pay from the login screen: a sign-up that never paid (waiting_payment), and an
    // owner whose free trial has ended (login answers AUTH_007 until the first payment lands).
    $trial_lapsed = $user['account_status'] === 'verified'
        && $user['role_name'] === 'Business Owner'
        && isTrialLapsed($conn, $user['company_id']);
    if ($user['account_status'] !== 'waiting_payment' && !$trial_lapsed) {
        authResponse(409, 'This account does not have a payment pending.', 'PAY_001');
        return;
    }

    $company_id = mysqli_real_escape_string($conn, $user['company_id']);
    expireStaleSubscriptionPayments($conn, $company_id);

    // Still-valid pending link: hand it back instead of failing with 409.
    $result = mysqli_query($conn, "SELECT p.id AS payment_id, p.order_id, p.service_id, p.amount, p.status, p.payment_link_url, p.expires_at
        FROM " . APP_SCHEMA . ".subscription_payment p
        WHERE p.company_id = '$company_id' AND p.status = 'pending' AND p.payment_link_url IS NOT NULL LIMIT 1");
    $pending = $result ? mysqli_fetch_assoc($result) : null;
    if ($pending) {
        $pending['amount'] = (int)$pending['amount'];
        authResponse(200, 'Payment pending', null, $pending);
        return;
    }

    // Otherwise a new payment for the package chosen at sign-up (or the one sent now).
    $service_id = trim((string)($input['service_id'] ?? ''));
    if ($service_id === '') {
        $result = mysqli_query($conn, "SELECT service_id FROM " . APP_SCHEMA . ".subscription_payment
            WHERE company_id = '$company_id' ORDER BY created_at DESC LIMIT 1");
        $last = $result ? mysqli_fetch_assoc($result) : null;
        $service_id = $last['service_id'] ?? '';
    }
    if ($service_id === '') {
        // Trial owners never had a payment row: use the package they signed up for.
        $result = mysqli_query($conn, "SELECT service_id FROM " . CORE_SCHEMA . ".app_subscription
            WHERE app_company_id = '$company_id' AND app_id = '" . APP_ID . "' ORDER BY created_at DESC LIMIT 1");
        $last = $result ? mysqli_fetch_assoc($result) : null;
        $service_id = $last['service_id'] ?? '';
    }
    $packages = $service_id !== '' ? fetchPurchasablePackages($conn, null, $service_id) : [];
    if (!$packages) {
        authResponse(404, 'Package not found or cannot be purchased online. Choose a package.', 'REG_010');
        return;
    }

    $result = startSubscriptionPayment($conn, $user['company_id'], $packages[0], $email);
    authResponse($result['code'], $result['message'], null, $result['data']);
}

try {
    $conn   = getConn();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($action === 'plans' && $method === 'GET') {
        listPublicPlans($conn);
    } elseif ($action === 'owner-checkout' && $method === 'POST') {
        validateJsonContentType();
        ownerCheckout($conn, json_decode(file_get_contents('php://input'), true) ?? []);
    } else {
        jsonResponse(404, 'Route not found');
    }
} catch (Exception $e) {
    handleModuleException($e);
}
