<?php
// Header access is required
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Connection access
require_once('../connection/connection.php');

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");
    $action = $_POST['action'];

    if ($action == '1') {
        $expiration_datetime = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        $verification_code = mt_rand(100000, 999999);
        $createdBy = $_POST['createdBy'];

        $query = "INSERT INTO verification(code, createdBy, createdDt, expiredDt, isUsed) 
                  VALUES ('$verification_code','$createdBy','$currentDateTimeString','$expiration_datetime',0)";

        if (mysqli_query($connect, $query)) {
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
                    "message" => "Error: Unable to insert data - " . mysqli_error($connect)
                )
            );
        }
    } else if ($action == '2') {
        $verification_code = $_POST['verification_code'];

        // Check if the code has expired
        $query = "SELECT expiredDt FROM verification WHERE code = '$verification_code'";
        $result = mysqli_query($connect, $query);
        $row = mysqli_fetch_assoc($result);
        $expiredDatetime = $row['expiredDt'];

        if ($currentDateTimeString > $expiredDatetime) {
            // Code has expired
            http_response_code(400);
            echo json_encode(
                array(
                    "StatusCode" => 400,
                    'Status' => 'Error',
                    "message" => "Error: Verification code has expired"
                )
            );
        } else {
            // Check if the code has already been used
            $query = "SELECT isUsed FROM verification WHERE code = '$verification_code'";
            $result = mysqli_query($connect, $query);
            $row = mysqli_fetch_assoc($result);
            $isUsed = $row['isUsed'];

            if ($isUsed == 1) {
                // Code has already been used
                http_response_code(400);
                echo json_encode(
                    array(
                        "StatusCode" => 400,
                        'Status' => 'Error',
                        "message" => "Error: Verification code has already been used"
                    )
                );
            } else {
                // Update the code as it's valid and not used yet
                $query = "UPDATE verification SET isUsed = 1, usedDt = '$currentDateTimeString' WHERE code = '$verification_code'";

                if (mysqli_query($connect, $query)) {
                    http_response_code(200);
                    echo json_encode(
                        array(
                            "StatusCode" => 200,
                            'Status' => 'Success',
                            "message" => "Success: Data updated successfully"
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
        }
    } else {
        http_response_code(400);
        echo json_encode(
            array(
                "StatusCode" => 400,
                'Status' => 'Error',
                "message" => "Error: Invalid action"
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
