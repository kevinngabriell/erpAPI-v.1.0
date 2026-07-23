<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/report_dates.php';

function fetchAccountBreakdown($conn, $company_id, $account_type, $date_from, $date_to) {
    $result = mysqli_query($conn, "SELECT ac.id AS account_code_id, ac.account_code, ac.account_code_name,
            COALESCE(SUM(ftd.amount), 0) AS total
        FROM " . APP_SCHEMA . ".account_code ac
        LEFT JOIN " . APP_SCHEMA . ".finance_transaction_detail ftd
               ON ftd.account_code_id = ac.id AND ftd.deleted_at IS NULL
        LEFT JOIN " . APP_SCHEMA . ".finance_transaction ft
               ON ft.id = ftd.finance_transaction_id AND ft.deleted_at IS NULL
               AND ft.transaction_date BETWEEN '$date_from' AND '$date_to'
        WHERE ac.company_id = '$company_id' AND ac.deleted_at IS NULL AND ac.account_type = '$account_type'
          AND (ftd.id IS NULL OR ft.id IS NOT NULL)
        GROUP BY ac.id, ac.account_code, ac.account_code_name
        HAVING total != 0
        ORDER BY ac.account_code ASC");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['total'] = (float)$row['total'];
    return $rows;
}

function getProfitLossReport($conn, $company_id, $params) {
    [$date_from, $date_to] = resolveReportDateRange($params);
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to   = mysqli_real_escape_string($conn, $date_to);

    $revenue_by_account = fetchAccountBreakdown($conn, $company_id, 'revenue', $date_from, $date_to);
    $expense_by_account = fetchAccountBreakdown($conn, $company_id, 'expense', $date_from, $date_to);

    $revenue_total = array_sum(array_column($revenue_by_account, 'total'));
    $expense_total = array_sum(array_column($expense_by_account, 'total'));

    jsonResponse(200, 'Profit and loss report found', [
        'date_from'  => $date_from,
        'date_to'    => $date_to,
        'revenue'    => ['total' => $revenue_total, 'by_account' => $revenue_by_account],
        'expense'    => ['total' => $expense_total, 'by_account' => $expense_by_account],
        'net_profit' => $revenue_total - $expense_total,
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
    getProfitLossReport($conn, $company_id, $_GET);
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
