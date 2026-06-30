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
function getAllShipVia($conn): void {
    $result = mysqli_query($conn, "SELECT shipID, shipName FROM shipVia ORDER BY shipName ASC");

    if ($result && mysqli_num_rows($result) > 0) {
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = ['shipID' => $row['shipID'], 'shipName' => $row['shipName']];
        }
        jsonResponse(200, 'Success', $data);
    } else {
        jsonResponse(404, 'No ship-via options found');
    }
}

// --- CREATE ---
function createShipVia($conn, array $input): void {
    if (empty($input['shipvia_name']) || trim($input['shipvia_name']) === '') {
        jsonResponse(400, 'shipvia_name is required');
    }

    $shipvia_name = mysqli_real_escape_string($conn, trim($input['shipvia_name']));
    $id           = generateUUID();

    if (mysqli_query($conn, "INSERT INTO shipVia (shipID, shipName) VALUES ('$id', '$shipvia_name')")) {
        jsonResponse(201, 'Ship-via created successfully', ['shipID' => $id]);
    } else {
        jsonResponse(500, 'Failed to create ship-via');
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
        getAllShipVia($conn);
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        createShipVia($conn, $input);
        break;

    default:
        jsonResponse(405, 'Method Not Allowed');
}
