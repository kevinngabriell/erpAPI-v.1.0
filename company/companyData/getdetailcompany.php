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
    $company_id = $_GET['company_id'];

    $company_detail_query = "SELECT company_name, company_address, company_email, company_phone, company_web, company_industry FROM company WHERE company_id = '$company_id'";
    $company_result = mysqli_query($connect, $company_detail_query);

    $company_array = array();
    while($company_row = mysqli_fetch_array($company_result)){
        array_push(
            $company_array,
            array(
                'company_name' => $company_row['company_name'],
                'company_address' => $company_row['company_address'],
                'company_phone' => $company_row['company_phone'],
                'company_email' => $company_row['company_email'],
                'company_web' => $company_row['company_web'],
                'company_industry' => $company_row['company_industry']
            )
        );
    }

    if($company_array){
        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Data' => $company_array
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
?>