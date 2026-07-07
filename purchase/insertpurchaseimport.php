<?php
//Header access is required

//Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

//Connection access
require_once('../general.php');
require_once('../auth/middleware.php');
require_once('../connection/connection.php');

//Checking call API method
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $decoded = verifyToken();
    $purchase_order_number = $_POST['purchase_order_number'];
    $purchase_order_date = $_POST['purchase_order_date'];
    $purchase_order_supplier = $_POST['purchase_order_supplier'];
    $purchase_order_shipment = $_POST['purchase_order_shipment'];
    $purchase_order_term = $_POST['purchase_order_term'];
    $purchase_order_payment = $_POST['purchase_order_payment'];
    $purchase_order_origin = $_POST['purchase_order_origin'];
    $purchase_order_shippingmarks = $_POST['purchase_order_shippingmarks'];
    $purchase_order_remarks = $_POST['purchase_order_remarks'];
    $purchase_order_status = $_POST['purchase_order_status'];
    $purchase_order_type = $_POST['purchase_order_type'];
    $purchase_order_currency = $_POST['purchase_order_currency'];
    $insert_by = $decoded->sub;

    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");

    // Extract date from the provided string
    $date_parts = explode(' ', $purchase_order_date);
    $date_string = $date_parts[1] . ' ' . $date_parts[2] . ' ' . $date_parts[3];
    
    // Create DateTime object with the extracted date string
    $purchase_order_date_obj = DateTime::createFromFormat('M d Y', $date_string);
    $formatted_purchase_order_date = $purchase_order_date_obj->format("Y-m-d");
    
    $product_length = $_POST['product_length'];
    $insert_purchase_import_query = "INSERT INTO purchaseOrder (PONumber, PODate, POSupplier, POShipment, POTerm, POPayment, POOrigin, POShippingMarks, PORemarks, POStatus, POtype, POCurrency, InsertBy, InsertDt) 
    VALUES ('$purchase_order_number','$formatted_purchase_order_date', '$purchase_order_supplier', '$purchase_order_shipment', '$purchase_order_term', '$purchase_order_payment','$purchase_order_origin','$purchase_order_shippingmarks','$purchase_order_remarks', '$purchase_order_status', '$purchase_order_type', '$purchase_order_currency', '$insert_by', '$currentDateTimeString');";
    
    if(mysqli_query($connect, $insert_purchase_import_query)){
        
        for ($i = 1; $i <= $product_length; $i++) {
            // Construct the variable names dynamically
            $purchase_order_product_name = $_POST['purchase_order_product_name_' . $i];
            $purchase_order_product_quantity = $_POST['purchase_order_product_quantity_' . $i];
            $purchase_order_product_packaging_size = $_POST['purchase_order_product_packaging_size_' . $i];
            $purchase_order_product_unit_price = $_POST['purchase_order_product_unit_price_' . $i];
            // Insert the product into the database
            $insert_item_query = "INSERT INTO purchaseOrderItem (PONumber, POProductName, POQuantity, POPackagingSize, POUnitPrice, POVAT, POTotal) VALUES ('$purchase_order_number','$purchase_order_product_name', '$purchase_order_product_quantity', '$purchase_order_product_packaging_size', '$purchase_order_product_unit_price', 0, 0);";
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
            "message" => "Error: API not found"
        )
    );
}

?>
