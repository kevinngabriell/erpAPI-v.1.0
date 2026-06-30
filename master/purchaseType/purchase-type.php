<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../general.php';
require_once '../../vendor/autoload.php';
require_once '../../connection/connection.php';
require_once '../../auth/middleware.php';

function getAllPurchaseType($conn, string $search = '', int $page = 1, int $limit = 10): void {
    $search = mysqli_real_escape_string($conn, $search);
    $page   = max(1, $page);
    $limit  = min(100, max(1, $limit));
    $offset = ($page - 1) * $limit;

    $where = $search !== '' ? "WHERE PO_Type_Name LIKE '%$search%'" : '';

    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM purchaseType $where");
    $total        = (int) mysqli_fetch_assoc($count_result)['total'];

    $result = mysqli_query($conn, "SELECT PO_Type_ID, PO_Type_Name FROM purchaseType $where ORDER BY PO_Type_Name ASC LIMIT $limit OFFSET $offset");

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
        jsonResponse(404, 'No purchase types found');
    }
}

function createPurchaseType($conn, array $input): void {
    if (empty($input['purchase_type_name']) || trim($input['purchase_type_name']) === '') {
        jsonResponse(400, 'purchase_type_name is required');
    }

    $type_name = mysqli_real_escape_string($conn, trim($input['purchase_type_name']));
    $id        = generateUUID();

    if (mysqli_query($conn, "INSERT INTO purchaseType (PO_Type_ID, PO_Type_Name) VALUES ('$id', '$type_name')")) {
        jsonResponse(201, 'Purchase type created successfully', ['PO_Type_ID' => $id]);
    } else {
        jsonResponse(500, 'Failed to create purchase type');
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
            getAllPurchaseType(
                $conn,
                $_GET['params'] ?? '',
                (int)($_GET['page']  ?? 1),
                (int)($_GET['limit'] ?? 10)
            );
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createPurchaseType($conn, $input);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
