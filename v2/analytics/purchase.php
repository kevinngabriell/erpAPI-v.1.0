<?php

function buildPurchaseSummary($conn, $company_id, $ytd_from, $ytd_to, $default_from, $default_to) {
    $ytd_result = mysqli_query($conn, "SELECT COALESCE(SUM(pii.quantity * pii.unit_price), 0) AS total
        FROM " . APP_SCHEMA . ".purchase_invoice pi
        JOIN " . APP_SCHEMA . ".purchase_invoice_item pii ON pii.purchase_invoice_id = pi.id AND pii.deleted_at IS NULL
        WHERE pi.company_id = '$company_id' AND pi.deleted_at IS NULL
          AND pi.invoice_date BETWEEN '$ytd_from' AND '$ytd_to'");
    $total_purchase_ytd = (float)mysqli_fetch_assoc($ytd_result)['total'];

    // Cycle time — POs whose first receipt landed in the window, mean
    // days between po_date and that first purchase_receive.
    $cycle_result = mysqli_query($conn, "SELECT AVG(cycle_days) AS avg_days FROM (
            SELECT DATEDIFF(MIN(pr.receiving_date), po.po_date) AS cycle_days, MIN(pr.receiving_date) AS first_receipt
            FROM " . APP_SCHEMA . ".purchase_order po
            JOIN " . APP_SCHEMA . ".purchase_receive pr ON pr.purchase_order_id = po.id AND pr.deleted_at IS NULL
            WHERE po.company_id = '$company_id' AND po.deleted_at IS NULL
            GROUP BY po.id, po.po_date
            HAVING first_receipt BETWEEN '$default_from' AND '$default_to'
        ) t");
    $avg_days             = mysqli_fetch_assoc($cycle_result)['avg_days'];
    $avg_cycle_time_days   = $avg_days !== null ? round((float)$avg_days, 1) : 0;

    $import_result = mysqli_query($conn, "SELECT
            SUM(CASE WHEN LOWER(pty.type_name) LIKE '%import%' THEN pii.quantity * pii.unit_price ELSE 0 END) AS import_total,
            SUM(pii.quantity * pii.unit_price) AS grand_total
        FROM " . APP_SCHEMA . ".purchase_invoice pi
        JOIN " . APP_SCHEMA . ".purchase_invoice_item pii ON pii.purchase_invoice_id = pi.id AND pii.deleted_at IS NULL
        LEFT JOIN " . APP_SCHEMA . ".purchase_order po ON po.id = pi.purchase_order_id
        LEFT JOIN " . APP_SCHEMA . ".purchase_type pty ON pty.id = po.type_id
        WHERE pi.company_id = '$company_id' AND pi.deleted_at IS NULL
          AND pi.invoice_date BETWEEN '$default_from' AND '$default_to'");
    $import_row           = mysqli_fetch_assoc($import_result);
    $import_total          = (float)$import_row['import_total'];
    $grand_total            = (float)$import_row['grand_total'];
    $import_share_percent  = $grand_total > 0 ? round($import_total / $grand_total * 100, 2) : 0;

    return [
        'total_purchase_ytd'   => $total_purchase_ytd,
        'avg_cycle_time_days'  => $avg_cycle_time_days,
        'import_share_percent' => $import_share_percent,
    ];
}

function buildPurchaseMonthly($conn, $company_id, $monthly_from, $monthly_to) {
    $months = [];
    foreach (buildAnalyticsMonthBuckets($monthly_from, $monthly_to) as $ym) {
        $months[$ym] = ['month' => $ym, 'total_import' => 0, 'total_local' => 0];
    }

    $result = mysqli_query($conn, "SELECT DATE_FORMAT(pi.invoice_date, '%Y-%m') AS ym,
            SUM(CASE WHEN LOWER(pty.type_name) LIKE '%import%' THEN pii.quantity * pii.unit_price ELSE 0 END) AS total_import,
            SUM(CASE WHEN LOWER(pty.type_name) LIKE '%local%' THEN pii.quantity * pii.unit_price ELSE 0 END) AS total_local
        FROM " . APP_SCHEMA . ".purchase_invoice pi
        JOIN " . APP_SCHEMA . ".purchase_invoice_item pii ON pii.purchase_invoice_id = pi.id AND pii.deleted_at IS NULL
        LEFT JOIN " . APP_SCHEMA . ".purchase_order po ON po.id = pi.purchase_order_id
        LEFT JOIN " . APP_SCHEMA . ".purchase_type pty ON pty.id = po.type_id
        WHERE pi.company_id = '$company_id' AND pi.deleted_at IS NULL
          AND pi.invoice_date BETWEEN '$monthly_from' AND '$monthly_to'
        GROUP BY ym");
    while ($row = mysqli_fetch_assoc($result)) {
        if (isset($months[$row['ym']])) {
            $months[$row['ym']]['total_import'] = (float)$row['total_import'];
            $months[$row['ym']]['total_local']  = (float)$row['total_local'];
        }
    }

    return array_values($months);
}

function buildPurchaseSupplierOrigin($conn, $company_id, $default_from, $default_to) {
    $result = mysqli_query($conn, "SELECT o.origin_name AS country,
            SUM(pii.quantity * pii.unit_price) AS total
        FROM " . APP_SCHEMA . ".purchase_invoice pi
        JOIN " . APP_SCHEMA . ".purchase_invoice_item pii ON pii.purchase_invoice_id = pi.id AND pii.deleted_at IS NULL
        LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = pi.supplier_id
        LEFT JOIN " . APP_SCHEMA . ".origin o ON o.id = s.supplier_origin_id
        WHERE pi.company_id = '$company_id' AND pi.deleted_at IS NULL
          AND pi.invoice_date BETWEEN '$default_from' AND '$default_to'
        GROUP BY o.id, o.origin_name ORDER BY total DESC");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['total'] = (float)$row['total'];

    return $rows;
}

function buildPurchaseTopProducts($conn, $company_id, $default_from, $default_to) {
    $result = mysqli_query($conn, "SELECT pii.product_name,
            SUM(pii.quantity * pii.unit_price) AS total_purchase
        FROM " . APP_SCHEMA . ".purchase_invoice pi
        JOIN " . APP_SCHEMA . ".purchase_invoice_item pii ON pii.purchase_invoice_id = pi.id AND pii.deleted_at IS NULL
        WHERE pi.company_id = '$company_id' AND pi.deleted_at IS NULL
          AND pi.invoice_date BETWEEN '$default_from' AND '$default_to'
        GROUP BY pii.product_name ORDER BY total_purchase DESC LIMIT 10");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['total_purchase'] = (float)$row['total_purchase'];

    return $rows;
}

function buildPurchaseTopSuppliers($conn, $company_id, $default_from, $default_to) {
    $result = mysqli_query($conn, "SELECT s.supplier_name,
            SUM(pii.quantity * pii.unit_price) AS total
        FROM " . APP_SCHEMA . ".purchase_invoice pi
        JOIN " . APP_SCHEMA . ".purchase_invoice_item pii ON pii.purchase_invoice_id = pi.id AND pii.deleted_at IS NULL
        LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = pi.supplier_id
        WHERE pi.company_id = '$company_id' AND pi.deleted_at IS NULL
          AND pi.invoice_date BETWEEN '$default_from' AND '$default_to'
        GROUP BY s.id, s.supplier_name ORDER BY total DESC LIMIT 10");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['total'] = (float)$row['total'];

    return $rows;
}

function getPurchaseAnalytics($conn, $company_id, $params) {
    [$ytd_from, $ytd_to]         = resolveAnalyticsYtdRange($params);
    [$monthly_from, $monthly_to] = resolveAnalyticsMonthlyRange($params);
    [$default_from, $default_to] = resolveAnalyticsDefaultRange($params);

    $ytd_from     = mysqli_real_escape_string($conn, $ytd_from);
    $ytd_to       = mysqli_real_escape_string($conn, $ytd_to);
    $monthly_from = mysqli_real_escape_string($conn, $monthly_from);
    $monthly_to   = mysqli_real_escape_string($conn, $monthly_to);
    $default_from = mysqli_real_escape_string($conn, $default_from);
    $default_to   = mysqli_real_escape_string($conn, $default_to);

    jsonResponse(200, 'Purchase analytics found', [
        'summary'         => buildPurchaseSummary($conn, $company_id, $ytd_from, $ytd_to, $default_from, $default_to),
        'monthly'         => buildPurchaseMonthly($conn, $company_id, $monthly_from, $monthly_to),
        'supplier_origin' => buildPurchaseSupplierOrigin($conn, $company_id, $default_from, $default_to),
        'top_products'    => buildPurchaseTopProducts($conn, $company_id, $default_from, $default_to),
        'top_suppliers'   => buildPurchaseTopSuppliers($conn, $company_id, $default_from, $default_to),
    ]);
}
