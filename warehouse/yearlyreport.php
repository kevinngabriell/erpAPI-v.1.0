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
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)$currentDateTime->format('Y');

const TRX_IN  = '452c5015-e80f-4e8a-8';
const TRX_OUT = '9cafab5b-d975-41e8-8';

$beginningOfYear = "$year-01-01";
$endOfYear        = "$year-12-31";

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

// Beginning balance (BB) as of start of year, per product
$beginning_balance_query = "
    SELECT
        w.product AS kodeProduk,
        p.productName AS namaProduk,
        COALESCE(SUM(w.beginningbalance), 0) +
        COALESCE(SUM(CASE WHEN wt.transactionType = '" . TRX_IN . "' THEN wt.quantity * u.conversionFactor ELSE 0 END), 0) -
        COALESCE(SUM(CASE WHEN wt.transactionType = '" . TRX_OUT . "' THEN wt.quantity * u.conversionFactor ELSE 0 END), 0) AS BB
    FROM warehouse w
    LEFT JOIN product p ON w.product = p.skuID
    LEFT JOIN warehouseTransaction wt ON w.product = wt.product AND wt.transactionDate < '$beginningOfYear'
    LEFT JOIN unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
    WHERE wt.transactionDate < '$beginningOfYear'
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

// Barang masuk (In) for the whole year, per product
$barang_masuk_query = "
    SELECT product AS kodeProduk, SUM(quantity * u.conversionFactor) AS `In`
    FROM warehouseTransaction wt
    LEFT JOIN unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
    WHERE transactionType = '" . TRX_IN . "'
      AND transactionDate BETWEEN '$beginningOfYear' AND '$endOfYear'
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

// Barang keluar (Out) for the whole year, per product
$barang_keluar_query = "
    SELECT product AS kodeProduk, SUM(quantity * u.conversionFactor) AS `Out`
    FROM warehouseTransaction wt
    LEFT JOIN unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
    WHERE transactionType = '" . TRX_OUT . "'
      AND transactionDate BETWEEN '$beginningOfYear' AND '$endOfYear'
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

// Monthly In/Out breakdown for the whole year, per product (one query each)
$monthly_in_query = "
    SELECT product AS kodeProduk, MONTH(transactionDate) AS bln, SUM(quantity * u.conversionFactor) AS `In`
    FROM warehouseTransaction wt
    LEFT JOIN unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
    WHERE transactionType = '" . TRX_IN . "'
      AND transactionDate BETWEEN '$beginningOfYear' AND '$endOfYear'
    GROUP BY product, MONTH(transactionDate)
";
$monthly_in_result = mysqli_query($connect, $monthly_in_query);
$monthly_ins = array();
while ($row = mysqli_fetch_assoc($monthly_in_result)) {
    $monthly_ins[$row['kodeProduk']][(int)$row['bln']] = $row['In'];
}

$monthly_out_query = "
    SELECT product AS kodeProduk, MONTH(transactionDate) AS bln, SUM(quantity * u.conversionFactor) AS `Out`
    FROM warehouseTransaction wt
    LEFT JOIN unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
    WHERE transactionType = '" . TRX_OUT . "'
      AND transactionDate BETWEEN '$beginningOfYear' AND '$endOfYear'
    GROUP BY product, MONTH(transactionDate)
";
$monthly_out_result = mysqli_query($connect, $monthly_out_query);
$monthly_outs = array();
while ($row = mysqli_fetch_assoc($monthly_out_result)) {
    $monthly_outs[$row['kodeProduk']][(int)$row['bln']] = $row['Out'];
}

$month_names = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];

$yearly_stock_report = array();
foreach ($all_products as $kodeProduk => $details) {
    $namaProduk = $details['namaProduk'];
    $BB  = isset($beginning_balances[$kodeProduk]) ? $beginning_balances[$kodeProduk] : 0;
    $In  = isset($barang_masuks[$kodeProduk])      ? $barang_masuks[$kodeProduk]      : 0;
    $Out = isset($barang_keluars[$kodeProduk])     ? $barang_keluars[$kodeProduk]     : 0;

    $monthly_transactions = array();
    for ($m = 1; $m <= 12; $m++) {
        $month_in  = $monthly_ins[$kodeProduk][$m]  ?? 0;
        $month_out = $monthly_outs[$kodeProduk][$m] ?? 0;

        $monthly_transactions[] = array(
            "month"      => $m,
            "month_name" => $month_names[$m],
            "In"         => rtrim(rtrim(number_format($month_in, 5), '0'), '.'),
            "Out"        => rtrim(rtrim(number_format($month_out, 5), '0'), '.')
        );
    }

    $ending_balance = $BB + $In - $Out;

    $yearly_stock_report[] = array(
        "kodeProduk"            => $kodeProduk,
        "namaProduk"            => $namaProduk,
        "BB"                    => rtrim(rtrim(number_format($BB, 5), '0'), '.'),
        "In"                    => rtrim(rtrim(number_format($In, 5), '0'), '.'),
        "Out"                   => rtrim(rtrim(number_format($Out, 5), '0'), '.'),
        "ending_balance"         => rtrim(rtrim(number_format($ending_balance, 5), '0'), '.'),
        "monthly_transactions"   => $monthly_transactions
    );
}

http_response_code(200);
echo json_encode(array(
    "StatusCode" => 200,
    "Status"     => "Success",
    "message"    => "Yearly stock report generated successfully.",
    "period"     => array("year" => $year, "start_date" => $beginningOfYear, "end_date" => $endOfYear),
    "data"       => $yearly_stock_report
));

mysqli_close($connect);
?>
