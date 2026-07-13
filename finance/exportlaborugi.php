<?php
// Export Laporan Laba Rugi ke Excel
// GET params: start_date, end_date
require __DIR__ . '/../vendor/autoload.php';
require_once('../connection/connection.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$start_date = isset($_GET['start_date']) ? mysqli_real_escape_string($connect, $_GET['start_date']) : date('Y-m-01');
$end_date   = isset($_GET['end_date'])   ? mysqli_real_escape_string($connect, $_GET['end_date'])   : date('Y-m-d');

$penjualanQuery = "
    SELECT COALESCE(SUM(A2.productQuantity * A2.unitPrice), 0) AS total
    FROM salesInvoice A1
    LEFT JOIN salesInvoiceItem A2 ON A1.invoiceNumber = A2.DONumber
    WHERE A1.invoiceDate BETWEEN '$start_date' AND '$end_date'
";

$returDiskonQuery = "
    SELECT A2.account_code, A2.account_code_name_alias AS account_name, SUM(A1.amount) AS total
    FROM financeTransaction A1
    LEFT JOIN account_code A2 ON A1.accountcode = A2.account_code
    WHERE A1.date BETWEEN '$start_date' AND '$end_date'
      AND (
            A2.account_code_name LIKE '%retur%' OR A2.account_code_name LIKE '%diskon%'
            OR A2.account_code_name_alias LIKE '%retur%' OR A2.account_code_name_alias LIKE '%diskon%'
          )
    GROUP BY A2.account_code, A2.account_code_name_alias ORDER BY A2.account_code
";

$avgCostQuery = "
    SELECT A2.POProductName AS nama_produk,
        CASE WHEN SUM(A2.POQuantity) > 0
             THEN SUM(A2.POQuantity * A2.POUnitPrice) / SUM(A2.POQuantity)
             ELSE 0 END AS harga_rata_rata
    FROM purchaseOrder A1
    LEFT JOIN purchaseOrderItem A2 ON A1.PONumber = A2.PONumber
    WHERE A2.POProductName IS NOT NULL
    GROUP BY A2.POProductName
";

$barangTerjualQuery = "
    SELECT A2.productName, SUM(A2.productQuantity) AS qty_terjual
    FROM salesInvoice A1
    LEFT JOIN salesInvoiceItem A2 ON A1.invoiceNumber = A2.DONumber
    WHERE A1.invoiceDate BETWEEN '$start_date' AND '$end_date'
      AND A2.productName IS NOT NULL
    GROUP BY A2.productName
";

$biayaUsahaQuery = "
    SELECT A2.account_code, A2.account_code_name_alias AS account_name, SUM(A1.amount) AS total
    FROM financeTransaction A1
    LEFT JOIN account_code A2 ON A1.accountcode = A2.account_code
    WHERE A1.finance_category = '1d604104-226d-11ef-a' AND A1.date BETWEEN '$start_date' AND '$end_date'
      AND (A2.account_code LIKE '5%' OR A2.account_code LIKE '6%')
      AND A2.account_code_name NOT LIKE '%pajak%' AND A2.account_code_name NOT LIKE '%pph%'
      AND A2.account_code_name_alias NOT LIKE '%pajak%' AND A2.account_code_name_alias NOT LIKE '%pph%'
    GROUP BY A2.account_code, A2.account_code_name_alias ORDER BY A2.account_code
";

$pajakQuery = "
    SELECT A2.account_code, A2.account_code_name_alias AS account_name, SUM(A1.amount) AS total
    FROM financeTransaction A1
    LEFT JOIN account_code A2 ON A1.accountcode = A2.account_code
    WHERE A1.date BETWEEN '$start_date' AND '$end_date'
      AND (
            A2.account_code_name LIKE '%pajak%' OR A2.account_code_name LIKE '%pph%'
            OR A2.account_code_name_alias LIKE '%pajak%' OR A2.account_code_name_alias LIKE '%pph%'
          )
    GROUP BY A2.account_code, A2.account_code_name_alias ORDER BY A2.account_code
";

$penjualan_row = mysqli_fetch_assoc(mysqli_query($connect, $penjualanQuery));
$penjualan     = (float)$penjualan_row['total'];

$retur_diskon_jurnal = mysqli_fetch_all(mysqli_query($connect, $returDiskonQuery), MYSQLI_ASSOC);
$total_retur_diskon  = array_sum(array_column($retur_diskon_jurnal, 'total'));
$total_pendapatan    = $penjualan - $total_retur_diskon;

$avgCostMap = [];
$avgCostResult = mysqli_query($connect, $avgCostQuery);
while ($row = mysqli_fetch_assoc($avgCostResult)) {
    $avgCostMap[$row['nama_produk']] = (float)$row['harga_rata_rata'];
}

$total_hpp = 0.0;
$barangTerjualResult = mysqli_query($connect, $barangTerjualQuery);
while ($row = mysqli_fetch_assoc($barangTerjualResult)) {
    $harga_rata = $avgCostMap[$row['productName']] ?? 0.0;
    $total_hpp += (float)$row['qty_terjual'] * $harga_rata;
}

$laba_rugi_kotor = $total_pendapatan - $total_hpp;

$biaya_usaha_jurnal = mysqli_fetch_all(mysqli_query($connect, $biayaUsahaQuery), MYSQLI_ASSOC);
$total_biaya_usaha  = array_sum(array_column($biaya_usaha_jurnal, 'total'));
$laba_sebelum_pajak = $laba_rugi_kotor - $total_biaya_usaha;

$pajak_jurnal = mysqli_fetch_all(mysqli_query($connect, $pajakQuery), MYSQLI_ASSOC);
$total_pajak  = array_sum(array_column($pajak_jurnal, 'total'));
$laba_bersih_setelah_pajak = $laba_sebelum_pajak - $total_pajak;

$ss    = new Spreadsheet();
$sheet = $ss->getActiveSheet();
$sheet->setTitle('Laba Rugi');

$boldStyle = ['font' => ['bold' => true]];
$numFormat = '#,##0.00';

$sheet->mergeCells('A1:C1');
$sheet->setCellValue('A1', 'LAPORAN LABA RUGI');
$sheet->getStyle('A1')->applyFromArray(array_merge($boldStyle, ['font' => ['bold' => true, 'size' => 14]]));
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A2:C2');
$sheet->setCellValue('A2', "Periode: $start_date s/d $end_date");
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$r = 4;

$sheet->setCellValue("A$r", 'PENDAPATAN');
$sheet->getStyle("A$r")->getFont()->setBold(true);
$sheet->getStyle("A$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1F2');
$r++;

$sheet->setCellValue("B$r", 'Penjualan');
$sheet->setCellValue("C$r", $penjualan);
$sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFormat);
$r++;

foreach ($retur_diskon_jurnal as $row) {
    $sheet->setCellValue("A$r", $row['account_code']);
    $sheet->setCellValue("B$r", '(-) ' . $row['account_name']);
    $sheet->setCellValue("C$r", -(float)$row['total']);
    $sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFormat);
    $r++;
}

