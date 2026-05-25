<?php
// Header access is required
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Connection access
require_once('../connection/connection.php');

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $productName = $_GET['productName'];

    // Query to fetch the required data
    $query = "SELECT A1.lot, A1.product, A2.productName, A1.endbalance
              FROM warehouse A1
              LEFT JOIN product A2 ON A1.product = A2.skuID
              WHERE A2.productName = '$productName'";

    $result = mysqli_query($connect, $query);
    $response_array = array();

    if (mysqli_num_rows($result) > 0) {
        while($row = mysqli_fetch_assoc($result)){
            array_push(
                $response_array,
                array(
                    'productId' => $row['product'],
                    'lotNumber' => $row['lot'],
                    'quantity' => $row['endbalance']
                )
            );
        }

        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Data' => $response_array
            )
        );
    } else {
        http_response_code(404);
        echo json_encode(
            array(
                "StatusCode" => 404,
                'Status' => 'Error',
                "message" => "No records found for productName: $productName."
            )
        );
    }
} else {
    http_response_code(404);
    echo json_encode(
        array(
            "StatusCode" => 404,
            'Status' => 'Error',
            "message" => "Error: Invalid method. Only GET requests are allowed."
        )
    );
}
?>
