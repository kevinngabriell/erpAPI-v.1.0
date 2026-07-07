<?php
// Header access is required

// Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Connection access
require_once('../../general.php');
require_once('../../auth/middleware.php');
require_once('../../connection/connection.php');

// Function to generate UUID
function generate_uuid() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Function to send JSON response
function sendResponse($statusCode, $status, $message) {
    http_response_code($statusCode);
    echo json_encode(array(
        "StatusCode" => $statusCode,
        "Status" => $status,
        "message" => $message
    ));
    exit;
}

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $decoded = verifyToken();

    // Validate required fields
    $requiredFields = [
        'purchase_order_number', 'purchase_order_supplier', 'invoice_number',
        'invoice_date', 'ship_date', 'kurs', 'term', 'product_length'
    ];
    
    foreach ($requiredFields as $field) {
        if (empty($_POST[$field]) || trim($_POST[$field]) === '-') {
            sendResponse(400, 'Error', "Missing or invalid required field: $field");
        }
    }

    $purchase_order_number = $_POST['purchase_order_number'];
    $purchase_order_supplier = $_POST['purchase_order_supplier'];
    $invoice_number = $_POST['invoice_number'];
    $invoice_date = $_POST['invoice_date'];
    $ship_date = $_POST['ship_date'];
    $kurs = $_POST['kurs'];
    $term = $_POST['term'];
    $username = $decoded->sub;
    $insert_by = $decoded->sub;

    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");

    // Check for duplicate invoice before inserting
    $checkStmt = $connect->prepare("SELECT invoiceNumber FROM purchaseInvoice WHERE invoiceNumber = ? LIMIT 1");
    $checkStmt->bind_param("s", $invoice_number);
    $checkStmt->execute();
    $checkStmt->store_result();
    if ($checkStmt->num_rows > 0) {
        sendResponse(409, 'Error', "Invoice number already exists: $invoice_number");
    }
    $checkStmt->close();

    // Prepare and bind — note: insertBy uses $insert_by, not $username
    $stmt = $connect->prepare("INSERT INTO purchaseInvoice (PONumber, supplier, invoiceNumber, invoiceDate, shipDate, kurs, term, insertBy, insertDt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssssss", $purchase_order_number, $purchase_order_supplier, $invoice_number, $invoice_date, $ship_date, $kurs, $term, $insert_by, $currentDateTimeString);

    if (!$stmt->execute()) {
        sendResponse(500, 'Error', "Error: Unable to insert data to purchaseInvoice table - " . $stmt->error);
    }

    $stmt = $connect->prepare("UPDATE purchaseOrder SET POStatus = 'e4376c01-1438-11ef-9' WHERE PONumber = ?");
    $stmt->bind_param("s", $purchase_order_number);
    
    if (!$stmt->execute()) {
        sendResponse(500, 'Error', "Error: Unable to update purchaseOrder table - " . $stmt->error);
    }
    
    $product_length = (int)$_POST['product_length'];
    
    for ($i = 1; $i <= $product_length; $i++) {
        $requiredProductFields = [
            "purchase_order_product_name_$i", "purchase_order_product_quantity_$i", 
            "purchase_order_product_packaging_size_$i", "purchase_order_product_unit_price_$i"
        ];
        
        foreach ($requiredProductFields as $field) {
            if (empty($_POST[$field])) {
                sendResponse(400, 'Error', "Missing required field: $field");
            }
        }
        
        $purchase_order_product_name = $_POST["purchase_order_product_name_$i"];
        $purchase_order_product_quantity = $_POST["purchase_order_product_quantity_$i"];
        $purchase_order_product_packaging_size = $_POST["purchase_order_product_packaging_size_$i"];
        $purchase_order_product_unit_price = $_POST["purchase_order_product_unit_price_$i"];
        
        $stmt = $connect->prepare("INSERT INTO PurchaseInvoiceItem (PONumber, ProductName, Quantity, PackagingSize, UnitPrice, VAT, Total) VALUES (?, ?, ?, ?, ?, 0, 0)");
        $stmt->bind_param("sssss", $purchase_order_number, $purchase_order_product_name, $purchase_order_product_quantity, $purchase_order_product_packaging_size, $purchase_order_product_unit_price);
        
        if (!$stmt->execute()) {
            sendResponse(500, 'Error', "Error: Unable to insert data to PurchaseInvoiceItem table - " . $stmt->error);
        }
    }

    // Insert into financeItem table
    $total_amount = $_POST['total_amount'];
    $id_transaction = generate_uuid();
    $stmt = $connect->prepare("INSERT INTO financeItem (id_transaction, invoice_number, paid_amount, due_amount, insert_by, insert_dt) VALUES (?, ?, '0', ?, ?, ?)");
    $stmt->bind_param("sssss", $id_transaction, $invoice_number, $total_amount, $insert_by, $currentDateTimeString);
    if (!$stmt->execute()) {
        sendResponse(500, 'Error', "Error: Unable to insert data to financeItem table - {$stmt->error}");
    }
    
    sendResponse(200, 'Success', "Data successfully inserted and updated.");
} else {
    sendResponse(404, 'Error', "Error: Invalid method. Only POST requests are allowed.");
}
?>
