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

    $currency_query = "SELECT * FROM currency";
    $currency_result = mysqli_query($connect, $currency_query);

    $currency_array = array();
    while($currency_row = mysqli_fetch_array($currency_result)){
        array_push(
            $currency_array,
            array(
                'Id' => $currency_row['currency_id'],
                'Currency' => $currency_row['currency_name']
            )
        );
    }

    if($currency_array){
        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Data' => $currency_array
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