<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../general.php';
require_once '../../vendor/autoload.php';
require_once '../../connection/connection.php';
require_once '../../auth/middleware.php';

function getAllCustomer($conn, string $company_id, string $search = '', int $page = 1, int $limit = 10): void {
    $company_id = mysqli_real_escape_string($conn, $company_id);
    $search     = mysqli_real_escape_string($conn, $search);
    $page       = max(1, $page);
    $limit      = min(100, max(1, $limit));
    $offset     = ($page - 1) * $limit;

    $where = "company = '$company_id'";
    if ($search !== '') {
        $where .= " AND (company_name LIKE '%$search%' OR company_address LIKE '%$search%' OR company_phone LIKE '%$search%')";
    }

    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM customer WHERE $where");
    $total        = (int) mysqli_fetch_assoc($count_result)['total'];

    $result = mysqli_query($conn, "SELECT company_id, company_name, company_address, company_phone
                                   FROM customer WHERE $where
                                   ORDER BY company_name ASC
                                   LIMIT $limit OFFSET $offset");

    if ($result && mysqli_num_rows($result) > 0) {
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = [
                'company_id'      => $row['company_id'],
                'Company Name'    => $row['company_name'],
                'Company Address' => $row['company_address'],
                'Company Phone'   => $row['company_phone'],
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
        jsonResponse(404, 'No customers found');
    }
}

function getDetailCustomer($conn, string $customer_id, string $type = ''): void {
    if ($customer_id === '') jsonResponse(400, 'company_id is required');

    $customer_id = mysqli_real_escape_string($conn, $customer_id);

    if ($type === 'address') {
        $result = mysqli_query($conn, "SELECT company_address, company_top FROM customer WHERE company_id = '$customer_id' LIMIT 1");
    } else {
        $result = mysqli_query($conn, "SELECT company_id, company_name, company_address, company_phone,
                                              company_pic_name, company_pic_contact, company_top
                                       FROM customer WHERE company_id = '$customer_id' LIMIT 1");
    }

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Customer found', mysqli_fetch_assoc($result));
    } else {
        jsonResponse(404, 'Customer not found');
    }
}

function createCustomer($conn, array $input, string $company_id): void {
    $required = ['company_name', 'company_address', 'company_phone', 'company_pic_name', 'company_pic_contact', 'company_top'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            jsonResponse(400, "$field is required");
        }
    }

    $company_name        = mysqli_real_escape_string($conn, trim($input['company_name']));
    $company_address     = mysqli_real_escape_string($conn, trim($input['company_address']));
    $company_phone       = mysqli_real_escape_string($conn, trim($input['company_phone']));
    $company_pic_name    = mysqli_real_escape_string($conn, trim($input['company_pic_name']));
    $company_pic_contact = mysqli_real_escape_string($conn, trim($input['company_pic_contact']));
    $company_top         = mysqli_real_escape_string($conn, trim($input['company_top']));
    $company_id          = mysqli_real_escape_string($conn, $company_id);

    $id     = generateUUID();
    $insert = "INSERT INTO customer (company_id, company, company_name, company_address, company_phone, company_pic_name, company_pic_contact, company_top)
               VALUES ('$id', '$company_id', '$company_name', '$company_address', '$company_phone', '$company_pic_name', '$company_pic_contact', '$company_top')";

    if (mysqli_query($conn, $insert)) {
        jsonResponse(201, 'Customer created successfully', ['company_id' => $id]);
    } else {
        jsonResponse(500, 'Failed to create customer');
    }
}

function updateCustomer($conn, array $input, string $company_id): void {
    if (empty($input['company_id'])) jsonResponse(400, 'company_id is required');

    $customer_id = mysqli_real_escape_string($conn, $input['company_id']);
    $company_id  = mysqli_real_escape_string($conn, $company_id);

    $check = mysqli_query($conn, "SELECT 1 FROM customer WHERE company_id = '$customer_id' AND company = '$company_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) jsonResponse(404, 'Customer not found');

    $updates = [];
    $fields  = ['company_name', 'company_address', 'company_phone', 'company_pic_name', 'company_pic_contact', 'company_top'];
    foreach ($fields as $f) {
        if (isset($input[$f])) {
            $val       = mysqli_real_escape_string($conn, trim($input[$f]));
            $updates[] = "$f = '$val'";
        }
    }

    if (empty($updates)) jsonResponse(400, 'No fields provided for update');

    if (mysqli_query($conn, "UPDATE customer SET " . implode(', ', $updates) . " WHERE company_id = '$customer_id' AND company = '$company_id'")) {
        jsonResponse(200, 'Customer updated successfully');
    } else {
        jsonResponse(500, 'Failed to update customer');
    }
}

$decoded    = verifyToken();
$username   = $decoded->sub ?? '';
$company_id = $decoded->companyId ?? '';

$conn = DB::conn();
$GLOBALS['_log_conn']       = $conn;
$GLOBALS['_log_user']       = $username;
$GLOBALS['_log_company_id'] = $company_id;
$GLOBALS['_log_request_id'] = bin2hex(random_bytes(8));

if (!$company_id) {
    jsonResponse(400, 'company_id missing from token');
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $customer_id = $_GET['company_id'] ?? '';
            $type        = $_GET['type'] ?? '';

            if ($customer_id !== '') {
                getDetailCustomer($conn, $customer_id, $type);
            } else {
                getAllCustomer(
                    $conn,
                    $company_id,
                    $_GET['params'] ?? '',
                    (int)($_GET['page']  ?? 1),
                    (int)($_GET['limit'] ?? 10)
                );
            }
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createCustomer($conn, $input, $company_id);
            break;

        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            updateCustomer($conn, $input, $company_id);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
