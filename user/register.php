<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once '../connection/connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['statusCode' => 405, 'status' => 'Error', 'message' => 'Method not allowed. Use POST.']);
    exit;
}

$firstName        = $_POST['first_name']        ?? null;
$lastName         = $_POST['last_name']         ?? null;
$username         = $_POST['username']          ?? null;
$password         = $_POST['password']          ?? null;
$verificationCode = $_POST['verification_code'] ?? null;

if (!$firstName || !$lastName || !$username || !$password || !$verificationCode) {
    http_response_code(400);
    echo json_encode(['statusCode' => 400, 'status' => 'Error', 'message' => 'first_name, last_name, username, password, and verification_code are required']);
    exit;
}

$permissionId = 'a9b8390e-bfd8-11ee-9';

$stmt = $connect->prepare("SELECT expiredDt, isUsed FROM verification WHERE code = ?");
$stmt->bind_param('s', $verificationCode);
$stmt->execute();
$verifyResult = $stmt->get_result();

if ($verifyResult->num_rows === 0) {
    http_response_code(422);
    echo json_encode(['statusCode' => 422, 'status' => 'Error', 'message' => 'Verification code not found']);
    exit;
}

$verifyRow = $verifyResult->fetch_assoc();

if ($verifyRow['isUsed']) {
    http_response_code(422);
    echo json_encode(['statusCode' => 422, 'status' => 'Error', 'message' => 'Verification code has already been used']);
    exit;
}

if (strtotime($verifyRow['expiredDt']) < time()) {
    http_response_code(422);
    echo json_encode(['statusCode' => 422, 'status' => 'Error', 'message' => 'Verification code has expired']);
    exit;
}

$stmt = $connect->prepare("SELECT username FROM user WHERE username = ?");
$stmt->bind_param('s', $username);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    http_response_code(409);
    echo json_encode(['statusCode' => 409, 'status' => 'Error', 'message' => 'Username already exists. Please choose another.']);
    exit;
}

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$now = (new DateTime())->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s');

$stmt = $connect->prepare("INSERT IGNORE INTO user (first_name, last_name, username, password, permission_id, unique_id) VALUES (?, ?, ?, ?, ?, 'FGr9km')");
$stmt->bind_param('sssss', $firstName, $lastName, $username, $hashedPassword, $permissionId);

$stmt2 = $connect->prepare("UPDATE verification SET isUsed = 1, usedDt = ? WHERE code = ?");
$stmt2->bind_param('ss', $now, $verificationCode);

if ($stmt->execute() && $stmt2->execute()) {
    http_response_code(201);
    echo json_encode(['statusCode' => 201, 'status' => 'Success', 'message' => 'User registered successfully']);
} else {
    http_response_code(500);
    echo json_encode(['statusCode' => 500, 'status' => 'Error', 'message' => 'Registration failed. Please try again.']);
}
