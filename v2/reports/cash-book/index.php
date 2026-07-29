<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/report_dates.php';

function buildCashBookUnion($company_id) {
    return "
        SELECT ft.bank_account_id, ft.transaction_date, ft.created_at, ft.voucher_number AS reference_number,
               ft.cheque_number, ft.payee AS party_name, ft.memo,
               (SELECT GROUP_CONCAT(ac2.account_code_name SEPARATOR ', ')
                  FROM " . APP_SCHEMA . ".finance_transaction_detail ftd2
                  JOIN " . APP_SCHEMA . ".account_code ac2 ON ac2.id = ftd2.account_code_id
                  WHERE ftd2.finance_transaction_id = ft.id AND ftd2.deleted_at IS NULL) AS description,
               CASE WHEN fc.category_type = 'debit' THEN ft.amount ELSE -ft.amount END AS signed_amount
        FROM " . APP_SCHEMA . ".finance_transaction ft
        LEFT JOIN " . APP_SCHEMA . ".finance_category fc ON fc.id = ft.finance_category_id
        WHERE ft.company_id = '$company_id' AND ft.deleted_at IS NULL

        UNION ALL

        SELECT fp.bank_account_id, fp.payment_date AS transaction_date, fp.created_at, fp.invoice_number AS reference_number,
               fp.cheque_number, COALESCE(s.supplier_name, c.customer_name) AS party_name, fp.memo, NULL AS description,
               CASE WHEN fp.customer_id IS NOT NULL THEN fp.paid_amount ELSE -fp.paid_amount END AS signed_amount
        FROM " . APP_SCHEMA . ".finance_payment fp
        LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = fp.supplier_id
        LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = fp.customer_id
        WHERE fp.company_id = '$company_id' AND fp.deleted_at IS NULL AND fp.bank_account_id IS NOT NULL
    ";
}

function getAllCashBooks($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    [$date_from, $date_to] = resolveReportDateRange($params);
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to   = mysqli_real_escape_string($conn, $date_to);

    $where = "ba.company_id = '$company_id' AND ba.deleted_at IS NULL";
    $union = buildCashBookUnion($company_id);

    $result = mysqli_query($conn, "SELECT ba.id AS bank_account_id, ba.bank_name, ba.bank_number,
            COALESCE(SUM(CASE WHEN cb.transaction_date < '$date_from' THEN cb.signed_amount ELSE 0 END), 0) AS opening_balance,
            COALESCE(SUM(CASE WHEN cb.transaction_date BETWEEN '$date_from' AND '$date_to' THEN cb.signed_amount ELSE 0 END), 0) AS period_movement
        FROM " . APP_SCHEMA . ".bank_account ba
        LEFT JOIN ($union) cb ON cb.bank_account_id = ba.id
        WHERE $where
        GROUP BY ba.id, ba.bank_name, ba.bank_number
        ORDER BY ba.bank_name ASC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".bank_account ba WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($data as &$row) {
        $row['opening_balance'] = (float)$row['opening_balance'];
        $row['period_movement'] = (float)$row['period_movement'];
        $row['closing_balance'] = $row['opening_balance'] + $row['period_movement'];
    }

    jsonResponse(200, 'Cash books found', [
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

function getDetailCashBook($conn, $bank_account_id, $company_id, $params) {
    $bank_account_id = mysqli_real_escape_string($conn, $bank_account_id);

    $bank_result = mysqli_query($conn, "SELECT id AS bank_account_id, bank_name, bank_number
        FROM " . APP_SCHEMA . ".bank_account
        WHERE id = '$bank_account_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (!$bank_result || mysqli_num_rows($bank_result) === 0) {
        jsonResponse(404, 'Bank account not found');
        return;
    }
    $bank_account = mysqli_fetch_assoc($bank_result);

    [$date_from, $date_to] = resolveReportDateRange($params);
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to   = mysqli_real_escape_string($conn, $date_to);

    $union = buildCashBookUnion($company_id);

    $opening_result = mysqli_query($conn, "SELECT COALESCE(SUM(cb.signed_amount), 0) AS opening_balance
        FROM ($union) cb
        WHERE cb.bank_account_id = '$bank_account_id' AND cb.transaction_date < '$date_from'");
    $opening_balance = (float)mysqli_fetch_assoc($opening_result)['opening_balance'];

    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = "cb.bank_account_id = '$bank_account_id' AND cb.transaction_date BETWEEN '$date_from' AND '$date_to'";

    $result = mysqli_query($conn, "SELECT cb.transaction_date, cb.reference_number, cb.cheque_number, cb.party_name, cb.memo, cb.description, cb.signed_amount
        FROM ($union) cb
        WHERE $where
        ORDER BY cb.transaction_date ASC, cb.created_at ASC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM ($union) cb WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    $running_balance = $opening_balance;
    $transactions    = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($transactions as &$row) {
        $row['signed_amount']   = (float)$row['signed_amount'];
        $running_balance       += $row['signed_amount'];
        $row['running_balance'] = $running_balance;
    }

    jsonResponse(200, 'Cash book found', array_merge($bank_account, [
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

$bank_account_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($bank_account_id) {
        getDetailCashBook($conn, $bank_account_id, $company_id, $_GET);
    } else {
        getAllCashBooks($conn, $company_id, $_GET);
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
