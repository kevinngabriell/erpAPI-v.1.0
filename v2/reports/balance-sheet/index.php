<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/excel_export.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

function fetchBalanceSheetAccounts($conn, $company_id, $account_type, $as_of_date) {
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
        ) combined ON combined.account_code_id = ac.id AND combined.transaction_date <= '$as_of_date'
        WHERE ac.company_id = '$company_id' AND ac.deleted_at IS NULL AND ac.account_type = '$account_type'
        GROUP BY ac.id, ac.account_code, ac.account_code_name
        HAVING total != 0
        ORDER BY ac.account_code ASC");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['total'] = (float)$row['total'];
    return $rows;
}

function getBalanceSheetReport($conn, $company_id, $params) {
    $as_of_date = isset($params['as_of_date']) && trim($params['as_of_date']) !== ''
        ? $params['as_of_date']
        : date('Y-m-d');
    $as_of_date = mysqli_real_escape_string($conn, $as_of_date);

    $asset_by_account     = fetchBalanceSheetAccounts($conn, $company_id, 'asset', $as_of_date);
    $liability_by_account = fetchBalanceSheetAccounts($conn, $company_id, 'liability', $as_of_date);
    $equity_by_account    = fetchBalanceSheetAccounts($conn, $company_id, 'equity', $as_of_date);

    jsonResponse(200, 'Balance sheet report found', [
        'as_of_date' => $as_of_date,
        'asset'      => ['total' => array_sum(array_column($asset_by_account, 'total')), 'by_account' => $asset_by_account],
        'liability'  => ['total' => array_sum(array_column($liability_by_account, 'total')), 'by_account' => $liability_by_account],
        'equity'     => ['total' => array_sum(array_column($equity_by_account, 'total')), 'by_account' => $equity_by_account],
    ]);
}

function writeBalanceSheetSection($sheet, $row, $title, $accounts, $total) {
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

function exportBalanceSheetReport($conn, $company_id, $params) {
    $as_of_date = isset($params['as_of_date']) && trim($params['as_of_date']) !== ''
        ? $params['as_of_date']
        : date('Y-m-d');
    $as_of_date = mysqli_real_escape_string($conn, $as_of_date);

    $asset_by_account     = fetchBalanceSheetAccounts($conn, $company_id, 'asset', $as_of_date);
    $liability_by_account = fetchBalanceSheetAccounts($conn, $company_id, 'liability', $as_of_date);
    $equity_by_account    = fetchBalanceSheetAccounts($conn, $company_id, 'equity', $as_of_date);

    $asset_total     = array_sum(array_column($asset_by_account, 'total'));
    $liability_total = array_sum(array_column($liability_by_account, 'total'));
    $equity_total    = array_sum(array_column($equity_by_account, 'total'));

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    $sheet->setCellValue('A1', 'NERACA');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->mergeCells('A1:C1');

    $sheet->setCellValue('A2', 'Per Tanggal: ' . formatIndonesianDate($as_of_date));
    $sheet->mergeCells('A2:C2');

    $row = 4;
    $row = writeBalanceSheetSection($sheet, $row, 'ASET', $asset_by_account, $asset_total);
    $row++;
    $row = writeBalanceSheetSection($sheet, $row, 'LIABILITAS', $liability_by_account, $liability_total);
    $row++;
    $row = writeBalanceSheetSection($sheet, $row, 'EKUITAS', $equity_by_account, $equity_total);
    $row++;

    $sheet->setCellValue("B$row", 'TOTAL LIABILITAS + EKUITAS');
    $sheet->setCellValue("C$row", number_format($liability_total + $equity_total, 2));
    $sheet->getStyle("B$row:C$row")->getFont()->setBold(true);
    $sheet->getStyle("B$row:C$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $sheet->getColumnDimension('A')->setWidth(15);
    $sheet->getColumnDimension('B')->setWidth(40);
    $sheet->getColumnDimension('C')->setWidth(20);

    streamXlsx($spreadsheet, 'neraca_' . sanitizeFilename($as_of_date) . '.xlsx');
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
        exportBalanceSheetReport($conn, $company_id, $_GET);
    } else {
        getBalanceSheetReport($conn, $company_id, $_GET);
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
