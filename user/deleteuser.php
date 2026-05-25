<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../connection/connection.php');

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $username = $_POST['username'];

    $query = "DELETE FROM user WHERE username = ?";
    $stmt = $connect->prepare($query);
    $stmt->bind_param('s', $username);
    $result = $stmt->execute();

    if($result){
        http_response_code(200);
        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Message' => 'User has been successfully deleted'
            )
        );
    } else {
        http_response_code(500);
        echo json_encode(
            array(
                'StatusCode' => 500,
                'Status' => 'Error',
                'Message' => 'Error: User cannot be deleted, there may be a mistake in the query'
            )
        );
    }

} else {
    http_response_code(404);
    echo json_encode(
        array(
            "StatusCode" => 404,
            'Status' => 'Error',
            "Message" => "Error: Invalid method. Only POST requests are allowed."
        )
    );
}
?>
