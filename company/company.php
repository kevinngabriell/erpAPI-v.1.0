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

// ── GET: fetch company detail ───────────────────────────────────────────────
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
        "SELECT company_name, company_address, company_email, company_phone, company_web, company_industry
         FROM company WHERE company_id = ?"
    );
    $stmt->bind_param("s", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            'company_name'     => $row['company_name'],
            'company_address'  => $row['company_address'],
            'company_email'    => $row['company_email'],
            'company_phone'    => $row['company_phone'],
            'company_web'      => $row['company_web'],
            'company_industry' => $row['company_industry']
        ];
    }

    if (!empty($data)) {
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'Data' => $data]);
    } else {
        http_response_code(404);
        echo json_encode([
            'StatusCode' => 404,
            'Status'     => 'Not Found',
            'message'    => 'Company not found.'
        ]);
    }

// ── POST: create new company ────────────────────────────────────────────────
} elseif ($method === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $input = $_POST;
    }

    $required = ['company_name', 'company_address', 'company_phone', 'company_web', 'company_industry'];
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

    $company_name     = $input['company_name'];
    $company_address  = $input['company_address'];
    $company_phone    = $input['company_phone'];
    $company_web      = $input['company_web'];
    $company_industry = $input['company_industry'];

    $stmt = $connect->prepare(
        "INSERT INTO company (company_id, company_name, company_address, company_phone, company_web, company_industry)
         VALUES (UUID(), ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("sssss", $company_name, $company_address, $company_phone, $company_web, $company_industry);

    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode([
            'StatusCode' => 201,
            'Status'     => 'Created',
            'message'    => 'Company created successfully.'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'StatusCode' => 500,
            'Status'     => 'Internal Server Error',
            'message'    => 'Failed to create company.'
        ]);
    }

// ── PUT: update existing company ────────────────────────────────────────────
} elseif ($method === 'PUT') {
    $input = [];
    parse_str(file_get_contents('php://input'), $input);

    $required = ['company_id', 'company_name', 'company_address', 'company_phone', 'company_email', 'company_web', 'company_industry'];
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

    $company_id       = $input['company_id'];
    $company_name     = $input['company_name'];
    $company_address  = $input['company_address'];
    $company_phone    = $input['company_phone'];
    $company_email    = $input['company_email'];
    $company_web      = $input['company_web'];
    $company_industry = $input['company_industry'];

    $stmt = $connect->prepare(
        "UPDATE company
         SET company_name = ?, company_address = ?, company_email = ?,
             company_phone = ?, company_web = ?, company_industry = ?
         WHERE company_id = ?"
    );
    $stmt->bind_param("sssssss", $company_name, $company_address, $company_email, $company_phone, $company_web, $company_industry, $company_id);

    if ($stmt->execute()) {
        echo json_encode([
            'StatusCode' => 200,
            'Status'     => 'Success',
            'message'    => 'Company updated successfully.'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'StatusCode' => 500,
            'Status'     => 'Internal Server Error',
            'message'    => 'Failed to update company.'
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
