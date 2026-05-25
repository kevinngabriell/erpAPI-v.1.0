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
    $bank_number = $_POST['bank_number'];
    $bank_name = $_POST['bank_name'];
    $bank_branch = $_POST['bank_branch'];

    //Search is origin name is already exist 
    $search_query = "SELECT bank_number FROM bank_account WHERE bank_number = '$bank_number';";
    $result = $connect->query($search_query);
    $row = $result->fetch_assoc();

    //If origin name is exist 
    if($row){
        http_response_code(203);
        echo json_encode(
            array(
                "StatusCode" => 203,
                'Status' => 'Error',
                "message" => "Error: Bank Account has been already exist in database"
            )
        );
    //If origin name is not exist
    } else {
        $origin_query = "INSERT INTO bank_account (bank_number, bank_name, bank_branch) 
                        VALUES ('$bank_number', '$bank_name', '$bank_branch')";
        
        if(mysqli_query($connect, $origin_query)){
            http_response_code(200);
            echo json_encode(
                array(
                    "StatusCode" => 200,
                    'Status' => 'Success',
                    "message" => "Success: Bank Account Data inserted successfully"
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