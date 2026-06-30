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

// GET /master/uom/uom.php → list all units of measure
if ($method === 'GET') {
    $result = mysqli_query($connect, "SELECT uomID, uomName FROM unitOfMeasure");
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = ['uomID' => $row['uomID'], 'uomName' => $row['uomName']];
    }

    if ($data) {
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'Data' => $data]);
    } else {
        http_response_code(400);
        echo json_encode(['StatusCode' => 400, 'Status' => 'Error Bad Request, Result not found !']);
    }

// POST /master/uom/uom.php → insert new unit of measure
} elseif ($method === 'POST') {
    $uom_name         = $_POST['uom_name'];
    $conversion_factor = $_POST['conversion_factor'];

    $stmt = $connect->prepare("INSERT INTO unitOfMeasure (uomID, uomName, conversionFactor) VALUES (UUID(), ?, ?)");
    $stmt->bind_param('ss', $uom_name, $conversion_factor);

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
