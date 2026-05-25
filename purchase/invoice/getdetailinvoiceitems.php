<?php
// Header access is required
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Connection access
require_once('../../connection/connection.php');

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $PONumber = $_GET['PONumber'];

    // First, count the number of records with the same salesOrderNumber
    $count_query = "SELECT COUNT(*) as total FROM PurchaseInvoiceItem WHERE PONumber = '$PONumber'";
    $count_result = mysqli_query($connect, $count_query);
    $count_row = mysqli_fetch_assoc($count_result);
    $total_records = $count_row['total'];

    if($total_records > 0){
        $purchase_query = "SELECT A2.supplier_name, A1.PONumber, A1.invoiceNumber, A1.invoiceDate, A1.shipDate, A1.kurs, A1.term, A1.InsertBy, A1.InsertDt, A4.PO_Status_Name, A5.currency_name, A3.POType, A6.PPNPercentage
    FROM purchaseInvoice A1
    LEFT JOIN supplier A2 ON A1.supplier = A2.supplier_id
    LEFT JOIN purchaseOrder A3 ON A3.PONumber = A1.PONumber
    LEFT JOIN purchaseStatus A4 ON A3.POStatus = A4.PO_Status_ID
    LEFT JOIN currency A5 ON A3.POCurrency = A5.currency_id
    LEFT JOIN salesPPNType A6 ON A3.POPPN = A6.PPNType_id
    WHERE A1.PONumber = '$PONumber';";

        $purchase_result = mysqli_query($connect, $purchase_query);
        $purchase_array = array();

        while($purchase_row = mysqli_fetch_array($purchase_result)){
            array_push(
                $purchase_array,
                array(
                    'supplier_name' => $purchase_row['supplier_name'],
                    'PONumber' => $purchase_row['PONumber'],
                    'invoiceNumber' => $purchase_row['invoiceNumber'],
                    'invoiceDate' => $purchase_row['invoiceDate'],
                    'shipDate' => $purchase_row['shipDate'],
                    'kurs' => $purchase_row['kurs'],
                    'term' => $purchase_row['term'],
                    'InsertBy' => $purchase_row['InsertBy'],
                    'InsertDt' => $purchase_row['InsertDt'],
                    'PO_Status_Name' => $purchase_row['PO_Status_Name'],
                    'currency_name' => $purchase_row['currency_name'],
                    'POType' => $purchase_row['POType'],
                    'PPNPercentage' => $purchase_row['PPNPercentage'],
                )
            );
        }
    } else {

    }

    function fetchItems($connect, $PONumber, $limit, $offset){
        $sales_item_query = "SELECT * 
            FROM PurchaseInvoiceItem
            WHERE PONumber = '$PONumber' 
            ORDER BY ProductName 
            LIMIT $limit OFFSET $offset;";

        $sales_item_result = mysqli_query($connect, $sales_item_query);
        $sales_array = array();

        if (mysqli_num_rows($sales_item_result) > 0) {
            while ($sales_item_row = mysqli_fetch_array($sales_item_result)) {
                array_push(
                    $sales_array,
                    array(
                        'ProductName' => $sales_item_row['ProductName'],
                        'Quantity' => $sales_item_row['Quantity'],
                        'PackagingSize' => $sales_item_row['PackagingSize'],
                        'UnitPrice' => $sales_item_row['UnitPrice']
                    )
                );
            }
        } else {
            array_push(
                $sales_array,
                array(
                    'ProductName' => NULL,
                    'Quantity' => NULL,
                    'PackagingSize' => NULL,
                    'UnitPrice' => NULL
                )
            );
        }

        return $sales_array;
    }

    // Fetch items
    $sales_items = fetchItems($connect, $PONumber, $total_records, 0);

    echo json_encode(
        array(
            'StatusCode' => 200,
            'Status' => 'Success',
            'TotalRecords' => $total_records,
            'Data' => [
                'Details' => $purchase_array,
                'Items' => $sales_items
            ]
        )
    );

} else {
    http_response_code(404);
    echo json_encode(
        array(
            "StatusCode" => 404,
            'Status' => 'Error',
            "message" => "Error: API not found"
        )
    );
}