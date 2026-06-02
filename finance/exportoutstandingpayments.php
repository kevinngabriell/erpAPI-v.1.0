<?php
// Export Outstanding Hutang & Piutang ke Excel
// GET params: type (piutang|hutang|all), month, year
require __DIR__ . '/../vendor/autoload.php';
require_once('../connection/connection.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$type  = isset($_GET['type'])  ? strtolower($_GET['type'])  : 'all';
$month = isset($_GET['month']) ? (int)$_GET['month']        : 0;
$year  = isset($_GET['year'])  ? (int)$_GET['year']         : 0;

$date_filter_piutang = '';
$date_filter_hutang  = '';
if ($year > 0) {
    $date_filter_piutang .= " AND YEAR(A2.invoiceDate) = $year";
    $date_filter_hutang  .= " AND YEAR(A2.invoiceDate) = $year";
}
if ($month > 0) {
    $date_filter_piutang .= " AND MONTH(A2.invoiceDate) = $month";
    $date_filter_hutang  .= " AND MONTH(A2.invoiceDate) = $month";
}

$headerStyle = [
    'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2F5496']],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
];

$totalStyle = [
    'font'    => ['bold' => true],
    'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF2CC']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
];

$overdueFill = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFCE4D6']];
$numFmt = '#,##0.00';

$ss = new Spreadsheet();
$sheetIndex = 0;

