<?php
// Export Buku Kas ke Excel
// GET params: bank_account, start_date, end_date
require __DIR__ . '/../vendor/autoload.php';
require_once('../connection/connection.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$bank_account = isset($_GET['bank_account']) ? mysqli_real_escape_string($connect, $_GET['bank_account']) : '';
$start_date   = isset($_GET['start_date'])   ? mysqli_real_escape_string($connect, $_GET['start_date'])   : date('Y-m-01');
$end_date     = isset($_GET['end_date'])     ? mysqli_real_escape_string($connect, $_GET['end_date'])     : date('Y-m-d');
$accountcode  = isset($_GET['accountcode'])  ? mysqli_real_escape_string($connect, $_GET['accountcode'])  : '';

if (!empty($bank_account)) {
    $ba_ft = "A1.bank_account = '$bank_account'";
    $ba_fi = "A1.bank = '$bank_account'";
} else {
    $saldo_exclude = !empty($accountcode)
        ? "AND NOT (A1.memo = '__SALDO_AWAL__' AND A1.accountcode != '' AND A1.accountcode != '$accountcode')"
        : "";
    $ba_ft = "(A1.bank_account IS NULL OR A1.bank_account = '') $saldo_exclude";
    $ba_fi = "(A1.bank IS NULL OR A1.bank = '')";
}

const CAT_PENERIMAAN = '174c61e8-226d-11ef-a';
const CAT_PEMBAYARAN  = '1d604104-226d-11ef-a';

// Beginning balance — __SALDO_AWAL__ adjustment is included automatically in the SUM
$bbResult = mysqli_query($connect, "
    SELECT SUM(amount) AS balance FROM (
        SELECT CASE
                   WHEN A1.amount < 0 THEN A1.amount
                   WHEN A1.finance_category = '" . CAT_PENERIMAAN . "' THEN A1.amount
                   WHEN A1.finance_category = '" . CAT_PEMBAYARAN  . "' THEN -A1.amount
                   ELSE 0
               END AS amount
        FROM financeTransaction A1
        WHERE $ba_ft AND (DATE(A1.date) < '$start_date' OR (DATE(A1.date) = '$start_date' AND (A1.memo LIKE 'SALDO AWAL%' OR A1.memo = '__SALDO_AWAL__')))
        UNION ALL
        SELECT CASE
                   WHEN A1.supplier IS NOT NULL THEN -A1.paid_amount
                   WHEN A1.customer IS NOT NULL THEN A1.paid_amount
                   ELSE 0
               END AS amount
        FROM financeItem A1
        WHERE $ba_fi AND DATE(A1.paymentdate) <= '$start_date'
    ) AS balances
");
$beginningBalance = (float)(mysqli_fetch_assoc($bbResult)['balance'] ?? 0);

// Transaction rows — financeTransaction (exclude __SALDO_AWAL__ sentinel)
$result_one = mysqli_query($connect, "
    SELECT A1.chequeno, A1.date AS transaction_date,
           A2.account_name_alias AS description,
           A3.category_name,
           A1.finance_category,
           A1.amount AS paid_amount,
           NULL AS party_type
    FROM financeTransaction A1
    LEFT JOIN account_code A2 ON A1.accountcode = A2.code
    LEFT JOIN finance_category A3 ON A1.finance_category = A3.category_id
    WHERE $ba_ft
      AND DATE(A1.date) BETWEEN '$start_date' AND '$end_date'
      AND (A1.memo IS NULL OR (A1.memo NOT LIKE 'SALDO AWAL%' AND A1.memo != '__SALDO_AWAL__'))
");

// Transaction rows — financeItem (supplier / payable)
$result_two = mysqli_query($connect, "
    SELECT A1.chequeno, A1.paymentdate AS transaction_date,
           A2.supplier_name AS description,
           NULL AS category_name,
           NULL AS finance_category,
           A1.paid_amount,
           'supplier' AS party_type
    FROM financeItem A1
    LEFT JOIN supplier A2 ON A1.supplier = A2.supplier_id
    WHERE $ba_fi
      AND A1.supplier IS NOT NULL
      AND A1.paid_amount IS NOT NULL
      AND DATE(A1.paymentdate) > '$start_date' AND DATE(A1.paymentdate) <= '$end_date'
");

// Transaction rows — financeItem (customer / receivable)
$result_three = mysqli_query($connect, "
    SELECT A1.chequeno, A1.paymentdate AS transaction_date,
           A2.company_name AS description,
           NULL AS category_name,
           NULL AS finance_category,
           A1.paid_amount,
           'customer' AS party_type
    FROM financeItem A1
    LEFT JOIN customer A2 ON A1.customer = A2.company_id
    WHERE $ba_fi
      AND A1.customer IS NOT NULL
      AND A1.paid_amount IS NOT NULL
      AND DATE(A1.paymentdate) > '$start_date' AND DATE(A1.paymentdate) <= '$end_date'
");

$transactions = array_merge(
    mysqli_fetch_all($result_one,   MYSQLI_ASSOC),
    mysqli_fetch_all($result_two,   MYSQLI_ASSOC),
    mysqli_fetch_all($result_three, MYSQLI_ASSOC)
);

usort($transactions, fn($a, $b) => strtotime($a['transaction_date']) - strtotime($b['transaction_date']));

// Build spreadsheet
$ss    = new Spreadsheet();
$sheet = $ss->getActiveSheet();
$sheet->setTitle('Buku Kas');

// Title
$sheet->mergeCells('A1:G1');
$sheet->setCellValue('A1', 'BUKU KAS — Rekening: ' . $bank_account);
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A2:G2');
$sheet->setCellValue('A2', "Periode: $start_date s/d $end_date");
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Header
$headers = ['Tanggal', 'No. Cheque', 'Keterangan', 'Jenis', 'Debit', 'Kredit', 'Saldo'];
$sheet->fromArray($headers, null, 'A4');
$headerStyle = [
    'font'      => ['bold' => true],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9E1F2']],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
];
$sheet->getStyle('A4:G4')->applyFromArray($headerStyle);

$r = 5;

// Saldo Awal row
$sheet->setCellValue("C$r", 'Saldo Awal');
$sheet->setCellValue("G$r", $beginningBalance);
$sheet->getStyle("E$r:G$r")->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle("A$r:G$r")->getFont()->setItalic(true)->setBold(true);
$sheet->getStyle("A$r:G$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$r++;

$saldo = $beginningBalance;

foreach ($transactions as $row) {
    $debit  = 0.0;
    $credit = 0.0;
    $jenis  = '';

    if ($row['party_type'] === 'supplier') {
        $credit = (float)$row['paid_amount']; // money out → Kredit (pengeluaran)
        $jenis  = 'Pembayaran';
    } elseif ($row['party_type'] === 'customer') {
        $debit = (float)$row['paid_amount'];  // money in → Debit (pemasukan)
        $jenis = 'Penerimaan';
    } elseif ($row['finance_category'] === CAT_PENERIMAAN) {
        $debit = (float)$row['paid_amount'];  // income → Debit (pemasukan)
        $jenis = $row['category_name'] ?? 'Penerimaan';
    } elseif ($row['finance_category'] === CAT_PEMBAYARAN) {
        $credit = (float)$row['paid_amount']; // expense → Kredit (pengeluaran)
        $jenis  = $row['category_name'] ?? 'Pembayaran';
    } else {
        $jenis = $row['category_name'] ?? '';
    }

    $saldo += $debit - $credit;

    $sheet->setCellValue("A$r", $row['transaction_date']);
    $sheet->setCellValue("B$r", $row['chequeno']);
    $sheet->setCellValue("C$r", $row['description']);
    $sheet->setCellValue("D$r", $jenis);
    $sheet->setCellValue("E$r", $debit  > 0 ? $debit  : '');
    $sheet->setCellValue("F$r", $credit > 0 ? $credit : '');
    $sheet->setCellValue("G$r", $saldo);
    $sheet->getStyle("E$r:G$r")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle("A$r:G$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $r++;
}

// Saldo Akhir row
$sheet->setCellValue("C$r", 'Saldo Akhir');
$sheet->setCellValue("G$r", $saldo);
$sheet->getStyle("A$r:G$r")->getFont()->setBold(true);
$sheet->getStyle("E$r:G$r")->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle("A$r:G$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("A$r:G$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF2CC');

foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$filename = "Buku_Kas_{$bank_account}_{$start_date}_sd_{$end_date}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');
(new Xlsx($ss))->save('php://output');
exit;
?>
