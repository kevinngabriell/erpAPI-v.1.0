<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../general.php';
require_once '../../vendor/autoload.php';
require_once '../../connection/connection.php';
require_once '../../auth/middleware.php';

// --- GET ALL ---
// Searchable by: SO_Status_Name
// ?params=search  &page=1  &limit=10
function getAllSalesStatus($conn, string $params = '', int $page = 1, int $limit = 10): void {
    $params = mysqli_real_escape_string($conn, $params);
    $page   = max(1, $page);
    $limit  = min(100, max(1, $limit));
    $offset = ($page - 1) * $limit;

    $where = $params !== '' ? "WHERE SO_Status_Name LIKE '%$params%'" : '';

    $countResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM salesStatus $where");
    $total       = (int) mysqli_fetch_assoc($countResult)['total'];

    $result = mysqli_query($conn, "SELECT SO_Status_ID, SO_Status_Name FROM salesStatus $where ORDER BY SO_Status_Name ASC LIMIT $limit OFFSET $offset");

    if ($result && mysqli_num_rows($result) > 0) {
        $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
        jsonResponse(200, 'Success', [
            'rows'       => $rows,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int) ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No sales statuses found');
    }
}

// --- CREATE ---
function createSalesStatus($conn, array $input): void {
    if (empty($input['sales_status_name']) || trim($input['sales_status_name']) === '') {
        jsonResponse(400, 'sales_status_name is required');
    }

    $status_name = mysqli_real_escape_string($conn, trim($input['sales_status_name']));
    $id          = generateUUID();

    if (mysqli_query($conn, "INSERT INTO salesStatus (SO_Status_ID, SO_Status_Name) VALUES ('$id', '$status_name')")) {
        jsonResponse(201, 'Sales status created successfully', ['SO_Status_ID' => $id]);
    } else {
        jsonResponse(500, 'Failed to create sales status');
    }
}

// ── Auth ──────────────────────────────────────────────
$decoded = verifyToken();
$userId  = $decoded->sub ?? '';

$conn = DB::conn();
$GLOBALS['_log_conn']       = $conn;
$GLOBALS['_log_user']       = $userId;
$GLOBALS['_log_request_id'] = bin2hex(random_bytes(8));

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        getAllSalesStatus(
            $conn,
            $_GET['params'] ?? '',
            (int)($_GET['page']  ?? 1),
            (int)($_GET['limit'] ?? 10)
        );
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        createSalesStatus($conn, $input);
        break;

    default:
        jsonResponse(405, 'Method Not Allowed');
}
