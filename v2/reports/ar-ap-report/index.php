<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/excel_export.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

function agingBucket($days) {
    if ($days <= 0)  return 'current';
    if ($days <= 30) return '30';
    if ($days <= 60) return '60';
    if ($days <= 90) return '90';
    return '90+';
}

function fetchArRows($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT
            fp.invoice_number, c.customer_name AS partner_name, c.id AS partner_id, si.invoice_date,
            MAX(fp.due_amount) - SUM(COALESCE(fp.paid_amount, 0)) AS outstanding,
            DATEDIFF(CURDATE(), DATE_ADD(si.invoice_date, INTERVAL COALESCE(c.customer_top_days, 0) DAY)) AS days_overdue
        FROM " . APP_SCHEMA . ".finance_payment fp
        LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = fp.customer_id
        LEFT JOIN " . APP_SCHEMA . ".sales_invoice si ON si.invoice_display_number = fp.invoice_number AND si.company_id = fp.company_id
        WHERE fp.company_id = '$company_id' AND fp.customer_id IS NOT NULL AND fp.deleted_at IS NULL
        GROUP BY fp.invoice_number, c.customer_name, c.id, si.invoice_date, c.customer_top_days
        HAVING outstanding > 0");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) {
        $row['type']          = 'ar';
        $row['outstanding']   = (float)$row['outstanding'];
        $row['days_overdue']  = (int)$row['days_overdue'];
        $row['bucket']        = agingBucket($row['days_overdue']);
    }
    return $rows;
}

function fetchApRows($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT
            fp.invoice_number, s.supplier_name AS partner_name, s.id AS partner_id, pi.invoice_date,
            MAX(fp.due_amount) - SUM(COALESCE(fp.paid_amount, 0)) AS outstanding,
            DATEDIFF(CURDATE(), DATE_ADD(pi.invoice_date, INTERVAL COALESCE(pt.days, 0) DAY)) AS days_overdue
        FROM " . APP_SCHEMA . ".finance_payment fp
        LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = fp.supplier_id
        LEFT JOIN " . APP_SCHEMA . ".payment_term pt ON pt.id = s.supplier_term_id
        LEFT JOIN " . APP_SCHEMA . ".purchase_invoice pi ON pi.invoice_display_number = fp.invoice_number AND pi.company_id = fp.company_id
        WHERE fp.company_id = '$company_id' AND fp.supplier_id IS NOT NULL AND fp.deleted_at IS NULL
        GROUP BY fp.invoice_number, s.supplier_name, s.id, pi.invoice_date, pt.days
        HAVING outstanding > 0");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) {
        $row['type']          = 'ap';
        $row['outstanding']   = (float)$row['outstanding'];
        $row['days_overdue']  = (int)$row['days_overdue'];
        $row['bucket']        = agingBucket($row['days_overdue']);
    }
    return $rows;
}

function getArApReport($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $type   = in_array($params['type'] ?? 'all', ['ar', 'ap', 'all'], true) ? $params['type'] : 'all';
    $bucket = in_array($params['bucket'] ?? '', ['current', '30', '60', '90', '90+'], true) ? $params['bucket'] : '';
    $search = isset($params['search']) ? mb_strtolower(trim($params['search'])) : '';

    $ar_rows = fetchArRows($conn, $company_id);
    $ap_rows = fetchApRows($conn, $company_id);

    $rows = [];
    if ($type === 'ar' || $type === 'all') $rows = array_merge($rows, $ar_rows);
    if ($type === 'ap' || $type === 'all') $rows = array_merge($rows, $ap_rows);

    if ($bucket !== '') {
        $rows = array_values(array_filter($rows, fn($row) => $row['bucket'] === $bucket));
    }
    if ($search !== '') {
        $rows = array_values(array_filter($rows, fn($row) =>
            str_contains(mb_strtolower($row['partner_name'] ?? ''), $search) ||
            str_contains(mb_strtolower($row['invoice_number'] ?? ''), $search)
        ));
    }

    usort($rows, fn($a, $b) => $b['days_overdue'] <=> $a['days_overdue']);

    $total       = count($rows);
    $total_pages = (int)ceil($total / $limit);
    $data        = array_slice($rows, ($page - 1) * $limit, $limit);

    $receivables = ['current' => 0, '30' => 0, '60' => 0, '90' => 0, '90+' => 0];
    $payables    = $receivables;
    foreach ($ar_rows as $row) $receivables[$row['bucket']] += $row['outstanding'];
    foreach ($ap_rows as $row) $payables[$row['bucket']]    += $row['outstanding'];

    jsonResponse(200, 'AR/AP report found', [
        'data'       => $data,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => $total_pages,
        ],
        'summary' => [
            'receivables' => $receivables,
            'payables'    => $payables,
        ],
    ]);
}

