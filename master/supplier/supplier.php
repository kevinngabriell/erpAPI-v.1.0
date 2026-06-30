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

// GET /master/supplier/supplier.php
//   ?company=X                      → list all suppliers for company
//   ?company=X&type=import          → import suppliers only (origin != 10)
//   ?company=X&type=local           → local suppliers only (origin = 10)
//   ?supplier_id=X                  → supplier detail
//   ?supplier_id=X&type=history     → purchase order history
//   ?supplier_id=X&type=currency    → currency info
//   ?supplier_id=X&type=origin      → origin info
//   ?supplier_id=X&type=term        → term info
//   ?supplier_id=X&type=pic         → PIC name, origin, currency, term
if ($method === 'GET') {
    $company     = isset($_GET['company'])     ? $_GET['company']     : null;
    $supplier_id = isset($_GET['supplier_id']) ? $_GET['supplier_id'] : null;
    $supplier    = isset($_GET['supplier'])    ? $_GET['supplier']    : ($supplier_id ?? null);
    $type        = isset($_GET['type'])        ? $_GET['type']        : null;

    $data = [];

    if ($supplier_id && $type === 'history') {
        $stmt = $connect->prepare(
            "SELECT A1.PONumber, A3.PO_Type_Name, A1.PODate, A4.origin_name, A5.PO_Status_Name
             FROM purchaseOrder A1
             LEFT JOIN supplier A2 ON A1.POSupplier = A2.supplier_id
             LEFT JOIN purchaseType A3 ON A1.POType = A3.PO_Type_ID
             LEFT JOIN origin A4 ON A1.POOrigin = A4.origin_id
             LEFT JOIN purchaseStatus A5 ON A1.POStatus = A5.PO_Status_ID
             WHERE A1.POSupplier = ?"
        );
        $stmt->bind_param('s', $supplier_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data[] = ['PONumber' => $row['PONumber'], 'PO_Type_Name' => $row['PO_Type_Name'], 'PODate' => $row['PODate'], 'origin_name' => $row['origin_name'], 'PO_Status_Name' => $row['PO_Status_Name']];
        }

    } elseif ($supplier_id && $type === 'currency') {
        $stmt = $connect->prepare("SELECT A1.supplier_currency, A2.currency_name FROM supplier A1 LEFT JOIN currency A2 ON A1.supplier_currency = A2.currency_id WHERE A1.supplier_id = ?");
        $stmt->bind_param('s', $supplier_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data[] = ['supplier_currency' => $row['supplier_currency'], 'currency_name' => $row['currency_name']];
        }

    } elseif ($supplier_id && $type === 'origin') {
        $stmt = $connect->prepare("SELECT A1.supplier_origin, A2.origin_name FROM supplier A1 LEFT JOIN origin A2 ON A1.supplier_origin = A2.origin_id WHERE A1.supplier_id = ?");
        $stmt->bind_param('s', $supplier_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data[] = ['supplier_origin' => $row['supplier_origin'], 'origin_name' => $row['origin_name']];
        }

    } elseif ($supplier_id && $type === 'term') {
        $stmt = $connect->prepare("SELECT A1.supplier_term, A2.term_name FROM supplier A1 LEFT JOIN term A2 ON A1.supplier_term = A2.term_id WHERE A1.supplier_id = ?");
        $stmt->bind_param('s', $supplier_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data[] = ['supplier_term' => $row['supplier_term'], 'term_name' => $row['term_name']];
        }

    } elseif ($supplier_id && $type === 'pic') {
        $stmt = $connect->prepare("SELECT supplier_pic_name, supplier_origin, supplier_currency, supplier_term FROM supplier WHERE supplier_id = ?");
        $stmt->bind_param('s', $supplier_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data[] = ['supplier_pic_name' => $row['supplier_pic_name'], 'supplier_origin' => $row['supplier_origin'], 'supplier_currency' => $row['supplier_currency'], 'supplier_term' => $row['supplier_term']];
        }

    } elseif ($supplier_id) {
        $stmt = $connect->prepare(
            "SELECT A1.supplier_id, A1.supplier_name, A1.supplier_phone, A1.supplier_address,
                    A1.supplier_pic_name, A1.supplier_pic_contact, A1.supplier_origin,
                    A2.origin_is_free_trade, A1.supplier_currency, A1.supplier_term, A1.supplier_bank_information
             FROM supplier A1
             JOIN origin A2 ON A2.origin_id = A1.supplier_origin
             WHERE A1.supplier_id = ?"
        );
        $stmt->bind_param('s', $supplier_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'supplier_id'              => $row['supplier_id'],
                'supplier_name'            => $row['supplier_name'],
                'supplier_phone'           => $row['supplier_phone'],
                'supplier_address'         => $row['supplier_address'],
                'supplier_pic_name'        => $row['supplier_pic_name'],
                'supplier_pic_contact'     => $row['supplier_pic_contact'],
                'supplier_origin'          => $row['supplier_origin'],
                'origin_is_free_trade'     => $row['origin_is_free_trade'],
                'supplier_currency'        => $row['supplier_currency'],
                'supplier_term'            => $row['supplier_term'],
                'supplier_bank_information'=> $row['supplier_bank_information'],
            ];
        }

    } elseif ($company && $type === 'import') {
        $stmt = $connect->prepare(
            "SELECT A1.supplier_id, A1.supplier_name, A1.supplier_phone, A2.origin_name,
                    A1.supplier_pic_name, A1.supplier_pic_contact, A1.supplier_origin,
                    A1.supplier_currency, A1.supplier_term, A1.supplier_bank_information
             FROM supplier A1 JOIN origin A2 ON A2.origin_id = A1.supplier_origin
             WHERE A1.company = ? AND supplier_origin != '10' ORDER BY A1.supplier_name ASC"
        );
        $stmt->bind_param('s', $company);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data[] = ['supplier_id' => $row['supplier_id'], 'Supplier' => $row['supplier_name'], 'Phone Number' => $row['supplier_phone'], 'Origin' => $row['origin_name'], 'PIC' => $row['supplier_pic_name'], 'PIC Contact' => $row['supplier_pic_contact'], 'supplier_origin' => $row['supplier_origin'], 'supplier_currency' => $row['supplier_currency'], 'supplier_term' => $row['supplier_term']];
        }

    } elseif ($company && $type === 'local') {
        $stmt = $connect->prepare(
            "SELECT A1.supplier_id, A1.supplier_name, A1.supplier_phone, A2.origin_name,
                    A1.supplier_pic_name, A1.supplier_pic_contact, A1.supplier_origin,
                    A1.supplier_currency, A1.supplier_term, A1.supplier_bank_information
             FROM supplier A1 JOIN origin A2 ON A2.origin_id = A1.supplier_origin
             WHERE A1.company = ? AND supplier_origin = '10' ORDER BY A1.supplier_name ASC"
        );
        $stmt->bind_param('s', $company);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data[] = ['supplier_id' => $row['supplier_id'], 'Supplier' => $row['supplier_name'], 'Phone Number' => $row['supplier_phone'], 'Origin' => $row['origin_name'], 'PIC' => $row['supplier_pic_name'], 'PIC Contact' => $row['supplier_pic_contact'], 'supplier_origin' => $row['supplier_origin'], 'supplier_currency' => $row['supplier_currency'], 'supplier_term' => $row['supplier_term']];
        }

    } elseif ($company) {
        $stmt = $connect->prepare(
            "SELECT A1.supplier_id, A1.supplier_name, A1.supplier_phone, A2.origin_name,
                    A1.supplier_pic_name, A1.supplier_pic_contact, A1.supplier_origin, A3.currency_name
             FROM supplier A1
             JOIN origin A2 ON A2.origin_id = A1.supplier_origin
             JOIN currency A3 ON A1.supplier_currency = A3.currency_id
             WHERE A1.company = ? ORDER BY A1.supplier_name ASC"
        );
        $stmt->bind_param('s', $company);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data[] = ['supplier_id' => $row['supplier_id'], 'Supplier' => $row['supplier_name'], 'Phone Number' => $row['supplier_phone'], 'Origin' => $row['origin_name'], 'PIC' => $row['supplier_pic_name'], 'PIC Contact' => $row['supplier_pic_contact'], 'Currency' => $row['currency_name']];
        }

    } else {
        http_response_code(400);
        echo json_encode(['StatusCode' => 400, 'Status' => 'Error', 'message' => 'Missing required parameter: company or supplier_id']);
        exit;
    }

    if ($data) {
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'Data' => $data]);
    } else {
        http_response_code(400);
        echo json_encode(['StatusCode' => 400, 'Status' => 'Error Bad Request, Result not found !']);
    }

