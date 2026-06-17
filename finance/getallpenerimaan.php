<?php
//Header access is required

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
    $start_date = normalizeDate($_GET['start_date'] ?? '');
    $end_date   = normalizeDate($_GET['end_date'] ?? '');

    $where_ft = "A1.finance_category = '174c61e8-226d-11ef-a'";
    if ($search !== '')     $where_ft .= " AND (A1.memo LIKE '%$search%' OR A1.bank_account LIKE '%$search%' OR A2.account_code_name LIKE '%$search%')";
    if ($start_date !== '') $where_ft .= " AND DATE(A1.date) >= '$start_date'";
    if ($end_date !== '')   $where_ft .= " AND DATE(A1.date) <= '$end_date'";

    $where_fi = "A1.customer IS NOT NULL AND A1.paymentdate IS NOT NULL AND A1.paid_amount IS NOT NULL";
    if ($search !== '')     $where_fi .= " AND (A1.memo LIKE '%$search%' OR A1.bank LIKE '%$search%' OR A3.company_name LIKE '%$search%')";
    if ($start_date !== '') $where_fi .= " AND DATE(A1.paymentdate) >= '$start_date'";
    if ($end_date !== '')   $where_fi .= " AND DATE(A1.paymentdate) <= '$end_date'";

    // Query to get total number of items across both sources
    $totalQuery = "SELECT SUM(cnt) AS total FROM (
                       SELECT COUNT(*) AS cnt
                       FROM financeTransaction A1
                       LEFT JOIN account_code A2 ON A1.accountcode = A2.account_code
                       WHERE $where_ft
                       UNION ALL
                       SELECT COUNT(*) AS cnt
                       FROM financeItem A1
                       LEFT JOIN customer A3 ON A1.customer = A3.company_id
                       WHERE $where_fi
                   ) AS combined";
    $totalResult = mysqli_query($connect, $totalQuery);
    $totalRow = mysqli_fetch_assoc($totalResult);
    $totalItems = $totalRow['total'];

    // Query to get paginated results from both financeTransaction and financeItem (customer payments)
    $query = "SELECT amount, account_name, bank_account, id_transaction, date, memo
              FROM (
                  SELECT A1.amount, A2.account_code_name AS account_name, A1.bank_account,
                         A1.id_transaction, A1.date, A1.memo
                  FROM financeTransaction A1
                  LEFT JOIN account_code A2 ON A1.accountcode = A2.account_code
                  WHERE $where_ft

                  UNION ALL

                  SELECT A1.paid_amount AS amount, A3.company_name AS account_name, A1.bank AS bank_account,
                         A1.id_transaction, A1.paymentdate AS date, A1.memo
                  FROM financeItem A1
                  LEFT JOIN customer A3 ON A1.customer = A3.company_id
                  WHERE $where_fi
              ) AS combined
              ORDER BY date DESC
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
