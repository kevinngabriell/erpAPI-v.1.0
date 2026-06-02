<?php
// Export Laporan Laba Rugi ke Excel
// GET params: start_date, end_date
require __DIR__ . '/../vendor/autoload.php';
require_once('../connection/connection.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$start_date = isset($_GET['start_date']) ? mysqli_real_escape_string($connect, $_GET['start_date']) : date('Y-m-01');
$end_date   = isset($_GET['end_date'])   ? mysqli_real_escape_string($connect, $_GET['end_date'])   : date('Y-m-d');

$revenueQuery = "
    SELECT A2.account_code, A2.account_code_name_alias AS account_name, SUM(A1.amount) AS total
    FROM financeTransaction A1 LEFT JOIN account_code A2 ON A1.accountcode COLLATE utf8mb4_general_ci = A2.account_code
    WHERE A1.finance_category = '174c61e8-226d-11ef-a' AND A1.date BETWEEN '$start_date' AND '$end_date'
    GROUP BY A2.code, A2.account_name_alias ORDER BY A2.code
";
$expenseQuery = "
    SELECT A2.account_code, A2.account_code_name_alias AS account_name, SUM(A1.amount) AS total
    FROM financeTransaction A1 LEFT JOIN account_code A2 ON A1.accountcode COLLATE utf8mb4_general_ci = A2.account_code
    WHERE A1.finance_category = '1d604104-226d-11ef-a' AND A1.date BETWEEN '$start_date' AND '$end_date'
    GROUP BY A2.code, A2.account_name_alias ORDER BY A2.code
";
$salesReceiptQuery  = "SELECT COALESCE(SUM(paid_amount),0) AS total FROM financeItem WHERE customer IS NOT NULL AND paid_amount IS NOT NULL AND paymentdate BETWEEN '$start_date' AND '$end_date'";
$purchasePayQuery   = "SELECT COALESCE(SUM(paid_amount),0) AS total FROM financeItem WHERE supplier IS NOT NULL AND paid_amount IS NOT NULL AND paymentdate BETWEEN '$start_date' AND '$end_date'";

$revenues      = mysqli_fetch_all(mysqli_query($connect, $revenueQuery), MYSQLI_ASSOC);
$expenses      = mysqli_fetch_all(mysqli_query($connect, $expenseQuery), MYSQLI_ASSOC);
$sales_receipt = (float)mysqli_fetch_assoc(mysqli_query($connect, $salesReceiptQuery))['total'];
$purchase_pay  = (float)mysqli_fetch_assoc(mysqli_query($connect, $purchasePayQuery))['total'];

$total_pendapatan = array_sum(array_column($revenues, 'total')) + $sales_receipt;
$total_beban      = array_sum(array_column($expenses, 'total')) + $purchase_pay;
$laba_bersih      = $total_pendapatan - $total_beban;

$ss    = new Spreadsheet();
$sheet = $ss->getActiveSheet();
$sheet->setTitle('Laba Rugi');

$boldStyle   = ['font' => ['bold' => true]];
$centerStyle = ['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]];
$numFormat   = '#,##0.00';

$sheet->mergeCells('A1:C1');
$sheet->setCellValue('A1', 'LAPORAN LABA RUGI');
$sheet->getStyle('A1')->applyFromArray(array_merge($boldStyle, ['font' => ['bold' => true, 'size' => 14]]));
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A2:C2');
$sheet->setCellValue('A2', "Periode: $start_date s/d $end_date");
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$r = 4;
// PENDAPATAN SECTION
$sheet->setCellValue("A$r", 'PENDAPATAN');
$sheet->getStyle("A$r")->getFont()->setBold(true);
$sheet->getStyle("A$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1F2');
$r++;

foreach ($revenues as $row) {
    $sheet->setCellValue("A$r", $row['account_code']);
    $sheet->setCellValue("B$r", $row['account_code_name']);
    $sheet->setCellValue("C$r", (float)$row['total']);
    $sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFormat);
    $r++;
}
if ($sales_receipt > 0) {
    $sheet->setCellValue("B$r", 'Penerimaan Invoice Penjualan');
    $sheet->setCellValue("C$r", $sales_receipt);
    $sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFormat);
    $r++;
}
$sheet->setCellValue("B$r", 'TOTAL PENDAPATAN');
$sheet->setCellValue("C$r", $total_pendapatan);
$sheet->getStyle("A$r:C$r")->getFont()->setBold(true);
$sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFormat);
$r += 2;

// BEBAN SECTION
$sheet->setCellValue("A$r", 'BEBAN / PENGELUARAN');
$sheet->getStyle("A$r")->getFont()->setBold(true);
$sheet->getStyle("A$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF2CC');
$r++;

foreach ($expenses as $row) {
    $sheet->setCellValue("A$r", $row['account_code']);
    $sheet->setCellValue("B$r", $row['account_code_name']);
    $sheet->setCellValue("C$r", (float)$row['total']);
    $sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFormat);
    $r++;
}
if ($purchase_pay > 0) {
    $sheet->setCellValue("B$r", 'Pembayaran Invoice Pembelian');
    $sheet->setCellValue("C$r", $purchase_pay);
    $sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFormat);
    $r++;
}
$sheet->setCellValue("B$r", 'TOTAL BEBAN');
$sheet->setCellValue("C$r", $total_beban);
$sheet->getStyle("A$r:C$r")->getFont()->setBold(true);
$sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFormat);
$r += 2;

// LABA BERSIH
$sheet->setCellValue("B$r", 'LABA BERSIH');
$sheet->setCellValue("C$r", $laba_bersih);
$sheet->getStyle("A$r:C$r")->getFont()->setBold(true)->setSize(12);
$sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFormat);
$color = $laba_bersih >= 0 ? 'FFE2EFDA' : 'FFFCE4D6';
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
