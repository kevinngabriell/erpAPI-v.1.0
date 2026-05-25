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
    $category_name = $_POST['category_name'];

    //Search is origin name is already exist 
    $search_query = "SELECT category_name FROM finance_category WHERE category_name = '$category_name';";
    $result = $connect->query($search_query);
    $row = $result->fetch_assoc();

    //If origin name is exist 
    if($row){
        http_response_code(203);
        echo json_encode(
            array(
                "StatusCode" => 203,
                'Status' => 'Error',
                "message" => "Error: Finance Category has been already exist in database"
            )
        );
    //If origin name is not exist
    } else {
        $origin_query = "INSERT INTO finance_category (category_id, category_name) 
                        VALUES (UUID(), '$category_name')";
        
        if(mysqli_query($connect, $origin_query)){
            http_response_code(200);
            echo json_encode(
                array(
                    "StatusCode" => 200,
                    'Status' => 'Success',
                    "message" => "Success: Finance Category Data inserted successfully"
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