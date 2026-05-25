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
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $username = $_POST['username'];
    $password = $_POST['password'];
    $permission_id = 'a9b8390e-bfd8-11ee-9';
    $verification_code = $_POST['verification_code'];
    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");

    $verify_query = "SELECT * FROM verification WHERE code = '$verification_code';";
    $verify_result = $connect->query($verify_query);

    if ($verify_result->num_rows > 0) { {
        $verify_row = $verify_result->fetch_assoc();
        $expired_date = $verify_row['expiredDt'];
        $is_used = $verify_row['isUsed'];

        if ($is_used) {
            http_response_code(500);
            echo json_encode(
                array(
                    "StatusCode" => 500,
                    'Status' => 'Not Found',
                    "message" => "Error: Your verification code is not valid"
                )
            );
        } else if (strtotime($expired_date) < time()) {
            http_response_code(500);
            echo json_encode(
                array(
                    "StatusCode" => 500,
                    'Status' => 'Not Found',
                    "message" => "Error: Your verification code is not valid"
                )
            );
        } else {
            // Code is valid

            $password = password_hash($password, PASSWORD_DEFAULT);

            $search_username_query = "SELECT username FROM user WHERE username = '$username';";
            $result = $connect->query($search_username_query);
            $row = $result->fetch_assoc();

            if($row){
                http_response_code(203);
                echo json_encode(
                    array(
                        "StatusCode" => 203,
                        'Status' => 'Not Found',
                        "message" => "Error: The username is already exist. Please use another username"
                    )
                );
            } else {
                $insert_user_query = "INSERT IGNORE INTO user (first_name, last_name, username, password, permission_id, unique_id) VALUES ('$first_name','$last_name','$username','$password','$permission_id','FGr9km');";   
                $update_verification_query = "UPDATE verification SET isUsed = 1, usedDt = '$currentDateTimeString' WHERE code = '$verification_code';";

                if(mysqli_query($connect, $insert_user_query) && mysqli_query($connect, $update_verification_query)){
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
        }}
    } else {
        http_response_code(500);
        echo json_encode(
            array(
                "StatusCode" => 500,
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
?>