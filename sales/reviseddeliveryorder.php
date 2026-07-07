<?php
// Header access is required

// Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Connection access
require_once('../general.php');
require_once('../auth/middleware.php');
require_once('../connection/connection.php');

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $decoded = verifyToken();
    $sales_order_number = $_POST['sales_order_number'];
    $delivery_date = $_POST['delivery_date'];
    $insert_by = $decoded->sub;
    $product_length = $_POST['product_length'];
    $sales_order_status = '8096b9e4-1efc-11ef-a';
    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");
    $action = "Draft Delivery Order telah direvisi dan menunggu persetujuan";

    $date_parts_delivery_date = explode(' ', $delivery_date);
    $date_string_delivery_date = $date_parts_delivery_date[1] . ' ' . $date_parts_delivery_date[2] . ' ' . $date_parts_delivery_date[3];
    $delivery_date_obj = DateTime::createFromFormat('M d Y', $date_string_delivery_date);
    $formatted_delivery_date = $delivery_date_obj->format("Y-m-d");

    // Prepare and execute query to update salesOrder table
    $update_sales_order_query = "UPDATE salesDelivery SET DeliveryDate = ?, UpdateBy = ?, UpdateDt = ? WHERE DONumber = ?";
    $stmt_update_sales_order = mysqli_prepare($connect, $update_sales_order_query);
    mysqli_stmt_bind_param($stmt_update_sales_order, 'ssss', $formatted_delivery_date, $insert_by, $currentDateTimeString, $sales_order_number);
    $success_update_sales_order = mysqli_stmt_execute($stmt_update_sales_order);

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
 
    for ($i = 1; $i <= $product_length; $i++) {
        $sales_order_product_name = $_POST['sales_order_product_name_' . $i];
        $sales_order_quantity_before = $_POST['sales_order_quantity_before_' . $i];
        $sales_order_keterangan_before = $_POST['sales_order_keterangan_before_' . $i];
        $sales_order_keterangan_after = $_POST['sales_order_keterangan_after_' . $i];
        $sales_order_quantity_after = $_POST['sales_order_quantity_after_' . $i];

        // Prepare and execute query to update salesOrderItem table
        $update_item_query = "UPDATE salesDeliveryItem SET productQTY = ?, Keterangan = ? WHERE DeliveryOrder = ? AND productName = ? AND productQTY = ? AND Keterangan = ?";
        $stmt_update_item = mysqli_prepare($connect, $update_item_query);
        mysqli_stmt_bind_param($stmt_update_item, 'ssssss', $sales_order_quantity_after, $sales_order_keterangan_after, $sales_order_number, $sales_order_product_name, $sales_order_quantity_before, $sales_order_keterangan_before);
        mysqli_stmt_execute($stmt_update_item);
    }  
    
    if ($success_update_sales_order && $success_insert_history && $success_update_sales_status) {
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