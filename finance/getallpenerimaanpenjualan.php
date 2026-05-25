
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

    $where = "A2.customerID IS NOT NULL AND A1.customer IS NULL";
    if ($search !== '')     $where .= " AND (A3.company_name LIKE '%$search%' OR A1.invoice_number LIKE '%$search%')";
    if ($start_date !== '') $where .= " AND A2.invoiceDate >= '$start_date'";
    if ($end_date !== '')   $where .= " AND A2.invoiceDate <= '$end_date'";

    // Query to get total number of items
    $totalQuery = "SELECT COUNT(*) as transaction_count
                   FROM financeItem A1
                   LEFT JOIN salesInvoice A2 ON A1.invoice_number = A2.invoiceNumber
                   LEFT JOIN customer A3 ON A2.customerID = A3.company_id
                   WHERE $where";
    $totalResult = mysqli_query($connect, $totalQuery);
    $totalRow = mysqli_fetch_assoc($totalResult);
    $totalItems = $totalRow['transaction_count'];

    // Query to get paginated results
    $query = "SELECT A1.due_amount, A1.id_transaction, A1.paid_amount, A2.customerID, A1.invoice_number, A2.invoiceDate, A3.company_name
                FROM financeItem A1
                LEFT JOIN salesInvoice A2 ON A1.invoice_number = A2.invoiceNumber
                LEFT JOIN customer A3 ON A2.customerID = A3.company_id
                WHERE $where
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
                'customerID' => $row['customerID'],
                'invoice_number' => $row['invoice_number'],
                'invoiceDate' => $row['invoiceDate'],
                'company_name' => $row['company_name']
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
