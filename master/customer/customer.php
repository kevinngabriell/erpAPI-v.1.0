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

// GET /master/customer/customer.php
//   ?company=X                   → list all customers for company
//   ?company_id=X                → detail for one customer
//   ?company_id=X&type=address   → address + TOP only
if ($method === 'GET') {
    $company    = isset($_GET['company'])    ? $_GET['company']    : null;
    $company_id = isset($_GET['company_id']) ? $_GET['company_id'] : null;
    $type       = isset($_GET['type'])       ? $_GET['type']       : null;

    if ($company_id && $type === 'address') {
        $stmt = $connect->prepare("SELECT company_address, company_top FROM customer WHERE company_id = ?");
        $stmt->bind_param('s', $company_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = ['company_address' => $row['company_address'], 'company_top' => $row['company_top']];
        }

    } elseif ($company_id) {
        $stmt = $connect->prepare("SELECT company_id, company_name, company_address, company_phone, company_pic_name, company_pic_contact, company_top FROM customer WHERE company_id = ?");
        $stmt->bind_param('s', $company_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'company_id'          => $row['company_id'],
                'company_name'        => $row['company_name'],
                'company_address'     => $row['company_address'],
                'company_phone'       => $row['company_phone'],
                'company_pic_name'    => $row['company_pic_name'],
                'company_pic_contact' => $row['company_pic_contact'],
                'company_top'         => $row['company_top'],
            ];
        }

    } elseif ($company) {
        $stmt = $connect->prepare("SELECT company_id, company_name, company_address, company_phone FROM customer WHERE company = ? ORDER BY company_name ASC");
        $stmt->bind_param('s', $company);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'company_id'      => $row['company_id'],
                'Company Name'    => $row['company_name'],
                'Company Address' => $row['company_address'],
                'Company Phone'   => $row['company_phone'],
            ];
        }

    } else {
        http_response_code(400);
        echo json_encode(['StatusCode' => 400, 'Status' => 'Error', 'message' => 'Missing required parameter: company or company_id']);
        exit;
    }

    if ($data) {
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'Data' => $data]);
    } else {
        http_response_code(400);
        echo json_encode(['StatusCode' => 400, 'Status' => 'Error Bad Request, Result not found !']);
    }

// POST /master/customer/customer.php → insert new customer
} elseif ($method === 'POST') {
    $company_id          = $_POST['company_id'];
    $company_name        = $_POST['company_name'];
    $company_address     = $_POST['company_address'];
    $company_phone       = $_POST['company_phone'];
    $company_pic_name    = $_POST['company_pic_name'];
    $company_pic_contact = $_POST['company_pic_contact'];
    $company_top         = $_POST['company_top'];

    $stmt = $connect->prepare("INSERT INTO customer (company_id, company, company_name, company_address, company_phone, company_pic_name, company_pic_contact, company_top) VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('sssssss', $company_id, $company_name, $company_address, $company_phone, $company_pic_name, $company_pic_contact, $company_top);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'message' => 'Success: Data inserted successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => 'Error: Unable to insert data - ' . $connect->error]);
    }

// PATCH /master/customer/customer.php → update existing customer
} elseif ($method === 'PATCH') {
    $body = json_decode(file_get_contents('php://input'), true);

    $company_id          = $body['company_id'];
    $company_name        = $body['company_name'];
    $company_address     = $body['company_address'];
    $company_phone       = $body['company_phone'];
    $company_pic_name    = $body['company_pic_name'];
    $company_pic_contact = $body['company_pic_contact'];
    $company_top         = $body['company_top'];

    $stmt = $connect->prepare("UPDATE customer SET company_name = ?, company_address = ?, company_phone = ?, company_pic_name = ?, company_pic_contact = ?, company_top = ? WHERE company_id = ?");
    $stmt->bind_param('sssssss', $company_name, $company_address, $company_phone, $company_pic_name, $company_pic_contact, $company_top, $company_id);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'message' => 'Success: Data updated successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => 'Error: Unable to update data - ' . $connect->error]);
    }

} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Error', 'message' => 'Method not allowed. Allowed: GET, POST, PATCH']);
}
