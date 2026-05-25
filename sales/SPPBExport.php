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
    SELECT SS.SPPBNumber, SS.SPPBDate, C.company_name
    FROM salesSPPB SS
    LEFT JOIN customer C ON SS.SPPBCustomer = C.company_id
    WHERE SS.SPPBNumber = '$order_id';
";
$orderResult = mysqli_query($connect, $orderQuery);

$historyQuery = "SELECT ActionBy, ActionDt FROM salesOrderHistory WHERE SONumber = '$order_id' AND Action LIKE '%Draft SPPB telah berhasil diinput dan menunggu %'";
$historyResult = mysqli_query($connect, $historyQuery);

$historyQueryApprove = "SELECT ActionBy, ActionDt FROM salesOrderHistory WHERE SONumber = '$order_id' AND Action LIKE '%Draft SPPB telah disetujui%'";
$historyResultApprove = mysqli_query($connect, $historyQueryApprove);


if (!$orderResult || mysqli_num_rows($orderResult) === 0) {
    die('No sales order found for the given ID.');
}

$orderData = mysqli_fetch_assoc($orderResult);
$historyData = mysqli_fetch_assoc($historyResult);
$historyDataApprove = mysqli_fetch_assoc($historyResultApprove);

$SPPBNumber = $orderData['SPPBNumber'];
$SPPBDate = $orderData['SPPBDate'];
$Customer = $orderData['company_name'];
$uploadedBy = $historyData['ActionBy'];
$uploadedDt = $historyData['ActionDt'];
$approveBy = $historyDataApprove['ActionBy'];
$approveDt = $historyDataApprove['ActionDt'];

$itemQuery = "
    SELECT SSI.PONumber, SSI.SendTo, SSI.SendDate, SSI.ProductName, SSI.Quantity, UOM.uomName, SSI.Description
    FROM salesSPPBItem SSI
    LEFT JOIN unitOfMeasure UOM ON SSI.Measure = UOM.uomID
    WHERE SSI.SPPBNumber = '$order_id';
";
$itemResult = mysqli_query($connect, $itemQuery);

if (!$itemResult) {
    die('Failed to fetch order items.');
}

// Create a new spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set document title in A1
$sheet->setCellValue('A1', 'SURAT PERMINTAAN PENGELUARAN BARANG (SPPB)');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

// Adjust column widths
$sheet->getColumnDimension('A')->setWidth(20);
$sheet->getColumnDimension('B')->setWidth(25);
$sheet->getColumnDimension('C')->setWidth(35);

// Center title text horizontally across columns A to D
$sheet->mergeCells('A1:C1');
$sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

// Enable text wrapping for the title
$sheet->getStyle('A1')->getAlignment()->setWrapText(true);

$sheet->mergeCells("A2:B2");
$sheet->setCellValue('A2', 'No SPPB : ');
$sheet->setCellValue('C2', $SPPBNumber);
$sheet->mergeCells("A3:B3");
$sheet->setCellValue('A3', 'Tanggal : ');
$sheet->setCellValue('C3', formatIndonesianDate($SPPBDate));
$sheet->mergeCells("A4:B4");
$sheet->setCellValue('A4', 'Customer : ');
$sheet->setCellValue('C4', $Customer);

$tableHeaders = [
    '','PO CUSTOMER', 'DIKIRIM KE', 'Tgl Kirim', 'Nama Barang', 'QTY', 'SAT', 'Keterangan'
];

$sheet->fromArray($tableHeaders, null, 'A5');
$sheet->getStyle('A5:H5')->getFont()->setBold(true);
$sheet->getStyle('A5:H5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A5:H5')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

// Fill item rows
$row = 6;
$no = 1;

while ($item = mysqli_fetch_assoc($itemResult)) {
    $sheet->setCellValue("A$row", $no);
    $sheet->setCellValue("B$row", $item['PONumber']);
    $sheet->setCellValue("C$row", $item['SendTo']);
    $sheet->setCellValue("D$row", formatIndonesianDate($item['SendDate']));
    $sheet->setCellValue("E$row", $item['ProductName']);
    $sheet->setCellValue("F$row", number_format($item['Quantity'], 2));
    $sheet->setCellValue("G$row", $item['uomName']);
    $sheet->setCellValue("H$row", $item['Description']);

    $sheet->getStyle("A$row:H$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $row++;
    $no++;
}

$sheet->getStyle("A$row:H$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN); // Border for first null row
$row++; 
$sheet->getStyle("A$row:H$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN); // Border for first null row
$row++; 

$sheet->setCellValue("A$row", 'DIBUAT OLEH,');
$sheet->setCellValue("C$row", 'MENGETAHUI OLEH,');
$sheet->setCellValue("E$row", 'DISETUJUI OLEH,');
$row++; 
$sheet->setCellValue("A$row", $uploadedBy . " pada " . $uploadedDt);
$sheet->setCellValue("C$row", $uploadedBy . " pada " .$uploadedDt);
$sheet->setCellValue("E$row", $approveBy . " pada " .$approveDt);
$row += 3;
$sheet->setCellValue("A$row", '( ADMIN SALES )');
$sheet->setCellValue("C$row", '( TUKAR FAKTUR )');
$sheet->setCellValue("E$row", '( SULANTO )    ( IRENE )');


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