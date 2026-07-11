<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/report_dates.php';

function getAllGeneralLedgerAccounts($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    [$date_from, $date_to] = resolveReportDateRange($params);
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to   = mysqli_real_escape_string($conn, $date_to);

    $where = "ac.company_id = '$company_id' AND ac.deleted_at IS NULL";

    $result = mysqli_query($conn, "SELECT ac.id AS account_code_id, ac.account_code, ac.account_code_name, ac.account_type,
            COALESCE(SUM(CASE WHEN ft.transaction_date < '$date_from' THEN ft.account_amount ELSE 0 END), 0) AS opening_balance,
            COALESCE(SUM(CASE WHEN ft.transaction_date BETWEEN '$date_from' AND '$date_to' THEN ft.account_amount ELSE 0 END), 0) AS period_movement
        FROM " . APP_SCHEMA . ".account_code ac
        LEFT JOIN " . APP_SCHEMA . ".finance_transaction ft
               ON ft.account_code_id = ac.id AND ft.deleted_at IS NULL AND ft.transaction_date <= '$date_to'
        WHERE $where
        GROUP BY ac.id, ac.account_code, ac.account_code_name, ac.account_type
        ORDER BY ac.account_code ASC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".account_code ac WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($data as &$row) {
        $row['opening_balance']  = (float)$row['opening_balance'];
        $row['period_movement']  = (float)$row['period_movement'];
        $row['closing_balance']  = $row['opening_balance'] + $row['period_movement'];
    }

    jsonResponse(200, 'General ledger found', [
        'date_from'  => $date_from,
        'date_to'    => $date_to,
        'data'       => $data,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

function getDetailGeneralLedgerAccount($conn, $account_code_id, $company_id, $params) {
    $account_code_id = mysqli_real_escape_string($conn, $account_code_id);

    $account_result = mysqli_query($conn, "SELECT id AS account_code_id, account_code, account_code_name, account_type
        FROM " . APP_SCHEMA . ".account_code
        WHERE id = '$account_code_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (!$account_result || mysqli_num_rows($account_result) === 0) {
        jsonResponse(404, 'Account code not found');
        return;
    }
    $account = mysqli_fetch_assoc($account_result);

    [$date_from, $date_to] = resolveReportDateRange($params);
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to   = mysqli_real_escape_string($conn, $date_to);

    $opening_result = mysqli_query($conn, "SELECT COALESCE(SUM(account_amount), 0) AS opening_balance
        FROM " . APP_SCHEMA . ".finance_transaction
        WHERE account_code_id = '$account_code_id' AND company_id = '$company_id' AND deleted_at IS NULL
          AND transaction_date < '$date_from'");
    $opening_balance = (float)mysqli_fetch_assoc($opening_result)['opening_balance'];

    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = "account_code_id = '$account_code_id' AND company_id = '$company_id' AND deleted_at IS NULL
              AND transaction_date BETWEEN '$date_from' AND '$date_to'";

    $result = mysqli_query($conn, "SELECT id, transaction_date, voucher_number, memo, account_amount
        FROM " . APP_SCHEMA . ".finance_transaction
        WHERE $where
        ORDER BY transaction_date ASC, created_at ASC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".finance_transaction WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    $running_balance = $opening_balance;
    $transactions    = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($transactions as &$row) {
        $row['account_amount']  = (float)$row['account_amount'];
        $running_balance       += $row['account_amount'];
        $row['running_balance'] = $running_balance;
    }

    jsonResponse(200, 'General ledger account found', array_merge($account, [
        'date_from'       => $date_from,
        'date_to'         => $date_to,
        'opening_balance' => $opening_balance,
        'transactions'    => $transactions,
        'closing_balance' => $running_balance,
        'pagination'      => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]));
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

$account_code_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($account_code_id) {
        getDetailGeneralLedgerAccount($conn, $account_code_id, $company_id, $_GET);
    } else {
        getAllGeneralLedgerAccounts($conn, $company_id, $_GET);
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
