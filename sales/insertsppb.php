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
    $sppb_number = $_POST['sppb_number'];
    $sppb_date = $_POST['sppb_date'];
    $customer_id = $_POST['customer_id'];
    $insert_by = $decoded->sub;
    $product_length = $_POST['product_length'];
    $sales_order_status = '76b86e17-1efc-11ef-a';
    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");
    $action = "Draft SPPB telah berhasil diinput dan menunggu persetujuan";

    $date_parts_sppbdate = explode(' ', $sppb_date);
    $date_string_sppbdate = $date_parts_sppbdate[1] . ' ' . $date_parts_sppbdate[2] . ' ' . $date_parts_sppbdate[3];
    $sppb_date_obj = DateTime::createFromFormat('M d Y', $date_string_sppbdate);
    $formatted_sppb_date = $sppb_date_obj->format("Y-m-d");

    $insert_sppb_query = "INSERT INTO salesSPPB (SPPBNumber, SPPBDate, SPPBCustomer, InsertBy, InsertDt) VALUES ('$sppb_number','$formatted_sppb_date', '$customer_id', '$insert_by', '$currentDateTimeString');";
    $insert_sales_order_history_query = "INSERT INTO salesOrderHistory (SONumber, Action, ActionBy, ActionDt) VALUES ('$sppb_number','$action', '$insert_by', '$currentDateTimeString')";
    $update_sales_status = "UPDATE salesOrder SET SOStatus = '76b86e17-1efc-11ef-a' WHERE SONumber = '$sppb_number'";

    if(mysqli_query($connect, $insert_sppb_query) && mysqli_query($connect, $insert_sales_order_history_query) && mysqli_query($connect, $update_sales_status) ){
        
        for ($i = 1; $i <= $product_length; $i++) {
            $sppb_po_number = $_POST['SPPB_PO_' . $i];
            $sppb_send_date = $_POST['sppb_send_date_' . $i];
            $sppb_send_to = $_POST['sppb_send_to_' . $i];
            $sppb_product_name = $_POST['sppb_product_name_' . $i];
            $sppb_quantity = $_POST['sppb_quantity_' . $i];
            $sppb_measure = $_POST['sppb_measure_' . $i];
            $sppb_description = $_POST['sppb_desc_' . $i];
        
            // Parsing and formatting the sppb_send_date
            $date_parts = explode(' ', $sppb_send_date);
            $date_string = $date_parts[1] . ' ' . $date_parts[2] . ' ' . $date_parts[3];
            $sppb_send_date_obj = DateTime::createFromFormat('M d Y', $date_string);
            $formatted_send_date = $sppb_send_date_obj->format("Y-m-d");
        
            // Inserting the item with the formatted date
            $insert_item_query = "INSERT INTO salesSPPBItem (SPPBNumber, PONumber, SendTo, SendDate, ProductName, Quantity, Measure, Description) VALUES ('$sppb_number', '$sppb_po_number', '$sppb_send_to', '$formatted_send_date', '$sppb_product_name', '$sppb_quantity', '$sppb_measure', '$sppb_description');";
            if(mysqli_query($connect, $insert_item_query)){
                http_response_code(200);
                echo json_encode(
                    array(
                        "StatusCode" => 200,
                        'Status' => 'Success',
                        "message" => "Data has been successfully inserted" 
                    )
                );
            } else {
                http_response_code(500);
                echo json_encode(
                    array(
                        "StatusCode" => 500,
                        'Status' => 'Error',
                        "message" => "Error: Unable to insert data to purchaseOrder table - " . mysqli_error($connect)
                    )
                );
            };
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