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
    $currentYear = date("Y");

    // Define your queries
    $query_total_targeting = "SELECT target_value FROM targeting WHERE target_year = '$currentYear';";

    $query_total_sales = "SELECT SUM(A2.HargaSatuan * A2.Quantity) as total_sales
    FROM salesOrder A1
    LEFT JOIN salesOrderItem A2 ON A1.SONumber = A2.salesOrderNumber
    WHERE YEAR(A1.SODate) = '$currentYear'";

    $query_total_purchase = "SELECT COUNT(PONumber) as total_purchase
    FROM purchaseOrder
    WHERE YEAR(PODate) = '$currentYear'";

    $query_total_invoice = "SELECT COUNT(*) as total_invoice
    FROM purchaseOrder A1
    WHERE A1.POStatus = 'e4376c01-1438-11ef-9';";

    $query_sales_chart = "SELECT
        MONTH(m) AS month,
        COALESCE(SUM(A2.HargaSatuan * A2.Quantity), 0) AS total_sales
    FROM (
        SELECT '$currentYear-01-01' AS m UNION ALL
        SELECT '$currentYear-02-01' UNION ALL
        SELECT '$currentYear-03-01' UNION ALL
        SELECT '$currentYear-04-01' UNION ALL
        SELECT '$currentYear-05-01' UNION ALL
        SELECT '$currentYear-06-01' UNION ALL
        SELECT '$currentYear-07-01' UNION ALL
        SELECT '$currentYear-08-01' UNION ALL
        SELECT '$currentYear-09-01' UNION ALL
        SELECT '$currentYear-10-01' UNION ALL
        SELECT '$currentYear-11-01' UNION ALL
        SELECT '$currentYear-12-01'
    ) AS months
    LEFT JOIN salesOrder A1 ON MONTH(A1.SODate) = MONTH(months.m) AND YEAR(A1.SODate) = YEAR(months.m)
    LEFT JOIN salesOrderItem A2 ON A1.SONumber = A2.salesOrderNumber
    GROUP BY months.m
    ORDER BY months.m";

    $query_purchase_chart = "SELECT
        MONTH(m) AS month,
        COALESCE(SUM(CASE WHEN A1.POType = '705da1d4-d157-11ee-8' THEN A2.POQuantity * A2.POUnitPrice * A3.kurs ELSE 0 END), 0) AS total_import,
        COALESCE(SUM(CASE WHEN A1.POType = '741ead94-d157-11ee-8' THEN A2.POQuantity * A2.POUnitPrice ELSE 0 END), 0) AS total_local
    FROM (
        SELECT '$currentYear-01-01' AS m UNION ALL
        SELECT '$currentYear-02-01' UNION ALL
        SELECT '$currentYear-03-01' UNION ALL
        SELECT '$currentYear-04-01' UNION ALL
        SELECT '$currentYear-05-01' UNION ALL
        SELECT '$currentYear-06-01' UNION ALL
        SELECT '$currentYear-07-01' UNION ALL
        SELECT '$currentYear-08-01' UNION ALL
        SELECT '$currentYear-09-01' UNION ALL
        SELECT '$currentYear-10-01' UNION ALL
        SELECT '$currentYear-11-01' UNION ALL
        SELECT '$currentYear-12-01'
    ) AS months
    LEFT JOIN purchaseOrder A1 ON MONTH(A1.PODate) = MONTH(months.m) AND YEAR(A1.PODate) = YEAR(months.m)
    LEFT JOIN purchaseOrderItem A2 ON A1.PONumber = A2.PONumber
    LEFT JOIN purchaseInvoice A3 ON A1.PONumber = A3.PONumber
    GROUP BY months.m
    ORDER BY months.m;";
    
    $query_total_outstand_supplier = "SELECT SUM(A1.due_amount) as total_outstand_supplier
    FROM financeItem A1
    LEFT JOIN purchaseInvoice A2 ON A1.invoice_number = A2.invoiceNumber
    LEFT JOIN supplier A3 ON A2.supplier = A3.supplier_id
    WHERE A2.supplier IS NOT NULL
    ORDER BY A1.insert_dt DESC;";
    
    $query_order_count_by_country = "SELECT A2.origin_name, COUNT(A1.PONumber) AS OrderCount
    FROM purchaseOrder A1
    LEFT JOIN origin A2 ON A1.POOrigin = A2.origin_id
    GROUP BY A2.origin_name;";
    
    $query_purchase_top_products = "SELECT 
        p.productName, 
        SUM(poi.POQuantity * poi.POUnitPrice) AS total_purchase 
    FROM 
        purchaseOrderItem poi 
        LEFT JOIN product p ON poi.POProductName = p.productName 
    GROUP BY 
        p.productName 
    ORDER BY 
        total_purchase DESC 
    LIMIT 10;";
    
    $query_total_outstand_customer = "SELECT SUM(A1.due_amount) as total_outstand_customer
    FROM financeItem A1
    LEFT JOIN salesInvoice A2 ON A1.invoice_number = A2.invoiceNumber
    LEFT JOIN customer A3 ON A2.customerID = A3.company_id
    WHERE A2.customerID IS NOT NULL AND A1.due_amount != '0'
    ORDER BY A1.insert_dt DESC;";
    
    $query_sales_outstand_customers = "SELECT A1.due_amount, A1.id_transaction, A1.paid_amount, A2.customerID, A1.invoice_number, A2.invoiceDate, A3.company_name
        FROM financeItem A1
        LEFT JOIN salesInvoice A2 ON A1.invoice_number = A2.invoiceNumber
        LEFT JOIN customer A3 ON A2.customerID = A3.company_id
        WHERE A2.customerID IS NOT NULL AND A1.due_amount != '0'
        ORDER BY A1.insert_dt DESC;";
        
    $query_top_sales_products = "
    SELECT 
        p.productName, 
        SUM(soi.Quantity * soi.HargaSatuan) AS total_sales 
    FROM 
        salesOrderItem soi 
        LEFT JOIN product p ON soi.ProductName = p.productName 
    GROUP BY 
        p.productName 
    ORDER BY 
        total_sales DESC 
    LIMIT 10;
