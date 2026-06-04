<?php
header("Content-Type: application/json");

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once '../connection/connection.php';
require_once '../auth/middleware.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$authUser = verifyToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['statusCode' => 405, 'status' => 'Error', 'message' => 'Method not allowed. Use POST.']);
    exit;
}

$body     = json_decode(file_get_contents('php://input'), true);
$username = $body['username'] ?? null;

if (!$username) {
    http_response_code(400);
    echo json_encode(['statusCode' => 400, 'status' => 'Error', 'message' => 'username is required']);
    exit;
}

$hashed = password_hash('123456', PASSWORD_DEFAULT);
$stmt   = $connect->prepare("UPDATE user SET password = ? WHERE username = ?");
$stmt->bind_param('ss', $hashed, $username);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    http_response_code(200);
    echo json_encode(['statusCode' => 200, 'status' => 'Success', 'message' => 'Account password has been reset to default']);
} elseif ($stmt->affected_rows === 0) {
    http_response_code(404);
    echo json_encode(['statusCode' => 404, 'status' => 'Error', 'message' => 'User not found']);
} else {
    http_response_code(500);
    echo json_encode(['statusCode' => 500, 'status' => 'Error', 'message' => 'Failed to reset account']);
}
