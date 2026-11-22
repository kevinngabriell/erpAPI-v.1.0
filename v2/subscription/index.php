<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/permission.php';
require_once __DIR__ . '/../helpers/audit_log.php';
require_once __DIR__ . '/../helpers/sumopod.php';
require_once __DIR__ . '/../helpers/subscription.php';

const SUBSCRIPTION_MANAGE_PERMISSION = 'subscription.manage';

function getAllPackages($conn, $company_id) {
    $company_result = mysqli_query($conn, "SELECT customer_id FROM " . CORE_SCHEMA . ".app_company
        WHERE company_id = '$company_id' AND app_id = '" . APP_ID . "' LIMIT 1");
    $company = $company_result ? mysqli_fetch_assoc($company_result) : null;
    if (!$company) {
        jsonResponse(404, 'Company not found');
        return;
    }

    jsonResponse(200, 'Packages found', fetchPurchasablePackages($conn, $company['customer_id']));
}

function getDetailSubscription($conn, $company_id) {
    expireStaleSubscriptionPayments($conn, $company_id);

    $result = mysqli_query($conn, "SELECT s.subscription_id, s.service_id, sv.service_name, s.billing_cycle,
            s.subscription_status, s.payment_status, s.start_date, s.current_period_start, s.current_period_end,
            s.next_billing_date, s.price_snapshot,
            GREATEST(COALESCE(DATEDIFF(s.next_billing_date, NOW()), 0), 0) AS days_remaining
        FROM " . CORE_SCHEMA . ".app_subscription s
        LEFT JOIN " . CORE_SCHEMA . ".app_services sv ON sv.service_id = s.service_id
        WHERE s.app_company_id = '$company_id' AND s.app_id = '" . APP_ID . "'
        ORDER BY s.created_at DESC LIMIT 1");
    $subscription = $result ? mysqli_fetch_assoc($result) : null;

    if ($subscription) {
        $subscription['price_snapshot'] = $subscription['price_snapshot'] !== null ? (int)$subscription['price_snapshot'] : null;
        $subscription['days_remaining'] = (int)$subscription['days_remaining'];
    }

    $payment_result = mysqli_query($conn, "SELECT id AS payment_id, order_id, service_id, amount, status, payment_link_url, expires_at, paid_at, created_at
        FROM " . APP_SCHEMA . ".subscription_payment
        WHERE company_id = '$company_id' ORDER BY created_at DESC LIMIT 1");
    $latest_payment = $payment_result ? mysqli_fetch_assoc($payment_result) : null;
    if ($latest_payment) {
        $latest_payment['amount'] = (int)$latest_payment['amount'];
        if ($latest_payment['status'] !== 'pending') {
            $latest_payment['payment_link_url'] = null;
        }
    }

    jsonResponse(200, 'Subscription found', [
        'subscription'   => $subscription,
        'latest_payment' => $latest_payment,
    ]);
}

function createSubscriptionPayment($conn, $input, $username, $company_id) {
    if (!isset($input['service_id']) || trim($input['service_id']) === '') {
        jsonResponse(400, 'service_id is required');
        return;
    }

    $company_result = mysqli_query($conn, "SELECT customer_id FROM " . CORE_SCHEMA . ".app_company
        WHERE company_id = '$company_id' AND app_id = '" . APP_ID . "' LIMIT 1");
    $company = $company_result ? mysqli_fetch_assoc($company_result) : null;
    if (!$company) {
        jsonResponse(404, 'Company not found');
        return;
    }

    // Price comes from Movira's current package (campaigns included), never from the client.
    $packages = fetchPurchasablePackages($conn, $company['customer_id'], trim($input['service_id']));
    if (!$packages) {
        jsonResponse(404, 'Package not found or cannot be purchased online');
        return;
    }

    $result = startSubscriptionPayment($conn, $company_id, $packages[0], $username);
    jsonResponse($result['code'], $result['message'], $result['data']);
}

function getDetailSubscriptionPayment($conn, $payment_id, $company_id) {
    expireStaleSubscriptionPayments($conn, $company_id);

    $payment_id = mysqli_real_escape_string($conn, $payment_id);
    $result = mysqli_query($conn, "SELECT id AS payment_id, order_id, service_id, subscription_id, amount, fee, net_amount,
            billing_cycle, status, payment_link_url, expires_at, paid_at, activated_at, created_at
        FROM " . APP_SCHEMA . ".subscription_payment
        WHERE id = '$payment_id' AND company_id = '$company_id' LIMIT 1");
    $payment = $result ? mysqli_fetch_assoc($result) : null;

    if (!$payment) {
        jsonResponse(404, 'Payment not found');
        return;
    }

    foreach (['amount', 'fee', 'net_amount'] as $col) {
        $payment[$col] = $payment[$col] !== null ? (int)$payment[$col] : null;
    }
    $payment['is_activated'] = $payment['activated_at'] !== null;
    if ($payment['status'] !== 'pending') {
        $payment['payment_link_url'] = null;
    }

    jsonResponse(200, 'Payment found', $payment);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser    = requireAuth();
$method      = $_SERVER['REQUEST_METHOD'];
$company_id  = $authUser['company_id'] ?? null;
$username    = $authUser['sub'] ?? $authUser['user_id'] ?? null;
$app_role_id = $authUser['app_role_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

$payment_id = $parts[4] ?? null;

try {
    $conn = getConn();
    $company_id = mysqli_real_escape_string($conn, $company_id);
    $username = mysqli_real_escape_string($conn, $username);

    if ($action === 'packages' && $method === 'GET') {
        getAllPackages($conn, $company_id);
    } elseif ($action === 'current' && $method === 'GET') {
        getDetailSubscription($conn, $company_id);
    } elseif ($action === 'checkout' && $method === 'POST') {
        if (!userHasPermission($conn, $app_role_id, SUBSCRIPTION_MANAGE_PERMISSION)) {
            jsonResponse(403, 'You do not have permission to manage the subscription');
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        createSubscriptionPayment($conn, $input, $username, $company_id);
    } elseif ($action === 'payments' && $payment_id && $method === 'GET') {
        getDetailSubscriptionPayment($conn, $payment_id, $company_id);
    } else {
        jsonResponse(404, 'Route not found');
    }

} catch (Exception $e) {
    handleModuleException($e);
}
