<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/report_dates.php';
require_once __DIR__ . '/../../helpers/excel_export.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

function fetchSalesReportData($conn, $company_id, $date_from, $date_to) {
    $summary_result = mysqli_query($conn, "SELECT
            COALESCE(SUM(sii.quantity * sii.unit_price), 0) AS total_omset,
            COUNT(DISTINCT si.id) AS total_invoices
        FROM " . APP_SCHEMA . ".sales_invoice si
        JOIN " . APP_SCHEMA . ".sales_invoice_item sii ON sii.sales_invoice_id = si.id AND sii.deleted_at IS NULL
        WHERE si.company_id = '$company_id' AND si.deleted_at IS NULL
          AND si.invoice_date BETWEEN '$date_from' AND '$date_to'");
    $summary = mysqli_fetch_assoc($summary_result);
    $summary['total_omset']    = (float)$summary['total_omset'];
    $summary['total_invoices'] = (int)$summary['total_invoices'];

    $trend_result = mysqli_query($conn, "SELECT DATE_FORMAT(si.invoice_date, '%Y-%m') AS ym,
            SUM(sii.quantity * sii.unit_price) AS revenue
        FROM " . APP_SCHEMA . ".sales_invoice si
        JOIN " . APP_SCHEMA . ".sales_invoice_item sii ON sii.sales_invoice_id = si.id AND sii.deleted_at IS NULL
        WHERE si.company_id = '$company_id' AND si.deleted_at IS NULL
          AND si.invoice_date BETWEEN '$date_from' AND '$date_to'
        GROUP BY ym ORDER BY ym ASC");
    $monthly_trend = mysqli_fetch_all($trend_result, MYSQLI_ASSOC);
    foreach ($monthly_trend as &$row) $row['revenue'] = (float)$row['revenue'];

    $top_products_result = mysqli_query($conn, "SELECT sii.product_name,
            SUM(sii.quantity) AS quantity, SUM(sii.quantity * sii.unit_price) AS revenue
        FROM " . APP_SCHEMA . ".sales_invoice si
        JOIN " . APP_SCHEMA . ".sales_invoice_item sii ON sii.sales_invoice_id = si.id AND sii.deleted_at IS NULL
        WHERE si.company_id = '$company_id' AND si.deleted_at IS NULL
          AND si.invoice_date BETWEEN '$date_from' AND '$date_to'
        GROUP BY sii.product_name ORDER BY revenue DESC LIMIT 10");
    $top_products = mysqli_fetch_all($top_products_result, MYSQLI_ASSOC);
    foreach ($top_products as &$row) {
        $row['quantity'] = (float)$row['quantity'];
        $row['revenue']  = (float)$row['revenue'];
    }

    $top_customers_result = mysqli_query($conn, "SELECT c.id AS customer_id, c.customer_name,
            SUM(sii.quantity * sii.unit_price) AS total
        FROM " . APP_SCHEMA . ".sales_invoice si
        JOIN " . APP_SCHEMA . ".sales_invoice_item sii ON sii.sales_invoice_id = si.id AND sii.deleted_at IS NULL
        JOIN " . APP_SCHEMA . ".customer c ON c.id = si.customer_id
        WHERE si.company_id = '$company_id' AND si.deleted_at IS NULL
          AND si.invoice_date BETWEEN '$date_from' AND '$date_to'
        GROUP BY c.id, c.customer_name ORDER BY total DESC LIMIT 10");
    $top_customers = mysqli_fetch_all($top_customers_result, MYSQLI_ASSOC);
    foreach ($top_customers as &$row) $row['total'] = (float)$row['total'];

    return [
        'summary'       => $summary,
        'monthly_trend' => $monthly_trend,
        'top_products'  => $top_products,
        'top_customers' => $top_customers,
    ];
}

function getSalesReport($conn, $company_id, $params) {
    [$date_from, $date_to] = resolveReportDateRange($params);
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to   = mysqli_real_escape_string($conn, $date_to);

    $report = fetchSalesReportData($conn, $company_id, $date_from, $date_to);

    jsonResponse(200, 'Sales report found', array_merge([
        'date_from' => $date_from,
        'date_to'   => $date_to,
    ], $report));
}

function exportSalesReport($conn, $company_id, $params) {
    [$date_from, $date_to] = resolveReportDateRange($params);
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to   = mysqli_real_escape_string($conn, $date_to);

    $report = fetchSalesReportData($conn, $company_id, $date_from, $date_to);

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    $sheet->setCellValue('A1', 'LAPORAN PENJUALAN (OMSET)');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->mergeCells('A1:D1');

    $sheet->setCellValue('A2', 'Periode: ' . formatIndonesianDate($date_from) . ' - ' . formatIndonesianDate($date_to));
    $sheet->mergeCells('A2:D2');

    $sheet->setCellValue('A4', 'Total Omset');
    $sheet->setCellValue('B4', number_format($report['summary']['total_omset'], 2));
    $sheet->setCellValue('A5', 'Total Invoice');
    $sheet->setCellValue('B5', $report['summary']['total_invoices']);

    $row = 7;
    $sheet->setCellValue("A$row", 'TREN BULANAN');
    $sheet->getStyle("A$row")->getFont()->setBold(true);
    $row++;
    $sheet->fromArray(['Bulan', 'Omset'], null, "A$row");
    $sheet->getStyle("A$row:B$row")->getFont()->setBold(true);
    $sheet->getStyle("A$row:B$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A$row:B$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
    foreach ($report['monthly_trend'] as $item) {
        $sheet->setCellValue("A$row", $item['ym']);
        $sheet->setCellValue("B$row", number_format($item['revenue'], 2));
        $sheet->getStyle("A$row:B$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $row++;
    }

    $row += 1;
    $sheet->setCellValue("A$row", 'PRODUK TERLARIS');
    $sheet->getStyle("A$row")->getFont()->setBold(true);
    $row++;
    $sheet->fromArray(['Produk', 'Qty', 'Omset'], null, "A$row");
    $sheet->getStyle("A$row:C$row")->getFont()->setBold(true);
    $sheet->getStyle("A$row:C$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A$row:C$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
    foreach ($report['top_products'] as $item) {
        $sheet->setCellValue("A$row", $item['product_name']);
        $sheet->setCellValue("B$row", number_format($item['quantity'], 0));
        $sheet->setCellValue("C$row", number_format($item['revenue'], 2));
        $sheet->getStyle("A$row:C$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $row++;
    }

    $row += 1;
    $sheet->setCellValue("A$row", 'PELANGGAN TERBAIK');
    $sheet->getStyle("A$row")->getFont()->setBold(true);
    $row++;
    $sheet->fromArray(['Pelanggan', 'Total'], null, "A$row");
    $sheet->getStyle("A$row:B$row")->getFont()->setBold(true);
    $sheet->getStyle("A$row:B$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A$row:B$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
    foreach ($report['top_customers'] as $item) {
        $sheet->setCellValue("A$row", $item['customer_name']);
        $sheet->setCellValue("B$row", number_format($item['total'], 2));
        $sheet->getStyle("A$row:B$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $row++;
    }

    foreach (range('A', 'D') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    streamXlsx($spreadsheet, 'penjualan_' . sanitizeFilename("{$date_from}_{$date_to}") . '.xlsx');
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
        exportSalesReport($conn, $company_id, $_GET);
    } else {
        getSalesReport($conn, $company_id, $_GET);
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
