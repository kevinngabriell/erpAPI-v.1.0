<?php
// Header access is required

// Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Connection access
require_once('../connection/connection.php');

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sales_order_number = $_POST['sales_order_number'];
    $sales_order_date = $_POST['sales_order_date'];
    $insert_by = $_POST['insert_by'];
    $product_length = $_POST['product_length'];
    $sales_order_status = '76b86e17-1efc-11ef-a';
    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");
    $action = "Draft SPPB telah direvisi dan menunggu persetujuan";

    $date_parts_sodate = explode(' ', $sales_order_date);
    $date_string_sodate = $date_parts_sodate[1] . ' ' . $date_parts_sodate[2] . ' ' . $date_parts_sodate[3];
    $sales_order_date_obj = DateTime::createFromFormat('M d Y', $date_string_sodate);
    $formatted_sales_order_date = $sales_order_date_obj->format("Y-m-d");

    // Prepare and execute query to update salesOrder table
    $update_sales_order_query = "UPDATE salesSPPB SET SPPBDate = ?, UpdateBy = ?, UpdateDt = ? WHERE SPPBNumber = ?";
    $stmt_update_sales_order = mysqli_prepare($connect, $update_sales_order_query);
    mysqli_stmt_bind_param($stmt_update_sales_order, 'ssss', $formatted_sales_order_date, $insert_by, $currentDateTimeString, $sales_order_number);
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

     // Loop through products to update salesOrderItem table
     for ($i = 1; $i <= $product_length; $i++) {
        $sales_order_po_number = $_POST['sales_order_PO_' . $i];
        $sales_order_product_name = $_POST['sales_order_product_name_' . $i];
        $sales_order_send_to_before = $_POST['sales_order_send_to_before_' . $i];
        $sales_order_send_date_before = $_POST['sales_order_send_date_before_' . $i];
        $sales_order_quantity_before = $_POST['sales_order_quantity_before_' . $i];
        $sales_order_send_to_after = $_POST['sales_order_send_to_after_' . $i];
        $sales_order_send_date_after = $_POST['sales_order_send_date_after_' . $i];
        $sales_order_quantity_after = $_POST['sales_order_quantity_after_' . $i];
        
        $date_parts_senddate = explode(' ', $sales_order_send_date_after);
        $date_string_senddate = $date_parts_senddate[1] . ' ' . $date_parts_senddate[2] . ' ' . $date_parts_senddate[3];
        $sppb_send_date_obj = DateTime::createFromFormat('M d Y', $date_string_senddate);
        $formatted_sppb_send_date = $sppb_send_date_obj->format("Y-m-d");

        // Prepare and execute query to update salesOrderItem table
        $update_item_query = "UPDATE salesSPPBItem SET SendTo = ?, SendDate = ?, Quantity = ? WHERE SPPBNumber = ? AND PONumber = ? AND SendTo = ? AND SendDate = ? AND ProductName = ? AND Quantity = ?";
        $stmt_update_item = mysqli_prepare($connect, $update_item_query);
        mysqli_stmt_bind_param($stmt_update_item, 'sssssssss', $sales_order_send_to_after, $formatted_sppb_send_date, $sales_order_quantity_after, $sales_order_number, $sales_order_po_number, $sales_order_send_to_before, $sales_order_send_date_before, $sales_order_product_name, $sales_order_quantity_before);
        mysqli_stmt_execute($stmt_update_item);
        
        
    }   
    // Check if all queries were successful
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