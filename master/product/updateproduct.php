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
    $product_code_new = $_POST['product_code_new'];
    $product_name_new = $_POST['product_name_new'];
    $product_desc_new = $_POST['product_desc_new'];
    $product_code_before = $_POST['product_code_before'];
    $product_name_before = $_POST['product_name_before'];
    $product_desc_before = $_POST['product_desc_before'];

    $update_query = "UPDATE product SET skuID = '$product_code_new', productName = '$product_name_new', productDesc = '$product_desc_new' WHERE  skuID = '$product_code_before' AND productName = '$product_name_before' AND productDesc = '$product_desc_before'";

    if(mysqli_query($connect, $update_query)){
        http_response_code(200);
        echo json_encode(
            array(
                "StatusCode" => 200,
                'Status' => 'Success',
                "message" => "Success: Product Data updated successfully"
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