";

    // Execute each query and store the results
    $result_total_target = mysqli_query($connect, $query_total_targeting);
    $result_total_sales = mysqli_query($connect, $query_total_sales);
    $result_total_purchase = mysqli_query($connect, $query_total_purchase);
    $result_total_invoice = mysqli_query($connect, $query_total_invoice);
    $result_sales_chart = mysqli_query($connect, $query_sales_chart);
    $result_purchase_chart = mysqli_query($connect, $query_purchase_chart);
    $result_total_outstand_supplier = mysqli_query($connect, $query_total_outstand_supplier);
    $result_order_count_by_country = mysqli_query($connect, $query_order_count_by_country);
    $result_purchase_top_products = mysqli_query($connect, $query_purchase_top_products);
    $result_total_outstand_customer = mysqli_query($connect, $query_total_outstand_customer);
    $result_sales_outstand_customers = mysqli_query($connect, $query_sales_outstand_customers);
    $result_top_sales_products = mysqli_query($connect, $query_top_sales_products);


    // Initialize array to store results
    $data = array(
        'total_target' => 0,
        'total_sales' => 0,
        'total_purchase' => 0,
        'total_invoice' => 0,
        'total_outstand_supplier' => 0,
        'total_outstand_customer' => 0,
        'sales_chart' => array(),
        'purchase_chart' => array(),
        'order_count_by_country' => array(),
        'top_purchase_products' => array(),
        'sales_outstand_customers' => array(),
        'top_sales_products' => array()
    );

    // Fetch and store each result
    if ($result_total_target) {
        $row = mysqli_fetch_assoc($result_total_target);
        $data['total_target'] = $row['target_value'];
    }
    if ($result_total_sales) {
        $row = mysqli_fetch_assoc($result_total_sales);
        $data['total_sales'] = $row['total_sales'];
    }
    if ($result_total_purchase) {
        $row = mysqli_fetch_assoc($result_total_purchase);
        $data['total_purchase'] = $row['total_purchase'];
    }
    if ($result_total_invoice) {
        $row = mysqli_fetch_assoc($result_total_invoice);
        $data['total_invoice'] = $row['total_invoice'];
    }
    if ($result_sales_chart) {
        while ($row = mysqli_fetch_assoc($result_sales_chart)) {
            $data['sales_chart'][] = array(
                'month' => $row['month'],
                'total_sales' => $row['total_sales']
            );
        }
    }
    if ($result_purchase_chart) {
        while ($row = mysqli_fetch_assoc($result_purchase_chart)) {
            $data['purchase_chart'][] = array(
                'month' => $row['month'],
                'total_import' => $row['total_import'],
                'total_local' => $row['total_local']
            );
        }
    }
    if ($result_total_outstand_supplier) {
        $row = mysqli_fetch_assoc($result_total_outstand_supplier);
        $data['total_outstand_supplier'] = $row['total_outstand_supplier'];
    }
    if ($result_order_count_by_country) {
        while ($row = mysqli_fetch_assoc($result_order_count_by_country)) {
            $data['order_count_by_country'][] = array(
                'country' => $row['origin_name'],
                'order_count' => $row['OrderCount']
            );
        }
    }
    if ($result_purchase_top_products) {
        while ($row = mysqli_fetch_assoc($result_purchase_top_products)) {
            $data['top_purchase_products'][] = array(
                'product_name' => $row['productName'],
                'total_purchase' => $row['total_purchase']
            );
        }
    }
    if ($result_total_outstand_customer) {
        $row = mysqli_fetch_assoc($result_total_outstand_customer);
        $data['total_outstand_customer'] = $row['total_outstand_customer'];
    }
    if ($result_sales_outstand_customers) {
        while ($row = mysqli_fetch_assoc($result_sales_outstand_customers)) {
            $data['sales_outstand_customers'][] = array(
                'due_amount' => $row['due_amount'],
                'id_transaction' => $row['id_transaction'],
                'paid_amount' => $row['paid_amount'],
                'customerID' => $row['customerID'],
                'invoice_number' => $row['invoice_number'],
                'invoiceDate' => $row['invoiceDate'],
                'company_name' => $row['company_name']
            );
        }
    }
    if ($result_top_sales_products) {
        while ($row = mysqli_fetch_assoc($result_top_sales_products)) {
            $data['top_sales_products'][] = array(
                'product_name' => $row['productName'],
                'total_sales' => $row['total_sales']
            );
        }
    }

    // Output the results
    echo json_encode(
        array(
            'StatusCode' => 200,
            'Status' => 'Success',
            'Data' => $data
        ),
        JSON_PRETTY_PRINT
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
