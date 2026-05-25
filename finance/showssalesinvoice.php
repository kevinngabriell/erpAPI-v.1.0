<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../connection/connection.php');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $customer_id = $_GET['customer_id'];

    $query = "SELECT MAX(A1.due_amount) AS due_amount, MIN(A1.id_transaction) AS id_transaction,
                     SUM(COALESCE(A1.paid_amount, 0)) AS paid_amount, A2.customerID,
                     A1.invoice_number, A2.invoiceDate, A3.company_name, MAX(A5.PPNPercentage) AS PPNPercentage,
                     MAX(A1.paymentdate) AS last_payment_date,
                     (SELECT fi2.insert_by FROM financeItem fi2
                      WHERE fi2.invoice_number = A1.invoice_number AND fi2.paymentdate IS NOT NULL
                      ORDER BY fi2.paymentdate DESC, fi2.insert_dt DESC LIMIT 1) AS paid_by
        FROM financeItem A1
        LEFT JOIN salesInvoice A2 ON A1.invoice_number = A2.invoiceNumber
        LEFT JOIN customer A3 ON A2.customerID = A3.company_id
        LEFT JOIN salesOrder A4 ON A2.salesOrder = A4.SONumber
        LEFT JOIN salesPPNType A5 ON A4.SOPPN = A5.PPNType_id
        WHERE A2.customerID IS NOT NULL AND A2.customerID = '$customer_id'
        GROUP BY A1.invoice_number, A2.customerID, A2.invoiceDate, A3.company_name
        ORDER BY A2.invoiceDate DESC;";

    $result = mysqli_query($connect, $query);

    $array = array();
    while($row = mysqli_fetch_array($result)){
        array_push(
            $array,
            array(
                'invoice_number'    => $row['invoice_number'],
                'invoiceDate'       => $row['invoiceDate'],
                'due_amount'        => $row['due_amount'],
                'paid_amount'       => $row['paid_amount'],
                'id_transaction'    => $row['id_transaction'],
                'company_name'      => $row['company_name'],
                'ppn_percentage'    => $row['PPNPercentage'],
                'last_payment_date' => $row['last_payment_date'],
                'paid_by'           => $row['paid_by'],
                'status'            => ((float)$row['due_amount'] <= 0) ? 'LUNAS' : 'BELUM LUNAS',
            )
        );
    }

    if($array){
        echo json_encode(array('StatusCode' => 200, 'Status' => 'Success', 'Data' => $array));
    } else {
        http_response_code(400);
        echo json_encode(array('StatusCode' => 400, 'Status' => 'Error Bad Request, Result not found !'));
    }

} else {
    http_response_code(404);
    echo json_encode(array("StatusCode" => 404, 'Status' => 'Error', "message" => "Error: Invalid method. Only GET requests are allowed."));
}
