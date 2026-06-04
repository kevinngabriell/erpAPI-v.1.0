<?php
header("Content-Type: application/json");

require_once('../connection/connection.php');
require_once('../auth/middleware.php');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$decoded = verifyToken();
$method  = $_SERVER['REQUEST_METHOD'];

// ── GET: list targeting by company ─────────────────────────────────────────
if ($method === 'GET') {
    if (empty($_GET['company_id'])) {
        http_response_code(400);
        echo json_encode([
            'StatusCode' => 400,
            'Status'     => 'Bad Request',
            'message'    => 'company_id is required.'
        ]);
        exit;
    }

    $company_id = $_GET['company_id'];

    $stmt = $connect->prepare(
        "SELECT targeting_id, target_year, target_value
         FROM targeting WHERE company = ? ORDER BY target_year ASC"
    );
    $stmt->bind_param("s", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            'targeting_id' => $row['targeting_id'],
            'target_year'  => $row['target_year'],
            'target_value' => $row['target_value']
        ];
    }

    if (!empty($data)) {
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'Data' => $data]);
    } else {
        http_response_code(404);
        echo json_encode([
            'StatusCode' => 404,
            'Status'     => 'Not Found',
            'message'    => 'No targeting data found for the specified company.'
        ]);
    }

// ── POST: create new targeting ──────────────────────────────────────────────
} elseif ($method === 'POST') {
    $required = ['company_id', 'target_year', 'target_value'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            http_response_code(400);
            echo json_encode([
                'StatusCode' => 400,
                'Status'     => 'Bad Request',
                'message'    => "$field is required."
            ]);
            exit;
        }
    }

    $company_id   = $_POST['company_id'];
    $target_year  = $_POST['target_year'];
    $target_value = $_POST['target_value'];

    if (!ctype_digit((string)$target_year) || strlen((string)$target_year) !== 4) {
        http_response_code(400);
        echo json_encode([
            'StatusCode' => 400,
            'Status'     => 'Bad Request',
            'message'    => 'target_year must be a valid 4-digit year.'
        ]);
        exit;
    }

    if (!is_numeric($target_value)) {
        http_response_code(400);
        echo json_encode([
            'StatusCode' => 400,
            'Status'     => 'Bad Request',
            'message'    => 'target_value must be a numeric value.'
        ]);
        exit;
    }

    $target_year  = (int)$target_year;
    $target_value = (float)$target_value;

    $stmt = $connect->prepare(
        "INSERT INTO targeting (targeting_id, company, target_year, target_value)
         VALUES (UUID(), ?, ?, ?)"
    );
    $stmt->bind_param("sid", $company_id, $target_year, $target_value);

    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode([
            'StatusCode' => 201,
            'Status'     => 'Created',
            'message'    => 'Targeting created successfully.'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'StatusCode' => 500,
            'Status'     => 'Internal Server Error',
            'message'    => 'Failed to create targeting.'
        ]);
    }

// ── PUT: update existing targeting ─────────────────────────────────────────
} elseif ($method === 'PUT') {
    $input = [];
    parse_str(file_get_contents('php://input'), $input);

    $required = ['targeting_id', 'target_year', 'target_value'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode([
                'StatusCode' => 400,
                'Status'     => 'Bad Request',
                'message'    => "$field is required."
            ]);
            exit;
        }
    }

    $targeting_id = $input['targeting_id'];
    $target_year  = $input['target_year'];
    $target_value = $input['target_value'];

    if (!ctype_digit((string)$target_year) || strlen((string)$target_year) !== 4) {
        http_response_code(400);
        echo json_encode([
            'StatusCode' => 400,
            'Status'     => 'Bad Request',
            'message'    => 'target_year must be a valid 4-digit year.'
        ]);
        exit;
    }

    if (!is_numeric($target_value)) {
        http_response_code(400);
        echo json_encode([
            'StatusCode' => 400,
            'Status'     => 'Bad Request',
            'message'    => 'target_value must be a numeric value.'
        ]);
        exit;
    }

    $target_year  = (int)$target_year;
    $target_value = (float)$target_value;

    $stmt = $connect->prepare(
        "UPDATE targeting SET target_year = ?, target_value = ? WHERE targeting_id = ?"
    );
    $stmt->bind_param("ids", $target_year, $target_value, $targeting_id);

    if ($stmt->execute()) {
        echo json_encode([
            'StatusCode' => 200,
            'Status'     => 'Success',
            'message'    => 'Targeting updated successfully.'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'StatusCode' => 500,
            'Status'     => 'Internal Server Error',
            'message'    => 'Failed to update targeting.'
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
