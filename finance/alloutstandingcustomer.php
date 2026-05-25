<?php
// Header access is required
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Connection access
require_once('../connection/connection.php');

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $query = "SELECT DISTINCT
    A1.invoiceNumber,
    A1.invoiceDate,
    A2.company_top,
    A4.SO_Status_Name,
    F.due_amount,
    CASE
        WHEN F.due_amount = 0 THEN 'Paid'
        ELSE
            CASE
                WHEN DATEDIFF(CURDATE(), A1.invoiceDate) > DATEDIFF(A2.company_top, A1.invoiceDate) THEN 'Outstanding'
                ELSE 'Not Outstanding'
            END
    END AS paymentStatus,
    CASE
        WHEN A4.SO_Status_Name = 'Sales Invoice Approved' AND F.due_amount <> 0 THEN DATEDIFF(CURDATE(), A1.invoiceDate)
        ELSE NULL
    END AS outstandingDays
FROM
    salesInvoice A1
LEFT JOIN customer A2 ON A1.customerID = A2.company_id
LEFT JOIN salesOrder A3 ON A1.invoiceNumber = A3.SONumber
LEFT JOIN salesStatus A4 ON A3.SOStatus = A4.SO_Status_ID
LEFT JOIN financeItem F ON A1.invoiceNumber = F.invoice_number
WHERE F.due_amount != 0;";

    $result = mysqli_query($connect, $query);

    $array = array();
    while($row = mysqli_fetch_array($result)){
        array_push(
            $array,
            array(
                'invoiceNumber' => $row['invoiceNumber'],
                'invoiceDate' => $row['invoiceDate'],
                'company_top' => $row['company_top'],
                'SO_Status_Name' => $row['SO_Status_Name'],
                'paymentStatus' => $row['paymentStatus'],
                'outstandingDays' => $row['outstandingDays'],
                'due_amount' => $row['due_amount']
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