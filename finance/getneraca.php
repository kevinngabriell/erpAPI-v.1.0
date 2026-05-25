<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../connection/connection.php');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $as_of_date = isset($_GET['as_of_date']) ? $_GET['as_of_date'] : date('Y-m-d');
    $as_of_date = mysqli_real_escape_string($connect, $as_of_date);

    // ASET LANCAR 1: Kas & Bank — net balance per bank account up to as_of_date
    // Sources: financeTransaction (penerimaan = +, pembayaran = -)
    //          financeItem (customer receipt = +, supplier payment = -)
    $kasQuery = "
        SELECT bank_account AS akun, SUM(balance) AS saldo
        FROM (
            SELECT
                bank_account,
                SUM(CASE WHEN finance_category = '174c61e8-226d-11ef-a' THEN amount ELSE -amount END) AS balance
            FROM financeTransaction
            WHERE date <= '$as_of_date'
              AND (memo IS NULL OR memo != '__SALDO_AWAL__')
            GROUP BY bank_account

            UNION ALL

            SELECT
                bank AS bank_account,
                SUM(CASE
                    WHEN customer IS NOT NULL THEN paid_amount
                    WHEN supplier IS NOT NULL THEN -paid_amount
                    ELSE 0
                END) AS balance
            FROM financeItem
            WHERE paid_amount IS NOT NULL
              AND paymentdate <= '$as_of_date'
            GROUP BY bank
        ) AS combined
        GROUP BY bank_account
    ";

    // ASET LANCAR 2: Piutang Usaha — outstanding customer invoice amounts
    $piutangQuery = "
        SELECT
            A3.company_name AS nama_pelanggan,
            SUM(A1.due_amount) AS outstanding
        FROM financeItem A1
        LEFT JOIN salesInvoice A2 ON A1.invoice_number = A2.invoiceNumber
        LEFT JOIN customer A3 ON A2.customerID = A3.company_id
        WHERE A2.customerID IS NOT NULL
          AND A1.due_amount > 0
          AND DATE(A1.insert_dt) <= '$as_of_date'
        GROUP BY A3.company_name
        ORDER BY A3.company_name
    ";

    // KEWAJIBAN LANCAR: Hutang Usaha — outstanding supplier invoice amounts
    $hutangQuery = "
        SELECT
            A3.supplier_name AS nama_supplier,
            SUM(A1.due_amount) AS outstanding
        FROM financeItem A1
        LEFT JOIN purchaseInvoice A2 ON A1.invoice_number = A2.invoiceNumber
        LEFT JOIN supplier A3 ON A2.supplier = A3.supplier_id
        WHERE A2.supplier IS NOT NULL
          AND A1.due_amount > 0
          AND DATE(A1.insert_dt) <= '$as_of_date'
        GROUP BY A3.supplier_name
        ORDER BY A3.supplier_name
    ";

    // MODAL: Accumulated net income up to as_of_date (Revenue - Expense from all journal entries)
    $modalQuery = "
        SELECT
            SUM(CASE WHEN finance_category = '174c61e8-226d-11ef-a' THEN amount ELSE 0 END) -
            SUM(CASE WHEN finance_category = '1d604104-226d-11ef-a' THEN amount ELSE 0 END) AS laba_ditahan
        FROM financeTransaction
        WHERE date <= '$as_of_date'
          AND (memo IS NULL OR memo != '__SALDO_AWAL__')
    ";

    $kasResult     = mysqli_query($connect, $kasQuery);
    $piutangResult = mysqli_query($connect, $piutangQuery);
    $hutangResult  = mysqli_query($connect, $hutangQuery);
    $modalResult   = mysqli_query($connect, $modalQuery);

    if (!$kasResult || !$piutangResult || !$hutangResult || !$modalResult) {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
        exit;
    }

    $kasAccounts  = mysqli_fetch_all($kasResult, MYSQLI_ASSOC);
    $piutangItems = mysqli_fetch_all($piutangResult, MYSQLI_ASSOC);
    $hutangItems  = mysqli_fetch_all($hutangResult, MYSQLI_ASSOC);

    $modalRow    = mysqli_fetch_assoc($modalResult);
    $laba_ditahan = (float)($modalRow['laba_ditahan'] ?? 0);

    $total_kas     = array_sum(array_column($kasAccounts, 'saldo'));
    $total_piutang = array_sum(array_column($piutangItems, 'outstanding'));
    $total_hutang  = array_sum(array_column($hutangItems, 'outstanding'));

    $total_aset_lancar      = $total_kas + $total_piutang;
    $total_kewajiban_lancar = $total_hutang;
    $total_modal            = $laba_ditahan;
    $total_kewajiban_modal  = $total_kewajiban_lancar + $total_modal;

    echo json_encode([
        'StatusCode' => 200,
        'Status'     => 'Success',
        'as_of_date' => $as_of_date,
        'Data'       => [
            'aset' => [
                'aset_lancar' => [
                    'kas_bank' => [
                        'details' => array_map(fn($r) => array_merge($r, ['saldo' => (float)$r['saldo']]), $kasAccounts),
                        'total'   => (float)$total_kas
                    ],
                    'piutang_usaha' => [
                        'details' => array_map(fn($r) => array_merge($r, ['outstanding' => (float)$r['outstanding']]), $piutangItems),
                        'total'   => (float)$total_piutang
                    ],
                    'total_aset_lancar' => (float)$total_aset_lancar
                ],
                'total_aset' => (float)$total_aset_lancar
            ],
            'kewajiban' => [
                'kewajiban_lancar' => [
                    'hutang_usaha' => [
                        'details' => array_map(fn($r) => array_merge($r, ['outstanding' => (float)$r['outstanding']]), $hutangItems),
                        'total'   => (float)$total_hutang
                    ],
                    'total_kewajiban_lancar' => (float)$total_kewajiban_lancar
                ],
                'total_kewajiban' => (float)$total_kewajiban_lancar
            ],
            'modal' => [
                'laba_ditahan' => (float)$laba_ditahan,
                'total_modal'  => (float)$total_modal
            ],
            'total_kewajiban_dan_modal' => (float)$total_kewajiban_modal
        ]
    ]);
} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Method Not Allowed']);
}
?>
