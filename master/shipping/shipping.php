<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../general.php';
require_once '../../vendor/autoload.php';
require_once '../../connection/connection.php';
require_once '../../auth/middleware.php';

// --- GET ALL ---
function getAllShipping($conn): void {
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

    $result = mysqli_query($conn, $query);

    if ($result && mysqli_num_rows($result) > 0) {
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = ['shipment_id' => $row['shipment_id'], 'shipment_name' => $row['shipment_name']];
        }
        jsonResponse(200, 'Success', $data);
    } else {
        jsonResponse(404, 'No shipment schedules found');
    }
}

// --- CREATE ---
function createShipping($conn, array $input): void {
    if (empty($input['shipping_name']) || trim($input['shipping_name']) === '') {
        jsonResponse(400, 'shipping_name is required');
    }

    $shipping_name = mysqli_real_escape_string($conn, trim($input['shipping_name']));
    $id            = generateUUID();

    if (mysqli_query($conn, "INSERT INTO shipment (shipment_id, shipment_name) VALUES ('$id', '$shipping_name')")) {
        jsonResponse(201, 'Shipment schedule created successfully', ['shipment_id' => $id]);
    } else {
        jsonResponse(500, 'Failed to create shipment schedule');
    }
}

// ── Auth ──────────────────────────────────────────────
$decoded = verifyToken();
$userId  = $decoded->sub ?? '';

$conn   = DB::conn();
$GLOBALS['_log_conn']       = $conn;
$GLOBALS['_log_user']       = $userId;
$GLOBALS['_log_request_id'] = bin2hex(random_bytes(8));

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        getAllShipping($conn);
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        createShipping($conn, $input);
        break;

    default:
        jsonResponse(405, 'Method Not Allowed');
}
