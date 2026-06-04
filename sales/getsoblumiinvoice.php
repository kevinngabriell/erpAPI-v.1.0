<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../connection/connection.php');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $start_date  = isset($_GET['start_date'])  ? mysqli_real_escape_string($connect, $_GET['start_date'])  : '';
    $end_date    = isset($_GET['end_date'])    ? mysqli_real_escape_string($connect, $_GET['end_date'])    : '';
    $customer_id = isset($_GET['customer_id']) ? mysqli_real_escape_string($connect, $_GET['customer_id']) : '';
    $page        = isset($_GET['page'])        ? max(1, (int)$_GET['page'])  : 1;
    $limit       = isset($_GET['limit'])       ? max(1, (int)$_GET['limit']) : 25;
    $offset      = ($page - 1) * $limit;

    // SO belum di-invoice = salesOrder that has no matching salesInvoice
    $where = "WHERE A3.invoiceNumber IS NULL
              AND A4.SO_Status_Name NOT IN ('Rejected','Cancelled')";

    if ($start_date && $end_date) {
        $where .= " AND A1.SODate BETWEEN '$start_date' AND '$end_date'";
    }
    if ($customer_id) {
        $where .= " AND A1.SOCustomer = '$customer_id'";
    }

    // Count total
    $countQuery = "
        SELECT COUNT(DISTINCT A1.SONumber) AS total
        FROM salesOrder A1
        LEFT JOIN salesInvoice A3 ON A1.SONumber = A3.salesOrder
        LEFT JOIN salesStatus  A4 ON A1.SOStatus = A4.SO_Status_ID
        $where
    ";
    $countResult = mysqli_query($connect, $countQuery);
    if (!$countResult) {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
        exit;
    }
    $totalItems = (int)mysqli_fetch_assoc($countResult)['total'];

    // Main query with item details
    $query = "
        SELECT
            A1.SONumber,
            A1.SODate,
            A2.company_name                             AS nama_pelanggan,
            A2.company_id                               AS customer_id,
            A4.SO_Status_Name                           AS status_so,
            COUNT(A5.ProductName)                       AS total_item,
            SUM(A5.Quantity * A5.HargaSatuan * COALESCE(A5.Kurs, 1)) AS nilai_so,
            DATEDIFF(CURDATE(), A1.SODate)              AS hari_sejak_so
        FROM salesOrder A1
        LEFT JOIN customer     A2 ON A1.SOCustomer = A2.company_id
        LEFT JOIN salesInvoice A3 ON A1.SONumber   = A3.salesOrder
        LEFT JOIN salesStatus  A4 ON A1.SOStatus   = A4.SO_Status_ID
        LEFT JOIN salesOrderItem A5 ON A1.SONumber = A5.salesOrderNumber
        $where
        GROUP BY A1.SONumber, A1.SODate, A2.company_name, A2.company_id, A4.SO_Status_Name
        ORDER BY A1.SODate DESC
        LIMIT $limit OFFSET $offset
    ";

    $result = mysqli_query($connect, $query);
    if (!$result) {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
        exit;
    }

    $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($data as &$row) {
        $row['total_item']    = (int)$row['total_item'];
        $row['nilai_so']      = (float)$row['nilai_so'];
        $row['hari_sejak_so'] = (int)$row['hari_sejak_so'];
    }

    $total_nilai = array_sum(array_column($data, 'nilai_so'));

    echo json_encode([
        'StatusCode'  => 200,
        'Status'      => 'Success',
        'totalItems'  => $totalItems,
        'page'        => $page,
        'limit'       => $limit,
        'total_nilai_so_belum_invoice' => (float)$total_nilai,
        'Data'        => $data
    ]);
} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Method Not Allowed']);
}
?>
