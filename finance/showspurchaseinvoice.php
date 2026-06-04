<?php
// Header access is required

// Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Connection access
require_once('../connection/connection.php');

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $supplier = $_GET['supplier'];

    $query = "SELECT MAX(A1.due_amount) AS due_amount, MIN(A1.id_transaction) AS id_transaction,
                 SUM(COALESCE(A1.paid_amount, 0)) AS paid_amount, A2.supplier,
                 A1.invoice_number, A2.invoiceDate, A3.supplier_name, A5.currency_name,
                 MAX(A6.PPNPercentage) AS PPNPercentage,
                 MAX(A1.paymentdate) AS last_payment_date,
                 (SELECT fi2.insert_by FROM financeItem fi2
                  WHERE fi2.invoice_number = A1.invoice_number AND fi2.paymentdate IS NOT NULL
                  ORDER BY fi2.paymentdate DESC, fi2.insert_dt DESC LIMIT 1) AS paid_by
    FROM financeItem A1
    LEFT JOIN purchaseInvoice A2 ON A1.invoice_number = A2.invoiceNumber
    LEFT JOIN supplier A3 ON A2.supplier = A3.supplier_id
    LEFT JOIN purchaseOrder A4 ON A2.PONumber = A4.PONumber
    LEFT JOIN currency A5 ON A4.POCurrency = A5.currency_id
    LEFT JOIN salesPPNType A6 ON A4.POPPN = A6.PPNType_id
    WHERE A2.supplier IS NOT NULL AND A2.supplier = '$supplier'
    GROUP BY A1.invoice_number, A2.supplier, A2.invoiceDate, A3.supplier_name, A5.currency_name
    ORDER BY A2.invoiceDate DESC;";


    $result = mysqli_query($connect, $query);

    $array = array();
    while($row = mysqli_fetch_array($result)){
        array_push(
            $array,
            array(
                'due_amount'        => $row['due_amount'],
                'id_transaction'    => $row['id_transaction'],
                'paid_amount'       => $row['paid_amount'],
                'supplier'          => $row['supplier'],
                'invoice_number'    => $row['invoice_number'],
                'invoiceDate'       => $row['invoiceDate'],
                'supplier_name'     => $row['supplier_name'],
                'currency_name'     => $row['currency_name'],
                'last_payment_date' => $row['last_payment_date'],
                'paid_by'           => $row['paid_by'],
                'status'            => ((float)$row['due_amount'] <= 0) ? 'LUNAS' : 'BELUM LUNAS'
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