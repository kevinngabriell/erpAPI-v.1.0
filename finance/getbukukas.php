<?php
require_once('../general.php');

ini_set('display_errors', '0');
error_reporting(0);

// Connection access
require_once('../connection/connection.php');

$GLOBALS['_log_conn']       = $connect;
$GLOBALS['_log_user']       = 'guest';
$GLOBALS['_log_request_id'] = bin2hex(random_bytes(8));

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        logApiError(500, $err['message'], $err['file'], $err['line']);
        if (!headers_sent()) http_response_code(500);
        echo json_encode(['error' => 'Internal server error', 'detail' => $err['message']]);
    }
});

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $bank_account = mysqli_real_escape_string($connect, $_GET['bank_account'] ?? '');
    $start_date   = mysqli_real_escape_string($connect, $_GET['start_date'] ?? '');
    $end_date     = mysqli_real_escape_string($connect, $_GET['end_date'] ?? '');
    $accountcode  = mysqli_real_escape_string($connect, $_GET['accountcode'] ?? '');

    if (!empty($bank_account)) {
        $ba_ft = "A1.bank_account = '$bank_account'";
        $ba_fi = "A1.bank = '$bank_account'";
    } else {
        // When accountcode is provided (e.g. KAS KECIL = 101-001-000), exclude __SALDO_AWAL__
        // entries that belong to other accounts stored by setaccountbeginningbalance.php
        $saldo_exclude = !empty($accountcode)
            ? "AND NOT (A1.memo = '__SALDO_AWAL__' AND A1.accountcode != '' AND A1.accountcode != '$accountcode')"
            : "";
        $ba_ft = "(A1.bank_account IS NULL OR A1.bank_account = '') $saldo_exclude";
        $ba_fi = "(A1.bank IS NULL OR A1.bank = '')";
    }

    // Beginning balance — __SALDO_AWAL__ adjustment is included automatically in the SUM
    $beginningBalanceQuery = "SELECT SUM(amount) AS balance FROM (
        SELECT CASE
                   WHEN A1.amount < 0 THEN A1.amount
                   WHEN A1.finance_category = '174c61e8-226d-11ef-a' THEN A1.amount
                   WHEN A1.finance_category = '1d604104-226d-11ef-a' THEN -A1.amount
                   ELSE 0
               END AS amount
        FROM financeTransaction A1
        WHERE $ba_ft AND (DATE(A1.date) < '$start_date' OR (DATE(A1.date) = '$start_date' AND (A1.memo LIKE 'SALDO AWAL%' OR A1.memo = '__SALDO_AWAL__')))
        UNION ALL
        SELECT CASE
                   WHEN A1.supplier IS NOT NULL THEN -A1.paid_amount
                   WHEN A1.customer IS NOT NULL THEN A1.paid_amount
                   ELSE 0
               END AS amount
        FROM financeItem A1
        WHERE $ba_fi AND DATE(A1.paymentdate) <= '$start_date'
    ) AS balances";

    $beginningBalanceResult = mysqli_query($connect, $beginningBalanceQuery);
    if (!$beginningBalanceResult) {
        http_response_code(500);
        echo json_encode(['error' => mysqli_error($connect)]);
        exit;
    }
    $beginningBalance = (float)(mysqli_fetch_assoc($beginningBalanceResult)['balance'] ?? 0);

    // End balance — __SALDO_AWAL__ adjustment is included automatically in the SUM
    $endBalanceQuery = "SELECT SUM(amount) AS balance FROM (
        SELECT CASE
                   WHEN A1.amount < 0 THEN A1.amount
                   WHEN A1.finance_category = '174c61e8-226d-11ef-a' THEN A1.amount
                   WHEN A1.finance_category = '1d604104-226d-11ef-a' THEN -A1.amount
                   ELSE 0
               END AS amount
        FROM financeTransaction A1
        WHERE $ba_ft AND DATE(A1.date) <= '$end_date'
        UNION ALL
        SELECT CASE
                   WHEN A1.supplier IS NOT NULL THEN -A1.paid_amount
                   WHEN A1.customer IS NOT NULL THEN A1.paid_amount
                   ELSE 0
               END AS amount
        FROM financeItem A1
        WHERE $ba_fi AND DATE(A1.paymentdate) <= '$end_date'
    ) AS balances";

    $endBalanceResult = mysqli_query($connect, $endBalanceQuery);
    if (!$endBalanceResult) {
        http_response_code(500);
        echo json_encode(['error' => mysqli_error($connect)]);
        exit;
    }
    $endBalance = (float)(mysqli_fetch_assoc($endBalanceResult)['balance'] ?? 0);

    // Total count — exclude __SALDO_AWAL__ sentinel from the list
    $totalQuery = "SELECT SUM(total_amount) AS total_transactions
        FROM (
            SELECT COUNT(A1.id_transaction) AS total_amount
            FROM financeTransaction A1
            WHERE $ba_ft
              AND DATE(A1.date) BETWEEN '$start_date' AND '$end_date'
              AND (A1.memo IS NULL OR (A1.memo NOT LIKE 'SALDO AWAL%' AND A1.memo != '__SALDO_AWAL__'))

            UNION ALL

            SELECT COUNT(A1.id_transaction) AS total_amount
            FROM financeItem A1
            WHERE $ba_fi
              AND A1.supplier IS NOT NULL
              AND A1.paid_amount IS NOT NULL
              AND DATE(A1.paymentdate) > '$start_date' AND DATE(A1.paymentdate) <= '$end_date'

            UNION ALL

            SELECT COUNT(A1.id_transaction) AS total_amount
            FROM financeItem A1
            WHERE $ba_fi
              AND A1.customer IS NOT NULL
              AND A1.paid_amount IS NOT NULL
              AND DATE(A1.paymentdate) > '$start_date' AND DATE(A1.paymentdate) <= '$end_date'
        ) AS combined_results";

    $totalResult = mysqli_query($connect, $totalQuery);
    if (!$totalResult) {
        http_response_code(500);
        echo json_encode(['error' => mysqli_error($connect)]);
        exit;
    }
    $totalItems = (int)(mysqli_fetch_assoc($totalResult)['total_transactions'] ?? 0);

    // Transaction list — exclude __SALDO_AWAL__ sentinel so it doesn't appear as a row
    $query_one = "SELECT A1.bank_account, A1.chequeno, A1.date AS transaction_date,
                         A2.account_code_name_alias, A1.amount, A3.category_name
        FROM financeTransaction A1
        LEFT JOIN account_code A2 ON A1.accountcode = A2.account_code
        LEFT JOIN finance_category A3 ON A1.finance_category = A3.category_id
        WHERE $ba_ft
          AND DATE(A1.date) BETWEEN '$start_date' AND '$end_date'
          AND (A1.memo IS NULL OR (A1.memo NOT LIKE 'SALDO AWAL%' AND A1.memo != '__SALDO_AWAL__'))";

    $query_two = "SELECT A1.bank, A1.chequeno, A1.paymentdate AS transaction_date,
                         A1.paid_amount, A2.supplier_name, A1.rate
        FROM financeItem A1
        LEFT JOIN supplier A2 ON A1.supplier = A2.supplier_id
        WHERE $ba_fi
          AND A1.supplier IS NOT NULL
          AND A1.paid_amount IS NOT NULL
          AND DATE(A1.paymentdate) > '$start_date' AND DATE(A1.paymentdate) <= '$end_date'";

    $query_three = "SELECT A1.bank, A1.chequeno, A1.paymentdate AS transaction_date,
                           A1.paid_amount, A2.company_name, A1.rate
        FROM financeItem A1
        LEFT JOIN customer A2 ON A1.customer = A2.company_id
        WHERE $ba_fi
          AND A1.customer IS NOT NULL
          AND A1.paid_amount IS NOT NULL
          AND DATE(A1.paymentdate) > '$start_date' AND DATE(A1.paymentdate) <= '$end_date'";

    // Fetching results
    $result_one = mysqli_query($connect, $query_one);
    $result_two = mysqli_query($connect, $query_two);
    $result_three = mysqli_query($connect, $query_three);

    if (!$result_one || !$result_two || !$result_three) {
        http_response_code(500); // Internal Server Error
        echo json_encode(array('error' => mysqli_error($connect)));
        exit;
    }

    // Merge results
    $transactions = array_merge(
        mysqli_fetch_all($result_one, MYSQLI_ASSOC),
        mysqli_fetch_all($result_two, MYSQLI_ASSOC),
        mysqli_fetch_all($result_three, MYSQLI_ASSOC)
    );

    // Sort transactions by date
    usort($transactions, function($a, $b) {
        return strtotime($a['transaction_date']) - strtotime($b['transaction_date']);
    });

    // Prepare response
    $response = array(
        'beginning_balance' => $beginningBalance,
        'end_balance' => $endBalance,
        'total_transactions' => $totalItems,
        'transactions' => $transactions
    );

    // Return JSON response
    echo json_encode($response);

} else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(array('error' => 'Method Not Allowed'));
}
?>
