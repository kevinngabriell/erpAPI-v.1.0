<?php

require_once '../../general.php';
require_once '../../vendor/autoload.php';
require_once '../../connection/connection.php';
require_once '../../auth/middleware.php';

// --- CREATE ---
function createBankAccount($conn, $input): void {
    $required = ['bank_number', 'bank_name', 'bank_branch'];
    foreach ($required as $field) {
        if (!isset($input[$field])) {
            jsonResponse(400, "$field is required");
        }
    }

    $bank_number = trim(mysqli_real_escape_string($conn, $input['bank_number']));
    $bank_name   = trim(mysqli_real_escape_string($conn, $input['bank_name']));
    $bank_branch = trim(mysqli_real_escape_string($conn, $input['bank_branch']));

    if ($bank_number === '' || $bank_name === '' || $bank_branch === '') {
        jsonResponse(400, 'bank_number, bank_name, and bank_branch cannot be empty');
    }

    $dupCheck = mysqli_query($conn, "SELECT 1 FROM bank_account WHERE bank_number = '$bank_number' LIMIT 1");
    if (mysqli_num_rows($dupCheck) > 0) {
        jsonResponse(400, 'Bank account already exists');
    }

    $insert = "INSERT INTO bank_account (bank_number, bank_name, bank_branch)
               VALUES ('$bank_number', '$bank_name', '$bank_branch')";

    if (mysqli_query($conn, $insert)) {
        jsonResponse(201, 'Bank account created successfully', ['bank_number' => $bank_number]);
    } else {
        jsonResponse(500, 'Failed to create bank account');
    }
}

// --- GET ALL ---
function getAllBankAccount($conn, string $params = '', int $page = 1, int $limit = 10): void {
    $params = mysqli_real_escape_string($conn, $params);
    $page   = max(1, $page);
    $limit  = min(100, max(1, $limit));
    $offset = ($page - 1) * $limit;

    $where = $params !== ''
        ? "WHERE bank_number LIKE '%$params%' OR bank_name LIKE '%$params%' OR bank_branch LIKE '%$params%'"
        : '';

    $countResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM bank_account $where");
    $total       = (int) mysqli_fetch_assoc($countResult)['total'];

    $result = mysqli_query($conn, "SELECT bank_number, bank_name, bank_branch
                                   FROM bank_account $where
                                   ORDER BY bank_name ASC
                                   LIMIT $limit OFFSET $offset");

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
        jsonResponse(200, 'Bank account found', [
            'rows'       => $data,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int) ceil($total / $limit),
            ]
        ]);
    } else {
        jsonResponse(404, 'Bank account not found');
    }
}

// --- GET DETAIL ---
function getDetailBankAccount($conn, ?string $bank_number): void {
    if (!$bank_number) {
        jsonResponse(400, 'bank_number is required');
    }

    $bank_number = mysqli_real_escape_string($conn, $bank_number);
    $result      = mysqli_query($conn, "SELECT bank_number, bank_name, bank_branch
                                        FROM bank_account WHERE bank_number = '$bank_number' LIMIT 1");

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Bank account found', mysqli_fetch_assoc($result));
    } else {
        jsonResponse(404, 'Bank account not found');
    }
}

// --- UPDATE ---
function updateBankAccount($conn, $input): void {
    if (!isset($input['bank_number'])) {
        jsonResponse(400, 'bank_number is required');
    }

    $bank_number = mysqli_real_escape_string($conn, $input['bank_number']);
    $check       = mysqli_query($conn, "SELECT 1 FROM bank_account WHERE bank_number = '$bank_number' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Bank account not found');
    }

    $updates = [];

    if (isset($input['bank_name'])) {
        $name = trim(mysqli_real_escape_string($conn, $input['bank_name']));
        if ($name === '') jsonResponse(400, 'bank_name cannot be empty');
        $updates[] = "bank_name = '$name'";
    }

    if (isset($input['bank_branch'])) {
        $branch = trim(mysqli_real_escape_string($conn, $input['bank_branch']));
        if ($branch === '') jsonResponse(400, 'bank_branch cannot be empty');
        $updates[] = "bank_branch = '$branch'";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
    }

    if (mysqli_query($conn, "UPDATE bank_account SET " . implode(', ', $updates) . " WHERE bank_number = '$bank_number'")) {
        jsonResponse(200, 'Bank account updated successfully');
    } else {
        jsonResponse(500, 'Failed to update bank account');
    }
}

// --- DELETE ---
function deleteBankAccount($conn, ?string $bank_number): void {
    if (!$bank_number) {
        jsonResponse(400, 'bank_number is required');
    }

    $bank_number = mysqli_real_escape_string($conn, $bank_number);
    $check       = mysqli_query($conn, "SELECT 1 FROM bank_account WHERE bank_number = '$bank_number' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Bank account not found');
    }

    if (mysqli_query($conn, "DELETE FROM bank_account WHERE bank_number = '$bank_number'")) {
        jsonResponse(200, 'Bank account deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete bank account');
    }
}

// Verify JWT
$decoded = verifyToken();
$userId  = $decoded->sub ?? '';

$conn   = DB::conn();
$GLOBALS['_log_conn']       = $conn;
$GLOBALS['_log_user']       = $userId;
$GLOBALS['_log_request_id'] = bin2hex(random_bytes(8));

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        createBankAccount($conn, $input);
        break;

    case 'GET':
        $bank_number = $_GET['bank_number'] ?? null;
        if ($bank_number) {
            getDetailBankAccount($conn, $bank_number);
        } else {
            getAllBankAccount(
                $conn,
                $_GET['params'] ?? '',
                (int)($_GET['page']  ?? 1),
                (int)($_GET['limit'] ?? 10)
            );
        }
        break;

    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        updateBankAccount($conn, $input);
        break;

    case 'DELETE':
        deleteBankAccount($conn, $_GET['bank_number'] ?? null);
        break;

    default:
        jsonResponse(405, 'Method Not Allowed');
}
