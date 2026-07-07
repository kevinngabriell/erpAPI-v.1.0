<?php

require_once '../../general.php';
require_once '../../vendor/autoload.php';
require_once '../../connection/connection.php';
require_once '../../auth/middleware.php';

function getAllSupplier($conn, string $company_id, string $type = '', string $search = '', int $page = 1, int $limit = 10): void {
    $company_id = mysqli_real_escape_string($conn, $company_id);
    $search     = mysqli_real_escape_string($conn, $search);
    $page       = max(1, $page);
    $limit      = min(100, max(1, $limit));
    $offset     = ($page - 1) * $limit;

    $where = "A1.company = '$company_id'";

    if ($search !== '') {
        $where .= " AND (A1.supplier_name LIKE '%$search%' OR A1.supplier_phone LIKE '%$search%' OR A1.supplier_pic_name LIKE '%$search%')";
    }

    if ($type === 'import') {
        $where      .= " AND A1.supplier_origin != '10'";
        $extra_cols  = 'A1.supplier_currency, A1.supplier_term, A1.supplier_bank_information,';
    } elseif ($type === 'local') {
        $where      .= " AND A1.supplier_origin = '10'";
        $extra_cols  = 'A1.supplier_currency, A1.supplier_term, A1.supplier_bank_information,';
    } else {
        $extra_cols = 'A3.currency_name,';
    }

    $currency_join = ($type === '') ? ' LEFT JOIN currency A3 ON A1.supplier_currency = A3.currency_id' : '';
    $from_clause   = "FROM supplier A1 LEFT JOIN origin A2 ON A2.origin_id = A1.supplier_origin$currency_join";

    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total $from_clause WHERE $where");
    $total        = (int) mysqli_fetch_assoc($count_result)['total'];

    $result = mysqli_query($conn,
        "SELECT A1.supplier_id, A1.supplier_name, A1.supplier_phone, A2.origin_name,
                A1.supplier_pic_name, A1.supplier_pic_contact, A1.supplier_origin, $extra_cols
                A1.supplier_currency
         $from_clause WHERE $where
         ORDER BY A1.supplier_name ASC LIMIT $limit OFFSET $offset"
    );

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
        jsonResponse(404, 'No suppliers found');
    }
}

function getDetailSupplier($conn, string $supplier_id, string $type = ''): void {
    if ($supplier_id === '') jsonResponse(400, 'supplier_id is required');

    $supplier_id = mysqli_real_escape_string($conn, $supplier_id);

    switch ($type) {
        case 'history':
            $query = "SELECT A1.PONumber, A3.PO_Type_Name, A1.PODate, A4.origin_name, A5.PO_Status_Name
                      FROM purchaseOrder A1
                      LEFT JOIN supplier A2 ON A1.POSupplier = A2.supplier_id
                      LEFT JOIN purchaseType A3 ON A1.POType = A3.PO_Type_ID
                      LEFT JOIN origin A4 ON A1.POOrigin = A4.origin_id
                      LEFT JOIN purchaseStatus A5 ON A1.POStatus = A5.PO_Status_ID
                      WHERE A1.POSupplier = '$supplier_id'";
            break;
        case 'currency':
            $query = "SELECT A1.supplier_currency, A2.currency_name
                      FROM supplier A1 LEFT JOIN currency A2 ON A1.supplier_currency = A2.currency_id
                      WHERE A1.supplier_id = '$supplier_id' LIMIT 1";
            break;
        case 'origin':
            $query = "SELECT A1.supplier_origin, A2.origin_name
                      FROM supplier A1 LEFT JOIN origin A2 ON A1.supplier_origin = A2.origin_id
                      WHERE A1.supplier_id = '$supplier_id' LIMIT 1";
            break;
        case 'term':
            $query = "SELECT A1.supplier_term, A2.term_name
                      FROM supplier A1 LEFT JOIN term A2 ON A1.supplier_term = A2.term_id
                      WHERE A1.supplier_id = '$supplier_id' LIMIT 1";
            break;
        case 'pic':
            $query = "SELECT supplier_pic_name, supplier_origin, supplier_currency, supplier_term
                      FROM supplier WHERE supplier_id = '$supplier_id' LIMIT 1";
            break;
        default:
            $query = "SELECT A1.supplier_id, A1.supplier_name, A1.supplier_phone, A1.supplier_address,
                             A1.supplier_pic_name, A1.supplier_pic_contact, A1.supplier_origin,
                             A2.origin_is_free_trade, A1.supplier_currency, A1.supplier_term, A1.supplier_bank_information
                      FROM supplier A1 JOIN origin A2 ON A2.origin_id = A1.supplier_origin
                      WHERE A1.supplier_id = '$supplier_id' LIMIT 1";
    }

    $result = mysqli_query($conn, $query);

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
        jsonResponse(200, 'Supplier found', count($data) === 1 ? $data[0] : $data);
    } else {
        jsonResponse(404, 'Supplier not found');
    }
}

