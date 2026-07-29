<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function agingBucket($days) {
    if ($days <= 0)  return 'current';
    if ($days <= 30) return '30';
    if ($days <= 60) return '60';
    if ($days <= 90) return '90';
    return '90+';
}

function fetchArRows($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT
            fp.invoice_number, c.customer_name AS partner_name, c.id AS partner_id, si.invoice_date,
            MAX(fp.due_amount) - SUM(COALESCE(fp.paid_amount, 0)) AS outstanding,
            DATEDIFF(CURDATE(), DATE_ADD(si.invoice_date, INTERVAL COALESCE(c.customer_top_days, 0) DAY)) AS days_overdue
        FROM " . APP_SCHEMA . ".finance_payment fp
        LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = fp.customer_id
        LEFT JOIN " . APP_SCHEMA . ".sales_invoice si ON si.invoice_display_number = fp.invoice_number AND si.company_id = fp.company_id
        WHERE fp.company_id = '$company_id' AND fp.customer_id IS NOT NULL AND fp.deleted_at IS NULL
        GROUP BY fp.invoice_number, c.customer_name, c.id, si.invoice_date, c.customer_top_days
        HAVING outstanding > 0");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) {
        $row['type']          = 'ar';
        $row['outstanding']   = (float)$row['outstanding'];
        $row['days_overdue']  = (int)$row['days_overdue'];
        $row['bucket']        = agingBucket($row['days_overdue']);
    }
    return $rows;
}

function fetchApRows($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT
            fp.invoice_number, s.supplier_name AS partner_name, s.id AS partner_id, pi.invoice_date,
            MAX(fp.due_amount) - SUM(COALESCE(fp.paid_amount, 0)) AS outstanding,
            DATEDIFF(CURDATE(), DATE_ADD(pi.invoice_date, INTERVAL COALESCE(pt.days, 0) DAY)) AS days_overdue
        FROM " . APP_SCHEMA . ".finance_payment fp
        LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = fp.supplier_id
        LEFT JOIN " . APP_SCHEMA . ".payment_term pt ON pt.id = s.supplier_term_id
        LEFT JOIN " . APP_SCHEMA . ".purchase_invoice pi ON pi.invoice_display_number = fp.invoice_number AND pi.company_id = fp.company_id
        WHERE fp.company_id = '$company_id' AND fp.supplier_id IS NOT NULL AND fp.deleted_at IS NULL
        GROUP BY fp.invoice_number, s.supplier_name, s.id, pi.invoice_date, pt.days
        HAVING outstanding > 0");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) {
        $row['type']          = 'ap';
        $row['outstanding']   = (float)$row['outstanding'];
        $row['days_overdue']  = (int)$row['days_overdue'];
        $row['bucket']        = agingBucket($row['days_overdue']);
    }
    return $rows;
}

function getArApReport($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $type   = in_array($params['type'] ?? 'all', ['ar', 'ap', 'all'], true) ? $params['type'] : 'all';
    $bucket = in_array($params['bucket'] ?? '', ['current', '30', '60', '90', '90+'], true) ? $params['bucket'] : '';
    $search = isset($params['search']) ? mb_strtolower(trim($params['search'])) : '';

    $ar_rows = fetchArRows($conn, $company_id);
    $ap_rows = fetchApRows($conn, $company_id);

    $rows = [];
    if ($type === 'ar' || $type === 'all') $rows = array_merge($rows, $ar_rows);
    if ($type === 'ap' || $type === 'all') $rows = array_merge($rows, $ap_rows);

    if ($bucket !== '') {
        $rows = array_values(array_filter($rows, fn($row) => $row['bucket'] === $bucket));
    }
    if ($search !== '') {
        $rows = array_values(array_filter($rows, fn($row) =>
            str_contains(mb_strtolower($row['partner_name'] ?? ''), $search) ||
            str_contains(mb_strtolower($row['invoice_number'] ?? ''), $search)
        ));
    }

    usort($rows, fn($a, $b) => $b['days_overdue'] <=> $a['days_overdue']);

    $total       = count($rows);
    $total_pages = (int)ceil($total / $limit);
    $data        = array_slice($rows, ($page - 1) * $limit, $limit);

    $receivables = ['current' => 0, '30' => 0, '60' => 0, '90' => 0, '90+' => 0];
    $payables    = $receivables;
    foreach ($ar_rows as $row) $receivables[$row['bucket']] += $row['outstanding'];
    foreach ($ap_rows as $row) $payables[$row['bucket']]    += $row['outstanding'];

    jsonResponse(200, 'AR/AP report found', [
        'data'       => $data,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => $total_pages,
        ],
        'summary' => [
            'receivables' => $receivables,
            'payables'    => $payables,
        ],
    ]);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}
if ($method !== 'GET') {
    jsonResponse(405, 'Method Not Allowed');
    exit;
}

try {
    $conn = getConn();
    getArApReport($conn, $company_id, $_GET);
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
