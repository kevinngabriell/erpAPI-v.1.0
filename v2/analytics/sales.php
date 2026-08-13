<?php

function buildSalesSummary($conn, $company_id, $ytd_from, $ytd_to) {
    $result = mysqli_query($conn, "SELECT
            COALESCE(SUM(sii.quantity * sii.unit_price), 0) AS revenue,
            COUNT(DISTINCT si.sales_order_id) AS order_count
        FROM " . APP_SCHEMA . ".sales_invoice si
        JOIN " . APP_SCHEMA . ".sales_invoice_item sii ON sii.sales_invoice_id = si.id AND sii.deleted_at IS NULL
        WHERE si.company_id = '$company_id' AND si.deleted_at IS NULL
          AND si.invoice_date BETWEEN '$ytd_from' AND '$ytd_to'");
    $row               = mysqli_fetch_assoc($result);
    $total_sales_ytd   = (float)$row['revenue'];
    $order_count       = (int)$row['order_count'];
    $avg_order_value   = $order_count > 0 ? round($total_sales_ytd / $order_count, 2) : 0;

    $target_year   = (int)date('Y', strtotime($ytd_to));
    $target_result = mysqli_query($conn, "SELECT target_value FROM " . APP_SCHEMA . ".sales_target
        WHERE company_id = '$company_id' AND target_year = $target_year AND deleted_at IS NULL LIMIT 1");
    $target_row    = $target_result ? mysqli_fetch_assoc($target_result) : null;
    $target_sales  = $target_row ? (float)$target_row['target_value'] : 0;

    return [
        'total_sales_ytd' => $total_sales_ytd,
        'target_sales'    => $target_sales,
        'avg_order_value' => $avg_order_value,
    ];
}

function buildSalesMonthly($conn, $company_id, $monthly_from, $monthly_to) {
    $months = [];
    foreach (buildAnalyticsMonthBuckets($monthly_from, $monthly_to) as $ym) {
        $months[$ym] = ['month' => $ym, 'total_sales' => 0];
    }

    $result = mysqli_query($conn, "SELECT DATE_FORMAT(si.invoice_date, '%Y-%m') AS ym,
            SUM(sii.quantity * sii.unit_price) AS total
        FROM " . APP_SCHEMA . ".sales_invoice si
        JOIN " . APP_SCHEMA . ".sales_invoice_item sii ON sii.sales_invoice_id = si.id AND sii.deleted_at IS NULL
        WHERE si.company_id = '$company_id' AND si.deleted_at IS NULL
          AND si.invoice_date BETWEEN '$monthly_from' AND '$monthly_to'
        GROUP BY ym");
    while ($row = mysqli_fetch_assoc($result)) {
        if (isset($months[$row['ym']])) $months[$row['ym']]['total_sales'] = (float)$row['total'];
    }

    return array_values($months);
}

function buildSalesTopProducts($conn, $company_id, $default_from, $default_to) {
    $result = mysqli_query($conn, "SELECT sii.product_name,
            SUM(sii.quantity * sii.unit_price) AS total_sales
        FROM " . APP_SCHEMA . ".sales_invoice si
        JOIN " . APP_SCHEMA . ".sales_invoice_item sii ON sii.sales_invoice_id = si.id AND sii.deleted_at IS NULL
        WHERE si.company_id = '$company_id' AND si.deleted_at IS NULL
          AND si.invoice_date BETWEEN '$default_from' AND '$default_to'
        GROUP BY sii.product_name ORDER BY total_sales DESC LIMIT 10");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['total_sales'] = (float)$row['total_sales'];

    return $rows;
}

function buildSalesFunnel($conn, $company_id, $default_from, $default_to) {
    $result = mysqli_query($conn, "SELECT
            COUNT(DISTINCT so.id) AS total_so,
            COUNT(DISTINCT ssp.sales_order_id) AS with_sppb,
            COUNT(DISTINCT sd.sales_order_id) AS with_delivery,
            COUNT(DISTINCT si.sales_order_id) AS with_invoice
        FROM " . APP_SCHEMA . ".sales_order so
        LEFT JOIN " . APP_SCHEMA . ".sales_sppb ssp ON ssp.sales_order_id = so.id AND ssp.deleted_at IS NULL
        LEFT JOIN " . APP_SCHEMA . ".sales_delivery sd ON sd.sales_order_id = so.id AND sd.deleted_at IS NULL
        LEFT JOIN " . APP_SCHEMA . ".sales_invoice si ON si.sales_order_id = so.id AND si.deleted_at IS NULL
        WHERE so.company_id = '$company_id' AND so.deleted_at IS NULL
          AND so.so_date BETWEEN '$default_from' AND '$default_to'");

    $row      = mysqli_fetch_assoc($result);
    $total_so = (int)$row['total_so'];

    $rate = fn($count) => $total_so > 0 ? round((int)$count / $total_so * 100, 2) : 0;

    return [
        'so'       => 100,
        'sppb'     => $rate($row['with_sppb']),
        'delivery' => $rate($row['with_delivery']),
        'invoice'  => $rate($row['with_invoice']),
    ];
}

function getSalesAnalytics($conn, $company_id, $params) {
    [$ytd_from, $ytd_to]         = resolveAnalyticsYtdRange($params);
    [$monthly_from, $monthly_to] = resolveAnalyticsMonthlyRange($params);
    [$default_from, $default_to] = resolveAnalyticsDefaultRange($params);

    $ytd_from     = mysqli_real_escape_string($conn, $ytd_from);
    $ytd_to       = mysqli_real_escape_string($conn, $ytd_to);
    $monthly_from = mysqli_real_escape_string($conn, $monthly_from);
    $monthly_to   = mysqli_real_escape_string($conn, $monthly_to);
    $default_from = mysqli_real_escape_string($conn, $default_from);
    $default_to   = mysqli_real_escape_string($conn, $default_to);

    jsonResponse(200, 'Sales analytics found', [
        'summary'      => buildSalesSummary($conn, $company_id, $ytd_from, $ytd_to),
        'monthly'      => buildSalesMonthly($conn, $company_id, $monthly_from, $monthly_to),
        'top_products' => buildSalesTopProducts($conn, $company_id, $default_from, $default_to),
        'funnel'       => buildSalesFunnel($conn, $company_id, $default_from, $default_to),
    ]);
}