// ── PIUTANG USAHA ──────────────────────────────────────────────────────────────
if ($type === 'piutang' || $type === 'all') {
    $piutangQuery = "
        SELECT
            A1.invoice_number,
            A3.company_name                            AS nama_pelanggan,
            A3.company_id                              AS customer_id,
            A2.invoiceDate                             AS tanggal_invoice,
            A3.company_top                             AS term_of_payment,
            DATE_ADD(A2.invoiceDate, INTERVAL COALESCE(A3.company_top, 0) DAY) AS jatuh_tempo,
            DATEDIFF(CURDATE(), DATE_ADD(A2.invoiceDate, INTERVAL COALESCE(A3.company_top, 0) DAY))
                                                       AS hari_overdue,
            COALESCE(MAX(NULLIF(A4.Kurs, 0)), 1)      AS kurs,
            COALESCE(MAX(A5.currency_name), 'IDR')    AS currency,
            (MAX(A1.due_amount) - SUM(COALESCE(A1.paid_amount, 0)))
                * COALESCE(MAX(NULLIF(A4.Kurs, 0)), 1) AS sisa_tagihan,
            MAX(A1.due_amount)
                * COALESCE(MAX(NULLIF(A4.Kurs, 0)), 1) AS nilai_invoice,
            SUM(COALESCE(A1.paid_amount, 0))
                * COALESCE(MAX(NULLIF(A4.Kurs, 0)), 1) AS sudah_dibayar
        FROM financeItem A1
        LEFT JOIN salesInvoice  A2 ON A1.invoice_number = A2.invoiceNumber
        LEFT JOIN customer      A3 ON A2.customerID     = A3.company_id
        LEFT JOIN salesOrderItem A4 ON A2.salesOrder    = A4.salesOrderNumber
        LEFT JOIN currency      A5 ON A4.MataUang       = A5.currency_id
        WHERE A2.customerID IS NOT NULL
          $date_filter_piutang
        GROUP BY A1.invoice_number, A3.company_name, A3.company_id, A2.invoiceDate, A3.company_top
        HAVING (MAX(A1.due_amount) - SUM(COALESCE(A1.paid_amount, 0))) > 0
        ORDER BY jatuh_tempo ASC
    ";

    $result = mysqli_query($connect, $piutangQuery);
    if (!$result) {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
        exit;
    }

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);

    $sheet = ($sheetIndex === 0) ? $ss->getActiveSheet() : $ss->createSheet();
    $sheet->setTitle('Piutang Usaha');
    $sheetIndex++;

    // Title
    $sheet->mergeCells('A1:L1');
    $sheet->setCellValue('A1', 'LAPORAN PIUTANG USAHA (OUTSTANDING)');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $filterLabel = 'Per: ' . date('d/m/Y');
    if ($year > 0)  $filterLabel .= " | Tahun: $year";
    if ($month > 0) $filterLabel .= " | Bulan: $month";
    $sheet->mergeCells('A2:L2');
    $sheet->setCellValue('A2', $filterLabel);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Headers
    $headers = ['No', 'No. Invoice', 'Nama Pelanggan', 'Customer ID', 'Tgl. Invoice',
                'TOP (Hari)', 'Jatuh Tempo', 'Hari Overdue', 'Currency', 'Kurs',
                'Nilai Invoice (IDR)', 'Sudah Dibayar (IDR)', 'Sisa Tagihan (IDR)', 'Status'];
    $sheet->fromArray($headers, null, 'A4');
    $sheet->getStyle('A4:N4')->applyFromArray($headerStyle);

    $r = 5;
    $totalSisa = 0.0;

    foreach ($rows as $i => $row) {
        $sisa   = (float)$row['sisa_tagihan'];
        $nilai  = (float)$row['nilai_invoice'];
        $bayar  = (float)$row['sudah_dibayar'];
        $overdue = (int)$row['hari_overdue'];
        $status  = $overdue > 0 ? 'Overdue' : 'Belum Jatuh Tempo';
        $totalSisa += $sisa;

        $sheet->setCellValue("A$r", $i + 1);
        $sheet->setCellValue("B$r", $row['invoice_number']);
        $sheet->setCellValue("C$r", $row['nama_pelanggan']);
        $sheet->setCellValue("D$r", $row['customer_id']);
        $sheet->setCellValue("E$r", $row['tanggal_invoice']);
        $sheet->setCellValue("F$r", $row['term_of_payment']);
        $sheet->setCellValue("G$r", $row['jatuh_tempo']);
        $sheet->setCellValue("H$r", $overdue);
        $sheet->setCellValue("I$r", $row['currency']);
        $sheet->setCellValue("J$r", (float)$row['kurs']);
        $sheet->setCellValue("K$r", $nilai);
        $sheet->setCellValue("L$r", $bayar);
        $sheet->setCellValue("M$r", $sisa);
        $sheet->setCellValue("N$r", $status);

        $sheet->getStyle("J$r:M$r")->getNumberFormat()->setFormatCode($numFmt);
        $sheet->getStyle("A$r:N$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        if ($overdue > 0) {
            $sheet->getStyle("A$r:N$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFCE4D6');
        }
        $r++;
    }

    // Total row
    $sheet->mergeCells("A$r:L$r");
    $sheet->setCellValue("A$r", 'TOTAL PIUTANG');
    $sheet->setCellValue("M$r", $totalSisa);
    $sheet->getStyle("A$r:N$r")->applyFromArray($totalStyle);
    $sheet->getStyle("M$r")->getNumberFormat()->setFormatCode($numFmt);

    foreach (range('A', 'N') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

// ── HUTANG USAHA ───────────────────────────────────────────────────────────────
if ($type === 'hutang' || $type === 'all') {
    $hutangQuery = "
        SELECT
            A1.invoice_number,
            A3.supplier_name                           AS nama_supplier,
            A3.supplier_id,
            A2.invoiceDate                             AS tanggal_invoice,
            COALESCE(NULLIF(A2.kurs, 0), 1)           AS kurs,
            COALESCE(A5.currency_name, 'IDR')         AS currency,
            (MAX(A1.due_amount) - SUM(COALESCE(A1.paid_amount, 0)))
                * COALESCE(NULLIF(A2.kurs, 0), 1)     AS sisa_hutang,
            MAX(A1.due_amount)
                * COALESCE(NULLIF(A2.kurs, 0), 1)     AS nilai_invoice,
            SUM(COALESCE(A1.paid_amount, 0))
                * COALESCE(NULLIF(A2.kurs, 0), 1)     AS sudah_dibayar,
            DATEDIFF(CURDATE(), A2.invoiceDate)        AS hari_sejak_invoice
        FROM financeItem A1
        LEFT JOIN purchaseInvoice A2 ON A1.invoice_number = A2.invoiceNumber
        LEFT JOIN supplier        A3 ON A2.supplier       = A3.supplier_id
        LEFT JOIN purchaseOrder   A4 ON A2.PONumber       = A4.PONumber
        LEFT JOIN currency        A5 ON A4.POCurrency     = A5.currency_id
        WHERE A2.supplier IS NOT NULL
          $date_filter_hutang
        GROUP BY A1.invoice_number, A3.supplier_name, A3.supplier_id, A2.invoiceDate, A2.kurs, A5.currency_name
        HAVING (MAX(A1.due_amount) - SUM(COALESCE(A1.paid_amount, 0))) > 0
        ORDER BY A2.invoiceDate ASC
    ";

    $result = mysqli_query($connect, $hutangQuery);
    if (!$result) {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
        exit;
    }

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);

    $sheet = ($sheetIndex === 0) ? $ss->getActiveSheet() : $ss->createSheet();
    $sheet->setTitle('Hutang Usaha');
    $sheetIndex++;

    // Title
    $sheet->mergeCells('A1:K1');
    $sheet->setCellValue('A1', 'LAPORAN HUTANG USAHA (OUTSTANDING)');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $filterLabel = 'Per: ' . date('d/m/Y');
    if ($year > 0)  $filterLabel .= " | Tahun: $year";
    if ($month > 0) $filterLabel .= " | Bulan: $month";
    $sheet->mergeCells('A2:K2');
    $sheet->setCellValue('A2', $filterLabel);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Headers
    $headers = ['No', 'No. Invoice', 'Nama Supplier', 'Supplier ID', 'Tgl. Invoice',
                'Hari Sejak Invoice', 'Currency', 'Kurs',
                'Nilai Invoice (IDR)', 'Sudah Dibayar (IDR)', 'Sisa Hutang (IDR)'];
    $sheet->fromArray($headers, null, 'A4');
    $sheet->getStyle('A4:K4')->applyFromArray($headerStyle);

    $r = 5;
    $totalSisa = 0.0;

    foreach ($rows as $i => $row) {
        $sisa  = (float)$row['sisa_hutang'];
        $nilai = (float)$row['nilai_invoice'];
        $bayar = (float)$row['sudah_dibayar'];
        $totalSisa += $sisa;

        $sheet->setCellValue("A$r", $i + 1);
        $sheet->setCellValue("B$r", $row['invoice_number']);
        $sheet->setCellValue("C$r", $row['nama_supplier']);
        $sheet->setCellValue("D$r", $row['supplier_id']);
        $sheet->setCellValue("E$r", $row['tanggal_invoice']);
        $sheet->setCellValue("F$r", (int)$row['hari_sejak_invoice']);
        $sheet->setCellValue("G$r", $row['currency']);
        $sheet->setCellValue("H$r", (float)$row['kurs']);
        $sheet->setCellValue("I$r", $nilai);
        $sheet->setCellValue("J$r", $bayar);
        $sheet->setCellValue("K$r", $sisa);

        $sheet->getStyle("H$r:K$r")->getNumberFormat()->setFormatCode($numFmt);
        $sheet->getStyle("A$r:K$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $r++;
    }

    // Total row
    $sheet->mergeCells("A$r:J$r");
    $sheet->setCellValue("A$r", 'TOTAL HUTANG');
    $sheet->setCellValue("K$r", $totalSisa);
    $sheet->getStyle("A$r:K$r")->applyFromArray($totalStyle);
    $sheet->getStyle("K$r")->getNumberFormat()->setFormatCode($numFmt);

    foreach (range('A', 'K') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

if ($sheetIndex === 0) {
    http_response_code(400);
    echo json_encode(['StatusCode' => 400, 'Status' => 'No Data Found']);
    exit;
}

$ss->setActiveSheetIndex(0);

$suffix = '';
if ($year > 0)  $suffix .= "_$year";
if ($month > 0) $suffix .= sprintf('_%02d', $month);
$filename = "Outstanding_Payments{$suffix}.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');
(new Xlsx($ss))->save('php://output');
exit;
?>
