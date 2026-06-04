<?php


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../general.php';
require_once '../../vendor/autoload.php';
require_once '../../connection/connection.php';
require_once '../../auth/middleware.php';

$ACCOUNT_TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

function generateUUID(): string {
    $data    = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// --- CREATE ---
function createAccountCode($conn, $input, string $userId): void {
    global $ACCOUNT_TYPES;

    $required = ['account_code', 'account_code_name', 'account_type'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            jsonResponse(400, "$field is required");
        }
    }

    $account_code      = trim(mysqli_real_escape_string($conn, $input['account_code']));
    $account_code_name = trim(mysqli_real_escape_string($conn, $input['account_code_name']));
    $account_type      = trim($input['account_type']);

    if (!in_array($account_type, $ACCOUNT_TYPES, true)) {
        jsonResponse(400, 'account_type must be one of: ' . implode(', ', $ACCOUNT_TYPES));
    }
    $account_type = mysqli_real_escape_string($conn, $account_type);

    $alias_val = isset($input['account_code_name_alias']) && trim($input['account_code_name_alias']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['account_code_name_alias'])) . "'"
        : 'NULL';

    $parent_val = 'NULL';
    if (!empty($input['parent_account_code_id'])) {
        $pid         = mysqli_real_escape_string($conn, trim($input['parent_account_code_id']));
        $parentCheck = mysqli_query($conn, "SELECT 1 FROM account_code WHERE account_code_id = '$pid' LIMIT 1");
        if (mysqli_num_rows($parentCheck) === 0) {
            jsonResponse(404, 'parent_account_code_id not found');
        }
        $parent_val = "'$pid'";
    }

    $dupCheck = mysqli_query($conn, "SELECT 1 FROM account_code WHERE account_code = '$account_code' LIMIT 1");
    if (mysqli_num_rows($dupCheck) > 0) {
        jsonResponse(400, 'account_code already exists');
    }

    $id         = generateUUID();
    $is_active  = isset($input['is_active']) ? (int)(bool)$input['is_active'] : 1;
    $created_by = mysqli_real_escape_string($conn, $userId);

    $insert = "INSERT INTO account_code
               (account_code_id, account_code, account_code_name, account_code_name_alias,
                account_type, parent_account_code_id, is_active, created_by)
               VALUES ('$id', '$account_code', '$account_code_name', $alias_val,
                       '$account_type', $parent_val, $is_active, '$created_by')";

    if (mysqli_query($conn, $insert)) {
        jsonResponse(201, 'Account code created successfully', [
            'account_code_id' => $id,
            'account_code'    => $account_code,
        ]);
    } else {
        jsonResponse(500, 'Failed to create account code');
    }
}

// --- GET ALL ---
function getAllAccountCode($conn, string $params = '', int $page = 1, int $limit = 10): void {
    $params = mysqli_real_escape_string($conn, $params);
    $page   = max(1, $page);
    $limit  = min(100, max(1, $limit));
    $offset = ($page - 1) * $limit;

    $where = $params !== ''
        ? "WHERE account_code LIKE '%$params%' OR account_code_name LIKE '%$params%' OR account_code_name_alias LIKE '%$params%'"
        : '';

    $countResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM account_code $where");
    $total       = (int) mysqli_fetch_assoc($countResult)['total'];

    $result = mysqli_query($conn, "SELECT account_code_id, account_code, account_code_name,
                                          account_code_name_alias, account_type, parent_account_code_id, is_active
                                   FROM account_code $where
                                   ORDER BY account_code ASC
                                   LIMIT $limit OFFSET $offset");

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
        jsonResponse(200, 'Account code found', [
            'rows'       => $data,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int) ceil($total / $limit),
            ]
        ]);
    } else {
        jsonResponse(404, 'Account code not found');
    }
}

// --- GET DETAIL ---
function getDetailAccountCode($conn, ?string $account_code_id): void {
    if (!$account_code_id) {
        jsonResponse(400, 'account_code_id is required');
    }

    $id     = mysqli_real_escape_string($conn, $account_code_id);
    $result = mysqli_query($conn, "SELECT account_code_id, account_code, account_code_name,
                                          account_code_name_alias, account_type, parent_account_code_id,
                                          is_active, created_by, created_at, updated_by, updated_at
                                   FROM account_code WHERE account_code_id = '$id' LIMIT 1");

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Account code found', mysqli_fetch_assoc($result));
    } else {
        jsonResponse(404, 'Account code not found');
    }
}

