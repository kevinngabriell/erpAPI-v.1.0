<?php
// Export Buku Besar (General Ledger) ke Excel
// GET params: start_date, end_date, account_code (optional)
require __DIR__ . '/../vendor/autoload.php';
require_once('../connection/connection.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$start_date          = isset($_GET['start_date'])   ? mysqli_real_escape_string($connect, $_GET['start_date'])   : date('Y-m-01');
$end_date            = isset($_GET['end_date'])      ? mysqli_real_escape_string($connect, $_GET['end_date'])     : date('Y-m-d');
$account_code_filter = isset($_GET['account_code']) ? mysqli_real_escape_string($connect, $_GET['account_code']) : '';

$account_filter = $account_code_filter ? "AND A1.accountcode = '$account_code_filter'" : '';

const CAT_PENERIMAAN = '174c61e8-226d-11ef-a';
const CAT_PEMBAYARAN  = '1d604104-226d-11ef-a';

$query = "
    SELECT A2.account_code, A2.account_code_name, A2.account_code_name_alias, A1.date, A1.voucher_no, A1.memo,
           A1.finance_category, A1.amount
    FROM financeTransaction A1
    LEFT JOIN account_code A2 ON A1.accountcode   = A2.account_code
    WHERE DATE(A1.date) BETWEEN '$start_date' AND '$end_date'
      AND (A1.memo IS NULL OR A1.memo != '__SALDO_AWAL__')
      $account_filter
    ORDER BY A2.account_code, A1.date ASC
";
$result       = mysqli_query($connect, $query);
$transactions = mysqli_fetch_all($result, MYSQLI_ASSOC);

// Group by account code
$grouped = [];
foreach ($transactions as $row) {
    $code = $row['account_code'];
    if (!isset($grouped[$code])) {
        // Opening balance — __SALDO_AWAL__ adjustment is included automatically in the SUM
        $ob = mysqli_fetch_assoc(mysqli_query($connect,
            "SELECT SUM(IF(finance_category = '" . CAT_PENERIMAAN . "', amount, -amount)) AS ob
             FROM financeTransaction
             WHERE accountcode = '$code' AND DATE(date) < '$start_date'"
        ));

        $grouped[$code] = [
            'account_name'  => $row['account_code_name'],
            'alias'         => $row['account_code_name_alias'],
            'opening'       => (float)($ob['ob'] ?? 0),
            'rows'          => []
        ];
    }
    $grouped[$code]['rows'][] = $row;
}

$ss    = new Spreadsheet();
$sheet = $ss->getActiveSheet();
$sheet->setTitle('Buku Besar');

$sheet->mergeCells('A1:F1');
$sheet->setCellValue('A1', 'BUKU BESAR (GENERAL LEDGER)');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A2:F2');
$sheet->setCellValue('A2', "Periode: $start_date s/d $end_date");
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$r = 4;

if (empty($grouped)) {
    $sheet->setCellValue("A$r", 'Tidak ada data untuk periode yang dipilih.');
}

foreach ($grouped as $code => $acct) {
    // Account section header
    $sheet->mergeCells("A{$r}:F{$r}");
    $sheet->setCellValue("A$r", "AKUN: $code - " . $acct['account_name'] . ' (' . $acct['alias'] . ')');
    $sheet->getStyle("A$r")->getFont()->setBold(true)->setSize(11);
    $sheet->getStyle("A{$r}:F{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFBFBFBF');
    $r++;

    $headers = ['Tanggal', 'Voucher No', 'Keterangan', 'Debit', 'Kredit', 'Saldo'];
    $sheet->fromArray($headers, null, "A$r");
    $sheet->getStyle("A{$r}:F{$r}")->getFont()->setBold(true);
    $sheet->getStyle("A{$r}:F{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("A{$r}:F{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1F2');
    $sheet->getStyle("A{$r}:F{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $r++;

    // Opening balance row
    $sheet->setCellValue("C$r", 'Saldo Awal');
    $sheet->setCellValue("F$r", $acct['opening']);
    $sheet->getStyle("D$r:F$r")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle("A$r:F$r")->getFont()->setItalic(true);
    $r++;

    $saldo = $acct['opening'];
    foreach ($acct['rows'] as $row) {
        $debit  = $row['finance_category'] === CAT_PENERIMAAN ? (float)$row['amount'] : 0.0;
        $credit = $row['finance_category'] === CAT_PEMBAYARAN  ? (float)$row['amount'] : 0.0;
        $saldo += $debit - $credit;

        $sheet->setCellValue("A$r", $row['date']);
        $sheet->setCellValue("B$r", $row['voucher_no']);
        $sheet->setCellValue("C$r", $row['memo']);
        $sheet->setCellValue("D$r", $debit > 0 ? $debit : '');
        $sheet->setCellValue("E$r", $credit > 0 ? $credit : '');
        $sheet->setCellValue("F$r", $saldo);
        $sheet->getStyle("D$r:F$r")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("A$r:F$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $r++;
    }

    // Closing balance
    $sheet->setCellValue("C$r", 'Saldo Akhir');
    $sheet->setCellValue("F$r", $saldo);
    $sheet->getStyle("A$r:F$r")->getFont()->setBold(true);
    $sheet->getStyle("D$r:F$r")->getNumberFormat()->setFormatCode('#,##0.00');
    $r++;

    // Blank separator row between accounts
    $r++;
}

foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$filename = "Buku_Besar_{$start_date}_sd_{$end_date}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');
(new Xlsx($ss))->save('php://output');
exit;
?>
