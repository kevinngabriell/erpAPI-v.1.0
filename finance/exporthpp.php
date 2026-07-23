<?php
// Export HPP (Harga Pokok Penjualan) ke Excel
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

$avgCostQuery = "
    SELECT
        A2.POProductName          AS nama_produk,
        CASE WHEN SUM(A2.POQuantity) > 0
             THEN SUM(A2.POQuantity * A2.POUnitPrice) / SUM(A2.POQuantity)
             ELSE 0 END           AS harga_rata_rata
    FROM purchaseOrder A1
    LEFT JOIN purchaseOrderItem A2 ON A1.PONumber = A2.PONumber
    WHERE A2.POProductName IS NOT NULL
    GROUP BY A2.POProductName
";

$pembelianQuery = "
    SELECT
        A2.POProductName          AS nama_produk,
        SUM(A2.POQuantity)        AS qty_beli,
        SUM(A2.POQuantity * A2.POUnitPrice) AS nilai_pembelian
    FROM purchaseOrder A1
    LEFT JOIN purchaseOrderItem A2 ON A1.PONumber = A2.PONumber
    WHERE A1.PODate BETWEEN '$start_date' AND '$end_date'
      AND A2.POProductName IS NOT NULL
    GROUP BY A2.POProductName
    ORDER BY nilai_pembelian DESC
";

$stokAkhirQuery = "
    SELECT
        A2.productName            AS nama_produk,
        SUM(A1.endbalance)        AS stok_akhir
    FROM warehouse A1
    LEFT JOIN product A2 ON A1.product = A2.skuID
    WHERE A2.productName IS NOT NULL
    GROUP BY A2.productName
";

$penjualanQuery = "
    SELECT
        A2.productName,
        SUM(A2.productQuantity)                      AS qty_terjual,
        SUM(A2.productQuantity * A2.unitPrice)       AS nilai_penjualan
    FROM salesInvoice A1
    LEFT JOIN salesInvoiceItem A2 ON A1.invoiceNumber = A2.DONumber
    WHERE A1.invoiceDate BETWEEN '$start_date' AND '$end_date'
      AND A2.productName IS NOT NULL
    GROUP BY A2.productName
    ORDER BY nilai_penjualan DESC
";

$avgCostResult   = mysqli_query($connect, $avgCostQuery);
$pembelianResult = mysqli_query($connect, $pembelianQuery);
$stokAkhirResult = mysqli_query($connect, $stokAkhirQuery);
$penjualanResult = mysqli_query($connect, $penjualanQuery);

if (!$avgCostResult || !$pembelianResult || !$stokAkhirResult || !$penjualanResult) {
    http_response_code(500);
    echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
    exit;
}

$avgCostMap = [];
while ($row = mysqli_fetch_assoc($avgCostResult)) {
    $avgCostMap[$row['nama_produk']] = (float)$row['harga_rata_rata'];
}

$stokAkhirMap = [];
while ($row = mysqli_fetch_assoc($stokAkhirResult)) {
    $stokAkhirMap[$row['nama_produk']] = (float)$row['stok_akhir'];
}

$pembelian = mysqli_fetch_all($pembelianResult, MYSQLI_ASSOC);
$penjualan = mysqli_fetch_all($penjualanResult, MYSQLI_ASSOC);

$total_pembelian = array_sum(array_column($pembelian, 'nilai_pembelian'));

$hpp_per_produk = [];
$total_hpp = 0.0;
foreach ($penjualan as $item) {
    $nama_produk = $item['productName'];
    $qty_terjual = (float)$item['qty_terjual'];
    $harga_rata  = $avgCostMap[$nama_produk] ?? 0.0;
    $hpp_produk  = $qty_terjual * $harga_rata;
    $stok_akhir  = $stokAkhirMap[$nama_produk] ?? 0.0;
    $total_hpp  += $hpp_produk;

    $hpp_per_produk[] = [
        'nama_produk'      => $nama_produk,
        'qty_terjual'      => $qty_terjual,
        'harga_pokok_rata' => $harga_rata,
        'hpp'              => $hpp_produk,
        'nilai_penjualan'  => (float)$item['nilai_penjualan'],
        'gross_profit'     => (float)$item['nilai_penjualan'] - $hpp_produk,
        'stok_akhir'       => $stok_akhir
    ];
}

$total_nilai_stok_akhir = 0.0;
foreach ($stokAkhirMap as $produk => $qty) {
    $avg = $avgCostMap[$produk] ?? 0.0;
    $total_nilai_stok_akhir += $qty * $avg;
}

