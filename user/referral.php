<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
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

$body      = json_decode(file_get_contents('php://input'), true);
$companyId = $body['company_id'] ?? null;
$limitUser = $body['limit_user'] ?? null;

if (!$companyId || !$limitUser) {
    http_response_code(400);
    echo json_encode(['statusCode' => 400, 'status' => 'Error', 'message' => 'company_id and limit_user are required']);
    exit;
}

$stmt = $connect->prepare("SELECT company_name FROM company WHERE company_id = ?");
$stmt->bind_param('s', $companyId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    http_response_code(404);
    echo json_encode(['statusCode' => 404, 'status' => 'Error', 'message' => 'Company not found']);
    exit;
}

$chars      = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
$referralId = '';
for ($i = 0; $i < 6; $i++) {
    $referralId .= $chars[mt_rand(0, strlen($chars) - 1)];
}

$stmt = $connect->prepare("INSERT IGNORE INTO refferal (refferal_id, company, limit_user) VALUES (?, ?, ?)");
$stmt->bind_param('ssi', $referralId, $companyId, $limitUser);

if ($stmt->execute()) {
    http_response_code(201);
    echo json_encode([
        'statusCode' => 201,
        'status'     => 'Success',
        'message'    => 'Referral created successfully',
        'data'       => [
            'referralId' => $referralId,
            'companyId'  => $companyId,
            'limitUser'  => (int) $limitUser,
        ],
    ]);
} else {
    http_response_code(500);
    echo json_encode(['statusCode' => 500, 'status' => 'Error', 'message' => 'Failed to create referral']);
}
