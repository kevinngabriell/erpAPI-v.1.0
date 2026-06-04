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
    $SONumber = $_GET['SONumber'];

    // First, count the number of records with the same salesOrderNumber
    $count_query = "SELECT COUNT(*) as total FROM salesOrderItem WHERE salesOrderNumber = '$SONumber'";
    $count_result = mysqli_query($connect, $count_query);
    $count_row = mysqli_fetch_assoc($count_result);
    $total_records = $count_row['total'];

    if ($total_records > 0) {
        $sales_query = "SELECT DISTINCT A1.SONumber, A1.SODate, A2.company_id, A2.company_name, A3.PPNType_name, A2.company_address, A1.SOSendTo, A1.SOSendDate, A1.InsertBy, A1.InsertDt, A4.SO_Status_Name, A3.PPNPercentage, A5.purchaseOrderNumber, A2.company_top
        FROM salesOrder A1
        LEFT JOIN customer A2 ON A1.SOCustomer = A2.company_id
        LEFT JOIN salesPPNType A3 ON A1.SOPPN = A3.PPNType_id
        LEFT JOIN salesStatus A4 ON A1.SOStatus = A4.SO_Status_ID
        LEFT JOIN salesOrderItem A5 ON A1.SONumber = A5.salesOrderNumber
        WHERE A1.SONumber = '$SONumber';";

        $sales_result = mysqli_query($connect, $sales_query);
        $sales_array = array();
        while($sales_row = mysqli_fetch_array($sales_result)){
            array_push(
                $sales_array,
                array(
                    'SONumber' => $sales_row['SONumber'],
                    'purchaseOrderNumber' => $sales_row['purchaseOrderNumber'],
                    'SOStatus' => $sales_row['SO_Status_Name'],
                    'SODate' => $sales_row['SODate'],
                    'company_id' => $sales_row['company_id'],
                    'company_name' => $sales_row['company_name'],
                    'PPNType_name' => $sales_row['PPNType_name'],
                    'PPNPercentage' => $sales_row['PPNPercentage'],
                    'company_address' => $sales_row['company_address'],
                    'SOSendTo' => $sales_row['SOSendTo'],
                    'SOSendDate' => $sales_row['SOSendDate'],
                    'company_top' => $sales_row['company_top'],
                    'InsertBy' => $sales_row['InsertBy'],
                    'InsertDt' => $sales_row['InsertDt']
                )
            );
        }

        // Function to fetch items with a given offset
        function fetchItems($connect, $SONumber, $limit, $offset) {
            $sales_item_query = "SELECT A1.purchaseOrderNumber, A1.ProductName, A1.Quantity, A2.currency_name, A3.uomName, A1.HargaSatuan, A1.Kurs, A3.uomID 
            FROM salesOrderItem A1
            LEFT JOIN currency A2 ON A1.MataUang = A2.currency_id
            LEFT JOIN unitOfMeasure A3 ON A1.Satuan = A3.uomID 
            WHERE salesOrderNumber = '$SONumber' 
            ORDER BY ProductName 
            LIMIT $limit OFFSET $offset;";
            $sales_item_result = mysqli_query($connect, $sales_item_query);
            $sales_array = array();

            if (mysqli_num_rows($sales_item_result) > 0) {
                while ($sales_item_row = mysqli_fetch_array($sales_item_result)) {
                    array_push(
                        $sales_array,
                        array(
                            'purchaseOrderNumber' => $sales_item_row['purchaseOrderNumber'],
                            'ProductName' => $sales_item_row['ProductName'],
                            'Quantity' => $sales_item_row['Quantity'],
                            'currency_name' => $sales_item_row['currency_name'],
                            'uomName' => $sales_item_row['uomName'],
                            'HargaSatuan' => $sales_item_row['HargaSatuan'],
                            'Kurs' => $sales_item_row['Kurs'],
                            'uomID' => $sales_item_row['uomID'],
                        )
                    );
                }
            } else {
                array_push(
                    $sales_array,
                    array(
                        'purchaseOrderNumber' => NULL,
                        'ProductName' => NULL,
                        'Quantity' => NULL,
                        'currency_name' => NULL,
                        'uomName' => NULL,
                        'HargaSatuan' => NULL,
                        'Kurs' => NULL,
                    )
                );
            }

            return $sales_array;
        }

        // Fetch items
        $sales_items = fetchItems($connect, $SONumber, $total_records, 0);

        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'TotalRecords' => $total_records,
                'Data' => [
                    'Details' => $sales_array,
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
