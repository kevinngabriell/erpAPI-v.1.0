<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/report_dates.php';
require_once __DIR__ . '/../../helpers/excel_export.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

function getCogsReport($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    [$date_from, $date_to] = resolveReportDateRange($params);
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to   = mysqli_real_escape_string($conn, $date_to);

    $where = "sp.company_id = '$company_id' AND sp.deleted_at IS NULL
              AND sp.created_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'";

    $from = APP_SCHEMA . ".sales_profit sp
            JOIN " . APP_SCHEMA . ".sales_profit_item spi ON spi.sales_profit_id = sp.id AND spi.deleted_at IS NULL";

    $result = mysqli_query($conn, "SELECT spi.product_name,
            SUM(spi.quantity) AS quantity_sold,
            SUM(spi.price * spi.quantity) AS revenue,
            SUM(spi.landed_cost * spi.quantity) AS cogs
        FROM $from WHERE $where
        GROUP BY spi.product_name ORDER BY revenue DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(DISTINCT spi.product_name) AS total FROM $from WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($data as &$row) {
        $row['quantity_sold'] = (float)$row['quantity_sold'];
        $row['revenue']       = (float)$row['revenue'];
        $row['cogs']          = (float)$row['cogs'];
        $row['gross_profit']  = $row['revenue'] - $row['cogs'];
        $row['margin_percent'] = $row['revenue'] > 0 ? round($row['gross_profit'] / $row['revenue'] * 100, 2) : 0;
    }

    $summary_result = mysqli_query($conn, "SELECT
            SUM(spi.price * spi.quantity) AS total_revenue,
            SUM(spi.landed_cost * spi.quantity) AS total_cogs
        FROM $from WHERE $where");
    $summary = mysqli_fetch_assoc($summary_result);
    $total_revenue      = (float)($summary['total_revenue'] ?? 0);
    $total_cogs         = (float)($summary['total_cogs'] ?? 0);
    $total_gross_profit = $total_revenue - $total_cogs;

    jsonResponse(200, 'COGS report found', [
        'date_from'  => $date_from,
        'date_to'    => $date_to,
        'data'       => $data,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
        'summary' => [
            'total_revenue'          => $total_revenue,
            'total_cogs'             => $total_cogs,
            'total_gross_profit'     => $total_gross_profit,
            'overall_margin_percent' => $total_revenue > 0 ? round($total_gross_profit / $total_revenue * 100, 2) : 0,
        ],
    ]);
}

function exportCogsReport($conn, $company_id, $params) {
    [$date_from, $date_to] = resolveReportDateRange($params);
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to   = mysqli_real_escape_string($conn, $date_to);

    $where = "sp.company_id = '$company_id' AND sp.deleted_at IS NULL
              AND sp.created_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'";

    $from = APP_SCHEMA . ".sales_profit sp
            JOIN " . APP_SCHEMA . ".sales_profit_item spi ON spi.sales_profit_id = sp.id AND spi.deleted_at IS NULL";

    $result = mysqli_query($conn, "SELECT spi.product_name,
            SUM(spi.quantity) AS quantity_sold,
            SUM(spi.price * spi.quantity) AS revenue,
            SUM(spi.landed_cost * spi.quantity) AS cogs
        FROM $from WHERE $where
        GROUP BY spi.product_name ORDER BY revenue DESC");
    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    $sheet->setCellValue('A1', 'LAPORAN HARGA POKOK PENJUALAN (HPP)');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->mergeCells('A1:F1');

    $sheet->setCellValue('A2', 'Periode: ' . formatIndonesianDate($date_from) . ' - ' . formatIndonesianDate($date_to));
    $sheet->mergeCells('A2:F2');

    $headers = ['No', 'Produk', 'Qty Terjual', 'Pendapatan', 'HPP', 'Laba Kotor', 'Margin %'];
    $sheet->fromArray($headers, null, 'A4');
    $sheet->getStyle('A4:G4')->getFont()->setBold(true);
    $sheet->getStyle('A4:G4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A4:G4')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $row                = 5;
    $no                 = 1;
    $total_revenue      = 0;
    $total_cogs         = 0;
    $total_gross_profit = 0;
    foreach ($rows as $item) {
        $quantity_sold = (float)$item['quantity_sold'];
        $revenue       = (float)$item['revenue'];
        $cogs          = (float)$item['cogs'];
        $gross_profit  = $revenue - $cogs;
        $margin        = $revenue > 0 ? round($gross_profit / $revenue * 100, 2) : 0;

        $sheet->setCellValue("A$row", $no);
        $sheet->setCellValue("B$row", $item['product_name']);
        $sheet->setCellValue("C$row", number_format($quantity_sold, 0));
        $sheet->setCellValue("D$row", number_format($revenue, 2));
        $sheet->setCellValue("E$row", number_format($cogs, 2));
        $sheet->setCellValue("F$row", number_format($gross_profit, 2));
        $sheet->setCellValue("G$row", $margin);
        $sheet->getStyle("A$row:G$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $total_revenue      += $revenue;
        $total_cogs         += $cogs;
        $total_gross_profit += $gross_profit;
        $row++;
        $no++;
    }

    $overall_margin = $total_revenue > 0 ? round($total_gross_profit / $total_revenue * 100, 2) : 0;

    $sheet->setCellValue("B$row", 'TOTAL');
    $sheet->setCellValue("D$row", number_format($total_revenue, 2));
    $sheet->setCellValue("E$row", number_format($total_cogs, 2));
    $sheet->setCellValue("F$row", number_format($total_gross_profit, 2));
    $sheet->setCellValue("G$row", $overall_margin);
    $sheet->getStyle("B$row:G$row")->getFont()->setBold(true);
    $sheet->getStyle("A$row:G$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    foreach (range('A', 'G') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    streamXlsx($spreadsheet, 'hpp_' . sanitizeFilename("{$date_from}_{$date_to}") . '.xlsx');
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
        exportCogsReport($conn, $company_id, $_GET);
    } else {
        getCogsReport($conn, $company_id, $_GET);
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