// POST /master/supplier/supplier.php → insert new supplier
} elseif ($method === 'POST') {
    $company_id          = $_POST['company_id'];
    $supplier_name       = $_POST['supplier_name'];
    $supplier_origin     = $_POST['supplier_origin'];
    $supplier_address    = $_POST['supplier_address'];
    $supplier_phone      = $_POST['supplier_phone'];
    $supplier_pic_name   = $_POST['supplier_pic_name'];
    $supplier_pic_contact= $_POST['supplier_pic_contact'];
    $supplier_currency   = $_POST['supplier_currency'];
    $supplier_term       = $_POST['supplier_term'];
    $supplier_bank       = $_POST['supplier_bank'];

    $stmt = $connect->prepare("INSERT INTO supplier (supplier_id, company, supplier_name, supplier_origin, supplier_address, supplier_phone, supplier_pic_name, supplier_pic_contact, supplier_currency, supplier_term, supplier_bank_information) VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ssssssssss', $company_id, $supplier_name, $supplier_origin, $supplier_address, $supplier_phone, $supplier_pic_name, $supplier_pic_contact, $supplier_currency, $supplier_term, $supplier_bank);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'message' => 'Success: Data inserted successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => 'Error: Unable to insert data - ' . $connect->error]);
    }

// PATCH /master/supplier/supplier.php → update supplier
} elseif ($method === 'PATCH') {
    $body = json_decode(file_get_contents('php://input'), true);

    $supplier_id         = $body['supplier_id'];
    $supplier_name       = $body['supplier_name'];
    $supplier_phone      = $body['supplier_phone'];
    $supplier_address    = $body['supplier_address'];
    $supplier_pic_name   = $body['supplier_pic_name'];
    $supplier_pic_contact= $body['supplier_pic_contact'];
    $supplier_origin     = $body['supplier_origin'];
    $supplier_currency   = $body['supplier_currency'];
    $supplier_term       = $body['supplier_term'];
    $supplier_bank       = $body['supplier_bank'];

    $stmt = $connect->prepare("UPDATE supplier SET supplier_name = ?, supplier_phone = ?, supplier_address = ?, supplier_pic_name = ?, supplier_pic_contact = ?, supplier_origin = ?, supplier_currency = ?, supplier_term = ?, supplier_bank_information = ? WHERE supplier_id = ?");
    $stmt->bind_param('ssssssssss', $supplier_name, $supplier_phone, $supplier_address, $supplier_pic_name, $supplier_pic_contact, $supplier_origin, $supplier_currency, $supplier_term, $supplier_bank, $supplier_id);

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
