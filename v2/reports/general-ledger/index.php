<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/report_dates.php';
require_once __DIR__ . '/../../helpers/excel_export.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

function getAllGeneralLedgerAccounts($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    [$date_from, $date_to] = resolveReportDateRange($params);
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to   = mysqli_real_escape_string($conn, $date_to);

    $where = "ac.company_id = '$company_id' AND ac.deleted_at IS NULL";

    $result = mysqli_query($conn, "SELECT ac.id AS account_code_id, ac.account_code, ac.account_code_name, ac.account_type,
            COALESCE(SUM(CASE WHEN combined.transaction_date < '$date_from' THEN combined.amount ELSE 0 END), 0) AS opening_balance,
            COALESCE(SUM(CASE WHEN combined.transaction_date BETWEEN '$date_from' AND '$date_to' THEN combined.amount ELSE 0 END), 0) AS period_movement
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
        ) combined ON combined.account_code_id = ac.id AND combined.transaction_date <= '$date_to'
        WHERE $where
        GROUP BY ac.id, ac.account_code, ac.account_code_name, ac.account_type
        ORDER BY ac.account_code ASC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".account_code ac WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($data as &$row) {
        $row['opening_balance']  = (float)$row['opening_balance'];
        $row['period_movement']  = (float)$row['period_movement'];
        $row['closing_balance']  = $row['opening_balance'] + $row['period_movement'];
    }

    jsonResponse(200, 'General ledger found', [
        'date_from'  => $date_from,
        'date_to'    => $date_to,
        'data'       => $data,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

function getDetailGeneralLedgerAccount($conn, $account_code_id, $company_id, $params) {
    $account_code_id = mysqli_real_escape_string($conn, $account_code_id);

    $account_result = mysqli_query($conn, "SELECT id AS account_code_id, account_code, account_code_name, account_type
        FROM " . APP_SCHEMA . ".account_code
        WHERE id = '$account_code_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (!$account_result || mysqli_num_rows($account_result) === 0) {
        jsonResponse(404, 'Account code not found');
        return;
    }
    $account = mysqli_fetch_assoc($account_result);

    [$date_from, $date_to] = resolveReportDateRange($params);
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to   = mysqli_real_escape_string($conn, $date_to);

    $combined = "(
            SELECT ftd.id, ftd.account_code_id, ftd.amount, ft.transaction_date, ft.created_at,
                   ft.voucher_number AS reference_number, ft.memo, 'cash_transaction' AS source
            FROM " . APP_SCHEMA . ".finance_transaction_detail ftd
            JOIN " . APP_SCHEMA . ".finance_transaction ft ON ft.id = ftd.finance_transaction_id AND ft.deleted_at IS NULL
            WHERE ftd.deleted_at IS NULL AND ft.company_id = '$company_id'
            UNION ALL
            SELECT gjd.id, gjd.account_code_id, gjd.amount, gj.transaction_date, gj.created_at,
                   gj.journal_number AS reference_number, gj.memo, 'general_journal' AS source
            FROM " . APP_SCHEMA . ".general_journal_detail gjd
            JOIN " . APP_SCHEMA . ".general_journal gj ON gj.id = gjd.general_journal_id AND gj.deleted_at IS NULL
            WHERE gjd.deleted_at IS NULL AND gj.company_id = '$company_id'
        ) combined";

    $opening_result = mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) AS opening_balance
        FROM $combined
        WHERE account_code_id = '$account_code_id' AND transaction_date < '$date_from'");
    $opening_balance = (float)mysqli_fetch_assoc($opening_result)['opening_balance'];

    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = "account_code_id = '$account_code_id' AND transaction_date BETWEEN '$date_from' AND '$date_to'";

    $result = mysqli_query($conn, "SELECT id, transaction_date, reference_number, memo, source, amount AS account_amount
        FROM $combined
        WHERE $where
        ORDER BY transaction_date ASC, created_at ASC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM $combined WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    $preceding_balance = 0;
    if ($offset > 0) {
        $preceding_result = mysqli_query($conn, "SELECT COALESCE(SUM(t.account_amount), 0) AS preceding_balance
            FROM (
                SELECT amount AS account_amount
                FROM $combined
                WHERE $where
                ORDER BY transaction_date ASC, created_at ASC LIMIT $offset
            ) t");
        $preceding_balance = (float)mysqli_fetch_assoc($preceding_result)['preceding_balance'];
    }

    $running_balance = $opening_balance + $preceding_balance;
    $transactions    = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($transactions as &$row) {
        $row['account_amount']  = (float)$row['account_amount'];
        $running_balance       += $row['account_amount'];
        $row['running_balance'] = $running_balance;
    }

    jsonResponse(200, 'General ledger account found', array_merge($account, [
        'date_from'       => $date_from,
        'date_to'         => $date_to,
        'opening_balance' => $opening_balance,
        'transactions'    => $transactions,
        'closing_balance' => $running_balance,
        'pagination'      => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]));
}

function exportGeneralLedgerSummary($conn, $company_id, $params) {
    [$date_from, $date_to] = resolveReportDateRange($params);
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to   = mysqli_real_escape_string($conn, $date_to);

    $where = "ac.company_id = '$company_id' AND ac.deleted_at IS NULL";

    $result = mysqli_query($conn, "SELECT ac.account_code, ac.account_code_name, ac.account_type,
            COALESCE(SUM(CASE WHEN combined.transaction_date < '$date_from' THEN combined.amount ELSE 0 END), 0) AS opening_balance,
            COALESCE(SUM(CASE WHEN combined.transaction_date BETWEEN '$date_from' AND '$date_to' THEN combined.amount ELSE 0 END), 0) AS period_movement
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
        ) combined ON combined.account_code_id = ac.id AND combined.transaction_date <= '$date_to'
        WHERE $where
        GROUP BY ac.id, ac.account_code, ac.account_code_name, ac.account_type
        ORDER BY ac.account_code ASC");
    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    $sheet->setCellValue('A1', 'BUKU BESAR');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->mergeCells('A1:F1');

    $sheet->setCellValue('A2', 'Periode: ' . formatIndonesianDate($date_from) . ' - ' . formatIndonesianDate($date_to));
    $sheet->mergeCells('A2:F2');

    $headers = ['No', 'Kode Akun', 'Nama Akun', 'Saldo Awal', 'Pergerakan', 'Saldo Akhir'];
    $sheet->fromArray($headers, null, 'A4');
    $sheet->getStyle('A4:F4')->getFont()->setBold(true);
    $sheet->getStyle('A4:F4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A4:F4')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $row = 5;
    $no  = 1;
    foreach ($rows as $account) {
        $opening_balance = (float)$account['opening_balance'];
        $period_movement = (float)$account['period_movement'];
        $closing_balance = $opening_balance + $period_movement;

        $sheet->setCellValue("A$row", $no);
        $sheet->setCellValue("B$row", $account['account_code']);
        $sheet->setCellValue("C$row", $account['account_code_name']);
        $sheet->setCellValue("D$row", number_format($opening_balance, 2));
        $sheet->setCellValue("E$row", number_format($period_movement, 2));
        $sheet->setCellValue("F$row", number_format($closing_balance, 2));
        $sheet->getStyle("A$row:F$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $row++;
        $no++;
    }

    foreach (range('A', 'F') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    streamXlsx($spreadsheet, 'buku_besar_' . sanitizeFilename("{$date_from}_{$date_to}") . '.xlsx');
}

function exportGeneralLedgerDetail($conn, $account_code_id, $company_id, $params) {
    $account_code_id = mysqli_real_escape_string($conn, $account_code_id);

    $account_result = mysqli_query($conn, "SELECT id AS account_code_id, account_code, account_code_name, account_type
        FROM " . APP_SCHEMA . ".account_code
        WHERE id = '$account_code_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (!$account_result || mysqli_num_rows($account_result) === 0) {
        jsonResponse(404, 'Account code not found');
        return;
    }
    $account = mysqli_fetch_assoc($account_result);

    [$date_from, $date_to] = resolveReportDateRange($params);
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to   = mysqli_real_escape_string($conn, $date_to);

    $combined = "(
            SELECT ftd.account_code_id, ftd.amount, ft.transaction_date, ft.created_at,
                   ft.voucher_number AS reference_number, ft.memo, 'Transaksi Kas/Bank' AS source
            FROM " . APP_SCHEMA . ".finance_transaction_detail ftd
            JOIN " . APP_SCHEMA . ".finance_transaction ft ON ft.id = ftd.finance_transaction_id AND ft.deleted_at IS NULL
            WHERE ftd.deleted_at IS NULL AND ft.company_id = '$company_id'
            UNION ALL
            SELECT gjd.account_code_id, gjd.amount, gj.transaction_date, gj.created_at,
                   gj.journal_number AS reference_number, gj.memo, 'Jurnal Umum' AS source
            FROM " . APP_SCHEMA . ".general_journal_detail gjd
            JOIN " . APP_SCHEMA . ".general_journal gj ON gj.id = gjd.general_journal_id AND gj.deleted_at IS NULL
            WHERE gjd.deleted_at IS NULL AND gj.company_id = '$company_id'
        ) combined";

    $opening_result = mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) AS opening_balance
        FROM $combined
        WHERE account_code_id = '$account_code_id' AND transaction_date < '$date_from'");
    $opening_balance = (float)mysqli_fetch_assoc($opening_result)['opening_balance'];

    $result = mysqli_query($conn, "SELECT transaction_date, reference_number, memo, source, amount AS account_amount
        FROM $combined
        WHERE account_code_id = '$account_code_id' AND transaction_date BETWEEN '$date_from' AND '$date_to'
        ORDER BY transaction_date ASC, created_at ASC");
    $transactions = mysqli_fetch_all($result, MYSQLI_ASSOC);

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    $sheet->setCellValue('A1', 'BUKU BESAR');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->mergeCells('A1:F1');

    $sheet->setCellValue('A2', $account['account_code'] . ' - ' . $account['account_code_name']);
    $sheet->mergeCells('A2:F2');
    $sheet->setCellValue('A3', 'Periode: ' . formatIndonesianDate($date_from) . ' - ' . formatIndonesianDate($date_to));
    $sheet->mergeCells('A3:F3');

    $headers = ['No', 'Tanggal', 'No Referensi', 'Memo', 'Sumber', 'Jumlah'];
    $sheet->fromArray($headers, null, 'A5');
    $sheet->getStyle('A5:F5')->getFont()->setBold(true);
    $sheet->getStyle('A5:F5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A5:F5')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $row = 6;
    $sheet->setCellValue("E$row", 'Saldo Awal');
    $sheet->setCellValue("F$row", number_format($opening_balance, 2));
    $sheet->getStyle("E$row:F$row")->getFont()->setBold(true);
    $sheet->getStyle("A$row:F$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;

    $no              = 1;
    $running_balance = $opening_balance;
    foreach ($transactions as $item) {
        $account_amount   = (float)$item['account_amount'];
        $running_balance += $account_amount;

        $sheet->setCellValue("A$row", $no);
        $sheet->setCellValue("B$row", $item['transaction_date']);
        $sheet->setCellValue("C$row", $item['reference_number'] ?? '-');
        $sheet->setCellValue("D$row", $item['memo'] ?? '-');
        $sheet->setCellValue("E$row", $item['source']);
        $sheet->setCellValue("F$row", number_format($account_amount, 2));
        $sheet->getStyle("A$row:F$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $row++;
        $no++;
    }

    $sheet->setCellValue("E$row", 'Saldo Akhir');
    $sheet->setCellValue("F$row", number_format($running_balance, 2));
    $sheet->getStyle("E$row:F$row")->getFont()->setBold(true);
    $sheet->getStyle("A$row:F$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    foreach (range('A', 'F') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    streamXlsx($spreadsheet, 'buku_besar_' . sanitizeFilename($account['account_code'] . "_{$date_from}_{$date_to}") . '.xlsx');
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

$account_code_id = !empty($action) ? $action : null;
$sub_action      = $parts[4] ?? '';

try {
    $conn = getConn();

    if ($account_code_id === 'export') {
        exportGeneralLedgerSummary($conn, $company_id, $_GET);
    } elseif ($account_code_id && $sub_action === 'export') {
        exportGeneralLedgerDetail($conn, $account_code_id, $company_id, $_GET);
    } elseif ($account_code_id) {
        getDetailGeneralLedgerAccount($conn, $account_code_id, $company_id, $_GET);
    } else {
        getAllGeneralLedgerAccounts($conn, $company_id, $_GET);
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
