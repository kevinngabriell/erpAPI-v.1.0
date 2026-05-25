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
if($_SERVER['REQUEST_METHOD'] === 'GET'){
    $createdBy = $_GET['createdBy'];

    $query = "SELECT code FROM verification WHERE isUsed = 0 AND createdBy = '$createdBy' ORDER BY createdDt DESC";
    $setting_result = mysqli_query($connect, $query);

        $setting_array = array();
        while ($setting_row = mysqli_fetch_assoc($setting_result)) {
            $setting_array[] = $setting_row;
        }

        if (!empty($setting_array)) {
            echo json_encode(
                array(
                    'StatusCode' => 200,
                    'Status' => 'Success',
                    'Data' => $setting_array
                )
            );
        } else {
            http_response_code(404);
            echo json_encode(
                array(
                    'StatusCode' => 404,
                    'Status' => 'Error',
                    'Message' => 'Settings not found'
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