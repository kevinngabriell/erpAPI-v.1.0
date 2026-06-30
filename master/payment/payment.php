<?php

require_once '../../general.php';
require_once '../../vendor/autoload.php';
require_once '../../connection/connection.php';
require_once '../../auth/middleware.php';

function getAllPayment($conn, string $search = '', int $page = 1, int $limit = 10): void {
    $search = mysqli_real_escape_string($conn, $search);
    $page   = max(1, $page);
    $limit  = min(100, max(1, $limit));
    $offset = ($page - 1) * $limit;

    $where = $search !== '' ? "WHERE payment_name LIKE '%$search%'" : '';

    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM payment $where");
    $total        = (int) mysqli_fetch_assoc($count_result)['total'];

    $result = mysqli_query($conn, "SELECT payment_id, payment_name FROM payment $where ORDER BY payment_name ASC LIMIT $limit OFFSET $offset");

    if ($result && mysqli_num_rows($result) > 0) {
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = ['Id' => $row['payment_id'], 'Payment Method' => $row['payment_name']];
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
        jsonResponse(404, 'No payment methods found');
    }
}

function createPayment($conn, array $input): void {
    if (empty($input['payment_name']) || trim($input['payment_name']) === '') {
        jsonResponse(400, 'payment_name is required');
    }

    $payment_name = mysqli_real_escape_string($conn, trim($input['payment_name']));
    $id           = generateUUID();

    if (mysqli_query($conn, "INSERT INTO payment (payment_id, payment_name) VALUES ('$id', '$payment_name')")) {
        jsonResponse(201, 'Payment method created successfully', ['payment_id' => $id]);
    } else {
        jsonResponse(500, 'Failed to create payment method');
    }
}

function deletePayment($conn, ?string $payment_id): void {
    if (!$payment_id) jsonResponse(400, 'payment_id is required');

    $payment_id = mysqli_real_escape_string($conn, $payment_id);
    $check      = mysqli_query($conn, "SELECT 1 FROM payment WHERE payment_id = '$payment_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) jsonResponse(404, 'Payment method not found');

    if (mysqli_query($conn, "DELETE FROM payment WHERE payment_id = '$payment_id'")) {
        jsonResponse(200, 'Payment method deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete payment method');
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
            getAllPayment(
                $conn,
                $_GET['params'] ?? '',
                (int)($_GET['page']  ?? 1),
                (int)($_GET['limit'] ?? 10)
            );
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createPayment($conn, $input);
            break;

        case 'DELETE':
            deletePayment($conn, $_GET['payment_id'] ?? null);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
