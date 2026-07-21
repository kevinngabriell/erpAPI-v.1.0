<?php

// Daily digest — WhatsApp recap of everything still pending approval, sent
// once per working day at 08:00 WIB per Aluria-Notification-Module-Spec.md
// §4.6/§4.6a. Dual-mode script following the same pattern documented in
// v2/docs/WHATSAPP_WAHA_GUIDE.md §6: CLI (cron) runs unconditionally over
// every company; HTTP requires ?debug=1 (optionally scoped to one company
// with ?company_id=) since there's no time-window restriction to fall back
// on here — this script gates itself per-company via isSendableDigestDay().
//
// Crontab line (not installed by this script — see
// v2/docs/migrations/v23_notification_schema.md's post-migration checklist):
//   0 8 * * * php /home/ubuntu/apps/erpAPI-dev/v2/notification/digest.php >> /var/log/notification_digest.log 2>&1

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/notification.php';

const DIGEST_MODULE_LABELS = [
    'sales_order'         => 'Sales',
    'purchase_order'      => 'Purchase',
    'finance_transaction' => 'Finance',
    'finance_payment'     => 'Finance',
];

function getPendingDocuments($conn, $company_id, $module) {
    switch ($module) {
        case 'sales_order':
            $sql = "SELECT so.id, so.so_display_number AS document_number, so.created_at, NULL AS approved_by_owner_id, NULL AS approved_by_treasury_id
                    FROM " . APP_SCHEMA . ".sales_order so
                    LEFT JOIN " . APP_SCHEMA . ".sales_status ss ON ss.id = so.status_id
                    WHERE so.company_id = '$company_id' AND ss.status_name = 'Draft' AND so.deleted_at IS NULL";
            break;
        case 'purchase_order':
            $sql = "SELECT po.id, po.po_display_number AS document_number, po.created_at, NULL AS approved_by_owner_id, NULL AS approved_by_treasury_id
                    FROM " . APP_SCHEMA . ".purchase_order po
                    LEFT JOIN " . APP_SCHEMA . ".purchase_status ps ON ps.id = po.status_id
                    WHERE po.company_id = '$company_id' AND ps.status_name = 'Draft' AND po.deleted_at IS NULL";
            break;
        case 'finance_transaction':
            $sql = "SELECT ft.id, COALESCE(ft.voucher_number, ft.id) AS document_number, ft.created_at, ft.approved_by_owner_id, ft.approved_by_treasury_id
                    FROM " . APP_SCHEMA . ".finance_transaction ft
                    WHERE ft.company_id = '$company_id' AND ft.transaction_status IN ('draft', 'partially_approved') AND ft.deleted_at IS NULL";
            break;
        case 'finance_payment':
            $sql = "SELECT fp.id, fp.invoice_number AS document_number, fp.created_at, fp.approved_by_owner_id, fp.approved_by_treasury_id
                    FROM " . APP_SCHEMA . ".finance_payment fp
                    WHERE fp.company_id = '$company_id' AND fp.transaction_status IN ('draft', 'partially_approved') AND fp.deleted_at IS NULL";
            break;
        default:
            return [];
    }

    $result = mysqli_query($conn, $sql);
    return $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
}

// A Finance doc already signed by one slot holder shouldn't nag that same
// person again — only the still-open slot's holder(s) get it in the digest.
function documentRecipientsFor($all_recipients, $document) {
    return array_values(array_filter($all_recipients, function ($user_id) use ($document) {
        return $user_id !== $document['approved_by_owner_id'] && $user_id !== $document['approved_by_treasury_id'];
    }));
}

