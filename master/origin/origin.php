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

// GET /master/origin/origin.php
//   (no params)       → list all origins
//   ?origin_id=X      → detail for one origin
//   ?supplier=X       → origin ID + supplier ID based on supplier
if ($method === 'GET') {
    $origin_id = isset($_GET['origin_id']) ? $_GET['origin_id'] : null;
    $supplier  = isset($_GET['supplier'])  ? $_GET['supplier']  : null;

    $data = [];

    if ($origin_id) {
        $stmt = $connect->prepare("SELECT A1.origin_name, A1.origin_is_free_trade, A2.region_name FROM origin A1 JOIN region A2 ON A2.region_id = A1.origin_region WHERE A1.origin_id = ?");
        $stmt->bind_param('s', $origin_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data[] = ['origin_name' => $row['origin_name'], 'origin_is_free_trade' => $row['origin_is_free_trade'], 'region_name' => $row['region_name']];
        }

    } elseif ($supplier) {
        $stmt = $connect->prepare("SELECT supplier_origin, supplier_id FROM supplier WHERE supplier_id = ?");
        $stmt->bind_param('s', $supplier);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data[] = ['supplier_origin' => $row['supplier_origin'], 'supplier_id' => $row['supplier_id']];
        }

    } else {
        $result = mysqli_query($connect, "SELECT A1.origin_id, A1.origin_name, A1.origin_is_free_trade, A2.region_name FROM origin A1 JOIN region A2 ON A2.region_id = A1.origin_region ORDER BY A1.origin_name ASC");
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = ['origin_id' => $row['origin_id'], 'Country Name' => $row['origin_name'], 'Is Free Trade' => $row['origin_is_free_trade'], 'Region' => $row['region_name']];
        }
    }

    if ($data) {
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'Data' => $data]);
    } else {
        http_response_code(400);
        echo json_encode(['StatusCode' => 400, 'Status' => 'Error Bad Request, Result not found !']);
    }

// POST /master/origin/origin.php → insert new origin
} elseif ($method === 'POST') {
    $origin_name         = $_POST['origin_name'];
    $origin_region       = $_POST['origin_region'];
    $origin_is_free_trade= $_POST['origin_is_free_trade'];

    $check = $connect->prepare("SELECT origin_name FROM origin WHERE origin_name = ?");
    $check->bind_param('s', $origin_name);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        http_response_code(409);
        echo json_encode(['StatusCode' => 409, 'Status' => 'Error', 'message' => 'Error: Origin already exists in database']);
    } else {
        $stmt = $connect->prepare("INSERT IGNORE INTO origin (origin_id, origin_name, origin_region, origin_is_free_trade) VALUES (NULL, ?, ?, ?)");
        $stmt->bind_param('sss', $origin_name, $origin_region, $origin_is_free_trade);

        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'message' => 'Success: Data inserted successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => 'Error: Unable to insert data - ' . $connect->error]);
        }
    }

// PATCH /master/origin/origin.php → update origin
} elseif ($method === 'PATCH') {
    $body = json_decode(file_get_contents('php://input'), true);

    $origin_id           = $body['origin_id'];
    $origin_name         = $body['origin_name'];
    $origin_is_free_trade= $body['origin_is_free_trade'];

    $stmt = $connect->prepare("UPDATE origin SET origin_is_free_trade = ?, origin_name = ? WHERE origin_id = ?");
    $stmt->bind_param('sss', $origin_is_free_trade, $origin_name, $origin_id);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'message' => 'Success: Data updated successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => 'Error: Unable to update data - ' . $connect->error]);
    }

} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Error', 'message' => 'Method not allowed. Allowed: GET, POST, PATCH']);
}
