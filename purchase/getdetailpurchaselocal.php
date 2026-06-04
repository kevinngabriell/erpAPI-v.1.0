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
    $PONumber = $_GET['PONumber'];

    // First, count the number of records with the same salesOrderNumber
    $count_query = "SELECT COUNT(*) as total FROM purchaseOrderItem WHERE PONumber = '$PONumber'";
    $count_result = mysqli_query($connect, $count_query);
    $count_row = mysqli_fetch_assoc($count_result);
    $total_records = $count_row['total'];

    if($total_records > 0){
        $purchase_query = "SELECT A1.PONumber, A1.PODate, A1.POSupplier, A2.supplier_pic_name, A1.POPayment, A1.POOrigin, A1.POStatus, A1.POType, A1.POShipmentDate, A3.PO_Status_Name, A1.InsertDt, A2.supplier_name, A1.InsertBy, A4.payment_name, A5.PPNPercentage
            FROM purchaseOrder A1
            LEFT JOIN supplier A2 ON A1.POSupplier = A2.supplier_id
            LEFT JOIN purchaseStatus A3 ON A1.POStatus = A3.PO_Status_ID
            LEFT JOIN payment A4 ON A1.POPayment = A4.payment_id
            LEFT JOIN salesPPNType A5 ON A1.POPPN = A5.PPNType_id
            WHERE A1.PONumber = '$PONumber';";

        $purchase_result = mysqli_query($connect, $purchase_query);
        $purchase_array = array();

        while($purchase_row = mysqli_fetch_array($purchase_result)){
            array_push(
                $purchase_array,
                array(
                    'PONumber' => $purchase_row['PONumber'],
                    'PODate' => $purchase_row['PODate'],
                    'POSupplier' => $purchase_row['POSupplier'],
                    'POShipment' => $purchase_row['POShipmentDate'],
                    'POSupplierPIC' => $purchase_row['supplier_pic_name'],
                    'POPayment' => $purchase_row['POPayment'],
                    'POOrigin' => $purchase_row['POOrigin'],
                    'POStatus' => $purchase_row['POStatus'],
                    'POStatusName' => $purchase_row['PO_Status_Name'],
                    'POType' => $purchase_row['POType'],
                    'InsertDt' => $purchase_row['InsertDt'],
                    'supplierName' => $purchase_row['supplier_name'],
                    'InsertBy' => $purchase_row['InsertBy'],
                    'payment_name' => $purchase_row['payment_name'],
                    'PPNPercentage' => $purchase_row['PPNPercentage'],
                )
            );
        }

    } else {

    }

    function fetchItems($connect, $PONumber, $limit, $offset){
        $sales_item_query = "SELECT *
            FROM purchaseOrderItem
            WHERE PONumber = '$PONumber' 
            ORDER BY POProductName 
            LIMIT $limit OFFSET $offset;";

        $sales_item_result = mysqli_query($connect, $sales_item_query);
        $sales_array = array();

        if (mysqli_num_rows($sales_item_result) > 0) {
            while ($sales_item_row = mysqli_fetch_array($sales_item_result)) {
                array_push(
                    $sales_array,
                    array(
                        'POProductName' => $sales_item_row['POProductName'],
                        'POQuantity' => $sales_item_row['POQuantity'],
                        'POPackagingSize' => $sales_item_row['POPackagingSize'],
                        'POUnitPrice' => $sales_item_row['POUnitPrice']
                    )
                );
            }
        } else {
            array_push(
                $sales_array,
                array(
                    'POProductName' => NULL,
                    'POQuantity' => NULL,
                    'POPackagingSize' => NULL,
                    'POUnitPrice' => NULL
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
            "message" => "Error: Invalid method. Only GET requests are allowed."
        )
    );
}
?>