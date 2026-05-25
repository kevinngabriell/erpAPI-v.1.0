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

    $shipment_query = "SELECT *
        FROM shipment
        ORDER BY
            CASE
                WHEN shipment_name LIKE '%early january%' THEN 1
                WHEN shipment_name LIKE '%mid january%' THEN 2
                WHEN shipment_name LIKE '%end january%' THEN 3
                WHEN shipment_name LIKE '%early february%' THEN 4
                WHEN shipment_name LIKE '%mid february%' THEN 5
                WHEN shipment_name LIKE '%end february%' THEN 6
                WHEN shipment_name LIKE '%early march%' THEN 7
                WHEN shipment_name LIKE '%mid march%' THEN 8
                WHEN shipment_name LIKE '%end march%' THEN 9
                WHEN shipment_name LIKE '%early april%' THEN 10
                WHEN shipment_name LIKE '%mid april%' THEN 11
                WHEN shipment_name LIKE '%end april%' THEN 12
                WHEN shipment_name LIKE '%early may%' THEN 13
                WHEN shipment_name LIKE '%mid may%' THEN 14
                WHEN shipment_name LIKE '%end may%' THEN 15
                WHEN shipment_name LIKE '%early june%' THEN 16
                WHEN shipment_name LIKE '%mid june%' THEN 17
                WHEN shipment_name LIKE '%end june%' THEN 18
                WHEN shipment_name LIKE '%early july%' THEN 19
                WHEN shipment_name LIKE '%mid july%' THEN 20
                WHEN shipment_name LIKE '%end july%' THEN 21
                WHEN shipment_name LIKE '%early august%' THEN 22
                WHEN shipment_name LIKE '%mid august%' THEN 23
                WHEN shipment_name LIKE '%end august%' THEN 24
                -- Add more cases for other months as needed
                ELSE 99  -- Default case, if the month name is not recognized
            END;";
    $shipment_result = mysqli_query($connect, $shipment_query);

    $shipment_array = array();
    while($shipment_row = mysqli_fetch_array($shipment_result)){
        array_push(
            $shipment_array,
            array(
                'shipment_id' => $shipment_row['shipment_id'],
                'shipment_name' => $shipment_row['shipment_name']
            )
        );
    }

    if($shipment_array){
        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Data' => $shipment_array
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