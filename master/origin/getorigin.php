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
    $origin_query = "SELECT A1.origin_id, A1.origin_name, A1.origin_is_free_trade, A2.region_name FROM origin A1 JOIN region A2 ON A2.region_id = A1.origin_region ORDER BY A1.origin_name ASC;";
    $origin_result = mysqli_query($connect, $origin_query);

    $origin_array = array();
    while($origin_row = mysqli_fetch_array($origin_result)){
        array_push(
            $origin_array,
            array(
                'origin_id' => $origin_row['origin_id'],
                'Country Name' => $origin_row['origin_name'],
                'Is Free Trade' => $origin_row['origin_is_free_trade'],
                'Region' => $origin_row['region_name']
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