<?php

require_once '../../general.php';
require_once '../../vendor/autoload.php';
require_once '../../connection/connection.php';
require_once '../../auth/middleware.php';

function getAllGender($conn, string $search = '', int $page = 1, int $limit = 10): void {
    $search = mysqli_real_escape_string($conn, $search);
    $page   = max(1, $page);
    $limit  = min(100, max(1, $limit));
    $offset = ($page - 1) * $limit;

    $where = $search !== '' ? "WHERE gender_name LIKE '%$search%'" : '';

    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM gender $where");
    $total        = (int) mysqli_fetch_assoc($count_result)['total'];

    $result = mysqli_query($conn, "SELECT id, gender_name FROM gender $where ORDER BY gender_name ASC LIMIT $limit OFFSET $offset");

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
        jsonResponse(200, 'Success', [
            'data'       => $data,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int) ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No genders found');
    }
}

function createGender($conn, array $input): void {
    if (empty($input['gender_name']) || trim($input['gender_name']) === '') {
        jsonResponse(400, 'gender_name is required');
    }

    $gender_name = mysqli_real_escape_string($conn, trim($input['gender_name']));
    $id          = generateUUID();

    if (mysqli_query($conn, "INSERT INTO gender (id, gender_name) VALUES ('$id', '$gender_name')")) {
        jsonResponse(201, 'Gender created successfully', ['id' => $id]);
    } else {
        jsonResponse(500, 'Failed to create gender');
    }
}

$decoded  = verifyToken();
$username = $decoded->sub ?? '';

$conn = DB::conn();
$GLOBALS['_log_conn']       = $conn;
$GLOBALS['_log_user']       = $username;
$GLOBALS['_log_request_id'] = bin2hex(random_bytes(8));

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            getAllGender(
                $conn,
                $_GET['params'] ?? '',
                (int)($_GET['page']  ?? 1),
                (int)($_GET['limit'] ?? 10)
            );
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createGender($conn, $input);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
