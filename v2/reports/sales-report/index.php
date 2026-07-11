<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/report_dates.php';

function getSalesReport($conn, $company_id, $params) {
    [$date_from, $date_to] = resolveReportDateRange($params);
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to   = mysqli_real_escape_string($conn, $date_to);

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

    jsonResponse(200, 'Sales report found', [
        'date_from'     => $date_from,
        'date_to'       => $date_to,
        'summary'       => $summary,
        'monthly_trend' => $monthly_trend,
        'top_products'  => $top_products,
        'top_customers' => $top_customers,
    ]);
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
    getSalesReport($conn, $company_id, $_GET);
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
