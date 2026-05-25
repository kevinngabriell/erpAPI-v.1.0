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
require_once('../connection/connection.php');

//Checking call API method
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $sppb_number = $_POST['sppb_number'];
    $sales_order_status = 'd9b88016-1f11-11ef-a';
    $update_by = $_POST['update_by'];
    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");
    $action = "Draft SPPB telah disetujui";

    $update_approve_query = "UPDATE salesOrder SET SOStatus = '$sales_order_status', UpdateBy = '$update_by', UpdateDt = '$currentDateTimeString' WHERE SONumber = '$sppb_number'";
    $insert_action_query = "INSERT INTO salesOrderHistory (SONumber, Action, ActionBy, ActionDt) VALUES ('$sppb_number', '$action', '$update_by', '$currentDateTimeString');";

    if(mysqli_query($connect, $update_approve_query) && mysqli_query($connect, $insert_action_query)){
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
            "message" => "Error: Invalid method. Only POST requests are allowed."
        )
    );
}
?>