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
require_once('../../connection/connection.php');

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code_new = $_POST['code_new'];
    $account_name_new = $_POST['account_name_new'];
    $account_name_alias_new = $_POST['account_name_alias_new'];
    $code_before = $_POST['code_before'];
    $account_name_before = $_POST['account_name_before'];
    $account_name_alias_before = $_POST['account_name_alias_before'];

    $update_query = "UPDATE account_code SET code = '$code_new', account_name = '$account_name_new', account_name_alias = '$account_name_alias_new' WHERE code = '$code_before' AND account_name = '$account_name_before' AND account_name_alias = '$account_name_alias_before'";

    if (mysqli_query($connect, $update_query)) {
        http_response_code(200);
        echo json_encode(
            array(
                "StatusCode" => 200,
                'Status' => 'Success',
                "message" => "Success: Account Code Data updated successfully"
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
