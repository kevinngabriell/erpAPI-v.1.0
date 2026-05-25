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
$orderQuery = 
"
    SELECT SO.SONumber, C.company_name, SO.SODate, C.company_address, SPT.PPNType_name, SOI.purchaseOrderNumber, C.company_top, SPT.PPNPercentage, SO.SOSendDate, SO.SOSendTo
    FROM salesOrder SO
    LEFT JOIN customer C ON SO.SOCustomer = C.company_id
    LEFT JOIN salesPPNType SPT ON SO.SOPPN = SPT.PPNType_id
    LEFT JOIN salesOrderItem SOI ON SO.SONumber = SOI.salesOrderNumber
    WHERE SO.SONumber = '$order_id';
";
$orderResult = mysqli_query($connect, $orderQuery);

$historyQuery = "SELECT ActionBy, ActionDt FROM salesOrderHistory WHERE SONumber = '$order_id' AND Action LIKE '%Draft Sales Order telah berhasil diinput%'";
$historyResult = mysqli_query($connect, $historyQuery);

$historyQueryApprove = "SELECT ActionBy, ActionDt FROM salesOrderHistory WHERE SONumber = '$order_id' AND Action LIKE '%Draft sales order telah disetujui%'";
$historyResultApprove = mysqli_query($connect, $historyQueryApprove);

if (!$orderResult || mysqli_num_rows($orderResult) === 0) {
    die('No sales order found for the given ID.');
}

$orderData = mysqli_fetch_assoc($orderResult);
$historyData = mysqli_fetch_assoc($historyResult);
$historyDataApprove = mysqli_fetch_assoc($historyResultApprove);

$SONumber = $orderData['SONumber'];
$Customer = $orderData['company_name'];
$Tanggal = $orderData['SODate'];
$CustomerAddress = $orderData['company_address'];
$PPNType = $orderData['PPNType_name'];
$PONumber = $orderData['purchaseOrderNumber'];
$TOP = $orderData['company_top'];
$SendTo = $orderData['SOSendTo'];
$SendDate = $orderData['SOSendDate'];
$uploadedBy = $historyData['ActionBy'];
$uploadedDt = $historyData['ActionDt'];
$approveBy = $historyDataApprove['ActionBy'];
$approveDt = $historyDataApprove['ActionDt'];

$itemQuery = "
    SELECT SOI.ProductName, SOI.Quantity, UOM.uomName, C.currency_name, SOI.HargaSatuan, (SOI.Quantity * SOI.HargaSatuan) AS total
    FROM salesOrderItem SOI
    LEFT JOIN currency C ON SOI.MataUang = C.currency_id
    LEFT JOIN unitOfMeasure UOM ON SOI.Satuan = UOM.uomID
    WHERE SOI.salesOrderNumber = '$SONumber';
";
$itemResult = mysqli_query($connect, $itemQuery);

if (!$itemResult) {
    die('Failed to fetch order items.');
}

// Create a new spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set document title
$sheet->mergeCells('A1:B1');
$sheet->setCellValue('A1', 'SALES ORDER');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Information rows
$sheet->setCellValue('A2', 'No SO :');
$sheet->setCellValue('B2', $SONumber); // Dynamically set SONumber
$sheet->setCellValue('F2', 'Customer :');
$sheet->setCellValue('G2', $Customer); // Dynamically set Customer
$sheet->setCellValue('F3', 'ALAMAT :');
$sheet->setCellValue('G3', $CustomerAddress); // Dynamically set Customer Address
$sheet->setCellValue('F4', 'PO NO :');
$sheet->setCellValue('G4', $PONumber); // Dynamically set Customer Address
$sheet->setCellValue('A3', 'Tanggal :');
$sheet->setCellValue('B3', formatIndonesianDate($Tanggal)); // Dynamically set Tanggal
$sheet->setCellValue('A4', 'PPN/NO PPN : ');
$sheet->setCellValue('B4', $PPNType); // Dynamically set Tanggal

