<?php
// Header access is required

// Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Connection access
require_once('../connection/connection.php');

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $query = "SELECT A1.invoiceNumber, A2.company_name, A1.invoiceDate, A1.salesOrder
    FROM salesInvoice A1
    LEFT JOIN customer A2 ON A1.customerID = A2.company_id
    ORDER BY A1.InsertDt DESC
    LIMIT 3;";

    $result = mysqli_query($connect, $query);

    $array = array();
    while($row = mysqli_fetch_array($result)){
        array_push(
            $array,
            array(
                'invoiceNumber' => $row['invoiceNumber'],
                'invoiceDate' => $row['invoiceDate'],
                'company_name' => $row['company_name'],
                'salesOrder' => $row['salesOrder']
            )
        );
    }

    if($array){
        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Data' => $array
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