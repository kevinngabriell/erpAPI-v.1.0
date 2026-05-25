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
require_once('../../connection/connection.php');

//Checking call API method
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $purchase_order_supplier = $_POST['purchase_order_supplier'];
    $purchase_order_number = $_POST['purchase_order_number'];
    $receiving_date = $_POST['receiving_date'];
    $ship_date = $_POST['ship_date'];
    $ship_via = $_POST['ship_via'];
    $insert_by = $_POST['insert_by'];
    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");

    $date_parts = explode(' ', $receiving_date);
    $date_string = $date_parts[1] . ' ' . $date_parts[2] . ' ' . $date_parts[3];
    $receiving_date_obj = DateTime::createFromFormat('M d Y', $date_string);
    $formatted_receiving_date = $receiving_date_obj->format("Y-m-d");

    $date_parts_ship = explode(' ', $ship_date);
    $date_string_ship = $date_parts_ship[1] . ' ' . $date_parts_ship[2] . ' ' . $date_parts_ship[3];
    $ship_date_obj_ship = DateTime::createFromFormat('M d Y', $date_string_ship);
    $formatted_ship_date = $ship_date_obj_ship->format("Y-m-d");

    $product_length = $_POST['product_length'];

    $insert_purchase_receive_query = "INSERT INTO purchaseRecieve (supplierID, PONumber, ReceivingDate, ShipDate, ShipVia, InsertBy , InsertDt) 
    VALUES ('$purchase_order_supplier', '$purchase_order_number', '$formatted_receiving_date', '$formatted_ship_date', '$ship_via', '$insert_by', '$currentDateTimeString');";

    $update_query = "UPDATE purchaseOrder SET POStatus = 'e73d9d9c-1438-11ef-9' WHERE PONumber = '$purchase_order_number'";

    if(mysqli_query($connect, $insert_purchase_receive_query) && mysqli_query($connect, $update_query)){

        for ($i = 1; $i <= $product_length; $i++) {
            // Construct the variable names dynamically
            $purchase_order_product_name = $_POST['purchase_order_product_name_' . $i];
            $purchase_order_product_quantity = $_POST['purchase_order_product_quantity_' . $i];
            $purchase_order_product_packaging_size = $_POST['purchase_order_product_packaging_size_' . $i];
            $purchase_order_product_unit_price = $_POST['purchase_order_product_unit_price_' . $i];
            echo json_encode(
                $purchase_order_product_name
            );
            // Insert the product into the database
            $insert_item_query = "INSERT INTO purchaseReceiveItem (PONumber, ProductName, Quantity, PackagingSize, UnitPrice, VAT, Total) 
            VALUES ('$purchase_order_number','$purchase_order_product_name', '$purchase_order_product_quantity', '$purchase_order_product_packaging_size', '$purchase_order_product_unit_price', 0, 0);";
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