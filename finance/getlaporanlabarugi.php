<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../connection/connection.php');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date   = isset($_GET['end_date'])   ? $_GET['end_date']   : null;

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

    // Revenue: penerimaan journal transactions grouped by account code
    // Only include income/revenue accounts (code prefix 4xx)
    $revenueQuery = "
        SELECT
            A2.account_code,
            A2.account_code_name_alias AS account_name,
            SUM(A1.amount) AS total
        FROM financeTransaction A1
        LEFT JOIN account_code A2 ON A1.accountcode   = A2.account_code
        WHERE A1.finance_category = '174c61e8-226d-11ef-a'
          AND A1.date BETWEEN '$start_date' AND '$end_date'
          AND A2.account_code LIKE '4%'
        GROUP BY A2.account_code, A2.account_code_name_alias
        ORDER BY A2.account_code
    ";

    // Revenue: customer invoice receipts within the period
    $salesReceiptQuery = "
        SELECT COALESCE(SUM(A1.paid_amount), 0) AS total
        FROM financeItem A1
        WHERE A1.customer IS NOT NULL
          AND A1.paid_amount IS NOT NULL
          AND A1.paymentdate BETWEEN '$start_date' AND '$end_date'
    ";

    // Expenses: pembayaran journal transactions grouped by account code
    // Only include expense accounts (code prefix 5xx or 6xx)
    $expenseQuery = "
        SELECT
            A2.account_code,
            A2.account_code_name_alias AS account_name,
            SUM(A1.amount) AS total
        FROM financeTransaction A1
        LEFT JOIN account_code A2 ON A1.accountcode   = A2.account_code
        WHERE A1.finance_category = '1d604104-226d-11ef-a'
          AND A1.date BETWEEN '$start_date' AND '$end_date'
          AND (A2.account_code LIKE '5%' OR A2.account_code LIKE '6%')
        GROUP BY A2.account_code, A2.account_code_name_alias
        ORDER BY A2.account_code
    ";

    // Expenses: supplier invoice payments within the period
    $purchasePaymentQuery = "
        SELECT COALESCE(SUM(A1.paid_amount), 0) AS total
        FROM financeItem A1
        WHERE A1.supplier IS NOT NULL
          AND A1.paid_amount IS NOT NULL
          AND A1.paymentdate BETWEEN '$start_date' AND '$end_date'
    ";

    $revenueResult         = mysqli_query($connect, $revenueQuery);
    $salesReceiptResult    = mysqli_query($connect, $salesReceiptQuery);
    $expenseResult         = mysqli_query($connect, $expenseQuery);
    $purchasePaymentResult = mysqli_query($connect, $purchasePaymentQuery);

    if (!$revenueResult || !$expenseResult || !$salesReceiptResult || !$purchasePaymentResult) {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
        exit;
    }

    $revenues = mysqli_fetch_all($revenueResult, MYSQLI_ASSOC);
    $expenses = mysqli_fetch_all($expenseResult, MYSQLI_ASSOC);

    $salesReceiptRow       = mysqli_fetch_assoc($salesReceiptResult);
    $total_sales_receipt   = (float)$salesReceiptRow['total'];

    $purchasePaymentRow    = mysqli_fetch_assoc($purchasePaymentResult);
    $total_purchase_payment = (float)$purchasePaymentRow['total'];

    $total_revenue_journal = array_sum(array_column($revenues, 'total'));
    $total_expense_journal = array_sum(array_column($expenses, 'total'));

    $total_pendapatan = $total_revenue_journal + $total_sales_receipt;
    $total_beban      = $total_expense_journal + $total_purchase_payment;
    $laba_bersih      = $total_pendapatan - $total_beban;

    echo json_encode([
        'StatusCode' => 200,
        'Status'     => 'Success',
        'period'     => ['start_date' => $start_date, 'end_date' => $end_date],
        'Data'       => [
            'pendapatan' => [
                'jurnal'             => array_map(fn($r) => array_merge($r, ['total' => (float)$r['total']]), $revenues),
                'penerimaan_invoice' => $total_sales_receipt,
                'total_pendapatan'   => $total_pendapatan
            ],
            'beban' => [
                'jurnal'           => array_map(fn($e) => array_merge($e, ['total' => (float)$e['total']]), $expenses),
                'pembayaran_invoice'=> $total_purchase_payment,
                'total_beban'      => $total_beban
            ],
            'laba_bersih' => $laba_bersih
        ]
    ]);
} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Method Not Allowed']);
}
?>
