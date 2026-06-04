<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../connection/connection.php');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $start_date  = isset($_GET['start_date'])  ? mysqli_real_escape_string($connect, $_GET['start_date'])  : '';
    $end_date    = isset($_GET['end_date'])    ? mysqli_real_escape_string($connect, $_GET['end_date'])    : '';
    $supplier_id = isset($_GET['supplier_id']) ? mysqli_real_escape_string($connect, $_GET['supplier_id']) : '';
    $page        = isset($_GET['page'])        ? max(1, (int)$_GET['page'])  : 1;
    $limit       = isset($_GET['limit'])       ? max(1, (int)$_GET['limit']) : 25;
    $offset      = ($page - 1) * $limit;

    $where = "WHERE A3.invoiceNumber IS NULL
              AND A1.POStatus NOT IN (
                  SELECT PO_Status_ID FROM purchaseStatus WHERE PO_Status_Name IN ('Rejected','Cancelled')
              )";

    if ($start_date && $end_date) {
        $where .= " AND A1.PODate BETWEEN '$start_date' AND '$end_date'";
    }
    if ($supplier_id) {
        $where .= " AND A1.POSupplier = '$supplier_id'";
    }

    // Count total
    $countQuery = "
        SELECT COUNT(DISTINCT A1.PONumber) AS total
        FROM purchaseOrder A1
        LEFT JOIN purchaseInvoice A3 ON A1.PONumber = A3.PONumber
        $where
    ";
    $countResult = mysqli_query($connect, $countQuery);
    if (!$countResult) {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
        exit;
    }
    $totalItems = (int)mysqli_fetch_assoc($countResult)['total'];

    // Main query
    $query = "
        SELECT
            A1.PONumber,
            A1.PODate,
            A2.supplier_name,
            A4.PO_Status_Name,
            A5.PO_Type_Name,
            COUNT(A6.POProductName)                         AS total_item,
            SUM(A6.POQuantity * A6.POUnitPrice)             AS nilai_po,
            DATEDIFF(CURDATE(), A1.PODate)                  AS hari_sejak_po
        FROM purchaseOrder A1
        LEFT JOIN supplier       A2 ON A1.POSupplier = A2.supplier_id
        LEFT JOIN purchaseInvoice A3 ON A1.PONumber  = A3.PONumber
        LEFT JOIN purchaseStatus  A4 ON A1.POStatus  = A4.PO_Status_ID
        LEFT JOIN purchaseType    A5 ON A1.POType    = A5.PO_Type_ID
        LEFT JOIN purchaseOrderItem A6 ON A1.PONumber = A6.PONumber
        $where
        GROUP BY A1.PONumber, A1.PODate, A2.supplier_name, A4.PO_Status_Name, A5.PO_Type_Name
        ORDER BY A1.PODate DESC
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
        $row['total_item']      = (int)$row['total_item'];
        $row['nilai_po']        = (float)$row['nilai_po'];
        $row['hari_sejak_po']   = (int)$row['hari_sejak_po'];
    }

    $total_nilai = array_sum(array_column($data, 'nilai_po'));

    echo json_encode([
        'StatusCode'  => 200,
        'Status'      => 'Success',
        'totalItems'  => $totalItems,
        'page'        => $page,
        'limit'       => $limit,
        'total_nilai_po_belum_invoice' => (float)$total_nilai,
        'Data'        => $data
    ]);
} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Method Not Allowed']);
}
?>
