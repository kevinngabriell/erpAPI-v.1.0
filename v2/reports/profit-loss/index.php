<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/report_dates.php';
require_once __DIR__ . '/../../helpers/excel_export.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

function fetchAccountBreakdown($conn, $company_id, $account_type, $date_from, $date_to) {
    $result = mysqli_query($conn, "SELECT ac.id AS account_code_id, ac.account_code, ac.account_code_name,
            COALESCE(SUM(combined.amount), 0) AS total
        FROM " . APP_SCHEMA . ".account_code ac
        LEFT JOIN (
            SELECT ftd.account_code_id, ftd.amount, ft.transaction_date
            FROM " . APP_SCHEMA . ".finance_transaction_detail ftd
            JOIN " . APP_SCHEMA . ".finance_transaction ft ON ft.id = ftd.finance_transaction_id AND ft.deleted_at IS NULL
            WHERE ftd.deleted_at IS NULL
            UNION ALL
            SELECT gjd.account_code_id, gjd.amount, gj.transaction_date
            FROM " . APP_SCHEMA . ".general_journal_detail gjd
            JOIN " . APP_SCHEMA . ".general_journal gj ON gj.id = gjd.general_journal_id AND gj.deleted_at IS NULL
            WHERE gjd.deleted_at IS NULL
        ) combined ON combined.account_code_id = ac.id AND combined.transaction_date BETWEEN '$date_from' AND '$date_to'
        WHERE ac.company_id = '$company_id' AND ac.deleted_at IS NULL AND ac.account_type = '$account_type'
        GROUP BY ac.id, ac.account_code, ac.account_code_name
        HAVING total != 0
        ORDER BY ac.account_code ASC");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['total'] = (float)$row['total'];
    return $rows;
}

function getProfitLossReport($conn, $company_id, $params) {
    [$date_from, $date_to] = resolveReportDateRange($params);
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to   = mysqli_real_escape_string($conn, $date_to);

    $revenue_by_account = fetchAccountBreakdown($conn, $company_id, 'revenue', $date_from, $date_to);
    $expense_by_account = fetchAccountBreakdown($conn, $company_id, 'expense', $date_from, $date_to);

    $revenue_total = array_sum(array_column($revenue_by_account, 'total'));
    $expense_total = array_sum(array_column($expense_by_account, 'total'));

    jsonResponse(200, 'Profit and loss report found', [
        'date_from'  => $date_from,
        'date_to'    => $date_to,
        'revenue'    => ['total' => $revenue_total, 'by_account' => $revenue_by_account],
        'expense'    => ['total' => $expense_total, 'by_account' => $expense_by_account],
        'net_profit' => $revenue_total - $expense_total,
    ]);
}

function writeAccountBreakdownSection($sheet, $row, $title, $accounts, $total) {
    $sheet->setCellValue("A$row", $title);
    $sheet->getStyle("A$row")->getFont()->setBold(true);
    $row++;

    $sheet->fromArray(['Kode Akun', 'Nama Akun', 'Jumlah'], null, "A$row");
    $sheet->getStyle("A$row:C$row")->getFont()->setBold(true);
    $sheet->getStyle("A$row:C$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A$row:C$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;

    foreach ($accounts as $account) {
        $sheet->setCellValue("A$row", $account['account_code']);
        $sheet->setCellValue("B$row", $account['account_code_name']);
        $sheet->setCellValue("C$row", number_format($account['total'], 2));
        $sheet->getStyle("A$row:C$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $row++;
    }

    $sheet->setCellValue("B$row", 'TOTAL ' . $title);
    $sheet->setCellValue("C$row", number_format($total, 2));
    $sheet->getStyle("B$row:C$row")->getFont()->setBold(true);
    $sheet->getStyle("A$row:C$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;

    return $row;
}

function exportProfitLossReport($conn, $company_id, $params) {
    [$date_from, $date_to] = resolveReportDateRange($params);
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to   = mysqli_real_escape_string($conn, $date_to);

    $revenue_by_account = fetchAccountBreakdown($conn, $company_id, 'revenue', $date_from, $date_to);
    $expense_by_account = fetchAccountBreakdown($conn, $company_id, 'expense', $date_from, $date_to);

    $revenue_total = array_sum(array_column($revenue_by_account, 'total'));
    $expense_total = array_sum(array_column($expense_by_account, 'total'));
    $net_profit    = $revenue_total - $expense_total;

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    $sheet->setCellValue('A1', 'LAPORAN LABA RUGI');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->mergeCells('A1:C1');

    $sheet->setCellValue('A2', 'Periode: ' . formatIndonesianDate($date_from) . ' - ' . formatIndonesianDate($date_to));
    $sheet->mergeCells('A2:C2');

    $row = 4;
    $row = writeAccountBreakdownSection($sheet, $row, 'PENDAPATAN', $revenue_by_account, $revenue_total);
    $row++;
    $row = writeAccountBreakdownSection($sheet, $row, 'BEBAN', $expense_by_account, $expense_total);
    $row++;

    $sheet->setCellValue("B$row", 'LABA/RUGI BERSIH');
    $sheet->setCellValue("C$row", number_format($net_profit, 2));
    $sheet->getStyle("B$row:C$row")->getFont()->setBold(true);
    $sheet->getStyle("B$row:C$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $sheet->getColumnDimension('A')->setWidth(15);
    $sheet->getColumnDimension('B')->setWidth(40);
    $sheet->getColumnDimension('C')->setWidth(20);

    streamXlsx($spreadsheet, 'laba_rugi_' . sanitizeFilename("{$date_from}_{$date_to}") . '.xlsx');
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}
if ($method !== 'GET') {
    jsonResponse(405, 'Method Not Allowed');
    exit;
}

try {
    $conn = getConn();

    if ($action === 'export') {
        exportProfitLossReport($conn, $company_id, $_GET);
    } else {
        getProfitLossReport($conn, $company_id, $_GET);
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
