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
require_once('../connection/connection.php');

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $purchase_query = "SELECT 
    PONumber,
    PODate,
    supplier_name,
    shipment_name,
    payment_name,
    PO_Status_Name,
    PO_Type_Name,
    MAX(CASE WHEN ProductName = 1 THEN POProductName END) AS ProductName1,
    MAX(CASE WHEN UnitPrice = 1 THEN POUnitPrice END) AS UnitPrice1,
    MAX(CASE WHEN Quantity = 1 THEN POQuantity END) AS Quantity1,
    MAX(CASE WHEN PackagingSize = 1 THEN POPackagingSize END) AS PackagingSize1,
    MAX(CASE WHEN ProductName = 2 THEN POProductName END) AS ProductName2,
    MAX(CASE WHEN UnitPrice = 2 THEN POUnitPrice END) AS UnitPrice2,
    MAX(CASE WHEN Quantity = 2 THEN POQuantity END) AS Quantity2,
    MAX(CASE WHEN PackagingSize = 2 THEN POPackagingSize END) AS PackagingSize2,
    MAX(CASE WHEN ProductName = 3 THEN POProductName END) AS ProductName3,
    MAX(CASE WHEN UnitPrice = 3 THEN POUnitPrice END) AS UnitPrice3,
    MAX(CASE WHEN Quantity = 3 THEN POQuantity END) AS Quantity3,
    MAX(CASE WHEN PackagingSize = 3 THEN POPackagingSize END) AS PackagingSize3,
    MAX(CASE WHEN ProductName = 4 THEN POProductName END) AS ProductName4,
    MAX(CASE WHEN UnitPrice = 4 THEN POUnitPrice END) AS UnitPrice4,
    MAX(CASE WHEN Quantity = 4 THEN POQuantity END) AS Quantity4,
    MAX(CASE WHEN PackagingSize = 4 THEN POPackagingSize END) AS PackagingSize4,
    MAX(CASE WHEN ProductName = 5 THEN POProductName END) AS ProductName5,
    MAX(CASE WHEN UnitPrice = 5 THEN POUnitPrice END) AS UnitPrice5,
    MAX(CASE WHEN Quantity = 5 THEN POQuantity END) AS Quantity5,
    MAX(CASE WHEN PackagingSize = 5 THEN POPackagingSize END) AS PackagingSize5,
    InsertBy
FROM (
    SELECT
        A1.PONumber,
    	A1.PODate,
        A2.POProductName,
    	A2.POUnitPrice,
    	A2.POQuantity,
    	A2.POPackagingSize,
    	A3.supplier_name,
    	A4.shipment_name,
    	A5.payment_name,
    	A6.PO_Status_Name, 
    	A7.PO_Type_Name, 
    	A1.InsertBy,
        ROW_NUMBER() OVER (PARTITION BY A1.PONumber ORDER BY A2.POProductName) AS ProductName,
        ROW_NUMBER() OVER (PARTITION BY A1.PONumber ORDER BY A2.POUnitPrice) AS UnitPrice,
     	ROW_NUMBER() OVER (PARTITION BY A1.PONumber ORDER BY A2.POQuantity) AS Quantity,
    	ROW_NUMBER() OVER (PARTITION BY A1.PONumber ORDER BY A2.POPackagingSize) AS PackagingSize
    FROM purchaseOrder A1
    LEFT JOIN purchaseOrderItem A2 ON A1.PONumber = A2.PONumber
    LEFT JOIN supplier A3 ON A1.POSupplier = A3.supplier_id
    LEFT JOIN shipment A4 ON A1.POShipment = A4.shipment_id
    LEFT JOIN payment A5 ON A1.POPayment = A5.payment_id
    LEFT JOIN purchaseStatus A6 ON A1.POStatus = A6.PO_Status_ID
    LEFT JOIN purchaseType A7 ON A1.POType = A7.PO_Type_ID
    WHERE A1.POType = '705da1d4-d157-11ee-8'
) AS PivotData
GROUP BY PONumber
ORDER BY PONumber DESC LIMIT 4;";
    $purchase_result = mysqli_query($connect, $purchase_query);

    $purchase_array = array();
    while($purchase_row = mysqli_fetch_array($purchase_result)){
        array_push(
            $purchase_array,
            array(
                'PONumber' => $purchase_row['PONumber'],
                'PODate' => $purchase_row['PODate'],
                'supplier_name' => $purchase_row['supplier_name'],
                'shipment_name' => $purchase_row['shipment_name'],
                'payment_name' => $purchase_row['payment_name'],
                'PO_Status_Name' => $purchase_row['PO_Status_Name'],
                'PO_Type_Name' => $purchase_row['PO_Type_Name'],
                'InsertBy' => $purchase_row['InsertBy'],
                'ProductName1' => $purchase_row['ProductName1']
            )
        );
    }

    if($purchase_array){
        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Data' => $purchase_array
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

?>