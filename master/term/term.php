<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../general.php';
require_once '../../vendor/autoload.php';
require_once '../../connection/connection.php';
require_once '../../auth/middleware.php';

function getAllTerm($conn, string $search = '', int $page = 1, int $limit = 10): void {
    $search = mysqli_real_escape_string($conn, $search);
    $page   = max(1, $page);
    $limit  = min(100, max(1, $limit));
    $offset = ($page - 1) * $limit;

    $where = $search !== '' ? "WHERE term_name LIKE '%$search%'" : '';

    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM term $where");
    $total        = (int) mysqli_fetch_assoc($count_result)['total'];

    $result = mysqli_query($conn, "SELECT term_id, term_name FROM term $where ORDER BY term_name ASC LIMIT $limit OFFSET $offset");

    if ($result && mysqli_num_rows($result) > 0) {
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = ['term_id' => $row['term_id'], 'Term' => $row['term_name']];
        }
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
        jsonResponse(404, 'No terms found');
    }
}

function createTerm($conn, array $input): void {
    if (empty($input['term_name']) || trim($input['term_name']) === '') {
        jsonResponse(400, 'term_name is required');
    }

    $term_name = mysqli_real_escape_string($conn, trim($input['term_name']));
    $id        = generateUUID();

    if (mysqli_query($conn, "INSERT INTO term (term_id, term_name) VALUES ('$id', '$term_name')")) {
        jsonResponse(201, 'Term created successfully', ['term_id' => $id]);
    } else {
        jsonResponse(500, 'Failed to create term');
    }
}

function deleteTerm($conn, ?string $term_id): void {
    if (!$term_id) jsonResponse(400, 'term_id is required');

    $term_id = mysqli_real_escape_string($conn, $term_id);
    $check   = mysqli_query($conn, "SELECT 1 FROM term WHERE term_id = '$term_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) jsonResponse(404, 'Term not found');

    if (mysqli_query($conn, "DELETE FROM term WHERE term_id = '$term_id'")) {
        jsonResponse(200, 'Term deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete term');
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
            getAllTerm(
                $conn,
                $_GET['params'] ?? '',
                (int)($_GET['page']  ?? 1),
                (int)($_GET['limit'] ?? 10)
            );
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createTerm($conn, $input);
            break;

        case 'DELETE':
            deleteTerm($conn, $_GET['term_id'] ?? null);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
