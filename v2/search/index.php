<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

function searchSalesOrders($conn, $company_id, $search, $limit) {
    $result = mysqli_query($conn, "SELECT so.id, so.so_display_number, c.customer_name
            FROM " . APP_SCHEMA . ".sales_order so
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = so.customer_id
            WHERE so.company_id = '$company_id' AND so.deleted_at IS NULL
              AND so.so_display_number LIKE '%$search%'
            ORDER BY so.created_at DESC LIMIT $limit");

    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = ['id' => $row['id'], 'title' => $row['so_display_number'], 'subtitle' => $row['customer_name']];
    }
    return $items;
}

function searchSalesInvoices($conn, $company_id, $search, $limit) {
    $result = mysqli_query($conn, "SELECT si.id, si.invoice_display_number, c.customer_name
            FROM " . APP_SCHEMA . ".sales_invoice si
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = si.customer_id
            WHERE si.company_id = '$company_id' AND si.deleted_at IS NULL
              AND si.invoice_display_number LIKE '%$search%'
            ORDER BY si.created_at DESC LIMIT $limit");

    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = ['id' => $row['id'], 'title' => $row['invoice_display_number'], 'subtitle' => $row['customer_name']];
    }
    return $items;
}

function searchSalesDeliveries($conn, $company_id, $search, $limit) {
    $result = mysqli_query($conn, "SELECT sd.id, sd.do_display_number, c.customer_name
            FROM " . APP_SCHEMA . ".sales_delivery sd
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = sd.customer_id
            WHERE sd.company_id = '$company_id' AND sd.deleted_at IS NULL
              AND sd.do_display_number LIKE '%$search%'
            ORDER BY sd.created_at DESC LIMIT $limit");

    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = ['id' => $row['id'], 'title' => $row['do_display_number'], 'subtitle' => $row['customer_name']];
    }
    return $items;
}

function searchSalesSppbs($conn, $company_id, $search, $limit) {
    $result = mysqli_query($conn, "SELECT ssp.id, ssp.sppb_display_number, c.customer_name
            FROM " . APP_SCHEMA . ".sales_sppb ssp
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = ssp.customer_id
            WHERE ssp.company_id = '$company_id' AND ssp.deleted_at IS NULL
              AND ssp.sppb_display_number LIKE '%$search%'
            ORDER BY ssp.created_at DESC LIMIT $limit");

    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = ['id' => $row['id'], 'title' => $row['sppb_display_number'], 'subtitle' => $row['customer_name']];
    }
    return $items;
}

function searchPurchaseOrders($conn, $company_id, $search, $limit) {
    $result = mysqli_query($conn, "SELECT po.id, po.po_display_number, s.supplier_name, pty.type_name
            FROM " . APP_SCHEMA . ".purchase_order po
            LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = po.supplier_id
            LEFT JOIN " . APP_SCHEMA . ".purchase_type pty ON pty.id = po.type_id
            WHERE po.company_id = '$company_id' AND po.deleted_at IS NULL
              AND po.po_display_number LIKE '%$search%'
            ORDER BY po.created_at DESC LIMIT $limit");

    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = [
            'id'        => $row['id'],
            'title'     => $row['po_display_number'],
            'subtitle'  => $row['supplier_name'],
            'type_name' => $row['type_name'],
        ];
    }
    return $items;
}

function searchPurchaseReceives($conn, $company_id, $search, $limit) {
    $result = mysqli_query($conn, "SELECT pr.id, po.po_display_number, s.supplier_name
            FROM " . APP_SCHEMA . ".purchase_receive pr
            LEFT JOIN " . APP_SCHEMA . ".purchase_order po ON po.id = pr.purchase_order_id
            LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = pr.supplier_id
            WHERE pr.company_id = '$company_id' AND pr.deleted_at IS NULL
              AND po.po_display_number LIKE '%$search%'
            ORDER BY pr.created_at DESC LIMIT $limit");

    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = ['id' => $row['id'], 'title' => $row['po_display_number'], 'subtitle' => $row['supplier_name']];
    }
    return $items;
}

function searchPurchaseInvoices($conn, $company_id, $search, $limit) {
    $result = mysqli_query($conn, "SELECT pi.id, pi.invoice_display_number, s.supplier_name
            FROM " . APP_SCHEMA . ".purchase_invoice pi
            LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = pi.supplier_id
            WHERE pi.company_id = '$company_id' AND pi.deleted_at IS NULL
              AND pi.invoice_display_number LIKE '%$search%'
            ORDER BY pi.created_at DESC LIMIT $limit");

    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = ['id' => $row['id'], 'title' => $row['invoice_display_number'], 'subtitle' => $row['supplier_name']];
    }
    return $items;
}

function searchCustomers($conn, $company_id, $search, $limit) {
    $result = mysqli_query($conn, "SELECT c.id, c.customer_name, c.customer_pic_name
            FROM " . APP_SCHEMA . ".customer c
            WHERE c.company_id = '$company_id' AND c.deleted_at IS NULL
              AND (c.customer_name LIKE '%$search%' OR c.customer_pic_name LIKE '%$search%')
            ORDER BY c.created_at DESC LIMIT $limit");

    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = ['id' => $row['id'], 'title' => $row['customer_name'], 'subtitle' => $row['customer_pic_name']];
    }
    return $items;
}

