<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/report_dates.php';

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
    getCogsReport($conn, $company_id, $_GET);
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
