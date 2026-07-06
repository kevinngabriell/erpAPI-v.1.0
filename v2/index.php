<?php
require_once __DIR__ . '/general.php';

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = trim($uri, '/');
$parts  = explode('/', $uri);

// Expect: api / v2 / {module} / {action} [/ {sub_action} [/ {sub_id}]]
$prefix  = $parts[0] ?? '';
$version = $parts[1] ?? '';
$module  = $parts[2] ?? '';
$action  = $parts[3] ?? '';

if ($prefix !== 'api' || $version !== 'v2') {
    jsonResponse(404, 'Route not found');
}

switch ($module) {

    case 'account':
        $actionMap = [
            'login'            => 'login.php',
            'register'         => 'register.php',
            'send-otp'         => 'send-otp.php',
            'forgot-password'  => 'forgot-password.php',
            'reset-password'   => 'reset-password.php',
        ];
        $file = isset($actionMap[$action]) ? __DIR__ . '/auth/' . $actionMap[$action] : null;
        if (!$file) jsonResponse(404, 'Route not found');
        require $file;
        break;

    // ── Master data — global lookups ────────────────────────────────────────
    case 'region':
        require __DIR__ . '/master/region/index.php';
        break;
    case 'origin':
        require __DIR__ . '/master/origin/index.php';
        break;
    case 'unit-of-measure':
        require __DIR__ . '/master/unit-of-measure/index.php';
        break;
    case 'currency':
        require __DIR__ . '/master/currency/index.php';
        break;
    case 'ppn-type':
        require __DIR__ . '/master/ppn-type/index.php';
        break;
    case 'purchase-status':
        require __DIR__ . '/master/purchase-status/index.php';
        break;
    case 'purchase-type':
        require __DIR__ . '/master/purchase-type/index.php';
        break;
    case 'sales-status':
        require __DIR__ . '/master/sales-status/index.php';
        break;
    case 'ship-via':
        require __DIR__ . '/master/ship-via/index.php';
        break;
    case 'payment-method':
        require __DIR__ . '/master/payment-method/index.php';
        break;
    case 'payment-term':
        require __DIR__ . '/master/payment-term/index.php';
        break;
    case 'finance-category':
        require __DIR__ . '/master/finance-category/index.php';
        break;
    case 'salary-category':
        require __DIR__ . '/master/salary-category/index.php';
        break;

    // ── Master data — tenant-scoped ─────────────────────────────────────────
    case 'account-code':
        require __DIR__ . '/master/account-code/index.php';
        break;
    case 'bank-account':
        require __DIR__ . '/master/bank-account/index.php';
        break;
    case 'customer':
        require __DIR__ . '/master/customer/index.php';
        break;
    case 'supplier':
        require __DIR__ . '/master/supplier/index.php';
        break;
    case 'product':
        require __DIR__ . '/master/product/index.php';
        break;
    case 'document-center':
        require __DIR__ . '/master/document-center/index.php';
        break;
    case 'document-watermark':
        require __DIR__ . '/master/document-watermark/index.php';
        break;
    case 'company-setting-menu':
        $sub_action = $parts[4] ?? '';
        if ($sub_action === 'details') {
            require __DIR__ . '/master/company-setting-menu/details.php';
        } else {
            require __DIR__ . '/master/company-setting-menu/index.php';
        }
        break;
    case 'sales-target':
        require __DIR__ . '/master/sales-target/index.php';
        break;
    case 'warehouse-location':
        require __DIR__ . '/master/warehouse-location/index.php';
        break;

    // ── Purchase flow ────────────────────────────────────────────────────────
    case 'purchase-order':
        require __DIR__ . '/purchase/purchase-order/index.php';
        break;
    case 'purchase-receive':
        require __DIR__ . '/purchase/purchase-receive/index.php';
        break;
    case 'purchase-invoice':
        require __DIR__ . '/purchase/purchase-invoice/index.php';
        break;

    // ── Sales flow ────────────────────────────────────────────────────────────
    case 'sales-order':
        require __DIR__ . '/sales/sales-order/index.php';
        break;
    case 'sales-delivery':
        require __DIR__ . '/sales/sales-delivery/index.php';
        break;
    case 'sales-invoice':
        require __DIR__ . '/sales/sales-invoice/index.php';
        break;
    case 'sales-sppb':
        require __DIR__ . '/sales/sales-sppb/index.php';
        break;
    case 'sales-profit':
        require __DIR__ . '/sales/sales-profit/index.php';
        break;

    // ── Finance ──────────────────────────────────────────────────────────────
    case 'finance-transaction':
        require __DIR__ . '/finance/finance-transaction/index.php';
        break;
    case 'finance-payment':
        require __DIR__ . '/finance/finance-payment/index.php';
        break;

    // ── Warehouse ────────────────────────────────────────────────────────────
    case 'warehouse-lot':
        require __DIR__ . '/warehouse/warehouse-lot/index.php';
        break;
    case 'warehouse-transaction':
        require __DIR__ . '/warehouse/warehouse-transaction/index.php';
        break;

    // ── HR ───────────────────────────────────────────────────────────────────
    case 'salary-transaction':
        require __DIR__ . '/hr/salary-transaction/index.php';
        break;

    // ── Audit ────────────────────────────────────────────────────────────────
    case 'audit-log':
        require __DIR__ . '/audit/audit-log/index.php';
        break;

    default:
        jsonResponse(404, 'Route not found');
}
