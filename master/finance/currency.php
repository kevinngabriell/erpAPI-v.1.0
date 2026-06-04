<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../general.php';
require_once '../../vendor/autoload.php';
require_once '../../connection/connection.php';
require_once '../../auth/middleware.php';

// --- CREATE ---
function createCurrency($conn, $input, string $userId): void {
    $required = ['currency_code', 'currency_symbol'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            jsonResponse(400, "$field is required");
        }
    }

    $currency_code   = strtoupper(trim(mysqli_real_escape_string($conn, $input['currency_code'])));
    $currency_symbol = trim(mysqli_real_escape_string($conn, $input['currency_symbol']));

    if (strlen($currency_code) !== 3) {
        jsonResponse(400, 'currency_code must be exactly 3 characters');
    }

    $name_val = isset($input['currency_name']) && trim($input['currency_name']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['currency_name'])) . "'"
        : 'NULL';

    $dupCheck = mysqli_query($conn, "SELECT 1 FROM currency_new WHERE currency_code = '$currency_code' LIMIT 1");
    if (mysqli_num_rows($dupCheck) > 0) {
        jsonResponse(400, 'currency_code already exists');
    }

    $id         = generateUUID();
    $created_by = mysqli_real_escape_string($conn, $userId);

    $insert = "INSERT INTO currency_new
               (currency_id, currency_code, currency_symbol, currency_name, created_by)
               VALUES ('$id', '$currency_code', '$currency_symbol', $name_val, '$created_by')";

    if (mysqli_query($conn, $insert)) {
        jsonResponse(201, 'Currency created successfully', [
            'currency_id'   => $id,
            'currency_code' => $currency_code,
        ]);
    } else {
        jsonResponse(500, 'Failed to create currency');
    }
}

// --- GET ALL ---
function getAllCurrency($conn, string $params = '', int $page = 1, int $limit = 10): void {
    $params = mysqli_real_escape_string($conn, $params);
    $page   = max(1, $page);
    $limit  = min(100, max(1, $limit));
    $offset = ($page - 1) * $limit;

    $where = $params !== ''
        ? "WHERE currency_code LIKE '%$params%' OR currency_name LIKE '%$params%' OR currency_symbol LIKE '%$params%'"
        : '';

    $countResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM currency_new $where");
    $total       = (int) mysqli_fetch_assoc($countResult)['total'];

    $result = mysqli_query($conn, "SELECT currency_id, currency_code, currency_symbol, currency_name
                                   FROM currency_new $where
                                   ORDER BY currency_code ASC
                                   LIMIT $limit OFFSET $offset");

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
        jsonResponse(200, 'Currency found', [
            'rows'       => $data,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int) ceil($total / $limit),
            ]
        ]);
    } else {
        jsonResponse(404, 'Currency not found');
    }
}

// --- GET DETAIL ---
function getDetailCurrency($conn, ?string $currency_id): void {
    if (!$currency_id) {
        jsonResponse(400, 'currency_id is required');
    }

    $id     = mysqli_real_escape_string($conn, $currency_id);
    $result = mysqli_query($conn, "SELECT currency_id, currency_code, currency_symbol, currency_name,
                                          created_by, created_at, updated_by, updated_at
                                   FROM currency_new WHERE currency_id = '$id' LIMIT 1");

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Currency found', mysqli_fetch_assoc($result));
    } else {
        jsonResponse(404, 'Currency not found');
    }
}

// --- UPDATE ---
function updateCurrency($conn, $input, string $userId): void {
    if (empty($input['currency_id'])) {
        jsonResponse(400, 'currency_id is required');
    }

    $id    = mysqli_real_escape_string($conn, $input['currency_id']);
    $check = mysqli_query($conn, "SELECT 1 FROM currency_new WHERE currency_id = '$id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Currency not found');
    }

    $updates = [];

    if (isset($input['currency_code'])) {
        $code = strtoupper(trim(mysqli_real_escape_string($conn, $input['currency_code'])));
        if ($code === '') jsonResponse(400, 'currency_code cannot be empty');
        if (strlen($code) !== 3) jsonResponse(400, 'currency_code must be exactly 3 characters');
        $dup = mysqli_query($conn, "SELECT 1 FROM currency_new WHERE currency_code = '$code' AND currency_id != '$id' LIMIT 1");
        if (mysqli_num_rows($dup) > 0) jsonResponse(400, 'currency_code already exists');
        $updates[] = "currency_code = '$code'";
    }

    if (isset($input['currency_symbol'])) {
        $symbol = trim(mysqli_real_escape_string($conn, $input['currency_symbol']));
        if ($symbol === '') jsonResponse(400, 'currency_symbol cannot be empty');
        $updates[] = "currency_symbol = '$symbol'";
    }

    if (isset($input['currency_name'])) {
        $name      = trim(mysqli_real_escape_string($conn, $input['currency_name']));
        $updates[] = "currency_name = " . ($name !== '' ? "'$name'" : 'NULL');
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
    }

    $updated_by = mysqli_real_escape_string($conn, $userId);
    $updates[]  = "updated_by = '$updated_by'";

    if (mysqli_query($conn, "UPDATE currency_new SET " . implode(', ', $updates) . " WHERE currency_id = '$id'")) {
        jsonResponse(200, 'Currency updated successfully');
    } else {
        jsonResponse(500, 'Failed to update currency');
    }
}

// --- DELETE ---
function deleteCurrency($conn, ?string $currency_id): void {
    if (!$currency_id) {
        jsonResponse(400, 'currency_id is required');
    }

    $id    = mysqli_real_escape_string($conn, $currency_id);
    $check = mysqli_query($conn, "SELECT 1 FROM currency_new WHERE currency_id = '$id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Currency not found');
    }

    if (mysqli_query($conn, "DELETE FROM currency_new WHERE currency_id = '$id'")) {
        jsonResponse(200, 'Currency deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete currency');
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
        createCurrency($conn, $input, $userId);
        break;

    case 'GET':
        $currency_id = $_GET['currency_id'] ?? null;
        if ($currency_id) {
            getDetailCurrency($conn, $currency_id);
        } else {
            getAllCurrency(
                $conn,
                $_GET['params'] ?? '',
                (int)($_GET['page']  ?? 1),
                (int)($_GET['limit'] ?? 10)
            );
        }
        break;

    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        updateCurrency($conn, $input, $userId);
        break;

    case 'DELETE':
        deleteCurrency($conn, $_GET['currency_id'] ?? null);
        break;

    default:
        jsonResponse(405, 'Method Not Allowed');
}
