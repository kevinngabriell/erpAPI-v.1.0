<?php

require_once __DIR__ . '/sumopod.php';
require_once __DIR__ . '/audit_log.php';

const SUBSCRIPTION_PAYMENT_EXPIRES_IN_HOURS = 24;

// Clamps the day-of-month so Jan 31 + 1 month = Feb 28/29, not Mar 3.
function addBillingCycle(string $date, string $billing_cycle): string {
    $base = new DateTime($date);
    $day  = (int)$base->format('j');
    $base->modify('first day of this month');
    $base->modify($billing_cycle === 'yearly' ? '+1 year' : '+1 month');
    $base->setDate((int)$base->format('Y'), (int)$base->format('n'), min($day, (int)$base->format('t')));
    return $base->format('Y-m-d');
}

// Caller owns the transaction. Core stays the source of truth for access:
// login reads app_subscription.next_billing_date, app_company.expired_at mirrors current_period_end.
function activateSubscriptionFromPayment($conn, array $payment, string $now): string {
    $company_id    = mysqli_real_escape_string($conn, $payment['company_id']);
    $service_id    = mysqli_real_escape_string($conn, $payment['service_id']);
    $billing_cycle = $payment['billing_cycle'] === 'yearly' ? 'yearly' : 'monthly';
    $amount        = (int)$payment['amount'];
    $today         = date('Y-m-d');

    $company_result = mysqli_query($conn, "SELECT customer_id FROM " . CORE_SCHEMA . ".app_company
        WHERE company_id = '$company_id' AND app_id = '" . APP_ID . "' LIMIT 1");
    $company = $company_result ? mysqli_fetch_assoc($company_result) : null;
    if (!$company) {
        throw new Exception('Company not found for subscription activation');
    }
    $customer_id_sql = $company['customer_id'] !== null ? "'" . mysqli_real_escape_string($conn, $company['customer_id']) . "'" : 'NULL';

    $trial_support = coreTrialSupportAvailable($conn);
    $trial_select  = $trial_support ? ', is_trial' : ', 0 AS is_trial';
    $existing_result = mysqli_query($conn, "SELECT subscription_id, subscription_status, current_period_end, next_billing_date$trial_select
        FROM " . CORE_SCHEMA . ".app_subscription
        WHERE app_company_id = '$company_id' AND app_id = '" . APP_ID . "'
          AND subscription_status IN ('active', 'paused', 'pending', 'expired')
        ORDER BY created_at DESC LIMIT 1 FOR UPDATE");
    $existing = $existing_result ? mysqli_fetch_assoc($existing_result) : null;

    $period_start = $today;
    // Paying during (or after) the trial starts the paid period today; the unused trial days are not stacked.
    if ($existing && $existing['subscription_status'] === 'active' && !(int)$existing['is_trial']) {
        $current_end = $existing['current_period_end'] ?? $existing['next_billing_date'];
        if ($current_end && $current_end > $today) {
            $period_start = $current_end;
        }
    }
    $period_end = addBillingCycle($period_start, $billing_cycle);

    if ($existing) {
        $subscription_id = $existing['subscription_id'];
        mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_subscription SET
                service_id = '$service_id', billing_cycle = '$billing_cycle',
                subscription_status = 'active', payment_status = 'paid',
                current_period_start = '$period_start', current_period_end = '$period_end',
                next_billing_date = '$period_end',
                pricing_model_snapshot = 'fixed', price_snapshot = $amount" . ($trial_support ? ', is_trial = 0' : '') . "
            WHERE subscription_id = '$subscription_id'");
    } else {
        $subscription_id = generateUUID();
        mysqli_query($conn, "INSERT INTO " . CORE_SCHEMA . ".app_subscription
            (subscription_id, customer_id, app_company_id, app_id, service_id, start_date, next_billing_date,
             billing_cycle, subscription_status, payment_status, current_period_start, current_period_end,
             pricing_model_snapshot, price_snapshot, created_at)
            VALUES ('$subscription_id', $customer_id_sql, '$company_id', '" . APP_ID . "', '$service_id', '$today', '$period_end',
                    '$billing_cycle', 'active', 'paid', '$period_start', '$period_end',
                    'fixed', $amount, '$now')");
    }

    mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_company SET expired_at = '$period_end 23:59:59'
        WHERE company_id = '$company_id' AND app_id = '" . APP_ID . "'");

    // First payment of an owner sign-up: the company was created 'inactive' and the owner
    // 'waiting_payment' (see auth/register-owner.php). Only a company with no earlier subscription
    // is opened here, so a payment can never re-activate a company Movira suspended on purpose.
    if (!$existing) {
        mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_company SET status = 'active'
            WHERE company_id = '$company_id' AND app_id = '" . APP_ID . "' AND status = 'inactive'");
        mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_user SET account_status = 'verified'
            WHERE company_id = '$company_id' AND app_id = '" . APP_ID . "' AND account_status = 'waiting_payment'");
    }

    return $subscription_id;
}


// Owner sign-up with a free trial: same end state as a paid first activation (company active, owner
// verified) but the subscription is flagged is_trial and runs $trial_days. Caller owns the transaction.
function activateTrialSubscription($conn, string $company_id, array $package, int $trial_days, string $now): string {
    $company_id    = mysqli_real_escape_string($conn, $company_id);
    $service_id    = mysqli_real_escape_string($conn, $package['service_id']);
    $billing_cycle = $package['billing_cycle'] === 'yearly' ? 'yearly' : 'monthly';
    $amount        = (int)$package['service_price'];
    $today         = date('Y-m-d');
    $trial_end     = date('Y-m-d', strtotime("+{$trial_days} days"));
    $subscription_id = generateUUID();

    mysqli_query($conn, "INSERT INTO " . CORE_SCHEMA . ".app_subscription
        (subscription_id, customer_id, app_company_id, app_id, service_id, start_date, next_billing_date,
         billing_cycle, subscription_status, payment_status, current_period_start, current_period_end,
         pricing_model_snapshot, price_snapshot, is_trial, created_at)
        VALUES ('$subscription_id', NULL, '$company_id', '" . APP_ID . "', '$service_id', '$today', '$trial_end',
                '$billing_cycle', 'active', 'unpaid', '$today', '$trial_end',
                'fixed', $amount, 1, '$now')");
    mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_company SET status = 'active', expired_at = '$trial_end 23:59:59'
        WHERE company_id = '$company_id' AND app_id = '" . APP_ID . "'");
    mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_user SET account_status = 'verified'
        WHERE company_id = '$company_id' AND app_id = '" . APP_ID . "' AND account_status = 'waiting_payment'");

    return $subscription_id;
}

// True when the company's latest subscription is an unpaid trial whose last day has passed.
function isTrialLapsed($conn, string $company_id): bool {
    if (!coreTrialSupportAvailable($conn)) return false;
    $company_id = mysqli_real_escape_string($conn, $company_id);
    $result = mysqli_query($conn, "SELECT is_trial, next_billing_date FROM " . CORE_SCHEMA . ".app_subscription
        WHERE app_company_id = '$company_id' AND app_id = '" . APP_ID . "'
        ORDER BY created_at DESC LIMIT 1");
    $sub = $result ? mysqli_fetch_assoc($result) : null;
    return $sub && (int)$sub['is_trial'] === 1 && $sub['next_billing_date'] !== null && $sub['next_billing_date'] < date('Y-m-d');
}


// ── Packages (Movira app_services) ───────────────────────────────────────────
// Packages are owned by Movira. Booku-era pricing columns (list_price/tagline/features) and
// campaigns (app_service_campaign) come from movira_api migration 2026_11_16; fall back to the
// plain service_price when a core DB doesn't have them yet.

function corePricingExtensionsAvailable($conn): bool {
    static $available = null;
    if ($available === null) {
        $result = mysqli_query($conn, "SELECT
                (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '" . CORE_SCHEMA . "' AND TABLE_NAME = 'app_services' AND COLUMN_NAME = 'list_price')
              + (SELECT COUNT(*) FROM information_schema.TABLES  WHERE TABLE_SCHEMA = '" . CORE_SCHEMA . "' AND TABLE_NAME = 'app_service_campaign') AS n");
        $row = $result ? mysqli_fetch_assoc($result) : null;
        $available = $row && (int)$row['n'] === 2;
    }
    return $available;
}

// Free trial (movira_api migration 2026_11_22): app_services.trial_days is the length Movira configures
// per package, app_subscription.is_trial marks a subscription that has not been paid yet. Without the
// columns every package simply has no trial and sign-up stays pay-first.
function coreTrialSupportAvailable($conn): bool {
    static $available = null;
    if ($available === null) {
        $result = mysqli_query($conn, "SELECT
                (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '" . CORE_SCHEMA . "' AND TABLE_NAME = 'app_services' AND COLUMN_NAME = 'trial_days')
              + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '" . CORE_SCHEMA . "' AND TABLE_NAME = 'app_subscription' AND COLUMN_NAME = 'is_trial') AS n");
        $row = $result ? mysqli_fetch_assoc($result) : null;
        $available = $row && (int)$row['n'] === 2;
    }
    return $available;
}

// Same rule as Booku: a running campaign only applies when cheaper than the base price; the strike
// price is list_price when higher than the selling price, else the base price while a campaign runs.
function effectivePackagePrice(int $base, ?int $list, ?int $campaign): array {
    $price = $base;
    $list  = $list ?? 0;
    if ($campaign !== null && $campaign < $base) {
        $price = $campaign;
        if ($list <= $price) $list = $base;
    }
    return [$price, $list > $price ? $list : null];
}

function splitPackageFeatures(?string $features): array {
    if ($features === null || trim($features) === '') return [];
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $features))));
}

// Active fixed-price monthly/yearly packages for Aluria: public ones plus, when $customer_id is
// given, that customer's private offers. Packages whose effective price is 0 are not sold online.
function fetchPurchasablePackages($conn, ?string $customer_id, ?string $only_service_id = null): array {
    $has_extensions = corePricingExtensionsAvailable($conn);

    $private_sql = '';
    if ($customer_id !== null) {
        $private_sql = " OR s.customer_id = '" . mysqli_real_escape_string($conn, $customer_id) . "'";
    }
    $only_sql = $only_service_id !== null
        ? " AND s.service_id = '" . mysqli_real_escape_string($conn, $only_service_id) . "'"
        : '';

    $extension_columns = $has_extensions
        ? ", s.list_price, s.tagline, s.features,
           (SELECT MIN(c.campaign_price) FROM " . CORE_SCHEMA . ".app_service_campaign c
             WHERE c.service_id = s.service_id AND c.is_active = 1 AND c.starts_at <= NOW()
               AND (c.ends_at IS NULL OR c.ends_at > NOW())) AS campaign_price"
        : ", NULL AS list_price, NULL AS tagline, NULL AS features, NULL AS campaign_price";

    $trial_column = coreTrialSupportAvailable($conn) ? ', s.trial_days' : ', NULL AS trial_days';

    $result = mysqli_query($conn, "SELECT s.service_id, s.service_name, s.service_description, s.service_price,
            s.billing_cycle, s.customer_id $trial_column $extension_columns
        FROM " . CORE_SCHEMA . ".app_services s
        WHERE s.app_id = '" . APP_ID . "' AND s.is_active = 1 AND s.pricing_model = 'fixed'
          AND s.billing_cycle IN ('monthly', 'yearly') AND (s.customer_id IS NULL$private_sql) $only_sql
        ORDER BY s.sort_order ASC, s.service_price ASC");

    $packages = [];
    while ($row = $result ? mysqli_fetch_assoc($result) : null) {
        [$price, $strike] = effectivePackagePrice(
            (int)$row['service_price'],
            $row['list_price'] !== null ? (int)$row['list_price'] : null,
            $row['campaign_price'] !== null ? (int)$row['campaign_price'] : null
        );
        if ($price <= 0) continue;
        $packages[] = [
            'service_id'          => $row['service_id'],
            'service_name'        => $row['service_name'],
            'service_description' => $row['service_description'],
            'service_price'       => $price,
            'list_price'          => $strike,
            'billing_cycle'       => $row['billing_cycle'],
            'tagline'             => $row['tagline'],
            'features'            => splitPackageFeatures($row['features']),
            'is_private'          => $row['customer_id'] !== null,
            'trial_days'          => max(0, min(90, (int)($row['trial_days'] ?? 0))),
        ];
    }
    return $packages;
}

// ── Checkout ─────────────────────────────────────────────────────────────────

function expireStaleSubscriptionPayments($conn, $company_id) {
    $now = date('Y-m-d H:i:s');
    mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".subscription_payment SET status = 'expired', updated_at = '$now'
        WHERE company_id = '$company_id' AND status = 'pending' AND expires_at IS NOT NULL AND expires_at < '$now'");
}

// Creates the pending subscription_payment row, then the SumoPod QRIS link.
// $package comes from fetchPurchasablePackages(), so the amount is Movira's current effective price.
// Returns ['code' => int, 'message' => string, 'data' => array]; the caller renders it.
function startSubscriptionPayment($conn, string $company_id, array $package, ?string $username): array {
    $company_id       = mysqli_real_escape_string($conn, $company_id);
    $service_id       = mysqli_real_escape_string($conn, $package['service_id']);
    $amount           = (int)$package['service_price'];
    $billing_cycle    = $package['billing_cycle'] === 'yearly' ? 'yearly' : 'monthly';
    $username_escaped = mysqli_real_escape_string($conn, (string)$username);

    expireStaleSubscriptionPayments($conn, $company_id);

    $now        = date('Y-m-d H:i:s');
    $payment_id = 'spy_' . uniqid();
    $order_id   = 'ALU-SUB-' . strtoupper(uniqid());

    $conn->begin_transaction();
    try {
        $dup_result = mysqli_query($conn, "SELECT id AS payment_id, order_id, payment_link_url, expires_at
            FROM " . APP_SCHEMA . ".subscription_payment
            WHERE company_id = '$company_id' AND status = 'pending' LIMIT 1 FOR UPDATE");
        $dup = $dup_result ? mysqli_fetch_assoc($dup_result) : null;
        if ($dup) {
            $conn->rollback();
            return ['code' => 409, 'message' => 'A payment is already pending for this company', 'data' => $dup];
        }

        mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".subscription_payment
            (id, company_id, service_id, order_id, amount, billing_cycle, status, created_by, created_at, updated_at)
            VALUES ('$payment_id', '$company_id', '$service_id', '$order_id', $amount, '$billing_cycle', 'pending', '$username_escaped', '$now', '$now')");

        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        if ($e->getCode() === 1062) {
            return ['code' => 409, 'message' => 'A payment is already pending for this company', 'data' => []];
        }
        throw $e;
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    $gateway_payload = [
        'order_id'                 => $order_id,
        'amount'                   => $amount,
        'currency'                 => 'IDR',
        'expires_in_hours'         => SUBSCRIPTION_PAYMENT_EXPIRES_IN_HOURS,
        'payment_method_type_code' => 'QRIS',
    ];
    if (SUMOPOD_SUCCESS_URL !== '') $gateway_payload['success_return_url'] = SUMOPOD_SUCCESS_URL;
    if (SUMOPOD_CANCEL_URL !== '')  $gateway_payload['cancel_return_url']  = SUMOPOD_CANCEL_URL;

    $gateway      = createSumoPodPayment($gateway_payload);
    $gateway_data = $gateway['data'] ?? [];

    if (!$gateway['success'] || empty($gateway_data['payment_id']) || empty($gateway_data['payment_link_url'])) {
        mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".subscription_payment SET status = 'failed', updated_at = '$now' WHERE id = '$payment_id'");
        return ['code' => 502, 'message' => 'Payment gateway is unavailable, please try again', 'data' => []];
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

    return ['code' => 201, 'message' => 'Payment created', 'data' => [
        'payment_id'       => $payment_id,
        'order_id'         => $order_id,
        'service_id'       => $package['service_id'],
        'service_name'     => $package['service_name'],
        'amount'           => $amount,
        'fee'              => $fee_sql === 'NULL' ? null : $fee_sql,
        'net_amount'       => $net_amount_sql === 'NULL' ? null : $net_amount_sql,
        'status'           => 'pending',
        'payment_link_url' => $gateway_data['payment_link_url'],
        'expires_at'       => $expires_at,
    ]];
}
