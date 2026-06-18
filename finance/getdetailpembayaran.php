<?php
//Header access is required

//Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

//Connection access
require_once('../connection/connection.php');

//Checking call API method
if($_SERVER['REQUEST_METHOD'] === 'GET'){
    $id_transaction = $_GET['id_transaction'];

    $query = "SELECT A1.bank_account, A1.voucher_no, A1.chequeno, A1.date, A1.memo, A1.payee, A1.amount, A1.accountcode, A2.account_code_name_alias, A1.accountamount, A1.accountmemo, A3.bank_name
        FROM financeTransaction A1
        LEFT JOIN account_code A2 ON A1.accountcode  = A2.account_code
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
                    'chequeno' => $row['chequeno'],
                    'date' => $row['date'],
                    'memo' => $row['memo'],
                    'payee' => $row['payee'],
                    'amount' => $row['amount'],
                    'accountcode' => $row['accountcode'],
                    'account_name_alias' => $row['account_code_name_alias'],
                    'accountamount' => $row['accountamount'],
                    'accountmemo' => $row['accountmemo'],
                    'bank_name' => $row['bank_name']
                )
            );
        }

        // Fall back to financeItem (supplier payments) if not found in financeTransaction
        if (empty($array)) {
            $query_fi = "SELECT A1.bank AS bank_account, A1.formno AS voucher_no, A1.chequeno, A1.paymentdate AS date,
                                A1.memo, A1.penerima AS payee, A1.paid_amount AS amount, A1.invoice_number AS accountcode,
                                A2.supplier_name AS account_code_name_alias, A1.paid_amount AS accountamount,
                                A1.invoice_number AS accountmemo, A3.bank_name,
                                A1.invoice_number, A1.due_amount
                         FROM financeItem A1
                         LEFT JOIN supplier A2 ON A1.supplier = A2.supplier_id
                         LEFT JOIN bank_account A3 ON A1.bank = A3.bank_number
                         WHERE A1.id_transaction = '$id_transaction'";

            $result_fi = mysqli_query($connect, $query_fi);
            while ($row = mysqli_fetch_array($result_fi)) {
                array_push(
                    $array,
                    array(
                        'bank_account' => $row['bank_account'],
                        'voucher_no'   => $row['voucher_no'],
                        'chequeno'     => $row['chequeno'],
                        'date'         => $row['date'],
                        'memo'         => $row['memo'],
                        'payee'        => $row['payee'],
                        'amount'       => $row['amount'],
                        'accountcode'  => $row['accountcode'],
                        'account_name_alias' => $row['account_code_name_alias'],
                        'accountamount' => $row['accountamount'],
                        'accountmemo'  => $row['accountmemo'],
                        'bank_name'    => $row['bank_name'],
                        'invoice_number' => $row['invoice_number'],
                        'due_amount'   => $row['due_amount']
                    )
                );
            }
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