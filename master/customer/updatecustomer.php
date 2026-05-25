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
    $company_id = $_POST['company_id'];
    $company_name = $_POST['company_name'];
    $company_address = $_POST['company_address'];
    $company_phone = $_POST['company_phone'];
    $company_pic_name = $_POST['company_pic_name'];
    $company_pic_contact = $_POST['company_pic_contact'];
    $company_top = $_POST['company_top'];

    $update_customer_query = "UPDATE customer SET company_name = '$company_name', company_address = '$company_address', company_phone = '$company_phone', company_pic_name = '$company_pic_name', company_pic_contact = '$company_pic_contact', company_top = '$company_top' WHERE company_id = '$company_id'";

    if(mysqli_query($connect, $update_customer_query)){
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