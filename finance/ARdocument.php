<?php
require __DIR__ . '/../vendor/autoload.php';
require_once('../connection/connection.php'); 

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
    
$itemQuery = "SELECT C.company_name, SO.SODate, C.company_top, SO.SONumber, SOI.ProductName, SOI.Quantity, SOI.HargaSatuan, SPT.PPNPercentage, SI.invoiceDate
FROM salesOrder SO
LEFT JOIN customer C ON SO.SOCustomer = C.company_id
LEFT JOIN salesOrderItem SOI ON SO.SONumber = SOI.salesOrderNumber
LEFT JOIN salesPPNType SPT ON SO.SOPPN = SPT.PPNType_id
LEFT JOIN salesInvoice SI ON SO.SONumber = SI.invoiceNumber
WHERE SO.SODate BETWEEN '$start_date' AND '$end_date'";
$itemResult = mysqli_query($connect, $itemQuery);

if (!$itemResult) {
    die('Failed to fetch order items.');
}

function formatIndonesianDate($date) {
    $monthNames = [
        1 => 'JANUARI', 'FEBRUARI', 'MARET', 'APRIL', 'MEI', 'JUNI', 
        'JULI', 'AGUSTUS', 'SEPTEMBER', 'OKTOBER', 'NOVEMBER', 'DESEMBER'
    ];
    
    $timestamp = strtotime($date);
    $day = date('j', $timestamp);
    $month = $monthNames[(int)date('n', $timestamp)];
    $year = date('Y', $timestamp);
    
    return "$day $month $year";
}

if ($start_date && $end_date) {
    $start_date_formatted = formatIndonesianDate($start_date);
    $end_date_formatted = formatIndonesianDate($end_date);
    $period = "PERIODE $start_date_formatted S/D $end_date_formatted";
} else {
    $period = "PERIODE 1 JANUARI 2024 S/D 31 JANUARI 2024"; // Default fallback
}

// Create a new spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set the title and subtitle (merged cells)
$sheet->mergeCells('A1:P1');
$sheet->setCellValue('A1', 'PT. VENKEN INTERNATIONAL KIMIA');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

$sheet->mergeCells('A2:P2');
$sheet->setCellValue('A2', $period);
$sheet->getStyle('A2')->getAlignment()->setHorizontal('center');

// Header row
$headers = [
    'NO', 'NAMA CUSTOMER', 'TGL FAKTUR', 'TOP', 'TGL JTT', 'NO. INV',
    'NAMA BARANG', 'QTY IN KG', 'CURR', 'PRICE @', 'TOTAL', 'PPN',
    'TOTAL SALES (INCL PPN)', 'KETERANGAN', 'TGL. PELUNASAN', 'KIRIM FAKTUR'
];
$sheet->fromArray($headers, null, 'A4');
$sheet->getStyle('A4:P4')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A4:P4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('92D050'); // Green background
$sheet->getStyle('A4:P4')->getAlignment()->setHorizontal('center');

$row = 5;
$no = 1;
$totalbeforePPN = 0;
$totalPPN = 0;
$totalafterPPN = 0;

while ($item = mysqli_fetch_assoc($itemResult)) {
    $totalbeforePPN = $item['Quantity'] * $item['HargaSatuan'];
    $totalPPN = $item['PPNPercentage'] * $totalbeforePPN / 100;
    $totalafterPPN = $totalbeforePPN + $totalPPN;
    
    $sheet->setCellValue("A$row", $no);
    $sheet->setCellValue("B$row", $item['company_name']);
    $sheet->setCellValue(
        "C$row", 
        $item['SODate'] ? formatIndonesianDate($item['SODate']) : '-'
    );

    $sheet->setCellValue("D$row", $item['company_top']);
    
    $soDateTimestamp = strtotime($item['SODate']);
    $companyTop = (int)$item['company_top'];
    $newDateTimestamp = strtotime("+$companyTop days", $soDateTimestamp);
    $newFormattedDate = formatIndonesianDate(date('Y-m-d', $newDateTimestamp));
    
    $sheet->setCellValue("E$row", $newFormattedDate);
    $sheet->setCellValue("F$row", $item['SONumber']);
    $sheet->setCellValue("G$row", $item['ProductName']);
    $sheet->setCellValue("H$row", $item['Quantity']);
    $sheet->setCellValue("I$row", 'IDR');
    $sheet->setCellValue("J$row", number_format($item['HargaSatuan'],2));
    $sheet->setCellValue("K$row", number_format($totalbeforePPN,2));
    $sheet->setCellValue("L$row", number_format($totalPPN,2));
    $sheet->setCellValue("M$row", number_format($totalafterPPN,2));
    
    $invoiceNumber = $item['SONumber'];
    $paymentQuery = "
        SELECT DISTINCT due_amount, paymentdate
        FROM financeItem FI
        WHERE FI.invoice_number = '$invoiceNumber'
        ORDER BY FI.insert_dt DESC LIMIT 1;
    ";
    $paymentResult = mysqli_query($connect, $paymentQuery);
    $payment = mysqli_fetch_assoc($paymentResult);
    
    $keterangan = "Belum Lunas";
    $pelunasanDate = '-';
    
    if ($payment && (float)$payment['due_amount'] == 0) {
        $keterangan = "Lunas";
        $pelunasanDate = $payment['paymentdate'] ? formatIndonesianDate($payment['paymentdate']) : '-';
    }
    
    $sheet->setCellValue("N$row", $keterangan);
    $sheet->setCellValue("O$row", $pelunasanDate);
    
    $sheet->setCellValue(
        "P$row", 
        $item['invoiceDate'] ? formatIndonesianDate($item['invoiceDate']) : '-'
    );
    $sheet->getStyle("A$row:M$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFF99');
    $row++;
    $no++;
}


// Adjust column widths
foreach (range('A', 'P') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

// Set borders for the data area
$lastRow = $row - 1;
$sheet->getStyle("A4:P$lastRow")->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

// Download the file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="sales_report.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;