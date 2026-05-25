<?php
//Header access is required
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

//Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

//Connection access
require_once('../connection/connection.php');

//Checking call API method
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $customer_id = $_POST['customer_id'];
    $po_number = $_POST['po_number'];
    $do_number = $_POST['do_number'];
    $so_number = $_POST['so_number'];
    $delivery_date = $_POST['delivery_date'];
    $bill_to = $_POST['bill_to'];
    $ship_to = $_POST['ship_to'];
    $insert_by = $_POST['insert_by'];
    $product_length = $_POST['product_length'];
    $sales_order_status = '8096b9e4-1efc-11ef-a';
    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");
    $action = "Draft Delivery Order telah berhasil diinput dan menunggu persetujuan";

    $date_parts_deliverydate = explode(' ', $delivery_date);
    $date_string_deliverydate = $date_parts_deliverydate[1] . ' ' . $date_parts_deliverydate[2] . ' ' . $date_parts_deliverydate[3];
    $delivery_date_obj = DateTime::createFromFormat('M d Y', $date_string_deliverydate);
    $formatted_delivery_date = $delivery_date_obj->format("Y-m-d");

    $insert_sppb_query = "INSERT INTO salesDelivery (customerID, PONumber, DONumber, SONumber, DeliveryDate, BillTo, ShipTo, InsertBy, InsertDt) 
    VALUES ('$customer_id', '$po_number' ,'$do_number', '$so_number','$formatted_delivery_date', '$bill_to', '$ship_to', '$insert_by', '$currentDateTimeString');";
    $insert_sales_order_history_query = "INSERT INTO salesOrderHistory (SONumber, Action, ActionBy, ActionDt) VALUES 
    ('$so_number','$action', '$insert_by', '$currentDateTimeString')";
    $update_sales_status = "UPDATE salesOrder SET SOStatus = '8096b9e4-1efc-11ef-a' WHERE SONumber = '$so_number'";

    if(mysqli_query($connect, $insert_sppb_query) && mysqli_query($connect, $insert_sales_order_history_query) && mysqli_query($connect, $update_sales_status) ){
        
        for ($i = 1; $i <= $product_length; $i++) {
            $product_name = $_POST['product_name_' . $i];
            $quantity = $_POST['quantity_' . $i];
            $keterangan = $_POST['keterangan_' . $i];
        
            // Inserting the item with the formatted date
            $insert_item_query = "INSERT INTO salesDeliveryItem (DeliveryOrder, productName, productQTY, Keterangan) VALUES ('$so_number', '$product_name', '$quantity', '$keterangan');";
            mysqli_query($connect, $insert_item_query);
        }
        

    } else {
        http_response_code(500);
        echo json_encode(
            array(
                "StatusCode" => 500,
                'Status' => 'Error',
                "message" => "Error: Unable to insert data to purchaseOrder table - " . mysqli_error($connect)
            )
        );
    }



} else {
    http_response_code(404);
    echo json_encode(
        array(
            "StatusCode" => 404,
            'Status' => 'Error',
            "message" => "Error: Invalid method. Only POST requests are allowed."
        )
    );
}

?>