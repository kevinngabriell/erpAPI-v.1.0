<?php
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// Allowed origins
$allowed_origins = array(
    'http://localhost:3000',
    'http://localhost/',
    'https://sslclever.jagoanhosting.com'
);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if (in_array($origin, $allowed_origins)) {
} else {
}

// Start output buffering
ob_start();

// Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Connection access
require_once('../connection/connection.php');

// Manually set current datetime for testing purposes
$currentDateTime = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
$currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");

// Calculate beginning and end of the week
$beginningOfWeek = date('Y-m-d', strtotime('last Monday', strtotime($currentDateTimeString)));
$endOfWeek = date('Y-m-d', strtotime('next Sunday', strtotime($beginningOfWeek)));

// Query to fetch all products with stock
$all_products_query = "
    SELECT w.product AS kodeProduk, p.productName AS namaProduk, 
           COALESCE(SUM(w.endbalance), 0) AS currentStock
    FROM warehouse w
    LEFT JOIN product p ON w.product = p.skuID
    GROUP BY w.product, p.productName
";
$all_products_result = mysqli_query($connect, $all_products_query);

if (!$all_products_result) {
    http_response_code(500);
    echo json_encode(array(
        "StatusCode" => 500,
        "Status" => "Error",
        "message" => "Error: Failed to fetch products with stock." . mysqli_error($connect)
    ));
    exit;
}

// Fetch all products into an associative array
$all_products = array();
while ($row = mysqli_fetch_assoc($all_products_result)) {
    $all_products[$row['kodeProduk']] = array(
        "namaProduk" => $row['namaProduk'],
        "currentStock" => $row['currentStock']
    );
}

// Query to fetch beginning balance (BB) for each product
$beginning_balance_query = "
    SELECT 
        w.product AS kodeProduk, 
        p.productName AS namaProduk,
        COALESCE(SUM(w.beginningbalance), 0) + 
        COALESCE(SUM(CASE WHEN wt.transactionType = '452c5015-e80f-4e8a-8' THEN wt.quantity * u.conversionFactor ELSE 0 END), 0) - 
        COALESCE(SUM(CASE WHEN wt.transactionType = '9cafab5b-d975-41e8-8' THEN wt.quantity * u.conversionFactor ELSE 0 END), 0) AS BB
    FROM 
        warehouse w
    LEFT JOIN 
        product p ON w.product = p.skuID
    LEFT JOIN 
        warehouseTransaction wt ON w.product = wt.product AND wt.transactionDate < '$beginningOfWeek'
    LEFT JOIN 
        unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
    WHERE 
        wt.transactionDate < '$beginningOfWeek'
    GROUP BY 
        w.product, p.productName
";
$beginning_balance_result = mysqli_query($connect, $beginning_balance_query);

if (!$beginning_balance_result) {
    http_response_code(500);
    echo json_encode(array(
        "StatusCode" => 500,
        "Status" => "Error",
        "message" => "Error: Failed to fetch beginning balance." . mysqli_error($connect)
    ));
    exit;
}

// Fetch beginning balances into an associative array
$beginning_balances = array();
while ($row = mysqli_fetch_assoc($beginning_balance_result)) {
    $beginning_balances[$row['kodeProduk']] = $row['BB'];
}

// Query to fetch barang masuk (In) for each kodeProduk
$barang_masuk_query = "
    SELECT product AS kodeProduk, SUM(quantity * u.conversionFactor) AS `In`
    FROM warehouseTransaction wt
    LEFT JOIN unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
    WHERE transactionType = '452c5015-e80f-4e8a-8'
      AND transactionDate BETWEEN '$beginningOfWeek' AND '$endOfWeek'
    GROUP BY product
";
$barang_masuk_result = mysqli_query($connect, $barang_masuk_query);

if (!$barang_masuk_result) {
    http_response_code(500);
    echo json_encode(array(
        "StatusCode" => 500,
        "Status" => "Error",
        "message" => "Error: Failed to fetch barang masuk."
    ));
    exit;
}

// Fetch barang masuk into an associative array
$barang_masuks = array();
while ($row = mysqli_fetch_assoc($barang_masuk_result)) {
    $barang_masuks[$row['kodeProduk']] = $row['In'];
}

