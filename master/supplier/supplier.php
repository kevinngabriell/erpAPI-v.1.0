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
// Searchable by: supplier_name, supplier_phone, supplier_pic_name
// ?company=X  &type=import|local  &params=search  &page=1  &limit=10
function getAllSupplier($conn, string $company, string $type = '', string $params = '', int $page = 1, int $limit = 10): void {
    if ($company === '') jsonResponse(400, 'company is required');

    $c      = mysqli_real_escape_string($conn, $company);
    $params = mysqli_real_escape_string($conn, $params);
    $page   = max(1, $page);
    $limit  = min(100, max(1, $limit));
    $offset = ($page - 1) * $limit;

    $search = $params !== ''
        ? "AND (A1.supplier_name LIKE '%$params%' OR A1.supplier_phone LIKE '%$params%' OR A1.supplier_pic_name LIKE '%$params%')"
        : '';

    if ($type === 'import') {
        $typeFilter = "AND supplier_origin != '10'";
        $selectExtra = 'A1.supplier_currency, A1.supplier_term, A1.supplier_bank_information,';
    } elseif ($type === 'local') {
        $typeFilter = "AND supplier_origin = '10'";
        $selectExtra = 'A1.supplier_currency, A1.supplier_term, A1.supplier_bank_information,';
    } else {
        $typeFilter  = '';
        $selectExtra = 'A3.currency_name,';
    }

    $baseFrom  = "FROM supplier A1 JOIN origin A2 ON A2.origin_id = A1.supplier_origin";
    $baseFrom .= ($type === '' ? " JOIN currency A3 ON A1.supplier_currency = A3.currency_id" : '');
    $baseWhere = "WHERE A1.company = '$c' $typeFilter $search";

    $countResult = mysqli_query($conn, "SELECT COUNT(*) AS total $baseFrom $baseWhere");
    $total       = (int) mysqli_fetch_assoc($countResult)['total'];

    $result = mysqli_query($conn,
        "SELECT A1.supplier_id, A1.supplier_name, A1.supplier_phone, A2.origin_name,
                A1.supplier_pic_name, A1.supplier_pic_contact, A1.supplier_origin, $selectExtra
                A1.supplier_currency
         $baseFrom $baseWhere
         ORDER BY A1.supplier_name ASC LIMIT $limit OFFSET $offset"
    );

    if ($result && mysqli_num_rows($result) > 0) {
        $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
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
        jsonResponse(404, 'No suppliers found');
    }
}

// --- GET DETAIL ---
// ?supplier_id=X                  → full supplier profile
// ?supplier_id=X&type=history     → purchase order history
// ?supplier_id=X&type=currency    → currency info
// ?supplier_id=X&type=origin      → origin info
// ?supplier_id=X&type=term        → payment term info
// ?supplier_id=X&type=pic         → PIC + defaults
function getDetailSupplier($conn, string $supplier_id, string $type = ''): void {
    if ($supplier_id === '') jsonResponse(400, 'supplier_id is required');

    $id = mysqli_real_escape_string($conn, $supplier_id);

    switch ($type) {
        case 'history':
            $sql = "SELECT A1.PONumber, A3.PO_Type_Name, A1.PODate, A4.origin_name, A5.PO_Status_Name
                    FROM purchaseOrder A1
                    LEFT JOIN supplier A2 ON A1.POSupplier = A2.supplier_id
                    LEFT JOIN purchaseType A3 ON A1.POType = A3.PO_Type_ID
                    LEFT JOIN origin A4 ON A1.POOrigin = A4.origin_id
                    LEFT JOIN purchaseStatus A5 ON A1.POStatus = A5.PO_Status_ID
                    WHERE A1.POSupplier = '$id'";
            break;
        case 'currency':
            $sql = "SELECT A1.supplier_currency, A2.currency_name
                    FROM supplier A1 LEFT JOIN currency A2 ON A1.supplier_currency = A2.currency_id
                    WHERE A1.supplier_id = '$id' LIMIT 1";
            break;
        case 'origin':
            $sql = "SELECT A1.supplier_origin, A2.origin_name
                    FROM supplier A1 LEFT JOIN origin A2 ON A1.supplier_origin = A2.origin_id
                    WHERE A1.supplier_id = '$id' LIMIT 1";
            break;
        case 'term':
            $sql = "SELECT A1.supplier_term, A2.term_name
                    FROM supplier A1 LEFT JOIN term A2 ON A1.supplier_term = A2.term_id
                    WHERE A1.supplier_id = '$id' LIMIT 1";
            break;
        case 'pic':
            $sql = "SELECT supplier_pic_name, supplier_origin, supplier_currency, supplier_term
                    FROM supplier WHERE supplier_id = '$id' LIMIT 1";
            break;
        default:
            $sql = "SELECT A1.supplier_id, A1.supplier_name, A1.supplier_phone, A1.supplier_address,
                           A1.supplier_pic_name, A1.supplier_pic_contact, A1.supplier_origin,
                           A2.origin_is_free_trade, A1.supplier_currency, A1.supplier_term, A1.supplier_bank_information
                    FROM supplier A1 JOIN origin A2 ON A2.origin_id = A1.supplier_origin
                    WHERE A1.supplier_id = '$id' LIMIT 1";
    }

    $result = mysqli_query($conn, $sql);

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
        jsonResponse(200, 'Supplier found', count($data) === 1 ? $data[0] : $data);
    } else {
        jsonResponse(404, 'Supplier not found');
    }
}

