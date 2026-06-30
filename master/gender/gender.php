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
function getAllGender($conn): void {
    $result = mysqli_query($conn, "SELECT id, gender_name FROM gender ORDER BY gender_name ASC");

    if ($result && mysqli_num_rows($result) > 0) {
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = ['id' => $row['id'], 'gender_name' => $row['gender_name']];
        }
        jsonResponse(200, 'Success', $data);
    } else {
        jsonResponse(404, 'No genders found');
    }
}

// --- CREATE ---
function createGender($conn, array $input): void {
    if (empty($input['gender_name']) || trim($input['gender_name']) === '') {
        jsonResponse(400, 'gender_name is required');
    }

    $gender_name = mysqli_real_escape_string($conn, trim($input['gender_name']));
    $id          = generateUUID();

    if (mysqli_query($conn, "INSERT INTO gender (id, gender_name) VALUES ('$id', '$gender_name')")) {
        jsonResponse(201, 'Gender created successfully', ['id' => $id]);
    } else {
        jsonResponse(500, 'Failed to create gender');
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
        getAllGender($conn);
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        createGender($conn, $input);
        break;

    default:
        jsonResponse(405, 'Method Not Allowed');
}
