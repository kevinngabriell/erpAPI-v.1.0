<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/permission.php';
require_once __DIR__ . '/../helpers/audit_log.php';
require_once __DIR__ . '/../helpers/sumopod.php';

const SUBSCRIPTION_MANAGE_PERMISSION = 'subscription.manage';
const SUBSCRIPTION_PAYMENT_EXPIRES_IN_HOURS = 24;

function expireStaleSubscriptionPayments($conn, $company_id) {
    $now = date('Y-m-d H:i:s');
    mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".subscription_payment SET status = 'expired', updated_at = '$now'
        WHERE company_id = '$company_id' AND status = 'pending' AND expires_at IS NOT NULL AND expires_at < '$now'");
}

function getAllPackages($conn, $company_id) {
    $company_result = mysqli_query($conn, "SELECT customer_id FROM " . CORE_SCHEMA . ".app_company
        WHERE company_id = '$company_id' AND app_id = '" . APP_ID . "' LIMIT 1");
    $company = $company_result ? mysqli_fetch_assoc($company_result) : null;
    if (!$company) {
        jsonResponse(404, 'Company not found');
        return;
    }

    $private_sql = '';
    if ($company['customer_id'] !== null) {
        $customer_id = mysqli_real_escape_string($conn, $company['customer_id']);
        $private_sql = " OR customer_id = '$customer_id'";
    }

    $result = mysqli_query($conn, "SELECT service_id, service_name, service_description, service_price, billing_cycle, customer_id
        FROM " . CORE_SCHEMA . ".app_services
        WHERE app_id = '" . APP_ID . "' AND is_active = 1 AND pricing_model = 'fixed'
          AND billing_cycle IN ('monthly', 'yearly') AND service_price > 0
          AND (customer_id IS NULL$private_sql)
        ORDER BY sort_order ASC, service_price ASC");

    $packages = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['service_price'] = (int)$row['service_price'];
        $row['is_private']    = $row['customer_id'] !== null;
        unset($row['customer_id']);
        $packages[] = $row;
    }

    jsonResponse(200, 'Packages found', $packages);
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
    $required = ['service_id'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $service_id = mysqli_real_escape_string($conn, trim($input['service_id']));

    $company_result = mysqli_query($conn, "SELECT customer_id FROM " . CORE_SCHEMA . ".app_company
        WHERE company_id = '$company_id' AND app_id = '" . APP_ID . "' LIMIT 1");
    $company = $company_result ? mysqli_fetch_assoc($company_result) : null;
    if (!$company) {
        jsonResponse(404, 'Company not found');
        return;
    }

    $service_result = mysqli_query($conn, "SELECT service_id, service_name, service_price, billing_cycle, pricing_model, customer_id
        FROM " . CORE_SCHEMA . ".app_services
        WHERE service_id = '$service_id' AND app_id = '" . APP_ID . "' AND is_active = 1 LIMIT 1");
    $service = $service_result ? mysqli_fetch_assoc($service_result) : null;
    if (!$service || ($service['customer_id'] !== null && $service['customer_id'] !== $company['customer_id'])) {
        jsonResponse(404, 'Package not found');
        return;
    }

    $amount = (int)$service['service_price'];
    if ($service['pricing_model'] !== 'fixed' || !in_array($service['billing_cycle'], ['monthly', 'yearly'], true) || $amount <= 0) {
        jsonResponse(400, 'This package cannot be purchased online');
        return;
    }

    expireStaleSubscriptionPayments($conn, $company_id);

    $now                 = date('Y-m-d H:i:s');
    $payment_id          = 'spy_' . uniqid();
    $order_id            = 'ALU-SUB-' . strtoupper(uniqid());
    $billing_cycle       = $service['billing_cycle'];
    $username_escaped    = mysqli_real_escape_string($conn, (string)$username);

    $conn->begin_transaction();
    try {
        $dup_result = mysqli_query($conn, "SELECT id AS payment_id, order_id, payment_link_url, expires_at
            FROM " . APP_SCHEMA . ".subscription_payment
            WHERE company_id = '$company_id' AND status = 'pending' LIMIT 1 FOR UPDATE");
        $dup = $dup_result ? mysqli_fetch_assoc($dup_result) : null;
        if ($dup) {
            $conn->rollback();
            jsonResponse(409, 'A payment is already pending for this company', $dup);
            return;
        }

        mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".subscription_payment
            (id, company_id, service_id, order_id, amount, billing_cycle, status, created_by, created_at, updated_at)
            VALUES ('$payment_id', '$company_id', '$service_id', '$order_id', $amount, '$billing_cycle', 'pending', '$username_escaped', '$now', '$now')");

        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        if ($e->getCode() === 1062) {
            jsonResponse(409, 'A payment is already pending for this company');
            return;
        }
        throw $e;
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    $gateway_payload = [
        'order_id'                  => $order_id,
        'amount'                    => $amount,
        'currency'                  => 'IDR',
        'expires_in_hours'          => SUBSCRIPTION_PAYMENT_EXPIRES_IN_HOURS,
        'payment_method_type_code'  => 'QRIS',
    ];
    if (SUMOPOD_SUCCESS_URL !== '') $gateway_payload['success_return_url'] = SUMOPOD_SUCCESS_URL;
    if (SUMOPOD_CANCEL_URL !== '')  $gateway_payload['cancel_return_url']  = SUMOPOD_CANCEL_URL;

    $gateway = createSumoPodPayment($gateway_payload);
    $gateway_data = $gateway['data'] ?? [];

    if (!$gateway['success'] || empty($gateway_data['payment_id']) || empty($gateway_data['payment_link_url'])) {
        mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".subscription_payment SET status = 'failed', updated_at = '$now' WHERE id = '$payment_id'");
        jsonResponse(502, 'Payment gateway is unavailable, please try again');
        return;
    }

    $gateway_payment_id = mysqli_real_escape_string($conn, $gateway_data['payment_id']);
    $payment_link_url   = mysqli_real_escape_string($conn, $gateway_data['payment_link_url']);
    $fee_sql            = isset($gateway_data['fee']) ? (int)$gateway_data['fee'] : 'NULL';
    $net_amount_sql     = isset($gateway_data['net_amount']) ? (int)$gateway_data['net_amount'] : 'NULL';
    $expires_at         = !empty($gateway_data['expires_at']) && strtotime($gateway_data['expires_at']) !== false
        ? date('Y-m-d H:i:s', strtotime($gateway_data['expires_at']))
        : date('Y-m-d H:i:s', time() + SUBSCRIPTION_PAYMENT_EXPIRES_IN_HOURS * 3600);

    mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".subscription_payment SET
            gateway_payment_id = '$gateway_payment_id', payment_link_url = '$payment_link_url',
            fee = $fee_sql, net_amount = $net_amount_sql, expires_at = '$expires_at', updated_at = '$now'
        WHERE id = '$payment_id'");

    insertAuditLog($conn, $company_id, 'subscription', $payment_id, 'checkout_created', $username, "order_id=$order_id service_id=$service_id amount=$amount");

    jsonResponse(201, 'Payment created', [
        'payment_id'       => $payment_id,
        'order_id'         => $order_id,
        'service_id'       => $service['service_id'],
        'service_name'     => $service['service_name'],
        'amount'           => $amount,
        'fee'              => $fee_sql === 'NULL' ? null : $fee_sql,
        'net_amount'       => $net_amount_sql === 'NULL' ? null : $net_amount_sql,
        'status'           => 'pending',
        'payment_link_url' => $gateway_data['payment_link_url'],
        'expires_at'       => $expires_at,
    ]);
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
