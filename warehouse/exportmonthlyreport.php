<?php
// Export Laporan Stok Bulanan ke Excel (untuk kebutuhan Laporan SPT)
// GET params: year, month
require __DIR__ . '/../vendor/autoload.php';
require_once('../connection/connection.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

const TRX_IN  = '452c5015-e80f-4e8a-8';
const TRX_OUT = '9cafab5b-d975-41e8-8';

$currentDateTime = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)$currentDateTime->format('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)$currentDateTime->format('n');
if ($month < 1 || $month > 12) $month = (int)$currentDateTime->format('n');

$month_names = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];

$beginningOfMonth = sprintf('%04d-%02d-01', $year, $month);
$endOfMonth        = date('Y-m-t', strtotime($beginningOfMonth));

$all_products_result = mysqli_query($connect, "
    SELECT w.product AS kodeProduk, p.productName AS namaProduk
    FROM warehouse w
    LEFT JOIN product p ON w.product = p.skuID
    GROUP BY w.product, p.productName
    ORDER BY p.productName ASC
");
$all_products = mysqli_fetch_all($all_products_result, MYSQLI_ASSOC);

$beginning_balance_result = mysqli_query($connect, "
    SELECT
        w.product AS kodeProduk,
        COALESCE(SUM(w.beginningbalance), 0) +
        COALESCE(SUM(CASE WHEN wt.transactionType = '" . TRX_IN . "' THEN wt.quantity * u.conversionFactor ELSE 0 END), 0) -
        COALESCE(SUM(CASE WHEN wt.transactionType = '" . TRX_OUT . "' THEN wt.quantity * u.conversionFactor ELSE 0 END), 0) AS BB
    FROM warehouse w
    LEFT JOIN warehouseTransaction wt ON w.product = wt.product AND wt.transactionDate < '$beginningOfMonth'
    LEFT JOIN unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
    WHERE wt.transactionDate < '$beginningOfMonth'
    GROUP BY w.product
");
$beginning_balances = [];
while ($row = mysqli_fetch_assoc($beginning_balance_result)) {
    $beginning_balances[$row['kodeProduk']] = (float)$row['BB'];
}

$barang_masuk_result = mysqli_query($connect, "
    SELECT product AS kodeProduk, SUM(quantity * u.conversionFactor) AS `In`
    FROM warehouseTransaction wt
    LEFT JOIN unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
    WHERE transactionType = '" . TRX_IN . "'
      AND transactionDate BETWEEN '$beginningOfMonth' AND '$endOfMonth'
    GROUP BY product
");
$barang_masuks = [];
while ($row = mysqli_fetch_assoc($barang_masuk_result)) {
    $barang_masuks[$row['kodeProduk']] = (float)$row['In'];
}

$barang_keluar_result = mysqli_query($connect, "
    SELECT product AS kodeProduk, SUM(quantity * u.conversionFactor) AS `Out`
    FROM warehouseTransaction wt
    LEFT JOIN unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
    WHERE transactionType = '" . TRX_OUT . "'
      AND transactionDate BETWEEN '$beginningOfMonth' AND '$endOfMonth'
    GROUP BY product
");
$barang_keluars = [];
while ($row = mysqli_fetch_assoc($barang_keluar_result)) {
    $barang_keluars[$row['kodeProduk']] = (float)$row['Out'];
}

$ss    = new Spreadsheet();
$sheet = $ss->getActiveSheet();
$sheet->setTitle('Stok Bulanan');

$sheet->mergeCells('A1:F1');
$sheet->setCellValue('A1', 'LAPORAN STOK BULANAN - ' . strtoupper($month_names[$month]) . ' ' . $year);
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A2:F2');
$sheet->setCellValue('A2', "Periode: $beginningOfMonth s/d $endOfMonth");
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$headers = ['Kode Produk', 'Nama Produk', 'Saldo Awal', 'Barang Masuk', 'Barang Keluar', 'Saldo Akhir'];
$sheet->fromArray($headers, null, 'A4');
$sheet->getStyle('A4:F4')->getFont()->setBold(true);
$sheet->getStyle('A4:F4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A4:F4')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle('A4:F4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1F2');

$r = 5;
$total_bb = 0; $total_in = 0; $total_out = 0; $total_akhir = 0;

foreach ($all_products as $product) {
    $kode = $product['kodeProduk'];
    $bb   = $beginning_balances[$kode] ?? 0.0;
    $in   = $barang_masuks[$kode]      ?? 0.0;
    $out  = $barang_keluars[$kode]     ?? 0.0;
    $akhir = $bb + $in - $out;

    $sheet->setCellValue("A$r", $kode);
    $sheet->setCellValue("B$r", $product['namaProduk']);
    $sheet->setCellValue("C$r", $bb);
    $sheet->setCellValue("D$r", $in);
    $sheet->setCellValue("E$r", $out);
    $sheet->setCellValue("F$r", $akhir);
    $sheet->getStyle("C$r:F$r")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle("A$r:F$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $total_bb += $bb; $total_in += $in; $total_out += $out; $total_akhir += $akhir;
    $r++;
}

$sheet->setCellValue("B$r", 'TOTAL');
$sheet->setCellValue("C$r", $total_bb);
$sheet->setCellValue("D$r", $total_in);
$sheet->setCellValue("E$r", $total_out);
$sheet->setCellValue("F$r", $total_akhir);
$sheet->getStyle("A$r:F$r")->getFont()->setBold(true);
$sheet->getStyle("C$r:F$r")->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle("A$r:F$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

foreach (range('A', 'F') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$filename = sprintf('Laporan_Stok_Bulanan_%04d_%02d.xlsx', $year, $month);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');
(new Xlsx($ss))->save('php://output');
exit;
?>
