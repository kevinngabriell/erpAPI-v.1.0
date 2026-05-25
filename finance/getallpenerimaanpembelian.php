
<?php
//Header access is required
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

//Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

//Connection access
require_once('../connection/connection.php');

//Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // Get page and limit from query parameters, with default values
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;

    // Calculate offset
    $offset = ($page - 1) * $limit;

    // Search params
    $search     = isset($_GET['search'])     ? mysqli_real_escape_string($connect, $_GET['search'])     : '';
    $start_date = isset($_GET['start_date']) ? mysqli_real_escape_string($connect, $_GET['start_date']) : '';
    $end_date   = isset($_GET['end_date'])   ? mysqli_real_escape_string($connect, $_GET['end_date'])   : '';

    $where = "A2.supplier IS NOT NULL";
    if ($search !== '')     $where .= " AND (A3.supplier_name LIKE '%$search%' OR A1.invoice_number LIKE '%$search%')";
    if ($start_date !== '') $where .= " AND A2.invoiceDate >= '$start_date'";
    if ($end_date !== '')   $where .= " AND A2.invoiceDate <= '$end_date'";

    // Query to get total number of items
    $totalQuery = "SELECT COUNT(*) as transaction_count
FROM (
    SELECT A1.invoice_number
        FROM financeItem A1
        LEFT JOIN purchaseInvoice A2 ON A1.invoice_number = A2.invoiceNumber
        LEFT JOIN supplier A3 ON A2.supplier = A3.supplier_id
        LEFT JOIN purchaseOrder A4 ON A2.PONumber = A4.PONumber
        LEFT JOIN currency A5 ON A4.POCurrency = A5.currency_id
        WHERE $where
        GROUP BY A1.invoice_number, A2.supplier
) AS transaction_counts;";
    $totalResult = mysqli_query($connect, $totalQuery);
    $totalRow = mysqli_fetch_assoc($totalResult);
    $totalItems = $totalRow['transaction_count'];

    // Query to get paginated results
    $query = "SELECT A1.due_amount, A1.id_transaction, A1.paid_amount, A2.supplier, A1.invoice_number, A2.invoiceDate, A3.supplier_name, A5.currency_name
        FROM financeItem A1
        LEFT JOIN purchaseInvoice A2 ON A1.invoice_number = A2.invoiceNumber
        LEFT JOIN supplier A3 ON A2.supplier = A3.supplier_id
        LEFT JOIN purchaseOrder A4 ON A2.PONumber = A4.PONumber
        LEFT JOIN currency A5 ON A4.POCurrency = A5.currency_id
        WHERE $where
        GROUP BY A1.invoice_number, A2.supplier
        ORDER BY A1.insert_dt DESC
        LIMIT $limit OFFSET $offset";


    $result = mysqli_query($connect, $query);

    $array = array();
    while ($row = mysqli_fetch_array($result)) {
        array_push(
            $array,
            array(
                'due_amount' => $row['due_amount'],
                'id_transaction' => $row['id_transaction'],
                'paid_amount' => $row['paid_amount'],
                'supplier' => $row['supplier'],
                'invoice_number' => $row['invoice_number'],
                'invoiceDate' => $row['invoiceDate'],
                'supplier_name' => $row['supplier_name'],
                'currency_name' => $row['currency_name'],
                'status' => ((float)$row['due_amount'] <= 0) ? 'LUNAS' : 'BELUM LUNAS'
            )
        );
    }

    if ($array) {
        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Data' => $array,
                'totalItems' => $totalItems // Add totalItems to the response
            )
        );
    } else {
        http_response_code(400);
        echo json_encode(
            array(
                'StatusCode' => 400,
                'Status' => 'No Data Found',
                'Data' => []
            )
        );
    }
} else {
    http_response_code(405);
    echo json_encode(
        array(
            'StatusCode' => 405,
            'Status' => 'Method Not Allowed'
        )
    );
}
?>
