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

    $pending_query = "SELECT COUNT(*) as pending FROM purchaseOrder WHERE POStatus = 'd7ab6134-d157-11ee-8' AND PODate >= CURDATE() - INTERVAL 7 DAY";
    $pending_result = mysqli_query($connect, $pending_query);

    $pending_array = array();
    while($pending_row = mysqli_fetch_array($pending_result)){
        $pending = $pending_row['pending'];
    }

    $top_item_query = "SELECT POI.POProductName, COUNT(*) AS frequency
                        FROM purchaseOrderItem POI
                        JOIN purchaseOrder PO ON POI.PONumber = PO.PONumber
                        WHERE PO.PODate >= CURDATE() - INTERVAL 7 DAY
                        GROUP BY POI.POProductName
                        ORDER BY frequency DESC
                        LIMIT 1;";
    $top_item_result = mysqli_query($connect, $top_item_query);

    $top_item_array = array();
    while($top_item_row = mysqli_fetch_array($top_item_result)){
        $top = $top_item_row['POProductName'];
    }

    $total_query = "SELECT SUM(A1.POUnitPrice * A1.POQuantity * 1.11) AS total
    FROM purchaseOrderItem A1
    JOIN purchaseOrder A2 ON A1.PONumber = A2.PONumber
    WHERE A2.PODate >= CURDATE() - INTERVAL 7 DAY;";
    $total_result = mysqli_query($connect, $total_query);

    $total_array = array();
    while($total_row = mysqli_fetch_array($total_result)){
        $total = $total_row['total'];
    }

    $combinedData = array(
        'Pending' => $pending,
        'Top' => $top,
        'Total' => $total
        // 'AnotherData' => $anotherArray
        // Add more keys for additional queries if needed
    );

    if ($combinedData) {
        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Data' => $combinedData
            )
        );
    } else {
        http_response_code(400);
        echo json_encode(
            array(
                'StatusCode' => 400,
                'Status' => 'Error Bad Request, Result not found!'
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