<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/permission.php';
require_once __DIR__ . '/../helpers/analytics_dates.php';
require_once __DIR__ . '/overview.php';
require_once __DIR__ . '/sales.php';
require_once __DIR__ . '/purchase.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/warehouse.php';

const ANALYTICS_ACTIONS = ['overview', 'sales', 'purchase', 'finance', 'warehouse'];

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
    methodNotAllowed(['GET']);
    exit;
}
if (!in_array($action, ANALYTICS_ACTIONS, true)) {
    jsonResponse(404, 'Route not found');
    exit;
}

try {
    $conn = getConn();
    $company_id = mysqli_real_escape_string($conn, $company_id);

    validateAnalyticsWindowParams($_GET) or jsonResponse(400, 'from/to must be YYYY-MM-DD format');

    if (!userHasPermission($conn, $app_role_id, "analytics.$action.view")) {
        jsonResponse(403, 'You do not have permission to view this analytics module');
        exit;
    }

    switch ($action) {
        case 'overview':
            getOverviewAnalytics($conn, $company_id, $_GET);
            break;
        case 'sales':
            getSalesAnalytics($conn, $company_id, $_GET);
            break;
        case 'purchase':
            getPurchaseAnalytics($conn, $company_id, $_GET);
            break;
        case 'finance':
            getFinanceAnalytics($conn, $company_id, $_GET);
            break;
        case 'warehouse':
            getWarehouseAnalytics($conn, $company_id, $_GET);
            break;
    }

} catch (Exception $e) {
    handleModuleException($e);
}
