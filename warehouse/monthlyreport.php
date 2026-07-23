<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../connection/connection.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["StatusCode" => 405, "Status" => "Method Not Allowed"]);
    exit;
}

$currentDateTime = new DateTime('now', new DateTimeZone('Asia/Jakarta'));

$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)$currentDateTime->format('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)$currentDateTime->format('n');

if ($month < 1 || $month > 12) {
    http_response_code(400);
    echo json_encode(["StatusCode" => 400, "Status" => "Bad Request", "message" => "month must be between 1 and 12"]);
    exit;
}

const TRX_IN  = '452c5015-e80f-4e8a-8';
const TRX_OUT = '9cafab5b-d975-41e8-8';

$beginningOfMonth = sprintf('%04d-%02d-01', $year, $month);
$endOfMonth        = date('Y-m-t', strtotime($beginningOfMonth));
$daysInMonth       = (int)date('t', strtotime($beginningOfMonth));

// All products with current stock
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
    echo json_encode(["StatusCode" => 500, "Status" => "Error", "message" => "Failed to fetch products with stock. " . mysqli_error($connect)]);
    exit;
}

$all_products = array();
while ($row = mysqli_fetch_assoc($all_products_result)) {
    $all_products[$row['kodeProduk']] = array(
        "namaProduk" => $row['namaProduk'],
        "currentStock" => $row['currentStock']
    );
}

// Beginning balance (BB) as of start of month, per product
$beginning_balance_query = "
    SELECT
        w.product AS kodeProduk,
        p.productName AS namaProduk,
        COALESCE(SUM(w.beginningbalance), 0) +
        COALESCE(SUM(CASE WHEN wt.transactionType = '" . TRX_IN . "' THEN wt.quantity * u.conversionFactor ELSE 0 END), 0) -
        COALESCE(SUM(CASE WHEN wt.transactionType = '" . TRX_OUT . "' THEN wt.quantity * u.conversionFactor ELSE 0 END), 0) AS BB
    FROM warehouse w
    LEFT JOIN product p ON w.product = p.skuID
    LEFT JOIN warehouseTransaction wt ON w.product = wt.product AND wt.transactionDate < '$beginningOfMonth'
    LEFT JOIN unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
    WHERE wt.transactionDate < '$beginningOfMonth'
    GROUP BY w.product, p.productName
";
$beginning_balance_result = mysqli_query($connect, $beginning_balance_query);

if (!$beginning_balance_result) {
    http_response_code(500);
    echo json_encode(["StatusCode" => 500, "Status" => "Error", "message" => "Failed to fetch beginning balance. " . mysqli_error($connect)]);
    exit;
}

$beginning_balances = array();
while ($row = mysqli_fetch_assoc($beginning_balance_result)) {
    $beginning_balances[$row['kodeProduk']] = $row['BB'];
}

// Barang masuk (In) during the month
$barang_masuk_query = "
    SELECT product AS kodeProduk, SUM(quantity * u.conversionFactor) AS `In`
    FROM warehouseTransaction wt
    LEFT JOIN unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
    WHERE transactionType = '" . TRX_IN . "'
      AND transactionDate BETWEEN '$beginningOfMonth' AND '$endOfMonth'
    GROUP BY product
";
$barang_masuk_result = mysqli_query($connect, $barang_masuk_query);

if (!$barang_masuk_result) {
    http_response_code(500);
    echo json_encode(["StatusCode" => 500, "Status" => "Error", "message" => "Failed to fetch barang masuk."]);
    exit;
}

$barang_masuks = array();
while ($row = mysqli_fetch_assoc($barang_masuk_result)) {
    $barang_masuks[$row['kodeProduk']] = $row['In'];
}

// Barang keluar (Out) during the month
$barang_keluar_query = "
    SELECT product AS kodeProduk, SUM(quantity * u.conversionFactor) AS `Out`
    FROM warehouseTransaction wt
    LEFT JOIN unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
    WHERE transactionType = '" . TRX_OUT . "'
      AND transactionDate BETWEEN '$beginningOfMonth' AND '$endOfMonth'
    GROUP BY product
