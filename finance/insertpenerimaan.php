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
    $deposit_to = $_POST["deposit_to"];
    $voucher_no = $_POST["voucher_no"];
    $date = $_POST["date"];
    $memo = $_POST["memo"];
    $amount = $_POST["amount"];
    $account_code = $_POST["account_code"];
    $account_amount = $_POST["account_amount"];
    $account_memo = $_POST["account_memo"];
    $username = $_POST["username"];
    $currentDateTime = new DateTime();
    $indonesiaTimeZone = new DateTimeZone('Asia/Jakarta');
    $currentDateTime->setTimezone($indonesiaTimeZone);
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");

    $date_parts = explode(' ', $date);
    $date_string = $date_parts[1] . ' ' . $date_parts[2] . ' ' . $date_parts[3];
    $date_obj = DateTime::createFromFormat('M d Y', $date_string);
    $formatted_date = $date_obj->format("Y-m-d");

    // Generate UUID for id_transaction
    $id_transaction = generate_uuid();

    $insert_query = "INSERT INTO financeTransaction (id_transaction, bank_account, voucher_no, date, memo, amount, accountcode, accountamount, accountmemo, insertby, insertdt, finance_category) 
                        VALUES ('$id_transaction', '$deposit_to', '$voucher_no', '$formatted_date', '$memo', '$amount', '$account_code', '$account_amount', '$account_memo', '$username', '$currentDateTimeString', '174c61e8-226d-11ef-a')";

    $insert_history = "INSERT INTO financeLog (id_transaction, action, actionBy, actionDt) VALUES ('$id_transaction', 'Penerimaan telah berhasil dimasukkan kedalam sistem', '$username', '$currentDateTimeString')";

    if (mysqli_query($connect, $insert_query) && mysqli_query($connect, $insert_history)) {
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
