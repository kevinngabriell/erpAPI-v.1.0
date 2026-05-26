<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

require_once('../connection/connection.php');
require_once('../auth/middleware.php');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$decoded = verifyToken();
$method  = $_SERVER['REQUEST_METHOD'];

// ── GET: list all permission types ─────────────────────────────────────────
if ($method === 'GET') {
    $result = $connect->query("SELECT permission_id, permission_access FROM permission");

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            'permission_id'     => $row['permission_id'],
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
            'message'    => 'No permissions found.'
        ]);
    }

// ── POST: assign permission to a user ──────────────────────────────────────
} elseif ($method === 'POST') {
    if (empty($_POST['permission_id']) || empty($_POST['username'])) {
        http_response_code(400);
        echo json_encode([
            'StatusCode' => 400,
            'Status'     => 'Bad Request',
            'message'    => 'permission_id and username are required.'
        ]);
        exit;
    }

    $permission_id = $_POST['permission_id'];
    $username      = $_POST['username'];

    $stmt = $connect->prepare("UPDATE user SET permission_id = ? WHERE username = ?");
    $stmt->bind_param("ss", $permission_id, $username);

    if ($stmt->execute()) {
        echo json_encode([
            'StatusCode' => 200,
            'Status'     => 'Success',
            'message'    => 'Permission updated successfully.'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'StatusCode' => 500,
            'Status'     => 'Internal Server Error',
            'message'    => 'Failed to update permission.'
        ]);
    }

// ── PUT: update referral user limit ────────────────────────────────────────
} elseif ($method === 'PUT') {
    $input = [];
    parse_str(file_get_contents('php://input'), $input);

    if (empty($input['userLimit']) || empty($input['refferalCode'])) {
        http_response_code(400);
        echo json_encode([
            'StatusCode' => 400,
            'Status'     => 'Bad Request',
            'message'    => 'userLimit and refferalCode are required.'
        ]);
        exit;
    }

    $userLimit    = $input['userLimit'];
    $refferalCode = $input['refferalCode'];

    if (!is_numeric($userLimit) || (int)$userLimit < 0) {
        http_response_code(400);
        echo json_encode([
            'StatusCode' => 400,
            'Status'     => 'Bad Request',
            'message'    => 'userLimit must be a non-negative integer.'
        ]);
        exit;
    }

    $userLimit = (int)$userLimit;

    $stmt = $connect->prepare("UPDATE refferal SET limit_user = ? WHERE refferal_id = ?");
    $stmt->bind_param("is", $userLimit, $refferalCode);

    if ($stmt->execute()) {
        echo json_encode([
            'StatusCode' => 200,
            'Status'     => 'Success',
            'message'    => 'User limit updated successfully.'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'StatusCode' => 500,
            'Status'     => 'Internal Server Error',
            'message'    => 'Failed to update user limit.'
        ]);
    }

// ── Method not allowed ──────────────────────────────────────────────────────
} else {
    http_response_code(405);
    echo json_encode([
        'StatusCode' => 405,
        'Status'     => 'Method Not Allowed',
        'message'    => 'Allowed methods: GET, POST, PUT.'
    ]);
}