// Lightweight "due within N days" totals, same tables/joins as
// v2/reports/ar-ap-report/index.php's fetchArRows()/fetchApRows() but summed
// for a digest line instead of paginated for a report screen.
function getArApDueSoon($conn, $company_id, $days = 3) {
    $ar_result = mysqli_query($conn, "SELECT MAX(fp.due_amount) - SUM(COALESCE(fp.paid_amount, 0)) AS outstanding,
            DATEDIFF(DATE_ADD(si.invoice_date, INTERVAL COALESCE(c.customer_top_days, 0) DAY), CURDATE()) AS days_until_due
        FROM " . APP_SCHEMA . ".finance_payment fp
        LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = fp.customer_id
        LEFT JOIN " . APP_SCHEMA . ".sales_invoice si ON si.invoice_display_number = fp.invoice_number AND si.company_id = fp.company_id
        WHERE fp.company_id = '$company_id' AND fp.customer_id IS NOT NULL AND fp.deleted_at IS NULL
        GROUP BY fp.invoice_number, si.invoice_date, c.customer_top_days
        HAVING outstanding > 0 AND days_until_due BETWEEN 0 AND $days");

    $ar_due = 0.0;
    while ($ar_result && ($row = mysqli_fetch_assoc($ar_result))) {
        $ar_due += (float)$row['outstanding'];
    }

    $ap_result = mysqli_query($conn, "SELECT MAX(fp.due_amount) - SUM(COALESCE(fp.paid_amount, 0)) AS outstanding,
            DATEDIFF(DATE_ADD(pi.invoice_date, INTERVAL COALESCE(pt.days, 0) DAY), CURDATE()) AS days_until_due
        FROM " . APP_SCHEMA . ".finance_payment fp
        LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = fp.supplier_id
        LEFT JOIN " . APP_SCHEMA . ".payment_term pt ON pt.id = s.supplier_term_id
        LEFT JOIN " . APP_SCHEMA . ".purchase_invoice pi ON pi.invoice_display_number = fp.invoice_number AND pi.company_id = fp.company_id
        WHERE fp.company_id = '$company_id' AND fp.supplier_id IS NOT NULL AND fp.deleted_at IS NULL
        GROUP BY fp.invoice_number, pi.invoice_date, pt.days
        HAVING outstanding > 0 AND days_until_due BETWEEN 0 AND $days");

    $ap_due = 0.0;
    while ($ap_result && ($row = mysqli_fetch_assoc($ap_result))) {
        $ap_due += (float)$row['outstanding'];
    }

    return ['ar_due' => $ar_due, 'ap_due' => $ap_due];
}

function buildDigestMessage($items, $ar_ap_summary = null) {
    $lines = ['📋 Rekap Pending Approval — ' . date('d M Y')];
    $no    = 1;

    foreach ($items as $item) {
        $age_days = max(0, (int)floor((time() - strtotime($item['created_at'])) / 86400));
        $label    = DIGEST_MODULE_LABELS[$item['module']] ?? $item['module'];
        $lines[]  = "{$no}. {$item['document_number']} ($label, {$age_days} hari) → {$item['approve_link']}";
        $no++;
    }

    if ($ar_ap_summary !== null) {
        $lines[] = '';
        $lines[] = '💰 AR jatuh tempo 3 hari ke depan: Rp ' . number_format($ar_ap_summary['ar_due'], 0, ',', '.');
        $lines[] = '💰 AP jatuh tempo 3 hari ke depan: Rp ' . number_format($ar_ap_summary['ap_due'], 0, ',', '.');
    }

    return implode("\n", $lines);
}

function runDigestForCompany($conn, $company) {
    $company_id = $company['company_id'];
    $today      = new DateTime('now');

    if (!isSendableDigestDay($conn, $company_id, $today)) {
        return ['company_id' => $company_id, 'company_name' => $company['company_name'], 'sent' => false, 'reason' => 'not a sendable digest day'];
    }

    $perUser           = [];
    $userNotificationId = [];

    foreach (array_keys(DIGEST_MODULE_LABELS) as $module) {
        $documents = getPendingDocuments($conn, $company_id, $module);
        if (empty($documents)) continue;

        $all_recipients = resolveApprovalRecipients($conn, $company_id, $module);
        if (empty($all_recipients)) continue;

        foreach ($documents as $document) {
            $recipients = in_array($module, ['finance_transaction', 'finance_payment'], true)
                ? documentRecipientsFor($all_recipients, $document)
                : $all_recipients;

            foreach ($recipients as $user_id) {
                // One notification row per recipient per day holds the whole
                // digest — every token generated for that recipient today
                // points at the same (not-yet-inserted) notification_id,
                // which is created once below after the message is built.
                if (!isset($userNotificationId[$user_id])) {
                    $userNotificationId[$user_id] = generateUUID();
                }
                $notification_id = $userNotificationId[$user_id];
                $token = generateApprovalToken($conn, $company_id, $notification_id, $module, $document['id'], $user_id, 'system_digest_cron');

                $perUser[$user_id][] = [
                    'module'          => $module,
                    'document_number' => $document['document_number'],
                    'created_at'      => $document['created_at'],
                    'approve_link'    => rtrim(APPROVAL_BASE_URL, '/') . '/approve/' . $token,
                ];
            }
        }
    }

    $sent_count = 0;
    foreach ($perUser as $user_id => $items) {
        $is_finance_holder = in_array($user_id, resolveApprovalRecipients($conn, $company_id, 'finance_transaction'), true);
        $ar_ap_summary     = $is_finance_holder ? getArApDueSoon($conn, $company_id) : null;

        $body = buildDigestMessage($items, $ar_ap_summary);
        $now  = date('Y-m-d H:i:s');

        $notification_id  = $userNotificationId[$user_id];
        $company_id_sql   = mysqli_real_escape_string($conn, $company_id);
        $user_id_sql      = mysqli_real_escape_string($conn, $user_id);
        $body_sql         = mysqli_real_escape_string($conn, $body);

        mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".notification
                (id, company_id, type, source_module, source_document_id, title, body, target_user_id, created_by, created_at)
                VALUES ('$notification_id', '$company_id_sql', 'digest_daily', 'notification', 'digest', 'Rekap Pending Approval', '$body_sql', '$user_id_sql', 'system_digest_cron', '$now')");

        $phone = getUserPhone($conn, $user_id);
        if ($phone) {
            $wa_result   = sendWhatsAppText(buildWhatsAppChatId($phone), $body);
            $wa_status   = !empty($wa_result['success']) ? 'sent' : 'failed';
            $sent_at_sql = $wa_status === 'sent' ? "'$now'" : 'NULL';
            $error_sql   = $wa_status === 'failed' ? "'" . mysqli_real_escape_string($conn, substr(json_encode($wa_result), 0, 1000)) . "'" : 'NULL';
            $phone_sql   = mysqli_real_escape_string($conn, $phone);
        } else {
            $wa_status   = 'skipped';
            $sent_at_sql = 'NULL';
            $error_sql   = "'No phone number on file'";
            $phone_sql   = 'NULL';
        }

        $delivery_id = generateUUID();
        mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".notification_delivery
                (id, notification_id, company_id, channel, recipient_user_id, recipient_phone, status, error_message, sent_at, created_by, created_at)
                VALUES ('$delivery_id', '$notification_id', '$company_id_sql', 'whatsapp', '$user_id_sql', $phone_sql, '$wa_status', $error_sql, $sent_at_sql, 'system_digest_cron', '$now')");

        if ($wa_status === 'sent') $sent_count++;
    }

    return ['company_id' => $company_id, 'company_name' => $company['company_name'], 'sent' => true, 'recipients_notified' => $sent_count];
}

// ── Entry point ──────────────────────────────────────────────────────────────

$isCli  = php_sapi_name() === 'cli';
$isDebugHttp = !$isCli && ($_GET['debug'] ?? '') === '1';

if (!$isCli && !$isDebugHttp) {
    jsonResponse(403, 'This endpoint requires ?debug=1 or CLI invocation');
    exit;
}

$conn = getConn();

$company_id_filter = $_GET['company_id'] ?? null;
$where = "app_id = '" . mysqli_real_escape_string($conn, APP_ID) . "' AND status = 'active'";
if ($company_id_filter) {
    $where .= " AND company_id = '" . mysqli_real_escape_string($conn, $company_id_filter) . "'";
}

$companies_result = mysqli_query($conn, "SELECT company_id, company_name FROM " . CORE_SCHEMA . ".app_company WHERE $where");
$companies         = $companies_result ? mysqli_fetch_all($companies_result, MYSQLI_ASSOC) : [];

$results = [];
foreach ($companies as $company) {
    $results[] = runDigestForCompany($conn, $company);
}

if ($isCli) {
    echo json_encode(['status_code' => 200, 'status_message' => 'Daily digest processed', 'results' => $results], JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    jsonResponse(200, 'Daily digest processed. Check per-company results.', ['results' => $results]);
}
