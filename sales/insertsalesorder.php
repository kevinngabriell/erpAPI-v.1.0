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

function send_error_response($code, $message) {
    http_response_code($code);
    echo json_encode(
        array(
            "StatusCode" => $code,
            'Status' => 'Error',
            "message" => $message
        )
    );
    exit();
}

//Checking call API method
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $decoded = verifyToken();
    $sales_order_number = $_POST['sales_order_number'] ?? null;
    $sales_order_date = $_POST['sales_order_date'] ?? null;
    $sales_order_ppn = $_POST['sales_order_ppn'] ?? null;
    $sales_order_customer = $_POST['sales_order_customer'] ?? null;
    $sales_order_send_to = $_POST['sales_order_send_to'] ?? null;
    $sales_order_send_date = $_POST['sales_order_send_date'] ?? null;
    $insert_by = $decoded->sub;
    $product_length = $_POST['product_length'] ?? null;

    if (!$sales_order_number || !$sales_order_date || !$sales_order_ppn || !$sales_order_customer || !$sales_order_send_to || !$sales_order_send_date || !$insert_by || !$product_length) {
        send_error_response(400, "Missing required fields");
    }

    $sales_order_status = '6d352c3a-1efc-11ef-a';
    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");
    $action = "Draft Sales Order telah berhasil diinput dan menunggu persetujuan";

    try {
        $sales_order_date_obj = DateTime::createFromFormat('d/m/Y', $sales_order_date);
        $formatted_sales_order_date = $sales_order_date_obj ? $sales_order_date_obj->format("Y-m-d") : null;

        $sales_sentto_date_obj = DateTime::createFromFormat('d/m/Y', $sales_order_send_to);
        $formatted_send_to_date = $sales_sentto_date_obj ? $sales_sentto_date_obj->format("Y-m-d") : null;

        $sales_sent_date_obj = DateTime::createFromFormat('d/m/Y', $sales_order_send_date);
        $formatted_send_date = $sales_sent_date_obj ? $sales_sent_date_obj->format("Y-m-d") : null;

        if (!$formatted_sales_order_date || !$formatted_send_to_date || !$formatted_send_date) {
            throw new Exception("Invalid date format");
        }
    } catch (Exception $e) {
        send_error_response(500, "Error: Date formatting issue - " . $e->getMessage());
    }

    $insert_sales_order_query = "INSERT INTO salesOrder (SONumber, SODate, SOPPN, SOCustomer, SOSendTo, SoSendDate, SOStatus, InsertBy , InsertDt) VALUES ('$sales_order_number','$formatted_sales_order_date', '$sales_order_ppn', '$sales_order_customer', '$formatted_send_to_date', '$formatted_send_date', '$sales_order_status','$insert_by', '$currentDateTimeString');";
    $insert_sales_order_history_query = "INSERT INTO salesOrderHistory (SONumber, Action, ActionBy, ActionDt) VALUES ('$sales_order_number','$action', '$insert_by', '$currentDateTimeString')";

    if(mysqli_query($connect, $insert_sales_order_query) && mysqli_query($connect, $insert_sales_order_history_query)){
        for ($i = 1; $i <= $product_length; $i++) {
            $sales_order_po_number = $_POST['sales_order_PO_' . $i] ?? null;
            $sales_order_product_name = $_POST['sales_order_product_name_' . $i] ?? null;
            $sales_order_product_quantity = $_POST['sales_order_product_quantity_' . $i] ?? null;
            $sales_order_satuan = $_POST['sales_order_satuan_' . $i] ?? null;
            $sales_order_matauang = $_POST['sales_order_matauang_' . $i] ?? null;
            $sales_order_hargasatuan = $_POST['sales_order_hargasatuan_' . $i] ?? null;
            $sales_order_kurs = $_POST['sales_order_kurs_' . $i] ?? null;
            
            $insert_item_query = "INSERT INTO salesOrderItem (salesOrderNumber, purchaseOrderNumber, ProductName, Quantity, Satuan, MataUang, HargaSatuan, Kurs) VALUES ('$sales_order_number', '$sales_order_po_number', '$sales_order_product_name','$sales_order_product_quantity', '$sales_order_satuan', '$sales_order_matauang', '$sales_order_hargasatuan', '$sales_order_kurs');";
            
            if (!mysqli_query($connect, $insert_item_query)) {
                send_error_response(500, "Error: Unable to insert data to salesOrderItem table - " . mysqli_error($connect));
            }
        }
    } else {
        send_error_response(500, "Error: Unable to insert data to salesOrder table - " . mysqli_error($connect));
    }

    echo json_encode(
        array(
            "StatusCode" => 200,
            'Status' => 'Success',
            "message" => "Data inserted successfully"
        )
    );

} else {
    send_error_response(404, "Error: Invalid method. Only POST requests are allowed.");
}

?>
