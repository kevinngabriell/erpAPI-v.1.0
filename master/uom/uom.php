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
function getAllUOM($conn): void {
    $result = mysqli_query($conn, "SELECT uomID, uomName FROM unitOfMeasure ORDER BY uomName ASC");

    if ($result && mysqli_num_rows($result) > 0) {
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = ['uomID' => $row['uomID'], 'uomName' => $row['uomName']];
        }
        jsonResponse(200, 'Success', $data);
    } else {
        jsonResponse(404, 'No units of measure found');
    }
}

// --- CREATE ---
function createUOM($conn, array $input): void {
    $required = ['uom_name', 'conversion_factor'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            jsonResponse(400, "$field is required");
        }
    }

    $uom_name         = mysqli_real_escape_string($conn, trim($input['uom_name']));
    $conversion_factor = mysqli_real_escape_string($conn, trim($input['conversion_factor']));
    $id               = generateUUID();

    if (mysqli_query($conn, "INSERT INTO unitOfMeasure (uomID, uomName, conversionFactor) VALUES ('$id', '$uom_name', '$conversion_factor')")) {
        jsonResponse(201, 'Unit of measure created successfully', ['uomID' => $id]);
    } else {
        jsonResponse(500, 'Failed to create unit of measure');
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
        getAllUOM($conn);
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        createUOM($conn, $input);
        break;

    default:
        jsonResponse(405, 'Method Not Allowed');
}
