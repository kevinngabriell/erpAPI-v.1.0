<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

require_once('../../connection/connection.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $photo = $_POST['photo'];

    $query = "INSERT INTO documentWatermark (id, watermark) VALUES (?, ?)";
    $stmt = mysqli_prepare($connect, $query);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "is", $id, $photo);
        $result = mysqli_stmt_execute($stmt);

        if ($result) {
            http_response_code(200);
            echo json_encode(array(
                "StatusCode" => 200,
                'Status' => 'Success',
                "message" => "Success: Data updated successfully"
            ));
        } else {
            http_response_code(400);
            echo json_encode(array(
                "StatusCode" => 400,
                'Status' => 'Error',
                "message" => "Error: Unable to update data - " . mysqli_error($connect)
            ));
        }
    } else {
        http_response_code(500);
        echo json_encode(array(
            "StatusCode" => 500,
            'Status' => 'Error',
            "message" => "Error: Database error - " . mysqli_error($connect)
        ));
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
    $id = $_GET['id'];

    $query = "SELECT watermark FROM documentWatermark WHERE id = ?";
    $stmt = mysqli_prepare($connect, $query);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $watermark);
        mysqli_stmt_fetch($stmt);

        if ($watermark) {
            http_response_code(200);
            echo json_encode(array(
                "StatusCode" => 200,
                'Status' => 'Success',
                "data" => array(
                    "id" => $id,
                    "watermark" => $watermark
                )
            ));
        } else {
            http_response_code(404);
            echo json_encode(array(
                "StatusCode" => 404,
                'Status' => 'Error',
                "message" => "Error: Data not found"
            ));
        }
    } else {
        http_response_code(500);
        echo json_encode(array(
            "StatusCode" => 500,
            'Status' => 'Error',
            "message" => "Error: Database error - " . mysqli_error($connect)
        ));
    }
} else {
    http_response_code(404);
    echo json_encode(array(
        "StatusCode" => 404,
        'Status' => 'Error',
        "message" => "Error: Invalid method or missing parameters."
    ));
}
?>
