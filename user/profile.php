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
$method   = $_SERVER['REQUEST_METHOD'];

// ── GET /user/profile.php?username=xxx ─────────────────────────────────────
if ($method === 'GET') {
    $username = $_GET['username'] ?? null;

    if (!$username) {
        http_response_code(400);
        echo json_encode(['statusCode' => 400, 'status' => 'Error', 'message' => 'username query param is required']);
        exit;
    }

    $stmt = $connect->prepare(
        "SELECT CONCAT(A1.first_name, ' ', A1.last_name) AS full_name,
                A1.username,
                A2.permission_access
         FROM user A1
         LEFT JOIN permission A2 ON A1.permission_id = A2.permission_id
         WHERE A1.username = ?"
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row) {
        http_response_code(200);
        echo json_encode(['statusCode' => 200, 'status' => 'Success', 'data' => $row]);
    } else {
        http_response_code(404);
        echo json_encode(['statusCode' => 404, 'status' => 'Error', 'message' => 'User not found']);
    }

// ── PATCH /user/profile.php  { "username": "...", "new_password": "..." } ───
} elseif ($method === 'PATCH') {
    $body        = json_decode(file_get_contents('php://input'), true);
    $username    = $body['username']     ?? null;
    $newPassword = $body['new_password'] ?? null;

    if (!$username || !$newPassword) {
        http_response_code(400);
        echo json_encode(['statusCode' => 400, 'status' => 'Error', 'message' => 'username and new_password are required']);
        exit;
    }

    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt   = $connect->prepare("UPDATE user SET password = ? WHERE username = ?");
    $stmt->bind_param('ss', $hashed, $username);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        http_response_code(200);
        echo json_encode(['statusCode' => 200, 'status' => 'Success', 'message' => 'Password updated successfully']);
    } elseif ($stmt->affected_rows === 0) {
        http_response_code(404);
        echo json_encode(['statusCode' => 404, 'status' => 'Error', 'message' => 'User not found']);
    } else {
        http_response_code(500);
        echo json_encode(['statusCode' => 500, 'status' => 'Error', 'message' => 'Failed to update password']);
    }

// ── DELETE /user/profile.php  { "username": "..." } ─────────────────────────
} elseif ($method === 'DELETE') {
    $body     = json_decode(file_get_contents('php://input'), true);
    $username = $body['username'] ?? null;

    if (!$username) {
        http_response_code(400);
        echo json_encode(['statusCode' => 400, 'status' => 'Error', 'message' => 'username is required']);
        exit;
    }

    $stmt = $connect->prepare("DELETE FROM user WHERE username = ?");
    $stmt->bind_param('s', $username);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        http_response_code(200);
        echo json_encode(['statusCode' => 200, 'status' => 'Success', 'message' => 'User deleted successfully']);
    } elseif ($stmt->affected_rows === 0) {
        http_response_code(404);
        echo json_encode(['statusCode' => 404, 'status' => 'Error', 'message' => 'User not found']);
    } else {
        http_response_code(500);
        echo json_encode(['statusCode' => 500, 'status' => 'Error', 'message' => 'Failed to delete user']);
    }

} else {
    http_response_code(405);
    echo json_encode(['statusCode' => 405, 'status' => 'Error', 'message' => 'Method not allowed. Supported: GET, PATCH, DELETE']);
}