function searchSuppliers($conn, $company_id, $search, $limit) {
    $result = mysqli_query($conn, "SELECT s.id, s.supplier_name, s.supplier_pic_name
            FROM " . APP_SCHEMA . ".supplier s
            WHERE s.company_id = '$company_id' AND s.deleted_at IS NULL
              AND (s.supplier_name LIKE '%$search%' OR s.supplier_pic_name LIKE '%$search%')
            ORDER BY s.created_at DESC LIMIT $limit");

    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = ['id' => $row['id'], 'title' => $row['supplier_name'], 'subtitle' => $row['supplier_pic_name']];
    }
    return $items;
}

function searchProducts($conn, $company_id, $search, $limit) {
    $result = mysqli_query($conn, "SELECT p.id, p.product_name, p.product_code, p.hs_code
            FROM " . APP_SCHEMA . ".product p
            WHERE p.company_id = '$company_id' AND p.deleted_at IS NULL
              AND (p.product_name LIKE '%$search%' OR p.product_code LIKE '%$search%' OR p.hs_code LIKE '%$search%')
            ORDER BY p.created_at DESC LIMIT $limit");

    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = ['id' => $row['id'], 'title' => $row['product_name'], 'subtitle' => $row['product_code'] ?? $row['hs_code']];
    }
    return $items;
}

function searchFinanceTransactions($conn, $company_id, $search, $limit) {
    $result = mysqli_query($conn, "SELECT ft.id, ft.voucher_number, ft.payee
            FROM " . APP_SCHEMA . ".finance_transaction ft
            WHERE ft.company_id = '$company_id' AND ft.deleted_at IS NULL
              AND (ft.voucher_number LIKE '%$search%' OR ft.payee LIKE '%$search%')
            ORDER BY ft.created_at DESC LIMIT $limit");

    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = ['id' => $row['id'], 'title' => $row['voucher_number'], 'subtitle' => $row['payee']];
    }
    return $items;
}

function searchFinancePayments($conn, $company_id, $search, $limit) {
    $result = mysqli_query($conn, "SELECT fp.id, fp.invoice_number, c.customer_name, s.supplier_name
            FROM " . APP_SCHEMA . ".finance_payment fp
            LEFT JOIN " . APP_SCHEMA . ".customer c ON c.id = fp.customer_id
            LEFT JOIN " . APP_SCHEMA . ".supplier s ON s.id = fp.supplier_id
            WHERE fp.company_id = '$company_id' AND fp.deleted_at IS NULL
              AND fp.invoice_number LIKE '%$search%'
            ORDER BY fp.created_at DESC LIMIT $limit");

    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = [
            'id'       => $row['id'],
            'title'    => $row['invoice_number'],
            'subtitle' => $row['customer_name'] ?? $row['supplier_name'],
        ];
    }
    return $items;
}

function getGlobalSearchResults($conn, $company_id, $params) {
    $search_term = isset($params['q']) ? trim($params['q']) : '';
    if ($search_term === '') {
        jsonResponse(400, 'q is required');
        return;
    }

    $limit  = min(10, max(1, (int)($params['limit'] ?? 5)));
    $search = mysqli_real_escape_string($conn, $search_term);

    $group_builders = [
        'sales-order'         => ['label' => 'Sales Orders',         'items' => searchSalesOrders($conn, $company_id, $search, $limit)],
        'sales-invoice'       => ['label' => 'Sales Invoices',       'items' => searchSalesInvoices($conn, $company_id, $search, $limit)],
        'sales-delivery'      => ['label' => 'Sales Deliveries',     'items' => searchSalesDeliveries($conn, $company_id, $search, $limit)],
        'sales-sppb'          => ['label' => 'Sales SPPBs',          'items' => searchSalesSppbs($conn, $company_id, $search, $limit)],
        'purchase-order'      => ['label' => 'Purchase Orders',      'items' => searchPurchaseOrders($conn, $company_id, $search, $limit)],
        'purchase-receive'    => ['label' => 'Purchase Receives',    'items' => searchPurchaseReceives($conn, $company_id, $search, $limit)],
        'purchase-invoice'    => ['label' => 'Purchase Invoices',    'items' => searchPurchaseInvoices($conn, $company_id, $search, $limit)],
        'customer'            => ['label' => 'Customers',            'items' => searchCustomers($conn, $company_id, $search, $limit)],
        'supplier'            => ['label' => 'Suppliers',            'items' => searchSuppliers($conn, $company_id, $search, $limit)],
        'product'             => ['label' => 'Products',             'items' => searchProducts($conn, $company_id, $search, $limit)],
        'finance-transaction' => ['label' => 'Finance Transactions', 'items' => searchFinanceTransactions($conn, $company_id, $search, $limit)],
        'finance-payment'     => ['label' => 'Finance Payments',     'items' => searchFinancePayments($conn, $company_id, $search, $limit)],
    ];

    $groups = [];
    foreach ($group_builders as $type => $group) {
        if (empty($group['items'])) continue;
        $groups[] = ['type' => $type, 'label' => $group['label'], 'items' => $group['items']];
    }

    jsonResponse(200, 'Search results found', ['groups' => $groups]);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

try {
    $conn = getConn();

    if ($method !== 'GET') {
        jsonResponse(405, 'Method Not Allowed');
    }

    getGlobalSearchResults($conn, $company_id, $_GET);

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
