<?php
// Export detail invoice omset per bulan ke Excel
// GET params: year, month (optional), customer_id (optional), search (optional)
require __DIR__ . '/../vendor/autoload.php';
require_once('../connection/connection.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$year        = isset($_GET['year'])        ? (int)$_GET['year']                                        : (int)date('Y');
$month       = isset($_GET['month'])       ? (int)$_GET['month']                                       : 0;
$customer_id = isset($_GET['customer_id']) ? mysqli_real_escape_string($connect, $_GET['customer_id']) : '';
$search      = isset($_GET['search'])      ? mysqli_real_escape_string($connect, $_GET['search'])      : '';

$where = "YEAR(A1.invoiceDate) = $year";
if ($month > 0)          $where .= " AND MONTH(A1.invoiceDate) = $month";
if ($customer_id !== '') $where .= " AND A1.customerID = '$customer_id'";
if ($search !== '')      $where .= " AND (A1.invoiceNumber LIKE '%$search%' OR A3.company_name LIKE '%$search%')";

$query = "
    SELECT
        A1.invoiceNumber,
        A1.invoiceDate,
        A1.customerID,
        A3.company_name,
        COUNT(A2.id)                                                            AS total_items,
        SUM(A2.productQuantity)                                                 AS total_qty,
        SUM(A2.productQuantity * A2.unitPrice)                                  AS omset_sebelum_ppn,
        SUM(A2.productQuantity * A2.unitPrice + COALESCE(A2.tax, 0)) AS omset_termasuk_ppn
    FROM salesInvoice A1
    LEFT JOIN salesInvoiceItem A2 ON A1.invoiceNumber = A2.DONumber
    LEFT JOIN customer A3 ON A1.customerID = A3.company_id
    WHERE $where
    GROUP BY A1.invoiceNumber, A1.invoiceDate, A1.customerID, A3.company_name
    ORDER BY A1.invoiceDate DESC
";

$result = mysqli_query($connect, $query);
$rows   = mysqli_fetch_all($result, MYSQLI_ASSOC);

$ss    = new Spreadsheet();
$sheet = $ss->getActiveSheet();
$sheet->setTitle('Detail Omset');

// Title
$monthLabel = $month > 0 ? date('F', mktime(0, 0, 0, $month, 1)) . ' ' : '';
$sheet->mergeCells('A1:H1');
$sheet->setCellValue('A1', "DETAIL INVOICE OMSET / PENJUALAN — {$monthLabel}TAHUN $year");
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Header
$headers = ['No', 'No. Invoice', 'Tanggal', 'Customer ID', 'Nama Customer', 'Total Item', 'Total Qty', 'Omset (Before PPN)', 'Omset (Incl. PPN)'];
$sheet->fromArray($headers, null, 'A3');
$lastCol = 'I';
$sheet->getStyle("A3:{$lastCol}3")->getFont()->setBold(true);
$sheet->getStyle("A3:{$lastCol}3")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("A3:{$lastCol}3")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("A3:{$lastCol}3")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1F2');

// Data rows
$r = 4;
$grandPre = 0;
$grandInc = 0;
$no = 1;
foreach ($rows as $row) {
    $pre = (float)$row['omset_sebelum_ppn'];
    $inc = (float)$row['omset_termasuk_ppn'];
    $sheet->setCellValue("A$r", $no++);
    $sheet->setCellValue("B$r", $row['invoiceNumber']);
    $sheet->setCellValue("C$r", $row['invoiceDate']);
    $sheet->setCellValue("D$r", $row['customerID']);
    $sheet->setCellValue("E$r", $row['company_name']);
    $sheet->setCellValue("F$r", (int)$row['total_items']);
    $sheet->setCellValue("G$r", (float)$row['total_qty']);
    $sheet->setCellValue("H$r", $pre);
    $sheet->setCellValue("I$r", $inc);
    $sheet->getStyle("H$r:I$r")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle("A$r:{$lastCol}$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $grandPre += $pre;
    $grandInc += $inc;
    $r++;
}

// Total row
$sheet->setCellValue("D$r", 'TOTAL');
$sheet->setCellValue("H$r", $grandPre);
$sheet->setCellValue("I$r", $grandInc);
$sheet->getStyle("A$r:{$lastCol}$r")->getFont()->setBold(true);
$sheet->getStyle("H$r:I$r")->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle("A$r:{$lastCol}$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("A$r:{$lastCol}$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF2CC');

foreach (range('A', 'I') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$monthSuffix = $month > 0 ? '_' . str_pad($month, 2, '0', STR_PAD_LEFT) : '';
$filename    = "Detail_Omset_{$year}{$monthSuffix}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');

$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
?>
