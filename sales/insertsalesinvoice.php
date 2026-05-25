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

function generate_uuid() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

//Checking call API method
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $customer_id = $_POST['customer_id'];
    $invoice_id = $_POST['invoice_id'];
    $sales_order = $_POST['sales_order'];
    $invoice_date = $_POST['invoice_date'];
    $ship_to = $_POST['ship_to'];
    $bill_to = $_POST['bill_to'];
    $insert_by = $_POST['insert_by'];
    $product_length = $_POST['product_length'];
    $total_amount = $_POST['total_amount'];
    $sales_order_status = '7c44858e-1efc-11ef-a';
    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");
    $action = "Draft Sales Invoice telah berhasil diinput dan menunggu persetujuan";

    $insert_sppb_query = "INSERT INTO salesInvoice (customerID, invoiceNumber, salesOrder, invoiceDate, ShipTo, BillTo, InsertBy, InsertDt)
                                VALUES ('$customer_id', '$invoice_id', '$sales_order', '$invoice_date', '$ship_to', '$bill_to' ,'$insert_by', '$currentDateTimeString');";
    $insert_sales_order_history_query = "INSERT INTO salesOrderHistory (SONumber, Action, ActionBy, ActionDt)
    VALUES ('$sales_order','$action', '$insert_by', '$currentDateTimeString')";

    $id_transaction = generate_uuid();

    $insert_finance_query = "INSERT INTO financeItem (id_transaction, invoice_number, paid_amount, due_amount, insert_by, insert_dt)
        VALUES ('$id_transaction', '$invoice_id', '0', '$total_amount', '$insert_by', '$currentDateTimeString')";

    $update_so_status_query = "UPDATE salesOrder SET SOStatus = '$sales_order_status', UpdateBy = '$insert_by', UpdateDt = '$currentDateTimeString' WHERE SONumber = '$sales_order'";

    if(mysqli_query($connect, $insert_sppb_query) && mysqli_query($connect, $insert_sales_order_history_query) && mysqli_query($connect, $insert_finance_query) && mysqli_query($connect, $update_so_status_query)){

        for ($i = 1; $i <= $product_length; $i++) {
            $so_number = $_POST['SO_' . $i];
            $product_name = $_POST['product_name_' . $i];
            $quantity = $_POST['quantity_' . $i];
            $price = $_POST['price_' . $i];
            $tax = $_POST['tax_' . $i];
            $do_number = $_POST['do_number_' . $i];

            $insert_item_query = "INSERT INTO salesInvoiceItem (SONumber, productName, productQuantity, unitPrice, tax, DONumber) VALUES ('$so_number', '$product_name', '$quantity', '$price', '$tax', '$invoice_id');";
            mysqli_query($connect, $insert_item_query);
        }

        http_response_code(200);
        echo json_encode(
            array(
                "StatusCode" => 200,
                'Status' => 'Success',
                "message" => "Success: Data inserted successfully"
            )
        );

    } else {
        http_response_code(500);
        echo json_encode(
            array(
                "StatusCode" => 500,
                'Status' => 'Error',
                "message" => "Error: Unable to insert data to salesInvoice table - " . mysqli_error($connect)
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