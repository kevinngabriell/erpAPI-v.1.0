<?php
//Header access is required

//Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

//Connection access
require_once('../../connection/connection.php');

//Checking call API method
if($_SERVER['REQUEST_METHOD'] === 'GET'){
    $company = $_GET['company'];

    $supplier_query = "SELECT A1.supplier_id, A1.supplier_name, A1.supplier_phone, A2.origin_name, A1.supplier_pic_name, A1.supplier_pic_contact, A1.supplier_origin, A1.supplier_currency, A1.supplier_term, A1.supplier_bank_information FROM supplier A1 JOIN origin A2 ON A2.origin_id = A1.supplier_origin WHERE A1.company = '$company' AND supplier_origin = '10' ORDER BY A1.supplier_name ASC;";
    $supplier_result = mysqli_query($connect, $supplier_query);

    $supplier_array = array();
    while($supplier_row = mysqli_fetch_array($supplier_result)){
        array_push(
            $supplier_array,
            array(
                'supplier_id' => $supplier_row['supplier_id'],
                'Supplier' => $supplier_row['supplier_name'],
                'Phone Number' => $supplier_row['supplier_phone'],
                'Origin' => $supplier_row['origin_name'],
                'PIC' => $supplier_row['supplier_pic_name'],
                'PIC Contact' => $supplier_row['supplier_pic_contact'],
                'supplier_origin' => $supplier_row['supplier_origin'],
                'supplier_currency' => $supplier_row['supplier_currency'],
                'supplier_term' => $supplier_row['supplier_term'],
            )
        );
    }

    if($supplier_array){
        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Data' => $supplier_array
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