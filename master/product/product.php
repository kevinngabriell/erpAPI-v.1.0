<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../../connection/connection.php');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// GET /master/product/product.php
//   (no params)       → list all products
//   ?product_code=X   → detail for one product
if ($method === 'GET') {
    $product_code = isset($_GET['product_code']) ? $_GET['product_code'] : null;

    if ($product_code) {
        $stmt = $connect->prepare("SELECT skuID, productName, productDesc FROM product WHERE skuID = ?");
        $stmt->bind_param('s', $product_code);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = ['skuID' => $row['skuID'], 'productName' => $row['productName'], 'productDesc' => $row['productDesc']];
        }
    } else {
        $result = mysqli_query($connect, "SELECT skuID, productName, productDesc FROM product");
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = ['skuID' => $row['skuID'], 'Code' => $row['skuID'], 'Product Name' => $row['productName'], 'Product Description' => $row['productDesc']];
        }
    }

    if ($data) {
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'Data' => $data]);
    } else {
        http_response_code(400);
        echo json_encode(['StatusCode' => 400, 'Status' => 'Error Bad Request, Result not found !']);
    }

// POST /master/product/product.php → insert new product
} elseif ($method === 'POST') {
    $product_code = $_POST['product_code'];
    $product_name = $_POST['product_name'];
    $product_desc = $_POST['product_desc'];
    $username     = $_POST['username'];

    $currentDateTime = new DateTime();
    $currentDateTime->setTimezone(new DateTimeZone('Asia/Jakarta'));
    $currentDateTimeString = $currentDateTime->format('Y-m-d H:i:s');

    $stmt = $connect->prepare("INSERT INTO product (skuID, productName, productDesc, insertBy, insertDt) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('sssss', $product_code, $product_name, $product_desc, $username, $currentDateTimeString);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'message' => 'Success: Data inserted successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => 'Error: Unable to insert data - ' . $connect->error]);
    }

// PATCH /master/product/product.php → update product
} elseif ($method === 'PATCH') {
    $body = json_decode(file_get_contents('php://input'), true);

    $product_code_new    = $body['product_code_new'];
    $product_name_new    = $body['product_name_new'];
    $product_desc_new    = $body['product_desc_new'];
    $product_code_before = $body['product_code_before'];
    $product_name_before = $body['product_name_before'];
    $product_desc_before = $body['product_desc_before'];

    $stmt = $connect->prepare("UPDATE product SET skuID = ?, productName = ?, productDesc = ? WHERE skuID = ? AND productName = ? AND productDesc = ?");
    $stmt->bind_param('ssssss', $product_code_new, $product_name_new, $product_desc_new, $product_code_before, $product_name_before, $product_desc_before);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'message' => 'Success: Product Data updated successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => 'Error: Unable to update data - ' . $connect->error]);
    }

// DELETE /master/product/product.php → delete product
} elseif ($method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true);
    $product_code = $body['product_code'];

    $stmt = $connect->prepare("DELETE FROM product WHERE skuID = ?");
    $stmt->bind_param('s', $product_code);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'message' => 'Success: Data deleted successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => 'Error: Unable to delete data - ' . $connect->error]);
    }

} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Error', 'message' => 'Method not allowed. Allowed: GET, POST, PATCH, DELETE']);
}
