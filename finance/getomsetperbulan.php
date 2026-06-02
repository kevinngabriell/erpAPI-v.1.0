<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../connection/connection.php');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $year        = isset($_GET['year'])       ? (int)$_GET['year']       : (int)date('Y');
    $month       = isset($_GET['month'])      ? (int)$_GET['month']      : 0;
    $customer_id = isset($_GET['customer_id'])? mysqli_real_escape_string($connect, $_GET['customer_id']) : '';

    // Build monthly filter
    $date_filter = "YEAR(A1.invoiceDate) = $year";
    if ($month > 0) {
        $date_filter .= " AND MONTH(A1.invoiceDate) = $month";
    }

    $customer_filter = $customer_id ? "AND A1.customerID = '$customer_id'" : '';

    // Monthly omset: sum of salesInvoiceItem (productQuantity * unitPrice) per month
    $omsetQuery = "
        SELECT
            YEAR(A1.invoiceDate)                              AS tahun,
            MONTH(A1.invoiceDate)                             AS bulan,
            MONTHNAME(A1.invoiceDate)                         AS nama_bulan,
            COUNT(DISTINCT A1.invoiceNumber)                  AS total_invoice,
            SUM(A2.productQuantity * A2.unitPrice)            AS omset_sebelum_ppn,
            SUM(A2.productQuantity * A2.unitPrice + COALESCE(A2.tax, 0)) AS omset_termasuk_ppn
        FROM salesInvoice A1
        LEFT JOIN salesInvoiceItem A2 ON A1.invoiceNumber = A2.DONumber
        WHERE $date_filter
          $customer_filter
        GROUP BY YEAR(A1.invoiceDate), MONTH(A1.invoiceDate), MONTHNAME(A1.invoiceDate)
        ORDER BY tahun ASC, bulan ASC
    ";

    // Per-invoice detail in the same period
    $invoiceDetailQuery = "
        SELECT
            A1.invoiceNumber,
            A1.invoiceDate,
            YEAR(A1.invoiceDate)                                                        AS tahun,
            MONTH(A1.invoiceDate)                                                       AS bulan,
            A3.company_name                                                             AS customer_name,
            A1.customerID,
            SUM(A2.productQuantity * A2.unitPrice)                                      AS total_sebelum_ppn,
            SUM(A2.productQuantity * A2.unitPrice + COALESCE(A2.tax, 0))   AS total_termasuk_ppn,
            COUNT(*)                                                                    AS total_item
        FROM salesInvoice A1
        LEFT JOIN salesInvoiceItem A2 ON A1.invoiceNumber = A2.DONumber
        LEFT JOIN customer A3 ON A1.customerID = A3.company_id
        WHERE $date_filter
          $customer_filter
        GROUP BY A1.invoiceNumber, A1.invoiceDate, A1.customerID, A3.company_name
        ORDER BY A1.invoiceDate ASC, A1.invoiceNumber ASC
    ";

    // Top selling products in the same period
    $topProdukQuery = "
        SELECT
            A2.productName,
            SUM(A2.productQuantity)                     AS total_qty,
            SUM(A2.productQuantity * A2.unitPrice)      AS total_nilai
        FROM salesInvoice A1
        LEFT JOIN salesInvoiceItem A2 ON A1.invoiceNumber = A2.DONumber
        WHERE $date_filter
          $customer_filter
          AND A2.productName IS NOT NULL
        GROUP BY A2.productName
        ORDER BY total_nilai DESC
        LIMIT 10
    ";

    // Top customers in the same period
    $topCustomerQuery = "
        SELECT
            A3.company_name,
            COUNT(DISTINCT A1.invoiceNumber)                        AS total_invoice,
            SUM(A2.productQuantity * A2.unitPrice)                  AS total_nilai
        FROM salesInvoice A1
        LEFT JOIN salesInvoiceItem A2 ON A1.invoiceNumber = A2.DONumber
        LEFT JOIN customer A3 ON A1.customerID = A3.company_id
        WHERE $date_filter
          $customer_filter
        GROUP BY A1.customerID, A3.company_name
        ORDER BY total_nilai DESC
        LIMIT 10
    ";

    $omsetResult        = mysqli_query($connect, $omsetQuery);
    $invoiceDetailResult= mysqli_query($connect, $invoiceDetailQuery);
    $topProdukResult    = mysqli_query($connect, $topProdukQuery);
    $topCustomerResult  = mysqli_query($connect, $topCustomerQuery);

    if (!$omsetResult || !$invoiceDetailResult || !$topProdukResult || !$topCustomerResult) {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
        exit;
    }

    $omset_bulanan  = mysqli_fetch_all($omsetResult, MYSQLI_ASSOC);
    $invoice_rows   = mysqli_fetch_all($invoiceDetailResult, MYSQLI_ASSOC);
    $top_produk     = mysqli_fetch_all($topProdukResult, MYSQLI_ASSOC);
    $top_customer   = mysqli_fetch_all($topCustomerResult, MYSQLI_ASSOC);

    // Index invoices by year-month for fast lookup
    $invoices_by_month = [];
    foreach ($invoice_rows as &$inv) {
        $inv['total_sebelum_ppn']  = (float)$inv['total_sebelum_ppn'];
        $inv['total_termasuk_ppn'] = (float)$inv['total_termasuk_ppn'];
        $inv['total_item']         = (int)$inv['total_item'];
        $key = $inv['tahun'] . '-' . $inv['bulan'];
        unset($inv['tahun'], $inv['bulan']);
        $invoices_by_month[$key][] = $inv;
    }
    unset($inv);

    $grand_total_sebelum_ppn  = array_sum(array_column($omset_bulanan, 'omset_sebelum_ppn'));
    $grand_total_termasuk_ppn = array_sum(array_column($omset_bulanan, 'omset_termasuk_ppn'));

    // Cast numeric strings and embed invoice details per month
    foreach ($omset_bulanan as &$row) {
        $row['total_invoice']         = (int)$row['total_invoice'];
        $row['omset_sebelum_ppn']     = (float)$row['omset_sebelum_ppn'];
        $row['omset_termasuk_ppn']    = (float)$row['omset_termasuk_ppn'];
        $key = $row['tahun'] . '-' . $row['bulan'];
        $row['invoices'] = $invoices_by_month[$key] ?? [];
    }
    foreach ($top_produk as &$row) {
        $row['total_qty']   = (float)$row['total_qty'];
        $row['total_nilai'] = (float)$row['total_nilai'];
    }
    foreach ($top_customer as &$row) {
        $row['total_invoice'] = (int)$row['total_invoice'];
        $row['total_nilai']   = (float)$row['total_nilai'];
    }

    echo json_encode([
        'StatusCode' => 200,
        'Status'     => 'Success',
        'filter'     => ['year' => $year, 'month' => $month ?: 'all', 'customer_id' => $customer_id ?: 'all'],
        'summary'    => [
            'grand_total_sebelum_ppn'  => (float)$grand_total_sebelum_ppn,
            'grand_total_termasuk_ppn' => (float)$grand_total_termasuk_ppn
        ],
        'omset_bulanan' => $omset_bulanan,
        'top_produk'    => $top_produk,
        'top_customer'  => $top_customer
    ]);
} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Method Not Allowed']);
}
?>
