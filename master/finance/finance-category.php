<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../general.php';
require_once '../../vendor/autoload.php';
require_once '../../connection/connection.php';
require_once '../../auth/middleware.php';

function getAllFinanceCategory($conn, string $search = '', int $page = 1, int $limit = 10): void {
    $search = mysqli_real_escape_string($conn, $search);
    $page   = max(1, $page);
    $limit  = min(100, max(1, $limit));
    $offset = ($page - 1) * $limit;

    $where = $search !== '' ? "WHERE category_name LIKE '%$search%'" : '';

    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM finance_category $where");
    $total        = (int) mysqli_fetch_assoc($count_result)['total'];

    $result = mysqli_query($conn, "SELECT category_id, category_name FROM finance_category $where ORDER BY category_name ASC LIMIT $limit OFFSET $offset");

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
        jsonResponse(404, 'No finance categories found');
    }
}

function getDetailFinanceCategory($conn, string $category_id): void {
    if ($category_id === '') jsonResponse(400, 'category_id is required');

    $category_id = mysqli_real_escape_string($conn, $category_id);
    $result      = mysqli_query($conn, "SELECT category_id, category_name FROM finance_category WHERE category_id = '$category_id' LIMIT 1");

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Finance category found', mysqli_fetch_assoc($result));
    } else {
        jsonResponse(404, 'Finance category not found');
    }
}

function createFinanceCategory($conn, array $input, string $username): void {
    if (empty($input['category_name']) || trim($input['category_name']) === '') {
        jsonResponse(400, 'category_name is required');
    }

    $category_name = mysqli_real_escape_string($conn, trim($input['category_name']));

    $dup = mysqli_query($conn, "SELECT 1 FROM finance_category WHERE category_name = '$category_name' LIMIT 1");
    if (mysqli_num_rows($dup) > 0) jsonResponse(409, 'Finance category already exists in database');

    $id = generateUUID();

    if (mysqli_query($conn, "INSERT INTO finance_category (category_id, category_name) VALUES ('$id', '$category_name')")) {
        jsonResponse(201, 'Finance category created successfully', ['category_id' => $id]);
    } else {
        jsonResponse(500, 'Failed to create finance category');
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
            $category_id = $_GET['category_id'] ?? '';

            if ($category_id !== '') {
                getDetailFinanceCategory($conn, $category_id);
            } else {
                getAllFinanceCategory(
                    $conn,
                    $_GET['params'] ?? '',
                    (int)($_GET['page']  ?? 1),
                    (int)($_GET['limit'] ?? 10)
                );
            }
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createFinanceCategory($conn, $input, $username);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
