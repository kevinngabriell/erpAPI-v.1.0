<?php
// Header access is required
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Connection access
require_once('../connection/connection.php');

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sales_order_number = $_POST['sales_order_number'];
    $insert_by = $_POST['insert_by'];
    $product_length = $_POST['product_length'];
    $sales_order_status = '7c44858e-1efc-11ef-a';
    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");
    $action = "Draft Profit telah direvisi dan menunggu persetujuan";

    // Prepare and execute query to insert into salesOrderHistory table
    $insert_sales_order_history_query = "INSERT INTO salesOrderHistory (SONumber, Action, ActionBy, ActionDt) VALUES (?, ?, ?, ?)";
    $stmt_insert_sales_order_history = mysqli_prepare($connect, $insert_sales_order_history_query);
    mysqli_stmt_bind_param($stmt_insert_sales_order_history, 'ssss', $sales_order_number, $action, $insert_by, $currentDateTimeString);
    $success_insert_history = mysqli_stmt_execute($stmt_insert_sales_order_history);
    
    // Prepare and execute query to update salesOrder table
    $update_sales_status_query = "UPDATE salesOrder SET SOStatus = ?, UpdateBy = ?, UpdateDt = ? WHERE SONumber = ?";
    $stmt_update_sales_status = mysqli_prepare($connect, $update_sales_status_query);
    mysqli_stmt_bind_param($stmt_update_sales_status, 'ssss', $sales_order_status, $insert_by, $currentDateTimeString, $sales_order_number);
    $success_update_sales_status = mysqli_stmt_execute($stmt_update_sales_status);

    // Loop through products to update salesOrderItem table
    for ($i = 1; $i <= $product_length; $i++) {
        $sales_order_po_number = $_POST['sales_order_PO_' . $i];
        $sales_order_product_name = $_POST['sales_order_product_name_' . $i];
        $sales_order_quantity = $_POST['sales_order_quantity_' . $i];
        $sales_order_landed_before = $_POST['sales_order_landed_before_' . $i];
        $sales_order_landed_after = $_POST['sales_order_landed_after_' . $i];

        // Prepare and execute query to update salesOrderItem table
        $update_item_query = "UPDATE salesProfitItem SET LandedCost = ? WHERE SalesOrderNumber = ? AND PONumber = ? AND ProductName = ? AND Quantity = ? AND LandedCost = ?";
        $stmt_update_item = mysqli_prepare($connect, $update_item_query);
        mysqli_stmt_bind_param($stmt_update_item, 'ssssss', $sales_order_landed_after, $sales_order_number, $sales_order_po_number, $sales_order_product_name, $sales_order_quantity, $sales_order_landed_before);
        mysqli_stmt_execute($stmt_update_item);
    }   
    
} else {
    http_response_code(404);
    echo json_encode(array(
        "StatusCode" => 404,
        "Status" => "Error",
        "message" => "Error: Invalid method. Only POST requests are allowed."
    ));
}