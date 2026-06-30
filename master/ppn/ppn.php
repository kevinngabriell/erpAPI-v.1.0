<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../general.php';
require_once '../../vendor/autoload.php';
require_once '../../connection/connection.php';
require_once '../../auth/middleware.php';

function getAllPPN($conn, string $search = '', int $page = 1, int $limit = 10): void {
    $search = mysqli_real_escape_string($conn, $search);
    $page   = max(1, $page);
    $limit  = min(100, max(1, $limit));
    $offset = ($page - 1) * $limit;

    $where = $search !== '' ? "WHERE PPNType_name LIKE '%$search%'" : '';

    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM salesPPNType $where");
    $total        = (int) mysqli_fetch_assoc($count_result)['total'];

    $result = mysqli_query($conn, "SELECT PPNType_id, PPNType_name, PPNPercentage FROM salesPPNType $where ORDER BY PPNType_name ASC LIMIT $limit OFFSET $offset");

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
        jsonResponse(404, 'No PPN types found');
    }
}

function getPPNPercentage($conn, string $ppn_type_id): void {
    if ($ppn_type_id === '') jsonResponse(400, 'PPNType_id is required');

    $ppn_type_id = mysqli_real_escape_string($conn, $ppn_type_id);
    $result      = mysqli_query($conn, "SELECT PPNPercentage FROM salesPPNType WHERE PPNType_id = '$ppn_type_id' LIMIT 1");

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'PPN type found', mysqli_fetch_assoc($result));
    } else {
        jsonResponse(404, 'PPN type not found');
    }
}

function createPPN($conn, array $input): void {
    if (empty($input['ppn_name']) || trim($input['ppn_name']) === '') {
        jsonResponse(400, 'ppn_name is required');
    }

    $ppn_name = mysqli_real_escape_string($conn, trim($input['ppn_name']));
    $id       = generateUUID();

    if (mysqli_query($conn, "INSERT INTO salesPPNType (PPNTYPE_id, PPNType_name) VALUES ('$id', '$ppn_name')")) {
        jsonResponse(201, 'PPN type created successfully', ['PPNType_id' => $id]);
    } else {
        jsonResponse(500, 'Failed to create PPN type');
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
            $ppn_type_id = $_GET['PPNType_id'] ?? '';
            if ($ppn_type_id !== '') {
                getPPNPercentage($conn, $ppn_type_id);
            } else {
                getAllPPN(
                    $conn,
                    $_GET['params'] ?? '',
                    (int)($_GET['page']  ?? 1),
                    (int)($_GET['limit'] ?? 10)
                );
            }
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createPPN($conn, $input);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
