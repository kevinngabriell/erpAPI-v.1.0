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
    $supplier_name = $_POST['supplier_name'];
    $supplier_origin = $_POST['supplier_origin'];
    $supplier_address = $_POST['supplier_address'];
    $supplier_phone = $_POST['supplier_phone'];
    $supplier_pic_name = $_POST['supplier_pic_name'];
    $supplier_pic_contact = $_POST['supplier_pic_contact'];
    $supplier_currency = $_POST['supplier_currency'];
    $supplier_term = $_POST['supplier_term'];
    $supplier_bank = $_POST['supplier_bank'];

    $insert_supplier_query = "INSERT INTO supplier (supplier_id, company, supplier_name, supplier_origin, supplier_address, supplier_phone, supplier_pic_name, supplier_pic_contact, supplier_currency, supplier_term, supplier_bank_information) VALUES (UUID(), '$company_id','$supplier_name', '$supplier_origin', '$supplier_address', '$supplier_phone', '$supplier_pic_name', '$supplier_pic_contact', '$supplier_currency', '$supplier_term', '$supplier_bank');";

    if(mysqli_query($connect, $insert_supplier_query)){
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