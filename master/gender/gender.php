<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../../connection/connection.php');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// GET /master/gender/gender.php → list all genders
if ($method === 'GET') {
    $result = mysqli_query($connect, "SELECT * FROM gender");
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = ['id' => $row['id'], 'gender_name' => $row['gender_name']];
    }

    if ($data) {
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'Data' => $data]);
    } else {
        http_response_code(400);
        echo json_encode(['StatusCode' => 400, 'Status' => 'Error Bad Request, Result not found !']);
    }

// POST /master/gender/gender.php → insert new gender
} elseif ($method === 'POST') {
    $gender_name = $_POST['gender_name'];

    $stmt = $connect->prepare("INSERT INTO gender (id, gender_name) VALUES (UUID(), ?)");
    $stmt->bind_param('s', $gender_name);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'message' => 'Success: Data inserted successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => 'Error: Unable to insert data - ' . $connect->error]);
    }

} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Error', 'message' => 'Method not allowed. Allowed: GET, POST']);
}