function createSupplier($conn, array $input, string $company_id): void {
    $required = ['supplier_name', 'supplier_origin', 'supplier_address', 'supplier_phone', 'supplier_pic_name', 'supplier_pic_contact', 'supplier_currency', 'supplier_term', 'supplier_bank'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            jsonResponse(400, "$field is required");
        }
    }

    $supplier_name        = mysqli_real_escape_string($conn, trim($input['supplier_name']));
    $supplier_origin      = mysqli_real_escape_string($conn, trim($input['supplier_origin']));
    $supplier_address     = mysqli_real_escape_string($conn, trim($input['supplier_address']));
    $supplier_phone       = mysqli_real_escape_string($conn, trim($input['supplier_phone']));
    $supplier_pic_name    = mysqli_real_escape_string($conn, trim($input['supplier_pic_name']));
    $supplier_pic_contact = mysqli_real_escape_string($conn, trim($input['supplier_pic_contact']));
    $supplier_currency    = mysqli_real_escape_string($conn, trim($input['supplier_currency']));
    $supplier_term        = mysqli_real_escape_string($conn, trim($input['supplier_term']));
    $supplier_bank        = mysqli_real_escape_string($conn, trim($input['supplier_bank']));
    $company_id           = mysqli_real_escape_string($conn, $company_id);
    $id                   = generateUUID();

    $insert = "INSERT INTO supplier
               (supplier_id, company, supplier_name, supplier_origin, supplier_address, supplier_phone,
                supplier_pic_name, supplier_pic_contact, supplier_currency, supplier_term, supplier_bank_information)
               VALUES ('$id', '$company_id', '$supplier_name', '$supplier_origin', '$supplier_address', '$supplier_phone',
                       '$supplier_pic_name', '$supplier_pic_contact', '$supplier_currency', '$supplier_term', '$supplier_bank')";

    if (mysqli_query($conn, $insert)) {
        jsonResponse(201, 'Supplier created successfully', ['supplier_id' => $id]);
    } else {
        jsonResponse(500, 'Failed to create supplier');
    }
}

function updateSupplier($conn, array $input, string $company_id): void {
    if (empty($input['supplier_id'])) jsonResponse(400, 'supplier_id is required');

    $supplier_id = mysqli_real_escape_string($conn, $input['supplier_id']);
    $company_id  = mysqli_real_escape_string($conn, $company_id);

    $check = mysqli_query($conn, "SELECT 1 FROM supplier WHERE supplier_id = '$supplier_id' AND company = '$company_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) jsonResponse(404, 'Supplier not found');

    $updatable = ['supplier_name', 'supplier_phone', 'supplier_address', 'supplier_pic_name', 'supplier_pic_contact', 'supplier_origin', 'supplier_currency', 'supplier_term'];
    $updates   = [];

    foreach ($updatable as $f) {
        if (isset($input[$f])) {
            $val       = mysqli_real_escape_string($conn, trim($input[$f]));
            $updates[] = "$f = '$val'";
        }
    }

    if (isset($input['supplier_bank'])) {
        $val       = mysqli_real_escape_string($conn, trim($input['supplier_bank']));
        $updates[] = "supplier_bank_information = '$val'";
    }

    if (empty($updates)) jsonResponse(400, 'No fields provided for update');

    if (mysqli_query($conn, "UPDATE supplier SET " . implode(', ', $updates) . " WHERE supplier_id = '$supplier_id' AND company = '$company_id'")) {
        jsonResponse(200, 'Supplier updated successfully');
    } else {
        jsonResponse(500, 'Failed to update supplier');
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

// migration_temp_venken.supplier still keys on the pre-SaaS Venken UUID; forcing
// it here until the legacy tables are backfilled to the new company_id scheme.
$company_id = '1252f67e-bfda-11ee-9dcf-0e799759a249';

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $supplier_id = $_GET['supplier_id'] ?? '';
            $type        = $_GET['type'] ?? '';

            if ($supplier_id !== '') {
                getDetailSupplier($conn, $supplier_id, $type);
            } else {
                getAllSupplier(
                    $conn,
                    $company_id,
                    $type,
                    $_GET['params'] ?? '',
                    (int)($_GET['page']  ?? 1),
                    (int)($_GET['limit'] ?? 10)
                );
            }
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createSupplier($conn, $input, $company_id);
            break;

        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            updateSupplier($conn, $input, $company_id);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
