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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $verfication_code = $_POST['verfication_code'];
    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");

    $verify_query = "SELECT * FROM verification WHERE code = '$verfication_code';";
    $verify_result = $connect->query($verify_query);
    
    if ($verify_result->num_rows > 0) {
        $verify_row = $verify_result->fetch_assoc();
        $expired_date = $verify_row['expiredDt'];
        $is_used = $verify_row['isUsed'];

        if ($is_used) {
            http_response_code(204);
            echo json_encode(
                array(
                    "StatusCode" => 204,
                    'Status' => 'Not Found',
                    "message" => "Error: Your verification code is not valid"
                )
            );
        } elseif (strtotime($expired_date) < time()) {
            http_response_code(204);
            echo json_encode(
                array(
                    "StatusCode" => 204,
                    'Status' => 'Not Found',
                    "message" => "Error: Your verification code is not valid"
                )
            );
        } else {
            // Code is valid
            $password = password_hash('123456', PASSWORD_DEFAULT);

            $query = "UPDATE user SET password = '$password' WHERE username = '$username';";
            $update_verification_query = "UPDATE verification SET isUsed = 1, usedDt = '$currentDateTimeString' WHERE code = '$verfication_code';";

            if(mysqli_query($connect, $query) && mysqli_query($connect, $update_verification_query)){
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
        http_response_code(204);
        echo json_encode(
            array(
                "StatusCode" => 204,
                'Status' => 'Not Found',
                "message" => "Error: Your verification code is not valid"
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