$total_nilai_penjualan = array_sum(array_column($penjualan, 'nilai_penjualan'));
$gross_profit          = $total_nilai_penjualan - $total_hpp;

$ss = new Spreadsheet();

// --- Sheet 1: HPP per Produk (with summary) ---
$sheet = $ss->getActiveSheet();
$sheet->setTitle('HPP per Produk');

$sheet->mergeCells('A1:G1');
$sheet->setCellValue('A1', 'LAPORAN HPP (HARGA POKOK PENJUALAN)');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A2:G2');
$sheet->setCellValue('A2', "Periode: $start_date s/d $end_date");
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$summaryRows = [
    ['Total Pembelian', $total_pembelian],
    ['Nilai Stok Akhir', $total_nilai_stok_akhir],
    ['Total HPP', $total_hpp],
    ['Nilai Penjualan', $total_nilai_penjualan],
    ['Gross Profit', $gross_profit],
];
$r = 4;
foreach ($summaryRows as $summary) {
    $sheet->setCellValue("A$r", $summary[0]);
    $sheet->mergeCells("B$r:C$r");
    $sheet->setCellValue("B$r", $summary[1]);
    $sheet->getStyle("B$r:C$r")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle("A$r")->getFont()->setBold(true);
    $r++;
}

$r += 1;
$headers = ['Nama Produk', 'Qty Terjual', 'Harga Pokok Rata-Rata', 'HPP', 'Nilai Penjualan', 'Gross Profit', 'Stok Akhir'];
$sheet->fromArray($headers, null, "A$r");
$sheet->getStyle("A$r:G$r")->getFont()->setBold(true);
$sheet->getStyle("A$r:G$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("A$r:G$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("A$r:G$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1F2');
$r++;

foreach ($hpp_per_produk as $item) {
    $sheet->setCellValue("A$r", $item['nama_produk']);
    $sheet->setCellValue("B$r", $item['qty_terjual']);
    $sheet->setCellValue("C$r", $item['harga_pokok_rata']);
    $sheet->setCellValue("D$r", $item['hpp']);
    $sheet->setCellValue("E$r", $item['nilai_penjualan']);
    $sheet->setCellValue("F$r", $item['gross_profit']);
    $sheet->setCellValue("G$r", $item['stok_akhir']);
    $sheet->getStyle("C$r:F$r")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle("A$r:G$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $r++;
}

foreach (range('A', 'G') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// --- Sheet 2: Pembelian Periode ---
$sheet2 = $ss->createSheet();
$sheet2->setTitle('Pembelian Periode');

$sheet2->mergeCells('A1:C1');
$sheet2->setCellValue('A1', 'PEMBELIAN PERIODE');
$sheet2->getStyle('A1')->getFont()->setBold(true)->setSize(13);
$sheet2->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet2->mergeCells('A2:C2');
$sheet2->setCellValue('A2', "Periode: $start_date s/d $end_date");
$sheet2->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$headers2 = ['Nama Produk', 'Qty Beli', 'Nilai Pembelian'];
$sheet2->fromArray($headers2, null, 'A4');
$sheet2->getStyle('A4:C4')->getFont()->setBold(true);
$sheet2->getStyle('A4:C4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet2->getStyle('A4:C4')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet2->getStyle('A4:C4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1F2');

$r2 = 5;
foreach ($pembelian as $item) {
    $sheet2->setCellValue("A$r2", $item['nama_produk']);
    $sheet2->setCellValue("B$r2", (float)$item['qty_beli']);
    $sheet2->setCellValue("C$r2", (float)$item['nilai_pembelian']);
    $sheet2->getStyle("C$r2")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet2->getStyle("A$r2:C$r2")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $r2++;
}

$sheet2->setCellValue("A$r2", 'TOTAL');
$sheet2->setCellValue("C$r2", $total_pembelian);
$sheet2->getStyle("A$r2:C$r2")->getFont()->setBold(true);
$sheet2->getStyle("C$r2")->getNumberFormat()->setFormatCode('#,##0.00');
$sheet2->getStyle("A$r2:C$r2")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

foreach (range('A', 'C') as $col) {
    $sheet2->getColumnDimension($col)->setAutoSize(true);
}

$ss->setActiveSheetIndex(0);

$filename = "HPP_{$start_date}_sd_{$end_date}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');
(new Xlsx($ss))->save('php://output');
exit;
?>
