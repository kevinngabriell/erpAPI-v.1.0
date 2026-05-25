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
if($_SERVER['REQUEST_METHOD'] === 'GET'){
    $query = "SELECT A1.PONumber, A1.PODate, A2.supplier_name, A3.origin_name
    FROM purchaseOrder A1 
    LEFT JOIN supplier A2 ON A1.POSupplier = A2.supplier_id
    LEFT JOIN origin A3 ON A1.POOrigin = A3.origin_id
    ORDER BY A1.PONumber ASC;";

    $result = mysqli_query($connect, $query);
    $po_array = array();
    while($row = mysqli_fetch_array($result)){
        array_push(
            $po_array,
            array(
                'PONumber' => $row['PONumber'],
                'PODate' => $row['PODate'],
                'POSupplier' => $row['supplier_name'],
                'POOrigin' => $row['origin_name']
            )
        );
    }

    if($po_array){
        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Data' => $po_array
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
            "message" => "Error: Invalid method. Only POST requests are allowed."
        )
    );
}