// Query to fetch barang keluar (Out) for each kodeProduk
$barang_keluar_query = "
    SELECT product AS kodeProduk, SUM(quantity * u.conversionFactor) AS `Out`
    FROM warehouseTransaction wt
    LEFT JOIN unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
    WHERE transactionType = '9cafab5b-d975-41e8-8'
      AND transactionDate BETWEEN '$beginningOfWeek' AND '$endOfWeek'
    GROUP BY product
";
$barang_keluar_result = mysqli_query($connect, $barang_keluar_query);

if (!$barang_keluar_result) {
    http_response_code(500);
    echo json_encode(array(
        "StatusCode" => 500,
        "Status" => "Error",
        "message" => "Error: Failed to fetch barang keluar."
    ));
    exit;
}

// Fetch barang keluar into an associative array
$barang_keluars = array();
while ($row = mysqli_fetch_assoc($barang_keluar_result)) {
    $barang_keluars[$row['kodeProduk']] = $row['Out'];
}

// Combine results into a weekly stock report
$weekly_stock_report = array();
foreach ($all_products as $kodeProduk => $details) {
    $namaProduk = $details['namaProduk'];
    $currentStock = $details['currentStock'];

    // Check if BB exists, otherwise default to 0
    $BB = isset($beginning_balances[$kodeProduk]) ? $beginning_balances[$kodeProduk] : 0;

    $In = isset($barang_masuks[$kodeProduk]) ? $barang_masuks[$kodeProduk] : 0;
    $Out = isset($barang_keluars[$kodeProduk]) ? $barang_keluars[$kodeProduk] : 0;

    $daily_transactions = array();

    // Calculate daily transactions
    for ($i = 0; $i < 7; $i++) {
        $date = date('Y-m-d', strtotime("$beginningOfWeek +$i day"));

        // Fetch daily In transactions
        $daily_in_query = "
            SELECT SUM(quantity * u.conversionFactor) AS `In`
            FROM warehouseTransaction wt
            LEFT JOIN unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
            WHERE product = '$kodeProduk'
              AND transactionType = '452c5015-e80f-4e8a-8'
              AND transactionDate = '$date'
        ";
        $daily_in_result = mysqli_query($connect, $daily_in_query);
        $daily_in = mysqli_fetch_assoc($daily_in_result)['In'] ?? 0;

        // Fetch daily Out transactions
        $daily_out_query = "
            SELECT SUM(quantity * u.conversionFactor) AS `Out`
            FROM warehouseTransaction wt
            LEFT JOIN unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
            WHERE product = '$kodeProduk'
              AND transactionType = '9cafab5b-d975-41e8-8'
              AND transactionDate = '$date'
        ";
        $daily_out_result = mysqli_query($connect, $daily_out_query);
        $daily_out = mysqli_fetch_assoc($daily_out_result)['Out'] ?? 0;

        // Format the daily transactions to remove unnecessary decimals
        $daily_in = rtrim(rtrim(number_format($daily_in, 5), '0'), '.');
        $daily_out = rtrim(rtrim(number_format($daily_out, 5), '0'), '.');

        $daily_transactions[] = array(
            "date" => $date,
            "In" => $daily_in,
            "Out" => $daily_out
        );
    }

    // Calculate ending balance correctly
    $ending_balance = $BB + $In - $Out;

    // Format the quantities to remove unnecessary decimals
    $BB = rtrim(rtrim(number_format($BB, 5), '0'), '.');
    $In = rtrim(rtrim(number_format($In, 5), '0'), '.');
    $Out = rtrim(rtrim(number_format($Out, 5), '0'), '.');
    $ending_balance = rtrim(rtrim(number_format($ending_balance, 5), '0'), '.');

    $weekly_stock_report[] = array(
        "kodeProduk" => $kodeProduk,
        "namaProduk" => $namaProduk,
        "BB" => $BB,
        "In" => $In,
        "Out" => $Out,
        "ending_balance" => $ending_balance,
        "daily_transactions" => $daily_transactions
    );
}

// If successful
http_response_code(200);
echo json_encode(array(
    "StatusCode" => 200,
    "Status" => "Success",
    "message" => "Weekly stock report generated successfully.",
    "data" => $weekly_stock_report
));

// Close database connection
mysqli_close($connect);
?>
