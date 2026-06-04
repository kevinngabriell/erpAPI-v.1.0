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
    // Initialize variables from POST data
    $sales_order_number = $_POST['sales_order_number'];
    $sales_order_date = $_POST['sales_order_date'];
    $sales_order_send_to = $_POST['sales_order_send_to'];
    $sales_order_send_date = $_POST['sales_order_send_date'];
    $insert_by = $_POST['insert_by'];
    $product_length = $_POST['product_length'];
    $sales_order_status = '6d352c3a-1efc-11ef-a';
    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");
    $action = "Draft Sales Order telah direvisi dan menunggu persetujuan";

    // Format SODate
    $date_parts_sodate = explode(' ', $sales_order_date);
    $date_string_sodate = $date_parts_sodate[1] . ' ' . $date_parts_sodate[2] . ' ' . $date_parts_sodate[3];
    $sales_order_date_obj = DateTime::createFromFormat('M d Y', $date_string_sodate);
    $formatted_sales_order_date = $sales_order_date_obj->format("Y-m-d");

    // Format SOSendTo date
    $date_parts_sosendto = explode(' ', $sales_order_send_to);
    $date_string_sosendto = $date_parts_sosendto[1] . ' ' . $date_parts_sosendto[2] . ' ' . $date_parts_sosendto[3];
    $sales_sentto_date_obj = DateTime::createFromFormat('M d Y', $date_string_sosendto);
    $formatted_send_to_date = $sales_sentto_date_obj->format("Y-m-d");

    // Format SOSendDate date
    $date_parts_sosenddate = explode(' ', $sales_order_send_date);
    $date_string_sosenddate = $date_parts_sosenddate[1] . ' ' . $date_parts_sosenddate[2] . ' ' . $date_parts_sosenddate[3];
    $sales_sent_date_obj = DateTime::createFromFormat('M d Y', $date_string_sosenddate);
    $formatted_send_date = $sales_sent_date_obj->format("Y-m-d");

    // Prepare and execute query to update salesOrder table
    $update_sales_order_query = "UPDATE salesOrder SET SODate = ?, SOSendTo = ?, SOSendDate = ?, SOStatus = ? WHERE SONumber = ?";
    $stmt_update_sales_order = mysqli_prepare($connect, $update_sales_order_query);
    mysqli_stmt_bind_param($stmt_update_sales_order, 'sssss', $formatted_sales_order_date, $formatted_send_to_date, $formatted_send_date, $sales_order_status,$sales_order_number);
    $success_update_sales_order = mysqli_stmt_execute($stmt_update_sales_order);

    // Prepare and execute query to insert into salesOrderHistory table
    $insert_sales_order_history_query = "INSERT INTO salesOrderHistory (SONumber, Action, ActionBy, ActionDt) VALUES (?, ?, ?, ?)";
    $stmt_insert_sales_order_history = mysqli_prepare($connect, $insert_sales_order_history_query);
    mysqli_stmt_bind_param($stmt_insert_sales_order_history, 'ssss', $sales_order_number, $action, $insert_by, $currentDateTimeString);
    $success_insert_history = mysqli_stmt_execute($stmt_insert_sales_order_history);

    // Loop through products to update salesOrderItem table
    for ($i = 1; $i <= $product_length; $i++) {
        $sales_order_po_number = $_POST['sales_order_PO_' . $i];
        $sales_order_product_name = $_POST['sales_order_product_name_' . $i];
        $sales_order_product_quantity_before = $_POST['sales_order_product_quantity_before_' . $i];
        $sales_order_hargasatuan_before = $_POST['sales_order_hargasatuan_before_' . $i];
        $sales_order_product_quantity_after = $_POST['sales_order_product_quantity_after_' . $i];
        $sales_order_hargasatuan_after = $_POST['sales_order_hargasatuan_after_' . $i];

        // Prepare and execute query to update salesOrderItem table
        $update_item_query = "UPDATE salesOrderItem SET Quantity = '$sales_order_product_quantity_after', HargaSatuan = '$sales_order_hargasatuan_after' WHERE salesOrderNumber = '$sales_order_number' AND purchaseOrderNumber = '$sales_order_po_number' AND productName = '$sales_order_product_name' AND Quantity = '$sales_order_product_quantity_before' AND HargaSatuan = '$sales_order_hargasatuan_before'";
        $result_update_item = mysqli_query($connect, $update_item_query);
    }

    // Check if all queries were successful
    if ($success_update_sales_order && $success_insert_history) {
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

// Close database connection
mysqli_close($connect);
?>
