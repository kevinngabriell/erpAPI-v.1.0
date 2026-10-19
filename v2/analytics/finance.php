<?php

// Revenue/cogs/gross_profit here follow the same landed-cost method as the
// existing cogs-report and the dashboard's profit_summary widget — there is
// no COGS-specific account_type in account_code (only revenue/expense), so
// the account_code-based /profit-loss total can't be decomposed into "cost
// of goods" vs opex. net_profit in the monthly series below is therefore
// gross profit (revenue - COGS), not profit after operating expenses.
function buildFinanceRevenueCogs($conn, $company_id, $date_from, $date_to) {
    $result = mysqli_query($conn, "SELECT
            COALESCE(SUM(spi.price * spi.quantity), 0) AS revenue,
            COALESCE(SUM(spi.landed_cost * spi.quantity), 0) AS cogs
        FROM " . APP_SCHEMA . ".sales_profit sp
        JOIN " . APP_SCHEMA . ".sales_profit_item spi ON spi.sales_profit_id = sp.id AND spi.deleted_at IS NULL
        WHERE sp.company_id = '$company_id' AND sp.deleted_at IS NULL
          AND sp.created_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'");

    $row = mysqli_fetch_assoc($result);
    return ['revenue' => bcadd($row['revenue'], '0', 2), 'cogs' => bcadd($row['cogs'], '0', 2)];
}

function buildFinanceSummary($conn, $company_id, $ytd_from, $ytd_to) {
    $totals               = buildFinanceRevenueCogs($conn, $company_id, $ytd_from, $ytd_to);
    $gross_profit          = bcsub($totals['revenue'], $totals['cogs'], 2);
    $gross_margin_percent = bccomp($totals['revenue'], '0', 2) > 0 ? round((float)bcdiv($gross_profit, $totals['revenue'], 8) * 100, 2) : 0;

    return [
        'revenue'              => $totals['revenue'],
        'cogs'                 => $totals['cogs'],
        'gross_profit'         => $gross_profit,
        'gross_margin_percent' => $gross_margin_percent,
    ];
}

function buildFinanceMonthly($conn, $company_id, $monthly_from, $monthly_to) {
    $months = [];
    foreach (buildAnalyticsMonthBuckets($monthly_from, $monthly_to) as $ym) {
        $months[$ym] = ['month' => $ym, 'revenue' => '0.00', 'cogs' => '0.00', 'net_profit' => '0.00'];
    }

    $result = mysqli_query($conn, "SELECT DATE_FORMAT(sp.created_at, '%Y-%m') AS ym,
            SUM(spi.price * spi.quantity) AS revenue,
            SUM(spi.landed_cost * spi.quantity) AS cogs
        FROM " . APP_SCHEMA . ".sales_profit sp
        JOIN " . APP_SCHEMA . ".sales_profit_item spi ON spi.sales_profit_id = sp.id AND spi.deleted_at IS NULL
        WHERE sp.company_id = '$company_id' AND sp.deleted_at IS NULL
          AND sp.created_at BETWEEN '$monthly_from 00:00:00' AND '$monthly_to 23:59:59'
        GROUP BY ym LIMIT 100");
    while ($row = mysqli_fetch_assoc($result)) {
        if (isset($months[$row['ym']])) {
            $revenue = bcadd($row['revenue'], '0', 2);
            $cogs    = bcadd($row['cogs'], '0', 2);
            $months[$row['ym']]['revenue']    = $revenue;
            $months[$row['ym']]['cogs']       = $cogs;
            $months[$row['ym']]['net_profit'] = bcsub($revenue, $cogs, 2);
        }
    }

    return array_values($months);
}

// Point-in-time outstanding balances aggregated by partner — ar-ap-report
// returns per-invoice rows, not a per-partner ranking, so this re-aggregates
// the same finance_payment base data ar-ap-report already uses.
function buildFinanceTopDebtors($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT partner_name, SUM(outstanding) AS outstanding FROM (
            SELECT c.id AS partner_id, c.customer_name AS partner_name,
                   MAX(fp.due_amount) - SUM(COALESCE(fp.paid_amount, 0)) AS outstanding
            FROM " . APP_SCHEMA . ".finance_payment fp
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = fp.customer_id
            WHERE fp.company_id = '$company_id' AND fp.customer_id IS NOT NULL AND fp.deleted_at IS NULL
            GROUP BY fp.invoice_number, c.id, c.customer_name
            HAVING outstanding > 0
        ) t
        GROUP BY partner_name ORDER BY outstanding DESC LIMIT 10");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['outstanding'] = bcadd($row['outstanding'], '0', 2);

    return $rows;
}

function buildFinanceTopCreditors($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT partner_name, SUM(outstanding) AS outstanding FROM (
            SELECT s.id AS partner_id, s.supplier_name AS partner_name,
                   MAX(fp.due_amount) - SUM(COALESCE(fp.paid_amount, 0)) AS outstanding
            FROM " . APP_SCHEMA . ".finance_payment fp
            LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = fp.supplier_id
            WHERE fp.company_id = '$company_id' AND fp.supplier_id IS NOT NULL AND fp.deleted_at IS NULL
            GROUP BY fp.invoice_number, s.id, s.supplier_name
            HAVING outstanding > 0
        ) t
        GROUP BY partner_name ORDER BY outstanding DESC LIMIT 10");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['outstanding'] = bcadd($row['outstanding'], '0', 2);

    return $rows;
}

function getFinanceAnalytics($conn, $company_id, $params) {
    [$ytd_from, $ytd_to]         = resolveAnalyticsYtdRange($params);
    [$monthly_from, $monthly_to] = resolveAnalyticsMonthlyRange($params);

    $ytd_from     = mysqli_real_escape_string($conn, $ytd_from);
    $ytd_to       = mysqli_real_escape_string($conn, $ytd_to);
    $monthly_from = mysqli_real_escape_string($conn, $monthly_from);
    $monthly_to   = mysqli_real_escape_string($conn, $monthly_to);

    jsonResponse(200, 'Finance analytics found', [
        'summary'       => buildFinanceSummary($conn, $company_id, $ytd_from, $ytd_to),
        'monthly'       => buildFinanceMonthly($conn, $company_id, $monthly_from, $monthly_to),
        'top_debtors'   => buildFinanceTopDebtors($conn, $company_id),
        'top_creditors' => buildFinanceTopCreditors($conn, $company_id),
    ]);
}
