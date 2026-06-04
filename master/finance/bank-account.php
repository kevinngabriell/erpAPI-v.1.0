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
function createBankAccount($conn, $input, string $userId): void {
    $required = ['company_id', 'bank_number', 'bank_name', 'bank_branch', 'currency_id'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            jsonResponse(400, "$field is required");
        }
    }

    $company_id  = trim(mysqli_real_escape_string($conn, $input['company_id']));
    $bank_number = trim(mysqli_real_escape_string($conn, $input['bank_number']));
    $bank_name   = trim(mysqli_real_escape_string($conn, $input['bank_name']));
    $bank_branch = trim(mysqli_real_escape_string($conn, $input['bank_branch']));
    $currency_id = trim(mysqli_real_escape_string($conn, $input['currency_id']));
    $is_primary  = isset($input['is_primary']) ? (int)(bool)$input['is_primary'] : 0;
    $created_by  = mysqli_real_escape_string($conn, $userId);

    $dupCheck = mysqli_query($conn, "SELECT 1 FROM bank_account WHERE bank_number = '$bank_number' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($dupCheck) > 0) {
        jsonResponse(400, 'Bank account number already exists for this company');
    }

    $id     = generateUUID();
    $insert = "INSERT INTO bank_account
               (bank_account_id, bank_number, bank_name, bank_branch, currency_id, is_primary, company_id, created_by)
               VALUES ('$id', '$bank_number', '$bank_name', '$bank_branch', '$currency_id', $is_primary, '$company_id', '$created_by')";

    if (mysqli_query($conn, $insert)) {
        jsonResponse(201, 'Bank account created successfully', [
            'bank_account_id' => $id,
            'bank_number'     => $bank_number,
        ]);
    } else {
        jsonResponse(500, 'Failed to create bank account');
    }
}

// --- GET ALL ---
function getAllBankAccount($conn, string $company_id = '', string $params = '', int $page = 1, int $limit = 10): void {
    $company_id = mysqli_real_escape_string($conn, $company_id);
    $params     = mysqli_real_escape_string($conn, $params);
    $page       = max(1, $page);
    $limit      = min(100, max(1, $limit));
    $offset     = ($page - 1) * $limit;

    $conditions = [];
    if ($company_id !== '') $conditions[] = "company_id = '$company_id'";
    if ($params !== '')     $conditions[] = "(bank_number LIKE '%$params%' OR bank_name LIKE '%$params%' OR bank_branch LIKE '%$params%')";

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $countResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM bank_account $where");
    $total       = (int) mysqli_fetch_assoc($countResult)['total'];

    $result = mysqli_query($conn, "SELECT bank_account_id, bank_number, bank_name, bank_branch,
                                          currency_id, is_primary, company_id
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
function getDetailBankAccount($conn, ?string $bank_account_id): void {
    if (!$bank_account_id) {
        jsonResponse(400, 'bank_account_id is required');
    }

    $id     = mysqli_real_escape_string($conn, $bank_account_id);
    $result = mysqli_query($conn, "SELECT bank_account_id, bank_number, bank_name, bank_branch,
                                          currency_id, is_primary, company_id,
                                          created_by, created_at, updated_by, updated_at
                                   FROM bank_account WHERE bank_account_id = '$id' LIMIT 1");

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Bank account found', mysqli_fetch_assoc($result));
    } else {
        jsonResponse(404, 'Bank account not found');
    }
}

// --- UPDATE ---
function updateBankAccount($conn, $input, string $userId): void {
    if (empty($input['bank_account_id'])) {
        jsonResponse(400, 'bank_account_id is required');
    }

    $id    = mysqli_real_escape_string($conn, $input['bank_account_id']);
    $check = mysqli_query($conn, "SELECT company_id FROM bank_account WHERE bank_account_id = '$id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Bank account not found');
    }

    $row        = mysqli_fetch_assoc($check);
    $company_id = $row['company_id'];
    $updates    = [];

    if (isset($input['bank_number'])) {
        $num = trim(mysqli_real_escape_string($conn, $input['bank_number']));
        if ($num === '') jsonResponse(400, 'bank_number cannot be empty');
        $dup = mysqli_query($conn, "SELECT 1 FROM bank_account WHERE bank_number = '$num' AND company_id = '$company_id' AND bank_account_id != '$id' LIMIT 1");
        if (mysqli_num_rows($dup) > 0) jsonResponse(400, 'bank_number already exists for this company');
        $updates[] = "bank_number = '$num'";
    }

    if (isset($input['bank_name'])) {
        $name = trim(mysqli_real_escape_string($conn, $input['bank_name']));
        if ($name === '') jsonResponse(400, 'bank_name cannot be empty');
        $updates[] = "bank_name = '$name'";
    }

    if (isset($input['bank_branch'])) {
        $branch    = trim(mysqli_real_escape_string($conn, $input['bank_branch']));
        $updates[] = "bank_branch = " . ($branch !== '' ? "'$branch'" : 'NULL');
    }

    if (isset($input['currency_id'])) {
        $currency = trim(mysqli_real_escape_string($conn, $input['currency_id']));
        if ($currency === '') jsonResponse(400, 'currency_id cannot be empty');
        $updates[] = "currency_id = '$currency'";
    }

    if (isset($input['is_primary'])) {
        $updates[] = "is_primary = " . (int)(bool)$input['is_primary'];
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
    }

    $updated_by = mysqli_real_escape_string($conn, $userId);
    $updates[]  = "updated_by = '$updated_by'";

    if (mysqli_query($conn, "UPDATE bank_account SET " . implode(', ', $updates) . " WHERE bank_account_id = '$id'")) {
        jsonResponse(200, 'Bank account updated successfully');
    } else {
        jsonResponse(500, 'Failed to update bank account');
    }
}

// --- DELETE ---
function deleteBankAccount($conn, ?string $bank_account_id): void {
    if (!$bank_account_id) {
        jsonResponse(400, 'bank_account_id is required');
    }

    $id    = mysqli_real_escape_string($conn, $bank_account_id);
    $check = mysqli_query($conn, "SELECT 1 FROM bank_account WHERE bank_account_id = '$id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Bank account not found');
    }

    if (mysqli_query($conn, "DELETE FROM bank_account WHERE bank_account_id = '$id'")) {
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
        createBankAccount($conn, $input, $userId);
        break;

    case 'GET':
        $bank_account_id = $_GET['bank_account_id'] ?? null;
        if ($bank_account_id) {
            getDetailBankAccount($conn, $bank_account_id);
        } else {
            getAllBankAccount(
                $conn,
                $_GET['company_id'] ?? '',
                $_GET['params']     ?? '',
                (int)($_GET['page']  ?? 1),
                (int)($_GET['limit'] ?? 10)
            );
        }
        break;

    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        updateBankAccount($conn, $input, $userId);
        break;

    case 'DELETE':
        deleteBankAccount($conn, $_GET['bank_account_id'] ?? null);
        break;

    default:
        jsonResponse(405, 'Method Not Allowed');
}
