<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../connection/connection.php');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $year        = isset($_GET['year'])        ? (int)$_GET['year']                                          : (int)date('Y');
    $month       = isset($_GET['month'])       ? (int)$_GET['month']                                         : 0;
    $customer_id = isset($_GET['customer_id']) ? mysqli_real_escape_string($connect, $_GET['customer_id'])   : '';
    $search      = isset($_GET['search'])      ? mysqli_real_escape_string($connect, $_GET['search'])        : '';
    $page        = isset($_GET['page'])        ? (int)$_GET['page']                                          : 1;
    $limit       = isset($_GET['limit'])       ? (int)$_GET['limit']                                         : 25;
    $offset      = ($page - 1) * $limit;

    // Base WHERE conditions
    $where = "YEAR(A1.invoiceDate) = $year";
    if ($month > 0)       $where .= " AND MONTH(A1.invoiceDate) = $month";
    if ($customer_id !== '') $where .= " AND A1.customerID = '$customer_id'";
    if ($search !== '')   $where .= " AND (A1.invoiceNumber LIKE '%$search%' OR A3.company_name LIKE '%$search%')";

    // Total count (per invoice)
    $totalQuery = "
        SELECT COUNT(DISTINCT A1.invoiceNumber) AS total
        FROM salesInvoice A1
        LEFT JOIN salesInvoiceItem A2 ON A1.invoiceNumber = A2.DONumber
        LEFT JOIN customer A3 ON A1.customerID = A3.company_id
        WHERE $where
    ";
    $totalResult = mysqli_query($connect, $totalQuery);
    $totalRow    = mysqli_fetch_assoc($totalResult);
    $totalItems  = (int)$totalRow['total'];

    // Invoice-level detail: one row per invoice with aggregated amounts
    $query = "
        SELECT
            A1.invoiceNumber,
            A1.invoiceDate,
            A1.customerID,
            A3.company_name,
            COUNT(A2.id)                                                            AS total_items,
            SUM(A2.productQuantity)                                                 AS total_qty,
            SUM(A2.productQuantity * A2.unitPrice)                                  AS omset_sebelum_ppn,
            SUM(A2.productQuantity * A2.unitPrice + COALESCE(A2.tax, 0)) AS omset_termasuk_ppn
        FROM salesInvoice A1
        LEFT JOIN salesInvoiceItem A2 ON A1.invoiceNumber = A2.DONumber
        LEFT JOIN customer A3 ON A1.customerID = A3.company_id
        WHERE $where
        GROUP BY A1.invoiceNumber, A1.invoiceDate, A1.customerID, A3.company_name
        ORDER BY A1.invoiceDate DESC
        LIMIT $limit OFFSET $offset
    ";

    $result = mysqli_query($connect, $query);

    if (!$result) {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Query Error', 'message' => mysqli_error($connect)]);
        exit;
    }

    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = [
            'invoiceNumber'     => $row['invoiceNumber'],
            'invoiceDate'       => $row['invoiceDate'],
            'customerID'        => $row['customerID'],
            'company_name'      => $row['company_name'],
            'total_items'       => (int)$row['total_items'],
            'total_qty'         => (float)$row['total_qty'],
            'omset_sebelum_ppn' => (float)$row['omset_sebelum_ppn'],
            'omset_termasuk_ppn'=> (float)$row['omset_termasuk_ppn'],
        ];
    }

    if ($data) {
        echo json_encode([
            'StatusCode' => 200,
            'Status'     => 'Success',
            'filter'     => [
                'year'        => $year,
                'month'       => $month ?: 'all',
                'customer_id' => $customer_id ?: 'all',
                'search'      => $search ?: '',
            ],
            'totalItems' => $totalItems,
            'page'       => $page,
            'limit'      => $limit,
            'Data'       => $data,
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['StatusCode' => 400, 'Status' => 'No Data Found', 'Data' => []]);
    }

} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Method Not Allowed']);
}
?>
