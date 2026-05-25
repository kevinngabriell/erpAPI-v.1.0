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
    $purchase_order_number = $_POST['purchase_order_number'];
    $purchase_order_date = $_POST['purchase_order_date'];
    // $shipment_date = $_POST['shipment_date'];
    $insert_by = $_POST['insert_by'];
    $product_length = $_POST['product_length'];
    $purchase_order_status = 'd7ab6134-d157-11ee-8';
    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");
    $action = "Draft Purchase Order telah direvisi dan menunggu persetujuan";

    $date_parts_podate = explode(' ', $purchase_order_date);
    $date_string_podate = $date_parts_podate[1] . ' ' . $date_parts_podate[2] . ' ' . $date_parts_podate[3];
    $purchase_order_date_obj = DateTime::createFromFormat('M d Y', $date_string_podate);
    $formatted_purchase_order_date = $purchase_order_date_obj->format("Y-m-d");

    // Prepare and execute query to update salesOrder table
    $update_purchase_order_query = "UPDATE purchaseOrder SET PODate = ?, POStatus = ?, UpdateBy = ?, UpdateDt = ? WHERE PONumber = ?";
    $stmt_update_purchase_order = mysqli_prepare($connect, $update_purchase_order_query);
    mysqli_stmt_bind_param($stmt_update_purchase_order, 'sssss', $formatted_purchase_order_date, $purchase_order_status, $insert_by, $currentDateTimeString, $purchase_order_number);
    $success_update_purchase_order = mysqli_stmt_execute($stmt_update_purchase_order);

    // Prepare and execute query to insert into salesOrderHistory table
    $insert_purchase_order_history_query = "INSERT INTO purchaseOrderHistory (PONumber, Action, ActionBy, ActionDt) VALUES (?, ?, ?, ?)";
    $stmt_insert_purchase_order_history = mysqli_prepare($connect, $insert_purchase_order_history_query);
    mysqli_stmt_bind_param($stmt_insert_purchase_order_history, 'ssss', $purchase_order_number, $action, $insert_by, $currentDateTimeString);
    $success_insert_history = mysqli_stmt_execute($stmt_insert_purchase_order_history);

    // Loop through products to update salesOrderItem table
    for ($i = 1; $i <= $product_length; $i++) {
        $purchase_order_product_name = $_POST['purchase_order_product_name_' . $i];
        $purchase_order_quantity_before = $_POST['purchase_order_quantity_before_' . $i];
        $purchase_order_unit_price_before = $_POST['purchase_order_unit_price_before_' . $i];
        $purchase_order_quantity_after = $_POST['purchase_order_quantity_after_' . $i];
        $purchase_order_unit_price_after = $_POST['purchase_order_unit_price_after_' . $i];

        // Prepare and execute query to update salesOrderItem table
        $update_item_query = "UPDATE purchaseOrderItem SET POQuantity = ?, POUnitPrice = ? WHERE PONumber = ? AND POProductName = ? AND POQuantity = ? AND POUnitPrice = ?";
        $stmt_update_item = mysqli_prepare($connect, $update_item_query);
        mysqli_stmt_bind_param($stmt_update_item, 'ddssss', $purchase_order_quantity_after, $purchase_order_unit_price_after, $purchase_order_number, $purchase_order_product_name, $purchase_order_quantity_before, $purchase_order_unit_price_before);
        mysqli_stmt_execute($stmt_update_item);
    }

    // Check if all queries were successful
    if ($success_update_purchase_order && $success_insert_history) {
        echo json_encode(array(
            "StatusCode" => 200,
            "Status" => "Success",
            "message" => "Data updated successfully"
        ));
    } else {
        http_response_code(500);
        echo json_encode(array(
            "StatusCode" => 500,
            "Status" => "Error",
            "message" => "Error: Unable to update data - " . mysqli_error($connect)
        ));
    }
} else {
    http_response_code(404);
    echo json_encode(array(
        "StatusCode" => 404,
        "Status" => "Error",
        "message" => "Error: Invalid method. Only POST requests are allowed."
    ));
}