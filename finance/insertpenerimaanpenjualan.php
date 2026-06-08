<?php
//Header access is required

//Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

//Connection access
require_once('../connection/connection.php');

//Function to generate UUID
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
    $customer_id = $_POST["customer_id"];
    $payment_date = $_POST["payment_date"];
    $form_no = $_POST["form_no"];
    $bank_number = $_POST["bank_number"];
    $rate = !empty($_POST["rate"]) ? (float)$_POST["rate"] : 1;
    $cheque_no = $_POST["cheque_no"];
    $cheque_date = $_POST["cheque_date"];
    $cheque_amount = $_POST["cheque_amount"];    
    $memo = $_POST["memo"]; 
    $invoice_length = $_POST["invoice_length"];  
    $username = $_POST["username"];
    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");     
    
    $payment_timestamp = strtotime($payment_date);
    if (!$payment_timestamp) {
        http_response_code(400);
        echo json_encode(["StatusCode" => 400, "Status" => "Error", "message" => "Invalid payment_date format: $payment_date"]);
        exit;
    }
    $formatted_payment_date = date("Y-m-d", $payment_timestamp);

    $formatted_cheque_date = null;
    if (!empty($cheque_date)) {
        $cheque_timestamp = strtotime($cheque_date);
        if ($cheque_timestamp) {
            $formatted_cheque_date = date("Y-m-d", $cheque_timestamp);
        }
    }

    for ($i = 1; $i <= $invoice_length; $i++) {
        $invoice_number = $_POST['invoice_number_' . $i];
        $invoice_date = $_POST['invoice_date_' . $i];
        $invoice_amount = $_POST['invoice_amount_' . $i];
        $paymount_amount = $_POST['paymount_amount_' . $i];

        $due = $invoice_amount - $paymount_amount;

        $insert_query = "INSERT INTO financeItem(id_transaction, `invoice_number`, `paid_amount`, `customer`, `paymentdate`, `formno`, `bank`, `rate`, `chequeno`, `chequedate`, `chequeamount`, `memo`, `insert_by`, `insert_dt`) VALUES (UUID() ,'$invoice_number','$paymount_amount','$customer_id','$formatted_payment_date','$form_no','$bank_number','$rate','$cheque_no','$formatted_cheque_date','$cheque_amount','$memo','$username','$currentDateTimeString')";
        $update_query = "UPDATE financeItem A1 SET A1.due_amount = '$due', A1.update_by = '$username', A1.update_dt = '$currentDateTimeString' WHERE A1.invoice_number = '$invoice_number';";
    
        if (mysqli_query($connect, $insert_query) && mysqli_query($connect, $update_query)) {
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
                    "message" => "Error: Unable to update data - " . mysqli_error($connect)
                )
            );
        }
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