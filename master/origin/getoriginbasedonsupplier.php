<?php
//Header access is required

//Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

//Connection access
require_once('../../connection/connection.php');

//Checking call API method
if($_SERVER['REQUEST_METHOD'] === 'GET'){
    $supplier = $_GET['supplier'];

    $origin_query = "SELECT supplier_origin, supplier_id FROM supplier WHERE supplier_id = '$supplier';;";
    $origin_result = mysqli_query($connect, $origin_query);

    $origin_array = array();
    while($origin_row = mysqli_fetch_array($origin_result)){
        array_push(
            $origin_array,
            array(
                'supplier_origin' => $origin_row['supplier_origin'],
                'supplier_id' => $origin_row['supplier_id']
            )
        );
    }

    if($origin_array){
        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Data' => $origin_array
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