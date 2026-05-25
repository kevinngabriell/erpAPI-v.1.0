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
if($_SERVER['REQUEST_METHOD'] === 'GET'){
    $company = $_GET['company'];

    $customer_query = "SELECT company_id, company_name, company_address, company_phone FROM customer WHERE company = '$company' ORDER BY company_name ASC";
    $customer_result = mysqli_query($connect, $customer_query);

    $customer_array = array();
    while($customer_row = mysqli_fetch_array($customer_result)){
        array_push(
            $customer_array,
            array(
                'company_id' => $customer_row['company_id'],
                'Company Name' => $customer_row['company_name'],
                'Company Address' => $customer_row['company_address'],
                'Company Phone' => $customer_row['company_phone']
            )
        );
    }

    if($customer_array){
        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Data' => $customer_array
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