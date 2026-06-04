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
    $supplier_id = $_POST['supplier_id'];
    $supplier_name = $_POST['supplier_name'];
    $supplier_phone = $_POST['supplier_phone'];
    $supplier_address = $_POST['supplier_address'];
    $supplier_pic_name = $_POST['supplier_pic_name'];
    $supplier_pic_contact = $_POST['supplier_pic_contact'];
    $supplier_origin = $_POST['supplier_origin'];
    $supplier_currency = $_POST['supplier_currency'];
    $supplier_term = $_POST['supplier_term'];
    $supplier_bank = $_POST['supplier_bank'];

    $update_supplier_query = "UPDATE supplier SET supplier_name = '$supplier_name', supplier_phone = '$supplier_phone', supplier_address = '$supplier_address', 
    supplier_pic_name = '$supplier_pic_name', supplier_pic_contact = '$supplier_pic_contact', supplier_origin = '$supplier_origin', 
    supplier_currency = '$supplier_currency', supplier_term = '$supplier_term', supplier_bank_information = '$supplier_bank' WHERE supplier_id = '$supplier_id'";

    if(mysqli_query($connect, $update_supplier_query)){
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