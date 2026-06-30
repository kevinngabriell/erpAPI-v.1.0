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

// GET /master/ppn/ppn.php
//   (no params)       → list all PPN types
//   ?PPNType_id=X     → get percentage for a specific PPN type
if ($method === 'GET') {
    $ppn_type_id = isset($_GET['PPNType_id']) ? $_GET['PPNType_id'] : null;

    if ($ppn_type_id) {
        $stmt = $connect->prepare("SELECT PPNPercentage FROM salesPPNType WHERE PPNType_id = ?");
        $stmt->bind_param('s', $ppn_type_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = ['PPNPercentage' => $row['PPNPercentage']];
        }
    } else {
        $result = mysqli_query($connect, "SELECT PPNType_id, PPNType_name, PPNPercentage FROM salesPPNType");
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = ['PPNType_id' => $row['PPNType_id'], 'PPNType_name Name' => $row['PPNType_name'], 'PPNPercentage' => $row['PPNPercentage']];
        }
    }

    if ($data) {
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'Data' => $data]);
    } else {
        http_response_code(400);
        echo json_encode(['StatusCode' => 400, 'Status' => 'Error Bad Request, Result not found !']);
    }

// POST /master/ppn/ppn.php → insert new PPN type
} elseif ($method === 'POST') {
    $ppn_name = $_POST['ppn_name'];

    $stmt = $connect->prepare("INSERT INTO salesPPNType (PPNTYPE_id, PPNType_name) VALUES (UUID(), ?)");
    $stmt->bind_param('s', $ppn_name);

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