$sheet->setCellValue("B$r", 'TOTAL PENDAPATAN');
$sheet->setCellValue("C$r", $total_pendapatan);
$sheet->getStyle("A$r:C$r")->getFont()->setBold(true);
$sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFormat);
$r += 2;

$sheet->setCellValue("B$r", 'HARGA POKOK PENJUALAN (HPP)');
$sheet->setCellValue("C$r", $total_hpp);
$sheet->getStyle("A$r:C$r")->getFont()->setBold(true);
$sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFormat);
$r++;

$sheet->setCellValue("B$r", 'LABA RUGI KOTOR');
$sheet->setCellValue("C$r", $laba_rugi_kotor);
$sheet->getStyle("A$r:C$r")->getFont()->setBold(true);
$sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFormat);
$r += 2;

$sheet->setCellValue("A$r", 'BIAYA / BEBAN USAHA');
$sheet->getStyle("A$r")->getFont()->setBold(true);
$sheet->getStyle("A$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF2CC');
$r++;

foreach ($biaya_usaha_jurnal as $row) {
    $sheet->setCellValue("A$r", $row['account_code']);
    $sheet->setCellValue("B$r", $row['account_name']);
    $sheet->setCellValue("C$r", (float)$row['total']);
    $sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFormat);
    $r++;
}

$sheet->setCellValue("B$r", 'TOTAL BIAYA USAHA');
$sheet->setCellValue("C$r", $total_biaya_usaha);
$sheet->getStyle("A$r:C$r")->getFont()->setBold(true);
$sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFormat);
$r += 2;

$sheet->setCellValue("B$r", 'LABA SEBELUM PAJAK');
$sheet->setCellValue("C$r", $laba_sebelum_pajak);
$sheet->getStyle("A$r:C$r")->getFont()->setBold(true);
$sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFormat);
$r += 2;

$sheet->setCellValue("A$r", 'PAJAK PENGHASILAN');
$sheet->getStyle("A$r")->getFont()->setBold(true);
$sheet->getStyle("A$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF2CC');
$r++;

foreach ($pajak_jurnal as $row) {
    $sheet->setCellValue("A$r", $row['account_code']);
    $sheet->setCellValue("B$r", $row['account_name']);
    $sheet->setCellValue("C$r", (float)$row['total']);
    $sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFormat);
    $r++;
}

$sheet->setCellValue("B$r", 'TOTAL PAJAK PENGHASILAN');
$sheet->setCellValue("C$r", $total_pajak);
$sheet->getStyle("A$r:C$r")->getFont()->setBold(true);
$sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFormat);
$r += 2;

$sheet->setCellValue("B$r", 'LABA BERSIH SETELAH PAJAK');
$sheet->setCellValue("C$r", $laba_bersih_setelah_pajak);
$sheet->getStyle("A$r:C$r")->getFont()->setBold(true)->setSize(12);
$sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFormat);
$color = $laba_bersih_setelah_pajak >= 0 ? 'FFE2EFDA' : 'FFFCE4D6';
$sheet->getStyle("A$r:C$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);

foreach (['A', 'B', 'C'] as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$filename = "Laporan_Laba_Rugi_{$start_date}_sd_{$end_date}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');
(new Xlsx($ss))->save('php://output');
exit;
?>
