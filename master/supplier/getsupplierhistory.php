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
    $company = $_GET['company'];

    $query = "SELECT A1.PONumber, A3.PO_Type_Name, A1.PODate, A4.origin_name, A5.PO_Status_Name
    FROM purchaseOrder A1
    LEFT JOIN supplier A2 ON A1.POSupplier = A2.supplier_id
    LEFT JOIN purchaseType A3 ON A1.POType = A3.PO_Type_ID
    LEFT JOIN origin A4 ON A1.POOrigin = A4.origin_id
    LEFT JOIN purchaseStatus A5 ON A1.POStatus = A5.PO_Status_ID
    WHERE A1.POSupplier = '$company';";

    $supplier_result = mysqli_query($connect, $query);

    $supplier_array = array();
    while($supplier_row = mysqli_fetch_array($supplier_result)){
        array_push(
            $supplier_array,
            array(
                'PONumber' => $supplier_row['PONumber'],
                'PO_Type_Name' => $supplier_row['PO_Type_Name'],
                'PODate' => $supplier_row['PODate'],
                'origin_name' => $supplier_row['origin_name'],
                'PO_Status_Name' => $supplier_row['PO_Status_Name']
            )
        );
    }

    if($supplier_array){
        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Data' => $supplier_array
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
            "message" => "Error: Invalid method. Only GET requests are allowed."
        )
    );
}

?>