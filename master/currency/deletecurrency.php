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
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $currency_id = $_POST['currency_id'];

    $query = "DELETE FROM currency WHERE currency_id = ?";
    $stmt = $connect->prepare($query);
    $stmt->bind_param('s', $currency_id);
    $result = $stmt->execute();

    if($result){
        http_response_code(200);
        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Message' => 'Currency has been successfully deleted'
            )
        );
    } else {
        http_response_code(500);
        echo json_encode(
            array(
                'StatusCode' => 500,
                'Status' => 'Error',
                'Message' => 'Error: Currency cannot be deleted, there may be a mistake in the query'
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