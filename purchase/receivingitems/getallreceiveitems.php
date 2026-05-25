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
require_once('../../connection/connection.php');

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $query = "SELECT A2.supplier_name, A1.ReceivingDate, A1.PONumber, A1.InsertDt
    FROM purchaseRecieve A1
    LEFT JOIN supplier A2 ON A1.supplierID = A2.supplier_id
    ORDER BY A1.InsertDt DESC;";

    $result = mysqli_query($connect, $query); 
    $array = array();
    while($row = mysqli_fetch_array($result)){
        array_push(
            $array,
            array(
                'supplier_name' => $row['supplier_name'],
                'ReceivingDate' => $row['ReceivingDate'],
                'PONumber' => $row['PONumber']
            )
        );
    }
    
    if($array){
        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Data' => $array
            )
        );
    } else {
        http_response_code(400);
        echo json_encode(
            array(
                'StatusCode' => 400,
                'Status' => 'Error Bad Request, Result not found !'
            )
        );
    }

} else {
    http_response_code(404);
    echo json_encode(
        array(
            "StatusCode" => 404,
            'Status' => 'Error',
            "message" => "Error: API not found"
        )
    );
}