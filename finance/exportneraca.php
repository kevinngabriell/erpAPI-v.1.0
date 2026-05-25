<?php
// Export Neraca (Balance Sheet) ke Excel
// GET params: as_of_date
require __DIR__ . '/../vendor/autoload.php';
require_once('../connection/connection.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$as_of_date = isset($_GET['as_of_date']) ? mysqli_real_escape_string($connect, $_GET['as_of_date']) : date('Y-m-d');

$kasQuery = "
    SELECT bank_account AS akun, SUM(balance) AS saldo FROM (
        SELECT bank_account, SUM(CASE WHEN finance_category='174c61e8-226d-11ef-a' THEN amount ELSE -amount END) AS balance
        FROM financeTransaction WHERE date <= '$as_of_date' GROUP BY bank_account
        UNION ALL
        SELECT bank AS bank_account, SUM(CASE WHEN customer IS NOT NULL THEN paid_amount WHEN supplier IS NOT NULL THEN -paid_amount ELSE 0 END) AS balance
        FROM financeItem WHERE paid_amount IS NOT NULL AND paymentdate <= '$as_of_date' GROUP BY bank
    ) AS c GROUP BY bank_account
";
$piutangQuery = "
    SELECT A3.company_name AS nama, SUM(A1.due_amount) AS outstanding
    FROM financeItem A1 LEFT JOIN salesInvoice A2 ON A1.invoice_number=A2.invoiceNumber LEFT JOIN customer A3 ON A2.customerID=A3.company_id
    WHERE A2.customerID IS NOT NULL AND A1.due_amount>0 AND DATE(A1.insert_dt)<='$as_of_date' GROUP BY A3.company_name
";
$hutangQuery = "
    SELECT A3.supplier_name AS nama, SUM(A1.due_amount) AS outstanding
    FROM financeItem A1 LEFT JOIN purchaseInvoice A2 ON A1.invoice_number=A2.invoiceNumber LEFT JOIN supplier A3 ON A2.supplier=A3.supplier_id
    WHERE A2.supplier IS NOT NULL AND A1.due_amount>0 AND DATE(A1.insert_dt)<='$as_of_date' GROUP BY A3.supplier_name
";
$modalQuery = "
    SELECT SUM(CASE WHEN finance_category='174c61e8-226d-11ef-a' THEN amount ELSE 0 END) -
           SUM(CASE WHEN finance_category='1d604104-226d-11ef-a' THEN amount ELSE 0 END) AS laba_ditahan
    FROM financeTransaction WHERE date <= '$as_of_date'
";

$kas_data     = mysqli_fetch_all(mysqli_query($connect, $kasQuery), MYSQLI_ASSOC);
$piutang_data = mysqli_fetch_all(mysqli_query($connect, $piutangQuery), MYSQLI_ASSOC);
$hutang_data  = mysqli_fetch_all(mysqli_query($connect, $hutangQuery), MYSQLI_ASSOC);
$modal_row    = mysqli_fetch_assoc(mysqli_query($connect, $modalQuery));

$total_kas     = array_sum(array_column($kas_data, 'saldo'));
$total_piutang = array_sum(array_column($piutang_data, 'outstanding'));
$total_hutang  = array_sum(array_column($hutang_data, 'outstanding'));
$laba_ditahan  = (float)($modal_row['laba_ditahan'] ?? 0);
$total_aset    = $total_kas + $total_piutang;
$total_modal   = $laba_ditahan;
$total_kwj_modal = $total_hutang + $total_modal;

$ss    = new Spreadsheet();
$sheet = $ss->getActiveSheet();
$sheet->setTitle('Neraca');

$numFmt = '#,##0.00';
$boldSz = function($size = 11) { return ['font' => ['bold' => true, 'size' => $size]]; };
$sectionFill = function($color) use ($sheet) {
    return $sheet->getStyle($color)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor();
};

$sheet->mergeCells('A1:C1');
$sheet->setCellValue('A1', 'NERACA (BALANCE SHEET)');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->mergeCells('A2:C2');
$sheet->setCellValue('A2', "Per Tanggal: $as_of_date");
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$r = 4;
$writeSection = function($title, $bgColor) use ($sheet, &$r) {
    $sheet->setCellValue("A$r", $title);
    $sheet->getStyle("A$r:C$r")->getFont()->setBold(true);
    $sheet->getStyle("A$r:C$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bgColor);
    $r++;
};
$writeRow = function($label, $value) use ($sheet, &$r, $numFmt) {
    $sheet->setCellValue("B$r", $label);
    $sheet->setCellValue("C$r", (float)$value);
    $sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFmt);
    $r++;
};
$writeTotalRow = function($label, $value) use ($sheet, &$r, $numFmt) {
    $sheet->setCellValue("B$r", $label);
    $sheet->setCellValue("C$r", (float)$value);
    $sheet->getStyle("A$r:C$r")->getFont()->setBold(true);
    $sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFmt);
    $r += 2;
};

// ASET
$writeSection('ASET', 'FFD9E1F2');
$writeSection('  Kas & Bank', 'FFEAF0FB');
foreach ($kas_data as $row) $writeRow('    ' . $row['akun'], $row['saldo']);
$writeRow('  Total Kas & Bank', $total_kas);
$writeSection('  Piutang Usaha', 'FFEAF0FB');
foreach ($piutang_data as $row) $writeRow('    ' . $row['nama'], $row['outstanding']);
$writeRow('  Total Piutang Usaha', $total_piutang);
$writeTotalRow('TOTAL ASET', $total_aset);

// KEWAJIBAN
$writeSection('KEWAJIBAN', 'FFFFF2CC');
$writeSection('  Hutang Usaha', 'FFFDF2D0');
foreach ($hutang_data as $row) $writeRow('    ' . $row['nama'], $row['outstanding']);
$writeTotalRow('TOTAL KEWAJIBAN', $total_hutang);

// MODAL
$writeSection('MODAL', 'FFE2EFDA');
$writeRow('  Laba Ditahan', $laba_ditahan);
$writeTotalRow('TOTAL MODAL', $total_modal);

// TOTAL KEWAJIBAN + MODAL
$sheet->setCellValue("B$r", 'TOTAL KEWAJIBAN & MODAL');
$sheet->setCellValue("C$r", $total_kwj_modal);
$sheet->getStyle("A$r:C$r")->getFont()->setBold(true)->setSize(12);
$sheet->getStyle("C$r")->getNumberFormat()->setFormatCode($numFmt);

foreach (['A', 'B', 'C'] as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

$filename = "Neraca_{$as_of_date}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');
(new Xlsx($ss))->save('php://output');
exit;
?>
