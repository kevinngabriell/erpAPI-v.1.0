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
require_once('../../connection/connection.php');

//Checking call API method
if($_SERVER['REQUEST_METHOD'] === 'GET'){
    // Get page and limit from query parameters, with default values
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
 
    // Calculate offset
    $offset = ($page - 1) * $limit;
 
    // Query to get total number of items
    $totalQuery = "SELECT COUNT(*) as total FROM account_code;";
    $totalResult = mysqli_query($connect, $totalQuery);
    $totalRow = mysqli_fetch_assoc($totalResult);
    $totalItems = $totalRow['total'];

    // Query to get paginated results
    $query = "SELECT * FROM account_code
              LIMIT $limit OFFSET $offset";

    $result = mysqli_query($connect, $query);

    $array = array();
    while ($row = mysqli_fetch_array($result)) {
        array_push(
            $array,
            array(
                'code' => $row['code'],
                'account_name' => $row['account_name'],
                'account_name_alias' => $row['account_name_alias']
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