// --- UPDATE ---
function updateAccountCode($conn, $input, string $userId): void {
    global $ACCOUNT_TYPES;

    if (empty($input['account_code_id'])) {
        jsonResponse(400, 'account_code_id is required');
    }

    $id    = mysqli_real_escape_string($conn, $input['account_code_id']);
    $check = mysqli_query($conn, "SELECT 1 FROM account_code WHERE account_code_id = '$id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Account code not found');
    }

    $updates = [];

    if (isset($input['account_code'])) {
        $code = trim(mysqli_real_escape_string($conn, $input['account_code']));
        if ($code === '') jsonResponse(400, 'account_code cannot be empty');
        $dup = mysqli_query($conn, "SELECT 1 FROM account_code WHERE account_code = '$code' AND account_code_id != '$id' LIMIT 1");
        if (mysqli_num_rows($dup) > 0) jsonResponse(400, 'account_code already exists');
        $updates[] = "account_code = '$code'";
    }

    if (isset($input['account_code_name'])) {
        $name = trim(mysqli_real_escape_string($conn, $input['account_code_name']));
        if ($name === '') jsonResponse(400, 'account_code_name cannot be empty');
        $updates[] = "account_code_name = '$name'";
    }

    if (isset($input['account_code_name_alias'])) {
        $alias     = trim(mysqli_real_escape_string($conn, $input['account_code_name_alias']));
        $updates[] = "account_code_name_alias = " . ($alias !== '' ? "'$alias'" : 'NULL');
    }

    if (isset($input['account_type'])) {
        if (!in_array($input['account_type'], $ACCOUNT_TYPES, true)) {
            jsonResponse(400, 'account_type must be one of: ' . implode(', ', $ACCOUNT_TYPES));
        }
        $type      = mysqli_real_escape_string($conn, $input['account_type']);
        $updates[] = "account_type = '$type'";
    }

    if (array_key_exists('parent_account_code_id', $input)) {
        if (!empty($input['parent_account_code_id'])) {
            $pid = mysqli_real_escape_string($conn, trim($input['parent_account_code_id']));
            if ($pid === $id) jsonResponse(400, 'parent_account_code_id cannot reference itself');
            $parentCheck = mysqli_query($conn, "SELECT 1 FROM account_code WHERE account_code_id = '$pid' LIMIT 1");
            if (mysqli_num_rows($parentCheck) === 0) jsonResponse(404, 'parent_account_code_id not found');
            $updates[] = "parent_account_code_id = '$pid'";
        } else {
            $updates[] = "parent_account_code_id = NULL";
        }
    }

    if (isset($input['is_active'])) {
        $updates[] = "is_active = " . (int)(bool)$input['is_active'];
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
    }

    $updated_by = mysqli_real_escape_string($conn, $userId);
    $updates[]  = "updated_by = '$updated_by'";

    if (mysqli_query($conn, "UPDATE account_code SET " . implode(', ', $updates) . " WHERE account_code_id = '$id'")) {
        jsonResponse(200, 'Account code updated successfully');
    } else {
        jsonResponse(500, 'Failed to update account code');
    }
}

// --- DELETE ---
function deleteAccountCode($conn, ?string $account_code_id): void {
    if (!$account_code_id) {
        jsonResponse(400, 'account_code_id is required');
    }

    $id    = mysqli_real_escape_string($conn, $account_code_id);
    $check = mysqli_query($conn, "SELECT 1 FROM account_code WHERE account_code_id = '$id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Account code not found');
    }

    if (mysqli_query($conn, "DELETE FROM account_code WHERE account_code_id = '$id'")) {
        jsonResponse(200, 'Account code deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete account code');
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
        createAccountCode($conn, $input, $userId);
        break;

    case 'GET':
        $account_code_id = $_GET['account_code_id'] ?? null;
        if ($account_code_id) {
            getDetailAccountCode($conn, $account_code_id);
        } else {
            getAllAccountCode(
                $conn,
                $_GET['params'] ?? '',
                (int)($_GET['page']  ?? 1),
                (int)($_GET['limit'] ?? 10)
            );
        }
        break;

    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        updateAccountCode($conn, $input, $userId);
        break;

    case 'DELETE':
        deleteAccountCode($conn, $_GET['account_code_id'] ?? null);
        break;

    default:
        jsonResponse(405, 'Method Not Allowed');
}
