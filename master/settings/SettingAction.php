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

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = $_POST['action'];
    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");

    if($action == '1'){
        $settingImage = $_POST['settingImage'];
        $settingName = $_POST['settingName'];
        $companyId = $_POST['companyId'];
        $settingCaption = $_POST['settingCaption'];
        $username = $_POST['username'];

        $query = "INSERT INTO settingMenu(settingId, companyId, settingImage, settingName, settingCaption, createdBy, createdDt) VALUES (UUID(),'$companyId','$settingImage','$settingName','$settingCaption','$username','$currentDateTimeString')";
        
        if(mysqli_query($connect, $query)){
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

    } else if ($action == '2'){
        $settingId = $_POST['settingId'];
        $isTab = $_POST['isTab'];
        $tabCount = $_POST['tabCount'];
        $isData = $_POST['isData'];
        $dataURL = $_POST['dataURL'];
        $isCanNew = $_POST['isCanNew'];
        $createdBy = $_POST['createdBy'];

        $query = "INSERT INTO settingDetail(settingID, isTab, tabCount, isData, dataURL, isCanNew, createdBy, createdDt) VALUES ('$settingId','$isTab','$tabCount','$isData','$dataURL','$isCanNew','$createdBy','$currentDateTimeString');";

        if(mysqli_query($connect, $query)){
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