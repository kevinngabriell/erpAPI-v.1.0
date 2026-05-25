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
if($_SERVER['REQUEST_METHOD'] === 'GET'){
    $id_transaction = $_GET['id_transaction'];

    $query = "SELECT A1.bank_account, A1.voucher_no, A1.date, A1.memo, A1.amount, A1.accountcode, A2.account_name_alias, A1.accountamount, A1.accountmemo, A3.bank_name
        FROM financeTransaction A1
        LEFT JOIN account_code A2 ON A1.accountcode = A2.code
        LEFT JOIN bank_account A3 ON A1.bank_account = A3.bank_number
        WHERE A1.id_transaction = '$id_transaction';";

        $result = mysqli_query($connect, $query);

        $array = array();
        while($row = mysqli_fetch_array($result)){
            array_push(
                $array,
                array(
                    'bank_account' => $row['bank_account'],
                    'voucher_no' => $row['voucher_no'],
                    'date' => $row['date'],
                    'memo' => $row['memo'],
                    'amount' => $row['amount'],
                    'accountcode' => $row['accountcode'],
                    'account_name_alias' => $row['account_name_alias'],
                    'accountamount' => $row['accountamount'],
                    'accountmemo' => $row['accountmemo'],
                    'bank_name' => $row['bank_name']
                )
            );
        }

        if($array){
            echo json_encode(
                array(
                    'StatusCode' => 200,
                    'Status' => 'Success',
                    'Data' => $array
                )
            );
        } else {
            http_response_code(400);
            echo json_encode(
                array(
                    'StatusCode' => 400,
                    'Status' => 'Error Bad Request, Result not found !'
                )
            );
        }

} else {
    http_response_code(404);
    echo json_encode(
        array(
            "StatusCode" => 404,
            'Status' => 'Error',
            "message" => "Error: Invalid method. Only GET requests are allowed."
        )
    );
} 