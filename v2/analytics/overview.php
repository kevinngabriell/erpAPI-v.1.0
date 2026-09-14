<?php

function buildOverviewSummary($conn, $company_id, $ytd_from, $ytd_to) {
    $revenue_result = mysqli_query($conn, "SELECT COALESCE(SUM(sii.quantity * sii.unit_price), 0) AS revenue
        FROM " . APP_SCHEMA . ".sales_invoice si
        JOIN " . APP_SCHEMA . ".sales_invoice_item sii ON sii.sales_invoice_id = si.id AND sii.deleted_at IS NULL
        WHERE si.company_id = '$company_id' AND si.deleted_at IS NULL
          AND si.invoice_date BETWEEN '$ytd_from' AND '$ytd_to'");
    $revenue_ytd = (float)mysqli_fetch_assoc($revenue_result)['revenue'];

    $margin_result = mysqli_query($conn, "SELECT
            COALESCE(SUM(spi.price * spi.quantity), 0) AS revenue,
            COALESCE(SUM(spi.landed_cost * spi.quantity), 0) AS cogs
        FROM " . APP_SCHEMA . ".sales_profit sp
        JOIN " . APP_SCHEMA . ".sales_profit_item spi ON spi.sales_profit_id = sp.id AND spi.deleted_at IS NULL
        WHERE sp.company_id = '$company_id' AND sp.deleted_at IS NULL
          AND sp.created_at BETWEEN '$ytd_from 00:00:00' AND '$ytd_to 23:59:59'");
    $margin_row            = mysqli_fetch_assoc($margin_result);
    $profit_revenue         = (float)$margin_row['revenue'];
    $profit_cogs            = (float)$margin_row['cogs'];
    $gross_margin_percent   = $profit_revenue > 0 ? round(($profit_revenue - $profit_cogs) / $profit_revenue * 100, 2) : 0;

    // Point-in-time balances — cash position and AR/AP are "as of now", not
    // scoped to the requested window, same convention as the dashboard's
    // cash_position/ar_ap_summary widgets and the ar-ap-report endpoint.
    $cash_result = mysqli_query($conn, "SELECT COALESCE(SUM(ft.amount), 0) AS total
        FROM " . APP_SCHEMA . ".bank_account ba
        LEFT JOIN " . APP_SCHEMA . ".finance_transaction ft ON ft.bank_account_id = ba.id AND ft.deleted_at IS NULL
        WHERE ba.company_id = '$company_id' AND ba.deleted_at IS NULL");
    $cash_position = (float)mysqli_fetch_assoc($cash_result)['total'];

    $ar_result = mysqli_query($conn, "SELECT COALESCE(SUM(outstanding), 0) AS total FROM (
            SELECT MAX(fp.due_amount) - SUM(COALESCE(fp.paid_amount, 0)) AS outstanding
            FROM " . APP_SCHEMA . ".finance_payment fp
            WHERE fp.company_id = '$company_id' AND fp.customer_id IS NOT NULL AND fp.deleted_at IS NULL
            GROUP BY fp.invoice_number
            HAVING outstanding > 0
        ) t");
    $total_ar = (float)mysqli_fetch_assoc($ar_result)['total'];

    $ap_result = mysqli_query($conn, "SELECT COALESCE(SUM(outstanding), 0) AS total FROM (
            SELECT MAX(fp.due_amount) - SUM(COALESCE(fp.paid_amount, 0)) AS outstanding
            FROM " . APP_SCHEMA . ".finance_payment fp
            WHERE fp.company_id = '$company_id' AND fp.supplier_id IS NOT NULL AND fp.deleted_at IS NULL
            GROUP BY fp.invoice_number
            HAVING outstanding > 0
        ) t");
    $total_ap = (float)mysqli_fetch_assoc($ap_result)['total'];

    return [
        'revenue_ytd'          => $revenue_ytd,
        'gross_margin_percent' => $gross_margin_percent,
        'cash_position'        => $cash_position,
        'total_ar'             => $total_ar,
        'total_ap'             => $total_ap,
    ];
}

function buildOverviewMonthly($conn, $company_id, $monthly_from, $monthly_to) {
    $months = [];
    foreach (buildAnalyticsMonthBuckets($monthly_from, $monthly_to) as $ym) {
        $months[$ym] = ['month' => $ym, 'revenue' => 0, 'purchase_spend' => 0];
    }

    $revenue_result = mysqli_query($conn, "SELECT DATE_FORMAT(si.invoice_date, '%Y-%m') AS ym,
            SUM(sii.quantity * sii.unit_price) AS total
        FROM " . APP_SCHEMA . ".sales_invoice si
        JOIN " . APP_SCHEMA . ".sales_invoice_item sii ON sii.sales_invoice_id = si.id AND sii.deleted_at IS NULL
        WHERE si.company_id = '$company_id' AND si.deleted_at IS NULL
          AND si.invoice_date BETWEEN '$monthly_from' AND '$monthly_to'
        GROUP BY ym");
    while ($row = mysqli_fetch_assoc($revenue_result)) {
        if (isset($months[$row['ym']])) $months[$row['ym']]['revenue'] = (float)$row['total'];
    }

    $purchase_result = mysqli_query($conn, "SELECT DATE_FORMAT(pi.invoice_date, '%Y-%m') AS ym,
            SUM(pii.quantity * pii.unit_price) AS total
        FROM " . APP_SCHEMA . ".purchase_invoice pi
        JOIN " . APP_SCHEMA . ".purchase_invoice_item pii ON pii.purchase_invoice_id = pi.id AND pii.deleted_at IS NULL
        WHERE pi.company_id = '$company_id' AND pi.deleted_at IS NULL
          AND pi.invoice_date BETWEEN '$monthly_from' AND '$monthly_to'
        GROUP BY ym");
    while ($row = mysqli_fetch_assoc($purchase_result)) {
        if (isset($months[$row['ym']])) $months[$row['ym']]['purchase_spend'] = (float)$row['total'];
    }

    return array_values($months);
}

function getOverviewAnalytics($conn, $company_id, $params) {
    [$ytd_from, $ytd_to]         = resolveAnalyticsYtdRange($params);
    [$monthly_from, $monthly_to] = resolveAnalyticsMonthlyRange($params);

    $ytd_from     = mysqli_real_escape_string($conn, $ytd_from);
    $ytd_to       = mysqli_real_escape_string($conn, $ytd_to);
    $monthly_from = mysqli_real_escape_string($conn, $monthly_from);
    $monthly_to   = mysqli_real_escape_string($conn, $monthly_to);

    jsonResponse(200, 'Overview analytics found', [
        'summary' => buildOverviewSummary($conn, $company_id, $ytd_from, $ytd_to),
        'monthly' => buildOverviewMonthly($conn, $company_id, $monthly_from, $monthly_to),
    ]);
}
