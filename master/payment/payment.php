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
function getAllPayment($conn): void {
    $result = mysqli_query($conn, "SELECT payment_id, payment_name FROM payment ORDER BY payment_name ASC");

    if ($result && mysqli_num_rows($result) > 0) {
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = ['Id' => $row['payment_id'], 'Payment Method' => $row['payment_name']];
        }
        jsonResponse(200, 'Success', $data);
    } else {
        jsonResponse(404, 'No payment methods found');
    }
}

// --- CREATE ---
function createPayment($conn, array $input): void {
    if (empty($input['payment_name']) || trim($input['payment_name']) === '') {
        jsonResponse(400, 'payment_name is required');
    }

    $payment_name = mysqli_real_escape_string($conn, trim($input['payment_name']));
    $id           = generateUUID();

    if (mysqli_query($conn, "INSERT INTO payment (payment_id, payment_name) VALUES ('$id', '$payment_name')")) {
        jsonResponse(201, 'Payment method created successfully', ['payment_id' => $id]);
    } else {
        jsonResponse(500, 'Failed to create payment method');
    }
}

// --- DELETE ---
function deletePayment($conn, ?string $payment_id): void {
    if (!$payment_id) jsonResponse(400, 'payment_id is required');

    $id    = mysqli_real_escape_string($conn, $payment_id);
    $check = mysqli_query($conn, "SELECT 1 FROM payment WHERE payment_id = '$id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) jsonResponse(404, 'Payment method not found');

    if (mysqli_query($conn, "DELETE FROM payment WHERE payment_id = '$id'")) {
        jsonResponse(200, 'Payment method deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete payment method');
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
        getAllPayment($conn);
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        createPayment($conn, $input);
        break;

    case 'DELETE':
        deletePayment($conn, $_GET['payment_id'] ?? null);
        break;

    default:
        jsonResponse(405, 'Method Not Allowed');
}
