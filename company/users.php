<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

require_once('../connection/connection.php');
require_once('../auth/middleware.php');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$decoded = verifyToken();

// ── GET: list users by referral ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (empty($_GET['referral_id'])) {
        http_response_code(400);
        echo json_encode([
            'StatusCode' => 400,
            'Status'     => 'Bad Request',
            'message'    => 'referral_id is required.'
        ]);
        exit;
    }

    $referral_id = $_GET['referral_id'];

    $stmt = $connect->prepare(
        "SELECT CONCAT(A2.first_name, ' ', A2.last_name) AS full_name,
                A2.username, A3.permission_access, A4.company_name
         FROM refferal A1
         JOIN user A2 ON A1.refferal_id = A2.unique_id
         JOIN permission A3 ON A2.permission_id = A3.permission_id
         JOIN company A4 ON A1.company = A4.company_id
         WHERE A1.refferal_id = ?"
    );
    $stmt->bind_param("s", $referral_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            'name'              => $row['full_name'],
            'username'          => $row['username'],
            'company_name'      => $row['company_name'],
            'permission_access' => $row['permission_access']
        ];
    }

    if (!empty($data)) {
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'Data' => $data]);
    } else {
        http_response_code(404);
        echo json_encode([
            'StatusCode' => 404,
            'Status'     => 'Not Found',
            'message'    => 'No users found for the specified referral.'
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode([
        'StatusCode' => 405,
        'Status'     => 'Method Not Allowed',
        'message'    => 'Only GET requests are allowed.'
    ]);
}
