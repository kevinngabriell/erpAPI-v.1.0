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

    $purchase_query = "SELECT * FROM purchaseType";
    $purchase_result = mysqli_query($connect, $purchase_query);

    $purchase_array = array();
    while($purchase_row = mysqli_fetch_array($purchase_result)){
        array_push(
            $purchase_array,
            array(
                'PO_Type_ID' => $purchase_row['PO_Type_ID'],
                'PO_Type_Name' => $purchase_row['PO_Type_Name']
            )
        );
    }

    if($purchase_array){
        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Data' => $purchase_array
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