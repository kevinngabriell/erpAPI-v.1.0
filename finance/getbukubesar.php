<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../connection/connection.php');

const CAT_PENERIMAAN = '174c61e8-226d-11ef-a';
const CAT_PEMBAYARAN  = '1d604104-226d-11ef-a';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date   = isset($_GET['end_date'])   ? $_GET['end_date']   : null;
    $account_code_filter = isset($_GET['account_code']) ? $_GET['account_code'] : null;

    if (!$start_date || !$end_date) {
        http_response_code(400);
        echo json_encode([
            'StatusCode' => 400,
            'Status'     => 'Bad Request',
            'message'    => 'start_date and end_date are required'
        ]);
        exit;
    }

    $start_date = mysqli_real_escape_string($connect, $start_date);
    $end_date   = mysqli_real_escape_string($connect, $end_date);

    $account_filter = '';
    if ($account_code_filter) {
        $account_code_filter = mysqli_real_escape_string($connect, $account_code_filter);
        $account_filter = "AND A1.accountcode = '$account_code_filter'";
    }

    $query = "
        SELECT
            A2.account_code,
            A2.account_code_name,
            A2.account_code_name_alias,
            A1.date,
            A1.voucher_no,
            A1.memo,
            A1.finance_category,
            A1.amount
        FROM financeTransaction A1
        LEFT JOIN account_code A2 ON A1.accountcode COLLATE utf8mb4_general_ci = A2.account_code
        WHERE DATE(A1.date) BETWEEN '$start_date' AND '$end_date'
          AND (A1.memo IS NULL OR A1.memo != '__SALDO_AWAL__')
        $account_filter
        ORDER BY A2.account_code, A1.date ASC
    ";

    $result = mysqli_query($connect, $query);
    if (!$result) {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
        exit;
    }

    $transactions = mysqli_fetch_all($result, MYSQLI_ASSOC);

    $grouped = [];
    foreach ($transactions as $row) {
        $code = $row['account_code'];

        if (!isset($grouped[$code])) {
            // Opening balance — __SALDO_AWAL__ adjustment is included automatically in the SUM
            $openBalResult = mysqli_query($connect, "
                SELECT SUM(IF(finance_category = '" . CAT_PENERIMAAN . "', amount, -amount)) AS opening_balance
                FROM financeTransaction
                WHERE accountcode = '$code' AND DATE(date) < '$start_date'
            ");
            $opening_balance = (float)(mysqli_fetch_assoc($openBalResult)['opening_balance'] ?? 0);

            $grouped[$code] = [
                'account_code'      => $code,
                'account_name'      => $row['account_code_name'],
                'account_name_alias'=> $row['account_code_name_alias'],
                'opening_balance'   => $opening_balance,
                'total_debit'       => 0.0,
                'total_credit'      => 0.0,
                'closing_balance'   => $opening_balance,
                'transactions'      => []
            ];
        }

        $debit  = $row['finance_category'] === CAT_PENERIMAAN ? (float)$row['amount'] : 0.0;
        $credit = $row['finance_category'] === CAT_PEMBAYARAN  ? (float)$row['amount'] : 0.0;

        $grouped[$code]['total_debit']     += $debit;
        $grouped[$code]['total_credit']    += $credit;
        $grouped[$code]['closing_balance'] += $debit - $credit;
        $grouped[$code]['transactions'][]   = [
            'date'       => $row['date'],
            'voucher_no' => $row['voucher_no'],
            'memo'       => $row['memo'],
            'debit'      => $debit,
            'credit'     => $credit
        ];
    }

    $data = array_values($grouped);

    echo json_encode([
        'StatusCode'     => 200,
        'Status'         => 'Success',
        'period'         => ['start_date' => $start_date, 'end_date' => $end_date],
        'total_accounts' => count($data),
        'Data'           => $data
    ]);
} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Method Not Allowed']);
}
?>
