<?php
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

$username         = $_POST['username']          ?? null;
$verificationCode = $_POST['verification_code'] ?? null;

if (!$username || !$verificationCode) {
    http_response_code(400);
    echo json_encode(['statusCode' => 400, 'status' => 'Error', 'message' => 'username and verification_code are required']);
    exit;
}

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

$hashedPassword = password_hash('123456', PASSWORD_DEFAULT);
$now = (new DateTime())->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s');

$stmt = $connect->prepare("UPDATE user SET password = ? WHERE username = ?");
$stmt->bind_param('ss', $hashedPassword, $username);

$stmt2 = $connect->prepare("UPDATE verification SET isUsed = 1, usedDt = ? WHERE code = ?");
$stmt2->bind_param('ss', $now, $verificationCode);

if ($stmt->execute() && $stmt2->execute()) {
    http_response_code(200);
    echo json_encode(['statusCode' => 200, 'status' => 'Success', 'message' => 'Password has been reset to default. Please change it after logging in.']);
} else {
    http_response_code(500);
    echo json_encode(['statusCode' => 500, 'status' => 'Error', 'message' => 'Password reset failed. Please try again.']);
}
