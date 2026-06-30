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

// GET /master/shipping/shipping.php → list all shipment schedules
if ($method === 'GET') {
    $query = "SELECT * FROM shipment ORDER BY
        CASE
            WHEN shipment_name LIKE '%early january%'   THEN 1
            WHEN shipment_name LIKE '%mid january%'     THEN 2
            WHEN shipment_name LIKE '%end january%'     THEN 3
            WHEN shipment_name LIKE '%early february%'  THEN 4
            WHEN shipment_name LIKE '%mid february%'    THEN 5
            WHEN shipment_name LIKE '%end february%'    THEN 6
            WHEN shipment_name LIKE '%early march%'     THEN 7
            WHEN shipment_name LIKE '%mid march%'       THEN 8
            WHEN shipment_name LIKE '%end march%'       THEN 9
            WHEN shipment_name LIKE '%early april%'     THEN 10
            WHEN shipment_name LIKE '%mid april%'       THEN 11
            WHEN shipment_name LIKE '%end april%'       THEN 12
            WHEN shipment_name LIKE '%early may%'       THEN 13
            WHEN shipment_name LIKE '%mid may%'         THEN 14
            WHEN shipment_name LIKE '%end may%'         THEN 15
            WHEN shipment_name LIKE '%early june%'      THEN 16
            WHEN shipment_name LIKE '%mid june%'        THEN 17
            WHEN shipment_name LIKE '%end june%'        THEN 18
            WHEN shipment_name LIKE '%early july%'      THEN 19
            WHEN shipment_name LIKE '%mid july%'        THEN 20
            WHEN shipment_name LIKE '%end july%'        THEN 21
            WHEN shipment_name LIKE '%early august%'    THEN 22
            WHEN shipment_name LIKE '%mid august%'      THEN 23
            WHEN shipment_name LIKE '%end august%'      THEN 24
            ELSE 99
        END";

    $result = mysqli_query($connect, $query);
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = ['shipment_id' => $row['shipment_id'], 'shipment_name' => $row['shipment_name']];
    }

    if ($data) {
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'Data' => $data]);
    } else {
        http_response_code(400);
        echo json_encode(['StatusCode' => 400, 'Status' => 'Error Bad Request, Result not found !']);
    }

// POST /master/shipping/shipping.php → insert new shipment schedule
} elseif ($method === 'POST') {
    $shipping_name = $_POST['shipping_name'];

    $stmt = $connect->prepare("INSERT INTO shipment (shipment_id, shipment_name) VALUES (UUID(), ?)");
    $stmt->bind_param('s', $shipping_name);

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
