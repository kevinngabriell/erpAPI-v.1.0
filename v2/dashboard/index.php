<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

// Purchase-order status_id literals — same values already relied on by the
// purchase-order approval flow and the pre-existing overview widgets.
const PO_STATUS_DRAFT    = 'd7ab6134-d157-11ee-8';
const PO_STATUS_APPROVED = 'e71e4fc4-d157-11ee-8';
const PO_STATUS_RECEIVED = 'e73d9d9c-1438-11ef-9';
const PO_STATUS_INVOICED = 'e4376c01-1438-11ef-9';

function getPermittedDashboardKeys($conn, $app_role_id) {
    $permitted = [];
    $result = mysqli_query($conn, "SELECT p.permission_key
        FROM " . CORE_SCHEMA . ".app_role_permission rp
        JOIN " . CORE_SCHEMA . ".app_permission p ON p.permission_id = rp.permission_id
        WHERE rp.app_role_id = '$app_role_id' AND p.permission_key LIKE 'dashboard.%'");
    while ($row = mysqli_fetch_assoc($result)) {
        $permitted[$row['permission_key']] = true;
    }
    return $permitted;
}

function agingBucket($days) {
    if ($days <= 0)  return 'current';
    if ($days <= 30) return '30';
    if ($days <= 60) return '60';
    if ($days <= 90) return '90';
    return '90+';
}

// ── Business Owner ──────────────────────────────────────────────────────────

function buildRevenueTrend($conn, $company_id) {
    $months = [];
    for ($i = 5; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("-$i months"));
        $months[$ym] = ['month' => $ym, 'revenue' => 0];
    }

    $result = mysqli_query($conn, "SELECT DATE_FORMAT(si.invoice_date, '%Y-%m') AS ym,
            SUM(sii.quantity * sii.unit_price) AS revenue
        FROM " . APP_SCHEMA . ".sales_invoice si
        JOIN " . APP_SCHEMA . ".sales_invoice_item sii ON sii.sales_invoice_id = si.id AND sii.deleted_at IS NULL
        WHERE si.company_id = '$company_id' AND si.deleted_at IS NULL
          AND si.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY ym");
    while ($row = mysqli_fetch_assoc($result)) {
        if (isset($months[$row['ym']])) {
            $months[$row['ym']]['revenue'] = (float)$row['revenue'];
        }
    }

    return array_values($months);
}

function buildProfitSummary($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT
            DATE_FORMAT(sp.created_at, '%Y-%m') AS ym,
            SUM((spi.price - spi.landed_cost) * spi.quantity) AS profit,
            SUM(spi.price * spi.quantity) AS revenue
        FROM " . APP_SCHEMA . ".sales_profit sp
        JOIN " . APP_SCHEMA . ".sales_profit_item spi ON spi.sales_profit_id = sp.id AND spi.deleted_at IS NULL
        WHERE sp.company_id = '$company_id' AND sp.deleted_at IS NULL
          AND sp.created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
        GROUP BY ym");

    $this_month = date('Y-m');
    $last_month = date('Y-m', strtotime('-1 month'));
    $summary = [
        $this_month => ['profit' => 0, 'revenue' => 0],
        $last_month => ['profit' => 0, 'revenue' => 0],
    ];
    while ($row = mysqli_fetch_assoc($result)) {
        if (isset($summary[$row['ym']])) {
            $summary[$row['ym']] = ['profit' => (float)$row['profit'], 'revenue' => (float)$row['revenue']];
        }
    }

    $margin = fn($s) => $s['revenue'] > 0 ? round($s['profit'] / $s['revenue'] * 100, 2) : 0;

    return [
        'this_month' => array_merge($summary[$this_month], ['margin_percent' => $margin($summary[$this_month])]),
        'last_month' => array_merge($summary[$last_month], ['margin_percent' => $margin($summary[$last_month])]),
    ];
}

function buildCashPosition($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT ba.id, ba.bank_name, ba.bank_number,
            COALESCE(SUM(ft.amount), 0) AS balance
        FROM " . APP_SCHEMA . ".bank_account ba
        LEFT JOIN " . APP_SCHEMA . ".finance_transaction ft
               ON ft.bank_account_id = ba.id AND ft.deleted_at IS NULL
        WHERE ba.company_id = '$company_id' AND ba.deleted_at IS NULL
        GROUP BY ba.id, ba.bank_name, ba.bank_number");

    $accounts    = [];
    $total_cash  = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        $balance      = (float)$row['balance'];
        $total_cash  += $balance;
        $accounts[]   = [
            'bank_account_id' => $row['id'],
            'bank_name'       => $row['bank_name'],
            'bank_number'     => $row['bank_number'],
            'balance'         => $balance,
        ];
    }

    return ['total_cash' => $total_cash, 'accounts' => $accounts];
}

