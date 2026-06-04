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
    // Query to fetch outstanding amounts for customers
    $query = "
    SELECT 
        A3.company_id AS customerID,
        A3.company_name AS customerName,
        SUM(A1.due_amount) AS totalDueAmount
    FROM 
        financeItem A1
    LEFT JOIN 
        salesInvoice A2 ON A1.invoice_number = A2.invoiceNumber
    LEFT JOIN 
        customer A3 ON A2.customerID = A3.company_id
    WHERE 
        A2.customerID IS NOT NULL
    GROUP BY
        A3.company_id, A3.company_name
    ORDER BY
        MAX(A1.insert_dt) DESC;
    ";

    $result = mysqli_query($connect, $query);

    $customerArray = array();
    $totalCustomerOutstanding = 0;
    while($row = mysqli_fetch_array($result)){
        $totalCustomerOutstanding += $row['totalDueAmount'];
        array_push(
            $customerArray,
            array(
                'customerID' => $row['customerID'],
                'customerName' => $row['customerName'],
                'totalDueAmount' => $row['totalDueAmount']
            )
        );
    }

    // Query to fetch outstanding amounts for suppliers (you need to define this query similarly based on your database structure)
    $supplierQuery = "
    SELECT 
        A3.supplier_id AS supplierID,
        A3.supplier_name AS supplierName,
        SUM(A1.due_amount) AS totalDueAmount
    FROM 
        financeItem A1
    LEFT JOIN 
        purchaseInvoice A2 ON A1.invoice_number = A2.invoiceNumber
    LEFT JOIN 
        supplier A3 ON A2.supplier = A3.supplier_id
    WHERE 
        A2.supplier IS NOT NULL
    GROUP BY
        A3.supplier_id, A3.supplier_name
    ORDER BY
        MAX(A1.insert_dt) DESC;
    ";

    $supplierResult = mysqli_query($connect, $supplierQuery);

    $supplierArray = array();
    $totalSupplierOutstanding = 0;
    while($row = mysqli_fetch_array($supplierResult)){
        $totalSupplierOutstanding += $row['totalDueAmount'];
        array_push(
            $supplierArray,
            array(
                'supplierID' => $row['supplierID'],
                'supplierName' => $row['supplierName'],
                'totalDueAmount' => $row['totalDueAmount']
            )
        );
    }

    // Combine results into a summary report
    $summaryReport = array(
        'customerOutstanding' => $customerArray,
        'totalCustomerOutstanding' => $totalCustomerOutstanding,
        'supplierOutstanding' => $supplierArray,
        'totalSupplierOutstanding' => $totalSupplierOutstanding
    );

    // If successful
    http_response_code(200);
    echo json_encode(array(
        'StatusCode' => 200,
        'Status' => 'Success',
        'Message' => 'Outstanding amounts fetched successfully.',
        'Data' => $summaryReport
    ));
} else {
    http_response_code(404);
    echo json_encode(array(
        'StatusCode' => 404,
        'Status' => 'Error',
        'Message' => 'Error: Invalid method. Only GET requests are allowed.'
    ));
}

// Close database connection
mysqli_close($connect);
?>
