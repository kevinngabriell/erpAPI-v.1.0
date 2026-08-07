<?php

require_once __DIR__ . '/whatsapp.php';

const NOTIFICATION_ACTIONABLE_TYPES = ['approval_pending', 'approval_approved', 'approval_rejected', 'low_stock_alert'];

function resolveDisplayName($conn, $user_id) {
    if (!$user_id) return null;

    $user_id = mysqli_real_escape_string($conn, $user_id);
    $result  = mysqli_query($conn, "SELECT CONCAT(first_name, ' ', last_name) AS display_name
            FROM " . CORE_SCHEMA . ".app_user WHERE user_id COLLATE utf8mb4_general_ci = '$user_id' LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? $row['display_name'] : null;
}

function getUserPhone($conn, $user_id) {
    $user_id = mysqli_real_escape_string($conn, $user_id);
    $result  = mysqli_query($conn, "SELECT phone_number FROM " . CORE_SCHEMA . ".app_user WHERE user_id COLLATE utf8mb4_general_ci = '$user_id' LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row && !empty($row['phone_number']) ? $row['phone_number'] : null;
}

// Who to notify for a given module's approval_pending event — driven entirely
// by permission_key, never role name, matching the pattern getPermittedDashboardKeys()
// uses in v2/dashboard/index.php. Finance reuses the existing dual-approval
// keys instead of a separate config table.
function resolveApprovalRecipients($conn, $company_id, $source_module) {
    $permission_keys = match ($source_module) {
        'sales_order'      => ['notification.sales_order.approver'],
        'purchase_order'   => ['notification.purchase_order.approver'],
        'purchase_invoice' => ['notification.purchase_invoice.approver'],
        'purchase_receive' => ['notification.purchase_receive.approver'],
        'finance_transaction', 'finance_payment' => ['keuangan.approve_owner', 'keuangan.approve_treasury'],
        default => [],
    };

    if (empty($permission_keys)) return [];

    $company_id_sql = mysqli_real_escape_string($conn, $company_id);
    $keys_sql       = implode(',', array_map(fn($k) => "'" . mysqli_real_escape_string($conn, $k) . "'", $permission_keys));

    $result = mysqli_query($conn, "SELECT DISTINCT au.user_id
            FROM " . CORE_SCHEMA . ".app_user au
            JOIN " . CORE_SCHEMA . ".app_role_permission arp ON arp.app_role_id = au.app_role_id
            JOIN " . CORE_SCHEMA . ".app_permission ap ON ap.permission_id = arp.permission_id
            WHERE au.company_id = '$company_id_sql' AND au.account_status = 'verified' AND ap.permission_key IN ($keys_sql)");

    return $result ? array_column(mysqli_fetch_all($result, MYSQLI_ASSOC), 'user_id') : [];
}

// Who to notify for a low-stock alert — everyone in the company holding
// warehouse.low_stock.view, same permission-key-driven pattern as
// resolveApprovalRecipients() above (never role name).
function resolveLowStockRecipients($conn, $company_id) {
    $company_id_sql = mysqli_real_escape_string($conn, $company_id);

    $result = mysqli_query($conn, "SELECT DISTINCT au.user_id
            FROM " . CORE_SCHEMA . ".app_user au
            JOIN " . CORE_SCHEMA . ".app_role_permission arp ON arp.app_role_id = au.app_role_id
            JOIN " . CORE_SCHEMA . ".app_permission ap ON ap.permission_id = arp.permission_id
            WHERE au.company_id = '$company_id_sql' AND au.account_status = 'verified' AND ap.permission_key = 'warehouse.low_stock.view'");

    return $result ? array_column(mysqli_fetch_all($result, MYSQLI_ASSOC), 'user_id') : [];
}

function getCompanySetting($conn, $company_id, $key, $default = null) {
    $company_id_sql = mysqli_real_escape_string($conn, $company_id);
    $key_sql        = mysqli_real_escape_string($conn, $key);

    $result = mysqli_query($conn, "SELECT setting_value FROM " . APP_SCHEMA . ".company_setting
            WHERE company_id = '$company_id_sql' AND setting_key = '$key_sql' LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? $row['setting_value'] : $default;
}

function setCompanySetting($conn, $company_id, $key, $value, $username) {
    $id             = generateUUID();
    $now            = date('Y-m-d H:i:s');
    $company_id_sql = mysqli_real_escape_string($conn, $company_id);
    $key_sql        = mysqli_real_escape_string($conn, $key);
    $value_sql      = mysqli_real_escape_string($conn, $value);
    $username_sql   = mysqli_real_escape_string($conn, $username);

    mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".company_setting
            (id, company_id, setting_key, setting_value, created_by, created_at)
            VALUES ('$id', '$company_id_sql', '$key_sql', '$value_sql', '$username_sql', '$now')
            ON DUPLICATE KEY UPDATE setting_value = '$value_sql', updated_by = '$username_sql', updated_at = '$now'");
}

function isSendableDigestDay($conn, $company_id, DateTime $date) {
    $day_codes  = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];
    $today_code = $day_codes[(int)$date->format('N') - 1];

    $working_days = json_decode(
        getCompanySetting($conn, $company_id, 'notification.working_days', '["MON","TUE","WED","THU","FRI"]'),
        true
    ) ?: ['MON', 'TUE', 'WED', 'THU', 'FRI'];

    if (!in_array($today_code, $working_days, true)) return false;

    $company_id_sql = mysqli_real_escape_string($conn, $company_id);
    $date_sql       = $date->format('Y-m-d');

    $result = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".public_holiday
            WHERE holiday_date = '$date_sql' AND (company_id = '$company_id_sql' OR company_id IS NULL) AND deleted_at IS NULL LIMIT 1");

    return !($result && mysqli_num_rows($result) > 0);
}

function getUnreadNotificationCount($conn, $company_id, $user_id) {
    $company_id_sql = mysqli_real_escape_string($conn, $company_id);
    $user_id_sql    = mysqli_real_escape_string($conn, $user_id);

    $result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".notification
            WHERE company_id = '$company_id_sql' AND target_user_id = '$user_id_sql' AND read_at IS NULL AND deleted_at IS NULL");
    return $result ? (int)mysqli_fetch_assoc($result)['total'] : 0;
}

// Best-effort push to the WebSocket daemon (v2/notification/ws-server.php).
// Fire-and-forget over a short-lived loopback socket — a down/unreachable
// daemon must never fail the caller's main request.
function pushWebSocketEvent($user_id, $event_name, $payload) {
    $message = json_encode(['user_id' => $user_id, 'event' => $event_name, 'payload' => $payload]) . "\n";

    $socket = @stream_socket_client('tcp://127.0.0.1:' . WS_PUBLISH_PORT, $errno, $errstr, 1);
    if (!$socket) {
        error_log("WS push failed ($event_name for $user_id): $errstr");
        return false;
    }

    stream_set_timeout($socket, 1);
    fwrite($socket, $message);
    fclose($socket);
    return true;
}

// One-click approve/reject link — single-use, scoped to 1 user + 1 document,
// expires in 72h. See v2/docs/migrations/v23_notification_schema.md.
function generateApprovalToken($conn, $company_id, $notification_id, $source_module, $source_document_id, $target_user_id, $username) {
    $token_id             = generateUUID();
    $now                  = date('Y-m-d H:i:s');
    $expires_at           = date('Y-m-d H:i:s', strtotime('+72 hours'));
    $company_id_sql       = mysqli_real_escape_string($conn, $company_id);
    $notification_id_sql  = mysqli_real_escape_string($conn, $notification_id);
    $source_module_sql    = mysqli_real_escape_string($conn, $source_module);
    $source_document_sql  = mysqli_real_escape_string($conn, $source_document_id);
    $target_user_id_sql   = mysqli_real_escape_string($conn, $target_user_id);
    $username_sql         = mysqli_real_escape_string($conn, $username);

    mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".approval_action_token
            (id, company_id, notification_id, source_module, source_document_id, target_user_id, expires_at, created_by, created_at)
            VALUES ('$token_id', '$company_id_sql', '$notification_id_sql', '$source_module_sql', '$source_document_sql', '$target_user_id_sql', '$expires_at', '$username_sql', '$now')");

    return $token_id;
}

// Called once a document reaches a terminal state (approved/rejected/fully
// approved) so any still-open tokens for other recipients stop working.
function invalidateApprovalTokens($conn, $source_module, $source_document_id, $except_token_id = null) {
    $source_module_sql   = mysqli_real_escape_string($conn, $source_module);
    $source_document_sql = mysqli_real_escape_string($conn, $source_document_id);
    $now                 = date('Y-m-d H:i:s');
    $except_sql          = $except_token_id ? " AND id != '" . mysqli_real_escape_string($conn, $except_token_id) . "'" : '';

    mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".approval_action_token
            SET used_at = '$now'
            WHERE source_module = '$source_module_sql' AND source_document_id = '$source_document_sql' AND used_at IS NULL$except_sql");
}

// Central dispatcher — see v2/docs/migrations/v23_notification_schema.md and
// Aluria-Notification-Module-Spec.md §4.1. $event:
//   company_id, type, source_module, source_document_id, title, body,
//   created_by (the user who triggered the event), recipients (user_id[])
// WhatsApp sends happen synchronously (short 10s-timeout call reused from
// v2/helpers/whatsapp.php), same precedent as auth/send-otp.php and
// auth/forgot-password.php — never a hard failure for the caller.
function notify($conn, array $event) {
    try {
        $company_id         = $event['company_id'];
        $type                = $event['type'];
        $source_module       = $event['source_module'];
        $source_document_id  = $event['source_document_id'];
        $title               = $event['title'];
        $body                = $event['body'];
        $username            = $event['created_by'];
        $recipients          = array_values(array_unique(array_filter($event['recipients'] ?? [])));

        $company_id_sql = mysqli_real_escape_string($conn, $company_id);
        $type_sql       = mysqli_real_escape_string($conn, $type);
        $module_sql     = mysqli_real_escape_string($conn, $source_module);
        $doc_id_sql     = mysqli_real_escape_string($conn, $source_document_id);
        $title_sql      = mysqli_real_escape_string($conn, $title);
        $body_sql       = mysqli_real_escape_string($conn, $body);
        $username_sql   = mysqli_real_escape_string($conn, $username);

        foreach ($recipients as $recipient_user_id) {
            $notification_id  = generateUUID();
            $now               = date('Y-m-d H:i:s');
            $recipient_sql     = mysqli_real_escape_string($conn, $recipient_user_id);

            mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".notification
                    (id, company_id, type, source_module, source_document_id, title, body, target_user_id, created_by, created_at)
                    VALUES ('$notification_id', '$company_id_sql', '$type_sql', '$module_sql', '$doc_id_sql', '$title_sql', '$body_sql', '$recipient_sql', '$username_sql', '$now')");

            $in_app_delivery_id = generateUUID();
            mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".notification_delivery
                    (id, notification_id, company_id, channel, recipient_user_id, status, sent_at, created_by, created_at)
                    VALUES ('$in_app_delivery_id', '$notification_id', '$company_id_sql', 'in_app', '$recipient_sql', 'sent', '$now', '$username_sql', '$now')");

            pushWebSocketEvent($recipient_user_id, 'notification:new', [
                'id'                 => $notification_id,
                'type'               => $type,
                'source_module'      => $source_module,
                'source_document_id' => $source_document_id,
                'title'              => $title,
                'body'               => $body,
                'created_at'         => $now,
            ]);
            pushWebSocketEvent($recipient_user_id, 'notification:unread_count', [
                'unread_count' => getUnreadNotificationCount($conn, $company_id, $recipient_user_id),
            ]);

            if (!in_array($type, NOTIFICATION_ACTIONABLE_TYPES, true)) {
                continue;
            }

            $wa_delivery_id = generateUUID();
            $phone          = getUserPhone($conn, $recipient_user_id);

            if (!$phone) {
                mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".notification_delivery
                        (id, notification_id, company_id, channel, recipient_user_id, status, error_message, created_by, created_at)
                        VALUES ('$wa_delivery_id', '$notification_id', '$company_id_sql', 'whatsapp', '$recipient_sql', 'skipped', 'No phone number on file', '$username_sql', '$now')");
                continue;
            }

            $recipient_name = resolveDisplayName($conn, $recipient_user_id);
            $greeting       = 'Halo Bapak/Ibu' . ($recipient_name ? " $recipient_name" : '') . ",\n\n";
            $wa_text        = $greeting . $body;
            if ($type === 'approval_pending') {
                $token = generateApprovalToken($conn, $company_id, $notification_id, $source_module, $source_document_id, $recipient_user_id, $username);
                $wa_text .= "\n\n" . rtrim(APPROVAL_BASE_URL, '/') . '/approve/' . $token;
            }

            $wa_result   = sendWhatsAppText(buildWhatsAppChatId($phone), $wa_text);
            $wa_status   = !empty($wa_result['success']) ? 'sent' : 'failed';
            $sent_at_sql = $wa_status === 'sent' ? "'$now'" : 'NULL';
            $error_sql   = $wa_status === 'failed' ? "'" . mysqli_real_escape_string($conn, substr(json_encode($wa_result), 0, 1000)) . "'" : 'NULL';
            $phone_sql   = mysqli_real_escape_string($conn, $phone);

            mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".notification_delivery
                    (id, notification_id, company_id, channel, recipient_user_id, recipient_phone, status, error_message, sent_at, created_by, created_at)
                    VALUES ('$wa_delivery_id', '$notification_id', '$company_id_sql', 'whatsapp', '$recipient_sql', '$phone_sql', '$wa_status', $error_sql, $sent_at_sql, '$username_sql', '$now')");
        }
    } catch (Throwable $e) {
        error_log('notify() failed: ' . $e->getMessage());
    }
}

// Called after a warehouse_transaction posts, once per unique (product_id,
// location_id) it touched. alerted_at is the de-dup flag: fires once when
// stock crosses below min_stock, stays quiet on further dips until stock
// recovers at/above threshold and clears it — otherwise every subsequent
// stock_out while already below threshold would re-notify.
function checkReorderPointAndNotify($conn, $company_id, $product_id, $location_id, $username) {
    try {
        $company_id_sql  = mysqli_real_escape_string($conn, $company_id);
        $product_id_sql  = mysqli_real_escape_string($conn, $product_id);
        $location_id_sql = mysqli_real_escape_string($conn, $location_id);

        $rp_result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".reorder_point
                WHERE company_id = '$company_id_sql' AND product_id = '$product_id_sql' AND location_id = '$location_id_sql' AND deleted_at IS NULL LIMIT 1");
        $reorder_point = $rp_result ? mysqli_fetch_assoc($rp_result) : null;
        if (!$reorder_point) return;

        $stock_result = mysqli_query($conn, "SELECT COALESCE(SUM(end_balance), 0) AS current_stock FROM " . APP_SCHEMA . ".warehouse_lot
                WHERE product_id = '$product_id_sql' AND location_id = '$location_id_sql' AND deleted_at IS NULL");
        $current_stock = (float)mysqli_fetch_assoc($stock_result)['current_stock'];
        $min_stock     = (float)$reorder_point['min_stock'];
        $now           = date('Y-m-d H:i:s');

        if ($current_stock < $min_stock && $reorder_point['alerted_at'] === null) {
            $product_result  = mysqli_query($conn, "SELECT product_name FROM " . APP_SCHEMA . ".product WHERE id = '$product_id_sql' LIMIT 1");
            $product_name    = $product_result ? (mysqli_fetch_assoc($product_result)['product_name'] ?? $product_id) : $product_id;
            $location_result = mysqli_query($conn, "SELECT location_name FROM " . APP_SCHEMA . ".warehouse_location WHERE id = '$location_id_sql' LIMIT 1");
            $location_name   = $location_result ? (mysqli_fetch_assoc($location_result)['location_name'] ?? $location_id) : $location_id;

            notify($conn, [
                'company_id'         => $company_id,
                'type'               => 'low_stock_alert',
                'source_module'      => 'reorder_point',
                'source_document_id' => $reorder_point['id'],
                'title'              => 'Peringatan Stok Menipis',
                'body'               => "Stok produk *$product_name* di lokasi *$location_name* sudah menipis. Sisa stok saat ini: $current_stock (batas minimum: $min_stock).",
                'created_by'         => $username,
                'recipients'         => resolveLowStockRecipients($conn, $company_id),
            ]);

            mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".reorder_point SET alerted_at = '$now' WHERE id = '" . $reorder_point['id'] . "'");

        } elseif ($current_stock >= $min_stock && $reorder_point['alerted_at'] !== null) {
            mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".reorder_point SET alerted_at = NULL WHERE id = '" . $reorder_point['id'] . "'");
        }
    } catch (Throwable $e) {
        error_log('checkReorderPointAndNotify() failed: ' . $e->getMessage());
    }
}