";
$barang_keluar_result = mysqli_query($connect, $barang_keluar_query);

if (!$barang_keluar_result) {
    http_response_code(500);
    echo json_encode(["StatusCode" => 500, "Status" => "Error", "message" => "Failed to fetch barang keluar."]);
    exit;
}

$barang_keluars = array();
while ($row = mysqli_fetch_assoc($barang_keluar_result)) {
    $barang_keluars[$row['kodeProduk']] = $row['Out'];
}

// Daily In/Out for the whole month, per product, in one query each (avoids N+1 per day)
$daily_in_query = "
    SELECT product AS kodeProduk, DATE(transactionDate) AS tgl, SUM(quantity * u.conversionFactor) AS `In`
    FROM warehouseTransaction wt
    LEFT JOIN unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
    WHERE transactionType = '" . TRX_IN . "'
      AND transactionDate BETWEEN '$beginningOfMonth' AND '$endOfMonth'
    GROUP BY product, DATE(transactionDate)
";
$daily_in_result = mysqli_query($connect, $daily_in_query);
$daily_ins = array();
while ($row = mysqli_fetch_assoc($daily_in_result)) {
    $daily_ins[$row['kodeProduk']][$row['tgl']] = $row['In'];
}

$daily_out_query = "
    SELECT product AS kodeProduk, DATE(transactionDate) AS tgl, SUM(quantity * u.conversionFactor) AS `Out`
    FROM warehouseTransaction wt
    LEFT JOIN unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
    WHERE transactionType = '" . TRX_OUT . "'
      AND transactionDate BETWEEN '$beginningOfMonth' AND '$endOfMonth'
    GROUP BY product, DATE(transactionDate)
";
$daily_out_result = mysqli_query($connect, $daily_out_query);
$daily_outs = array();
while ($row = mysqli_fetch_assoc($daily_out_result)) {
    $daily_outs[$row['kodeProduk']][$row['tgl']] = $row['Out'];
}

$monthly_stock_report = array();
foreach ($all_products as $kodeProduk => $details) {
    $namaProduk   = $details['namaProduk'];
    $BB  = isset($beginning_balances[$kodeProduk]) ? $beginning_balances[$kodeProduk] : 0;
    $In  = isset($barang_masuks[$kodeProduk])      ? $barang_masuks[$kodeProduk]      : 0;
    $Out = isset($barang_keluars[$kodeProduk])     ? $barang_keluars[$kodeProduk]     : 0;

    $daily_transactions = array();
    for ($i = 0; $i < $daysInMonth; $i++) {
        $date = date('Y-m-d', strtotime("$beginningOfMonth +$i day"));
        $daily_in  = $daily_ins[$kodeProduk][$date]  ?? 0;
        $daily_out = $daily_outs[$kodeProduk][$date] ?? 0;

        $daily_transactions[] = array(
            "date" => $date,
            "In"   => rtrim(rtrim(number_format($daily_in, 5), '0'), '.'),
            "Out"  => rtrim(rtrim(number_format($daily_out, 5), '0'), '.')
        );
    }

    $ending_balance = $BB + $In - $Out;

    $monthly_stock_report[] = array(
        "kodeProduk"          => $kodeProduk,
        "namaProduk"          => $namaProduk,
        "BB"                  => rtrim(rtrim(number_format($BB, 5), '0'), '.'),
        "In"                  => rtrim(rtrim(number_format($In, 5), '0'), '.'),
        "Out"                 => rtrim(rtrim(number_format($Out, 5), '0'), '.'),
        "ending_balance"       => rtrim(rtrim(number_format($ending_balance, 5), '0'), '.'),
        "daily_transactions"   => $daily_transactions
    );
}

http_response_code(200);
echo json_encode(array(
    "StatusCode" => 200,
    "Status"     => "Success",
    "message"    => "Monthly stock report generated successfully.",
    "period"     => array("year" => $year, "month" => $month, "start_date" => $beginningOfMonth, "end_date" => $endOfMonth),
    "data"       => $monthly_stock_report
));

mysqli_close($connect);
?>
