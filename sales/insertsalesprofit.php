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
    $sales_number = $_POST['sales_number'];
    $customer_id = $_POST['customer_id'];
    $insert_by = $decoded->sub;
    $product_length = $_POST['product_length'];
    $sales_order_status = '7c44858e-1efc-11ef-a';
    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");
    $action = "Draft Profit telah berhasil diinput dan menunggu persetujuan";

    $insert_sppb_query = "INSERT INTO salesProfit (SalesNumber, ProfitCustomer, InsertBy, InsertDt) VALUES ('$sales_number', '$customer_id' ,'$insert_by', '$currentDateTimeString');";
    $insert_sales_order_history_query = "INSERT INTO salesOrderHistory (SONumber, Action, ActionBy, ActionDt) VALUES ('$sales_number','$action', '$insert_by', '$currentDateTimeString')";
    $update_sales_status = "UPDATE salesOrder SET SOStatus = '7c44858e-1efc-11ef-a' WHERE SONumber = '$sales_number'";
    
    if(mysqli_query($connect, $insert_sppb_query) && mysqli_query($connect, $insert_sales_order_history_query) && mysqli_query($connect, $update_sales_status) ){
        
        for ($i = 1; $i <= $product_length; $i++) {
            $po_number = $_POST['PO_' . $i];
            $so_number = $_POST['SO_' . $i];
            $product_name = $_POST['product_name_' . $i];
            $quantity = $_POST['quantity_' . $i];
            $price = $_POST['price_' . $i];
            $landed_cost = $_POST['landed_cost_' . $i];
        
            // Inserting the item with the formatted date
            $insert_item_query = "INSERT INTO salesProfitItem (PONumber, SalesOrderNumber, ProductName, Quantity, Price, LandedCost) VALUES ('$po_number', '$so_number', '$product_name', '$quantity', '$price', '$landed_cost');";
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