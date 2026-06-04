<?php
require __DIR__ . '/../vendor/autoload.php';
require_once('../connection/connection.php'); 

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Get parameters from the request
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
$bank_account = isset($_GET['bank_account']) ? $_GET['bank_account'] : null;


if (!$bank_account || !$start_date || !$end_date) {
    http_response_code(400); // Bad Request
    echo json_encode(array('error' => 'Missing required parameters: bank_account, start_date, or end_date'));
    exit;
}

// Fetch data from your database
$query = "SELECT 
            A1.date AS transaction_date, 
            A1.chequeno, 
            A2.account_code_name_alias AS description, 
            CASE WHEN A1.finance_category = '174c61e8-226d-11ef-a' THEN A1.amount ELSE 0 END AS debit, 
            CASE WHEN A1.finance_category = '1d604104-226d-11ef-a' THEN -A1.amount ELSE 0 END AS credit 
          FROM financeTransaction A1
          LEFT JOIN account_code A2 ON A1.accountcode COLLATE utf8mb4_general_ci = A2.account_code
          WHERE A1.bank_account = '$bank_account'
          AND (A1.date BETWEEN '$start_date' AND '$end_date')
          UNION ALL
          SELECT 
            A1.paymentdate AS transaction_date, 
            A1.chequeno, 
            A2.supplier_name AS description, 
            0 AS debit, 
            A1.paid_amount AS credit 
          FROM financeItem A1
          LEFT JOIN supplier A2 ON A1.supplier = A2.supplier_id
          WHERE A1.bank = '$bank_account' 
          AND A1.supplier IS NOT NULL 
          AND A1.paid_amount IS NOT NULL 
          AND (A1.paymentdate BETWEEN '$start_date' AND '$end_date')
          ORDER BY transaction_date ASC"; // Ensure data is sorted by date
          


$result = mysqli_query($connect, $query);
if (!$result) {
    http_response_code(500); // Internal Server Error
    echo json_encode(array('error' => mysqli_error($connect)));
    exit;
}

// Create a new spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Add the title and subtitle
$sheet->mergeCells('A1:G1');
$sheet->setCellValue('A1', 'BUKU KAS PT. VENKEN INTERNATIONAL KIMIA');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

$sheet->mergeCells('A2:G2');
$sheet->setCellValue('A2', "Bank: $bank_account | Period: $start_date to $end_date");
$sheet->getStyle('A2')->getAlignment()->setHorizontal('center');

// Add column headers
$headers = ['Tanggal', 'No. Sumber', 'No. Cek', 'Keterangan', 'Pemasukan (Dr)', 'Pengeluaran (Cr)', 'Total'];
$sheet->fromArray($headers, null, 'A4');
$sheet->getStyle('A4:G4')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A4:G4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('92D050');
$sheet->getStyle('A4:G4')->getAlignment()->setHorizontal('center');

// Populate rows with data and calculate totals
$row = 5;
$total = 0; // Initialize running total
while ($data = mysqli_fetch_assoc($result)) {
    $debit = (float)$data['debit'];
    $credit = (float)$data['credit'];
    $total += $debit - $credit; // Update the running total

    $sheet->setCellValue("A$row", $data['transaction_date']);
    $sheet->setCellValue("B$row", $bank_account);
    $sheet->setCellValue("C$row", $data['chequeno']);
    $sheet->setCellValue("D$row", $data['description']);
    $sheet->setCellValue("E$row", number_format($debit, 2));
    $sheet->setCellValue("F$row", number_format($credit, 2));
    $sheet->setCellValue("G$row", number_format($total, 2)); // Add running total to the last column

    $row++;
}

// Adjust column widths
foreach (range('A', 'G') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}


// Set proper headers for file download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"finance_report_{$start_date}_to_{$end_date}.xlsx\"");
header('Cache-Control: max-age=0');

// Write the file to output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>
