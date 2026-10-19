<?php

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

    $existing_result = mysqli_query($conn, "SELECT subscription_id, subscription_status, current_period_end, next_billing_date
        FROM " . CORE_SCHEMA . ".app_subscription
        WHERE app_company_id = '$company_id' AND app_id = '" . APP_ID . "'
          AND subscription_status IN ('active', 'paused', 'pending', 'expired')
        ORDER BY created_at DESC LIMIT 1 FOR UPDATE");
    $existing = $existing_result ? mysqli_fetch_assoc($existing_result) : null;

    $period_start = $today;
    if ($existing && $existing['subscription_status'] === 'active') {
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
                pricing_model_snapshot = 'fixed', price_snapshot = $amount
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

    return $subscription_id;
}
