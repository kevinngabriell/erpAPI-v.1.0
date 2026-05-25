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
    $origin_name = $_POST['origin_name'];
    $origin_region = $_POST['origin_region'];
    $origin_is_free_trade = $_POST['origin_is_free_trade'];

    //Search is origin name is already exist 
    $search_query = "SELECT origin_name FROM origin WHERE origin_name = '$origin_name'";
    $result = $connect->query($search_query);
    $row = $result->fetch_assoc();

    //If origin name is exist 
    if($row){
        http_response_code(203);
        echo json_encode(
            array(
                "StatusCode" => 203,
                'Status' => 'Error',
                "message" => "Error: Origin has been already exist in database"
            )
        );
    //If origin name is not exist
    } else {
        $origin_query = "INSERT IGNORE INTO origin (origin_id, origin_name, origin_region, origin_is_free_trade) 
                        VALUES (NULL, '$origin_name', '$origin_region', '$origin_is_free_trade')";
        
        if(mysqli_query($connect, $origin_query)){
            http_response_code(200);
            echo json_encode(
                array(
                    "StatusCode" => 200,
                    'Status' => 'Success',
                    "message" => "Success: Data inserted successfully"
                )
            );
        } else {
            http_response_code(500);
            echo json_encode(
                array(
                    "StatusCode" => 500,
                    'Status' => 'Error',
                    "message" => "Error: Unable to update data - " . mysqli_error($connect)
                )
            );
        }
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
?>