function buildArApSummary($conn, $company_id) {
    $buckets = ['current' => 0, '30' => 0, '60' => 0, '90' => 0, '90+' => 0];
    $ar = $buckets;
    $ap = $buckets;

    $result = mysqli_query($conn, "SELECT
            MAX(fp.due_amount) - SUM(COALESCE(fp.paid_amount, 0)) AS outstanding,
            DATEDIFF(CURDATE(), DATE_ADD(si.invoice_date, INTERVAL COALESCE(c.customer_top_days, 0) DAY)) AS days_overdue
        FROM " . APP_SCHEMA . ".finance_payment fp
        LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = fp.customer_id
        LEFT JOIN " . APP_SCHEMA . ".sales_invoice si ON si.invoice_display_number = fp.invoice_number AND si.company_id = fp.company_id
        WHERE fp.company_id = '$company_id' AND fp.customer_id IS NOT NULL AND fp.deleted_at IS NULL
        GROUP BY fp.invoice_number, si.invoice_date, c.customer_top_days
        HAVING outstanding > 0");
    while ($row = mysqli_fetch_assoc($result)) {
        $ar[agingBucket((int)$row['days_overdue'])] += (float)$row['outstanding'];
    }

    $result = mysqli_query($conn, "SELECT
            MAX(fp.due_amount) - SUM(COALESCE(fp.paid_amount, 0)) AS outstanding,
            DATEDIFF(CURDATE(), DATE_ADD(pi.invoice_date, INTERVAL COALESCE(pt.days, 0) DAY)) AS days_overdue
        FROM " . APP_SCHEMA . ".finance_payment fp
        LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = fp.supplier_id
        LEFT JOIN " . APP_SCHEMA . ".payment_term pt ON pt.id = s.supplier_term_id
        LEFT JOIN " . APP_SCHEMA . ".purchase_invoice pi ON pi.invoice_display_number = fp.invoice_number AND pi.company_id = fp.company_id
        WHERE fp.company_id = '$company_id' AND fp.supplier_id IS NOT NULL AND fp.deleted_at IS NULL
        GROUP BY fp.invoice_number, pi.invoice_date, pt.days
        HAVING outstanding > 0");
    while ($row = mysqli_fetch_assoc($result)) {
        $ap[agingBucket((int)$row['days_overdue'])] += (float)$row['outstanding'];
    }

    return ['receivables' => $ar, 'payables' => $ap];
}

function buildPendingApprovals($conn, $company_id) {
    $po_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".purchase_order
        WHERE company_id = '$company_id' AND deleted_at IS NULL AND approved_by IS NULL");
    $so_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".sales_order
        WHERE company_id = '$company_id' AND deleted_at IS NULL AND approved_by IS NULL");

    return [
        'purchase_orders' => (int)mysqli_fetch_assoc($po_result)['total'],
        'sales_orders'    => (int)mysqli_fetch_assoc($so_result)['total'],
    ];
}

function buildTopPartners($conn, $company_id) {
    $customers = mysqli_fetch_all(mysqli_query($conn, "SELECT c.id AS customer_id, c.customer_name,
            SUM(sii.quantity * sii.unit_price) AS total
        FROM " . APP_SCHEMA . ".sales_invoice si
        JOIN " . APP_SCHEMA . ".sales_invoice_item sii ON sii.sales_invoice_id = si.id AND sii.deleted_at IS NULL
        JOIN " . APP_SCHEMA . ".customer c ON c.id = si.customer_id
        WHERE si.company_id = '$company_id' AND si.deleted_at IS NULL
          AND MONTH(si.invoice_date) = MONTH(CURDATE()) AND YEAR(si.invoice_date) = YEAR(CURDATE())
        GROUP BY c.id, c.customer_name ORDER BY total DESC LIMIT 5"), MYSQLI_ASSOC);

    $suppliers = mysqli_fetch_all(mysqli_query($conn, "SELECT s.id AS supplier_id, s.supplier_name,
            SUM(pii.quantity * pii.unit_price) AS total
        FROM " . APP_SCHEMA . ".purchase_invoice pi
        JOIN " . APP_SCHEMA . ".purchase_invoice_item pii ON pii.purchase_invoice_id = pi.id AND pii.deleted_at IS NULL
        JOIN " . APP_SCHEMA . ".supplier s ON s.id = pi.supplier_id
        WHERE pi.company_id = '$company_id' AND pi.deleted_at IS NULL
          AND MONTH(pi.invoice_date) = MONTH(CURDATE()) AND YEAR(pi.invoice_date) = YEAR(CURDATE())
        GROUP BY s.id, s.supplier_name ORDER BY total DESC LIMIT 5"), MYSQLI_ASSOC);

    foreach ($customers as &$row) $row['total'] = (float)$row['total'];
    foreach ($suppliers as &$row) $row['total'] = (float)$row['total'];

    return ['top_customers' => $customers, 'top_suppliers' => $suppliers];
}

function buildSubscriptionStatus($authUser) {
    return ['days_remaining' => (int)($authUser['days_remaining'] ?? 0)];
}

function buildPendingUsers($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . CORE_SCHEMA . ".app_user
        WHERE company_id = '$company_id' AND app_id = '" . APP_ID . "' AND account_status = 'pending'");
    return ['total_pending' => (int)mysqli_fetch_assoc($result)['total']];
}

// ── Manager ──────────────────────────────────────────────────────────────────

function buildExceptions($conn, $company_id) {
    $overdue_pos = mysqli_fetch_all(mysqli_query($conn, "SELECT po.id, po.po_display_number, po.eta_date
        FROM " . APP_SCHEMA . ".purchase_order po
        LEFT JOIN " . APP_SCHEMA . ".purchase_receive pr ON pr.purchase_order_id = po.id AND pr.deleted_at IS NULL
        WHERE po.company_id = '$company_id' AND po.deleted_at IS NULL AND po.approved_by IS NOT NULL
          AND po.eta_date IS NOT NULL AND po.eta_date < CURDATE() AND pr.id IS NULL"), MYSQLI_ASSOC);

    $delayed_deliveries = mysqli_fetch_all(mysqli_query($conn, "SELECT sd.id, sd.do_display_number, sd.eta_date
        FROM " . APP_SCHEMA . ".sales_delivery sd
        WHERE sd.company_id = '$company_id' AND sd.deleted_at IS NULL
          AND sd.eta_date IS NOT NULL AND sd.eta_date < CURDATE()"), MYSQLI_ASSOC);

    return [
        'overdue_purchase_orders'  => $overdue_pos,
        'delayed_sales_deliveries' => $delayed_deliveries,
    ];
}

function buildApprovalQueue($conn, $company_id) {
    return buildPendingApprovals($conn, $company_id);
}

function buildActivityLog($conn, $company_id) {
    return mysqli_fetch_all(mysqli_query($conn, "SELECT module, reference_id, action, action_by, action_at
        FROM " . APP_SCHEMA . ".audit_log
        WHERE company_id = '$company_id'
        ORDER BY action_at DESC LIMIT 20"), MYSQLI_ASSOC);
}

// ── Finance ──────────────────────────────────────────────────────────────────

function buildBukuKasSnapshot($conn, $company_id) {
    $today_result = mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) AS total FROM " . APP_SCHEMA . ".finance_transaction
        WHERE company_id = '$company_id' AND deleted_at IS NULL AND transaction_date = CURDATE()");
    $mtd_result = mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) AS total FROM " . APP_SCHEMA . ".finance_transaction
        WHERE company_id = '$company_id' AND deleted_at IS NULL
          AND MONTH(transaction_date) = MONTH(CURDATE()) AND YEAR(transaction_date) = YEAR(CURDATE())");

    return [
        'today_net' => (float)mysqli_fetch_assoc($today_result)['total'],
        'mtd_net'   => (float)mysqli_fetch_assoc($mtd_result)['total'],
    ];
}

function buildBankBalances($conn, $company_id) {
    return buildCashPosition($conn, $company_id)['accounts'];
}

function buildFinanceEntriesToday($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT id, voucher_number, bank_account_id, amount, payee, created_by, created_at
        FROM " . APP_SCHEMA . ".finance_transaction
        WHERE company_id = '$company_id' AND deleted_at IS NULL AND transaction_date = CURDATE()
        ORDER BY created_at DESC");
    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['amount'] = (float)$row['amount'];

    return ['total_entries' => count($rows), 'data' => $rows];
}

// ── Accounting ───────────────────────────────────────────────────────────────

function buildPnlSnapshot($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT ac.account_type, COALESCE(SUM(ftd.amount), 0) AS total
        FROM " . APP_SCHEMA . ".finance_transaction_detail ftd
        JOIN " . APP_SCHEMA . ".finance_transaction ft ON ft.id = ftd.finance_transaction_id AND ft.deleted_at IS NULL
        JOIN " . APP_SCHEMA . ".account_code ac ON ac.id = ftd.account_code_id
        WHERE ft.company_id = '$company_id' AND ftd.deleted_at IS NULL
          AND ac.account_type IN ('revenue', 'expense')
          AND MONTH(ft.transaction_date) = MONTH(CURDATE()) AND YEAR(ft.transaction_date) = YEAR(CURDATE())
        GROUP BY ac.account_type");

    $totals = ['revenue' => 0, 'expense' => 0];
    while ($row = mysqli_fetch_assoc($result)) {
        $totals[$row['account_type']] = (float)$row['total'];
    }

    return [
        'revenue' => $totals['revenue'],
        'expense' => $totals['expense'],
        'profit'  => $totals['revenue'] - $totals['expense'],
    ];
}

function buildNeracaSnapshot($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT ac.account_type, COALESCE(SUM(ftd.amount), 0) AS total
        FROM " . APP_SCHEMA . ".finance_transaction_detail ftd
        JOIN " . APP_SCHEMA . ".finance_transaction ft ON ft.id = ftd.finance_transaction_id AND ft.deleted_at IS NULL
        JOIN " . APP_SCHEMA . ".account_code ac ON ac.id = ftd.account_code_id
        WHERE ft.company_id = '$company_id' AND ftd.deleted_at IS NULL
          AND ac.account_type IN ('asset', 'liability', 'equity')
        GROUP BY ac.account_type");

    $totals = ['asset' => 0, 'liability' => 0, 'equity' => 0];
    while ($row = mysqli_fetch_assoc($result)) {
        $totals[$row['account_type']] = (float)$row['total'];
    }

    return $totals;
}

function buildBukuBesarSummary($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT ac.id AS account_code_id, ac.account_code, ac.account_code_name,
            COALESCE(SUM(ftd.amount), 0) AS total
        FROM " . APP_SCHEMA . ".account_code ac
        LEFT JOIN " . APP_SCHEMA . ".finance_transaction_detail ftd
               ON ftd.account_code_id = ac.id AND ftd.deleted_at IS NULL
        LEFT JOIN " . APP_SCHEMA . ".finance_transaction ft
               ON ft.id = ftd.finance_transaction_id AND ft.deleted_at IS NULL
        WHERE ac.company_id = '$company_id' AND ac.deleted_at IS NULL
          AND (ftd.id IS NULL OR ft.id IS NOT NULL)
        GROUP BY ac.id, ac.account_code, ac.account_code_name
        ORDER BY ac.account_code ASC");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['total'] = (float)$row['total'];

    return $rows;
}

function buildArAging($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT
            fp.invoice_number, c.customer_name, c.id AS customer_id, si.invoice_date,
            MAX(fp.due_amount) - SUM(COALESCE(fp.paid_amount, 0)) AS outstanding,
            DATEDIFF(CURDATE(), DATE_ADD(si.invoice_date, INTERVAL COALESCE(c.customer_top_days, 0) DAY)) AS days_overdue
        FROM " . APP_SCHEMA . ".finance_payment fp
        LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = fp.customer_id
        LEFT JOIN " . APP_SCHEMA . ".sales_invoice si ON si.invoice_display_number = fp.invoice_number AND si.company_id = fp.company_id
        WHERE fp.company_id = '$company_id' AND fp.customer_id IS NOT NULL AND fp.deleted_at IS NULL
        GROUP BY fp.invoice_number, c.customer_name, c.id, si.invoice_date, c.customer_top_days
        HAVING outstanding > 0
        ORDER BY days_overdue DESC");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) {
        $row['outstanding']  = (float)$row['outstanding'];
        $row['days_overdue'] = (int)$row['days_overdue'];
        $row['bucket']       = agingBucket($row['days_overdue']);
    }

    return $rows;
}

function buildApAging($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT
            fp.invoice_number, s.supplier_name, s.id AS supplier_id, pi.invoice_date,
            MAX(fp.due_amount) - SUM(COALESCE(fp.paid_amount, 0)) AS outstanding,
            DATEDIFF(CURDATE(), DATE_ADD(pi.invoice_date, INTERVAL COALESCE(pt.days, 0) DAY)) AS days_overdue
        FROM " . APP_SCHEMA . ".finance_payment fp
        LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = fp.supplier_id
        LEFT JOIN " . APP_SCHEMA . ".payment_term pt ON pt.id = s.supplier_term_id
        LEFT JOIN " . APP_SCHEMA . ".purchase_invoice pi ON pi.invoice_display_number = fp.invoice_number AND pi.company_id = fp.company_id
        WHERE fp.company_id = '$company_id' AND fp.supplier_id IS NOT NULL AND fp.deleted_at IS NULL
        GROUP BY fp.invoice_number, s.supplier_name, s.id, pi.invoice_date, pt.days
        HAVING outstanding > 0
        ORDER BY days_overdue DESC");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) {
        $row['outstanding']  = (float)$row['outstanding'];
        $row['days_overdue'] = (int)$row['days_overdue'];
        $row['bucket']       = agingBucket($row['days_overdue']);
    }

    return $rows;
}

function buildTaxDue($conn, $company_id) {
    $sales_pending = mysqli_fetch_all(mysqli_query($conn, "SELECT id, invoice_display_number, invoice_date, customer_id
        FROM " . APP_SCHEMA . ".sales_invoice
        WHERE company_id = '$company_id' AND deleted_at IS NULL AND tax_invoice_number IS NULL
        ORDER BY invoice_date ASC"), MYSQLI_ASSOC);

    $purchase_pending = mysqli_fetch_all(mysqli_query($conn, "SELECT id, invoice_display_number, invoice_date, supplier_id
        FROM " . APP_SCHEMA . ".purchase_invoice
        WHERE company_id = '$company_id' AND deleted_at IS NULL AND tax_invoice_number IS NULL
        ORDER BY invoice_date ASC"), MYSQLI_ASSOC);

    return [
        'sales_invoices_pending_faktur'    => $sales_pending,
        'purchase_invoices_pending_faktur' => $purchase_pending,
    ];
}

// ── Admin Purchase ───────────────────────────────────────────────────────────

function buildOpenPos($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT po.id, po.po_display_number, po.po_date, s.supplier_name,
            SUM(poi.quantity * poi.unit_price) AS total
        FROM " . APP_SCHEMA . ".purchase_order po
        LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = po.supplier_id
        JOIN " . APP_SCHEMA . ".purchase_order_item poi ON poi.purchase_order_id = po.id AND poi.deleted_at IS NULL
        WHERE po.company_id = '$company_id' AND po.deleted_at IS NULL
          AND po.approved_by IS NOT NULL AND po.status_id != '" . PO_STATUS_INVOICED . "'
        GROUP BY po.id, po.po_display_number, po.po_date, s.supplier_name
        ORDER BY total DESC LIMIT 5");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['total'] = (float)$row['total'];

    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".purchase_order
        WHERE company_id = '$company_id' AND deleted_at IS NULL
          AND approved_by IS NOT NULL AND status_id != '" . PO_STATUS_INVOICED . "'");

    return ['total_open' => (int)mysqli_fetch_assoc($count_result)['total'], 'top_by_value' => $rows];
}

function buildPoPendingApproval($conn, $company_id) {
    return ['total_pending' => buildPendingApprovals($conn, $company_id)['purchase_orders']];
}

function buildIncomingEta($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT id, po_display_number, eta_date, vessel_name, container_number, bl_number
        FROM " . APP_SCHEMA . ".purchase_order
        WHERE company_id = '$company_id' AND deleted_at IS NULL AND approved_by IS NOT NULL
          AND eta_date IS NOT NULL AND eta_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY eta_date ASC");

    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

function buildInvoiceMatching($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT COUNT(*) AS total
        FROM " . APP_SCHEMA . ".purchase_order po
        JOIN " . APP_SCHEMA . ".purchase_receive pr ON pr.purchase_order_id = po.id AND pr.deleted_at IS NULL
        LEFT JOIN " . APP_SCHEMA . ".purchase_invoice pi ON pi.purchase_order_id = po.id AND pi.deleted_at IS NULL
        WHERE po.company_id = '$company_id' AND po.deleted_at IS NULL AND pi.id IS NULL");

    return ['received_not_invoiced' => (int)mysqli_fetch_assoc($result)['total']];
}

// ── Admin Sales ──────────────────────────────────────────────────────────────

function buildOpenSos($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".sales_order
        WHERE company_id = '$company_id' AND deleted_at IS NULL AND approved_by IS NOT NULL");

    return ['total_open' => (int)mysqli_fetch_assoc($result)['total']];
}

function buildSoPendingApproval($conn, $company_id) {
    return ['total_pending' => buildPendingApprovals($conn, $company_id)['sales_orders']];
}

function buildDeliveryStatus($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT
            SUM(CASE WHEN sd.id IS NULL THEN 1 ELSE 0 END) AS not_yet_delivered,
            SUM(CASE WHEN sd.id IS NOT NULL THEN 1 ELSE 0 END) AS delivered
        FROM " . APP_SCHEMA . ".sales_order so
        LEFT JOIN " . APP_SCHEMA . ".sales_delivery sd ON sd.sales_order_id = so.id AND sd.deleted_at IS NULL
        WHERE so.company_id = '$company_id' AND so.deleted_at IS NULL AND so.approved_by IS NOT NULL");

    $row = mysqli_fetch_assoc($result);
    return ['not_yet_delivered' => (int)$row['not_yet_delivered'], 'delivered' => (int)$row['delivered']];
}

function buildSppbPending($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT COUNT(*) AS total
        FROM " . APP_SCHEMA . ".sales_order so
        LEFT JOIN " . APP_SCHEMA . ".sales_sppb sp ON sp.sales_order_id = so.id AND sp.deleted_at IS NULL
        WHERE so.company_id = '$company_id' AND so.deleted_at IS NULL AND so.approved_by IS NOT NULL AND sp.id IS NULL");

    return ['total_pending' => (int)mysqli_fetch_assoc($result)['total']];
}

function buildOrderBacklog($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT COALESCE(SUM(soi.quantity * soi.unit_price), 0) AS total
        FROM " . APP_SCHEMA . ".sales_order so
        JOIN " . APP_SCHEMA . ".sales_order_item soi ON soi.sales_order_id = so.id AND soi.deleted_at IS NULL
        LEFT JOIN " . APP_SCHEMA . ".sales_delivery sd ON sd.sales_order_id = so.id AND sd.deleted_at IS NULL
        WHERE so.company_id = '$company_id' AND so.deleted_at IS NULL AND so.approved_by IS NOT NULL AND sd.id IS NULL");

    return ['backlog_value' => (float)mysqli_fetch_assoc($result)['total']];
}

function buildTopCustomersMtd($conn, $company_id) {
    return buildTopPartners($conn, $company_id)['top_customers'];
}

// ── Logistics / Import Coordinator ───────────────────────────────────────────

function buildShipmentsInTransit($conn, $company_id) {
    $purchase = mysqli_fetch_all(mysqli_query($conn, "SELECT id, po_display_number, vessel_name, container_number, bl_number, etd_date, eta_date
        FROM " . APP_SCHEMA . ".purchase_order
        WHERE company_id = '$company_id' AND deleted_at IS NULL AND approved_by IS NOT NULL
          AND status_id != '" . PO_STATUS_RECEIVED . "' AND status_id != '" . PO_STATUS_INVOICED . "'
          AND (vessel_name IS NOT NULL OR container_number IS NOT NULL)
        ORDER BY eta_date ASC"), MYSQLI_ASSOC);

    $sales = mysqli_fetch_all(mysqli_query($conn, "SELECT id, do_display_number, vessel_name, container_number, bl_number, etd_date, eta_date
        FROM " . APP_SCHEMA . ".sales_delivery
        WHERE company_id = '$company_id' AND deleted_at IS NULL
          AND (vessel_name IS NOT NULL OR container_number IS NOT NULL)
        ORDER BY eta_date ASC"), MYSQLI_ASSOC);

    return ['inbound' => $purchase, 'outbound' => $sales];
}

function buildSppbTracker($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT sp.id, sp.sppb_display_number, sp.sppb_date, c.customer_name
        FROM " . APP_SCHEMA . ".sales_sppb sp
        LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = sp.customer_id
        WHERE sp.company_id = '$company_id' AND sp.deleted_at IS NULL
        ORDER BY sp.created_at DESC LIMIT 10");

    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

function buildContainerLookup($conn, $company_id) {
    $purchase = mysqli_fetch_all(mysqli_query($conn, "SELECT id, po_display_number, container_number, bl_number, vessel_name
        FROM " . APP_SCHEMA . ".purchase_order
        WHERE company_id = '$company_id' AND deleted_at IS NULL AND container_number IS NOT NULL"), MYSQLI_ASSOC);

    $sales = mysqli_fetch_all(mysqli_query($conn, "SELECT id, do_display_number, container_number, bl_number, vessel_name
        FROM " . APP_SCHEMA . ".sales_delivery
        WHERE company_id = '$company_id' AND deleted_at IS NULL AND container_number IS NOT NULL"), MYSQLI_ASSOC);

    return ['inbound' => $purchase, 'outbound' => $sales];
}

function buildAtRiskShipments($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT po.id, po.po_display_number, po.eta_date, po.vessel_name, po.container_number
        FROM " . APP_SCHEMA . ".purchase_order po
        WHERE po.company_id = '$company_id' AND po.deleted_at IS NULL AND po.approved_by IS NOT NULL
          AND po.eta_date IS NOT NULL
          AND po.eta_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
          AND po.status_id != '" . PO_STATUS_RECEIVED . "' AND po.status_id != '" . PO_STATUS_INVOICED . "'");

    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

// ── Gudang ───────────────────────────────────────────────────────────────────

function buildStockMovementsToday($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT wt.transaction_type, COUNT(*) AS total
        FROM " . APP_SCHEMA . ".warehouse_transaction wt
        WHERE wt.company_id = '$company_id' AND wt.deleted_at IS NULL AND wt.transaction_date = CURDATE()
        GROUP BY wt.transaction_type");

    $counts = ['stock_in' => 0, 'stock_out' => 0, 'adjustment' => 0, 'transfer' => 0];
    while ($row = mysqli_fetch_assoc($result)) {
        $counts[$row['transaction_type']] = (int)$row['total'];
    }

    return $counts;
}

function buildPendingReceive($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT po.id, po.po_display_number, po.eta_date, s.supplier_name
        FROM " . APP_SCHEMA . ".purchase_order po
        LEFT JOIN " . APP_SCHEMA . ".purchase_receive pr ON pr.purchase_order_id = po.id AND pr.deleted_at IS NULL
        LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = po.supplier_id
        WHERE po.company_id = '$company_id' AND po.deleted_at IS NULL AND po.approved_by IS NOT NULL AND pr.id IS NULL
        ORDER BY po.eta_date ASC");

    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

function buildPendingOutbound($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT so.id, so.so_display_number, so.so_date, c.customer_name
        FROM " . APP_SCHEMA . ".sales_order so
        LEFT JOIN " . APP_SCHEMA . ".sales_delivery sd ON sd.sales_order_id = so.id AND sd.deleted_at IS NULL
        LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = so.customer_id
        WHERE so.company_id = '$company_id' AND so.deleted_at IS NULL AND so.approved_by IS NOT NULL AND sd.id IS NULL
        ORDER BY so.so_date ASC");

    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

// ── Kepala Gudang ────────────────────────────────────────────────────────────

function buildStockByLocation($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT wl.id AS location_id, wl.location_name,
            COALESCE(SUM(lot.end_balance), 0) AS total_stock
        FROM " . APP_SCHEMA . ".warehouse_location wl
        LEFT JOIN " . APP_SCHEMA . ".warehouse_lot lot
               ON lot.location_id = wl.id AND lot.deleted_at IS NULL
        WHERE wl.company_id = '$company_id' AND wl.deleted_at IS NULL
        GROUP BY wl.id, wl.location_name");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['total_stock'] = (float)$row['total_stock'];

    return $rows;
}

function buildLowStock($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT rp.id AS reorder_point_id, p.id AS product_id, p.product_name,
            wl.id AS location_id, wl.location_name, rp.min_stock,
            COALESCE(SUM(lot.end_balance), 0) AS current_stock
        FROM " . APP_SCHEMA . ".reorder_point rp
        JOIN " . APP_SCHEMA . ".product p ON p.id = rp.product_id
        JOIN " . APP_SCHEMA . ".warehouse_location wl ON wl.id = rp.location_id
        LEFT JOIN " . APP_SCHEMA . ".warehouse_lot lot
               ON lot.product_id = rp.product_id AND lot.location_id = rp.location_id AND lot.deleted_at IS NULL
        WHERE rp.company_id = '$company_id' AND rp.deleted_at IS NULL
        GROUP BY rp.id, p.id, p.product_name, wl.id, wl.location_name, rp.min_stock
        HAVING current_stock < rp.min_stock
        ORDER BY p.product_name ASC");

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) {
        $row['min_stock']     = (float)$row['min_stock'];
        $row['current_stock'] = (float)$row['current_stock'];
    }

    return $rows;
}

function buildWarehouseMonthlySummary($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT wl.location_name, wt.transaction_type, COUNT(*) AS total
        FROM " . APP_SCHEMA . ".warehouse_transaction wt
        JOIN " . APP_SCHEMA . ".warehouse_transaction_item wti ON wti.warehouse_transaction_id = wt.id
        JOIN " . APP_SCHEMA . ".warehouse_lot lot ON lot.id = wti.warehouse_lot_id
        JOIN " . APP_SCHEMA . ".warehouse_location wl ON wl.id = lot.location_id
        WHERE wt.company_id = '$company_id' AND wt.deleted_at IS NULL
          AND MONTH(wt.transaction_date) = MONTH(CURDATE()) AND YEAR(wt.transaction_date) = YEAR(CURDATE())
        GROUP BY wl.location_name, wt.transaction_type");

    $summary = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $summary[$row['location_name']][$row['transaction_type']] = (int)$row['total'];
    }

    return $summary;
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser    = requireAuth();
$method      = $_SERVER['REQUEST_METHOD'];
$company_id  = $authUser['company_id'] ?? null;
$app_role_id = $authUser['app_role_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}
if (!$app_role_id) {
    jsonResponse(400, 'No role assigned to this account yet.');
    exit;
}
if ($method !== 'GET') {
    jsonResponse(405, 'Method Not Allowed');
    exit;
}

try {
    $conn = getConn();

    // Widgets not listed here (dept_comparison, payment_verification,
    // supplier_scorecard, clearance_mode, adjustment_approvals,
    // discrepancy_flags) have no backing schema yet — see docs/api/dashboard.md
    // "Unavailable widgets". Holding the permission_key just means the widget
    // key never appears in the response, not an error.
    $widgetBuilders = [
        'dashboard.revenue_trend.view'              => fn() => buildRevenueTrend($conn, $company_id),
        'dashboard.profit_summary.view'              => fn() => buildProfitSummary($conn, $company_id),
        'dashboard.cash_position.view'                => fn() => buildCashPosition($conn, $company_id),
        'dashboard.ar_ap_summary.view'                => fn() => buildArApSummary($conn, $company_id),
        'dashboard.pending_approvals.view'            => fn() => buildPendingApprovals($conn, $company_id),
        'dashboard.top_partners.view'                 => fn() => buildTopPartners($conn, $company_id),
        'dashboard.subscription_status.view'          => fn() => buildSubscriptionStatus($authUser),
        'dashboard.pending_users.view'                 => fn() => buildPendingUsers($conn, $company_id),

        'dashboard.exceptions.view'                    => fn() => buildExceptions($conn, $company_id),
        'dashboard.approval_queue.view'               => fn() => buildApprovalQueue($conn, $company_id),
        'dashboard.activity_log.view'                  => fn() => buildActivityLog($conn, $company_id),

        'dashboard.buku_kas_snapshot.view'            => fn() => buildBukuKasSnapshot($conn, $company_id),
        'dashboard.bank_balances.view'                 => fn() => buildBankBalances($conn, $company_id),
        'dashboard.finance_entries_today.view'        => fn() => buildFinanceEntriesToday($conn, $company_id),

        'dashboard.pnl_snapshot.view'                  => fn() => buildPnlSnapshot($conn, $company_id),
        'dashboard.neraca_snapshot.view'               => fn() => buildNeracaSnapshot($conn, $company_id),
        'dashboard.buku_besar_summary.view'           => fn() => buildBukuBesarSummary($conn, $company_id),
        'dashboard.ar_aging.view'                      => fn() => buildArAging($conn, $company_id),
        'dashboard.ap_aging.view'                      => fn() => buildApAging($conn, $company_id),
        'dashboard.tax_due.view'                       => fn() => buildTaxDue($conn, $company_id),

        'dashboard.open_pos.view'                      => fn() => buildOpenPos($conn, $company_id),
        'dashboard.po_pending_approval.view'           => fn() => buildPoPendingApproval($conn, $company_id),
        'dashboard.incoming_eta.view'                  => fn() => buildIncomingEta($conn, $company_id),
        'dashboard.invoice_matching.view'              => fn() => buildInvoiceMatching($conn, $company_id),

        'dashboard.open_sos.view'                      => fn() => buildOpenSos($conn, $company_id),
        'dashboard.so_pending_approval.view'           => fn() => buildSoPendingApproval($conn, $company_id),
        'dashboard.delivery_status.view'                => fn() => buildDeliveryStatus($conn, $company_id),
        'dashboard.sppb_pending.view'                   => fn() => buildSppbPending($conn, $company_id),
        'dashboard.order_backlog.view'                  => fn() => buildOrderBacklog($conn, $company_id),
        'dashboard.top_customers_mtd.view'               => fn() => buildTopCustomersMtd($conn, $company_id),

        'dashboard.shipments_in_transit.view'          => fn() => buildShipmentsInTransit($conn, $company_id),
        'dashboard.sppb_tracker.view'                   => fn() => buildSppbTracker($conn, $company_id),
        'dashboard.container_lookup.view'               => fn() => buildContainerLookup($conn, $company_id),
        'dashboard.at_risk_shipments.view'               => fn() => buildAtRiskShipments($conn, $company_id),

        'dashboard.stock_movements_today.view'          => fn() => buildStockMovementsToday($conn, $company_id),
        'dashboard.pending_receive.view'                => fn() => buildPendingReceive($conn, $company_id),
        'dashboard.pending_outbound.view'               => fn() => buildPendingOutbound($conn, $company_id),

        'dashboard.stock_by_location.view'              => fn() => buildStockByLocation($conn, $company_id),
        'dashboard.warehouse_monthly_summary.view'      => fn() => buildWarehouseMonthlySummary($conn, $company_id),
        'dashboard.low_stock.view'                       => fn() => buildLowStock($conn, $company_id),
    ];

    $permitted = getPermittedDashboardKeys($conn, $app_role_id);

    $widgets = [];
    foreach ($widgetBuilders as $permission_key => $builder) {
        if (!isset($permitted[$permission_key])) continue;
        $widget_key = str_replace(['dashboard.', '.view'], '', $permission_key);
        $widgets[$widget_key] = $builder();
    }

    jsonResponse(200, 'Dashboard found', ['widgets' => $widgets]);

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
