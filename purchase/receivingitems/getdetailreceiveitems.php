<?php
// Header access is required

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
    $count_query = "SELECT COUNT(*) as total FROM purchaseReceiveItem WHERE PONumber = '$PONumber'";
    $count_result = mysqli_query($connect, $count_query);
    $count_row = mysqli_fetch_assoc($count_result);
    $total_records = $count_row['total'];

    if($total_records > 0){
        $purchase_query = "SELECT A1.PONumber, A2.supplier_name, A1.ReceivingDate, A3.shipName, A1.InsertDt, A1.InsertBy, A1.ShipDate, A2.supplier_address, A5.PO_Status_Name, A2.supplier_id, A6.term_name, A6.term_id, A7.currency_name, A4.POType, A8.PPNPercentage
     FROM purchaseRecieve A1
     LEFT JOIN supplier A2 ON A1.supplierID = A2.supplier_id
     LEFT JOIN shipVia A3 ON A1.ShipVia = A3.shipID
     LEFT JOIN purchaseOrder A4 ON A1.PONumber = A4.PONumber
 LEFT JOIN purchaseStatus A5 ON A4.POStatus = A5.PO_Status_ID
     LEFT JOIN term A6 ON A4.POTerm = A6.term_id
     LEFT JOIN currency A7 ON A4.POCurrency = A7.currency_id
     LEFT JOIN salesPPNType A8 ON A4.POPPN = A8.PPNType_id
     WHERE A1.PONumber = '$PONumber';";

        $purchase_result = mysqli_query($connect, $purchase_query);
        $purchase_array = array();

        while($purchase_row = mysqli_fetch_array($purchase_result)){
            array_push(
                $purchase_array,
                array(
                    'PONumber' => $purchase_row['PONumber'],
                    'supplier_name' => $purchase_row['supplier_name'],
                    'ReceivingDate' => $purchase_row['ReceivingDate'],
                    'shipName' => $purchase_row['shipName'],
                    'ShipDate' => $purchase_row['ShipDate'],
                    'InsertDt' => $purchase_row['InsertDt'],
                    'InsertBy' => $purchase_row['InsertBy'],
                    'supplier_address' => $purchase_row['supplier_address'],
                    'PO_Status_Name' => $purchase_row['PO_Status_Name'],
                    'supplier_id' => $purchase_row['supplier_id'],
                    'term_name' => $purchase_row['term_name'],
                    'term_id' => $purchase_row['term_id'],
                    'currency_name' => $purchase_row['currency_name'],
                    'POType' => $purchase_row['POType'],
                    'PPNPercentage' => $purchase_row['PPNPercentage']
                )
            );
        }
    } else {

    }

    function fetchItems($connect, $PONumber, $limit, $offset){
        $sales_item_query = "SELECT *
            FROM purchaseReceiveItem
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