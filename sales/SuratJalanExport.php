<?php
require __DIR__ . '/../vendor/autoload.php';
require_once('../connection/connection.php'); 

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

function formatIndonesianDate($date) {
    $monthNames = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    
    $timestamp = strtotime($date);
    $day = date('j', $timestamp);
    $month = $monthNames[(int)date('n', $timestamp)];
    $year = date('Y', $timestamp);
    
    return "$day $month $year";
}

$order_id = $_GET['SONumber'];
$orderQuery = "
    SELECT SO.SONumber, C.company_name, SO.SODate, C.company_address, SPT.PPNType_name, SOI.purchaseOrderNumber, C.company_top, SPT.PPNPercentage, SO.SOSendDate, SO.SOSendTo,
    SD.DeliveryDate, C.company_phone, SD.DONumber
    FROM salesOrder SO
    LEFT JOIN customer C ON SO.SOCustomer = C.company_id
    LEFT JOIN salesPPNType SPT ON SO.SOPPN = SPT.PPNType_id
    LEFT JOIN salesOrderItem SOI ON SO.SONumber = SOI.salesOrderNumber
    LEFT JOIN salesDelivery SD ON SO.SONumber = SD.SONumber
    WHERE SO.SONumber = '$order_id';
";
$orderResult = mysqli_query($connect, $orderQuery);

if (!$orderResult || mysqli_num_rows($orderResult) === 0) {
    die('No sales order found for the given ID.');
}

$orderData = mysqli_fetch_assoc($orderResult);
$Customer = $orderData['company_name'];
$CustomerPhone = $orderData['company_phone'];
$CustomerAddress = $orderData['company_address'];
$DeliveryDate = $orderData['DeliveryDate'];
$DeliveryNumber = $orderData['DONumber'];

$itemQuery = "SELECT productName, productQTY FROM salesDeliveryItem where DeliveryOrder = '$order_id'";
$itemResult = mysqli_query($connect, $itemQuery);

if (!$itemResult) {
    die('Failed to fetch order items.');
}


// Create a new spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$sheet->setCellValue('G1', formatIndonesianDate($DeliveryDate));
$sheet->setCellValue('G3', $Customer);
$sheet->setCellValue('G4', $CustomerAddress);
$sheet->setCellValue('B5', $DeliveryNumber);
$sheet->setCellValue('G5', $CustomerAddress);
$sheet->setCellValue('G6', $CustomerPhone);
$sheet->setCellValue('A8', 'Banyaknya');
$sheet->setCellValue('C8', 'Nama Barang');

$row = 9;
while($item = mysqli_fetch_assoc($itemResult)){
    $sheet->setCellValue("A$row", $item['productQTY']. " kg");
    $sheet->setCellValue("C$row", $item['productName']);
    $row++;
    $sheet->setCellValue("C$row","[@... Kg x .. Bag]");
    $row++;
    $sheet->setCellValue("C$row","Lot No:");
    $sheet->setCellValue("D$row","[Nomor Lot]");
    $row++;
}

$sheet->setCellValue('G24', 'Intan');

// Adjust column widths
foreach (range('A', 'J') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Output the file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="sales_order.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;