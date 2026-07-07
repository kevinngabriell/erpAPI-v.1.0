<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

function getDashboardOverview($conn, $company_id, $params) {
    $current_year = isset($params['year']) && (int)$params['year'] > 0 ? (int)$params['year'] : (int)date('Y');

    $target_result = mysqli_query($conn, "SELECT target_value FROM " . APP_SCHEMA . ".sales_target WHERE company_id = '$company_id' AND target_year = $current_year AND deleted_at IS NULL LIMIT 1");
    $total_target  = $target_result && mysqli_num_rows($target_result) > 0 ? (float)mysqli_fetch_assoc($target_result)['target_value'] : 0;

    $sales_result = mysqli_query($conn, "SELECT COALESCE(SUM(soi.quantity * soi.unit_price), 0) AS total_sales
        FROM " . APP_SCHEMA . ".sales_order so
        JOIN " . APP_SCHEMA . ".sales_order_item soi ON soi.sales_order_id = so.id AND soi.deleted_at IS NULL
        WHERE so.company_id = '$company_id' AND YEAR(so.so_date) = $current_year AND so.deleted_at IS NULL");
    $total_sales = (float)mysqli_fetch_assoc($sales_result)['total_sales'];

    $purchase_count_result = mysqli_query($conn, "SELECT COUNT(*) AS total_purchase FROM " . APP_SCHEMA . ".purchase_order WHERE company_id = '$company_id' AND YEAR(po_date) = $current_year AND deleted_at IS NULL");
    $total_purchase = (int)mysqli_fetch_assoc($purchase_count_result)['total_purchase'];

    $invoice_count_result = mysqli_query($conn, "SELECT COUNT(*) AS total_invoice
        FROM " . APP_SCHEMA . ".purchase_order po
        JOIN " . APP_SCHEMA . ".purchase_status ps ON ps.id = po.status_id
        WHERE po.company_id = '$company_id' AND YEAR(po.po_date) = $current_year AND po.deleted_at IS NULL AND ps.status_name = 'Invoice'");
    $total_invoice = (int)mysqli_fetch_assoc($invoice_count_result)['total_invoice'];

    $sales_chart = [];
    for ($month = 1; $month <= 12; $month++) {
        $sales_chart[$month] = ['month' => $month, 'total_sales' => 0];
    }
    $sales_chart_result = mysqli_query($conn, "SELECT MONTH(so.so_date) AS month, COALESCE(SUM(soi.quantity * soi.unit_price), 0) AS total_sales
        FROM " . APP_SCHEMA . ".sales_order so
        JOIN " . APP_SCHEMA . ".sales_order_item soi ON soi.sales_order_id = so.id AND soi.deleted_at IS NULL
        WHERE so.company_id = '$company_id' AND YEAR(so.so_date) = $current_year AND so.deleted_at IS NULL
        GROUP BY MONTH(so.so_date)");
    while ($row = mysqli_fetch_assoc($sales_chart_result)) {
        $sales_chart[(int)$row['month']]['total_sales'] = (float)$row['total_sales'];
    }

    $purchase_chart = [];
    for ($month = 1; $month <= 12; $month++) {
        $purchase_chart[$month] = ['month' => $month, 'total_import' => 0, 'total_local' => 0];
    }
    $purchase_chart_result = mysqli_query($conn, "SELECT MONTH(po.po_date) AS month, pt.type_name,
            COALESCE(SUM(poi.quantity * poi.unit_price), 0) AS total_purchase
        FROM " . APP_SCHEMA . ".purchase_order po
        JOIN " . APP_SCHEMA . ".purchase_order_item poi ON poi.purchase_order_id = po.id AND poi.deleted_at IS NULL
        JOIN " . APP_SCHEMA . ".purchase_type pt ON pt.id = po.type_id
        WHERE po.company_id = '$company_id' AND YEAR(po.po_date) = $current_year AND po.deleted_at IS NULL
        GROUP BY MONTH(po.po_date), pt.type_name");
    while ($row = mysqli_fetch_assoc($purchase_chart_result)) {
        $month = (int)$row['month'];
        if ($row['type_name'] === 'Import') {
            $purchase_chart[$month]['total_import'] = (float)$row['total_purchase'];
        } elseif ($row['type_name'] === 'Local') {
            $purchase_chart[$month]['total_local'] = (float)$row['total_purchase'];
        }
    }

    $outstand_supplier_result = mysqli_query($conn, "SELECT COALESCE(SUM(due_amount - paid_amount), 0) AS total_outstand_supplier
        FROM " . APP_SCHEMA . ".finance_payment
        WHERE company_id = '$company_id' AND supplier_id IS NOT NULL AND deleted_at IS NULL");
    $total_outstand_supplier = (float)mysqli_fetch_assoc($outstand_supplier_result)['total_outstand_supplier'];

    $outstand_customer_result = mysqli_query($conn, "SELECT COALESCE(SUM(due_amount - paid_amount), 0) AS total_outstand_customer
        FROM " . APP_SCHEMA . ".finance_payment
        WHERE company_id = '$company_id' AND customer_id IS NOT NULL AND deleted_at IS NULL");
    $total_outstand_customer = (float)mysqli_fetch_assoc($outstand_customer_result)['total_outstand_customer'];

    $order_count_by_country = [];
    $country_result = mysqli_query($conn, "SELECT o.origin_name, COUNT(po.id) AS order_count
        FROM " . APP_SCHEMA . ".purchase_order po
        LEFT JOIN " . APP_SCHEMA . ".origin o ON o.id = po.origin_id
        WHERE po.company_id = '$company_id' AND po.deleted_at IS NULL
        GROUP BY o.origin_name");
    while ($row = mysqli_fetch_assoc($country_result)) {
        $order_count_by_country[] = ['country' => $row['origin_name'], 'order_count' => (int)$row['order_count']];
    }

    $top_purchase_products = [];
    $top_purchase_result = mysqli_query($conn, "SELECT poi.product_name, SUM(poi.quantity * poi.unit_price) AS total_purchase
        FROM " . APP_SCHEMA . ".purchase_order_item poi
        JOIN " . APP_SCHEMA . ".purchase_order po ON po.id = poi.purchase_order_id
        WHERE po.company_id = '$company_id' AND poi.deleted_at IS NULL AND po.deleted_at IS NULL
        GROUP BY poi.product_name ORDER BY total_purchase DESC LIMIT 10");
    while ($row = mysqli_fetch_assoc($top_purchase_result)) {
        $top_purchase_products[] = ['product_name' => $row['product_name'], 'total_purchase' => (float)$row['total_purchase']];
    }

    $top_sales_products = [];
    $top_sales_result = mysqli_query($conn, "SELECT soi.product_name, SUM(soi.quantity * soi.unit_price) AS total_sales
        FROM " . APP_SCHEMA . ".sales_order_item soi
        JOIN " . APP_SCHEMA . ".sales_order so ON so.id = soi.sales_order_id
        WHERE so.company_id = '$company_id' AND soi.deleted_at IS NULL AND so.deleted_at IS NULL
        GROUP BY soi.product_name ORDER BY total_sales DESC LIMIT 10");
    while ($row = mysqli_fetch_assoc($top_sales_result)) {
        $top_sales_products[] = ['product_name' => $row['product_name'], 'total_sales' => (float)$row['total_sales']];
    }

    jsonResponse(200, 'Dashboard overview found', [
        'year'                     => $current_year,
        'total_target'             => $total_target,
        'total_sales'               => $total_sales,
        'total_purchase'            => $total_purchase,
        'total_invoice'             => $total_invoice,
        'total_outstand_supplier'   => $total_outstand_supplier,
        'total_outstand_customer'   => $total_outstand_customer,
        'sales_chart'               => array_values($sales_chart),
        'purchase_chart'            => array_values($purchase_chart),
        'order_count_by_country'    => $order_count_by_country,
        'top_purchase_products'     => $top_purchase_products,
        'top_sales_products'        => $top_sales_products,
    ]);
}

function getPurchaseOverview($conn, $company_id, $params) {
    $current_month = isset($params['month']) && (int)$params['month'] > 0 ? (int)$params['month'] : (int)date('n');
    $current_year  = isset($params['year']) && (int)$params['year'] > 0 ? (int)$params['year'] : (int)date('Y');

    $counts_by_status = ['Draft' => 0, 'Approved' => 0, 'Received' => 0, 'Invoice' => 0];

    $result = mysqli_query($conn, "SELECT ps.status_name, COUNT(*) AS total
        FROM " . APP_SCHEMA . ".purchase_order po
        JOIN " . APP_SCHEMA . ".purchase_status ps ON ps.id = po.status_id
        WHERE po.company_id = '$company_id' AND po.deleted_at IS NULL
          AND MONTH(po.po_date) = $current_month AND YEAR(po.po_date) = $current_year
          AND ps.status_name IN ('Draft', 'Approved', 'Received', 'Invoice')
        GROUP BY ps.status_name");
    while ($row = mysqli_fetch_assoc($result)) {
        $counts_by_status[$row['status_name']] = (int)$row['total'];
    }

    jsonResponse(200, 'Purchase overview found', [
        'month'          => $current_month,
        'year'           => $current_year,
        'total_draft'    => $counts_by_status['Draft'],
        'total_approved' => $counts_by_status['Approved'],
        'total_received' => $counts_by_status['Received'],
        'total_invoice'  => $counts_by_status['Invoice'],
    ]);
}

function getTopSalesOrders($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT so.id, so.so_display_number, so.so_date, c.customer_name
        FROM " . APP_SCHEMA . ".sales_order so
        LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = so.customer_id
        WHERE so.company_id = '$company_id' AND so.deleted_at IS NULL
        ORDER BY so.created_at DESC LIMIT 3");

    jsonResponse(200, 'Top sales orders found', ['data' => mysqli_fetch_all($result, MYSQLI_ASSOC)]);
}

function getTopSppb($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT sp.id, sp.sppb_display_number, sp.sppb_date, c.customer_name
        FROM " . APP_SCHEMA . ".sales_sppb sp
        LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = sp.customer_id
        WHERE sp.company_id = '$company_id' AND sp.deleted_at IS NULL
        ORDER BY sp.created_at DESC LIMIT 3");

    jsonResponse(200, 'Top SPPB found', ['data' => mysqli_fetch_all($result, MYSQLI_ASSOC)]);
}

function getTopSalesInvoices($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT si.id, si.invoice_display_number, si.invoice_date, si.sales_order_id, c.customer_name
        FROM " . APP_SCHEMA . ".sales_invoice si
        LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = si.customer_id
        WHERE si.company_id = '$company_id' AND si.deleted_at IS NULL
        ORDER BY si.created_at DESC LIMIT 3");

    jsonResponse(200, 'Top sales invoices found', ['data' => mysqli_fetch_all($result, MYSQLI_ASSOC)]);
}

function getTopDeliveryOrders($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT sd.id, sd.do_display_number, sd.delivery_date, sd.sales_order_id, c.customer_name
        FROM " . APP_SCHEMA . ".sales_delivery sd
        LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = sd.customer_id
        WHERE sd.company_id = '$company_id' AND sd.deleted_at IS NULL
        ORDER BY sd.created_at DESC LIMIT 3");

    jsonResponse(200, 'Top delivery orders found', ['data' => mysqli_fetch_all($result, MYSQLI_ASSOC)]);
}

function getTopProfit($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT sp.id, sp.sales_order_id, sp.created_at, c.customer_name
        FROM " . APP_SCHEMA . ".sales_profit sp
        LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = sp.customer_id
        WHERE sp.company_id = '$company_id' AND sp.deleted_at IS NULL
        ORDER BY sp.created_at DESC LIMIT 3");

    jsonResponse(200, 'Top sales profit found', ['data' => mysqli_fetch_all($result, MYSQLI_ASSOC)]);
}

function getTopPurchaseReceives($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT pr.id, pr.receiving_date, pr.purchase_order_id, po.po_display_number, s.supplier_name
        FROM " . APP_SCHEMA . ".purchase_receive pr
        LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = pr.supplier_id
        LEFT JOIN " . APP_SCHEMA . ".purchase_order po ON po.id = pr.purchase_order_id
        WHERE pr.company_id = '$company_id' AND pr.deleted_at IS NULL
        ORDER BY pr.created_at DESC LIMIT 4");

    jsonResponse(200, 'Top purchase receives found', ['data' => mysqli_fetch_all($result, MYSQLI_ASSOC)]);
}

function getTopPurchaseInvoices($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT pi.id, pi.invoice_display_number, pi.invoice_date, pi.purchase_order_id, po.po_display_number, s.supplier_name
        FROM " . APP_SCHEMA . ".purchase_invoice pi
        LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = pi.supplier_id
        LEFT JOIN " . APP_SCHEMA . ".purchase_order po ON po.id = pi.purchase_order_id
        WHERE pi.company_id = '$company_id' AND pi.deleted_at IS NULL
        ORDER BY pi.created_at DESC LIMIT 4");

    jsonResponse(200, 'Top purchase invoices found', ['data' => mysqli_fetch_all($result, MYSQLI_ASSOC)]);
}

function getTopPurchaseByType($conn, $company_id, $type_name) {
    $type_name = mysqli_real_escape_string($conn, $type_name);

    $result = mysqli_query($conn, "SELECT po.id, po.po_display_number, po.po_date, s.supplier_name,
            po.shipment_method, pm.method_name AS payment_method_name, ps.status_name, pt.type_name
        FROM " . APP_SCHEMA . ".purchase_order po
        LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = po.supplier_id
        LEFT JOIN " . APP_SCHEMA . ".payment_method pm ON pm.id = po.payment_method_id
        LEFT JOIN " . APP_SCHEMA . ".purchase_status ps ON ps.id = po.status_id
        JOIN " . APP_SCHEMA . ".purchase_type pt ON pt.id = po.type_id
        WHERE po.company_id = '$company_id' AND po.deleted_at IS NULL AND pt.type_name = '$type_name'
        ORDER BY po.created_at DESC LIMIT 4");

    $purchase_orders = mysqli_fetch_all($result, MYSQLI_ASSOC);

    if (count($purchase_orders) > 0) {
        $ids = array_map(fn($po) => "'" . mysqli_real_escape_string($conn, $po['id']) . "'", $purchase_orders);
        $items_result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".purchase_order_item
            WHERE purchase_order_id IN (" . implode(',', $ids) . ") AND deleted_at IS NULL
            ORDER BY created_at ASC");
        $items_by_po = [];
        while ($item = mysqli_fetch_assoc($items_result)) {
            $items_by_po[$item['purchase_order_id']][] = $item;
        }
        foreach ($purchase_orders as &$po) {
            $po['items'] = $items_by_po[$po['id']] ?? [];
        }
    }

    return $purchase_orders;
}

function getTopPurchaseImport($conn, $company_id) {
    jsonResponse(200, 'Top import purchase orders found', ['data' => getTopPurchaseByType($conn, $company_id, 'Import')]);
}

function getTopPurchaseLocal($conn, $company_id) {
    jsonResponse(200, 'Top local purchase orders found', ['data' => getTopPurchaseByType($conn, $company_id, 'Local')]);
}

function getOutstanding($conn, $company_id, $params) {
    $type  = isset($params['type']) ? strtolower($params['type']) : 'all';
    $month = isset($params['month']) ? (int)$params['month'] : 0;
    $year  = isset($params['year'])  ? (int)$params['year']  : 0;

    if (!in_array($type, ['piutang', 'hutang', 'all'], true)) {
        jsonResponse(400, 'type must be piutang, hutang, or all');
        return;
    }

    $response = [];

    if ($type === 'piutang' || $type === 'all') {
        $date_filter = '';
        if ($year > 0)  $date_filter .= " AND YEAR(si.invoice_date) = $year";
        if ($month > 0) $date_filter .= " AND MONTH(si.invoice_date) = $month";

        $result = mysqli_query($conn, "SELECT
                fp.invoice_number,
                c.customer_name                                       AS nama_pelanggan,
                c.id                                                   AS customer_id,
                si.invoice_date                                        AS tanggal_invoice,
                c.customer_top_days                                    AS term_of_payment,
                DATE_ADD(si.invoice_date, INTERVAL COALESCE(c.customer_top_days, 0) DAY) AS jatuh_tempo,
                DATEDIFF(CURDATE(), DATE_ADD(si.invoice_date, INTERVAL COALESCE(c.customer_top_days, 0) DAY)) AS hari_overdue,
                MAX(fp.due_amount)                                     AS nilai_invoice,
                SUM(COALESCE(fp.paid_amount, 0))                       AS sudah_dibayar,
                MAX(fp.due_amount) - SUM(COALESCE(fp.paid_amount, 0))  AS sisa_tagihan
            FROM " . APP_SCHEMA . ".finance_payment fp
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = fp.customer_id
            LEFT JOIN " . APP_SCHEMA . ".sales_invoice si ON si.invoice_display_number = fp.invoice_number AND si.company_id = fp.company_id
            WHERE fp.company_id = '$company_id' AND fp.customer_id IS NOT NULL AND fp.deleted_at IS NULL
              $date_filter
            GROUP BY fp.invoice_number, c.customer_name, c.id, si.invoice_date, c.customer_top_days
            HAVING sisa_tagihan > 0
            ORDER BY jatuh_tempo ASC");

        $piutang_list = mysqli_fetch_all($result, MYSQLI_ASSOC);
        foreach ($piutang_list as &$row) {
            $row['sisa_tagihan']  = (float)$row['sisa_tagihan'];
            $row['nilai_invoice'] = (float)$row['nilai_invoice'];
            $row['sudah_dibayar'] = (float)$row['sudah_dibayar'];
            $row['hari_overdue']  = (int)$row['hari_overdue'];
            $row['status']        = $row['hari_overdue'] > 0 ? 'Overdue' : 'Belum Jatuh Tempo';
        }

        $response['piutang_usaha'] = [
            'total_piutang' => array_sum(array_column($piutang_list, 'sisa_tagihan')),
            'total_invoice' => count($piutang_list),
            'data'          => $piutang_list,
        ];
    }

    if ($type === 'hutang' || $type === 'all') {
        $date_filter = '';
        if ($year > 0)  $date_filter .= " AND YEAR(pi.invoice_date) = $year";
        if ($month > 0) $date_filter .= " AND MONTH(pi.invoice_date) = $month";

        $result = mysqli_query($conn, "SELECT
                fp.invoice_number,
                s.supplier_name,
                s.id                                                   AS supplier_id,
                pi.invoice_date                                        AS tanggal_invoice,
                COALESCE(NULLIF(pi.kurs, 0), 1)                       AS kurs,
                MAX(fp.due_amount)                                     AS nilai_invoice,
                SUM(COALESCE(fp.paid_amount, 0))                       AS sudah_dibayar,
                MAX(fp.due_amount) - SUM(COALESCE(fp.paid_amount, 0))  AS sisa_hutang,
                DATEDIFF(CURDATE(), pi.invoice_date)                   AS hari_sejak_invoice
            FROM " . APP_SCHEMA . ".finance_payment fp
            LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = fp.supplier_id
            LEFT JOIN " . APP_SCHEMA . ".purchase_invoice pi ON pi.invoice_display_number = fp.invoice_number AND pi.company_id = fp.company_id
            WHERE fp.company_id = '$company_id' AND fp.supplier_id IS NOT NULL AND fp.deleted_at IS NULL
              $date_filter
            GROUP BY fp.invoice_number, s.supplier_name, s.id, pi.invoice_date, pi.kurs
            HAVING sisa_hutang > 0
            ORDER BY tanggal_invoice ASC");

        $hutang_list = mysqli_fetch_all($result, MYSQLI_ASSOC);
        foreach ($hutang_list as &$row) {
            $row['sisa_hutang']        = (float)$row['sisa_hutang'];
            $row['nilai_invoice']      = (float)$row['nilai_invoice'];
            $row['sudah_dibayar']      = (float)$row['sudah_dibayar'];
            $row['hari_sejak_invoice'] = (int)$row['hari_sejak_invoice'];
            $row['kurs']               = (float)$row['kurs'];
        }

        $response['hutang_usaha'] = [
            'total_hutang'  => array_sum(array_column($hutang_list, 'sisa_hutang')),
            'total_invoice' => count($hutang_list),
            'data'          => $hutang_list,
        ];
    }

    jsonResponse(200, 'Outstanding report found', array_merge([
        'type'   => $type,
        'filter' => ['year' => $year > 0 ? $year : 'all', 'month' => $month > 0 ? $month : 'all'],
    ], $response));
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

$widget = !empty($action) ? $action : 'overview';

try {
    $conn = getConn();

    switch ($widget) {
        case 'overview':
            getDashboardOverview($conn, $company_id, $_GET);
            break;
        case 'purchase-overview':
            getPurchaseOverview($conn, $company_id, $_GET);
            break;
        case 'top-sales-orders':
            getTopSalesOrders($conn, $company_id);
            break;
        case 'top-sppb':
            getTopSppb($conn, $company_id);
            break;
        case 'top-sales-invoices':
            getTopSalesInvoices($conn, $company_id);
            break;
        case 'top-delivery-orders':
            getTopDeliveryOrders($conn, $company_id);
            break;
        case 'top-profit':
            getTopProfit($conn, $company_id);
            break;
        case 'top-purchase-receives':
            getTopPurchaseReceives($conn, $company_id);
            break;
        case 'top-purchase-invoices':
            getTopPurchaseInvoices($conn, $company_id);
            break;
        case 'top-purchase-import':
            getTopPurchaseImport($conn, $company_id);
            break;
        case 'top-purchase-local':
            getTopPurchaseLocal($conn, $company_id);
            break;
        case 'outstanding':
            getOutstanding($conn, $company_id, $_GET);
            break;
        default:
            jsonResponse(404, 'Route not found');
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
