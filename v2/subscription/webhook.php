<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/audit_log.php';
require_once __DIR__ . '/../helpers/sumopod.php';
require_once __DIR__ . '/../helpers/subscription.php';

function handleSumoPodWebhook($conn, $event_id, $event) {
    $event_type = $event['event_type'] ?? '';
    $data       = $event['data'] ?? [];
    $order_id   = isset($data['order_id']) ? mysqli_real_escape_string($conn, (string)$data['order_id']) : '';
    $now        = date('Y-m-d H:i:s');

    if ($event_type === 'payment.test') {
        jsonResponse(200, 'Test event received');
        return;
    }

    if (!in_array($event_type, ['payment.completed', 'payment.failed', 'payment.expired'], true) || $order_id === '') {
        jsonResponse(200, 'Event ignored');
        return;
    }

    $conn->begin_transaction();
    try {
        $event_id_escaped = mysqli_real_escape_string($conn, $event_id);
        $event_type_escaped = mysqli_real_escape_string($conn, $event_type);

        // Svix retries reuse svix-id, so a duplicate PK means this delivery was already handled.
        $dedupe = mysqli_query($conn, "INSERT IGNORE INTO " . APP_SCHEMA . ".payment_webhook_event
            (id, event_type, order_id, received_at) VALUES ('$event_id_escaped', '$event_type_escaped', '$order_id', '$now')");
        if (mysqli_affected_rows($conn) === 0) {
            $conn->rollback();
            jsonResponse(200, 'Event already processed');
            return;
        }

        $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".subscription_payment
            WHERE order_id = '$order_id' LIMIT 1 FOR UPDATE");
        $payment = $result ? mysqli_fetch_assoc($result) : null;

        if (!$payment) {
            $conn->commit();
            jsonResponse(200, 'Unknown order, ignored');
            return;
        }

        if ($payment['status'] !== 'pending') {
            $conn->commit();
            jsonResponse(200, 'Payment already ' . $payment['status']);
            return;
        }

        $payment_id = $payment['id'];

        if ($event_type === 'payment.failed' || $event_type === 'payment.expired') {
            $new_status = $event_type === 'payment.failed' ? 'failed' : 'expired';
            mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".subscription_payment SET status = '$new_status', updated_at = '$now' WHERE id = '$payment_id'");
            insertAuditLog($conn, $payment['company_id'], 'subscription', $payment_id, 'payment_' . $new_status, 'sumopod', "order_id=$order_id");
            $conn->commit();
            jsonResponse(200, 'Payment marked ' . $new_status);
            return;
        }

        // Never activate on an amount we didn't ask for — an underpaid link must not buy a full period.
        if ((int)($data['amount'] ?? -1) !== (int)$payment['amount']) {
            error_log("SumoPod webhook amount mismatch for $order_id: expected {$payment['amount']}, got " . ($data['amount'] ?? 'null'));
            $conn->rollback();
            jsonResponse(409, 'Amount mismatch');
            return;
        }

        $paid_at_ts = !empty($data['paid_at']) ? strtotime($data['paid_at']) : false;
        $paid_at    = $paid_at_ts !== false ? date('Y-m-d H:i:s', $paid_at_ts) : $now;
        $fee_sql        = isset($data['fee']) ? (int)$data['fee'] : 'NULL';
        $net_amount_sql = isset($data['net_amount']) ? (int)$data['net_amount'] : 'NULL';

        $subscription_id = activateSubscriptionFromPayment($conn, $payment, $now);

        mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".subscription_payment SET
                status = 'completed', subscription_id = '$subscription_id', fee = $fee_sql, net_amount = $net_amount_sql,
                paid_at = '$paid_at', activated_at = '$now', updated_at = '$now'
            WHERE id = '$payment_id'");
        insertAuditLog($conn, $payment['company_id'], 'subscription', $payment_id, 'payment_completed', 'sumopod', "order_id=$order_id subscription_id=$subscription_id");

        $conn->commit();
        jsonResponse(200, 'Payment completed and subscription activated');
    } catch (Exception $e) {
        $conn->rollback();
        error_log('SumoPod webhook processing failed: ' . $e->getMessage());
        jsonResponse(500, 'Webhook processing failed');
    }
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
// Public on purpose: SumoPod can't send a JWT. Authenticity comes from the Svix signature instead.

$method = $_SERVER['REQUEST_METHOD'];

try {
    $conn = getConn();

    if ($method !== 'POST') {
        methodNotAllowed(['POST']);
    }

    if (SUMOPOD_WEBHOOK_SECRET === '') {
        error_log('SumoPod webhook received but SUMOPOD_WEBHOOK_SECRET is not configured');
        jsonResponse(500, 'Webhook is not configured');
    }

    $raw_body  = file_get_contents('php://input');
    $svix_id   = $_SERVER['HTTP_SVIX_ID'] ?? '';

    $is_valid = verifySumoPodWebhookSignature(
        SUMOPOD_WEBHOOK_SECRET,
        $svix_id,
        $_SERVER['HTTP_SVIX_TIMESTAMP'] ?? '',
        $_SERVER['HTTP_SVIX_SIGNATURE'] ?? '',
        $raw_body
    );
    if (!$is_valid) {
        jsonResponse(401, 'Invalid signature');
    }

    $event = json_decode($raw_body, true);
    if (!is_array($event)) {
        jsonResponse(400, 'Invalid JSON body');
    }

    handleSumoPodWebhook($conn, $svix_id, $event);

} catch (Exception $e) {
    handleModuleException($e);
}