// Table headers
$tableHeaders = [
    'NO', 'BARANG', 'QTY', 'SAT', 'CURR', 'HARGA @', 'TOTAL', 'KURS', 'DPP', 'PPN'
];
$sheet->fromArray($tableHeaders, null, 'A5');
$sheet->getStyle('A5:J5')->getFont()->setBold(true);
$sheet->getStyle('A5:J5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A5:J5')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

// Fill item rows
$row = 6;
$no = 1;
$totalDPP = 0;
$totalPPN = 0;

while ($item = mysqli_fetch_assoc($itemResult)) {
    $dpp = $item['total']; // Assign the total directly to DPP
    $ppn = $orderData['PPNPercentage'] * $dpp / 100; // Calculate PPN based on percentage
    
    $sheet->setCellValue("A$row", $no);
    $sheet->setCellValue("B$row", $item['ProductName']);
    $sheet->setCellValue("C$row", $item['Quantity']);
    $sheet->setCellValue("D$row", $item['uomName']);
    $sheet->setCellValue("E$row", $item['currency_name']);
    $sheet->setCellValue("F$row", number_format($item['HargaSatuan'], 2));
    $sheet->setCellValue("G$row", number_format($item['total'], 2));
    $sheet->setCellValue("H$row", number_format($item['Kurs'], 2)); // Assuming 1.00 as the exchange rate
    $sheet->setCellValue("I$row", number_format($dpp, 2)); // DPP
    $sheet->setCellValue("J$row", number_format($ppn, 2)); // PPN

    $sheet->getStyle("A$row:J$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $totalDPP += $dpp;
    $totalPPN += $ppn;
    $row++;
    $no++;
}

$sheet->getStyle("A$row:J$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN); // Border for first null row
$row++; 
$sheet->getStyle("A$row:J$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN); // Border for first null row
$sheet->setCellValue("A$row", 'TOP :'); 
$sheet->setCellValue("B$row", $TOP. ' hari'); 
$row++; 
$sheet->getStyle("A$row:J$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN); // Border for first null row
$row++; 

// Total row
$sheet->setCellValue("F$row", 'Total :');
$sheet->setCellValue("G$row", number_format($totalDPP, 2));
$sheet->setCellValue("I$row", number_format($totalDPP, 2));
$sheet->setCellValue("J$row", number_format($totalPPN, 2));
$sheet->getStyle("A$row:J$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("A$row:J$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN); 
$row++; 

$sheet->mergeCells("I$row:J$row");
$sheet->setCellValue("I$row", number_format($totalDPP + $totalPPN, 2));
$sheet->getStyle("A$row:J$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN); 
$row++;

$sheet->setCellValue("A$row", 'DIKIRIM Tgl : '); 
$sheet->setCellValue("B$row", formatIndonesianDate($SendDate)); 
$row++; 
$sheet->setCellValue("A$row", 'DIKIRIM KE : '); 
$sheet->setCellValue("B$row", formatIndonesianDate($SendTo)); 
$row++; 


// Footer
$row += 2;
$sheet->setCellValue("A$row", 'DIBUAT OLEH,');
$sheet->setCellValue("D$row", 'MENGETAHUI OLEH,');
$sheet->setCellValue("I$row", 'DISETUJUI OLEH,');
$row++; 
$sheet->setCellValue("A$row", $uploadedBy . " pada " . $uploadedDt);
$sheet->setCellValue("D$row", $uploadedBy . " pada " .$uploadedDt);
$sheet->setCellValue("I$row", $approveBy . " pada " .$approveDt);
$row += 3;
$sheet->setCellValue("A$row", '( ADMIN SALES )');
$sheet->setCellValue("D$row", '( TUKAR FAKTUR )');
$sheet->setCellValue("I$row", '( SULANTO )    ( IRENE )');

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