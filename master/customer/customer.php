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
// Searchable by: company_name, company_address, company_phone
// ?company=X  &params=search  &page=1  &limit=10
function getAllCustomer($conn, string $company, string $params = '', int $page = 1, int $limit = 10): void {
    if ($company === '') jsonResponse(400, 'company is required');

    $company = mysqli_real_escape_string($conn, $company);
    $params  = mysqli_real_escape_string($conn, $params);
    $page    = max(1, $page);
    $limit   = min(100, max(1, $limit));
    $offset  = ($page - 1) * $limit;

    $search = $params !== ''
        ? "AND (company_name LIKE '%$params%' OR company_address LIKE '%$params%' OR company_phone LIKE '%$params%')"
        : '';

    $where = "WHERE company = '$company' $search";

    $countResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM customer $where");
    $total       = (int) mysqli_fetch_assoc($countResult)['total'];

    $result = mysqli_query($conn, "SELECT company_id, company_name, company_address, company_phone
                                   FROM customer $where
                                   ORDER BY company_name ASC
                                   LIMIT $limit OFFSET $offset");

    if ($result && mysqli_num_rows($result) > 0) {
        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = [
                'company_id'      => $row['company_id'],
                'Company Name'    => $row['company_name'],
                'Company Address' => $row['company_address'],
                'Company Phone'   => $row['company_phone'],
            ];
        }
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
        jsonResponse(404, 'No customers found');
    }
}

// --- GET DETAIL ---
function getDetailCustomer($conn, string $company_id, string $type = ''): void {
    if ($company_id === '') jsonResponse(400, 'company_id is required');

    $id = mysqli_real_escape_string($conn, $company_id);

    if ($type === 'address') {
        $result = mysqli_query($conn, "SELECT company_address, company_top FROM customer WHERE company_id = '$id' LIMIT 1");
    } else {
        $result = mysqli_query($conn, "SELECT company_id, company_name, company_address, company_phone,
                                              company_pic_name, company_pic_contact, company_top
                                       FROM customer WHERE company_id = '$id' LIMIT 1");
    }

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Customer found', mysqli_fetch_assoc($result));
    } else {
        jsonResponse(404, 'Customer not found');
    }
}

// --- CREATE ---
function createCustomer($conn, array $input): void {
    $required = ['company_id', 'company_name', 'company_address', 'company_phone', 'company_pic_name', 'company_pic_contact', 'company_top'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            jsonResponse(400, "$field is required");
        }
    }

    $company_id          = mysqli_real_escape_string($conn, trim($input['company_id']));
    $company_name        = mysqli_real_escape_string($conn, trim($input['company_name']));
    $company_address     = mysqli_real_escape_string($conn, trim($input['company_address']));
    $company_phone       = mysqli_real_escape_string($conn, trim($input['company_phone']));
    $company_pic_name    = mysqli_real_escape_string($conn, trim($input['company_pic_name']));
    $company_pic_contact = mysqli_real_escape_string($conn, trim($input['company_pic_contact']));
    $company_top         = mysqli_real_escape_string($conn, trim($input['company_top']));

    $id = generateUUID();
    $insert = "INSERT INTO customer (company_id, company, company_name, company_address, company_phone, company_pic_name, company_pic_contact, company_top)
               VALUES ('$id', '$company_id', '$company_name', '$company_address', '$company_phone', '$company_pic_name', '$company_pic_contact', '$company_top')";

    if (mysqli_query($conn, $insert)) {
        jsonResponse(201, 'Customer created successfully', ['company_id' => $id]);
    } else {
        jsonResponse(500, 'Failed to create customer');
    }
}

// --- UPDATE ---
function updateCustomer($conn, array $input, string $userId): void {
    if (empty($input['company_id'])) jsonResponse(400, 'company_id is required');

    $id    = mysqli_real_escape_string($conn, $input['company_id']);
    $check = mysqli_query($conn, "SELECT 1 FROM customer WHERE company_id = '$id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) jsonResponse(404, 'Customer not found');

    $updates = [];
    $fields  = ['company_name', 'company_address', 'company_phone', 'company_pic_name', 'company_pic_contact', 'company_top'];
    foreach ($fields as $f) {
        if (isset($input[$f])) {
            $v         = mysqli_real_escape_string($conn, trim($input[$f]));
            $updates[] = "$f = '$v'";
        }
    }

    if (empty($updates)) jsonResponse(400, 'No fields provided for update');

    if (mysqli_query($conn, "UPDATE customer SET " . implode(', ', $updates) . " WHERE company_id = '$id'")) {
        jsonResponse(200, 'Customer updated successfully');
    } else {
        jsonResponse(500, 'Failed to update customer');
    }
}

// ── Auth ──────────────────────────────────────────────
$decoded = verifyToken();
$userId  = $decoded->sub ?? '';

$conn   = DB::conn();
$GLOBALS['_log_conn']       = $conn;
$GLOBALS['_log_user']       = $userId;
$GLOBALS['_log_request_id'] = bin2hex(random_bytes(8));

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $company_id = $_GET['company_id'] ?? '';
        $company    = $_GET['company']    ?? '';
        $type       = $_GET['type']       ?? '';

        if ($company_id !== '') {
            getDetailCustomer($conn, $company_id, $type);
        } else {
            getAllCustomer(
                $conn,
                $company,
                $_GET['params'] ?? '',
                (int)($_GET['page']  ?? 1),
                (int)($_GET['limit'] ?? 10)
            );
        }
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        createCustomer($conn, $input);
        break;

    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        updateCustomer($conn, $input, $userId);
        break;

    default:
        jsonResponse(405, 'Method Not Allowed');
}
