
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

    $where = "A1.finance_category = '1d604104-226d-11ef-a'";
    if ($search !== '')     $where .= " AND (A1.memo LIKE '%$search%' OR A1.bank_account LIKE '%$search%' OR A2.account_name LIKE '%$search%')";
    if ($start_date !== '') $where .= " AND A1.date >= '$start_date'";
    if ($end_date !== '')   $where .= " AND A1.date <= '$end_date'";

    // Query to get total number of items
    $totalQuery = "SELECT COUNT(*) as total
                   FROM financeTransaction A1
                   LEFT JOIN account_code A2 ON A1.accountcode COLLATE utf8mb4_general_ci = A2.account_code
                   WHERE $where";
    $totalResult = mysqli_query($connect, $totalQuery);
    $totalRow = mysqli_fetch_assoc($totalResult);
    $totalItems = $totalRow['total'];

    // Query to get paginated results
    $query = "SELECT A1.amount, A2.account_name, A1.bank_account, A1.id_transaction, A1.date, A1.memo
              FROM financeTransaction A1
              LEFT JOIN account_code A2 ON A1.accountcode COLLATE utf8mb4_general_ci = A2.account_code
              WHERE $where
              ORDER BY A1.date DESC
              LIMIT $limit OFFSET $offset";

    $result = mysqli_query($connect, $query);

    $array = array();
    while ($row = mysqli_fetch_array($result)) {
        array_push(
            $array,
            array(
                'amount' => $row['amount'],
                'account_name' => $row['account_name'],
                'bank_account' => $row['bank_account'],
                'id_transaction' => $row['id_transaction'],
                'date' => $row['date'],
                'memo' => $row['memo']
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
