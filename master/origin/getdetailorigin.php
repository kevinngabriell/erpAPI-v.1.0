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
    $origin_id = $_GET['origin_id'];

    $origin_query = "SELECT A1.origin_name, A1.origin_is_free_trade, A2.region_name FROM origin A1 JOIN region A2 ON A2.region_id = A1.origin_region WHERE A1.origin_id = '$origin_id';";
    $origin_result = mysqli_query($connect, $origin_query);

    $origin_array = array();
    while($origin_row = mysqli_fetch_array($origin_result)){
        array_push(
            $origin_array,
            array(
                'origin_name' => $origin_row['origin_name'],
                'origin_is_free_trade' => $origin_row['origin_is_free_trade'],
                'region_name' => $origin_row['region_name']
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