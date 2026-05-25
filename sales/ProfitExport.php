<?php
require __DIR__ . '/../vendor/autoload.php';
require_once('../connection/connection.php'); // Include your database connection

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

// Fetch order details
$order_id = $_GET['SONumber']; // Assuming you pass the order ID via a query parameter
$orderQuery = "
    SELECT SO.SONumber, C.company_name, SO.SODate, C.company_address, SPT.PPNType_name, SOI.purchaseOrderNumber, C.company_top, SPT.PPNPercentage, SO.SOSendDate, SO.SOSendTo
    FROM salesOrder SO
    LEFT JOIN customer C ON SO.SOCustomer = C.company_id
    LEFT JOIN salesPPNType SPT ON SO.SOPPN = SPT.PPNType_id
    LEFT JOIN salesOrderItem SOI ON SO.SONumber = SOI.salesOrderNumber
    WHERE SO.SONumber = '$order_id';
";
$orderResult = mysqli_query($connect, $orderQuery);

$historyQuery = "SELECT ActionBy, ActionDt FROM salesOrderHistory WHERE SONumber = '$order_id' AND Action LIKE '%Draft Profit telah berhasil diinput dan menunggu%'";
$historyResult = mysqli_query($connect, $historyQuery);

$historyQueryApprove = "SELECT ActionBy, ActionDt FROM salesOrderHistory WHERE SONumber = '$order_id' AND Action LIKE '%Draft Profit telah disetujui%'";
$historyResultApprove = mysqli_query($connect, $historyQueryApprove);

if (!$orderResult || mysqli_num_rows($orderResult) === 0) {
    die('No sales order found for the given ID.');
}

$orderData = mysqli_fetch_assoc($orderResult);
$historyData = mysqli_fetch_assoc($historyResult);
$historyDataApprove = mysqli_fetch_assoc($historyResultApprove);

$SONumber = $orderData['SONumber'];
$TOP = $orderData['company_top'];
$Customer = $orderData['company_name'];
$uploadedBy = $historyData['ActionBy'];
$uploadedDt = $historyData['ActionDt'];
$approveBy = $historyDataApprove['ActionBy'];
$approveDt = $historyDataApprove['ActionDt'];

$itemQuery = "
    SELECT 
        DISTINCT SOI.Kurs, 
        SPI.ProductName, 
        SPI.Quantity, 
        SPI.Price, 
        SPI.LandedCost, 
        (SPI.Price - SPI.LandedCost) AS ProfitDifference,
        ((SPI.Price - SPI.LandedCost) / SPI.LandedCost * 100) AS PercentageDifference
    FROM 
        salesOrder SO
    LEFT JOIN 
        salesProfit SP 
        ON SO.SONumber = SP.SalesNumber
    LEFT JOIN 
        salesProfitItem SPI 
        ON SO.SONumber = SPI.SalesOrderNumber
    LEFT JOIN 
        salesOrderItem SOI 
        ON SO.SONumber = SOI.salesOrderNumber
    WHERE SO.SONumber = '$order_id';
";
$itemResult = mysqli_query($connect, $itemQuery);

if (!$itemResult) {
    die('Failed to fetch order items.');
}

// Create a new spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set document title in A1
$sheet->setCellValue('A1', 'PROFIT CALCULATION');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

// Adjust column widths
$sheet->getColumnDimension('A')->setWidth(10);
$sheet->getColumnDimension('B')->setWidth(45);

// Center title text horizontally across columns A to D
$sheet->mergeCells('A1:B1');
$sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

// Enable text wrapping for the title
$sheet->getStyle('A1')->getAlignment()->setWrapText(true);


$sheet->setCellValue('A3', 'SO : ');
$sheet->setCellValue('B3', $SONumber);
$sheet->setCellValue('A4', 'Cust .');
$sheet->setCellValue('B4', $Customer);

$tableHeaders = [
    'No','Nama Barang', 'Qty', 'Harga Jual Satuan', 'Landed Cost', 'PROFIT', 'Profit %'
];
$sheet->fromArray($tableHeaders, null, 'A6');
$sheet->getStyle('A6:G6')->getFont()->setBold(true);
$sheet->getStyle('A6:G6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A6:G6')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

$kurs = $item['Kurs'] ?? 'N/A';

$sheet->setCellValue('B7', 'Kurs = '. $kurs);
$sheet->getStyle('A7:G7')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle('A8:G8')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

$row = 9;
$no = 1;
$totalqty = 0;
$totalprice = 0;
$totallanded = 0;
$totalprofit = 0;
$totalpercentage = 0;

while ($item = mysqli_fetch_assoc($itemResult)) {
     $sheet->setCellValue("A$row", $no);
     $sheet->setCellValue("B$row", $item['ProductName']);
     $sheet->setCellValue("C$row", number_format($item['Quantity'], 0));
     $sheet->setCellValue("D$row", number_format($item['Price'], 2));
     $sheet->setCellValue("E$row", number_format($item['LandedCost'], 2));
     $sheet->setCellValue("F$row", number_format($item['ProfitDifference'], 2));
     $sheet->setCellValue("G$row", $item['PercentageDifference']);
     
     $sheet->getStyle("A$row:G$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
     $totalqty += $item['Quantity'];
     $totalprice += $item['Price'];
     $totallanded += $item['LandedCost'];
     $totalprofit += $item['ProfitDifference'];
     $totalpercentage += $item['PercentageDifference'];
     
     $row++;
     $no++;
}

$sheet->getStyle("A$row:G$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN); // Border for first null row
$row++; 
$sheet->getStyle("A$row:G$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN); // Border for first null row
$row++; 
$sheet->getStyle("A$row:G$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN); // Border for first null row
$row++; 
$sheet->getStyle("A$row:G$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN); // Border for first null row
$sheet->setCellValue("A$row", 'TOP :'); 
$sheet->setCellValue("B$row", $TOP. ' hari'); 
$row++; 

$sheet->setCellValue("B$row", 'TOTAL');
$sheet->setCellValue("C$row", $totalqty);
$sheet->setCellValue("D$row", $totalprice);
$sheet->setCellValue("E$row", $totallanded);
$sheet->setCellValue("F$row", $totalprofit);
$sheet->setCellValue("G$row", $totalpercentage);
$sheet->getStyle("A$row:G$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN); 
$row++; 

// Footer
$row += 2;
$sheet->setCellValue("A$row", 'DIBUAT OLEH,');
$sheet->setCellValue("C$row", 'MENGETAHUI OLEH,');
$sheet->setCellValue("F$row", 'DISETUJUI OLEH,');
$row++; 
$sheet->setCellValue("A$row", $uploadedBy . " pada " . $uploadedDt);
$sheet->setCellValue("C$row", $uploadedBy . " pada " .$uploadedDt);
$sheet->setCellValue("F$row", $approveBy . " pada " .$approveDt);
$row += 3;
$sheet->setCellValue("A$row", '( ADMIN SALES )');
$sheet->setCellValue("C$row", '( TUKAR FAKTUR )');
$sheet->setCellValue("F$row", '( SULANTO )    ( IRENE )');


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