<?php
// Export Omset per Bulan ke Excel
// GET params: year, month (optional), customer_id (optional)
require __DIR__ . '/../vendor/autoload.php';
require_once('../connection/connection.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$year        = isset($_GET['year'])       ? (int)$_GET['year']       : (int)date('Y');
$month       = isset($_GET['month'])      ? (int)$_GET['month']      : 0;
$customer_id = isset($_GET['customer_id'])? mysqli_real_escape_string($connect, $_GET['customer_id']) : '';

$date_filter = "YEAR(A1.invoiceDate) = $year";
if ($month > 0) $date_filter .= " AND MONTH(A1.invoiceDate) = $month";
$cust_filter = $customer_id ? "AND A1.customerID = '$customer_id'" : '';

$omsetQuery = "
    SELECT YEAR(A1.invoiceDate) AS tahun, MONTH(A1.invoiceDate) AS bulan, ANY_VALUE(MONTHNAME(A1.invoiceDate)) AS nama_bulan,
           COUNT(DISTINCT A1.invoiceNumber) AS total_invoice,
           SUM(A2.productQuantity * A2.unitPrice) AS omset_sebelum_ppn,
           SUM(A2.productQuantity * A2.unitPrice + COALESCE(A2.tax, 0)) AS omset_termasuk_ppn
    FROM salesInvoice A1
    LEFT JOIN salesInvoiceItem A2 ON A1.invoiceNumber = A2.DONumber
    WHERE $date_filter $cust_filter
    GROUP BY YEAR(A1.invoiceDate), MONTH(A1.invoiceDate)
    ORDER BY tahun ASC, bulan ASC
";

$invoiceDetailQuery = "
    SELECT
        A1.invoiceNumber,
        A1.invoiceDate,
        YEAR(A1.invoiceDate)                                                     AS tahun,
        MONTH(A1.invoiceDate)                                                    AS bulan,
        A3.company_name                                                          AS customer_name,
        SUM(A2.productQuantity * A2.unitPrice)                                   AS total_sebelum_ppn,
        SUM(A2.productQuantity * A2.unitPrice + COALESCE(A2.tax, 0))             AS total_termasuk_ppn,
        COUNT(*)                                                                 AS total_item
    FROM salesInvoice A1
    LEFT JOIN salesInvoiceItem A2 ON A1.invoiceNumber = A2.DONumber
    LEFT JOIN customer A3 ON A1.customerID = A3.company_id
    WHERE $date_filter $cust_filter
    GROUP BY A1.invoiceNumber, A1.invoiceDate, A1.customerID, A3.company_name
    ORDER BY A1.invoiceDate ASC, A1.invoiceNumber ASC
";

$result       = mysqli_query($connect, $omsetQuery);
$detailResult = mysqli_query($connect, $invoiceDetailQuery);
$rows         = mysqli_fetch_all($result, MYSQLI_ASSOC);
$invRows      = mysqli_fetch_all($detailResult, MYSQLI_ASSOC);

// Group invoice details by year-month key
$invByMonth = [];
foreach ($invRows as $inv) {
    $key = $inv['tahun'] . '-' . $inv['bulan'];
    $invByMonth[$key][] = $inv;
}

$ss    = new Spreadsheet();
$sheet = $ss->getActiveSheet();
$sheet->setTitle('Omset Per Bulan');

// Title
$sheet->mergeCells('A1:F1');
$sheet->setCellValue('A1', "LAPORAN OMSET / PENJUALAN TAHUN $year");
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Header row
$headers = ['No. Invoice / Bulan', 'Tanggal / Tahun', 'Customer / Nama Bulan', 'Total Item / Invoice', 'Omset (Before PPN)', 'Omset (Incl. PPN)'];
$sheet->fromArray($headers, null, 'A3');
$sheet->getStyle('A3:F3')->getFont()->setBold(true);
$sheet->getStyle('A3:F3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A3:F3')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle('A3:F3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1F2');

// Style definitions
$monthFill   = 'FFD9E1F2'; // blue-grey for month summary rows
$invoiceFill = 'FFFAFAFA'; // near-white for invoice detail rows

$r = 4;
$grandPre = 0;
$grandInc = 0;

foreach ($rows as $row) {
    $key = $row['tahun'] . '-' . $row['bulan'];

    // Month summary row
    $sheet->setCellValue("A$r", $row['bulan']);
    $sheet->setCellValue("B$r", (int)$row['tahun']);
    $sheet->setCellValue("C$r", $row['nama_bulan']);
    $sheet->setCellValue("D$r", (int)$row['total_invoice']);
    $sheet->setCellValue("E$r", (float)$row['omset_sebelum_ppn']);
    $sheet->setCellValue("F$r", (float)$row['omset_termasuk_ppn']);
    $sheet->getStyle("A$r:F$r")->getFont()->setBold(true);
    $sheet->getStyle("E$r:F$r")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle("A$r:F$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("A$r:F$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($monthFill);

    $grandPre += (float)$row['omset_sebelum_ppn'];
    $grandInc += (float)$row['omset_termasuk_ppn'];
    $r++;

    // Invoice detail rows for this month
    if (!empty($invByMonth[$key])) {
        foreach ($invByMonth[$key] as $inv) {
            $sheet->setCellValue("A$r", $inv['invoiceNumber']);
            $sheet->setCellValue("B$r", $inv['invoiceDate']);
            $sheet->setCellValue("C$r", $inv['customer_name']);
            $sheet->setCellValue("D$r", (int)$inv['total_item']);
            $sheet->setCellValue("E$r", (float)$inv['total_sebelum_ppn']);
            $sheet->setCellValue("F$r", (float)$inv['total_termasuk_ppn']);
            $sheet->getStyle("A$r:C$r")->getAlignment()->setIndent(2);
            $sheet->getStyle("E$r:F$r")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("A$r:F$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("A$r:F$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($invoiceFill);
            $r++;
        }
    }
}

// Total row
$sheet->setCellValue("C$r", 'TOTAL');
$sheet->setCellValue("E$r", $grandPre);
$sheet->setCellValue("F$r", $grandInc);
$sheet->getStyle("A$r:F$r")->getFont()->setBold(true);
$sheet->getStyle("E$r:F$r")->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle("A$r:F$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

foreach (range('A', 'F') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$filename = "Omset_Penjualan_{$year}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');

$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
?>
