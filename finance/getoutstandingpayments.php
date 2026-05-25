`<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../connection/connection.php');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $type  = isset($_GET['type'])  ? strtolower($_GET['type'])  : 'all'; // piutang | hutang | all
    $month = isset($_GET['month']) ? (int)$_GET['month']        : 0;     // 1-12, 0 = all months
    $year  = isset($_GET['year'])  ? (int)$_GET['year']         : 0;     // e.g. 2025, 0 = all years

    // Build optional date filter for invoiceDate
    $date_filter_piutang = '';
    $date_filter_hutang  = '';
    if ($year > 0) {
        $date_filter_piutang .= " AND YEAR(A2.invoiceDate) = $year";
        $date_filter_hutang  .= " AND YEAR(A2.invoiceDate) = $year";
    }
    if ($month > 0) {
        $date_filter_piutang .= " AND MONTH(A2.invoiceDate) = $month";
        $date_filter_hutang  .= " AND MONTH(A2.invoiceDate) = $month";
    }

    $response = [];

    // PIUTANG USAHA — customer invoices with remaining due_amount > 0
    if ($type === 'piutang' || $type === 'all') {
        $piutangQuery = "
            SELECT
                A1.invoice_number,
                A3.company_name                            AS nama_pelanggan,
                A3.company_id                              AS customer_id,
                A2.invoiceDate                             AS tanggal_invoice,
                A3.company_top                             AS term_of_payment,
                DATE_ADD(A2.invoiceDate, INTERVAL COALESCE(A3.company_top, 0) DAY) AS jatuh_tempo,
                DATEDIFF(CURDATE(), DATE_ADD(A2.invoiceDate, INTERVAL COALESCE(A3.company_top, 0) DAY))
                                                           AS hari_overdue,
                COALESCE(MAX(NULLIF(A4.Kurs, 0)), 1)      AS kurs,
                COALESCE(MAX(A5.currency_name), 'IDR')    AS currency,
                (MAX(A1.due_amount) - SUM(COALESCE(A1.paid_amount, 0)))
                    * COALESCE(MAX(NULLIF(A4.Kurs, 0)), 1) AS sisa_tagihan,
                MAX(A1.due_amount)
                    * COALESCE(MAX(NULLIF(A4.Kurs, 0)), 1) AS nilai_invoice,
                SUM(COALESCE(A1.paid_amount, 0))
                    * COALESCE(MAX(NULLIF(A4.Kurs, 0)), 1) AS sudah_dibayar
            FROM financeItem A1
            LEFT JOIN salesInvoice  A2 ON A1.invoice_number = A2.invoiceNumber
            LEFT JOIN customer      A3 ON A2.customerID     = A3.company_id
            LEFT JOIN salesOrderItem A4 ON A2.salesOrder    = A4.salesOrderNumber
            LEFT JOIN currency      A5 ON A4.MataUang       = A5.currency_id
            WHERE A2.customerID IS NOT NULL
              $date_filter_piutang
            GROUP BY A1.invoice_number, A3.company_name, A3.company_id, A2.invoiceDate, A3.company_top
            HAVING (MAX(A1.due_amount) - SUM(COALESCE(A1.paid_amount, 0))) > 0
            ORDER BY jatuh_tempo ASC
        ";

        $piutangResult = mysqli_query($connect, $piutangQuery);
        if (!$piutangResult) {
            http_response_code(500);
            echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
            exit;
        }

        $piutang_list = mysqli_fetch_all($piutangResult, MYSQLI_ASSOC);
        foreach ($piutang_list as &$row) {
            $row['sisa_tagihan']  = (float)$row['sisa_tagihan'];
            $row['nilai_invoice'] = (float)$row['nilai_invoice'];
            $row['sudah_dibayar'] = (float)$row['sudah_dibayar'];
            $row['hari_overdue']  = (int)$row['hari_overdue'];
            $row['kurs']          = (float)$row['kurs'];
            $row['status']        = $row['hari_overdue'] > 0 ? 'Overdue' : 'Belum Jatuh Tempo';
        }

        $total_piutang = array_sum(array_column($piutang_list, 'sisa_tagihan'));

        $response['piutang_usaha'] = [
            'total_piutang' => (float)$total_piutang,
            'total_invoice' => count($piutang_list),
            'data'          => $piutang_list
        ];
    }

    // HUTANG USAHA — supplier invoices with remaining due_amount > 0
    if ($type === 'hutang' || $type === 'all') {
        $hutangQuery = "
            SELECT
                A1.invoice_number,
                A3.supplier_name                           AS nama_supplier,
                A3.supplier_id,
                A2.invoiceDate                             AS tanggal_invoice,
                COALESCE(NULLIF(A2.kurs, 0), 1)           AS kurs,
                COALESCE(A5.currency_name, 'IDR')         AS currency,
                (MAX(A1.due_amount) - SUM(COALESCE(A1.paid_amount, 0)))
                    * COALESCE(NULLIF(A2.kurs, 0), 1)     AS sisa_hutang,
                MAX(A1.due_amount)
                    * COALESCE(NULLIF(A2.kurs, 0), 1)     AS nilai_invoice,
                SUM(COALESCE(A1.paid_amount, 0))
                    * COALESCE(NULLIF(A2.kurs, 0), 1)     AS sudah_dibayar,
                DATEDIFF(CURDATE(), A2.invoiceDate)        AS hari_sejak_invoice
            FROM financeItem A1
            LEFT JOIN purchaseInvoice A2 ON A1.invoice_number = A2.invoiceNumber
            LEFT JOIN supplier        A3 ON A2.supplier       = A3.supplier_id
            LEFT JOIN purchaseOrder   A4 ON A2.PONumber       = A4.PONumber
            LEFT JOIN currency        A5 ON A4.POCurrency     = A5.currency_id
            WHERE A2.supplier IS NOT NULL
              $date_filter_hutang
            GROUP BY A1.invoice_number, A3.supplier_name, A3.supplier_id, A2.invoiceDate, A2.kurs, A5.currency_name
            HAVING (MAX(A1.due_amount) - SUM(COALESCE(A1.paid_amount, 0))) > 0
            ORDER BY A2.invoiceDate ASC
        ";

        $hutangResult = mysqli_query($connect, $hutangQuery);
        if (!$hutangResult) {
            http_response_code(500);
            echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
            exit;
        }

        $hutang_list = mysqli_fetch_all($hutangResult, MYSQLI_ASSOC);
        foreach ($hutang_list as &$row) {
            $row['sisa_hutang']         = (float)$row['sisa_hutang'];
            $row['nilai_invoice']       = (float)$row['nilai_invoice'];
            $row['sudah_dibayar']       = (float)$row['sudah_dibayar'];
            $row['hari_sejak_invoice']  = (int)$row['hari_sejak_invoice'];
            $row['kurs']                = (float)$row['kurs'];
        }

        $total_hutang = array_sum(array_column($hutang_list, 'sisa_hutang'));

        $response['hutang_usaha'] = [
            'total_hutang'  => (float)$total_hutang,
            'total_invoice' => count($hutang_list),
            'data'          => $hutang_list
        ];
    }

    echo json_encode(array_merge(
        [
            'StatusCode' => 200,
            'Status'     => 'Success',
            'type'       => $type,
            'filter'     => [
                'year'  => $year  > 0 ? $year  : 'all',
                'month' => $month > 0 ? $month : 'all'
            ]
        ],
        $response
    ));
} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Method Not Allowed']);
}
?>
`