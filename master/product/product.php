<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../general.php';
require_once '../../vendor/autoload.php';
require_once '../../connection/connection.php';
require_once '../../auth/middleware.php';

function getAllProduct($conn, string $search = '', int $page = 1, int $limit = 10): void {
    $search = mysqli_real_escape_string($conn, $search);
    $page   = max(1, $page);
    $limit  = min(100, max(1, $limit));
    $offset = ($page - 1) * $limit;

    $where = $search !== ''
        ? "WHERE skuID LIKE '%$search%' OR productName LIKE '%$search%' OR productDesc LIKE '%$search%'"
        : '';

    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM product $where");
    $total        = (int) mysqli_fetch_assoc($count_result)['total'];

    $result = mysqli_query($conn, "SELECT skuID, productName, productDesc FROM product $where ORDER BY productName ASC LIMIT $limit OFFSET $offset");

    if ($result && mysqli_num_rows($result) > 0) {
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = [
                'skuID'               => $row['skuID'],
                'Code'                => $row['skuID'],
                'Product Name'        => $row['productName'],
                'Product Description' => $row['productDesc'],
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
        jsonResponse(404, 'No products found');
    }
}

function getDetailProduct($conn, string $product_code): void {
    if ($product_code === '') jsonResponse(400, 'product_code is required');

    $code   = mysqli_real_escape_string($conn, $product_code);
    $result = mysqli_query($conn, "SELECT skuID, productName, productDesc FROM product WHERE skuID = '$code' LIMIT 1");

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Product found', mysqli_fetch_assoc($result));
    } else {
        jsonResponse(404, 'Product not found');
    }
}

function createProduct($conn, array $input, string $username): void {
    $required = ['product_code', 'product_name', 'product_desc'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            jsonResponse(400, "$field is required");
        }
    }

    $product_code = mysqli_real_escape_string($conn, trim($input['product_code']));
    $product_name = mysqli_real_escape_string($conn, trim($input['product_name']));
    $product_desc = mysqli_real_escape_string($conn, trim($input['product_desc']));
    $insert_by    = mysqli_real_escape_string($conn, $username);
    $insert_dt    = getCurrentDateTimeJakarta();

    $dup = mysqli_query($conn, "SELECT 1 FROM product WHERE skuID = '$product_code' LIMIT 1");
    if (mysqli_num_rows($dup) > 0) jsonResponse(409, 'product_code already exists');

    $query = "INSERT INTO product (skuID, productName, productDesc, insertBy, insertDt)
              VALUES ('$product_code', '$product_name', '$product_desc', '$insert_by', '$insert_dt')";

    if (mysqli_query($conn, $query)) {
        jsonResponse(201, 'Product created successfully', ['skuID' => $product_code]);
    } else {
        jsonResponse(500, 'Failed to create product');
    }
}

function updateProduct($conn, array $input): void {
    $required = ['product_code_before', 'product_name_before', 'product_desc_before', 'product_code_new', 'product_name_new', 'product_desc_new'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            jsonResponse(400, "$field is required");
        }
    }

    $code_new = mysqli_real_escape_string($conn, trim($input['product_code_new']));
    $name_new = mysqli_real_escape_string($conn, trim($input['product_name_new']));
    $desc_new = mysqli_real_escape_string($conn, trim($input['product_desc_new']));
    $code_old = mysqli_real_escape_string($conn, trim($input['product_code_before']));
    $name_old = mysqli_real_escape_string($conn, trim($input['product_name_before']));
    $desc_old = mysqli_real_escape_string($conn, trim($input['product_desc_before']));

    $check = mysqli_query($conn, "SELECT 1 FROM product WHERE skuID = '$code_old' AND productName = '$name_old' AND productDesc = '$desc_old' LIMIT 1");
    if (mysqli_num_rows($check) === 0) jsonResponse(404, 'Product not found');

    $query = "UPDATE product SET skuID = '$code_new', productName = '$name_new', productDesc = '$desc_new'
              WHERE skuID = '$code_old' AND productName = '$name_old' AND productDesc = '$desc_old'";

    if (mysqli_query($conn, $query)) {
        jsonResponse(200, 'Product updated successfully');
    } else {
        jsonResponse(500, 'Failed to update product');
    }
}

function deleteProduct($conn, ?string $product_code): void {
    if (!$product_code) jsonResponse(400, 'product_code is required');

    $code  = mysqli_real_escape_string($conn, $product_code);
    $check = mysqli_query($conn, "SELECT 1 FROM product WHERE skuID = '$code' LIMIT 1");
    if (mysqli_num_rows($check) === 0) jsonResponse(404, 'Product not found');

    if (mysqli_query($conn, "DELETE FROM product WHERE skuID = '$code'")) {
        jsonResponse(200, 'Product deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete product');
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
            $product_code = $_GET['product_code'] ?? '';
            if ($product_code !== '') {
                getDetailProduct($conn, $product_code);
            } else {
                getAllProduct(
                    $conn,
                    $_GET['params'] ?? '',
                    (int)($_GET['page']  ?? 1),
                    (int)($_GET['limit'] ?? 10)
                );
            }
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createProduct($conn, $input, $username);
            break;

        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            updateProduct($conn, $input);
            break;

        case 'DELETE':
            deleteProduct($conn, $_GET['product_code'] ?? null);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
