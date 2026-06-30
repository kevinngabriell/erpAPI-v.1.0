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
function getAllTerm($conn): void {
    $result = mysqli_query($conn, "SELECT term_id, term_name FROM term ORDER BY term_name ASC");

    if ($result && mysqli_num_rows($result) > 0) {
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = ['term_id' => $row['term_id'], 'Term' => $row['term_name']];
        }
        jsonResponse(200, 'Success', $data);
    } else {
        jsonResponse(404, 'No terms found');
    }
}

// --- CREATE ---
function createTerm($conn, array $input): void {
    if (empty($input['term_name']) || trim($input['term_name']) === '') {
        jsonResponse(400, 'term_name is required');
    }

    $term_name = mysqli_real_escape_string($conn, trim($input['term_name']));
    $id        = generateUUID();

    if (mysqli_query($conn, "INSERT INTO term (term_id, term_name) VALUES ('$id', '$term_name')")) {
        jsonResponse(201, 'Term created successfully', ['term_id' => $id]);
    } else {
        jsonResponse(500, 'Failed to create term');
    }
}

// --- DELETE ---
function deleteTerm($conn, ?string $term_id): void {
    if (!$term_id) jsonResponse(400, 'term_id is required');

    $id    = mysqli_real_escape_string($conn, $term_id);
    $check = mysqli_query($conn, "SELECT 1 FROM term WHERE term_id = '$id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) jsonResponse(404, 'Term not found');

    if (mysqli_query($conn, "DELETE FROM term WHERE term_id = '$id'")) {
        jsonResponse(200, 'Term deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete term');
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
        getAllTerm($conn);
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        createTerm($conn, $input);
        break;

    case 'DELETE':
        deleteTerm($conn, $_GET['term_id'] ?? null);
        break;

    default:
        jsonResponse(405, 'Method Not Allowed');
}
