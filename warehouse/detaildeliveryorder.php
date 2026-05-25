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
    $SONumber = $_GET['SONumber'];

    // First, count the number of records with the same salesOrderNumber
    $count_query = "SELECT COUNT(*) as total FROM salesDeliveryItem WHERE DeliveryOrder = '$SONumber'";
    $count_result = mysqli_query($connect, $count_query);
    $count_row = mysqli_fetch_assoc($count_result);
    $total_records = $count_row['total'];

    if ($total_records > 0) {
        $sales_query = "SELECT 
                            A4.productName, 
                            A4.DeliveryOrder, 
                            A3.company_name, 
                            A1.ShipTo, 
                            A4.productQTY 
                        FROM salesDelivery A1
                        LEFT JOIN salesOrder A2 ON A1.DONumber = A2.SONumber
                        LEFT JOIN customer A3 ON A1.customerID = A3.company_id
                        LEFT JOIN salesDeliveryItem A4 ON A1.DONumber = A4.DeliveryOrder
                        WHERE A1.DONumber = '$SONumber';";

        $sales_result = mysqli_query($connect, $sales_query);
        $sales_array = array();
        while($sales_row = mysqli_fetch_array($sales_result)){
            array_push(
                $sales_array,
                array(
                    'productName' => $sales_row['productName'],
                    'DeliveryOrder' => $sales_row['DeliveryOrder'],
                    'company_name' => $sales_row['company_name'],
                    'ShipTo' => $sales_row['ShipTo'],
                    'productQTY' => $sales_row['productQTY']
                )
            );
        }

        // Preparing the result in the desired format
        $result = array(
            'id' => $SONumber,
            'customer' => array(
                'name' => $sales_array[0]['company_name'],
                'address' => $sales_array[0]['ShipTo']
            ),
            'salesDetails' => array()
        );

        foreach($sales_array as $value) {
            array_push(
                $result['salesDetails'],
                array(
                    'productName' => $value['productName'],
                    'quantity' => $value['productQTY']
                )
            );
        }

        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Data' => $result
            )
        );
    } else {
        http_response_code(404);
        echo json_encode(
            array(
                "StatusCode" => 404,
                'Status' => 'Error',
                "message" => "No records found for SONumber: $SONumber."
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
