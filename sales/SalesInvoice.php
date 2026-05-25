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

function formatDateShort($date) {
    $timestamp = strtotime($date);
    return date('d-M-y', $timestamp); // Formats as "03-Jan-22"
}


$order_id = $_GET['SONumber'];
$orderQuery = "
    SELECT SI.invoiceDate, C.company_name, C.company_address, SI.invoiceNumber, SPT.PPNPercentage, C.company_top, SOI.purchaseOrderNumber
    FROM salesOrder SO
    LEFT JOIN customer C ON SO.SOCustomer = C.company_id
    LEFT JOIN salesPPNType SPT ON SO.SOPPN = SPT.PPNType_id
    LEFT JOIN salesOrderItem SOI ON SO.SONumber = SOI.salesOrderNumber
    LEFT JOIN salesInvoice SI ON SO.SONumber = SI.invoiceNumber
    WHERE SO.SONumber = '$order_id';
";
$orderResult = mysqli_query($connect, $orderQuery);

if (!$orderResult || mysqli_num_rows($orderResult) === 0) {
    die('No sales order found for the given ID.');
}

$orderData = mysqli_fetch_assoc($orderResult);
$InvoiceDate = $orderData['invoiceDate'];
$Customer = $orderData['company_name'];
$Address = $orderData['company_address'];
$PPNPercentage = $orderData['PPNPercentage'];
$TOP = $orderData['company_top'];
$NOPO = $orderData['purchaseOrderNumber'];

$itemQuery = "SELECT productQuantity, productName, unitPrice, tax FROM salesInvoiceItem WHERE SONumber = '$order_id'";
$itemResult = mysqli_query($connect, $itemQuery);

if (!$itemResult) {
    die('Failed to fetch order items.');
}

// Create a new spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$sheet->setCellValue('I1', formatDateShort($InvoiceDate));
$sheet->setCellValue('G4', $Customer);
$sheet->setCellValue('G5', $Address);
$sheet->setCellValue('I7', $order_id);

$sheet->setCellValue('A10', 'QTY');
$sheet->setCellValue('C10', 'Nama Barang');
$sheet->setCellValue('G10', 'HARGA');

$row = 11;
$totalprice = 0;
$totalPriceForItem = 0;

while ($item = mysqli_fetch_assoc($itemResult)) {
    $sheet->setCellValue("A$row", $item['productQuantity'] . " KG");
    $sheet->setCellValue("B$row", "              " . $item['productName']);
    $sheet->setCellValue("G$row", number_format($item['unitPrice'], 2));
    
    $totalPriceForItem = $item['unitPrice'] * $item['productQuantity'];
    $sheet->setCellValue("I$row", number_format($totalPriceForItem, 2)); 
    $row++; 
    
    $sheet->setCellValue("B$row", "              PPN " . $PPNPercentage . "%");
    $sheet->setCellValue("I$row", number_format($item['tax'], 2));
    $row++; 
    
    $totalprice += $totalPriceForItem + $item['tax'];
}

$row++;
$row++;
$sheet->setCellValue("B$row", "                TOP: " . $TOP . " HARI");
$row++;
$row++;
$sheet->setCellValue("B$row", "JTT :");
$invoiceDateObj = new DateTime($InvoiceDate);
$invoiceDateObj->modify("+$TOP days");
$JTT = formatDateShort($invoiceDateObj->format('Y-m-d'));
$sheet->setCellValue("D$row", $JTT);
$row++;
$row++;
$sheet->setCellValue("D$row", "PO No    : " . $NOPO);
$row++;
$row++;
$sheet->setCellValue("D$row", "No.Rek PT.Venken International Kimia :  8015.373.888 (BCA)");

$row++;
$row++;
$row++;
$row++;
$row++;
$row++;
$sheet->setCellValue("I$row", number_format($totalprice, 2));

// Output the file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="SalesInvoice.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;