// --- CREATE ---
function createSupplier($conn, array $input, string $userId): void {
    $required = ['company_id', 'supplier_name', 'supplier_origin', 'supplier_address', 'supplier_phone', 'supplier_pic_name', 'supplier_pic_contact', 'supplier_currency', 'supplier_term', 'supplier_bank'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            jsonResponse(400, "$field is required");
        }
    }

    $company_id           = mysqli_real_escape_string($conn, trim($input['company_id']));
    $supplier_name        = mysqli_real_escape_string($conn, trim($input['supplier_name']));
    $supplier_origin      = mysqli_real_escape_string($conn, trim($input['supplier_origin']));
    $supplier_address     = mysqli_real_escape_string($conn, trim($input['supplier_address']));
    $supplier_phone       = mysqli_real_escape_string($conn, trim($input['supplier_phone']));
    $supplier_pic_name    = mysqli_real_escape_string($conn, trim($input['supplier_pic_name']));
    $supplier_pic_contact = mysqli_real_escape_string($conn, trim($input['supplier_pic_contact']));
    $supplier_currency    = mysqli_real_escape_string($conn, trim($input['supplier_currency']));
    $supplier_term        = mysqli_real_escape_string($conn, trim($input['supplier_term']));
    $supplier_bank        = mysqli_real_escape_string($conn, trim($input['supplier_bank']));
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

// --- UPDATE ---
function updateSupplier($conn, array $input, string $userId): void {
    if (empty($input['supplier_id'])) jsonResponse(400, 'supplier_id is required');

    $id    = mysqli_real_escape_string($conn, $input['supplier_id']);
    $check = mysqli_query($conn, "SELECT 1 FROM supplier WHERE supplier_id = '$id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) jsonResponse(404, 'Supplier not found');

    $updatable = ['supplier_name', 'supplier_phone', 'supplier_address', 'supplier_pic_name', 'supplier_pic_contact', 'supplier_origin', 'supplier_currency', 'supplier_term'];
    $updates   = [];

    foreach ($updatable as $f) {
        if (isset($input[$f])) {
            $v         = mysqli_real_escape_string($conn, trim($input[$f]));
            $updates[] = "$f = '$v'";
        }
    }

    if (isset($input['supplier_bank'])) {
        $v         = mysqli_real_escape_string($conn, trim($input['supplier_bank']));
        $updates[] = "supplier_bank_information = '$v'";
    }

    if (empty($updates)) jsonResponse(400, 'No fields provided for update');

    if (mysqli_query($conn, "UPDATE supplier SET " . implode(', ', $updates) . " WHERE supplier_id = '$id'")) {
        jsonResponse(200, 'Supplier updated successfully');
    } else {
        jsonResponse(500, 'Failed to update supplier');
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
        $supplier_id = $_GET['supplier_id'] ?? '';
        $company     = $_GET['company']     ?? '';
        $type        = $_GET['type']        ?? '';

        if ($supplier_id !== '') {
            getDetailSupplier($conn, $supplier_id, $type);
        } else {
            getAllSupplier(
                $conn,
                $company,
                $type,
                $_GET['params'] ?? '',
                (int)($_GET['page']  ?? 1),
                (int)($_GET['limit'] ?? 10)
            );
        }
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        createSupplier($conn, $input, $userId);
        break;

    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        updateSupplier($conn, $input, $userId);
        break;

    default:
        jsonResponse(405, 'Method Not Allowed');
}