function exportArApReport($conn, $company_id, $params) {
    $type   = in_array($params['type'] ?? 'all', ['ar', 'ap', 'all'], true) ? $params['type'] : 'all';
    $bucket = in_array($params['bucket'] ?? '', ['current', '30', '60', '90', '90+'], true) ? $params['bucket'] : '';
    $search = isset($params['search']) ? mb_strtolower(trim($params['search'])) : '';

    $ar_rows = fetchArRows($conn, $company_id);
    $ap_rows = fetchApRows($conn, $company_id);

    $rows = [];
    if ($type === 'ar' || $type === 'all') $rows = array_merge($rows, $ar_rows);
    if ($type === 'ap' || $type === 'all') $rows = array_merge($rows, $ap_rows);

    if ($bucket !== '') {
        $rows = array_values(array_filter($rows, fn($row) => $row['bucket'] === $bucket));
    }
    if ($search !== '') {
        $rows = array_values(array_filter($rows, fn($row) =>
            str_contains(mb_strtolower($row['partner_name'] ?? ''), $search) ||
            str_contains(mb_strtolower($row['invoice_number'] ?? ''), $search)
        ));
    }

    usort($rows, fn($a, $b) => $b['days_overdue'] <=> $a['days_overdue']);

    $bucket_labels = ['current' => 'Current', '30' => '1-30 hari', '60' => '31-60 hari', '90' => '61-90 hari', '90+' => '> 90 hari'];

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    $sheet->setCellValue('A1', 'LAPORAN PIUTANG & HUTANG');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->mergeCells('A1:H1');

    $headers = ['No', 'Tipe', 'No Invoice', 'Partner', 'Tanggal Invoice', 'Outstanding', 'Hari Terlambat', 'Aging'];
    $sheet->fromArray($headers, null, 'A3');
    $sheet->getStyle('A3:H3')->getFont()->setBold(true);
    $sheet->getStyle('A3:H3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A3:H3')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $row              = 4;
    $no               = 1;
    $total_outstanding = 0;
    foreach ($rows as $item) {
        $sheet->setCellValue("A$row", $no);
        $sheet->setCellValue("B$row", $item['type'] === 'ar' ? 'Piutang' : 'Hutang');
        $sheet->setCellValue("C$row", $item['invoice_number']);
        $sheet->setCellValue("D$row", $item['partner_name'] ?? '-');
        $sheet->setCellValue("E$row", $item['invoice_date'] ?? '-');
        $sheet->setCellValue("F$row", number_format($item['outstanding'], 2));
        $sheet->setCellValue("G$row", $item['days_overdue']);
        $sheet->setCellValue("H$row", $bucket_labels[$item['bucket']]);
        $sheet->getStyle("A$row:H$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $total_outstanding += $item['outstanding'];
        $row++;
        $no++;
    }

    $sheet->setCellValue("E$row", 'TOTAL');
    $sheet->setCellValue("F$row", number_format($total_outstanding, 2));
    $sheet->getStyle("E$row:F$row")->getFont()->setBold(true);
    $sheet->getStyle("A$row:H$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $receivables = ['current' => 0, '30' => 0, '60' => 0, '90' => 0, '90+' => 0];
    $payables    = $receivables;
    foreach ($ar_rows as $item) $receivables[$item['bucket']] += $item['outstanding'];
    foreach ($ap_rows as $item) $payables[$item['bucket']]    += $item['outstanding'];

    $row += 2;
    $sheet->setCellValue("A$row", 'RINGKASAN AGING');
    $sheet->getStyle("A$row")->getFont()->setBold(true);
    $row++;
    $sheet->fromArray(['Aging', 'Piutang', 'Hutang'], null, "A$row");
    $sheet->getStyle("A$row:C$row")->getFont()->setBold(true);
    $sheet->getStyle("A$row:C$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
    foreach ($bucket_labels as $key => $label) {
        $sheet->setCellValue("A$row", $label);
        $sheet->setCellValue("B$row", number_format($receivables[$key], 2));
        $sheet->setCellValue("C$row", number_format($payables[$key], 2));
        $sheet->getStyle("A$row:C$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $row++;
    }

    foreach (range('A', 'H') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    streamXlsx($spreadsheet, 'piutang_hutang_' . sanitizeFilename(date('Y-m-d')) . '.xlsx');
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
        exportArApReport($conn, $company_id, $_GET);
    } else {
        getArApReport($conn, $company_id, $_GET);
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
