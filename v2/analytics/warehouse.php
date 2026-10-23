<?php

function buildWarehouseMonthly($conn, $company_id, $monthly_from, $monthly_to) {
    $months = [];
    foreach (buildAnalyticsMonthBuckets($monthly_from, $monthly_to) as $ym) {
        $months[$ym] = ['month' => $ym, 'total_in' => 0, 'total_out' => 0];
    }

    $result = mysqli_query($conn, "SELECT DATE_FORMAT(wt.transaction_date, '%Y-%m') AS ym,
            SUM(CASE WHEN wt.transaction_type = 'stock_in' THEN wti.quantity ELSE 0 END) AS total_in,
            SUM(CASE WHEN wt.transaction_type = 'stock_out' THEN wti.quantity ELSE 0 END) AS total_out
        FROM " . APP_SCHEMA . ".warehouse_transaction wt
        JOIN " . APP_SCHEMA . ".warehouse_transaction_item wti ON wti.warehouse_transaction_id = wt.id AND wti.deleted_at IS NULL
        WHERE wt.company_id = '$company_id' AND wt.deleted_at IS NULL
          AND wt.transaction_type IN ('stock_in', 'stock_out')
          AND wt.transaction_date BETWEEN '$monthly_from' AND '$monthly_to'
        GROUP BY ym LIMIT 100");
    while ($row = mysqli_fetch_assoc($result)) {
        if (isset($months[$row['ym']])) {
            $months[$row['ym']]['total_in']  = (float)$row['total_in'];
            $months[$row['ym']]['total_out'] = (float)$row['total_out'];
        }
    }

    return array_values($months);
}

function buildWarehouseTopMovers($conn, $company_id, $default_from, $default_to) {
    $result = mysqli_query($conn, "SELECT p.product_name,
            SUM(wti.quantity) AS total_qty
        FROM " . APP_SCHEMA . ".warehouse_transaction wt
        JOIN " . APP_SCHEMA . ".warehouse_transaction_item wti ON wti.warehouse_transaction_id = wt.id AND wti.deleted_at IS NULL
        JOIN " . APP_SCHEMA . ".product p ON p.id = wti.product_id
        WHERE wt.company_id = '$company_id' AND wt.deleted_at IS NULL
          AND wt.transaction_type IN ('stock_in', 'stock_out')
          AND wt.transaction_date BETWEEN '$default_from' AND '$default_to'
        GROUP BY p.id, p.product_name ORDER BY total_qty DESC LIMIT 10");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['total_qty'] = (float)$row['total_qty'];

    return $rows;
}

function getWarehouseAnalytics($conn, $company_id, $params) {
    [$monthly_from, $monthly_to] = resolveAnalyticsMonthlyRange($params);
    [$default_from, $default_to] = resolveAnalyticsDefaultRange($params);

    $monthly_from = mysqli_real_escape_string($conn, $monthly_from);
    $monthly_to   = mysqli_real_escape_string($conn, $monthly_to);
    $default_from = mysqli_real_escape_string($conn, $default_from);
    $default_to   = mysqli_real_escape_string($conn, $default_to);

    jsonResponse(200, 'Warehouse analytics found', [
        'monthly'    => buildWarehouseMonthly($conn, $company_id, $monthly_from, $monthly_to),
        'top_movers' => buildWarehouseTopMovers($conn, $company_id, $default_from, $default_to),
    ]);
}
