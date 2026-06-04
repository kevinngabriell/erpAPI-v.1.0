<?php
//Header access is required

//Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

//Connection access
require_once('../../connection/connection.php');

//Checking call API method
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $bank_number_new = $_POST['bank_number_new'];
    $bank_name_new = $_POST['bank_name_new'];
    $bank_branch_new = $_POST['bank_branch_new'];
    $bank_number_before = $_POST['bank_number_before'];
    $bank_name_before = $_POST['bank_name_before'];
    $bank_branch_before = $_POST['bank_branch_before'];

    $update_query = "UPDATE bank_account SET bank_number = '$bank_number_new', bank_name = '$bank_name_new', bank_branch = '$bank_branch_new' WHERE  bank_number = '$bank_number_before' AND bank_name = '$bank_name_before' AND bank_branch = '$bank_branch_before'";

    if(mysqli_query($connect, $update_query)){
        http_response_code(200);
        echo json_encode(
            array(
                "StatusCode" => 200,
                'Status' => 'Success',
                "message" => "Success: Bank Account Data updated successfully"
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