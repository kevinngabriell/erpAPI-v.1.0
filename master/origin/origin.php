<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../general.php';
require_once '../../vendor/autoload.php';
require_once '../../connection/connection.php';
require_once '../../auth/middleware.php';

function getAllOrigin($conn, string $search = '', int $page = 1, int $limit = 10): void {
    $search = mysqli_real_escape_string($conn, $search);
    $page   = max(1, $page);
    $limit  = min(100, max(1, $limit));
    $offset = ($page - 1) * $limit;

    $where = $search !== ''
        ? "WHERE A1.origin_name LIKE '%$search%' OR A2.region_name LIKE '%$search%'"
        : '';

    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM origin A1 JOIN region A2 ON A2.region_id = A1.origin_region $where");
    $total        = (int) mysqli_fetch_assoc($count_result)['total'];

    $result = mysqli_query($conn,
        "SELECT A1.origin_id, A1.origin_name, A1.origin_is_free_trade, A2.region_name
         FROM origin A1 JOIN region A2 ON A2.region_id = A1.origin_region
         $where
         ORDER BY A1.origin_name ASC LIMIT $limit OFFSET $offset"
    );

    if ($result && mysqli_num_rows($result) > 0) {
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = [
                'origin_id'     => $row['origin_id'],
                'Country Name'  => $row['origin_name'],
                'Is Free Trade' => $row['origin_is_free_trade'],
                'Region'        => $row['region_name'],
            ];
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
        jsonResponse(404, 'No origins found');
    }
}

function getDetailOrigin($conn, string $origin_id): void {
    if ($origin_id === '') jsonResponse(400, 'origin_id is required');

    $origin_id = mysqli_real_escape_string($conn, $origin_id);
    $result    = mysqli_query($conn,
        "SELECT A1.origin_name, A1.origin_is_free_trade, A2.region_name
         FROM origin A1 JOIN region A2 ON A2.region_id = A1.origin_region
         WHERE A1.origin_id = '$origin_id' LIMIT 1"
    );

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Origin found', mysqli_fetch_assoc($result));
    } else {
        jsonResponse(404, 'Origin not found');
    }
}

function getOriginBySupplier($conn, string $supplier_id): void {
    if ($supplier_id === '') jsonResponse(400, 'supplier is required');

    $supplier_id = mysqli_real_escape_string($conn, $supplier_id);
    $result      = mysqli_query($conn, "SELECT supplier_origin, supplier_id FROM supplier WHERE supplier_id = '$supplier_id' LIMIT 1");

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Origin found', mysqli_fetch_assoc($result));
    } else {
        jsonResponse(404, 'Supplier not found');
    }
}

function createOrigin($conn, array $input): void {
    $required = ['origin_name', 'origin_region', 'origin_is_free_trade'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            jsonResponse(400, "$field is required");
        }
    }

    $origin_name          = mysqli_real_escape_string($conn, trim($input['origin_name']));
    $origin_region        = mysqli_real_escape_string($conn, trim($input['origin_region']));
    $origin_is_free_trade = mysqli_real_escape_string($conn, trim($input['origin_is_free_trade']));

    $dup = mysqli_query($conn, "SELECT 1 FROM origin WHERE origin_name = '$origin_name' LIMIT 1");
    if (mysqli_num_rows($dup) > 0) jsonResponse(409, 'Origin already exists in database');

    if (mysqli_query($conn, "INSERT IGNORE INTO origin (origin_id, origin_name, origin_region, origin_is_free_trade) VALUES (NULL, '$origin_name', '$origin_region', '$origin_is_free_trade')")) {
        jsonResponse(201, 'Origin created successfully');
    } else {
        jsonResponse(500, 'Failed to create origin');
    }
}

function updateOrigin($conn, array $input): void {
    $required = ['origin_id', 'origin_name', 'origin_is_free_trade'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            jsonResponse(400, "$field is required");
        }
    }

    $origin_id            = mysqli_real_escape_string($conn, trim($input['origin_id']));
    $origin_name          = mysqli_real_escape_string($conn, trim($input['origin_name']));
    $origin_is_free_trade = mysqli_real_escape_string($conn, trim($input['origin_is_free_trade']));

    $check = mysqli_query($conn, "SELECT 1 FROM origin WHERE origin_id = '$origin_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) jsonResponse(404, 'Origin not found');

    if (mysqli_query($conn, "UPDATE origin SET origin_name = '$origin_name', origin_is_free_trade = '$origin_is_free_trade' WHERE origin_id = '$origin_id'")) {
        jsonResponse(200, 'Origin updated successfully');
    } else {
        jsonResponse(500, 'Failed to update origin');
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
            $origin_id   = $_GET['origin_id'] ?? '';
            $supplier_id = $_GET['supplier']  ?? '';

            if ($origin_id !== '') {
                getDetailOrigin($conn, $origin_id);
            } elseif ($supplier_id !== '') {
                getOriginBySupplier($conn, $supplier_id);
            } else {
                getAllOrigin(
                    $conn,
                    $_GET['params'] ?? '',
                    (int)($_GET['page']  ?? 1),
                    (int)($_GET['limit'] ?? 10)
                );
            }
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createOrigin($conn, $input);
            break;

        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            updateOrigin($conn, $input);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
